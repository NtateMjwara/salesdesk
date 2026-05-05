<?php
/**
 * SalesDesk — Admin: Payout Dashboard
 * T1 owns this file.
 *
 * This is the manual EFT control centre for MVP.
 *
 * Sections:
 *   1. Pending commissions — awaiting admin approval
 *   2. Scheduled payouts  — approved and ready to pay
 *   3. Failed payouts     — need retry or investigation
 *   4. Recent paid        — last 30 days, read-only
 *
 * Guards: requireRole('admin'), validateCSRF() on all writes.
 * Every state change goes through transitionCommissionStatus()
 * which enforces valid transitions and writes audit_logs.
 *
 * CRITICAL: Bank account encryption (p4) must be in place before
 * any live payout is processed. If USE_BANK_ENCRYPTION is not true
 * in config, the "Mark paid" action is disabled.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/mailer.php';
require_once '../../includes/response.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];

// Encryption gate — disable live payouts until bank data is encrypted.
$encryptionEnabled = defined('USE_BANK_ENCRYPTION') && USE_BANK_ENCRYPTION;

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // ── Approve commission ────────────────────────────────────
    if ($action === 'approve_commission') {
        $commissionId = (int) ($_POST['commission_id'] ?? 0);
        if ($commissionId > 0) {
            $ok = transitionCommissionStatus($commissionId, 'approved', $adminId, 'Admin approved via payout dashboard');
            if ($ok) {
                // Create a scheduled payout record.
                $commission = getCommissionById($commissionId);
                if ($commission) {
                    $bankAccount = getBrokerBankAccount(
                        (int) $commission['broker_id'],
                        $commission['organization_id'] ? (int) $commission['organization_id'] : null
                    );
                    if ($bankAccount) {
                        // Transition to scheduled.
                        transitionCommissionStatus($commissionId, 'scheduled', $adminId);
                        createPayoutRecord(
                            $commissionId,
                            (int) $commission['broker_id'],
                            (int) $bankAccount['id'],
                            (float) $commission['net_amount'],
                            $adminId,
                            $commission['organization_id'] ? (int) $commission['organization_id'] : null
                        );
                        $_SESSION['flash_ok'] = "Commission approved and payout scheduled.";
                    } else {
                        $_SESSION['flash_error'] = "Commission approved, but broker has no bank account on file — payout cannot be scheduled.";
                    }
                }
            } else {
                $_SESSION['flash_error'] = "Could not approve commission — invalid state transition.";
            }
        }
        redirect('/app/admin/payouts.php');
    }

    // ── Mark payout paid ─────────────────────────────────────
    if ($action === 'mark_paid') {
        if (!$encryptionEnabled) {
            $_SESSION['flash_error'] = "Live payouts are disabled until bank account encryption is configured.";
            redirect('/app/admin/payouts.php');
        }

        $payoutId     = (int) ($_POST['payout_id'] ?? 0);
        $commissionId = (int) ($_POST['commission_id'] ?? 0);
        $reference    = trim($_POST['reference_number'] ?? '');

        if ($payoutId > 0 && $commissionId > 0 && $reference !== '') {
            // Move commission to processing then paid.
            transitionCommissionStatus($commissionId, 'processing', $adminId);
            transitionCommissionStatus($commissionId, 'paid', $adminId, "EFT ref: {$reference}");

            // Update payout record.
            $pdo->prepare("
                UPDATE payouts
                SET status          = 'paid',
                    reference_number = ?,
                    processed_at    = NOW()
                WHERE id = ?
            ")->execute([$reference, $payoutId]);

            writeAuditLog('payout.paid', 'payout', $payoutId,
                ['status' => 'scheduled'],
                ['status' => 'paid', 'reference_number' => $reference],
                $adminId
            );

            // Notify broker.
            $commission = getCommissionById($commissionId);
            if ($commission) {
                // In-app notification.
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, meta, created_at)
                    VALUES (?, 'payout_paid', ?, ?, ?, NOW())
                ")->execute([
                    $commission['broker_id'],
                    'Commission paid — ' . formatZAR((float)$commission['net_amount']),
                    "EFT reference {$reference} processed successfully.",
                    json_encode(['commission_id' => $commissionId, 'payout_id' => $payoutId]),
                ]);

                // Email notification to broker.
                sendPayoutPaidEmail($commission, $reference);
            }

            $_SESSION['flash_ok'] = "Payout marked as paid. Broker notified.";
        } else {
            $_SESSION['flash_error'] = "Missing payout ID, commission ID, or EFT reference number.";
        }
        redirect('/app/admin/payouts.php');
    }

    // ── Retry failed payout ───────────────────────────────────
    if ($action === 'retry_payout') {
        $commissionId = (int) ($_POST['commission_id'] ?? 0);
        $brokerId     = (int) ($_POST['broker_id'] ?? 0);
        $orgId        = ($_POST['org_id'] ?? '') !== '' ? (int) $_POST['org_id'] : null;
        $amount       = (float) ($_POST['amount'] ?? 0);

        if ($commissionId > 0 && $brokerId > 0 && $amount > 0) {
            // Move failed commission back to scheduled.
            $ok = transitionCommissionStatus($commissionId, 'scheduled', $adminId, 'Retry initiated by admin');
            if ($ok) {
                $bankAccount = getBrokerBankAccount($brokerId, $orgId);
                if ($bankAccount) {
                    // Generate a new idempotency key (different from the failed attempt).
                    $newIdempKey = 'idem-retry-' . $commissionId . '-' . time();

                    $pdo->prepare("
                        INSERT INTO payouts
                            (uuid, commission_id, broker_id, organization_id, bank_account_id,
                             amount, status, idempotency_key, retry_count, scheduled_at, created_at)
                        SELECT ?, commission_id, broker_id, organization_id, ?,
                               ?, 'scheduled', ?, retry_count + 1, NOW(), NOW()
                        FROM payouts
                        WHERE commission_id = ?
                        ORDER BY created_at DESC
                        LIMIT 1
                    ")->execute([
                        generateUuidV4(),
                        $bankAccount['id'],
                        $amount,
                        $newIdempKey,
                        $commissionId,
                    ]);

                    writeAuditLog('payout.retry_scheduled', 'commission', $commissionId,
                        ['status' => 'failed'], ['status' => 'scheduled', 'retry' => true], $adminId);

                    $_SESSION['flash_ok'] = "Retry payout scheduled.";
                } else {
                    $_SESSION['flash_error'] = "Broker has no valid bank account — cannot retry.";
                }
            } else {
                $_SESSION['flash_error'] = "Cannot retry — commission is not in a failed state.";
            }
        }
        redirect('/app/admin/payouts.php');
    }

    // ── Mark payout failed (EFT bounced / returned) ───────────
    // Admin records when an EFT comes back so the broker is
    // notified and the payout enters the retry queue.
    if ($action === 'mark_failed') {
        $payoutId     = (int) ($_POST['payout_id']     ?? 0);
        $commissionId = (int) ($_POST['commission_id'] ?? 0);
        $errorMsg     = trim($_POST['error_message']   ?? 'EFT returned — please verify bank details.');

        if ($payoutId > 0 && $commissionId > 0) {
            // Transition commission: processing → failed
            // (if it's still scheduled, go scheduled → processing → failed)
            $commRow = $pdo->prepare("SELECT status FROM commissions WHERE id = ?");
            $commRow->execute([$commissionId]);
            $commStatus = $commRow->fetchColumn();

            if ($commStatus === 'scheduled') {
                transitionCommissionStatus($commissionId, 'processing', $adminId);
            }
            transitionCommissionStatus($commissionId, 'failed', $adminId, "EFT bounced: {$errorMsg}");

            // Mark the payout row as failed with the error message.
            $pdo->prepare("
                UPDATE payouts
                SET status        = 'failed',
                    error_message = ?,
                    processed_at  = NOW()
                WHERE id = ?
            ")->execute([$errorMsg, $payoutId]);

            writeAuditLog(
                'payout.failed',
                'payout',
                $payoutId,
                ['status' => 'scheduled'],
                ['status' => 'failed', 'error_message' => $errorMsg],
                $adminId
            );

            // Notify broker to verify their bank details.
            $commission = getCommissionById($commissionId);
            if ($commission) {
                // In-app notification.
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, meta, created_at)
                    VALUES (?, 'payout_failed', 'Action required — payout unsuccessful', ?, ?, NOW())
                ")->execute([
                    $commission['broker_id'],
                    'Please verify your banking details. We were unable to process your commission payout.',
                    json_encode(['commission_id' => $commissionId, 'payout_id' => $payoutId]),
                ]);

                // Email the broker.
                sendPayoutFailedEmail($commission);
            }

            $_SESSION['flash_error'] = "Payout marked as failed. Broker has been notified to verify their bank details.";
        } else {
            $_SESSION['flash_error'] = "Missing payout ID or commission ID.";
        }
        redirect('/app/admin/payouts.php');
    }
}

// ── GET: load dashboard data ─────────────────────────────────

// 1. Pending commissions (status = pending).
$pendingCommStmt = $pdo->prepare("
    SELECT
        c.id, c.uuid, c.gross_amount, c.platform_fee, c.net_amount, c.created_at,
        l.uuid          AS lead_uuid,
        l.buyer_name,
        cars.make, cars.model, cars.year,
        bu.email        AS broker_email,
        bp.first_name   AS broker_first,
        bp.last_name    AS broker_last,
        d.company_name  AS dealer_company,
        o.name          AS org_name
    FROM commissions c
    JOIN leads l    ON l.id = c.lead_id
    JOIN cars       ON cars.id = l.car_id
    JOIN users bu   ON bu.id = c.broker_id
    JOIN profiles bp ON bp.user_id = c.broker_id
    JOIN dealers d  ON d.id = c.dealer_id
    LEFT JOIN organizations o ON o.id = c.organization_id
    WHERE c.status = 'pending'
    ORDER BY c.created_at ASC
");
$pendingCommStmt->execute();
$pendingCommissions = $pendingCommStmt->fetchAll();

// 2. Scheduled payouts.
$scheduledStmt = $pdo->prepare("
    SELECT
        p.id AS payout_id, p.amount, p.scheduled_at, p.retry_count,
        p.idempotency_key,
        c.id AS commission_id, c.status AS commission_status,
        c.gross_amount, c.net_amount,
        ba.bank_name,
        ba.account_number,
        bu.email AS broker_email,
        bp.first_name AS broker_first,
        bp.last_name  AS broker_last,
        c.broker_id, c.organization_id,
        o.name AS org_name,
        cars.make, cars.model, cars.year
    FROM payouts p
    JOIN commissions c  ON c.id = p.commission_id
    JOIN bank_accounts ba ON ba.id = p.bank_account_id
    JOIN users bu       ON bu.id = p.broker_id
    JOIN profiles bp    ON bp.user_id = p.broker_id
    JOIN leads l        ON l.id = c.lead_id
    JOIN cars           ON cars.id = l.car_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    WHERE p.status = 'scheduled'
    ORDER BY p.scheduled_at ASC
");
$scheduledStmt->execute();
$scheduledPayouts = $scheduledStmt->fetchAll();

// 3. Failed payouts.
$failedStmt = $pdo->prepare("
    SELECT
        p.id AS payout_id, p.amount, p.error_message, p.retry_count, p.created_at,
        c.id AS commission_id, c.broker_id, c.organization_id,
        bu.email AS broker_email,
        cars.make, cars.model, cars.year,
        o.name AS org_name
    FROM payouts p
    JOIN commissions c ON c.id = p.commission_id
    JOIN users bu      ON bu.id = p.broker_id
    JOIN leads l       ON l.id = c.lead_id
    JOIN cars          ON cars.id = l.car_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    WHERE p.status = 'failed'
    ORDER BY p.created_at DESC
    LIMIT 50
");
$failedStmt->execute();
$failedPayouts = $failedStmt->fetchAll();

// 4. Recent paid (last 30 days).
$recentPaidStmt = $pdo->prepare("
    SELECT
        p.id AS payout_id, p.amount, p.reference_number, p.processed_at,
        bu.email AS broker_email,
        cars.make, cars.model, cars.year
    FROM payouts p
    JOIN commissions c ON c.id = p.commission_id
    JOIN users bu      ON bu.id = p.broker_id
    JOIN leads l       ON l.id = c.lead_id
    JOIN cars          ON cars.id = l.car_id
    WHERE p.status = 'paid'
      AND p.processed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY p.processed_at DESC
    LIMIT 50
");
$recentPaidStmt->execute();
$recentPaid = $recentPaidStmt->fetchAll();

// ── Totals for dashboard header ───────────────────────────────
$totals = $pdo->query("
    SELECT
        SUM(CASE WHEN status='pending'   THEN net_amount ELSE 0 END) AS pending_value,
        SUM(CASE WHEN status='scheduled' THEN net_amount ELSE 0 END) AS scheduled_value,
        SUM(CASE WHEN status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 THEN net_amount ELSE 0 END) AS paid_30d_value,
        COUNT(CASE WHEN status='pending'   THEN 1 END) AS pending_count,
        COUNT(CASE WHEN status='scheduled' THEN 1 END) AS scheduled_count,
        COUNT(CASE WHEN status='failed'    THEN 1 END) AS failed_count
    FROM commissions
")->fetch();

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<?php if (!$encryptionEnabled): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem;">
  <span class="alert-icon">🔒</span>
  <div>
    <strong>Bank account encryption is not configured.</strong>
    Live payout execution is disabled until <code>USE_BANK_ENCRYPTION</code> is set to
    <code>true</code> in <code>config.php</code> and the encryption key is set in the environment.
    You can approve commissions and schedule payouts, but "Mark paid" will be blocked.
  </div>
</div>
<?php endif; ?>

<!-- ── Dashboard header stats ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:2rem;">
  <?php
  $stats = [
    ['label' => 'Pending approval', 'value' => formatZAR((float)($totals['pending_value'] ?? 0)),   'count' => $totals['pending_count'],   'color' => 'var(--amber)'],
    ['label' => 'Scheduled payout', 'value' => formatZAR((float)($totals['scheduled_value'] ?? 0)), 'count' => $totals['scheduled_count'], 'color' => 'var(--p)'],
    ['label' => 'Failed — needs action', 'value' => $totals['failed_count'] . ' payout(s)',         'count' => $totals['failed_count'],    'color' => 'var(--red)'],
    ['label' => 'Paid (last 30 days)',   'value' => formatZAR((float)($totals['paid_30d_value'] ?? 0)), 'count' => null, 'color' => 'var(--green)'],
  ];
  foreach ($stats as $s):
  ?>
  <div class="card card-body" style="text-align:center;">
    <div style="font-size:20px;font-weight:700;color:<?= $s['color'] ?>;font-family:var(--mono);">
      <?= $s['value'] ?>
    </div>
    <?php if ($s['count'] !== null): ?>
    <div style="font-size:10px;font-family:var(--mono);color:var(--faint);"><?= $s['count'] ?> record(s)</div>
    <?php endif; ?>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;"><?= $s['label'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════
     SECTION 1: Pending commissions
     ══════════════════════════════════ -->
<div class="section-head" style="margin-bottom:.75rem;">
  <h2 class="section-title">Pending commissions</h2>
  <span class="section-count <?= count($pendingCommissions) > 0 ? 'alert-count' : '' ?>">
    <?= count($pendingCommissions) ?>
  </span>
</div>

<?php if (empty($pendingCommissions)): ?>
<div class="empty" style="margin-bottom:2rem;"><span class="empty-icon">✓</span>No pending commissions.</div>
<?php else: ?>
<div class="roster-wrap" style="margin-bottom:2rem;">
  <table class="roster">
    <thead>
      <tr>
        <th>Vehicle</th>
        <th>Broker</th>
        <th>Via</th>
        <th>Dealer</th>
        <th>Gross</th>
        <th>Fee</th>
        <th>Net payable</th>
        <th>Submitted</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingCommissions as $c): ?>
    <tr>
      <td style="font-weight:500;"><?= htmlspecialchars("{$c['year']} {$c['make']} {$c['model']}") ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars(trim("{$c['broker_first']} {$c['broker_last']}") ?: $c['broker_email']) ?></td>
      <td style="font-size:11px;color:var(--muted);"><?= $c['org_name'] ? htmlspecialchars($c['org_name']) : '—' ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars($c['dealer_company']) ?></td>
      <td style="font-family:var(--mono);font-size:12px;"><?= formatZAR((float)$c['gross_amount']) ?></td>
      <td style="font-family:var(--mono);font-size:12px;color:var(--red);"><?= formatZAR((float)$c['platform_fee']) ?></td>
      <td style="font-family:var(--mono);font-size:13px;font-weight:600;color:var(--green);"><?= formatZAR((float)$c['net_amount']) ?></td>
      <td style="font-size:11px;color:var(--faint);"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
      <td>
        <form method="POST">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="approve_commission">
          <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
          <button class="btn btn-success btn-sm" type="submit"
                  onclick="return confirm('Approve this commission and schedule payout?')">
            Approve
          </button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════
     SECTION 2: Scheduled payouts
     ══════════════════════════════════ -->
<div class="section-head" style="margin-bottom:.75rem;">
  <h2 class="section-title">Scheduled payouts</h2>
  <span class="section-count"><?= count($scheduledPayouts) ?></span>
</div>

<?php if (empty($scheduledPayouts)): ?>
<div class="empty" style="margin-bottom:2rem;"><span class="empty-icon">✓</span>No scheduled payouts.</div>
<?php else: ?>
<div class="roster-wrap" style="margin-bottom:2rem;">
  <table class="roster">
    <thead>
      <tr>
        <th>Broker / Org</th>
        <th>Vehicle</th>
        <th>Bank</th>
        <th>Amount</th>
        <th>Scheduled</th>
        <th>EFT reference</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($scheduledPayouts as $p): ?>
    <tr>
      <td>
        <div style="font-size:13px;font-weight:500;"><?= htmlspecialchars(trim("{$p['broker_first']} {$p['broker_last']}")) ?></div>
        <?php if ($p['org_name']): ?>
        <div style="font-size:11px;color:var(--muted);">via <?= htmlspecialchars($p['org_name']) ?></div>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--faint);"><?= htmlspecialchars($p['broker_email']) ?></div>
      </td>
      <td style="font-size:12px;"><?= htmlspecialchars("{$p['year']} {$p['make']} {$p['model']}") ?></td>
      <td style="font-size:12px;">
        <?= htmlspecialchars($p['bank_name']) ?><br>
        <span style="font-family:var(--mono);font-size:11px;color:var(--muted);">
          ···<?= htmlspecialchars(substr($p['account_number'], -4)) ?>
        </span>
      </td>
      <td style="font-family:var(--mono);font-size:14px;font-weight:700;color:var(--p);">
        <?= formatZAR((float)$p['amount']) ?>
      </td>
      <td style="font-size:11px;color:var(--faint);"><?= date('d M Y', strtotime($p['scheduled_at'])) ?></td>
      <td>
        <input type="text" id="ref-<?= $p['payout_id'] ?>"
               placeholder="EFT-2025-XXXX"
               style="padding:5px 8px;border:1px solid var(--border);border-radius:6px;
                      font-size:12px;font-family:var(--mono);width:140px;">
      </td>
      <td>
        <?php if ($encryptionEnabled): ?>
        <form method="POST" id="payForm-<?= $p['payout_id'] ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="mark_paid">
          <input type="hidden" name="payout_id" value="<?= $p['payout_id'] ?>">
          <input type="hidden" name="commission_id" value="<?= $p['commission_id'] ?>">
          <input type="hidden" name="reference_number" id="refHidden-<?= $p['payout_id'] ?>">
          <button class="btn btn-success btn-sm" type="button"
                  onclick="submitMarkPaid(<?= $p['payout_id'] ?>)">
            Mark paid ✓
          </button>
        </form>
        <?php else: ?>
        <button class="btn btn-ghost btn-sm" disabled title="Encryption not configured">Mark paid</button>
        <?php endif; ?>
        <button class="btn btn-danger btn-sm" style="margin-top:4px;"
                onclick="openMarkFailedModal(<?= $p['payout_id'] ?>, <?= $p['commission_id'] ?>)">
          Mark failed
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════
     SECTION 3: Failed payouts
     ══════════════════════════════════ -->
<?php if (!empty($failedPayouts)): ?>
<div class="section-head" style="margin-bottom:.75rem;">
  <h2 class="section-title" style="color:var(--red);">Failed payouts</h2>
  <span class="section-count alert-count"><?= count($failedPayouts) ?></span>
</div>
<div class="roster-wrap" style="margin-bottom:2rem;">
  <table class="roster">
    <thead><tr><th>Broker</th><th>Vehicle</th><th>Amount</th><th>Error</th><th>Retries</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($failedPayouts as $fp): ?>
    <tr>
      <td style="font-size:12px;"><?= htmlspecialchars($fp['broker_email']) ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars("{$fp['year']} {$fp['make']} {$fp['model']}") ?></td>
      <td style="font-family:var(--mono);font-size:12px;"><?= formatZAR((float)$fp['amount']) ?></td>
      <td style="font-size:11px;color:var(--red);"><?= htmlspecialchars($fp['error_message'] ?? 'Unknown error') ?></td>
      <td style="font-family:var(--mono);font-size:12px;text-align:center;"><?= (int)$fp['retry_count'] ?></td>
      <td>
        <form method="POST">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="retry_payout">
          <input type="hidden" name="commission_id" value="<?= $fp['commission_id'] ?>">
          <input type="hidden" name="broker_id" value="<?= $fp['broker_id'] ?>">
          <input type="hidden" name="org_id" value="<?= $fp['organization_id'] ?? '' ?>">
          <input type="hidden" name="amount" value="<?= $fp['amount'] ?>">
          <button class="btn btn-info btn-sm" type="submit"
                  onclick="return confirm('Schedule a retry payout for this commission?')">Retry</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════
     SECTION 4: Recently paid
     ══════════════════════════════════ -->
<div class="section-head" style="margin-bottom:.75rem;">
  <h2 class="section-title">Recently paid <span style="font-size:12px;color:var(--faint);font-weight:400;">(last 30 days)</span></h2>
</div>
<?php if (empty($recentPaid)): ?>
<div class="empty"><span class="empty-icon">—</span>No payouts in the last 30 days.</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead><tr><th>Broker</th><th>Vehicle</th><th>Net paid</th><th>EFT reference</th><th>Paid on</th></tr></thead>
    <tbody>
    <?php foreach ($recentPaid as $rp): ?>
    <tr>
      <td style="font-size:12px;"><?= htmlspecialchars($rp['broker_email']) ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars("{$rp['year']} {$rp['make']} {$rp['model']}") ?></td>
      <td style="font-family:var(--mono);font-size:13px;font-weight:600;color:var(--green);"><?= formatZAR((float)$rp['amount']) ?></td>
      <td style="font-family:var(--mono);font-size:11px;color:var(--muted);"><?= htmlspecialchars($rp['reference_number'] ?? '—') ?></td>
      <td style="font-size:11px;color:var(--faint);"><?= $rp['processed_at'] ? date('d M Y', strtotime($rp['processed_at'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════
     Mark Failed Modal
     ══════════════════════════════════ -->
<div class="modal-bg" id="markFailedModal">
  <div class="modal">
    <div class="modal-title" style="color:var(--red);">Mark Payout Failed</div>
    <p class="modal-sub">
      Record this EFT as returned or failed. The broker will be notified
      to verify their bank details. The payout will move to the retry queue.
    </p>
    <form method="POST" id="markFailedForm">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="mark_failed">
      <input type="hidden" name="payout_id" id="failedPayoutId">
      <input type="hidden" name="commission_id" id="failedCommissionId">
      <div class="fgroup">
        <label class="flabel" for="failedErrorMsg">Reason / error</label>
        <textarea class="finput" id="failedErrorMsg" name="error_message" rows="2"
                  placeholder="e.g. EFT returned — invalid account number"
                  style="min-height:60px;">EFT returned — please verify bank details.</textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost"
                onclick="document.getElementById('markFailedModal').classList.remove('open')">
          Cancel
        </button>
        <button type="submit" class="btn btn-danger">Mark failed &amp; notify broker</button>
      </div>
    </form>
  </div>
</div>

<script>
function submitMarkPaid(payoutId) {
  var refInput = document.getElementById('ref-' + payoutId);
  var ref = refInput ? refInput.value.trim() : '';
  if (!ref) {
    alert('Please enter an EFT reference number before marking as paid.');
    refInput && refInput.focus();
    return;
  }
  document.getElementById('refHidden-' + payoutId).value = ref;
  if (confirm('Confirm EFT reference: ' + ref + '\n\nMark this payout as paid?')) {
    document.getElementById('payForm-' + payoutId).submit();
  }
}

function openMarkFailedModal(payoutId, commissionId) {
  document.getElementById('failedPayoutId').value     = payoutId;
  document.getElementById('failedCommissionId').value = commissionId;
  document.getElementById('markFailedModal').classList.add('open');
}

// Close any modal on backdrop click.
document.querySelectorAll('.modal-bg').forEach(function (bg) {
  bg.addEventListener('click', function (e) {
    if (e.target === bg) bg.classList.remove('open');
  });
});
</script>

<?php
$pageContent = ob_get_clean();

function csrf_hidden_field(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(generateCSRFToken()) . '">';
}

$pageTitle = 'Payouts | Admin';
require_once '../../views/layout-app.php';
