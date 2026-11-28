<?php
/**
 * SalesDesk — "new listings paused" notice for dealer pages (PLANS-3b, 0023)
 * views/partials/dealer-listings-paused.php
 *
 * Shown on the dealer Inventory and import pages when the dealership's
 * business is over its plan's dealership limit and the 7-day window has
 * ended (dealers.listings_paused_at). Existing cars stay live; uploads
 * and imports add no new cars (includes/plan_grace.php).
 *
 * Expects $dealerId.
 */
require_once __DIR__ . '/../../includes/plan_grace.php';
if (empty($dealerId) || !sdDealerListingsPaused((int) $dealerId)) {
    return;
}
?>
<div class="alert alert-warn d-net-alert">
  <i class="fa-solid fa-pause alert-icon"></i>
  <div>
    <strong>New listings are paused for this dealership.</strong>
    Its business has more dealerships than its plan allows, so uploads and imports can't add new cars.
    Existing cars stay live and can still be updated.
  </div>
</div>
