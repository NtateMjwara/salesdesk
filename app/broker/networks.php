<?php
/**
 * SalesDesk — Broker: My networks  (plans Phase 2b)
 * Route: /app/broker/networks
 *
 * A business network is a dealership's (or brand's) circle of brokers.
 * Brokers can join several, up to their plan's max_networks (pending
 * applications count). Joining never limits what they can list: any car
 * on SalesDesk can still go on their desk, and commission is always paid
 * to them in full. In a network, their leads on that business's cars
 * count towards it, and the business's name shows on their desk.
 *
 *   Your networks — status per membership: withdraw, leave, apply again;
 *                   2c: accept / decline invitations from businesses
 *   Find a network — businesses taking applications (optional province)
 *
 * Replaces /app/broker/desk-org (redirects here). Logic lives in
 * includes/org_membership.php; styles in assets/css/networks.css.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/org_membership.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/vip_network.php';   // 4a: VIP perks per network
require_once '../../includes/network_dashboard.php';   // 4b: announcements

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$userId = (int) $_SESSION['user_id'];
$self   = '/app/broker/networks';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $orgId  = (int) ($_POST['org_id'] ?? 0);
    if ($action === 'apply') {
        $user = getUserById($userId);
        [$ok, $msg] = applyToNetwork($userId, $orgId, $user ? (string) $user['email'] : '');
    } elseif ($action === 'accept_invite' || $action === 'decline_invite') {   // 2c
        [$ok, $msg] = brokerRespondInvite($userId, $orgId, $action === 'accept_invite');
    } elseif ($action === 'leave') {
        [$ok, $msg] = brokerLeaveNetwork($userId, $orgId);
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }
    $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    $back = $self;
    if (isset($_POST['province']) && is_string($_POST['province']) && $_POST['province'] !== '') {
        $back .= '?province=' . rawurlencode($_POST['province']);
    }
    redirect($back);
}

$ready    = sdNetworksReady();
$province = is_string($_GET['province'] ?? null) ? trim($_GET['province']) : '';
$mine     = brokerNetworks($userId);
$allow    = brokerNetworkAllowance($userId);
$allOpen  = $ready ? listOpenNetworks($userId) : [];
$provinces = array_values(array_unique(array_filter(array_column($allOpen, 'province'))));
sort($provinces);
if ($province !== '' && !in_array($province, $provinces, true)) {
    $province = '';
}
$open     = $province === '' ? $allOpen : array_values(array_filter($allOpen, static fn ($n) => $n['province'] === $province));
$openById = array_column($allOpen, null, 'id');
$atLimit  = !$allow['room'];
$limitTxt = sdLimitText($allow['limit'], 'max_networks');

$statusLabel = [
    'invited'   => ['Invitation', 'net-badge-invite'],   // 2c
    'verified'  => ['In network', 'net-badge-ok'],
    'pending'   => ['Waiting for review', 'net-badge-wait'],
    'suspended' => ['Paused', 'net-badge-muted'],
    'rejected'  => ['Declined', 'net-badge-no'],
];

/** Initial for logo fallback. */
$initial = static fn (string $name): string => mb_strtoupper(mb_substr(trim($name), 0, 1));

// 4b: latest announcements of my networks
$annsBy = sdBrokerAnnouncements($userId);

// 4a: VIP perks per business (cached per page)
$perkCache = [];
$perkOf = static function (int $orgId) use (&$perkCache): array {
    return $perkCache[$orgId] ??= sdNetworkPerkSummary($orgId);
};

ob_start();
?>
<div class="section-head net-head">
  <div>
    <h1 class="section-title">My networks</h1>
    <p class="net-lede">Join dealership and brand networks. You can still share any car on SalesDesk, and your commission is always paid to you in full.</p>
  </div>
  <div class="net-meter" aria-label="Networks used: <?= (int) $allow['used'] ?> of <?= htmlspecialchars($limitTxt) ?>">
    <span class="net-meter-n"><?= (int) $allow['used'] ?><span> / <?= htmlspecialchars($limitTxt) ?></span></span>
    <span class="net-meter-l">networks on your plan</span>
    <?php if ($allow['limit'] !== null): ?>
      <progress class="net-meter-bar" max="<?= (int) $allow['limit'] ?>" value="<?= min((int) $allow['used'], (int) $allow['limit']) ?>"></progress>
      <a class="net-meter-link" href="/app/broker/plan">Need more? See Pro</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="empty"><span class="empty-icon"><i class="fa-solid fa-people-group"></i></span>Business networks aren’t available yet.</div>
<?php else: ?>

<!-- ── Your networks ─────────────────────────────────────────── -->
<section class="net-section" aria-labelledby="net-mine-h">
  <h2 class="net-h2" id="net-mine-h">Your networks <span class="section-count"><?= count($mine) ?></span></h2>
  <?php if (!$mine): ?>
    <p class="net-empty-line">You’re not in a network yet. Pick one below to apply.</p>
  <?php else: ?>
  <div class="net-list">
    <?php foreach ($mine as $m):
      [$label, $badge] = $statusLabel[$m['status']] ?? [ucfirst($m['status']), 'net-badge-muted'];
      $reopen = $m['status'] === 'rejected' && isset($openById[(int) $m['org_id']]) && !$openById[(int) $m['org_id']]['full']; ?>
    <article class="net-row net-row-<?= htmlspecialchars($m['status']) ?>" id="net-<?= (int) $m['org_id'] ?>">
      <?php if ($m['logo_url']): ?>
        <img class="net-logo" src="<?= htmlspecialchars($m['logo_url']) ?>" alt="" loading="lazy">
      <?php else: ?>
        <span class="net-logo net-logo-fallback" aria-hidden="true"><?= htmlspecialchars($initial($m['org_name'])) ?></span>
      <?php endif; ?>
      <div class="net-row-body">
        <div class="net-row-top">
          <h3 class="net-name"><?= htmlspecialchars($m['org_name']) ?></h3>
          <span class="net-badge <?= $badge ?>"><?= $label ?></span>
        </div>
        <p class="net-meta">
          <?= htmlspecialchars(implode(', ', array_filter([$m['city'], $m['province']])) ?: 'South Africa') ?>
          <?php if ($m['status'] === 'verified' && $m['verified_at']): ?> · since <?= date('j M Y', strtotime($m['verified_at'])) ?><?php endif; ?>
          <?php if ($m['status'] === 'pending'): ?> · applied <?= date('j M Y', strtotime($m['joined_at'])) ?><?php endif; ?>
        </p>
        <?php if ($m['status'] === 'invited'): ?>
          <p class="net-note"><?= $m['inviter_name'] ? htmlspecialchars($m['inviter_name']) . ' invited you' : 'You’ve been invited' ?> to join this network.
            Accepting uses one of your <?= htmlspecialchars($limitTxt) ?> network places.</p>
        <?php elseif (in_array($m['status'], ['suspended', 'rejected'], true) && $m['rejection_reason']): ?>
          <p class="net-reason">“<?= htmlspecialchars($m['rejection_reason']) ?>”</p>
        <?php elseif ($m['status'] === 'pending'): ?>
          <p class="net-note">You’ll get an email once it’s reviewed.</p>
        <?php elseif ($m['status'] === 'suspended'): ?>
          <p class="net-note">You can still share any car except its network-only stock. You don’t count as part of this network until you’re reinstated.</p>
        <?php endif; ?>
        <?php $pk = $perkOf((int) $m['org_id']); if ($m['status'] === 'verified' && ($pk['bonus'] || $pk['in_force'])): /* 4a */ ?>
          <p class="net-perks">
            <?php if ($pk['bonus']): ?><span class="net-perk"><i class="fa-solid fa-gift" aria-hidden="true"></i> Network bonus <strong><?= htmlspecialchars(sdBonusText($pk['bonus']['type'], $pk['bonus']['value'])) ?></strong> per sale</span><?php endif; ?>
            <?php if ($pk['in_force']): ?><span class="net-perk"><i class="fa-solid fa-lock" aria-hidden="true"></i> <?= (int) $pk['in_force'] ?> network-only car<?= $pk['in_force'] === 1 ? '' : 's' ?></span><?php endif; ?>
            <a href="/app/broker/inventory.php?tab=market&amp;net=<?= (int) $m['org_id'] ?>">See their cars →</a>
          </p>
        <?php endif; ?>
        <?php if ($m['status'] === 'verified' && !empty($annsBy[(int) $m['org_id']])): /* 4b */ ?>
          <div class="net-anns">
            <?php foreach ($annsBy[(int) $m['org_id']] as $i => $an): ?>
            <details class="net-ann"<?= $i === 0 && strtotime((string) $an['created_at']) > strtotime('-14 days') ? ' open' : '' ?>>
              <summary><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> <strong><?= htmlspecialchars($an['title']) ?></strong>
                <span class="net-ann__date"><?= htmlspecialchars(date('j M', strtotime((string) $an['created_at']))) ?></span></summary>
              <p><?= nl2br(htmlspecialchars($an['body'])) ?></p>
            </details>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="net-row-actions">
        <?php if ($m['status'] === 'invited'): ?>
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="accept_invite">
          <input type="hidden" name="org_id" value="<?= (int) $m['org_id'] ?>">
          <button class="btn btn-primary btn-sm" type="submit" <?= $atLimit ? 'disabled title="You’re at your plan’s network limit"' : '' ?>>Accept</button>
        </form>
        <form method="POST" action="<?= $self ?>" data-confirm="Decline the invitation from <?= htmlspecialchars($m['org_name'], ENT_QUOTES) ?>?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="decline_invite">
          <input type="hidden" name="org_id" value="<?= (int) $m['org_id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Decline</button>
        </form>
        <?php endif; ?>
        <?php if ($m['status'] === 'verified'): ?>
          <a class="btn btn-ghost btn-sm" href="/desks/<?= htmlspecialchars(rawurlencode($m['org_slug'])) ?>/" target="_blank" rel="noopener">Network page</a>
        <?php endif; ?>
        <?php if ($reopen): ?>
        <form method="POST" action="<?= $self ?>" data-confirm="Apply to <?= htmlspecialchars($m['org_name'], ENT_QUOTES) ?> again?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="org_id" value="<?= (int) $m['org_id'] ?>">
          <button class="btn btn-primary btn-sm" type="submit" <?= $atLimit ? 'disabled title="You’re at your plan’s network limit"' : '' ?>>Apply again</button>
        </form>
        <?php endif; ?>
        <?php if ($m['status'] !== 'invited'): ?>
        <form method="POST" action="<?= $self ?>"
              data-confirm="<?= htmlspecialchars(match ($m['status']) {
                  'pending'  => 'Withdraw your application to ' . $m['org_name'] . '?',
                  'rejected' => 'Remove ' . $m['org_name'] . ' from your list?',
                  default    => 'Leave ' . $m['org_name'] . '’s network? Its network-only cars will be paused on your desk; other cars stay.',
              }, ENT_QUOTES) ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="leave">
          <input type="hidden" name="org_id" value="<?= (int) $m['org_id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"><?= match ($m['status']) {
              'pending' => 'Withdraw', 'rejected' => 'Remove', default => 'Leave',
          } ?></button>
        </form>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- ── Find a network ─────────────────────────────────────────── -->
<section class="net-section" aria-labelledby="net-find-h">
  <div class="net-find-head">
    <h2 class="net-h2" id="net-find-h">Find a network <span class="section-count"><?= count($open) ?></span></h2>
    <?php if (count($provinces) > 1): ?>
    <form method="GET" action="<?= $self ?>" class="net-filter" data-net-filter>
      <label class="sr-only" for="net-province">Province</label>
      <select class="finput" id="net-province" name="province">
        <option value="">All provinces</option>
        <?php foreach ($provinces as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>" <?= $p === $province ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-ghost btn-sm net-filter-go" type="submit">Filter</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($atLimit): ?>
  <div class="dorg-banner dorg-banner-pending net-gap">
    <i class="fa-solid fa-circle-info"></i>
    <span>You’re using all <?= htmlspecialchars($limitTxt) ?> networks on your plan, counting applications waiting for review.
      Withdraw or leave one to apply elsewhere.</span>
    <a href="/app/broker/plan">See Pro</a>
  </div>
  <?php endif; ?>

  <?php if (!$open): ?>
    <div class="empty"><span class="empty-icon"><i class="fa-solid fa-people-group"></i></span>
      <?= $province !== '' ? 'No networks in ' . htmlspecialchars($province) . ' are taking applications right now.' : 'No networks are taking applications right now. Check back soon.' ?>
    </div>
  <?php else: ?>
  <div class="net-grid">
    <?php foreach ($open as $n):
      $myStatus = $n['my_status'];
      $limitN   = $n['broker_limit']; ?>
    <article class="net-card">
      <div class="net-card-head">
        <?php if ($n['logo_url']): ?>
          <img class="net-logo" src="<?= htmlspecialchars($n['logo_url']) ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="net-logo net-logo-fallback" aria-hidden="true"><?= htmlspecialchars($initial($n['name'])) ?></span>
        <?php endif; ?>
        <div class="net-card-title">
          <h3 class="net-name"><?= htmlspecialchars($n['name']) ?>
            <?php if ($n['verification_status'] === 'verified'): ?><i class="fa-solid fa-circle-check net-verified" title="Verified business" aria-label="Verified business"></i><?php endif; ?>
          </h3>
          <p class="net-meta"><?= htmlspecialchars(implode(', ', array_filter([$n['city'], $n['province']])) ?: 'South Africa') ?></p>
        </div>
      </div>
      <?php if ($n['brand_list']): ?>
      <div class="dorg-brands">
        <?php foreach (array_slice($n['brand_list'], 0, 4) as $b): ?><span class="dorg-brand"><?= htmlspecialchars($b) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <dl class="net-stats">
        <div><dt>Live cars</dt><dd><?= number_format((int) $n['live_cars'], 0, '.', ' ') ?></dd></div>
        <div><dt>Brokers</dt><dd><?= (int) $n['broker_count'] ?><?= $limitN !== null ? '<span> / ' . (int) $limitN . '</span>' : '' ?></dd></div>
        <?php if ((int) $n['dealer_count'] > 1): ?><div><dt>Dealerships</dt><dd><?= (int) $n['dealer_count'] ?></dd></div><?php endif; ?>
      </dl>
      <?php $pk = $perkOf((int) $n['id']); if ($pk['bonus'] || $pk['in_force']): /* 4a: reasons to join */ ?>
      <p class="net-perks net-perks--card">
        <?php if ($pk['bonus']): ?><span class="net-perk"><i class="fa-solid fa-gift" aria-hidden="true"></i> Bonus <strong><?= htmlspecialchars(sdBonusText($pk['bonus']['type'], $pk['bonus']['value'])) ?></strong></span><?php endif; ?>
        <?php if ($pk['in_force']): ?><span class="net-perk"><i class="fa-solid fa-lock" aria-hidden="true"></i> <?= (int) $pk['in_force'] ?> network-only</span><?php endif; ?>
      </p>
      <?php endif; ?>
      <div class="net-card-foot">
        <?php if ($myStatus && $myStatus !== 'rejected'): ?>
          <span class="net-badge <?= $statusLabel[$myStatus][1] ?? 'net-badge-muted' ?>"><?= $statusLabel[$myStatus][0] ?? ucfirst($myStatus) ?></span>
        <?php elseif ($n['full']): ?>
          <button class="btn btn-ghost btn-sm btn-full" type="button" disabled>Network full</button>
        <?php else: ?>
        <form method="POST" action="<?= $self ?>" data-confirm="Apply to join <?= htmlspecialchars($n['name'], ENT_QUOTES) ?>’s network?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="org_id" value="<?= (int) $n['id'] ?>">
          <?php if ($province !== ''): ?><input type="hidden" name="province" value="<?= htmlspecialchars($province) ?>"><?php endif; ?>
          <button class="btn btn-primary btn-sm btn-full" type="submit" <?= $atLimit ? 'disabled' : '' ?>>
            <?= $myStatus === 'rejected' ? 'Apply again' : 'Apply to join' ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'My networks';
$pageStyles  = ['/assets/css/desk-org.css', '/assets/css/networks.css'];
$pageScripts = ['/assets/js/admin.js', '/assets/js/networks.js'];   // admin.js: shared data-confirm handler
require_once '../../views/layout-app.php';
