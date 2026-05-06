<?php
/**
 * SalesDesk — Broker Dashboard
 * T4 owns this file. Route: /app/broker/dashboard.php
 *
 * Four headline numbers + quick links.
 * Context switcher (My Desk ↔ Org) appears if broker is in an org.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Load broker context ───────────────────────────────────────
// Salesdesk
$deskStmt = $pdo->prepare("
    SELECT sd.id, sd.slug, sd.display_name, sd.is_active,
           p.car_limit, p.first_name, p.last_name, p.avatar_url
    FROM salesdesks sd
    JOIN users u ON u.id = sd.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE sd.user_id = ?
    LIMIT 1
");
$deskStmt->execute([$userId]);
$desk = $deskStmt->fetch();

if (!$desk || !$desk['is_active']) {
    redirect('/auth/register.php');
}

$salesdeskId = (int) $desk['id'];

// Org membership?
$orgStmt = $pdo->prepare("
    SELECT o.id, o.name, o.slug, om.role
    FROM organization_members om
    JOIN organizations o ON o.id = om.organization_id
    WHERE om.user_id = ? AND o.is_active = 1
    ORDER BY om.joined_at ASC
    LIMIT 5
");
$orgStmt->execute([$userId]);
$orgs = $orgStmt->fetchAll();

// Active org context from session.
$orgContext = (int) ($_SESSION['org_context'] ?? 0);
$activeOrg  = null;
if ($orgContext) {
    foreach ($orgs as $o) {
        if ((int)$o['id'] === $orgContext) { $activeOrg = $o; break; }
    }
}

// Switch org context.
if (!empty($_GET['switch_context'])) {
    $switchTo = $_GET['switch_context'];
    if ($switchTo === 'personal') {
        unset($_SESSION['org_context']);
        redirect('/app/broker/dashboard.php');
    }
    foreach ($orgs as $o) {
        if ((string)$o['id'] === $switchTo) {
            $_SESSION['org_context'] = (int) $o['id'];
            redirect('/app/broker/dashboard.php');
        }
    }
}

// ── Stats queries ─────────────────────────────────────────────
$now          = date('Y-m-d H:i:s');
$monthStart   = date('Y-m-01 00:00:00');
$defaultLimit = getPlatformConfigInt('broker_car_limit_default', 10);
$carLimit     = $desk['car_limit'] ?? $defaultLimit;

// Active cars on desk.
$activeCarsStmt = $pdo->prepare("
    SELECT COUNT(*) FROM broker_inventory bi
    JOIN cars c ON c.id = bi.car_id
    WHERE bi.salesdesk_id = ? AND c.status = 'active'
");
$activeCarsStmt->execute([$salesdeskId]);
$activeCars = (int) $activeCarsStmt->fetchColumn();

// Leads this month.
$leadsMonthStmt = $pdo->prepare("
    SELECT COUNT(*) FROM leads
    WHERE salesdesk_id = ? AND created_at >= ?
    " . ($orgContext ? "AND organization_id = ?" : "") . "
");
$leadsMonthParams = $orgContext
    ? [$salesdeskId, $monthStart, $orgContext]
    : [$salesdeskId, $monthStart];
$leadsMonthStmt->execute($leadsMonthParams);
$leadsMonth = (int) $leadsMonthStmt->fetchColumn();

// All-time conversion rate (closed / total leads).
$convStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'closed') AS closed
    FROM leads
    WHERE broker_id = ?
");
$convStmt->execute([$userId]);
$conv = $convStmt->fetch();
$convRate = ($conv['total'] > 0)
    ? round(($conv['closed'] / $conv['total']) * 100, 1)
    : 0;

// Earnings pending.
$earningsStmt = $pdo->prepare("
    SELECT COALESCE(SUM(net_amount), 0)
    FROM commissions
    WHERE broker_id = ? AND status IN ('pending', 'approved', 'scheduled')
");
$earningsStmt->execute([$userId]);
$earningsPending = (float) $earningsStmt->fetchColumn();

// Recent leads (5).
$recentLeadsStmt = $pdo->prepare("
    SELECT l.id, l.buyer_name, l.buyer_intent, l.status, l.created_at,
           c.make, c.model, c.year
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    WHERE l.salesdesk_id = ?
    ORDER BY l.created_at DESC
    LIMIT 5
");
$recentLeadsStmt->execute([$salesdeskId]);
$recentLeads = $recentLeadsStmt->fetchAll();

// ── Render ────────────────────────────────────────────────────
$pageTitle   = 'Dashboard | ' . $desk['display_name'];
$brokerName  = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? '')) ?: $desk['display_name'];

ob_start();
?>

<!-- Context switcher (only if in an org) -->
<?php if (!empty($orgs)): ?>
<div class="context-bar">
  <div class="context-options">
    <a href="?switch_context=personal"
       class="context-opt <?= !$activeOrg ? 'active' : '' ?>">
      <i class="fa-solid fa-id-card"></i> My Desk
    </a>
    <?php foreach ($orgs as $o): ?>
    <a href="?switch_context=<?= (int)$o['id'] ?>"
       class="context-opt <?= $activeOrg && (int)$activeOrg['id'] === (int)$o['id'] ? 'active' : '' ?>">
      <i class="fa-solid fa-building"></i> <?= htmlspecialchars($o['name']) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Dashboard header -->
<div class="dash-header">
  <div>
    <h1 class="dash-title">
      <?= $activeOrg
        ? htmlspecialchars($activeOrg['name'])
        : htmlspecialchars($desk['display_name']) ?>
    </h1>
    <p class="dash-sub">
      <?= $activeOrg
        ? 'Organisation view · ' . ucfirst($activeOrg['role'])
        : 'Welcome back, ' . htmlspecialchars(explode(' ', $brokerName)[0]) ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <a href="/app/broker/inventory.php" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-plus"></i> Add a car
    </a>
    <?php if ($desk['is_active']): ?>
    <a href="/<?= htmlspecialchars($desk['slug']) ?>/" target="_blank"
       class="btn btn-ghost btn-sm">
      <i class="fa-solid fa-arrow-up-right-from-square"></i> My public page
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Stat cards -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--p-light);color:var(--p)">
      <i class="fa-solid fa-car-side"></i>
    </div>
    <div class="stat-main">
      <div class="stat-num"><?= $activeCars ?><span class="stat-denom"> / <?= $carLimit ?></span></div>
      <div class="stat-label">Cars on desk</div>
    </div>
    <div class="stat-sub">
      <?= $carLimit - $activeCars ?> slot<?= ($carLimit - $activeCars) !== 1 ? 's' : '' ?> remaining
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:var(--gr-bg);color:var(--green)">
      <i class="fa-solid fa-user-plus"></i>
    </div>
    <div class="stat-main">
      <div class="stat-num"><?= $leadsMonth ?></div>
      <div class="stat-label">Leads this month</div>
    </div>
    <div class="stat-sub">
      <a href="/app/broker/leads.php" style="color:var(--p);text-decoration:none;">View all →</a>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:var(--teal-bg);color:var(--teal)">
      <i class="fa-solid fa-chart-line"></i>
    </div>
    <div class="stat-main">
      <div class="stat-num"><?= $convRate ?>%</div>
      <div class="stat-label">Conversion rate</div>
    </div>
    <div class="stat-sub">All-time, <?= number_format((int)$conv['total']) ?> total leads</div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:var(--amb-bg);color:var(--amber)">
      <i class="fa-solid fa-sack-dollar"></i>
    </div>
    <div class="stat-main">
      <div class="stat-num" style="font-size:1.2rem">
        <?= formatZAR($earningsPending) ?>
      </div>
      <div class="stat-label">Earnings pending</div>
    </div>
    <div class="stat-sub">
      <a href="/app/broker/earnings.php" style="color:var(--p);text-decoration:none;">View earnings →</a>
    </div>
  </div>
</div>

<!-- Quick links -->
<div class="quick-links">
  <a href="/app/broker/inventory.php" class="quick-link">
    <div class="ql-icon"><i class="fa-solid fa-shop"></i></div>
    <div>
      <div class="ql-title">Browse marketplace</div>
      <div class="ql-sub">Find cars to add to your desk</div>
    </div>
    <i class="fa-solid fa-chevron-right ql-arrow"></i>
  </a>
  <a href="/app/broker/leads.php" class="quick-link">
    <div class="ql-icon"><i class="fa-solid fa-inbox"></i></div>
    <div>
      <div class="ql-title">My leads</div>
      <div class="ql-sub">Manage buyer enquiries</div>
    </div>
    <i class="fa-solid fa-chevron-right ql-arrow"></i>
  </a>
  <a href="/app/broker/earnings.php" class="quick-link">
    <div class="ql-icon"><i class="fa-solid fa-wallet"></i></div>
    <div>
      <div class="ql-title">Earnings &amp; payouts</div>
      <div class="ql-sub">Track commissions and bank account</div>
    </div>
    <i class="fa-solid fa-chevron-right ql-arrow"></i>
  </a>
</div>

<!-- Recent leads -->
<?php if (!empty($recentLeads)): ?>
<div style="margin-top:1.75rem;">
  <div class="section-head" style="margin-bottom:.75rem;">
    <h2 class="section-title">Recent leads</h2>
    <a href="/app/broker/leads.php" style="margin-left:auto;font-size:12px;color:var(--p);text-decoration:none;">
      View all →
    </a>
  </div>
  <div class="roster-wrap">
    <table class="roster">
      <thead>
        <tr>
          <th>Buyer</th>
          <th>Car</th>
          <th>Intent</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recentLeads as $lead): ?>
      <tr>
        <td style="font-weight:500;"><?= htmlspecialchars($lead['buyer_name']) ?></td>
        <td style="font-size:12px;color:var(--muted);">
          <?= htmlspecialchars($lead['year'] . ' ' . $lead['make'] . ' ' . $lead['model']) ?>
        </td>
        <td>
          <?php
          $intentBadge = match($lead['buyer_intent']) {
            'within_30d' => ['🔥', 'Within 30 days', 'var(--red-bg)', 'var(--red)', 'var(--red-b)'],
            'one_to_3mo' => ['🗓️', '1–3 months',    'var(--amb-bg)', 'var(--amber)', 'var(--amb-b)'],
            default      => ['👀', 'Browsing',        'var(--bg)',    'var(--faint)', 'var(--border)'],
          };
          ?>
          <span style="font-size:10px;font-family:var(--mono);padding:2px 7px;border-radius:9999px;
                       background:<?= $intentBadge[2] ?>;color:<?= $intentBadge[3] ?>;
                       border:1px solid <?= $intentBadge[4] ?>">
            <?= $intentBadge[0] ?> <?= $intentBadge[1] ?>
          </span>
        </td>
        <td>
          <span class="badge badge-<?= $lead['status'] === 'new' ? 'new' : ($lead['status'] === 'closed' ? 'verified' : 'pending') ?>">
            <?= ucfirst($lead['status']) ?>
          </span>
        </td>
        <td style="font-size:11px;color:var(--faint);">
          <?= date('d M', strtotime($lead['created_at'])) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
/* ── Broker dashboard styles ─────────────────────────────── */
.context-bar {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 4px;
  display: flex; margin-bottom: 1.25rem; overflow-x: auto;
}
.context-options { display: flex; gap: 2px; }
.context-opt {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: var(--r-md);
  font-size: 13px; color: var(--muted);
  text-decoration: none; white-space: nowrap;
  transition: background .15s, color .15s;
}
.context-opt:hover  { background: var(--white); color: var(--text); }
.context-opt.active { background: var(--white); color: var(--text); font-weight: 600;
                      box-shadow: var(--shadow-sm); }

.dash-header  { display: flex; align-items: flex-start; justify-content: space-between;
                flex-wrap: wrap; gap: 12px; margin-bottom: 1.5rem; }
.dash-title   { font-family: var(--serif); font-size: 1.6rem; font-weight: 300; line-height: 1.1; }
.dash-sub     { font-size: 13px; color: var(--muted); margin-top: 3px; }

.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1.25rem; }
.stat-card {
  background: var(--white); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 16px;
}
.stat-icon {
  width: 40px; height: 40px; border-radius: var(--r-md);
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; margin-bottom: 10px;
}
.stat-num   { font-size: 1.6rem; font-weight: 700; font-family: var(--mono); color: var(--text); }
.stat-denom { font-size: 1rem; color: var(--faint); }
.stat-label { font-size: 11px; color: var(--muted); margin-top: 1px; }
.stat-sub   { font-size: 11px; color: var(--faint); margin-top: 8px; }

.quick-links { display: flex; flex-direction: column; gap: 8px; }
.quick-link {
  background: var(--white); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 14px 16px;
  display: flex; align-items: center; gap: 14px;
  text-decoration: none; transition: border-color .15s, box-shadow .15s;
}
.quick-link:hover { border-color: var(--p-b); box-shadow: var(--shadow-sm); }
.ql-icon {
  width: 38px; height: 38px; border-radius: var(--r-md);
  background: var(--p-light); color: var(--p);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0;
}
.ql-title { font-size: 13px; font-weight: 600; color: var(--text); }
.ql-sub   { font-size: 11px; color: var(--muted); margin-top: 1px; }
.ql-arrow { margin-left: auto; color: var(--faint); font-size: 12px; }

@media (max-width: 640px) {
  .stat-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
