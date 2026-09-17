<?php
/**
 * SalesDesk — Public Layout Shell (v6)
 * T1 owns this file.
 *
 * RULE: this file contains no <style> blocks, no inline <script> logic
 * and no style="" / onclick="" attributes. Shell styles live in
 * assets/css/public-shell.css, shell behaviour in assets/js/public-nav.js
 * and assets/js/footer-newsletter.js. The only inline <script> is JSON-LD
 * data, which is not executable code.
 *
 * VARIABLES consumed (all optional):
 *   string $pageTitle, $siteName, $ogTitle, $ogDescription, $ogImage
 *   string $canonicalUrl, bool $metaRobotsNoindex
 *   string $layoutVariant   'wide' (default) | 'narrow'
 *   bool   $showBreadcrumb, array $breadcrumbs [[label, href|null], …]
 *   string $shareUrl, $shareTitle
 *   string $assetVersion    cache-buster (default: today)
 *   string $pageContent     page HTML (from ob_get_clean())
 *   string $extraCss        page <link> tags, rendered last in <head>
 *   array  $extraJs         page script paths, e.g. ['/assets/js/browse.js'],
 *                           rendered deferred after the shell scripts
 *                           (NEW in v6 — replaces inline page <script>s)
 *
 * v6 changes (UI consolidation, Phase 1):
 *   SHELL-1  Inline critical <style> in <head> removed. It was one of
 *            four conflicting copies of the nav rules. public-shell.css
 *            is preloaded and loaded synchronously instead.
 *   SHELL-2  Inline "FOOTER STYLES" <style> at the end of <body>
 *            removed — it forced .pub-nav__inner to 100% width / 64px
 *            padding at every screen size, which is what broke the
 *            mobile nav bar. Footer styles are in public-shell.css §11.
 *   SHELL-3  Inline mega-nav / hamburger script → assets/js/public-nav.js.
 *   SHELL-4  Inline footer newsletter script → assets/js/footer-newsletter.js.
 *   SHELL-5  browse-additions.css and mobile-hero-nav-fix.css no longer
 *            loaded (both deleted; merged into their owning files).
 *   SHELL-6  Share sheet buttons use data-share-* hooks (public.js)
 *            instead of onclick="".
 *   SHELL-7  $extraJs hook added.
 *   LINK-1   Footer links made root-relative (they 404'd below the root,
 *            e.g. /cars-for-sale/auth/login.php). "Sales Executives"
 *            pointed at the non-existent how-it-works/execs.php — now
 *            /how-it-works/sales-exec.php, matching the account panel.
 *   LINK-2   Mobile drawer "Buyer's guides" pointed at /compare/ — now
 *            /guides/buying/, matching the desktop News panel.
 *
 * Carried forward: PERF-1 preconnects, PERF-3 merged async Google Fonts,
 * PERF-4 async Font Awesome, PERF-5 deferred scripts, NL-1 footer
 * newsletter, ROUTE-1 /cars-for-sale/ links, CKC-1/CKC-2 cookie consent.
 */

// ── Defaults ──────────────────────────────────────────────────
require_once __DIR__ . '/../includes/structured-data.php';

$pageTitle      = $pageTitle      ?? 'SalesDesk';
$siteName       = $siteName       ?? 'SalesDesk';
$pageContent    = $pageContent    ?? '';
$ogTitle        = $ogTitle        ?? $pageTitle;
$ogDescription  = $ogDescription  ?? 'New & Used Cars for Sale Across South Africa | SalesDesk';
$ogImage        = $ogImage        ?? '';
$canonicalUrl   = $canonicalUrl   ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? '')
                  . ($_SERVER['REQUEST_URI'] ?? ''));
$metaRobotsNoindex = $metaRobotsNoindex ?? false;
$layoutVariant  = $layoutVariant  ?? 'wide';
$showBreadcrumb = $showBreadcrumb ?? false;
$breadcrumbs    = $breadcrumbs    ?? [];
$shareUrl       = $shareUrl       ?? $canonicalUrl;
$shareTitle     = $shareTitle     ?? $pageTitle;
$assetVersion   = $assetVersion   ?? date('Ymd');
$extraCss       = $extraCss       ?? '';
$extraJs        = $extraJs        ?? [];

// Site root (scheme://host, no path) — used by the Organization and
// BreadcrumbList JSON-LD below.
$siteBaseUrl = (!empty($_SERVER['HTTPS']) ? 'https' : 'http')
             . '://' . ($_SERVER['HTTP_HOST'] ?? 'salesdesk.co.za');

// ── Visitor session (init if caller hasn't done it) ───────────
if (!isset($visitor) || empty($visitor['id'])) {
    if (!function_exists('initVisitorSession')) {
        require_once __DIR__ . '/../includes/visitor.php';
    }
    $visitor = initVisitorSession();
}

// ── Auth-aware nav state ──────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$navLoggedIn  = !empty($_SESSION['user_id']);
$navUserRole  = $_SESSION['user_role'] ?? '';
$navFirstName = '';
$navDashLink  = '/auth/login.php';

if ($navLoggedIn) {
    $navFirstName = $_SESSION['nav_first_name'] ?? '';
    if (!$navFirstName && !empty($_SESSION['user_id'])) {
        try {
            $pdo   = \Database::getInstance();
            $pstmt = $pdo->prepare("SELECT first_name FROM profiles WHERE user_id = ? LIMIT 1");
            $pstmt->execute([(int)$_SESSION['user_id']]);
            $prow         = $pstmt->fetch();
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

// Google Fonts (single merged request, used by both <link> and <noscript>).
$googleFontsUrl = 'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500'
                . '&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;1,9..144,300'
                . '&family=DM+Sans:wght@300;400;500;600'
                . '&family=Sora:wght@300;400;500;600;700;800&display=swap';
$fontAwesomeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- SEO -->
  <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php if ($metaRobotsNoindex): ?>
  <meta name="robots" content="noindex,follow">
  <?php endif; ?>

  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/logo.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/logo.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/logo.png">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:site_name"   content="<?= htmlspecialchars($siteName) ?>">
  <meta property="og:title"       content="<?= htmlspecialchars($ogTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta property="og:url"         content="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php if ($ogImage): ?>
  <meta property="og:image"        content="<?= htmlspecialchars($ogImage) ?>">
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

  <!-- Structured data (JSON-LD — data, not executable script) -->
  <script type="application/ld+json">
  {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= htmlspecialchars($siteName, ENT_QUOTES) ?>",
      "alternateName": "Sales Desk",
      "url": "<?= htmlspecialchars($canonicalUrl) ?>"
  }
  </script>
  <?php
  // Organization schema. sameAs lists only the live social profiles —
  // the Instagram/Facebook/WhatsApp footer icons are still placeholders.
  echo renderOrganizationSchema($siteBaseUrl, [
      'https://x.com/salesdesk_za',
      'https://www.linkedin.com/company/salesdesk-za/',
  ]);
  ?>

  <!-- PERF-1: open connections early -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>

  <!-- First-party shell CSS: preloaded, synchronous (nav must paint styled) -->
  <link rel="preload" as="style" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="preload" as="style" href="/assets/css/public-shell.css?v=<?= $assetVersion ?>">

  <!-- PERF-3 / PERF-4: fonts + icons load async and never block render -->
  <link rel="stylesheet" href="<?= htmlspecialchars($googleFontsUrl) ?>"
        media="print" onload="this.media='all'">
  <link rel="stylesheet" href="<?= $fontAwesomeUrl ?>"
        crossorigin="anonymous" referrerpolicy="no-referrer"
        media="print" onload="this.media='all'">
  <noscript>
    <link rel="stylesheet" href="<?= htmlspecialchars($googleFontsUrl) ?>">
    <link rel="stylesheet" href="<?= $fontAwesomeUrl ?>" crossorigin="anonymous" referrerpolicy="no-referrer">
  </noscript>

  <!--
    CSS load order (cascade follows source order):
      1. global.css        tokens, reset, .sr-only
      2. public-shell.css  nav, drawer, breadcrumb, page shells, footer (sole owner)
      3. public.css        shared public components
      4. browse.css        browse page components
      5. how-it-works.css  async; to be scoped to its own pages (Phase 5)
      6. $extraCss         page-specific stylesheet(s)
  -->
  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/public-shell.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/public.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/browse.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/how-it-works.css?v=<?= $assetVersion ?>"
        media="print" onload="this.media='all'">
  <noscript>
    <link rel="stylesheet" href="/assets/css/how-it-works.css?v=<?= $assetVersion ?>">
  </noscript>

  <?= $extraCss ?>
</head>
<body>

<a class="pub-skip-link" href="#main-content">Skip to content</a>

<!-- ══════════════════════════════════════════════════════
     PUBLIC NAV   (styles: public-shell.css §1–8 · behaviour: public-nav.js)
     ══════════════════════════════════════════════════════ -->
<nav class="pub-nav" aria-label="Main navigation">
  <div class="pub-nav__inner">

    <!-- Brand -->
    <a href="/" class="pub-nav__brand">
      <span class="pub-nav__logo">
        <img src="/assets/img/icon.png" alt="" width="30" height="30">
      </span>
      <span class="pub-nav__name">Sales<span>Desk</span></span>
      <span class="pub-nav__badge">ZA</span>
    </a>

    <!-- Centre links (hidden ≤ 1100px — hamburger takes over) -->
    <div class="pub-nav__links">

      <!-- ── BUY A CAR ────────────────────────────────── -->
      <div class="pub-nav__browse" id="navBuyWrap">
        <button class="pub-nav__trigger" id="navBuyBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navBuyPanel">
          Buy a Car
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navBuyPanel" role="menu">
          <div class="pub-mega-cols">

            <!-- Col 1: By type -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Type</div>
              <a href="/cars-for-sale/?condition=new" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-star"></i></span>
                <span class="pub-mega-item-text">New Cars
                  <span class="pub-mega-item-sub">Factory-fresh, full warranty</span>
                </span>
              </a>
              <a href="/cars-for-sale/?condition=used" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car"></i></span>
                <span class="pub-mega-item-text">Pre-Owned Cars
                  <span class="pub-mega-item-sub">Thoroughly checked listings</span>
                </span>
              </a>
              <a href="/cars-for-sale/?condition=demo" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side"></i></span>
                <span class="pub-mega-item-text">Demo Cars
                  <span class="pub-mega-item-sub">Low mileage, big savings</span>
                </span>
              </a>
              <a href="/cars-for-sale/?fuel_type[]=Electric" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-bolt"></i></span>
                <span class="pub-mega-item-text">Electric Vehicles
                  <span class="pub-mega-item-sub">EVs &amp; plug-in hybrids</span>
                </span>
              </a>
            </div>

            <div class="pub-mega-divider"></div>

            <!-- Col 2: By body style -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Body Style</div>
              <a href="/cars-for-sale/?body_type[]=SUV%2F4x4" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-monster"></i></span>
                <span class="pub-mega-item-text">SUVs &amp; 4×4s</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Sedan" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car"></i></span>
                <span class="pub-mega-item-text">Sedans</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Hatchback" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side"></i></span>
                <span class="pub-mega-item-text">Hatchbacks</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Bakkie" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-pickup"></i></span>
                <span class="pub-mega-item-text">Bakkies &amp; Trucks</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=MPV" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-van-shuttle"></i></span>
                <span class="pub-mega-item-text">MPVs &amp; Minivans</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Coupe" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-burst"></i></span>
                <span class="pub-mega-item-text">Coupes &amp; Convertibles</span>
              </a>
            </div>

            <div class="pub-mega-divider"></div>

            <!-- Col 3: By province / quick links -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Province</div>
              <a href="/cars-for-sale/?province=Gauteng" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">Gauteng</span>
              </a>
              <a href="/cars-for-sale/?province=Western+Cape" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">Western Cape</span>
              </a>
              <a href="/cars-for-sale/?province=KwaZulu-Natal" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">KwaZulu-Natal</span>
              </a>
              <a href="/cars-for-sale/?province=Eastern+Cape" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">Eastern Cape</span>
              </a>
              <div class="pub-mega-col-label">Quick Links</div>
              <a href="/cars-for-sale/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-grid-2"></i></span>
                <span class="pub-mega-item-text">Browse all cars</span>
              </a>
            </div>

          </div>
        </div>
      </div><!-- /navBuyWrap -->

      <!-- ── SELL A CAR ─────────────────────────────────── -->
      <div class="pub-nav__browse" id="navSellWrap">
        <button class="pub-nav__trigger" id="navSellBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navSellPanel">
          Sell a Car
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navSellPanel" role="menu">
          <div class="pub-mega-panel__inner">
            <div class="pub-mega-col-label">How would you like to sell?</div>
            <a href="/sell/private/" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-user"></i></span>
              <span class="pub-mega-item-text">Sell Privately
                <span class="pub-mega-item-sub">List your car directly to buyers — no dealership cut</span>
              </span>
            </a>
            <a href="/sell/dealer/" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-building-user"></i></span>
              <span class="pub-mega-item-text">Sell to a Dealer
                <span class="pub-mega-item-sub">Fast, hassle-free — get an instant offer</span>
              </span>
            </a>
            <div class="pub-mega-hdivider"></div>
            <div class="pub-mega-col-label">Are you a car broker?</div>
            <a href="/brokers.php" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-id-card"></i></span>
              <span class="pub-mega-item-text">Create your SalesDesk
                <span class="pub-mega-item-sub">Earn commission — no stock needed</span>
              </span>
            </a>
            <a href="/dealers.php" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-store"></i></span>
              <span class="pub-mega-item-text">List as a Dealership
                <span class="pub-mega-item-sub">Upload inventory, manage leads &amp; commission</span>
              </span>
            </a>
          </div>
        </div>
      </div><!-- /navSellWrap -->

      <!-- ── NEWS & REVIEWS ─────────────────────────────── -->
      <div class="pub-nav__browse" id="navNewsWrap">
        <button class="pub-nav__trigger" id="navNewsBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navNewsPanel">
          News &amp; Reviews
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navNewsPanel" role="menu">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Latest</div>
              <a href="/news/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-newspaper"></i></span>
                <span class="pub-mega-item-text">Car News
                  <span class="pub-mega-item-sub">SA &amp; international updates</span>
                </span>
              </a>
              <a href="/news/launches/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-rocket"></i></span>
                <span class="pub-mega-item-text">New Launches
                  <span class="pub-mega-item-sub">What's arriving in SA showrooms</span>
                </span>
              </a>
              <a href="/news/industry/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-chart-line"></i></span>
                <span class="pub-mega-item-text">Industry &amp; Market
                  <span class="pub-mega-item-sub">Sales figures, trends, analysis</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Reviews &amp; Guides</div>
              <a href="/reviews/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-star-half-stroke"></i></span>
                <span class="pub-mega-item-text">Car Reviews
                  <span class="pub-mega-item-sub">Expert road tests &amp; ratings</span>
                </span>
              </a>
              <a href="/compare/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-scale-balanced"></i></span>
                <span class="pub-mega-item-text">Head-to-Head Comparisons
                  <span class="pub-mega-item-sub">Compare specs side by side</span>
                </span>
              </a>
              <a href="/guides/buying/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-book-open"></i></span>
                <span class="pub-mega-item-text">Buyer's Guides
                  <span class="pub-mega-item-sub">How to choose the right car</span>
                </span>
              </a>
              <a href="/guides/ownership/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-wrench"></i></span>
                <span class="pub-mega-item-text">Ownership &amp; Maintenance
                  <span class="pub-mega-item-sub">Tips for keeping your car running</span>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div><!-- /navNewsWrap -->

      <!-- ── SERVICES & TOOLS ───────────────────────────── -->
      <div class="pub-nav__browse" id="navToolsWrap">
        <button class="pub-nav__trigger" id="navToolsBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navToolsPanel">
          Services &amp; Tools
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navToolsPanel" role="menu">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Finance</div>
              <a href="/tools/finance-calculator/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-calculator"></i></span>
                <span class="pub-mega-item-text">Finance Calculator
                  <span class="pub-mega-item-sub">Estimate monthly repayments</span>
                </span>
              </a>
              <a href="/tools/affordability/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-wallet"></i></span>
                <span class="pub-mega-item-text">Affordability Check
                  <span class="pub-mega-item-sub">How much car can you afford?</span>
                </span>
              </a>
              <a href="/tools/pre-approval/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-file-signature"></i></span>
                <span class="pub-mega-item-text">Finance Pre-Approval
                  <span class="pub-mega-item-sub">Know your budget before you shop</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Insurance &amp; Value</div>
              <a href="/tools/insurance/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-shield-halved"></i></span>
                <span class="pub-mega-item-text">Car Insurance Quotes
                  <span class="pub-mega-item-sub">Compare insurance in minutes</span>
                </span>
              </a>
              <a href="/tools/valuation/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-magnifying-glass-dollar"></i></span>
                <span class="pub-mega-item-text">Free Car Valuation
                  <span class="pub-mega-item-sub">What's your car worth today?</span>
                </span>
              </a>
              <a href="/tools/compare/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-scale-balanced"></i></span>
                <span class="pub-mega-item-text">Car Comparison Tool
                  <span class="pub-mega-item-sub">Compare up to 3 cars at once</span>
                </span>
              </a>
              <a href="/tools/running-costs/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-gas-pump"></i></span>
                <span class="pub-mega-item-text">Running Cost Estimator
                  <span class="pub-mega-item-sub">Fuel, service &amp; tyres</span>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div><!-- /navToolsWrap -->

    </div><!-- /pub-nav__links -->

    <!-- Right: account + hamburger -->
    <div class="pub-nav__actions">
      <div class="pub-nav__acct-wrap" id="navAcctWrap">

        <?php if (!$navLoggedIn): ?>
        <a href="/auth/login.php" class="pub-nav__signin pub-nav__signin--guest">Sign in</a>
        <?php endif; ?>

        <button class="pub-nav__acct-btn" id="navAcctBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navAcctPanel"
                aria-label="<?= $navLoggedIn ? 'Account menu' : 'My account' ?>">
          <i class="fa-solid fa-user-circle" aria-hidden="true"></i>
          <span class="pub-nav-label"><?= htmlspecialchars($navLoggedIn ? ($navFirstName ?: 'My Account') : 'My Account') ?></span>
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <!-- Account dropdown panel -->
        <div class="pub-mega-panel pub-acct-panel" id="navAcctPanel" role="menu">

          <?php if ($navLoggedIn): ?>
          <a href="<?= htmlspecialchars($navDashLink) ?>" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-gauge"></i> My dashboard
          </a>
          <div class="pub-acct-divider"></div>
          <?php endif; ?>

          <div class="pub-acct-section-label">My Activity</div>
          <a href="/account/recently-viewed/" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-clock-rotate-left"></i> Recently Viewed
          </a>
          <a href="/account/wishlist/" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-heart"></i> Wishlist
          </a>
          <a href="/account/saved-searches/" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-magnifying-glass"></i> Saved Searches
          </a>

          <div class="pub-acct-divider"></div>
          <div class="pub-acct-section-label">How it works</div>
          <a href="/how-it-works/brokers.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-id-card"></i> For Brokers
          </a>
          <a href="/how-it-works/sales-exec.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-user-tie"></i> For Sales Executives
          </a>
          <a href="/how-it-works/dealers.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-building-user"></i> For Dealers
          </a>

          <div class="pub-acct-divider"></div>
          <?php if ($navLoggedIn): ?>
          <a href="/auth/logout.php" class="pub-acct-item danger" role="menuitem">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
          </a>
          <?php else: ?>
          <a href="/auth/login.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-right-to-bracket"></i> Sign in
          </a>
          <a href="/auth/register.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-user-plus"></i> Create an account
          </a>
          <?php endif; ?>

        </div><!-- /navAcctPanel -->
      </div><!-- /navAcctWrap -->

      <!-- Hamburger — visible ≤ 1100px -->
      <button class="pub-nav__hamburger" id="pubNavHamburger" type="button"
              aria-label="Open navigation menu"
              aria-expanded="false"
              aria-controls="pubMobileNav">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div><!-- /pub-nav__actions -->

  </div><!-- /pub-nav__inner -->
</nav>

<!-- ══════════════════════════════════════════════════════
     MOBILE NAV DRAWER   (public-shell.css §7 · public-nav.js)
     ══════════════════════════════════════════════════════ -->
<div class="pub-mobile-nav" id="pubMobileNav"
     role="dialog" aria-modal="true" aria-label="Navigation menu">
  <div class="pub-mobile-nav__inner">

    <div class="pub-mobile-nav__section">Browse</div>
    <a href="/cars-for-sale/"                      class="pub-mobile-nav__item"><i class="fa-solid fa-car"></i> Browse all cars</a>
    <a href="/cars-for-sale/?condition=new"        class="pub-mobile-nav__item"><i class="fa-solid fa-star"></i> New cars</a>
    <a href="/cars-for-sale/?condition=used"       class="pub-mobile-nav__item"><i class="fa-solid fa-car-side"></i> Pre-owned cars</a>
    <a href="/cars-for-sale/?condition=demo"       class="pub-mobile-nav__item"><i class="fa-solid fa-car-burst"></i> Demo cars</a>
    <a href="/cars-for-sale/?fuel_type[]=Electric" class="pub-mobile-nav__item"><i class="fa-solid fa-bolt"></i> Electric vehicles</a>

    <div class="pub-mobile-nav__section">Sell</div>
    <a href="/sell/private/" class="pub-mobile-nav__item"><i class="fa-solid fa-user"></i> Sell privately</a>
    <a href="/sell/dealer/"  class="pub-mobile-nav__item"><i class="fa-solid fa-building-user"></i> Sell to a dealer</a>
    <a href="/brokers.php"   class="pub-mobile-nav__item"><i class="fa-solid fa-id-card"></i> Create your SalesDesk</a>
    <a href="/dealers.php"   class="pub-mobile-nav__item"><i class="fa-solid fa-store"></i> List as a dealership</a>

    <div class="pub-mobile-nav__section">Tools</div>
    <a href="/tools/finance-calculator/" class="pub-mobile-nav__item"><i class="fa-solid fa-calculator"></i> Finance calculator</a>
    <a href="/tools/valuation/"          class="pub-mobile-nav__item"><i class="fa-solid fa-magnifying-glass-dollar"></i> Car valuation</a>
    <a href="/compare/"                  class="pub-mobile-nav__item"><i class="fa-solid fa-scale-balanced"></i> Compare cars</a>
    <a href="/tools/insurance/"          class="pub-mobile-nav__item"><i class="fa-solid fa-shield-halved"></i> Insurance quotes</a>

    <div class="pub-mobile-nav__section">News</div>
    <a href="/news/"          class="pub-mobile-nav__item"><i class="fa-solid fa-newspaper"></i> Car news</a>
    <a href="/reviews/"       class="pub-mobile-nav__item"><i class="fa-solid fa-star-half-stroke"></i> Car reviews</a>
    <a href="/guides/buying/" class="pub-mobile-nav__item"><i class="fa-solid fa-book-open"></i> Buyer's guides</a>

    <div class="pub-mobile-nav__section">Account</div>
    <?php if ($navLoggedIn): ?>
    <a href="<?= htmlspecialchars($navDashLink) ?>" class="pub-mobile-nav__item">
      <i class="fa-solid fa-gauge"></i> My dashboard
    </a>
    <a href="/account/recently-viewed/" class="pub-mobile-nav__item"><i class="fa-solid fa-clock-rotate-left"></i> Recently viewed</a>
    <a href="/account/wishlist/"        class="pub-mobile-nav__item"><i class="fa-solid fa-heart"></i> Wishlist</a>
    <a href="/account/saved-searches/"  class="pub-mobile-nav__item"><i class="fa-solid fa-magnifying-glass"></i> Saved searches</a>
    <a href="/auth/logout.php"          class="pub-mobile-nav__item pub-mobile-nav__item--danger">
      <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
    </a>
    <?php else: ?>
    <a href="/auth/login.php"    class="pub-mobile-nav__item"><i class="fa-solid fa-right-to-bracket"></i> Sign in</a>
    <a href="/auth/register.php" class="pub-mobile-nav__item"><i class="fa-solid fa-user-plus"></i> Create an account</a>
    <?php endif; ?>

  </div>
</div><!-- /pubMobileNav -->


<!-- ══════════════════════════════════════════════════════
     BREADCRUMB (optional)   (public-shell.css §9)
     ══════════════════════════════════════════════════════ -->
<?php if ($showBreadcrumb && !empty($breadcrumbs)): ?>
<nav class="pub-breadcrumb" aria-label="Breadcrumb">
  <a href="/">Home</a>
  <?php foreach ($breadcrumbs as $crumb): ?>
  <span class="sep" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
  <?php if ($crumb[1]): ?>
  <a href="<?= htmlspecialchars($crumb[1]) ?>"><?= htmlspecialchars($crumb[0]) ?></a>
  <?php else: ?>
  <span class="current" aria-current="page"><?= htmlspecialchars($crumb[0]) ?></span>
  <?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php echo renderBreadcrumbSchema($breadcrumbs, $siteBaseUrl); ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT   (public-shell.css §10)
     ══════════════════════════════════════════════════════ -->
<main class="<?= $layoutVariant === 'narrow' ? 'pub-page-narrow' : 'pub-page-wide' ?>"
      id="main-content">
  <?= $pageContent ?>
</main>

<!-- ══════════════════════════════════════════════════════
     FOOTER   (public-shell.css §11 · footer-newsletter.js)
     ══════════════════════════════════════════════════════ -->
<footer class="sd-footer">
  <div class="sd-footer__grid">

    <!-- Col 1: Brand + Newsletter + Socials -->
    <div class="sd-footer__col sd-footer__col--brand">
      <div class="sd-footer__brand">
        <div class="sd-footer__logo"><img src="/assets/img/logo.png" alt="" width="32" height="32"></div>
        <span class="sd-footer__name">Sales<span>Desk</span></span>
      </div>
      <p class="sd-footer__desc">
        South Africa's independent car sales platform.
        Commission-protected leads. Verified dealers.
        POPIA compliant.
      </p>

      <p class="sd-footer__nl-label">Get car news &amp; deal alerts</p>
      <form class="sd-footer__nl-form" id="footerNlForm" novalidate>
        <input type="email" class="sd-footer__nl-input" id="footerNlEmail"
               name="email" required
               placeholder="Your email address"
               aria-label="Email address for newsletter">
        <button class="sd-footer__nl-btn" type="submit" id="footerNlBtn">Subscribe</button>
      </form>
      <p class="sd-footer__nl-note" id="footerNlNote" aria-live="polite">No spam &mdash; unsubscribe any time. POPIA compliant.</p>

      <div class="sd-footer__socials">
        <a href="https://x.com/salesdesk_za" class="sd-footer__social" aria-label="SalesDesk on X" rel="noopener" target="_blank">
          <i class="fa-brands fa-x-twitter"></i>
        </a>
        <a href="#" class="sd-footer__social" aria-label="SalesDesk on Instagram">
          <i class="fa-brands fa-instagram"></i>
        </a>
        <a href="https://www.linkedin.com/company/salesdesk-za/" class="sd-footer__social" aria-label="SalesDesk on LinkedIn" rel="noopener" target="_blank">
          <i class="fa-brands fa-linkedin"></i>
        </a>
        <a href="#" class="sd-footer__social" aria-label="SalesDesk on Facebook">
          <i class="fa-brands fa-facebook"></i>
        </a>
        <a href="#" class="sd-footer__social" aria-label="SalesDesk on WhatsApp">
          <i class="fa-brands fa-whatsapp"></i>
        </a>
      </div>
    </div>

    <!-- Col 2: Platform -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">Platform</div>
      <div class="sd-footer__links">
        <a href="/cars-for-sale/"                class="sd-footer__link">Browse vehicles</a>
        <a href="/cars-for-sale/?condition=new"  class="sd-footer__link">New cars</a>
        <a href="/cars-for-sale/?condition=used" class="sd-footer__link">Pre-owned cars</a>
        <a href="/cars-for-sale/?condition=demo" class="sd-footer__link">Demo cars</a>
        <a href="/desks/"                        class="sd-footer__link">Find a SalesDesk</a>
        <a href="/auth/register.php"             class="sd-footer__link">Create your Desk</a>
        <a href="/auth/login.php"                class="sd-footer__link">List a vehicle</a>
      </div>
    </div>

    <!-- Col 3: About -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">About us</div>
      <div class="sd-footer__links">
        <a href="/how-it-works/brokers.php"    class="sd-footer__link">Brokers</a>
        <a href="/how-it-works/sales-exec.php" class="sd-footer__link">Sales Executives</a>
        <a href="/how-it-works/dealers.php"    class="sd-footer__link">Dealers</a>
        <a href="/outreach"                    class="sd-footer__link">Our Outreach</a>
        <a href="/careers"                     class="sd-footer__link">Careers</a>
        <a href="/about/"                      class="sd-footer__link">Our Story</a>
      </div>
    </div>

    <!-- Col 4: Legal -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">Legal &amp; Help</div>
      <div class="sd-footer__links">
        <a href="/privacy" class="sd-footer__link">Privacy Policy</a>
        <a href="/terms"   class="sd-footer__link">Terms of Service</a>
        <a href="/popia"   class="sd-footer__link">POPIA Compliance</a>
        <a href="/contact" class="sd-footer__link">Contact us</a>
        <a href="/help"    class="sd-footer__link">Help centre</a>
        <!-- CKC-2: cookie-consent-banner.php wires this id to reopen the preferences modal -->
        <a href="#" id="cookiePreferencesLink" class="sd-footer__link">Cookie preferences</a>
      </div>
    </div>

  </div><!-- /sd-footer__grid -->

  <div class="sd-footer__bottom">
    <span>
      &copy; <?= date('Y') ?> SalesDesk (Pty) Ltd &middot; South Africa &middot; A Subsidiary of SAUDI Group Holdings.
    </span>
    <div class="sd-footer__badges">
      <span class="sd-footer__badge">
        <i class="fa-solid fa-shield-halved"></i>
        Commissions platform-protected
      </span>
      <span class="sd-footer__badge">
        <i class="fa-solid fa-lock"></i>
        POPIA Compliant
      </span>
    </div>
  </div>
</footer>

<!-- ══════════════════════════════════════════════════════
     SHARE SHEET OVERLAY   (public.css §15 · public.js §2)
     ══════════════════════════════════════════════════════ -->
<div class="pub-share-overlay" id="shareOverlay" role="dialog"
     aria-modal="true" aria-labelledby="shareSheetTitle">
  <div class="pub-share-sheet">
    <div class="pub-share-sheet__handle" aria-hidden="true"></div>
    <div class="pub-share-sheet__title" id="shareSheetTitle">Share this listing</div>
    <p class="pub-share-sheet__sub"><?= htmlspecialchars($shareTitle) ?></p>

    <div class="pub-share-options">
      <a href="<?= htmlspecialchars($waShareLink) ?>" target="_blank" rel="noopener"
         class="pub-share-option wa">
        <i class="fa-brands fa-whatsapp"></i>WhatsApp
      </a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
         target="_blank" rel="noopener" class="pub-share-option fb">
        <i class="fa-brands fa-facebook"></i>Facebook
      </a>
      <a href="https://twitter.com/intent/tweet?url=<?= urlencode($shareUrl) ?>&amp;text=<?= urlencode($shareTitle) ?>"
         target="_blank" rel="noopener" class="pub-share-option tw">
        <i class="fa-brands fa-x-twitter"></i>X / Twitter
      </a>
      <button class="pub-share-option cp" type="button" data-share-copy>
        <i class="fa-solid fa-link"></i>Copy link
      </button>
    </div>

    <div class="pub-share-sheet__url">
      <input type="text" id="shareUrlInput"
             value="<?= htmlspecialchars($shareUrl) ?>"
             readonly aria-label="Share URL">
      <button class="pub-btn pub-btn-ghost" type="button" data-share-copy>
        <i class="fa-solid fa-copy"></i> Copy
      </button>
    </div>

    <button class="pub-btn pub-btn-ghost pub-btn-full" type="button" data-share-close>
      Close
    </button>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS — all deferred; execution order = source order
       1. global.js             CSRF fetch interceptor + helpers
       2. public.js             gallery, share, wishlist, enquiry, reveal
       3. public-nav.js         mega panels + mobile drawer
       4. footer-newsletter.js  needs global.js's interceptor
       5. $extraJs              page scripts
     ══════════════════════════════════════════════════════ -->
<script src="/assets/js/global.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/public.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/public-nav.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/footer-newsletter.js?v=<?= $assetVersion ?>" defer></script>
<?php foreach ((array) $extraJs as $jsPath):
    if (!is_string($jsPath) || !str_starts_with($jsPath, '/assets/js/')) continue; ?>
<script src="<?= htmlspecialchars($jsPath) ?>?v=<?= $assetVersion ?>" defer></script>
<?php endforeach; ?>

<?php
// CKC-1: cookie consent banner. Renders identical, visitor-agnostic
// markup on every request; visibility is decided client-side, so this is
// safe on pages using applyCachePolicy('public'). Its inline script is
// scheduled for extraction in the Phase 5 sweep.
require_once __DIR__ . '/partials/cookie-consent-banner.php';
?>

</body>
</html>
