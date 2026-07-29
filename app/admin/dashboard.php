<?php
/**
 * SalesDesk — Admin: Dashboard
 *
 * Landing page for the admin role. Read-only overview that surfaces
 * everything else in the admin area needs attention on:
 *   - Platform-wide user / dealer / listing / lead counts
 *   - CIPC verifications awaiting review        → users.php?tab=verifications
 *   - Commissions awaiting approval + failed EFTs → payouts.php
 *   - Newsletter + blog content health           → newsletter.php / blog.php
 *   - Latest audit trail entries                 → audit.php
 *   - Most recent leads across the platform
 *
 * No write actions live here — every number links through to the
 * page that owns the underlying action, same pattern as the rest
 * of /app/admin/*.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/response.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo = Database::getInstance();

// ── Users ─────────────────────────────────────────────────────
$userStats = $pdo->query("
    SELECT
        COUNT(*)                    AS total,
        SUM(role = 'broker')        AS brokers,
        SUM(role = 'dealer')        AS dealers,
        SUM(role = 'sales_exec')    AS sales_execs,
        SUM(status = 'pending')     AS pending,
        SUM(status = 'suspended')   AS suspended
    FROM users
")->fetch();

// ── CIPC verifications ──────────────────────────────────────────
$pendingDealerVerifications = (int) $pdo->query(
    "SELECT COUNT(*) FROM dealers WHERE verification_status = 'pending'"
)->fetchColumn();
$pendingOrgVerifications = (int) $pdo->query(
    "SELECT COUNT(*) FROM organizations WHERE verification_status = 'pending'"
)->fetchColumn();
$pendingVerificationsTotal = $pendingDealerVerifications + $pendingOrgVerifications;

// ── Listings ──────────────────────────────────────────────────
$carStats = $pdo->query("
    SELECT
        COUNT(*)                 AS total,
        SUM(status = 'active')   AS active,
        SUM(status = 'paused')   AS paused,
        SUM(status = 'sold')     AS sold
    FROM cars
")->fetch();

// ── Leads ─────────────────────────────────────────────────────
$leadStats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))  AS last_7d,
        SUM(status = 'new')                                 AS new_count,
        SUM(status = 'closed')                               AS closed_count
    FROM leads
")->fetch();

// ── Commissions & payouts ────────────────────────────────────
$commTotals = $pdo->query("
    SELECT
        SUM(CASE WHEN status = 'pending'   THEN net_amount ELSE 0 END) AS pending_value,
        COUNT(CASE WHEN status = 'pending' THEN 1 END)                 AS pending_count,
        SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 THEN net_amount ELSE 0 END)                            AS paid_30d_value
    FROM commissions
")->fetch();

$failedPayouts = (int) $pdo->query(
    "SELECT COUNT(*) FROM payouts WHERE status = 'failed'"
)->fetchColumn();

// ── Newsletter (optional table — degrade gracefully) ─────────
$newsletterStats = ['active' => 0, 'total' => 0];
try {
    $ns = $pdo->query("
        SELECT SUM(status = 'active') AS active, COUNT(*) AS total
        FROM newsletter_subscribers
    ")->fetch();
    if ($ns) $newsletterStats = $ns;
} catch (Throwable) {}

// ── Blog (optional table — degrade gracefully) ────────────────
$blogStats = ['published' => 0, 'draft' => 0];
try {
    $bs = $pdo->query("
        SELECT SUM(status = 'published') AS published, SUM(status = 'draft') AS draft
        FROM blog_posts
    ")->fetch();
    if ($bs) $blogStats = $bs;
} catch (Throwable) {}

// ── Recent audit trail (last 8) ────────────────────────────────
$recentAudit = $pdo->query("
    SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.email AS actor_email
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_id
    ORDER BY al.created_at DESC
    LIMIT 8
")->fetchAll();

// ── Recent leads (last 6) ───────────────────────────────────────
$recentLeads = $pdo->query("
    SELECT
        l.uuid, l.buyer_name, l.status, l.created_at,
        cars.make, cars.model, cars.year,
        d.company_name AS dealer_name
    FROM leads l
    JOIN cars cars ON cars.id = l.car_id
    JOIN dealers d ON d.id   = l.dealer_id
    ORDER BY l.created_at DESC
    LIMIT 6
")->fetchAll();

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title">Dashboard</h1>
  <span style="margin-left:auto;font-size:12px;color:var(--faint);">
    <?= date('l, d F Y — H:i') ?>
  </span>
</div>

<?php if ($pendingVerificationsTotal > 0 || $failedPayouts > 0): ?>
<div class="alert alert-warn" style="margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <span class="alert-icon">⚠</span>
  <div style="flex:1;">
    <?php if ($pendingVerificationsTotal > 0): ?>
    <strong><?= $pendingVerificationsTotal ?></strong> CIPC verification<?= $pendingVerificationsTotal !== 1 ? 's' : '' ?> awaiting review.
    <?php endif; ?>
    <?php if ($failedPayouts > 0): ?>
    <strong><?= $failedPayouts ?></strong> payout<?= $failedPayouts !== 1 ? 's' : '' ?> failed and need<?= $failedPayouts === 1 ? 's' : '' ?> attention.
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;">
    <?php if ($pendingVerificationsTotal > 0): ?>
    <a href="/app/admin/users.php?tab=verifications" class="btn btn-warn btn-sm">Review verifications</a>
    <?php endif; ?>
    <?php if ($failedPayouts > 0): ?>
    <a href="/app/admin/payouts.php" class="btn btn-danger btn-sm">Review payouts</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Top stat cards ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1.75rem;">
  <a href="/app/admin/users.php" class="card card-body" style="text-align:center;text-decoration:none;">
    <div style="font-size:22px;font-weight:700;color:var(--p);font-family:var(--mono);">
      <?= number_format((int)$userStats['total']) ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">
      Users
      <span style="color:var(--faint);">
        (<?= (int)$userStats['brokers'] ?> brokers · <?= (int)$userStats['dealers'] ?> dealers)
      </span>
    </div>
  </a>

  <a href="/c/" class="card card-body" style="text-align:center;text-decoration:none;">
    <div style="font-size:22px;font-weight:700;color:var(--green);font-family:var(--mono);">
      <?= number_format((int)$carStats['active']) ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">
      Active listings
      <span style="color:var(--faint);">(<?= number_format((int)$carStats['total']) ?> total)</span>
    </div>
  </a>

  <div class="card card-body" style="text-align:center;">
    <div style="font-size:22px;font-weight:700;color:var(--teal);font-family:var(--mono);">
      <?= number_format((int)$leadStats['total']) ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">
      Leads
      <span style="color:var(--faint);">(<?= (int)$leadStats['last_7d'] ?> this week)</span>
    </div>
  </div>

  <a href="/app/admin/payouts.php" class="card card-body" style="text-align:center;text-decoration:none;">
    <div style="font-size:22px;font-weight:700;color:var(--amber);font-family:var(--mono);">
      <?= formatZAR((float)($commTotals['pending_value'] ?? 0)) ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">
      Commissions pending
      <span style="color:var(--faint);">(<?= (int)($commTotals['pending_count'] ?? 0) ?>)</span>
    </div>
  </a>
</div>

<!-- ── Quick-access panels ── -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:2rem;">

  <!-- Verifications -->
  <div class="card card-body">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="font-size:13px;font-weight:600;color:var(--text);">CIPC Verifications</div>
      <?php if ($pendingVerificationsTotal > 0): ?>
      <span class="badge badge-pending"><?= $pendingVerificationsTotal ?> pending</span>
      <?php else: ?>
      <span class="badge badge-active">All clear</span>
      <?php endif; ?>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:14px;">
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Dealers pending</td>
        <td style="text-align:right;font-family:var(--mono);"><?= $pendingDealerVerifications ?></td>
      </tr>
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Organisations pending</td>
        <td style="text-align:right;font-family:var(--mono);"><?= $pendingOrgVerifications ?></td>
      </tr>
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Suspended users</td>
        <td style="text-align:right;font-family:var(--mono);"><?= (int)$userStats['suspended'] ?></td>
      </tr>
    </table>
    <a href="/app/admin/users.php" class="btn btn-ghost btn-sm" style="width:100%;">Manage users →</a>
  </div>

  <!-- Payouts -->
  <div class="card card-body">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="font-size:13px;font-weight:600;color:var(--text);">Payouts</div>
      <?php if ($failedPayouts > 0): ?>
      <span class="badge badge-suspended"><?= $failedPayouts ?> failed</span>
      <?php else: ?>
      <span class="badge badge-active">On track</span>
      <?php endif; ?>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:14px;">
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Pending approval</td>
        <td style="text-align:right;font-family:var(--mono);"><?= (int)($commTotals['pending_count'] ?? 0) ?></td>
      </tr>
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Paid (30 days)</td>
        <td style="text-align:right;font-family:var(--mono);color:var(--green);">
          <?= formatZAR((float)($commTotals['paid_30d_value'] ?? 0)) ?>
        </td>
      </tr>
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Failed EFTs</td>
        <td style="text-align:right;font-family:var(--mono);color:<?= $failedPayouts ? 'var(--red)' : 'var(--faint)' ?>;">
          <?= $failedPayouts ?>
        </td>
      </tr>
    </table>
    <a href="/app/admin/payouts.php" class="btn btn-ghost btn-sm" style="width:100%;">Open payout dashboard →</a>
  </div>

  <!-- Content -->
  <div class="card card-body">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="font-size:13px;font-weight:600;color:var(--text);">Content</div>
      <span class="badge badge-new"><?= (int)$blogStats['published'] ?> live posts</span>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:14px;">
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Blog drafts</td>
        <td style="text-align:right;font-family:var(--mono);"><?= (int)$blogStats['draft'] ?></td>
      </tr>
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Active subscribers</td>
        <td style="text-align:right;font-family:var(--mono);"><?= number_format((int)$newsletterStats['active']) ?></td>
      </tr>
      <tr>
        <td style="color:var(--faint);padding:3px 0;">Total subscribers</td>
        <td style="text-align:right;font-family:var(--mono);"><?= number_format((int)$newsletterStats['total']) ?></td>
      </tr>
    </table>
    <div style="display:flex;gap:6px;">
      <a href="/app/admin/blog.php" class="btn btn-ghost btn-sm" style="flex:1;">Blog →</a>
      <a href="/app/admin/newsletter.php" class="btn btn-ghost btn-sm" style="flex:1;">Newsletter →</a>
    </div>
  </div>

</div>

<!-- ── Recent activity + recent leads ── -->
<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:20px;align-items:start;">

  <!-- Recent audit trail -->
  <div>
    <div class="section-head" style="margin-bottom:.75rem;">
      <h2 class="section-title" style="font-size:15px;">Recent activity</h2>
      <a href="/app/admin/audit.php" style="margin-left:auto;font-size:12px;color:var(--p);">View full audit trail →</a>
    </div>
    <?php if (empty($recentAudit)): ?>
    <div class="empty"><span class="empty-icon">—</span>No activity yet.</div>
    <?php else: ?>
    <div class="roster-wrap">
      <table class="roster">
        <thead>
          <tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentAudit as $log): ?>
        <tr>
          <td style="font-size:11px;color:var(--faint);white-space:nowrap;font-family:var(--mono);">
            <?= date('d M H:i', strtotime($log['created_at'])) ?>
          </td>
          <td style="font-size:12px;">
            <?= $log['actor_email'] ? htmlspecialchars($log['actor_email']) : '<span style="font-style:italic;color:var(--faint);">system</span>' ?>
          </td>
          <td>
            <span style="font-family:var(--mono);font-size:11px;background:var(--bg);
                         padding:2px 7px;border-radius:4px;color:var(--p);">
              <?= htmlspecialchars($log['action']) ?>
            </span>
          </td>
          <td style="font-size:12px;color:var(--muted);">
            <?= htmlspecialchars($log['entity_type']) ?>
            <span style="font-family:var(--mono);color:var(--faint);">#<?= (int)$log['entity_id'] ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent leads -->
  <div>
    <div class="section-head" style="margin-bottom:.75rem;">
      <h2 class="section-title" style="font-size:15px;">Recent leads</h2>
    </div>
    <?php if (empty($recentLeads)): ?>
    <div class="empty"><span class="empty-icon">—</span>No leads yet.</div>
    <?php else: ?>
    <div class="roster-wrap">
      <table class="roster">
        <thead>
          <tr><th>Buyer</th><th>Vehicle</th><th>Status</th><th>When</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentLeads as $lead): ?>
        <?php
          $leadBadge = match($lead['status']) {
            'new'          => 'badge-new',
            'contacted'    => 'badge-pending',
            'test_drive'   => 'badge-pending',
            'negotiation'  => 'badge-pending',
            'closed'       => 'badge-active',
            'lost'         => 'badge-suspended',
            default        => '',
          };
        ?>
        <tr>
          <td style="font-size:12px;font-weight:500;"><?= htmlspecialchars($lead['buyer_name']) ?></td>
          <td style="font-size:12px;color:var(--muted);">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
          </td>
          <td><span class="badge <?= $leadBadge ?>"><?= str_replace('_', ' ', $lead['status']) ?></span></td>
          <td style="font-size:11px;color:var(--faint);white-space:nowrap;">
            <?= date('d M', strtotime($lead['created_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Dashboard | Admin';
require_once '../../views/layout-app.php';
