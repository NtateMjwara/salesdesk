<?php
/**
 * SalesDesk — Homepage
 * Route: /   (web root)
 *
 * Wired into layout-public.php (nav, footer, visitor tracking).
 * Live car counts pulled from DB; sample vehicle cards use real
 * broker_inventory + cars query (limited to 8, newest first, 4x2 grid).
 * Province counts pulled live.
 *
 * CHANGES IN THIS PASS:
 *   - Hero search replaced with HeroSearch v3 widget
 *     (views/partials/hero-search-widget.php). All search state,
 *     autocomplete, live result count, and recent-searches logic now
 *     live inside that self-contained partial. home.js retains only
 *     the activity-tab navigation and dynamic-VH helpers.
 *   - Activity tabs (Recently Viewed / Wishlist / Saved Searches) now
 *     hooked to car_views, visitor_wishlist, and saved_searches tables.
 *     See db/0008_saved_searches.sql for the new table.
 *   - Featured vehicle grid bumped from 3 → 8 cars (4 cols x 2 rows
 *     on desktop; CSS handles mobile layout — see home.css).
 *   - LEAN-1 (Oct 2026): Car News & Reviews section removed — the
 *     public site now focuses solely on cars and operations.
 *   - LEAN-2 (Oct 2026): "Why SalesDesk" section removed.
 *   - DESK-ORG-1 (Oct 2026): "Top SalesDesks" broker cards replaced by
 *     the top 3 desk organisations, rendered with the shared sdOrgCard()
 *     partial and desks.css (same cards as /desks/).
 *
 * PERF FIX (this pass):
 *   home.css was being <link>'d from the *middle of the page body*
 *   (right before the closing inline <style> block, near the bottom
 *   of $pageContent). Since home.css defines the hero, search card,
 *   shop cards, and vehicle grid — i.e. everything above the fold —
 *   the browser was painting all of that markup completely unstyled
 *   first, then only discovering/fetching home.css once the parser
 *   reached that <link> tag deep in <body>, causing a visible re-flow
 *   once it finally loaded. layout-public.php already supports an
 *   $extraCss hook rendered inside <head> (see its own PERF-1/2/3
 *   comments) — index.php just never used it. Now it does: home.css
 *   is preloaded + linked from <head>, in parallel with global.css /
 *   public.css / browse.css, so it's available before first paint
 *   instead of after it. The plain <link> tag that used to sit in the
 *   body has been removed; home.js (already `defer`'d) is left where
 *   it was since script position in the body doesn't block paint.
 *
 * ROUTE-1 (this pass): every /cars-for-sale/ browse link on the homepage (hero
 *   category pills, "Browse all vehicles" button, province chips,
 *   saved-search links, featured-car card links) now points at
 *   /cars-for-sale/ instead of /cars-for-sale/, matching the route rename in
 *   .htaccess and cars-for-sale/index.php.
 *
 * PUBLIC UX/UI OVERHAUL (this pass):
 *   HOME-1  Page rebuilt: ink hero with a vertical search card, body-type
 *           tiles, latest listings on the shared vehicle card
 *           (views/partials/vehicle-card.php), "continue browsing" rails
 *           that only render when the visitor has activity, affordability
 *           calculator + budget tiles, condition cards, how-it-works /
 *           trust band, provinces, top desks, news, trade CTA band.
 *   HOME-2  ALL inline <style>, <script>, style="" and onclick="" removed.
 *           Page CSS: assets/css/home.css + assets/css/hero-search.css.
 *           Page JS:  assets/js/home.js (hero search) — loaded via $extraJs.
 *   HOME-3  Hero search make list now comes from live inventory (with
 *           counts) instead of a hardcoded JS array; condition + body
 *           type counts added (one grouped query each).
 *
 * Remaining TODOs left as inline comments:
 *   - Top SalesDesks: replace static cards with live query
 */

declare(strict_types=1);

/**
 * SECURITY FIX: security.php was commented out here — meaning the
 * homepage, the single highest-traffic page on the entire site, was
 * shipping with NONE of the headers it sets: no X-Frame-Options (open to
 * clickjacking via iframe embedding), no Content-Security-Policy, no
 * X-Content-Type-Options, no X-XSS-Protection, no Referrer-Policy. Every
 * other page audited in this codebase (cars-for-sale/index.php, app/dealer/*, etc.)
 * requires this first. Re-enabled, and reordered to match security.php's
 * own documented canonical order (security -> session -> database ->
 * functions) — this file previously loaded database.php before
 * session.php, the reverse of that order.
 */
require_once 'includes/security.php';
require_once 'includes/session.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/visitor.php';

applyCachePolicy('public');

$pdo     = Database::getInstance();
$visitor = initVisitorSession();

// ── Live: total active listings count ─────────────────────────
// MIGRATION 0012 SYNC FIX (this pass): this previously required a
// broker_inventory row (JOIN bi), i.e. only counted cars that had
// been added to at least one broker's desk. But cars-for-sale/index.php
// (the page this number links to) no longer gates on desk attribution
// at all — per migration 0012 it's the platform's own browse page and
// shows every active car from every active dealer, desk or no desk
// (see that file's WHERE-build comment). That mismatch is exactly the
// "N vehicles" vs "actually see fewer on click-through" bug already
// diagnosed once for the hero widget (BUG-SEARCH-01 in
// api/cars/search.php) — it was just never fixed here on the homepage
// itself. Query now matches cars-for-sale/index.php's own count query
// exactly: status=active + dealer active, no desk gate.
try {
    $totalCars = (int) $pdo->query("
        SELECT COUNT(DISTINCT c.id)
        FROM cars c
        JOIN dealers d ON d.id = c.dealer_id
        WHERE c.status = 'active'
          AND d.is_active = 1
    ")->fetchColumn();
} catch (Throwable) {
    $totalCars = 0;
}

// ── Live: province listing counts ─────────────────────────────
// MIGRATION 0012 SYNC FIX (this pass): same issue as $totalCars above
// — the broker_inventory JOIN made this undercount vs. what clicking
// through to /cars-for-sale/?province=X actually shows (that page has
// no desk gate). Dropped the JOIN, added d.is_active=1 to match the
// dealer-active guard cars-for-sale/index.php's own $where uses.
try {
    $provStmt = $pdo->query("
        SELECT a.province, COUNT(DISTINCT c.id) AS cnt
        FROM cars c
        JOIN dealers d           ON d.id  = c.dealer_id
        JOIN addresses a         ON a.id  = d.address_id
        WHERE c.status = 'active'
          AND d.is_active = 1
          AND a.province IS NOT NULL
        GROUP BY a.province
        ORDER BY cnt DESC
    ");
    $provCounts = [];
    foreach ($provStmt->fetchAll() as $row) {
        $provCounts[$row['province']] = (int) $row['cnt'];
    }
} catch (Throwable) {
    $provCounts = [];
}

// ── Live: featured cars (newest 8 active cars) ─────────────────
// MIGRATION 0012 SYNC FIX (this pass): dropped the
// "first_desk.desk_slug IS NOT NULL" gate this query previously had.
// first_desk is LEFT JOINed purely as optional metadata here — same
// treatment cars-for-sale/index.php gives it — so a car that isn't on
// any broker's desk yet can still be featured; it just renders without
// a desk badge and links to /cars-for-sale/car/{slug}/ instead of
// /cars-for-sale/{desk}/{slug}/ (see $carUrl below in the markup).
try {
    $featuredStmt = $pdo->prepare("
        SELECT
            c.id, c.slug AS car_slug, c.make, c.model, c.variant, c.year, c.price,
            c.mileage, c.condition_type, c.body_type, c.fuel_type,
            c.transmission, c.drivetrain, c.image_urls,
            d.verification_status AS dealer_verified,
            d.company_name        AS dealer_name,
            a.city                AS dealer_city,
            a.province            AS dealer_province,
            first_desk.desk_slug,
            first_desk.desk_name,
            first_desk.tracking_code AS desk_tracking_code
        FROM cars c
        JOIN dealers d        ON d.id  = c.dealer_id
        LEFT JOIN addresses a ON a.id  = d.address_id
        LEFT JOIN (
            SELECT bi2.car_id,
                   sd2.slug         AS desk_slug,
                   sd2.display_name AS desk_name,
                   bi2.tracking_code
            FROM broker_inventory bi2
            JOIN salesdesks sd2 ON sd2.id = bi2.salesdesk_id
            WHERE bi2.added_at = (
                SELECT MIN(bi3.added_at)
                FROM broker_inventory bi3
                WHERE bi3.car_id = bi2.car_id
            )
            GROUP BY bi2.car_id
        ) first_desk ON first_desk.car_id = c.id
        WHERE c.status = 'active'
          AND d.is_active = 1
        ORDER BY c.created_at DESC
        LIMIT 8
    ");
    $featuredStmt->execute();
    $featuredCars = $featuredStmt->fetchAll();
} catch (Throwable) {
    $featuredCars = [];
}

// ── Live: top desk organisations (DESK-ORG-1) ──────────────────
// Same source and merit ordering as the /desks/ directory (live cars,
// then desks), so the homepage teaser and the directory always agree.
require_once __DIR__ . '/includes/desk-directory.php';
require_once __DIR__ . '/views/partials/desk-card.php';
try {
    $topOrgs     = array_slice(sdOrgDirectory(), 0, 3);
    $orgPreviews = $topOrgs ? sdOrgPreviews(array_map(static fn($o) => (int) $o['id'], $topOrgs)) : [];
} catch (Throwable) {
    $topOrgs     = [];
    $orgPreviews = [];
}

// ── Live: Recently Viewed (last 8 distinct cars for this visitor) ──
// MIGRATION 0012 SYNC FIX: dropped the desk_slug IS NOT NULL gate —
// a car the visitor genuinely viewed (via /cars-for-sale/car/{slug}/,
// the platform-attributed route) shouldn't disappear from their own
// activity feed just because no broker has added it to a desk yet.
try {
    $recentStmt = $pdo->prepare("
        SELECT
            c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
            c.mileage, c.image_urls, c.condition_type, c.fuel_type,
            d.company_name AS dealer_name,
            first_desk.desk_slug
        FROM car_views cv
        JOIN cars c    ON c.id = cv.car_id
        JOIN dealers d ON d.id = c.dealer_id
        LEFT JOIN (
            SELECT bi2.car_id, sd2.slug AS desk_slug
            FROM broker_inventory bi2
            JOIN salesdesks sd2 ON sd2.id = bi2.salesdesk_id
            WHERE bi2.added_at = (
                SELECT MIN(bi3.added_at)
                FROM broker_inventory bi3
                WHERE bi3.car_id = bi2.car_id
            )
            GROUP BY bi2.car_id
        ) first_desk ON first_desk.car_id = c.id
        WHERE cv.visitor_session_id = ?
          AND c.status = 'active'
          AND d.is_active = 1
        GROUP BY c.id
        ORDER BY MAX(cv.viewed_at) DESC
        LIMIT 8
    ");
    $recentStmt->execute([$visitor['id']]);
    $recentlyViewed = $recentStmt->fetchAll();
} catch (Throwable) {
    $recentlyViewed = [];
}

// ── Live: Wishlist ───────────────────────────────────────────────
// MIGRATION 0012 SYNC FIX: same as Recently Viewed above — dropped
// the desk_slug IS NOT NULL gate so a wishlisted platform-attributed
// car doesn't silently vanish from the visitor's own wishlist tab.
try {
    $wishlistIds  = getWishlistCarIds($visitor['id']);
    $wishlistCars = [];

    if ($wishlistIds) {
        $placeholders = implode(',', array_fill(0, count($wishlistIds), '?'));
        $wishStmt = $pdo->prepare("
            SELECT
                c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
                c.mileage, c.image_urls, c.condition_type, c.fuel_type,
                d.company_name AS dealer_name,
                first_desk.desk_slug
            FROM cars c
            JOIN dealers d ON d.id = c.dealer_id
            LEFT JOIN (
                SELECT bi2.car_id, sd2.slug AS desk_slug
                FROM broker_inventory bi2
                JOIN salesdesks sd2 ON sd2.id = bi2.salesdesk_id
                WHERE bi2.added_at = (
                    SELECT MIN(bi3.added_at)
                    FROM broker_inventory bi3
                    WHERE bi3.car_id = bi2.car_id
                )
                GROUP BY bi2.car_id
            ) first_desk ON first_desk.car_id = c.id
            WHERE c.id IN ($placeholders)
              AND c.status = 'active'
              AND d.is_active = 1
        ");
        $wishStmt->execute($wishlistIds);
        $wishlistCars = $wishStmt->fetchAll();

        $order = array_flip($wishlistIds);
        usort($wishlistCars, function ($a, $b) use ($order) {
            return ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX);
        });
    }
} catch (Throwable) {
    $wishlistCars = [];
}

// ── Live: inventory facets for the hero search + tiles (HOME-3) ──
// Same base filter as $totalCars (status=active + dealer active) so
// every number shown matches what /cars-for-sale/ renders on click.
$facetBase = "FROM cars c JOIN dealers d ON d.id = c.dealer_id WHERE c.status = 'active' AND d.is_active = 1";
try {
    $makeCounts = $pdo->query("SELECT c.make, COUNT(*) AS cnt {$facetBase} GROUP BY c.make ORDER BY c.make")
                      ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable) { $makeCounts = []; }
try {
    $conditionCounts = $pdo->query("SELECT c.condition_type, COUNT(*) {$facetBase} GROUP BY c.condition_type")
                           ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable) { $conditionCounts = []; }
try {
    $bodyCounts = $pdo->query("SELECT c.body_type, COUNT(*) {$facetBase} AND c.body_type IS NOT NULL GROUP BY c.body_type")
                      ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable) { $bodyCounts = []; }
try {
    $verifiedDealers = (int) $pdo->query("SELECT COUNT(*) FROM dealers WHERE is_active = 1 AND verification_status = 'verified'")->fetchColumn();
} catch (Throwable) { $verifiedDealers = 0; }

// ── Live: Saved Searches ────────────────────────────────────────
try {
    $savedStmt = $pdo->prepare("
        SELECT id, label, query_string, created_at
        FROM saved_searches
        WHERE visitor_session_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $savedStmt->execute([$visitor['id']]);
    $savedSearches = $savedStmt->fetchAll();
} catch (Throwable) {
    $savedSearches = [];
}

// ── Province display data ──────────────────────────────────────
$provinces = [
    'Gauteng'       => 'Johannesburg · Pretoria',
    'Western Cape'  => 'Cape Town · Stellenbosch',
    'KwaZulu-Natal' => 'Durban · Pietermaritzburg',
    'Eastern Cape'  => 'Gqeberha · East London',
    'Limpopo'       => 'Polokwane · Tzaneen',
    'Mpumalanga'    => 'Mbombela · Witbank',
    'North West'    => 'Rustenburg · Mahikeng',
    'Free State'    => 'Bloemfontein · Welkom',
    'Northern Cape' => 'Kimberley · Upington',
];

// ── Body-type tiles (label => filter value) ───────────────────
$bodyTiles = [
    'SUV'         => 'SUVs & 4×4s',
    'Bakkie'      => 'Bakkies',
    'Hatchback'   => 'Hatchbacks',
    'Sedan'       => 'Sedans',
    'Crossover'   => 'Crossovers',
    'MPV'         => 'Family & MPV',
    'Coupe'       => 'Coupes',
    'Station Wagon' => 'Wagons',
];

// ── Budget tiles ──────────────────────────────────────────────
$budgetTiles = [
    ['Under R150k',   null,    150000, 'First car, city runabout'],
    ['R150k – R300k', 150000,  300000, 'Hatchbacks & compact SUVs'],
    ['R300k – R500k', 300000,  500000, 'Family SUVs & bakkies'],
    ['R500k – R800k', 500000,  800000, 'Premium & double-cab'],
    ['R800k +',       800000,  null,   'Luxury & performance'],
];

require_once __DIR__ . '/views/partials/vehicle-card.php';
require_once __DIR__ . '/views/partials/body-type-icon.php';

$wishIds     = array_map('intval', $wishlistIds ?? []);
$hasActivity = !empty($recentlyViewed) || !empty($wishlistCars) || !empty($savedSearches);
$fmtTotal    = number_format($totalCars);

// ── Page meta ──────────────────────────────────────────────────
$siteUrl       = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$pageTitle     = 'New & Used Cars for Sale in South Africa | ' . $fmtTotal . ' Listings | SalesDesk';
$ogTitle       = 'Browse ' . $fmtTotal . ' New & Used Cars Across South Africa | SalesDesk';
$ogDescription = 'Browse ' . $fmtTotal . ' new and used cars for sale from verified dealers across South Africa. '
               . 'Compare prices, mileage, specs and finance — then deal with a broker who is paid to help.';
$canonicalUrl  = $siteUrl . '/';
$ogImage       = $siteUrl . '/assets/img/hero.jpg';
$layoutVariant  = 'wide';
$showBreadcrumb = false;
$shareUrl       = $canonicalUrl;
$shareTitle     = $ogTitle;
$hideNavSearch  = true;          // the hero search owns this job
$includeBrowseCss     = false;
$includeHowItWorksCss = false;

$assetVersion = $assetVersion ?? date('Ymd');
$extraCss     = '<link rel="stylesheet" href="/assets/css/hero-search.css?v=' . $assetVersion . '">' . "\n"
              . '<link rel="stylesheet" href="/assets/css/desks.css?v=' . $assetVersion . '">' . "\n"
              . '<link rel="stylesheet" href="/assets/css/home.css?v=' . $assetVersion . '">' . "\n";
$extraJs      = ['/assets/js/home.js'];

ob_start();
?>

<!-- ════════════════════════════════════════════════
     HERO
     ════════════════════════════════════════════════ -->
<section class="home-hero" aria-labelledby="homeHeroTitle">
  <div class="home-hero__glow" aria-hidden="true"></div>
  <div class="sd-container home-hero__inner">

    <div class="home-hero__copy pub-anim">
      <span class="home-hero__live">
        <span class="home-hero__pulse" aria-hidden="true"></span>
        <?= $totalCars > 0 ? $fmtTotal . ' cars live right now' : 'Live listings' ?>
      </span>

      <h1 class="home-hero__title" id="homeHeroTitle">
        New &amp; used cars for sale in <span class="home-hero__accent">South&nbsp;Africa</span>
      </h1>

      <p class="home-hero__sub">
        Salesdesk is a premium commerce community where people can discover, 
        meet, and shop the best car brands across South Africa.
      </p>

      <div class="home-hero__popular">
        <span class="home-hero__popular-label">Popular:</span>
        <a class="pub-chip pub-chip--on-ink" href="/cars-for-sale/?body_type[]=Bakkie">Bakkies</a>
        <a class="pub-chip pub-chip--on-ink" href="/cars-for-sale/?price_max=200000">Under R200k</a>
        <a class="pub-chip pub-chip--on-ink" href="/cars-for-sale/?body_type[]=SUV">SUVs</a>
        <a class="pub-chip pub-chip--on-ink" href="/cars-for-sale/?q=Hilux">Toyota Hilux</a>
        <a class="pub-chip pub-chip--on-ink" href="/cars-for-sale/?transmission[]=Automatic">Automatic</a>
      </div>

      <ul class="home-hero__trust">
        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          <span><strong><?= $verifiedDealers > 0 ? number_format($verifiedDealers) . ' verified' : 'Verified' ?></strong> dealerships</span></li>
        <li><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          <span><strong>POPIA</strong> compliant enquiries</span></li>
        <!--<li><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i>
          <span><strong>No fees</strong> for buyers</span></li>-->
      </ul>
    </div>

    <div class="home-hero__search pub-anim pub-d2">
      <?php include __DIR__ . '/views/partials/hero-search-widget.php'; ?>
    </div>

  </div>
</section>

<!-- ════════════════════════════════════════════════
     BODY TYPES
     ════════════════════════════════════════════════ -->
<section class="pub-section pub-section--tight" aria-labelledby="homeBodyTitle">
  <div class="sd-container">
    <div class="pub-section__head">
      <div>
        <h2 class="pub-section__title pub-section__title--sm" id="homeBodyTitle">Browse by body type</h2>
      </div>
      <a href="/cars-for-sale/" class="pub-link pub-section__link">All <?= $fmtTotal ?> cars <i class="fa-solid fa-arrow-right"></i></a>
    </div>

    <div class="home-bodies">
      <?php foreach ($bodyTiles as $val => $label):
        $cnt = (int) ($bodyCounts[$val] ?? 0); ?>
      <a class="home-body" href="/cars-for-sale/?body_type[]=<?= urlencode($val) ?>">
        <?= sdBodyTypeIcon($val, 'home-body__icon') ?>
        <span class="home-body__label"><?= htmlspecialchars($label) ?></span>
        <span class="home-body__count"><?= $cnt > 0 ? number_format($cnt) . ' car' . ($cnt === 1 ? '' : 's') : 'Browse' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($hasActivity): ?>
<!-- ════════════════════════════════════════════════
     CONTINUE BROWSING (only when the visitor has activity)
     ════════════════════════════════════════════════ -->
<section class="pub-section pub-section--tight" aria-labelledby="homeActivityTitle">
  <div class="sd-container">
    <div class="pub-section__head">
      <div>
        <span class="pub-eyebrow"><i class="fa-solid fa-clock-rotate-left"></i> Your activity</span>
        <h2 class="pub-section__title pub-section__title--sm" id="homeActivityTitle">Pick up where you left off</h2>
      </div>
    </div>

    <div class="home-tabs" data-tabs>
      <div class="home-tabs__list" role="tablist" aria-label="Your activity">
        <?php
        $actTabs = array_filter([
            'recent'   => !empty($recentlyViewed) ? ['Recently viewed', count($recentlyViewed)] : null,
            'wishlist' => !empty($wishlistCars)   ? ['Saved cars', count($wishlistCars)]        : null,
            'saved'    => !empty($savedSearches)  ? ['Saved searches', count($savedSearches)]   : null,
        ]);
        $firstTab = array_key_first($actTabs);
        foreach ($actTabs as $key => [$label, $cnt]): ?>
        <button class="home-tabs__tab" type="button" role="tab" id="tab-btn-<?= $key ?>"
                aria-controls="tab-<?= $key ?>" aria-selected="<?= $key === $firstTab ? 'true' : 'false' ?>"
                tabindex="<?= $key === $firstTab ? '0' : '-1' ?>">
          <?= $label ?> <span class="home-tabs__count"><?= $cnt ?></span>
        </button>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($recentlyViewed)): ?>
      <div class="home-tabs__panel" role="tabpanel" id="tab-recent" aria-labelledby="tab-btn-recent" <?= $firstTab !== 'recent' ? 'hidden' : '' ?>>
        <div class="pub-rail home-rail">
          <?php foreach ($recentlyViewed as $car) echo sdVehicleCard($car, ['variant' => 'compact', 'wishlisted' => in_array((int) $car['id'], $wishIds, true)]); ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($wishlistCars)): ?>
      <div class="home-tabs__panel" role="tabpanel" id="tab-wishlist" aria-labelledby="tab-btn-wishlist" <?= $firstTab !== 'wishlist' ? 'hidden' : '' ?>>
        <div class="pub-rail home-rail">
          <?php foreach ($wishlistCars as $car) echo sdVehicleCard($car, ['variant' => 'compact', 'wishlisted' => true]); ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($savedSearches)): ?>
      <div class="home-tabs__panel" role="tabpanel" id="tab-saved" aria-labelledby="tab-btn-saved" <?= $firstTab !== 'saved' ? 'hidden' : '' ?>>
        <div class="home-saved">
          <?php foreach ($savedSearches as $search): ?>
          <a href="/cars-for-sale/?<?= htmlspecialchars($search['query_string'], ENT_QUOTES) ?>" class="home-saved__item">
            <span class="home-saved__icon"><i class="fa-regular fa-bookmark"></i></span>
            <span class="home-saved__text">
              <span class="home-saved__label"><?= htmlspecialchars($search['label']) ?></span>
              <span class="home-saved__meta">Saved <?= htmlspecialchars(date('j M Y', strtotime($search['created_at']))) ?></span>
            </span>
            <i class="fa-solid fa-arrow-right home-saved__arrow" aria-hidden="true"></i>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     LATEST LISTINGS
     ════════════════════════════════════════════════ -->
<?php if (!empty($featuredCars)): ?>
<section class="pub-section" aria-labelledby="homeLatestTitle">
  <div class="sd-container">
    <div class="pub-section__head">
      <div>
        <span class="pub-eyebrow">Fresh stock</span>
        <h2 class="pub-section__title" id="homeLatestTitle">Just listed</h2>
        <p class="pub-section__sub">The newest cars from verified dealerships around the country.</p>
      </div>
      <a href="/cars-for-sale/" class="pub-btn pub-btn-ghost pub-section__link">
        View all <?= $fmtTotal ?> cars <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

    <div class="vc-grid vc-grid--4 vc-grid--teaser">
      <?php foreach ($featuredCars as $i => $car) {
          echo sdVehicleCard($car, [
              'wishlisted' => in_array((int) $car['id'], $wishIds, true),
              'eager'      => $i < 4,
          ]);
      } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     BUDGET + AFFORDABILITY
     ════════════════════════════════════════════════ -->
<section class="pub-section" aria-labelledby="homeBudgetTitle">
  <div class="sd-container">
    <div class="home-budget">

      <div class="home-afford pub-reveal">
        <span class="pub-eyebrow">Affordability</span>
        <h2 class="pub-section__title" id="homeBudgetTitle">What can I afford?</h2>
        <p class="home-afford__sub">Slide to your comfortable monthly repayment. We’ll work out the car price and show you what fits.</p>

        <div class="fin" data-finance="afford">
          <div class="fin__row">
            <div class="fin__row-head"><label for="affBudget">Monthly budget</label><output data-fin-budget-out>R 6 000 p/m</output></div>
            <input type="range" id="affBudget" min="2000" max="30000" step="500" value="6000" data-fin-budget>
          </div>
          <div class="fin__grid">
            <div class="fin__row">
              <div class="fin__row-head"><label for="affDeposit">Deposit</label><output data-fin-deposit-out>10%</output></div>
              <input type="range" id="affDeposit" min="0" max="40" step="5" value="10" data-fin-deposit>
            </div>
            <div class="fin__row">
              <div class="fin__row-head"><label for="affRate">Interest rate</label><output data-fin-rate-out>13.25%</output></div>
              <input type="range" id="affRate" min="8" max="22" step="0.25" value="13.25" data-fin-rate>
            </div>
          </div>
          <div class="fin__row">
            <div class="fin__row-head"><span id="affTermLbl">Term</span></div>
            <div class="pub-segment" role="radiogroup" aria-labelledby="affTermLbl">
              <?php foreach ([48, 60, 72] as $t): ?>
              <label class="pub-segment__opt"><input type="radio" name="aff_term" value="<?= $t ?>" data-fin-term <?= $t === 72 ? 'checked' : '' ?>><span><?= $t ?> months</span></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="home-afford__result">
            <div>
              <div class="fin__label">You could look at cars up to</div>
              <div class="fin__monthly" data-fin-result>R 0</div>
            </div>
            <a class="pub-btn pub-btn-primary" href="/cars-for-sale/" data-fin-link>
              <span>Show cars</span> <i class="fa-solid fa-arrow-right"></i>
            </a>
          </div>
          <p class="fin__note">Estimate only, at the rate and term selected. Your bank decides the final approval and rate.</p>
        </div>
      </div>

      <div class="home-budget__tiles">
        <h3 class="home-budget__title">Shop by budget</h3>
        <?php foreach ($budgetTiles as [$label, $min, $max, $hint]):
          $qs = http_build_query(array_filter(['price_min' => $min, 'price_max' => $max], fn($v) => $v !== null)); ?>
        <a class="home-budget__tile pub-reveal" href="/cars-for-sale/?<?= $qs ?>">
          <span>
            <span class="home-budget__label"><?= htmlspecialchars($label) ?></span>
            <span class="home-budget__hint"><?= htmlspecialchars($hint) ?></span>
          </span>
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     CONDITION
     ════════════════════════════════════════════════ -->
<section class="pub-section" aria-labelledby="homeCondTitle">
  <div class="sd-container">
    <div class="pub-section__head">
      <div>
        <span class="pub-eyebrow">New, used or demo</span>
        <h2 class="pub-section__title" id="homeCondTitle">Pick the right kind of deal</h2>
      </div>
    </div>
    <div class="home-conds">
      <?php
      $condCards = [
          'used' => ['Pre-owned', 'The widest choice and the best value. Every listing comes from a verified dealership.', 'fa-car'],
          'new'  => ['Brand new', 'Latest models with full manufacturer warranty and service plans.', 'fa-star'],
          'demo' => ['Demo',      'Nearly new, low mileage and still under warranty — at a used-car price.', 'fa-gauge-simple-high'],
      ];
      foreach ($condCards as $val => [$title, $desc, $icon]):
        $cnt = (int) ($conditionCounts[$val] ?? 0); ?>
      <a class="home-cond home-cond--<?= $val ?> pub-reveal" href="/cars-for-sale/?condition=<?= $val ?>">
        <span class="home-cond__icon"><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i></span>
        <span class="home-cond__count"><?= $cnt > 0 ? number_format($cnt) . ' available' : 'Browse' ?></span>
        <span class="home-cond__title"><?= $title ?> cars</span>
        <span class="home-cond__desc"><?= $desc ?></span>
        <span class="home-cond__cta">Shop <?= strtolower($title) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     PROVINCES
     ════════════════════════════════════════════════ -->
<section class="pub-section" aria-labelledby="homeProvTitle">
  <div class="sd-container">
    <div class="pub-section__head">
      <div>
        <span class="pub-eyebrow">Near you</span>
        <h2 class="pub-section__title" id="homeProvTitle">Cars for sale by province</h2>
      </div>
    </div>
    <div class="home-provs">
      <?php foreach ($provinces as $provName => $cities):
        $cnt = (int) ($provCounts[$provName] ?? 0); ?>
      <a href="/cars-for-sale/?province=<?= urlencode($provName) ?>" class="home-prov">
        <span class="home-prov__text">
          <span class="home-prov__name"><?= htmlspecialchars($provName) ?></span>
          <span class="home-prov__cities"><?= htmlspecialchars($cities) ?></span>
        </span>
        <span class="home-prov__count"><?= $cnt > 0 ? number_format($cnt) : '—' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if (!empty($topOrgs)): ?>
<!-- ════════════════════════════════════════════════
     DESK ORGANISATIONS (DESK-ORG-1 — shared sdOrgCard + desks.css)
     ════════════════════════════════════════════════ -->
<section class="pub-section" aria-labelledby="homeOrgsTitle">
  <div class="sd-container">
    <div class="pub-section__head">
      <div>
        <span class="pub-eyebrow">Desk organisations</span>
        <h2 class="pub-section__title" id="homeOrgsTitle">Shop by brand network</h2>
      </div>
      <a href="/desks/" class="pub-link pub-section__link">All organisations <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="dk-grid">
      <?php foreach ($topOrgs as $org): ?>
      <?= sdOrgCard($org, $orgPreviews[(int) $org['id']] ?? [], ['heading' => 'h3']) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     TRADE CTA
     ════════════════════════════════════════════════ -->
<section class="pub-section" aria-label="Work with SalesDesk">
  <div class="sd-container">
    <div class="home-trade">
      <a class="home-trade__card home-trade__card--broker" href="/how-it-works/brokers">
        <span class="pub-eyebrow">For brokers &amp; side-hustlers</span>
        <span class="home-trade__title">Earn commission selling cars. No stock, no showroom.</span>
        <span class="home-trade__text">Pick cars from verified dealers, share your link, and get paid when your buyer drives away. Attribution is tracked for you.</span>
        <span class="pub-btn pub-btn-accent">Create your SalesDesk <i class="fa-solid fa-arrow-right"></i></span>
      </a>
      <a class="home-trade__card home-trade__card--dealer" href="/how-it-works/dealers">
        <span class="pub-eyebrow">For dealerships</span>
        <span class="home-trade__title">Put your stock in front of a national sales force.</span>
        <span class="home-trade__text">Upload inventory once. Brokers and sales executives market it for you — you only pay on results.</span>
        <span class="pub-btn pub-btn-dark">List your dealership <i class="fa-solid fa-arrow-right"></i></span>
      </a>
    </div>
  </div>
</section>

<?php
$pageContent = ob_get_clean();
require_once 'views/layout-public.php';
