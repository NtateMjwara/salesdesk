<?php
/**
 * SalesDesk — Business networks (broker side)  (0013, reworked in plans Phase 2b)
 *
 * Since 0020 every organisation is a dealership's BUSINESS ACCOUNT. Its
 * broker network is the set of brokers in organization_members:
 *
 *   apply (My networks page or signup step 3)
 *     → status 'pending'
 *     → the admins managing the dealership approve / decline
 *       (Approvals → Network brokers, or the dealership's Business tab;
 *        the principal joins in when the dealer-portal side ships, Phase 2c)
 *   verified   → in the network: badge, leads on that business's cars are
 *                tagged with it (see sdLeadOrgSubquery)
 *   rejected   → can apply again later
 *   suspended  → still listed, but not counted as part of the network
 *   invited    → (0021, plans 2c) a Pro / Enterprise business invited the
 *                broker; they accept (→ verified) or decline on My networks
 *
 * PLANS 2c: the business side. The principal (or an admin operating the
 * dealership) runs the network from the dealer portal (/app/dealer/network):
 * open / close it, review applications, manage members, browse brokers and
 * invite them. The rules for every review action live in sdNetworkReview(),
 * shared with the admin Business tab and Approvals (admin_scope.php).
 *
 * A broker can belong to SEVERAL networks (the one-org key was dropped in
 * 0020). Pending + verified memberships count towards the broker's plan
 * limit max_networks. A network's size is capped by the business plan's
 * max_network_brokers (checked when applying and when approving).
 *
 * Open marketplace: a membership never limits what a broker can list, so
 * brokerCatalogueRules() always allows the full marketplace. The org takes
 * no cut: commission is always paid to the broker's own account.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/filter-whitelists.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/entitlements.php';

/** Decode organizations.brands / dealers.brand_focus (JSON) into a clean array. */
function orgBrands(?string $json): array
{
    $arr = $json ? json_decode($json, true) : null;
    return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
}

/** Networks need the 0013 membership columns and the 0020 business orgs. */
function sdNetworksReady(): bool
{
    return sdOrgSchemaReady() && sdBusinessReady();
}

/**
 * Every network membership the broker has, any status: invitations
 * first, then verified, pending, suspended, rejected.
 *
 * @return array<int, array>
 */
function brokerNetworks(int $userId): array
{
    if (!sdOrgSchemaReady()) {
        return [];
    }
    $stmt = Database::getInstance()->prepare("
        SELECT m.id AS member_id, m.status, m.rejection_reason, m.joined_at, m.verified_at,
               o.id AS org_id, o.name AS org_name, o.slug AS org_slug, o.logo_url,
               o.is_active AS org_active, o.accepting_applications,
               o.verification_status AS org_verification,
               a.city, a.province,
               TRIM(CONCAT(COALESCE(ip.first_name, ''), ' ', COALESCE(ip.last_name, ''))) AS inviter_name
        FROM organization_members m
        JOIN organizations o  ON o.id = m.organization_id
        LEFT JOIN addresses a ON a.id = o.address_id
        LEFT JOIN profiles ip ON ip.user_id = m.invited_by
        WHERE m.user_id = ?
        ORDER BY FIELD(m.status, 'invited', 'verified', 'pending', 'suspended', 'rejected'), o.name
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['brand_list'] = [];   // brands no longer limit a network (2a)
    }
    return $rows;
}

/**
 * The broker's main membership (verified first), or null when they're in
 * no network. Kept for callers that show a single line.
 */
function getBrokerMembership(int $userId): ?array
{
    foreach (brokerNetworks($userId) as $n) {
        if ($n['status'] !== 'invited') {   // an invitation isn't a membership yet
            return $n;
        }
    }
    return null;
}

/**
 * What the broker is allowed to add to their desk.
 *
 * @return array{mode:string, brands:array, org:?array, can_add:bool, message:string, car_limit:?int}
 */
function brokerCatalogueRules(int $userId): array
{
    // Open marketplace for everyone: a network adds perks (Phase 4) but
    // never limits listing. Keys kept for callers.
    return ['mode' => 'independent', 'brands' => [], 'org' => getBrokerMembership($userId), 'can_add' => true,
            'message' => '', 'car_limit' => null];
}

/**
 * The broker's network allowance.
 *
 * @return array{used:int, limit:?int, room:bool}
 */
function brokerNetworkAllowance(int $userId): array
{
    $used  = brokerNetworkCount($userId);
    $limit = entitlement('broker', $userId, 'max_networks');
    return ['used' => $used, 'limit' => $limit, 'room' => sdHasRoom($limit, $used)];
}

/**
 * Networks taking broker applications right now: an active business with
 * at least one active dealership and applications switched on.
 *
 * Each row: id, name, slug, logo_url, verification_status, city, province,
 * brand_list, dealer_count, live_cars, broker_count, broker_limit (?int),
 * full (bool), my_status (?string, when $userId is given).
 *
 * @return array<int, array>
 */
function listOpenNetworks(?int $userId = null, string $province = ''): array
{
    if (!sdNetworksReady()) {
        return [];
    }
    $params = [];
    $mine   = 'NULL';
    if ($userId) {
        $mine     = "(SELECT m2.status FROM organization_members m2 WHERE m2.organization_id = o.id AND m2.user_id = ? LIMIT 1)";
        $params[] = $userId;
    }
    $provSql = '';
    if ($province !== '') {
        $provSql  = ' AND a.province = ?';
        $params[] = $province;
    }
    $stmt = Database::getInstance()->prepare("
        SELECT o.id, o.name, o.slug, o.logo_url, o.verification_status,
               a.city, a.province,
               (SELECT d.brand_focus FROM dealers d WHERE d.organization_id = o.id ORDER BY d.id LIMIT 1) AS brand_focus,
               (SELECT COUNT(*) FROM dealers d WHERE d.organization_id = o.id AND d.is_active = 1)      AS dealer_count,
               (SELECT COUNT(*) FROM cars c JOIN dealers d ON d.id = c.dealer_id
                 WHERE d.organization_id = o.id AND c.status = 'active')                               AS live_cars,
               (SELECT COUNT(*) FROM organization_members m
                 WHERE m.organization_id = o.id AND m.status = 'verified')                             AS broker_count,
               {$mine} AS my_status
        FROM organizations o
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE o.is_active = 1 AND o.accepting_applications = 1
          AND EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = o.id AND d.is_active = 1){$provSql}
        ORDER BY live_cars DESC, o.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['brand_list']   = orgBrands($r['brand_focus']);
        $r['broker_limit'] = entitlement('organization', (int) $r['id'], 'max_network_brokers');
        $r['full']         = !sdHasRoom($r['broker_limit'], (int) $r['broker_count']);
    }
    return $rows;
}

/**
 * Admins who should hear about an application: managing admins of the
 * business's dealerships (and any legacy org managers). Nobody managing
 * it → the superadmins, so an application is never lost.
 *
 * @return array<int, array{id:int, email:string}>
 */
function sdNetworkReviewers(int $orgId): array
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.email FROM users u
        WHERE u.role = 'admin' AND u.status = 'active' AND (
               u.id IN (SELECT dm.admin_user_id FROM dealer_managers dm
                          JOIN dealers d ON d.id = dm.dealer_id WHERE d.organization_id = ?)
            OR u.id IN (SELECT om.admin_user_id FROM organization_managers om WHERE om.organization_id = ?)
        )
    ");
    $stmt->execute([$orgId, $orgId]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        return $rows;
    }
    try {
        return $pdo->query("
            SELECT u.id, u.email FROM users u
            WHERE u.role = 'admin' AND u.status = 'active' AND u.is_superadmin = 1
        ")->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * Apply to a business network. A declined application can be sent again.
 *
 * @return array{0:bool,1:string}
 */
function applyToNetwork(int $userId, int $orgId, string $brokerEmail): array
{
    if (!sdNetworksReady()) {
        return [false, 'Business networks aren’t available yet.'];
    }
    $pdo = Database::getInstance();

    $org = $pdo->prepare("
        SELECT o.id, o.name,
               (SELECT COUNT(*) FROM organization_members m
                 WHERE m.organization_id = o.id AND m.status = 'verified') AS broker_count
        FROM organizations o
        WHERE o.id = ? AND o.is_active = 1 AND o.accepting_applications = 1
          AND EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = o.id AND d.is_active = 1)
        LIMIT 1
    ");
    $org->execute([$orgId]);
    $org = $org->fetch();
    if (!$org) {
        return [false, 'That network isn’t taking applications right now.'];
    }

    $cur = $pdo->prepare("SELECT status FROM organization_members WHERE organization_id = ? AND user_id = ? LIMIT 1");
    $cur->execute([$orgId, $userId]);
    $status = $cur->fetchColumn();
    if ($status === 'pending') {
        return [false, "Your application to {$org['name']} is already waiting for review."];
    }
    if ($status === 'verified') {
        return [false, "You’re already in {$org['name']}’s network."];
    }
    if ($status === 'suspended') {
        return [false, "Your place in {$org['name']}’s network is paused. It has to be reinstated, not re-applied for."];
    }
    if ($status === 'invited') {
        return brokerRespondInvite($userId, $orgId, true);   // 2c: applying to a network that invited you = accept
    }

    $allow = brokerNetworkAllowance($userId);
    if (!$allow['room']) {
        return [false, 'Your plan lets you join up to ' . sdLimitText($allow['limit'], 'max_networks')
            . ($allow['limit'] === 1 ? ' network' : ' networks') . ', counting applications waiting for review. Withdraw or leave one, or upgrade to Pro for unlimited networks.'];
    }
    $orgLimit = entitlement('organization', $orgId, 'max_network_brokers');
    if (!sdHasRoom($orgLimit, (int) $org['broker_count'])) {
        return [false, "{$org['name']}’s network is full right now."];
    }

    $pdo->prepare("
        INSERT INTO organization_members
            (organization_id, user_id, role, status, verified_by, verified_at, rejection_reason, invited_by, joined_at)
        VALUES (?, ?, 'agent', 'pending', NULL, NULL, NULL, NULL, NOW())
        ON DUPLICATE KEY UPDATE
            role             = 'agent',
            status           = 'pending',
            verified_by      = NULL,
            verified_at      = NULL,
            rejection_reason = NULL,
            joined_at        = NOW()
    ")->execute([$orgId, $userId]);

    writeAuditLog('org.agent_applied', 'organization', $orgId, $status ? ['status' => $status] : null,
        ['user_id' => $userId, 'email' => $brokerEmail], $userId);

    // Tell the reviewers (in-app + email): the principal (2c, dealer
    // portal) and the admins managing the dealership.
    $notify = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'agent_application', ?, ?, ?, NOW())
    ");
    $told = [];
    foreach (sdNetworkPrincipals($orgId) as $pr) {
        $told[(int) $pr['id']] = true;
        $notify->execute([(int) $pr['id'], 'Network application',
            $brokerEmail . ' wants to join your broker network.',
            json_encode(['org_id' => $orgId, 'broker_user_id' => $userId])]);
        sendAgentApplication($pr['email'], $org['name'], $brokerEmail, SITE_URL . '/app/dealer/network');
    }
    foreach (sdNetworkReviewers($orgId) as $a) {
        if (isset($told[(int) $a['id']])) {
            continue;
        }
        $notify->execute([
            (int) $a['id'],
            'Network application — ' . $org['name'],
            $brokerEmail . ' wants to join ' . $org['name'] . '’s broker network.',
            json_encode(['org_id' => $orgId, 'broker_user_id' => $userId]),
        ]);
        sendAgentApplication($a['email'], $org['name'], $brokerEmail);
    }

    return [true, "Application sent to {$org['name']}. You’ll get an email once it’s reviewed."];
}

/**
 * Withdraw a pending application, leave a network, or clear a declined
 * application from the list. Cars already on the desk stay there.
 *
 * @return array{0:bool,1:string}
 */
function brokerLeaveNetwork(int $userId, int $orgId): array
{
    $pdo = Database::getInstance();
    $m   = $pdo->prepare("
        SELECT m.id, m.status, o.name FROM organization_members m
        JOIN organizations o ON o.id = m.organization_id
        WHERE m.organization_id = ? AND m.user_id = ? LIMIT 1
    ");
    $m->execute([$orgId, $userId]);
    $m = $m->fetch();
    if (!$m) {
        return [false, 'You’re not in that network.'];
    }
    $pdo->prepare("DELETE FROM organization_members WHERE id = ?")->execute([(int) $m['id']]);
    $paused = in_array($m['status'], ['verified', 'suspended'], true) ? sdNetworkMembershipChanged($userId) : 0;   // 4a
    writeAuditLog(match ($m['status']) {
            'pending'  => 'org.agent_withdrew',
            'rejected' => 'org.agent_cleared',
            'invited'  => 'org.invite_declined',
            default    => 'org.agent_left',
        }, 'organization', $orgId, ['status' => $m['status']], null, $userId);

    return [true, match ($m['status']) {
        'pending'  => "Application to {$m['name']} withdrawn.",
        'rejected' => "Removed {$m['name']} from your list.",
        'invited'  => "Invitation from {$m['name']} declined.",
        default    => "You’ve left {$m['name']}’s network. "
            . ($paused ? "{$paused} of its network-only car" . ($paused === 1 ? ' is' : 's are') . ' paused on your desk; other cars stay.' : 'Cars on your desk stay there.'),
    }];
}

/**
 * 4a: after a broker's membership changes, pause / bring back their
 * network-only desk cars. Returns how many were paused.
 */
function sdNetworkMembershipChanged(int $userId): int
{
    try {
        require_once __DIR__ . '/vip_network.php';
        return sdVipSyncBroker($userId)['parked'];
    } catch (Throwable $e) {
        error_log('[SalesDesk vip] broker sync failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * SQL fragment + params restricting cars (alias c) to a brand list,
 * including known alternative spellings (e.g. Volkswagen ↔ VW).
 */
function brandFilterSql(array $brands, string $alias = 'c'): array
{
    if (!$brands) {
        return ['', []];
    }
    $values = [];
    foreach ($brands as $b) {
        array_push($values, ...sdMakeVariants($b));
    }
    $values = array_values(array_unique($values));
    return [$alias . '.make IN (' . implode(',', array_fill(0, count($values), '?')) . ')', $values];
}


// ============================================================
// PLANS 2c — BUSINESS SIDE (dealer portal Network, shared rules)
// ============================================================

/** Can businesses invite brokers yet (0021 'invited' status)? */
function sdNetworkInvitesReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) Database::getInstance()->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members'
              AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%''invited''%'
        ")->fetchColumn();
    }
    return $ready;
}

/** Most invitations one business may send in 24 hours (anti-spam). */
const SD_NETWORK_INVITES_PER_DAY = 25;

/**
 * Who runs the network on the business side: the principal of a
 * single-dealership business, or (2d) the owner of a multi-dealership one.
 * Active accounts only.
 *
 * @return array<int, array{id:int, email:string}>
 */
function sdNetworkPrincipals(int $orgId): array
{
    if (!sdBusinessReady()) {
        return [];
    }
    $pdo = Database::getInstance();
    if (sdOrgDealerCount($orgId) > 1) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email FROM organizations o
            JOIN users u ON u.id = o.owner_user_id AND u.status = 'active'
            WHERE o.id = ?
        ");
        $stmt->execute([$orgId]);
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.email FROM dealers d
        JOIN users u ON u.id = d.user_id AND u.status = 'active'
        WHERE d.organization_id = ?
    ");
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

/** In-app notification to several users at once (ids de-duplicated). */
function sdNetworkNotify(array $userIds, string $type, string $title, string $body, array $meta): void
{
    $ins = Database::getInstance()->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid > 0) {
            $ins->execute([$uid, $type, $title, $body, json_encode($meta)]);
        }
    }
}

/**
 * The business's network at a glance, for its dealer-portal page and the
 * admin Business tab.
 *
 * @return array{org_id:int, name:string, slug:string, accepting:bool, verified:int, pending:int,
 *               invited:int, suspended:int, limit:?int, room:bool, can_invite:bool,
 *               invites_ready:bool, invites_today:int}|null
 */
function sdNetworkSummary(int $orgId): ?array
{
    $pdo = Database::getInstance();
    $o   = $pdo->prepare("SELECT id, name, slug, accepting_applications FROM organizations WHERE id = ? LIMIT 1");
    $o->execute([$orgId]);
    $o = $o->fetch();
    if (!$o) {
        return null;
    }
    $c = $pdo->prepare("SELECT status, COUNT(*) AS n FROM organization_members WHERE organization_id = ? GROUP BY status");
    $c->execute([$orgId]);
    $counts = array_column($c->fetchAll(), 'n', 'status');
    $today  = 0;
    if (sdNetworkInvitesReady()) {
        $t = $pdo->prepare("SELECT COUNT(*) FROM organization_members
                             WHERE organization_id = ? AND status = 'invited' AND joined_at > NOW() - INTERVAL 1 DAY");
        $t->execute([$orgId]);
        $today = (int) $t->fetchColumn();
    }
    $limit    = entitlement('organization', $orgId, 'max_network_brokers');
    $verified = (int) ($counts['verified'] ?? 0);
    return [
        'org_id'        => (int) $o['id'],
        'name'          => (string) $o['name'],
        'slug'          => (string) $o['slug'],
        'accepting'     => (bool) $o['accepting_applications'],
        'verified'      => $verified,
        'pending'       => (int) ($counts['pending'] ?? 0),
        'invited'       => (int) ($counts['invited'] ?? 0),
        'suspended'     => (int) ($counts['suspended'] ?? 0),
        'limit'         => $limit,
        'room'          => sdHasRoom($limit, $verified),
        'can_invite'    => canDo('organization', $orgId, 'can_invite_brokers'),
        'invites_ready' => sdNetworkInvitesReady(),
        'invites_today' => $today,
    ];
}

/**
 * Everyone connected to a network (any status), with what the business
 * needs to judge them: desk, cars on it, leads on this business's cars.
 */
function sdNetworkRoster(int $orgId): array
{
    $stmt = Database::getInstance()->prepare("
        SELECT m.id, m.user_id, m.status, m.joined_at, m.verified_at, m.rejection_reason,
               u.email, p.first_name, p.last_name, p.phone,
               pa.city, pa.province,
               sd.display_name AS desk_name, sd.slug AS desk_slug, sd.is_active AS desk_active,
               (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.salesdesk_id = sd.id) AS desk_cars,
               (SELECT COUNT(*) FROM leads l JOIN cars c ON c.id = l.car_id JOIN dealers d ON d.id = c.dealer_id
                 WHERE l.broker_id = m.user_id AND d.organization_id = m.organization_id) AS network_leads,
               (SELECT COUNT(*) FROM organization_members m3
                 WHERE m3.user_id = m.user_id AND m3.status = 'verified' AND m3.organization_id <> m.organization_id) AS other_networks
        FROM organization_members m
        JOIN users u            ON u.id = m.user_id
        LEFT JOIN profiles p    ON p.user_id = m.user_id
        LEFT JOIN addresses pa  ON pa.id = p.address_id
        LEFT JOIN salesdesks sd ON sd.user_id = m.user_id
        WHERE m.organization_id = ?
        ORDER BY FIELD(m.status, 'pending', 'verified', 'invited', 'suspended', 'rejected'),
                 m.joined_at ASC
    ");
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

/**
 * Open or close a business's network to broker applications.
 * Only an online dealership's network can be opened.
 *
 * @return array{0:bool,1:string}
 */
function sdSetNetworkOpen(int $dealerId, bool $open, int $actorId): array
{
    if (!sdBusinessReady()) {
        return [false, 'Business networks aren’t available yet.'];
    }
    $orgId = sdEnsureDealerOrganization($dealerId, $actorId);
    if (!$orgId) {
        return [false, 'This dealership’s business account couldn’t be loaded.'];
    }
    $pdo = Database::getInstance();
    $d   = $pdo->prepare("SELECT is_active FROM dealers WHERE id = ?");
    $d->execute([$dealerId]);
    if ($open && !(int) $d->fetchColumn()) {
        return [false, 'Bring the dealership online before opening its network.'];
    }
    $pdo->prepare("UPDATE organizations SET accepting_applications = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$open ? 1 : 0, $orgId]);
    writeAuditLog($open ? 'org.network_opened' : 'org.network_closed', 'organization', $orgId,
        null, ['dealer_id' => $dealerId], $actorId);
    return [true, $open
        ? 'Network open: brokers can now find it on My networks and apply.'
        : 'Network closed to new applications. Current brokers stay in it.'];
}

/**
 * Review one network membership. Callers check WHO may act (dealer
 * workspace or admin scope); this checks WHAT may happen:
 *   approve / reject  pending
 *   suspend           verified
 *   reinstate         suspended
 *   remove            verified, suspended, rejected
 *   cancel            invited (withdraw the invitation)
 * Approving or reinstating respects the plan's network size.
 *
 * @return array{0:bool,1:string}
 */
function sdNetworkReview(int $orgId, int $memberId, string $action, string $reason, int $actorId): array
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT m.id, m.user_id, m.organization_id, m.status,
               o.name AS org_name,
               u.email, p.first_name, p.last_name
        FROM organization_members m
        JOIN organizations o ON o.id = m.organization_id
        JOIN users u         ON u.id = m.user_id
        LEFT JOIN profiles p ON p.user_id = m.user_id
        WHERE m.id = ? AND m.organization_id = ?
        LIMIT 1
    ");
    $stmt->execute([$memberId, $orgId]);
    $m = $stmt->fetch();
    if (!$m) {
        return [false, 'That broker isn’t in this network.'];
    }

    $prev   = $m['status'];
    $name   = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: $m['email'];
    $org    = $m['org_name'];
    $reason = mb_substr(trim($reason), 0, 255);

    $allowed = [
        'approve'   => ['pending'],
        'reject'    => ['pending'],
        'suspend'   => ['verified'],
        'reinstate' => ['suspended'],
        'remove'    => ['verified', 'suspended', 'rejected'],
        'cancel'    => ['invited'],
    ];
    if (!isset($allowed[$action]) || !in_array($prev, $allowed[$action], true)) {
        $label = ['invited' => 'invited', 'pending' => 'waiting for review'][$prev] ?? $prev;
        return [false, "Can’t {$action} a broker who is {$label}."];
    }

    if ($action === 'remove' || $action === 'cancel') {
        $pdo->prepare("DELETE FROM organization_members WHERE id = ?")->execute([$memberId]);
        writeAuditLog($action === 'cancel' ? 'org.invite_cancelled' : 'org.agent_removed', 'organization', $orgId,
            ['user_id' => (int) $m['user_id'], 'status' => $prev], null, $actorId);
        if ($action === 'remove' && $prev !== 'rejected') {
            sdNetworkMembershipChanged((int) $m['user_id']);   // 4a: network-only cars leave their desk
        }
        if ($action === 'remove' && $prev !== 'rejected') {
            sdNetworkNotify([(int) $m['user_id']], 'agent_status', "You’ve been removed from {$org}’s network",
                'You can keep sharing any car on SalesDesk except its network-only stock, or join another network.', ['org_id' => $orgId]);
        }
        return [true, $action === 'cancel'
            ? "Invitation to {$name} withdrawn."
            : "{$name} removed from {$org}’s network."];
    }

    if (in_array($action, ['approve', 'reinstate'], true)) {
        $limit = entitlement('organization', $orgId, 'max_network_brokers');
        $count = $pdo->prepare("SELECT COUNT(*) FROM organization_members WHERE organization_id = ? AND status = 'verified'");
        $count->execute([$orgId]);
        $used = (int) $count->fetchColumn();
        if (!sdHasRoom($limit, $used)) {
            return [false, "{$org}’s network is full ({$used} of " . sdLimitText($limit, 'max_network_brokers')
                . ' brokers on its plan). Remove a broker, or upgrade the business plan.'];
        }
    }

    $new = match ($action) {
        'approve', 'reinstate' => 'verified',
        'reject'               => 'rejected',
        'suspend'              => 'suspended',
    };
    $pdo->prepare("
        UPDATE organization_members
        SET status = ?, verified_by = ?, verified_at = NOW(),
            rejection_reason = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$new, $actorId, in_array($new, ['rejected', 'suspended'], true) ? ($reason ?: null) : null, $memberId]);
    if (in_array($new, ['verified', 'suspended'], true)) {
        sdNetworkMembershipChanged((int) $m['user_id']);   // 4a: network-only cars follow membership
    }

    $auditAction = [
        'approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'reinstated',
    ][$action];
    writeAuditLog('org.agent_' . $auditAction,
        'organization', $orgId,
        ['user_id' => (int) $m['user_id'], 'status' => $prev],
        ['user_id' => (int) $m['user_id'], 'status' => $new, 'reason' => $reason, 'by' => $actorId], $actorId);

    sdNetworkNotify([(int) $m['user_id']], 'agent_status',
        match ($new) {
            'verified'  => "You’re in {$org}’s network",
            'rejected'  => "Application to {$org} declined",
            'suspended' => "Your place in {$org}’s network is paused",
        },
        $reason ?: match ($new) {
            'verified'  => 'Leads on its cars now count towards the network. Your commission is still paid to you.',
            'rejected'  => 'You can keep sharing any car, or apply to another network.',
            'suspended' => 'You can still share any car except its network-only stock. You’re not counted as part of this network until reinstated.',
        },
        ['org_id' => $orgId]);

    if ($new === 'verified' && $action === 'approve') {
        sendAgentApproved($m['email'], $name, $org);
    } elseif ($new === 'rejected') {
        sendAgentRejected($m['email'], $name, $org, $reason);
    }

    return [true, match ($action) {
        'approve'   => "{$name} is now in {$org}’s network.",
        'reject'    => "{$name}’s application to {$org} was declined.",
        'suspend'   => "{$name} suspended in {$org}’s network.",
        'reinstate' => "{$name} reinstated in {$org}’s network.",
    }];
}

/**
 * Brokers a business can browse: active brokers with an active desk.
 * Shows public desk details only (no email or phone) plus their status
 * with this network.
 *
 * @return array{rows: array, total: int, page: int, pages: int}
 */
function sdBrowseBrokers(int $orgId, string $q = '', string $province = '', int $page = 1, int $perPage = 24): array
{
    $pdo    = Database::getInstance();
    $where  = ["u.role = 'broker'", "u.status = 'active'", 'sd.is_active = 1'];
    $params = [];
    $q      = trim(mb_substr($q, 0, 80));
    if ($q !== '') {
        $where[] = "(sd.display_name LIKE ? OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE ? OR sd.slug LIKE ?)";
        $like    = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like, $like);
    }
    if ($province !== '') {
        $where[]  = 'pa.province = ?';
        $params[] = $province;
    }
    $from = "
        FROM salesdesks sd
        JOIN users u           ON u.id = sd.user_id
        LEFT JOIN profiles p   ON p.user_id = u.id
        LEFT JOIN addresses pa ON pa.id = p.address_id
        WHERE " . implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) {$from}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($page, $pages));

    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, p.first_name, p.last_name,
               sd.display_name AS desk_name, sd.slug AS desk_slug,
               pa.city, pa.province,
               (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.salesdesk_id = sd.id)     AS desk_cars,
               (SELECT COUNT(*) FROM leads l WHERE l.broker_id = u.id AND l.status = 'closed') AS deals_closed,
               (SELECT COUNT(*) FROM organization_members m2
                 WHERE m2.user_id = u.id AND m2.status = 'verified')                          AS networks,
               (SELECT m.status FROM organization_members m
                 WHERE m.user_id = u.id AND m.organization_id = ? LIMIT 1)                    AS my_status,
               (SELECT m.id FROM organization_members m
                 WHERE m.user_id = u.id AND m.organization_id = ? LIMIT 1)                    AS member_id
        {$from}
        ORDER BY desk_cars DESC, deals_closed DESC, sd.display_name
        LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
    );
    $stmt->execute(array_merge([$orgId, $orgId], $params));
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Provinces that have at least one active broker desk (Find brokers filter). */
function sdBrokerProvinces(): array
{
    return Database::getInstance()->query("
        SELECT DISTINCT pa.province FROM salesdesks sd
        JOIN users u           ON u.id = sd.user_id AND u.role = 'broker' AND u.status = 'active'
        JOIN profiles p        ON p.user_id = u.id
        JOIN addresses pa      ON pa.id = p.address_id
        WHERE sd.is_active = 1 AND pa.province IS NOT NULL AND pa.province <> ''
        ORDER BY pa.province
    ")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Invite a broker into the network (Pro / Enterprise: can_invite_brokers).
 * Open invitations plus members can't exceed the network size, and a
 * business can send at most SD_NETWORK_INVITES_PER_DAY a day.
 *
 * @return array{0:bool,1:string}
 */
function sdInviteBroker(int $orgId, int $brokerId, int $actorId): array
{
    if (!sdNetworkInvitesReady()) {
        return [false, 'Run 0021 Network invites in Admin → Migrations first.'];
    }
    $sum = sdNetworkSummary($orgId);
    if (!$sum) {
        return [false, 'This business account couldn’t be loaded.'];
    }
    if (!$sum['can_invite']) {
        return [false, 'Inviting brokers is part of the Pro and Enterprise business plans.'];
    }
    if ($sum['invites_today'] >= SD_NETWORK_INVITES_PER_DAY) {
        return [false, 'That’s ' . SD_NETWORK_INVITES_PER_DAY . ' invitations in the last 24 hours. Try again tomorrow.'];
    }

    $pdo = Database::getInstance();
    $b   = $pdo->prepare("
        SELECT u.id, u.email, p.first_name, p.last_name FROM users u
        JOIN salesdesks sd   ON sd.user_id = u.id AND sd.is_active = 1
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.role = 'broker' AND u.status = 'active' LIMIT 1
    ");
    $b->execute([$brokerId]);
    $b = $b->fetch();
    if (!$b) {
        return [false, 'That broker wasn’t found.'];
    }
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'This broker';

    $cur = $pdo->prepare("SELECT status FROM organization_members WHERE organization_id = ? AND user_id = ? LIMIT 1");
    $cur->execute([$orgId, $brokerId]);
    $status = $cur->fetchColumn();
    if ($status && $status !== 'rejected') {
        return [false, match ($status) {
            'pending'   => "{$name} has already applied. Approve their application instead.",
            'verified'  => "{$name} is already in your network.",
            'invited'   => "{$name} already has an invitation from you.",
            'suspended' => "{$name} is in your network but suspended. Reinstate them instead.",
            default     => "{$name} can’t be invited right now.",
        }];
    }

    if (!sdHasRoom($sum['limit'], $sum['verified'] + $sum['invited'])) {
        return [false, 'Your network is full, counting open invitations (' . sdLimitText($sum['limit'], 'max_network_brokers')
            . ' on your plan). Withdraw an invitation or remove a broker first.'];
    }

    $pdo->prepare("
        INSERT INTO organization_members
            (organization_id, user_id, role, status, verified_by, verified_at, rejection_reason, invited_by, joined_at)
        VALUES (?, ?, 'agent', 'invited', NULL, NULL, NULL, ?, NOW())
        ON DUPLICATE KEY UPDATE
            role = 'agent', status = 'invited', verified_by = NULL, verified_at = NULL,
            rejection_reason = NULL, invited_by = VALUES(invited_by), joined_at = NOW()
    ")->execute([$orgId, $brokerId, $actorId]);

    writeAuditLog('org.broker_invited', 'organization', $orgId, $status ? ['status' => $status] : null,
        ['user_id' => $brokerId], $actorId);

    sdNetworkNotify([$brokerId], 'network_invite', "{$sum['name']} invited you to its network",
        'Accept or decline on My networks. You can still share any car, and your commission is paid to you.',
        ['org_id' => $orgId]);
    sendNetworkInvite((string) $b['email'], $name, $sum['name']);

    return [true, "Invitation sent to {$name}."];
}

/**
 * The broker accepts or declines an invitation.
 *
 * @return array{0:bool,1:string}
 */
function brokerRespondInvite(int $userId, int $orgId, bool $accept): array
{
    $pdo = Database::getInstance();
    $m   = $pdo->prepare("
        SELECT m.id, m.invited_by, o.name, o.is_active FROM organization_members m
        JOIN organizations o ON o.id = m.organization_id
        WHERE m.organization_id = ? AND m.user_id = ? AND m.status = 'invited' LIMIT 1
    ");
    $m->execute([$orgId, $userId]);
    $m = $m->fetch();
    if (!$m) {
        return [false, 'That invitation is no longer open.'];
    }
    if (!$accept) {
        return brokerLeaveNetwork($userId, $orgId);
    }
    if (!(int) $m['is_active']) {
        return [false, "{$m['name']} isn’t active on SalesDesk right now."];
    }
    $allow = brokerNetworkAllowance($userId);
    if (!$allow['room']) {
        return [false, 'Your plan lets you join up to ' . sdLimitText($allow['limit'], 'max_networks')
            . ($allow['limit'] === 1 ? ' network' : ' networks') . '. Leave one, or upgrade to Pro, then accept.'];
    }
    $limit = entitlement('organization', $orgId, 'max_network_brokers');
    $c = $pdo->prepare("SELECT COUNT(*) FROM organization_members WHERE organization_id = ? AND status = 'verified'");
    $c->execute([$orgId]);
    if (!sdHasRoom($limit, (int) $c->fetchColumn())) {
        return [false, "{$m['name']}’s network is full right now."];
    }

    $pdo->prepare("
        UPDATE organization_members
        SET status = 'verified', verified_by = invited_by, verified_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([(int) $m['id']]);
    writeAuditLog('org.invite_accepted', 'organization', $orgId, ['status' => 'invited'],
        ['user_id' => $userId, 'status' => 'verified'], $userId);
    sdNetworkMembershipChanged($userId);   // 4a: paused network-only cars come back

    $broker = getUserById($userId);
    $who    = $broker ? (string) $broker['email'] : 'A broker';
    sdNetworkNotify(array_merge([(int) $m['invited_by']], array_column(sdNetworkPrincipals($orgId), 'id')),
        'network_joined', 'A broker joined your network', "{$who} accepted your invitation.", ['org_id' => $orgId]);

    return [true, "You’re in {$m['name']}’s network."];
}

/**
 * 2d: may this dealer-portal workspace manage the business's network?
 * One business = one network: in a multi-dealership business only the
 * business owner (principal or 'group' mode) and SalesDesk staff with
 * write access run it; the other dealerships' principals see it read-only.
 */
function sdCanManageNetwork(array $ws, int $orgId): bool
{
    if (empty($ws['can_write'])) {
        return false;
    }
    if (($ws['mode'] ?? '') !== 'principal' || sdOrgDealerCount($orgId) < 2) {
        return true;
    }
    $o = Database::getInstance()->prepare("SELECT owner_user_id FROM organizations WHERE id = ?");
    $o->execute([$orgId]);
    return (int) $o->fetchColumn() === (int) $ws['actor_id'];
}
