<?php
/**
 * SalesDesk — Dealer portal: Your business plan  (hybrid business billing, 0026)
 * Route: /app/dealer/plan
 *
 * The business's plan, what it uses (dealerships, network brokers,
 * invitations, VIP perks) and the business plans side by side.
 *
 *   • Business Pro is paid online: "Upgrade to Pro" starts a PayFast
 *     monthly subscription (app/billing/pay.php); Cancel / Update card here.
 *   • Enterprise is invoiced by SalesDesk: "Talk to us about Enterprise"
 *     sends a request to the superadmins; invoices are listed here with a
 *     printable view (app/billing/invoice.php).
 *   • Without PayFast configured, Pro is requested the same way.
 *
 * Everyone in the dealer workspace can see it; only the principal of a
 * single-dealership business or the business owner can pay, cancel or ask
 * (sdCanManageBilling).
 *
 * Logic: includes/billing.php, includes/invoicing.php, includes/entitlements.php.
 * Styles: assets/css/plans.css. Scripts: admin.js (confirm), billing-return.js.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/dealer_context.php';
require_once '../../includes/org_membership.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/billing.php';
require_once '../../includes/invoicing.php';

applyCachePolicy('auth');
$ws = requireDealerWorkspace();

$actorId  = (int) $_SESSION['user_id'];
$dealerId = (int) $ws['dealer_id'];
$self     = '/app/dealer/plan';
$orgId    = sdNetworksReady() ? (int) sdEnsureDealerOrganization($dealerId, $actorId) : 0;
$canBill  = $orgId && sdCanManageBilling($ws, $orgId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = (string) ($_POST['action'] ?? '');
    if (!$canBill) {
        $_SESSION['flash_error'] = 'Only the business owner can change the plan. Nothing was changed.';
        redirect($self);
    }
    if ($action === 'checkout') {
        [$ok, $msg, $ref] = sdBillingStartCheckout('organization', $orgId, (string) ($_POST['plan_code'] ?? ''), $actorId);
        if ($ok) {
            redirect('/app/billing/pay?ref=' . $ref);
        }
        $_SESSION['flash_error'] = $msg;
    } elseif ($action === 'cancel_billing') {
        [$ok, $msg] = sdBillingCancel('organization', $orgId, $actorId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    } elseif ($action === 'request_upgrade') {
        [$ok, $msg] = sdRequestBusinessUpgrade($orgId, (string) ($_POST['plan_code'] ?? ''), $actorId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    }
    redirect($self);
}

$ready   = sdPlansReady() && $orgId;
$plan    = $ready ? sdAccountPlan('organization', $orgId) : null;
$sub     = $plan['subscription'] ?? null;
$plans   = $ready ? array_values(array_filter(sdPlans('organization'), static fn ($p) => $p['is_active'] || ($plan && $p['id'] === $plan['id']))) : [];
$sum     = $orgId ? sdNetworkSummary($orgId) : null;
$dealers = $orgId ? sdOrgDealerCount($orgId) : 1;
$dLimit  = $orgId ? entitlement('organization', $orgId, 'max_dealerships') : 1;
$vip     = $orgId && canDo('organization', $orgId, 'vip_perks');
$orgName = $orgId ? (string) Database::getInstance()->query('SELECT name FROM organizations WHERE id = ' . $orgId)->fetchColumn() : $ws['company_name'];

$billingOn   = sdBillingAvailable();
$isOnline    = $sub && ($sub['provider'] ?? '') === 'payfast' && sdSubscriptionGrants($sub);
$isInvoiced  = $sub && ($sub['provider'] ?? '') === 'invoice' && sdSubscriptionGrants($sub);
$cancelling  = $isOnline && (int) ($sub['cancel_at_period_end'] ?? 0);
$pastDue     = $sub && $sub['status'] === 'past_due' && ($isOnline || $isInvoiced);
$paidThrough = $isOnline ? date('j M Y', strtotime((string) $sub['current_period_end'] . ' -' . SD_BILLING_GRACE_DAYS . ' days')) : '';
$cardUrl     = $isOnline && !$cancelling ? sdPayfastUpdateCardUrl((string) $sub['provider_ref'], SITE_URL . $self) : null;
$invoices    = $orgId ? sdOrgInvoices($orgId) : [];
$openInv     = array_values(array_filter($invoices, static fn ($i) => $i['status'] === 'open'));
$lastReq     = $orgId ? sdLastBusinessUpgradeRequest($orgId) : null;
$requested   = $lastReq && strtotime($lastReq) > strtotime('-7 days');
$checkoutQs  = is_string($_GET['checkout'] ?? null) ? $_GET['checkout'] : '';
$checkoutRef = is_string($_GET['ref'] ?? null) && preg_match('/^[0-9a-f\-]{36}$/', $_GET['ref']) ? $_GET['ref'] : '';
$money       = static fn (int $c): string => 'R ' . number_format($c / 100, 2, '.', ',');
$pct         = static fn (int $used, ?int $limit): int => $limit === null || $limit <= 0 ? 0 : (int) min(100, round($used / $limit * 100));

$pageTitle = 'Business plan';
ob_start();
?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__title">Your <em>plan</em></h1>
    <p class="dash-header__sub-plain"><?= htmlspecialchars($orgName) ?>’s SalesDesk business plan. Prices exclude VAT.</p>
  </div>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warn"><span class="alert-icon">!</span><div>Business plans are being switched on. Everything works as usual in the meantime.</div></div>
<?php else: ?>

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
  <i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i><div>Payment cancelled. Nothing was charged.</div>
</div>
<?php endif; ?>

<?php if (!$canBill): ?>
<div class="alert alert-info plan-billing-alert">
  <i class="fa-solid fa-building alert-icon" aria-hidden="true"></i>
  <div>Only the business owner can change the plan or pay for it. You can see the plan and invoices here.</div>
</div>
<?php endif; ?>

<?php if ($openInv): $oi = $openInv[0]; $late = sdInvoiceOverdue($oi); ?>
<div class="alert <?= $late ? 'alert-error' : 'alert-warn' ?> plan-billing-alert">
  <i class="fa-solid fa-file-invoice alert-icon" aria-hidden="true"></i>
  <div><strong>Invoice <?= htmlspecialchars($oi['invoice_number']) ?> — <?= htmlspecialchars($money((int) $oi['amount_cents'])) ?></strong>
    <?= $late ? 'was due' : 'is due' ?> <?= htmlspecialchars(date('j M Y', strtotime((string) $oi['due_at']))) ?>.
    Pay by EFT using the invoice number as the reference.
    <?php if ($late): ?>Your plan moves to Free on <?= htmlspecialchars(date('j M Y', strtotime($oi['due_at'] . ' +' . SD_INVOICE_GRACE_DAYS . ' days'))) ?> unless it’s paid.<?php endif; ?>
    <a href="/app/billing/invoice?id=<?= (int) $oi['id'] ?>">View invoice</a></div>
</div>
<?php endif; ?>

<div class="plan-usage card">
  <div class="card-body">
    <div class="plan-usage__head">
      <div>
        <div class="plan-usage__name"><?= htmlspecialchars($plan['name']) ?> plan</div>
        <div class="plan-usage__meta">
          <?php if ($plan['is_default']): ?>
          Free — no card needed.
          <?php elseif ($isOnline && $cancelling): ?>
          Cancelled · stays on until <?= htmlspecialchars($paidThrough) ?>, then back to Free.
          <?php elseif ($isOnline && $pastDue): ?>
          <span class="plan-billing-warn">This month’s payment didn’t go through.</span> Your plan stays on until <?= htmlspecialchars($paidThrough) ?>.
          <?php elseif ($isOnline): ?>
          Paid monthly by card (PayFast) · next payment around <?= htmlspecialchars($paidThrough) ?>.
          <?php elseif ($isInvoiced && (int) $sub['cancel_at_period_end']): ?>
          Invoicing stopped · stays on until <?= date('j M Y', strtotime((string) $sub['current_period_end'])) ?>, then Free.
          <?php elseif ($isInvoiced): ?>
          Invoiced monthly by SalesDesk · pay by EFT.<?= $pastDue ? ' <span class="plan-billing-warn">An invoice is overdue.</span>' : '' ?>
          <?php else: ?>
          <?= htmlspecialchars(sdSubStatusLabel($sub)) ?> (arranged with SalesDesk)
          <?php if (!empty($sub['current_period_end'])): ?> · until <?= date('j M Y', strtotime((string) $sub['current_period_end'])) ?><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="plan-usage__price"><?= htmlspecialchars(sdPlanPriceLabel($plan)) ?></div>
    </div>

    <?php if ($isOnline && !$cancelling && $canBill): ?>
    <div class="plan-billing-actions">
      <?php if ($cardUrl): ?>
      <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($cardUrl) ?>"><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Update card</a>
      <?php endif; ?>
      <form method="POST" action="<?= $self ?>"
            data-confirm="Cancel your <?= htmlspecialchars($plan['name'], ENT_QUOTES) ?> subscription? You won’t be charged again, and your plan stays on until <?= htmlspecialchars($paidThrough, ENT_QUOTES) ?>.">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="cancel_billing">
        <button class="btn btn-ghost btn-sm" type="submit">Cancel subscription</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="plan-meters">
      <div class="plan-meter">
        <div class="plan-meter__label"><span>Dealerships</span><strong><?= $dealers ?> / <?= sdLimitText($dLimit, 'max_dealerships') ?></strong></div>
        <progress class="plan-meter__bar<?= $dLimit === null ? ' is-unlimited' : ($dealers >= $dLimit ? ' is-full' : '') ?>" max="100"
                  value="<?= $dLimit === null ? 100 : $pct($dealers, $dLimit) ?>" aria-label="Dealerships: <?= $dealers ?> of <?= sdLimitText($dLimit, 'max_dealerships') ?>"></progress>
      </div>
      <?php if ($sum): ?>
      <div class="plan-meter">
        <div class="plan-meter__label"><span>Brokers in your network</span><strong><?= (int) $sum['verified'] ?> / <?= sdLimitText($sum['limit'], 'max_network_brokers') ?></strong></div>
        <progress class="plan-meter__bar<?= $sum['limit'] === null ? ' is-unlimited' : '' ?>" max="100"
                  value="<?= $sum['limit'] === null ? 100 : $pct((int) $sum['verified'], $sum['limit']) ?>" aria-label="Brokers in your network"></progress>
        <p class="plan-meter__note"><a href="/app/dealer/network">Your network</a></p>
      </div>
      <?php endif; ?>
    </div>
    <ul class="plan-flags">
      <li class="<?= ($sum['can_invite'] ?? false) ? 'is-on' : '' ?>"><i class="fa-solid <?= ($sum['can_invite'] ?? false) ? 'fa-check' : 'fa-xmark' ?>" aria-hidden="true"></i> Invite brokers</li>
      <li class="<?= $vip ? 'is-on' : '' ?>"><i class="fa-solid <?= $vip ? 'fa-check' : 'fa-xmark' ?>" aria-hidden="true"></i> VIP perks — network bonus, network-only stock, dashboard, announcements, badge</li>
    </ul>
  </div>
</div>

<?php if ($invoices): ?>
<h2 class="plan-compare__title" id="invoices">Invoices</h2>
<div class="plan-invoices card">
  <table class="plan-inv-table">
    <thead><tr><th scope="col">Invoice</th><th scope="col">Period</th><th scope="col" class="num">Amount</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($invoices as $iv): $st = sdInvoiceStatusLabel($iv); ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($iv['invoice_number']) ?></td>
        <td><?= htmlspecialchars(date('j M', strtotime((string) $iv['period_start']))) ?> – <?= htmlspecialchars(date('j M Y', strtotime($iv['period_end'] . ' -1 day'))) ?></td>
        <td class="num"><?= htmlspecialchars($money((int) $iv['amount_cents'])) ?></td>
        <td><?= htmlspecialchars(date('j M Y', strtotime((string) $iv['due_at']))) ?></td>
        <td><span class="inv-pill inv-pill--<?= strtolower($st) ?>"><?= htmlspecialchars($st) ?></span></td>
        <td><a href="/app/billing/invoice?id=<?= (int) $iv['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h2 class="plan-compare__title">Compare plans</h2>
<div class="plan-compare">
  <?php foreach ($plans as $p):
    $isCurrent = $plan && $p['id'] === $plan['id'];
    $isUp      = !$p['is_default'] && (int) $p['price_cents'] > (int) $plan['price_cents'];
    $invoiced  = sdPlanIsInvoiced($p);
    $pd = sdPlanLimit($p, 'max_dealerships'); $pb = sdPlanLimit($p, 'max_network_brokers');
    $pi = sdPlanLimit($p, 'can_invite_brokers'); $pv = sdPlanLimit($p, 'vip_perks'); ?>
  <div class="plan-option<?= $isCurrent ? ' is-current' : '' ?><?= !$p['is_default'] ? ' is-paid' : '' ?>">
    <div class="plan-option__head">
      <span class="plan-option__name"><?= htmlspecialchars($p['name']) ?></span>
      <?php if ($isCurrent): ?><span class="plan-option__tag">Your plan</span><?php endif; ?>
    </div>
    <div class="plan-option__price">
      <strong><?= htmlspecialchars((int) $p['price_cents'] > 0 ? sdPriceText((int) $p['price_cents']) : 'R0') ?></strong><span>/month</span>
    </div>
    <?php if ($p['tagline']): ?><p class="plan-option__tagline"><?= htmlspecialchars($p['tagline']) ?></p><?php endif; ?>
    <ul class="plan-option__list">
      <li><i class="fa-solid fa-store" aria-hidden="true"></i> <?= $pd === null ? 'Several dealerships' : ($pd === 1 ? '1 dealership' : $pd . ' dealerships') ?></li>
      <li><i class="fa-solid fa-people-group" aria-hidden="true"></i> <?= $pb === null ? 'Unlimited network brokers' : 'Up to ' . $pb . ' network brokers' ?></li>
      <li><i class="fa-solid <?= ($pi === null || $pi > 0) ? 'fa-envelope-open-text' : 'fa-xmark' ?>" aria-hidden="true"></i> <?= ($pi === null || $pi > 0) ? 'Invite brokers' : 'Brokers apply to join' ?></li>
      <li><i class="fa-solid <?= ($pv === null || $pv > 0) ? 'fa-crown' : 'fa-xmark' ?>" aria-hidden="true"></i> <?= ($pv === null || $pv > 0) ? 'VIP perks' : 'No VIP perks' ?></li>
      <li><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i> <?= $p['is_default'] ? 'No monthly fee' : ($invoiced ? 'Invoiced monthly · EFT' : 'Pay monthly by card · cancel any time') ?></li>
    </ul>

    <?php if ($isCurrent): ?>
    <button class="btn btn-ghost btn-sm plan-option__cta" type="button" disabled>Current plan</button>
    <?php elseif (!$canBill || !$isUp): ?>
    <?php if ($isUp || !$p['is_default']): ?><p class="plan-option__fine"><?= $canBill ? 'Contact SalesDesk to change plans.' : 'The business owner can change plans.' ?></p><?php endif; ?>
    <?php elseif (($isInvoiced || ($sub && !$isOnline && !$plan['is_default'])) && !$invoiced): ?>
    <p class="plan-option__fine">Your plan is arranged with SalesDesk — contact us to change it.</p>
    <?php elseif (!$invoiced && $billingOn && !$isOnline): /* Business Pro: pay online */ ?>
    <form method="POST" action="<?= $self ?>">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="checkout">
      <input type="hidden" name="plan_code" value="<?= htmlspecialchars($p['code']) ?>">
      <button class="btn btn-primary btn-sm plan-option__cta" type="submit">Upgrade to <?= htmlspecialchars($p['name']) ?></button>
    </form>
    <p class="plan-option__fine">Pay monthly by card through PayFast. Cancel any time.</p>
    <?php elseif (!$invoiced && $isOnline && $cancelling): ?>
    <button class="btn btn-ghost btn-sm plan-option__cta" type="button" disabled>Available after <?= htmlspecialchars($paidThrough) ?></button>
    <?php elseif ($requested): ?>
    <button class="btn btn-ghost btn-sm plan-option__cta" type="button" disabled><i class="fa-solid fa-check" aria-hidden="true"></i> Requested <?= date('j M', strtotime($lastReq)) ?></button>
    <p class="plan-option__fine">We’ll contact you to set it up.</p>
    <?php else: /* Enterprise (invoiced), or Pro while online payment is off */ ?>
    <form method="POST" action="<?= $self ?>">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="request_upgrade">
      <input type="hidden" name="plan_code" value="<?= htmlspecialchars($p['code']) ?>">
      <button class="btn btn-primary btn-sm plan-option__cta" type="submit"><?= $invoiced ? 'Talk to us about ' . htmlspecialchars($p['name']) : 'Request ' . htmlspecialchars($p['name']) ?></button>
    </form>
    <p class="plan-option__fine"><?= $invoiced ? 'Set up with SalesDesk and invoiced monthly — pay by EFT.' : 'Online payment is coming soon — we’ll contact you to set it up.' ?></p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<p class="plan-compare__fine">Prices exclude VAT. Broker commission and the SalesDesk fee work the same on every plan.</p>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageStyles  = ['/assets/css/plans.css'];
$pageScripts = ['/assets/js/admin.js', '/assets/js/billing-return.js'];   // data-confirm + waiting-for-PayFast refresh
require_once '../../views/layout-app.php';
