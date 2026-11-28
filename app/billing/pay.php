<?php
/**
 * SalesDesk — Hand-off to PayFast  (plans Phase 3)
 * Route: /app/billing/pay?ref={m_payment_id}
 *
 * Shows what's about to be charged and posts the signed form to PayFast
 * (assets/js/billing-pay.js submits it automatically; the button is there
 * for no-JS and as a fallback). The checkout must belong to the signed-in
 * user, be unused, and be under SD_BILLING_CHECKOUT_HOURS old.
 *
 * 0026: businesses pay here too — the business owner / principal started
 * the checkout from /app/dealer/plan.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/billing.php';

applyCachePolicy('auth');
requireLogin();
if (!in_array($_SESSION['user_role'] ?? '', ['broker', 'dealer'], true)) {   // 0026: brokers and business owners
    http_response_code(403);
    exit('Access denied.');
}

$userId = (int) $_SESSION['user_id'];
$ref    = is_string($_GET['ref'] ?? null) ? $_GET['ref'] : '';
$form   = sdBillingCheckoutForm($ref, $userId);
if (!$form) {
    $_SESSION['flash_error'] = 'That payment link has expired. Start again from your Plan page.';
    redirect(($_SESSION['user_role'] ?? '') === 'dealer' ? '/app/dealer/plan' : '/app/broker/plan');
}

ob_start();
?>
<div class="pay-handoff card">
  <div class="card-body">
    <div class="pay-handoff__icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></div>
    <h1 class="pay-handoff__title">Taking you to PayFast…</h1>
    <p class="pay-handoff__text">
      <strong><?= htmlspecialchars($form['plan']) ?></strong> — <?= htmlspecialchars(sdPriceText($form['amount'])) ?> today,
      then <?= htmlspecialchars(sdPriceText($form['amount'])) ?> every month until you cancel. Prices exclude VAT.
    </p>
    <p class="pay-handoff__fine">You’ll pay on PayFast’s secure page. SalesDesk never sees your card details.
      <?php if (sdPayfastSandbox()): ?><br><strong>Test mode (PayFast sandbox):</strong> no real money moves.<?php endif; ?></p>
    <form method="POST" action="<?= htmlspecialchars($form['action']) ?>" data-pay-handoff>
      <?php foreach ($form['fields'] as $name => $value): ?>
      <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Continue to PayFast</button>
    </form>
    <a class="pay-handoff__back" href="<?= htmlspecialchars(sdBillingPlanPage($form['type'])) ?>">Back to your plan</a>
  </div>
</div>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Pay with PayFast';
$pageStyles  = ['/assets/css/plans.css'];
$pageScripts = ['/assets/js/billing-pay.js'];
require_once '../../views/layout-app.php';
