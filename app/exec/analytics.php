<?php
/**
 * SalesDesk — Sales Exec Analytics.
 * T3 owns this file.
 *
 * REFACTORED: All inline style= layout attributes replaced with semantic
 * CSS classes defined in dealer-analytics-patch.css.
 *
 * Problems fixed (same root causes as dealer analytics):
 *   — inline KPI grid → .kpi-strip
 *   — inline analytics 2-col grid → .an-grid
 *   — bar chart: data-driven height values kept as CSS custom properties,
 *     all non-data layout moved to .an-bar-chart / .an-bar-col etc.
 *   — top listings mini-bars: inline width% kept (data-driven), layout → CSS
 *   — pipeline breakdown grid: inline grid-template-columns → .an-pipeline-grid
 *   — hardcoded font-size/color on KPI values → .kpi-card__value + inline color
 *   — range toggle: inline flex → .an-range-toggle
 *   — section heads: inline flex/padding → .d-section-head
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

// ── Date range ────────────────────────────────────────────────
$range       = $_GET['range'] ?? '90';
$validRanges = ['30'=>'30 days','90'=>'90 days','180'=>'6 months','365'=>'12 months'];
if (!isset($validRanges[$range])) $range = '90';
$since = "DATE_SUB(NOW(), INTERVAL {$range} DAY)";

// ── Summary stats — exec-scoped ───────────────────────────────
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) AS active_cars,
        COUNT(DISTINCT c.id)                                          AS total_cars,
        COUNT(DISTINCT CASE WHEN l.created_at >= {$since} THEN l.id END) AS leads_period,
        COUNT(DISTINCT l.id)                                          AS leads_all_time,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' AND l.created_at >= {$since}
                            THEN l.id END)                            AS deals_period,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END)  AS deals_all_time,
        AVG(CASE
            WHEN l.status != 'new' AND l.status_updated_at IS NOT NULL
            THEN TIMESTAMPDIFF(HOUR, l.created_at, l.status_updated_at)
        END) AS avg_response_hours
    FROM sales_executives se
    LEFT JOIN cars c ON c.uploaded_by_exec_id = se.id
    LEFT JOIN leads l ON l.car_id = c.id
    WHERE se.id = ?
");
$summaryStmt->execute([$execId]);
$summary = $summaryStmt->fetch();

$convRate   = $summary['leads_period'] > 0
    ? round($summary['deals_period'] / $summary['leads_period'] * 100, 1)
    : 0;
$avgH       = $summary['avg_response_hours'] ? round($summary['avg_response_hours']) : null;
$avgContact = $avgH ? ($avgH < 24 ? $avgH . 'h' : round($avgH / 24) . ' days') : '—';

// ── Monthly volume ─────────────────────────────────────────────
$monthlyStmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(l.created_at, '%Y-%m') AS month_key,
        DATE_FORMAT(l.created_at, '%b')     AS month_short,
        COUNT(*) AS lead_count,
        SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END) AS deal_count
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    WHERE c.uploaded_by_exec_id = ?
      AND l.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key, month_short
    ORDER BY month_key ASC
");
$monthlyStmt->execute([$execId]);
$monthlyData = $monthlyStmt->fetchAll();

// ── Top performing cars ────────────────────────────────────────
$topCarsStmt = $pdo->prepare("
    SELECT c.id, c.make, c.model, c.year, c.price, c.status,
           COUNT(DISTINCT l.id)  AS total_leads,
           SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END) AS deals_closed,
           COUNT(DISTINCT bi.salesdesk_id) AS broker_count
    FROM cars c
    LEFT JOIN leads l ON l.car_id = c.id AND l.created_at >= {$since}
    LEFT JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.uploaded_by_exec_id = ?
    GROUP BY c.id
    ORDER BY total_leads DESC, deals_closed DESC
    LIMIT 6
");
$topCarsStmt->execute([$execId]);
$topCars = $topCarsStmt->fetchAll();

// ── Pipeline breakdown ─────────────────────────────────────────
$pipelineStmt = $pdo->prepare("
    SELECT l.status, COUNT(*) AS cnt
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    WHERE c.uploaded_by_exec_id = ?
      AND l.created_at >= {$since}
    GROUP BY l.status
    ORDER BY FIELD(l.status,'new','contacted','test_drive','negotiation','closed','lost')
");
$pipelineStmt->execute([$execId]);
$pipelineRows = $pipelineStmt->fetchAll();

$pageTitle = 'Analytics';
ob_start();
?>

<?php /* ── Page header ──────────────────────────────────────── */ ?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__title">My <em>performance</em></h1>
    <div class="dash-header__sub">
      <span class="dash-header__dealer-name"><?= htmlspecialchars($exec['dealer_name']) ?></span>
      <span class="dash-header__city">· <?= htmlspecialchars($validRanges[$range]) ?></span>
    </div>
  </div>
  <div class="dash-header__actions">
    <div class="an-range-toggle" role="group" aria-label="Date range">
      <?php foreach ($validRanges as $val => $lbl): ?>
      <a href="?range=<?= $val ?>"
         class="btn btn-sm <?= $range === $val ? 'btn-primary' : 'btn-ghost' ?>"
         <?= $range === $val ? 'aria-current="true"' : '' ?>>
        <?= $lbl ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php /* ── KPI strip ─────────────────────────────────────────── */ ?>
<div class="kpi-strip" role="list">

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--blue" aria-hidden="true">
      <i class="fa-solid fa-car"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--p);"><?= $summary['active_cars'] ?></div>
    <div class="kpi-card__label">Active listings</div>
    <div class="kpi-card__sub"><?= $summary['total_cars'] ?> total uploaded</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--amber" aria-hidden="true">
      <i class="fa-solid fa-user-plus"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--amber);"><?= $summary['leads_period'] ?></div>
    <div class="kpi-card__label">Leads this period</div>
    <div class="kpi-card__sub"><?= $summary['leads_all_time'] ?> all time</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--<?= $convRate >= 15 ? 'green' : ($convRate >= 8 ? 'amber' : 'blue') ?>"
         aria-hidden="true">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div class="kpi-card__value"
         style="color:<?= $convRate >= 15 ? 'var(--green)' : ($convRate >= 8 ? 'var(--amber)' : 'var(--muted)') ?>;">
      <?= $convRate ?>%
    </div>
    <div class="kpi-card__label">Conversion rate</div>
    <div class="kpi-card__sub"><?= $summary['deals_period'] ?> deals closed</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--teal" aria-hidden="true">
      <i class="fa-solid fa-clock"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--teal);"><?= $avgContact ?></div>
    <div class="kpi-card__label">Avg time to contact</div>
    <div class="kpi-card__sub">after lead arrives</div>
  </div>

</div>

<?php /* ── Analytics grid: chart + top listings ─────────────── */ ?>
<div class="an-grid">

  <?php /* ── Monthly bar chart ────────────────────────────────── */ ?>
  <div class="card card-body an-chart-card">
    <div class="d-section-head">
      <h2 class="d-section-head__title">Monthly lead volume</h2>
    </div>

    <?php if (empty($monthlyData)): ?>
    <div class="empty"><span class="empty-icon">—</span>No data yet.</div>
    <?php else:
      $maxLeads = max(array_column($monthlyData, 'lead_count')) ?: 1;
    ?>
    <div class="an-bar-chart" role="img" aria-label="Monthly lead volume bar chart">
      <?php foreach ($monthlyData as $m):
        $barH  = max(4, (int) round($m['lead_count'] / $maxLeads * 120));
        $dealH = $m['lead_count'] > 0
          ? max(2, (int) round($m['deal_count'] / $m['lead_count'] * $barH))
          : 0;
        $isNow = $m['month_key'] === date('Y-m');
        $countBottom = $barH + 3;
      ?>
      <div class="an-bar-col"
           title="<?= htmlspecialchars($m['month_short']) ?>: <?= $m['lead_count'] ?> leads, <?= $m['deal_count'] ?> closed">
        <div class="an-bar-track">
          <div class="an-bar <?= $isNow ? 'an-bar--current' : 'an-bar--past' ?>"
               style="height:<?= $barH ?>px;">
            <?php if ($dealH > 0): ?>
            <div class="an-bar-fill" style="height:<?= $dealH ?>px;"></div>
            <?php endif; ?>
          </div>
          <?php if ($m['lead_count'] > 0): ?>
          <div class="an-bar-count" style="bottom:<?= $countBottom ?>px;">
            <?= $m['lead_count'] ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="an-bar-label"><?= htmlspecialchars($m['month_short']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="an-chart-legend">
      <div class="an-legend-item">
        <div class="an-legend-swatch an-legend-swatch--leads"></div>
        Leads
      </div>
      <div class="an-legend-item">
        <div class="an-legend-swatch an-legend-swatch--closed"></div>
        Closed
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php /* ── Top listings mini-bar card ───────────────────────── */ ?>
  <div class="card card-body an-minibar-card">
    <div class="d-section-head">
      <h2 class="d-section-head__title">My top listings</h2>
    </div>

    <?php if (empty($topCars)): ?>
    <div class="empty"><span class="empty-icon">—</span>No listings yet.</div>
    <?php else:
      $maxCL = max(array_column($topCars, 'total_leads')) ?: 1;
      foreach ($topCars as $tc):
        $barW = max(4, (int) round($tc['total_leads'] / $maxCL * 100));
    ?>
    <div class="an-minibar-row">
      <div class="an-minibar-row__header">
        <div class="an-minibar-row__name">
          <?= htmlspecialchars("{$tc['year']} {$tc['make']} {$tc['model']}") ?>
        </div>
        <div class="an-minibar-row__count">
          <?= $tc['total_leads'] ?> lead<?= $tc['total_leads'] != 1 ? 's' : '' ?>
        </div>
      </div>
      <div class="an-minibar-track">
        <div class="an-minibar-fill <?= $tc['deals_closed'] > 0 ? 'an-minibar-fill--green' : 'an-minibar-fill--blue' ?>"
             style="width:<?= $barW ?>%;"></div>
      </div>
      <div class="an-minibar-row__sub">
        <?= $tc['deals_closed'] ?> closed
        · <?= $tc['broker_count'] ?> broker<?= $tc['broker_count'] != 1 ? 's' : '' ?>
        · <span class="badge badge-<?= $tc['status'] ?>"><?= $tc['status'] ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div>

<?php /* ── Pipeline breakdown ───────────────────────────────── */ ?>
<?php if (!empty($pipelineRows)):
  $totalPL = array_sum(array_column($pipelineRows, 'cnt'));
?>
<div class="an-section">
  <div class="d-section-head">
    <h2 class="d-section-head__title">Pipeline breakdown</h2>
    <span class="d-section-head__count"><?= $totalPL ?> leads</span>
  </div>
  <div class="an-pipeline-grid">
    <?php foreach ($pipelineRows as $pr):
      $pct = round($pr['cnt'] / $totalPL * 100);
      $colourClass = match($pr['status']) {
        'new'         => 'an-pipeline-cell--blue',
        'contacted'   => 'an-pipeline-cell--amber',
        'test_drive'  => 'an-pipeline-cell--teal',
        'negotiation' => 'an-pipeline-cell--purple',
        'closed'      => 'an-pipeline-cell--green',
        'lost'        => 'an-pipeline-cell--faint',
        default       => '',
      };
    ?>
    <div class="an-pipeline-cell <?= $colourClass ?>">
      <div class="an-pipeline-cell__num"><?= $pr['cnt'] ?></div>
      <div class="an-pipeline-cell__label">
        <?= str_replace('_', ' ', $pr['status']) ?>
      </div>
      <div class="an-pipeline-cell__pct"><?= $pct ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';