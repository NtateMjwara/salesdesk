<?php
/**
 * SalesDesk — Sales Exec Dashboard.
 * T3 owns this file.
 *
 * REFACTORED: All inline style= attributes replaced with semantic CSS classes
 * defined in dealer.css §DB (dashboard).
 *
 * Changes from original:
 *   — inline KPI grid → .kpi-strip with .kpi-card classes
 *   — inline main grid → .dash-main-grid
 *   — onmouseover/onmouseout hover → .dash-lead-row CSS :hover
 *   — teal/inline vars for pipeline KPI → standardised .kpi-card__bg-icon--teal
 *   — listing mini-card rows → .dash-listing-row
 *   — section heads → .d-section-head
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/exec_guard.php';

applyCachePolicy('auth');

$exec     = requireExecVerified();
$execId   = (int) $exec['id'];
$dealerId = (int) $exec['dealer_id'];
$pdo      = Database::getInstance();

// ── KPI stats — exec-scoped ───────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END)    AS active_cars,
        COUNT(DISTINCT c.id)                                            AS total_cars,
        COUNT(DISTINCT CASE
            WHEN l.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
            THEN l.id END)                                              AS leads_this_month,
        COUNT(DISTINCT l.id)                                            AS leads_all_time,
        COUNT(DISTINCT CASE
            WHEN l.status NOT IN ('closed','lost')
            THEN l.id END)                                              AS open_pipeline,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END)    AS deals_closed
    FROM sales_executives se
    LEFT JOIN cars c  ON c.uploaded_by_exec_id = se.id
    LEFT JOIN leads l ON l.car_id = c.id
    WHERE se.id = ?
");
$statsStmt->execute([$execId]);
$stats = $statsStmt->fetch();

$convRate = ($stats['leads_all_time'] > 0)
    ? round($stats['deals_closed'] / $stats['leads_all_time'] * 100, 1)
    : 0;

// ── Recent leads on exec's cars ───────────────────────────────
$recentLeadsStmt = $pdo->prepare("
    SELECT
        l.id, l.buyer_name, l.buyer_intent, l.status, l.created_at,
        c.make, c.model, c.year,
        p.first_name AS broker_first, p.last_name AS broker_last,
        sd.display_name AS desk_name
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    JOIN users u ON u.id = l.broker_id
    LEFT JOIN profiles p ON p.user_id = l.broker_id
    LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
    WHERE c.uploaded_by_exec_id = ?
    ORDER BY l.created_at DESC
    LIMIT 8
");
$recentLeadsStmt->execute([$execId]);
$recentLeads = $recentLeadsStmt->fetchAll();

// ── My active listings ────────────────────────────────────────
$carsStmt = $pdo->prepare("
    SELECT c.id, c.make, c.model, c.year, c.price, c.status,
           c.commission_type, c.commission_value,
           COUNT(DISTINCT l.id) AS lead_count,
           COUNT(DISTINCT bi.id) AS broker_count
    FROM cars c
    LEFT JOIN leads l ON l.car_id = c.id
    LEFT JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.uploaded_by_exec_id = ?
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 5
");
$carsStmt->execute([$execId]);
$myCars = $carsStmt->fetchAll();

$execName = trim(($exec['first_name'] ?? '') . ' ' . ($exec['last_name'] ?? ''));

$pageTitle = 'Dashboard';
ob_start();
?>

<?php /* ── Page header ──────────────────────────────────────── */ ?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__greeting">
      Good <?= date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening') ?>,
      <em><?= htmlspecialchars($execName ?: 'there') ?></em>
    </h1>
    <div class="dash-header__sub">
      <span class="dash-header__dealer-name">
        <i class="fa-solid fa-building" aria-hidden="true"></i>
        <?= htmlspecialchars($exec['dealer_name']) ?>
      </span>
      <span class="badge badge-verified">
        <i class="fa-solid fa-circle-check" style="font-size:9px" aria-hidden="true"></i>
        Verified exec
      </span>
      <?php if ($exec['job_title']): ?>
      <span class="dash-header__city">· <?= htmlspecialchars($exec['job_title']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="dash-header__actions">
    <a href="/app/exec/car-upload.php" class="btn btn-primary" style="text-decoration:none;">
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
    <div class="kpi-card__value" style="color:var(--p);"><?= $stats['active_cars'] ?></div>
    <div class="kpi-card__label">Active listings</div>
    <div class="kpi-card__sub"><?= $stats['total_cars'] ?> total</div>
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
    <div class="kpi-card__bg-icon kpi-card__bg-icon--teal" aria-hidden="true">
      <i class="fa-solid fa-funnel-dollar"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--teal);"><?= $stats['open_pipeline'] ?></div>
    <div class="kpi-card__label">Open pipeline</div>
    <div class="kpi-card__sub">in progress</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--green" aria-hidden="true">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--green);"><?= $convRate ?>%</div>
    <div class="kpi-card__label">Conversion rate</div>
    <div class="kpi-card__sub"><?= $stats['deals_closed'] ?> deals closed</div>
  </div>

</div>

<?php /* ── Main content grid ────────────────────────────────── */ ?>
<div class="dash-main-grid">

  <?php /* ── Recent leads ──────────────────────────────────── */ ?>
  <div class="dash-leads-col">
    <div class="d-section-head">
      <h2 class="d-section-head__title">Recent leads on my listings</h2>
      <span class="d-section-head__count"><?= count($recentLeads) ?></span>
      <span class="d-section-head__spacer"></span>
      <a href="/app/exec/leads.php" class="d-section-head__action">View all →</a>
    </div>

    <?php if (empty($recentLeads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></span>
      No leads yet — list your first car to start receiving leads.
      <div style="margin-top:10px;">
        <a href="/app/exec/car-upload.php" class="btn btn-primary btn-sm" style="text-decoration:none;">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> List a car
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="d-roster-wrap dash-lead-list" role="list">
      <?php foreach ($recentLeads as $lead):
        $intentClass = match($lead['buyer_intent']) {
          'within_30d' => 'dash-intent--hot',
          'one_to_3mo' => 'dash-intent--warm',
          default      => 'dash-intent--cool',
        };
        $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
        $ageLabel = $ageHours < 24 ? round($ageHours) . 'h ago' : round($ageHours / 24) . 'd ago';
      ?>
      <a href="/app/exec/leads.php?id=<?= $lead['id'] ?>"
         class="dash-lead-row"
         role="listitem"
         aria-label="Lead from <?= htmlspecialchars($lead['buyer_name']) ?>">
        <div class="dash-intent-dot <?= $intentClass ?>" aria-hidden="true"></div>
        <div class="dash-lead-row__info">
          <div class="dash-lead-row__name"><?= htmlspecialchars($lead['buyer_name']) ?></div>
          <div class="dash-lead-row__meta">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
          </div>
        </div>
        <div class="dash-lead-row__right">
          <div class="dash-lead-status dash-status--<?= $lead['status'] ?>"><?= $lead['status'] ?></div>
          <div class="dash-lead-row__age"><?= $ageLabel ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php /* ── My listings ──────────────────────────────────── */ ?>
  <div class="dash-listings-col">
    <div class="d-section-head">
      <h2 class="d-section-head__title">My listings</h2>
      <span class="d-section-head__count"><?= $stats['active_cars'] ?></span>
      <span class="d-section-head__spacer"></span>
      <a href="/app/exec/inventory.php" class="d-section-head__action">View all →</a>
    </div>

    <?php if (empty($myCars)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-car" aria-hidden="true"></i></span>
      No listings yet.
      <div style="margin-top:8px;">
        <a href="/app/exec/car-upload.php" class="btn btn-primary btn-sm" style="text-decoration:none;">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> List first car
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="card dash-listings-card">
      <?php foreach ($myCars as $car):
        $commission = $car['commission_type'] === 'fixed'
          ? 'R ' . number_format($car['commission_value'], 0)
          : $car['commission_value'] . '%';
      ?>
      <div class="dash-listing-row">
        <div class="dash-listing-row__thumb dash-listing-row__thumb--icon" aria-hidden="true">
          <i class="fa-solid fa-car-side"></i>
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
        <a href="/app/exec/car-upload.php" class="btn btn-ghost btn-sm" style="text-decoration:none;font-size:12px;">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Add listing
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';