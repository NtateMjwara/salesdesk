<?php
/**
 * SalesDesk — Authenticated Application Layout Shell
 * T1 owns this file — MERGE BLOCKER for all other teams.
 *
 * USAGE (any authenticated page):
 *
 *   <?php
 *   require_once '../includes/security.php';
 *   require_once '../includes/session.php';
 *   require_once '../includes/functions.php';
 *   requireLogin();
 *   applyCachePolicy('auth');
 *
 *   $pageTitle = 'My Page Title';
 *   $flashOk   = '';
 *   $flashError = '';
 *
 *   // Optional: override $assetVersion for cache-busting
 *   // $assetVersion = '20250501';
 *
 *   ob_start();
 *   // ... page HTML content ...
 *   $pageContent = ob_get_clean();
 *
 *   require_once '../views/layout-app.php';
 *
 * VARIABLES consumed (all optional except $pageTitle):
 *   string $pageTitle      — <title> and page header
 *   string $pageContent    — main page HTML (from ob_get_clean())
 *   string $flashOk        — green flash message
 *   string $flashError     — red flash message
 *   string $assetVersion   — cache-buster suffix (default: today YYYYMMDD)
 *   bool   $hideNav        — set true to suppress the top nav (e.g. onboarding)
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

$pageTitle    = $pageTitle    ?? 'SalesDesk';
$pageContent  = $pageContent  ?? '';
$flashOk      = $flashOk      ?? ($_SESSION['flash_ok']    ?? '');
$flashError   = $flashError   ?? ($_SESSION['flash_error'] ?? '');
$hideNav      = $hideNav      ?? false;
$assetVersion = $assetVersion ?? date('Ymd');

// Clear session flash after reading.
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// Current user context.
$currentUserId   = (int) ($_SESSION['user_id']   ?? 0);
$currentUserRole = $_SESSION['user_role'] ?? '';

// Unread notification count (shown in nav badge).
$unreadCount = 0;
if ($currentUserId) {
    try {
        $pdo     = Database::getInstance();
        $nStmt   = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nStmt->execute([$currentUserId]);
        $unreadCount = (int) $nStmt->fetchColumn();
    } catch (Throwable) {
        $unreadCount = 0;
    }
}

// Role-specific nav links.
$navLinks = match ($currentUserRole) {
    'broker'     => [
        '/app/broker/dashboard.php'  => 'Dashboard',
        '/app/broker/inventory.php'  => 'Marketplace',
        '/app/broker/leads.php'      => 'My Leads',
        '/app/broker/earnings.php'   => 'Earnings',
    ],
    'dealer'     => [
        '/app/dealer/dashboard.php'  => 'Dashboard',
        '/app/dealer/inventory.php'  => 'Inventory',
        '/app/dealer/leads.php'      => 'Leads',
        '/app/dealer/team.php'       => 'Team',
    ],
    'sales_exec' => [
        '/app/exec/dashboard.php'    => 'Dashboard',
        '/app/exec/inventory.php'    => 'My Listings',
        '/app/exec/leads.php'        => 'Leads',
    ],
    'admin'      => [
        '/app/admin/users.php'       => 'Users',
        '/app/admin/payouts.php'     => 'Payouts',
        '/app/admin/audit.php'       => 'Audit',
    ],
    default      => [],
};

// Detect current path for active nav state.
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken()) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> | SalesDesk</title>

  <!-- Global assets -->
  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/components.css?v=<?= $assetVersion ?>">

  <!-- Font Awesome (icons) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Role-specific CSS -->
  <?php if ($currentUserRole === 'broker'): ?>
  <link rel="stylesheet" href="/assets/css/broker.css?v=<?= $assetVersion ?>">
  <?php elseif (in_array($currentUserRole, ['dealer', 'sales_exec'])): ?>
  <link rel="stylesheet" href="/assets/css/dealer.css?v=<?= $assetVersion ?>">
  <?php endif; ?>

  <style>
    /* Layout-app scoped styles */
    .topnav {
      background: var(--white);
      border-bottom: 1px solid var(--border);
      padding: 0 1.5rem;
      display: flex;
      align-items: center;
      height: 56px;
      gap: 1.25rem;
      position: sticky;
      top: 0;
      z-index: var(--z-sticky);
    }
    .topnav-brand {
      font-family: var(--serif);
      font-size: 1.1rem;
      font-weight: 500;
      color: var(--text);
      text-decoration: none;
      flex-shrink: 0;
    }
    .topnav-brand em { font-style: italic; color: var(--p); }
    .topnav-sep { flex: 1; }
    .topnav-link {
      font-size: 13px;
      color: var(--muted);
      text-decoration: none;
      padding: 4px 8px;
      border-radius: var(--r-sm);
      transition: color .15s, background .15s;
      white-space: nowrap;
    }
    .topnav-link:hover { color: var(--text); background: var(--bg); }
    .topnav-link.active { color: var(--p); font-weight: 600; }
    .topnav-notif {
      position: relative;
      color: var(--muted);
      font-size: 16px;
      padding: 4px 8px;
      text-decoration: none;
      line-height: 1;
    }
    .topnav-notif:hover { color: var(--text); }
    .notif-badge {
      position: absolute;
      top: 0; right: 2px;
      min-width: 16px; height: 16px;
      background: var(--red);
      color: #fff;
      font-size: 10px;
      font-family: var(--mono);
      font-weight: 700;
      border-radius: var(--r-full);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 3px;
    }
    .topnav-avatar {
      width: 32px; height: 32px;
      border-radius: 50%;
      background: var(--p-light);
      color: var(--p);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-family: var(--mono);
      font-weight: 700;
      text-decoration: none;
      flex-shrink: 0;
      border: 1px solid var(--p-b);
    }
    .topnav-avatar:hover { border-color: var(--p); }
    .role-chip {
      font-size: 10px;
      font-family: var(--mono);
      padding: 2px 7px;
      border-radius: var(--r-full);
      border: 1px solid var(--border);
      color: var(--muted);
      flex-shrink: 0;
    }
    .app-body {
      max-width: 1100px;
      margin: 0 auto;
      padding: 2rem 1.5rem 4rem;
    }
  </style>
</head>
<body>

<?php if (!$hideNav): ?>
<!-- ── Top navigation ───────────────────────────────────────── -->
<nav class="topnav" role="navigation" aria-label="Main navigation">
  <a href="<?= match($currentUserRole) {
    'broker'     => '/app/broker/dashboard.php',
    'dealer'     => '/app/dealer/dashboard.php',
    'sales_exec' => '/app/exec/dashboard.php',
    'admin'      => '/app/admin/users.php',
    default      => '/app/dashboard.php',
  } ?>" class="topnav-brand">
    Sales<em>Desk</em>
  </a>

  <span class="topnav-sep"></span>

  <?php foreach ($navLinks as $href => $label): ?>
  <a href="<?= $href ?>"
     class="topnav-link<?= str_starts_with($currentPath, $href) ? ' active' : '' ?>">
    <?= htmlspecialchars($label) ?>
  </a>
  <?php endforeach; ?>

  <!-- Notification bell -->
  <a href="/app/notifications.php" class="topnav-notif" aria-label="Notifications"
     id="notifBell">
    <i class="fa-regular fa-bell"></i>
    <?php if ($unreadCount > 0): ?>
    <span class="notif-badge" id="notifBadge" aria-label="<?= $unreadCount ?> unread">
      <?= $unreadCount < 100 ? $unreadCount : '99+' ?>
    </span>
    <?php endif; ?>
  </a>

  <!-- Role chip -->
  <span class="role-chip"><?= match($currentUserRole) {
    'broker'     => 'Broker',
    'dealer'     => 'Dealer',
    'sales_exec' => 'Exec',
    'admin'      => 'Admin',
    default      => '—',
  } ?></span>

  <!-- Account avatar link -->
  <a href="/app/settings.php" class="topnav-avatar" title="Account settings">
    <?= strtoupper(substr($currentUserRole, 0, 2)) ?>
  </a>
</nav>
<?php endif; ?>

<!-- ── Flash messages ───────────────────────────────────────── -->
<?php if ($flashOk || $flashError): ?>
<div style="max-width:1100px;margin:1rem auto;padding:0 1.5rem;">
  <?php if ($flashOk): ?>
  <div class="alert alert-success flash flash-ok">
    <i class="fa-solid fa-circle-check alert-icon"></i>
    <?= htmlspecialchars($flashOk) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="alert alert-error flash flash-error">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= htmlspecialchars($flashError) ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Page content ─────────────────────────────────────────── -->
<main class="app-body" id="main-content">
  <?= $pageContent ?>
</main>

<!-- ── Global JS ───────────────────────────────────────────── -->
<script src="/assets/js/global.js?v=<?= $assetVersion ?>"></script>

</body>
</html>
