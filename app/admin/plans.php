<?php
/**
 * SalesDesk — Admin: Plans  (PLANS-1, 0019)
 * Route: /app/admin/plans   — superadmin only
 *
 *   • Monthly recurring revenue and accounts per plan
 *   • The plan catalogue (broker + business): edit name, tagline, price
 *     and limits. Limits change here without a code deploy.
 *   • Recent plan changes (audit_logs plan.*)
 *   • Billing (Phase 3): PayFast set-up status, online MRR, every PayFast
 *     notification (billing_events) with its checks and outcome
 *   • Business invoices (0026): invoiced MRR, open / overdue invoices across
 *     every business (managed on each business's Business tab)
 *
 * Assigning a plan to one account: Users → broker → Edit → Plan & limits.
 * Business plans are assigned from plans Phase 2 (every dealership gets
 * its organisation = business account); prices and limits can be set now.
 *
 * Logic: includes/entitlements.php. Spec: project doc claude/plans-and-billing.md.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/superadmin.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/billing.php';   // Phase 3
require_once '../../includes/invoicing.php';  // 0026: business invoices

applyCachePolicy('auth');
requireSuperadmin();

$adminId = (int) $_SESSION['user_id'];
$self    = '/app/admin/plans';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    if (($_POST['action'] ?? '') === 'update_plan') {
        $planId = (int) ($_POST['plan_id'] ?? 0);
        [$ok, $msg] = sdUpdatePlan($planId, $_POST, $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '#plan-' . $planId);
    }
    redirect($self);
}

$ready   = sdPlansReady();
$billReady = sdBillingReady();
$billOn    = sdBillingAvailable();
$billSum   = $billReady ? sdBillingSummary() : null;
$billEvts  = $billReady ? sdBillingRecentEvents(25) : [];
$billUrls  = sdBillingUrls();
$invSum    = sdInvoicingReady() ? sdInvoiceSummary() : null;   // 0026
$stats   = $ready ? sdPlanStats() : ['plans' => [], 'mrr_cents' => 0, 'brokers_total' => 0, 'businesses_total' => null];
$bizOn   = $stats['businesses_total'] !== null;   // 0020: every dealership has its business account
$groups  = [
    'broker'       => ['Broker plans',   'For individual brokers. Every broker without a plan is on Free.'],
    'organization' => ['Business plans', $bizOn
        ? 'For dealerships and brands. Every dealership is a business account on Free until you move it.'
        : 'For dealerships and brands. Assignable once 0020 Business orgs has run in Admin → Migrations.'],
];
$changes = $ready ? sdRecentPlanChanges(15) : [];
$paying  = array_sum(array_column($stats['plans'], 'paid'));
$comped  = array_sum(array_column($stats['plans'], 'comped'));

$planNames  = [];
foreach (sdPlans() as $pl) {
    $planNames[$pl['code']] = ($pl['audience'] === 'broker' ? 'Broker ' : 'Business ') . $pl['name'];
}
$pName      = static fn (?string $code): string => $code === null ? '—' : ($planNames[$code] ?? $code);
$kName      = static fn (?string $key): string => SD_LIMIT_KEYS[$key]['label'] ?? (string) $key;
$changeText = static function (array $c) use ($pName, $kName): string {
    $before = json_decode((string) $c['before_data'], true) ?: [];
    $after  = json_decode((string) $c['after_data'], true) ?: [];
    $who    = $c['entity_type'] === 'user'
        ? ($c['target_email'] ?: 'user #' . $c['entity_id'])
        : (($c['target_org'] ?? '') ?: 'business #' . $c['entity_id']);
    $val    = static fn (array $d): string => (SD_LIMIT_KEYS[$d['key'] ?? '']['type'] ?? 'count') === 'bool'
        ? strtolower(sdLimitText(isset($d['value']) ? (int) $d['value'] : null, (string) $d['key']))   // 0020: yes / no
        : (array_key_exists('value', $d) && $d['value'] === null ? 'unlimited' : (string) ($d['value'] ?? ''));
    return match ($c['action']) {
        'plan.changed'                => $who . ': ' . $pName($before['plan'] ?? null) . ' → ' . $pName($after['plan'] ?? null)
                                         . (!empty($after['status']) ? ($after['status'] === 'comped' ? ' (comped)' : ' (paid)') : '')
                                         . (!empty($after['until']) ? ' until ' . date('j M Y', strtotime($after['until'])) : ''),
        'plan.limit_override_set'     => $who . ': custom ' . strtolower($kName($after['key'] ?? null)) . ' limit = ' . $val($after),
        'plan.limit_override_cleared' => $who . ': custom ' . strtolower($kName($before['key'] ?? null)) . ' limit removed',
        'plan.catalogue_updated'      => 'Plan “' . ($after['name'] ?? '') . '” edited',
        'plan.upgrade_requested'      => $who . ' asked for ' . $pName($after['plan'] ?? null),
        'plan.cancelled'              => $who . ': online subscription cancelled'
                                         . (!empty($after['until']) ? ', ends ' . date('j M Y', strtotime($after['until'])) : ''),
        default                       => $c['action'],
    };
};

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Plans</h1>
  <span class="section-count">Prices exclude VAT</span>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warn adm-gap"><span class="alert-icon">!</span>
  <div>Run <strong>0019 Plans</strong> in <a href="/app/admin/migrations">Migrations</a> to turn plans on.
    Until then brokers keep their old car limits.</div>
</div>
<?php else: ?>

<div class="adm-stats plans-mrr">
  <div class="adm-stat"><span class="adm-stat-n"><?= htmlspecialchars(sdPriceText($stats['mrr_cents']) === 'Free' ? 'R0' : sdPriceText($stats['mrr_cents'])) ?></span><span class="adm-stat-l">Monthly recurring revenue</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $paying ?></span><span class="adm-stat-l">Paying accounts</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $comped ?></span><span class="adm-stat-l">Comped accounts</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $stats['brokers_total'] ?></span><span class="adm-stat-l">Brokers</span></div>
  <?php if ($bizOn): ?>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $stats['businesses_total'] ?></span><span class="adm-stat-l">Business accounts</span></div>
  <?php endif; ?>
</div>

<p class="adm-muted adm-gap">
  “Paying” counts brokers who pay online (PayFast) and accounts a superadmin marked <em>Paid</em>.
  To move one broker: <a href="/app/admin/users?role=broker">Users</a> → the broker → <strong>Edit</strong> → <strong>Plan &amp; limits</strong>.
  <?php if ($bizOn): ?>To move a business: <a href="/app/admin/dealerships">Dealerships</a> → the dealership → <strong>Business plan</strong>.<?php endif; ?>
</p>

<?php foreach ($groups as $audience => [$title, $blurb]): ?>
<h2 class="adm-h2 adm-section-gap"><?= htmlspecialchars($title) ?></h2>
<p class="plan-phase-note"><?= htmlspecialchars($blurb) ?></p>
<div class="plans-grid">
  <?php foreach (sdPlans($audience) as $p):
    $st   = $stats['plans'][$p['id']] ?? ['accounts' => 0, 'paid' => 0, 'comped' => 0, 'mrr_cents' => 0];
    $keys = array_filter(SD_LIMIT_KEYS, static fn ($d) => $d['audience'] === $audience); ?>
  <div class="card" id="plan-<?= $p['id'] ?>">
    <div class="card-body">
      <div class="plan-card-head">
        <span class="plan-card-name"><?= htmlspecialchars($p['name']) ?>
          <?php if ($p['is_default']): ?><span class="badge badge-verified">Default</span><?php endif; ?>
          <?php if (!$p['is_active']): ?><span class="badge badge-rejected">Hidden</span><?php endif; ?>
        </span>
        <span class="plan-card-price"><?= htmlspecialchars(sdPlanPriceLabel($p)) ?></span>
      </div>
      <?php if ($p['tagline']): ?><p class="adm-sub"><?= htmlspecialchars($p['tagline']) ?></p><?php endif; ?>

      <div class="plan-card-stats">
        <span><strong><?= $audience === 'organization' && !$bizOn ? '—' : (int) $st['accounts'] ?></strong> accounts</span>
        <?php if (!$p['is_default']): ?>
        <span><strong><?= (int) $st['paid'] ?></strong> paid</span>
        <span><strong><?= (int) $st['comped'] ?></strong> comped</span>
        <span><strong><?= htmlspecialchars($st['mrr_cents'] > 0 ? sdPriceText($st['mrr_cents']) : 'R0') ?></strong>/mo</span>
        <?php endif; ?>
      </div>

      <ul class="plan-card-limits">
        <?php foreach ($keys as $key => $def): ?>
        <li><span><?= htmlspecialchars($def['label']) ?></span>
            <span><?= sdLimitText(sdPlanLimit($p, $key), $key) ?></span></li>
        <?php endforeach; ?>
      </ul>

      <details class="plan-edit">
        <summary>Edit plan</summary>
        <form method="POST" action="<?= $self ?>"
              data-confirm="Save changes to the <?= htmlspecialchars($p['name'], ENT_QUOTES) ?> plan? Limits apply to every account on it straight away.">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="update_plan">
          <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
          <div class="fgroup">
            <label class="flabel" for="pn-<?= $p['id'] ?>">Name</label>
            <input class="finput" id="pn-<?= $p['id'] ?>" name="name" maxlength="60" required value="<?= htmlspecialchars($p['name']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="pt-<?= $p['id'] ?>">Tagline <span class="flabel-opt">optional</span></label>
            <input class="finput" id="pt-<?= $p['id'] ?>" name="tagline" maxlength="160" value="<?= htmlspecialchars((string) $p['tagline']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="pp-<?= $p['id'] ?>">Price per month (R, excl. VAT)</label>
            <input class="finput" id="pp-<?= $p['id'] ?>" name="price" inputmode="decimal" required
                   value="<?= htmlspecialchars(rtrim(rtrim(number_format($p['price_cents'] / 100, 2, '.', ''), '0'), '.')) ?>"
                   <?= $p['is_default'] ? 'readonly' : '' ?>>
          </div>
          <?php foreach ($keys as $key => $def):
            $val = sdPlanLimit($p, $key); ?>
          <div class="fgroup">
            <?php if ($def['type'] === 'bool'): ?>
            <label class="plan-unl"><input type="checkbox" name="limit[<?= $key ?>]" value="1" <?= ($val === null || $val > 0) ? 'checked' : '' ?>>
              <?= htmlspecialchars($def['label']) ?></label>
            <?php else: ?>
            <label class="flabel" for="pl-<?= $p['id'] ?>-<?= $key ?>"><?= htmlspecialchars($def['label']) ?></label>
            <div class="plan-edit-limit">
              <input class="finput" id="pl-<?= $p['id'] ?>-<?= $key ?>" type="number" min="0" max="999999"
                     name="limit[<?= $key ?>]" value="<?= $val === null ? '' : (int) $val ?>">
              <label class="plan-unl"><input type="checkbox" name="unlimited[<?= $key ?>]" value="1" <?= $val === null ? 'checked' : '' ?>> Unlimited</label>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php if (!$p['is_default']): ?>
          <div class="fgroup">
            <label class="plan-unl"><input type="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> Offered (untick to hide it from new sign-ups)</label>
          </div>
          <?php else: ?>
          <input type="hidden" name="is_active" value="1">
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" type="submit">Save plan</button>
        </form>
      </details>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<!-- Phase 3: online billing -->
<h2 class="adm-h2 adm-section-gap" id="billing">Billing (PayFast)</h2>
<?php if (!$billReady): ?>
<div class="alert alert-warn adm-gap"><span class="alert-icon">!</span>
  <div>Run <strong>0022 Billing</strong> in <a href="/app/admin/migrations">Migrations</a> to switch on online payments.</div></div>
<?php else: ?>
<div class="card adm-card adm-card-wide adm-gap">
  <div class="card-body">
    <div class="bill-status">
      <span class="badge <?= $billOn ? 'badge-verified' : 'badge-pending' ?>"><?= $billOn ? 'Online payments on' : 'Not set up' ?></span>
      <?php if ($billOn): ?><span class="badge <?= sdPayfastSandbox() ? 'badge-pending' : 'badge-verified' ?>"><?= sdPayfastSandbox() ? 'Sandbox (test)' : 'Live' ?></span><?php endif; ?>
    </div>
    <?php if (!$billOn): ?>
    <p class="adm-muted">Brokers see “Request Pro” until PayFast is set up. On the server, copy
      <span class="adm-mono">includes/payfast.config.example.php</span> to <span class="adm-mono">includes/payfast.config.php</span>
      and fill in your merchant ID, merchant key and recurring-billing passphrase. Start with the sandbox values.</p>
    <?php else: ?>
    <p class="adm-muted">Brokers (Pro) and businesses (Business Pro, from the dealer portal’s Plan page) pay monthly by card through PayFast. Enterprise is invoiced — see Business invoices below.
      <?= sdPayfastSandbox() ? 'Sandbox: no real money moves. Switch PAYFAST_MODE to live with your live values when the test payments below look right.' : '' ?></p>
    <?php endif; ?>
    <dl class="biz-facts bill-urls">
      <div><dt>Notify URL (ITN)</dt><dd class="adm-mono"><?= htmlspecialchars($billUrls['notify']) ?></dd></div>
      <div><dt>Return / cancel</dt><dd class="adm-mono">/app/broker/plan · /app/dealer/plan</dd></div>
    </dl>
    <p class="adm-sub">SalesDesk sends these with every payment, so nothing needs entering on PayFast’s side. The notify URL must be reachable over HTTPS.</p>
  </div>
</div>

<div class="adm-stats plans-mrr">
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $billSum['online'] ?></span><span class="adm-stat-l">Paying online</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= htmlspecialchars($billSum['mrr_cents'] > 0 ? sdPriceText($billSum['mrr_cents']) : 'R0') ?></span><span class="adm-stat-l">Online MRR</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= htmlspecialchars($billSum['received_30d_cents'] > 0 ? sdPriceText($billSum['received_30d_cents']) : 'R0') ?></span><span class="adm-stat-l">Received, last 30 days</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $billSum['cancelling'] ?></span><span class="adm-stat-l">Cancelled, still active</span></div>
  <div class="adm-stat"><span class="adm-stat-n<?= $billSum['past_due'] ? ' adm-text-red' : '' ?>"><?= (int) $billSum['past_due'] ?></span><span class="adm-stat-l">Payment failed</span></div>
</div>

<h3 class="adm-h3">PayFast notifications</h3>
<?php if (!$billEvts): ?>
<div class="empty adm-gap"><span class="empty-icon">✓</span> None yet. Each payment, renewal, failure and cancellation PayFast reports appears here.</div>
<?php else: ?>
<div class="roster-wrap adm-card-wide">
  <table class="roster bill-events">
    <thead><tr><th>When</th><th>Account</th><th>Status</th><th>Amount</th><th>Checks</th><th>Outcome</th></tr></thead>
    <tbody>
    <?php foreach ($billEvts as $e):
      $okAll = $e['signature_ok'] && $e['source_ok'] && $e['amount_ok'] && $e['server_ok'];
      $bad   = str_starts_with((string) $e['outcome'], 'rejected') || str_starts_with((string) $e['outcome'], 'error'); ?>
      <tr>
        <td class="adm-muted"><?= date('j M, H:i', strtotime($e['created_at'])) ?></td>
        <td><?php if ($e['account_type'] === 'broker' && $e['account_id']): ?>
              <a href="/app/admin/users-view?id=<?= (int) $e['account_id'] ?>&amp;tab=edit#plan"><?= htmlspecialchars((string) ($e['email'] ?: 'broker #' . $e['account_id'])) ?></a>
            <?php elseif ($e['account_type'] === 'organization' && $e['account_id']): /* 0026 */ ?>
              <a href="/app/admin/dealerships-view?id=<?= (int) $e['org_dealer_id'] ?>&amp;tab=business#billing"><?= htmlspecialchars((string) ($e['org_name'] ?: 'business #' . $e['account_id'])) ?></a>
            <?php else: ?><span class="adm-sub">—</span><?php endif; ?></td>
        <td class="adm-mono"><?= htmlspecialchars((string) ($e['payment_status'] ?? '—')) ?></td>
        <td class="adm-mono"><?= $e['amount_gross_cents'] !== null ? htmlspecialchars(sdPayfastAmount((int) $e['amount_gross_cents'])) : '—' ?></td>
        <td class="adm-mono bill-checks" title="signature · source · amount · server confirmation">
          <?= $e['signature_ok'] ? '✓' : '✗' ?> <?= $e['source_ok'] ? '✓' : '✗' ?> <?= $e['amount_ok'] ? '✓' : '✗' ?> <?= $e['server_ok'] ? '✓' : '✗' ?>
        </td>
        <td><span class="badge <?= $bad ? 'badge-rejected' : ($okAll ? 'badge-verified' : 'badge-new') ?>"><?= htmlspecialchars((string) $e['outcome']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="adm-sub">Checks: signature (with your passphrase) · came from PayFast · amount matches · PayFast confirmed it. Anything “rejected” changed nothing.</p>
<?php endif; ?>
<?php endif; ?>

<!-- 0026: business invoices (Enterprise, EFT) -->
<h2 class="adm-h2 adm-section-gap" id="invoices">Business invoices</h2>
<?php if (!$invSum): ?>
<div class="alert alert-warn adm-gap"><span class="alert-icon">!</span>
  <div>Run <strong>0026 Business billing</strong> in <a href="/app/admin/migrations">Migrations</a> to invoice businesses.</div></div>
<?php else: ?>
<p class="adm-muted adm-gap">Enterprise (and any business you agree to invoice) is billed monthly by EFT. Start invoicing, mark payments and void
  invoices on each business’s <strong>Business</strong> tab. Invoices go out <?= SD_INVOICE_LEAD_DAYS ?> days before each month starts;
  a plan lapses <?= SD_INVOICE_GRACE_DAYS ?> days after an unpaid due date.</p>
<div class="adm-stats plans-mrr">
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $invSum['invoiced'] ?></span><span class="adm-stat-l">Invoiced businesses</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= htmlspecialchars($invSum['mrr_cents'] > 0 ? sdPriceText($invSum['mrr_cents']) : 'R0') ?></span><span class="adm-stat-l">Invoiced MRR</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= (int) $invSum['open'] ?> · <?= htmlspecialchars($invSum['open_cents'] > 0 ? sdPriceText($invSum['open_cents']) : 'R0') ?></span><span class="adm-stat-l">Open invoices</span></div>
  <div class="adm-stat"><span class="adm-stat-n<?= $invSum['overdue'] ? ' adm-text-red' : '' ?>"><?= (int) $invSum['overdue'] ?></span><span class="adm-stat-l">Overdue</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= htmlspecialchars($invSum['paid_30d_cents'] > 0 ? sdPriceText($invSum['paid_30d_cents']) : 'R0') ?></span><span class="adm-stat-l">Paid, last 30 days</span></div>
</div>
<?php if ($invSum['rows']): ?>
<div class="roster-wrap adm-card-wide">
  <table class="roster inv-admin-table">
    <thead><tr><th>Invoice</th><th>Business</th><th>Period</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($invSum['rows'] as $iv): $ist = sdInvoiceStatusLabel($iv); ?>
      <tr>
        <td><a class="adm-mono" href="/app/billing/invoice?id=<?= (int) $iv['id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($iv['invoice_number']) ?></a></td>
        <td><?php if ($iv['dealer_id']): ?><a href="/app/admin/dealerships-view?id=<?= (int) $iv['dealer_id'] ?>&amp;tab=business#billing"><?= htmlspecialchars($iv['billed_to']) ?></a><?php else: ?><?= htmlspecialchars($iv['billed_to']) ?><?php endif; ?></td>
        <td class="adm-muted"><?= htmlspecialchars(date('j M', strtotime((string) $iv['period_start']))) ?> – <?= htmlspecialchars(date('j M Y', strtotime($iv['period_end'] . ' -1 day'))) ?></td>
        <td class="adm-mono"><?= htmlspecialchars(sdPriceText((int) $iv['amount_cents'])) ?></td>
        <td class="adm-muted"><?= htmlspecialchars(date('j M Y', strtotime((string) $iv['due_at']))) ?></td>
        <td><span class="badge <?= $ist === 'Overdue' ? 'badge-rejected' : 'badge-pending' ?>"><?= $ist ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="empty adm-gap"><span class="empty-icon">✓</span> No open invoices.</div>
<?php endif; ?>
<?php endif; ?>

<h2 class="adm-h2 adm-section-gap">Recent plan changes</h2>
<?php if (!$changes): ?>
<div class="empty adm-gap"><span class="empty-icon">✓</span> No plan changes yet.</div>
<?php else: ?>
<ul class="adm-list adm-card-wide">
  <?php foreach ($changes as $c): ?>
  <li class="adm-list-row"><div>
    <span class="adm-strong"><?= htmlspecialchars($changeText($c)) ?></span><br>
    <span class="adm-sub"><?= htmlspecialchars(trim((string) $c['actor_name']) ?: ((string) $c['actor_email'] ?: 'SalesDesk (automatic)')) ?> · <?= date('j M Y, H:i', strtotime($c['created_at'])) ?></span>
  </div></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Plans | Admin';
$pageStyles  = ['/assets/css/admin.css'];
require_once '../../views/layout-app.php';
