<?php
/**
 * SalesDesk — Dealer Dashboard.
 * T3 owns this file.
 *
 * Task d1: Four KPI numbers, recent leads list, quick-action nav.
 *   - Active listings count
 *   - Leads this month
 *   - All-time conversion rate
 *   - SalesDesk-attributed revenue
 *
 * Feature gate: verification_status check via dealers table.
 * Unverified dealers see a CIPC prompt but can still access the dashboard.
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

// Conversion rate.
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

<!-- ── Verification banner ─────────────────────────────────── -->
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

<!-- ── Page header ────────────────────────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;
            margin-bottom:1.75rem;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.65rem;font-weight:300;
               color:var(--text);margin-bottom:3px;">
      Good <?= date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening') ?>,
      <em style="font-style:italic;"><?= htmlspecialchars($principalName) ?></em>
    </h1>
    <p style="font-size:13px;color:var(--muted);">
      <?= htmlspecialchars($dealer['company_name']) ?>
      <?php if ($dealer['verification_status'] === 'verified'): ?>
      &nbsp;<span class="badge badge-verified"><i class="fa-solid fa-circle-check" style="font-size:9px"></i> Verified</span>
      <?php endif; ?>
      <?php if ($dealer['city']): ?>
      · <?= htmlspecialchars($dealer['city']) ?>
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php if ($stats['pending_execs'] > 0): ?>
    <a href="/app/dealer/team.php" class="btn btn-warn btn-sm" style="text-decoration:none;">
      <i class="fa-solid fa-clock"></i>
      <?= $stats['pending_execs'] ?> exec<?= $stats['pending_execs'] > 1 ? 's' : '' ?> pending
    </a>
    <?php endif; ?>
    <a href="/app/dealer/car-upload.php" class="btn btn-primary" style="text-decoration:none;">
      <i class="fa-solid fa-plus"></i> List a car
    </a>
  </div>
</div>

<!-- ── KPI strip ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:2rem;">

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:60px;height:60px;
                background:var(--p-light);border-radius:0 var(--r-xl) 0 60px;
                display:flex;align-items:center;justify-content:center;
                font-size:18px;color:var(--p);">
      <i class="fa-solid fa-car"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--p);line-height:1;">
      <?= $stats['active_listings'] ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Active listings</div>
    <?php if ($stats['paused_listings'] > 0): ?>
    <div style="font-size:11px;color:var(--faint);margin-top:3px;">
      <?= $stats['paused_listings'] ?> paused
    </div>
    <?php endif; ?>
  </div>

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:60px;height:60px;
                background:var(--amb-bg);border-radius:0 var(--r-xl) 0 60px;
                display:flex;align-items:center;justify-content:center;
                font-size:18px;color:var(--amber);">
      <i class="fa-solid fa-user-plus"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--amber);line-height:1;">
      <?= $stats['leads_this_month'] ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Leads this month</div>
    <div style="font-size:11px;color:var(--faint);margin-top:3px;">
      <?= $stats['leads_all_time'] ?> all time
    </div>
  </div>

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:60px;height:60px;
                background:var(--gr-bg);border-radius:0 var(--r-xl) 0 60px;
                display:flex;align-items:center;justify-content:center;
                font-size:18px;color:var(--green);">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--green);line-height:1;">
      <?= $convRate ?>%
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Conversion rate</div>
    <div style="font-size:11px;color:var(--faint);margin-top:3px;">
      <?= $stats['deals_closed'] ?> of <?= $stats['leads_all_time'] ?> deals closed
    </div>
  </div>

  <div class="card card-body" style="position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:60px;height:60px;
                background:var(--teal-bg);border-radius:0 var(--r-xl) 0 60px;
                display:flex;align-items:center;justify-content:center;
                font-size:18px;color:var(--teal);">
      <i class="fa-solid fa-money-bill-wave"></i>
    </div>
    <div style="font-size:28px;font-weight:700;font-family:var(--mono);color:var(--teal);line-height:1;">
      <?= $stats['attributed_revenue'] > 0
        ? 'R ' . number_format($stats['attributed_revenue'] / 1000000, 1) . 'M'
        : 'R 0' ?>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:4px;">Revenue attributed</div>
    <div style="font-size:11px;color:var(--faint);margin-top:3px;">from broker-sourced deals</div>
  </div>

</div>

<!-- ── Main grid: leads + listings ───────────────────────────── -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:1.5rem;align-items:start;">

  <!-- Recent leads -->
  <div>
    <div class="section-head">
      <h2 class="section-title">Recent leads</h2>
      <span class="section-count"><?= count($recentLeads) ?></span>
      <span style="flex:1"></span>
      <a href="/app/dealer/leads.php" style="font-size:12px;color:var(--p);text-decoration:none;">
        View all →
      </a>
    </div>

    <?php if (empty($recentLeads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox"></i></span>
      No leads yet — brokers will start sending them once you list cars.
    </div>
    <?php else: ?>
    <div class="roster-wrap">
      <?php foreach ($recentLeads as $lead):
        $intentColour = match($lead['buyer_intent']) {
          'within_30d' => 'var(--green)',
          'one_to_3mo' => 'var(--amber)',
          default      => 'var(--faint)',
        };
        $intentLabel = match($lead['buyer_intent']) {
          'within_30d' => 'Hot',
          'one_to_3mo' => 'Warm',
          default      => 'Browsing',
        };
        $statusColour = match($lead['status']) {
          'new'         => 'var(--p)',
          'contacted'   => 'var(--amber)',
          'test_drive'  => 'var(--teal)',
          'negotiation' => 'var(--purple)',
          'closed'      => 'var(--green)',
          'lost'        => 'var(--faint)',
          default       => 'var(--muted)',
        };
        $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
        $ageLabel = $ageHours < 24
          ? round($ageHours) . 'h ago'
          : (round($ageHours / 24) . 'd ago');
      ?>
      <a href="/app/dealer/leads.php?id=<?= $lead['id'] ?>"
         style="display:flex;align-items:center;gap:12px;padding:12px 16px;
                border-bottom:1px solid var(--border);text-decoration:none;
                transition:background .12s;"
         onmouseover="this.style.background='var(--bg)'"
         onmouseout="this.style.background=''">
        <!-- Intent dot -->
        <div style="width:8px;height:8px;border-radius:50%;
                    flex-shrink:0;background:<?= $intentColour ?>"></div>
        <!-- Info -->
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:500;color:var(--text);
                      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($lead['buyer_name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
            · <?= htmlspecialchars($lead['desk_name'] ?? $lead['broker_email']) ?>
          </div>
        </div>
        <!-- Status + age -->
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:10px;font-family:var(--mono);font-weight:600;
                      color:<?= $statusColour ?>;text-transform:uppercase;letter-spacing:.04em;">
            <?= $lead['status'] ?>
          </div>
          <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $ageLabel ?></div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (count($recentLeads) === 10): ?>
      <div style="padding:10px 16px;text-align:center;">
        <a href="/app/dealer/leads.php" style="font-size:12px;color:var(--p);text-decoration:none;">
          View all leads →
        </a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent listings -->
  <div>
    <div class="section-head">
      <h2 class="section-title">My listings</h2>
      <span class="section-count"><?= $stats['active_listings'] ?></span>
      <span style="flex:1"></span>
      <a href="/app/dealer/inventory.php" style="font-size:12px;color:var(--p);text-decoration:none;">
        View all →
      </a>
    </div>

    <?php if (empty($recentCars)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-car"></i></span>
      No listings yet.
      <div style="margin-top:8px;">
        <a href="/app/dealer/car-upload.php" class="btn btn-primary btn-sm" style="text-decoration:none;">
          <i class="fa-solid fa-plus"></i> List your first car
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="card" style="overflow:hidden;">
      <?php foreach ($recentCars as $car):
        $images = json_decode($car['image_urls'] ?? '[]', true);
        $thumb  = !empty($images[0]) ? $images[0] : null;
        $commission = $car['commission_type'] === 'fixed'
          ? 'R ' . number_format($car['commission_value'], 0)
          : $car['commission_value'] . '%';
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:11px 14px;
                  border-bottom:1px solid var(--border);">
        <!-- Car thumb -->
        <div style="width:44px;height:36px;border-radius:6px;flex-shrink:0;
                    background:var(--bg);border:1px solid var(--border);
                    overflow:hidden;display:flex;align-items:center;justify-content:center;
                    font-size:16px;color:var(--faint);">
          <?php if ($thumb): ?>
          <img src="<?= htmlspecialchars($thumb) ?>" alt=""
               style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
          <i class="fa-solid fa-car-side"></i>
          <?php endif; ?>
        </div>
        <!-- Info -->
        <div style="flex:1;min-width:0;">
          <div style="font-size:12px;font-weight:500;color:var(--text);
                      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
          </div>
          <div style="font-size:11px;color:var(--muted);">
            R <?= number_format($car['price'], 0) ?> ·
            <span style="color:var(--green);"><?= $commission ?> commission</span>
          </div>
        </div>
        <!-- Stats -->
        <div style="text-align:right;flex-shrink:0;">
          <span class="badge badge-<?= $car['status'] ?>"><?= $car['status'] ?></span>
          <div style="font-size:10px;color:var(--faint);margin-top:3px;">
            <?= $car['lead_count'] ?> lead<?= $car['lead_count'] != 1 ? 's' : '' ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="padding:10px 14px;text-align:center;">
        <a href="/app/dealer/car-upload.php" class="btn btn-ghost btn-sm"
           style="text-decoration:none;font-size:12px;">
          <i class="fa-solid fa-plus"></i> Add listing
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Team quick-stat -->
    <?php if ($stats['exec_count'] > 0): ?>
    <div class="card card-body" style="margin-top:12px;">
      <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <div style="font-size:12px;font-weight:600;color:var(--text);">Sales team</div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px;">
            <?= $stats['exec_count'] ?> exec<?= $stats['exec_count'] > 1 ? 's' : '' ?> linked
            <?php if ($stats['pending_execs'] > 0): ?>
            · <span style="color:var(--amber);"><?= $stats['pending_execs'] ?> pending</span>
            <?php endif; ?>
          </div>
        </div>
        <a href="/app/dealer/team.php" class="btn btn-ghost btn-sm"
           style="text-decoration:none;font-size:12px;">Manage</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
