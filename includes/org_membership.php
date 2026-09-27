<?php
/**
 * SalesDesk — Desk organisation membership (broker side)  (0013)
 *
 * A desk organisation is an admin-run online dealership that sells
 * 1–3 brands. Brokers join one as AGENTS:
 *
 *   apply (signup step 3 or /app/broker/desk-org)
 *     → organization_members.status = 'pending'
 *     → managing admins approve / decline (app/admin/approvals)
 *   verified   → marketplace limited to the org's brands, org badge,
 *                leads tagged with the org
 *   rejected   → broker is independent again, may apply elsewhere
 *   suspended  → can't add cars until reinstated
 *
 * No membership row = independent broker (full marketplace).
 * One membership per broker (UNIQUE organization_members.user_id).
 * The org takes no cut — commission still goes to the agent.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/filter-whitelists.php';

/** Decode organizations.brands (JSON) into a clean array. */
function orgBrands(?string $json): array
{
    $arr = $json ? json_decode($json, true) : null;
    return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
}

/**
 * The broker's membership (any status) with org details, or null when
 * independent.
 */
function getBrokerMembership(int $userId): ?array
{
    if (!sdOrgSchemaReady()) {
        return null;
    }
    $stmt = Database::getInstance()->prepare("
        SELECT m.id AS member_id, m.status, m.rejection_reason, m.joined_at, m.verified_at,
               o.id AS org_id, o.name AS org_name, o.slug AS org_slug, o.brands,
               o.description, o.logo_url, o.agent_car_limit, o.is_active AS org_active,
               o.verification_status AS org_verification
        FROM organization_members m
        JOIN organizations o ON o.id = m.organization_id
        WHERE m.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['brand_list'] = orgBrands($row['brands']);
    return $row;
}

/**
 * What the broker is allowed to add to their desk.
 *
 * @return array{mode:string, brands:array, org:?array, can_add:bool, message:string, car_limit:?int}
 *   mode: 'independent' | 'agent' | 'pending' | 'suspended'
 */
function brokerCatalogueRules(int $userId): array
{
    $m = getBrokerMembership($userId);

    // No membership, or a declined application → independent.
    if (!$m || $m['status'] === 'rejected') {
        return ['mode' => 'independent', 'brands' => [], 'org' => $m, 'can_add' => true, 'message' => '', 'car_limit' => null];
    }

    $name = $m['org_name'];
    if ($m['status'] === 'pending') {
        return ['mode' => 'pending', 'brands' => $m['brand_list'], 'org' => $m, 'can_add' => false,
                'message' => "Your application to {$name} is waiting for approval. You can browse its cars, but can't add them yet.",
                'car_limit' => null];
    }
    if ($m['status'] === 'suspended' || !$m['org_active']) {
        return ['mode' => 'suspended', 'brands' => $m['brand_list'], 'org' => $m, 'can_add' => false,
                'message' => $m['status'] === 'suspended'
                    ? "Your agent access at {$name} is suspended. Contact SalesDesk to be reinstated."
                    : "{$name} is currently inactive, so new cars can't be added.",
                'car_limit' => null];
    }

    // Verified agent. Empty brand list (legacy org not yet configured) = unrestricted.
    return ['mode' => 'agent', 'brands' => $m['brand_list'], 'org' => $m, 'can_add' => true, 'message' => '',
            'car_limit' => $m['agent_car_limit'] !== null ? (int) $m['agent_car_limit'] : null];
}

/** Orgs a broker can apply to right now. */
function listOpenDeskOrgs(): array
{
    if (!sdOrgSchemaReady()) {
        return [];
    }
    $rows = Database::getInstance()->query("
        SELECT o.id, o.name, o.slug, o.brands, o.description, o.logo_url, o.verification_status,
               a.city, a.province,
               (SELECT COUNT(*) FROM organization_members m
                 WHERE m.organization_id = o.id AND m.status = 'verified') AS agent_count
        FROM organizations o
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE o.is_active = 1 AND o.accepting_applications = 1
        ORDER BY o.name
    ")->fetchAll();
    foreach ($rows as &$r) {
        $r['brand_list'] = orgBrands($r['brands']);
    }
    return $rows;
}

/**
 * Apply (or re-apply) to a desk organisation.
 * Replaces a rejected / withdrawn application; refuses if the broker is
 * already pending/verified/suspended somewhere.
 *
 * @return array{0:bool,1:string}
 */
function applyBrokerToOrg(int $userId, int $orgId, string $brokerEmail): array
{
    $pdo = Database::getInstance();

    $org = $pdo->prepare("
        SELECT id, name FROM organizations
        WHERE id = ? AND is_active = 1 AND accepting_applications = 1 LIMIT 1
    ");
    $org->execute([$orgId]);
    $org = $org->fetch();
    if (!$org) {
        return [false, 'That desk organisation isn\'t accepting applications.'];
    }

    $current = getBrokerMembership($userId);
    if ($current && $current['status'] !== 'rejected') {
        return [false, $current['status'] === 'pending'
            ? "You already have an application waiting at {$current['org_name']}. Withdraw it first."
            : "You're already an agent at {$current['org_name']}. Leave it first."];
    }

    $pdo->prepare("
        INSERT INTO organization_members
            (organization_id, user_id, role, status, verified_by, verified_at, rejection_reason, invited_by, joined_at)
        VALUES (?, ?, 'agent', 'pending', NULL, NULL, NULL, NULL, NOW())
        ON DUPLICATE KEY UPDATE
            organization_id  = VALUES(organization_id),
            role             = 'agent',
            status           = 'pending',
            verified_by      = NULL,
            verified_at      = NULL,
            rejection_reason = NULL,
            joined_at        = NOW()
    ")->execute([$orgId, $userId]);

    writeAuditLog('org.agent_applied', 'organization', $orgId, null,
        ['user_id' => $userId, 'email' => $brokerEmail], $userId);

    // Tell every managing admin (in-app + email).
    $admins = $pdo->prepare("
        SELECT u.id, u.email FROM organization_managers om
        JOIN users u ON u.id = om.admin_user_id AND u.status = 'active' AND u.role = 'admin'
        WHERE om.organization_id = ?
    ");
    $admins->execute([$orgId]);
    $notify = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'agent_application', ?, ?, ?, NOW())
    ");
    foreach ($admins->fetchAll() as $a) {
        $notify->execute([
            (int) $a['id'],
            'Agent application — ' . $org['name'],
            $brokerEmail . ' wants to join ' . $org['name'] . ' as an agent.',
            json_encode(['org_id' => $orgId, 'broker_user_id' => $userId]),
        ]);
        sendAgentApplication($a['email'], $org['name'], $brokerEmail);
    }

    return [true, "Application sent to {$org['name']}. You'll get an email once it's reviewed."];
}

/**
 * Withdraw a pending application, or leave an org. The broker becomes
 * independent. Cars already on their desk stay there.
 */
function brokerLeaveOrg(int $userId): array
{
    $m = getBrokerMembership($userId);
    if (!$m) {
        return [false, 'You\'re not in a desk organisation.'];
    }
    Database::getInstance()->prepare("DELETE FROM organization_members WHERE user_id = ?")->execute([$userId]);
    writeAuditLog($m['status'] === 'pending' ? 'org.agent_withdrew' : 'org.agent_left',
        'organization', (int) $m['org_id'], ['status' => $m['status']], null, $userId);
    return [true, $m['status'] === 'pending'
        ? "Application to {$m['org_name']} withdrawn. You're an independent broker."
        : "You've left {$m['org_name']}. You're now an independent broker."];
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
