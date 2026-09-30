<?php
/**
 * SalesDesk — My desk organisation (broker)  (0013)
 * Route: /app/broker/desk-org
 *
 * Replaces the old broker-run org pages (create-org, org-prompt,
 * org-settings), which now redirect here. Desk organisations are
 * admin-run online dealerships; brokers join them as agents.
 *
 * States (see includes/org_membership.php):
 *   independent / rejected — list open orgs, Apply
 *   pending                — waiting for approval, Withdraw
 *   verified               — agent: org details, team link, Leave
 *   suspended              — explanation, Leave
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/org_membership.php';

applyCachePolicy('auth');
requireRole('broker');

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'apply') {
        $user = getUserById($userId);
        [$ok, $msg] = applyBrokerToOrg($userId, (int) ($_POST['org_id'] ?? 0), $user ? $user['email'] : '');
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    } elseif ($action === 'leave') {
        [$ok, $msg] = brokerLeaveOrg($userId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        unset($_SESSION['org_context']);
    }
    redirect('/app/broker/desk-org');
}

$membership = getBrokerMembership($userId);
$status     = $membership['status'] ?? 'independent';
$showList   = in_array($status, ['independent', 'rejected'], true);
$openOrgs   = $showList ? listOpenDeskOrgs() : [];

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Desk organisation</h1>
</div>

<?php if ($status === 'pending'): ?>
  <div class="dorg-status dorg-status-pending">
    <div class="dorg-status-icon"><i class="fa-solid fa-clock"></i></div>
    <div class="dorg-status-body">
      <h2>Waiting for approval</h2>
      <p>You've applied to join <strong><?= htmlspecialchars($membership['org_name']) ?></strong> as an agent.
         You'll get an email once an admin reviews it. Until then you can browse its cars but not add them.</p>
      <form method="POST" data-confirm="Withdraw your application? You'll go back to being an independent broker.">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="leave">
        <button class="btn btn-ghost btn-sm" type="submit">Withdraw application</button>
      </form>
    </div>
  </div>

<?php elseif ($status === 'verified' || $status === 'suspended'): ?>
  <div class="dorg-status <?= $status === 'suspended' ? 'dorg-status-suspended' : 'dorg-status-ok' ?>">
    <div class="dorg-status-icon">
      <i class="fa-solid <?= $status === 'suspended' ? 'fa-pause' : 'fa-circle-check' ?>"></i>
    </div>
    <div class="dorg-status-body">
      <h2><?= $status === 'suspended' ? 'Agent access suspended' : 'You\'re an agent at ' . htmlspecialchars($membership['org_name']) ?></h2>
      <?php if ($status === 'suspended'): ?>
        <p>Your access at <strong><?= htmlspecialchars($membership['org_name']) ?></strong> is suspended<?= $membership['rejection_reason'] ? ': ' . htmlspecialchars($membership['rejection_reason']) : '' ?>.
           You can't add new cars until an admin reinstates you.</p>
      <?php else: ?>
        <p>
          <?= $membership['brand_list']
              ? 'You share <strong>' . htmlspecialchars(implode(', ', $membership['brand_list'])) . '</strong> cars from the marketplace.'
              : 'You can share any car on the marketplace.' ?>
          Commission on your leads is paid to you in full.
        </p>
        <?php if ($membership['description']): ?>
          <p class="dorg-desc"><?= nl2br(htmlspecialchars($membership['description'])) ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <div class="dorg-actions">
        <?php if ($status === 'verified'): ?>
          <a class="btn btn-primary btn-sm" href="/app/broker/inventory">Browse cars</a>
          <a class="btn btn-ghost btn-sm" href="/app/broker/dashboard?switch_context=<?= (int) $membership['org_id'] ?>">Team dashboard</a>
        <?php endif; ?>
        <form method="POST" data-confirm="Leave <?= htmlspecialchars($membership['org_name']) ?>? Cars already on your desk stay there.">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="leave">
          <button class="btn btn-ghost btn-sm" type="submit">Leave organisation</button>
        </form>
      </div>
    </div>
  </div>

<?php else: /* independent or rejected */ ?>

  <?php if ($status === 'rejected'): ?>
  <div class="alert alert-error dorg-gap">
    <span class="alert-icon"><i class="fa-solid fa-circle-xmark"></i></span>
    <div>Your application to <strong><?= htmlspecialchars($membership['org_name']) ?></strong> was declined<?= $membership['rejection_reason'] ? ': ' . htmlspecialchars($membership['rejection_reason']) : '' ?>.
         You're working as an independent broker.</div>
  </div>
  <?php endif; ?>

  <p class="dorg-intro">
    You're an <strong>independent broker</strong>: you can share any car on the marketplace.
    Or join a desk organisation — an online dealership for one to three brands. You'll be one of its agents,
    carry its name on your desk, and still earn your full commission.
  </p>

  <?php if (!$openOrgs): ?>
    <div class="empty"><span class="empty-icon"><i class="fa-solid fa-people-group"></i></span>No organisations are taking applications right now.</div>
  <?php else: ?>
  <div class="dorg-grid">
    <?php foreach ($openOrgs as $o): ?>
    <div class="dorg-card">
      <div class="dorg-card-head">
        <?php if ($o['logo_url']): ?>
          <img class="dorg-logo" src="<?= htmlspecialchars($o['logo_url']) ?>" alt="">
        <?php else: ?>
          <div class="dorg-logo dorg-logo-fallback" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($o['name'], 0, 1))) ?></div>
        <?php endif; ?>
        <div>
          <div class="dorg-card-name"><?= htmlspecialchars($o['name']) ?></div>
          <div class="dorg-card-meta">
            <?= (int) $o['agent_count'] ?> agent<?= (int) $o['agent_count'] === 1 ? '' : 's' ?>
            <?= $o['city'] ? ' · ' . htmlspecialchars($o['city']) : '' ?>
          </div>
        </div>
      </div>
      <div class="dorg-brands">
        <?php foreach ($o['brand_list'] ?: ['All brands'] as $b): ?>
          <span class="dorg-brand"><?= htmlspecialchars($b) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($o['description']): ?>
        <p class="dorg-card-desc"><?= htmlspecialchars(mb_strimwidth($o['description'], 0, 160, '…')) ?></p>
      <?php endif; ?>
      <form method="POST" data-confirm="Apply to join <?= htmlspecialchars($o['name']) ?> as an agent?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
        <button class="btn btn-primary btn-sm btn-full" type="submit">Apply to join</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Desk organisation';
$pageStyles  = ['/assets/css/desk-org.css'];
$pageScripts = ['/assets/js/admin.js'];   // data-confirm handler (shared)
require_once '../../views/layout-app.php';
