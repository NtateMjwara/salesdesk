<?php
/**
 * SalesDesk — Dealer workspace context  (0015)
 *
 * Every page under /app/dealer/ asks ONE question: "which dealership is
 * this request for, and may this person change it?" — requireDealerWorkspace()
 * answers it. Before 0015 each page found "the dealership whose principal
 * is the signed-in user", which left admin-managed dealerships (no
 * principal) with no way to be run.
 *
 * Relationships (see claude/dealer-workspace design, Phase 1):
 *
 *   principal  dealer role, dealers.user_id = me          → full access
 *   operator   admin, dealership has NO principal, and
 *              dealer_managers.access = 'operate' for me
 *              (or I'm a superadmin)                        → full access
 *   viewer     any other admin who manages it (access
 *              'view'), or any admin on a dealership that
 *              HAS a principal (delegation = Phase 3)       → read-only
 *
 * Admins enter a dealership through /app/admin/workspace (POST), which
 * stores $_SESSION['workspace_dealer_id']. Access is re-checked on
 * EVERY request, so taking a dealership off someone's portfolio or
 * switching them to View cuts access immediately.
 *
 * Read-only is enforced centrally: a viewer's non-GET request never
 * reaches the page's POST handler.
 *
 * Audit: while a workspace is active, writeAuditLog() stamps every
 * entry with {dealer_id, mode, admin_user_id} (see functions.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/superadmin.php';

const SD_WORKSPACE_SESSION_KEY = 'workspace_dealer_id';

/** Is the dealer_managers.access column there yet (migration 0015)? */
function sdWorkspaceSchemaReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) Database::getInstance()->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealer_managers' AND COLUMN_NAME = 'access'
        ")->fetchColumn();
        if (!$ready) {
            error_log('[SalesDesk] Dealer workspaces are read-only for admins: run db/0015_dealer_workspace.sql');
        }
    }
    return $ready;
}

/**
 * Resolve what $userId may do at $dealerId.
 *
 * @return array{dealer_id:int, company_name:string, has_principal:bool, is_active:bool,
 *               mode:string, can_write:bool, actor_id:int, reason:string}|null
 *         null = no access at all.
 */
function sdResolveDealerAccess(int $userId, string $role, ?int $dealerId): ?array
{
    $pdo = Database::getInstance();

    if ($role === 'dealer') {
        $stmt = $pdo->prepare("SELECT id, company_name, user_id, is_active FROM dealers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $d = $stmt->fetch();
        return $d ? sdWorkspaceRow($d, 'principal', true, $userId, '') : null;
    }

    if ($role !== 'admin' || !$dealerId) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, company_name, user_id, is_active FROM dealers WHERE id = ? LIMIT 1");
    $stmt->execute([$dealerId]);
    $d = $stmt->fetch();
    if (!$d) {
        return null;
    }

    $accessCol = sdWorkspaceSchemaReady() ? 'access' : "'view' AS access";
    $m = $pdo->prepare("SELECT {$accessCol} FROM dealer_managers WHERE dealer_id = ? AND admin_user_id = ? LIMIT 1");
    $m->execute([$dealerId, $userId]);
    $access  = $m->fetchColumn();              // 'view' | 'operate' | false
    $isSuper = isSuperadmin($userId);

    if ($access === false && !$isSuper) {
        return null;                            // not their dealership
    }

    if ($d['user_id'] !== null) {
        // 0017: the principal may have let SalesDesk in for a while.
        $deleg = function_exists('sdActiveDelegation') ? sdActiveDelegation((int) $d['id']) : null;
        if ($deleg) {
            $row = sdWorkspaceRow($d, 'delegate', true, $userId, '');
            $row['delegation_expires_at'] = $deleg['expires_at'];
            $row['delegation_note']       = $deleg['note'];
            return $row;
        }
        return sdWorkspaceRow($d, 'viewer', false, $userId,
            'This dealership has its own principal, so SalesDesk staff can view it but not change it '
            . '(unless the principal grants access from their Settings).');
    }
    if ($access === 'operate' || ($isSuper && sdWorkspaceSchemaReady())) {
        return sdWorkspaceRow($d, 'operator', true, $userId, '');
    }
    return sdWorkspaceRow($d, 'viewer', false, $userId,
        'You have view access to this dealership. A superadmin can switch you to Operate.');
}

function sdWorkspaceRow(array $d, string $mode, bool $canWrite, int $actorId, string $reason): array
{
    return [
        'dealer_id'     => (int) $d['id'],
        'company_name'  => (string) $d['company_name'],
        'has_principal' => $d['user_id'] !== null,
        'is_active'     => (bool) $d['is_active'],
        'mode'          => $mode,
        'can_write'     => $canWrite,
        'actor_id'      => $actorId,
        'reason'        => $reason,
    ];
}

/** The active workspace for this request (null for principals / outside the dealer portal). */
function sdCurrentWorkspace(): ?array
{
    return $GLOBALS['sdWorkspace'] ?? null;
}

/** True when an admin (not the principal) is working inside a dealership. */
function sdInStaffWorkspace(): bool
{
    $ws = sdCurrentWorkspace();
    return $ws !== null && $ws['mode'] !== 'principal';
}

/**
 * Gate for every /app/dealer/ page and dealer API.
 *
 *   $opts['api']            true → answer blocked requests with JSON, not a redirect
 *   $opts['allow_inactive'] true → don't bounce when the dealership is offline
 *   $opts['principal_only'] true → page changes are principal-only even for
 *                           delegates (company / verification / address settings)
 *
 * Returns the context; also stored in $GLOBALS['sdWorkspace'] for the
 * layout (banner, nav) and the audit log.
 */
function requireDealerWorkspace(array $opts = []): array
{
    requireLogin();
    $role   = $_SESSION['user_role'] ?? '';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $isApi  = !empty($opts['api']);

    if ($role === 'admin') {
        requireRole('admin');                   // re-checks status / role in the DB
    } elseif ($role !== 'dealer') {
        sdWorkspaceDeny($isApi, 403, 'Access denied.', '/');
    }

    $dealerId = $role === 'admin' ? (int) ($_SESSION[SD_WORKSPACE_SESSION_KEY] ?? 0) : null;
    $ctx      = sdResolveDealerAccess($userId, $role, $dealerId);

    if ($ctx === null) {
        if ($role === 'dealer') {
            redirect('/auth/register.php');     // principal without a dealership row (unchanged behaviour)
        }
        unset($_SESSION[SD_WORKSPACE_SESSION_KEY]);
        sdWorkspaceDeny($isApi, 403,
            $dealerId ? 'You no longer have access to that dealership.' : 'Choose a dealership to open first.',
            '/app/admin/dealerships');
    }

    $GLOBALS['sdWorkspace'] = $ctx;

    // ── Central read-only enforcement ─────────────────────────
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($ctx['mode'] === 'delegate' && !empty($opts['principal_only']) && !in_array($method, ['GET', 'HEAD'], true)) {
        $ctx['can_write'] = false;
        $ctx['reason']    = 'Company, verification and address settings stay with the principal.';
    }
    if (!$ctx['can_write'] && !in_array($method, ['GET', 'HEAD'], true)) {
        writeAuditLog('workspace.write_blocked', 'dealer', $ctx['dealer_id'], null,
            ['path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)], $userId);
        $back = parse_url($_SERVER['REQUEST_URI'] ?? '/app/dealer/dashboard.php', PHP_URL_PATH) ?: '/app/dealer/dashboard.php';
        sdWorkspaceDeny($isApi, 403, 'View only — nothing was changed. ' . $ctx['reason'], $back);
    }

    return $ctx;
}

/** Stop the request: JSON for APIs, flash + redirect for pages. */
function sdWorkspaceDeny(bool $isApi, int $status, string $message, string $redirectTo): never
{
    if ($isApi) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    $_SESSION['flash_error'] = $message;
    redirect($redirectTo);
}

/**
 * Dealerships an admin can open, with the access they'd get.
 * @return list<array{id:int,company_name:string,has_principal:bool,access:string}>
 */
function sdWorkspaceChoices(int $adminId): array
{
    $accessCol = sdWorkspaceSchemaReady() ? 'dm.access' : "'view'";
    $join      = adminScopeJoin($adminId);
    $stmt = Database::getInstance()->prepare("
        SELECT d.id, d.company_name, d.user_id, {$accessCol} AS access
        FROM dealers d
        {$join} dealer_managers dm ON dm.dealer_id = d.id AND dm.admin_user_id = ?
        ORDER BY d.company_name
    ");
    $stmt->execute([$adminId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $ctx = sdResolveDealerAccess($adminId, 'admin', (int) $r['id']);
        if ($ctx) {
            $out[] = ['id' => (int) $r['id'], 'company_name' => $r['company_name'],
                      'has_principal' => $r['user_id'] !== null, 'mode' => $ctx['mode']];
        }
    }
    return $out;
}

/**
 * Superadmin: set an admin's access on a dealership they manage.
 * @return array{0:bool,1:string}
 */
function saSetDealerAccess(int $dealerId, int $adminId, string $access, int $actorId): array
{
    if (!in_array($access, ['view', 'operate'], true)) {
        return [false, 'Unknown access level.'];
    }
    if (!sdWorkspaceSchemaReady()) {
        return [false, 'Run migration 0015 first (Admin → Migrations).'];
    }
    $pdo = Database::getInstance();
    $d = $pdo->prepare("SELECT company_name, user_id FROM dealers WHERE id = ? LIMIT 1");
    $d->execute([$dealerId]);
    $dealer = $d->fetch();
    if (!$dealer) {
        return [false, 'That dealership was not found.'];
    }
    if ($access === 'operate' && $dealer['user_id'] !== null) {
        return [false, $dealer['company_name'] . ' has its own principal, so staff can only view it.'];
    }

    $cur = $pdo->prepare("SELECT access FROM dealer_managers WHERE dealer_id = ? AND admin_user_id = ? LIMIT 1");
    $cur->execute([$dealerId, $adminId]);
    $before = $cur->fetchColumn();
    if ($before === false) {
        return [false, 'That admin doesn’t manage this dealership.'];
    }
    if ($before === $access) {
        return [true, 'No change.'];
    }

    $pdo->prepare("UPDATE dealer_managers SET access = ? WHERE dealer_id = ? AND admin_user_id = ?")
        ->execute([$access, $dealerId, $adminId]);
    writeAuditLog('dealer.manager_access_changed', 'dealer', $dealerId,
        ['admin_user_id' => $adminId, 'access' => $before],
        ['admin_user_id' => $adminId, 'access' => $access], $actorId);

    return [true, $access === 'operate'
        ? 'They can now run ' . $dealer['company_name'] . ' — cars, imports, leads and team.'
        : 'They now have view-only access to ' . $dealer['company_name'] . '.'];
}

/** Small badge for a workspace mode (operator / viewer / principal). */
function sdWorkspaceAccessBadge(string $mode): string
{
    return match ($mode) {
        'operator'  => '<span class="badge badge-ws-operate"><i class="fa-solid fa-screwdriver-wrench"></i> Operate</span>',
        'delegate'  => '<span class="badge badge-ws-operate"><i class="fa-solid fa-handshake"></i> Delegated</span>',
        'principal' => '<span class="badge badge-verified">Principal</span>',
        default     => '<span class="badge badge-ws-view"><i class="fa-regular fa-eye"></i> View</span>',
    };
}
