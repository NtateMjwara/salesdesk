<?php
/**
 * SalesDesk — Superadmin: one admin  (0014)
 * Route: /app/admin/admins-view?id={user_id}[&tab=portfolio|account]
 *
 *   portfolio — the dealerships and desk orgs this admin manages:
 *               assign more, remove one (never leaving it unmanaged),
 *               or hand over / share the WHOLE portfolio with another admin.
 *   account   — suspend / reinstate, grant / revoke superadmin, remove.
 *               These need a fresh step-up code (app/admin/confirm).
 *
 * Guardrails live in includes/superadmin.php (last superadmin, no
 * self-demotion / self-suspension, only-manager checks). Superadmins only.
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

$adminId  = (int) $_SESSION['user_id'];
$targetId = (int) ($_GET['id'] ?? $_POST['target_id'] ?? 0);
$tab      = in_array($_GET['tab'] ?? '', ['portfolio', 'account'], true) ? $_GET['tab'] : 'portfolio';

$target = $targetId ? saGetAdmin($targetId) : false;
if (!$target) {
    $_SESSION['flash_error'] = 'That admin was not found.';
    redirect('/app/admin/admins');
}
$self = '/app/admin/admins-view?id=' . $targetId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // ── Portfolio (no step-up: reversible, audited) ────────────
    if ($action === 'assign') {
        $type = ($_POST['type'] ?? '') === 'org' ? 'org' : 'dealer';
        [$ok, $msg] = saAssign($type, (int) ($_POST['entity_id'] ?? 0), $targetId, $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=portfolio');
    }
    if ($action === 'unassign') {
        $type = ($_POST['type'] ?? '') === 'org' ? 'org' : 'dealer';
        [$ok, $msg] = saUnassign($type, (int) ($_POST['entity_id'] ?? 0), $targetId, $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=portfolio');
    }
    if ($action === 'set_access') {   // 0015: View / Operate on one dealership
        [$ok, $msg] = saSetDealerAccess((int) ($_POST['entity_id'] ?? 0), $targetId, (string) ($_POST['access'] ?? ''), $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=portfolio');
    }
    if ($action === 'transfer') {
        [$ok, $msg] = saTransferPortfolio($targetId, (int) ($_POST['to_admin_id'] ?? 0), $adminId,
            ($_POST['mode'] ?? 'handover') === 'share');
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=portfolio');
    }

    // ── Account (step-up) ──────────────────────────────────────
    if (in_array($action, ['suspend', 'reinstate', 'grant_super', 'revoke_super', 'remove'], true)) {
        sdRequireStepUp($self . '&tab=account');
        [$ok, $msg] = match ($action) {
            'suspend'      => saSetAdminStatus($targetId, 'suspended', $adminId),
            'reinstate'    => saSetAdminStatus($targetId, 'active', $adminId),
            'grant_super'  => saSetSuperadmin($targetId, true, $adminId),
            'revoke_super' => saSetSuperadmin($targetId, false, $adminId),
            'remove'       => saRemoveAdmin($targetId, ((int) ($_POST['to_admin_id'] ?? 0)) ?: null, $adminId),
        };
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=account');
    }

    redirect($self);
}

$isSelf       = $targetId === $adminId;
$isSuper      = (int) $target['is_superadmin'] === 1;
$isActive     = $target['status'] === 'active';
$portfolio    = saPortfolio($targetId);
$counts       = ['dealers' => count($portfolio['dealers']), 'orgs' => count($portfolio['orgs'])];
$assignable   = saAssignable($targetId);
$otherAdmins  = array_values(array_filter(saListAdmins(), fn($a) => $a['status'] === 'active' && (int) $a['id'] !== $targetId));
$soleCount    = count(array_filter($portfolio['dealers'], fn($r) => (int) $r['other_managers'] === 0))
              + count(array_filter($portfolio['orgs'], fn($r) => (int) $r['other_managers'] === 0));
$lastSuper    = $isSuper && $isActive && saActiveSuperadminCount() <= 1;

ob_start();
?>
<a class="adm-back" href="/app/admin/admins"><i class="fa-solid fa-arrow-left"></i> All admins</a>

<div class="section-head">
  <h1 class="section-title"><?= htmlspecialchars(saAdminLabel($target)) ?></h1>
  <?php if ($isSuper): ?><span class="badge badge-superadmin"><i class="fa-solid fa-shield-halved"></i> Superadmin</span><?php endif; ?>
  <?= adminStatusBadge($target['status']) ?>
  <span class="section-count"><?= htmlspecialchars($target['email']) ?><?= $isSelf ? ' · you' : '' ?></span>
</div>

<div class="adm-stats">
  <div class="adm-stat"><span class="adm-stat-n"><?= $counts['dealers'] ?></span><span class="adm-stat-l">Dealerships</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= $counts['orgs'] ?></span><span class="adm-stat-l">Desk orgs</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= $soleCount ?></span><span class="adm-stat-l">Only manager of</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= $target['last_login'] ? date('d M', strtotime($target['last_login'])) : '—' ?></span><span class="adm-stat-l">Last sign-in</span></div>
</div>

<nav class="adm-tabs" aria-label="Admin sections">
  <?php foreach (['portfolio' => 'Portfolio', 'account' => 'Account'] as $key => $label): ?>
  <a href="<?= $self ?>&amp;tab=<?= $key ?>" class="adm-tab<?= $tab === $key ? ' is-active' : '' ?>"
     <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'portfolio'): ?>

  <?php if (!$isActive): ?>
  <div class="alert alert-warn adm-gap"><span class="alert-icon">!</span>
    <div>This admin is suspended, so nothing below reaches them. Anything they manage alone shows as <a href="/app/admin/admins#orphans">unmanaged</a> — hand it over below.</div>
  </div>
  <?php endif; ?>

  <?php foreach (['dealer' => ['Dealerships', $portfolio['dealers'], $assignable['dealers'], '/app/admin/dealerships-view?id='],
                  'org'    => ['Desk organisations', $portfolio['orgs'], $assignable['orgs'], '/app/admin/desk-orgs-view?id=']] as $type => [$heading, $rows, $options, $link]): ?>
  <div class="card adm-card adm-card-wide">
    <div class="card-body">
      <h2 class="adm-h2"><?= $heading ?> <span class="section-count"><?= count($rows) ?></span></h2>

      <?php if (!$rows): ?>
      <p class="adm-muted adm-gap">None yet.</p>
      <?php else: ?>
      <ul class="adm-list">
        <?php foreach ($rows as $r): $sole = (int) $r['other_managers'] === 0; ?>
        <li class="adm-list-row">
          <div>
            <a class="adm-strong" href="<?= $link . (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a>
            <?php if ($sole): ?><span class="badge badge-pending">Only manager</span><?php endif; ?>
            <?php if ($type === 'dealer'): ?>
            <?= sdWorkspaceAccessBadge(($r['access'] ?? 'view') === 'operate' && $r['principal_user_id'] === null ? 'operator' : 'viewer') ?>
            <?php endif; ?>
            <?= $r['is_active'] ? '' : adminStatusBadge('inactive') ?>
            <div class="adm-sub"><?= htmlspecialchars(implode(', ', array_filter([$r['city'], $r['province']])) ?: '—') ?>
              · <?= (int) $r['other_managers'] ?> other active manager<?= (int) $r['other_managers'] === 1 ? '' : 's' ?></div>
          </div>
          <div class="adm-actions">
          <?php if ($type === 'dealer' && $r['principal_user_id'] === null): ?>
          <form method="POST">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="set_access">
            <input type="hidden" name="entity_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="access" value="<?= ($r['access'] ?? 'view') === 'operate' ? 'view' : 'operate' ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= ($r['access'] ?? 'view') === 'operate' ? 'Make view-only' : 'Allow to operate' ?></button>
          </form>
          <?php endif; ?>
          <?php if (!$sole): ?>
          <form method="POST" data-confirm="Remove <?= htmlspecialchars($r['name'], ENT_QUOTES) ?> from <?= htmlspecialchars(saAdminLabel($target), ENT_QUOTES) ?>’s portfolio?">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="unassign">
            <input type="hidden" name="type" value="<?= $type ?>">
            <input type="hidden" name="entity_id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
          </form>
          <?php else: ?>
          <span class="adm-sub" title="Assign another admin first so it isn't left unmanaged">Can’t remove</span>
          <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if ($isActive && $options): ?>
      <form method="POST" class="adm-inline-form">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="type" value="<?= $type ?>">
        <label class="flabel" for="assign-<?= $type ?>">Assign <?= $type === 'dealer' ? 'a dealership' : 'an organisation' ?></label>
        <div class="adm-inline">
          <select class="finput" id="assign-<?= $type ?>" name="entity_id" required>
            <option value="">Choose…</option>
            <?php foreach ($options as $o): ?>
            <option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['name'] . ($o['city'] ? ' — ' . $o['city'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" type="submit">Assign</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($counts['dealers'] + $counts['orgs'] > 0 && $otherAdmins): ?>
  <div class="card adm-card adm-card-wide">
    <div class="card-body">
      <h2 class="adm-h2">Move the whole portfolio</h2>
      <p class="adm-muted adm-gap">
        Hand everything over when someone leaves or changes role, or share it while they’re away.
      </p>
      <form method="POST" class="adm-inline-form" data-confirm="Move this admin’s whole portfolio?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="transfer">
        <div class="adm-checks">
          <label><input type="radio" name="mode" value="handover" checked> Hand over — <?= htmlspecialchars(saAdminLabel($target)) ?> stops managing them</label>
          <label><input type="radio" name="mode" value="share"> Share — both manage them</label>
        </div>
        <label class="flabel" for="transferTo">To</label>
        <div class="adm-inline">
          <select class="finput" id="transferTo" name="to_admin_id" required>
            <option value="">Choose admin…</option>
            <?php foreach ($otherAdmins as $a): ?>
            <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars(saAdminLabel($a)) ?> (<?= (int) $a['dealer_count'] ?> / <?= (int) $a['org_count'] ?>)</option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" type="submit">Move portfolio</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php else: /* account */ ?>

  <p class="adm-muted adm-gap">
    Changes here ask you to confirm with an emailed code first. Everything is recorded in the audit log.
  </p>

  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Superadmin access</h2>
      <p class="adm-muted adm-gap">
        Superadmins manage admins and assignments, see every dealership and organisation, approve payouts,
        and use the dashboard, audit log, database and migrations.
      </p>
      <?php if ($isSuper): ?>
        <?php if ($isSelf): ?>
        <p class="adm-sub">You can’t remove your own superadmin access — another superadmin has to.</p>
        <?php elseif ($lastSuper): ?>
        <p class="adm-sub">This is the last active superadmin, so it can’t be removed.</p>
        <?php else: ?>
        <form method="POST" data-confirm="Remove superadmin access from <?= htmlspecialchars(saAdminLabel($target), ENT_QUOTES) ?>?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="revoke_super">
          <button class="btn btn-ghost btn-sm" type="submit">Make regular admin</button>
        </form>
        <?php endif; ?>
      <?php elseif ($isActive): ?>
      <form method="POST" data-confirm="Make <?= htmlspecialchars(saAdminLabel($target), ENT_QUOTES) ?> a superadmin? They will have full control, including over you.">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="grant_super">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-shield-halved"></i> Make superadmin</button>
      </form>
      <?php else: ?>
      <p class="adm-sub">Reinstate this admin first.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$isSelf): ?>
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2"><?= $isActive ? 'Suspend' : 'Reinstate' ?></h2>
      <?php if ($isActive): ?>
      <p class="adm-muted adm-gap">
        Signs them out immediately and blocks access. Their portfolio stays assigned, so reinstating restores
        everything. <?= $soleCount ? "They're the only manager of {$soleCount} — those become unmanaged while they're suspended." : '' ?>
      </p>
      <?php if ($lastSuper): ?>
      <p class="adm-sub">This is the last active superadmin, so it can’t be suspended.</p>
      <?php else: ?>
      <form method="POST" data-confirm="Suspend <?= htmlspecialchars(saAdminLabel($target), ENT_QUOTES) ?>?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="suspend">
        <button class="btn btn-danger btn-sm" type="submit">Suspend admin</button>
      </form>
      <?php endif; ?>
      <?php else: ?>
      <p class="adm-muted adm-gap">Restores their access and their existing portfolio.</p>
      <form method="POST">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="reinstate">
        <button class="btn btn-primary btn-sm" type="submit">Reinstate admin</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card adm-card adm-danger">
    <div class="card-body">
      <h2 class="adm-h2">Remove admin</h2>
      <p class="adm-muted adm-gap">
        For someone who’s leaving: their portfolio is handed to the admin you choose, then the account is
        suspended and loses superadmin access. The account itself is kept so the audit log stays complete.
      </p>
      <?php if ($lastSuper): ?>
      <p class="adm-sub">This is the last active superadmin, so it can’t be removed.</p>
      <?php else: ?>
      <form method="POST" class="adm-inline-form" data-confirm="Remove <?= htmlspecialchars(saAdminLabel($target), ENT_QUOTES) ?> as an admin?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="remove">
        <?php if ($counts['dealers'] + $counts['orgs'] > 0): ?>
        <label class="flabel" for="removeTo">Hand their <?= $counts['dealers'] + $counts['orgs'] ?> dealership(s) / org(s) to</label>
        <div class="adm-inline">
          <select class="finput" id="removeTo" name="to_admin_id" required>
            <option value="">Choose admin…</option>
            <?php foreach ($otherAdmins as $a): ?>
            <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars(saAdminLabel($a)) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-danger btn-sm" type="submit">Remove admin</button>
        </div>
        <?php else: ?>
        <button class="btn btn-danger btn-sm" type="submit">Remove admin</button>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>
  <p class="adm-sub">You can’t suspend or remove your own account.</p>
  <?php endif; ?>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = saAdminLabel($target) . ' | Superadmin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
