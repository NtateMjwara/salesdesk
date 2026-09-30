<?php
/**
 * SalesDesk — Admin: Dealer workspaces  (0015)
 * Route: /app/admin/workspace
 *
 *   GET            list the dealerships this admin can open, with the
 *                  access they'd get (Operate / View)
 *   POST enter     open one: stores the dealership in the session and
 *                  sends the admin to the normal dealer portal
 *   POST exit      leave the workspace, back to the admin area
 *
 * Access is re-checked on every dealer-portal request by
 * requireDealerWorkspace() (includes/dealer_context.php) — this page
 * only chooses which dealership, it never grants anything.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';   // loads superadmin.php + dealer_context.php

applyCachePolicy('auth');
requireRole('admin');

$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'enter') {
        $dealerId = (int) ($_POST['dealer_id'] ?? 0);
        $ctx      = sdResolveDealerAccess($adminId, 'admin', $dealerId);
        if (!$ctx) {
            $_SESSION['flash_error'] = 'You don’t manage that dealership.';
            redirect('/app/admin/workspace');
        }
        if (!$ctx['is_active']) {
            $_SESSION['flash_error'] = $ctx['company_name'] . ' is offline. Bring it back online from its admin page first.';
            redirect('/app/admin/dealerships-view?id=' . $dealerId);
        }
        $_SESSION[SD_WORKSPACE_SESSION_KEY] = $dealerId;
        unset($_SESSION['car_wz']);   // never carry a half-built car into another dealership
        writeAuditLog('workspace.entered', 'dealer', $dealerId, null, ['mode' => $ctx['mode']], $adminId);
        $_SESSION['flash_ok'] = match ($ctx['mode']) {
            'operator' => 'You’re now running ' . $ctx['company_name'] . '.',
            'delegate' => 'The principal has let SalesDesk help run ' . $ctx['company_name'] . ' — they see every change.',
            default    => 'You’re viewing ' . $ctx['company_name'] . ' (read-only).',
        };
        redirect('/app/dealer/dashboard.php');
    }

    if ($action === 'exit') {
        $was = (int) ($_SESSION[SD_WORKSPACE_SESSION_KEY] ?? 0);
        unset($_SESSION[SD_WORKSPACE_SESSION_KEY], $_SESSION['car_wz']);
        if ($was) {
            writeAuditLog('workspace.exited', 'dealer', $was, null, null, $adminId);
        }
        redirect($was ? '/app/admin/dealerships-view?id=' . $was : '/app/admin/dealerships');
    }

    redirect('/app/admin/workspace');
}

$choices = sdWorkspaceChoices($adminId);
$current = (int) ($_SESSION[SD_WORKSPACE_SESSION_KEY] ?? 0);

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Dealer workspaces</h1>
  <span class="section-count"><?= count($choices) ?> dealership<?= count($choices) === 1 ? '' : 's' ?></span>
</div>

<p class="adm-muted adm-gap">
  Open a dealership to work in its dealer portal — cars, imports, leads, team and settings.
  <strong>Operate</strong> lets you make changes; <strong>View</strong> is read-only. Dealerships with their own
  principal are always view-only for SalesDesk staff. Everything you do is recorded against your name.
</p>

<?php if (!$choices): ?>
<div class="empty"><span class="empty-icon"><i class="fa-solid fa-building"></i></span>You don’t manage any dealerships yet.</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead><tr><th>Dealership</th><th>Your access</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($choices as $c): ?>
      <tr>
        <td>
          <a class="adm-strong" href="/app/admin/dealerships-view?id=<?= $c['id'] ?>"><?= htmlspecialchars($c['company_name']) ?></a>
          <div class="adm-sub"><?= $c['has_principal'] ? 'Has its own principal' : 'Run by SalesDesk' ?></div>
        </td>
        <td><?= sdWorkspaceAccessBadge($c['mode']) ?></td>
        <td>
          <form method="POST" class="adm-inline">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="enter">
            <input type="hidden" name="dealer_id" value="<?= $c['id'] ?>">
            <button class="btn <?= in_array($c['mode'], ['operator', 'delegate'], true) ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit">
              <?= $current === $c['id'] ? 'Continue' : 'Open' ?> <i class="fa-solid fa-arrow-right"></i>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Dealer workspaces | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
