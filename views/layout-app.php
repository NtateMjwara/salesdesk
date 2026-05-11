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

// ── User display name + initials ──────────────────────────────
// Fetch first/last name from profiles for the avatar initials and
// the dropdown panel. Falls back gracefully if the query fails.
$currentUserFirstName = '';
$currentUserLastName  = '';
$currentUserEmail     = '';

if ($currentUserId) {
    try {
        $pdo ??= Database::getInstance();
        $profileStmt = $pdo->prepare("
            SELECT p.first_name, p.last_name, u.email
            FROM profiles p
            JOIN users u ON u.id = p.user_id
            WHERE p.user_id = ?
            LIMIT 1
        ");
        $profileStmt->execute([$currentUserId]);
        $profileRow = $profileStmt->fetch();
        if ($profileRow) {
            $currentUserFirstName = $profileRow['first_name'] ?? '';
            $currentUserLastName  = $profileRow['last_name']  ?? '';
            $currentUserEmail     = $profileRow['email']      ?? '';
        }
    } catch (Throwable) {
        // Silent fail — initials will fall back to role abbreviation below.
    }
}

// Build initials: first letter of first name + first letter of last name.
// Fallback: first two letters of the role slug (e.g. "BR", "DE", "EX", "AD").
$userInitials = '';
if ($currentUserFirstName || $currentUserLastName) {
    $userInitials = strtoupper(
        substr($currentUserFirstName, 0, 1) .
        substr($currentUserLastName,  0, 1)
    );
}
if (!$userInitials) {
    $userInitials = strtoupper(substr($currentUserRole, 0, 2));
}

$currentUserDisplayName = trim("{$currentUserFirstName} {$currentUserLastName}") ?: '';

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

// Settings page path per role.
$settingsPath = match ($currentUserRole) {
    'sales_exec' => '/app/exec/settings.php',
    'dealer'     => '/app/dealer/settings.php',
    default      => '/app/settings.php',
};
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
    /* ── Layout-app scoped styles ── */
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

    /* ── Avatar dropdown ── */
    .nav-avatar-wrap {
      position: relative;
      flex-shrink: 0;
    }

    .topnav-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: var(--p-light);
      color: var(--p);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-family: var(--mono);
      font-weight: 700;
      cursor: pointer;
      border: 1.5px solid var(--p-b);
      user-select: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .topnav-avatar:hover,
    .nav-avatar-wrap.open .topnav-avatar {
      border-color: var(--p);
      box-shadow: 0 0 0 3px rgba(15,76,158,.1);
    }

    .avatar-dropdown {
      display: none;
      position: absolute;
      top: calc(100% + 10px);
      right: 0;
      min-width: 220px;
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--r-lg);
      box-shadow: var(--shadow-lg);
      z-index: var(--z-dropdown);
      overflow: hidden;
      animation: dropdown-in .15s ease;
    }

    @keyframes dropdown-in {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .nav-avatar-wrap.open .avatar-dropdown { display: block; }

    /* Identity block at top of dropdown */
    .avatar-dd-identity {
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      background: var(--bg);
    }
    .avatar-dd-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .avatar-dd-email {
      font-size: 11px;
      color: var(--faint);
      font-family: var(--mono);
      margin-top: 1px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .avatar-dd-role {
      display: inline-flex;
      align-items: center;
      margin-top: 6px;
      font-size: 10px;
      font-family: var(--mono);
      padding: 2px 7px;
      border-radius: var(--r-full);
      border: 1px solid var(--border);
      color: var(--muted);
      background: var(--white);
    }

    /* Menu links inside dropdown */
    .avatar-dd-menu {
      padding: 6px 0;
    }
    .avatar-dd-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 16px;
      font-size: 13px;
      color: var(--text2);
      text-decoration: none;
      transition: background .12s, color .12s;
      cursor: pointer;
      border: none;
      background: none;
      width: 100%;
      text-align: left;
      font-family: var(--sans);
    }
    .avatar-dd-item:hover { background: var(--bg); color: var(--text); }
    .avatar-dd-item .dd-icon {
      width: 16px;
      text-align: center;
      color: var(--faint);
      font-size: 12px;
      flex-shrink: 0;
    }
    .avatar-dd-item:hover .dd-icon { color: var(--muted); }

    /* Logout — visually distinct */
    .avatar-dd-item.dd-logout {
      color: var(--red);
      border-top: 1px solid var(--border);
    }
    .avatar-dd-item.dd-logout .dd-icon { color: var(--red); opacity: .7; }
    .avatar-dd-item.dd-logout:hover { background: var(--red-bg); }
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

  <!-- ── Avatar + dropdown ── -->
  <div class="nav-avatar-wrap" id="avatarWrap">
    <div class="topnav-avatar"
         id="avatarBtn"
         role="button"
         aria-haspopup="true"
         aria-expanded="false"
         aria-label="Account menu"
         title="Account">
      <?= htmlspecialchars($userInitials) ?>
    </div>

    <div class="avatar-dropdown" id="avatarDropdown" role="menu">

      <!-- Identity block -->
      <div class="avatar-dd-identity">
        <?php if ($currentUserDisplayName): ?>
        <div class="avatar-dd-name"><?= htmlspecialchars($currentUserDisplayName) ?></div>
        <?php endif; ?>
        <?php if ($currentUserEmail): ?>
        <div class="avatar-dd-email"><?= htmlspecialchars($currentUserEmail) ?></div>
        <?php endif; ?>
        <span class="avatar-dd-role"><?= match($currentUserRole) {
          'broker'     => 'Auto Broker',
          'dealer'     => 'Dealer Principal',
          'sales_exec' => 'Sales Executive',
          'admin'      => 'Admin',
          default      => ucfirst($currentUserRole),
        } ?></span>
      </div>

      <!-- Menu items -->
      <div class="avatar-dd-menu">

        <a href="<?= htmlspecialchars($settingsPath) ?>" class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-gear"></i></span>
          Account settings
        </a>

        <?php if ($currentUserRole === 'broker'): ?>
        <a href="/app/broker/earnings.php" class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-wallet"></i></span>
          Earnings &amp; payouts
        </a>
        <?php endif; ?>

        <?php if (in_array($currentUserRole, ['dealer', 'sales_exec'])): ?>
        <a href="<?= $currentUserRole === 'dealer'
            ? '/app/dealer/analytics.php'
            : '/app/exec/analytics.php' ?>"
           class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-chart-bar"></i></span>
          Analytics
        </a>
        <?php endif; ?>

        <a href="/app/notifications.php" class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-regular fa-bell"></i></span>
          Notifications
          <?php if ($unreadCount > 0): ?>
          <span style="margin-left:auto;font-size:10px;font-family:var(--mono);
                       background:var(--red-bg);color:var(--red);
                       border:1px solid var(--red-b);border-radius:var(--r-full);
                       padding:1px 6px;">
            <?= $unreadCount ?>
          </span>
          <?php endif; ?>
        </a>

        <!-- Logout -->
        <a href="/auth/logout.php" class="avatar-dd-item dd-logout" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-arrow-right-from-bracket"></i></span>
          Sign out
        </a>

      </div>
    </div><!-- /avatar-dropdown -->
  </div><!-- /nav-avatar-wrap -->

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

<script>
/* ── Avatar dropdown toggle ── */
(function () {
  var wrap   = document.getElementById('avatarWrap');
  var btn    = document.getElementById('avatarBtn');
  var dd     = document.getElementById('avatarDropdown');
  if (!wrap || !btn || !dd) return;

  function open() {
    wrap.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
  }

  function close() {
    wrap.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }

  function toggle() {
    wrap.classList.contains('open') ? close() : open();
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    toggle();
  });

  // Close when clicking anywhere outside the dropdown.
  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) close();
  });

  // Close on Escape.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  // Close when a menu link is followed (navigation).
  dd.querySelectorAll('.avatar-dd-item').forEach(function (item) {
    item.addEventListener('click', function () { close(); });
  });
})();
</script>

</body>
</html>
