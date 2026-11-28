<?php
/**
 * SalesDesk — Dealer portal: Network  (plans Phase 2c)
 * Route: /app/dealer/network[?tab=members|find]
 *
 * The dealership's business account runs a BROKER NETWORK: brokers who
 * share its cars as part of the business. Joining never limits what a
 * broker lists, and commission is always paid to the broker in full.
 *
 *   members (default) — open / close to applications; review applications
 *                       (approve / decline, network size from the plan);
 *                       members (suspend, reinstate, remove); invitations
 *                       (withdraw); declined
 *   find              — browse active brokers (public desk details only)
 *                       and invite them — Pro / Enterprise
 *                       (can_invite_brokers), max SD_NETWORK_INVITES_PER_DAY
 *   perks (4a)        — VIP perks, Pro / Enterprise (vip_perks): the
 *                       network bonus and which of this dealership's cars
 *                       are network-only (includes/vip_network.php,
 *                       views/partials/dealer-network-perks.php)
 *   dashboard (4b)    — business dashboard, Pro / Enterprise: per-broker
 *                       views, leads, sales and commission (CSV download),
 *                       and announcements to the network
 *                       (includes/network_dashboard.php,
 *                       views/partials/dealer-network-dashboard.php)
 *
 * Who: the principal, or an admin with write access in the dealer
 * workspace (operator / delegate). Viewers see it read-only — the central
 * guard in requireDealerWorkspace() stops their POSTs. The admins managing
 * the dealership can also do all this from Admin → Dealerships → Business.
 *
 * Rules: includes/org_membership.php (sdNetworkReview, sdInviteBroker, …).
 * Styles: assets/css/networks.css + assets/css/dealer-network.css.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/dealer_context.php';
require_once '../../includes/org_membership.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/vip_network.php';   // 4a: VIP perks
require_once '../../includes/network_dashboard.php';   // 4b: dashboard + announcements

applyCachePolicy('auth');
$ws = requireDealerWorkspace();   // principal, or admin in a dealer workspace

$actorId  = (int) $_SESSION['user_id'];
$dealerId = (int) $ws['dealer_id'];
$self     = '/app/dealer/network';
$tab      = in_array($_GET['tab'] ?? '', ['find', 'perks', 'dashboard'], true) ? $_GET['tab'] : 'members';
$ready    = sdNetworksReady();
$orgId    = $ready ? sdEnsureDealerOrganization($dealerId, $actorId) : null;
// 2d: in a multi-dealership business the owner runs the one network.
$canWrite = $orgId ? sdCanManageNetwork($ws, $orgId) : (bool) $ws['can_write'];
$ownerRuns = $orgId && $ws['can_write'] && !$canWrite;

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $back   = $self . (in_array($_POST['tab'] ?? '', ['find', 'perks', 'dashboard'], true) ? '?tab=' . $_POST['tab'] : '');
    if (!empty($_POST['return_qs']) && is_string($_POST['return_qs']) && preg_match('/^[a-z0-9_=&%+.\-]*$/i', $_POST['return_qs'])) {
        $back = $self . '?' . $_POST['return_qs'];
    }
    if (!$orgId) {
        $_SESSION['flash_error'] = 'Business networks aren’t available yet.';
        redirect($self);
    }
    if (!$canWrite) {
        $_SESSION['flash_error'] = 'Your business owner runs the broker network. Nothing was changed.';
        redirect($back);
    }
    [$ok, $msg] = match (true) {
        $action === 'net_open', $action === 'net_close'
            => sdSetNetworkOpen($dealerId, $action === 'net_open', $actorId),
        $action === 'invite'
            => sdInviteBroker($orgId, (int) ($_POST['broker_id'] ?? 0), $actorId),
        in_array($action, ['approve', 'reject', 'suspend', 'reinstate', 'remove', 'cancel'], true)
            => sdNetworkReview($orgId, (int) ($_POST['member_id'] ?? $_POST['target_id'] ?? 0), $action,
                               (string) ($_POST['reason'] ?? ''), $actorId),
        $action === 'bonus_save'                     // 4a
            => sdSetOrgBonus($orgId, (string) ($_POST['bonus_type'] ?? ''), $_POST['bonus_value'] ?? 0, $actorId),
        $action === 'network_only_save'              // 4a
            => sdSetCarsNetworkOnly($dealerId, (array) ($_POST['scope'] ?? []), (array) ($_POST['network_only'] ?? []), $actorId),
        $action === 'announce_post'                  // 4b
            => sdPostAnnouncement($orgId, (string) ($_POST['ann_title'] ?? ''), (string) ($_POST['ann_body'] ?? ''), !empty($_POST['ann_email']), $actorId),
        $action === 'announce_delete'                // 4b
            => sdDeleteAnnouncement($orgId, (int) ($_POST['ann_id'] ?? 0), $actorId),
        default => [false, 'Unknown action.'],
    };
    $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    redirect($back);
}

// ── Load ──────────────────────────────────────────────────────
$sum    = $orgId ? sdNetworkSummary($orgId) : null;
$plan   = $orgId && sdPlansReady() ? sdAccountPlan('organization', $orgId) : null;
$roster = $orgId ? sdNetworkRoster($orgId) : [];
$by     = ['pending' => [], 'verified' => [], 'invited' => [], 'suspended' => [], 'rejected' => []];
foreach ($roster as $r) {
    $by[$r['status']][] = $r;
}

$q        = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$province = is_string($_GET['province'] ?? null) ? trim($_GET['province']) : '';
$page     = max(1, (int) ($_GET['page'] ?? 1));
$browse   = ($tab === 'find' && $orgId) ? sdBrowseBrokers($orgId, $q, $province, $page) : null;
$provs    = $tab === 'find' ? sdBrokerProvinces() : [];
$findQs   = http_build_query(array_filter(['tab' => 'find', 'q' => $q, 'province' => $province, 'page' => $page > 1 ? $page : null]));

$canInvite = $sum && $sum['can_invite'] && $sum['invites_ready'];
// 4a: VIP perks
$perks     = $orgId ? sdNetworkPerkSummary($orgId) : null;
$perkCars  = ($tab === 'perks' && $orgId && sdVipReady()) ? sdDealerCarsForPerks($dealerId) : [];
// 4b: business dashboard
$dashVip   = $orgId && sdNetDashVip($orgId);
$dashDays  = array_key_exists((int) ($_GET['days'] ?? 30), SD_NETWORK_DASH_PERIODS) ? (int) ($_GET['days'] ?? 30) : 30;
$perf      = ($tab === 'dashboard' && $dashVip) ? sdNetworkPerformance($orgId, $dashDays) : null;
$anns      = ($tab === 'dashboard' && $orgId) ? sdAnnouncements($orgId) : [];
if ($perf && ($_GET['export'] ?? '') === 'csv') {   // 4b: CSV download of the dashboard
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="network-brokers-' . date('Y-m-d') . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Broker', 'Desk', 'Status', 'Cars on desk', 'Views', 'Leads', 'Sales', 'Commission (R)', 'Period']);
    foreach ($perf['rows'] as $r) {
        fputcsv($fh, [$r['name'] ?: $r['desk_name'], $r['desk_name'], $r['status'], $r['cars'], $r['views'], $r['leads'], $r['sales'],
                      number_format((float) $r['commission'], 2, '.', ''), SD_NETWORK_DASH_PERIODS[$dashDays]]);
    }
    fclose($fh);
    exit;
}
$inviteRoom = $sum && sdHasRoom($sum['limit'], $sum['verified'] + $sum['invited'])
              && $sum['invites_today'] < SD_NETWORK_INVITES_PER_DAY;

$personName = static fn (array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['desk_name'] ?? 'Broker');
$initials   = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out   = mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '');
    return mb_strtoupper($out);
};

$pageTitle = 'Network';
ob_start();
?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__title">Your <em>network</em></h1>
    <p class="dash-header__sub-plain">
      Brokers who share your cars as part of <?= htmlspecialchars($sum['name'] ?? $ws['company_name']) ?>.
      They can still share any car on SalesDesk, and their commission is always paid to them.
    </p>
  </div>
</div>

<?php if (!$ready || !$sum): ?>
  <div class="empty"><span class="empty-icon"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span>
    Broker networks aren’t switched on yet. SalesDesk will let you know when they are.</div>
<?php else: ?>

<?php if ($ownerRuns): ?>
<div class="alert alert-info dnet-gap">
  <i class="fa-solid fa-building alert-icon" aria-hidden="true"></i>
  <div><?= htmlspecialchars($ws['company_name']) ?> is part of <strong><?= htmlspecialchars($sum['name']) ?></strong>, which runs one broker network for all its dealerships.
    The business owner manages it; you can see who’s in it here.</div>
</div>
<?php endif; ?>

<!-- Summary -->
<div class="d-team-stat-strip dnet-stats">
  <div class="d-team-stat-box">
    <div class="d-team-stat-box__num"><?= $sum['verified'] ?><span class="dnet-of"> / <?= htmlspecialchars(sdLimitText($sum['limit'], 'max_network_brokers')) ?></span></div>
    <div class="d-team-stat-box__label">Brokers in network</div>
  </div>
  <div class="d-team-stat-box">
    <div class="d-team-stat-box__num<?= $sum['pending'] ? ' dnet-alert' : '' ?>"><?= $sum['pending'] ?></div>
    <div class="d-team-stat-box__label">Waiting for review</div>
  </div>
  <div class="d-team-stat-box">
    <div class="d-team-stat-box__num"><?= $sum['invited'] ?></div>
    <div class="d-team-stat-box__label">Invitations open</div>
  </div>
  <div class="d-team-stat-box">
    <div class="d-team-stat-box__num dnet-plan"><?= htmlspecialchars($plan['name'] ?? 'Free') ?></div>
    <div class="d-team-stat-box__label">Business plan</div>
  </div>
</div>

<!-- Open / close -->
<div class="dnet-switch <?= $sum['accepting'] ? 'is-open' : '' ?>">
  <div class="dnet-switch__text">
    <span class="dnet-switch__state">
      <i class="fa-solid <?= $sum['accepting'] ? 'fa-door-open' : 'fa-door-closed' ?>" aria-hidden="true"></i>
      <?= $sum['accepting'] ? 'Open to applications' : 'Closed to applications' ?>
    </span>
    <span class="dnet-switch__help">
      <?= $sum['accepting']
          ? 'Brokers can find your network on their My networks page and apply. You review every application.'
          : 'Brokers can’t apply right now. Open your network to let brokers find it and apply.' ?>
      <?php if ($sum['verified'] > 0): ?>
        <a href="/desks/<?= htmlspecialchars(rawurlencode($sum['slug'])) ?>/" target="_blank" rel="noopener">View your network page ↗</a>
      <?php endif; ?>
    </span>
  </div>
  <?php if ($canWrite): ?>
  <form method="POST" action="<?= $self ?>"
        data-confirm="<?= $sum['accepting'] ? 'Close your network to new applications? Current brokers stay in it.' : 'Open your network to broker applications?' ?>">
    <?= csrf_hidden_field() ?>
    <input type="hidden" name="action" value="<?= $sum['accepting'] ? 'net_close' : 'net_open' ?>">
    <button class="btn <?= $sum['accepting'] ? 'btn-ghost' : 'btn-primary' ?> btn-sm" type="submit">
      <?= $sum['accepting'] ? 'Close to applications' : 'Open to applications' ?>
    </button>
  </form>
  <?php endif; ?>
</div>

<nav class="dnet-tabs" aria-label="Network sections">
  <a href="<?= $self ?>" class="dnet-tab<?= $tab === 'members' ? ' is-active' : '' ?>" <?= $tab === 'members' ? 'aria-current="page"' : '' ?>>
    Brokers <?php if ($sum['pending']): ?><span class="dnet-tab-count"><?= $sum['pending'] ?></span><?php endif; ?>
  </a>
  <a href="<?= $self ?>?tab=find" class="dnet-tab<?= $tab === 'find' ? ' is-active' : '' ?>" <?= $tab === 'find' ? 'aria-current="page"' : '' ?>>Find brokers</a>
  <a href="<?= $self ?>?tab=perks" class="dnet-tab<?= $tab === 'perks' ? ' is-active' : '' ?>" <?= $tab === 'perks' ? 'aria-current="page"' : '' ?>>VIP perks</a>
  <a href="<?= $self ?>?tab=dashboard" class="dnet-tab<?= $tab === 'dashboard' ? ' is-active' : '' ?>" <?= $tab === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
</nav>

<?php if ($tab === 'members'): ?>

  <?php if (!$roster): ?>
  <div class="empty">
    <span class="empty-icon"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span>
    No brokers yet.<br>
    <?= $sum['accepting'] ? 'Applications will appear here for you to review.' : 'Open your network so brokers can apply' ?>
    <?= $canInvite ? ', or <a href="' . $self . '?tab=find">find brokers to invite</a>.' : '.' ?>
  </div>
  <?php endif; ?>

  <?php
  $sections = [
      'pending'   => ['Waiting for review', 'd-team-section-count--alert'],
      'verified'  => ['In your network', ''],
      'invited'   => ['Invitations sent', ''],
      'suspended' => ['Suspended', ''],
      'rejected'  => ['Declined', ''],
  ];
  foreach ($sections as $status => [$label, $countClass]):
      if (!$by[$status]) { continue; } ?>
  <div class="d-team-section-head">
    <span class="d-team-section-label"><?= $label ?></span>
    <span class="d-team-section-count <?= $countClass ?>"><?= count($by[$status]) ?></span>
  </div>
  <?php foreach ($by[$status] as $r):
      $name = $personName($r); ?>
  <div class="d-exec-card dnet-card dnet-card--<?= $status ?>">
    <div class="avatar"><?= htmlspecialchars($initials($name)) ?></div>
    <div class="d-exec-card__info">
      <div class="d-exec-card__name"><?= htmlspecialchars($name) ?></div>
      <div class="d-exec-card__meta">
        <?php if ($r['desk_slug']): ?>
          <a href="/<?= htmlspecialchars($r['desk_slug']) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($r['desk_name']) ?> ↗</a>
        <?php endif; ?>
        <?= ($r['city'] || $r['province']) ? ' · ' . htmlspecialchars(implode(', ', array_filter([$r['city'], $r['province']]))) : '' ?>
        <?php if (in_array($status, ['pending', 'verified', 'suspended'], true)): ?>
          <br><a href="mailto:<?= htmlspecialchars($r['email']) ?>"><?= htmlspecialchars($r['email']) ?></a><?= $r['phone'] ? ' · ' . htmlspecialchars($r['phone']) : '' ?>
        <?php endif; ?>
      </div>
      <div class="d-exec-card__stats">
        <div class="d-exec-card__stat"><strong><?= (int) $r['desk_cars'] ?></strong> cars on desk</div>
        <div class="d-exec-card__stat"><strong><?= (int) $r['network_leads'] ?></strong> leads on your cars</div>
        <?php if ((int) $r['other_networks'] > 0): ?>
        <div class="d-exec-card__stat">In <?= (int) $r['other_networks'] ?> other network<?= (int) $r['other_networks'] === 1 ? '' : 's' ?></div>
        <?php endif; ?>
        <div class="d-exec-card__stat"><?= match ($status) {
            'pending'  => 'Applied ' . date('j M Y', strtotime($r['joined_at'])),
            'invited'  => 'Invited ' . date('j M Y', strtotime($r['joined_at'])),
            'verified' => $r['verified_at'] ? 'Since ' . date('j M Y', strtotime($r['verified_at'])) : '',
            default    => $r['verified_at'] ? date('j M Y', strtotime($r['verified_at'])) : '',
        } ?></div>
      </div>
      <?php if (in_array($status, ['suspended', 'rejected'], true) && $r['rejection_reason']): ?>
        <div class="dnet-reason">“<?= htmlspecialchars($r['rejection_reason']) ?>”</div>
      <?php endif; ?>
    </div>
    <?php if ($canWrite): ?>
    <div class="d-exec-card__actions">
      <?php if ($status === 'pending'): ?>
        <form method="POST" action="<?= $self ?>" data-confirm="Add <?= htmlspecialchars($name, ENT_QUOTES) ?> to your network? They’ll be emailed.">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="member_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-success btn-sm" type="submit" <?= !$sum['room'] ? 'disabled title="Your network is full"' : '' ?>>
            <i class="fa-solid fa-check" aria-hidden="true"></i> Approve
          </button>
        </form>
        <button class="btn btn-danger btn-sm" type="button" data-reason-modal="reject" data-reason-kind="agent"
                data-exec-id="<?= (int) $r['id'] ?>" data-exec-name="<?= htmlspecialchars($name) ?>">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i> Decline
        </button>
      <?php elseif ($status === 'verified'): ?>
        <button class="btn btn-warn btn-sm" type="button" data-reason-modal="suspend" data-reason-kind="agent"
                data-exec-id="<?= (int) $r['id'] ?>" data-exec-name="<?= htmlspecialchars($name) ?>">
          <i class="fa-solid fa-pause" aria-hidden="true"></i> Suspend
        </button>
      <?php elseif ($status === 'suspended'): ?>
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="reinstate">
          <input type="hidden" name="member_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-success btn-sm" type="submit" <?= !$sum['room'] ? 'disabled title="Your network is full"' : '' ?>>Reinstate</button>
        </form>
      <?php endif; ?>
      <?php if ($status === 'invited'): ?>
        <form method="POST" action="<?= $self ?>" data-confirm="Withdraw the invitation to <?= htmlspecialchars($name, ENT_QUOTES) ?>?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="member_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Withdraw</button>
        </form>
      <?php elseif ($status !== 'pending'): ?>
        <form method="POST" action="<?= $self ?>"
              data-confirm="<?= $status === 'rejected' ? 'Clear ' . htmlspecialchars($name, ENT_QUOTES) . ' from this list? They can apply again later.' : 'Remove ' . htmlspecialchars($name, ENT_QUOTES) . ' from your network? Cars on their desk stay there.' ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="member_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"><?= $status === 'rejected' ? 'Clear' : 'Remove' ?></button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>

  <?php if ($by['pending'] && !$sum['room']): ?>
  <div class="alert alert-warn dnet-gap">
    <i class="fa-solid fa-circle-exclamation alert-icon" aria-hidden="true"></i>
    <div>Your network is full (<?= htmlspecialchars(sdLimitText($sum['limit'], 'max_network_brokers')) ?> brokers on the <?= htmlspecialchars($plan['name'] ?? 'Free') ?> plan).
      Remove a broker to approve another, or ask SalesDesk about a bigger plan.</div>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'find'): ?>

  <?php if (!$sum['can_invite']): ?>
  <div class="alert alert-info dnet-gap">
    <i class="fa-solid fa-envelope-open-text alert-icon" aria-hidden="true"></i>
    <div>Browse brokers on any plan. <strong>Inviting</strong> them is part of the Pro and Enterprise business plans. <a href="/app/dealer/plan">See your plan</a> to upgrade.</div>
  </div>
  <?php elseif (!$sum['invites_ready']): ?>
  <div class="alert alert-info dnet-gap"><i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i>
    <div>Invitations switch on shortly. You can browse brokers now.</div></div>
  <?php elseif (!$inviteRoom): ?>
  <div class="alert alert-warn dnet-gap"><i class="fa-solid fa-circle-exclamation alert-icon" aria-hidden="true"></i>
    <div><?= $sum['invites_today'] >= SD_NETWORK_INVITES_PER_DAY
        ? 'That’s ' . SD_NETWORK_INVITES_PER_DAY . ' invitations in the last 24 hours. You can send more tomorrow.'
        : 'Your network is full, counting open invitations. Withdraw an invitation or remove a broker to invite more.' ?></div></div>
  <?php endif; ?>

  <form method="GET" action="<?= $self ?>" class="dnet-search">
    <input type="hidden" name="tab" value="find">
    <label class="sr-only" for="dnet-q">Search brokers</label>
    <input class="finput" id="dnet-q" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name or desk">
    <label class="sr-only" for="dnet-prov">Province</label>
    <select class="finput" id="dnet-prov" name="province">
      <option value="">All provinces</option>
      <?php foreach ($provs as $p): ?>
      <option value="<?= htmlspecialchars($p) ?>" <?= $p === $province ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
  </form>

  <p class="dnet-count"><?= number_format($browse['total'], 0, '.', ' ') ?> broker<?= $browse['total'] === 1 ? '' : 's' ?><?= $q !== '' || $province !== '' ? ' match' : ' on SalesDesk' ?></p>

  <?php if (!$browse['rows']): ?>
    <div class="empty"><span class="empty-icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>No brokers match that search.</div>
  <?php else: ?>
  <div class="net-grid">
    <?php foreach ($browse['rows'] as $b):
      $name = $personName($b);
      $st   = $b['my_status']; ?>
    <article class="net-card dnet-broker">
      <div class="net-card-head">
        <span class="net-logo net-logo-fallback" aria-hidden="true"><?= htmlspecialchars($initials($name)) ?></span>
        <div class="net-card-title">
          <h3 class="net-name"><?= htmlspecialchars($name) ?></h3>
          <p class="net-meta">
            <a href="/<?= htmlspecialchars($b['desk_slug']) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($b['desk_name']) ?> ↗</a>
          </p>
        </div>
      </div>
      <p class="net-meta"><?= htmlspecialchars(implode(', ', array_filter([$b['city'], $b['province']])) ?: 'South Africa') ?></p>
      <dl class="net-stats">
        <div><dt>Cars</dt><dd><?= (int) $b['desk_cars'] ?></dd></div>
        <div><dt>Deals</dt><dd><?= (int) $b['deals_closed'] ?></dd></div>
        <div><dt>Networks</dt><dd><?= (int) $b['networks'] ?></dd></div>
      </dl>
      <div class="net-card-foot">
        <?php if ($st && $st !== 'rejected'): ?>
          <span class="net-badge <?= ['verified' => 'net-badge-ok', 'pending' => 'net-badge-wait', 'invited' => 'net-badge-invite'][$st] ?? 'net-badge-muted' ?>">
            <?= ['verified' => 'In your network', 'pending' => 'Applied — review it', 'invited' => 'Invited', 'suspended' => 'Suspended'][$st] ?? ucfirst($st) ?>
          </span>
        <?php elseif ($canWrite && $canInvite): ?>
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="invite">
          <input type="hidden" name="broker_id" value="<?= (int) $b['user_id'] ?>">
          <input type="hidden" name="return_qs" value="<?= htmlspecialchars($findQs) ?>">
          <button class="btn btn-primary btn-sm btn-full" type="submit" <?= !$inviteRoom ? 'disabled' : '' ?>>
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Invite to network
          </button>
        </form>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <?php if ($browse['pages'] > 1): ?>
  <nav class="dnet-pager" aria-label="Pages">
    <?php for ($i = 1; $i <= $browse['pages']; $i++):
      $qs = http_build_query(array_filter(['tab' => 'find', 'q' => $q, 'province' => $province, 'page' => $i > 1 ? $i : null])); ?>
      <a href="<?= $self . '?' . htmlspecialchars($qs) ?>" class="dnet-page<?= $i === $browse['page'] ? ' is-active' : '' ?>"
         <?= $i === $browse['page'] ? 'aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>

<?php elseif ($tab === 'perks'): /* 4a */ ?>
  <?php require __DIR__ . '/../../views/partials/dealer-network-perks.php'; ?>
<?php else: /* dashboard (4b) */ ?>
  <?php require __DIR__ . '/../../views/partials/dealer-network-dashboard.php'; ?>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../views/partials/admin/exec-reason-modal.php'; ?>
<?php
$pageContent = ob_get_clean();
$pageStyles  = ['/assets/css/networks.css', '/assets/css/dealer-network.css'];
$pageScripts = ['/assets/js/admin.js', '/assets/js/dealer-perks.js'];   // shared data-confirm + reason modal handlers; 4a perks + 4b announcement form
require_once '../../views/layout-app.php';
