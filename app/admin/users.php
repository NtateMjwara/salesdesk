<?php
/**
 * SalesDesk — Admin: Users & CIPC Verifications
 * Route: /app/admin/users[?tab=users|verifications]
 *
 * Users tab (revised Oct 2026)
 *   Views:       Current (default — everyone except suspended) · Pending ·
 *                Suspended. Suspended accounts never show in the everyday
 *                list; they live in their own view.
 *   Narrowing:   search (email, name, phone, company, #id), role chips,
 *                quick filters (email unverified / stuck in signup / joined
 *                this week), sort, paginate.
 *   Row:         open a user (/app/admin/users-view?id=), suspend /
 *                reactivate (dealer principals cascade: listings paused,
 *                commissions frozen); superadmin: quick Activate / Verify.
 *   Bulk:        tick rows → bulk bar. Every action that makes sense for
 *                many users (UA_BULK_ACTIONS in includes/user_admin.php):
 *                admins get Suspend, Reactivate, Set car limit; superadmins
 *                also get Activate, Activate + verify, Finish onboarding,
 *                Mark email verified / unverified, Resend verification
 *                code, Send password reset code, Clear lockout, Export.
 *                A confirmation shows how many will change and why the
 *                rest will be skipped.
 *   Export:      superadmin, step-up (whole filtered list, or the selection).
 * Verifications tab: approve / reject dealer and desk-org CIPC documents.
 *
 * Guards
 *   requireRole('admin'); admins only see / act on users connected to the
 *   dealerships and orgs they manage (0014). Superadmins see everyone.
 *   Admin accounts never appear here — see /app/admin/admins.
 *   All writes validate CSRF and write to audit_logs.
 *   No inline CSS / JS: styles in assets/css/admin.css, behaviour in
 *   assets/js/admin.js (data-* hooks).
 *
 * NOTE: csrf_hidden_field() is declared in includes/csrf.php — don't
 * redeclare it here ("Cannot redeclare csrf_hidden_field()").
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/mailer.php';
require_once '../../includes/response.php';
require_once '../../includes/user_admin.php';   // loads admin_scope.php + superadmin.php
require_once '../../includes/entitlements.php';   // PLANS-1: plan + car limit column
require_once '../../includes/business.php';       // 0020: org verification mirrors the dealership

applyCachePolicy('auth');
requireRole('admin');

$pdo          = Database::getInstance();
$adminId      = (int) $_SESSION['user_id'];
$isSuperadmin = isSuperadmin($adminId);
$tab          = ($_GET['tab'] ?? '') === 'verifications' ? 'verifications' : 'users';
$filters      = uaFiltersFromRequest($_GET);
$page         = max(1, (int) ($_GET['page'] ?? 1));
$self         = '/app/admin/users';
$back         = $self . (($q = uaFilterQuery($filters, ['page' => $page > 1 ? $page : ''])) ? '?' . $q : '');

// ── CSV export (superadmin, step-up: it's personal information) ──
//    ?export=csv            → the filtered list
//    ?export=csv&ids=4-9-12 → exactly the selected users (from the bulk bar)
if (($_GET['export'] ?? '') === 'csv') {
    if (!$isSuperadmin) {
        $_SESSION['flash_error'] = 'Only a superadmin can export users.';
        redirect($self);
    }
    $exportIds = uaCleanIds(explode('-', is_string($_GET['ids'] ?? null) ? $_GET['ids'] : ''));
    $again     = $exportIds
        ? $self . '?export=csv&ids=' . implode('-', $exportIds)
        : $self . '?' . uaFilterQuery($filters, ['export' => 'csv']);
    sdRequireStepUp($again, 'Exporting users needs a quick confirmation. Nothing was downloaded yet.');
    uaExportCsv($adminId, $filters, $exportIds);
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action       = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $returnTo     = sdSafeAdminPath((string) ($_POST['return_to'] ?? $self));
    $targetUser   = (int) ($_POST['user_id']   ?? 0);
    $targetDealer = (int) ($_POST['dealer_id'] ?? 0);
    $targetOrg    = (int) ($_POST['org_id']    ?? 0);

    // Superadmin-only single-row actions (bulk checks per action in uaBulk()).
    $superOnly = ['activate', 'activate_verify', 'verify_email', 'set_car_limit'];   // PLANS-1: limits are superadmin-only
    if (in_array($action, $superOnly, true) && !$isSuperadmin) {
        $_SESSION['flash_error'] = 'Only a superadmin can do that.';
        redirect($returnTo);
    }

    // 0014 scope check before ANY single-target action.
    $allowed = match ($action) {
        'suspend_user', 'reactivate_user', 'set_car_limit',
        'activate', 'activate_verify', 'verify_email'      => adminCanActOnUser($adminId, $targetUser),
        'approve_dealer_cipc', 'reject_dealer_cipc'         => $targetDealer > 0 && adminCanActOnDealer($adminId, $targetDealer),
        'approve_org_cipc', 'reject_org_cipc'               => $targetOrg > 0 && adminCanActOnOrg($adminId, $targetOrg),
        'bulk'                                              => true,   // checked per user in uaBulk()
        default                                             => false,
    };
    if (!$allowed) {
        $_SESSION['flash_error'] = 'That account isn’t connected to a dealership or organisation you manage.';
        redirect(str_contains($action, 'cipc') ? $self . '?tab=verifications' : $returnTo);
    }

    // ── Bulk: export goes through the step-up'd GET download ──
    $bulkAction = is_string($_POST['bulk_action'] ?? null) ? $_POST['bulk_action'] : '';
    $bulkIds    = (array) ($_POST['ids'] ?? []);
    if ($action === 'bulk' && $bulkAction === 'export_csv') {
        $ids = uaCleanIds($bulkIds);
        if (!$isSuperadmin || !$ids) {
            $_SESSION['flash_error'] = $isSuperadmin ? 'Tick at least one user first.' : 'Only a superadmin can export users.';
            redirect($returnTo);
        }
        redirect($self . '?export=csv&ids=' . implode('-', $ids));
    }

    // ── User actions ───────────────────────────────────────────
    $userResult = match ($action) {
        'suspend_user'    => uaSetStatus($targetUser, 'suspended', $adminId),
        'reactivate_user' => uaSetStatus($targetUser, 'active', $adminId),
        'set_car_limit'   => uaSetCarLimit($targetUser, (int) ($_POST['car_limit'] ?? 0), $adminId),
        'activate'        => uaActivate($targetUser, $adminId),
        'activate_verify' => uaActivate($targetUser, $adminId, true),
        'verify_email'    => uaSetEmailVerified($targetUser, true, $adminId),
        'bulk'            => uaBulk($bulkAction, $bulkIds, $adminId, ['car_limit' => (int) ($_POST['car_limit'] ?? 0)]),
        default           => null,
    };
    if ($userResult !== null) {
        [$ok, $msg] = $userResult;
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($returnTo);
    }

    // ── Approve dealer CIPC ────────────────────────────────────
    if ($action === 'approve_dealer_cipc') {
        $dealerStmt = $pdo->prepare("
            SELECT d.id, d.company_name, d.verification_status, u.email
            FROM dealers d
            LEFT JOIN users u ON u.id = d.user_id
            WHERE d.id = ?
        ");
        $dealerStmt->execute([$targetDealer]);
        $dealer = $dealerStmt->fetch();
        if ($dealer) {
            $pdo->prepare("
                UPDATE dealers SET verification_status = 'verified', verified_at = NOW(), updated_at = NOW() WHERE id = ?
            ")->execute([$targetDealer]);
            writeAuditLog('dealer.cipc_approved', 'dealer', $targetDealer,
                ['verification_status' => $dealer['verification_status']], ['verification_status' => 'verified'], $adminId);
            sdSyncOrgFromDealer($targetDealer);   // 0020
            if ($dealer['email']) {
                sendDealerVerified(['email' => $dealer['email'], 'company_name' => $dealer['company_name']]);
            }
            $_SESSION['flash_ok'] = "{$dealer['company_name']} verified.";
        }
        redirect($self . '?tab=verifications');
    }

    // ── Reject dealer CIPC ─────────────────────────────────────
    if ($action === 'reject_dealer_cipc') {
        $reason     = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        $dealerStmt = $pdo->prepare("
            SELECT d.id, d.company_name, d.verification_status, u.email
            FROM dealers d
            LEFT JOIN users u ON u.id = d.user_id
            WHERE d.id = ?
        ");
        $dealerStmt->execute([$targetDealer]);
        $dealer = $dealerStmt->fetch();
        if ($dealer) {
            $pdo->prepare("UPDATE dealers SET verification_status = 'rejected', updated_at = NOW() WHERE id = ?")
                ->execute([$targetDealer]);
            writeAuditLog('dealer.cipc_rejected', 'dealer', $targetDealer,
                ['verification_status' => $dealer['verification_status']],
                ['verification_status' => 'rejected', 'reason' => $reason], $adminId);
            sdSyncOrgFromDealer($targetDealer);   // 0020
            if ($dealer['email']) {
                sendDealerVerificationRejected(['email' => $dealer['email'], 'company_name' => $dealer['company_name']], $reason);
            }
            $_SESSION['flash_ok'] = 'Verification rejected.';
        }
        redirect($self . '?tab=verifications');
    }

    // ── Approve / reject org CIPC ──────────────────────────────
    if ($action === 'approve_org_cipc' || $action === 'reject_org_cipc') {
        $approve = $action === 'approve_org_cipc';
        $reason  = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        $pdo->prepare("
            UPDATE organizations
            SET verification_status = ?, verified_at = " . ($approve ? 'NOW()' : 'verified_at') . ", updated_at = NOW()
            WHERE id = ?
        ")->execute([$approve ? 'verified' : 'rejected', $targetOrg]);
        writeAuditLog($approve ? 'org.cipc_approved' : 'org.cipc_rejected', 'organization', $targetOrg,
            ['verification_status' => 'pending'],
            ['verification_status' => $approve ? 'verified' : 'rejected'] + ($approve ? [] : ['reason' => $reason]), $adminId);
        $_SESSION['flash_ok'] = $approve ? 'Organisation verified.' : 'Organisation verification rejected.';
        redirect($self . '?tab=verifications');
    }

    redirect($returnTo);
}

// ── GET: load data ────────────────────────────────────────────
$counts     = uaViewCounts($adminId, $filters);
$total      = uaCountUsers($adminId, $filters);
$pages      = max(1, (int) ceil($total / UA_PAGE_SIZE));
$page       = min($page, $pages);
$users      = uaListUsers($adminId, $filters, UA_PAGE_SIZE, ($page - 1) * UA_PAGE_SIZE);
$pending    = getPendingVerifications($adminId);
$cipcCount  = count($pending['dealers']) + count($pending['orgs']);
$planSummaries = sdBrokerPlanSummaries(array_map(static fn ($u) => (int) $u['id'],
                   array_filter($users, static fn ($u) => $u['role'] === 'broker')));
$freeDefault   = sdDefaultPlan('broker');
$freeLimit     = ($freeDefault ? sdPlanLimit($freeDefault, 'max_desk_cars') : null) ?? 150;   // bulk input default
$hasFilters = uaHasFilters($filters);
$bulkMenu   = uaBulkMenu($isSuperadmin);
$view       = $filters['view'];
$viewNoun   = ['current' => 'current users', 'pending' => 'pending users', 'suspended' => 'suspended users'][$view];
$firstRow   = $total ? ($page - 1) * UA_PAGE_SIZE + 1 : 0;
$lastRow    = min($total, $page * UA_PAGE_SIZE);

/** Link to the list with some filters changed (page always resets). */
$link = fn(array $set) => $self . (($qs = uaFilterQuery(array_merge($filters, $set))) ? '?' . $qs : '');
/** Toggle a quick filter on / off. */
$chip = fn(string $key, string $val) => $link([$key => $filters[$key] === $val ? '' : $val]);

// ── Render ────────────────────────────────────────────────────
ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Users &amp; Verifications</h1>
  <span class="section-count"><?= number_format($counts['current'] ?? 0) ?> current users<?= $isSuperadmin ? '' : ' in your dealerships &amp; orgs' ?></span>
  <?php if ($isSuperadmin && $tab === 'users'): ?>
  <a class="btn btn-ghost btn-sm adm-head-action" href="<?= $self . '?' . htmlspecialchars(uaFilterQuery($filters, ['export' => 'csv'])) ?>"
     title="Download the users in this view and filter as CSV">
    <i class="fa-solid fa-file-arrow-down"></i> Export CSV
  </a>
  <?php endif; ?>
</div>

<nav class="adm-tabs" aria-label="Users sections">
  <a href="<?= $self ?>" class="adm-tab<?= $tab === 'users' ? ' is-active' : '' ?>" <?= $tab === 'users' ? 'aria-current="page"' : '' ?>>
    <i class="fa-solid fa-users"></i> Users
  </a>
  <a href="<?= $self ?>?tab=verifications" class="adm-tab<?= $tab === 'verifications' ? ' is-active' : '' ?>" <?= $tab === 'verifications' ? 'aria-current="page"' : '' ?>>
    <i class="fa-solid fa-file-shield"></i> CIPC Verifications<?php if ($cipcCount > 0): ?> <span class="adm-tab-count"><?= $cipcCount ?></span><?php endif; ?>
  </a>
</nav>

<?php if ($tab === 'users'): ?>

<!-- ── Toolbar: views + search ── -->
<div class="ua-toolbar">
  <nav class="ua-views" aria-label="Account status">
    <?php foreach (UA_VIEWS as $key => $label):
      $n = (int) ($counts[$key] ?? 0); ?>
    <a href="<?= htmlspecialchars($link(['view' => $key])) ?>"
       class="ua-view ua-view-<?= $key ?><?= $view === $key ? ' is-active' : '' ?>"
       <?= $view === $key ? 'aria-current="page"' : '' ?>>
      <?= $label ?> <span class="ua-view-n"><?= number_format($n) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <form method="GET" action="<?= $self ?>" class="ua-search" role="search">
    <?php foreach (['view', 'role', 'verified', 'onboarding', 'joined'] as $k):
      if ($filters[$k] === '' || ($k === 'view' && $filters[$k] === 'current')) continue; ?>
    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($filters[$k]) ?>">
    <?php endforeach; ?>
    <div class="ua-search-box">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input class="finput" type="search" name="q" value="<?= htmlspecialchars($filters['q']) ?>"
             placeholder="Email, name, phone, company or #ID" aria-label="Search users">
    </div>
    <select class="finput adm-select-sm" name="sort" aria-label="Sort" data-autosubmit>
      <option value="newest"     <?= $filters['sort'] === 'newest'     ? 'selected' : '' ?>>Newest first</option>
      <option value="oldest"     <?= $filters['sort'] === 'oldest'     ? 'selected' : '' ?>>Oldest first</option>
      <option value="last_login" <?= $filters['sort'] === 'last_login' ? 'selected' : '' ?>>Last sign-in</option>
      <option value="email"      <?= $filters['sort'] === 'email'      ? 'selected' : '' ?>>Email A–Z</option>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
  </form>
</div>

<!-- ── Quick filters ── -->
<div class="ua-chips" aria-label="Filters">
  <div class="ua-chip-group" role="group" aria-label="Role">
    <a href="<?= htmlspecialchars($link(['role' => ''])) ?>" class="ua-chip<?= $filters['role'] === '' ? ' is-on' : '' ?>">All roles</a>
    <?php foreach (UA_ROLES as $r): ?>
    <a href="<?= htmlspecialchars($link(['role' => $r])) ?>" class="ua-chip<?= $filters['role'] === $r ? ' is-on' : '' ?>"><?= uaRoleLabelPlural($r) ?></a>
    <?php endforeach; ?>
  </div>
  <span class="ua-chip-sep" aria-hidden="true"></span>
  <div class="ua-chip-group" role="group" aria-label="Quick filters">
    <a href="<?= htmlspecialchars($chip('verified', 'no')) ?>" class="ua-chip ua-chip-flag<?= $filters['verified'] === 'no' ? ' is-on' : '' ?>">
      Email unverified <span class="ua-chip-n"><?= (int) ($counts['unverified'] ?? 0) ?></span>
    </a>
    <a href="<?= htmlspecialchars($chip('onboarding', 'incomplete')) ?>" class="ua-chip ua-chip-flag<?= $filters['onboarding'] === 'incomplete' ? ' is-on' : '' ?>">
      Stuck in signup <span class="ua-chip-n"><?= (int) ($counts['incomplete'] ?? 0) ?></span>
    </a>
    <a href="<?= htmlspecialchars($chip('joined', 'week')) ?>" class="ua-chip<?= $filters['joined'] === 'week' ? ' is-on' : '' ?>">
      Joined this week <span class="ua-chip-n"><?= (int) ($counts['new_week'] ?? 0) ?></span>
    </a>
    <?php if ($filters['verified'] === 'yes'): ?>
    <a href="<?= htmlspecialchars($link(['verified' => ''])) ?>" class="ua-chip is-on">Email verified ×</a>
    <?php endif; ?>
    <?php if ($filters['onboarding'] === 'complete'): ?>
    <a href="<?= htmlspecialchars($link(['onboarding' => ''])) ?>" class="ua-chip is-on">Onboarded ×</a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Result line ── -->
<div class="ua-resultline">
  <span class="adm-muted">
    <?php if ($total): ?>
    Showing <strong><?= number_format($firstRow) ?>–<?= number_format($lastRow) ?></strong> of <strong><?= number_format($total) ?></strong> <?= $viewNoun ?>
    <?php else: ?>
    No <?= $viewNoun ?> to show
    <?php endif; ?>
    <?php if ($filters['q'] !== ''): ?> matching “<?= htmlspecialchars($filters['q']) ?>”<?php endif; ?>
  </span>
  <?php if ($hasFilters): ?>
  <a class="ua-clear" href="<?= htmlspecialchars($self . ($view !== 'current' ? '?view=' . $view : '')) ?>"><i class="fa-solid fa-xmark"></i> Clear filters</a>
  <?php endif; ?>
</div>

<?php if ($view === 'suspended' && $total): ?>
<div class="ua-viewnote">
  <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
  Suspended users can’t sign in. Suspended dealers’ listings stay paused and their pending commissions stay frozen until they’re reactivated.
</div>
<?php endif; ?>

<!-- ── Users table ── -->
<div class="roster-wrap ua-table-wrap">
  <table class="roster ua-table<?= $view === 'suspended' ? ' ua-table-suspended' : '' ?>">
    <thead>
      <tr>
        <th class="ua-col-check"><input type="checkbox" data-check-all aria-label="Select all on this page"<?= $users ? '' : ' disabled' ?>></th>
        <th>User</th>
        <th>Role</th>
        <th>Status</th>
        <th>Activity</th>
        <th class="ua-col-num">Plan · cars</th>
        <th class="ua-col-actions"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$users): ?>
      <tr class="ua-empty-row"><td colspan="7">
        <div class="ua-empty">
          <span class="empty-icon"><i class="fa-solid fa-user-slash"></i></span>
          <p>No <?= $viewNoun ?><?= $hasFilters ? ' match these filters' : '' ?>.</p>
          <?php if ($view === 'current' && ($counts['suspended'] ?? 0) > 0 && $filters['q'] !== ''): ?>
          <p class="adm-muted"><?= (int) $counts['suspended'] ?> suspended account<?= $counts['suspended'] === 1 ? '' : 's' ?> match<?= $counts['suspended'] === 1 ? 'es' : '' ?> your search.</p>
          <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($link(['view' => 'suspended'])) ?>">Show suspended</a>
          <?php elseif ($hasFilters): ?>
          <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($self . ($view !== 'current' ? '?view=' . $view : '')) ?>">Clear filters</a>
          <?php endif; ?>
        </div>
      </td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u):
      $uid         = (int) $u['id'];
      $displayName = uaDisplayName($u);
      $userLink    = '/app/admin/users-view?id=' . $uid;
      $context     = $u['exec_dealer'] ? 'Sales exec at ' . $u['exec_dealer']
                   : ($u['org_name'] ? 'Network · ' . $u['org_name'] . ($u['org_status'] !== 'verified' ? ' (' . $u['org_status'] . ')' : '')
                       . ((int) $u['net_count'] > 1 ? ' +' . ((int) $u['net_count'] - 1) : '') : '');   // 2b: several networks
      $nameLine    = implode(' · ', array_filter([$displayName, $context]));
      $isDealer    = $u['role'] === 'dealer' ? '1' : '0';
    ?>
    <tr class="ua-row ua-row-<?= htmlspecialchars($u['status']) ?>" data-row>
      <td class="ua-col-check">
        <input type="checkbox" name="ids[]" value="<?= $uid ?>" form="bulkForm" data-check-row
               data-bulk-ok="<?= uaBulkEligibleKeys($u, $isSuperadmin) ?>" data-dealer="<?= $isDealer ?>"
               aria-label="Select <?= htmlspecialchars($u['email']) ?>">
      </td>
      <td class="ua-col-user">
        <div class="ua-user">
          <span class="ua-avatar ua-avatar-<?= htmlspecialchars($u['role']) ?>" aria-hidden="true"><?= htmlspecialchars(uaInitials($u)) ?></span>
          <div class="ua-user-text">
            <a class="adm-strong ua-email" href="<?= $userLink ?>" title="<?= htmlspecialchars($u['email']) ?>"><?= htmlspecialchars($u['email']) ?></a>
            <?php if ($nameLine !== ''): ?><div class="adm-sub"><?= htmlspecialchars($nameLine) ?></div><?php endif; ?>
          </div>
        </div>
      </td>
      <td data-label="Role"><span class="badge <?= uaRoleBadgeClass($u['role']) ?>"><?= uaRoleLabel($u['role']) ?></span></td>
      <td data-label="Status">
        <?= adminStatusBadge($u['status']) ?>
        <?php if (!$u['email_verified'] || !$u['onboarding_completed']): ?>
        <div class="ua-flags">
          <?php if (!$u['email_verified']): ?><span class="ua-flag"><i class="fa-regular fa-envelope"></i> Email unverified</span><?php endif; ?>
          <?php if (!$u['onboarding_completed']): ?><span class="ua-flag"><i class="fa-solid fa-hourglass-half"></i> Signup unfinished</span><?php endif; ?>
        </div>
        <?php endif; ?>
      </td>
      <td class="ua-activity" data-label="Activity">
        <div><span class="ua-activity-k">Joined</span> <?= date('d M Y', strtotime($u['created_at'])) ?></div>
        <div><span class="ua-activity-k">Last in</span> <?= $u['last_login'] ? date('d M Y', strtotime($u['last_login'])) : 'Never' ?></div>
      </td>
      <td class="ua-col-num" data-label="Plan · cars">
        <?php if ($u['role'] === 'broker' && ($ps = $planSummaries[$uid] ?? null)): ?>
        <span class="adm-sub"><?= htmlspecialchars($ps['plan']) ?><?= $ps['comped'] ? ' (comped)' : '' ?></span>
        <span class="adm-mono" title="<?= $ps['custom'] ? 'Custom limit' : 'Plan limit' ?>"><?= sdLimitText($ps['limit']) ?><?= $ps['custom'] ? '*' : '' ?></span>
        <?php elseif ($u['role'] === 'broker'): ?>
        <span class="adm-mono"><?= (int) ($u['car_limit'] ?? getPlatformConfigInt('broker_car_limit_default', 10)) ?></span>
        <?php else: ?><span class="adm-sub">—</span><?php endif; ?>
      </td>
      <td class="ua-col-actions">
        <div class="adm-actions">
          <?php if ($isSuperadmin && $u['status'] === 'pending'): ?>
          <form method="POST">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="<?= $u['email_verified'] ? 'activate' : 'activate_verify' ?>">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($back) ?>">
            <button class="btn btn-success btn-sm" type="submit" title="<?= $u['email_verified'] ? 'Activate' : 'Activate and mark email verified' ?>">Activate</button>
          </form>
          <?php elseif ($isSuperadmin && !$u['email_verified'] && $u['status'] === 'active'): ?>
          <form method="POST">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="verify_email">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($back) ?>">
            <button class="btn btn-info btn-sm" type="submit">Verify</button>
          </form>
          <?php endif; ?>

          <?php if ($u['status'] === 'suspended'): ?>
          <button class="btn btn-info btn-sm" type="button" data-modal-open="statusModal"
                  data-mode="reactivate_user" data-user-id="<?= $uid ?>"
                  data-name="<?= htmlspecialchars($displayName ?: $u['email']) ?>"
                  data-dealer="<?= $isDealer ?>">Reactivate</button>
          <?php else: ?>
          <button class="btn btn-ghost btn-sm ua-btn-suspend" type="button" data-modal-open="statusModal"
                  data-mode="suspend_user" data-user-id="<?= $uid ?>"
                  data-name="<?= htmlspecialchars($displayName ?: $u['email']) ?>"
                  data-dealer="<?= $isDealer ?>">Suspend</button>
          <?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="<?= $userLink ?>"><?= $isSuperadmin ? 'Manage' : 'View' ?></a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<nav class="ua-pager" aria-label="Pages">
  <?php if ($page > 1): ?>
  <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($link(['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i> Previous</a>
  <?php endif; ?>
  <span class="adm-muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?>
  <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($link(['page' => $page + 1])) ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<!-- ── Bulk bar: appears (sticky) once rows are ticked.
     Row checkboxes join this form via form="bulkForm". ── -->
<form method="POST" action="<?= $self ?>" id="bulkForm" class="ua-bulkbar" data-bulk-form hidden
      data-email-max="<?= UA_BULK_EMAIL_MAX ?>">
  <?= csrf_hidden_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="return_to" value="<?= htmlspecialchars($back) ?>">
  <div class="ua-bulk-count">
    <strong data-bulk-count>0</strong> selected
    <button type="button" class="ua-bulk-clear" data-bulk-clear>Clear</button>
  </div>
  <div class="ua-bulk-controls">
    <select class="finput adm-select-sm" name="bulk_action" required aria-label="Bulk action" data-bulk-action>
      <option value="">Choose an action…</option>
      <?php foreach ($bulkMenu as $group => $items): ?>
      <optgroup label="<?= htmlspecialchars($group) ?>">
        <?php foreach ($items as $key => $label): $def = UA_BULK_ACTIONS[$key]; ?>
        <option value="<?= $key ?>" data-done="<?= htmlspecialchars($def['done']) ?>"
                data-email="<?= $def['email'] ? '1' : '0' ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>
    <label class="ua-bulk-limit" data-bulk-limit hidden>
      <span class="sr-only">Car limit</span>
      <input class="finput" type="number" name="car_limit" min="1" max="100000" value="<?= (int) $freeLimit ?>"
             aria-label="New custom car limit"> <span class="adm-muted">cars (custom limit)</span>
    </label>
    <span class="adm-muted ua-bulk-hint" data-bulk-hint></span>
    <button class="btn btn-primary btn-sm" type="submit" data-bulk-submit disabled>Apply</button>
  </div>
</form>

<!-- ── Bulk confirmation (filled by admin.js) ── -->
<div class="modal-bg" id="bulkModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="bulkModalTitle">
    <div class="modal-title" id="bulkModalTitle" data-bulk-title>Apply to selected users?</div>
    <ul class="ua-bulk-summary" data-bulk-summary></ul>
    <div class="alert alert-warn ua-modal-alert" data-bulk-warn hidden>
      <span class="alert-icon">⚠</span>
      <div data-bulk-warn-text></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
      <button type="button" class="btn btn-primary" data-bulk-confirm>Confirm</button>
    </div>
  </div>
</div>

<!-- ── Suspend / reactivate modal (one for all rows) ── -->
<div class="modal-bg" id="statusModal">
  <div class="modal">
    <div class="modal-title" data-status-title>Suspend user</div>
    <p class="modal-sub" data-status-sub></p>
    <div class="alert alert-warn ua-modal-alert" data-status-dealer-note hidden>
      <span class="alert-icon">⚠</span>
      <div data-status-dealer-text></div>
    </div>
    <form method="POST" action="<?= $self ?>">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" data-status-action value="suspend_user">
      <input type="hidden" name="user_id" data-status-user value="">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($back) ?>">
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger" data-status-submit>Suspend</button>
      </div>
    </form>
  </div>
</div>

<?php else: /* tab=verifications */ ?>

<h3 class="adm-h3">Dealer verifications <span class="section-count"><?= count($pending['dealers']) ?></span></h3>
<?php if (!$pending['dealers']): ?>
<div class="empty adm-gap"><span class="empty-icon">✓</span>No pending dealer verifications.</div>
<?php else: ?>
<div class="roster-wrap adm-gap">
  <table class="roster">
    <thead><tr><th>Dealership</th><th>Email</th><th>Location</th><th>Submitted</th><th>Document</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($pending['dealers'] as $d): ?>
    <tr>
      <td class="adm-strong"><?= htmlspecialchars($d['company_name']) ?></td>
      <td class="adm-muted"><?= htmlspecialchars($d['email'] ?? '—') ?></td>
      <td class="adm-muted"><?= htmlspecialchars(implode(', ', array_filter([$d['city'], $d['province']])) ?: '—') ?></td>
      <td class="ua-date"><?= date('d M Y', strtotime($d['submitted_at'])) ?></td>
      <td>
        <?php if ($d['cipc_doc_url']): ?>
        <a href="<?= htmlspecialchars($d['cipc_doc_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">View PDF ↗</a>
        <?php else: ?><span class="adm-sub">No document</span><?php endif; ?>
      </td>
      <td>
        <div class="adm-actions">
          <form method="POST" data-confirm="Approve and notify <?= htmlspecialchars($d['company_name'], ENT_QUOTES) ?>?">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="approve_dealer_cipc">
            <input type="hidden" name="dealer_id" value="<?= (int) $d['id'] ?>">
            <button class="btn btn-success btn-sm" type="submit">Approve</button>
          </form>
          <button class="btn btn-danger btn-sm" type="button" data-modal-open="rejectModal"
                  data-reject-action="reject_dealer_cipc" data-dealer-id="<?= (int) $d['id'] ?>" data-org-id=""
                  data-name="<?= htmlspecialchars($d['company_name']) ?>">Reject</button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h3 class="adm-h3">Organisation verifications <span class="section-count"><?= count($pending['orgs']) ?></span></h3>
<?php if (!$pending['orgs']): ?>
<div class="empty"><span class="empty-icon">✓</span>No pending organisation verifications.</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead><tr><th>Organisation</th><th>Owner email</th><th>CIPC number</th><th>Location</th><th>Submitted</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($pending['orgs'] as $o): ?>
    <tr>
      <td class="adm-strong"><?= htmlspecialchars($o['name']) ?></td>
      <td class="adm-muted"><?= htmlspecialchars($o['owner_email']) ?></td>
      <td class="adm-mono"><?= htmlspecialchars($o['cipc_number'] ?? '—') ?></td>
      <td class="adm-muted"><?= htmlspecialchars(implode(', ', array_filter([$o['city'], $o['province']])) ?: '—') ?></td>
      <td class="ua-date"><?= date('d M Y', strtotime($o['submitted_at'])) ?></td>
      <td>
        <div class="adm-actions">
          <form method="POST" data-confirm="Approve this organisation?">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="approve_org_cipc">
            <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
            <button class="btn btn-success btn-sm" type="submit">Approve</button>
          </form>
          <button class="btn btn-danger btn-sm" type="button" data-modal-open="rejectModal"
                  data-reject-action="reject_org_cipc" data-dealer-id="" data-org-id="<?= (int) $o['id'] ?>"
                  data-name="<?= htmlspecialchars($o['name']) ?>">Reject</button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Reject CIPC modal ── -->
<div class="modal-bg" id="rejectModal">
  <div class="modal">
    <div class="modal-title ua-title-red">Reject verification</div>
    <p class="modal-sub" data-reject-sub>Provide a reason — this will be included in the email to the applicant.</p>
    <form method="POST" action="<?= $self ?>">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" data-reject-action-input>
      <input type="hidden" name="dealer_id" data-reject-dealer-input>
      <input type="hidden" name="org_id" data-reject-org-input>
      <div class="fgroup">
        <label class="flabel" for="rejectReasonInput">Reason</label>
        <textarea class="finput" id="rejectReasonInput" name="reason" rows="3" maxlength="500"
                  placeholder="e.g. Document is illegible. Please re-upload a clear scan of your CIPC certificate."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger">Reject &amp; notify</button>
      </div>
    </form>
  </div>
</div>

<?php endif; // tab switch ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Users & Verifications | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
