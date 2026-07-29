<?php
/**
 * SalesDesk — Public Layout Shell (v5.4)
 * T1 owns this file.
 *
 * Changes in this pass:
 *   CKC-1  Wired in the cookie consent banner
 *          (views/partials/cookie-consent-banner.php), included right
 *          before </body> alongside the other footer scripts. Per
 *          that partial's own header comment, it renders identical,
 *          visitor-agnostic markup for every request — whether the
 *          banner actually displays is decided client-side by
 *          cookie-consent.js reading document.cookie at runtime, so
 *          this is safe to include on pages using
 *          applyCachePolicy('public') without breaking CDN/proxy
 *          caching (see COOKIE-CONSENT-GUIDE.md §5).
 *   CKC-2  Added a "Cookie preferences" link to the footer's Legal &
 *          Help column, id="cookiePreferencesLink". The banner's own
 *          inline script auto-detects this id and wires it up with no
 *          further JS needed here — clicking it reopens the
 *          preferences modal so a visitor can change or withdraw
 *          consent at any time (POPIA/GDPR requirement).
 *
 * Performance changes from v5.2 (this pass):
 *   PERF-6  browse-additions.css, mobile-hero-nav-fix.css, and
 *           how-it-works.css now load asynchronously via the same
 *           media="print" / onload="this.media='all'" trick already
 *           used for Google Fonts and Font Awesome (PERF-3/PERF-4).
 *           These three were previously plain blocking <link> tags
 *           loaded on *every* public page — including the homepage,
 *           which doesn't touch the mega-nav internals in
 *           browse-additions.css or the how-it-works page styles at
 *           all. global.css and public.css (the two files every page
 *           actually needs to paint its nav shell) remain synchronous
 *           <link> tags, matching PERF-1's preload treatment. browse.css
 *           stays synchronous too since the mega-nav panels in this
 *           very file depend on it for correct (non-flashing) initial
 *           layout. <noscript> fallbacks included so the styles still
 *           apply with JS disabled.
 *
 * Performance changes from v5.1 (carried forward):
 *   PERF-1  Added <link rel="preconnect"> for Google Fonts, gstatic, cdnjs.
 *           Browser opens TCP connections before the parser reaches those
 *           resources, eliminating the DNS + handshake latency on first load.
 *   PERF-2  Inline critical CSS for the nav shell injected directly into
 *           <head> so the page can paint without waiting for any network
 *           round-trip. Also sets --font-d fallback so layout does not
 *           shift while Sora loads.
 *   PERF-3  Google Fonts and Sora merged into one request (was two: global.css
 *           importing DM Sans/Mono/Fraunces, and public-fonts.css importing
 *           Sora). Both now load asynchronously via the media="print" trick —
 *           fonts never block rendering; FOUT is handled by font-display:swap
 *           in the Google Fonts URL. public-fonts.css is removed from the
 *           load order entirely.
 *   PERF-4  Font Awesome loads asynchronously (same media="print" pattern).
 *           Icons are invisible for ~100ms on a cold load; this is invisible
 *           on repeat loads because the stylesheet is cached for 1 year.
 *   PERF-5  global.js and public.js now carry defer. Browser downloads both
 *           in parallel during HTML parsing; execution happens in source order
 *           just before DOMContentLoaded — no render blocking.
 *
 * Changes from v5.1 (carried forward):
 *   MOB-1  Removed inline .pub-nav__inner override that set max-width:100%.
 *   MOB-2  Removed inline .pub-page-wide override that set padding:0.
 *   MOB-3  Removed inline .pub-breadcrumb override.
 *
 * Changes from v4 (carried forward from v5):
 *   FIX-3  <main> class corrected: pub-main-wide → pub-page-wide.
 *   FIX-8  Mobile nav drawer rendered + hamburger button added.
 *   - Footer replaced with full 4-column dark footer.
 *
 * Changes carried forward from v5.3:
 *   NL-1   Footer newsletter form (.sd-footer__nl-form) is now a real
 *          <form id="footerNlForm"> with named/id'd fields, wired via
 *          fetch() to /api/newsletter/subscribe.php (source=footer).
 *          Mirrors the AJAX pattern already used on
 *          newsletter/newsletter-confirmed.php and
 *          newsletter/newsletter-unsubscribe.php. CSRF header is
 *          auto-injected by global.js's fetch() interceptor — no
 *          manual token handling needed here.
 *   ROUTE-1 Route rename: every /cars-for-sale/ browse/detail link in the nav,
 *          mobile drawer, and footer now points at /cars-for-sale/
 *          instead of /cars-for-sale/. The physical route moved from c/ to
 *          cars-for-sale/ — see .htaccess and cars-for-sale/index.php.
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

// Site root (scheme://host, no path) — used by the Organization and
// BreadcrumbList JSON-LD below. Built the same way $canonicalUrl's
// own fallback already is, rather than assuming a SITE_URL constant
// exists (this file doesn't reference one anywhere else).
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

  <!-- Structured Data -->
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
  // Organization schema — rendered on every page (harmless duplication
  // for this schema type; Google associates it with the domain either
  // way). sameAs only lists the two footer social links that are
  // actually live (x.com/salesdesk_za, linkedin.com/company/salesdesk-za)
  // — the Instagram/Facebook footer icons are still "#" placeholders,
  // so they're deliberately left out until those exist.
  echo renderOrganizationSchema($siteBaseUrl, [
      'https://x.com/salesdesk_za',
      'https://www.linkedin.com/company/salesdesk-za/',
  ]);
  ?>

  <!--
    PERF-1: Preconnect hints — browser opens TCP/TLS connections to these
    origins immediately, before it encounters any resource from them.
    Eliminates the DNS + handshake latency on first cold load.
  -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>

  <!--
    PERF-1 cont.: Preload the two largest first-party CSS files so the
    browser starts downloading them in parallel before it finishes parsing
    the rest of the <head>.
  -->
  <link rel="preload" as="style" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="preload" as="style" href="/assets/css/public.css?v=<?= $assetVersion ?>">

  <!--
    PERF-2: Inline critical CSS — the minimum rules needed to render the
    nav shell and page skeleton without any network round-trip.
    Keeps the visual structure stable during the 100–300ms window before
    the full CSS files arrive.

    Rules included here:
      - Box model reset
      - Body font stack (system-ui fallback until DM Sans loads)
      - --font-d fallback (was in public-fonts.css; now inlined so layout
        doesn't shift when Sora is still loading)
      - .pub-nav shell (sticky bar, height, background)
      - .pub-page-wide shell (prevents content jumping into wrong width)
      - .pub-nav__inner (flex layout)

    Rules NOT included: anything component-specific. Those live in the
    CSS files and are acceptable to load asynchronously.
  -->
  <style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--font-d:'Sora',system-ui,sans-serif}
  html{font-size:15px;background:#f3f4f8;color:#1e293b;scroll-behavior:smooth;-webkit-text-size-adjust:100%}
  body{font-family:'DM Sans',system-ui,sans-serif;line-height:1.7;min-height:100vh;-webkit-font-smoothing:antialiased}
  img,svg,video{max-width:100%;display:block}
  a{color:#0f4c9e;text-decoration:none}
  .pub-nav{position:sticky;top:0;z-index:200;height:60px;background:rgba(255,255,255,.97);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06)}
  .pub-nav__inner{max-width:1400px;margin:0 auto;padding:0 10px;height:100%;display:flex;align-items:center;gap:20px}
  .pub-page-wide{max-width:100%;margin:0 auto;padding:0 0 64px}
  .pub-page-narrow{max-width:1240px;margin:0 auto;padding:28px 24px 64px}
  </style>

  <!--
    PERF-3: Google Fonts — Sora merged in here (was separate public-fonts.css).
    Single network request instead of two. Loads asynchronously via the
    media="print" trick: browser downloads the stylesheet but does not block
    rendering; onload switches media to "all" so the fonts apply.
    font-display=swap is included in the Google Fonts URL — text renders
    immediately in the system fallback, swaps to the web font when ready.
  -->
  <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;1,9..144,300&family=DM+Sans:wght@300;400;500;600&family=Sora:wght@300;400;500;600;700;800&display=swap"
        media="print"
        onload="this.media='all'">
  <noscript>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;1,9..144,300&family=DM+Sans:wght@300;400;500;600&family=Sora:wght@300;400;500;600;700;800&display=swap">
  </noscript>

  <!--
    PERF-4: Font Awesome — async. Icons are invisible for ~100ms on a cold
    first load; this is imperceptible on repeat visits because the file is
    cached for 1 year via the .htaccess Cache-Control header.
    On first load the nav text and layout are already visible from the
    critical CSS above, so invisible icons are a minor, acceptable trade-off
    versus the 200ms render-block they previously caused.
  -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer"
        media="print"
        onload="this.media='all'">
  <noscript>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
          crossorigin="anonymous" referrerpolicy="no-referrer">
  </noscript>

  <!--
    CSS load order (T1 rule — no team may alter this sequence):
      1. global.css            — :root vars, reset                  (sync — every page needs this to paint nav shell)
      2. public.css             — public page components             (sync — same reason)
      3. browse.css             — mega-nav panels + browse-page styles (sync — this file's own mega-nav markup
                                    depends on it for correct initial layout; async-loading it would cause a
                                    visible mega-nav flash-of-unstyled-panel on every page)
      4. browse-additions.css   — utility classes + responsive additions (ASYNC — PERF-6)
      5. mobile-hero-nav-fix.css — mobile nav + hero compact layout (v5.1)  (ASYNC — PERF-6)
      6. how-it-works.css       — loaded always (low cost; avoids per-page logic) (ASYNC — PERF-6)
      7. extraCss               — caller-injected page-specific sheet (if any)

    PERF-6 (this pass): #4-6 above were plain blocking <link> tags loaded
    on every public page regardless of whether that page's markup used
    any of their classes — e.g. the homepage doesn't touch the mega-nav
    internals in browse-additions.css or use any how-it-works.css class
    at all, yet was blocking first paint on both. Switched to the same
    media="print" / onload="this.media='all'" async pattern already used
    above for Google Fonts and Font Awesome, with <noscript> fallbacks.
    global.css, public.css, and browse.css remain synchronous since the
    nav shell and mega-nav panels rendered directly in this file depend
    on them to avoid a visible flash of unstyled content.

    Note: public-fonts.css removed in v5.2. Sora is now included in the
    merged Google Fonts request above; --font-d is set in the critical CSS
    inline block. global.css already imports DM Sans/Mono/Fraunces.
  -->
  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/public.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/browse.css?v=<?= $assetVersion ?>">

  <link rel="stylesheet"
        href="/assets/css/browse-additions.css?v=<?= $assetVersion ?>"
        media="print"
        onload="this.media='all'">
  <noscript>
    <link rel="stylesheet" href="/assets/css/browse-additions.css?v=<?= $assetVersion ?>">
  </noscript>

  <link rel="stylesheet"
        href="/assets/css/mobile-hero-nav-fix.css?v=<?= $assetVersion ?>"
        media="print"
        onload="this.media='all'">
  <noscript>
    <link rel="stylesheet" href="/assets/css/mobile-hero-nav-fix.css?v=<?= $assetVersion ?>">
  </noscript>

  <link rel="stylesheet"
        href="/assets/css/how-it-works.css?v=<?= $assetVersion ?>"
        media="print"
        onload="this.media='all'">
  <noscript>
    <link rel="stylesheet" href="/assets/css/how-it-works.css?v=<?= $assetVersion ?>">
  </noscript>

  <?php if (!empty($extraCss)) echo $extraCss; ?>
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
        <img src="/assets/img/icon.png" alt="SalesDesk logo">
      </div>
      <div class="pub-nav__name">Sales<span>Desk</span></div>
      <span class="pub-nav__badge">ZA</span>
    </a>

    <!-- Centre links (hidden ≤ 1024px — hamburger takes over) -->
    <div class="pub-nav__links">

      <!-- ── BUY A CAR ────────────────────────────────── -->
      <div class="pub-nav__browse" id="navBuyWrap">
        <button class="pub-nav__trigger" id="navBuyBtn"
                aria-haspopup="true" aria-expanded="false">
          Buy a Car
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navBuyPanel" role="menu">
          <div class="pub-mega-cols">

            <!-- Col 1: Browse by condition / type -->
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

            <!-- Col 2: Browse by body style -->
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

            <!-- Col 3: Browse by province / quick links -->
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
        <button class="pub-nav__trigger" id="navSellBtn"
                aria-haspopup="true" aria-expanded="false">
          Sell a Car
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
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
        <button class="pub-nav__trigger" id="navNewsBtn"
                aria-haspopup="true" aria-expanded="false">
          News &amp; Reviews
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
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
        <button class="pub-nav__trigger" id="navToolsBtn"
                aria-haspopup="true" aria-expanded="false">
          Services &amp; Tools
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
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

    <!-- Right: My Account + Hamburger -->
    <div class="pub-nav__actions">
      <div class="pub-nav__acct-wrap" id="navAcctWrap">

        <?php if ($navLoggedIn): ?>
        <button class="pub-nav__acct-btn" id="navAcctBtn"
                aria-haspopup="true" aria-expanded="false">
          <i class="fa-solid fa-user-circle"></i>
          <span class="pub-nav-label"><?= htmlspecialchars($navFirstName ?: 'My Account') ?></span>
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <?php else: ?>
        <a href="/auth/login.php" class="pub-nav__signin pub-nav__signin--guest">Sign in</a>
        <button class="pub-nav__acct-btn" id="navAcctBtn"
                aria-haspopup="true" aria-expanded="false">
          <i class="fa-solid fa-user-circle"></i>
          <span class="pub-nav-label">My Account</span>
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <?php endif; ?>

        <!-- Account dropdown panel -->
        <div class="pub-mega-panel pub-acct-panel" id="navAcctPanel" role="menu">

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

          <?php if ($navLoggedIn): ?>
          <div class="pub-acct-divider"></div>
          <a href="/auth/logout.php" class="pub-acct-item danger" role="menuitem">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
          </a>
          <?php else: ?>
          <div class="pub-acct-divider"></div>
          <a href="/auth/login.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-right-to-bracket"></i> Sign in
          </a>
          <a href="/auth/register.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-user-plus"></i> Create an account
          </a>
          <?php endif; ?>

        </div><!-- /navAcctPanel -->
      </div><!-- /navAcctWrap -->

      <!-- Hamburger — visible ≤ 1024px, sits right of account button -->
      <button class="pub-nav__hamburger" id="pubNavHamburger"
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
     FIX-8: MOBILE NAV DRAWER
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
    <a href="/sell/private/"  class="pub-mobile-nav__item"><i class="fa-solid fa-user"></i> Sell privately</a>
    <a href="/sell/dealer/"   class="pub-mobile-nav__item"><i class="fa-solid fa-building-user"></i> Sell to a dealer</a>
    <a href="/brokers.php"    class="pub-mobile-nav__item"><i class="fa-solid fa-id-card"></i> Create your SalesDesk</a>
    <a href="/dealers.php"    class="pub-mobile-nav__item"><i class="fa-solid fa-store"></i> List as a dealership</a>

    <div class="pub-mobile-nav__section">Tools</div>
    <a href="/tools/finance-calculator/" class="pub-mobile-nav__item"><i class="fa-solid fa-calculator"></i> Finance calculator</a>
    <a href="/tools/valuation/"          class="pub-mobile-nav__item"><i class="fa-solid fa-magnifying-glass-dollar"></i> Car valuation</a>
    <a href="/compare/"                  class="pub-mobile-nav__item"><i class="fa-solid fa-scale-balanced"></i> Compare cars</a>
    <a href="/tools/insurance/"          class="pub-mobile-nav__item"><i class="fa-solid fa-shield-halved"></i> Insurance quotes</a>

    <div class="pub-mobile-nav__section">News</div>
    <a href="/news/"     class="pub-mobile-nav__item"><i class="fa-solid fa-newspaper"></i> Car news</a>
    <a href="/reviews/"  class="pub-mobile-nav__item"><i class="fa-solid fa-star-half-stroke"></i> Car reviews</a>
    <a href="/compare/"  class="pub-mobile-nav__item"><i class="fa-solid fa-book-open"></i> Buyer's guides</a>

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
<?php echo renderBreadcrumbSchema($breadcrumbs, $siteBaseUrl); ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════ -->
<main class="<?= $layoutVariant === 'narrow' ? 'pub-page-narrow' : 'pub-page-wide' ?>"
      id="main-content">
  <?= $pageContent ?>
</main>

<!-- ══════════════════════════════════════════════════════
     FOOTER  (v4 — full 4-column dark footer)
     ══════════════════════════════════════════════════════ -->
<footer class="sd-footer">
  <div class="sd-footer__grid">

    <!-- Col 1: Brand + Newsletter + Socials -->
    <div class="sd-footer__col sd-footer__col--brand">
      <div class="sd-footer__brand">
        <div class="sd-footer__logo"><img src="/assets/img/logo.png" alt="SalesDesk logo" width="32" height="32"></div>
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
      <p class="sd-footer__nl-note" id="footerNlNote">No spam &mdash; unsubscribe any time. POPIA compliant.</p>

      <div class="sd-footer__socials">
        <a href="https://x.com/salesdesk_za" class="sd-footer__social" aria-label="twitter">
          <i class="fa-brands fa-x-twitter"></i>
        </a>        
        <a href="#" class="sd-footer__social" aria-label="Instagram">
          <i class="fab fa-instagram"></i>
        </a>
        <a href="https://www.linkedin.com/company/salesdesk-za/" class="sd-footer__social" aria-label="LinkedIn">
          <i class="fab fa-linkedin"></i>
        </a>
        <a href="#" class="sd-footer__social" aria-label="Facebook">
          <i class="fab fa-facebook"></i>
        </a>
        <a href="#" class="sd-footer__social" aria-label="WhatsApp">
          <i class="fab fa-whatsapp"></i>
        </a>
      </div>
    </div>

    <!-- Col 2: Platform -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">Platform</div>
      <div class="sd-footer__links">
        <a href="/cars-for-sale/"                     class="sd-footer__link">Browse vehicles</a>
        <a href="/cars-for-sale/?condition=new"       class="sd-footer__link">New cars</a>
        <a href="/cars-for-sale/?condition=used"      class="sd-footer__link">Pre-owned cars</a>
        <a href="/cars-for-sale/?condition=demo"      class="sd-footer__link">Demo cars</a>
        <a href="/desks/"                 class="sd-footer__link">Find a SalesDesk</a>
        <a href="/auth/register.php"      class="sd-footer__link">Create your Desk</a>
        <a href="auth/login.php"          class="sd-footer__link">List a vehicle</a>
      </div>
    </div>

    <!-- Col 3: About -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">About us</div>
      <div class="sd-footer__links">
        <a href="how-it-works/brokers.php"  class="sd-footer__link">Brokers</a>
        <a href="how-it-works/execs.php"    class="sd-footer__link">Sales Executives</a>
        <a href="how-it-works/dealers.php"  class="sd-footer__link">Dealers</a>
        <a href="/outreach"              class="sd-footer__link">Our Outreach</a>
        <a href="/careers"                  class="sd-footer__link">Careers</a>
        <a href="/about/"                   class="sd-footer__link">Our Story</a>
      </div>
    </div>

    <!-- Col 4: Legal -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">Legal &amp; Help</div>
      <div class="sd-footer__links">
        <a href="/privacy"   class="sd-footer__link">Privacy Policy</a>
        <a href="/terms"     class="sd-footer__link">Terms of Service</a>
        <a href="/popia"     class="sd-footer__link">POPIA Compliance</a>
        <a href="/contact"   class="sd-footer__link">Contact us</a>
        <a href="/help"      class="sd-footer__link">Help centre</a>
        <!--
          CKC-2: reopens the cookie-consent modal at any time so a
          visitor can change or withdraw consent — POPIA/GDPR require
          withdrawal to be as easy as giving consent. No extra JS
          needed here; cookie-consent-banner.php's inline script
          auto-detects this exact id and wires the click handler.
        -->
        <a href="#" id="cookiePreferencesLink" class="sd-footer__link">Cookie preferences</a>
      </div>
    </div>

  </div><!-- /sd-footer__grid -->

  <!-- Bottom bar -->
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
     FOOTER STYLES
     (will migrate to public.css in next CSS pass — T1)
     ══════════════════════════════════════════════════════ -->
<style>

.pub-nav__inner {
  max-width: 100%;
  padding: 0 64px;
  margin: 0 auto;
}

.pub-nav__name:hover {
  text-decoration: none;
}

/* ── Nav logo image ─────────────────────────────────────────── */
.pub-nav__logo img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}
.pub-nav__logo {
  background: #fff;
}

.pub-breadcrumb {
  max-width: 1400px;
  margin: 0 auto;
  margin-bottom: 28px;
  padding: 8px 16px;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--faint);
  border-bottom: 1px solid var(--border);
}

.pub-page-wide {
  max-width: 100%;
  margin: 0 auto;
  padding: 0 0 64px;
}


/* ── Footer shell ─────────────────────────────────────────────── */
.sd-footer {
  background: #08143c;
  padding: 56px 24px 0;
  margin-top: 4rem;
}

/* ── 4-column grid ────────────────────────────────────────────── */
.sd-footer__grid {
  max-width: 1280px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px;
  padding-bottom: 48px;
}

/* ── Brand column ─────────────────────────────────────────────── */
.sd-footer__brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}

.sd-footer__logo {
  width: 32px;
  height: 32px;
  background: rgba(255,255,255,.1);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 13px;
  flex-shrink: 0;
}
.sd-footer__logo img {
  border-radius: 10px;
}
.sd-footer__name {
  font-family: var(--font-d);
  font-size: 20px;
  font-weight: 700;
  color: #fff;
}
.sd-footer__name span { color: #93c5fd; }

.sd-footer__desc {
  font-size: 13px;
  color: #94a3b8;
  line-height: 1.7;
  max-width: 280px;
  margin-bottom: 22px;
}

/* ── Newsletter ───────────────────────────────────────────────── */
.sd-footer__nl-label {
  font-size: 12px;
  font-weight: 600;
  color: #cbd5e1;
  margin-bottom: 10px;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.sd-footer__nl-form {
  display: flex;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.07);
}
.sd-footer__nl-input {
  flex: 1;
  height: 42px;
  border: none;
  background: transparent;
  padding: 0 14px;
  font-size: 13px;
  font-family: var(--sans);
  color: #fff;
  outline: none;
  min-width: 0;
}
.sd-footer__nl-input::placeholder { color: #64748b; }
.sd-footer__nl-btn {
  height: 42px;
  padding: 0 16px;
  background: var(--p);
  color: #fff;
  border: none;
  font-size: 13px;
  font-weight: 600;
  font-family: var(--sans);
  cursor: pointer;
  transition: background .2s;
  white-space: nowrap;
  flex-shrink: 0;
}
.sd-footer__nl-btn:hover { background: var(--p-dark); }
.sd-footer__nl-btn:disabled { opacity: .6; cursor: not-allowed; }
.sd-footer__nl-note {
  font-size: 11px;
  color: #475569;
  margin-top: 8px;
  line-height: 1.5;
}

/* ── Social icons ─────────────────────────────────────────────── */
.sd-footer__socials {
  display: flex;
  gap: 14px;
  margin-top: 18px;
}
.sd-footer__social {
  font-size: 18px;
  color: #475569;
  transition: color .2s;
  text-decoration: none;
}
.sd-footer__social:hover { color: #fff; text-decoration: none; }

/* ── Link columns ─────────────────────────────────────────────── */
.sd-footer__col-title {
  font-size: 13px;
  font-weight: 600;
  color: #fff;
  margin-bottom: 16px;
  letter-spacing: .01em;
}
.sd-footer__links {
  display: flex;
  flex-direction: column;
  gap: 11px;
}
.sd-footer__link {
  font-size: 13px;
  color: #94a3b8;
  transition: color .2s;
  text-decoration: none;
  line-height: 1.4;
}
.sd-footer__link:hover { color: #fff; text-decoration: none; }

/* ── Bottom bar ───────────────────────────────────────────────── */
.sd-footer__bottom {
  max-width: 1280px;
  margin: 0 auto;
  border-top: 1px solid rgba(255,255,255,.08);
  padding: 20px 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12px;
  color: #475569;
}
.sd-footer__badges {
  display: flex;
  align-items: center;
  gap: 20px;
}
.sd-footer__badge {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}
.sd-footer__badge i { color: #3b82f6; }


/* ── Responsive ───────────────────────────────────────────────── */
@media (max-width: 1024px) {
  .sd-footer__grid {
    grid-template-columns: 1fr 1fr;
    gap: 32px;
  }
  .sd-footer__col--brand {
    grid-column: 1 / -1;
  }
}
@media (max-width: 640px) {
  .sd-footer {
    padding: 40px 20px 0;
  }
  .sd-footer__grid {
    grid-template-columns: 1fr;
    gap: 28px;
    padding-bottom: 32px;
  }
  .sd-footer__col--brand {
    grid-column: auto;
  }
  .sd-footer__bottom {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .sd-footer__badges {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
}
</style>

<!-- ══════════════════════════════════════════════════════
     SHARE SHEET OVERLAY
     ══════════════════════════════════════════════════════ -->
<div class="pub-share-overlay" id="shareOverlay" role="dialog"
     aria-modal="true" aria-label="Share this listing">
  <div class="pub-share-sheet">
    <div class="pub-share-sheet__handle"></div>
    <div class="pub-share-sheet__title">Share this listing</div>
    <p class="pub-share-sheet__sub">
      <?= htmlspecialchars($shareTitle) ?>
    </p>

    <div class="pub-share-options">
      <a href="<?= htmlspecialchars($waShareLink) ?>" target="_blank" rel="noopener"
         class="pub-share-option wa">
        <i class="fa-brands fa-whatsapp"></i>WhatsApp
      </a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
         target="_blank" rel="noopener" class="pub-share-option fb">
        <i class="fa-brands fa-facebook"></i>Facebook
      </a>
      <a href="https://twitter.com/intent/tweet?url=<?= urlencode($shareUrl) ?>&text=<?= urlencode($shareTitle) ?>"
         target="_blank" rel="noopener" class="pub-share-option tw">
        <i class="fa-brands fa-x-twitter"></i>X / Twitter
      </a>
      <button class="pub-share-option cp" onclick="copyShareUrl()" type="button">
        <i class="fa-solid fa-link" id="copyUrlBtn"></i>Copy link
      </button>
    </div>

    <div class="pub-share-sheet__url">
      <input type="text" id="shareUrlInput"
             value="<?= htmlspecialchars($shareUrl) ?>"
             readonly aria-label="Share URL">
      <button class="pub-btn pub-btn-ghost" onclick="copyShareUrl()" type="button">
        <i class="fa-solid fa-copy"></i> Copy
      </button>
    </div>

    <button class="pub-btn pub-btn-ghost pub-btn-full" onclick="closeShareSheet()" type="button">
      Close
    </button>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SCRIPTS
     PERF-5: defer on both files.
     - Browser downloads global.js + public.js in parallel during
       HTML parsing (no render blocking).
     - Execution order is preserved (global.js before public.js).
     - Both run just before DOMContentLoaded, after the full DOM
       is available — identical behaviour to the previous placement
       at the bottom of <body> without defer.
     ══════════════════════════════════════════════════════ -->
<script src="/assets/js/global.js?v=<?= $assetVersion ?>" defer></script>
<script src="/assets/js/public.js?v=<?= $assetVersion ?>" defer></script>

<script>
(function () {
  'use strict';

  /* ── Mega-nav panel controller ─────────────────────────────── */
  var zones = [
    { btn: 'navBuyBtn',   panel: 'navBuyPanel'   },
    { btn: 'navSellBtn',  panel: 'navSellPanel'  },
    { btn: 'navNewsBtn',  panel: 'navNewsPanel'  },
    { btn: 'navToolsBtn', panel: 'navToolsPanel' },
    { btn: 'navAcctBtn',  panel: 'navAcctPanel'  },
  ];

  function closeAll(except) {
    zones.forEach(function (z) {
      if (z === except) return;
      var btn   = document.getElementById(z.btn);
      var panel = document.getElementById(z.panel);
      if (!btn || !panel) return;
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      panel.classList.remove('open');
    });
  }

  zones.forEach(function (z) {
    var btn   = document.getElementById(z.btn);
    var panel = document.getElementById(z.panel);
    if (!btn || !panel) return;

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = panel.classList.contains('open');
      closeAll(isOpen ? null : z);
      if (!isOpen) {
        panel.classList.add('open');
        btn.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
      }
    });

    panel.addEventListener('click', function (e) { e.stopPropagation(); });
  });

  document.addEventListener('click', function () { closeAll(null); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll(null);
  });

  /* ── Mobile nav hamburger ───────────────────────────────────── */
  var hamburger = document.getElementById('pubNavHamburger');
  var mobileNav = document.getElementById('pubMobileNav');

  function openMobileNav() {
    if (!hamburger || !mobileNav) return;
    hamburger.classList.add('open');
    hamburger.setAttribute('aria-expanded', 'true');
    mobileNav.classList.add('open');
    document.body.style.overflow = 'hidden';
    closeAll(null);
  }

  function closeMobileNav() {
    if (!hamburger || !mobileNav) return;
    hamburger.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
    mobileNav.classList.remove('open');
    document.body.style.overflow = '';
  }

  if (hamburger) {
    hamburger.addEventListener('click', function (e) {
      e.stopPropagation();
      hamburger.classList.contains('open') ? closeMobileNav() : openMobileNav();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMobileNav();
  });

  if (mobileNav) {
    mobileNav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMobileNav);
    });
  }

})();
</script>

<!-- ══════════════════════════════════════════════════════
     NL-1: FOOTER NEWSLETTER SUBSCRIBE
     Wires .sd-footer__nl-form to /api/newsletter/subscribe.php
     (source=footer). Same AJAX shape as the resub forms on
     newsletter/newsletter-confirmed.php and
     newsletter/newsletter-unsubscribe.php. CSRF header is
     auto-injected by global.js's fetch() interceptor.
     ══════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  var form = document.getElementById('footerNlForm');
  if (!form) return;

  var input       = document.getElementById('footerNlEmail');
  var btn         = document.getElementById('footerNlBtn');
  var note        = document.getElementById('footerNlNote');
  var defaultNote = note.textContent;

  function setNote(msg, color) {
    note.textContent = msg;
    note.style.color = color || '';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var email = input.value.trim();
    if (!email) {
      setNote('Please enter your email address.', '#f87171');
      input.focus();
      return;
    }

    btn.disabled = true;
    var origLabel = btn.textContent;
    btn.textContent = 'Subscribing…';
    setNote('Sending…');

    fetch('/api/newsletter/subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'email=' + encodeURIComponent(email) + '&source=footer',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = origLabel;

        if (data.success) {
          input.value = '';
          setNote(data.message, '#4ade80');
        } else {
          setNote(data.message || 'Something went wrong. Please try again.', '#f87171');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = origLabel;
        setNote('Connection error — please try again.', '#f87171');
      });
  });

  // Reset the note back to its default once the person edits the field again.
  input.addEventListener('input', function () {
    if (note.textContent !== defaultNote) setNote(defaultNote);
  });

})();
</script>

<!-- ══════════════════════════════════════════════════════
     CKC-1: COOKIE CONSENT BANNER
     Rendered right before </body>, alongside the other footer
     scripts above. This partial (views/partials/cookie-consent-banner.php)
     renders identical, visitor-agnostic markup on every request —
     the decision of whether to actually SHOW the banner is made
     client-side by its own inline script reading document.cookie at
     runtime, not by any PHP conditional here. That's what keeps this
     safe to include on pages using applyCachePolicy('public') without
     leaking one visitor's consent state into another's cached HTML.
     See COOKIE-CONSENT-GUIDE.md §5 for the full reasoning, and
     includes/cookie-consent.php for the category definitions this
     partial reads from.
     ══════════════════════════════════════════════════════ -->
<?php require_once __DIR__ . '/partials/cookie-consent-banner.php'; ?>

</body>
</html>
