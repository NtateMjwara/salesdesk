<?php
/**
 * SalesDesk — Broker: choose which cars stay live  (PLANS-3b, 0023)
 * Route: /app/broker/keep-cars
 *
 * When a broker's desk is over its car limit after Pro ends (or a
 * superadmin lowers the limit), a 7-day window opens. Here the broker
 * ticks the cars to keep live — preselected with the default choice
 * (most leads first, then newest) — and either:
 *   - "Save choice": kept on the window; applied when it ends, or
 *   - "Pause the rest now": applied straight away.
 * Paused cars are parked (broker_inventory_parked), never deleted, and
 * come back automatically after an upgrade.
 *
 * Logic: includes/plan_grace.php. Styles: assets/css/plans.css.
 * Script: assets/js/keep-cars.js (live "n of limit" counter).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/org_membership.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/plan_grace.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$userId = (int) $_SESSION['user_id'];
$self   = '/app/broker/keep-cars';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $keep     = array_map('intval', (array) ($_POST['keep'] ?? []));
    $applyNow = ($_POST['action'] ?? '') === 'apply';
    [$ok, $msg] = sdGraceSaveKeep($userId, $keep, $applyNow);
    $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    redirect($ok && $applyNow ? '/app/broker/inventory.php?tab=desk' : $self);
}

$pdo  = Database::getInstance();
$desk = $pdo->prepare("SELECT id, display_name FROM salesdesks WHERE user_id = ? LIMIT 1");
$desk->execute([$userId]);
$desk = $desk->fetch();
if (!$desk) {
    redirect('/app/broker/dashboard.php');
}

$graceState = sdBrokerDeskState($userId);
$grace      = $graceState['grace'];
$limit      = $graceState['limit'];
$cars       = sdDeskCarsRanked($userId);
$over       = $limit !== null && count($cars) > $limit;

// Preselect: the broker's saved choice, topped up with the default ranking.
$saved   = $grace ? array_map('intval', json_decode((string) ($grace['keep_ids'] ?? ''), true) ?: []) : [];
$ranked  = array_map('intval', array_column($cars, 'inv_id'));
$chosen  = array_values(array_intersect($saved, $ranked));
$keepSet = $limit === null ? $ranked : array_slice(array_values(array_unique(array_merge($chosen, $ranked))), 0, $limit);
$keepSet = array_flip($keepSet);

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Choose which cars stay live</h1>
</div>

<?php require __DIR__ . '/../../views/partials/grace-banner.php'; ?>

<?php if (!$over): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check alert-icon"></i>
  <div>Your desk is within its plan limit — every car stays live. <a href="/app/broker/inventory.php?tab=desk">Back to My Desk</a></div>
</div>
<?php else: ?>
<form method="POST" class="keep-form" data-keep-form data-limit="<?= (int) $limit ?>">
  <?= csrf_hidden_field() ?>

  <div class="keep-bar">
    <p class="keep-bar__text">
      Tick up to <strong><?= (int) $limit ?></strong> cars to keep live. We've ticked the ones with the most leads, then the newest.
      The rest are paused — hidden from your desk and links, never deleted.
    </p>
    <p class="keep-bar__count" aria-live="polite">
      <span data-keep-count><?= count($keepSet) ?></span> of <?= (int) $limit ?> chosen
    </p>
  </div>

  <ul class="keep-list">
    <?php foreach ($cars as $car):
      $imgs = json_decode($car['image_urls'] ?? '[]', true) ?: [];
      $img  = $imgs[0] ?? null;
      $id   = (int) $car['inv_id'];
    ?>
    <li class="keep-row">
      <label class="keep-row__label">
        <input type="checkbox" name="keep[]" value="<?= $id ?>" class="keep-row__check"<?= isset($keepSet[$id]) ? ' checked' : '' ?>>
        <span class="keep-row__thumb">
          <?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-car-side"></i><?php endif; ?>
        </span>
        <span class="keep-row__info">
          <span class="keep-row__name"><?= htmlspecialchars($car['year'] . ' ' . $car['make'] . ' ' . $car['model']) ?></span>
          <span class="keep-row__meta">R <?= number_format((float) $car['price'], 0, '.', ',') ?>
            · <?= (int) $car['views'] ?> views · <?= (int) $car['leads'] ?> leads
            · added <?= htmlspecialchars(date('j M Y', strtotime((string) $car['added_at']))) ?></span>
        </span>
      </label>
    </li>
    <?php endforeach; ?>
  </ul>

  <p class="keep-error" data-keep-error hidden>You've chosen more than <?= (int) $limit ?> cars — untick some first.</p>

  <div class="keep-actions">
    <?php if ($grace): ?>
    <button class="btn btn-primary" type="submit" name="action" value="save" data-keep-submit>Save choice</button>
    <?php endif; ?>
    <button class="btn btn-warn" type="submit" name="action" value="apply" data-keep-submit
            data-confirm="Pause the cars you didn't tick now? They stay saved and come back after an upgrade.">Pause the rest now</button>
    <a class="btn btn-ghost" href="/app/broker/plan">Upgrade instead</a>
  </div>
  <?php if ($grace): ?>
  <p class="keep-fine">If you do nothing, your choice (or ours) is applied on <?= htmlspecialchars(date('j M Y', strtotime((string) $grace['ends_at']))) ?>.</p>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Choose cars | ' . $desk['display_name'];
$pageStyles  = ['/assets/css/plans.css'];
$pageScripts = ['/assets/js/keep-cars.js'];
require_once '../../views/layout-app.php';
