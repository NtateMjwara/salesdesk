<?php
/**
 * SalesDesk — dealer portal Network → Dashboard tab  (plans Phase 4b)
 * views/partials/dealer-network-dashboard.php, included by app/dealer/network.php
 *
 *   • per-broker performance on the business's cars for a period
 *     (cars on desk, views, leads, sales, commission) + CSV download
 *   • announcements to the network (post, list, remove)
 *
 * Expects: $dashVip, $dashDays, $perf (sdNetworkPerformance or null),
 *          $anns (sdAnnouncements), $canWrite, $self, $sum, $plan, $orgId.
 * Logic: includes/network_dashboard.php. Styles: assets/css/dealer-network.css.
 * Script: assets/js/dealer-perks.js (character counters).
 */

$fmtR    = static fn ($v): string => 'R ' . number_format((float) $v, 0, '.', ',');
$annLeft = $dashVip ? max(0, SD_NETWORK_ANNOUNCEMENTS_PER_DAY - sdAnnouncementsToday((int) $orgId)) : 0;
$people  = (int) ($sum['verified'] ?? 0);
?>
<?php if (!sdNetDashReady()): ?>
<div class="alert alert-info dnet-gap"><i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i>
  <div>The business dashboard switches on shortly.</div></div>
<?php elseif (!$dashVip): ?>
<div class="perk-locked">
  <i class="fa-solid fa-crown perk-locked__icon" aria-hidden="true"></i>
  <div>
    <strong class="perk-locked__title">The business dashboard comes with the Pro and Enterprise business plans</strong>
    <p class="perk-locked__text">
      See how each network broker performs on your cars — views, leads, sales and commission — and send announcements
      to your whole network. You’re on <?= htmlspecialchars($plan['name'] ?? 'Free') ?>. <a href="/app/dealer/plan">See your plan</a> to upgrade.
    </p>
  </div>
</div>
<?php else: ?>

<!-- Performance -->
<section class="perk-card" aria-labelledby="dashPerfTitle">
  <div class="perk-card__head">
    <h2 class="perk-card__title" id="dashPerfTitle"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Your brokers</h2>
    <form method="GET" action="<?= $self ?>" class="dash-period">
      <input type="hidden" name="tab" value="dashboard">
      <label class="sr-only" for="dashDays">Period</label>
      <select class="finput" id="dashDays" name="days" data-auto-submit>
        <?php foreach (SD_NETWORK_DASH_PERIODS as $d => $label): ?>
        <option value="<?= $d ?>" <?= $d === $dashDays ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-ghost btn-sm" type="submit">Show</button></noscript>
      <a class="btn btn-ghost btn-sm" href="<?= $self ?>?tab=dashboard&amp;days=<?= $dashDays ?>&amp;export=csv">
        <i class="fa-solid fa-download" aria-hidden="true"></i> CSV
      </a>
    </form>
  </div>
  <p class="perk-card__text">Views, leads, sales and commission on your business’s cars that came through each broker’s desk.
    Cars on desk is how many of your cars each broker shows right now.</p>

  <div class="dash-totals">
    <div class="dash-total"><span class="dash-total__num"><?= number_format($perf['totals']['views']) ?></span><span class="dash-total__label">Views</span></div>
    <div class="dash-total"><span class="dash-total__num"><?= number_format($perf['totals']['leads'] + $perf['other']['leads']) ?></span><span class="dash-total__label">Leads</span></div>
    <div class="dash-total"><span class="dash-total__num"><?= number_format($perf['totals']['sales'] + $perf['other']['sales']) ?></span><span class="dash-total__label">Sales</span></div>
    <div class="dash-total"><span class="dash-total__num"><?= $fmtR($perf['totals']['commission'] + $perf['other']['commission']) ?></span><span class="dash-total__label">Commission</span></div>
  </div>

  <?php if (!$perf['rows']): ?>
  <div class="empty"><span class="empty-icon"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span>No brokers in your network yet.</div>
  <?php else: ?>
  <div class="dash-table-wrap">
    <table class="dash-table">
      <thead>
        <tr><th scope="col">Broker</th><th scope="col" class="num">Cars on desk</th><th scope="col" class="num">Views</th>
            <th scope="col" class="num">Leads</th><th scope="col" class="num">Sales</th><th scope="col" class="num">Commission</th></tr>
      </thead>
      <tbody>
      <?php foreach ($perf['rows'] as $r): ?>
        <tr>
          <td>
            <span class="dash-broker"><?= htmlspecialchars($r['name'] ?: ($r['desk_name'] ?? 'Broker')) ?></span>
            <?php if ($r['desk_slug']): ?><a class="dash-desk" href="/<?= htmlspecialchars(rawurlencode($r['desk_slug'])) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($r['desk_name']) ?> ↗</a><?php endif; ?>
            <?php if ($r['status'] === 'suspended'): ?><span class="dash-flag">Suspended</span><?php endif; ?>
          </td>
          <td class="num"><?= (int) $r['cars'] ?></td>
          <td class="num"><?= number_format((int) $r['views']) ?></td>
          <td class="num"><?= (int) $r['leads'] ?></td>
          <td class="num"><?= (int) $r['sales'] ?></td>
          <td class="num"><?= $fmtR($r['commission']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($perf['other']['leads'] || $perf['other']['sales']): ?>
        <tr class="dash-other">
          <td>Brokers outside your network</td><td class="num">—</td><td class="num">—</td>
          <td class="num"><?= (int) $perf['other']['leads'] ?></td><td class="num"><?= (int) $perf['other']['sales'] ?></td>
          <td class="num"><?= $fmtR($perf['other']['commission']) ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Announcements -->
<section class="perk-card" aria-labelledby="dashAnnTitle">
  <div class="perk-card__head">
    <h2 class="perk-card__title" id="dashAnnTitle"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Announcements</h2>
    <span class="perk-state"><?= $people ?> broker<?= $people === 1 ? '' : 's' ?> will see it</span>
  </div>
  <p class="perk-card__text">Tell your network about new stock, a weekend special or a change to your bonus. Brokers get it in
    SalesDesk (and by email if you tick it) and can read it on their My networks page.
    Up to <?= SD_NETWORK_ANNOUNCEMENTS_PER_DAY ?> a day.</p>

  <?php if ($canWrite): ?>
  <form method="POST" action="<?= $self ?>" class="dash-ann-form" data-confirm="Send this announcement to your network?">
    <?= csrf_hidden_field() ?>
    <input type="hidden" name="action" value="announce_post">
    <input type="hidden" name="tab" value="dashboard">
    <div class="fgroup">
      <label class="flabel" for="ann_title">Title</label>
      <input class="finput" id="ann_title" name="ann_title" maxlength="120" required data-count-max="120" placeholder="e.g. 12 new Hilux demos this week">
    </div>
    <div class="fgroup">
      <label class="flabel" for="ann_body">Message</label>
      <textarea class="finput" id="ann_body" name="ann_body" rows="4" maxlength="2000" required data-count-max="2000"></textarea>
      <p class="perk-hint dash-count" data-count-for="ann_body"></p>
    </div>
    <label class="perk-radio"><input type="checkbox" name="ann_email" value="1"> Also email it to my brokers</label>
    <div class="dash-ann-foot">
      <button class="btn btn-primary btn-sm" type="submit" <?= ($annLeft && $people) ? '' : 'disabled' ?>>Send to network</button>
      <span class="dash-ann-left"><?= !$people ? 'Your network has no brokers yet.' : ($annLeft ? "{$annLeft} left today" : 'Daily limit reached — post again tomorrow.') ?></span>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($anns): ?>
  <ul class="dash-ann-list">
    <?php foreach ($anns as $a): ?>
    <li class="dash-ann">
      <div class="dash-ann__head">
        <strong class="dash-ann__title"><?= htmlspecialchars($a['title']) ?></strong>
        <span class="dash-ann__meta"><?= htmlspecialchars(date('j M Y, H:i', strtotime((string) $a['created_at']))) ?>
          · <?= (int) $a['recipients'] ?> broker<?= (int) $a['recipients'] === 1 ? '' : 's' ?><?= (int) $a['emailed'] ? ' · emailed' : '' ?>
          <?= $a['author'] ? '· ' . htmlspecialchars($a['author']) : '' ?></span>
      </div>
      <p class="dash-ann__body"><?= nl2br(htmlspecialchars($a['body'])) ?></p>
      <?php if ($canWrite): ?>
      <form method="POST" action="<?= $self ?>" data-confirm="Remove this announcement from the list? Brokers who already got it keep their notification.">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="announce_delete">
        <input type="hidden" name="tab" value="dashboard">
        <input type="hidden" name="ann_id" value="<?= (int) $a['id'] ?>">
        <button class="btn btn-ghost btn-sm dash-ann__remove" type="submit">Remove</button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php endif; ?>
