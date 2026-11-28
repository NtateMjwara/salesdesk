<?php
/**
 * SalesDesk — Broker: Your plan  (PLANS-1, 0019)
 * Route: /app/broker/plan
 *
 * Shows the broker's plan, how much of it they use (desk cars, networks)
 * and the broker plans side by side.
 *
 * Phase 3: with PayFast configured (includes/payfast.config.php), "Upgrade"
 * starts a monthly PayFast subscription (app/billing/pay.php), and online
 * subscribers can cancel or update their card here. Without it, "Request
 * Pro" notifies the superadmins, who set the plan up by hand.
 *
 * Logic: includes/entitlements.php. Styles: assets/css/plans.css.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/org_membership.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/billing.php';   // Phase 3: PayFast
require_once '../../includes/plan_grace.php';   // 3b: downgrade window banner

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$userId = (int) $_SESSION['user_id'];
$self   = '/app/broker/plan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    if ($action === 'request_upgrade') {
        [$ok, $msg] = sdRequestUpgrade($userId, (string) ($_POST['plan_code'] ?? ''));
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    } elseif ($action === 'checkout') {             // Phase 3: pay online
        [$ok, $msg, $ref] = sdBillingStartCheckout('broker', $userId, (string) ($_POST['plan_code'] ?? ''), $userId);
        if ($ok) {
            redirect('/app/billing/pay?ref=' . $ref);
        }
        $_SESSION['flash_error'] = $msg;
    } elseif ($action === 'cancel_billing') {
        [$ok, $msg] = sdBillingCancel('broker', $userId, $userId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    }
    redirect($self);
}

$pdo  = Database::getInstance();
$desk = $pdo->prepare("SELECT id, display_name FROM salesdesks WHERE user_id = ? LIMIT 1");
$desk->execute([$userId]);
$desk = $desk->fetch();

$ready       = sdPlansReady();
$plan        = $ready ? sdAccountPlan('broker', $userId) : null;
$sub         = $plan['subscription'] ?? null;
$rules       = brokerCatalogueRules($userId);
$carLimit    = brokerDeskCarLimit($userId, $rules['car_limit']);
$carsUsed    = $desk ? brokerDeskCarCount((int) $desk['id']) : 0;
$netLimit    = entitlement('broker', $userId, 'max_networks');
$netUsed     = brokerNetworkCount($userId);
$plans       = $ready ? array_filter(sdPlans('broker'), static fn ($p) => $p['is_active'] || ($plan && $p['id'] === $plan['id'])) : [];
$lastRequest = $ready ? sdLastUpgradeRequest($userId) : null;
$requested   = $lastRequest && strtotime($lastRequest) > strtotime('-7 days');
$overrides   = $ready ? sdLimitOverrides('broker', $userId) : [];
$orgCapped   = $rules['car_limit'] !== null && $carLimit === $rules['car_limit']
               && $carLimit !== entitlement('broker', $userId, 'max_desk_cars');

// Phase 3: online billing state
$billingOn   = sdBillingAvailable();
$isOnline    = $sub && ($sub['provider'] ?? '') === 'payfast' && sdSubscriptionGrants($sub);
$cancelling  = $isOnline && (int) ($sub['cancel_at_period_end'] ?? 0);
$pastDue     = $isOnline && $sub['status'] === 'past_due';
$paidThrough = $isOnline ? date('j M Y', strtotime((string) $sub['current_period_end'] . ' -' . SD_BILLING_GRACE_DAYS . ' days')) : '';
$cardUrl     = $isOnline && !$cancelling ? sdPayfastUpdateCardUrl((string) $sub['provider_ref'], SITE_URL . $self) : null;
$checkoutQs  = is_string($_GET['checkout'] ?? null) ? $_GET['checkout'] : '';
$checkoutRef = is_string($_GET['ref'] ?? null) && preg_match('/^[0-9a-f\-]{36}$/', $_GET['ref']) ? $_GET['ref'] : '';

$graceState  = $ready ? sdBrokerDeskState($userId) : null;   // 3b

$pct = static fn (int $used, ?int $limit): int => $limit === null || $limit <= 0 ? 0 : (int) min(100, round($used / $limit * 100));

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Your plan</h1>
  <?php if ($plan): ?><span class="plan-chip"><?= htmlspecialchars($plan['name']) ?></span><?php endif; ?>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warn"><span class="alert-icon">!</span>
  <div>Plans are being switched on. Your desk works as usual in the meantime.</div>
</div>
<?php else: ?>

<?php require __DIR__ . '/../../views/partials/grace-banner.php'; ?>

<?php if ($checkoutQs === 'return' && $isOnline): ?>
<div class="alert alert-success plan-billing-alert">
  <i class="fa-solid fa-circle-check alert-icon" aria-hidden="true"></i>
  <div><strong>You’re on <?= htmlspecialchars($plan['name']) ?>.</strong> Payment received — thank you. It renews monthly until you cancel.</div>
</div>
<?php elseif ($checkoutQs === 'return'): ?>
<div class="alert alert-info plan-billing-alert" data-billing-waiting="<?= htmlspecialchars($checkoutRef) ?>">
  <i class="fa-solid fa-hourglass-half alert-icon" aria-hidden="true"></i>
  <div><strong>Confirming your payment with PayFast…</strong> This usually takes a few seconds.
    <span data-billing-slow hidden>It’s taking longer than usual. You’ll get a notification as soon as PayFast confirms. If you were charged and nothing changes within an hour, contact us.</span></div>
</div>
<?php elseif ($checkoutQs === 'cancelled'): ?>
<div class="alert alert-info plan-billing-alert">
  <i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i>
  <div>Payment cancelled. Nothing was charged.</div>
</div>
<?php endif; ?>

<div class="plan-usage card">
  <div class="card-body">
    <div class="plan-usage__head">
      <div>
        <div class="plan-usage__name"><?= htmlspecialchars($plan['name']) ?> plan</div>
        <div class="plan-usage__meta">
          <?php if ($plan['is_default']): ?>
          Free forever — no card needed.
          <?php elseif ($isOnline && $cancelling): ?>
          Cancelled · stays on until <?= htmlspecialchars($paidThrough) ?>, then back to Free.
          <?php elseif ($isOnline && $pastDue): ?>
          <span class="plan-billing-warn">This month’s payment didn’t go through.</span> Your plan stays on until <?= htmlspecialchars($paidThrough) ?>.
          <?php elseif ($isOnline): ?>
          Paid monthly by card (PayFast) · next payment around <?= htmlspecialchars($paidThrough) ?>.
          <?php else: ?>
          <?= htmlspecialchars(sdSubStatusLabel($sub)) ?>
          <?php if (!empty($sub['current_period_end'])): ?> · until <?= date('j M Y', strtotime($sub['current_period_end'])) ?>, then back to Free<?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="plan-usage__price"><?= htmlspecialchars(sdPlanPriceLabel($plan)) ?></div>
    </div>

    <?php if ($isOnline && !$cancelling): ?>
    <!-- Phase 3: manage the online subscription -->
    <div class="plan-billing-actions">
      <?php if ($cardUrl): ?>
      <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($cardUrl) ?>"><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Update card</a>
      <?php endif; ?>
      <form method="POST" action="<?= $self ?>"
            data-confirm="Cancel your <?= htmlspecialchars($plan['name'], ENT_QUOTES) ?> subscription? You won’t be charged again, and your plan stays on until <?= htmlspecialchars($paidThrough, ENT_QUOTES) ?>.">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="cancel_billing">
        <button class="btn btn-ghost btn-sm" type="submit" data-busy-label="Cancelling…">Cancel subscription</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="plan-meters">
      <div class="plan-meter">
        <div class="plan-meter__label">
          <span>Cars on your desk</span>
          <strong><?= $carsUsed ?> / <?= sdLimitText($carLimit) ?></strong>
        </div>
        <progress class="plan-meter__bar<?= $carLimit === null ? ' is-unlimited' : ($carsUsed >= $carLimit ? ' is-full' : '') ?>"
                  max="100" value="<?= $carLimit === null ? 100 : $pct($carsUsed, $carLimit) ?>"
                  aria-label="Cars on your desk: <?= $carsUsed ?> of <?= sdLimitText($carLimit) ?>"></progress>
        <?php if ($orgCapped): ?>
        <p class="plan-meter__note">Capped at <?= (int) $carLimit ?> by <?= htmlspecialchars((string) ($rules['org']['org_name'] ?? 'your organisation')) ?>.</p>
        <?php elseif (isset($overrides['max_desk_cars'])): ?>
        <p class="plan-meter__note">A custom limit set by SalesDesk.</p>
        <?php endif; ?>
      </div>

      <div class="plan-meter">
        <div class="plan-meter__label">
          <span>Business networks</span>
          <strong><?= $netUsed ?> / <?= sdLimitText($netLimit, 'max_networks') ?></strong>
        </div>
        <progress class="plan-meter__bar<?= $netLimit === null ? ' is-unlimited' : '' ?>"
                  max="100" value="<?= $netLimit === null ? 100 : $pct($netUsed, $netLimit) ?>"
                  aria-label="Business networks: <?= $netUsed ?> of <?= sdLimitText($netLimit, 'max_networks') ?>"></progress>
        <p class="plan-meter__note">Applications waiting for review count too. <a href="/app/broker/networks">My networks</a></p>
      </div>
    </div>
  </div>
</div>

<h2 class="plan-compare__title">Compare plans</h2>
<div class="plan-compare">
  <?php foreach ($plans as $p):
    $isCurrent = $plan && $p['id'] === $plan['id'];
    $isUp      = !$p['is_default'] && $p['price_cents'] > (int) $plan['price_cents']; ?>
  <div class="plan-option<?= $isCurrent ? ' is-current' : '' ?><?= !$p['is_default'] ? ' is-paid' : '' ?>">
    <div class="plan-option__head">
      <span class="plan-option__name"><?= htmlspecialchars($p['name']) ?></span>
      <?php if ($isCurrent): ?><span class="plan-option__tag">Your plan</span><?php endif; ?>
    </div>
    <div class="plan-option__price">
      <?php if ($p['price_cents'] > 0): ?>
      <strong><?= htmlspecialchars(sdPriceText($p['price_cents'])) ?></strong><span>/month</span>
      <?php else: ?>
      <strong>R0</strong><span>/month</span>
      <?php endif; ?>
    </div>
    <?php if ($p['tagline']): ?><p class="plan-option__tagline"><?= htmlspecialchars($p['tagline']) ?></p><?php endif; ?>
    <ul class="plan-option__list">
      <?php $pc = sdPlanLimit($p, 'max_desk_cars'); $pn = sdPlanLimit($p, 'max_networks'); ?>
      <li><i class="fa-solid fa-car-side" aria-hidden="true"></i>
        <?= $pc === null ? 'Unlimited cars on your desk' : sdLimitText($pc) . ' cars on your desk' ?></li>
      <li><i class="fa-solid fa-building" aria-hidden="true"></i>
        <?= $pn === null ? 'Join unlimited business networks' : 'Join up to ' . sdLimitText($pn, 'max_networks') . ' business networks' ?></li>
      <li><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i> Earn commission on every sale</li>
    </ul>

    <?php if ($isCurrent): ?>
    <button class="btn btn-ghost btn-sm plan-option__cta" type="button" disabled>Current plan</button>
    <?php elseif ($isUp && $billingOn): /* Phase 3: pay online */ ?>
    <?php if ($cancelling): ?>
    <button class="btn btn-ghost btn-sm plan-option__cta" type="button" disabled>Available after <?= htmlspecialchars($paidThrough) ?></button>
    <?php else: ?>
    <form method="POST" action="<?= $self ?>">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="checkout">
      <input type="hidden" name="plan_code" value="<?= htmlspecialchars($p['code']) ?>">
      <button class="btn btn-primary btn-sm plan-option__cta" type="submit" data-busy-label="Opening PayFast…">
        Upgrade to <?= htmlspecialchars($p['name']) ?>
      </button>
    </form>
    <p class="plan-option__fine">Pay monthly by card through PayFast. Cancel any time.</p>
    <?php endif; ?>
    <?php elseif ($isUp && $requested): ?>
    <button class="btn btn-ghost btn-sm plan-option__cta" type="button" disabled>
      <i class="fa-solid fa-check" aria-hidden="true"></i> Requested <?= date('j M', strtotime($lastRequest)) ?>
    </button>
    <p class="plan-option__fine">We’ll contact you to set it up.</p>
    <?php elseif ($isUp): ?>
    <form method="POST" action="<?= $self ?>">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="request_upgrade">
      <input type="hidden" name="plan_code" value="<?= htmlspecialchars($p['code']) ?>">
      <button class="btn btn-primary btn-sm plan-option__cta" type="submit" data-busy-label="Sending…">
        Request <?= htmlspecialchars($p['name']) ?>
      </button>
    </form>
    <p class="plan-option__fine">Online payment is coming soon — for now we’ll contact you to set it up.</p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<p class="plan-compare__fine">Prices exclude VAT. Commission and the platform fee work the same on every plan.</p>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Your plan' . ($desk ? ' | ' . $desk['display_name'] : '');
$pageStyles  = ['/assets/css/plans.css'];
$pageScripts = ['/assets/js/admin.js', '/assets/js/billing-return.js'];   // data-confirm + waiting-for-PayFast refresh
require_once '../../views/layout-app.php';
