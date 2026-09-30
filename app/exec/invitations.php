<?php
/**
 * SalesDesk — Sales exec: dealership invitations  (0018, Phase 4)
 * Route: /app/exec/invitations
 *
 * Open invitations from dealerships (sent by SalesDesk admins who run
 * them). Accept → verified at that dealership straight away; Decline →
 * the inviter is told. Deliberately NOT behind requireExecVerified():
 * the people who need this page are the ones with no dealership yet.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireRole('sales_exec');

$userId = (int) $_SESSION['user_id'];
$self   = '/app/exec/invitations.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $invId  = (int) ($_POST['invitation_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'accept') {
        [$ok, $msg] = sdAcceptExecInvitation($invId, $userId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($ok ? '/app/exec/dashboard.php' : $self);
    }
    if ($action === 'decline') {
        [$ok, $msg] = sdDeclineExecInvitation($invId, $userId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    }
    redirect($self);
}

$invites = sdExecInvitations($userId);
$cur     = Database::getInstance()->prepare("
    SELECT se.verification_status, d.company_name FROM sales_executives se
    JOIN dealers d ON d.id = se.dealer_id WHERE se.user_id = ? LIMIT 1
");
$cur->execute([$userId]);
$current = $cur->fetch() ?: null;
$placed  = $current && in_array($current['verification_status'], ['verified', 'suspended'], true);

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Dealership invitations</h1>
  <span class="section-count"><?= count($invites) ?> open</span>
</div>

<?php if ($placed): ?>
<div class="alert alert-info inv-gap">
  <span class="alert-icon">ℹ</span>
  <div>You’re linked to <strong><?= htmlspecialchars($current['company_name']) ?></strong>. To move to another dealership, contact SalesDesk.</div>
</div>
<?php elseif ($current && $current['verification_status'] === 'pending'): ?>
<div class="alert alert-info inv-gap">
  <span class="alert-icon">ℹ</span>
  <div>Your request to join <strong><?= htmlspecialchars($current['company_name']) ?></strong> is still waiting. Accepting an
    invitation below replaces that request.</div>
</div>
<?php endif; ?>

<?php if (!$invites): ?>
<div class="empty inv-gap">
  <span class="empty-icon"><i class="fa-regular fa-envelope-open"></i></span>
  No open invitations. When a dealership on SalesDesk invites you, it’ll show up here and in your email.
  <?php if (!$current || $current['verification_status'] === 'rejected'): ?>
  <div class="inv-gap-top"><a class="btn btn-primary btn-sm" href="/auth/register.php?reapply=1">Apply to a dealership yourself</a></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="inv-list">
  <?php foreach ($invites as $i): ?>
  <div class="card card-body inv-card">
    <div class="inv-card__head">
      <span class="inv-card__icon" aria-hidden="true"><i class="fa-solid fa-building"></i></span>
      <div>
        <h2 class="inv-card__name"><?= htmlspecialchars($i['company_name']) ?></h2>
        <div class="inv-card__meta">
          <?= htmlspecialchars(implode(', ', array_filter([$i['city'], $i['province']])) ?: 'South Africa') ?>
          · open until <?= htmlspecialchars(date('j M Y', strtotime($i['expires_at']))) ?>
        </div>
      </div>
    </div>
    <?php if ($i['message']): ?>
    <p class="inv-card__msg">“<?= htmlspecialchars($i['message']) ?>”</p>
    <?php endif; ?>
    <p class="inv-card__what">Accept and you’re a verified sales exec at <?= htmlspecialchars($i['company_name']) ?> straight away —
      you can list their cars and earn on leads.</p>
    <?php if (!$placed): ?>
    <div class="inv-card__actions">
      <form method="POST">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="accept">
        <input type="hidden" name="invitation_id" value="<?= (int) $i['id'] ?>">
        <button class="btn btn-primary btn-sm" type="submit">Accept</button>
      </form>
      <form method="POST" data-confirm="Decline this invitation?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="decline">
        <input type="hidden" name="invitation_id" value="<?= (int) $i['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Decline</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Invitations';
$pageStyles  = ['/assets/css/exec-invitations.css'];
$pageScripts = ['/assets/js/admin.js'];   // form[data-confirm] handler
require_once '../../views/layout-app.php';
