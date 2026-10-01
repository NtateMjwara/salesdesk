<?php
/**
 * Admin partial — superadmin platform controls for one dealership  (0017)
 *
 * Expects: array $dealer (getManagedDealer row), int $dealerId, PDO $pdo
 *
 *   • Access state: SalesDesk-run, principal-owned, delegation active?
 *   • Handover to a principal (SalesDesk-run dealerships only; step-up)
 *   • Listing holds: pull a listing for a platform reason (the dealer is
 *     told why and can't resume it) / release a hold
 */

if (!sdPhase3Ready()): ?>
<div class="alert alert-warn adm-gap"><span class="alert-icon">!</span>
  <div>Run <strong>0017 Principal-owned dealerships</strong> in <a href="/app/admin/migrations">Migrations</a> to use these controls.</div>
</div>
<?php return; endif;

$pcDeleg = sdActiveDelegation($dealerId);
$pcCars  = $pdo->prepare("
    SELECT id, make, model, year, price, status, hold_reason, hold_at
    FROM cars WHERE dealer_id = ? AND status <> 'sold'
    ORDER BY (hold_reason IS NULL), status, created_at DESC
    LIMIT 200
");
$pcCars->execute([$dealerId]);
$pcCars = $pcCars->fetchAll();
?>

<div class="card adm-card adm-card-wide">
  <div class="card-body">
    <h2 class="adm-h2">Who runs this dealership</h2>
    <?php if ($dealer['user_id'] === null): ?>
    <p class="adm-muted">Run by <strong>SalesDesk</strong> — admins with <em>Operate</em> can make changes.</p>
    <?php else: ?>
    <p class="adm-muted">
      Owned by its principal (<?= htmlspecialchars($dealer['principal_email'] ?? '') ?>). SalesDesk staff can only view
      it<?php if ($pcDeleg): ?> — <strong>except right now:</strong> the principal granted access until
      <?= htmlspecialchars(date('j M Y, H:i', strtotime($pcDeleg['expires_at']))) ?><?= $pcDeleg['note'] ? ' (“' . htmlspecialchars($pcDeleg['note']) . '”)' : '' ?><?php endif; ?>.
      Deals SalesDesk closes here need the principal’s confirmation.
    </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($dealer['user_id'] === null): ?>
<div class="card adm-card adm-card-wide">
  <div class="card-body">
    <h2 class="adm-h2">Hand over to a principal</h2>
    <p class="adm-muted adm-gap">
      When the dealership’s owner is ready to run it themselves. Use their existing dealer account (one with no
      dealership yet), or a new email and we’ll invite them. Afterwards SalesDesk staff can only view it, and the
      principal gets a summary of the stock, leads and team you built. Needs a confirmation code.
    </p>
    <form method="POST" class="adm-inline-form" data-confirm="Hand <?= htmlspecialchars($dealer['company_name'], ENT_QUOTES) ?> over? SalesDesk staff will lose the ability to change it.">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="handover">
      <div class="adm-grid-2">
        <div class="fgroup">
          <label class="flabel" for="hoFirst">First name <span class="flabel-opt">for a new account</span></label>
          <input class="finput" id="hoFirst" name="first_name" maxlength="60">
        </div>
        <div class="fgroup">
          <label class="flabel" for="hoLast">Last name <span class="flabel-opt">optional</span></label>
          <input class="finput" id="hoLast" name="last_name" maxlength="60">
        </div>
      </div>
      <label class="flabel" for="hoEmail">Principal’s email</label>
      <div class="adm-inline">
        <input class="finput" id="hoEmail" name="email" type="email" required placeholder="owner@dealership.co.za">
        <button class="btn btn-primary btn-sm" type="submit">Hand over</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card adm-card adm-card-wide">
  <div class="card-body">
    <h2 class="adm-h2">Listing holds <span class="section-count"><?= count(array_filter($pcCars, fn($c) => $c['hold_reason'])) ?> on hold</span></h2>
    <p class="adm-muted adm-gap">
      Pull a listing for a platform reason (misleading price, wrong photos, compliance). It’s paused, the dealer is told
      why, and they can’t resume it — nor can an import — until you release the hold.
    </p>
    <?php if (!$pcCars): ?>
    <p class="adm-muted">No unsold listings.</p>
    <?php else: ?>
    <ul class="adm-list">
      <?php foreach ($pcCars as $car): ?>
      <li class="adm-list-row">
        <div>
          <div class="adm-strong"><?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
            <?= adminStatusBadge($car['status'] === 'active' ? 'active' : 'inactive') ?>
            <?php if ($car['hold_reason']): ?><span class="badge badge-rejected"><i class="fa-solid fa-lock"></i> On hold</span><?php endif; ?>
          </div>
          <div class="adm-sub">R <?= number_format((float) $car['price'], 0, '.', ' ') ?>
            <?= $car['hold_reason'] ? ' · “' . htmlspecialchars($car['hold_reason']) . '” since ' . date('j M', strtotime($car['hold_at'])) : '' ?></div>
        </div>
        <?php if ($car['hold_reason']): ?>
        <form method="POST">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="release_hold">
          <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Release hold</button>
        </form>
        <?php else: ?>
        <form method="POST" class="adm-inline adm-hold-form">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="hold_listing">
          <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">
          <label class="sr-only" for="hold-<?= (int) $car['id'] ?>">Reason</label>
          <input class="finput" id="hold-<?= (int) $car['id'] ?>" name="reason" minlength="5" maxlength="255" required placeholder="Reason the dealer will see">
          <button class="btn btn-danger btn-sm" type="submit">Hold</button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
