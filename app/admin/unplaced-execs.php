<?php
/**
 * SalesDesk — Admin: Unplaced sales execs  (0018, dealer workspace Phase 4)
 * Route: /app/admin/unplaced-execs
 *
 *   • Sales execs with no dealership: skipped choosing one at signup, or
 *     their request was declined. Search by name / email, filter by province.
 *   • Invite one to a dealership you can run (Operate, or delegated by its
 *     principal). The exec accepts or declines — nobody is placed without
 *     saying yes.
 *   • Invitations you've sent (superadmins: everyone's), with Withdraw.
 *
 * Every admin can see the list; only admins who can run a dealership can
 * invite. See includes/exec_placement.php.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';   // superadmin + dealer workspace helpers

applyCachePolicy('auth');
requireRole('admin');

$adminId = (int) $_SESSION['user_id'];
$self    = '/app/admin/unplaced-execs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    if ($action === 'invite') {
        [$ok, $msg] = sdInviteExec((int) ($_POST['exec_user_id'] ?? 0), (int) ($_POST['dealer_id'] ?? 0),
            $adminId, (string) ($_POST['message'] ?? ''));
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    } elseif ($action === 'cancel') {
        [$ok, $msg] = sdCancelExecInvitation((int) ($_POST['invitation_id'] ?? 0), $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    }
    redirect($self . (!empty($_POST['back']) ? '?' . preg_replace('/[^a-zA-Z0-9=&%+_.-]/', '', (string) $_POST['back']) : ''));
}

$q        = trim($_GET['q'] ?? '');
$province = in_array($_GET['province'] ?? '', SD_PROVINCES, true) ? $_GET['province'] : '';
$execs    = sdUnplacedExecs(['q' => $q, 'province' => $province]);
$targets  = sdInviteDealerships($adminId);
$sent     = sdSentExecInvitations($adminId, 30);
$ready    = sdExecPlacementReady();
$backQs   = http_build_query(array_filter(['q' => $q, 'province' => $province]));

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Unplaced sales execs</h1>
  <span class="section-count"><?= count($execs) ?> without a dealership</span>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warn adm-gap"><span class="alert-icon">!</span>
  <div>Run <strong>0018 Unplaced sales execs</strong> in <a href="/app/admin/migrations">Migrations</a> to send invitations.</div>
</div>
<?php endif; ?>

<p class="adm-muted adm-gap">
  These sales execs signed up but aren’t working at a dealership — they skipped choosing one, or their request
  was declined. Invite one to a dealership you run: they get an email and decide. If they accept they’re verified
  straight away.
  <?php if (!$targets): ?><br><strong>You can’t send invitations yet</strong> — you need <em>Operate</em> on a
  dealership SalesDesk runs, or a principal’s delegated access.<?php endif; ?>
</p>

<form method="GET" class="adm-filter">
  <input class="finput" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name or email…">
  <select class="finput adm-select-sm" name="province" data-autosubmit>
    <option value="">All provinces</option>
    <?php foreach (SD_PROVINCES as $prov): ?>
    <option value="<?= $prov ?>" <?= $province === $prov ? 'selected' : '' ?>><?= $prov ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Search</button>
  <?php if ($q !== '' || $province !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= $self ?>">Clear</a><?php endif; ?>
</form>

<?php if (!$execs): ?>
<div class="empty adm-gap"><span class="empty-icon">✓</span>
  <?= $q !== '' || $province !== '' ? 'No unplaced sales execs match that search.' : 'Every sales exec is placed at a dealership.' ?>
</div>
<?php else: ?>
<div class="roster-wrap adm-gap">
  <table class="roster">
    <thead>
      <tr><th>Sales exec</th><th>Location</th><th>Why unplaced</th><th>Signed up</th><th><?= $targets ? 'Invite to' : '' ?></th></tr>
    </thead>
    <tbody>
    <?php foreach ($execs as $x):
      $name = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? '')) ?: $x['email']; ?>
      <tr>
        <td>
          <div class="adm-strong"><?= htmlspecialchars($name) ?></div>
          <div class="adm-sub"><?= htmlspecialchars($x['email']) ?><?= $x['phone'] ? ' · ' . htmlspecialchars($x['phone']) : '' ?></div>
          <?php if ((int) $x['open_invites'] > 0): ?>
          <span class="badge badge-pending"><?= (int) $x['open_invites'] ?> open invitation<?= (int) $x['open_invites'] === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </td>
        <td class="adm-muted"><?= htmlspecialchars(implode(', ', array_filter([$x['city'], $x['province']])) ?: '—') ?></td>
        <td class="adm-muted">
          <?php if ($x['verification_status'] === 'rejected'): ?>
          Declined by <?= htmlspecialchars($x['declined_by'] ?? 'a dealership') ?>
          <?php if ($x['rejection_reason']): ?><div class="adm-sub">“<?= htmlspecialchars($x['rejection_reason']) ?>”</div><?php endif; ?>
          <?php else: ?>
          Skipped choosing a dealership
          <?php endif; ?>
        </td>
        <td class="adm-muted"><?= date('d M Y', strtotime($x['created_at'])) ?></td>
        <td>
          <?php if ($targets && $ready): ?>
          <form method="POST" class="adm-invite-form">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="invite">
            <input type="hidden" name="exec_user_id" value="<?= (int) $x['user_id'] ?>">
            <input type="hidden" name="back" value="<?= htmlspecialchars($backQs) ?>">
            <label class="sr-only" for="inv-d-<?= (int) $x['user_id'] ?>">Dealership</label>
            <select class="finput adm-select-sm" id="inv-d-<?= (int) $x['user_id'] ?>" name="dealer_id" required>
              <?php if (count($targets) > 1): ?><option value="">Choose…</option><?php endif; ?>
              <?php foreach ($targets as $t): ?>
              <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['company_name']) ?><?= $t['mode'] === 'delegate' ? ' (delegated)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <label class="sr-only" for="inv-m-<?= (int) $x['user_id'] ?>">Message</label>
            <input class="finput" id="inv-m-<?= (int) $x['user_id'] ?>" name="message" maxlength="255" placeholder="Short note (optional)">
            <button class="btn btn-primary btn-sm" type="submit">Invite</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($sent): ?>
<h2 class="adm-h2 adm-section-gap"><?= isSuperadmin($adminId) ? 'Invitations' : 'Invitations you’ve sent' ?></h2>
<div class="roster-wrap">
  <table class="roster">
    <thead><tr><th>Sales exec</th><th>Dealership</th><th>Status</th><th>Sent</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sent as $s):
      $status = $s['is_expired'] ? 'expired' : $s['status']; ?>
      <tr>
        <td>
          <div class="adm-strong"><?= htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: $s['exec_email']) ?></div>
          <div class="adm-sub"><?= htmlspecialchars($s['exec_email']) ?></div>
        </td>
        <td class="adm-muted"><?= htmlspecialchars($s['company_name']) ?>
          <?php if (isSuperadmin($adminId)): ?><div class="adm-sub">by <?= htmlspecialchars(trim(($s['inviter_first'] ?? '') . ' ' . ($s['inviter_last'] ?? '')) ?: '—') ?></div><?php endif; ?>
        </td>
        <td><?php [$bCls, $bTxt] = match ($status) {
              'accepted'  => ['badge-verified',  'Accepted'],
              'declined'  => ['badge-rejected',  'Declined'],
              'cancelled' => ['badge-suspended', 'Withdrawn'],
              'expired'   => ['badge-suspended', 'Expired'],
              default     => ['badge-pending',   'Waiting'],
            }; ?><span class="badge <?= $bCls ?>"><?= $bTxt ?></span></td>
        <td class="adm-muted"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
        <td>
          <?php if ($status === 'pending'): ?>
          <form method="POST" data-confirm="Withdraw this invitation?">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="invitation_id" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Withdraw</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Unplaced sales execs | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
