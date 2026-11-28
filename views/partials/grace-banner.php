<?php
/**
 * SalesDesk — broker downgrade banner (PLANS-3b, 0023)
 * views/partials/grace-banner.php
 *
 * Shown on the broker Dashboard, Plan and Inventory pages when:
 *   - the desk is over its car limit and the 7-day window is open
 *     ("choose which cars stay live by …"), or
 *   - cars were paused when a window ended ("N cars paused").
 *
 * Expects $graceState = sdBrokerDeskState($userId) (includes/plan_grace.php).
 * Styles: assets/css/plans.css (.grace-banner).
 */

if (empty($graceState) || !is_array($graceState)) {
    return;
}
$gbGrace  = $graceState['grace'] ?? null;
$gbParked = (int) ($graceState['parked_plan'] ?? $graceState['parked'] ?? 0);   // 4a: network pauses aren't plan pauses
if (!$gbGrace && $gbParked === 0) {
    return;
}
$gbOnKeepPage = str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/app/broker/keep-cars');
?>
<?php if ($gbGrace): ?>
<div class="grace-banner grace-banner--window" role="status">
  <i class="fa-solid fa-hourglass-half grace-banner__icon" aria-hidden="true"></i>
  <div class="grace-banner__body">
    <strong class="grace-banner__title">Your desk is over its plan limit</strong>
    <span class="grace-banner__text">
      <?= (int) $graceState['live'] ?> live cars, your plan allows <?= (int) $graceState['limit'] ?>.
      Choose which stay live by <strong><?= htmlspecialchars(date('j M Y', strtotime((string) $gbGrace['ends_at']))) ?></strong>
      — after that the extra <?= (int) $graceState['over'] ?> are paused automatically (never deleted).
    </span>
  </div>
  <div class="grace-banner__actions">
    <?php if (!$gbOnKeepPage): ?>
    <a class="btn btn-warn btn-sm" href="/app/broker/keep-cars">Choose cars</a>
    <?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="/app/broker/plan">Upgrade</a>
  </div>
</div>
<?php else: ?>
<div class="grace-banner grace-banner--parked" role="status">
  <i class="fa-solid fa-pause grace-banner__icon" aria-hidden="true"></i>
  <div class="grace-banner__body">
    <strong class="grace-banner__title"><?= $gbParked ?> car<?= $gbParked === 1 ? ' is' : 's are' ?> paused</strong>
    <span class="grace-banner__text">
      Over your plan's limit, so hidden from your desk and links. Nothing was deleted — upgrade and they come back automatically,
      or bring one back yourself when a slot is free.
    </span>
  </div>
  <div class="grace-banner__actions">
    <a class="btn btn-ghost btn-sm" href="/app/broker/inventory.php?tab=desk#parkedTitle">See paused cars</a>
    <a class="btn btn-ghost btn-sm" href="/app/broker/plan">Upgrade</a>
  </div>
</div>
<?php endif; ?>
