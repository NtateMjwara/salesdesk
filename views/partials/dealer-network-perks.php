<?php
/**
 * SalesDesk — dealer portal Network → VIP perks tab  (plans Phase 4a)
 * views/partials/dealer-network-perks.php, included by app/dealer/network.php
 *
 *   • Network bonus — extra R amount or % of the price for leads brought
 *     by the business's network brokers, on any of its cars
 *   • Network-only stock — which of this dealership's cars only network
 *     brokers can list and share
 *
 * Expects: $perks (sdNetworkPerkSummary), $perkCars (sdDealerCarsForPerks),
 *          $canWrite, $self, $sum, $plan, $ws, $orgId.
 * Logic: includes/vip_network.php. Styles: assets/css/dealer-network.css.
 * Script: assets/js/dealer-perks.js.
 */

$vip      = !empty($perks['vip']);
$raw      = $perks['raw_bonus'] ?? null;
$editable = $vip && $canWrite && sdVipReady();
$multi    = !empty($orgId) && sdOrgDealerCount((int) $orgId) > 1;
$markedN  = count(array_filter($perkCars, static fn ($c) => (int) $c['network_only']));
?>
<?php if (!sdVipReady()): ?>
<div class="alert alert-info dnet-gap"><i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i>
  <div>VIP perks switch on shortly.</div></div>
<?php else: ?>

<?php if (!$vip): ?>
<div class="perk-locked">
  <i class="fa-solid fa-crown perk-locked__icon" aria-hidden="true"></i>
  <div>
    <strong class="perk-locked__title">VIP perks come with the Pro and Enterprise business plans</strong>
    <p class="perk-locked__text">
      Reward your network brokers with a <strong>network bonus</strong> on every sale they bring, and keep chosen cars
      <strong>network-only</strong> so only your brokers can list them. You’re on <?= htmlspecialchars($plan['name'] ?? 'Free') ?>.
      <a href="/app/dealer/plan">See your plan</a> to upgrade.
      <?php if ($raw || ($perks['network_only'] ?? 0)): ?>
      Your saved settings are kept and switch back on when you upgrade.
      <?php endif; ?>
    </p>
  </div>
</div>
<?php elseif (!$canWrite): ?>
<div class="alert alert-info dnet-gap"><i class="fa-solid fa-building alert-icon" aria-hidden="true"></i>
  <div>Your business owner manages VIP perks. You can see the settings here.</div></div>
<?php endif; ?>

<!-- Network bonus -->
<section class="perk-card" aria-labelledby="perkBonusTitle">
  <div class="perk-card__head">
    <h2 class="perk-card__title" id="perkBonusTitle"><i class="fa-solid fa-gift" aria-hidden="true"></i> Network bonus</h2>
    <span class="perk-state<?= ($vip && $raw) ? ' is-on' : '' ?>"><?= ($vip && $raw) ? htmlspecialchars(sdBonusText($raw['type'], $raw['value'])) . ' per sale' : 'Off' ?></span>
  </div>
  <p class="perk-card__text">
    An extra amount on top of the car’s commission when one of your network brokers brings the buyer — on any of your
    business’s cars. Network brokers see it in the Marketplace. It’s locked in when the lead comes in, and invoiced with the
    commission on the sale (the SalesDesk fee applies as usual).
  </p>
  <form method="POST" action="<?= $self ?>" class="perk-bonus" data-perk-bonus>
    <?= csrf_hidden_field() ?>
    <input type="hidden" name="action" value="bonus_save">
    <input type="hidden" name="tab" value="perks">
    <fieldset class="perk-bonus__types" <?= $editable ? '' : 'disabled' ?>>
      <legend class="sr-only">Bonus type</legend>
      <?php $bt = $raw['type'] ?? 'none'; ?>
      <label class="perk-radio"><input type="radio" name="bonus_type" value="none" <?= $bt === 'none' ? 'checked' : '' ?>> No bonus</label>
      <label class="perk-radio"><input type="radio" name="bonus_type" value="fixed" <?= $bt === 'fixed' ? 'checked' : '' ?>> Rand amount</label>
      <label class="perk-radio"><input type="radio" name="bonus_type" value="percentage" <?= $bt === 'percentage' ? 'checked' : '' ?>> % of the car price</label>
    </fieldset>
    <div class="perk-bonus__value" data-perk-value <?= $bt === 'none' ? 'hidden' : '' ?>>
      <label class="flabel" for="bonus_value" data-perk-value-label><?= $bt === 'percentage' ? 'Percentage of the price' : 'Amount per sale (R)' ?></label>
      <input class="finput" type="number" id="bonus_value" name="bonus_value" min="0.01" step="any"
             max="<?= $bt === 'percentage' ? SD_NETWORK_BONUS_MAX_PCT : SD_NETWORK_BONUS_MAX_FIXED ?>"
             data-max-pct="<?= SD_NETWORK_BONUS_MAX_PCT ?>" data-max-fixed="<?= SD_NETWORK_BONUS_MAX_FIXED ?>"
             value="<?= $raw ? htmlspecialchars(rtrim(rtrim(number_format((float) $raw['value'], 2, '.', ''), '0'), '.')) : '' ?>"
             placeholder="<?= $bt === 'percentage' ? '0.5' : '1000' ?>" <?= $editable ? '' : 'disabled' ?>>
      <p class="perk-hint" data-perk-example></p>
    </div>
    <?php if ($editable): ?>
    <button class="btn btn-primary btn-sm" type="submit">Save bonus</button>
    <?php endif; ?>
  </form>
</section>

<!-- Network-only stock -->
<section class="perk-card" aria-labelledby="perkStockTitle">
  <div class="perk-card__head">
    <h2 class="perk-card__title" id="perkStockTitle"><i class="fa-solid fa-lock" aria-hidden="true"></i> Network-only stock</h2>
    <span class="perk-state<?= ($vip && $markedN) ? ' is-on' : '' ?>"><?= $markedN ?> of <?= count($perkCars) ?> car<?= count($perkCars) === 1 ? '' : 's' ?></span>
  </div>
  <p class="perk-card__text">
    Only your network brokers can list and share a network-only car. It leaves the public SalesDesk marketplace as a
    direct listing — buyers find it through your brokers’ desks — and brokers outside your network have it paused on their desks
    (it comes back to them if they join, or if you open it up again).
    <?php if ($multi): ?>
    These are <strong><?= htmlspecialchars($ws['company_name']) ?></strong>’s cars; switch dealership at the top to manage the others.
    <?php endif; ?>
  </p>

  <?php if (!$perkCars): ?>
  <div class="empty"><span class="empty-icon"><i class="fa-solid fa-car-side" aria-hidden="true"></i></span>No active cars yet.</div>
  <?php else: ?>
  <form method="POST" action="<?= $self ?>" class="perk-stock" data-perk-stock
        data-confirm="Save which cars are network-only? Brokers outside your network will have newly network-only cars paused on their desks.">
    <?= csrf_hidden_field() ?>
    <input type="hidden" name="action" value="network_only_save">
    <input type="hidden" name="tab" value="perks">
    <?php if ($editable): ?>
    <div class="perk-stock__bar">
      <label class="perk-radio"><input type="checkbox" data-perk-all> Select all</label>
      <span class="perk-stock__count" aria-live="polite"><span data-perk-count><?= $markedN ?></span> network-only</span>
    </div>
    <?php endif; ?>
    <ul class="perk-list">
      <?php foreach ($perkCars as $pc):
        $imgs = json_decode($pc['image_urls'] ?? '[]', true) ?: [];
        $img  = $imgs[0] ?? null; ?>
      <li class="perk-row">
        <label class="perk-row__label">
          <input type="hidden" name="scope[]" value="<?= (int) $pc['id'] ?>">
          <input type="checkbox" class="perk-row__check" name="network_only[]" value="<?= (int) $pc['id'] ?>"
                 <?= (int) $pc['network_only'] ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
          <span class="perk-row__thumb"><?php if ($img): ?><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-car-side" aria-hidden="true"></i><?php endif; ?></span>
          <span class="perk-row__info">
            <span class="perk-row__name"><?= htmlspecialchars($pc['year'] . ' ' . $pc['make'] . ' ' . $pc['model']) ?></span>
            <span class="perk-row__meta">R <?= number_format((float) $pc['price'], 0, '.', ',') ?>
              · <?= (int) $pc['brokers'] ?> broker<?= (int) $pc['brokers'] === 1 ? '' : 's' ?>
              <?= $pc['status'] === 'paused' ? '· paused by you' : '' ?></span>
          </span>
          <?php if ((int) $pc['network_only']): ?>
          <span class="net-only-tag"><i class="fa-solid fa-lock" aria-hidden="true"></i> Network only</span>
          <?php endif; ?>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($editable): ?>
    <button class="btn btn-primary btn-sm perk-stock__save" type="submit">Save network-only cars</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</section>

<?php endif; ?>
