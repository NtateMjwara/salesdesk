<?php
/**
 * SalesDesk — Superadmin: Admins  (0014)
 * Route: /app/admin/admins
 *
 *   • Every admin account: role tier, status, portfolio size, last login.
 *   • Invite a new admin (step-up confirmed). They set their own password.
 *   • Unmanaged dealerships / orgs ("orphans") — nobody can see or approve
 *     anything for these until they're assigned. Assign in one click.
 *
 * One admin's portfolio, status and superadmin flag: admins-view.php.
 * Superadmins only.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';   // also loads superadmin.php

applyCachePolicy('auth');
requireSuperadmin();

$adminId   = (int) $_SESSION['user_id'];
$self      = '/app/admin/admins';
$old       = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'invite_admin') {
        sdRequireStepUp($self . '?invite=1');
        $old = $_POST;
        [$ok, $msg, $newId] = saInviteAdmin(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['first_name'] ?? ''),
            (string) ($_POST['last_name'] ?? ''),
            $adminId
        );
        if ($ok) {
            $_SESSION['flash_ok'] = $msg;
            redirect('/app/admin/admins-view?id=' . (int) $newId);
        }
        $formError = $msg;
    }

    if ($action === 'assign_orphan') {
        $type = ($_POST['type'] ?? '') === 'org' ? 'org' : 'dealer';
        [$ok, $msg] = saAssign($type, (int) ($_POST['entity_id'] ?? 0), (int) ($_POST['admin_user_id'] ?? 0), $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '#orphans');
    }

    if ($formError === '') {
        redirect($self);
    }
}

$admins        = saListAdmins();
$activeAdmins  = array_values(array_filter($admins, fn($a) => $a['status'] === 'active'));
$orphans       = saOrphans();
$orphanCount   = count($orphans['dealers']) + count($orphans['orgs']);
$superCount    = count(array_filter($admins, fn($a) => (int) $a['is_superadmin'] === 1 && $a['status'] === 'active'));
$openForm      = $formError !== '' || isset($_GET['invite']);
$schemaReady   = sdSuperadminSchemaReady();

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Admins</h1>
  <span class="section-count"><?= count($activeAdmins) ?> active · <?= $superCount ?> superadmin<?= $superCount === 1 ? '' : 's' ?></span>
  <?php if ($orphanCount > 0): ?>
  <a class="section-count alert-count adm-link" href="#orphans"><?= $orphanCount ?> unmanaged</a>
  <?php endif; ?>
  <button type="button" class="btn btn-primary btn-sm adm-head-action" data-modal-open="inviteAdminModal">
    <i class="fa-solid fa-user-plus"></i> Invite admin
  </button>
</div>

<?php if (!$schemaReady): ?>
<div class="alert alert-warn adm-gap">
  <span class="alert-icon">!</span>
  <div>
    <strong>Superadmin isn’t switched on yet.</strong> Every admin still has full access.
    Run <strong>0014 Superadmin</strong> in <a href="/app/admin/migrations">Migrations</a> to turn it on.
  </div>
</div>
<?php endif; ?>

<div class="roster-wrap adm-gap">
  <table class="roster">
    <thead>
      <tr>
        <th>Admin</th>
        <th>Access</th>
        <th>Status</th>
        <th>Dealerships</th>
        <th>Orgs</th>
        <th>Last sign-in</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($admins as $a): ?>
      <tr>
        <td>
          <a class="adm-strong" href="/app/admin/admins-view?id=<?= (int) $a['id'] ?>"><?= htmlspecialchars(saAdminLabel($a)) ?></a>
          <?= (int) $a['id'] === $adminId ? '<span class="adm-sub">(you)</span>' : '' ?>
          <div class="adm-sub"><?= htmlspecialchars($a['email']) ?></div>
        </td>
        <td>
          <?php if ((int) $a['is_superadmin'] === 1): ?>
          <span class="badge badge-superadmin"><i class="fa-solid fa-shield-halved"></i> Superadmin</span>
          <?php else: ?>
          <span class="adm-muted">Admin</span>
          <?php endif; ?>
        </td>
        <td><?= adminStatusBadge($a['status']) ?></td>
        <td class="adm-mono"><?= (int) $a['dealer_count'] ?></td>
        <td class="adm-mono"><?= (int) $a['org_count'] ?></td>
        <td class="adm-muted"><?= $a['last_login'] ? date('d M Y', strtotime($a['last_login'])) : 'Never' ?></td>
        <td><a class="btn btn-ghost btn-sm" href="/app/admin/admins-view?id=<?= (int) $a['id'] ?>">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ══ Unmanaged dealerships & orgs ══ -->
<h2 class="adm-h2" id="orphans">Unmanaged <span class="section-count"><?= $orphanCount ?></span></h2>
<p class="adm-muted adm-gap">
  No active admin manages these, so their sales exec / agent requests and CIPC reviews only reach
  superadmins. Includes self-registered dealerships and anything whose admins were suspended.
</p>

<?php if ($orphanCount === 0): ?>
<div class="empty adm-gap"><span class="empty-icon">✓</span>Every dealership and organisation has an active admin.</div>
<?php else: ?>
<div class="roster-wrap adm-gap">
  <table class="roster">
    <thead>
      <tr><th>Name</th><th>Type</th><th>Location</th><th>Status</th><th>Assign to</th></tr>
    </thead>
    <tbody>
    <?php foreach (['dealer' => $orphans['dealers'], 'org' => $orphans['orgs']] as $type => $rows): ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <a class="adm-strong" href="<?= $type === 'dealer' ? '/app/admin/dealerships-view?id=' : '/app/admin/desk-orgs-view?id=' ?><?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a>
        </td>
        <td class="adm-muted"><?= $type === 'dealer' ? 'Dealership' . (!empty($r['user_id']) ? ' · self-registered' : '') : 'Desk org' ?></td>
        <td class="adm-muted"><?= htmlspecialchars(implode(', ', array_filter([$r['city'], $r['province']])) ?: '—') ?></td>
        <td><?= adminStatusBadge($r['is_active'] ? 'active' : 'inactive') ?></td>
        <td>
          <form method="POST" class="adm-inline">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="assign_orphan">
            <input type="hidden" name="type" value="<?= $type ?>">
            <input type="hidden" name="entity_id" value="<?= (int) $r['id'] ?>">
            <label class="sr-only" for="orphan-<?= $type ?>-<?= (int) $r['id'] ?>">Admin</label>
            <select class="finput adm-select-sm" id="orphan-<?= $type ?>-<?= (int) $r['id'] ?>" name="admin_user_id" required>
              <option value="">Choose admin…</option>
              <?php foreach ($activeAdmins as $a): ?>
              <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars(saAdminLabel($a)) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit">Assign</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══ Invite admin modal ══ -->
<div class="modal-bg<?= $openForm ? ' open' : '' ?>" id="inviteAdminModal">
  <div class="modal adm-modal-wide">
    <div class="modal-title">Invite an admin</div>
    <p class="modal-sub">
      Creates an admin account and emails them a link to set their own password. They start with no
      dealerships or orgs — assign some on the next screen. Use an email that has no broker or dealer account.
    </p>

    <?php if ($formError): ?>
    <div class="alert alert-error"><span class="alert-icon">!</span><div><?= htmlspecialchars($formError) ?></div></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="invite_admin">
      <div class="adm-grid-2">
        <div class="fgroup">
          <label class="flabel" for="ia_first">First name</label>
          <input class="finput" id="ia_first" name="first_name" required maxlength="60" value="<?= htmlspecialchars($old['first_name'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="ia_last">Last name <span class="flabel-opt">optional</span></label>
          <input class="finput" id="ia_last" name="last_name" maxlength="60" value="<?= htmlspecialchars($old['last_name'] ?? '') ?>">
        </div>
      </div>
      <div class="fgroup">
        <label class="flabel" for="ia_email">Email</label>
        <input class="finput" id="ia_email" name="email" type="email" required value="<?= htmlspecialchars($old['email'] ?? '') ?>" placeholder="name@salesdesk.co.za">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create admin &amp; send invite</button>
      </div>
    </form>
  </div>
</div>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Admins | Superadmin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
