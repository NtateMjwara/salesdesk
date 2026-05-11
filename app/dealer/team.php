<?php
/**
 * SalesDesk — Dealer team management.
 * T3 owns this page post-migration (app/dealer/team.php).
 * T2 ships it with all se1 bug fixes already applied:
 *
 *   BUG-04 (fixed): reinstate now writes verified_by + verified_at.
 *   BUG-06 (fixed): suspend now cascades to exec's active car listings.
 *   se1:   All status changes INSERT into audit_logs with before/after data.
 *          Flash message on suspend includes count of paused listings.
 *          Reinstate restores paused listings to active.
 *
 * Actions (POST):
 *   approve   — pending → verified, fires sendSalesExecApproved()
 *   reject    — pending/verified → rejected, fires sendSalesExecRejected()
 *   suspend   — verified → suspended, pauses exec's active listings
 *   reinstate — suspended/rejected → verified, restores paused listings, fires sendSalesExecApproved()
 *
 * Requires: dealer principal logged in (role=dealer, onboarding_completed=1).
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

$pdo          = Database::getInstance();
$principalId  = (int) $_SESSION['user_id'];

// Verify this user is an active dealer principal with a verified dealer record.
$dealerStmt = $pdo->prepare("
    SELECT d.id AS dealer_id, d.company_name
    FROM dealers d
    WHERE d.user_id = ? AND d.is_active = 1
    LIMIT 1
");
$dealerStmt->execute([$principalId]);
$dealerRow = $dealerStmt->fetch();

if (!$dealerRow) {
    redirect('/app/dealer/dashboard.php');
}

$dealerId    = (int) $dealerRow['dealer_id'];
$dealerName  = $dealerRow['company_name'];

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $action = $_POST['action'] ?? '';
    $execId = (int) ($_POST['exec_id'] ?? 0);

    if (!$execId || !$action) {
        redirect('/app/dealer/team.php');
    }

    // Fetch exec row — must belong to this dealer.
    $execStmt = $pdo->prepare("
        SELECT se.id, se.user_id, se.verification_status, se.job_title,
               p.first_name, p.last_name, u.email
        FROM sales_executives se
        JOIN users u ON u.id = se.user_id
        LEFT JOIN profiles p ON p.user_id = se.user_id
        WHERE se.id = ? AND se.dealer_id = ?
        LIMIT 1
    ");
    $execStmt->execute([$execId, $dealerId]);
    $exec = $execStmt->fetch();

    if (!$exec) {
        redirect('/app/dealer/team.php');
    }

    $execUserId  = (int) $exec['user_id'];
    $execName    = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? ''));
    $execEmail   = $exec['email'];
    $prevStatus  = $exec['verification_status'];

    match ($action) {
        'approve'   => _doApprove($pdo, $execId, $execUserId, $execName, $execEmail, $prevStatus, $principalId, $dealerName),
        'reject'    => _doReject($pdo, $execId, $execUserId, $execName, $execEmail, $prevStatus, $principalId, $dealerName),
        'suspend'   => _doSuspend($pdo, $execId, $execUserId, $execName, $execEmail, $prevStatus, $principalId, $dealerName),
        'reinstate' => _doReinstate($pdo, $execId, $execUserId, $execName, $execEmail, $prevStatus, $principalId, $dealerName),
        default     => null,
    };

    redirect('/app/dealer/team.php');
}


// ── Load team roster (single JOIN — no N+1) ─────────────────
$rosterStmt = $pdo->prepare("
    SELECT
        se.id,
        se.user_id,
        se.verification_status,
        se.job_title,
        se.rejection_reason,
        se.verified_at,
        se.created_at,
        p.first_name,
        p.last_name,
        p.phone,
        p.avatar_url,
        u.email,
        COUNT(c.id)                                         AS total_cars,
        SUM(c.status = 'active')                            AS active_cars,
        COUNT(DISTINCT l.id)                                AS total_leads
    FROM sales_executives se
    JOIN users u ON u.id = se.user_id
    LEFT JOIN profiles p ON p.user_id = se.user_id
    LEFT JOIN cars c ON c.uploaded_by_exec_id = se.id
    LEFT JOIN leads l ON l.car_id = c.id
    WHERE se.dealer_id = ?
    GROUP BY se.id
    ORDER BY
        FIELD(se.verification_status, 'pending', 'verified', 'suspended', 'rejected'),
        se.created_at DESC
");
$rosterStmt->execute([$dealerId]);
$roster = $rosterStmt->fetchAll();

// Bucket by status for the section counts.
$counts = ['pending' => 0, 'verified' => 0, 'suspended' => 0, 'rejected' => 0];
foreach ($roster as $row) {
    $counts[$row['verification_status']] = ($counts[$row['verification_status']] ?? 0) + 1;
}

$csrf = generateCSRFToken();


// ═══════════════════════════════════════════════════════════
// ACTION HANDLERS
// ═══════════════════════════════════════════════════════════

function _doApprove(
    PDO $pdo, int $execId, int $execUserId, string $execName,
    string $execEmail, string $prevStatus, int $principalId, string $dealerName
): void {
    $pdo->prepare("
        UPDATE sales_executives
        SET verification_status = 'verified',
            verified_by         = ?,
            verified_at         = NOW(),
            rejection_reason    = NULL,
            updated_at          = NOW()
        WHERE id = ?
    ")->execute([$principalId, $execId]);

    writeAuditLog(
        'sales_executive.approved',
        'sales_executive',
        $execId,
        ['verification_status' => $prevStatus],
        ['verification_status' => 'verified', 'verified_by' => $principalId]
    );

    sendSalesExecApproved($execEmail, $execName, $dealerName);

    $_SESSION['flash_ok'] = "{$execName} has been approved and can now upload cars.";
}

function _doReject(
    PDO $pdo, int $execId, int $execUserId, string $execName,
    string $execEmail, string $prevStatus, int $principalId, string $dealerName
): void {
    $reason = trim($_POST['rejection_reason'] ?? '');

    $pdo->prepare("
        UPDATE sales_executives
        SET verification_status = 'rejected',
            verified_by         = ?,
            verified_at         = NOW(),
            rejection_reason    = ?,
            updated_at          = NOW()
        WHERE id = ?
    ")->execute([$principalId, $reason ?: null, $execId]);

    writeAuditLog(
        'sales_executive.rejected',
        'sales_executive',
        $execId,
        ['verification_status' => $prevStatus],
        ['verification_status' => 'rejected', 'rejection_reason' => $reason, 'verified_by' => $principalId]
    );

    sendSalesExecRejected($execEmail, $execName, $dealerName, $reason);

    $_SESSION['flash_ok'] = "{$execName}'s request has been declined.";
}

/**
 * BUG-06 FIX: Suspend cascades to exec's active car listings.
 * Flash message includes count of paused listings.
 */
function _doSuspend(
    PDO $pdo, int $execId, int $execUserId, string $execName,
    string $execEmail, string $prevStatus, int $principalId, string $dealerName
): void {
    $reason = trim($_POST['suspension_reason'] ?? '');

    $pdo->prepare("
        UPDATE sales_executives
        SET verification_status = 'suspended',
            rejection_reason    = ?,
            updated_at          = NOW()
        WHERE id = ?
    ")->execute([$reason ?: null, $execId]);

    // BUG-06 FIX: Pause all active listings uploaded by this exec.
    $pauseStmt = $pdo->prepare("
        UPDATE cars
        SET status = 'paused', updated_at = NOW()
        WHERE uploaded_by_exec_id = ? AND status = 'active'
    ");
    $pauseStmt->execute([$execId]);
    $pausedCount = $pauseStmt->rowCount();

    writeAuditLog(
        'sales_executive.suspended',
        'sales_executive',
        $execId,
        ['verification_status' => $prevStatus],
        [
            'verification_status' => 'suspended',
            'rejection_reason'    => $reason,
            'cars_paused'         => $pausedCount,
        ]
    );

    $carNote = $pausedCount > 0
        ? " {$pausedCount} active listing(s) have been paused."
        : '';
    $_SESSION['flash_ok'] = "{$execName} has been suspended.{$carNote}";
}

/**
 * BUG-04 FIX: Reinstate writes verified_by + verified_at (previously missing).
 * Also restores exec's paused listings to active.
 */
function _doReinstate(
    PDO $pdo, int $execId, int $execUserId, string $execName,
    string $execEmail, string $prevStatus, int $principalId, string $dealerName
): void {
    // BUG-04 FIX: verified_by and verified_at were not written on reinstate.
    $pdo->prepare("
        UPDATE sales_executives
        SET verification_status = 'verified',
            verified_by         = ?,
            verified_at         = NOW(),
            rejection_reason    = NULL,
            updated_at          = NOW()
        WHERE id = ?
    ")->execute([$principalId, $execId]);

    // Restore listings that were paused by the suspension.
    $restoreStmt = $pdo->prepare("
        UPDATE cars
        SET status = 'active', updated_at = NOW()
        WHERE uploaded_by_exec_id = ? AND status = 'paused'
    ");
    $restoreStmt->execute([$execId]);
    $restoredCount = $restoreStmt->rowCount();

    writeAuditLog(
        'sales_executive.reinstated',
        'sales_executive',
        $execId,
        ['verification_status' => $prevStatus],
        [
            'verification_status' => 'verified',
            'verified_by'         => $principalId,
            'cars_restored'       => $restoredCount,
        ]
    );

    sendSalesExecApproved($execEmail, $execName, $dealerName);

    $carNote = $restoredCount > 0
        ? " {$restoredCount} listing(s) restored to active."
        : '';
    $_SESSION['flash_ok'] = "{$execName} has been reinstated.{$carNote}";
}


// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function statusBadge(string $status): string
{
    return match ($status) {
        'verified'  => '<span class="badge badge-verified"><i class="fa-solid fa-circle-check" style="font-size:9px"></i> Verified</span>',
        'pending'   => '<span class="badge badge-pending"><i class="fa-solid fa-clock" style="font-size:9px"></i> Pending</span>',
        'suspended' => '<span class="badge badge-suspended"><i class="fa-solid fa-pause" style="font-size:9px"></i> Suspended</span>',
        'rejected'  => '<span class="badge badge-rejected"><i class="fa-solid fa-xmark" style="font-size:9px"></i> Declined</span>',
        default     => '<span class="badge">' . htmlspecialchars($status) . '</span>',
    };
}

function initials(string $name): string
{
    $parts = array_filter(explode(' ', $name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($name, 0, 2)) ?: '??';
}

// ═══════════════════════════════════════════════════════════
// PAGE OUTPUT — captured for layout-app.php
// ═══════════════════════════════════════════════════════════

$pageTitle = 'Team';

ob_start();
?>

<style>
  /* Team page scoped styles */
  .page-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 10px; }
  .page-title { font-family: var(--serif); font-size: 1.6rem; font-weight: 300; }
  .page-title em { font-style: italic; }
  .page-sub { font-size: 13px; color: var(--muted); margin-top: 2px; }
  .stat-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 1.75rem; }
  .stat-box { background: var(--white); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 14px 16px; }
  .stat-num { font-size: 24px; font-weight: 500; font-family: var(--mono); color: var(--text); }
  .stat-label { font-size: 11px; color: var(--faint); margin-top: 2px; }
  .section-head { display: flex; align-items: center; gap: 10px; margin: 2rem 0 .75rem; }
  .section-label { font-size: 13px; font-weight: 600; color: var(--text); }
  .section-count-badge {
    font-family: var(--mono); font-size: 10px; padding: 2px 8px;
    border-radius: var(--r-full); border: 1px solid var(--border);
    background: var(--bg); color: var(--muted);
  }
  .section-count-badge.alert { background: var(--amb-bg); border-color: var(--amb-b); color: var(--amber); }
  .exec-card {
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--r-lg); padding: 16px 18px;
    margin-bottom: 8px; display: grid;
    grid-template-columns: 44px 1fr auto;
    gap: 14px; align-items: start;
  }
  .exec-card:last-child { margin-bottom: 0; }
  .exec-card.status-pending   { border-left: 3px solid var(--amber); }
  .exec-card.status-verified  { border-left: 3px solid var(--green); }
  .exec-card.status-suspended { border-left: 3px solid var(--faint); }
  .exec-card.status-rejected  { border-left: 3px solid var(--red); }
  .exec-info { min-width: 0; }
  .exec-name { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 3px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .exec-meta { font-size: 12px; color: var(--muted); line-height: 1.6; }
  .exec-meta a { color: var(--p); }
  .exec-stats { display: flex; gap: 16px; margin-top: 8px; }
  .exec-stat { font-size: 12px; color: var(--faint); }
  .exec-stat strong { color: var(--text); font-weight: 500; }
  .exec-actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
  .reason-note {
    margin-top: 8px; padding: 8px 12px; border-radius: var(--r-sm);
    background: var(--red-bg); border: 1px solid var(--red-b);
    font-size: 12px; color: var(--red); line-height: 1.5;
  }
  .empty-state {
    text-align: center; padding: 2.5rem 1rem;
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--r-lg); font-size: 13px; color: var(--faint);
  }
  .modal-bg {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 300;
    align-items: center; justify-content: center; padding: 1rem;
    backdrop-filter: blur(2px);
  }
  .modal-bg.open { display: flex; }
  .modal {
    background: var(--white); border-radius: var(--r-xl);
    padding: 1.75rem; width: 100%; max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: modal-in .18s ease;
  }
  @keyframes modal-in { from { opacity:0;transform:scale(.96) } to { opacity:1;transform:scale(1) } }
  .modal-title { font-size: 16px; font-weight: 600; margin-bottom: 6px; }
  .modal-sub { font-size: 13px; color: var(--muted); margin-bottom: 1.25rem; line-height: 1.55; }
  .modal-actions { display: flex; gap: 8px; margin-top: 1.1rem; justify-content: flex-end; }
</style>

<!-- Page header -->
<div class="page-head">
  <div>
    <div class="page-title">Your <em>team</em></div>
    <div class="page-sub"><?= htmlspecialchars($dealerName) ?> — sales executives</div>
  </div>
</div>

<!-- Summary strip -->
<div class="stat-strip">
  <div class="stat-box">
    <div class="stat-num"><?= $counts['verified'] ?></div>
    <div class="stat-label">Active execs</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="<?= $counts['pending'] ? 'color:var(--amber)' : '' ?>"><?= $counts['pending'] ?></div>
    <div class="stat-label">Pending approval</div>
  </div>
  <div class="stat-box">
    <div class="stat-num"><?= $counts['suspended'] ?></div>
    <div class="stat-label">Suspended</div>
  </div>
  <div class="stat-box">
    <div class="stat-num"><?= $counts['rejected'] ?></div>
    <div class="stat-label">Declined</div>
  </div>
</div>

<?php if (empty($roster)): ?>
<div class="empty-state">
  <span style="font-size:28px;display:block;margin-bottom:10px;color:var(--border)">
    <i class="fa-solid fa-user-tie"></i>
  </span>
  No sales executives have joined yet.<br>
  When an exec signs up and selects your dealership, they'll appear here for approval.
</div>

<?php else: ?>

<!-- ── PENDING ──────────────────────────────────────── -->
<?php
$pending = array_filter($roster, fn($r) => $r['verification_status'] === 'pending');
if ($pending):
?>
<div class="section-head">
  <span class="section-label">Pending approval</span>
  <span class="section-count-badge alert"><?= count($pending) ?></span>
</div>
<?php foreach ($pending as $exec):
  $name = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? '')) ?: 'Unnamed';
?>
<div class="exec-card status-pending">
  <div class="avatar"><?= initials($name) ?></div>
  <div class="exec-info">
    <div class="exec-name">
      <?= htmlspecialchars($name) ?>
      <?= statusBadge('pending') ?>
    </div>
    <div class="exec-meta">
      <a href="mailto:<?= htmlspecialchars($exec['email']) ?>"><?= htmlspecialchars($exec['email']) ?></a>
      <?php if ($exec['job_title']): ?> · <?= htmlspecialchars($exec['job_title']) ?><?php endif; ?>
      <br>Requested <?= date('j M Y', strtotime($exec['created_at'])) ?>
    </div>
  </div>
  <div class="exec-actions">
    <!-- Approve -->
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"  value="approve">
      <input type="hidden" name="exec_id" value="<?= $exec['id'] ?>">
      <button class="btn btn-success btn-sm" type="submit">
        <i class="fa-solid fa-check"></i> Approve
      </button>
    </form>
    <!-- Reject — opens modal -->
    <button class="btn btn-danger btn-sm" type="button"
            onclick="openRejectModal(<?= $exec['id'] ?>, '<?= htmlspecialchars(addslashes($name)) ?>')">
      <i class="fa-solid fa-xmark"></i> Decline
    </button>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<!-- ── VERIFIED ─────────────────────────────────────── -->
<?php
$verified = array_filter($roster, fn($r) => $r['verification_status'] === 'verified');
if ($verified):
?>
<div class="section-head">
  <span class="section-label">Active team</span>
  <span class="section-count-badge"><?= count($verified) ?></span>
</div>
<?php foreach ($verified as $exec):
  $name = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? '')) ?: 'Unnamed';
?>
<div class="exec-card status-verified">
  <div class="avatar"><?= initials($name) ?></div>
  <div class="exec-info">
    <div class="exec-name">
      <?= htmlspecialchars($name) ?>
      <?= statusBadge('verified') ?>
    </div>
    <div class="exec-meta">
      <a href="mailto:<?= htmlspecialchars($exec['email']) ?>"><?= htmlspecialchars($exec['email']) ?></a>
      <?php if ($exec['job_title']): ?> · <?= htmlspecialchars($exec['job_title']) ?><?php endif; ?>
    </div>
    <div class="exec-stats">
      <div class="exec-stat"><strong><?= (int)$exec['active_cars'] ?></strong> active listings</div>
      <div class="exec-stat"><strong><?= (int)$exec['total_leads'] ?></strong> leads</div>
      <?php if ($exec['verified_at']): ?>
      <div class="exec-stat">Active since <?= date('j M Y', strtotime($exec['verified_at'])) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="exec-actions">
    <button class="btn btn-warn btn-sm" type="button"
            onclick="openSuspendModal(<?= $exec['id'] ?>, '<?= htmlspecialchars(addslashes($name)) ?>', <?= (int)$exec['active_cars'] ?>)">
      <i class="fa-solid fa-pause"></i> Suspend
    </button>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<!-- ── SUSPENDED ─────────────────────────────────────── -->
<?php
$suspended = array_filter($roster, fn($r) => $r['verification_status'] === 'suspended');
if ($suspended):
?>
<div class="section-head">
  <span class="section-label">Suspended</span>
  <span class="section-count-badge"><?= count($suspended) ?></span>
</div>
<?php foreach ($suspended as $exec):
  $name = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? '')) ?: 'Unnamed';
?>
<div class="exec-card status-suspended">
  <div class="avatar" style="background:var(--bg);color:var(--faint)"><?= initials($name) ?></div>
  <div class="exec-info">
    <div class="exec-name">
      <?= htmlspecialchars($name) ?>
      <?= statusBadge('suspended') ?>
    </div>
    <div class="exec-meta">
      <a href="mailto:<?= htmlspecialchars($exec['email']) ?>"><?= htmlspecialchars($exec['email']) ?></a>
      <?php if ($exec['job_title']): ?> · <?= htmlspecialchars($exec['job_title']) ?><?php endif; ?>
    </div>
    <?php if ($exec['rejection_reason']): ?>
    <div class="reason-note">
      <strong>Reason:</strong> <?= htmlspecialchars($exec['rejection_reason']) ?>
    </div>
    <?php endif; ?>
    <div class="exec-stats">
      <div class="exec-stat"><strong><?= (int)$exec['total_cars'] ?></strong> total listings</div>
      <div class="exec-stat"><strong><?= (int)$exec['total_leads'] ?></strong> leads</div>
    </div>
  </div>
  <div class="exec-actions">
    <!-- Reinstate -->
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"  value="reinstate">
      <input type="hidden" name="exec_id" value="<?= $exec['id'] ?>">
      <button class="btn btn-info btn-sm" type="submit">
        <i class="fa-solid fa-rotate-left"></i> Reinstate
      </button>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<!-- ── REJECTED / DECLINED ───────────────────────────── -->
<?php
$rejected = array_filter($roster, fn($r) => $r['verification_status'] === 'rejected');
if ($rejected):
?>
<div class="section-head">
  <span class="section-label">Declined</span>
  <span class="section-count-badge"><?= count($rejected) ?></span>
</div>
<?php foreach ($rejected as $exec):
  $name = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? '')) ?: 'Unnamed';
?>
<div class="exec-card status-rejected">
  <div class="avatar" style="background:var(--red-bg);color:var(--red)"><?= initials($name) ?></div>
  <div class="exec-info">
    <div class="exec-name">
      <?= htmlspecialchars($name) ?>
      <?= statusBadge('rejected') ?>
    </div>
    <div class="exec-meta">
      <a href="mailto:<?= htmlspecialchars($exec['email']) ?>"><?= htmlspecialchars($exec['email']) ?></a>
      <?php if ($exec['job_title']): ?> · <?= htmlspecialchars($exec['job_title']) ?><?php endif; ?>
      <br>Requested <?= date('j M Y', strtotime($exec['created_at'])) ?>
    </div>
    <?php if ($exec['rejection_reason']): ?>
    <div class="reason-note">
      <strong>Reason:</strong> <?= htmlspecialchars($exec['rejection_reason']) ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="exec-actions">
    <!-- Allow re-approval for declined execs in case of mistake -->
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"  value="reinstate">
      <input type="hidden" name="exec_id" value="<?= $exec['id'] ?>">
      <button class="btn btn-info btn-sm" type="submit">
        <i class="fa-solid fa-rotate-left"></i> Approve
      </button>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; // end roster not empty ?>


<!-- ── REJECT MODAL ──────────────────────────────────────── -->
<div class="modal-bg" id="rejectModal">
  <div class="modal">
    <div class="modal-title">Decline join request</div>
    <div class="modal-sub" id="rejectModalSub">
      Declining will notify the exec by email. You can optionally provide a reason.
    </div>
    <form method="POST" id="rejectForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"  value="reject">
      <input type="hidden" name="exec_id" id="rejectExecId" value="">
      <div class="fgroup">
        <label class="flabel" for="rejection_reason">
          Reason <span class="flabel-opt">(optional — sent to exec)</span>
        </label>
        <textarea class="finput" id="rejection_reason" name="rejection_reason"
                  maxlength="255" rows="3"
                  placeholder="e.g. Position filled, please try again next month"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm">
          <i class="fa-solid fa-xmark"></i> Decline request
        </button>
      </div>
    </form>
  </div>
</div>


<!-- ── SUSPEND MODAL ─────────────────────────────────────── -->
<div class="modal-bg" id="suspendModal">
  <div class="modal">
    <div class="modal-title">Suspend exec</div>
    <div class="modal-sub" id="suspendModalSub">
      Suspending will immediately pause all their active listings.
      You can reinstate them at any time.
    </div>
    <form method="POST" id="suspendForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"  value="suspend">
      <input type="hidden" name="exec_id" id="suspendExecId" value="">
      <div class="fgroup">
        <label class="flabel" for="suspension_reason">
          Reason <span class="flabel-opt">(optional — visible to you only)</span>
        </label>
        <textarea class="finput" id="suspension_reason" name="suspension_reason"
                  maxlength="255" rows="3"
                  placeholder="e.g. On leave, pending review"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('suspendModal')">Cancel</button>
        <button type="submit" class="btn btn-warn btn-sm">
          <i class="fa-solid fa-pause"></i> Suspend
        </button>
      </div>
    </form>
  </div>
</div>


<script>
function openRejectModal(execId, execName) {
  document.getElementById('rejectExecId').value = execId;
  document.getElementById('rejectModalSub').textContent =
    'Declining ' + execName + '\'s request will notify them by email. You can optionally provide a reason.';
  document.getElementById('rejection_reason').value = '';
  document.getElementById('rejectModal').classList.add('open');
  document.getElementById('rejection_reason').focus();
}

function openSuspendModal(execId, execName, activeCars) {
  document.getElementById('suspendExecId').value = execId;
  const carNote = activeCars > 0
    ? ' They have ' + activeCars + ' active listing(s) that will be paused.'
    : ' They have no active listings.';
  document.getElementById('suspendModalSub').textContent =
    'Suspending ' + execName + ' will prevent them from uploading or managing cars.' + carNote;
  document.getElementById('suspension_reason').value = '';
  document.getElementById('suspendModal').classList.add('open');
  document.getElementById('suspension_reason').focus();
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Close modals on background click.
document.querySelectorAll('.modal-bg').forEach(bg => {
  bg.addEventListener('click', e => {
    if (e.target === bg) bg.classList.remove('open');
  });
});

// Close on Escape.
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-bg.open').forEach(bg => bg.classList.remove('open'));
  }
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
