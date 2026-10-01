<?php
/**
 * SalesDesk — Superadmin & admin scoping  (0014)
 *
 * Two admin tiers, one role:
 *   users.role = 'admin'                       → admin
 *   users.role = 'admin' AND is_superadmin = 1 → superadmin
 *
 * SUPERADMIN
 *   • Admins: invite, suspend / reinstate, remove (portfolio must be
 *     handed over first), grant / revoke superadmin.
 *   • Assignment: give any dealership or desk org to any admin, move a
 *     whole portfolio between admins, see unmanaged ("orphan") entities.
 *   • Sees everything admins see, across the whole platform.
 *   • Payout actions (approve, mark paid / failed, retry).
 *   • Owns Dashboard, Audit, Database, Migrations.
 *
 * ADMIN (everyone else)
 *   • Only the dealerships / orgs they manage (dealer_managers /
 *     organization_managers), the users connected to them, and — read
 *     only — the commissions and payouts connected to them.
 *
 * GUARDRAILS
 *   • The last active superadmin can't be suspended, removed or
 *     demoted, and nobody can demote, suspend or remove themselves.
 *   • Admin accounts are only managed from the Admins page — the Users
 *     page never acts on an admin.
 *   • Sensitive superadmin actions need a fresh email code (step-up,
 *     valid SD_STEPUP_TTL seconds) — see app/admin/confirm.php.
 *   • Every change is written to audit_logs.
 *
 * BOOTSTRAP: until db/0014_superadmin.sql has run (no is_superadmin
 * column) every active admin is treated as a superadmin — today's
 * behaviour — so the migration can still be run from the admin panel.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

const SD_STEPUP_TTL      = 900;   // seconds a step-up confirmation stays valid
const SD_STEPUP_PURPOSE  = 'admin_stepup';
const SD_STEPUP_MAX_TRIES = 5;


// ============================================================
// WHO IS A SUPERADMIN
// ============================================================

function sdSuperadminSchemaReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) Database::getInstance()->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_superadmin'
        ")->fetchColumn();
        if (!$ready) {
            error_log('[SalesDesk] Superadmin is off (all admins unrestricted): run db/0014_superadmin.sql');
        }
    }
    return $ready;
}

/** True for an ACTIVE admin with is_superadmin = 1 (or any active admin before 0014). */
function isSuperadmin(?int $userId = null): bool
{
    static $cache = [];
    $userId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        return false;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $col  = sdSuperadminSchemaReady() ? 'is_superadmin' : '1 AS is_superadmin';
    $stmt = Database::getInstance()->prepare("SELECT role, status, {$col} FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    return $cache[$userId] = ($u && $u['role'] === 'admin' && $u['status'] === 'active' && (int) $u['is_superadmin'] === 1);
}

/** Gate a whole page to superadmins. Admins are sent to their portfolio. */
function requireSuperadmin(): void
{
    requireRole('admin');
    if (!isSuperadmin()) {
        $_SESSION['flash_error'] = 'Only a superadmin can open that page.';
        redirect('/app/admin/dealerships');
    }
}

/** Where an admin lands: superadmins → dashboard, admins → their dealerships. */
function adminHomePath(): string
{
    return isSuperadmin() ? '/app/admin/dashboard' : '/app/admin/dealerships';
}


// ============================================================
// SCOPE HELPERS (used by admin_scope.php, users.php, payouts.php)
// ============================================================

/**
 * JOIN keyword for a manager-table join. Admins: JOIN (only rows they
 * manage). Superadmins: LEFT JOIN on the same condition — every row
 * comes back, and the bound ? parameter stays in the same position, so
 * call sites don't change their params.
 */
function adminScopeJoin(int $adminId): string
{
    return isSuperadmin($adminId) ? 'LEFT JOIN' : 'JOIN';
}

/**
 * WHERE fragment limiting users (alias) to the ones an admin is
 * responsible for: principals of their dealerships, sales execs of their
 * dealerships, and brokers who applied to / belong to their orgs.
 * Admin accounts are never in scope here (they're managed on Admins).
 *
 * @return array{0:string,1:array}
 */
function adminUserScopeSql(int $adminId, string $alias = 'u'): array
{
    if (isSuperadmin($adminId)) {
        return ["{$alias}.role <> 'admin'", []];
    }
    return ["{$alias}.role <> 'admin' AND (
            EXISTS (SELECT 1 FROM dealers sd_d
                      JOIN dealer_managers sd_dm ON sd_dm.dealer_id = sd_d.id AND sd_dm.admin_user_id = ?
                     WHERE sd_d.user_id = {$alias}.id)
         OR EXISTS (SELECT 1 FROM sales_executives sd_se
                      JOIN dealer_managers sd_dm2 ON sd_dm2.dealer_id = sd_se.dealer_id AND sd_dm2.admin_user_id = ?
                     WHERE sd_se.user_id = {$alias}.id)
         OR EXISTS (SELECT 1 FROM organization_members sd_om
                      JOIN organization_managers sd_omg ON sd_omg.organization_id = sd_om.organization_id AND sd_omg.admin_user_id = ?
                     WHERE sd_om.user_id = {$alias}.id)
        )", [$adminId, $adminId, $adminId]];
}

/**
 * WHERE fragment limiting commissions (alias) to an admin's dealerships
 * or orgs. Superadmins: everything.
 *
 * @return array{0:string,1:array}
 */
function adminCommissionScopeSql(int $adminId, string $alias = 'c'): array
{
    if (isSuperadmin($adminId)) {
        return ['1 = 1', []];
    }
    return ["(
            {$alias}.dealer_id IN (SELECT sd_dm.dealer_id FROM dealer_managers sd_dm WHERE sd_dm.admin_user_id = ?)
         OR {$alias}.organization_id IN (SELECT sd_om.organization_id FROM organization_managers sd_om WHERE sd_om.admin_user_id = ?)
        )", [$adminId, $adminId]];
}

/** Can this admin act on this (non-admin) user from the Users page? */
function adminCanActOnUser(int $adminId, int $targetUserId): bool
{
    if ($targetUserId <= 0 || $targetUserId === $adminId) {
        return false;
    }
    [$scope, $params] = adminUserScopeSql($adminId, 'u');
    $stmt = Database::getInstance()->prepare("SELECT 1 FROM users u WHERE u.id = ? AND {$scope} LIMIT 1");
    $stmt->execute(array_merge([$targetUserId], $params));
    return (bool) $stmt->fetchColumn();
}

/** Can this admin act on this dealership (CIPC, suspend)? */
function adminCanActOnDealer(int $adminId, int $dealerId): bool
{
    if (isSuperadmin($adminId)) {
        return true;
    }
    $stmt = Database::getInstance()->prepare(
        "SELECT 1 FROM dealer_managers WHERE dealer_id = ? AND admin_user_id = ? LIMIT 1"
    );
    $stmt->execute([$dealerId, $adminId]);
    return (bool) $stmt->fetchColumn();
}

/** Can this admin act on this desk org (CIPC)? */
function adminCanActOnOrg(int $adminId, int $orgId): bool
{
    if (isSuperadmin($adminId)) {
        return true;
    }
    $stmt = Database::getInstance()->prepare(
        "SELECT 1 FROM organization_managers WHERE organization_id = ? AND admin_user_id = ? LIMIT 1"
    );
    $stmt->execute([$orgId, $adminId]);
    return (bool) $stmt->fetchColumn();
}


// ============================================================
// STEP-UP CONFIRMATION (fresh email code for sensitive actions)
// ============================================================

function sdStepUpFresh(): bool
{
    return isset($_SESSION['stepup_at'], $_SESSION['stepup_user'])
        && (int) $_SESSION['stepup_user'] === (int) ($_SESSION['user_id'] ?? 0)
        && (time() - (int) $_SESSION['stepup_at']) < SD_STEPUP_TTL;
}

/**
 * Call at the top of a sensitive POST handler. If the admin hasn't
 * confirmed recently, nothing is changed: they're sent to the confirm
 * page and brought back to $returnTo to repeat the action.
 */
function sdRequireStepUp(string $returnTo, ?string $message = null): void
{
    if (sdStepUpFresh()) {
        return;
    }
    $_SESSION['flash_error'] = $message ?? 'Please confirm it’s you, then repeat that action. Nothing was changed.';
    redirect('/app/admin/confirm?next=' . urlencode(sdSafeAdminPath($returnTo)));
}

/** Only ever return inside /app/admin/ (no open redirects). */
function sdSafeAdminPath(string $path): string
{
    return (preg_match('#^/app/admin/[A-Za-z0-9/_\-.?=&%]*$#', $path) && !str_contains($path, '//'))
        ? $path : '/app/admin/dealerships';
}


// ============================================================
// SUPERADMIN — ADMIN ACCOUNTS
// ============================================================

/** Every admin account with portfolio sizes. */
function saListAdmins(): array
{
    $sa = sdSuperadminSchemaReady() ? 'u.is_superadmin' : '1';
    return Database::getInstance()->query("
        SELECT u.id, u.email, u.status, u.last_login, u.created_at, {$sa} AS is_superadmin,
               p.first_name, p.last_name,
               (SELECT COUNT(*) FROM dealer_managers dm       WHERE dm.admin_user_id = u.id) AS dealer_count,
               (SELECT COUNT(*) FROM organization_managers om WHERE om.admin_user_id = u.id) AS org_count
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.role = 'admin'
        ORDER BY (u.status = 'active') DESC, {$sa} DESC, u.email ASC
    ")->fetchAll();
}

/** One admin account (any status), or false. */
function saGetAdmin(int $userId): array|false
{
    $sa   = sdSuperadminSchemaReady() ? 'u.is_superadmin' : '1';
    $stmt = Database::getInstance()->prepare("
        SELECT u.id, u.email, u.status, u.last_login, u.created_at, {$sa} AS is_superadmin,
               p.first_name, p.last_name
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.role = 'admin'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function saAdminLabel(array $a): string
{
    $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
    return $name !== '' ? $name : (string) $a['email'];
}

function saActiveSuperadminCount(): int
{
    if (!sdSuperadminSchemaReady()) {
        return 0;
    }
    return (int) Database::getInstance()->query("
        SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND is_superadmin = 1
    ")->fetchColumn();
}

/**
 * Create a new admin account. The invitee sets their own password via
 * "Forgot password" (the invite email links there) — no password ever
 * passes through the superadmin.
 *
 * @return array{0:bool,1:string,2:?int}
 */
function saInviteAdmin(string $email, string $first, string $last, int $actorId): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Please enter a valid email address.', null];
    }
    $first = mb_substr(trim($first), 0, 60);
    $last  = mb_substr(trim($last), 0, 60);
    if ($first === '') {
        return [false, 'Please enter the admin’s first name.', null];
    }

    $pdo      = Database::getInstance();
    $existing = getUserByEmail($email);
    if ($existing) {
        if ($existing['role'] === 'admin') {
            return [false, $email . ' is already an admin' . ($existing['status'] !== 'active' ? ' (suspended — reinstate them instead).' : '.'), (int) $existing['id']];
        }
        return [false, $email . ' already has a ' . str_replace('_', ' ', $existing['role'])
            . ' account. Admins need their own email address, so their broker / dealer account keeps working.', null];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO users (uuid, email, role, password_hash, status, email_verified, created_at, updated_at)
            VALUES (?, ?, 'admin', ?, 'active', 1, NOW(), NOW())
        ")->execute([generateUuidV4(), $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_ALGO)]);
        $newId = (int) $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO profiles (user_id, first_name, last_name, onboarding_step, onboarding_completed, created_at, updated_at)
            VALUES (?, ?, ?, 0, 1, NOW(), NOW())
        ")->execute([$newId, $first, $last !== '' ? $last : null]);

        writeAuditLog('admin.invited', 'user', $newId, null, ['email' => $email, 'role' => 'admin'], $actorId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk saInviteAdmin] ' . $e->getMessage());
        return [false, 'Could not create the admin account. Please try again.', null];
    }

    $inviter = saGetAdmin($actorId);
    sendAdminInvite($email, $first, $inviter ? saAdminLabel($inviter) : 'A SalesDesk superadmin');
    return [true, "{$email} is now an admin. They’ve been emailed a link to set their password — assign them dealerships or orgs below.", $newId];
}

/**
 * Suspend or reinstate an admin. Suspended admins lose access on their
 * next request (requireRole re-checks the database) but keep their
 * portfolio, so reinstating restores everything.
 *
 * @return array{0:bool,1:string}
 */
function saSetAdminStatus(int $targetId, string $status, int $actorId): array
{
    if (!in_array($status, ['active', 'suspended'], true)) {
        return [false, 'Unknown status.'];
    }
    $target = saGetAdmin($targetId);
    if (!$target) {
        return [false, 'That admin was not found.'];
    }
    if ($targetId === $actorId) {
        return [false, 'You can’t suspend your own account.'];
    }
    if ($status === 'suspended' && (int) $target['is_superadmin'] === 1 && $target['status'] === 'active'
        && saActiveSuperadminCount() <= 1) {
        return [false, 'That’s the last active superadmin — make someone else a superadmin first.'];
    }
    if ($target['status'] === $status) {
        return [true, saAdminLabel($target) . ' is already ' . $status . '.'];
    }

    Database::getInstance()->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? AND role = 'admin'")
        ->execute([$status, $targetId]);
    writeAuditLog($status === 'active' ? 'admin.reinstated' : 'admin.suspended', 'user', $targetId,
        ['status' => $target['status']], ['status' => $status], $actorId);

    return [true, saAdminLabel($target) . ($status === 'active' ? ' is reinstated.' : ' is suspended. Their dealerships and orgs are unchanged — hand them over if this is permanent.')];
}

/** @return array{0:bool,1:string} */
function saSetSuperadmin(int $targetId, bool $grant, int $actorId): array
{
    if (!sdSuperadminSchemaReady()) {
        return [false, 'Run migration 0014 first (Admin → Database → Migrations).'];
    }
    $target = saGetAdmin($targetId);
    if (!$target) {
        return [false, 'That admin was not found.'];
    }
    if (!$grant && $targetId === $actorId) {
        return [false, 'You can’t remove your own superadmin access — ask another superadmin.'];
    }
    if ($grant && $target['status'] !== 'active') {
        return [false, 'Reinstate this admin before making them a superadmin.'];
    }
    if (!$grant && (int) $target['is_superadmin'] === 1 && saActiveSuperadminCount() <= 1) {
        return [false, 'That’s the last active superadmin.'];
    }
    if ((int) $target['is_superadmin'] === ($grant ? 1 : 0)) {
        return [true, saAdminLabel($target) . ($grant ? ' is already a superadmin.' : ' is not a superadmin.')];
    }

    Database::getInstance()->prepare("UPDATE users SET is_superadmin = ?, updated_at = NOW() WHERE id = ? AND role = 'admin'")
        ->execute([$grant ? 1 : 0, $targetId]);
    writeAuditLog($grant ? 'admin.superadmin_granted' : 'admin.superadmin_revoked', 'user', $targetId,
        ['is_superadmin' => (int) $target['is_superadmin']], ['is_superadmin' => $grant ? 1 : 0], $actorId);

    return [true, saAdminLabel($target) . ($grant ? ' is now a superadmin.' : ' is no longer a superadmin.')];
}

/**
 * Remove an admin: hand their whole portfolio to $transferToId (required
 * when they manage anything), then suspend the account and drop their
 * superadmin flag. The account row stays — audit_logs point at it.
 *
 * @return array{0:bool,1:string}
 */
function saRemoveAdmin(int $targetId, ?int $transferToId, int $actorId): array
{
    $target = saGetAdmin($targetId);
    if (!$target) {
        return [false, 'That admin was not found.'];
    }
    if ($targetId === $actorId) {
        return [false, 'You can’t remove your own account.'];
    }
    if ((int) $target['is_superadmin'] === 1 && $target['status'] === 'active' && saActiveSuperadminCount() <= 1) {
        return [false, 'That’s the last active superadmin.'];
    }

    $portfolio = saPortfolioCounts($targetId);
    if ($portfolio['total'] > 0) {
        if (!$transferToId) {
            return [false, 'Choose who takes over their ' . $portfolio['total'] . ' dealership(s) / org(s) first.'];
        }
        [$ok, $msg] = saTransferPortfolio($targetId, $transferToId, $actorId, false);
        if (!$ok) {
            return [false, $msg];
        }
    }

    $sa = sdSuperadminSchemaReady() ? ', is_superadmin = 0' : '';
    Database::getInstance()->prepare("UPDATE users SET status = 'suspended'{$sa}, updated_at = NOW() WHERE id = ? AND role = 'admin'")
        ->execute([$targetId]);
    writeAuditLog('admin.removed', 'user', $targetId,
        ['status' => $target['status'], 'is_superadmin' => (int) $target['is_superadmin']],
        ['status' => 'suspended', 'is_superadmin' => 0, 'portfolio_to' => $transferToId], $actorId);

    return [true, saAdminLabel($target) . ' is removed' . ($portfolio['total'] ? ' and their portfolio handed over.' : '.')];
}


// ============================================================
// SUPERADMIN — PORTFOLIOS & ASSIGNMENT
// ============================================================

/** @return array{dealers:int,orgs:int,total:int} */
function saPortfolioCounts(int $adminId): array
{
    $pdo = Database::getInstance();
    $d = $pdo->prepare("SELECT COUNT(*) FROM dealer_managers WHERE admin_user_id = ?");
    $d->execute([$adminId]);
    $o = $pdo->prepare("SELECT COUNT(*) FROM organization_managers WHERE admin_user_id = ?");
    $o->execute([$adminId]);
    $dc = (int) $d->fetchColumn();
    $oc = (int) $o->fetchColumn();
    return ['dealers' => $dc, 'orgs' => $oc, 'total' => $dc + $oc];
}

/**
 * An admin's dealerships and orgs, each with how many OTHER active
 * admins co-manage it (0 = this admin is the only one).
 *
 * @return array{dealers:array,orgs:array}
 */
function saPortfolio(int $adminId): array
{
    $pdo = Database::getInstance();
    $acc = function_exists('sdWorkspaceSchemaReady') && sdWorkspaceSchemaReady() ? 'dm.access' : "'view'";
    $d = $pdo->prepare("
        SELECT d.id, d.company_name AS name, d.is_active, d.verification_status, a.city, a.province,
               {$acc} AS access, d.user_id AS principal_user_id,
               (SELECT COUNT(*) FROM dealer_managers x JOIN users xu ON xu.id = x.admin_user_id AND xu.status = 'active'
                 WHERE x.dealer_id = d.id AND x.admin_user_id <> ?) AS other_managers
        FROM dealer_managers dm
        JOIN dealers d ON d.id = dm.dealer_id
        LEFT JOIN addresses a ON a.id = d.address_id
        WHERE dm.admin_user_id = ?
        ORDER BY d.company_name
    ");
    $d->execute([$adminId, $adminId]);

    $o = $pdo->prepare("
        SELECT o.id, o.name, o.is_active, o.verification_status, a.city, a.province,
               (SELECT COUNT(*) FROM organization_managers x JOIN users xu ON xu.id = x.admin_user_id AND xu.status = 'active'
                 WHERE x.organization_id = o.id AND x.admin_user_id <> ?) AS other_managers
        FROM organization_managers om
        JOIN organizations o ON o.id = om.organization_id
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE om.admin_user_id = ?
        ORDER BY o.name
    ");
    $o->execute([$adminId, $adminId]);

    return ['dealers' => $d->fetchAll(), 'orgs' => $o->fetchAll()];
}

/**
 * Dealerships / orgs with NO active managing admin — nobody can see
 * or approve anything for them until a superadmin assigns one.
 * Includes self-registered dealerships that never had a manager, and
 * entities whose only managers are suspended.
 *
 * @return array{dealers:array,orgs:array}
 */
function saOrphans(): array
{
    $pdo = Database::getInstance();
    $dealers = $pdo->query("
        SELECT d.id, d.company_name AS name, d.is_active, d.verification_status, d.user_id, d.created_at,
               a.city, a.province
        FROM dealers d
        LEFT JOIN addresses a ON a.id = d.address_id
        WHERE NOT EXISTS (
            SELECT 1 FROM dealer_managers dm
            JOIN users u ON u.id = dm.admin_user_id AND u.role = 'admin' AND u.status = 'active'
            WHERE dm.dealer_id = d.id
        )
        ORDER BY d.is_active DESC, d.company_name
    ")->fetchAll();

    $orgs = $pdo->query("
        SELECT o.id, o.name, o.is_active, o.verification_status, o.created_at,
               a.city, a.province
        FROM organizations o
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE NOT EXISTS (
            SELECT 1 FROM organization_managers om
            JOIN users u ON u.id = om.admin_user_id AND u.role = 'admin' AND u.status = 'active'
            WHERE om.organization_id = o.id
        )
        ORDER BY o.is_active DESC, o.name
    ")->fetchAll();

    return ['dealers' => $dealers, 'orgs' => $orgs];
}

/** Dealerships / orgs this admin does NOT manage yet (for the assign pickers). */
function saAssignable(int $adminId): array
{
    $pdo = Database::getInstance();
    $d = $pdo->prepare("
        SELECT d.id, d.company_name AS name, a.city
        FROM dealers d
        LEFT JOIN addresses a ON a.id = d.address_id
        WHERE NOT EXISTS (SELECT 1 FROM dealer_managers dm WHERE dm.dealer_id = d.id AND dm.admin_user_id = ?)
        ORDER BY d.company_name
    ");
    $d->execute([$adminId]);
    $o = $pdo->prepare("
        SELECT o.id, o.name, a.city
        FROM organizations o
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE NOT EXISTS (SELECT 1 FROM organization_managers om WHERE om.organization_id = o.id AND om.admin_user_id = ?)
        ORDER BY o.name
    ");
    $o->execute([$adminId]);
    return ['dealers' => $d->fetchAll(), 'orgs' => $o->fetchAll()];
}

/** @return array{0:string,1:string,2:string,3:string} table, fk column, entity table, audit entity */
function saEntityMeta(string $type): array
{
    return $type === 'dealer'
        ? ['dealer_managers', 'dealer_id', 'dealers', 'dealer']
        : ['organization_managers', 'organization_id', 'organizations', 'organization'];
}

/**
 * Make $adminId a manager of a dealership / org.
 * @return array{0:bool,1:string}
 */
function saAssign(string $type, int $entityId, int $adminId, int $actorId): array
{
    [$table, $col, $entityTable, $entity] = saEntityMeta($type);
    $admin = saGetAdmin($adminId);
    if (!$admin || $admin['status'] !== 'active') {
        return [false, 'Choose an active admin.'];
    }
    $exists = Database::getInstance()->prepare("SELECT 1 FROM {$entityTable} WHERE id = ? LIMIT 1");
    $exists->execute([$entityId]);
    if (!$exists->fetchColumn()) {
        return [false, 'That ' . ($type === 'dealer' ? 'dealership' : 'organisation') . ' was not found.'];
    }

    $stmt = Database::getInstance()->prepare("
        INSERT IGNORE INTO {$table} ({$col}, admin_user_id, added_by, created_at) VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$entityId, $adminId, $actorId]);
    if ($stmt->rowCount() === 0) {
        return [true, saAdminLabel($admin) . ' already manages it.'];
    }
    writeAuditLog("{$entity}.manager_added", $entity, $entityId, null,
        ['admin_user_id' => $adminId, 'email' => $admin['email'], 'by' => 'superadmin'], $actorId);
    return [true, saAdminLabel($admin) . ' now manages it.'];
}

/**
 * Take a dealership / org away from $adminId. Refused when they're its
 * only ACTIVE manager — assign someone else first (or use Transfer).
 * @return array{0:bool,1:string}
 */
function saUnassign(string $type, int $entityId, int $adminId, int $actorId): array
{
    [$table, $col, , $entity] = saEntityMeta($type);
    $pdo = Database::getInstance();

    $others = $pdo->prepare("
        SELECT COUNT(*) FROM {$table} m
        JOIN users u ON u.id = m.admin_user_id AND u.role = 'admin' AND u.status = 'active'
        WHERE m.{$col} = ? AND m.admin_user_id <> ?
    ");
    $others->execute([$entityId, $adminId]);
    if ((int) $others->fetchColumn() === 0) {
        return [false, 'They’re its only active manager — assign another admin first, so it isn’t left unmanaged.'];
    }

    $del = $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ? AND admin_user_id = ?");
    $del->execute([$entityId, $adminId]);
    if ($del->rowCount() === 0) {
        return [true, 'They didn’t manage it.'];
    }
    writeAuditLog("{$entity}.manager_removed", $entity, $entityId, null,
        ['admin_user_id' => $adminId, 'by' => 'superadmin'], $actorId);
    return [true, 'Removed from their portfolio.'];
}

/**
 * Move (or copy) EVERYTHING $fromId manages to $toId.
 * $keepSource = true → both manage it afterwards (share);
 * false → $fromId stops managing it (hand over).
 *
 * @return array{0:bool,1:string}
 */
function saTransferPortfolio(int $fromId, int $toId, int $actorId, bool $keepSource): array
{
    if ($fromId === $toId) {
        return [false, 'Choose a different admin to hand over to.'];
    }
    $from = saGetAdmin($fromId);
    $to   = saGetAdmin($toId);
    if (!$from || !$to) {
        return [false, 'That admin was not found.'];
    }
    if ($to['status'] !== 'active') {
        return [false, saAdminLabel($to) . ' is suspended — choose an active admin.'];
    }

    $pdo    = Database::getInstance();
    $counts = saPortfolioCounts($fromId);
    $pdo->beginTransaction();
    try {
        // 0015: a handover keeps each dealership's View / Operate level.
        $hasAccess = function_exists('sdWorkspaceSchemaReady') && sdWorkspaceSchemaReady();
        $pdo->prepare($hasAccess ? "
            INSERT IGNORE INTO dealer_managers (dealer_id, admin_user_id, access, added_by, created_at)
            SELECT dealer_id, ?, access, ?, NOW() FROM dealer_managers WHERE admin_user_id = ?
        " : "
            INSERT IGNORE INTO dealer_managers (dealer_id, admin_user_id, added_by, created_at)
            SELECT dealer_id, ?, ?, NOW() FROM dealer_managers WHERE admin_user_id = ?
        ")->execute([$toId, $actorId, $fromId]);
        $pdo->prepare("
            INSERT IGNORE INTO organization_managers (organization_id, admin_user_id, added_by, created_at)
            SELECT organization_id, ?, ?, NOW() FROM organization_managers WHERE admin_user_id = ?
        ")->execute([$toId, $actorId, $fromId]);
        if (!$keepSource) {
            $pdo->prepare("DELETE FROM dealer_managers WHERE admin_user_id = ?")->execute([$fromId]);
            $pdo->prepare("DELETE FROM organization_managers WHERE admin_user_id = ?")->execute([$fromId]);
        }
        writeAuditLog($keepSource ? 'admin.portfolio_shared' : 'admin.portfolio_transferred', 'user', $fromId,
            ['dealers' => $counts['dealers'], 'orgs' => $counts['orgs']],
            ['to_admin_user_id' => $toId, 'to_email' => $to['email']], $actorId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk saTransferPortfolio] ' . $e->getMessage());
        return [false, 'Could not move the portfolio. Nothing was changed.'];
    }

    return [true, sprintf('%d dealership(s) and %d org(s) %s %s.',
        $counts['dealers'], $counts['orgs'], $keepSource ? 'now shared with' : 'handed over to', saAdminLabel($to))];
}
