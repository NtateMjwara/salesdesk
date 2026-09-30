<?php
/**
 * SalesDesk — Admin: Manage one dealership  (0012)
 * Route: /app/admin/dealerships-view?id={dealer_id}[&tab=team|details|managers]
 *
 * Tabs:
 *   team      — pending sales exec requests (approve / decline) + roster
 *               (suspend / reinstate). Same rules as app/dealer/team.php.
 *   details   — name, location, brand focus, logo, verification, active flag
 *   managers  — co-managing admins (add by email, remove; never the last one)
 *
 * Scope: only reachable if the admin has a dealer_managers row for this
 * dealership (getManagedDealer). Every POST re-checks via the same getter.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';

applyCachePolicy('auth');
requireRole('admin');
adminRequireSchema('Dealership');   // clear 'run migration' screen if the DB is behind

$pdo      = Database::getInstance();
$adminId  = (int) $_SESSION['user_id'];
$dealerId = (int) ($_GET['id'] ?? $_POST['dealer_id'] ?? 0);
$tab      = in_array($_GET['tab'] ?? '', ['team', 'details', 'managers', 'platform'], true) ? $_GET['tab'] : 'team';

$dealer = $dealerId ? getManagedDealer($adminId, $dealerId) : false;
if (!$dealer) {
    $_SESSION['flash_error'] = 'That dealership was not found, or you don\'t manage it.';
    redirect('/app/admin/dealerships');
}

$self = '/app/admin/dealerships-view?id=' . $dealerId;

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // Sales exec review — approve / reject / suspend / reinstate
    if (in_array($action, ['approve', 'reject', 'suspend', 'reinstate'], true)) {
        [$ok, $msg] = adminReviewSalesExec(
            $adminId,
            (int) ($_POST['exec_id'] ?? $_POST['target_id'] ?? 0),
            $action,
            (string) ($_POST['reason'] ?? '')
        );
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=team');
    }

    // Edit details
    if ($action === 'save_details') {
        $name = mb_substr(trim($_POST['company_name'] ?? ''), 0, 120);
        if (mb_strlen($name) < 2) {
            $_SESSION['flash_error'] = 'Please enter the dealership name.';
            redirect($self . '&tab=details');
        }
        $verified = ($_POST['verification_status'] ?? '') === 'verified';
        $before   = [
            'company_name'        => $dealer['company_name'],
            'verification_status' => $dealer['verification_status'],
        ];

        $pdo->beginTransaction();
        try {
            $addressId = adminSaveAddress($dealer['address_id'] ? (int) $dealer['address_id'] : null, $_POST);
            $slug      = $name !== $dealer['company_name']
                ? adminUniqueSlug('dealers', $name, 80, $dealerId)
                : $dealer['slug'];

            // Verification: admins can mark verified/unverified. A dealer
            // that is mid-CIPC review (pending/rejected) keeps that status
            // unless the admin explicitly ticks "verified".
            $newStatus = $verified
                ? 'verified'
                : (in_array($dealer['verification_status'], ['pending', 'rejected'], true)
                    ? $dealer['verification_status'] : 'unverified');

            $pdo->prepare("
                UPDATE dealers
                SET company_name = ?, slug = ?, logo_url = ?, address_id = ?, brand_focus = ?,
                    verification_status = ?,
                    verified_at = " . ($newStatus === 'verified' ? 'COALESCE(verified_at, NOW())' : 'NULL') . ",   -- decided in PHP: avoids collation error 1267
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $name, $slug,
                adminCleanUrl($_POST['logo_url'] ?? ''),
                $addressId,
                adminBrandFocusJson($_POST['brand_focus'] ?? ''),
                $newStatus,
                $dealerId,
            ]);
            writeAuditLog('dealer.updated_by_admin', 'dealer', $dealerId, $before,
                ['company_name' => $name, 'verification_status' => $newStatus], $adminId);
            $pdo->commit();
            $_SESSION['flash_ok'] = 'Dealership details saved.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = adminDbErrorMessage($e, 'admin/dealership save');
        }
        redirect($self . '&tab=details');
    }

    // Take offline / bring back online (pauses / restores listings)
    if ($action === 'deactivate' || $action === 'activate') {
        if ($dealer['user_id']) {
            // Principal-linked dealership: suspension is tied to the principal's
            // login and handled from Users & Verifications, as before.
            $_SESSION['flash_error'] = 'This dealership has a principal — suspend it from Users & Verifications.';
        } else {
            [$ok, $msg] = adminSetDealerOnline($adminId, $dealerId, $action === 'activate');
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        }
        redirect($self . '&tab=details');
    }

    // 0017: platform controls (superadmin only) — listing holds, handover
    if (in_array($action, ['hold_listing', 'release_hold', 'handover'], true)) {
        if (!isSuperadmin($adminId)) {
            $_SESSION['flash_error'] = 'Only a superadmin can do that.';
            redirect($self . '&tab=platform');
        }
        if ($action === 'handover') {
            sdRequireStepUp($self . '&tab=platform');
            [$ok, $msg] = saHandoverToPrincipal($dealerId, (string) ($_POST['email'] ?? ''),
                (string) ($_POST['first_name'] ?? ''), (string) ($_POST['last_name'] ?? ''), $adminId);
        } else {
            $carId = (int) ($_POST['car_id'] ?? 0);
            $own   = $pdo->prepare("SELECT 1 FROM cars WHERE id = ? AND dealer_id = ?");
            $own->execute([$carId, $dealerId]);
            [$ok, $msg] = !$own->fetchColumn()
                ? [false, 'That listing isn’t at this dealership.']
                : ($action === 'hold_listing'
                    ? sdHoldListing($carId, (string) ($_POST['reason'] ?? ''), $adminId)
                    : sdReleaseHold($carId, $adminId));
        }
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=platform');
    }

    // 0015: View / Operate for a managing admin (superadmin only)
    if ($action === 'set_access') {
        if (!isSuperadmin($adminId)) {
            $_SESSION['flash_error'] = 'Only a superadmin can change what an admin may do here.';
        } else {
            [$ok, $msg] = saSetDealerAccess($dealerId, (int) ($_POST['admin_user_id'] ?? 0),
                (string) ($_POST['access'] ?? ''), $adminId);
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        }
        redirect($self . '&tab=managers');
    }

    // Co-managers — 0014: assigning admins is a superadmin action
    if (in_array($action, ['add_manager', 'remove_manager'], true) && !isSuperadmin($adminId)) {
        $_SESSION['flash_error'] = 'Only a superadmin can change who manages this.';
        redirect($self . '&tab=managers');
    }
    if ($action === 'add_manager') {
        [$ok, $msg] = adminAddManager('dealer', $dealerId, (string) ($_POST['email'] ?? ''), $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=managers');
    }
    if ($action === 'remove_manager') {
        $target = (int) ($_POST['admin_user_id'] ?? 0);
        [$ok, $msg] = adminRemoveManager('dealer', $dealerId, $target, $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($ok && $target === $adminId ? '/app/admin/dealerships' : $self . '&tab=managers');
    }

    redirect($self);
}

// ── Data ──────────────────────────────────────────────────────
$pending = adminPendingExecApplications($adminId, $dealerId);

$rosterStmt = $pdo->prepare("
    SELECT se.id, se.verification_status, se.job_title, se.rejection_reason,
           se.verified_at, se.created_at,
           u.email, p.first_name, p.last_name, p.phone,
           (SELECT COUNT(*) FROM cars c WHERE c.uploaded_by_exec_id = se.id AND c.status = 'active') AS active_cars
    FROM sales_executives se
    JOIN users u         ON u.id = se.user_id
    LEFT JOIN profiles p ON p.user_id = se.user_id
    WHERE se.dealer_id = ? AND se.verification_status != 'pending'
    ORDER BY FIELD(se.verification_status, 'verified', 'suspended', 'rejected'), se.created_at DESC
");
$rosterStmt->execute([$dealerId]);
$roster = $rosterStmt->fetchAll();

$statsStmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM cars  WHERE dealer_id = ? AND status = 'active') AS active_cars,
        (SELECT COUNT(*) FROM leads WHERE dealer_id = ?)                       AS total_leads,
        (SELECT COUNT(*) FROM leads WHERE dealer_id = ? AND status NOT IN ('closed','lost')) AS open_leads
");
$statsStmt->execute([$dealerId, $dealerId, $dealerId]);
$stats = $statsStmt->fetch();

$managers = adminListManagers('dealer', $dealerId);
$teamSize = count(array_filter($roster, fn($r) => $r['verification_status'] === 'verified'));

// ── Render ────────────────────────────────────────────────────
ob_start();
?>
<a class="adm-back" href="/app/admin/dealerships"><i class="fa-solid fa-arrow-left"></i> All dealerships</a>

<div class="section-head">
  <h1 class="section-title"><?= htmlspecialchars($dealer['company_name']) ?></h1>
  <?= adminStatusBadge($dealer['is_active'] ? 'active' : 'inactive') ?>
  <?= adminStatusBadge($dealer['verification_status']) ?>
  <span class="section-count"><?= $dealer['user_id'] ? 'Principal: ' . htmlspecialchars($dealer['principal_email'] ?? '') : 'Admin-managed' ?></span>
  <?php $wsCtx = sdResolveDealerAccess($adminId, 'admin', $dealerId); ?>
  <?php if ($wsCtx && $dealer['is_active']): ?>
  <form method="POST" action="/app/admin/workspace" class="adm-head-action">
    <?= csrf_hidden_field() ?>
    <input type="hidden" name="action" value="enter">
    <input type="hidden" name="dealer_id" value="<?= (int) $dealerId ?>">
    <?php $wsCanWrite = in_array($wsCtx['mode'], ['operator', 'delegate'], true); ?>
    <button class="btn <?= $wsCanWrite ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit">
      <i class="fa-solid <?= $wsCtx['mode'] === 'delegate' ? 'fa-handshake' : ($wsCanWrite ? 'fa-screwdriver-wrench' : 'fa-eye') ?>"></i>
      <?= $wsCtx['mode'] === 'delegate' ? 'Help run (access granted)' : ($wsCanWrite ? 'Run in dealer portal' : 'View in dealer portal') ?>
    </button>
  </form>
  <?php endif; ?>
</div>

<div class="adm-stats">
  <div class="adm-stat"><span class="adm-stat-n"><?= count($pending) ?></span><span class="adm-stat-l">Pending requests</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= $teamSize ?></span><span class="adm-stat-l">Sales execs</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $stats['active_cars'] ?></span><span class="adm-stat-l">Live cars</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $stats['open_leads'] ?></span><span class="adm-stat-l">Open leads</span></div>
</div>

<nav class="adm-tabs" aria-label="Dealership sections">
  <?php $dvTabs = ['team' => 'Team & requests', 'details' => 'Details', 'managers' => 'Managers'];
        if (isSuperadmin($adminId)) { $dvTabs['platform'] = 'Platform controls'; } ?>
  <?php foreach ($dvTabs as $key => $label): ?>
  <a href="<?= $self ?>&amp;tab=<?= $key ?>" class="adm-tab<?= $tab === $key ? ' is-active' : '' ?>"
     <?= $tab === $key ? 'aria-current="page"' : '' ?>>
    <?= $label ?>
    <?php if ($key === 'team' && $pending): ?><span class="adm-tab-count"><?= count($pending) ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'team'): ?>

  <h2 class="adm-h2">Requests to join <span class="section-count"><?= count($pending) ?></span></h2>
  <?php if (!$pending): ?>
    <div class="empty adm-gap"><span class="empty-icon">✓</span>No pending requests.</div>
  <?php else: ?>
    <?php $showDealer = false; include __DIR__ . '/../../views/partials/admin/exec-requests-table.php'; ?>
  <?php endif; ?>

  <h2 class="adm-h2">Team <span class="section-count"><?= count($roster) ?></span></h2>
  <?php if (!$roster): ?>
    <div class="empty"><span class="empty-icon"><i class="fa-solid fa-users"></i></span>No sales execs yet.</div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead><tr><th>Sales exec</th><th>Job title</th><th>Status</th><th>Live cars</th><th>Since</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($roster as $r):
        $rName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: $r['email']; ?>
        <tr>
          <td>
            <div class="adm-strong"><?= htmlspecialchars($rName) ?></div>
            <div class="adm-sub"><?= htmlspecialchars($r['email']) ?><?= $r['phone'] ? ' · ' . htmlspecialchars($r['phone']) : '' ?></div>
          </td>
          <td class="adm-muted"><?= htmlspecialchars($r['job_title'] ?? '—') ?></td>
          <td>
            <?= adminStatusBadge($r['verification_status']) ?>
            <?php if ($r['rejection_reason']): ?><div class="adm-sub"><?= htmlspecialchars($r['rejection_reason']) ?></div><?php endif; ?>
          </td>
          <td class="adm-mono"><?= (int) $r['active_cars'] ?></td>
          <td class="adm-muted"><?= date('d M Y', strtotime($r['verified_at'] ?? $r['created_at'])) ?></td>
          <td><div class="adm-actions">
            <?php if ($r['verification_status'] === 'verified'): ?>
              <button type="button" class="btn btn-warn btn-sm"
                      data-reason-modal="suspend" data-exec-id="<?= (int) $r['id'] ?>"
                      data-exec-name="<?= htmlspecialchars($rName) ?>">Suspend</button>
            <?php else: ?>
              <form method="POST" data-confirm="Reinstate <?= htmlspecialchars($rName) ?>? They'll be emailed.">
                <?= csrf_hidden_field() ?>
                <input type="hidden" name="dealer_id" value="<?= $dealerId ?>">
                <input type="hidden" name="exec_id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="action" value="reinstate">
                <button class="btn btn-info btn-sm" type="submit">Reinstate</button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'details'): ?>

  <div class="card adm-card">
    <form method="POST" class="card-body">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="save_details">
      <input type="hidden" name="dealer_id" value="<?= $dealerId ?>">

      <div class="fgroup">
        <label class="flabel" for="dd_name">Dealership name</label>
        <input class="finput" id="dd_name" name="company_name" required maxlength="120" value="<?= htmlspecialchars($dealer['company_name']) ?>">
      </div>
      <div class="adm-grid-2">
        <div class="fgroup">
          <label class="flabel" for="dd_province">Province</label>
          <select class="finput" id="dd_province" name="province">
            <option value="">Select…</option>
            <?php foreach (SD_PROVINCES as $prov): ?>
            <option value="<?= $prov ?>" <?= ($dealer['province'] ?? '') === $prov ? 'selected' : '' ?>><?= $prov ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="dd_city">City / town</label>
          <input class="finput" id="dd_city" name="city" maxlength="80" value="<?= htmlspecialchars($dealer['city'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="dd_suburb">Suburb</label>
          <input class="finput" id="dd_suburb" name="suburb" maxlength="80" value="<?= htmlspecialchars($dealer['suburb'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="dd_street">Street address</label>
          <input class="finput" id="dd_street" name="street_line1" maxlength="120" value="<?= htmlspecialchars($dealer['street_line1'] ?? '') ?>">
        </div>
      </div>
      <div class="fgroup">
        <label class="flabel" for="dd_brands">Brand focus <span class="flabel-opt">comma-separated</span></label>
        <input class="finput" id="dd_brands" name="brand_focus" value="<?= htmlspecialchars(adminBrandFocusText($dealer['brand_focus'])) ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="dd_logo">Logo URL</label>
        <input class="finput" id="dd_logo" name="logo_url" type="url" value="<?= htmlspecialchars($dealer['logo_url'] ?? '') ?>">
      </div>
      <div class="adm-checks">
        <label><input type="checkbox" name="verification_status" value="verified"
               <?= $dealer['verification_status'] === 'verified' ? 'checked' : '' ?>> Verified</label>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn btn-primary">Save details</button>
      </div>
    </form>
  </div>

  <?php if (!$dealer['user_id']): ?>
  <div class="card adm-card adm-danger">
    <div class="card-body">
      <?php if ($dealer['is_active']): ?>
        <h2 class="adm-h2">Take offline</h2>
        <p class="adm-muted">Hides the dealership from exec signup and pauses every live listing. Reversible.</p>
        <form method="POST" data-confirm="Take <?= htmlspecialchars($dealer['company_name']) ?> offline and pause all its listings?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="dealer_id" value="<?= $dealerId ?>">
          <input type="hidden" name="action" value="deactivate">
          <button class="btn btn-danger btn-sm" type="submit">Take offline</button>
        </form>
      <?php else: ?>
        <h2 class="adm-h2">Bring online</h2>
        <p class="adm-muted">Makes the dealership visible again and restores paused listings.</p>
        <form method="POST">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="dealer_id" value="<?= $dealerId ?>">
          <input type="hidden" name="action" value="activate">
          <button class="btn btn-success btn-sm" type="submit">Bring online</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'platform' && isSuperadmin($adminId)): /* 0017 */ ?>

  <?php include __DIR__ . '/../../views/partials/admin/dealer-platform-controls.php'; ?>

<?php else: /* managers */ ?>

  <?php $managerEntityField = 'dealer_id'; $managerEntityId = $dealerId; $managerHasPrincipal = $dealer['user_id'] !== null; include __DIR__ . '/../../views/partials/admin/managers-panel.php'; ?>

<?php endif; ?>

<?php include __DIR__ . '/../../views/partials/admin/exec-reason-modal.php'; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = $dealer['company_name'] . ' | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
