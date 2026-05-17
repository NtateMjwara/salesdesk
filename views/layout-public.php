<?php
/**
 * SalesDesk — Public Layout Shell (v2)
 * T1 owns this file.
 *
 * Shell for all buyer-facing pages. Includes:
 *   - Sticky nav with Browse dropdown + broker/auth-aware Account button
 *   - Optional breadcrumb row
 *   - Wide (1240px) or Narrow (840px) content variant
 *   - Visitor session init (anonymous cookie tracking)
 *   - Share sheet overlay (activated by openShareSheet() in public.js)
 *   - Public font (Sora) via public-fonts.css
 *   - SEO / Open Graph meta
 *
 * USAGE:
 *
 *   require_once '../../includes/visitor.php';
 *   require_once '../../includes/session.php';   // if you need $_SESSION
 *
 *   $visitor = initVisitorSession();
 *
 *   $pageTitle     = '2022 Toyota Corolla Cross | SalesDesk';
 *   $ogTitle       = '2022 Toyota Corolla Cross — R 349 900';
 *   $ogDescription = 'Listed by Sipho\'s SalesDesk · Sandton';
 *   $ogImage       = 'https://...';
 *   $canonicalUrl  = 'https://salesdesk.co.za/c/...';
 *
 *   // Optional:
 *   $layoutVariant   = 'wide';    // 'wide' (default) | 'narrow'
 *   $showBreadcrumb  = true;
 *   $breadcrumbs     = [
 *       ['Browse', '/c/'],
 *       ['Toyota', '/c/?make=Toyota'],
 *       ['2022 Corolla Cross', null],
 *   ];
 *   $shareUrl        = 'https://salesdesk.co.za/c/...?ref=...';
 *   $shareTitle      = '2022 Toyota Corolla Cross — R 349 900';
 *
 *   ob_start();
 *   // ... page HTML ...
 *   $pageContent = ob_get_clean();
 *
 *   require_once '../../views/layout-public.php';
 *
 * VARIABLES consumed:
 *   string $pageTitle       — <title>
 *   string $pageContent     — main HTML
 *   string $ogTitle         — og:title (falls back to $pageTitle)
 *   string $ogDescription   — og:description
 *   string $ogImage         — og:image URL
 *   string $canonicalUrl    — canonical URL
 *   string $layoutVariant   — 'wide' (default) | 'narrow'
 *   bool   $showBreadcrumb  — show breadcrumb row (default false)
 *   array  $breadcrumbs     — [['label', 'url'], ...] last url = null for current
 *   string $shareUrl        — URL for share sheet (default = canonicalUrl)
 *   string $shareTitle      — Title text for share sheet
 *   string $assetVersion    — cache-buster
 *   array  $visitor         — from initVisitorSession() (optional, only needed
 *                             if caller did not already call it)
 */

// ── Defaults ──────────────────────────────────────────────────
$pageTitle      = $pageTitle      ?? 'SalesDesk';
$pageContent    = $pageContent    ?? '';
$ogTitle        = $ogTitle        ?? $pageTitle;
$ogDescription  = $ogDescription  ?? 'South Africa\'s trusted car sales platform.';
$ogImage        = $ogImage        ?? '';
$canonicalUrl   = $canonicalUrl   ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                  . ($_SERVER['REQUEST_URI'] ?? ''));
$layoutVariant  = $layoutVariant  ?? 'wide';
$showBreadcrumb = $showBreadcrumb ?? false;
$breadcrumbs    = $breadcrumbs    ?? [];
$shareUrl       = $shareUrl       ?? $canonicalUrl;
$shareTitle     = $shareTitle     ?? $pageTitle;
$assetVersion   = $assetVersion   ?? date('Ymd');

// ── Visitor session (init if caller hasn't done it) ───────────
if (!isset($visitor) || empty($visitor['id'])) {
    if (!function_exists('initVisitorSession')) {
        require_once __DIR__ . '/../includes/visitor.php';
    }
    $visitor = initVisitorSession();
}

// ── Auth-aware nav state ──────────────────────────────────────
// Check if a broker/dealer is browsing the public site while logged in.
// We need session without disrupting the session already started by visitor.php.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$navLoggedIn    = !empty($_SESSION['user_id']);
$navUserRole    = $_SESSION['user_role']  ?? '';
$navFirstName   = '';
$navDashLink    = '/auth/login.php';

if ($navLoggedIn) {
    $navFirstName = $_SESSION['nav_first_name'] ?? '';
    if (!$navFirstName && !empty($_SESSION['user_id'])) {
        try {
            $pdo = \Database::getInstance();
            $pstmt = $pdo->prepare("SELECT first_name FROM profiles WHERE user_id = ? LIMIT 1");
            $pstmt->execute([(int)$_SESSION['user_id']]);
            $prow = $pstmt->fetch();
            $navFirstName = $prow['first_name'] ?? '';
            $_SESSION['nav_first_name'] = $navFirstName;
        } catch (Throwable) {}
    }
    $navDashLink = match($navUserRole) {
        'dealer'     => '/app/dealer/dashboard.php',
        'sales_exec' => '/app/exec/dashboard.php',
        'admin'      => '/app/admin/users.php',
        default      => '/app/broker/dashboard.php',
    };
}

// WhatsApp share link helper.
$waShareLink = 'https://wa.me/?text=' . urlencode($shareTitle . ' — ' . $shareUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- SEO -->
  <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:title"       content="<?= htmlspecialchars($ogTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta property="og:url"         content="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php if ($ogImage): ?>
  <meta property="og:image"       content="<?= htmlspecialchars($ogImage) ?>">
  <meta property="og:image:width"  content="1200">
  <meta property="og:image:height" content="630">
  <?php endif; ?>

  <!-- Twitter -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:title"       content="<?= htmlspecialchars($ogTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <?php if ($ogImage): ?>
  <meta name="twitter:image"       content="<?= htmlspecialchars($ogImage) ?>">
  <?php endif; ?>

  <!-- Assets: global tokens first, then public-specific -->
  <link rel="stylesheet" href="../assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="../assets/css/public-fonts.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="../assets/css/public.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- ══════════════════════════════════════════════════════
     PUBLIC NAV
     ══════════════════════════════════════════════════════ -->
<nav class="pub-nav" role="navigation" aria-label="Main navigation">
  <div class="pub-nav__inner">

    <!-- Brand -->
    <a href="/" class="pub-nav__brand">
      <div class="pub-nav__logo">
        <i class="fa-solid fa-car-side"></i>
      </div>
      <div class="pub-nav__name">Sales<span>Desk</span></div>
      <span class="pub-nav__badge">ZA</span>
    </a>

    <!-- Centre links (hidden < 1024px) -->
    <div class="pub-nav__links">

      <!-- Browse dropdown -->
      <div class="pub-nav__browse">
        <button class="pub-nav__browse-btn" id="browseBtn"
                aria-haspopup="true" aria-expanded="false">
          Browse
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <div class="pub-nav__browse-panel" id="browsePanel" role="menu">
          <div class="pub-nav__browse-panel-title">Categories</div>
          <a href="/c/?condition=new"   class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-star"></i> New Cars
          </a>
          <a href="/c/?condition=used"  class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-car"></i> Used Cars
          </a>
          <a href="/c/?condition=demo"  class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-car-side"></i> Demo Cars
          </a>
          <div class="pub-nav__browse-divider"></div>
          <div class="pub-nav__browse-panel-title">Popular Makes</div>
          <a href="/c/?make=Toyota"     class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-circle"></i> Toyota
          </a>
          <a href="/c/?make=BMW"        class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-circle"></i> BMW
          </a>
          <a href="/c/?make=Volkswagen" class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-circle"></i> Volkswagen
          </a>
          <a href="/c/?make=Ford"       class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-circle"></i> Ford
          </a>
          <div class="pub-nav__browse-divider"></div>
          <a href="/c/"                 class="pub-nav__browse-item" role="menuitem">
            <i class="fa-solid fa-grid-2"></i> View all cars
          </a>
        </div>
      </div>

      <a href="/brokers.php"  class="pub-nav__link">For Brokers</a>
      <a href="/dealers.php"  class="pub-nav__link">For Dealers</a>
    </div>

    <!-- Right actions -->
    <div class="pub-nav__actions">

      <?php if ($navLoggedIn): ?>
      <!-- Logged-in broker/dealer browsing public site -->
      <div class="pub-nav__account">
        <button class="pub-nav__account-btn" id="accountBtn"
                aria-haspopup="true" aria-expanded="false">
          <i class="fa-solid fa-user-circle"></i>
          <span class="pub-nav-label"><?= htmlspecialchars($navFirstName ?: 'My Account') ?></span>
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <div class="pub-nav__dropdown" id="accountPanel" role="menu">
          <a href="<?= htmlspecialchars($navDashLink) ?>" class="pub-nav__dropdown-item" role="menuitem">
            <i class="fa-solid fa-gauge"></i> Dashboard
          </a>
          <?php if ($navUserRole === 'broker'): ?>
          <a href="/app/broker/inventory.php" class="pub-nav__dropdown-item" role="menuitem">
            <i class="fa-solid fa-id-card"></i> My SalesDesk
          </a>
          <a href="/app/broker/leads.php" class="pub-nav__dropdown-item" role="menuitem">
            <i class="fa-solid fa-inbox"></i> My Leads
          </a>
          <?php endif; ?>
          <div class="pub-nav__dropdown-divider"></div>
          <a href="/auth/logout.php" class="pub-nav__dropdown-item danger" role="menuitem">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
          </a>
        </div>
      </div>
      <?php else: ?>
      <!-- Guest -->
      <a href="/auth/login.php" class="pub-nav__signin">Sign in</a>
      <a href="/auth/register.php" class="pub-btn pub-btn-primary" style="padding:8px 18px;font-size:13px;">
        Register
      </a>
      <?php endif; ?>

    </div>
  </div>
</nav>

<!-- ══════════════════════════════════════════════════════
     BREADCRUMB (optional)
     ══════════════════════════════════════════════════════ -->
<?php if ($showBreadcrumb && !empty($breadcrumbs)): ?>
<div class="pub-breadcrumb">
  <a href="/">Home</a>
  <?php foreach ($breadcrumbs as $crumb): ?>
  <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
  <?php if ($crumb[1]): ?>
  <a href="<?= htmlspecialchars($crumb[1]) ?>"><?= htmlspecialchars($crumb[0]) ?></a>
  <?php else: ?>
  <span class="current"><?= htmlspecialchars($crumb[0]) ?></span>
  <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════ -->
<main class="pub-page-<?= $layoutVariant === 'narrow' ? 'narrow' : 'wide' ?>"
      id="main-content">
  <?= $pageContent ?>
</main>

<!-- ══════════════════════════════════════════════════════
     FOOTER
     ══════════════════════════════════════════════════════ -->
<footer>
  <div class="pub-footer">
    <div>
      <span style="font-family:var(--font-d);font-weight:700;color:var(--text);">
        Sales<span style="color:var(--p);">Desk</span>
      </span>
      &copy; <?= date('Y') ?> SalesDesk (Pty) Ltd &middot; South Africa
    </div>
    <div style="display:flex;gap:20px;">
      <a href="/privacy">Privacy</a>
      <a href="/terms">Terms</a>
      <a href="/brokers.php">For Brokers</a>
      <a href="/dealers.php">For Dealers</a>
    </div>
  </div>
</footer>

<!-- ══════════════════════════════════════════════════════
     SHARE SHEET OVERLAY (always in DOM, shown on demand)
     ══════════════════════════════════════════════════════ -->
<div class="pub-share-overlay" id="shareOverlay" role="dialog" aria-modal="true"
     aria-label="Share this listing">
  <div class="pub-share-sheet">
    <div class="pub-share-sheet__handle"></div>
    <div class="pub-share-sheet__title">Share this listing</div>
    <p style="font-size:12px;color:var(--muted);margin-top:2px;margin-bottom:0;">
      <?= htmlspecialchars($shareTitle) ?>
    </p>

    <!-- Quick share options -->
    <div class="pub-share-options" style="margin-top:1rem;">
      <a href="<?= htmlspecialchars($waShareLink) ?>" target="_blank" rel="noopener"
         class="pub-share-option wa">
        <i class="fa-brands fa-whatsapp"></i>
        WhatsApp
      </a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
         target="_blank" rel="noopener" class="pub-share-option fb">
        <i class="fa-brands fa-facebook"></i>
        Facebook
      </a>
      <a href="https://twitter.com/intent/tweet?url=<?= urlencode($shareUrl) ?>&text=<?= urlencode($shareTitle) ?>"
         target="_blank" rel="noopener" class="pub-share-option tw">
        <i class="fa-brands fa-x-twitter"></i>
        X / Twitter
      </a>
      <button class="pub-share-option cp" onclick="copyShareUrl()" type="button">
        <i class="fa-solid fa-link" id="copyUrlBtn"></i>
        Copy link
      </button>
    </div>

    <!-- URL input -->
    <div class="pub-share-sheet__url">
      <input type="text" id="shareUrlInput"
             value="<?= htmlspecialchars($shareUrl) ?>"
             readonly aria-label="Share URL">
      <button class="pub-btn pub-btn-ghost" onclick="copyShareUrl()" type="button"
              style="padding:8px 14px;font-size:12px;white-space:nowrap;">
        <i class="fa-solid fa-copy"></i> Copy
      </button>
    </div>

    <button class="pub-btn pub-btn-ghost" onclick="closeShareSheet()" type="button"
            style="width:100%;padding:10px;font-size:13px;">
      Close
    </button>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS
     ══════════════════════════════════════════════════════ -->
<script src="/assets/js/public.js?v=<?= $assetVersion ?>"></script>

</body>
</html>
