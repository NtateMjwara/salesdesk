<?php
/**
 * SalesDesk — Admin: Users & CIPC Verifications
 * Route: /app/admin/users[?tab=users|verifications]
 *
 * Users tab
 *   Everyone:    search (email, name, phone, company, #id), filter by role /
 *                status / email verified / onboarding, sort, paginate, open a
 *                user (/app/admin/users-view?id=), suspend / reactivate (dealer
 *                principals cascade: listings paused, commissions frozen),
 *                broker car limit.
 *   Superadmin:  quick Activate / Verify email per row, bulk Activate /
 *                Activate + verify / Verify email, CSV export (step-up).
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
if (($_GET['export'] ?? '') === 'csv') {
    if (!$isSuperadmin) {
        $_SESSION['flash_error'] = 'Only a superadmin can export users.';
        redirect($self);
    }
    sdRequireStepUp($self . '?' . uaFilterQuery($filters, ['export' => 'csv']),
        'Exporting users needs a quick confirmation. Nothing was downloaded yet.');
    uaExportCsv($adminId, $filters);
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action       = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $returnTo     = sdSafeAdminPath((string) ($_POST['return_to'] ?? $self));
    $targetUser   = (int) ($_POST['user_id']   ?? 0);
    $targetDealer = (int) ($_POST['dealer_id'] ?? 0);
    $targetOrg    = (int) ($_POST['org_id']    ?? 0);

    // Superadmin-only actions.
    $superOnly = ['activate', 'activate_verify', 'verify_email', 'bulk'];
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

    // ── User actions ───────────────────────────────────────────
    $userResult = match ($action) {
        'suspend_user'    => uaSetStatus($targetUser, 'suspended', $adminId),
        'reactivate_user' => uaSetStatus($targetUser, 'active', $adminId),
        'set_car_limit'   => uaSetCarLimit($targetUser, (int) ($_POST['car_limit'] ?? 0), $adminId),
        'activate'        => uaActivate($targetUser, $adminId),
        'activate_verify' => uaActivate($targetUser, $adminId, true),
        'verify_email'    => uaSetEmailVerified($targetUser, true, $adminId),
        'bulk'            => uaBulk((string) ($_POST['bulk_action'] ?? ''), (array) ($_POST['ids'] ?? []), $adminId),
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
$stats      = uaStats($adminId);
$total      = uaCountUsers($adminId, $filters);
$pages      = max(1, (int) ceil($total / UA_PAGE_SIZE));
$page       = min($page, $pages);
$users      = uaListUsers($adminId, $filters, UA_PAGE_SIZE, ($page - 1) * UA_PAGE_SIZE);
$pending    = getPendingVerifications($adminId);
$cipcCount  = count($pending['dealers']) + count($pending['orgs']);
$defaultCarLimit = getPlatformConfigInt('broker_car_limit_default', 10);
$hasFilters = $filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== ''
           || $filters['verified'] !== '' || $filters['onboarding'] !== '';

/** Link to the list with one filter changed (stat-strip shortcuts). */
$quick = fn(array $set) => $self . '?' . uaFilterQuery(array_merge(uaFiltersFromRequest([]), $set));

// ── Render ────────────────────────────────────────────────────
ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Users &amp; Verifications</h1>
  <span class="section-count"><?= number_format($stats['total'] ?? 0) ?> users<?= $isSuperadmin ? '' : ' in your dealerships &amp; orgs' ?></span>
  <?php if ($cipcCount > 0): ?>
  <span class="section-count alert-count"><?= $cipcCount ?> pending CIPC</span>
  <?php endif; ?>
  <?php if ($isSuperadmin && $tab === 'users'): ?>
  <a class="btn btn-ghost btn-sm adm-head-action" href="<?= $self . '?' . htmlspecialchars(uaFilterQuery($filters, ['export' => 'csv'])) ?>">
    <i class="fa-solid fa-file-arrow-down"></i> Export CSV
  </a>
  <?php endif; ?>
</div>

<nav class="adm-tabs" aria-label="Users sections">
  <a href="<?= $self ?>" class="adm-tab<?= $tab === 'users' ? ' is-active' : '' ?>" <?= $tab === 'users' ? 'aria-current="page"' : '' ?>>Users</a>
  <a href="<?= $self ?>?tab=verifications" class="adm-tab<?= $tab === 'verifications' ? ' is-active' : '' ?>" <?= $tab === 'verifications' ? 'aria-current="page"' : '' ?>>
    CIPC Verifications<?php if ($cipcCount > 0): ?> <span class="adm-tab-count"><?= $cipcCount ?></span><?php endif; ?>
  </a>
</nav>

<?php if ($tab === 'users'): ?>

<div class="adm-stats">
  <a class="adm-stat ua-stat-link" href="<?= $quick(['status' => 'pending']) ?>"><span class="adm-stat-n"><?= $stats['pending'] ?? 0 ?></span><span class="adm-stat-l">Pending</span></a>
  <a class="adm-stat ua-stat-link" href="<?= $quick(['verified' => 'no']) ?>"><span class="adm-stat-n"><?= $stats['unverified'] ?? 0 ?></span><span class="adm-stat-l">Email unverified</span></a>
  <a class="adm-stat ua-stat-link" href="<?= $quick(['status' => 'suspended']) ?>"><span class="adm-stat-n"><?= $stats['suspended'] ?? 0 ?></span><span class="adm-stat-l">Suspended</span></a>
  <div class="adm-stat"><span class="adm-stat-n"><?= $stats['new_week'] ?? 0 ?></span><span class="adm-stat-l">New this week</span></div>
</div>

<!-- ── Filters ── -->
<form method="GET" action="<?= $self ?>" class="adm-filter ua-filter">
  <input class="finput ua-filter-q" name="q" value="<?= htmlspecialchars($filters['q']) ?>"
         placeholder="Email, name, phone, company or #ID" aria-label="Search users">
  <select class="finput adm-select-sm" name="role" aria-label="Role">
    <option value="">All roles</option>
    <?php foreach (UA_ROLES as $r): ?>
    <option value="<?= $r ?>" <?= $filters['role'] === $r ? 'selected' : '' ?>><?= uaRoleLabel($r) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="finput adm-select-sm" name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (UA_STATUSES as $s): ?>
    <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="finput adm-select-sm" name="verified" aria-label="Email verified">
    <option value="">Email: any</option>
    <option value="yes" <?= $filters['verified'] === 'yes' ? 'selected' : '' ?>>Verified</option>
    <option value="no"  <?= $filters['verified'] === 'no'  ? 'selected' : '' ?>>Unverified</option>
  </select>
  <select class="finput adm-select-sm" name="onboarding" aria-label="Onboarding">
    <option value="">Onboarding: any</option>
    <option value="complete"   <?= $filters['onboarding'] === 'complete'   ? 'selected' : '' ?>>Complete</option>
    <option value="incomplete" <?= $filters['onboarding'] === 'incomplete' ? 'selected' : '' ?>>Stuck in signup</option>
  </select>
  <select class="finput adm-select-sm" name="sort" aria-label="Sort">
    <option value="newest"     <?= $filters['sort'] === 'newest'     ? 'selected' : '' ?>>Newest</option>
    <option value="oldest"     <?= $filters['sort'] === 'oldest'     ? 'selected' : '' ?>>Oldest</option>
    <option value="last_login" <?= $filters['sort'] === 'last_login' ? 'selected' : '' ?>>Last sign-in</option>
    <option value="email"      <?= $filters['sort'] === 'email'      ? 'selected' : '' ?>>Email A–Z</option>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
  <?php if ($hasFilters): ?><a href="<?= $self ?>" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
</form>

<?php if ($isSuperadmin): ?>
<!-- ── Bulk actions (row checkboxes join this form via form="bulkForm") ── -->
<form method="POST" action="<?= $self ?>" id="bulkForm" class="ua-bulkbar" data-bulk-form
      data-confirm="Apply this to the selected users?">
  <?= csrf_hidden_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="return_to" value="<?= htmlspecialchars($back) ?>">
  <span class="adm-muted"><strong data-bulk-count>0</strong> selected</span>
  <select class="finput adm-select-sm" name="bulk_action" required aria-label="Bulk action">
    <option value="">Choose action…</option>
    <option value="activate_verify">Activate + verify email</option>
    <option value="activate">Activate</option>
    <option value="verify_email">Verify email</option>
  </select>
  <button class="btn btn-primary btn-sm" type="submit" data-bulk-submit disabled>Apply</button>
</form>
<?php endif; ?>

<!-- ── Users table ── -->
<div class="roster-wrap">
  <table class="roster ua-table">
    <thead>
      <tr>
        <?php if ($isSuperadmin): ?>
        <th class="ua-col-check"><input type="checkbox" data-check-all aria-label="Select all on this page"></th>
        <?php endif; ?>
        <th>User</th>
        <th>Role</th>
        <th>Status</th>
        <th>Email</th>
        <th>Joined</th>
        <th>Last sign-in</th>
        <th>Car limit</th>
        <th class="ua-col-actions">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$users): ?>
      <tr><td colspan="<?= $isSuperadmin ? 9 : 8 ?>" class="ua-empty">No users found.</td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u):
      $uid         = (int) $u['id'];
      $displayName = uaDisplayName($u);
      $link        = '/app/admin/users-view?id=' . $uid;
      $context     = $u['exec_dealer'] ? 'Sales exec at ' . $u['exec_dealer']
                   : ($u['org_name'] ? 'Agent · ' . $u['org_name'] . ($u['org_status'] !== 'verified' ? ' (' . $u['org_status'] . ')' : '') : '');
    ?>
    <tr>
      <?php if ($isSuperadmin): ?>
      <td class="ua-col-check"><input type="checkbox" name="ids[]" value="<?= $uid ?>" form="bulkForm" data-check-row
             aria-label="Select <?= htmlspecialchars($u['email']) ?>"></td>
      <?php endif; ?>
      <td>
        <a class="adm-strong" href="<?= $link ?>"><?= htmlspecialchars($u['email']) ?></a>
        <?php if ($displayName !== ''): ?><div class="adm-sub"><?= htmlspecialchars($displayName) ?></div><?php endif; ?>
        <?php if ($context !== ''): ?><div class="adm-sub"><?= htmlspecialchars($context) ?></div><?php endif; ?>
      </td>
      <td><span class="badge <?= uaRoleBadgeClass($u['role']) ?>"><?= uaRoleLabel($u['role']) ?></span></td>
      <td>
        <?= adminStatusBadge($u['status']) ?>
        <?php if (!$u['onboarding_completed']): ?><div class="adm-sub">Signup unfinished</div><?php endif; ?>
      </td>
      <td class="<?= $u['email_verified'] ? 'ua-yes' : 'ua-no' ?>">
        <?= $u['email_verified'] ? '<i class="fa-solid fa-check"></i> Verified' : '— Unverified' ?>
      </td>
      <td class="ua-date"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
      <td class="ua-date"><?= $u['last_login'] ? date('d M Y', strtotime($u['last_login'])) : 'Never' ?></td>
      <td>
        <?php if ($u['role'] === 'broker'): ?>
        <form method="POST" class="ua-limit-form">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="set_car_limit">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars($back) ?>">
          <input class="ua-limit-input" type="number" name="car_limit" min="1" max="200"
                 value="<?= (int) ($u['car_limit'] ?? $defaultCarLimit) ?>" aria-label="Car limit">
          <button class="btn btn-ghost btn-sm" type="submit">Set</button>
        </form>
        <?php else: ?>
        <span class="adm-sub">—</span>
        <?php endif; ?>
      </td>
      <td>
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
                  data-dealer="<?= $u['role'] === 'dealer' ? '1' : '0' ?>">Reactivate</button>
          <?php elseif ($u['status'] === 'active'): ?>
          <button class="btn btn-warn btn-sm" type="button" data-modal-open="statusModal"
                  data-mode="suspend_user" data-user-id="<?= $uid ?>"
                  data-name="<?= htmlspecialchars($displayName ?: $u['email']) ?>"
                  data-dealer="<?= $u['role'] === 'dealer' ? '1' : '0' ?>">Suspend</button>
          <?php endif; ?>
          <a class="btn btn-ghost btn-sm" href="<?= $link ?>"><?= $isSuperadmin ? 'Manage' : 'View' ?></a>
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
  <a class="btn btn-ghost btn-sm" href="<?= $self . '?' . htmlspecialchars(uaFilterQuery($filters, ['page' => $page - 1])) ?>">← Previous</a>
  <?php endif; ?>
  <span class="adm-muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> users</span>
  <?php if ($page < $pages): ?>
  <a class="btn btn-ghost btn-sm" href="<?= $self . '?' . htmlspecialchars(uaFilterQuery($filters, ['page' => $page + 1])) ?>">Next →</a>
  <?php endif; ?>
</nav>
<?php elseif ($hasFilters): ?>
<p class="adm-muted ua-pager"><?= number_format($total) ?> matching user<?= $total === 1 ? '' : 's' ?></p>
<?php endif; ?>

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
