<?php
/**
 * SalesDesk — Broker Earnings & Bank Account
 * T4 owns this file. Route: /app/broker/earnings.php
 *
 * Commission list: car, dealer, gross, fee, net, status.
 * Totals: earned (paid) + pending.
 * Payout history.
 * Bank account setup inline.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Salesdesk ─────────────────────────────────────────────────
$deskStmt = $pdo->prepare("SELECT id, display_name FROM salesdesks WHERE user_id = ? AND is_active = 1 LIMIT 1");
$deskStmt->execute([$userId]);
$desk = $deskStmt->fetch();
if (!$desk) { redirect('/auth/register.php'); }
$salesdeskId = (int) $desk['id'];

$csrf = generateCSRFToken();

// ── Handle bank account save ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bank_account') {
        $holder  = trim($_POST['account_holder'] ?? '');
        $bank    = trim($_POST['bank_name']      ?? '');
        $accNum  = trim($_POST['account_number'] ?? '');
        $branch  = trim($_POST['branch_code']    ?? '');
        $accType = $_POST['account_type'] ?? 'cheque';

        $bankErrors = [];
        if (!$holder)                                      $bankErrors[] = 'Account holder name is required.';
        if (!$bank)                                        $bankErrors[] = 'Bank name is required.';
        if (!$accNum || !preg_match('/^\d{6,20}$/', preg_replace('/\s/', '', $accNum)))
                                                           $bankErrors[] = 'Please enter a valid account number (digits only).';
        if (!$branch || !preg_match('/^\d{5,6}$/', preg_replace('/\s/', '', $branch)))
                                                           $bankErrors[] = 'Please enter a valid 5 or 6 digit branch code.';
        if (!in_array($accType, ['cheque','savings','transmission']))
                                                           $accType = 'cheque';

        if (!$bankErrors) {
            // Set existing accounts as non-primary.
            $pdo->prepare("UPDATE bank_accounts SET is_primary=0 WHERE user_id=?")->execute([$userId]);
            // Insert new.
            $pdo->prepare("
                INSERT INTO bank_accounts
                    (user_id, account_holder, bank_name, account_number, branch_code, account_type, is_primary, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    account_holder=VALUES(account_holder),
                    bank_name=VALUES(bank_name),
                    account_number=VALUES(account_number),
                    branch_code=VALUES(branch_code),
                    account_type=VALUES(account_type),
                    is_primary=1
            ")->execute([$userId, $holder, $bank, preg_replace('/\s/', '', $accNum), preg_replace('/\s/', '', $branch), $accType]);
            $_SESSION['flash_ok'] = 'Bank account saved successfully.';
            redirect('/app/broker/earnings.php');
        }
    }
}

$bankErrors = $bankErrors ?? [];
$flash      = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// ── Load bank account ─────────────────────────────────────────
$bankStmt = $pdo->prepare("
    SELECT * FROM bank_accounts WHERE user_id = ? AND is_primary = 1 LIMIT 1
");
$bankStmt->execute([$userId]);
$bankAccount = $bankStmt->fetch();

// ── Load commissions ──────────────────────────────────────────
$commStmt = $pdo->prepare("
    SELECT
        cm.id, cm.uuid, cm.gross_amount, cm.platform_fee, cm.net_amount,
        cm.status, cm.approved_at, cm.paid_at, cm.created_at,
        l.uuid AS lead_uuid, l.buyer_name,
        c.make, c.model, c.year,
        d.company_name AS dealer_name
    FROM commissions cm
    JOIN leads l  ON l.id = cm.lead_id
    JOIN cars c   ON c.id = l.car_id
    JOIN dealers d ON d.id = cm.dealer_id
    WHERE cm.broker_id = ?
    ORDER BY cm.created_at DESC
");
$commStmt->execute([$userId]);
$commissions = $commStmt->fetchAll();

// ── Summary totals ────────────────────────────────────────────
$totals = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status='paid'                                THEN net_amount ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN status IN ('pending','approved','scheduled') THEN net_amount ELSE 0 END), 0) AS total_pending,
        COUNT(CASE WHEN status='paid' THEN 1 END)    AS count_paid,
        COUNT(CASE WHEN status='pending' THEN 1 END) AS count_pending
    FROM commissions WHERE broker_id = ?
");
$totals->execute([$userId]);
$totals = $totals->fetch();

// ── Load payouts ──────────────────────────────────────────────
$payoutsStmt = $pdo->prepare("
    SELECT p.id, p.amount, p.status, p.reference_number, p.processed_at, p.scheduled_at,
           ba.bank_name, ba.account_number
    FROM payouts p
    LEFT JOIN bank_accounts ba ON ba.id = p.bank_account_id
    WHERE p.broker_id = ?
    ORDER BY p.created_at DESC
    LIMIT 20
");
$payoutsStmt->execute([$userId]);
$payouts = $payoutsStmt->fetchAll();

// SA banks list.
$saBanks = [
    'ABSA', 'Capitec Bank', 'First National Bank (FNB)', 'Nedbank',
    'Standard Bank', 'African Bank', 'Bidvest Bank', 'Discovery Bank',
    'Grindrod Bank', 'Investec', 'Mercantile Bank', 'Old Mutual Finance',
    'SA Home Loans', 'TymeBank', 'Ubank', 'Other',
];

// ── Render ────────────────────────────────────────────────────
$pageTitle = 'Earnings | ' . $desk['display_name'];
ob_start();
?>

<?php if ($flash): ?>
<div class="alert alert-success" style="margin-bottom:1rem">
  <i class="fa-solid fa-circle-check alert-icon"></i> <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error" style="margin-bottom:1rem">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i> <?= htmlspecialchars($flashError) ?>
</div>
<?php endif; ?>

<div class="earnings-header">
  <div>
    <h1 class="earnings-title">Earnings</h1>
    <p style="font-size:13px;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($desk['display_name']) ?></p>
  </div>
</div>

<!-- Summary cards -->
<div class="earnings-summary">
  <div class="earn-card">
    <div class="earn-card-icon" style="background:var(--gr-bg);color:var(--green)">
      <i class="fa-solid fa-circle-check"></i>
    </div>
    <div>
      <div class="earn-card-num"><?= formatZAR((float)$totals['total_paid']) ?></div>
      <div class="earn-card-label">Total paid out</div>
      <div class="earn-card-sub"><?= (int)$totals['count_paid'] ?> commission<?= (int)$totals['count_paid'] !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="earn-card">
    <div class="earn-card-icon" style="background:var(--amb-bg);color:var(--amber)">
      <i class="fa-solid fa-clock"></i>
    </div>
    <div>
      <div class="earn-card-num"><?= formatZAR((float)$totals['total_pending']) ?></div>
      <div class="earn-card-label">Pending</div>
      <div class="earn-card-sub"><?= (int)$totals['count_pending'] ?> commission<?= (int)$totals['count_pending'] !== 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="earn-card">
    <div class="earn-card-icon" style="background:var(--p-light);color:var(--p)">
      <i class="fa-solid fa-sack-dollar"></i>
    </div>
    <div>
      <div class="earn-card-num"><?= formatZAR((float)$totals['total_paid'] + (float)$totals['total_pending']) ?></div>
      <div class="earn-card-label">All-time earnings</div>
      <div class="earn-card-sub">Gross → net after platform fee</div>
    </div>
  </div>
</div>

<!-- Bank account setup -->
<div class="earn-section">
  <div class="section-head" style="margin-bottom:.75rem">
    <h2 class="section-title">
      <i class="fa-solid fa-building-columns" style="color:var(--p);margin-right:5px"></i>
      Bank account
    </h2>
    <?php if (!$bankAccount): ?>
    <span class="badge badge-pending">Setup required</span>
    <?php endif; ?>
  </div>

  <?php if ($bankAccount && empty($bankErrors)): ?>
  <!-- Show existing account -->
  <div class="bank-card">
    <div class="bank-card-icon"><i class="fa-solid fa-building-columns"></i></div>
    <div class="bank-card-info">
      <div class="bank-name"><?= htmlspecialchars($bankAccount['bank_name']) ?></div>
      <div class="bank-holder"><?= htmlspecialchars($bankAccount['account_holder']) ?></div>
      <div class="bank-num">···· <?= htmlspecialchars(substr($bankAccount['account_number'], -4)) ?>
        · <?= ucfirst($bankAccount['account_type']) ?>
      </div>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('bankForm').style.display='block';this.style.display='none'">
      <i class="fa-solid fa-pen"></i> Update
    </button>
  </div>
  <div id="bankForm" style="display:none;margin-top:12px;">
  <?php else: ?>
  <?php if (!$bankAccount): ?>
  <div class="alert alert-warn" style="margin-bottom:1rem">
    <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
    Add your bank account to receive commission payouts.
  </div>
  <?php endif; ?>
  <?php if (!empty($bankErrors)): ?>
  <div class="alert alert-error" style="margin-bottom:1rem">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= htmlspecialchars($bankErrors[0]) ?>
  </div>
  <?php endif; ?>
  <div id="bankForm">
  <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="save_bank_account">
      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="account_holder">Account holder name</label>
          <input class="finput" type="text" id="account_holder" name="account_holder"
                 required maxlength="120" autocomplete="name"
                 placeholder="Full legal name or company name"
                 value="<?= htmlspecialchars($_POST['account_holder'] ?? $bankAccount['account_holder'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="bank_name">Bank</label>
          <select class="finput" id="bank_name" name="bank_name" required>
            <option value="">— Select bank —</option>
            <?php foreach ($saBanks as $b): ?>
            <option value="<?= htmlspecialchars($b) ?>"
              <?= (($_POST['bank_name'] ?? $bankAccount['bank_name'] ?? '') === $b) ? 'selected' : '' ?>>
              <?= htmlspecialchars($b) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="account_number">Account number</label>
          <input class="finput" type="text" id="account_number" name="account_number"
                 required maxlength="20" inputmode="numeric"
                 placeholder="Digits only"
                 value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="branch_code">Branch code</label>
          <input class="finput" type="text" id="branch_code" name="branch_code"
                 required maxlength="6" inputmode="numeric"
                 placeholder="e.g. 250655"
                 value="<?= htmlspecialchars($_POST['branch_code'] ?? $bankAccount['branch_code'] ?? '') ?>">
        </div>
      </div>
      <div class="fgroup">
        <label class="flabel" for="account_type">Account type</label>
        <select class="finput" id="account_type" name="account_type" style="max-width:200px">
          <?php foreach (['cheque'=>'Cheque','savings'=>'Savings','transmission'=>'Transmission'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= (($_POST['account_type'] ?? $bankAccount['account_type'] ?? 'cheque') === $v) ? 'selected' : '' ?>>
            <?= $l ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="alert alert-info" style="margin-bottom:1rem">
        <i class="fa-solid fa-shield-halved alert-icon"></i>
        Your banking details are stored securely and only used for commission payouts.
        We will never share your details with third parties.
      </div>
      <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-floppy-disk"></i> Save bank account
      </button>
    </form>
  </div>
</div>

<!-- Commissions table -->
<div class="earn-section">
  <div class="section-head" style="margin-bottom:.75rem">
    <h2 class="section-title">Commission history</h2>
    <span class="section-count"><?= count($commissions) ?></span>
  </div>

  <?php if (empty($commissions)): ?>
  <div class="empty">
    <span class="empty-icon"><i class="fa-solid fa-coins"></i></span>
    No commissions yet. Commissions are created when a dealer closes a lead.
  </div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead>
        <tr>
          <th>Car</th>
          <th>Dealer</th>
          <th>Buyer</th>
          <th>Gross</th>
          <th>Fee</th>
          <th style="color:var(--green)">Net</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($commissions as $c): ?>
      <tr>
        <td style="font-weight:500;font-size:13px;">
          <?= htmlspecialchars($c['year'].' '.$c['make'].' '.$c['model']) ?>
        </td>
        <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($c['dealer_name']) ?></td>
        <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($c['buyer_name']) ?></td>
        <td style="font-family:var(--mono);font-size:12px;"><?= formatZAR((float)$c['gross_amount']) ?></td>
        <td style="font-family:var(--mono);font-size:12px;color:var(--red);">
          -<?= formatZAR((float)$c['platform_fee']) ?>
        </td>
        <td style="font-family:var(--mono);font-size:13px;font-weight:700;color:var(--green);">
          <?= formatZAR((float)$c['net_amount']) ?>
        </td>
        <td>
          <span class="badge badge-<?= match($c['status']) {
            'paid'      => 'verified',
            'pending'   => 'pending',
            'approved'  => 'pending',
            'scheduled' => 'active',
            'failed'    => 'rejected',
            default     => 'suspended',
          } ?>">
            <?= ucfirst($c['status']) ?>
          </span>
        </td>
        <td style="font-size:11px;color:var(--faint);">
          <?= date('d M Y', strtotime($c['created_at'])) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Payout history -->
<?php if (!empty($payouts)): ?>
<div class="earn-section">
  <div class="section-head" style="margin-bottom:.75rem">
    <h2 class="section-title">Payout history</h2>
  </div>
  <div class="roster-wrap">
    <table class="roster">
      <thead><tr><th>Amount</th><th>Bank</th><th>Reference</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($payouts as $p): ?>
      <tr>
        <td style="font-family:var(--mono);font-weight:700;color:var(--green);">
          <?= formatZAR((float)$p['amount']) ?>
        </td>
        <td style="font-size:12px;">
          <?= htmlspecialchars($p['bank_name'] ?? '—') ?>
          <?php if ($p['account_number']): ?>
          ···<?= htmlspecialchars(substr($p['account_number'], -4)) ?>
          <?php endif; ?>
        </td>
        <td style="font-family:var(--mono);font-size:11px;color:var(--muted);">
          <?= htmlspecialchars($p['reference_number'] ?? '—') ?>
        </td>
        <td>
          <span class="badge badge-<?= $p['status'] === 'paid' ? 'verified' : ($p['status'] === 'failed' ? 'rejected' : 'pending') ?>">
            <?= ucfirst($p['status']) ?>
          </span>
        </td>
        <td style="font-size:11px;color:var(--faint);">
          <?= date('d M Y', strtotime($p['processed_at'] ?: $p['scheduled_at'])) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
.earnings-header { display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1.25rem; }
.earnings-title  { font-family:var(--serif);font-size:1.5rem;font-weight:300; }

.earnings-summary { display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:1.5rem; }
.earn-card { background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;display:flex;gap:14px;align-items:flex-start; }
.earn-card-icon { width:42px;height:42px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0; }
.earn-card-num  { font-size:1.25rem;font-weight:700;font-family:var(--mono);color:var(--text); }
.earn-card-label{ font-size:12px;color:var(--muted);margin-top:1px; }
.earn-card-sub  { font-size:11px;color:var(--faint);margin-top:4px; }

.earn-section { background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.25rem;margin-bottom:1rem; }

.bank-card { display:flex;align-items:center;gap:14px;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md); }
.bank-card-icon { width:36px;height:36px;border-radius:var(--r-md);background:var(--p-light);color:var(--p);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0; }
.bank-name   { font-size:14px;font-weight:600;color:var(--text); }
.bank-holder { font-size:12px;color:var(--muted); }
.bank-num    { font-size:11px;font-family:var(--mono);color:var(--faint);margin-top:2px; }

@media (max-width:600px) { .earnings-summary { grid-template-columns:1fr; } }
</style>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
