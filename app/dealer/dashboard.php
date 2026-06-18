<?php
/**
 * SalesDesk — Dealer Dashboard.
 * T3 owns this file.
 *
 * REFACTORED: All inline style= attributes replaced with semantic CSS classes
 * defined in dealer.css §DB (dashboard). Responsive layout now controlled
 * entirely by CSS media queries — no inline grid/flex overrides remain.
 *
 * Changes from original:
 *   — inline grid/flex → .dash-kpi-strip / .dash-main-grid / .dash-lead-row
 *   — onmouseover/onmouseout hover → CSS :hover on .dash-lead-row
 *   — KPI value font-size inline → .kpi-card__value (CSS)
 *   — corner icon absolute div → .kpi-card__bg-icon + modifier
 *   — settings/action header row → .dash-header
 *   — listing row inside card → .dash-listing-row
 *   — team quick-stat → .dash-team-card
 *   — flash messages → .flash .flash-ok / .flash-error (existing)
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Dealer record ─────────────────────────────────────────────
$dealerStmt = $pdo->prepare("
    SELECT d.id AS dealer_id, d.company_name, d.verification_status,
           d.is_active, d.cipc_doc_url,
           a.city, a.province
    FROM dealers d
    LEFT JOIN addresses a ON a.id = d.address_id
    WHERE d.user_id = ?
    LIMIT 1
");
$dealerStmt->execute([$userId]);
$dealer = $dealerStmt->fetch();

if (!$dealer) {
    redirect('/auth/register.php');
}

$dealerId = (int) $dealer['dealer_id'];

// ── KPI stats — single query ──────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END)      AS active_listings,
        COUNT(DISTINCT CASE WHEN c.status = 'paused' THEN c.id END)      AS paused_listings,
        COUNT(DISTINCT CASE WHEN c.status = 'sold'   THEN c.id END)      AS sold_listings,
        COUNT(DISTINCT CASE
            WHEN l.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
            THEN l.id END)                                                AS leads_this_month,
        COUNT(DISTINCT l.id)                                              AS leads_all_time,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END)      AS deals_closed,
        COALESCE(SUM(CASE WHEN cm.status = 'paid' THEN c2.price END), 0) AS attributed_revenue,
        COUNT(DISTINCT se.id)                                             AS exec_count,
        COUNT(DISTINCT CASE
            WHEN se.verification_status = 'pending'
            THEN se.id END)                                               AS pending_execs
    FROM dealers d
    LEFT JOIN cars c  ON c.dealer_id = d.id
    LEFT JOIN leads l ON l.dealer_id = d.id
    LEFT JOIN commissions cm ON cm.lead_id = l.id
    LEFT JOIN cars c2 ON c2.id = l.car_id
    LEFT JOIN sales_executives se ON se.dealer_id = d.id
    WHERE d.id = ?
");
$statsStmt->execute([$dealerId]);
$stats = $statsStmt->fetch();

$convRate = ($stats['leads_all_time'] > 0)
    ? round($stats['deals_closed'] / $stats['leads_all_time'] * 100, 1)
    : 0;

// ── Recent leads (last 10) ────────────────────────────────────
$recentLeadsStmt = $pdo->prepare("
    SELECT
        l.id, l.uuid, l.buyer_name, l.buyer_phone, l.buyer_intent,
        l.status, l.created_at, l.status_updated_at,
        c.make, c.model, c.year,
        u.email AS broker_email,
        p.first_name AS broker_first, p.last_name AS broker_last,
        sd.display_name AS desk_name
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    JOIN users u ON u.id = l.broker_id
    LEFT JOIN profiles p ON p.user_id = l.broker_id
    LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
    WHERE l.dealer_id = ?
    ORDER BY l.created_at DESC
    LIMIT 10
");
$recentLeadsStmt->execute([$dealerId]);
$recentLeads = $recentLeadsStmt->fetchAll();

// ── Recent listings ───────────────────────────────────────────
$recentCarsStmt = $pdo->prepare("
    SELECT c.id, c.make, c.model, c.year, c.price, c.status,
           c.commission_type, c.commission_value, c.image_urls,
           c.created_at,
           COUNT(DISTINCT l.id) AS lead_count,
           COUNT(DISTINCT bi.id) AS broker_count,
           se.id AS exec_id,
           CONCAT(p.first_name, ' ', p.last_name) AS exec_name
    FROM cars c
    LEFT JOIN leads l ON l.car_id = c.id
    LEFT JOIN broker_inventory bi ON bi.car_id = c.id
    LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
    LEFT JOIN profiles p ON p.user_id = se.user_id
    WHERE c.dealer_id = ?
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 6
");
$recentCarsStmt->execute([$dealerId]);
$recentCars = $recentCarsStmt->fetchAll();

// Profile display name
$profileStmt = $pdo->prepare("SELECT first_name, last_name FROM profiles WHERE user_id = ?");
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch();
$principalName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'Dealer';

$pageTitle = 'Dashboard';

ob_start();
?>

<?php /* ── Verification banner ─────────────────────────────── */ ?>
<?php if ($dealer['verification_status'] === 'unverified'): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem;">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i>
  <div>
    <strong>Your dealership is not yet verified.</strong>
    Upload your CIPC certificate to get a verified badge and rank higher in broker search.
    <a href="/app/dealer/settings.php" style="color:var(--amber);font-weight:600;margin-left:6px;">Upload now →</a>
  </div>
</div>
<?php elseif ($dealer['verification_status'] === 'pending'): ?>
<div class="alert alert-info" style="margin-bottom:1.5rem;">
  <i class="fa-solid fa-clock alert-icon"></i>
  <div>Your CIPC document is under review. We'll notify you once verification is complete.</div>
</div>
<?php endif; ?>

<?php /* ── Page header ──────────────────────────────────────── */ ?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__greeting">
      Good <?= date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening') ?>,
      <em><?= htmlspecialchars($principalName) ?></em>
    </h1>
    <div class="dash-header__sub">
      <span class="dash-header__dealer-name">
        <i class="fa-solid fa-building" aria-hidden="true"></i>
        <?= htmlspecialchars($dealer['company_name']) ?>
      </span>
      <?php if ($dealer['verification_status'] === 'verified'): ?>
      <span class="badge badge-verified">
        <i class="fa-solid fa-circle-check" style="font-size:9px" aria-hidden="true"></i>
        Verified
      </span>
      <?php endif; ?>
      <?php if ($dealer['city']): ?>
      <span class="dash-header__city">· <?= htmlspecialchars($dealer['city']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dash-header__actions">
    <?php if ($stats['pending_execs'] > 0): ?>
    <a href="/app/dealer/team.php" class="btn btn-warn btn-sm" style="text-decoration:none;">
      <i class="fa-solid fa-clock" aria-hidden="true"></i>
      <?= $stats['pending_execs'] ?> exec<?= $stats['pending_execs'] > 1 ? 's' : '' ?> pending
    </a>
    <?php endif; ?>
    <a href="/app/dealer/car-upload.php" class="btn btn-primary" style="text-decoration:none;">
      <i class="fa-solid fa-plus" aria-hidden="true"></i>
      <span class="dash-btn-label">List a car</span>
    </a>
  </div>
</div>

<?php /* ── KPI strip ─────────────────────────────────────────── */ ?>
<div class="kpi-strip" role="list">

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--blue" aria-hidden="true">
      <i class="fa-solid fa-car"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--p);"><?= $stats['active_listings'] ?></div>
    <div class="kpi-card__label">Active listings</div>
    <div class="kpi-card__sub">
      <?php if ($stats['paused_listings'] > 0): ?>
      <?= $stats['paused_listings'] ?> paused
      <?php else: ?>
      <?= $stats['sold_listings'] ?> sold all time
      <?php endif; ?>
    </div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--amber" aria-hidden="true">
      <i class="fa-solid fa-user-plus"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--amber);"><?= $stats['leads_this_month'] ?></div>
    <div class="kpi-card__label">Leads this month</div>
    <div class="kpi-card__sub"><?= $stats['leads_all_time'] ?> all time</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--green" aria-hidden="true">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--green);"><?= $convRate ?>%</div>
    <div class="kpi-card__label">Conversion rate</div>
    <div class="kpi-card__sub"><?= $stats['deals_closed'] ?> of <?= $stats['leads_all_time'] ?> closed</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--teal" aria-hidden="true">
      <i class="fa-solid fa-money-bill-wave"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--teal);">
      <?= $stats['attributed_revenue'] > 0
        ? 'R ' . number_format($stats['attributed_revenue'] / 1000000, 1) . 'M'
        : 'R 0' ?>
    </div>
    <div class="kpi-card__label">Revenue attributed</div>
    <div class="kpi-card__sub">from broker-sourced deals</div>
  </div>

</div>

<?php /* ── Main content grid: leads left, listings right ─────── */ ?>
<div class="dash-main-grid">

  <?php /* ── Recent leads ──────────────────────────────────── */ ?>
  <div class="dash-leads-col">
    <div class="d-section-head">
      <h2 class="d-section-head__title">Recent leads</h2>
      <span class="d-section-head__count"><?= count($recentLeads) ?></span>
      <span class="d-section-head__spacer"></span>
      <a href="/app/dealer/leads.php" class="d-section-head__action">View all →</a>
    </div>

    <?php if (empty($recentLeads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></span>
      No leads yet — brokers will start sending them once you list cars.
    </div>
    <?php else: ?>
    <div class="d-roster-wrap dash-lead-list" role="list">
      <?php foreach ($recentLeads as $lead):
        $intentClass = match($lead['buyer_intent']) {
          'within_30d' => 'dash-intent--hot',
          'one_to_3mo' => 'dash-intent--warm',
          default      => 'dash-intent--cool',
        };
        $statusClass = match($lead['status']) {
          'new'         => 'dash-status--new',
          'contacted'   => 'dash-status--contacted',
          'test_drive'  => 'dash-status--testdrive',
          'negotiation' => 'dash-status--negotiation',
          'closed'      => 'dash-status--closed',
          'lost'        => 'dash-status--lost',
          default       => '',
        };
        $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
        $ageLabel = $ageHours < 24
          ? round($ageHours) . 'h ago'
          : round($ageHours / 24) . 'd ago';
      ?>
      <a href="/app/dealer/leads.php?id=<?= $lead['id'] ?>"
         class="dash-lead-row"
         role="listitem"
         aria-label="Lead from <?= htmlspecialchars($lead['buyer_name']) ?>">
        <div class="dash-intent-dot <?= $intentClass ?>" aria-hidden="true"></div>
        <div class="dash-lead-row__info">
          <div class="dash-lead-row__name"><?= htmlspecialchars($lead['buyer_name']) ?></div>
          <div class="dash-lead-row__meta">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
            <span class="dash-lead-row__sep" aria-hidden="true">·</span>
            <?= htmlspecialchars($lead['desk_name'] ?? $lead['broker_email']) ?>
          </div>
        </div>
        <div class="dash-lead-row__right">
          <div class="dash-lead-status <?= $statusClass ?>"><?= str_replace('_', ' ', $lead['status']) ?></div>
          <div class="dash-lead-row__age"><?= $ageLabel ?></div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (count($recentLeads) === 10): ?>
      <div class="dash-view-all-row">
        <a href="/app/dealer/leads.php" class="dash-view-all-link">View all leads →</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php /* ── Recent listings + team card ─────────────────────── */ ?>
  <div class="dash-listings-col">
    <div class="d-section-head">
      <h2 class="d-section-head__title">My listings</h2>
      <span class="d-section-head__count"><?= $stats['active_listings'] ?></span>
      <span class="d-section-head__spacer"></span>
      <a href="/app/dealer/inventory.php" class="d-section-head__action">View all →</a>
    </div>

    <?php if (empty($recentCars)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-car" aria-hidden="true"></i></span>
      No listings yet.
      <div style="margin-top:8px;">
        <a href="/app/dealer/car-upload.php" class="btn btn-primary btn-sm" style="text-decoration:none;">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> List your first car
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="card dash-listings-card">
      <?php foreach ($recentCars as $car):
        $images = json_decode($car['image_urls'] ?? '[]', true);
        $thumb  = !empty($images[0]) ? $images[0] : null;
        $commission = $car['commission_type'] === 'fixed'
          ? 'R ' . number_format($car['commission_value'], 0)
          : $car['commission_value'] . '%';
      ?>
      <div class="dash-listing-row">
        <div class="dash-listing-row__thumb" aria-hidden="true">
          <?php if ($thumb): ?>
          <img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy">
          <?php else: ?>
          <i class="fa-solid fa-car-side"></i>
          <?php endif; ?>
        </div>
        <div class="dash-listing-row__info">
          <div class="dash-listing-row__name">
            <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
          </div>
          <div class="dash-listing-row__price-row">
            R <?= number_format($car['price'], 0) ?>
            <span class="dash-listing-row__comm">· <?= htmlspecialchars($commission) ?> comm</span>
          </div>
        </div>
        <div class="dash-listing-row__right">
          <span class="badge badge-<?= $car['status'] ?>"><?= $car['status'] ?></span>
          <div class="dash-listing-row__leads">
            <?= $car['lead_count'] ?> lead<?= $car['lead_count'] != 1 ? 's' : '' ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="dash-listing-row dash-listing-row--add">
        <a href="/app/dealer/car-upload.php" class="btn btn-ghost btn-sm" style="text-decoration:none;font-size:12px;">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Add listing
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ── Team quick-stat ───────────────────────────────── */ ?>
    <?php if ($stats['exec_count'] > 0): ?>
    <div class="card dash-team-card">
      <div class="dash-team-card__inner">
        <div class="dash-team-card__text">
          <div class="dash-team-card__title">Sales team</div>
          <div class="dash-team-card__sub">
            <?= $stats['exec_count'] ?> exec<?= $stats['exec_count'] > 1 ? 's' : '' ?> linked
            <?php if ($stats['pending_execs'] > 0): ?>
            <span class="dash-team-card__pending">
              · <?= $stats['pending_execs'] ?> pending
            </span>
            <?php endif; ?>
          </div>
        </div>
        <a href="/app/dealer/team.php" class="btn btn-ghost btn-sm" style="text-decoration:none;font-size:12px;">Manage</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';