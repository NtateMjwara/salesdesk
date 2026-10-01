<?php
/**
 * Admin partial — co-managing admins panel.  (0012)
 *
 * Expects:
 *   array  $managers            rows from adminListManagers()
 *   int    $adminId             current admin
 *   string $managerEntityField  hidden field name ('dealer_id' | 'org_id')
 *   int    $managerEntityId
 *
 * Posts action=add_manager (email) / remove_manager (admin_user_id).
 * 0014: only superadmins get the add / remove controls — admins see
 * the list read-only (assignment is done by a superadmin).
 * 0015: on dealerships each manager shows View / Operate; superadmins can
 * switch it (Operate only where there's no principal). Optional input:
 *   bool $managerHasPrincipal   dealership has its own principal
 */
$canManageManagers = isSuperadmin($adminId);
$isDealerPanel     = ($managerEntityField === 'dealer_id');
$managerHasPrincipal = $managerHasPrincipal ?? false;
?>
<div class="card adm-card">
  <div class="card-body">
    <h2 class="adm-h2">Managing admins <span class="section-count"><?= count($managers) ?></span></h2>
    <p class="adm-muted">Every admin listed here can see this, edit it and act on its requests. There's always at least one.<?= $canManageManagers ? '' : ' Only a superadmin can change who manages it.' ?></p>
    <?php if ($isDealerPanel): ?>
    <p class="adm-muted"><?= $managerHasPrincipal
        ? 'This dealership has its own principal, so its admins can open the dealer portal read-only.'
        : '<strong>Operate</strong> lets an admin run this dealership in the dealer portal (cars, imports, leads, team). <strong>View</strong> is read-only.' ?></p>
    <?php endif; ?>

    <ul class="adm-list">
    <?php foreach ($managers as $m):
      $mName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')); ?>
      <li class="adm-list-row">
        <div>
          <div class="adm-strong"><?= htmlspecialchars($mName ?: $m['email']) ?><?= (int) $m['admin_user_id'] === $adminId ? ' <span class="adm-sub">(you)</span>' : '' ?></div>
          <div class="adm-sub"><?= htmlspecialchars($m['email']) ?> · since <?= date('d M Y', strtotime($m['created_at'])) ?><?= ($m['status'] ?? 'active') !== 'active' ? ' · suspended' : '' ?></div>
          <?php if ($isDealerPanel): ?>
          <div class="adm-inline">
            <?= sdWorkspaceAccessBadge(($m['access'] ?? 'view') === 'operate' && !$managerHasPrincipal ? 'operator' : 'viewer') ?>
            <?php if ($canManageManagers && !$managerHasPrincipal): ?>
            <form method="POST" class="adm-access-form">
              <?= csrf_hidden_field() ?>
              <input type="hidden" name="<?= htmlspecialchars($managerEntityField) ?>" value="<?= (int) $managerEntityId ?>">
              <input type="hidden" name="action" value="set_access">
              <input type="hidden" name="admin_user_id" value="<?= (int) $m['admin_user_id'] ?>">
              <input type="hidden" name="access" value="<?= ($m['access'] ?? 'view') === 'operate' ? 'view' : 'operate' ?>">
              <button class="btn btn-ghost btn-sm" type="submit"><?= ($m['access'] ?? 'view') === 'operate' ? 'Make view-only' : 'Allow to operate' ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($canManageManagers && count($managers) > 1): ?>
        <form method="POST" data-confirm="<?= (int) $m['admin_user_id'] === $adminId ? 'Stop managing this? You will lose access to it.' : 'Remove this admin as a manager?' ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="<?= htmlspecialchars($managerEntityField) ?>" value="<?= (int) $managerEntityId ?>">
          <input type="hidden" name="action" value="remove_manager">
          <input type="hidden" name="admin_user_id" value="<?= (int) $m['admin_user_id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $m['admin_user_id'] === $adminId ? 'Leave' : 'Remove' ?></button>
        </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>

    <?php if ($canManageManagers): ?>
    <form method="POST" class="adm-inline-form">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="<?= htmlspecialchars($managerEntityField) ?>" value="<?= (int) $managerEntityId ?>">
      <input type="hidden" name="action" value="add_manager">
      <label class="flabel" for="addManagerEmail">Add another admin</label>
      <div class="adm-inline">
        <input class="finput" id="addManagerEmail" type="email" name="email" required placeholder="admin@salesdesk.co.za">
        <button class="btn btn-primary btn-sm" type="submit">Add</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
