<?php
/**
 * SalesDesk — Dealer Analytics.
 * T3 owns this file.
 *
 * Task d7:
 *   - Top brokers by leads + conversion
 *   - Leads per car (top performers)
 *   - Month-over-month lead volume
 *   - Per-exec table: cars_uploaded, leads_generated, deals_closed
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
$range   = $_GET['range'] ?? '90';
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

// ── Top brokers by leads + conversion ────────────────────────
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

// ── Top cars by leads ─────────────────────────────────────────
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

<!-- ── Header ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;
            margin-bottom:1.75rem;flex-wrap:wrap;gap:10px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.5rem;font-weight:300;margin-bottom:2px;">
      Dealer <em style="font-style:italic;">analytics</em>
    </h1>
    <p style="font-size:13px;color:var(--muted);">
      <?= htmlspecialchars($dealer['company_name']) ?> · <?= $validRanges[$range] ?>
    </p>
  </div>
  <!-- Date range selector -->
  <form method="GET" style="display:flex;gap:4px;">
    <?php foreach ($validRanges as $val => $lbl): ?>
    <button type="submit" name="range" value="<?= $val ?>"
            class="btn btn-sm <?= $range === $val ? 'btn-primary' : 'btn-ghost' ?>">
      <?= $lbl ?>
    </button>
    <?php endforeach; ?>
  </form>
</div>

<!-- ── KPI summary strip ─────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:2rem;">
  <?php
  $kpis = [
    ['Leads',           $summary['leads_period'],   'var(--p)',     'fa-user-plus',   $summary['leads_all_time'] . ' all time'],
    ['Deals closed',    $summary['deals_period'],   'var(--green)', 'fa-handshake',   $summary['deals_all_time'] . ' all time'],
    ['Conversion rate', $convRate . '%',            $convRate >= 10 ? 'var(--green)' : 'var(--amber)', 'fa-chart-line', 'of leads this period'],
    ['Revenue',         'R ' . number_format($summary['revenue_period'] / 1000, 0) . 'K',
                                                    'var(--teal)',  'fa-money-bill-wave', 'attributed sales'],
  ];
  foreach ($kpis as [$label, $value, $colour, $icon, $sub]):
  ?>
  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:52px;height:52px;
                background:rgba(0,0,0,.04);border-radius:0 var(--r-xl) 0 52px;
                display:flex;align-items:center;justify-content:center;
                font-size:16px;color:<?= $colour ?>;">
      <i class="fa-solid <?= $icon ?>"></i>
    </div>
    <div style="font-size:26px;font-weight:700;font-family:var(--mono);
                color:<?= $colour ?>;line-height:1.1;">
      <?= $value ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;"><?= $label ?></div>
    <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $sub ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Monthly chart + top cars ──────────────────────────────── -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:1.5rem;margin-bottom:2rem;
            align-items:start;">

  <!-- Monthly volume bar chart -->
  <div class="card card-body">
    <div class="section-head" style="margin-bottom:1rem;">
      <h2 class="section-title">Monthly lead volume</h2>
    </div>
    <?php if (empty($monthlyData)): ?>
    <div class="empty"><span class="empty-icon">—</span>No data yet.</div>
    <?php else:
      $maxLeads = max(array_column($monthlyData, 'lead_count')) ?: 1;
    ?>
    <div style="display:flex;align-items:flex-end;gap:6px;height:140px;padding-bottom:20px;">
      <?php foreach ($monthlyData as $m):
        $barH    = max(4, round($m['lead_count'] / $maxLeads * 120));
        $dealH   = $m['lead_count'] > 0
          ? max(2, round($m['deal_count'] / $m['lead_count'] * $barH))
          : 0;
        $isThisMonth = $m['month_key'] === date('Y-m');
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;
                  position:relative;" title="<?= htmlspecialchars($m['month_label']) ?>: <?= $m['lead_count'] ?> leads, <?= $m['deal_count'] ?> closed">
        <!-- Bar -->
        <div style="width:100%;display:flex;flex-direction:column;justify-content:flex-end;
                    height:120px;">
          <div style="width:100%;background:<?= $isThisMonth ? 'var(--p)' : 'var(--p-b)' ?>;
                      border-radius:3px 3px 0 0;height:<?= $barH ?>px;
                      position:relative;overflow:hidden;">
            <?php if ($dealH > 0): ?>
            <div style="position:absolute;bottom:0;left:0;right:0;height:<?= $dealH ?>px;
                        background:var(--green);opacity:.7;"></div>
            <?php endif; ?>
          </div>
        </div>
        <!-- Month label -->
        <div style="font-size:9px;font-family:var(--mono);color:var(--faint);
                    position:absolute;bottom:-18px;white-space:nowrap;
                    transform:translateX(-50%);left:50%;">
          <?= substr($m['month_label'], 0, 3) ?>
        </div>
        <!-- Count label on bar -->
        <?php if ($m['lead_count'] > 0): ?>
        <div style="position:absolute;bottom:<?= $barH + 3 ?>px;left:50%;
                    transform:translateX(-50%);font-size:9px;font-family:var(--mono);
                    color:var(--muted);">
          <?= $m['lead_count'] ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:12px;margin-top:8px;padding-top:8px;
                border-top:1px solid var(--border);">
      <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);">
        <div style="width:10px;height:10px;background:var(--p-b);border-radius:2px;"></div>
        Leads
      </div>
      <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);">
        <div style="width:10px;height:10px;background:var(--green);border-radius:2px;opacity:.7;"></div>
        Deals closed
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top performing cars -->
  <div class="card card-body">
    <div class="section-head" style="margin-bottom:1rem;">
      <h2 class="section-title">Top listings</h2>
    </div>
    <?php if (empty($topCars)): ?>
    <div class="empty"><span class="empty-icon">—</span>No data yet.</div>
    <?php else:
      $maxCarLeads = max(array_column($topCars, 'total_leads')) ?: 1;
      foreach ($topCars as $tc):
        $barW = max(4, round($tc['total_leads'] / $maxCarLeads * 100));
    ?>
    <div style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;align-items:baseline;
                  margin-bottom:3px;">
        <div style="font-size:12px;font-weight:500;color:var(--text);
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:65%;">
          <?= htmlspecialchars("{$tc['year']} {$tc['make']} {$tc['model']}") ?>
        </div>
        <div style="font-size:11px;font-family:var(--mono);color:var(--muted);flex-shrink:0;">
          <?= $tc['total_leads'] ?> lead<?= $tc['total_leads'] != 1 ? 's' : '' ?>
        </div>
      </div>
      <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
        <div style="height:100%;width:<?= $barW ?>%;
                    background:<?= $tc['deals_closed'] > 0 ? 'var(--green)' : 'var(--p-b)' ?>;
                    border-radius:3px;"></div>
      </div>
      <div style="font-size:10px;color:var(--faint);margin-top:2px;">
        <?= $tc['deals_closed'] ?> closed · <?= $tc['broker_count'] ?> broker<?= $tc['broker_count'] != 1 ? 's' : '' ?>
        · <span class="badge badge-<?= $tc['status'] ?>"><?= $tc['status'] ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div>

<!-- ── Top brokers table ──────────────────────────────────────── -->
<div style="margin-bottom:2rem;">
  <div class="section-head" style="margin-bottom:.75rem;">
    <h2 class="section-title">Top brokers</h2>
    <span class="section-count"><?= count($topBrokers) ?></span>
  </div>
  <?php if (empty($topBrokers)): ?>
  <div class="empty"><span class="empty-icon"><i class="fa-solid fa-users"></i></span>No broker activity yet.</div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead>
        <tr>
          <th>#</th>
          <th>Broker / Desk</th>
          <th>Leads</th>
          <th>Closed</th>
          <th>Conversion</th>
          <th>Earned (paid)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($topBrokers as $i => $b):
        $bConv = $b['total_leads'] > 0 ? round($b['deals_closed'] / $b['total_leads'] * 100, 0) : 0;
        $bName = trim($b['broker_name']) ?: $b['broker_email'];
      ?>
      <tr>
        <td style="font-family:var(--mono);font-size:11px;color:var(--faint);"><?= $i + 1 ?></td>
        <td>
          <div style="font-size:13px;font-weight:500;color:var(--text);">
            <?= htmlspecialchars($bName) ?>
          </div>
          <div style="font-size:11px;color:var(--faint);">
            <?= htmlspecialchars($b['desk_name'] ?? $b['broker_email']) ?>
          </div>
        </td>
        <td style="font-family:var(--mono);font-size:13px;color:var(--text);">
          <?= $b['total_leads'] ?>
        </td>
        <td style="font-family:var(--mono);font-size:13px;color:var(--green);">
          <?= $b['deals_closed'] ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            <div style="flex:1;height:4px;background:var(--border);border-radius:2px;min-width:40px;">
              <div style="height:100%;width:<?= min(100, $bConv) ?>%;
                          background:<?= $bConv >= 20 ? 'var(--green)' : ($bConv >= 10 ? 'var(--amber)' : 'var(--p-b)') ?>;
                          border-radius:2px;"></div>
            </div>
            <span style="font-size:11px;font-family:var(--mono);color:var(--muted);
                         white-space:nowrap;"><?= $bConv ?>%</span>
          </div>
        </td>
        <td style="font-family:var(--mono);font-size:12px;color:<?= $b['earned'] > 0 ? 'var(--green)' : 'var(--faint)' ?>;">
          <?= $b['earned'] > 0 ? 'R ' . number_format($b['earned'], 0) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── Per-exec breakdown ─────────────────────────────────────── -->
<?php if (!empty($execBreakdown)): ?>
<div style="margin-bottom:2rem;">
  <div class="section-head" style="margin-bottom:.75rem;">
    <h2 class="section-title">Sales exec performance</h2>
    <span class="section-count"><?= count($execBreakdown) ?></span>
  </div>
  <div class="roster-wrap">
    <table class="roster">
      <thead>
        <tr>
          <th>Exec</th>
          <th>Status</th>
          <th>Cars listed</th>
          <th>Active</th>
          <th>Leads</th>
          <th>Deals closed</th>
          <th>Avg response</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($execBreakdown as $e):
        $eName = trim($e['exec_name']) ?: 'Unknown';
        $avgH  = $e['avg_response_hours'] ? round($e['avg_response_hours']) : null;
        $avgLabel = $avgH ? ($avgH < 24 ? $avgH . 'h' : round($avgH / 24) . 'd') : '—';
      ?>
      <tr>
        <td>
          <div style="font-size:13px;font-weight:500;color:var(--text);">
            <?= htmlspecialchars($eName) ?>
          </div>
          <?php if ($e['job_title']): ?>
          <div style="font-size:11px;color:var(--faint);"><?= htmlspecialchars($e['job_title']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge badge-<?= $e['verification_status'] ?>">
            <?= $e['verification_status'] ?>
          </span>
        </td>
        <td style="font-family:var(--mono);font-size:12px;"><?= $e['cars_uploaded'] ?></td>
        <td style="font-family:var(--mono);font-size:12px;color:var(--green);"><?= $e['active_cars'] ?></td>
        <td style="font-family:var(--mono);font-size:13px;"><?= $e['leads_generated'] ?></td>
        <td style="font-family:var(--mono);font-size:13px;color:var(--green);"><?= $e['deals_closed'] ?></td>
        <td style="font-size:12px;color:var(--muted);font-family:var(--mono);"><?= $avgLabel ?></td>
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
