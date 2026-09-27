<?php
/**
 * SalesDesk — Admin: Approvals  (0012, agents added in 0013)
 * Route: /app/admin/approvals[?tab=execs|agents]
 *
 * One place for everything waiting on this admin:
 *   execs  — sales execs asking to join a dealership the admin manages
 *   agents — brokers applying to a desk organisation the admin manages
 *
 * Both lists are scoped (dealer_managers / organization_managers), and the
 * review helpers re-check scope, so a tampered id can't reach anything else.
 * Replaces /app/admin/exec-approvals (which now redirects here).
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';

applyCachePolicy('auth');
requireRole('admin');
adminRequireSchema('Approvals');   // clear 'run migration' screen if the DB is behind

$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $kind   = ($_POST['kind'] ?? 'exec') === 'agent' ? 'agent' : 'exec';
    $reason = (string) ($_POST['reason'] ?? '');

    if (in_array($action, ['approve', 'reject'], true)) {
        [$ok, $msg] = $kind === 'agent'
            ? adminReviewAgent($adminId, (int) ($_POST['member_id'] ?? $_POST['target_id'] ?? 0), $action, $reason)
            : adminReviewSalesExec($adminId, (int) ($_POST['exec_id'] ?? $_POST['target_id'] ?? 0), $action, $reason);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    }
    redirect('/app/admin/approvals?tab=' . ($kind === 'agent' ? 'agents' : 'execs'));
}

$pending      = adminPendingExecApplications($adminId);
$agentPending = adminPendingAgentApplications($adminId);

$tab = $_GET['tab'] ?? '';
if (!in_array($tab, ['execs', 'agents'], true)) {
    $tab = (!$pending && $agentPending) ? 'agents' : 'execs';
}

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Approvals</h1>
  <span class="section-count"><?= count($pending) + count($agentPending) ?> waiting</span>
</div>

<nav class="adm-tabs" aria-label="Approval queues">
  <a href="?tab=execs" class="adm-tab<?= $tab === 'execs' ? ' is-active' : '' ?>" <?= $tab === 'execs' ? 'aria-current="page"' : '' ?>>
    Sales execs <?php if ($pending): ?><span class="adm-tab-count"><?= count($pending) ?></span><?php endif; ?>
  </a>
  <a href="?tab=agents" class="adm-tab<?= $tab === 'agents' ? ' is-active' : '' ?>" <?= $tab === 'agents' ? 'aria-current="page"' : '' ?>>
    Desk org agents <?php if ($agentPending): ?><span class="adm-tab-count"><?= count($agentPending) ?></span><?php endif; ?>
  </a>
</nav>

<?php if ($tab === 'execs'): ?>
  <p class="adm-muted adm-gap">
    Sales executives asking to join a dealership you manage. Approving lets them upload cars and receive leads for it.
  </p>
  <?php if (!$pending): ?>
    <div class="empty"><span class="empty-icon">✓</span>No sales exec requests waiting.</div>
  <?php else: ?>
    <?php $showDealer = true; include __DIR__ . '/../../views/partials/admin/exec-requests-table.php'; ?>
  <?php endif; ?>
<?php else: ?>
  <p class="adm-muted adm-gap">
    Brokers applying to a desk organisation you manage. Approved agents can add the organisation's brands to their desk
    and carry its name. Their commission is unchanged.
  </p>
  <?php if (!$agentPending): ?>
    <div class="empty"><span class="empty-icon">✓</span>No agent applications waiting.</div>
  <?php else: ?>
    <?php $showOrg = true; include __DIR__ . '/../../views/partials/admin/agent-requests-table.php'; ?>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../views/partials/admin/exec-reason-modal.php'; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Approvals | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
