<?php
/**
 * SalesDesk — Public Layout Shell (v5)
 * T1 owns this file.
 *
 * Changes from v4:
 *   - Mobile hamburger nav drawer added (full menu access ≤ 1024px):
 *       · Button (#navHamburger) injected into .pub-nav__actions
 *       · Full <nav class="pub-mobile-nav"> drawer below main nav
 *       · JS: hamburger toggle, Escape-to-close, link-click-to-close,
 *         body scroll-lock while drawer is open
 *   - browse-additions.css added to CSS load order (sr-only, footer
 *     responsive patches, pub-mega-panel__inner etc.)
 *   - All remaining inline style= removed from nav HTML:
 *       · style="position:relative" on #navAcctWrap
 *         → class="pub-nav__acct-wrap"
 *       · style="margin-right:10px" on guest sign-in link
 *         → class="pub-nav__signin--guest"
 *       · style="padding:4px" on Sell panel body div
 *         → class="pub-mega-panel__inner"
 *       · style="min-width:…px" on panel divs removed
 *         (widths now live in browse.css §2)
 *   - aria improvements: aria-controls on all trigger buttons,
 *     aria-hidden="true" on all decorative FA icons,
 *     role="menubar" on .pub-nav__links,
 *     breadcrumb uses <nav> + aria-label + aria-current="page"
 *   - JS: closeAll() now also resets aria-expanded to 'false' on the
 *     closing branch (was missing from v4)
 *   - $navDashLink retained from v4 (role-based portal redirect)
 *
 * Footer (4-column dark footer + inline <style>) unchanged from v4.
 * Will migrate footer CSS to public.css in next dedicated CSS pass.
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
      1. global.css            — :root vars, reset
      2. public-fonts.css      — Sora + --font-d override
      3. public.css            — public page components
      4. browse.css            — mega-nav panels + browse-page styles
      5. browse-additions.css  — sr-only, footer, responsive patches
      6. extraCss              — caller-injected page-specific sheet
  -->
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/public-fonts.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/public.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/browse.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="<?= $baseUrl ?? '' ?>assets/css/browse-additions.css?v=<?= $assetVersion ?>">
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
        <i class="fa-solid fa-car-side" aria-hidden="true"></i>
      </div>
      <div class="pub-nav__name">Sales<span>Desk</span></div>
      <span class="pub-nav__badge">ZA</span>
    </a>

    <!-- Centre links (desktop only — hidden ≤ 1024px) -->
    <div class="pub-nav__links" role="menubar" aria-label="Main menu">

      <!-- ── BUY A CAR ────────────────────────────────── -->
      <div class="pub-nav__browse" id="navBuyWrap">
        <button class="pub-nav__trigger" id="navBuyBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="navBuyPanel">
          Buy a Car
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navBuyPanel" role="menu">
          <div class="pub-mega-cols">

            <!-- Col 1: Browse by condition / type -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Type</div>
              <a href="/c/?condition=new" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">New Cars
                  <span class="pub-mega-item-sub">Factory-fresh, full warranty</span>
                </span>
              </a>
              <a href="/c/?condition=used" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Pre-Owned Cars
                  <span class="pub-mega-item-sub">Thoroughly checked listings</span>
                </span>
              </a>
              <a href="/c/?condition=demo" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Demo Cars
                  <span class="pub-mega-item-sub">Low mileage, big savings</span>
                </span>
              </a>
              <a href="/c/?fuel_type[]=Electric" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Electric Vehicles
                  <span class="pub-mega-item-sub">EVs &amp; plug-in hybrids</span>
                </span>
              </a>
            </div>

            <div class="pub-mega-divider" aria-hidden="true"></div>

            <!-- Col 2: Browse by body style -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Body Style</div>
              <a href="/c/?body_type[]=SUV%2F4x4" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-monster" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">SUVs &amp; 4×4s</span>
              </a>
              <a href="/c/?body_type[]=Sedan" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Sedans</span>
              </a>
              <a href="/c/?body_type[]=Hatchback" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-side" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Hatchbacks</span>
              </a>
              <a href="/c/?body_type[]=Bakkie" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-truck-pickup" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Bakkies &amp; Trucks</span>
              </a>
              <a href="/c/?body_type[]=MPV" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-van-shuttle" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">MPVs &amp; Minivans</span>
              </a>
              <a href="/c/?body_type[]=Coupe" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-car-burst" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Coupes &amp; Convertibles</span>
              </a>
            </div>

            <div class="pub-mega-divider" aria-hidden="true"></div>

            <!-- Col 3: Browse by province / quick links -->
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">By Province</div>
              <a href="/c/?province=Gauteng" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Gauteng</span>
              </a>
              <a href="/c/?province=Western+Cape" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Western Cape</span>
              </a>
              <a href="/c/?province=KwaZulu-Natal" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">KwaZulu-Natal</span>
              </a>
              <a href="/c/?province=Eastern+Cape" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Eastern Cape</span>
              </a>
              <div class="pub-mega-col-label">Quick Links</div>
              <a href="/c/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-grid-2" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Browse all cars</span>
              </a>
            </div>

          </div>
        </div>
      </div><!-- /navBuyWrap -->

      <!-- ── SELL A CAR ─────────────────────────────────── -->
      <div class="pub-nav__browse" id="navSellWrap">
        <button class="pub-nav__trigger" id="navSellBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="navSellPanel">
          Sell a Car
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navSellPanel" role="menu">
          <div class="pub-mega-panel__inner">
            <div class="pub-mega-col-label">How would you like to sell?</div>
            <a href="/sell/private/" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
              <span class="pub-mega-item-text">Sell Privately
                <span class="pub-mega-item-sub">List your car directly to buyers — no dealership cut</span>
              </span>
            </a>
            <a href="/sell/dealer/" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-building-user" aria-hidden="true"></i></span>
              <span class="pub-mega-item-text">Sell to a Dealer
                <span class="pub-mega-item-sub">Fast, hassle-free — get an instant offer</span>
              </span>
            </a>
            <div class="pub-mega-hdivider" aria-hidden="true"></div>
            <div class="pub-mega-col-label">Are you a car broker?</div>
            <a href="/brokers.php" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-id-card" aria-hidden="true"></i></span>
              <span class="pub-mega-item-text">Create your SalesDesk
                <span class="pub-mega-item-sub">Earn commission — no stock needed</span>
              </span>
            </a>
            <a href="/dealers.php" class="pub-mega-item" role="menuitem">
              <span class="pub-mega-icon"><i class="fa-solid fa-store" aria-hidden="true"></i></span>
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
                aria-haspopup="true" aria-expanded="false" aria-controls="navNewsPanel">
          News &amp; Reviews
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navNewsPanel" role="menu">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Latest</div>
              <a href="/news/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-newspaper" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Car News
                  <span class="pub-mega-item-sub">SA &amp; international updates</span>
                </span>
              </a>
              <a href="/news/launches/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-rocket" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">New Launches
                  <span class="pub-mega-item-sub">What's arriving in SA showrooms</span>
                </span>
              </a>
              <a href="/news/industry/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Industry &amp; Market
                  <span class="pub-mega-item-sub">Sales figures, trends, analysis</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider" aria-hidden="true"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Reviews &amp; Guides</div>
              <a href="/reviews/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-star-half-stroke" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Car Reviews
                  <span class="pub-mega-item-sub">Expert road tests &amp; ratings</span>
                </span>
              </a>
              <a href="/compare/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Head-to-Head Comparisons
                  <span class="pub-mega-item-sub">Compare specs side by side</span>
                </span>
              </a>
              <a href="/guides/buying/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-book-open" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Buyer's Guides
                  <span class="pub-mega-item-sub">How to choose the right car</span>
                </span>
              </a>
              <a href="/guides/ownership/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-wrench" aria-hidden="true"></i></span>
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
                aria-haspopup="true" aria-expanded="false" aria-controls="navToolsPanel">
          Services &amp; Tools
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>

        <div class="pub-mega-panel" id="navToolsPanel" role="menu">
          <div class="pub-mega-cols">
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Finance</div>
              <a href="/tools/finance-calculator/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-calculator" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Finance Calculator
                  <span class="pub-mega-item-sub">Estimate monthly repayments</span>
                </span>
              </a>
              <a href="/tools/affordability/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Affordability Check
                  <span class="pub-mega-item-sub">How much car can you afford?</span>
                </span>
              </a>
              <a href="/tools/pre-approval/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Finance Pre-Approval
                  <span class="pub-mega-item-sub">Know your budget before you shop</span>
                </span>
              </a>
            </div>
            <div class="pub-mega-divider" aria-hidden="true"></div>
            <div class="pub-mega-col">
              <div class="pub-mega-col-label">Insurance &amp; Value</div>
              <a href="/tools/insurance/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Car Insurance Quotes
                  <span class="pub-mega-item-sub">Compare insurance in minutes</span>
                </span>
              </a>
              <a href="/tools/valuation/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-magnifying-glass-dollar" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Free Car Valuation
                  <span class="pub-mega-item-sub">What's your car worth today?</span>
                </span>
              </a>
              <a href="/compare/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Car Comparison Tool
                  <span class="pub-mega-item-sub">Compare up to 3 cars at once</span>
                </span>
              </a>
              <a href="/tools/running-costs/" class="pub-mega-item" role="menuitem">
                <span class="pub-mega-icon"><i class="fa-solid fa-gas-pump" aria-hidden="true"></i></span>
                <span class="pub-mega-item-text">Running Cost Estimator
                  <span class="pub-mega-item-sub">Fuel, service &amp; tyres</span>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div><!-- /navToolsWrap -->

    </div><!-- /pub-nav__links -->

    <!-- Right: hamburger (mobile) + My Account -->
    <div class="pub-nav__actions">

      <!-- Hamburger — only visible ≤ 1024px -->
      <button class="pub-nav__hamburger" id="navHamburger"
              aria-label="Open navigation menu" aria-expanded="false"
              aria-controls="mobileNav">
        <span></span>
        <span></span>
        <span></span>
      </button>

      <!-- My Account (always visible) -->
      <div class="pub-nav__acct-wrap" id="navAcctWrap">

        <?php if ($navLoggedIn): ?>
        <button class="pub-nav__acct-btn" id="navAcctBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="navAcctPanel">
          <i class="fa-solid fa-user-circle" aria-hidden="true"></i>
          <span class="pub-nav-label"><?= htmlspecialchars($navFirstName ?: 'My Account') ?></span>
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <?php else: ?>
        <a href="/auth/login.php" class="pub-nav__signin pub-nav__signin--guest">Sign in</a>
        <button class="pub-nav__acct-btn" id="navAcctBtn"
                aria-haspopup="true" aria-expanded="false" aria-controls="navAcctPanel">
          <i class="fa-solid fa-user-circle" aria-hidden="true"></i>
          <span class="pub-nav-label">My Account</span>
          <span class="pub-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </button>
        <?php endif; ?>

        <!-- Account dropdown panel -->
        <div class="pub-mega-panel pub-acct-panel" id="navAcctPanel" role="menu">

          <div class="pub-acct-section-label">My Activity</div>
          <a href="/account/recently-viewed/" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Recently Viewed
          </a>
          <a href="/account/wishlist/" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-heart" aria-hidden="true"></i> Wishlist
          </a>
          <a href="/account/saved-searches/" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Saved Searches
          </a>

          <div class="pub-acct-divider" aria-hidden="true"></div>
          <div class="pub-acct-section-label">Portals</div>
          <a href="/app/broker/dashboard.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-id-card" aria-hidden="true"></i> For Brokers
          </a>
          <a href="/app/exec/dashboard.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-user-tie" aria-hidden="true"></i> For Sales Executives
          </a>
          <a href="/app/dealer/dashboard.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-building-user" aria-hidden="true"></i> For Dealers
          </a>

          <?php if ($navLoggedIn): ?>
          <div class="pub-acct-divider" aria-hidden="true"></div>
          <a href="/auth/logout.php" class="pub-acct-item danger" role="menuitem">
            <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sign out
          </a>
          <?php else: ?>
          <div class="pub-acct-divider" aria-hidden="true"></div>
          <a href="/auth/login.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Sign in
          </a>
          <a href="/auth/register.php" class="pub-acct-item" role="menuitem">
            <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create an account
          </a>
          <?php endif; ?>

        </div><!-- /navAcctPanel -->
      </div><!-- /navAcctWrap -->
    </div><!-- /pub-nav__actions -->

  </div><!-- /pub-nav__inner -->
</nav>

<!-- ══════════════════════════════════════════════════════
     MOBILE NAV DRAWER  (hidden on desktop, shown ≤ 1024px)
     ══════════════════════════════════════════════════════ -->
<nav class="pub-mobile-nav" id="mobileNav" aria-label="Mobile navigation" aria-hidden="true">
  <div class="pub-mobile-nav__inner">

    <div class="pub-mobile-nav__section">Buy a Car</div>
    <a href="/c/?condition=new"          class="pub-mobile-nav__item"><i class="fa-solid fa-star" aria-hidden="true"></i> New Cars</a>
    <a href="/c/?condition=used"         class="pub-mobile-nav__item"><i class="fa-solid fa-car" aria-hidden="true"></i> Pre-Owned Cars</a>
    <a href="/c/?condition=demo"         class="pub-mobile-nav__item"><i class="fa-solid fa-car-side" aria-hidden="true"></i> Demo Cars</a>
    <a href="/c/?fuel_type[]=Electric"   class="pub-mobile-nav__item"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Electric Vehicles</a>
    <a href="/c/?body_type[]=SUV%2F4x4"  class="pub-mobile-nav__item"><i class="fa-solid fa-truck-monster" aria-hidden="true"></i> SUVs &amp; 4×4s</a>
    <a href="/c/?body_type[]=Hatchback"  class="pub-mobile-nav__item"><i class="fa-solid fa-car-side" aria-hidden="true"></i> Hatchbacks</a>
    <a href="/c/?body_type[]=Bakkie"     class="pub-mobile-nav__item"><i class="fa-solid fa-truck-pickup" aria-hidden="true"></i> Bakkies &amp; Trucks</a>
    <a href="/c/"                        class="pub-mobile-nav__item"><i class="fa-solid fa-grid-2" aria-hidden="true"></i> Browse all cars</a>

    <div class="pub-mobile-nav__section">Sell a Car</div>
    <a href="/sell/private/"  class="pub-mobile-nav__item"><i class="fa-solid fa-user" aria-hidden="true"></i> Sell Privately</a>
    <a href="/sell/dealer/"   class="pub-mobile-nav__item"><i class="fa-solid fa-building-user" aria-hidden="true"></i> Sell to a Dealer</a>
    <a href="/brokers.php"    class="pub-mobile-nav__item"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Create your SalesDesk</a>
    <a href="/dealers.php"    class="pub-mobile-nav__item"><i class="fa-solid fa-store" aria-hidden="true"></i> List as a Dealership</a>

    <div class="pub-mobile-nav__section">News &amp; Reviews</div>
    <a href="/news/"             class="pub-mobile-nav__item"><i class="fa-solid fa-newspaper" aria-hidden="true"></i> Car News</a>
    <a href="/news/launches/"    class="pub-mobile-nav__item"><i class="fa-solid fa-rocket" aria-hidden="true"></i> New Launches</a>
    <a href="/reviews/"          class="pub-mobile-nav__item"><i class="fa-solid fa-star-half-stroke" aria-hidden="true"></i> Car Reviews</a>
    <a href="/guides/buying/"    class="pub-mobile-nav__item"><i class="fa-solid fa-book-open" aria-hidden="true"></i> Buyer's Guides</a>

    <div class="pub-mobile-nav__section">Services &amp; Tools</div>
    <a href="/tools/finance-calculator/" class="pub-mobile-nav__item"><i class="fa-solid fa-calculator" aria-hidden="true"></i> Finance Calculator</a>
    <a href="/tools/affordability/"      class="pub-mobile-nav__item"><i class="fa-solid fa-wallet" aria-hidden="true"></i> Affordability Check</a>
    <a href="/tools/insurance/"          class="pub-mobile-nav__item"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Car Insurance Quotes</a>
    <a href="/tools/valuation/"          class="pub-mobile-nav__item"><i class="fa-solid fa-magnifying-glass-dollar" aria-hidden="true"></i> Free Car Valuation</a>
    <a href="/compare/"                  class="pub-mobile-nav__item"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Compare Cars</a>

    <div class="pub-mobile-nav__section">My Account</div>
    <a href="/account/wishlist/"         class="pub-mobile-nav__item"><i class="fa-solid fa-heart" aria-hidden="true"></i> Wishlist</a>
    <a href="/account/recently-viewed/"  class="pub-mobile-nav__item"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Recently Viewed</a>
    <a href="/app/broker/dashboard.php"  class="pub-mobile-nav__item"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Broker Portal</a>
    <a href="/app/dealer/dashboard.php"  class="pub-mobile-nav__item"><i class="fa-solid fa-building-user" aria-hidden="true"></i> Dealer Portal</a>
    <?php if ($navLoggedIn): ?>
    <a href="/auth/logout.php" class="pub-mobile-nav__item pub-mobile-nav__item--danger">
      <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sign out
    </a>
    <?php else: ?>
    <a href="/auth/login.php"    class="pub-mobile-nav__item"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Sign in</a>
    <a href="/auth/register.php" class="pub-mobile-nav__item"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create an account</a>
    <?php endif; ?>

  </div>
</nav>


<!-- ══════════════════════════════════════════════════════
     BREADCRUMB (optional)
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
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════ -->
<main class="pub-page-<?= $layoutVariant === 'narrow' ? 'narrow' : 'wide' ?>"
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
        <div class="sd-footer__logo"><i class="fa-solid fa-car-side"></i></div>
        <span class="sd-footer__name">Sales<span>Desk</span></span>
      </div>
      <p class="sd-footer__desc">
        South Africa's independent car sales platform.
        Commission-protected leads. Verified dealers.
        POPIA compliant.
      </p>

      <!-- Newsletter — wiring deferred to next session -->
      <p class="sd-footer__nl-label">Get car news &amp; deal alerts</p>
      <div class="sd-footer__nl-form">
        <!-- TODO: wire to POST /api/newsletter/subscribe -->
        <input type="email" class="sd-footer__nl-input"
               placeholder="Your email address"
               aria-label="Email address for newsletter">
        <button class="sd-footer__nl-btn" type="button">Subscribe</button>
      </div>
      <p class="sd-footer__nl-note">No spam &mdash; unsubscribe any time. POPIA compliant.</p>

      <div class="sd-footer__socials">
        <a href="#" class="sd-footer__social" aria-label="Instagram">
          <i class="fab fa-instagram"></i>
        </a>
        <a href="#" class="sd-footer__social" aria-label="LinkedIn">
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
        <a href="/c/"                     class="sd-footer__link">Browse vehicles</a>
        <a href="/c/?condition=new"       class="sd-footer__link">New cars</a>
        <a href="/c/?condition=used"      class="sd-footer__link">Pre-owned cars</a>
        <a href="/c/?condition=demo"      class="sd-footer__link">Demo cars</a>
        <a href="/desks/"                 class="sd-footer__link">Find a SalesDesk</a>
        <a href="/auth/register.php"      class="sd-footer__link">Create your Desk</a>
        <a href="/dealers.php"            class="sd-footer__link">List a vehicle</a>
      </div>
    </div>

    <!-- Col 3: For Brokers -->
    <div class="sd-footer__col">
      <div class="sd-footer__col-title">For Brokers</div>
      <div class="sd-footer__links">
        <a href="/brokers.php"  class="sd-footer__link">How commissions work</a>
        <a href="/brokers.php"  class="sd-footer__link">SalesDesk Organisations</a>
        <a href="/brokers.php"  class="sd-footer__link">Lead attribution</a>
        <a href="/brokers.php"  class="sd-footer__link">Payout schedule</a>
        <a href="/dealers.php"  class="sd-footer__link">For Dealers</a>
        <a href="/app/broker/dashboard.php" class="sd-footer__link">Broker portal</a>
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
      </div>
    </div>

  </div><!-- /sd-footer__grid -->

  <!-- Bottom bar -->
  <div class="sd-footer__bottom">
    <span>
      &copy; <?= date('Y') ?> SalesDesk (Pty) Ltd &middot; South Africa &middot; CIPC Registered
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
/* ── Footer shell ─────────────────────────────────────────────── */
.sd-footer {
  background: #08143c;   /* --dark from design tokens */
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
    grid-column: 1 / -1;  /* full width on tablet */
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
     ══════════════════════════════════════════════════════ -->
<script src="/assets/js/public.js?v=<?= $assetVersion ?>"></script>

<script>
(function () {
  'use strict';

  /* ── Mega-nav (desktop) ─────────────────────────────────── */
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
      } else {
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    // Prevent clicks inside the panel closing it via the document handler
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
  });

  document.addEventListener('click', function () { closeAll(null); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll(null);
  });

  /* ── Mobile hamburger nav ────────────────────────────────── */
  var hamburger = document.getElementById('navHamburger');
  var mobileNav = document.getElementById('mobileNav');
  var body      = document.body;

  if (hamburger && mobileNav) {
    hamburger.addEventListener('click', function () {
      var isOpen = mobileNav.classList.contains('open');
      if (isOpen) {
        mobileNav.classList.remove('open');
        hamburger.classList.remove('open');
        hamburger.setAttribute('aria-expanded', 'false');
        mobileNav.setAttribute('aria-hidden', 'true');
        body.style.overflow = '';
      } else {
        closeAll(null); // close any open desktop dropdowns first
        mobileNav.classList.add('open');
        hamburger.classList.add('open');
        hamburger.setAttribute('aria-expanded', 'true');
        mobileNav.setAttribute('aria-hidden', 'false');
        body.style.overflow = 'hidden'; // prevent body scroll behind drawer
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
        mobileNav.classList.remove('open');
        hamburger.classList.remove('open');
        hamburger.setAttribute('aria-expanded', 'false');
        mobileNav.setAttribute('aria-hidden', 'true');
        body.style.overflow = '';
        hamburger.focus();
      }
    });

    // Close when a link inside the drawer is clicked
    mobileNav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        mobileNav.classList.remove('open');
        hamburger.classList.remove('open');
        hamburger.setAttribute('aria-expanded', 'false');
        mobileNav.setAttribute('aria-hidden', 'true');
        body.style.overflow = '';
      });
    });
  }

})();
</script>

</body>
</html>
