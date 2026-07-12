<?php
/**
 * SalesDesk — Admin: User Management & CIPC Verification
 * T1 owns this file.
 *
 * Responsibilities:
 *   - User table: all roles, filter/search, suspend/reactivate, set car limit
 *   - CIPC verification queue: approve / reject dealers and orgs
 *
 * Guards:
 *   requireRole('admin') — hard gate, session check insufficient
 *   All write actions validate CSRF
 *   All state changes write to audit_logs
 *
 * NOTE: csrf_hidden_field() is declared in includes/csrf.php (required
 * below). Do NOT redeclare it locally in this file — PHP hoists
 * unconditional top-level function declarations at compile time, so a
 * local copy here collides with csrf.php's declaration and causes a
 * fatal "Cannot redeclare csrf_hidden_field()" error.
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
$tab     = $_GET['tab'] ?? 'users';    // 'users' | 'verifications'
$flash   = '';
$flashType = 'ok';

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // ── Suspend dealer (full cascade via suspendDealer()) ──────
    if ($action === 'suspend_dealer') {
        $dealerId = (int) ($_POST['dealer_id'] ?? 0);
        if ($dealerId > 0) {
            $result = suspendDealer($dealerId, $adminId);
            $_SESSION['flash_ok'] = "Dealer suspended. "
                . "{$result['cars_paused']} listing(s) paused, "
                . "{$result['commissions_frozen']} commission(s) frozen.";
        }
        redirect('/app/admin/users.php?tab=users');
    }

    // ── Suspend non-dealer user ────────────────────────────────
    if ($action === 'suspend_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId > 0 && $userId !== $adminId) {
            $pdo->prepare("UPDATE users SET status='suspended', updated_at=NOW() WHERE id=?")
                ->execute([$userId]);
            writeAuditLog('user.suspended', 'user', $userId, ['status' => 'active'], ['status' => 'suspended'], $adminId);
            $_SESSION['flash_ok'] = "User suspended.";
        }
        redirect('/app/admin/users.php?tab=users');
    }

    // ── Reactivate non-dealer user ────────────────────────────
    if ($action === 'reactivate_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $pdo->prepare("UPDATE users SET status='active', updated_at=NOW() WHERE id=?")
                ->execute([$userId]);
            writeAuditLog('user.reactivated', 'user', $userId, ['status' => 'suspended'], ['status' => 'active'], $adminId);
            $_SESSION['flash_ok'] = "User reactivated.";
        }
        redirect('/app/admin/users.php?tab=users');
    }

    // ── Reinstate dealer (full cascade via reinstateDealer()) ──
    // Separate action from reactivate_user so the dealer cascade
    // (unpause listings, unfreeze commissions) runs correctly.
    if ($action === 'reinstate_dealer') {
        $dealerId = (int) ($_POST['dealer_id'] ?? 0);
        if ($dealerId > 0) {
            $result = reinstateDealer($dealerId, $adminId);
            $_SESSION['flash_ok'] = "Dealer reinstated. "
                . "{$result['cars_reinstated']} listing(s) restored, "
                . "{$result['commissions_unfrozen']} commission(s) unfrozen.";
        }
        redirect('/app/admin/users.php?tab=users');
    }

    // ── Set broker car limit ──────────────────────────────────
    if ($action === 'set_car_limit') {
        $userId   = (int) ($_POST['user_id'] ?? 0);
        $newLimit = (int) ($_POST['car_limit'] ?? 0);
        if ($userId > 0 && $newLimit >= 1 && $newLimit <= 200) {
            $pdo->prepare("UPDATE profiles SET car_limit=?, updated_at=NOW() WHERE user_id=?")
                ->execute([$newLimit, $userId]);
            writeAuditLog('user.car_limit_set', 'user', $userId, null, ['car_limit' => $newLimit], $adminId);
            $_SESSION['flash_ok'] = "Car limit updated to {$newLimit}.";
        }
        redirect('/app/admin/users.php?tab=users');
    }

    // ── Approve CIPC verification ─────────────────────────────
    if ($action === 'approve_dealer_cipc') {
        $dealerId = (int) ($_POST['dealer_id'] ?? 0);
        if ($dealerId > 0) {
            $dealerStmt = $pdo->prepare("
                SELECT d.id, d.company_name, u.email
                FROM dealers d
                JOIN users u ON u.id = d.user_id
                WHERE d.id = ?
            ");
            $dealerStmt->execute([$dealerId]);
            $dealer = $dealerStmt->fetch();

            if ($dealer) {
                $pdo->prepare("
                    UPDATE dealers
                    SET verification_status='verified', verified_at=NOW(), updated_at=NOW()
                    WHERE id=?
                ")->execute([$dealerId]);

                writeAuditLog(
                    'dealer.cipc_approved',
                    'dealer',
                    $dealerId,
                    ['verification_status' => 'pending'],
                    ['verification_status' => 'verified'],
                    $adminId
                );

                sendDealerVerified([
                    'email'        => $dealer['email'],
                    'company_name' => $dealer['company_name'],
                ]);

                $_SESSION['flash_ok'] = "{$dealer['company_name']} verified.";
            }
        }
        redirect('/app/admin/users.php?tab=verifications');
    }

    // ── Reject CIPC verification ──────────────────────────────
    if ($action === 'reject_dealer_cipc') {
        $dealerId = (int) ($_POST['dealer_id'] ?? 0);
        $reason   = trim($_POST['reason'] ?? '');
        if ($dealerId > 0) {
            $dealerStmt = $pdo->prepare("
                SELECT d.id, d.company_name, u.email
                FROM dealers d
                JOIN users u ON u.id = d.user_id
                WHERE d.id = ?
            ");
            $dealerStmt->execute([$dealerId]);
            $dealer = $dealerStmt->fetch();

            if ($dealer) {
                $pdo->prepare("
                    UPDATE dealers
                    SET verification_status='rejected', updated_at=NOW()
                    WHERE id=?
                ")->execute([$dealerId]);

                writeAuditLog(
                    'dealer.cipc_rejected',
                    'dealer',
                    $dealerId,
                    ['verification_status' => 'pending'],
                    ['verification_status' => 'rejected', 'reason' => $reason],
                    $adminId
                );

                sendDealerVerificationRejected([
                    'email'        => $dealer['email'],
                    'company_name' => $dealer['company_name'],
                ], $reason);

                $_SESSION['flash_ok'] = "Verification rejected.";
            }
        }
        redirect('/app/admin/users.php?tab=verifications');
    }

    // ── Approve org verification ──────────────────────────────
    if ($action === 'approve_org_cipc') {
        $orgId = (int) ($_POST['org_id'] ?? 0);
        if ($orgId > 0) {
            $pdo->prepare("
                UPDATE organizations
                SET verification_status='verified', verified_at=NOW(), updated_at=NOW()
                WHERE id=?
            ")->execute([$orgId]);
            writeAuditLog('org.cipc_approved', 'organization', $orgId,
                ['verification_status' => 'pending'],
                ['verification_status' => 'verified'],
                $adminId
            );
            $_SESSION['flash_ok'] = "Organisation verified.";
        }
        redirect('/app/admin/users.php?tab=verifications');
    }

    // ── Reject org verification ───────────────────────────────
    if ($action === 'reject_org_cipc') {
        $orgId  = (int) ($_POST['org_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($orgId > 0) {
            $pdo->prepare("
                UPDATE organizations
                SET verification_status='rejected', updated_at=NOW()
                WHERE id=?
            ")->execute([$orgId]);
            writeAuditLog('org.cipc_rejected', 'organization', $orgId,
                ['verification_status' => 'pending'],
                ['verification_status' => 'rejected', 'reason' => $reason],
                $adminId
            );
            $_SESSION['flash_ok'] = "Organisation verification rejected.";
        }
        redirect('/app/admin/users.php?tab=verifications');
    }
}

// ── GET: load data ────────────────────────────────────────────
$roleFilter   = $_GET['role']   ?? '';
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$users = getUsersForAdmin(
    $roleFilter   ?: null,
    $statusFilter ?: null,
    $search       ?: null,
    limit: 50,
    offset: 0
);

$pendingVerifications = getPendingVerifications();

$defaultCarLimit = getPlatformConfigInt('broker_car_limit_default', 10);

// ── Render ────────────────────────────────────────────────────
ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Users &amp; Verifications</h1>
  <span class="section-count"><?= count($users) ?> users</span>
  <?php if (count($pendingVerifications['dealers']) + count($pendingVerifications['orgs']) > 0): ?>
  <span class="section-count alert-count">
    <?= count($pendingVerifications['dealers']) + count($pendingVerifications['orgs']) ?> pending CIPC
  </span>
  <?php endif; ?>
</div>

<!-- Tab nav -->
<div style="display:flex;gap:4px;margin-bottom:1.5rem;border-bottom:1px solid var(--border);padding-bottom:0;">
  <a href="?tab=users"
     style="padding:8px 16px;font-size:13px;font-weight:<?= $tab === 'users' ? '600' : '400' ?>;
            color:<?= $tab === 'users' ? 'var(--p)' : 'var(--muted)' ?>;
            border-bottom:<?= $tab === 'users' ? '2px solid var(--p)' : 'none' ?>;
            text-decoration:none;margin-bottom:-1px;">
    Users
  </a>
  <a href="?tab=verifications"
     style="padding:8px 16px;font-size:13px;font-weight:<?= $tab === 'verifications' ? '600' : '400' ?>;
            color:<?= $tab === 'verifications' ? 'var(--p)' : 'var(--muted)' ?>;
            border-bottom:<?= $tab === 'verifications' ? '2px solid var(--p)' : 'none' ?>;
            text-decoration:none;margin-bottom:-1px;">
    CIPC Verifications
    <?php $vc = count($pendingVerifications['dealers']) + count($pendingVerifications['orgs']); ?>
    <?php if ($vc > 0): ?>
    <span style="background:var(--amb-bg);color:var(--amber);border:1px solid var(--amb-b);
                 border-radius:9999px;font-size:10px;font-family:var(--mono);
                 padding:1px 7px;margin-left:5px;"><?= $vc ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if ($tab === 'users'): ?>

<!-- ── User filter bar ── -->
<form method="GET" action="" style="display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap;">
  <input type="hidden" name="tab" value="users">
  <input class="finput" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Search email…" style="max-width:240px;">
  <select class="finput" name="role" style="max-width:140px;">
    <option value="">All roles</option>
    <?php foreach (['broker','dealer','sales_exec','admin'] as $r): ?>
    <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$r)) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="finput" name="status" style="max-width:140px;">
    <option value="">All statuses</option>
    <?php foreach (['pending','active','suspended'] as $s): ?>
    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
  <?php if ($search || $roleFilter || $statusFilter): ?>
  <a href="?tab=users" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>

<!-- ── Users table ── -->
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th>Verified</th>
        <th>Joined</th>
        <th>Car limit</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($users)): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--faint);padding:2rem;">No users found.</td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u): ?>
    <?php
      $displayName = $u['dealer_company']
          ?? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))
          ?: '—';
      $isSelf      = (int)$u['id'] === $adminId;
    ?>
    <tr>
      <td>
        <div style="font-weight:500;color:var(--text);font-size:13px;"><?= htmlspecialchars($u['email']) ?></div>
        <?php if ($displayName !== '—'): ?>
        <div style="font-size:11px;color:var(--faint);"><?= htmlspecialchars($displayName) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <span class="badge <?= match($u['role']) {
          'admin'      => 'badge-active',
          'broker'     => 'badge-new',
          'dealer'     => 'badge-pending',
          'sales_exec' => 'badge-suspended',
          default      => ''
        } ?>"><?= htmlspecialchars($u['role']) ?></span>
      </td>
      <td>
        <span class="badge badge-<?= $u['status'] ?>"><?= $u['status'] ?></span>
      </td>
      <td style="font-size:12px;color:<?= $u['email_verified'] ? 'var(--green)' : 'var(--faint)' ?>;">
        <?= $u['email_verified'] ? '✓ Yes' : '— No' ?>
      </td>
      <td style="font-size:12px;color:var(--faint);">
        <?= date('d M Y', strtotime($u['created_at'])) ?>
      </td>
      <td>
        <?php if ($u['role'] === 'broker'): ?>
        <!-- Car limit inline edit -->
        <form method="POST" style="display:flex;gap:4px;align-items:center;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="set_car_limit">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <input type="number" name="car_limit"
                 value="<?= (int)($u['car_limit'] ?? $defaultCarLimit) ?>"
                 min="1" max="200"
                 style="width:55px;padding:4px 6px;border:1px solid var(--border);
                        border-radius:6px;font-size:12px;font-family:var(--mono);">
          <button class="btn btn-ghost btn-sm" type="submit">Set</button>
        </form>
        <?php else: ?>
        <span style="color:var(--faint);font-size:12px;">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isSelf): ?>
        <span style="font-size:11px;color:var(--faint);">You</span>
        <?php elseif ($u['status'] === 'active'): ?>
          <?php if ($u['role'] === 'dealer'): ?>
          <!-- Dealer suspend: show modal for confirmation -->
          <button class="btn btn-warn btn-sm"
                  onclick="openSuspendDealerModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($displayName)) ?>')">
            Suspend
          </button>
          <?php else: ?>
          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="suspend_user">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button class="btn btn-warn btn-sm" type="submit"
                    onclick="return confirm('Suspend this user?')">Suspend</button>
          </form>
          <?php endif; ?>
        <?php elseif ($u['status'] === 'suspended'): ?>
          <?php if ($u['role'] === 'dealer'): ?>
          <!-- Dealer reinstate: modal shows cascade preview -->
          <button class="btn btn-info btn-sm"
                  onclick="openReinstateDealerModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($displayName)) ?>')">
            Reinstate
          </button>
          <?php else: ?>
          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="reactivate_user">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button class="btn btn-info btn-sm" type="submit"
                    onclick="return confirm('Reactivate this user?')">Reactivate</button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: /* tab=verifications */ ?>

<!-- ── Dealers pending CIPC ── -->
<h3 style="font-size:14px;font-weight:600;margin-bottom:.75rem;color:var(--text);">
  Dealer verifications
  <span class="section-count"><?= count($pendingVerifications['dealers']) ?></span>
</h3>

<?php if (empty($pendingVerifications['dealers'])): ?>
<div class="empty" style="margin-bottom:1.5rem;">
  <span class="empty-icon">✓</span>No pending dealer verifications.
</div>
<?php else: ?>
<div class="roster-wrap" style="margin-bottom:1.75rem;">
  <table class="roster">
    <thead>
      <tr>
        <th>Dealership</th>
        <th>Email</th>
        <th>Location</th>
        <th>Submitted</th>
        <th>Document</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingVerifications['dealers'] as $d): ?>
    <tr>
      <td style="font-weight:600;color:var(--text);"><?= htmlspecialchars($d['company_name']) ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars($d['email']) ?></td>
      <td style="font-size:12px;color:var(--muted);">
        <?= htmlspecialchars(implode(', ', array_filter([$d['city'], $d['province']]))) ?: '—' ?>
      </td>
      <td style="font-size:12px;color:var(--faint);">
        <?= date('d M Y', strtotime($d['submitted_at'])) ?>
      </td>
      <td>
        <?php if ($d['cipc_doc_url']): ?>
        <a href="<?= htmlspecialchars($d['cipc_doc_url']) ?>" target="_blank" rel="noopener"
           class="btn btn-ghost btn-sm">View PDF ↗</a>
        <?php else: ?>
        <span style="font-size:11px;color:var(--faint);">No document</span>
        <?php endif; ?>
      </td>
      <td style="display:flex;gap:6px;">
        <!-- Approve -->
        <form method="POST" style="display:inline;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="approve_dealer_cipc">
          <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
          <button class="btn btn-success btn-sm" type="submit"
                  onclick="return confirm('Approve and notify <?= htmlspecialchars(addslashes($d['company_name'])) ?>?')">
            Approve
          </button>
        </form>
        <!-- Reject -->
        <button class="btn btn-danger btn-sm"
                onclick="openRejectModal('dealer', <?= $d['id'] ?>, '<?= htmlspecialchars(addslashes($d['company_name'])) ?>')">
          Reject
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Orgs pending CIPC ── -->
<h3 style="font-size:14px;font-weight:600;margin-bottom:.75rem;color:var(--text);">
  Organisation verifications
  <span class="section-count"><?= count($pendingVerifications['orgs']) ?></span>
</h3>

<?php if (empty($pendingVerifications['orgs'])): ?>
<div class="empty">
  <span class="empty-icon">✓</span>No pending organisation verifications.
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Organisation</th>
        <th>Owner email</th>
        <th>CIPC number</th>
        <th>Location</th>
        <th>Submitted</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pendingVerifications['orgs'] as $o): ?>
    <tr>
      <td style="font-weight:600;color:var(--text);"><?= htmlspecialchars($o['name']) ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars($o['owner_email']) ?></td>
      <td style="font-size:12px;font-family:var(--mono);"><?= htmlspecialchars($o['cipc_number'] ?? '—') ?></td>
      <td style="font-size:12px;color:var(--muted);">
        <?= htmlspecialchars(implode(', ', array_filter([$o['city'], $o['province']]))) ?: '—' ?>
      </td>
      <td style="font-size:12px;color:var(--faint);"><?= date('d M Y', strtotime($o['submitted_at'])) ?></td>
      <td style="display:flex;gap:6px;">
        <form method="POST" style="display:inline;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="approve_org_cipc">
          <input type="hidden" name="org_id" value="<?= $o['id'] ?>">
          <button class="btn btn-success btn-sm" type="submit"
                  onclick="return confirm('Approve this organisation?')">Approve</button>
        </form>
        <button class="btn btn-danger btn-sm"
                onclick="openRejectModal('org', <?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['name'])) ?>')">
          Reject
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; // end tab switch ?>

<!-- ══════════════════════════════════
     Reinstate Dealer Modal (cascade preview)
     ══════════════════════════════════ -->
<div class="modal-bg" id="reinstateDealerModal">
  <div class="modal">
    <div class="modal-title" style="color:var(--green);">Reinstate Dealer</div>
    <p class="modal-sub" id="reinstateDealerSub"></p>
    <div class="alert alert-info" style="margin-bottom:1rem;">
      <span class="alert-icon">ℹ</span>
      <div>
        All <strong>paused</strong> listings will be restored to <strong>active</strong>.
        Frozen commissions will be <strong>unfrozen</strong>.
        Broker attribution is not affected.
      </div>
    </div>
    <form method="POST" id="reinstateDealerForm">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="reinstate_dealer">
      <input type="hidden" name="dealer_id" id="reinstateDealerIdInput">
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('reinstateDealerModal')">Cancel</button>
        <button type="submit" class="btn btn-success">Reinstate dealer</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════
     Suspend Dealer Modal (with cascade preview)
     ══════════════════════════════════ -->
<div class="modal-bg" id="suspendDealerModal">
  <div class="modal">
    <div class="modal-title" style="color:var(--amber);">Suspend Dealer</div>
    <p class="modal-sub" id="suspendDealerSub"></p>
    <div class="alert alert-warn" style="margin-bottom:1rem;">
      <span class="alert-icon">⚠</span>
      <div>
        All active listings will be <strong>paused</strong>.
        Pending commissions will be <strong>frozen</strong>.
        Broker attribution is never affected.
      </div>
    </div>
    <form method="POST" id="suspendDealerForm">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="suspend_dealer">
      <input type="hidden" name="dealer_id" id="suspendDealerIdInput">
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('suspendDealerModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Suspend dealer</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════
     Reject CIPC Modal
     ══════════════════════════════════ -->
<div class="modal-bg" id="rejectModal">
  <div class="modal">
    <div class="modal-title" style="color:var(--red);">Reject Verification</div>
    <p class="modal-sub" id="rejectModalSub">Provide a reason — this will be included in the email to the applicant.</p>
    <form method="POST" id="rejectModalForm">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" id="rejectActionInput">
      <input type="hidden" name="dealer_id" id="rejectDealerIdInput">
      <input type="hidden" name="org_id" id="rejectOrgIdInput">
      <div class="fgroup">
        <label class="flabel" for="rejectReasonInput">Reason</label>
        <textarea class="finput" id="rejectReasonInput" name="reason" rows="3"
                  placeholder="e.g. Document is illegible. Please re-upload a clear scan of your CIPC certificate."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Reject &amp; notify</button>
      </div>
    </form>
  </div>
</div>

<script>
function openSuspendDealerModal(dealerId, dealerName) {
  document.getElementById('suspendDealerIdInput').value = dealerId;
  document.getElementById('suspendDealerSub').textContent =
    'You are about to suspend ' + dealerName + '.';
  document.getElementById('suspendDealerModal').classList.add('open');
}

function openReinstateDealerModal(dealerId, dealerName) {
  document.getElementById('reinstateDealerIdInput').value = dealerId;
  document.getElementById('reinstateDealerSub').textContent =
    'You are about to reinstate ' + dealerName + '.';
  document.getElementById('reinstateDealerModal').classList.add('open');
}

function openRejectModal(entityType, entityId, entityName) {
  var isDealer = entityType === 'dealer';
  document.getElementById('rejectActionInput').value = isDealer ? 'reject_dealer_cipc' : 'reject_org_cipc';
  document.getElementById('rejectDealerIdInput').value = isDealer ? entityId : '';
  document.getElementById('rejectOrgIdInput').value    = isDealer ? '' : entityId;
  document.getElementById('rejectModalSub').textContent =
    'Rejecting verification for: ' + entityName;
  document.getElementById('rejectModal').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Close modals on backdrop click.
document.querySelectorAll('.modal-bg').forEach(function(bg) {
  bg.addEventListener('click', function(e) {
    if (e.target === bg) bg.classList.remove('open');
  });
});
</script>

<?php
$pageContent = ob_get_clean();

$pageTitle = 'Users & Verifications | Admin';
require_once '../../views/layout-app.php';