<?php
/**
 * SalesDesk — Public Layout Shell (v7)
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
 * NEW VARIABLES in v7 (all optional):
 *   bool   $hideNavSearch        hide the inline nav search (homepage —
 *                                the hero search already owns that job)
 *   bool   $includeBrowseCss     default true; pages that render no
 *                                browse components set false
 *   bool   $includeHowItWorksCss default true; same idea
 *   string $bodyClass            extra class(es) on <body>
 *
 * v7 changes (public UX/UI overhaul):
 *   UX-1   Nav rebuilt: max-width container, inline search with
 *          typeahead, saved-cars icon with live count, avatar account
 *          button, "Sell your car" CTA, scrolled-state shadow.
 *   UX-2   Mobile: search sheet + right-hand drawer with accordion
 *          groups and a pinned account footer.
 *   UX-3   Footer rebuilt on the ink surface; placeholder "#" social
 *          links (Instagram / Facebook / WhatsApp) removed until the
 *          real profiles exist.
 *   UX-4   Toast region (#pubToasts) for wishlist / copy feedback.
 *   URL-1  Every internal page link is extensionless (no .php), per the
 *          platform-wide URL decision. .htaccess maps them back.
 *   PERF-6 Fraunces dropped from the public font request (unused on
 *          public pages); browse.css / how-it-works.css are opt-out.
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
$hideNavSearch  = $hideNavSearch  ?? false;
$includeBrowseCss     = $includeBrowseCss     ?? true;
$includeHowItWorksCss = $includeHowItWorksCss ?? true;
$bodyClass      = trim('sd-public ' . ($bodyClass ?? ''));

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
$navDashLink  = '/auth/login';

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
        'dealer'     => '/app/dealer/dashboard',
        'sales_exec' => '/app/exec/dashboard',
        'admin'      => '/app/admin/users',
        default      => '/app/broker/dashboard',
    };
}

$navInitials = $navLoggedIn
    ? strtoupper(substr($navFirstName ?: 'A', 0, 1))
    : '';

// Saved-cars count for the nav heart (visitor-scoped, no login needed).
$navWishlistCount = 0;
if (!empty($visitor['id']) && function_exists('getWishlistCarIds')) {
    try { $navWishlistCount = count(getWishlistCarIds((int) $visitor['id'])); } catch (Throwable) {}
}

// Commission figures are for the trade, not buyers (see car detail).
$navIsTrade = $navLoggedIn && in_array($navUserRole, ['broker', 'sales_exec', 'dealer', 'admin'], true);

// WhatsApp share link helper.
$waShareLink = 'https://wa.me/?text=' . urlencode($shareTitle . ' — ' . $shareUrl);

// Google Fonts (single merged request, used by both <link> and <noscript>).
$googleFontsUrl = 'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500'
                . '&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700'
                . '&family=Sora:wght@500;600;700;800&display=swap';
$fontAwesomeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
?>
<!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- SEO -->
  <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php if ($metaRobotsNoindex): ?>
  <meta name="robots" content="noindex,follow">
  <?php endif; ?>

  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/icon.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/logo.png">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:locale"      content="en_ZA">
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
  <meta name="twitter:site"        content="@salesdesk_za">
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
      "url": "<?= htmlspecialchars($siteBaseUrl) ?>/",
      "potentialAction": {
          "@type": "SearchAction",
          "target": "<?= htmlspecialchars($siteBaseUrl) ?>/cars-for-sale/?q={search_term_string}",
          "query-input": "required name=search_term_string"
      }
  }
  </script>
  <?php
  // Organization schema. sameAs lists only the live social profiles.
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
      1. global.css        tokens, reset, .sr-only, .sd-container
      2. public-shell.css  nav, drawer, search sheet, breadcrumb, footer (sole owner)
      3. public.css        shared public components (buttons, vehicle card,
                           chips, sections, share sheet, toasts, lightbox)
      4. browse.css        browse components   (opt-out: $includeBrowseCss)
      5. how-it-works.css  async                (opt-out: $includeHowItWorksCss)
      6. $extraCss         page-specific stylesheet(s)
  -->
  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/public-shell.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/public.css?v=<?= $assetVersion ?>">
  <?php if ($includeBrowseCss): ?>
  <link rel="stylesheet" href="/assets/css/browse.css?v=<?= $assetVersion ?>">
  <?php endif; ?>
  <?php if ($includeHowItWorksCss): ?>
  <link rel="stylesheet" href="/assets/css/how-it-works.css?v=<?= $assetVersion ?>"
        media="print" onload="this.media='all'">
  <noscript>
    <link rel="stylesheet" href="/assets/css/how-it-works.css?v=<?= $assetVersion ?>">
  </noscript>
  <?php endif; ?>

  <?= $extraCss ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<a class="pub-skip-link" href="#main-content">Skip to content</a>

<!-- ══════════════════════════════════════════════════════
     PUBLIC NAV   (styles: public-shell.css §2–7 · behaviour: public-nav.js)
     ══════════════════════════════════════════════════════ -->
<header class="pub-nav" id="pubNav">
  <div class="pub-nav__inner sd-container">

    <!-- Brand -->
    <a href="/" class="pub-nav__brand" aria-label="SalesDesk home">
      <img class="pub-nav__mark" src="/assets/img/icon-mark.png" alt="" width="44" height="15">
      <span class="pub-nav__name">Sales<span>Desk</span></span>
    </a>

    <!-- Primary links (hidden ≤ 1100px — drawer takes over) -->
    <nav class="pub-nav__links" aria-label="Main navigation">

      <!-- ── BUY ─────────────────────────────────────────── -->
      <div class="pub-nav__browse">
        <button class="pub-nav__trigger" id="navBuyBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navBuyPanel">
          Buy a car
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navBuyPanel">
          <div class="pub-mega-cols">

            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By condition</div>
              <a href="/cars-for-sale/?condition=new" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-star"></i></span>
                <span class="pub-mega-item-text">New cars
                  <span class="pub-mega-item-sub">Factory-fresh, full warranty</span>
                </span>
              </a>
              <a href="/cars-for-sale/?condition=used" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-car"></i></span>
                <span class="pub-mega-item-text">Pre-owned cars
                  <span class="pub-mega-item-sub">From verified dealerships</span>
                </span>
              </a>
              <a href="/cars-for-sale/?condition=demo" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side"></i></span>
                <span class="pub-mega-item-text">Demo cars
                  <span class="pub-mega-item-sub">Low mileage, big savings</span>
                </span>
              </a>
              <a href="/cars-for-sale/?fuel_type[]=Electric" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-bolt"></i></span>
                <span class="pub-mega-item-text">Electric vehicles
                  <span class="pub-mega-item-sub">EVs &amp; plug-in hybrids</span>
                </span>
              </a>
            </div>

            <div class="pub-mega-divider" aria-hidden="true"></div>

            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By body style</div>
              <a href="/cars-for-sale/?body_type[]=SUV" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-monster"></i></span>
                <span class="pub-mega-item-text">SUVs &amp; 4×4s</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Bakkie" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-pickup"></i></span>
                <span class="pub-mega-item-text">Bakkies</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Hatchback" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side"></i></span>
                <span class="pub-mega-item-text">Hatchbacks</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=Sedan" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-car"></i></span>
                <span class="pub-mega-item-text">Sedans</span>
              </a>
              <a href="/cars-for-sale/?body_type[]=MPV" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-van-shuttle"></i></span>
                <span class="pub-mega-item-text">MPVs &amp; family cars</span>
              </a>
            </div>

            <div class="pub-mega-divider" aria-hidden="true"></div>

            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By budget</div>
              <a href="/cars-for-sale/?price_max=200000" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-tag"></i></span>
                <span class="pub-mega-item-text">Under R200k</span>
              </a>
              <a href="/cars-for-sale/?price_min=200000&amp;price_max=400000" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-tag"></i></span>
                <span class="pub-mega-item-text">R200k – R400k</span>
              </a>
              <a href="/cars-for-sale/?price_min=400000&amp;price_max=700000" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-tag"></i></span>
                <span class="pub-mega-item-text">R400k – R700k</span>
              </a>
              <a href="/cars-for-sale/?price_min=700000" class="pub-mega-item pub-mega-item--compact">
                <span class="pub-mega-icon"><i class="fa-solid fa-gem"></i></span>
                <span class="pub-mega-item-text">R700k and up</span>
              </a>
            </div>

            <a href="/cars-for-sale/" class="pub-mega-promo">
              <span>
                <span class="pub-mega-promo__eyebrow">Live inventory</span>
                <span class="pub-mega-promo__title">Every car from every verified dealer, in one search.</span>
              </span>
              <span class="pub-mega-promo__link">Browse all cars <i class="fa-solid fa-arrow-right"></i></span>
            </a>

          </div>
        </div>
      </div>

      <!-- ── SELL ────────────────────────────────────────── -->
      <div class="pub-nav__browse">
        <button class="pub-nav__trigger" id="navSellBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navSellPanel">
          Sell &amp; earn
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navSellPanel">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Sell your car</div>
              <a href="/sell/private/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-user"></i></span>
                <span class="pub-mega-item-text">Sell privately
                  <span class="pub-mega-item-sub">List directly to buyers — no dealership cut</span>
                </span>
              </a>
              <a href="/sell/dealer/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-building-user"></i></span>
                <span class="pub-mega-item-text">Sell to a dealer
                  <span class="pub-mega-item-sub">Fast and hassle-free — get an offer</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider" aria-hidden="true"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Work with SalesDesk</div>
              <a href="/how-it-works/brokers" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-id-card"></i></span>
                <span class="pub-mega-item-text">Become a broker
                  <span class="pub-mega-item-sub">Earn commission — no stock needed</span>
                </span>
              </a>
              <a href="/how-it-works/sales-exec" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-user-tie"></i></span>
                <span class="pub-mega-item-text">Sales executives
                  <span class="pub-mega-item-sub">Turn your network into deals</span>
                </span>
              </a>
              <a href="/how-it-works/dealers" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-store"></i></span>
                <span class="pub-mega-item-text">List as a dealership
                  <span class="pub-mega-item-sub">Inventory, leads &amp; commission in one place</span>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- ── RESEARCH ────────────────────────────────────── -->
      <div class="pub-nav__browse">
        <button class="pub-nav__trigger" id="navNewsBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navNewsPanel">
          News &amp; reviews
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navNewsPanel">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Latest</div>
              <a href="/news/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-newspaper"></i></span>
                <span class="pub-mega-item-text">Car news
                  <span class="pub-mega-item-sub">SA &amp; international updates</span>
                </span>
              </a>
              <a href="/news/launches/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-rocket"></i></span>
                <span class="pub-mega-item-text">New launches
                  <span class="pub-mega-item-sub">What's arriving in SA showrooms</span>
                </span>
              </a>
              <a href="/news/industry/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-chart-line"></i></span>
                <span class="pub-mega-item-text">Industry &amp; market
                  <span class="pub-mega-item-sub">Sales figures, trends, analysis</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider" aria-hidden="true"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Reviews &amp; guides</div>
              <a href="/reviews/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-star-half-stroke"></i></span>
                <span class="pub-mega-item-text">Car reviews
                  <span class="pub-mega-item-sub">Expert road tests &amp; ratings</span>
                </span>
              </a>
              <a href="/guides/buying/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-book-open"></i></span>
                <span class="pub-mega-item-text">Buyer's guides
                  <span class="pub-mega-item-sub">How to choose the right car</span>
                </span>
              </a>
              <a href="/guides/ownership/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-wrench"></i></span>
                <span class="pub-mega-item-text">Ownership &amp; maintenance
                  <span class="pub-mega-item-sub">Keep your car running well</span>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- ── FINANCE & TOOLS ─────────────────────────────── -->
      <div class="pub-nav__browse">
        <button class="pub-nav__trigger" id="navToolsBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navToolsPanel">
          Finance &amp; tools
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navToolsPanel">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Finance</div>
              <a href="/tools/finance-calculator/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-calculator"></i></span>
                <span class="pub-mega-item-text">Finance calculator
                  <span class="pub-mega-item-sub">Estimate monthly repayments</span>
                </span>
              </a>
              <a href="/tools/affordability/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-wallet"></i></span>
                <span class="pub-mega-item-text">Affordability check
                  <span class="pub-mega-item-sub">How much car can you afford?</span>
                </span>
              </a>
              <a href="/tools/pre-approval/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-file-signature"></i></span>
                <span class="pub-mega-item-text">Finance pre-approval
                  <span class="pub-mega-item-sub">Know your budget before you shop</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider" aria-hidden="true"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Insurance &amp; value</div>
              <a href="/tools/insurance/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-shield-halved"></i></span>
                <span class="pub-mega-item-text">Insurance quotes
                  <span class="pub-mega-item-sub">Compare cover in minutes</span>
                </span>
              </a>
              <a href="/tools/valuation/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-magnifying-glass-dollar"></i></span>
                <span class="pub-mega-item-text">Free car valuation
                  <span class="pub-mega-item-sub">What's your car worth today?</span>
                </span>
              </a>
              <a href="/tools/compare/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-scale-balanced"></i></span>
                <span class="pub-mega-item-text">Compare cars
                  <span class="pub-mega-item-sub">Up to 3 cars side by side</span>
                </span>
              </a>
              <a href="/tools/running-costs/" class="pub-mega-item">
                <span class="pub-mega-icon"><i class="fa-solid fa-gas-pump"></i></span>
                <span class="pub-mega-item-text">Running cost estimator
                  <span class="pub-mega-item-sub">Fuel, service &amp; tyres</span>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div>

    </nav><!-- /pub-nav__links -->

    <!-- Right: search, saved, account, CTA, hamburger -->
    <div class="pub-nav__actions">

      <?php if (!$hideNavSearch): ?>
      <form class="pub-nav__search" action="/cars-for-sale/" method="get" role="search">
        <i class="fa-solid fa-magnifying-glass pub-nav__search-icon" aria-hidden="true"></i>
        <input class="pub-nav__search-input" type="search" name="q" id="navSearch"
               placeholder="Search make or model" autocomplete="off"
               aria-label="Search cars" data-typeahead-box="navSearchBox">
        <div id="navSearchBox" class="typeahead-box" role="listbox" aria-label="Search suggestions"></div>
      </form>
      <?php endif; ?>

      <button class="pub-nav__icon-btn pub-nav__search-btn" type="button"
              data-search-open aria-controls="pubSearchSheet" aria-label="Search cars">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      </button>

      <a href="/account/wishlist/" class="pub-nav__icon-btn" aria-label="Saved cars (<?= $navWishlistCount ?>)" data-wishlist-link>
        <i class="fa-regular fa-heart" aria-hidden="true"></i>
        <span class="pub-nav__count" data-wishlist-count <?= $navWishlistCount ? '' : 'hidden' ?>><?= $navWishlistCount ?></span>
      </a>

      <div class="pub-nav__acct-wrap">
        <?php if (!$navLoggedIn): ?>
        <a href="/auth/login" class="pub-nav__signin">Sign in</a>
        <?php endif; ?>

        <button class="pub-nav__acct-btn" id="navAcctBtn" type="button"
                aria-haspopup="true" aria-expanded="false" aria-controls="navAcctPanel"
                aria-label="<?= $navLoggedIn ? 'Account menu' : 'More options' ?>">
          <?php if ($navLoggedIn): ?>
          <span class="pub-nav__avatar" aria-hidden="true"><?= htmlspecialchars($navInitials) ?></span>
          <?php else: ?>
          <span class="pub-nav__avatar pub-nav__avatar--guest" aria-hidden="true"><i class="fa-regular fa-user"></i></span>
          <?php endif; ?>
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel pub-acct-panel" id="navAcctPanel">
          <?php if ($navLoggedIn): ?>
          <div class="pub-acct-head">
            <span class="pub-nav__avatar" aria-hidden="true"><?= htmlspecialchars($navInitials) ?></span>
            <span>
              <span class="pub-acct-head__title">Hi<?= $navFirstName ? ', ' . htmlspecialchars($navFirstName) : '' ?></span><br>
              <span class="pub-acct-head__sub">Signed in</span>
            </span>
          </div>
          <a href="<?= htmlspecialchars($navDashLink) ?>" class="pub-acct-item">
            <i class="fa-solid fa-gauge"></i> My dashboard
          </a>
          <div class="pub-acct-divider"></div>
          <?php endif; ?>

          <div class="pub-acct-section-label">My activity</div>
          <a href="/account/recently-viewed/" class="pub-acct-item"><i class="fa-solid fa-clock-rotate-left"></i> Recently viewed</a>
          <a href="/account/wishlist/" class="pub-acct-item"><i class="fa-regular fa-heart"></i> Saved cars</a>
          <a href="/account/saved-searches/" class="pub-acct-item"><i class="fa-regular fa-bookmark"></i> Saved searches</a>

          <div class="pub-acct-divider"></div>
          <div class="pub-acct-section-label">How it works</div>
          <a href="/how-it-works/brokers" class="pub-acct-item"><i class="fa-solid fa-id-card"></i> For brokers</a>
          <a href="/how-it-works/sales-exec" class="pub-acct-item"><i class="fa-solid fa-user-tie"></i> For sales executives</a>
          <a href="/how-it-works/dealers" class="pub-acct-item"><i class="fa-solid fa-building-user"></i> For dealers</a>

          <div class="pub-acct-divider"></div>
          <?php if ($navLoggedIn): ?>
          <a href="/auth/logout" class="pub-acct-item danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
          <?php else: ?>
          <div class="pub-acct-buttons">
            <a href="/auth/login" class="pub-btn pub-btn-ghost pub-btn-sm">Sign in</a>
            <a href="/auth/register" class="pub-btn pub-btn-primary pub-btn-sm">Join free</a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <a href="/sell/private/" class="pub-btn pub-btn-accent pub-btn-sm pub-nav__cta">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Sell your car
      </a>

      <button class="pub-nav__hamburger" id="pubNavHamburger" type="button"
              aria-label="Open navigation menu" aria-expanded="false" aria-controls="pubMobileNav">
        <span></span><span></span><span></span>
      </button>
    </div><!-- /pub-nav__actions -->

  </div><!-- /pub-nav__inner -->
</header>

<!-- ══════════════════════════════════════════════════════
     MOBILE SEARCH SHEET   (public-shell.css §8 · public-nav.js)
     ══════════════════════════════════════════════════════ -->
<div class="pub-search-sheet" id="pubSearchSheet" role="dialog" aria-modal="true" aria-label="Search cars">
  <div class="pub-search-sheet__head">
    <form class="pub-search-sheet__form" action="/cars-for-sale/" method="get" role="search">
      <i class="fa-solid fa-magnifying-glass pub-search-sheet__icon" aria-hidden="true"></i>
      <input class="pub-search-sheet__input" type="search" name="q" id="sheetSearch"
             placeholder="Make, model or keyword" autocomplete="off" enterkeyhint="search"
             aria-label="Search cars" data-typeahead-box="sheetSearchBox">
      <div id="sheetSearchBox" class="typeahead-box" role="listbox" aria-label="Search suggestions"></div>
    </form>
    <button class="pub-nav__icon-btn" type="button" data-search-close aria-label="Close search">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
  </div>
  <div class="pub-search-sheet__body">
    <p class="pub-search-sheet__label">Popular searches</p>
    <div class="pub-search-sheet__chips">
      <a class="pub-chip" href="/cars-for-sale/?q=Toyota+Hilux">Toyota Hilux</a>
      <a class="pub-chip" href="/cars-for-sale/?q=Polo">VW Polo</a>
      <a class="pub-chip" href="/cars-for-sale/?q=Ranger">Ford Ranger</a>
      <a class="pub-chip" href="/cars-for-sale/?q=Fortuner">Fortuner</a>
      <a class="pub-chip" href="/cars-for-sale/?make=BMW">BMW</a>
    </div>
    <p class="pub-search-sheet__label">Shop by</p>
    <div class="pub-search-sheet__chips">
      <a class="pub-chip" href="/cars-for-sale/?body_type[]=Bakkie"><i class="fa-solid fa-truck-pickup"></i> Bakkies</a>
      <a class="pub-chip" href="/cars-for-sale/?body_type[]=SUV"><i class="fa-solid fa-truck-monster"></i> SUVs</a>
      <a class="pub-chip" href="/cars-for-sale/?body_type[]=Hatchback"><i class="fa-solid fa-car-side"></i> Hatchbacks</a>
      <a class="pub-chip" href="/cars-for-sale/?price_max=200000"><i class="fa-solid fa-tag"></i> Under R200k</a>
      <a class="pub-chip" href="/cars-for-sale/?fuel_type[]=Electric"><i class="fa-solid fa-bolt"></i> Electric</a>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     MOBILE NAV DRAWER   (public-shell.css §9 · public-nav.js)
     ══════════════════════════════════════════════════════ -->
<div class="pub-mobile-nav" id="pubMobileNav" role="dialog" aria-modal="true" aria-label="Navigation menu">
  <div class="pub-mobile-nav__panel">

    <div class="pub-mobile-nav__head">
      <a href="/" class="pub-nav__brand" aria-label="SalesDesk home">
        <img class="pub-nav__mark" src="/assets/img/icon-mark.png" alt="" width="44" height="15">
        <span class="pub-nav__name">Sales<span>Desk</span></span>
      </a>
      <button class="pub-nav__icon-btn" type="button" data-nav-close aria-label="Close menu">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>

    <div class="pub-mobile-nav__body">
      <a href="/cars-for-sale/" class="pub-mobile-nav__primary">
        Browse all cars <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>

      <details class="pub-mobile-nav__group" open>
        <summary>Buy a car</summary>
        <div class="pub-mobile-nav__links">
          <a href="/cars-for-sale/?condition=new"        class="pub-mobile-nav__item"><i class="fa-solid fa-star"></i> New cars</a>
          <a href="/cars-for-sale/?condition=used"       class="pub-mobile-nav__item"><i class="fa-solid fa-car"></i> Pre-owned cars</a>
          <a href="/cars-for-sale/?condition=demo"       class="pub-mobile-nav__item"><i class="fa-solid fa-car-side"></i> Demo cars</a>
          <a href="/cars-for-sale/?body_type[]=Bakkie"   class="pub-mobile-nav__item"><i class="fa-solid fa-truck-pickup"></i> Bakkies</a>
          <a href="/cars-for-sale/?body_type[]=SUV"      class="pub-mobile-nav__item"><i class="fa-solid fa-truck-monster"></i> SUVs &amp; 4×4s</a>
          <a href="/cars-for-sale/?fuel_type[]=Electric" class="pub-mobile-nav__item"><i class="fa-solid fa-bolt"></i> Electric vehicles</a>
        </div>
      </details>

      <details class="pub-mobile-nav__group">
        <summary>Sell &amp; earn</summary>
        <div class="pub-mobile-nav__links">
          <a href="/sell/private/"            class="pub-mobile-nav__item"><i class="fa-solid fa-user"></i> Sell privately</a>
          <a href="/sell/dealer/"             class="pub-mobile-nav__item"><i class="fa-solid fa-building-user"></i> Sell to a dealer</a>
          <a href="/how-it-works/brokers"     class="pub-mobile-nav__item"><i class="fa-solid fa-id-card"></i> Become a broker</a>
          <a href="/how-it-works/sales-exec"  class="pub-mobile-nav__item"><i class="fa-solid fa-user-tie"></i> Sales executives</a>
          <a href="/how-it-works/dealers"     class="pub-mobile-nav__item"><i class="fa-solid fa-store"></i> List as a dealership</a>
        </div>
      </details>

      <details class="pub-mobile-nav__group">
        <summary>Finance &amp; tools</summary>
        <div class="pub-mobile-nav__links">
          <a href="/tools/finance-calculator/" class="pub-mobile-nav__item"><i class="fa-solid fa-calculator"></i> Finance calculator</a>
          <a href="/tools/affordability/"      class="pub-mobile-nav__item"><i class="fa-solid fa-wallet"></i> Affordability check</a>
          <a href="/tools/valuation/"          class="pub-mobile-nav__item"><i class="fa-solid fa-magnifying-glass-dollar"></i> Car valuation</a>
          <a href="/tools/compare/"            class="pub-mobile-nav__item"><i class="fa-solid fa-scale-balanced"></i> Compare cars</a>
          <a href="/tools/insurance/"          class="pub-mobile-nav__item"><i class="fa-solid fa-shield-halved"></i> Insurance quotes</a>
        </div>
      </details>

      <details class="pub-mobile-nav__group">
        <summary>News &amp; reviews</summary>
        <div class="pub-mobile-nav__links">
          <a href="/news/"          class="pub-mobile-nav__item"><i class="fa-solid fa-newspaper"></i> Car news</a>
          <a href="/reviews/"       class="pub-mobile-nav__item"><i class="fa-solid fa-star-half-stroke"></i> Car reviews</a>
          <a href="/guides/buying/" class="pub-mobile-nav__item"><i class="fa-solid fa-book-open"></i> Buyer's guides</a>
        </div>
      </details>

      <details class="pub-mobile-nav__group">
        <summary>My activity</summary>
        <div class="pub-mobile-nav__links">
          <?php if ($navLoggedIn): ?>
          <a href="<?= htmlspecialchars($navDashLink) ?>" class="pub-mobile-nav__item"><i class="fa-solid fa-gauge"></i> My dashboard</a>
          <?php endif; ?>
          <a href="/account/wishlist/"        class="pub-mobile-nav__item"><i class="fa-regular fa-heart"></i> Saved cars<?= $navWishlistCount ? ' (' . $navWishlistCount . ')' : '' ?></a>
          <a href="/account/recently-viewed/" class="pub-mobile-nav__item"><i class="fa-solid fa-clock-rotate-left"></i> Recently viewed</a>
          <a href="/account/saved-searches/"  class="pub-mobile-nav__item"><i class="fa-regular fa-bookmark"></i> Saved searches</a>
          <?php if ($navLoggedIn): ?>
          <a href="/auth/logout" class="pub-mobile-nav__item pub-mobile-nav__item--danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
          <?php endif; ?>
        </div>
      </details>
    </div>

    <div class="pub-mobile-nav__foot<?= $navLoggedIn ? ' pub-mobile-nav__foot--single' : '' ?>">
      <?php if ($navLoggedIn): ?>
      <a href="/sell/private/" class="pub-btn pub-btn-accent pub-btn-full"><i class="fa-solid fa-plus"></i> Sell your car</a>
      <?php else: ?>
      <a href="/auth/login" class="pub-btn pub-btn-ghost pub-btn-full">Sign in</a>
      <a href="/auth/register" class="pub-btn pub-btn-primary pub-btn-full">Join free</a>
      <?php endif; ?>
    </div>

  </div>
</div><!-- /pubMobileNav -->


<!-- ══════════════════════════════════════════════════════
     BREADCRUMB (optional)   (public-shell.css §11)
     ══════════════════════════════════════════════════════ -->
<?php if ($showBreadcrumb && !empty($breadcrumbs)): ?>
<nav class="pub-breadcrumb sd-container" aria-label="Breadcrumb">
  <ol class="pub-breadcrumb__list">
    <li><a href="/">Home</a></li>
    <?php foreach ($breadcrumbs as $crumb): ?>
    <li class="sep" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></li>
    <?php if ($crumb[1]): ?>
    <li><a href="<?= htmlspecialchars($crumb[1]) ?>"><?= htmlspecialchars($crumb[0]) ?></a></li>
    <?php else: ?>
    <li class="current" aria-current="page"><?= htmlspecialchars($crumb[0]) ?></li>
    <?php endif; ?>
    <?php endforeach; ?>
  </ol>
</nav>
<?php echo renderBreadcrumbSchema($breadcrumbs, $siteBaseUrl); ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT   (public-shell.css §12)
     ══════════════════════════════════════════════════════ -->
<main class="<?= $layoutVariant === 'narrow' ? 'pub-page-narrow' : 'pub-page-wide' ?>"
      id="main-content">
  <?= $pageContent ?>
</main>

<!-- ══════════════════════════════════════════════════════
     FOOTER   (public-shell.css §13 · footer-newsletter.js)
     ══════════════════════════════════════════════════════ -->
<footer class="sd-footer">
  <div class="sd-container">
    <div class="sd-footer__top">

      <div class="sd-footer__col sd-footer__col--brand">
        <a href="/" class="sd-footer__brand" aria-label="SalesDesk home">
          <img class="sd-footer__logo" src="/assets/img/icon-mark.png" alt="" width="44" height="15" loading="lazy">
          <span class="sd-footer__name">Sales<span>Desk</span></span>
        </a>
        <p class="sd-footer__desc">
          South Africa's commission-first car sales network. Verified dealers,
          independent brokers and buyers — connected through one transparent platform.
        </p>
        <div class="sd-footer__socials">
          <a href="https://x.com/salesdesk_za" class="sd-footer__social" aria-label="SalesDesk on X" rel="noopener" target="_blank">
            <i class="fa-brands fa-x-twitter" aria-hidden="true"></i>
          </a>
          <a href="https://www.linkedin.com/company/salesdesk-za/" class="sd-footer__social" aria-label="SalesDesk on LinkedIn" rel="noopener" target="_blank">
            <i class="fa-brands fa-linkedin-in" aria-hidden="true"></i>
          </a>
        </div>
      </div>

      <div class="sd-footer__col">
        <div class="sd-footer__col-title">Buy</div>
        <div class="sd-footer__links">
          <a href="/cars-for-sale/"                class="sd-footer__link">All cars for sale</a>
          <a href="/cars-for-sale/?condition=new"  class="sd-footer__link">New cars</a>
          <a href="/cars-for-sale/?condition=used" class="sd-footer__link">Pre-owned cars</a>
          <a href="/cars-for-sale/?condition=demo" class="sd-footer__link">Demo cars</a>
          <a href="/desks/"                        class="sd-footer__link">Find a SalesDesk</a>
          <a href="/tools/finance-calculator/"     class="sd-footer__link">Finance calculator</a>
        </div>
      </div>

      <div class="sd-footer__col">
        <div class="sd-footer__col-title">Sell &amp; earn</div>
        <div class="sd-footer__links">
          <a href="/sell/private/"            class="sd-footer__link">Sell your car</a>
          <a href="/how-it-works/brokers"     class="sd-footer__link">Brokers</a>
          <a href="/how-it-works/sales-exec"  class="sd-footer__link">Sales executives</a>
          <a href="/how-it-works/dealers"     class="sd-footer__link">Dealers</a>
          <a href="/auth/register"            class="sd-footer__link">Create your desk</a>
        </div>
      </div>

      <div class="sd-footer__col">
        <div class="sd-footer__col-title">Company</div>
        <div class="sd-footer__links">
          <a href="/about/"   class="sd-footer__link">Our story</a>
          <a href="/outreach" class="sd-footer__link">Our outreach</a>
          <a href="/careers"  class="sd-footer__link">Careers</a>
          <a href="/news/"    class="sd-footer__link">News</a>
          <a href="/contact"  class="sd-footer__link">Contact us</a>
          <a href="/help"     class="sd-footer__link">Help centre</a>
        </div>
      </div>

      <div class="sd-footer__col sd-footer__col--nl">
        <div class="sd-footer__nl">
          <p class="sd-footer__nl-label">Deals in your inbox</p>
          <p class="sd-footer__nl-sub">New listings, price drops and car news. Weekly, never spammy.</p>
          <form class="sd-footer__nl-form" id="footerNlForm" novalidate>
            <input type="email" class="sd-footer__nl-input" id="footerNlEmail"
                   name="email" required autocomplete="email"
                   placeholder="you@example.com"
                   aria-label="Email address for newsletter">
            <button class="sd-footer__nl-btn" type="submit" id="footerNlBtn">Subscribe</button>
          </form>
          <p class="sd-footer__nl-note" id="footerNlNote" aria-live="polite">Unsubscribe any time. POPIA compliant.</p>
        </div>
      </div>

    </div><!-- /sd-footer__top -->

    <div class="sd-footer__trust">
      <span class="sd-footer__badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Verified dealerships</span>
      <span class="sd-footer__badge"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Commissions platform-protected</span>
      <span class="sd-footer__badge"><i class="fa-solid fa-lock" aria-hidden="true"></i> POPIA compliant</span>
    </div>

    <div class="sd-footer__bottom">
      <span>&copy; <?= date('Y') ?> SalesDesk (Pty) Ltd &middot; South Africa &middot; A subsidiary of SAUDI Group Holdings.</span>
      <nav class="sd-footer__legal" aria-label="Legal">
        <a href="/privacy">Privacy</a>
        <a href="/terms">Terms</a>
        <a href="/popia">POPIA</a>
        <!-- CKC-2: cookie-consent-banner.php wires this id to reopen the preferences modal -->
        <a href="#" id="cookiePreferencesLink">Cookie preferences</a>
      </nav>
    </div>
  </div>
</footer>

<!-- ══════════════════════════════════════════════════════
     SHARE SHEET OVERLAY   (public.css · public.js)
     ══════════════════════════════════════════════════════ -->
<div class="pub-share-overlay" id="shareOverlay" role="dialog"
     aria-modal="true" aria-labelledby="shareSheetTitle">
  <div class="pub-share-sheet">
    <div class="pub-share-sheet__handle" aria-hidden="true"></div>
    <div class="pub-share-sheet__title" id="shareSheetTitle">Share this listing</div>
    <p class="pub-share-sheet__sub"><?= htmlspecialchars($shareTitle) ?></p>

    <div class="pub-share-options">
      <a href="<?= htmlspecialchars($waShareLink) ?>" target="_blank" rel="noopener" class="pub-share-option wa">
        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>WhatsApp
      </a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
         target="_blank" rel="noopener" class="pub-share-option fb">
        <i class="fa-brands fa-facebook-f" aria-hidden="true"></i>Facebook
      </a>
      <a href="https://twitter.com/intent/tweet?url=<?= urlencode($shareUrl) ?>&amp;text=<?= urlencode($shareTitle) ?>"
         target="_blank" rel="noopener" class="pub-share-option tw">
        <i class="fa-brands fa-x-twitter" aria-hidden="true"></i>X
      </a>
      <a href="mailto:?subject=<?= rawurlencode($shareTitle) ?>&amp;body=<?= rawurlencode($shareUrl) ?>" class="pub-share-option em">
        <i class="fa-solid fa-envelope" aria-hidden="true"></i>Email
      </a>
    </div>

    <div class="pub-share-sheet__url">
      <input type="text" id="shareUrlInput" value="<?= htmlspecialchars($shareUrl) ?>" readonly aria-label="Share URL">
      <button class="pub-btn pub-btn-primary pub-btn-sm" type="button" data-share-copy>
        <i class="fa-solid fa-copy" aria-hidden="true"></i> Copy
      </button>
    </div>

    <button class="pub-btn pub-btn-ghost pub-btn-full" type="button" data-share-close>Close</button>
  </div>
</div>

<!-- Toasts (public.js §toast) -->
<div class="pub-toasts" id="pubToasts" role="status" aria-live="polite"></div>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS — all deferred; execution order = source order
       1. global.js             CSRF fetch interceptor + helpers
       2. search-typeahead.js   shared typeahead (nav, sheet, browse)
       3. public.js             cards, wishlist, share, gallery, enquiry, finance
       4. public-nav.js         mega panels, drawer, search sheet
       5. footer-newsletter.js  needs global.js's interceptor
       6. $extraJs              page scripts
     ══════════════════════════════════════════════════════ -->
<script src="/assets/js/global.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/search-typeahead.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/public.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/public-nav.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/footer-newsletter.js?v=<?= $assetVersion ?>" defer></script>
<?php foreach ((array) $extraJs as $jsPath):
    if (!is_string($jsPath) || !str_starts_with($jsPath, '/assets/js/')) continue;
    if ($jsPath === '/assets/js/search-typeahead.js') continue;   // already loaded above ?>
<script src="<?= htmlspecialchars($jsPath) ?>?v=<?= $assetVersion ?>" defer></script>
<?php endforeach; ?>

<?php
// CKC-1: cookie consent banner. Renders identical, visitor-agnostic
// markup on every request; visibility is decided client-side, so this is
// safe on pages using applyCachePolicy('public').
require_once __DIR__ . '/partials/cookie-consent-banner.php';
?>

</body>
</html>
