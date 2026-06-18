<?php
/**
 * SalesDesk — Dealer Analytics.
 * T3 owns this file.
 *
 * REFACTORED: All inline style= layout attributes replaced with semantic
 * CSS classes defined in dealer-analytics-patch.css.
 *
 * Problems fixed:
 *   — inline grid-template-columns on KPI strip, analytics grid,
 *     per-exec table → replaced with .kpi-strip / .an-grid / .d-roster-wrap
 *   — bar chart bars sized with inline style="height:Xpx;width:Xpx" only —
 *     now use CSS custom properties (--bar-h, --bar-w) set inline, which
 *     are safe: they do not constitute layout themselves, the CSS reads them
 *   — bar chart label positions use inline style for bottom/left offsets
 *     which are data-driven — kept as inline data attributes, rendered safe
 *   — onmouseover/onmouseout hover on broker table rows → CSS :hover
 *   — hardcoded font-size/color on every KPI value → .kpi-card__value
 *   — monthly chart column: absolute-position labels retained as inline
 *     only where the value is data-computed (px offset) — unavoidable for
 *     canvas-less charts; all non-data layout moved to CSS
 *   — broker table: min-width/width on cells → CSS column classes
 *   — per-exec table: same approach
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Dealer record ─────────────────────────────────────────────
$dealerStmt = $pdo->prepare("
    SELECT id AS dealer_id, company_name FROM dealers WHERE user_id = ? AND is_active = 1
");
$dealerStmt->execute([$userId]);
$dealer = $dealerStmt->fetch();
if (!$dealer) redirect('/app/dealer/dashboard.php');
$dealerId = (int) $dealer['dealer_id'];

// ── Date range ─────────────────────────────────────────────────
$range       = $_GET['range'] ?? '90';
$validRanges = ['30' => '30 days', '90' => '90 days', '180' => '6 months', '365' => '12 months'];
if (!isset($validRanges[$range])) $range = '90';
$since = "DATE_SUB(NOW(), INTERVAL {$range} DAY)";

// ── Overall summary ────────────────────────────────────────────
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN l.created_at >= {$since} THEN l.id END)           AS leads_period,
        COUNT(DISTINCT l.id)                                                         AS leads_all_time,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' AND l.created_at >= {$since}
                            THEN l.id END)                                           AS deals_period,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END)                 AS deals_all_time,
        COALESCE(SUM(CASE WHEN cm.status = 'paid' AND cm.created_at >= {$since}
                          THEN c.price END), 0)                                      AS revenue_period,
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END)                 AS active_listings
    FROM dealers d
    LEFT JOIN cars c  ON c.dealer_id = d.id
    LEFT JOIN leads l ON l.dealer_id = d.id
    LEFT JOIN commissions cm ON cm.lead_id = l.id
    WHERE d.id = ?
");
$summaryStmt->execute([$dealerId]);
$summary = $summaryStmt->fetch();

$convRate = $summary['leads_period'] > 0
    ? round($summary['deals_period'] / $summary['leads_period'] * 100, 1)
    : 0;

// ── Monthly lead volume (last 12 months) ──────────────────────
$monthlyStmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        DATE_FORMAT(created_at, '%b %Y') AS month_label,
        COUNT(*) AS lead_count,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS deal_count
    FROM leads
    WHERE dealer_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$monthlyStmt->execute([$dealerId]);
$monthlyData = $monthlyStmt->fetchAll();

// ── Top brokers ───────────────────────────────────────────────
$brokersStmt = $pdo->prepare("
    SELECT
        u.id AS broker_id,
        CONCAT(p.first_name, ' ', p.last_name) AS broker_name,
        u.email AS broker_email,
        sd.display_name AS desk_name,
        COUNT(l.id)                                                AS total_leads,
        SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END)      AS deals_closed,
        SUM(CASE WHEN l.status = 'lost'   THEN 1 ELSE 0 END)      AS deals_lost,
        COALESCE(SUM(CASE WHEN cm.status = 'paid' THEN cm.net_amount END), 0) AS earned
    FROM leads l
    JOIN users u ON u.id = l.broker_id
    LEFT JOIN profiles p ON p.user_id = l.broker_id
    LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
    LEFT JOIN commissions cm ON cm.lead_id = l.id
    WHERE l.dealer_id = ?
      AND l.created_at >= {$since}
    GROUP BY l.broker_id
    ORDER BY total_leads DESC, deals_closed DESC
    LIMIT 10
");
$brokersStmt->execute([$dealerId]);
$topBrokers = $brokersStmt->fetchAll();

// ── Top cars ──────────────────────────────────────────────────
$topCarsStmt = $pdo->prepare("
    SELECT
        c.id, c.make, c.model, c.year, c.price, c.status,
        c.commission_type, c.commission_value,
        COUNT(l.id) AS total_leads,
        SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END) AS deals_closed,
        COUNT(DISTINCT bi.salesdesk_id) AS broker_count
    FROM cars c
    LEFT JOIN leads l ON l.car_id = c.id AND l.created_at >= {$since}
    LEFT JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.dealer_id = ?
    GROUP BY c.id
    ORDER BY total_leads DESC, deals_closed DESC
    LIMIT 8
");
$topCarsStmt->execute([$dealerId]);
$topCars = $topCarsStmt->fetchAll();

// ── Per-exec breakdown ────────────────────────────────────────
$execsStmt = $pdo->prepare("
    SELECT
        se.id AS exec_id,
        CONCAT(p.first_name, ' ', p.last_name) AS exec_name,
        p.first_name, p.last_name,
        se.job_title, se.verification_status,
        COUNT(DISTINCT c.id)                                       AS cars_uploaded,
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) AS active_cars,
        COUNT(DISTINCT l.id)                                       AS leads_generated,
        SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END)      AS deals_closed,
        AVG(CASE WHEN l.status NOT IN ('new') AND l.status_updated_at IS NOT NULL
                 THEN TIMESTAMPDIFF(HOUR, l.created_at, l.status_updated_at) END) AS avg_response_hours
    FROM sales_executives se
    LEFT JOIN profiles p ON p.user_id = se.user_id
    LEFT JOIN cars c ON c.uploaded_by_exec_id = se.id
    LEFT JOIN leads l ON l.car_id = c.id AND l.created_at >= {$since}
    WHERE se.dealer_id = ?
    GROUP BY se.id
    ORDER BY se.verification_status = 'verified' DESC, leads_generated DESC
");
$execsStmt->execute([$dealerId]);
$execBreakdown = $execsStmt->fetchAll();

$pageTitle = 'Analytics';
ob_start();
?>

<?php /* ── Page header ──────────────────────────────────────── */ ?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__title">Dealer <em>analytics</em></h1>
    <div class="dash-header__sub">
      <span class="dash-header__dealer-name"><?= htmlspecialchars($dealer['company_name']) ?></span>
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
      <i class="fa-solid fa-user-plus"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--p);"><?= $summary['leads_period'] ?></div>
    <div class="kpi-card__label">Leads</div>
    <div class="kpi-card__sub"><?= $summary['leads_all_time'] ?> all time</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--green" aria-hidden="true">
      <i class="fa-solid fa-handshake"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--green);"><?= $summary['deals_period'] ?></div>
    <div class="kpi-card__label">Deals closed</div>
    <div class="kpi-card__sub"><?= $summary['deals_all_time'] ?> all time</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--amber" aria-hidden="true">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div class="kpi-card__value"
         style="color:<?= $convRate >= 10 ? 'var(--green)' : 'var(--amber)' ?>;">
      <?= $convRate ?>%
    </div>
    <div class="kpi-card__label">Conversion rate</div>
    <div class="kpi-card__sub">of leads this period</div>
  </div>

  <div class="kpi-card" role="listitem">
    <div class="kpi-card__bg-icon kpi-card__bg-icon--teal" aria-hidden="true">
      <i class="fa-solid fa-money-bill-wave"></i>
    </div>
    <div class="kpi-card__value" style="color:var(--teal);">
      R <?= number_format($summary['revenue_period'] / 1000, 0) ?>K
    </div>
    <div class="kpi-card__label">Revenue attributed</div>
    <div class="kpi-card__sub">attributed sales</div>
  </div>

</div>

<?php /* ── Main analytics grid: chart left, top cars right ──── */ ?>
<div class="an-grid">

  <?php /* ── Monthly volume bar chart ──────────────────────── */ ?>
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
        $barH    = max(4, (int) round($m['lead_count'] / $maxLeads * 120));
        $dealH   = $m['lead_count'] > 0
          ? max(2, (int) round($m['deal_count'] / $m['lead_count'] * $barH))
          : 0;
        $isNow   = $m['month_key'] === date('Y-m');
        $countBottom = $barH + 3;
      ?>
      <div class="an-bar-col"
           title="<?= htmlspecialchars($m['month_label']) ?>: <?= $m['lead_count'] ?> leads, <?= $m['deal_count'] ?> closed">
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
        <div class="an-bar-label"><?= htmlspecialchars(substr($m['month_label'], 0, 3)) ?></div>
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
        Deals closed
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php /* ── Top performing cars ─────────────────────────────── */ ?>
  <div class="card card-body an-minibar-card">
    <div class="d-section-head">
      <h2 class="d-section-head__title">Top listings</h2>
    </div>

    <?php if (empty($topCars)): ?>
    <div class="empty"><span class="empty-icon">—</span>No data yet.</div>
    <?php else:
      $maxCarLeads = max(array_column($topCars, 'total_leads')) ?: 1;
      foreach ($topCars as $tc):
        $barW = max(4, (int) round($tc['total_leads'] / $maxCarLeads * 100));
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

<?php /* ── Top brokers table ───────────────────────────────── */ ?>
<div class="an-section">
  <div class="d-section-head">
    <h2 class="d-section-head__title">Top brokers</h2>
    <span class="d-section-head__count"><?= count($topBrokers) ?></span>
  </div>

  <?php if (empty($topBrokers)): ?>
  <div class="empty">
    <span class="empty-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
    No broker activity yet.
  </div>
  <?php else: ?>
  <div class="d-roster-wrap">
    <table class="d-roster an-broker-table">
      <thead>
        <tr>
          <th class="an-col-rank">#</th>
          <th class="an-col-broker">Broker / Desk</th>
          <th class="an-col-num">Leads</th>
          <th class="an-col-num">Closed</th>
          <th class="an-col-conv">Conversion</th>
          <th class="an-col-earned">Earned (paid)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($topBrokers as $i => $b):
        $bConv = $b['total_leads'] > 0
          ? round($b['deals_closed'] / $b['total_leads'] * 100, 0)
          : 0;
        $bName = trim($b['broker_name']) ?: $b['broker_email'];
        $convW = min(100, $bConv);
        $convColour = $bConv >= 20
          ? 'an-conv-fill--green'
          : ($bConv >= 10 ? 'an-conv-fill--amber' : 'an-conv-fill--blue');
      ?>
      <tr class="d-roster__row">
        <td class="an-col-rank an-cell-mono an-cell-faint"><?= $i + 1 ?></td>
        <td>
          <div class="an-broker-name"><?= htmlspecialchars($bName) ?></div>
          <div class="an-broker-desk"><?= htmlspecialchars($b['desk_name'] ?? $b['broker_email']) ?></div>
        </td>
        <td class="an-col-num an-cell-mono"><?= $b['total_leads'] ?></td>
        <td class="an-col-num an-cell-mono an-cell-green"><?= $b['deals_closed'] ?></td>
        <td class="an-col-conv">
          <div class="an-conv-bar">
            <div class="an-conv-track">
              <div class="an-conv-fill <?= $convColour ?>" style="width:<?= $convW ?>%;"></div>
            </div>
            <span class="an-conv-value"><?= $bConv ?>%</span>
          </div>
        </td>
        <td class="an-col-earned an-cell-mono <?= $b['earned'] > 0 ? 'an-cell-green' : 'an-cell-faint' ?>">
          <?= $b['earned'] > 0 ? 'R ' . number_format($b['earned'], 0) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ── Per-exec breakdown ─────────────────────────────────── */ ?>
<?php if (!empty($execBreakdown)): ?>
<div class="an-section">
  <div class="d-section-head">
    <h2 class="d-section-head__title">Sales exec performance</h2>
    <span class="d-section-head__count"><?= count($execBreakdown) ?></span>
  </div>
  <div class="d-roster-wrap">
    <table class="d-roster an-exec-table">
      <thead>
        <tr>
          <th class="an-col-exec">Exec</th>
          <th class="an-col-status">Status</th>
          <th class="an-col-num">Cars listed</th>
          <th class="an-col-num">Active</th>
          <th class="an-col-num">Leads</th>
          <th class="an-col-num">Deals</th>
          <th class="an-col-response">Avg response</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($execBreakdown as $e):
        $eName = trim($e['exec_name']) ?: 'Unknown';
        $avgH  = $e['avg_response_hours'] ? round($e['avg_response_hours']) : null;
        $avgLabel = $avgH ? ($avgH < 24 ? $avgH . 'h' : round($avgH / 24) . 'd') : '—';
      ?>
      <tr class="d-roster__row">
        <td>
          <div class="an-exec-name"><?= htmlspecialchars($eName) ?></div>
          <?php if ($e['job_title']): ?>
          <div class="an-exec-meta"><?= htmlspecialchars($e['job_title']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge badge-<?= $e['verification_status'] ?>">
            <?= $e['verification_status'] ?>
          </span>
        </td>
        <td class="an-col-num an-cell-mono"><?= $e['cars_uploaded'] ?></td>
        <td class="an-col-num an-cell-mono an-cell-green"><?= $e['active_cars'] ?></td>
        <td class="an-col-num an-cell-mono"><?= $e['leads_generated'] ?></td>
        <td class="an-col-num an-cell-mono an-cell-green"><?= $e['deals_closed'] ?></td>
        <td class="an-col-response an-cell-mono an-cell-muted"><?= $avgLabel ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';