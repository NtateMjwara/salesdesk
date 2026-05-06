<?php
/**
 * SalesDesk — Sales Exec Dashboard.
 * T3 owns this file.
 *
 * Task sep1: Verified exec home.
 *   - Four numbers scoped to uploaded_by_exec_id = se.id
 *   - active cars, leads this month, open pipeline deals, all-time conversion
 *   - Dealer name + Verified badge at top
 *
 * Gate: requireExecVerified() — DB-level, not session-only.
 * All queries use WHERE c.uploaded_by_exec_id = se.id.
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

// Profile name
$execName = trim(($exec['first_name'] ?? '') . ' ' . $exec['last_name'] ?? '');

$pageTitle = 'Dashboard';
ob_start();
?>

<!-- ── Dealer + exec header ──────────────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;
            margin-bottom:1.75rem;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.65rem;font-weight:300;
               color:var(--text);margin-bottom:3px;">
      Good <?= date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening') ?>,
      <em style="font-style:italic;"><?= htmlspecialchars($execName ?: 'there') ?></em>
    </h1>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <p style="font-size:13px;color:var(--muted);">
        <i class="fa-solid fa-building" style="font-size:11px;margin-right:4px;"></i>
        <?= htmlspecialchars($exec['dealer_name']) ?>
      </p>
      <span class="badge badge-verified">
        <i class="fa-solid fa-circle-check" style="font-size:9px;"></i> Verified exec
      </span>
      <?php if ($exec['job_title']): ?>
      <span style="font-size:11px;color:var(--faint);">· <?= htmlspecialchars($exec['job_title']) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <a href="/app/exec/car-upload.php" class="btn btn-primary" style="text-decoration:none;">
    <i class="fa-solid fa-plus"></i> List a car
  </a>
</div>

<!-- ── KPI strip ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:2rem;">

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:52px;height:52px;
                background:var(--p-light);border-radius:0 var(--r-xl) 0 52px;
                display:flex;align-items:center;justify-content:center;
                font-size:16px;color:var(--p);">
      <i class="fa-solid fa-car"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--p);">
      <?= $stats['active_cars'] ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Active listings</div>
    <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $stats['total_cars'] ?> total</div>
  </div>

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:52px;height:52px;
                background:var(--amb-bg);border-radius:0 var(--r-xl) 0 52px;
                display:flex;align-items:center;justify-content:center;
                font-size:16px;color:var(--amber);">
      <i class="fa-solid fa-user-plus"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--amber);">
      <?= $stats['leads_this_month'] ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Leads this month</div>
    <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $stats['leads_all_time'] ?> all time</div>
  </div>

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:52px;height:52px;
                background:var(--teal-bg);border-radius:0 var(--r-xl) 0 52px;
                display:flex;align-items:center;justify-content:center;
                font-size:16px;color:var(--teal);">
      <i class="fa-solid fa-funnel-dollar"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--teal);">
      <?= $stats['open_pipeline'] ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Open pipeline</div>
    <div style="font-size:11px;color:var(--faint);margin-top:2px;">in progress</div>
  </div>

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:52px;height:52px;
                background:var(--gr-bg);border-radius:0 var(--r-xl) 0 52px;
                display:flex;align-items:center;justify-content:center;
                font-size:16px;color:var(--green);">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--green);">
      <?= $convRate ?>%
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Conversion rate</div>
    <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $stats['deals_closed'] ?> deals closed</div>
  </div>

</div>

<!-- ── Leads + listings grid ──────────────────────────────────── -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:1.5rem;align-items:start;">

  <!-- Recent leads -->
  <div>
    <div class="section-head">
      <h2 class="section-title">Recent leads on my listings</h2>
      <span class="section-count"><?= count($recentLeads) ?></span>
      <span style="flex:1;"></span>
      <a href="/app/exec/leads.php" style="font-size:12px;color:var(--p);text-decoration:none;">
        View all →
      </a>
    </div>
    <?php if (empty($recentLeads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox"></i></span>
      No leads yet — list your first car to start receiving leads.
    </div>
    <?php else: ?>
    <div class="roster-wrap">
      <?php foreach ($recentLeads as $lead):
        $intentColour = match($lead['buyer_intent']) {
          'within_30d' => 'var(--green)',
          'one_to_3mo' => 'var(--amber)',
          default      => 'var(--faint)',
        };
        $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
        $ageLabel = $ageHours < 24 ? round($ageHours) . 'h ago' : round($ageHours / 24) . 'd ago';
      ?>
      <a href="/app/exec/leads.php?id=<?= $lead['id'] ?>"
         style="display:flex;align-items:center;gap:12px;padding:12px 16px;
                border-bottom:1px solid var(--border);text-decoration:none;
                transition:background .12s;"
         onmouseover="this.style.background='var(--bg)'"
         onmouseout="this.style.background=''">
        <div style="width:8px;height:8px;border-radius:50%;flex-shrink:0;
                    background:<?= $intentColour ?>"></div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:500;color:var(--text);
                      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($lead['buyer_name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:10px;font-family:var(--mono);font-weight:600;
                      text-transform:uppercase;color:var(--p);"><?= $lead['status'] ?></div>
          <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $ageLabel ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- My listings -->
  <div>
    <div class="section-head">
      <h2 class="section-title">My listings</h2>
      <span class="section-count"><?= $stats['active_cars'] ?></span>
      <span style="flex:1;"></span>
      <a href="/app/exec/inventory.php" style="font-size:12px;color:var(--p);text-decoration:none;">
        View all →
      </a>
    </div>
    <?php if (empty($myCars)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-car"></i></span>
      No listings yet.
      <div style="margin-top:8px;">
        <a href="/app/exec/car-upload.php" class="btn btn-primary btn-sm"
           style="text-decoration:none;">
          <i class="fa-solid fa-plus"></i> List first car
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="card" style="overflow:hidden;">
      <?php foreach ($myCars as $car):
        $commission = $car['commission_type'] === 'fixed'
          ? 'R ' . number_format($car['commission_value'], 0)
          : $car['commission_value'] . '%';
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:11px 14px;
                  border-bottom:1px solid var(--border);">
        <div style="width:36px;height:30px;border-radius:5px;background:var(--bg);
                    border:1px solid var(--border);display:flex;align-items:center;
                    justify-content:center;font-size:14px;color:var(--faint);flex-shrink:0;">
          <i class="fa-solid fa-car-side"></i>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:12px;font-weight:500;color:var(--text);
                      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
          </div>
          <div style="font-size:11px;color:var(--muted);">
            R <?= number_format($car['price'], 0) ?> · <?= $commission ?> comm
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <span class="badge badge-<?= $car['status'] ?>"><?= $car['status'] ?></span>
          <div style="font-size:10px;color:var(--faint);margin-top:2px;">
            <?= $car['lead_count'] ?> lead<?= $car['lead_count'] != 1 ? 's' : '' ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="padding:10px 14px;text-align:center;">
        <a href="/app/exec/car-upload.php" class="btn btn-ghost btn-sm"
           style="text-decoration:none;font-size:12px;">
          <i class="fa-solid fa-plus"></i> Add listing
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
