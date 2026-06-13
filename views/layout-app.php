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
require_once __DIR__ . '/../includes/csrf.php';
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
        // Silent fail — initials fall back to role abbreviation below.
    }
}

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
  <link rel="stylesheet" href="/assets/css/dealer-dashboard-patch.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/dealer-analytics-patch.css?v=<?= $assetVersion ?>">
  <?php endif; ?>

  <style>
    /* ══════════════════════════════════════════════════════════
       LAYOUT-APP SCOPED STYLES
       Sections:
         A. Top navigation bar
         B. Avatar dropdown
         C. Mobile hamburger button
         D. Mobile nav drawer + overlay
         E. App body content area
         F. Flash messages
         G. Responsive — tablet (≤ 900px)
         H. Responsive — mobile landscape (≤ 768px)
         I. Responsive — mobile portrait (≤ 480px)
         J. Responsive — small phone (≤ 360px)
         K. Orientation — landscape on short devices
         L. Touch / hover overrides
         M. Safe-area insets (notched devices)
         N. Print
    ══════════════════════════════════════════════════════════ */


    /* ── A. TOP NAVIGATION BAR ──────────────────────────────── */
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
      /* Safe-area: prevent notch overlap on landscape iPhones */
      padding-left: max(1.5rem, env(safe-area-inset-left));
      padding-right: max(1.5rem, env(safe-area-inset-right));
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
    .topnav-brand:hover { text-decoration: none; }

    /* Spacer that pushes right-side controls flush right */
    .topnav-sep { flex: 1; }

    /* Desktop nav links — hidden on mobile via responsive section G */
    .topnav-links {
      display: flex;
      align-items: center;
      gap: 0.25rem;
      flex-shrink: 0;
    }

    .topnav-link {
      font-size: 13px;
      color: var(--muted);
      text-decoration: none;
      padding: 5px 10px;
      border-radius: var(--r-sm);
      transition: color .15s, background .15s;
      white-space: nowrap;
    }
    .topnav-link:hover { color: var(--text); background: var(--bg); text-decoration: none; }
    .topnav-link.active {
      color: var(--p);
      font-weight: 600;
      background: var(--p-light);
    }

    /* Notification bell */
    .topnav-notif {
      position: relative;
      color: var(--muted);
      font-size: 16px;
      padding: 6px 8px;
      text-decoration: none;
      line-height: 1;
      border-radius: var(--r-sm);
      transition: color .15s, background .15s;
      flex-shrink: 0;
      /* Minimum tap target */
      min-width: 36px;
      min-height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .topnav-notif:hover { color: var(--text); background: var(--bg); }

    .notif-badge {
      position: absolute;
      top: 1px; right: 1px;
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
      pointer-events: none;
    }

    /* Role chip — hidden on smallest screens via responsive section I */
    .role-chip {
      font-size: 10px;
      font-family: var(--mono);
      padding: 2px 7px;
      border-radius: var(--r-full);
      border: 1px solid var(--border);
      color: var(--muted);
      flex-shrink: 0;
      white-space: nowrap;
    }


    /* ── B. AVATAR DROPDOWN ─────────────────────────────────── */
    .nav-avatar-wrap {
      position: relative;
      flex-shrink: 0;
    }

    .topnav-avatar {
      width: 34px;
      height: 34px;
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
      /* Minimum tap target via padding trick */
      box-sizing: content-box;
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

    .avatar-dd-menu { padding: 6px 0; }

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
      /* Minimum tap target */
      min-height: 40px;
    }
    .avatar-dd-item:hover { background: var(--bg); color: var(--text); text-decoration: none; }
    .avatar-dd-item .dd-icon {
      width: 16px;
      text-align: center;
      color: var(--faint);
      font-size: 12px;
      flex-shrink: 0;
    }
    .avatar-dd-item:hover .dd-icon { color: var(--muted); }

    .avatar-dd-item.dd-logout {
      color: var(--red);
      border-top: 1px solid var(--border);
    }
    .avatar-dd-item.dd-logout .dd-icon { color: var(--red); opacity: .7; }
    .avatar-dd-item.dd-logout:hover { background: var(--red-bg); }


    /* ── C. MOBILE HAMBURGER BUTTON ─────────────────────────── */
    /* Hidden on desktop, shown ≤ 900px */
    .topnav-hamburger {
      display: none;
      flex-direction: column;
      justify-content: center;
      gap: 5px;
      width: 36px;
      height: 36px;
      border: 1px solid var(--border);
      border-radius: var(--r-sm);
      background: var(--bg);
      cursor: pointer;
      padding: 8px;
      flex-shrink: 0;
      transition: background .15s, border-color .15s;
      /* Ensure minimum touch target */
      min-width: 44px;
      min-height: 44px;
      align-items: center;
    }
    .topnav-hamburger:hover { background: var(--p-light); border-color: var(--p-b); }

    .topnav-hamburger span {
      display: block;
      width: 18px;
      height: 2px;
      background: var(--text);
      border-radius: 1px;
      transition: transform .22s ease, opacity .22s ease;
    }
    /* Animated ✕ state when drawer is open */
    .topnav-hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .topnav-hamburger.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
    .topnav-hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }


    /* ── D. MOBILE NAV DRAWER + OVERLAY ─────────────────────── */
    /* Semi-transparent backdrop */
    .app-nav-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .4);
      z-index: calc(var(--z-sticky) + 5);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
    }
    .app-nav-overlay.open { display: block; }

    /* Slide-in drawer */
    .app-nav-drawer {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: min(300px, 85vw);
      background: var(--white);
      z-index: calc(var(--z-sticky) + 10);
      box-shadow: 4px 0 32px rgba(0, 0, 0, .18);
      overflow-y: auto;
      overscroll-behavior: contain;
      transform: translateX(-100%);
      transition: transform .28s cubic-bezier(.2, 0, 0, 1);
      display: flex;
      flex-direction: column;
      /* Safe-area: left notch on landscape */
      padding-bottom: env(safe-area-inset-bottom);
    }
    .app-nav-drawer.open { transform: translateX(0); }

    /* Drawer header — branding + close button */
    .drawer-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      height: 56px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }
    .drawer-brand {
      font-family: var(--serif);
      font-size: 1.05rem;
      font-weight: 500;
      color: var(--text);
      text-decoration: none;
    }
    .drawer-brand em { font-style: italic; color: var(--p); }
    .drawer-brand:hover { text-decoration: none; }

    .drawer-close {
      width: 32px;
      height: 32px;
      border-radius: var(--r-sm);
      border: 1px solid var(--border);
      background: var(--bg);
      color: var(--muted);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      transition: background .15s, color .15s;
      padding: 0;
    }
    .drawer-close:hover { background: var(--red-bg); color: var(--red); border-color: var(--red-b); }

    /* Drawer identity block (mirrors avatar dropdown identity) */
    .drawer-identity {
      padding: 16px;
      border-bottom: 1px solid var(--border);
      background: var(--bg);
      display: flex;
      align-items: center;
      gap: 12px;
      flex-shrink: 0;
    }
    .drawer-identity-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--p-light);
      color: var(--p);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-family: var(--mono);
      font-weight: 700;
      border: 1.5px solid var(--p-b);
      flex-shrink: 0;
    }
    .drawer-identity-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--text);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .drawer-identity-role {
      display: inline-flex;
      align-items: center;
      margin-top: 3px;
      font-size: 10px;
      font-family: var(--mono);
      padding: 2px 7px;
      border-radius: var(--r-full);
      border: 1px solid var(--border);
      color: var(--muted);
      background: var(--white);
    }

    /* Drawer nav links */
    .drawer-nav { flex: 1; padding: 8px 0; }

    .drawer-section-label {
      font-size: 9px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--faint);
      padding: 10px 16px 4px;
    }

    .drawer-link {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 11px 16px;
      font-size: 14px;
      font-weight: 500;
      color: var(--text);
      text-decoration: none;
      transition: background .13s, color .13s;
      border-radius: 0;
      /* Minimum tap target */
      min-height: 44px;
    }
    .drawer-link i {
      width: 16px;
      text-align: center;
      color: var(--faint);
      font-size: 13px;
      flex-shrink: 0;
    }
    .drawer-link:hover, .drawer-link:active {
      background: var(--p-light);
      color: var(--p);
      text-decoration: none;
    }
    .drawer-link:hover i, .drawer-link:active i { color: var(--p); }
    .drawer-link.active {
      background: var(--p-light);
      color: var(--p);
      font-weight: 600;
    }
    .drawer-link.active i { color: var(--p); }

    /* Notification count inside drawer */
    .drawer-link-badge {
      margin-left: auto;
      font-size: 10px;
      font-family: var(--mono);
      font-weight: 700;
      background: var(--red);
      color: #fff;
      border-radius: var(--r-full);
      padding: 1px 6px;
      min-width: 18px;
      text-align: center;
    }

    /* Drawer footer — sign out */
    .drawer-footer {
      border-top: 1px solid var(--border);
      padding: 8px 0;
      flex-shrink: 0;
    }
    .drawer-signout {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 11px 16px;
      font-size: 14px;
      font-weight: 500;
      color: var(--red);
      text-decoration: none;
      transition: background .13s;
      min-height: 44px;
    }
    .drawer-signout i { color: var(--red); opacity: .7; font-size: 13px; flex-shrink: 0; }
    .drawer-signout:hover, .drawer-signout:active {
      background: var(--red-bg);
      text-decoration: none;
    }


    /* ── E. APP BODY ─────────────────────────────────────────── */
    .app-body {
      max-width: 1100px;
      margin: 0 auto;
      padding: 2rem 1.5rem 4rem;
      /* Safe-area: ensure content isn't hidden under notch/home-bar */
      padding-left: max(1.5rem, env(safe-area-inset-left));
      padding-right: max(1.5rem, env(safe-area-inset-right));
      padding-bottom: max(4rem, calc(2rem + env(safe-area-inset-bottom)));
    }


    /* ── F. FLASH MESSAGES ───────────────────────────────────── */
    .flash-wrap {
      max-width: 1100px;
      margin: 1rem auto 0;
      padding: 0 1.5rem;
      padding-left: max(1.5rem, env(safe-area-inset-left));
      padding-right: max(1.5rem, env(safe-area-inset-right));
    }


    /* ══════════════════════════════════════
       G. RESPONSIVE — TABLET (≤ 900px)
       Nav links collapse; hamburger appears.
    ══════════════════════════════════════ */
    @media (max-width: 900px) {
      /* Hide inline nav links */
      .topnav-links { display: none; }
      /* Hide role chip — space is at a premium */
      .role-chip { display: none; }
      /* Show hamburger */
      .topnav-hamburger { display: flex; }

      /* Reduce horizontal padding to recover space */
      .topnav { padding-left: 1rem; padding-right: 1rem; gap: 0.75rem; }

      /* Avatar dropdown: prevent right-edge clip on narrower viewports */
      .avatar-dropdown {
        right: 0;
        left: auto;
        min-width: 200px;
      }
    }


    /* ══════════════════════════════════════
       H. RESPONSIVE — MOBILE LANDSCAPE (≤ 768px)
    ══════════════════════════════════════ */
    @media (max-width: 768px) {
      .topnav { height: 52px; padding-left: 0.875rem; padding-right: 0.875rem; gap: 0.5rem; }
      .topnav-brand { font-size: 1rem; }

      .app-body { padding: 1.25rem 1rem 3rem; }

      /* Flash messages padding */
      .flash-wrap { padding: 0 1rem; margin-top: 0.75rem; }

      /* Avatar dropdown: on very narrow viewports, pin it to the right but
         allow it to grow left so it doesn't clip off screen. */
      .avatar-dropdown {
        right: -0.5rem;
        min-width: 210px;
        max-width: calc(100vw - 2rem);
      }
    }


    /* ══════════════════════════════════════
       I. RESPONSIVE — MOBILE PORTRAIT (≤ 480px)
    ══════════════════════════════════════ */
    @media (max-width: 480px) {
      .topnav { height: 50px; padding-left: 0.75rem; padding-right: 0.75rem; gap: 0.375rem; }
      .topnav-brand { font-size: 0.95rem; }

      .app-body { padding: 1rem 0.875rem 2.5rem; }

      /* Notification bell: slightly tighter */
      .topnav-notif { padding: 5px 6px; font-size: 15px; }

      /* Avatar dropdown: full-width on small phones */
      .avatar-dropdown {
        position: fixed;
        top: 50px; /* matches nav height */
        left: 0.75rem;
        right: 0.75rem;
        min-width: unset;
        width: auto;
        max-width: none;
        border-radius: var(--r-lg);
        /* Slightly taller identity block looks better on full-width dropdown */
      }

      /* Flash messages */
      .flash-wrap { padding: 0 0.875rem; }
    }


    /* ══════════════════════════════════════
       J. RESPONSIVE — SMALL PHONE (≤ 360px)
    ══════════════════════════════════════ */
    @media (max-width: 360px) {
      .topnav { height: 48px; padding-left: 0.625rem; padding-right: 0.625rem; }
      .topnav-brand { font-size: 0.9rem; }
      .topnav-hamburger { min-width: 36px; min-height: 36px; }

      .app-body { padding: 0.875rem 0.75rem 2rem; }

      .avatar-dropdown { left: 0.5rem; right: 0.5rem; }
    }


    /* ══════════════════════════════════════
       K. ORIENTATION — LANDSCAPE ON SHORT DEVICES
       Covers iPhone SE landscape, Pixel 4a landscape, etc.
       max-height prevents navbar eating too much vertical space.
    ══════════════════════════════════════ */
    @media (max-height: 500px) and (orientation: landscape) {
      .topnav { height: 46px; }

      /* Drawer height fills full viewport; inner items compact */
      .app-nav-drawer { border-radius: 0; }
      .drawer-header  { height: 46px; }
      .drawer-link    { padding: 9px 16px; min-height: 40px; }
      .drawer-signout { padding: 9px 16px; min-height: 40px; }

      .app-body { padding-top: 1rem; }

      /* Avatar dropdown: position below the shorter nav */
      .avatar-dropdown { top: calc(46px + 8px); }
    }

    /* Very short landscape (e.g. Galaxy Fold open landscape) */
    @media (max-height: 400px) and (orientation: landscape) {
      .topnav { height: 42px; }
      .app-body { padding-top: 0.75rem; }
    }


    /* ══════════════════════════════════════
       L. TOUCH / HOVER OVERRIDES
       Removes hover styles that "stick" on
       touch devices (no real hover event).
    ══════════════════════════════════════ */
    @media (hover: none) and (pointer: coarse) {
      /* Disable the focus-ring-on-hover on nav items */
      .topnav-link:hover,
      .topnav-notif:hover {
        background: transparent;
        color: var(--muted);
      }
      /* Active tap feedback instead */
      .topnav-link:active { background: var(--p-light); color: var(--p); }
      .topnav-notif:active { background: var(--bg); color: var(--text); }

      /* Avatar dropdown items: no hover state on touch */
      .avatar-dd-item:hover { background: transparent; color: var(--text2); }
      .avatar-dd-item:active { background: var(--bg); color: var(--text); }

      /* Drawer links */
      .drawer-link:hover { background: transparent; color: var(--text); }
      .drawer-link:active { background: var(--p-light); color: var(--p); }

      /* Hamburger */
      .topnav-hamburger:hover { background: var(--bg); border-color: var(--border); }
      .topnav-hamburger:active { background: var(--p-light); border-color: var(--p-b); }
    }


    /* ══════════════════════════════════════
       M. SAFE-AREA INSETS
       Handles notched iPhones, punch-hole
       Androids, and iPads with home bar.
       Already applied above via env() on
       .topnav, .app-body, .flash-wrap.
       This section adds the drawer.
    ══════════════════════════════════════ */
    @supports (padding: env(safe-area-inset-left)) {
      .app-nav-drawer {
        /* In landscape, drawer left edge can be behind the notch */
        padding-left: env(safe-area-inset-left);
      }
    }


    /* ══════════════════════════════════════
       N. PRINT
    ══════════════════════════════════════ */
    @media print {
      .topnav,
      .topnav-hamburger,
      .app-nav-overlay,
      .app-nav-drawer,
      .topnav-notif,
      .role-chip,
      .nav-avatar-wrap { display: none !important; }

      .app-body {
        max-width: 100%;
        padding: 0;
        margin: 0;
      }

      .flash-wrap { padding: 0; }
    }
  </style>
</head>
<body>

<!-- ── Mobile nav overlay (backdrop) ───────────────────────── -->
<div class="app-nav-overlay" id="appNavOverlay" aria-hidden="true"></div>

<!-- ── Mobile nav drawer ────────────────────────────────────── -->
<nav class="app-nav-drawer" id="appNavDrawer" aria-label="Mobile navigation" aria-hidden="true">

  <!-- Drawer header -->
  <div class="drawer-header">
    <a href="<?= match($currentUserRole) {
      'broker'     => '/app/broker/dashboard.php',
      'dealer'     => '/app/dealer/dashboard.php',
      'sales_exec' => '/app/exec/dashboard.php',
      'admin'      => '/app/admin/users.php',
      default      => '/app/dashboard.php',
    } ?>" class="drawer-brand">
      Sales<em>Desk</em>
    </a>
    <button class="drawer-close" id="drawerClose" aria-label="Close navigation">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
  </div>

  <!-- Identity block -->
  <?php if ($currentUserDisplayName || $currentUserEmail): ?>
  <div class="drawer-identity">
    <div class="drawer-identity-avatar"><?= htmlspecialchars($userInitials) ?></div>
    <div style="min-width: 0;">
      <?php if ($currentUserDisplayName): ?>
      <div class="drawer-identity-name"><?= htmlspecialchars($currentUserDisplayName) ?></div>
      <?php endif; ?>
      <span class="drawer-identity-role"><?= match($currentUserRole) {
        'broker'     => 'Auto Broker',
        'dealer'     => 'Dealer Principal',
        'sales_exec' => 'Sales Executive',
        'admin'      => 'Admin',
        default      => ucfirst($currentUserRole),
      } ?></span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Navigation links -->
  <div class="drawer-nav">
    <?php if ($navLinks): ?>
    <div class="drawer-section-label">Navigation</div>
    <?php foreach ($navLinks as $href => $label):
      $isActive = str_starts_with($currentPath, $href);
    ?>
    <a href="<?= $href ?>"
       class="drawer-link<?= $isActive ? ' active' : '' ?>"
       <?= $isActive ? 'aria-current="page"' : '' ?>>
      <i class="fa-solid <?= match($label) {
        'Dashboard'   => 'fa-gauge-high',
        'Marketplace' => 'fa-shop',
        'My Leads'    => 'fa-user-group',
        'Leads'       => 'fa-user-group',
        'Earnings'    => 'fa-wallet',
        'Inventory'   => 'fa-car',
        'My Listings' => 'fa-list',
        'Team'        => 'fa-users',
        'Users'       => 'fa-users',
        'Payouts'     => 'fa-money-bill-transfer',
        'Audit'       => 'fa-shield-halved',
        default       => 'fa-circle-dot',
      } ?>" aria-hidden="true"></i>
      <?= htmlspecialchars($label) ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="drawer-section-label" style="margin-top: 8px;">Account</div>

    <a href="<?= htmlspecialchars($settingsPath) ?>" class="drawer-link">
      <i class="fa-solid fa-gear" aria-hidden="true"></i>
      Account settings
    </a>

    <?php if ($currentUserRole === 'broker'): ?>
    <a href="/app/broker/earnings.php" class="drawer-link">
      <i class="fa-solid fa-wallet" aria-hidden="true"></i>
      Earnings &amp; payouts
    </a>
    <?php endif; ?>

    <?php if (in_array($currentUserRole, ['dealer', 'sales_exec'])): ?>
    <a href="<?= $currentUserRole === 'dealer' ? '/app/dealer/analytics.php' : '/app/exec/analytics.php' ?>" class="drawer-link">
      <i class="fa-solid fa-chart-bar" aria-hidden="true"></i>
      Analytics
    </a>
    <?php endif; ?>

    <a href="/app/notifications.php" class="drawer-link">
      <i class="fa-regular fa-bell" aria-hidden="true"></i>
      Notifications
      <?php if ($unreadCount > 0): ?>
      <span class="drawer-link-badge"><?= $unreadCount < 100 ? $unreadCount : '99+' ?></span>
      <?php endif; ?>
    </a>
  </div>

  <!-- Drawer footer sign-out -->
  <div class="drawer-footer">
    <a href="/auth/logout.php" class="drawer-signout">
      <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
      Sign out
    </a>
  </div>

</nav>

<?php if (!$hideNav): ?>
<!-- ── Top navigation ───────────────────────────────────────── -->
<nav class="topnav" role="navigation" aria-label="Main navigation">

  <!-- Hamburger (mobile only — shown via CSS ≤900px) -->
  <button class="topnav-hamburger"
          id="navHamburger"
          aria-label="Open navigation"
          aria-expanded="false"
          aria-controls="appNavDrawer">
    <span></span>
    <span></span>
    <span></span>
  </button>

  <!-- Brand -->
  <a href="<?= match($currentUserRole) {
    'broker'     => '/app/broker/dashboard.php',
    'dealer'     => '/app/dealer/dashboard.php',
    'sales_exec' => '/app/exec/dashboard.php',
    'admin'      => '/app/admin/users.php',
    default      => '/app/dashboard.php',
  } ?>" class="topnav-brand" aria-label="SalesDesk home">
    Sales<em>Desk</em>
  </a>

  <!-- Desktop nav links (hidden ≤ 900px via CSS) -->
  <div class="topnav-links" role="list">
    <?php foreach ($navLinks as $href => $label): ?>
    <a href="<?= $href ?>"
       class="topnav-link<?= str_starts_with($currentPath, $href) ? ' active' : '' ?>"
       role="listitem"
       <?= str_starts_with($currentPath, $href) ? 'aria-current="page"' : '' ?>>
      <?= htmlspecialchars($label) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <span class="topnav-sep" aria-hidden="true"></span>

  <!-- Notification bell -->
  <a href="/app/notifications.php"
     class="topnav-notif"
     aria-label="<?= $unreadCount > 0 ? "{$unreadCount} unread notifications" : 'Notifications' ?>"
     id="notifBell">
    <i class="fa-regular fa-bell" aria-hidden="true"></i>
    <?php if ($unreadCount > 0): ?>
    <span class="notif-badge" id="notifBadge" aria-hidden="true">
      <?= $unreadCount < 100 ? $unreadCount : '99+' ?>
    </span>
    <?php endif; ?>
  </a>

  <!-- Role chip (hidden ≤ 900px via CSS) -->
  <span class="role-chip" aria-hidden="true"><?= match($currentUserRole) {
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
         tabindex="0"
         aria-haspopup="true"
         aria-expanded="false"
         aria-label="Account menu"
         title="Account">
      <?= htmlspecialchars($userInitials) ?>
    </div>

    <div class="avatar-dropdown" id="avatarDropdown" role="menu" aria-label="Account options">

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
          <span class="dd-icon"><i class="fa-solid fa-gear" aria-hidden="true"></i></span>
          Account settings
        </a>

        <?php if ($currentUserRole === 'broker'): ?>
        <a href="/app/broker/earnings.php" class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span>
          Earnings &amp; payouts
        </a>
        <?php endif; ?>

        <?php if (in_array($currentUserRole, ['dealer', 'sales_exec'])): ?>
        <a href="<?= $currentUserRole === 'dealer'
            ? '/app/dealer/analytics.php'
            : '/app/exec/analytics.php' ?>"
           class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-chart-bar" aria-hidden="true"></i></span>
          Analytics
        </a>
        <?php endif; ?>

        <a href="/app/notifications.php" class="avatar-dd-item" role="menuitem">
          <span class="dd-icon"><i class="fa-regular fa-bell" aria-hidden="true"></i></span>
          Notifications
          <?php if ($unreadCount > 0): ?>
          <span style="margin-left:auto;font-size:10px;font-family:var(--mono);
                       background:var(--red-bg);color:var(--red);
                       border:1px solid var(--red-b);border-radius:var(--r-full);
                       padding:1px 6px;" aria-label="<?= $unreadCount ?> unread">
            <?= $unreadCount ?>
          </span>
          <?php endif; ?>
        </a>

        <a href="/auth/logout.php" class="avatar-dd-item dd-logout" role="menuitem">
          <span class="dd-icon"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i></span>
          Sign out
        </a>
      </div>
    </div><!-- /avatar-dropdown -->
  </div><!-- /nav-avatar-wrap -->

</nav>
<?php endif; ?>

<!-- ── Flash messages ───────────────────────────────────────── -->
<?php if ($flashOk || $flashError): ?>
<div class="flash-wrap">
  <?php if ($flashOk): ?>
  <div class="alert alert-success flash flash-ok">
    <i class="fa-solid fa-circle-check alert-icon" aria-hidden="true"></i>
    <?= htmlspecialchars($flashOk) ?>
  </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
  <div class="alert alert-error flash flash-error">
    <i class="fa-solid fa-circle-exclamation alert-icon" aria-hidden="true"></i>
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
/* ══════════════════════════════════════════════════════════════
   LAYOUT-APP NAVIGATION JAVASCRIPT
   • Avatar dropdown toggle (keyboard + click + outside)
   • Mobile drawer toggle (hamburger, overlay, close btn)
   • Escape key closes whichever is open
   • Focus trap inside drawer for accessibility
   • Active-state cleanup after navigation
══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Element refs ──────────────────────────────────────────── */
  var hamburger  = document.getElementById('navHamburger');
  var overlay    = document.getElementById('appNavOverlay');
  var drawer     = document.getElementById('appNavDrawer');
  var drawerClose= document.getElementById('drawerClose');
  var avatarWrap = document.getElementById('avatarWrap');
  var avatarBtn  = document.getElementById('avatarBtn');
  var avatarDd   = document.getElementById('avatarDropdown');

  /* ── Drawer helpers ────────────────────────────────────────── */
  function openDrawer() {
    if (!drawer) return;
    drawer.classList.add('open');
    overlay.classList.add('open');
    hamburger.classList.add('open');
    hamburger.setAttribute('aria-expanded', 'true');
    hamburger.setAttribute('aria-label', 'Close navigation');
    drawer.setAttribute('aria-hidden', 'false');
    overlay.setAttribute('aria-hidden', 'false');
    /* Prevent body scroll while drawer is open */
    document.body.style.overflow = 'hidden';
    /* Move focus into drawer for accessibility */
    var firstFocusable = drawer.querySelector('a, button, [tabindex="0"]');
    if (firstFocusable) { setTimeout(function () { firstFocusable.focus(); }, 50); }
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('open');
    overlay.classList.remove('open');
    hamburger.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
    hamburger.setAttribute('aria-label', 'Open navigation');
    drawer.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    hamburger.focus();
  }

  function isDrawerOpen() {
    return drawer && drawer.classList.contains('open');
  }

  /* ── Avatar dropdown helpers ───────────────────────────────── */
  function openDropdown() {
    if (!avatarWrap) return;
    avatarWrap.classList.add('open');
    avatarBtn.setAttribute('aria-expanded', 'true');
  }

  function closeDropdown() {
    if (!avatarWrap) return;
    avatarWrap.classList.remove('open');
    avatarBtn.setAttribute('aria-expanded', 'false');
  }

  function isDropdownOpen() {
    return avatarWrap && avatarWrap.classList.contains('open');
  }

  /* ── Event listeners: Hamburger ────────────────────────────── */
  if (hamburger) {
    hamburger.addEventListener('click', function (e) {
      e.stopPropagation();
      isDrawerOpen() ? closeDrawer() : openDrawer();
    });
  }

  /* Overlay click: close drawer */
  if (overlay) {
    overlay.addEventListener('click', closeDrawer);
  }

  /* Drawer close button */
  if (drawerClose) {
    drawerClose.addEventListener('click', closeDrawer);
  }

  /* ── Event listeners: Avatar dropdown ──────────────────────── */
  if (avatarBtn) {
    avatarBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      isDropdownOpen() ? closeDropdown() : openDropdown();
    });

    /* Keyboard: Enter / Space opens dropdown */
    avatarBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        isDropdownOpen() ? closeDropdown() : openDropdown();
      }
    });
  }

  /* ── Global: click outside closes dropdown ─────────────────── */
  document.addEventListener('click', function (e) {
    if (avatarWrap && !avatarWrap.contains(e.target)) {
      closeDropdown();
    }
  });

  /* ── Global: Escape closes whichever is open ────────────────── */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (isDropdownOpen()) { closeDropdown(); avatarBtn && avatarBtn.focus(); }
      if (isDrawerOpen())   { closeDrawer(); }
    }
  });

  /* ── Dropdown: close after menu item click ──────────────────── */
  if (avatarDd) {
    avatarDd.querySelectorAll('.avatar-dd-item').forEach(function (item) {
      item.addEventListener('click', closeDropdown);
    });
  }

  /* ── Drawer: close after link click (SPA-style navigation) ──── */
  if (drawer) {
    drawer.querySelectorAll('.drawer-link, .drawer-signout').forEach(function (link) {
      link.addEventListener('click', function () {
        /* Small delay so the click registers before body overflow reset */
        setTimeout(closeDrawer, 60);
      });
    });
  }

  /* ── Focus trap inside drawer ───────────────────────────────── */
  if (drawer) {
    drawer.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var focusable = Array.from(drawer.querySelectorAll(
        'a[href], button:not([disabled]), [tabindex="0"]'
      )).filter(function (el) { return el.offsetParent !== null; });
      if (!focusable.length) return;
      var first = focusable[0];
      var last  = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
      } else {
        if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
      }
    });
  }

  /* ── Resize: close drawer if viewport grows past breakpoint ──── */
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      if (window.innerWidth > 900 && isDrawerOpen()) {
        closeDrawer();
      }
    }, 120);
  });

  /* ── Orientation change: re-check body overflow state ──────── */
  window.addEventListener('orientationchange', function () {
    setTimeout(function () {
      if (!isDrawerOpen()) {
        document.body.style.overflow = '';
      }
    }, 300);
  });

})();
</script>

</body>
</html>
