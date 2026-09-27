<?php
/**
 * SalesDesk — Admin-managed dealerships & desk organisations  (0012)
 *
 * Shared helpers for:
 *   app/admin/dealerships.php       — list + create dealerships
 *   app/admin/dealerships-view.php  — manage one dealership (team, details, managers)
 *   app/admin/approvals.php         — pending sales exec + agent requests (0013)
 *   app/admin/desk-orgs.php         — list + create desk organisations
 *   app/admin/desk-orgs-view.php    — manage one organisation (requests, agents, details, managers)
 *
 * SCOPING RULE: an admin only sees and acts on dealerships / orgs where
 * they have a row in dealer_managers / organization_managers. Every
 * page loads its entity through the scoped getters below — never by id
 * alone — so a tampered id in a POST can't reach someone else's entity.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/filter-whitelists.php';
require_once __DIR__ . '/org_membership.php';

const SD_PROVINCES = [
    'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
    'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
];


// ============================================================
// SCOPE CHECKS
// ============================================================

function adminManagesDealer(int $adminId, int $dealerId): bool
{
    $stmt = Database::getInstance()->prepare(
        "SELECT 1 FROM dealer_managers WHERE dealer_id = ? AND admin_user_id = ? LIMIT 1"
    );
    $stmt->execute([$dealerId, $adminId]);
    return (bool) $stmt->fetchColumn();
}

function adminManagesOrg(int $adminId, int $orgId): bool
{
    $stmt = Database::getInstance()->prepare(
        "SELECT 1 FROM organization_managers WHERE organization_id = ? AND admin_user_id = ? LIMIT 1"
    );
    $stmt->execute([$orgId, $adminId]);
    return (bool) $stmt->fetchColumn();
}

/** Dealership row (with address) if this admin manages it, else false. */
function getManagedDealer(int $adminId, int $dealerId): array|false
{
    $stmt = Database::getInstance()->prepare("
        SELECT d.*, a.province, a.city, a.suburb, a.street_line1, a.postal_code,
               pu.email AS principal_email
        FROM dealers d
        JOIN dealer_managers dm ON dm.dealer_id = d.id AND dm.admin_user_id = ?
        LEFT JOIN addresses a  ON a.id  = d.address_id
        LEFT JOIN users pu     ON pu.id = d.user_id
        WHERE d.id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId, $dealerId]);
    return $stmt->fetch();
}

/** Organisation row (with address) if this admin manages it, else false. */
function getManagedOrg(int $adminId, int $orgId): array|false
{
    $stmt = Database::getInstance()->prepare("
        SELECT o.*, a.province, a.city, a.suburb
        FROM organizations o
        JOIN organization_managers om ON om.organization_id = o.id AND om.admin_user_id = ?
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId, $orgId]);
    return $stmt->fetch();
}


// ============================================================
// SHARED UTILITIES
// ============================================================

/** Unique URL slug for dealers / organizations. */
function adminUniqueSlug(string $table, string $name, int $maxLen, ?int $ignoreId = null): string
{
    if (!in_array($table, ['dealers', 'organizations'], true)) {
        throw new InvalidArgumentException('Bad slug table');
    }
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $base = trim(substr($base, 0, $maxLen - 4), '-') ?: ($table === 'dealers' ? 'dealer' : 'org');
    $pdo  = Database::getInstance();
    $slug = $base;
    $n    = 2;
    while (true) {
        $sql    = "SELECT id FROM {$table} WHERE slug = ?" . ($ignoreId ? " AND id != ?" : '') . " LIMIT 1";
        $check  = $pdo->prepare($sql);
        $check->execute($ignoreId ? [$slug, $ignoreId] : [$slug]);
        if (!$check->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

/**
 * Create or update an address row from POST-style fields.
 * Returns the address id (or null if every field is blank and there was none).
 */
function adminSaveAddress(?int $addressId, array $in): ?int
{
    $fields = [
        'province'     => in_array($in['province'] ?? '', SD_PROVINCES, true) ? $in['province'] : null,
        'city'         => trim($in['city'] ?? '') ?: null,
        'suburb'       => trim($in['suburb'] ?? '') ?: null,
        'street_line1' => trim($in['street_line1'] ?? '') ?: null,
        'postal_code'  => trim($in['postal_code'] ?? '') ?: null,
    ];
    foreach ($fields as $k => $v) {
        if ($v !== null) {
            $fields[$k] = mb_substr($v, 0, $k === 'postal_code' ? 10 : 120);
        }
    }
    $pdo = Database::getInstance();

    if ($addressId) {
        $pdo->prepare("
            UPDATE addresses
            SET province = ?, city = ?, suburb = ?, street_line1 = ?, postal_code = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([...array_values($fields), $addressId]);
        return $addressId;
    }

    if (!array_filter($fields)) {
        return null;
    }
    $pdo->prepare("
        INSERT INTO addresses (province, city, suburb, street_line1, postal_code, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ")->execute(array_values($fields));
    return (int) $pdo->lastInsertId();
}

/** "Toyota, Ford" → '["Toyota","Ford"]' (or null). */
function adminBrandFocusJson(string $raw): ?string
{
    $brands = array_values(array_unique(array_filter(array_map(
        static fn($b) => mb_substr(trim($b), 0, 40),
        explode(',', $raw)
    ))));
    return $brands ? json_encode(array_slice($brands, 0, 20), JSON_UNESCAPED_UNICODE) : null;
}

/** '["Toyota","Ford"]' → "Toyota, Ford" */
function adminBrandFocusText(?string $json): string
{
    $arr = $json ? json_decode($json, true) : null;
    return is_array($arr) ? implode(', ', $arr) : '';
}

/** Only http(s) URLs are stored for logos. */
function adminCleanUrl(string $raw): ?string
{
    $url = trim($raw);
    if ($url === '') {
        return null;
    }
    return (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url))
        ? mb_substr($url, 0, 512)
        : null;
}

/** Look up an active admin by email (for adding co-managers). */
function adminFindActiveAdmin(string $email): array|false
{
    $stmt = Database::getInstance()->prepare(
        "SELECT id, email FROM users WHERE email = ? AND role = 'admin' AND status = 'active' LIMIT 1"
    );
    $stmt->execute([trim($email)]);
    return $stmt->fetch();
}

/**
 * Managers of a dealership or org.
 * @param 'dealer'|'org' $type
 */
function adminListManagers(string $type, int $entityId): array
{
    [$table, $col] = $type === 'dealer'
        ? ['dealer_managers', 'dealer_id']
        : ['organization_managers', 'organization_id'];

    $stmt = Database::getInstance()->prepare("
        SELECT m.admin_user_id, m.created_at, u.email, p.first_name, p.last_name
        FROM {$table} m
        JOIN users u ON u.id = m.admin_user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE m.{$col} = ?
        ORDER BY m.id
    ");
    $stmt->execute([$entityId]);
    return $stmt->fetchAll();
}

/**
 * Add / remove a co-manager. Returns [ok(bool), message].
 * @param 'dealer'|'org' $type
 */
function adminAddManager(string $type, int $entityId, string $email, int $actorId): array
{
    $admin = adminFindActiveAdmin($email);
    if (!$admin) {
        return [false, 'No active admin account uses that email.'];
    }
    [$table, $col, $entity] = $type === 'dealer'
        ? ['dealer_managers', 'dealer_id', 'dealer']
        : ['organization_managers', 'organization_id', 'organization'];

    $stmt = Database::getInstance()->prepare("
        INSERT IGNORE INTO {$table} ({$col}, admin_user_id, added_by, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$entityId, (int) $admin['id'], $actorId]);
    if ($stmt->rowCount() === 0) {
        return [false, $admin['email'] . ' already manages this.'];
    }
    writeAuditLog("{$entity}.manager_added", $entity, $entityId, null,
        ['admin_user_id' => (int) $admin['id'], 'email' => $admin['email']], $actorId);
    return [true, $admin['email'] . ' can now manage this.'];
}

function adminRemoveManager(string $type, int $entityId, int $targetAdminId, int $actorId): array
{
    [$table, $col, $entity] = $type === 'dealer'
        ? ['dealer_managers', 'dealer_id', 'dealer']
        : ['organization_managers', 'organization_id', 'organization'];
    $pdo = Database::getInstance();

    $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = ?");
    $count->execute([$entityId]);
    if ((int) $count->fetchColumn() <= 1) {
        return [false, 'There must always be at least one managing admin.'];
    }
    $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ? AND admin_user_id = ?")
        ->execute([$entityId, $targetAdminId]);
    writeAuditLog("{$entity}.manager_removed", $entity, $entityId, null,
        ['admin_user_id' => $targetAdminId], $actorId);
    return [true, $targetAdminId === $actorId ? 'You no longer manage this.' : 'Manager removed.'];
}


// ============================================================
// DEALERSHIP ONLINE / OFFLINE (admin-managed only)
// Principal-linked dealerships keep using suspendDealer() /
// reinstateDealer() from Users & Verifications, which suspend the
// principal's login. An admin-managed dealership has no login, so
// "offline" = dealers.is_active = 0 + its live listings paused.
// ============================================================

/**
 * @return array{0:bool,1:string}
 */
function adminSetDealerOnline(int $adminId, int $dealerId, bool $online): array
{
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dealers SET is_active = ?, updated_at = NOW() WHERE id = ? AND user_id IS NULL")
            ->execute([$online ? 1 : 0, $dealerId]);

        if ($online) {
            // Restore paused listings — but not ones belonging to an exec
            // who is still suspended/rejected (those stay paused).
            $cars = $pdo->prepare("
                UPDATE cars c
                LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
                SET c.status = 'active', c.updated_at = NOW()
                WHERE c.dealer_id = ? AND c.status = 'paused'
                  AND (c.uploaded_by_exec_id IS NULL OR se.verification_status = 'verified')
            ");
        } else {
            $cars = $pdo->prepare("
                UPDATE cars SET status = 'paused', updated_at = NOW()
                WHERE dealer_id = ? AND status = 'active'
            ");
        }
        $cars->execute([$dealerId]);
        $count = $cars->rowCount();

        writeAuditLog($online ? 'dealer.brought_online' : 'dealer.taken_offline', 'dealer', $dealerId,
            ['is_active' => $online ? 0 : 1],
            ['is_active' => $online ? 1 : 0, $online ? 'cars_restored' : 'cars_paused' => $count],
            $adminId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, adminDbErrorMessage($e, 'adminSetDealerOnline', 'change the dealership status')];
    }

    return [true, $online
        ? "Dealership back online. {$count} listing(s) restored."
        : "Dealership taken offline. {$count} listing(s) paused."];
}


// ============================================================
// SALES EXEC REVIEW (admin side)
// Mirrors app/dealer/team.php's principal actions so both paths
// write the same columns, audit entries and emails.
// ============================================================

/**
 * Apply approve / reject / suspend / reinstate to one sales exec,
 * but only if the exec belongs to a dealership this admin manages.
 *
 * @return array{0:bool,1:string}  [ok, flash message]
 */
function adminReviewSalesExec(int $adminId, int $execId, string $action, string $reason = ''): array
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT se.id, se.user_id, se.dealer_id, se.verification_status,
               d.company_name AS dealer_name,
               u.email, p.first_name, p.last_name
        FROM sales_executives se
        JOIN dealers d          ON d.id = se.dealer_id
        JOIN dealer_managers dm ON dm.dealer_id = d.id AND dm.admin_user_id = ?
        JOIN users u            ON u.id = se.user_id
        LEFT JOIN profiles p    ON p.user_id = se.user_id
        WHERE se.id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId, $execId]);
    $exec = $stmt->fetch();

    if (!$exec) {
        return [false, 'That application was not found, or it belongs to a dealership you don\'t manage.'];
    }

    $prev   = $exec['verification_status'];
    $name   = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? '')) ?: $exec['email'];
    $dealer = $exec['dealer_name'];
    $reason = mb_substr(trim($reason), 0, 255);

    $allowed = [
        'approve'   => ['pending'],
        'reject'    => ['pending', 'verified'],
        'suspend'   => ['verified'],
        'reinstate' => ['suspended', 'rejected'],
    ];
    if (!isset($allowed[$action]) || !in_array($prev, $allowed[$action], true)) {
        return [false, "Can't {$action} an application that is {$prev}."];
    }

    switch ($action) {
        case 'approve':
            $pdo->prepare("
                UPDATE sales_executives
                SET verification_status = 'verified', verified_by = ?, verified_at = NOW(),
                    rejection_reason = NULL, updated_at = NOW()
                WHERE id = ?
            ")->execute([$adminId, $execId]);
            writeAuditLog('sales_executive.approved', 'sales_executive', $execId,
                ['verification_status' => $prev],
                ['verification_status' => 'verified', 'verified_by' => $adminId, 'via' => 'admin'], $adminId);
            sendSalesExecApproved($exec['email'], $name, $dealer);
            return [true, "{$name} approved for {$dealer}."];

        case 'reject':
            $pdo->prepare("
                UPDATE sales_executives
                SET verification_status = 'rejected', verified_by = ?, verified_at = NOW(),
                    rejection_reason = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$adminId, $reason ?: null, $execId]);
            writeAuditLog('sales_executive.rejected', 'sales_executive', $execId,
                ['verification_status' => $prev],
                ['verification_status' => 'rejected', 'rejection_reason' => $reason,
                 'verified_by' => $adminId, 'via' => 'admin'], $adminId);
            sendSalesExecRejected($exec['email'], $name, $dealer, $reason);
            return [true, "{$name}'s request to join {$dealer} was declined."];

        case 'suspend':
            $pdo->prepare("
                UPDATE sales_executives
                SET verification_status = 'suspended', rejection_reason = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$reason ?: null, $execId]);
            $pause = $pdo->prepare("
                UPDATE cars SET status = 'paused', updated_at = NOW()
                WHERE uploaded_by_exec_id = ? AND status = 'active'
            ");
            $pause->execute([$execId]);
            $paused = $pause->rowCount();
            writeAuditLog('sales_executive.suspended', 'sales_executive', $execId,
                ['verification_status' => $prev],
                ['verification_status' => 'suspended', 'rejection_reason' => $reason,
                 'cars_paused' => $paused, 'via' => 'admin'], $adminId);
            return [true, "{$name} suspended." . ($paused ? " {$paused} listing(s) paused." : '')];

        case 'reinstate':
            $pdo->prepare("
                UPDATE sales_executives
                SET verification_status = 'verified', verified_by = ?, verified_at = NOW(),
                    rejection_reason = NULL, updated_at = NOW()
                WHERE id = ?
            ")->execute([$adminId, $execId]);
            $restore = $pdo->prepare("
                UPDATE cars SET status = 'active', updated_at = NOW()
                WHERE uploaded_by_exec_id = ? AND status = 'paused'
            ");
            $restore->execute([$execId]);
            $restored = $restore->rowCount();
            writeAuditLog('sales_executive.reinstated', 'sales_executive', $execId,
                ['verification_status' => $prev],
                ['verification_status' => 'verified', 'verified_by' => $adminId,
                 'cars_restored' => $restored, 'via' => 'admin'], $adminId);
            sendSalesExecApproved($exec['email'], $name, $dealer);
            return [true, "{$name} reinstated." . ($restored ? " {$restored} listing(s) restored." : '')];
    }

    return [false, 'Unknown action.'];
}

/** Pending exec applications across every dealership this admin manages. */
function adminPendingExecApplications(int $adminId, ?int $dealerId = null): array
{
    $sql = "
        SELECT se.id, se.job_title, se.created_at, se.dealer_id,
               d.company_name AS dealer_name,
               u.email, u.email_verified,
               p.first_name, p.last_name, p.phone
        FROM sales_executives se
        JOIN dealers d          ON d.id = se.dealer_id
        JOIN dealer_managers dm ON dm.dealer_id = d.id AND dm.admin_user_id = ?
        JOIN users u            ON u.id = se.user_id
        LEFT JOIN profiles p    ON p.user_id = se.user_id
        WHERE se.verification_status = 'pending'
    ";
    $params = [$adminId];
    if ($dealerId) {
        $sql     .= " AND se.dealer_id = ?";
        $params[] = $dealerId;
    }
    $sql .= " ORDER BY se.created_at ASC";

    $stmt = Database::getInstance()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Small HTML badge for exec / verification status. */
function adminStatusBadge(string $status): string
{
    $class = match ($status) {
        'verified', 'active' => 'badge-verified',
        'pending'            => 'badge-pending',
        'suspended', 'inactive' => 'badge-suspended',
        'rejected'           => 'badge-rejected',
        default              => 'badge-new',
    };
    return '<span class="badge ' . $class . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}


// ============================================================
// DESK ORGANISATION AGENTS  (0013)
// Brokers apply to an org; its managing admins approve / decline,
// suspend / reinstate, or remove them. Mirrors the sales exec flow.
// ============================================================

/** Maximum number of brands one desk organisation may sell. */
const SD_ORG_MAX_BRANDS = 3;

/**
 * Validate brand picks from a form (array of makes).
 * @return array{0:?array,1:string}  [brands or null, error]
 */
function adminParseOrgBrands(array $raw): array
{
    $valid  = sdCarMakes();
    $brands = [];
    foreach ($raw as $b) {
        $b = trim((string) $b);
        if ($b === '') {
            continue;
        }
        if (!in_array($b, $valid, true)) {
            return [null, "“{$b}” isn't a recognised car brand."];
        }
        $brands[$b] = true;
    }
    $brands = array_keys($brands);
    if (!$brands) {
        return [null, 'Choose at least one brand this organisation sells.'];
    }
    if (count($brands) > SD_ORG_MAX_BRANDS) {
        return [null, 'An organisation can sell at most ' . SD_ORG_MAX_BRANDS . ' brands.'];
    }
    return [$brands, ''];
}

/** Pending agent applications across the orgs this admin manages. */
function adminPendingAgentApplications(int $adminId, ?int $orgId = null): array
{
    $sql = "
        SELECT m.id, m.organization_id AS org_id, m.joined_at AS applied_at,
               o.name AS org_name,
               u.email, u.email_verified,
               p.first_name, p.last_name, p.phone,
               sd.display_name AS desk_name, sd.slug AS desk_slug,
               (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.salesdesk_id = sd.id) AS desk_cars,
               (SELECT COUNT(*) FROM leads l WHERE l.broker_id = m.user_id)              AS total_leads
        FROM organization_members m
        JOIN organizations o          ON o.id = m.organization_id
        JOIN organization_managers om ON om.organization_id = o.id AND om.admin_user_id = ?
        JOIN users u                  ON u.id = m.user_id
        LEFT JOIN profiles p          ON p.user_id = m.user_id
        LEFT JOIN salesdesks sd       ON sd.user_id = m.user_id
        WHERE m.status = 'pending'
    ";
    $params = [$adminId];
    if ($orgId) {
        $sql     .= " AND m.organization_id = ?";
        $params[] = $orgId;
    }
    $sql .= " ORDER BY m.joined_at ASC";
    $stmt = Database::getInstance()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * approve | reject | suspend | reinstate | remove an agent, scoped to
 * orgs this admin manages.
 *
 * @return array{0:bool,1:string}
 */
function adminReviewAgent(int $adminId, int $memberId, string $action, string $reason = ''): array
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT m.id, m.user_id, m.organization_id, m.status,
               o.name AS org_name, o.brands,
               u.email, p.first_name, p.last_name
        FROM organization_members m
        JOIN organizations o          ON o.id = m.organization_id
        JOIN organization_managers om ON om.organization_id = o.id AND om.admin_user_id = ?
        JOIN users u                  ON u.id = m.user_id
        LEFT JOIN profiles p          ON p.user_id = m.user_id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId, $memberId]);
    $m = $stmt->fetch();
    if (!$m) {
        return [false, 'That agent was not found, or belongs to an organisation you don\'t manage.'];
    }

    $prev   = $m['status'];
    $name   = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: $m['email'];
    $org    = $m['org_name'];
    $orgId  = (int) $m['organization_id'];
    $reason = mb_substr(trim($reason), 0, 255);

    $allowed = [
        'approve'   => ['pending'],
        'reject'    => ['pending'],
        'suspend'   => ['verified'],
        'reinstate' => ['suspended'],
        'remove'    => ['verified', 'suspended', 'rejected'],
    ];
    if (!isset($allowed[$action]) || !in_array($prev, $allowed[$action], true)) {
        return [false, "Can't {$action} an agent who is {$prev}."];
    }

    if ($action === 'remove') {
        $pdo->prepare("DELETE FROM organization_members WHERE id = ?")->execute([$memberId]);
        writeAuditLog('org.agent_removed', 'organization', $orgId,
            ['user_id' => (int) $m['user_id'], 'status' => $prev], null, $adminId);
        return [true, "{$name} removed from {$org}. They're an independent broker now."];
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
    ")->execute([$new, $adminId, in_array($new, ['rejected', 'suspended'], true) ? ($reason ?: null) : null, $memberId]);

    $auditAction = [
        'approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'reinstated',
    ][$action];
    writeAuditLog('org.agent_' . $auditAction,
        'organization', $orgId,
        ['user_id' => (int) $m['user_id'], 'status' => $prev],
        ['user_id' => (int) $m['user_id'], 'status' => $new, 'reason' => $reason, 'by' => $adminId], $adminId);

    $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'agent_status', ?, ?, ?, NOW())
    ")->execute([
        (int) $m['user_id'],
        match ($new) {
            'verified'  => "You're an agent at {$org}",
            'rejected'  => "Application to {$org} declined",
            'suspended' => "Agent access at {$org} suspended",
        },
        $reason ?: match ($new) {
            'verified'  => 'You can now add its cars to your desk.',
            'rejected'  => 'You can keep working independently or apply elsewhere.',
            'suspended' => 'You can\'t add new cars until you\'re reinstated.',
        },
        json_encode(['org_id' => $orgId]),
    ]);

    if ($new === 'verified') {
        sendAgentApproved($m['email'], $name, $org, orgBrands($m['brands']));
    } elseif ($new === 'rejected') {
        sendAgentRejected($m['email'], $name, $org, $reason);
    }

    return [true, match ($action) {
        'approve'   => "{$name} is now an agent at {$org}.",
        'reject'    => "{$name}'s application to {$org} was declined.",
        'suspend'   => "{$name} suspended at {$org}.",
        'reinstate' => "{$name} reinstated at {$org}.",
    }];
}


// ============================================================
// SCHEMA HEALTH + ERROR REPORTING
// Admin pages call adminRequireSchema() before touching 0012/0013
// tables. If the database is missing anything, the admin sees exactly
// which migration to run instead of a blank page or a vague error.
// ============================================================

/**
 * What the admin pages need that the database doesn't have.
 * @return array<int, array{migration:string, missing:string}>
 */
function adminSchemaProblems(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $need = [
        '0012_admin_managed_dealers_orgs.sql' => [
            'columns' => ['dealers.created_by_admin_id', 'organizations.created_by_admin_id'],
            'tables'  => ['dealer_managers', 'organization_managers'],
            'nullable'=> ['dealers.user_id'],
            'indexes' => [],
        ],
        '0013_desk_orgs_online_dealerships.sql' => [
            'columns' => [
                'organizations.brands', 'organizations.description', 'organizations.agent_car_limit',
                'organizations.accepting_applications',
                'organization_members.status', 'organization_members.verified_by',
                'organization_members.verified_at', 'organization_members.rejection_reason',
                'organization_members.updated_at',
            ],
            'tables'  => [],
            // Columns that must accept NULL. A hand-made column with the same
            // name but NOT NULL (e.g. description) makes saves fail.
            'nullable'=> [
                'organizations.brands', 'organizations.description', 'organizations.agent_car_limit',
                'organization_members.verified_by', 'organization_members.verified_at',
                'organization_members.rejection_reason',
            ],
            'types'   => [
                'organizations.brands'      => ['text', 'mediumtext', 'longtext'],
                'organizations.description' => ['text', 'mediumtext', 'longtext'],
            ],
            'indexes' => ['organization_members.uq_orgmem_one_org'],
        ],
    ];

    $pdo  = Database::getInstance();
    $cols  = [];
    $types = [];
    foreach ($pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('dealers','organizations','organization_members','dealer_managers','organization_managers')
    ") as $r) {
        $cols[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']]  = $r['IS_NULLABLE'] === 'YES';
        $types[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = strtolower($r['DATA_TYPE']);
    }
    $tables = [];
    foreach ($cols as $k => $_) {
        $tables[explode('.', $k)[0]] = true;
    }
    $idx = [];
    foreach ($pdo->query("
        SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members'
    ") as $r) {
        $idx[$r['TABLE_NAME'] . '.' . $r['INDEX_NAME']] = true;
    }

    $problems = [];
    foreach ($need as $migration => $req) {
        foreach ($req['tables'] as $t) {
            if (!isset($tables[$t])) $problems[] = ['migration' => $migration, 'missing' => "table {$t}"];
        }
        foreach ($req['columns'] as $c) {
            if (!array_key_exists($c, $cols)) $problems[] = ['migration' => $migration, 'missing' => "column {$c}"];
        }
        foreach ($req['nullable'] as $c) {
            if (array_key_exists($c, $cols) && !$cols[$c]) $problems[] = ['migration' => $migration, 'missing' => "{$c} must allow NULL"];
        }
        foreach (($req['types'] ?? []) as $c => $ok) {
            if (isset($types[$c]) && !in_array($types[$c], $ok, true)) {
                $problems[] = ['migration' => $migration, 'missing' => "{$c} has the wrong type ({$types[$c]})"];
            }
        }
        foreach ($req['indexes'] as $i) {
            if (!isset($idx[$i])) $problems[] = ['migration' => $migration, 'missing' => "index {$i}"];
        }
    }
    return $cache = $problems;
}

/**
 * Stop an admin page with a clear "run this migration" screen when the
 * database is behind the code.
 */
function adminRequireSchema(string $pageTitle): void
{
    $problems = adminSchemaProblems();
    if (!$problems) {
        return;
    }
    $byMigration = [];
    foreach ($problems as $p) {
        $byMigration[$p['migration']][] = $p['missing'];
    }
    ob_start();
    include __DIR__ . '/../views/partials/admin/schema-check.php';
    $pageContent = ob_get_clean();
    $pageStyles  = ['/assets/css/admin.css'];
    require __DIR__ . '/../views/layout-app.php';
    exit;
}

/**
 * Admin-facing explanation of a failed save. Logs the full error and
 * returns a message that says what actually went wrong.
 */
function adminDbErrorMessage(Throwable $e, string $context, string $what = 'save the details'): string
{
    error_log("[SalesDesk {$context}] " . get_class($e) . ': ' . $e->getMessage());

    $state = $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : '';
    $msg   = $e->getMessage();

    if ($state === '42S22' || $state === '42S02' || str_contains($msg, 'Unknown column') || str_contains($msg, "doesn't exist")) {
        preg_match("/Unknown column '([^']+)'|Table '([^']+)' doesn't exist/", $msg, $m);
        $thing = $m[1] ?? ($m[2] ?? 'a column or table');
        return "Could not {$what}: the database is missing {$thing}. Run the latest migrations "
             . "(db/0012_admin_managed_dealers_orgs.sql, then db/0013_desk_orgs_online_dealerships.sql) — both are safe to re-run.";
    }
    if (preg_match("/Column '([^']+)' cannot be null|Field '([^']+)' doesn't have a default value/", $msg, $m)) {
        $col = $m[1] ?: $m[2];
        return "Could not {$what}: the database column “{$col}” is set up differently than this release expects "
             . "(it won't accept an empty value). Open Admin → Database updates and click “Run all updates” — "
             . "it corrects the column definitions without losing data.";
    }
    if ($state === '23000') {
        return "Could not {$what}: it clashes with an existing record (" . mb_substr($msg, 0, 160) . ').';
    }
    return "Could not {$what}. Database error: " . mb_substr(preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/', '', $msg), 0, 200);
}


// ============================================================
// ORGANISATION DETAILS SAVE  (shared by desk-orgs-view + diagnostics)
// ============================================================

/**
 * Validate and save an organisation's Details form.
 * $dryRun = true runs every query inside a transaction and rolls it back —
 * used by Admin → Database updates → "Test saving" to show the real error.
 *
 * @param array $org  row from getManagedOrg()
 * @param array $in   form fields ($_POST shape)
 * @return array{0:bool,1:string}
 */
function adminSaveOrgDetails(array $org, array $in, int $adminId, bool $dryRun = false): array
{
    $pdo         = Database::getInstance();
    $orgId       = (int) $org['id'];
    $name        = mb_substr(trim($in['name'] ?? ''), 0, 120);
    $cipc        = mb_substr(trim($in['cipc_number'] ?? ''), 0, 30);
    $description = mb_substr(trim($in['description'] ?? ''), 0, 1000);
    $limitRaw    = trim((string) ($in['agent_car_limit'] ?? ''));
    [$newBrands, $brandError] = adminParseOrgBrands((array) ($in['brands'] ?? []));

    $error = match (true) {
        mb_strlen($name) < 2 => 'Please enter the organisation name.',
        $newBrands === null  => $brandError,
        $limitRaw !== '' && (!ctype_digit($limitRaw) || (int) $limitRaw < 1 || (int) $limitRaw > 500)
                             => 'Agent car limit must be a number from 1 to 500, or blank for the platform default.',
        default              => '',
    };
    if ($error) {
        return [false, $error];
    }

    $verified  = ($in['verification_status'] ?? '') === 'verified';
    $newStatus = $verified
        ? 'verified'
        : (in_array($org['verification_status'], ['pending', 'rejected'], true) ? $org['verification_status'] : 'unverified');

    $pdo->beginTransaction();
    try {
        $addressId = adminSaveAddress(!empty($org['address_id']) ? (int) $org['address_id'] : null, $in);
        $slug      = $name !== $org['name'] ? adminUniqueSlug('organizations', $name, 60, $orgId) : $org['slug'];
        // verified_at is decided in PHP — comparing a bound value with a
        // string literal in SQL can hit "Illegal mix of collations" (1267).
        $verifiedAtSql = $newStatus === 'verified' ? 'COALESCE(verified_at, NOW())' : 'NULL';
        $pdo->prepare("
            UPDATE organizations
            SET name = ?, slug = ?, brands = ?, description = ?, agent_car_limit = ?,
                accepting_applications = ?, cipc_number = ?, address_id = ?, logo_url = ?,
                verification_status = ?,
                verified_at = {$verifiedAtSql},
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $name, $slug,
            json_encode($newBrands, JSON_UNESCAPED_UNICODE),
            $description !== '' ? $description : null,
            $limitRaw !== '' ? (int) $limitRaw : null,
            !empty($in['accepting_applications']) ? 1 : 0,
            $cipc !== '' ? $cipc : null,
            $addressId,
            adminCleanUrl($in['logo_url'] ?? ''),
            $newStatus, $orgId,
        ]);
        writeAuditLog('org.updated_by_admin', 'organization', $orgId,
            ['name' => $org['name'], 'brands' => orgBrands($org['brands'] ?? null), 'verification_status' => $org['verification_status']],
            ['name' => $name, 'brands' => $newBrands, 'verification_status' => $newStatus], $adminId);

        if ($dryRun) {
            $pdo->rollBack();
            return [true, 'Test save worked (nothing was changed).'];
        }
        $pdo->commit();
        return [true, 'Organisation details saved.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, adminDbErrorMessage($e, 'admin/desk-org save' . ($dryRun ? ' (test)' : ''))];
    }
}
