<?php
/**
 * SalesDesk — Homepage
 * Route: /   (web root)
 *
 * Wired into layout-public.php (nav, footer, visitor tracking).
 * Live car counts pulled from DB; sample vehicle cards use real
 * broker_inventory + cars query (limited to 8, newest first, 4x2 grid).
 * Province counts pulled live. Newsletter wired in next session.
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
 *   - Car News & Reviews section now pulled live from blog_posts
 *     (was a hardcoded 3-item array — see NEWS-1 below).
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
 * other page audited in this codebase (c/index.php, app/dealer/*, etc.)
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
try {
    $totalCars = (int) $pdo->query("
        SELECT COUNT(DISTINCT c.id)
        FROM cars c
        JOIN broker_inventory bi ON bi.car_id = c.id
        WHERE c.status = 'active'
    ")->fetchColumn();
} catch (Throwable) {
    $totalCars = 0;
}

// ── Live: province listing counts ─────────────────────────────
try {
    $provStmt = $pdo->query("
        SELECT a.province, COUNT(DISTINCT c.id) AS cnt
        FROM cars c
        JOIN dealers d           ON d.id  = c.dealer_id
        JOIN addresses a         ON a.id  = d.address_id
        JOIN broker_inventory bi ON bi.car_id = c.id
        WHERE c.status = 'active'
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

// ── Live: featured cars (newest 8 on any active desk) ─────────
try {
    $featuredStmt = $pdo->prepare("
        SELECT
            c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
            c.mileage, c.condition_type, c.body_type, c.fuel_type,
            c.transmission, c.drivetrain, c.image_urls,
            d.verification_status AS dealer_verified,
            d.company_name        AS dealer_name,
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
          AND first_desk.desk_slug IS NOT NULL
        ORDER BY c.created_at DESC
        LIMIT 8
    ");
    $featuredStmt->execute();
    $featuredCars = $featuredStmt->fetchAll();
} catch (Throwable) {
    $featuredCars = [];
}

// ── Live: top SalesDesks (most leads this month) ───────────────
try {
    $topDesksStmt = $pdo->prepare("
        SELECT
            sd.id, sd.slug, sd.display_name, sd.logo_url,
            p.first_name, p.last_name, p.avatar_url,
            a.city, a.province,
            (SELECT COUNT(*) FROM broker_inventory bi2
             WHERE bi2.salesdesk_id = sd.id
               AND EXISTS (SELECT 1 FROM cars c2
                           WHERE c2.id = bi2.car_id AND c2.status = 'active')
            ) AS active_listings,
            (SELECT COUNT(*) FROM leads l
             WHERE l.salesdesk_id = sd.id
               AND l.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            ) AS leads_this_month,
            (SELECT COUNT(*) FROM leads l2
             WHERE l2.salesdesk_id = sd.id AND l2.status = 'closed'
            ) AS deals_closed
        FROM salesdesks sd
        JOIN users u ON u.id = sd.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        LEFT JOIN addresses a ON a.id = p.address_id
        WHERE sd.is_active = 1
        ORDER BY leads_this_month DESC, active_listings DESC
        LIMIT 3
    ");
    $topDesksStmt->execute();
    $topDesks = $topDesksStmt->fetchAll();
} catch (Throwable) {
    $topDesks = [];
}

// ── Live: Recently Viewed (last 8 distinct cars for this visitor) ──
try {
    $recentStmt = $pdo->prepare("
        SELECT
            c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
            c.mileage, c.image_urls,
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
          AND first_desk.desk_slug IS NOT NULL
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
try {
    $wishlistIds  = getWishlistCarIds($visitor['id']);
    $wishlistCars = [];

    if ($wishlistIds) {
        $placeholders = implode(',', array_fill(0, count($wishlistIds), '?'));
        $wishStmt = $pdo->prepare("
            SELECT
                c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
                c.mileage, c.image_urls,
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
              AND first_desk.desk_slug IS NOT NULL
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

// ── Live: Latest car news & reviews (was a hardcoded array) ────
// NEWS-1: pulls the 3 most recently published blog posts, same
// source table /news/ uses (blog_posts + blog_categories), so the
// homepage teaser and the real article always agree.
try {
    $homeNewsStmt = $pdo->prepare("
        SELECT
            p.slug, p.title, p.excerpt, p.content,
            p.featured_image_url, p.published_at,
            c.name AS category_name
        FROM blog_posts p
        LEFT JOIN blog_categories c ON c.id = p.category_id
        WHERE p.status = 'published'
          AND p.published_at <= NOW()
        ORDER BY p.published_at DESC
        LIMIT 3
    ");
    $homeNewsStmt->execute();
    $latestNews = $homeNewsStmt->fetchAll();
} catch (Throwable) {
    $latestNews = [];
}

// ── Province display data ──────────────────────────────────────
$provinces = [
    'Gauteng'       => ['abbr' => 'GP', 'icon' => 'fa-city'],
    'Western Cape'  => ['abbr' => 'WC', 'icon' => 'fa-water'],
    'KwaZulu-Natal' => ['abbr' => 'KZN','icon' => 'fa-umbrella-beach'],
    'Eastern Cape'  => ['abbr' => 'EC', 'icon' => 'fa-mountain'],
    'Limpopo'       => ['abbr' => 'LP', 'icon' => 'fa-tree'],
    'Mpumalanga'    => ['abbr' => 'MP', 'icon' => 'fa-cloud-sun'],
    'North West'    => ['abbr' => 'NW', 'icon' => 'fa-diamond'],
    'Free State'    => ['abbr' => 'FS', 'icon' => 'fa-wheat-awn'],
    'Northern Cape' => ['abbr' => 'NC', 'icon' => 'fa-sun'],
];

// ── Prov abbreviation helper ───────────────────────────────────
$provAbbr = [
    'Gauteng'       => 'GP',  'Western Cape'  => 'WC',  'KwaZulu-Natal' => 'KZN',
    'Eastern Cape'  => 'EC',  'Limpopo'       => 'LP',  'Mpumalanga'    => 'MP',
    'North West'    => 'NW',  'Free State'    => 'FS',  'Northern Cape' => 'NC',
];

// ── Fuel icon helper ───────────────────────────────────────────
/**
 * BUG FIX: this used an exact match() against strtolower($fuel), but the
 * real fuel_type values written by app/dealer/car-upload.php's wizard
 * carry parenthetical suffixes — 'Plug-in Hybrid (PHEV)', 'LPG (Autogas)',
 * 'CNG (Natural Gas)', 'Flex Fuel (E85/Ethanol)' — none of which equal
 * the old bare 'plug-in hybrid' case, so PHEV cars silently fell through
 * to the generic pump icon instead of the intended leaf icon. Switched to
 * str_contains() substring matching, same approach used in
 * CsvImporter::normalizeFuelType(), so every real value classifies
 * correctly regardless of the parenthetical suffix.
 */
function homeFuelIcon(string $fuel): string {
    $v = strtolower($fuel);
    return match (true) {
        str_contains($v, 'electric')            => 'fa-bolt',
        str_contains($v, 'hybrid')               => 'fa-leaf',
        str_contains($v, 'hydrogen')              => 'fa-droplet',
        str_contains($v, 'diesel')                => 'fa-oil-can',
        str_contains($v, 'lpg') || str_contains($v, 'cng') || str_contains($v, 'autogas') || str_contains($v, 'natural gas')
                                                   => 'fa-fire-flame-simple',
        default                                   => 'fa-gas-pump',
    };
}

// ── News read-time helper (NEWS-1) ──────────────────────────────
// Mirrors news/index.php + news/article.php's articleReadTime() /
// newsReadTime() so the estimate is consistent site-wide.
function homeNewsReadTime(string $content): string {
    $words = str_word_count(strip_tags($content));
    return max(1, (int) ceil($words / 220)) . ' min read';
}

// ── News thumbnail fallback (NEWS-1) ────────────────────────────
// Same Unsplash fallback used by news/index.php's newsThumb().
function homeNewsThumb(array $post): string {
    return $post['featured_image_url']
        ?: 'https://images.unsplash.com/photo-1494976388531-d1058494cdd8?q=80&w=1200&auto=format&fit=crop';
}

// ── Desk avatar initials ───────────────────────────────────────
function deskInitials(array $desk): string {
    $first = $desk['first_name'] ?? '';
    $last  = $desk['last_name']  ?? '';
    $init  = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    return $init ?: strtoupper(substr($desk['display_name'], 0, 2));
}

// ── Gradient pool for desk avatars ────────────────────────────
$gradients = [
    'linear-gradient(135deg,#3b82f6,#1d4ed8)',
    'linear-gradient(135deg,#8b5cf6,#6d28d9)',
    'linear-gradient(135deg,#10b981,#047857)',
    'linear-gradient(135deg,#f59e0b,#b45309)',
    'linear-gradient(135deg,#ef4444,#b91c1c)',
];

/**
 * Render a single activity-tab vehicle card (Recently Viewed / Wishlist).
 */
function renderActivityCard(array $car): string {
    $imgs   = json_decode($car['image_urls'] ?? '[]', true) ?: [];
    $thumb  = $imgs[0] ?? null;
    $carUrl = '/c/' . htmlspecialchars($car['desk_slug']) . '/' . htmlspecialchars($car['car_slug']) . '/';

    ob_start();
    ?>
    <a href="<?= $carUrl ?>" class="sd-vcard">
      <div class="sd-vcard__img-wrap">
        <?php if ($thumb): ?>
        <img class="sd-vcard__img"
             src="<?= htmlspecialchars($thumb) ?>"
             alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
             loading="lazy">
        <?php else: ?>
        <div class="sd-vcard__img-placeholder"><i class="fa-solid fa-car-side"></i></div>
        <?php endif; ?>
        <span class="sd-vcard__year"><?= (int)$car['year'] ?></span>
        <?php if ($car['mileage']): ?>
        <span class="sd-vcard__km">
          <i class="fa-solid fa-road" style="color:#94a3b8;margin-right:3px;"></i>
          <?= number_format((int)$car['mileage']) ?> km
        </span>
        <?php endif; ?>
      </div>
      <div class="sd-vcard__body">
        <div class="sd-vcard__top">
          <div>
            <div class="sd-vcard__name">
              <?= htmlspecialchars("{$car['make']} {$car['model']}") ?>
            </div>
            <div class="sd-vcard__dealer">
              <i class="fa-regular fa-building"></i> <?= htmlspecialchars($car['dealer_name']) ?>
            </div>
          </div>
          <div class="sd-vcard__price">
            R <?= number_format((float)$car['price'], 0, '.', '&nbsp;') ?>
          </div>
        </div>
      </div>
    </a>
    <?php
    return ob_get_clean();
}

// ── Page meta ──────────────────────────────────────────────────
$pageTitle     = 'Browse ' . number_format($totalCars) . ' New & Used Cars for Sale in South Africa | SalesDesk';
$ogTitle       = 'Browse ' . number_format($totalCars) . ' New & Used Cars Across South Africa | SalesDesk';
$ogDescription = 'Browse ' . number_format($totalCars) . ' new and used cars for sale across South Africa. '.
                   'Compare prices, mileage, specs, and finance options all in one place.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/';
$layoutVariant  = 'wide';
$showBreadcrumb = false;
$shareUrl       = $canonicalUrl;
$shareTitle     = 'Browse ' . number_format($totalCars) . ' New & Used Cars for Sale in South Africa | SalesDesk';

/**
 * PERF FIX: home.css defines the hero, search card, shop cards, and
 * vehicle grid — all above-the-fold homepage markup. It used to be
 * <link>'d from inside $pageContent (mid-<body>), which meant the
 * browser painted all of that markup unstyled first, then re-flowed
 * once the parser finally reached the tag and fetched the file.
 *
 * layout-public.php renders $extraCss inside <head>, right alongside
 * global.css / public.css / browse.css (see its own CSS-load-order
 * comment). Using that hook here means home.css is requested in
 * parallel with the rest of the critical CSS, before first paint,
 * instead of being discovered after unstyled content is already on
 * screen. Preloaded too, matching the pattern layout-public.php
 * already uses for global.css / public.css.
 */
$assetVersion = $assetVersion ?? date('Ymd');
$extraCss     = '<link rel="preload" as="style" href="/assets/css/home.css?v=' . $assetVersion . '">' . "\n"
              . '<link rel="stylesheet" href="/assets/css/home.css?v=' . $assetVersion . '">' . "\n";

ob_start();
?>

<!-- ════════════════════════════════════════════════
     HERO
     ════════════════════════════════════════════════ -->
<section class="sd-hero">
  <div class="sd-hero__overlay"></div>
  <div class="sd-hero__content anim-up">

    <h1 class="sd-hero__title">New &amp; Used Cars for Sale</h1>
    <p class="sd-hero__sub">South Africa&rsquo;s Independent Car Marketplace.</p>

    <?php include __DIR__ . '/views/partials/hero-search-widget.php'; ?>

  </div>
</section>

<!-- ════════════════════════════════════════════════
     PICK UP WHERE YOU LEFT OFF
     ════════════════════════════════════════════════ -->
<section class="sd-section ">
  <div class="container">
    <div class="sd-sec-header" style="margin-bottom:20px">
      <div>
        <span class="sd-eyebrow">
          <i class="fa-solid fa-clock-rotate-left" style="margin-right:5px;"></i>Your activity
        </span>
        <h2 class="sd-sec-title">Pick up where you left off</h2>
      </div>
    </div>

    <div class="sd-tab-nav">
      <button class="sd-tab-btn active" data-tab="recent">
        <i class="fa-regular fa-eye" style="margin-right:6px;"></i>Recently Viewed
        <?php if (!empty($recentlyViewed)): ?>
        <span class="sd-tab-count"><?= count($recentlyViewed) ?></span>
        <?php endif; ?>
      </button>
      <button class="sd-tab-btn" data-tab="wishlist">
        <i class="fa-regular fa-heart" style="margin-right:6px;"></i>Wishlist
        <?php if (!empty($wishlistCars)): ?>
        <span class="sd-tab-count"><?= count($wishlistCars) ?></span>
        <?php endif; ?>
      </button>
      <button class="sd-tab-btn" data-tab="saved">
        <i class="fa-regular fa-bookmark" style="margin-right:6px;"></i>Saved Searches
        <?php if (!empty($savedSearches)): ?>
        <span class="sd-tab-count"><?= count($savedSearches) ?></span>
        <?php endif; ?>
      </button>
    </div>

    <?php
    $tabs = [
        'recent'   => [
            'icon'  => 'fa-regular fa-eye-slash',
            'title' => 'No recently viewed vehicles yet',
            'sub'   => 'Start browsing and vehicles you view will appear here automatically.',
            'items' => $recentlyViewed,
            'type'  => 'cars',
        ],
        'wishlist' => [
            'icon'  => 'fa-regular fa-heart',
            'title' => 'Your wishlist is empty',
            'sub'   => 'Tap the heart icon on any vehicle card to save it here.',
            'items' => $wishlistCars,
            'type'  => 'cars',
        ],
        'saved'    => [
            'icon'  => 'fa-regular fa-bookmark',
            'title' => 'No saved searches yet',
            'sub'   => 'Set up filters on the browse page and click "Save this search" to be notified when matching vehicles are listed.',
            'items' => $savedSearches,
            'type'  => 'searches',
        ],
    ];
    foreach ($tabs as $key => $tab):
    ?>
    <div class="sd-tab-panel <?= $key === 'recent' ? 'active' : '' ?>" id="tab-<?= $key ?>">
      <?php if (!empty($tab['items'])): ?>

        <?php if ($tab['type'] === 'cars'): ?>
        <div class="sd-vehicle-grid sd-vehicle-grid--activity">
          <?php foreach ($tab['items'] as $car) echo renderActivityCard($car); ?>
        </div>

        <?php else: /* saved searches */ ?>
        <div class="sd-saved-search-list">
          <?php foreach ($tab['items'] as $search): ?>
          <a href="/c/?<?= htmlspecialchars($search['query_string'], ENT_QUOTES) ?>" class="sd-saved-search-item">
            <span class="sd-saved-search-icon"><i class="fa-regular fa-bookmark"></i></span>
            <span class="sd-saved-search-info">
              <span class="sd-saved-search-label"><?= htmlspecialchars($search['label']) ?></span>
              <span class="sd-saved-search-meta">Saved <?= htmlspecialchars(date('d M Y', strtotime($search['created_at']))) ?></span>
            </span>
            <span class="sd-saved-search-arrow"><i class="fa-solid fa-arrow-right"></i></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php else: ?>
        <div class="sd-pickup-empty">
          <div class="sd-pickup-empty__icon"><i class="<?= $tab['icon'] ?>"></i></div>
          <div class="sd-pickup-empty__title"><?= htmlspecialchars($tab['title']) ?></div>
          <div class="sd-pickup-empty__sub"><?= htmlspecialchars($tab['sub']) ?></div>
          <a href="/c/" class="pub-btn pub-btn-primary" style="font-size:13px;padding:9px 20px;">
            Browse vehicles <i class="fa-solid fa-arrow-right"></i>
          </a>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="sd-pickup-ip-note">
      <!--<i class="fa-solid fa-circle-info"></i>
      Activity is tracked by browser session &mdash; no sign-in required.-->
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     SHOP BY CONDITION
     ════════════════════════════════════════════════ -->
<section class="sd-section">
  <div class="container">
    <div class="sd-sec-header">
      <div>
        <span class="sd-eyebrow">New &amp; Pre-Owned</span>
        <h2 class="sd-sec-title">What kind of car are you after?</h2>
      </div>
    </div>
    <div class="sd-shop-grid">

      <a href="/c/?condition=used" class="sd-shop-card sd-shop-card--used">
        <div class="sd-shop-badge">Pre-Owned Vehicles</div>
        <div>
          <h3 class="sd-shop-title">Used Cars</h3>
          <p class="sd-shop-desc">Browse quality pre-owned vehicles from verified dealerships across South Africa. Compare prices, mileage, specs, and finance options all in one place.</p>
          <div class="sd-shop-actions">
            <span class="sd-shop-btn-primary">
              <i class="fa-solid fa-magnifying-glass"></i> Browse Used Cars
            </span>
          </div>
        </div>
      </a>

      <a href="/c/?condition=new" class="sd-shop-card sd-shop-card--new">
        <div class="sd-shop-badge">Latest Models</div>
        <div>
          <h3 class="sd-shop-title">New Cars</h3>
          <p class="sd-shop-desc">Explore the latest models from dealerships nationwide. Discover new releases, compare features and pricing, and connect directly with dealers.</p>
          <div class="sd-shop-actions">
            <span class="sd-shop-btn-primary">
              <i class="fa-solid fa-car"></i> Explore New Cars
            </span>
          </div>
        </div>
      </a>

    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     BROWSE BY CATEGORY
     ════════════════════════════════════════════════ -->
<section class="sd-section">
  <div class="container">
    <div class="sd-sec-header">
      <div>
        <span class="sd-eyebrow">What are you looking for?</span>
        <h2 class="sd-sec-title">Browse by category</h2>
      </div>
      <a href="/c/" class="sd-sec-link">View all &rarr;</a>
    </div>

    <div class="sd-pill-row">
      <a href="/c/"                        class="sd-pill active"><i class="fa-solid fa-list"></i> All</a>
      <a href="/c/?body_type[]=Bakkie"     class="sd-pill"><i class="fa-solid fa-truck-monster"></i> Bakkies</a>
      <a href="/c/?body_type[]=Hatchback"  class="sd-pill"><i class="fa-solid fa-car"></i> Hatchbacks</a>
      <a href="/c/?body_type[]=Sedan"      class="sd-pill"><i class="fa-solid fa-car-side"></i> Sedans</a>
      <a href="/c/?body_type[]=SUV"        class="sd-pill"><i class="fa-solid fa-truck"></i> SUVs / 4x4</a>
      <a href="/c/?fuel_type[]=Electric"   class="sd-pill"><i class="fa-solid fa-bolt"></i> Electric</a>
      <a href="/c/?price_min=1000000"      class="sd-pill"><i class="fa-solid fa-star"></i> Luxury</a>
      <a href="/c/?price_max=300000"       class="sd-pill"><i class="fa-solid fa-tag"></i> Under R 300k</a>
    </div>

    <?php if (!empty($featuredCars)): ?>
    <div class="sd-vehicle-grid">
      <?php foreach ($featuredCars as $i => $car):
        $imgs    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
        $thumb   = $imgs[0] ?? null;
        $prov    = $provAbbr[$car['dealer_province'] ?? ''] ?? ($car['dealer_province'] ?? '');
        $carUrl  = '/c/' . htmlspecialchars($car['desk_slug']) . '/'
                 . htmlspecialchars($car['car_slug']) . '/';
        $isEV    = strtolower($car['fuel_type'] ?? '') === 'electric';
      ?>
      <a href="<?= $carUrl ?>" class="sd-vcard pub-reveal" style="animation-delay:<?= ($i % 4) * 0.1 ?>s">
        <div class="sd-vcard__img-wrap">
          <?php if ($thumb): ?>
          <img class="sd-vcard__img"
               src="<?= htmlspecialchars($thumb) ?>"
               alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
               loading="lazy">
          <?php else: ?>
          <div class="sd-vcard__img-placeholder"><i class="fa-solid fa-car-side"></i></div>
          <?php endif; ?>
          <span class="sd-vcard__year"><?= (int)$car['year'] ?></span>
          <?php if ($prov): ?>
          <span class="sd-vcard__prov"><?= htmlspecialchars($prov) ?></span>
          <?php endif; ?>
          <?php if ($car['mileage']): ?>
          <span class="sd-vcard__km">
            <i class="fa-solid fa-road" style="color:#94a3b8;margin-right:3px;"></i>
            <?= number_format((int)$car['mileage']) ?> km
          </span>
          <?php endif; ?>
          <?php if ($isEV): ?>
          <span class="sd-vcard__ev">EV</span>
          <?php endif; ?>
        </div>
        <div class="sd-vcard__body">
          <div class="sd-vcard__top">
            <div>
              <div class="sd-vcard__name">
                <?= htmlspecialchars("{$car['make']} {$car['model']}") ?>
              </div>
              <div class="sd-vcard__dealer">
                <i class="fa-regular fa-building"></i> <?= htmlspecialchars($car['dealer_name']) ?>
              </div>
            </div>
            <div class="sd-vcard__price">
              R <?= number_format((float)$car['price'], 0, '.', '&nbsp;') ?>
            </div>
          </div>
          <div class="sd-vcard__desk-row">
            <span class="sd-badge-desk">
              <i class="fa-solid fa-id-card"></i>
              <?= htmlspecialchars($car['desk_name']) ?>
            </span>
            <?php if ($car['dealer_verified'] === 'verified'): ?>
            <span class="sd-badge-v" style="margin-left:6px;">
              <i class="fa-solid fa-circle-check"></i> Verified
            </span>
            <?php endif; ?>
          </div>
          <div class="sd-vcard__specs">
            <?php if ($car['fuel_type']): ?>
            <span class="sd-spec">
              <i class="fa-solid <?= homeFuelIcon($car['fuel_type']) ?>"></i>
              <?= htmlspecialchars($car['fuel_type']) ?>
            </span>
            <?php endif; ?>
            <?php if ($car['transmission']): ?>
            <span class="sd-spec"><?= htmlspecialchars($car['transmission']) ?></span>
            <?php endif; ?>
            <?php if ($car['drivetrain']): ?>
            <span class="sd-spec"><?= htmlspecialchars($car['drivetrain']) ?></span>
            <?php endif; ?>
            <?php if ($car['body_type']): ?>
            <span class="sd-spec"><?= htmlspecialchars($car['body_type']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="sd-view-all">
      <a href="/c/" class="pub-btn pub-btn-ghost">
        Browse all <?= $totalCars > 0 ? number_format($totalCars) . ' vehicles' : 'vehicles' ?>
        <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     BROWSE BY PROVINCE
     ════════════════════════════════════════════════ -->
<section class="sd-section">
  <div class="container">
    <div class="sd-sec-header">
      <div>
        <span class="sd-eyebrow">South Africa</span>
        <h2 class="sd-sec-title">Browse by province</h2>
      </div>
    </div>
    <div class="sd-prov-grid">
      <?php foreach ($provinces as $provName => $meta):
        $cnt = $provCounts[$provName] ?? 0;
      ?>
      <a href="/c/?province=<?= urlencode($provName) ?>" class="sd-prov-chip pub-reveal">
        <i class="fa-solid <?= $meta['icon'] ?>"></i>
        <span class="sd-prov-chip__label"><?= htmlspecialchars($provName) ?></span>
        <span class="sd-prov-chip__n">
          <?= $cnt > 0 ? number_format($cnt) . ' car' . ($cnt !== 1 ? 's' : '') : 'Browse' ?>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     TOOLS
     ════════════════════════════════════════════════ -->
<section class="sd-section ">
  <div class="container">
    <div class="sd-sec-header">
      <div>
        <span class="sd-eyebrow">Resources</span>
        <h2 class="sd-sec-title">Tools &amp; services</h2>
      </div>
    </div>
    <div class="sd-tools-grid">

      <div class="sd-tool-card">
        <div class="sd-tool-header">
          <div class="sd-tool-icon-wrap">
            <svg width="22" height="22" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" stroke="#fff">
              <path d="M3 13l2-5a2 2 0 0 1 2-1h10a2 2 0 0 1 2 1l2 5"/>
              <path d="M5 13h14M5 13v4M19 13v4"/>
              <circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/>
            </svg>
          </div>
          <h3 class="sd-tool-title">Compare Cars</h3>
        </div>
        <p class="sd-tool-desc">Compare key specifications and pricing side-by-side — finding your perfect match has never been easier.</p>
        <div class="sd-tool-btn-group">
          <a href="/tools/compare/" class="sd-tool-btn">
            <i class="fa-solid fa-arrow-right-arrow-left"></i> Compare Used Cars
          </a>
          <!--<a href="/c/?condition=new" class="sd-tool-btn">
            <i class="fa-solid fa-car"></i> Compare New Cars
          </a>-->
        </div>
      </div>

      <div class="sd-tool-card">
        <div class="sd-tool-header">
          <div class="sd-tool-icon-wrap">
            <svg width="22" height="22" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" stroke="#fff">
              <rect x="5" y="3" width="14" height="18" rx="2"/>
              <line x1="8" y1="7" x2="16" y2="7"/>
              <line x1="8" y1="11" x2="8" y2="11" stroke-width="3"/>
              <line x1="12" y1="11" x2="12" y2="11" stroke-width="3"/>
              <line x1="16" y1="11" x2="16" y2="11" stroke-width="3"/>
              <line x1="8" y1="15" x2="8" y2="15" stroke-width="3"/>
              <line x1="12" y1="15" x2="12" y2="15" stroke-width="3"/>
              <line x1="16" y1="15" x2="16" y2="15" stroke-width="3"/>
            </svg>
          </div>
          <h3 class="sd-tool-title">Affordability Calculator</h3>
        </div>
        <p class="sd-tool-desc">Calculate your perfect car budget by factoring in your financial goals, income, and monthly expenses.</p>
        <div class="sd-tool-btn-group">
          <a href="/tools/finance-calculator/" class="sd-tool-btn">
            <i class="fa-solid fa-calculator"></i> Calculate My Affordability
          </a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     CAR NEWS & REVIEWS
     NEWS-1: now sourced live from blog_posts (see $latestNews
     query near the top of this file) instead of a hardcoded
     3-item array. Section is skipped entirely if no posts have
     been published yet, rather than showing fake placeholder news.
     ════════════════════════════════════════════════ -->
<?php if (!empty($latestNews)): ?>
<section class="sd-section" id="for-dealers">
  <div class="container">
    <div class="sd-news-header">
      <div>
        <span class="sd-eyebrow">Stay informed</span>
        <h2 class="sd-sec-title">Latest car news &amp; reviews</h2>
      </div>
      <a href="/news/" class="sd-news-see-all">
        See all
        <span class="sd-news-arrow"><i class="fa-solid fa-arrow-right"></i></span>
      </a>
    </div>
    <div class="sd-news-grid">
      <?php foreach ($latestNews as $article):
        $newsUrl = '/news/' . htmlspecialchars($article['slug']) . '/';
      ?>
      <article class="sd-news-card pub-reveal" onclick="location.href='<?= $newsUrl ?>'">
        <div class="sd-news-img">
          <img src="<?= htmlspecialchars(homeNewsThumb($article)) ?>"
               alt="<?= htmlspecialchars($article['title']) ?>" loading="lazy">
        </div>
        <div class="sd-news-body">
          <h3 class="sd-news-title"><?= htmlspecialchars($article['title']) ?></h3>
          <?php if ($article['excerpt']): ?>
          <p class="sd-news-desc"><?= htmlspecialchars($article['excerpt']) ?></p>
          <?php endif; ?>
          <div class="sd-news-meta">
            <span class="sd-news-category"><?= htmlspecialchars($article['category_name'] ?? 'Car News') ?></span>
            <span class="sd-news-dot"></span>
            <span class="sd-news-read"><?= homeNewsReadTime($article['content'] ?? '') ?></span>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     TOP SALESDESKS THIS WEEK
     ════════════════════════════════════════════════ -->
<?php if (!empty($topDesks)): ?>
<section class="sd-section ">
  <div class="container">
    <div class="sd-sec-header">
      <div>
        <span class="sd-eyebrow">Active desks</span>
        <h2 class="sd-sec-title">Top SalesDesks this week</h2>
      </div>
    </div>
    <div class="sd-broker-grid">
      <?php foreach ($topDesks as $i => $desk):
        $initials = deskInitials($desk);
        $grad     = $gradients[$i % count($gradients)];
        $loc      = implode(' · ', array_filter([$desk['city'], $desk['province']]));
      ?>
      <div class="sd-bcard pub-reveal">
        <div class="sd-bcard__top">
          <div class="sd-bcard__av" style="background:<?= $grad ?>">
            <?php if ($desk['avatar_url']): ?>
            <img src="<?= htmlspecialchars($desk['avatar_url']) ?>"
                 style="width:100%;height:100%;object-fit:cover;border-radius:50%;" alt="">
            <?php else: ?>
            <?= htmlspecialchars($initials) ?>
            <?php endif; ?>
          </div>
          <div class="sd-bcard__info">
            <div class="sd-bcard__name"><?= htmlspecialchars($desk['display_name']) ?></div>
            <div class="sd-bcard__loc">
              <?= $loc ? htmlspecialchars($loc) . ' · ' : '' ?>Active broker
            </div>
          </div>
        </div>
        <div class="sd-bcard__stats">
          <div>
            <div class="sd-bcard__num"><?= (int)$desk['active_listings'] ?></div>
            <div class="sd-bcard__lbl">Listings</div>
          </div>
          <div>
            <div class="sd-bcard__num"><?= (int)$desk['leads_this_month'] ?></div>
            <div class="sd-bcard__lbl">Leads this month</div>
          </div>
          <div>
            <div class="sd-bcard__num"><?= (int)$desk['deals_closed'] ?></div>
            <div class="sd-bcard__lbl">Deals closed</div>
          </div>
        </div>
        <a href="/<?= htmlspecialchars($desk['slug']) ?>/" class="sd-bcard__link">
          View Desk &rarr;
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     PAGE-SPECIFIC ASSETS
     home.css is now loaded from <head> via $extraCss (set above,
     before this ob_start() block) — see the PERF FIX comment near
     the top of this file. home.js stays here: it's already `defer`'d
     in layout-public.php's pattern... actually it's inlined below
     with `defer` directly, so its position in the body doesn't
     block paint the way a blocking <link rel="stylesheet"> did.
     ════════════════════════════════════════════════ -->
<script src="/assets/js/home.js?v=<?= $assetVersion ?>" defer></script>

<?php
/*
 * Inline styles kept here to override any stale cached home.css.
 * Widget CSS (hws-* classes) lives inside hero-search-widget.php
 * and is never served from a cached asset file.
 */
?>
<style>
  section { overflow: hidden; }
  .sd-vehicle-grid { overflow-y: hidden; }

  /* Hero layout — inlined to defeat stale home.css cache */
  .sd-hero__title {
    color: #fff;
    font-family: var(--font-d);
    font-size: clamp(18px, calc(14px + 1.2vw), 32px);
    font-weight: 800;
    line-height: 1.05;
    margin-bottom: clamp(8px, 1.2vh, 14px);
    letter-spacing: -1px;
  }
  .sd-hero__sub {
    color: #fff;
    font-size: clamp(11px, calc(10px + 0.3vw), 14px);
    font-weight: 400;
    margin-bottom: clamp(22px, 3.5vh, 38px);
  }
  .sd-hero {
    position: relative;
    width: 100%;
    margin: 0;
    box-sizing: border-box;
    min-height: clamp(480px, 70vh, 100vh);
    background-image:
      linear-gradient(120deg, rgba(8,20,60,.82) 0%, rgba(15,40,100,.55) 50%, rgba(0,0,0,.28) 100%),
      url('assets/img/hero.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    display: flex;
    align-items: flex-start;
    padding: clamp(70px,10vh,110px) clamp(20px,5vw,80px) clamp(60px,8vh,100px) clamp(24px,10vw,140px);
    overflow-x: hidden;
  }
  .container {
    margin-inline: clamp(16px, 4vw, 48px);
    padding-inline: clamp(12px, 2vw, 24px);
  }
  .sd-tab-nav { overflow-y: hidden; }
  .sd-bcard { min-width: 0; }
  .sd-bcard__name { overflow-wrap: anywhere; }

  .sd-vehicle-grid--activity {
    grid-template-columns: none;
    grid-auto-flow: column;
    grid-auto-columns: 260px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  .sd-vehicle-grid--activity::-webkit-scrollbar { display: none; }

  @media (max-width: 900px) {
    .sd-vehicle-grid { grid-template-columns: repeat(2, 1fr); }
    .container {
      margin-inline: clamp(4px, 0.8vw, 12px);
      padding-inline: clamp(8px, 1vw, 12px);
    }
  }
  @media (max-width: 480px) {
    .sd-vehicle-grid,
    .sd-vehicle-grid--activity {
      grid-template-columns: none;
      grid-auto-flow: column;
      grid-auto-columns: 100%;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      gap: 12px;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      margin: 0 auto;
      padding: 0;
    }
    .sd-vehicle-grid::-webkit-scrollbar,
    .sd-vehicle-grid--activity::-webkit-scrollbar { display: none; }
    .sd-vehicle-grid .sd-vcard,
    .sd-vehicle-grid--activity .sd-vcard {
      width: 100%;
      scroll-snap-align: start;
    }
  }
</style>

<?php
$pageContent = ob_get_clean();
require_once 'views/layout-public.php';