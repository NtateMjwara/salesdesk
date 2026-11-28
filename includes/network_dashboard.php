<?php
/**
 * SalesDesk — VIP network perks, part 2  (plans Phase 4b, migration 0025)
 *
 * For businesses whose plan has vip_perks (Pro, Enterprise):
 *
 *   ANNOUNCEMENTS (network_announcements)
 *     • the principal / business owner posts a short update to its
 *       verified network brokers: in-app notification, optional email
 *     • max SD_NETWORK_ANNOUNCEMENTS_PER_DAY per business per 24 h;
 *       emails only while the network has <= SD_NETWORK_ANNOUNCE_EMAIL_MAX
 *       brokers (sent in the request)
 *     • brokers read them on My networks (latest per network)
 *
 *   BUSINESS DASHBOARD (read-only, from existing tables)
 *     • per verified broker, for a period: cars of the business on their
 *       desk, views (car_views by the broker's tracking codes), leads,
 *       sales and commission on the business's cars
 *
 *   NETWORK BADGE (presentation)
 *     • sdBrokerVipNetworks(userId) — the VIP businesses a broker is a
 *       verified member of, for badges on the public desk
 *
 * Who may post: sdCanManageNetwork() (the caller checks). Everyone in the
 * dealer workspace can read the dashboard.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/entitlements.php';

const SD_NETWORK_ANNOUNCEMENTS_PER_DAY = 3;
const SD_NETWORK_ANNOUNCE_EMAIL_MAX    = 250;
const SD_NETWORK_DASH_PERIODS          = [30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months', 0 => 'All time'];


// ============================================================
// READINESS
// ============================================================

/** 0025 has run. */
function sdNetDashReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) Database::getInstance()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'network_announcements'"
            )->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/** VIP perks on the business's plan (same gate as 4a). */
function sdNetDashVip(int $orgId): bool
{
    return $orgId > 0 && sdPlansReady() && canDo('organization', $orgId, 'vip_perks');
}


// ============================================================
// ANNOUNCEMENTS
// ============================================================

/**
 * Announcements posted by a business in the last 24 hours. Counted from
 * the audit log, so removing one from the list doesn't free a slot.
 */
function sdAnnouncementsToday(int $orgId): int
{
    if (!sdNetDashReady()) {
        return 0;
    }
    $s = Database::getInstance()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'org.announcement_posted'
                                           AND entity_type = 'organization' AND entity_id = ? AND created_at > NOW() - INTERVAL 1 DAY");
    $s->execute([$orgId]);
    return (int) $s->fetchColumn();
}

/** Verified brokers of a business: id, email, first_name. */
function sdNetworkVerifiedBrokers(int $orgId): array
{
    $s = Database::getInstance()->prepare("
        SELECT u.id, u.email, p.first_name
        FROM organization_members m
        JOIN users u ON u.id = m.user_id AND u.status = 'active'
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE m.organization_id = ?" . sdVerifiedMemberSql('m') . "
        ORDER BY u.id
    ");
    $s->execute([$orgId]);
    return $s->fetchAll();
}

/**
 * Post an announcement to the network.
 *
 * @return array{0:bool,1:string}
 */
function sdPostAnnouncement(int $orgId, string $title, string $body, bool $email, int $actorId): array
{
    if (!sdNetDashReady()) {
        return [false, 'Run 0025 first.'];
    }
    if (!sdNetDashVip($orgId)) {
        return [false, 'Announcements come with the Pro and Enterprise business plans.'];
    }
    $title = trim(preg_replace('/\s+/', ' ', $title));
    $body  = trim(str_replace("\r\n", "\n", $body));
    if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
        return [false, 'Give the announcement a title of 3 to 120 characters.'];
    }
    if (mb_strlen($body) < 3 || mb_strlen($body) > 2000) {
        return [false, 'Write a message of up to 2,000 characters.'];
    }
    if (sdAnnouncementsToday($orgId) >= SD_NETWORK_ANNOUNCEMENTS_PER_DAY) {
        return [false, 'That’s ' . SD_NETWORK_ANNOUNCEMENTS_PER_DAY . ' announcements in the last 24 hours. You can post again tomorrow.'];
    }
    $brokers = sdNetworkVerifiedBrokers($orgId);
    if (!$brokers) {
        return [false, 'Your network has no brokers yet — there’s nobody to tell.'];
    }
    $pdo = Database::getInstance();
    $org = (string) $pdo->query('SELECT name FROM organizations WHERE id = ' . $orgId)->fetchColumn();

    $canEmail = $email && count($brokers) <= SD_NETWORK_ANNOUNCE_EMAIL_MAX;
    $pdo->prepare("INSERT INTO network_announcements (organization_id, author_user_id, title, body, recipients, emailed, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$orgId, $actorId ?: null, $title, $body, count($brokers), $canEmail ? 1 : 0]);
    $annId = (int) $pdo->lastInsertId();

    $note = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, meta, created_at)
                           VALUES (?, 'network_announcement', ?, ?, ?, NOW())");
    $meta = json_encode(['org_id' => $orgId, 'announcement_id' => $annId, 'link' => '/app/broker/networks#net-' . $orgId]);
    foreach ($brokers as $b) {
        $note->execute([(int) $b['id'], "{$org}: {$title}", mb_strimwidth($body, 0, 240, '…'), $meta]);
    }
    $sent = 0;
    if ($canEmail) {
        require_once __DIR__ . '/mailer.php';
        foreach ($brokers as $b) {
            if (sendNetworkAnnouncement((string) $b['email'], (string) ($b['first_name'] ?? ''), $org, $title, $body)) {
                $sent++;
            }
        }
    }
    writeAuditLog('org.announcement_posted', 'organization', $orgId, null,
        ['announcement_id' => $annId, 'title' => $title, 'recipients' => count($brokers), 'emailed' => $sent], $actorId);

    $n = count($brokers);
    return [true, "Sent to {$n} broker" . ($n === 1 ? '' : 's') . ' in your network'
        . ($canEmail ? " (in-app and email)." : '.')
        . ($email && !$canEmail ? ' Email is only sent to networks of up to ' . SD_NETWORK_ANNOUNCE_EMAIL_MAX . ' brokers.' : '')];
}

/** Remove an announcement from the list (notifications already sent stay). */
function sdDeleteAnnouncement(int $orgId, int $annId, int $actorId): array
{
    if (!sdNetDashReady()) {
        return [false, 'Run 0025 first.'];
    }
    $d = Database::getInstance()->prepare("DELETE FROM network_announcements WHERE id = ? AND organization_id = ?");
    $d->execute([$annId, $orgId]);
    if (!$d->rowCount()) {
        return [false, 'That announcement wasn’t found.'];
    }
    writeAuditLog('org.announcement_removed', 'organization', $orgId, ['announcement_id' => $annId], null, $actorId);
    return [true, 'Announcement removed from the list. Brokers who already got it keep their notification.'];
}

/** A business's announcements, newest first. */
function sdAnnouncements(int $orgId, int $limit = 20): array
{
    if (!sdNetDashReady() || $orgId <= 0) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT a.id, a.title, a.body, a.recipients, a.emailed, a.created_at,
               TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS author
        FROM network_announcements a
        LEFT JOIN profiles p ON p.user_id = a.author_user_id
        WHERE a.organization_id = ?
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT " . max(1, min(100, $limit)));
    $s->execute([$orgId]);
    return $s->fetchAll();
}

/**
 * Latest announcements of every business a broker is a verified member
 * of: orgId => rows (newest first, up to $per each).
 */
function sdBrokerAnnouncements(int $userId, int $per = 3): array
{
    if (!sdNetDashReady()) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT a.organization_id, a.id, a.title, a.body, a.created_at
        FROM network_announcements a
        JOIN organization_members m ON m.organization_id = a.organization_id AND m.user_id = ?" . sdVerifiedMemberSql('m') . "
        WHERE a.created_at >= COALESCE(m.verified_at, m.joined_at, '1970-01-01') - INTERVAL 30 DAY
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT 200
    ");
    $s->execute([$userId]);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $o = (int) $r['organization_id'];
        if (count($out[$o] ?? []) < $per) {
            $out[$o][] = $r;
        }
    }
    return $out;
}


// ============================================================
// BUSINESS DASHBOARD (per-broker performance)
// ============================================================

/**
 * Per-broker performance on a business's cars.
 *
 * @param int $days 0 = all time
 * @return array{rows: array, totals: array, since: ?string}
 */
function sdNetworkPerformance(int $orgId, int $days = 30): array
{
    $pdo   = Database::getInstance();
    $since = $days > 0 ? date('Y-m-d H:i:s', strtotime("-{$days} days")) : null;
    $out   = ['rows' => [], 'totals' => ['cars' => 0, 'views' => 0, 'leads' => 0, 'sales' => 0, 'commission' => 0.0], 'since' => $since];
    if ($orgId <= 0) {
        return $out;
    }

    // Current verified (and suspended) brokers.
    $m = $pdo->prepare("
        SELECT m.user_id, m.status, m.verified_at,
               sd.id AS desk_id, sd.slug AS desk_slug, sd.display_name AS desk_name,
               TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS name
        FROM organization_members m
        LEFT JOIN salesdesks sd ON sd.user_id = m.user_id
        LEFT JOIN profiles p ON p.user_id = m.user_id
        WHERE m.organization_id = ? AND m.status IN ('verified', 'suspended')
    ");
    $m->execute([$orgId]);
    $rows = [];
    foreach ($m->fetchAll() as $r) {
        $rows[(int) $r['user_id']] = $r + ['cars' => 0, 'views' => 0, 'leads' => 0, 'sales' => 0, 'commission' => 0.0, 'former' => false];
    }

    $orgCars = "SELECT c.id FROM cars c JOIN dealers d ON d.id = c.dealer_id WHERE d.organization_id = " . $orgId;

    // Cars of the business live on each desk now.
    foreach ($pdo->query("
        SELECT sd.user_id, COUNT(*) AS n FROM broker_inventory bi
        JOIN salesdesks sd ON sd.id = bi.salesdesk_id
        JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
        WHERE bi.car_id IN ({$orgCars}) GROUP BY sd.user_id
    ") as $r) {
        if (isset($rows[(int) $r['user_id']])) {
            $rows[(int) $r['user_id']]['cars'] = (int) $r['n'];
        }
    }

    // Views: car_views carrying the broker's tracking codes (live or paused rows).
    $codes = "SELECT salesdesk_id, tracking_code, car_id FROM broker_inventory";
    try {
        if ($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broker_inventory_parked'")->fetchColumn()) {
            $codes .= " UNION ALL SELECT salesdesk_id, tracking_code, car_id FROM broker_inventory_parked";
        }
    } catch (Throwable) {
    }
    $v = $pdo->prepare("
        SELECT sd.user_id, COUNT(*) AS n
        FROM car_views cv
        JOIN ({$codes}) t ON t.tracking_code = cv.tracking_code AND t.car_id = cv.car_id
        JOIN salesdesks sd ON sd.id = t.salesdesk_id
        WHERE cv.car_id IN ({$orgCars})" . ($since ? ' AND cv.viewed_at >= ?' : '') . "
        GROUP BY sd.user_id
    ");
    $v->execute($since ? [$since] : []);
    foreach ($v->fetchAll() as $r) {
        if (isset($rows[(int) $r['user_id']])) {
            $rows[(int) $r['user_id']]['views'] = (int) $r['n'];
        }
    }

    // Leads on the business's cars (any broker, so former members still count in totals).
    $l = $pdo->prepare("
        SELECT l.broker_id, COUNT(*) AS n FROM leads l
        WHERE l.broker_id IS NOT NULL AND l.car_id IN ({$orgCars})" . ($since ? ' AND l.created_at >= ?' : '') . "
        GROUP BY l.broker_id
    ");
    $l->execute($since ? [$since] : []);
    $leadBy = [];
    foreach ($l->fetchAll() as $r) {
        $leadBy[(int) $r['broker_id']] = (int) $r['n'];
    }

    // Sales = commissions on the business's dealerships.
    $c = $pdo->prepare("
        SELECT cm.broker_id, COUNT(*) AS n, COALESCE(SUM(cm.gross_amount), 0) AS gross
        FROM commissions cm JOIN dealers d ON d.id = cm.dealer_id
        WHERE d.organization_id = ? AND cm.broker_id IS NOT NULL" . ($since ? ' AND cm.created_at >= ?' : '') . "
        GROUP BY cm.broker_id
    ");
    $c->execute($since ? [$orgId, $since] : [$orgId]);
    $saleBy = [];
    foreach ($c->fetchAll() as $r) {
        $saleBy[(int) $r['broker_id']] = [(int) $r['n'], (float) $r['gross']];
    }

    foreach ($rows as $uid => &$r) {
        $r['leads']      = $leadBy[$uid] ?? 0;
        $r['sales']      = $saleBy[$uid][0] ?? 0;
        $r['commission'] = $saleBy[$uid][1] ?? 0.0;
    }
    unset($r);

    // Brokers no longer in the network (or never in it) who brought leads or sales: one "other" line.
    $other = ['leads' => 0, 'sales' => 0, 'commission' => 0.0];
    foreach ($leadBy as $uid => $n) {
        if (!isset($rows[$uid])) {
            $other['leads'] += $n;
        }
    }
    foreach ($saleBy as $uid => [$n, $g]) {
        if (!isset($rows[$uid])) {
            $other['sales']      += $n;
            $other['commission'] += $g;
        }
    }

    uasort($rows, static fn ($a, $b) => [$b['sales'], $b['leads'], $b['views']] <=> [$a['sales'], $a['leads'], $a['views']]);
    foreach ($rows as $r) {
        $out['totals']['cars']       += $r['cars'];
        $out['totals']['views']      += $r['views'];
        $out['totals']['leads']      += $r['leads'];
        $out['totals']['sales']      += $r['sales'];
        $out['totals']['commission'] += $r['commission'];
    }
    $out['rows']  = array_values($rows);
    $out['other'] = $other;
    return $out;
}


// ============================================================
// NETWORK BADGE
// ============================================================

/** VIP businesses a broker is a verified member of (for desk badges). */
function sdBrokerVipNetworks(int $userId): array
{
    if (!sdPlansReady()) {
        return [];
    }
    try {
        $s = Database::getInstance()->prepare("
            SELECT o.id, o.name, o.slug, o.logo_url, o.verification_status
            FROM organization_members m
            JOIN organizations o ON o.id = m.organization_id AND o.is_active = 1
            WHERE m.user_id = ?" . sdVerifiedMemberSql('m') . "
            ORDER BY m.verified_at, m.id
        ");
        $s->execute([$userId]);
        return array_values(array_filter($s->fetchAll(), static fn ($o) => sdNetDashVip((int) $o['id'])));
    } catch (Throwable) {
        return [];
    }
}
