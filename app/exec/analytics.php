<?php
/**
 * SalesDesk — Sales Exec Analytics.
 * T3 owns this file.
 *
 * Task sep5: "My performance" scoped view.
 *   - cars listed, leads (month/all-time), conversion rate, avg time-to-contact
 *   - All scoped to uploaded_by_exec_id = se.id
 *   - Feeds dealer analytics per-exec table (same queries, wider scope)
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

$pageTitle = 'Analytics';
ob_start();
?>

<!-- ── Header ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;
            margin-bottom:1.75rem;flex-wrap:wrap;gap:10px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.5rem;font-weight:300;margin-bottom:2px;">
      My <em style="font-style:italic;">performance</em>
    </h1>
    <p style="font-size:13px;color:var(--muted);">
      <?= htmlspecialchars($exec['dealer_name']) ?> · <?= $validRanges[$range] ?>
    </p>
  </div>
  <form method="GET" style="display:flex;gap:4px;">
    <?php foreach ($validRanges as $val => $lbl): ?>
    <button type="submit" name="range" value="<?= $val ?>"
            class="btn btn-sm <?= $range === $val ? 'btn-primary' : 'btn-ghost' ?>">
      <?= $lbl ?>
    </button>
    <?php endforeach; ?>
  </form>
</div>

<!-- ── KPI strip ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:2rem;">
  <?php
  $kpis = [
    ['Active listings', $summary['active_cars'], 'var(--p)', 'fa-car',
     $summary['total_cars'] . ' total uploaded'],
    ['Leads this period', $summary['leads_period'], 'var(--amber)', 'fa-user-plus',
     $summary['leads_all_time'] . ' all time'],
    ['Conversion rate', $convRate . '%',
     $convRate >= 15 ? 'var(--green)' : ($convRate >= 8 ? 'var(--amber)' : 'var(--muted)'),
     'fa-chart-line', $summary['deals_period'] . ' deals closed'],
    ['Avg time to contact', $avgContact, 'var(--teal)', 'fa-clock', 'after lead arrives'],
  ];
  foreach ($kpis as [$label, $val, $colour, $icon, $sub]):
  ?>
  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:52px;height:52px;
                background:rgba(0,0,0,.04);border-radius:0 var(--r-xl) 0 52px;
                display:flex;align-items:center;justify-content:center;
                font-size:16px;color:<?= $colour ?>;">
      <i class="fa-solid <?= $icon ?>"></i>
    </div>
    <div style="font-size:26px;font-weight:700;font-family:var(--mono);
                color:<?= $colour ?>;line-height:1.1;"><?= $val ?></div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;"><?= $label ?></div>
    <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $sub ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Monthly chart + top cars ──────────────────────────────── -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:1.5rem;margin-bottom:2rem;align-items:start;">

  <!-- Monthly bar chart -->
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
        $barH  = max(4, round($m['lead_count'] / $maxLeads * 120));
        $dealH = $m['lead_count'] > 0
          ? max(2, round($m['deal_count'] / $m['lead_count'] * $barH))
          : 0;
        $isCurrent = $m['month_key'] === date('Y-m');
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;
                  position:relative;"
           title="<?= htmlspecialchars($m['month_short']) ?>: <?= $m['lead_count'] ?> leads, <?= $m['deal_count'] ?> closed">
        <div style="width:100%;display:flex;flex-direction:column;
                    justify-content:flex-end;height:120px;">
          <div style="width:100%;background:<?= $isCurrent ? 'var(--p)' : 'var(--p-b)' ?>;
                      border-radius:3px 3px 0 0;height:<?= $barH ?>px;
                      position:relative;overflow:hidden;">
            <?php if ($dealH > 0): ?>
            <div style="position:absolute;bottom:0;left:0;right:0;height:<?= $dealH ?>px;
                        background:var(--green);opacity:.75;"></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="font-size:9px;font-family:var(--mono);color:var(--faint);
                    position:absolute;bottom:-18px;white-space:nowrap;
                    transform:translateX(-50%);left:50%;">
          <?= htmlspecialchars($m['month_short']) ?>
        </div>
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
    <div style="display:flex;gap:12px;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
      <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);">
        <div style="width:10px;height:10px;background:var(--p-b);border-radius:2px;"></div> Leads
      </div>
      <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);">
        <div style="width:10px;height:10px;background:var(--green);border-radius:2px;opacity:.75;"></div> Closed
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top cars -->
  <div class="card card-body">
    <div class="section-head" style="margin-bottom:1rem;">
      <h2 class="section-title">My top listings</h2>
    </div>
    <?php if (empty($topCars)): ?>
    <div class="empty"><span class="empty-icon">—</span>No listings yet.</div>
    <?php else:
      $maxCL = max(array_column($topCars, 'total_leads')) ?: 1;
      foreach ($topCars as $tc):
        $barW = max(4, round($tc['total_leads'] / $maxCL * 100));
    ?>
    <div style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px;">
        <div style="font-size:12px;font-weight:500;color:var(--text);
                    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:65%;">
          <?= htmlspecialchars("{$tc['year']} {$tc['make']} {$tc['model']}") ?>
        </div>
        <div style="font-size:11px;font-family:var(--mono);color:var(--muted);flex-shrink:0;">
          <?= $tc['total_leads'] ?> lead<?= $tc['total_leads'] != 1 ? 's' : '' ?>
        </div>
      </div>
      <div style="height:5px;background:var(--border);border-radius:3px;overflow:hidden;">
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

<!-- ── Pipeline breakdown table ──────────────────────────────── -->
<?php
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
if (!empty($pipelineRows)):
  $totalPL = array_sum(array_column($pipelineRows, 'cnt'));
?>
<div class="card card-body" style="margin-bottom:2rem;">
  <div class="section-head" style="margin-bottom:1rem;">
    <h2 class="section-title">Pipeline breakdown</h2>
    <span class="section-count"><?= $totalPL ?> leads</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;">
    <?php foreach ($pipelineRows as $pr):
      $pct = round($pr['cnt'] / $totalPL * 100);
      $colour = match($pr['status']) {
        'new'         => 'var(--p)',
        'contacted'   => 'var(--amber)',
        'test_drive'  => 'var(--teal)',
        'negotiation' => 'var(--purple)',
        'closed'      => 'var(--green)',
        'lost'        => 'var(--faint)',
        default       => 'var(--muted)',
      };
    ?>
    <div style="text-align:center;padding:12px 8px;background:var(--bg);
                border:1px solid var(--border);border-radius:var(--r-md);">
      <div style="font-size:22px;font-weight:700;font-family:var(--mono);color:<?= $colour ?>;">
        <?= $pr['cnt'] ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;text-transform:capitalize;">
        <?= str_replace('_', ' ', $pr['status']) ?>
      </div>
      <div style="font-size:10px;color:var(--faint);"><?= $pct ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
