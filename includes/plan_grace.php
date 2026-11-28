<?php
/**
 * SalesDesk — Downgrade grace  (plans Phase 3b, migration 0023)
 *
 * When an account ends up over a plan limit (a paid plan ended, a
 * superadmin lowered a limit), nothing is deleted:
 *
 *   BROKERS (max_desk_cars — live cars on the desk)
 *     • a 7-day window opens (plan_grace) and the broker is told
 *     • they can pick which cars stay live (app/broker/keep-cars), or
 *       upgrade, or remove cars themselves
 *     • when the window ends, the extras are PARKED: the row moves to
 *       broker_inventory_parked with its id, tracking code, views and
 *       added date. Every page that reads broker_inventory (public desk,
 *       search, sitemaps, share links, lead attribution) ignores it.
 *     • kept by default: the cars with the most leads, then the newest
 *     • after an upgrade or a higher limit, parked cars come back
 *       automatically, best first, as room allows. A slot the broker frees
 *       by removing a car is theirs to use: nothing refills it, but they
 *       can resume a paused car by hand from My desk (or re-add it from
 *       the Marketplace — same link and views)
 *
 *   ENTERPRISE BUSINESSES (max_dealerships)
 *     • a paid plan ended with more dealerships than the plan allows:
 *       7-day window, owner + superadmins told
 *     • then the extra dealerships (not the owner's; newest first) get
 *       dealers.listings_paused_at: no NEW cars (upload or import); their
 *       existing cars stay live
 *     • moving dealerships out, renewing, or a custom limit lifts it
 *
 * Entry points:
 *   sdGraceRecheck(type, id) — after any plan / limit / dealership change
 *                              (billing.php, entitlements.php, business.php)
 *   sdGraceDaily()           — cron/billing-daily.php: re-check everyone,
 *                              enforce windows that ended
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/entitlements.php';

const SD_GRACE_DAYS = 7;


// ============================================================
// READINESS + LOOKUPS
// ============================================================

function sdGraceReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) Database::getInstance()->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broker_inventory_parked'
            ")->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/** The open window for an account + limit, or null. */
function sdGraceOpen(string $type, int $accountId, string $key): ?array
{
    if (!sdGraceReady()) {
        return null;
    }
    $s = Database::getInstance()->prepare("
        SELECT * FROM plan_grace
        WHERE account_type = ? AND account_id = ? AND limit_key = ? AND resolved_at IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $s->execute([$type, $accountId, $key]);
    return $s->fetch() ?: null;
}

function sdGraceDeskId(int $userId): ?int
{
    $s = Database::getInstance()->prepare("SELECT id FROM salesdesks WHERE user_id = ? LIMIT 1");
    $s->execute([$userId]);
    $id = $s->fetchColumn();
    return $id ? (int) $id : null;
}

/** Parked cars on a desk, best first (most leads, then newest). */
function sdParkedCars(int $userId): array
{
    if (!sdGraceReady() || !($deskId = sdGraceDeskId($userId))) {
        return [];
    }
    require_once __DIR__ . '/vip_network.php';
    $vip = sdVipReady();   // 4a: network-only cars
    $s = Database::getInstance()->prepare("
        SELECT p.id AS inv_id, p.car_id, p.tracking_code, p.views, p.added_at, p.parked_at, p.reason,
               c.slug, c.make, c.model, c.variant, c.year, c.price, c.status, c.image_urls,
               " . ($vip ? 'c.network_exclusive' : '0 AS network_exclusive') . ",
               d.organization_id AS org_id, o.name AS org_name,
               (SELECT COUNT(*) FROM leads l WHERE l.salesdesk_id = p.salesdesk_id AND l.car_id = p.car_id) AS leads
        FROM broker_inventory_parked p
        JOIN cars c ON c.id = p.car_id
        JOIN dealers d ON d.id = c.dealer_id
        LEFT JOIN organizations o ON o.id = d.organization_id
        WHERE p.salesdesk_id = ?
        ORDER BY leads DESC, p.added_at DESC
    ");
    $s->execute([$deskId]);
    $rows = $s->fetchAll();
    $mine = $vip ? sdBrokerVerifiedOrgIdsFresh($userId) : [];
    foreach ($rows as &$r) {   // may the broker list it? (network-only cars: members only)
        $r['may_list'] = !(int) $r['network_exclusive'] || in_array((int) $r['org_id'], $mine, true);
    }
    unset($r);
    return $rows;
}

/**
 * Live (active) desk cars ranked the way the default keeps them: most
 * leads first, then newest. Used by the keep-cars page and enforcement.
 */
function sdDeskCarsRanked(int $userId): array
{
    if (!($deskId = sdGraceDeskId($userId))) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT bi.id AS inv_id, bi.car_id, bi.views, bi.added_at,
               c.slug, c.make, c.model, c.variant, c.year, c.price, c.image_urls,
               (SELECT COUNT(*) FROM leads l WHERE l.salesdesk_id = bi.salesdesk_id AND l.car_id = bi.car_id) AS leads
        FROM broker_inventory bi
        JOIN cars c ON c.id = bi.car_id
        WHERE bi.salesdesk_id = ? AND c.status = 'active'
        ORDER BY leads DESC, bi.added_at DESC, bi.id DESC
    ");
    $s->execute([$deskId]);
    return $s->fetchAll();
}

/**
 * The broker's desk against their limit.
 *
 * @return array{limit:?int, live:int, parked:int, parked_plan:int, parked_network:int, over:int, room:?int, grace:?array}
 */
function sdBrokerDeskState(int $userId): array
{
    $deskId = sdGraceDeskId($userId);
    $limit  = brokerDeskCarLimit($userId);
    $live   = $deskId ? brokerDeskCarCount($deskId) : 0;
    $parked = $parkedNet = 0;
    if ($deskId && sdGraceReady()) {
        $p = Database::getInstance()->prepare("SELECT COUNT(*), COALESCE(SUM(reason = 'network'), 0) FROM broker_inventory_parked WHERE salesdesk_id = ?");
        $p->execute([$deskId]);
        [$parked, $parkedNet] = array_map('intval', $p->fetch(PDO::FETCH_NUM));
    }
    return [
        'limit'  => $limit,
        'live'   => $live,
        'parked' => $parked,
        'parked_plan'    => $parked - $parkedNet,   // 4a: paused by the plan limit
        'parked_network' => $parkedNet,             // 4a: paused because the car is network-only
        'over'   => $limit === null ? 0 : max(0, $live - $limit),
        'room'   => $limit === null ? null : max(0, $limit - $live),
        'grace'  => sdGraceOpen('broker', $userId, 'max_desk_cars'),
    ];
}


// ============================================================
// PARK / RESTORE (desk cars)
// ============================================================

/** Move desk rows into broker_inventory_parked. Returns how many moved. */
function sdParkDeskRows(int $deskId, array $invIds, string $reason = 'plan_limit'): int
{
    $invIds = array_values(array_unique(array_map('intval', $invIds)));
    if (!$invIds || !sdGraceReady()) {
        return 0;
    }
    $pdo = Database::getInstance();
    $in  = implode(',', array_fill(0, count($invIds), '?'));
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT IGNORE INTO broker_inventory_parked (id, salesdesk_id, car_id, tracking_code, views, added_at, parked_at, reason)
            SELECT id, salesdesk_id, car_id, tracking_code, views, added_at, NOW(), ?
            FROM broker_inventory WHERE salesdesk_id = ? AND id IN ({$in})
        ");
        $ins->execute(array_merge([$reason, $deskId], $invIds));
        // Only rows whose copy landed (same id, desk AND car) leave the desk.
        $del = $pdo->prepare("DELETE FROM broker_inventory WHERE salesdesk_id = ? AND id IN ({$in})
                              AND EXISTS (SELECT 1 FROM broker_inventory_parked p
                                          WHERE p.id = broker_inventory.id AND p.car_id = broker_inventory.car_id
                                            AND p.salesdesk_id = broker_inventory.salesdesk_id)");
        $del->execute(array_merge([$deskId], $invIds));
        $n = $del->rowCount();
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk grace] park failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Put a parked car back on the desk exactly as it was (same id when it's
 * free, same tracking code, views and added date) and relink its view
 * history. Returns true when restored.
 */
function sdRestoreParkedRow(int $deskId, int $invId): bool
{
    $pdo = Database::getInstance();
    $p   = $pdo->prepare("SELECT * FROM broker_inventory_parked WHERE id = ? AND salesdesk_id = ? LIMIT 1");
    $p->execute([$invId, $deskId]);
    $row = $p->fetch();
    if (!$row) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $onDesk = $pdo->prepare("SELECT id FROM broker_inventory WHERE salesdesk_id = ? AND car_id = ? LIMIT 1");
        $onDesk->execute([$deskId, (int) $row['car_id']]);
        if (!$onDesk->fetchColumn()) {
            $idFree = !$pdo->query('SELECT 1 FROM broker_inventory WHERE id = ' . (int) $row['id'])->fetchColumn();
            if ($idFree) {
                $pdo->prepare("INSERT INTO broker_inventory (id, salesdesk_id, car_id, tracking_code, views, added_at) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([(int) $row['id'], $deskId, (int) $row['car_id'], $row['tracking_code'], (int) $row['views'], $row['added_at']]);
                $newId = (int) $row['id'];
            } else {
                $pdo->prepare("INSERT INTO broker_inventory (salesdesk_id, car_id, tracking_code, views, added_at) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$deskId, (int) $row['car_id'], $row['tracking_code'], (int) $row['views'], $row['added_at']]);
                $newId = (int) $pdo->lastInsertId();
            }
            // car_views lost its link (ON DELETE SET NULL) when the car was parked.
            $pdo->prepare("UPDATE car_views SET broker_inventory_id = ? WHERE tracking_code = ? AND broker_inventory_id IS NULL")
                ->execute([$newId, $row['tracking_code']]);
        }
        $pdo->prepare("DELETE FROM broker_inventory_parked WHERE id = ?")->execute([(int) $row['id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk grace] restore failed: ' . $e->getMessage());
        return false;
    }
}

/** Bring parked cars back while there's room (null = all). Returns how many. */
function sdRestoreParkedUpTo(int $userId, ?int $room): int
{
    if (!sdGraceReady() || !($deskId = sdGraceDeskId($userId)) || ($room !== null && $room <= 0)) {
        return 0;
    }
    $n = 0;
    foreach (sdParkedCars($userId) as $p) {
        if ($room !== null && $n >= $room) {
            break;
        }
        if ($p['status'] !== 'active' || !$p['may_list']) {
            continue;   // sold / withdrawn cars needn't come back; 4a: network-only cars wait for membership
        }
        if (sdRestoreParkedRow($deskId, (int) $p['inv_id'])) {
            $n++;
        }
    }
    return $n;
}

/**
 * The broker resumes one parked car (needs room).
 *
 * @return array{0:bool,1:string}
 */
function sdBrokerResumeCar(int $userId, int $invId): array
{
    $deskId = sdGraceDeskId($userId);
    $state  = sdBrokerDeskState($userId);
    if (!$deskId) {
        return [false, 'Desk not found.'];
    }
    if ($state['limit'] !== null && $state['live'] >= $state['limit']) {
        return [false, 'Your desk is at its limit (' . $state['limit'] . ' live cars). Remove a car or upgrade to bring this one back.'];
    }
    $p = Database::getInstance()->prepare("SELECT car_id FROM broker_inventory_parked WHERE id = ? AND salesdesk_id = ?");
    $p->execute([$invId, $deskId]);
    $carId = (int) $p->fetchColumn();
    require_once __DIR__ . '/vip_network.php';
    if ($carId && !sdBrokerMayListCar($userId, $carId)) {   // 4a
        return [false, 'That car is for its business’s network brokers only. Join the network to bring it back.'];
    }
    return sdRestoreParkedRow($deskId, $invId)
        ? [true, 'Car is live on your desk again.']
        : [false, 'That car couldn’t be brought back.'];
}

/** The broker removes a parked car for good. */
function sdBrokerDropParked(int $userId, int $invId): array
{
    $deskId = sdGraceDeskId($userId);
    if (!$deskId || !sdGraceReady()) {
        return [false, 'Desk not found.'];
    }
    $d = Database::getInstance()->prepare("DELETE FROM broker_inventory_parked WHERE id = ? AND salesdesk_id = ?");
    $d->execute([$invId, $deskId]);
    return $d->rowCount() ? [true, 'Removed from your paused cars.'] : [false, 'That car wasn’t found.'];
}

/** Is this car parked on this desk? Returns the parked id or null. */
function sdParkedIdForCar(int $userId, int $carId): ?int
{
    if (!sdGraceReady() || !($deskId = sdGraceDeskId($userId))) {
        return null;
    }
    $s = Database::getInstance()->prepare("SELECT id FROM broker_inventory_parked WHERE salesdesk_id = ? AND car_id = ? LIMIT 1");
    $s->execute([$deskId, $carId]);
    $id = $s->fetchColumn();
    return $id ? (int) $id : null;
}


// ============================================================
// BROKER WINDOW
// ============================================================

/**
 * Re-check a broker: open / close the window, and — after a plan or limit
 * change ($restore) — bring parked cars back into any room. The daily run
 * and the broker's own removals don't restore, so freeing a slot to add a
 * different car isn't undone behind the broker's back.
 */
function sdGraceCheckBroker(int $userId, bool $restore = true): void
{
    if (!sdGraceReady() || !sdGraceDeskId($userId)) {
        return;
    }
    $state = sdBrokerDeskState($userId);
    if ($restore && $state['parked'] > 0 && ($state['limit'] === null || $state['room'] > 0)) {
        sdRestoreParkedUpTo($userId, $state['room']);
        $state = sdBrokerDeskState($userId);
    }
    $pdo = Database::getInstance();
    if ($state['over'] > 0 && !$state['grace']) {
        $ends = date('Y-m-d H:i:s', strtotime('+' . SD_GRACE_DAYS . ' days'));
        $pdo->prepare("INSERT INTO plan_grace (account_type, account_id, limit_key, limit_value, used_at_start, started_at, ends_at)
                       VALUES ('broker', ?, 'max_desk_cars', ?, ?, NOW(), ?)")
            ->execute([$userId, $state['limit'], $state['live'], $ends]);
        writeAuditLog('plan.grace_started', 'user', $userId, null,
            ['limit' => $state['limit'], 'live' => $state['live'], 'ends' => $ends], null);
        sdGraceNotifyUser($userId, 'Choose which cars stay live',
            "Your plan allows {$state['limit']} live cars and your desk has {$state['live']}. Choose which stay live by "
            . date('j M', strtotime($ends)) . ', or upgrade. After that the extra ' . $state['over']
            . ' are paused — never deleted.', '/app/broker/keep-cars');
    } elseif ($state['over'] === 0 && $state['grace']) {
        sdGraceResolve((int) $state['grace']['id'], 'under_limit');
    }
}

/**
 * Save the broker's choice of cars to keep live; optionally apply now.
 *
 * @return array{0:bool,1:string}
 */
function sdGraceSaveKeep(int $userId, array $keepIds, bool $applyNow): array
{
    $state = sdBrokerDeskState($userId);
    if (!$state['grace'] && !$applyNow) {
        return [false, 'Your desk is within its limit — nothing to choose.'];
    }
    if ($state['limit'] === null) {
        return [false, 'Your plan has no car limit.'];
    }
    $valid   = array_column(sdDeskCarsRanked($userId), 'inv_id');
    $keepIds = array_values(array_intersect(array_unique(array_map('intval', $keepIds)), array_map('intval', $valid)));
    if (count($keepIds) > $state['limit']) {
        return [false, 'Choose up to ' . $state['limit'] . ' cars — you chose ' . count($keepIds) . '.'];
    }
    if ($state['grace']) {
        Database::getInstance()->prepare("UPDATE plan_grace SET keep_ids = ? WHERE id = ?")
            ->execute([json_encode($keepIds), (int) $state['grace']['id']]);
    }
    if (!$applyNow) {
        return [true, 'Saved. These ' . count($keepIds) . ' cars stay live'
            . ($state['grace'] ? ' when the window ends on ' . date('j M', strtotime($state['grace']['ends_at'])) : '') . '.'];
    }
    $paused = sdGraceEnforceBroker($userId, $keepIds);
    if ($state['grace']) {
        sdGraceResolve((int) $state['grace']['id'], 'applied');
    }
    return [true, $paused . ' car' . ($paused === 1 ? '' : 's') . ' paused. You can bring them back from My desk once there’s room.'];
}

/**
 * Park everything beyond the limit, keeping $keepIds first, then the
 * default ranking. Returns how many were parked.
 */
function sdGraceEnforceBroker(int $userId, ?array $keepIds = null): int
{
    $deskId = sdGraceDeskId($userId);
    $limit  = brokerDeskCarLimit($userId);
    if (!$deskId || $limit === null) {
        return 0;
    }
    $ranked = array_map('intval', array_column(sdDeskCarsRanked($userId), 'inv_id'));
    if (count($ranked) <= $limit) {
        return 0;
    }
    $chosen = array_values(array_intersect(array_map('intval', (array) $keepIds), $ranked));
    $keep   = array_slice(array_values(array_unique(array_merge($chosen, $ranked))), 0, $limit);
    $park   = array_values(array_diff($ranked, $keep));
    $n      = sdParkDeskRows($deskId, $park);
    if ($n) {
        writeAuditLog('plan.cars_paused', 'user', $userId, null, ['paused' => $n, 'limit' => $limit], null);
    }
    return $n;
}


// ============================================================
// ENTERPRISE WINDOW (max_dealerships)
// ============================================================

/** Is a dealership barred from adding new cars? */
function sdDealerListingsPaused(int $dealerId): bool
{
    static $col = null;
    $pdo = Database::getInstance();
    if ($col === null) {
        $col = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                   AND TABLE_NAME = 'dealers' AND COLUMN_NAME = 'listings_paused_at'")->fetchColumn();
    }
    if (!$col) {
        return false;
    }
    $s = $pdo->prepare("SELECT listings_paused_at FROM dealers WHERE id = ?");
    $s->execute([$dealerId]);
    return (bool) $s->fetchColumn();
}

/** Throws when the dealership can't add new cars (car upload + importers). */
function sdAssertDealerCanList(int $dealerId): void
{
    if (sdDealerListingsPaused($dealerId)) {
        throw new RuntimeException('New listings are paused for this dealership: its business has more dealerships than its plan allows. Existing cars stay live.');
    }
}

/** Re-check a business: open / close the window, lift pauses when it fits. */
function sdGraceCheckOrg(int $orgId): void
{
    if (!sdGraceReady()) {
        return;
    }
    require_once __DIR__ . '/business.php';
    if (!sdBusinessReady()) {
        return;
    }
    $pdo   = Database::getInstance();
    $limit = entitlement('organization', $orgId, 'max_dealerships');
    $count = sdOrgDealerCount($orgId);
    $grace = sdGraceOpen('organization', $orgId, 'max_dealerships');

    if ($limit === null || $count <= $limit) {
        $lift = $pdo->prepare("UPDATE dealers SET listings_paused_at = NULL WHERE organization_id = ? AND listings_paused_at IS NOT NULL");
        $lift->execute([$orgId]);
        if ($lift->rowCount()) {
            writeAuditLog('plan.listings_resumed', 'organization', $orgId, null, ['dealerships' => $lift->rowCount()], null);
        }
        if ($grace) {
            sdGraceResolve((int) $grace['id'], 'under_limit');
        }
        return;
    }
    if (!$grace) {
        $ends = date('Y-m-d H:i:s', strtotime('+' . SD_GRACE_DAYS . ' days'));
        $pdo->prepare("INSERT INTO plan_grace (account_type, account_id, limit_key, limit_value, used_at_start, started_at, ends_at)
                       VALUES ('organization', ?, 'max_dealerships', ?, ?, NOW(), ?)")
            ->execute([$orgId, $limit, $count, $ends]);
        writeAuditLog('plan.grace_started', 'organization', $orgId, null, ['limit' => $limit, 'dealerships' => $count, 'ends' => $ends], null);
        $name = (string) $pdo->query('SELECT name FROM organizations WHERE id = ' . $orgId)->fetchColumn();
        $msg  = "{$name} has {$count} dealerships and its plan allows {$limit}. Renew the plan or move dealerships out by "
              . date('j M', strtotime($ends)) . '. After that the extra dealerships can’t add new cars (existing cars stay live).';
        $owner = (int) $pdo->query('SELECT COALESCE(owner_user_id, 0) FROM organizations WHERE id = ' . $orgId)->fetchColumn();
        if ($owner) {
            sdGraceNotifyUser($owner, 'Your business plan needs attention', $msg, '/app/dealer/dashboard.php');
        }
        foreach (sdGraceSuperadminIds() as $sa) {
            sdGraceNotifyUser($sa, "{$name}: over its dealership limit", $msg, '/app/admin/dealerships-view?id='
                . (int) $pdo->query('SELECT MIN(id) FROM dealers WHERE organization_id = ' . $orgId)->fetchColumn() . '&tab=business');
        }
    }
}

/** The window ended and the business is still over: pause the extra dealerships. */
function sdGraceEnforceOrg(int $orgId): int
{
    require_once __DIR__ . '/business.php';
    $limit = entitlement('organization', $orgId, 'max_dealerships');
    if ($limit === null) {
        return 0;
    }
    $keep  = array_slice(array_map('intval', array_column(sdOrgEnforcementOrder($orgId), 'id')), 0, max(0, $limit));
    $pdo   = Database::getInstance();
    $all   = $pdo->prepare("SELECT id FROM dealers WHERE organization_id = ?");
    $all->execute([$orgId]);
    $pause = array_values(array_diff(array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN)), $keep));
    if (!$pause) {
        return 0;
    }
    $in = implode(',', $pause);
    $pdo->exec("UPDATE dealers SET listings_paused_at = NOW() WHERE id IN ({$in}) AND listings_paused_at IS NULL");
    writeAuditLog('plan.listings_paused', 'organization', $orgId, null, ['dealer_ids' => $pause, 'limit' => $limit], null);
    return count($pause);
}

/** Which dealerships keep listing first: the owner's, then the longest-standing. */
function sdOrgEnforcementOrder(int $orgId): array
{
    $s = Database::getInstance()->prepare("
        SELECT d.id FROM dealers d JOIN organizations o ON o.id = d.organization_id
        WHERE d.organization_id = ?
        ORDER BY (o.owner_user_id IS NOT NULL AND o.owner_user_id = d.user_id) DESC, d.id ASC
    ");
    $s->execute([$orgId]);
    return $s->fetchAll();
}


// ============================================================
// SHARED
// ============================================================

/** Re-check one account after a plan / limit / dealership change. */
function sdGraceRecheck(string $type, int $accountId, bool $restore = true): void
{
    try {
        if ($type === 'broker') {
            sdGraceCheckBroker($accountId, $restore);
        } elseif ($type === 'organization') {
            sdGraceCheckOrg($accountId);
        }
    } catch (Throwable $e) {
        error_log('[SalesDesk grace] recheck failed: ' . $e->getMessage());
    }
}

function sdGraceResolve(int $graceId, string $resolution): void
{
    Database::getInstance()->prepare("UPDATE plan_grace SET resolved_at = NOW(), resolution = ? WHERE id = ? AND resolved_at IS NULL")
        ->execute([$resolution, $graceId]);
}

function sdGraceNotifyUser(int $userId, string $title, string $body, string $link): void
{
    try {
        Database::getInstance()->prepare("
            INSERT INTO notifications (user_id, type, title, body, meta, created_at)
            VALUES (?, 'plan_limit', ?, ?, ?, NOW())
        ")->execute([$userId, $title, $body, json_encode(['link' => $link])]);
    } catch (Throwable) {
        // best-effort
    }
}

function sdGraceSuperadminIds(): array
{
    try {
        return array_map('intval', Database::getInstance()->query(
            "SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND is_superadmin = 1"
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return [];
    }
}

/**
 * Daily (cron/billing-daily.php): re-check every broker with cars (or
 * parked cars) and every multi-dealership business, then enforce the
 * windows that have ended.
 *
 * @return string[] notes for the cron log
 */
function sdGraceDaily(): array
{
    if (!sdGraceReady() || !sdPlansReady()) {
        return ['Grace: not ready (run 0023).'];
    }
    $pdo   = Database::getInstance();
    $notes = [];

    $brokers = $pdo->query("
        SELECT DISTINCT sd.user_id FROM salesdesks sd
        WHERE EXISTS (SELECT 1 FROM broker_inventory bi WHERE bi.salesdesk_id = sd.id)
           OR EXISTS (SELECT 1 FROM broker_inventory_parked p WHERE p.salesdesk_id = sd.id)
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($brokers as $uid) {
        sdGraceRecheck('broker', (int) $uid, false);
    }
    require_once __DIR__ . '/business.php';
    if (sdBusinessReady()) {
        $orgs = $pdo->query("SELECT DISTINCT organization_id FROM dealers WHERE organization_id IS NOT NULL
                             GROUP BY organization_id HAVING COUNT(*) > 1
                             UNION SELECT DISTINCT organization_id FROM dealers WHERE listings_paused_at IS NOT NULL
                             UNION SELECT account_id FROM plan_grace WHERE account_type = 'organization' AND resolved_at IS NULL")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orgs as $oid) {
            sdGraceRecheck('organization', (int) $oid);
        }
    }

    $due = $pdo->query("SELECT * FROM plan_grace WHERE resolved_at IS NULL AND ends_at <= NOW()")->fetchAll();
    foreach ($due as $g) {
        if ($g['account_type'] === 'broker') {
            $n = sdGraceEnforceBroker((int) $g['account_id'], json_decode((string) $g['keep_ids'], true) ?: []);
            if ($n) {
                sdGraceNotifyUser((int) $g['account_id'], "{$n} car" . ($n === 1 ? '' : 's') . ' paused on your desk',
                    'They’re hidden from your desk and links until there’s room — nothing was deleted. Upgrade, or remove cars, to bring them back.',
                    '/app/broker/inventory.php');
            }
            $notes[] = "broker #{$g['account_id']}: grace ended, {$n} car(s) paused";
        } else {
            $n = sdGraceEnforceOrg((int) $g['account_id']);
            $notes[] = "business #{$g['account_id']}: grace ended, {$n} dealership(s) can't add new cars";
        }
        sdGraceResolve((int) $g['id'], 'enforced');
    }
    return $notes ?: ['Grace: nothing to do.'];
}
