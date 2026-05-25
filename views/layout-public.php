<?php
/**
 * SalesDesk — Public Layout Shell (v3)
 * T1 owns this file.
 *
 * Shell for all buyer-facing pages. Includes:
 *   - Sticky mega-nav with four content menus + My Account
 *   - Optional breadcrumb row
 *   - Wide (1240px) or Narrow (840px) content variant
 *   - Visitor session init (anonymous cookie tracking)
 *   - Share sheet overlay (activated by openShareSheet() in public.js)
 *   - Public font (Sora) via public-fonts.css
 *   - SEO / Open Graph meta
 *
 * Nav menus:
 *   Buy a Car      — browse by category, condition, body style, province
 *   Sell a Car     — private sale, dealer sale
 *   News & Reviews — car news, reviews, comparisons, guides
 *   Services & Tools — finance, insurance, comparison, valuations
 *   My Account     — buyer tools (wishlist, saved) + role portals
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

  <!--
    CSS load order (T1 rule — no team may alter this sequence):
      1. global.css       — :root vars, reset
      2. public-fonts.css — Sora + --font-d override
      3. public.css       — public page components
      4. browse.css       — mega-nav panels + browse-page styles
      5. broker.css       — broker-specific overrides
      6. extraCss         — caller-injected page-specific sheet (if any)
  -->
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/public-fonts.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/public.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/browse.css?v=<?= $assetVersion ?>">
  <!--<link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/broker.css?v=<?= $assetVersion ?>">-->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        crossorigin="anonymous" referrerpolicy="no-referrer">
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
        <i class="fa-solid fa-car-side"></i>
      </div>
      <div class="pub-nav__name">Sales<span>Desk</span></div>
      <span class="pub-nav__badge">ZA</span>
    </a>

    <!-- Centre links (hidden < 1024px) -->
    <div class="pub-nav__links">

      <!-- ── BUY A CAR ────────────────────────────────── -->
      <div class="pub-nav__browse" id="navBuyWrap">
        <button class="pub-nav__trigger" id="navBuyBtn"
                aria-haspopup="true" aria-expanded="false">
          Buy a Car
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navBuyPanel"
             style="min-width:600px;" role="menu">
          <div class="pub-mega-cols">

            <!-- Col 1: Browse by condition / type -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Type</div>
              <a href="/c/?condition=new" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-star"></i></span>
                <span class="pub-mega-item-text">New Cars
                  <span class="pub-mega-item-sub">Factory-fresh, full warranty</span>
                </span>
              </a>
              <a href="/c/?condition=used" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car"></i></span>
                <span class="pub-mega-item-text">Pre-Owned Cars
                  <span class="pub-mega-item-sub">Thoroughly checked listings</span>
                </span>
              </a>
              <a href="/c/?condition=demo" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side"></i></span>
                <span class="pub-mega-item-text">Demo Cars
                  <span class="pub-mega-item-sub">Low mileage, big savings</span>
                </span>
              </a>
              <a href="/c/?fuel_type[]=Electric" class="pub-mega-item" role="menuitem">
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
              <a href="/c/?body_type[]=SUV%2F4x4" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-monster"></i></span>
                <span class="pub-mega-item-text">SUVs &amp; 4×4s</span>
              </a>
              <a href="/c/?body_type[]=Sedan" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car"></i></span>
                <span class="pub-mega-item-text">Sedans</span>
              </a>
              <a href="/c/?body_type[]=Hatchback" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side"></i></span>
                <span class="pub-mega-item-text">Hatchbacks</span>
              </a>
              <a href="/c/?body_type[]=Bakkie" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-pickup"></i></span>
                <span class="pub-mega-item-text">Bakkies &amp; Trucks</span>
              </a>
              <a href="/c/?body_type[]=MPV" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-van-shuttle"></i></span>
                <span class="pub-mega-item-text">MPVs &amp; Minivans</span>
              </a>
              <a href="/c/?body_type[]=Coupe" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-burst"></i></span>
                <span class="pub-mega-item-text">Coupes &amp; Convertibles</span>
              </a>
            </div>

            <div class="pub-mega-divider"></div>

            <!-- Col 3: Browse by province / quick links -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Province</div>
              <a href="/c/?province=Gauteng" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">Gauteng</span>
              </a>
              <a href="/c/?province=Western+Cape" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">Western Cape</span>
              </a>
              <a href="/c/?province=KwaZulu-Natal" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">KwaZulu-Natal</span>
              </a>
              <a href="/c/?province=Eastern+Cape" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="pub-mega-item-text">Eastern Cape</span>
              </a>
              <div class="pub-mega-col-label">Quick Links</div>
              <a href="/c/" class="pub-mega-item" role="menuitem">
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

        <div class="pub-mega-panel" id="navSellPanel"
             style="min-width:380px;" role="menu">
          <div style="padding:4px;">
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

        <div class="pub-mega-panel" id="navNewsPanel"
             style="min-width:460px;" role="menu">
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

        <div class="pub-mega-panel" id="navToolsPanel"
             style="min-width:460px;" role="menu">
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
              <a href="/compare/" class="pub-mega-item" role="menuitem">
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

    <!-- Right: My Account -->
    <div class="pub-nav__actions">
      <div style="position:relative;" id="navAcctWrap">

        <?php if ($navLoggedIn): ?>
        <button class="pub-nav__acct-btn" id="navAcctBtn"
                aria-haspopup="true" aria-expanded="false">
          <i class="fa-solid fa-user-circle"></i>
          <span class="pub-nav-label"><?= htmlspecialchars($navFirstName ?: 'My Account') ?></span>
          <span class="pub-chevron"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <?php else: ?>
        <a href="/auth/login.php" class="pub-nav__signin" style="margin-right:10px;">Sign in</a>
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
          <div class="pub-acct-section-label">Portals</div>
          <a href="/app/broker/dashboard.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-id-card"></i> For Brokers
          </a>
          <a href="/app/exec/dashboard.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-user-tie"></i> For Sales Executives
          </a>
          <a href="/app/dealer/dashboard.php" class="pub-acct-item" role="menuitem">
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
    </div><!-- /pub-nav__actions -->

  </div><!-- /pub-nav__inner -->
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
      <span class="pub-footer__brand">
        Sales<span>Desk</span>
      </span>
      &copy; <?= date('Y') ?> SalesDesk (Pty) Ltd &middot; South Africa
    </div>
    <div class="pub-footer__links">
      <a href="/privacy">Privacy</a>
      <a href="/terms">Terms</a>
      <a href="/brokers.php">For Brokers</a>
      <a href="/dealers.php">For Dealers</a>
    </div>
  </div>
</footer>

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
     ══════════════════════════════════════════════════════ -->
<script src="/assets/js/public.js?v=<?= $assetVersion ?>"></script>

<script>
(function () {
  'use strict';

  /**
   * Mega-nav controller.
   *
   * Each zone has a trigger button, a panel div, and a wrapper div used
   * for click-outside detection. Only one panel is open at a time.
   * Clicking the same trigger closes it; clicking outside or pressing
   * Escape closes all. aria-expanded stays in sync with the open state.
   */
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

    // Prevent clicks inside the panel closing it via the document handler
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
  });

  document.addEventListener('click', function () { closeAll(null); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll(null);
  });

})();
</script>

</body>
</html>
