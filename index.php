<?php
/**
 * SalesDesk — Homepage
 * Route: /   (web root)
 *
 * Wired into layout-public.php (nav, footer, visitor tracking).
 * Live car counts pulled from DB; sample vehicle cards use real
 * broker_inventory + cars query (limited to 3, newest first).
 * Province counts pulled live. Newsletter wired in next session.
 *
 * TODOs left as inline comments:
 *   - Activity tabs: Recently Viewed / Wishlist / Saved Searches
 *     → needs visitor_sessions + car_views + visitor_wishlist queries
 *   - Hero search car count: replace static with live COUNT()
 *   - Top SalesDesks: replace static cards with live query
 */

declare(strict_types=1);

/*require_once 'includes/security.php';*/
require_once 'includes/database.php';
require_once 'includes/visitor.php';
require_once 'includes/functions.php';
require_once 'includes/session.php';

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

// ── Live: featured cars (newest 3 on any active desk) ─────────
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
        LIMIT 3
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

// ── Prov abbreviation helper (reused from c/index.php) ────────
$provAbbr = [
    'Gauteng'       => 'GP',  'Western Cape'  => 'WC',  'KwaZulu-Natal' => 'KZN',
    'Eastern Cape'  => 'EC',  'Limpopo'       => 'LP',  'Mpumalanga'    => 'MP',
    'North West'    => 'NW',  'Free State'    => 'FS',  'Northern Cape' => 'NC',
];

// ── Fuel icon helper ───────────────────────────────────────────
function homeFuelIcon(string $fuel): string {
    return match (strtolower($fuel)) {
        'electric'                 => 'fa-bolt',
        'hybrid', 'plug-in hybrid' => 'fa-leaf',
        'diesel'                   => 'fa-oil-can',
        default                    => 'fa-gas-pump',
    };
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

// ── Page meta ──────────────────────────────────────────────────
$pageTitle     = 'SalesDesk | South Africa\'s Car Sales Platform';
$ogTitle       = 'SalesDesk — New &amp; Used Cars for Sale in South Africa';
$ogDescription = 'Browse ' . number_format($totalCars) . ' cars for sale across South Africa. '
               . 'Commission-protected leads. Verified dealers. Independent broker desks.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/';
$layoutVariant  = 'wide';
$showBreadcrumb = false;
$shareUrl       = $canonicalUrl;
$shareTitle     = 'SalesDesk — South Africa\'s Car Sales Platform';

ob_start();
?>

<!-- ════════════════════════════════════════════════
     HERO
     ════════════════════════════════════════════════ -->
<section class="sd-hero">
  <div class="sd-hero__overlay"></div>
  <div class="sd-hero__content anim-up">

    <h1 class="sd-hero__title">New &amp; used cars for sale</h1>
    <p class="sd-hero__sub">Let&rsquo;s find what <em>moves you.</em></p>

    <!-- Search card -->
    <div class="sd-search-card">

      <!-- Text search -->
      <div class="sd-search-top">
        <span class="sd-search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="text" id="heroQ" class="sd-search-input"
               placeholder='Try &ldquo;Toyota Hilux&rdquo;'
               aria-label="Search vehicles">
        <button class="sd-history-btn" title="Recent searches" aria-label="Recent searches">
          <i class="fa-solid fa-clock-rotate-left"></i>
        </button>
      </div>

      <!-- Make / Province -->
      <div class="sd-search-grid">
        <select id="heroMake" class="sd-search-select" aria-label="Makes & Models">
          <option value="">Makes &amp; Models</option>
          <option>Toyota</option><option>Volkswagen</option><option>Ford</option>
          <option>BMW</option><option>Mercedes-Benz</option><option>Audi</option>
          <option>Porsche</option><option>Land Rover</option><option>Hyundai</option>
          <option>Honda</option><option>Kia</option><option>Nissan</option>
        </select>
        <select id="heroProvince" class="sd-search-select" aria-label="Province">
          <option value="">Province</option>
          <?php foreach (array_keys($provinces) as $pv): ?>
          <option><?= htmlspecialchars($pv) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sd-search-divider"></div>

      <!-- Price type -->
      <div class="sd-search-radio-row">
        <label>
          <input type="radio" name="pricing" checked value="price">
          Price
        </label>
        <label>
          <input type="radio" name="pricing" value="monthly">
          Monthly payment*
          <span class="sd-info-badge" title="Based on standard finance terms">i</span>
        </label>
      </div>

      <!-- Price range -->
      <div class="sd-search-grid">
        <input type="number" id="heroMinPrice" class="sd-search-field"
               placeholder="Min Price (R)" aria-label="Minimum price">
        <input type="number" id="heroMaxPrice" class="sd-search-field"
               placeholder="Max Price (R)" aria-label="Maximum price">
      </div>

      <!-- Year range -->
      <div class="sd-search-grid">
        <input type="number" id="heroMinYear" class="sd-search-field"
               placeholder="Min Year" min="1990" max="<?= date('Y') ?>"
               aria-label="Minimum year">
        <input type="number" id="heroMaxYear" class="sd-search-field"
               placeholder="Max Year" min="1990" max="<?= date('Y') ?>"
               aria-label="Maximum year">
      </div>

      <!-- Reset -->
      <div class="sd-search-bottom-row">
        <button class="sd-reset-btn" id="heroReset" type="button">
          <i class="fa-solid fa-rotate-left"></i> Reset filters
        </button>
      </div>

      <!-- Submit -->
      <button class="sd-search-submit" id="hSearch" type="button">
        Search <?= $totalCars > 0
            ? number_format($totalCars) . '&nbsp;' . ($totalCars === 1 ? 'car' : 'cars')
            : 'all cars' ?>
      </button>

    </div><!-- /.sd-search-card -->

    <div class="sd-hero__hints anim-up d2">
      <span><i class="fa-solid fa-circle" style="color:#4ade80;font-size:7px"></i>
        <?= $totalCars > 0 ? number_format($totalCars) . ' active listings' : 'Live listings' ?>
      </span>
      <span><i class="fa-solid fa-shield-halved" style="color:#60a5fa"></i> Lead attribution</span>
      <span><i class="fa-solid fa-handshake" style="color:#c084fc"></i> Commission-protected</span>
    </div>

  </div>
</section>

<!-- ════════════════════════════════════════════════
     PICK UP WHERE YOU LEFT OFF
     ════════════════════════════════════════════════ -->
<section class="sd-section sd-section--white">
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
      </button>
      <button class="sd-tab-btn" data-tab="wishlist">
        <i class="fa-regular fa-heart" style="margin-right:6px;"></i>Wishlist
      </button>
      <button class="sd-tab-btn" data-tab="saved">
        <i class="fa-regular fa-bookmark" style="margin-right:6px;"></i>Saved Searches
      </button>
    </div>

    <?php
    // TODO: Replace these empty states with live queries once
    // car_views → recently viewed, visitor_wishlist → wishlist,
    // and a saved_searches table are implemented.
    $tabs = [
        'recent'   => ['fa-regular fa-eye-slash',  'No recently viewed vehicles yet',
                       'Start browsing and vehicles you view will appear here automatically.'],
        'wishlist' => ['fa-regular fa-heart',       'Your wishlist is empty',
                       'Tap the heart icon on any vehicle card to save it here.'],
        'saved'    => ['fa-regular fa-bookmark',    'No saved searches yet',
                       'Set up filters on the browse page and click "Save this search" to be notified when matching vehicles are listed.'],
    ];
    foreach ($tabs as $key => [$icon, $title, $sub]):
    ?>
    <div class="sd-tab-panel <?= $key === 'recent' ? 'active' : '' ?>" id="tab-<?= $key ?>">
      <div class="sd-pickup-empty">
        <div class="sd-pickup-empty__icon"><i class="<?= $icon ?>"></i></div>
        <div class="sd-pickup-empty__title"><?= htmlspecialchars($title) ?></div>
        <div class="sd-pickup-empty__sub"><?= htmlspecialchars($sub) ?></div>
        <a href="/c/" class="pub-btn pub-btn-primary" style="font-size:13px;padding:9px 20px;">
          Browse vehicles <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="sd-pickup-ip-note">
      <i class="fa-solid fa-circle-info"></i>
      Activity is tracked by browser session &mdash; no sign-in required to pick up where you left off.
      <a href="/auth/login.php" style="color:var(--p);margin-left:4px;font-weight:500;">
        Sign in
      </a> to sync across devices.
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
<section class="sd-section sd-section--subtle">
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
      <a href="/c/?body_type[]=SUV%2F4x4"  class="sd-pill"><i class="fa-solid fa-truck"></i> SUVs / 4x4</a>
      <a href="/c/?fuel_type[]=Electric"   class="sd-pill"><i class="fa-solid fa-bolt"></i> Electric</a>
      <a href="/c/?price_min=1000000"      class="sd-pill"><i class="fa-solid fa-star"></i> Luxury</a>
      <a href="/c/?price_max=300000"       class="sd-pill"><i class="fa-solid fa-tag"></i> Under R 300k</a>
    </div>

    <!-- Live featured vehicle cards -->
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
      <a href="<?= $carUrl ?>" class="sd-vcard pub-reveal" style="animation-delay:<?= $i * 0.1 ?>s">
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
<section class="sd-section sd-section--white">
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
<section class="sd-section sd-section--subtle">
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
          <a href="/c/?condition=used" class="sd-tool-btn">
            <i class="fa-solid fa-arrow-right-arrow-left"></i> Compare Used Cars
          </a>
          <a href="/c/?condition=new" class="sd-tool-btn">
            <i class="fa-solid fa-car"></i> Compare New Cars
          </a>
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
          <!-- TODO: link to calculator.php when built -->
          <a href="/tools/finance-calculator/" class="sd-tool-btn">
            <i class="fa-solid fa-calculator"></i> Calculate My Affordability
          </a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     CAR NEWS & REVIEWS  (static placeholder —
     TODO: replace with GET /api/news?limit=3)
     ════════════════════════════════════════════════ -->
<section class="sd-section sd-section--white" id="for-dealers">
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
      <?php
      $newsItems = [
        ['https://images.unsplash.com/photo-1494976388531-d1058494cdd8?q=80&w=1200&auto=format&fit=crop',
         'Ford Everest (2026) Price &amp; Specs',
         'Pricing scoop! Here\'s what the revised Ford Everest line-up — which will start some R128&thinsp;000 lower than before — will cost when it officially launches in SA.',
         'Car News', '5 min read'],
        ['https://images.unsplash.com/photo-1503376780353-7e6692767b70?q=80&w=1200&auto=format&fit=crop',
         'Ford Ranger Super Duty Confirmed for SA in 2027',
         'The Ford Ranger Super Duty is confirmed for a South African market introduction in 2027 — here\'s what we know so far.',
         'Car News', '2 min read'],
        ['https://images.unsplash.com/photo-1552519507-da3b142c6e3d?q=80&w=1200&auto=format&fit=crop',
         'Isuzu D-Max (2026) Price &amp; Specs',
         'The facelifted Isuzu D-Max has finally launched in South Africa and we have full pricing for this refreshed SA-built bakkie.',
         'Car News', '8 min read'],
      ];
      foreach ($newsItems as [$img, $title, $desc, $cat, $read]):
      ?>
      <article class="sd-news-card pub-reveal" onclick="location.href='/news/'">
        <div class="sd-news-img">
          <img src="<?= $img ?>" alt="" loading="lazy">
        </div>
        <div class="sd-news-body">
          <h3 class="sd-news-title"><?= $title ?></h3>
          <p class="sd-news-desc"><?= $desc ?></p>
          <div class="sd-news-meta">
            <span class="sd-news-category"><?= $cat ?></span>
            <span class="sd-news-dot"></span>
            <span class="sd-news-read"><?= $read ?></span>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════
     TOP SALESDESKS THIS WEEK
     ════════════════════════════════════════════════ -->
<?php if (!empty($topDesks)): ?>
<section class="sd-section sd-section--white">
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
     PAGE-SPECIFIC STYLES
     ════════════════════════════════════════════════ -->
<style>
    /* === SECTIONS === */
    .section{padding:64px 24px}
    .section-white{background:#fff}
    .section-subtle{background:#f8faff}
    .section-dark{background:var(--dark)}
    .container{max-width:var(--max);margin:0 auto}
    .sec-header{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:28px}
    .eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--p)}
    .sec-title{font-family:var(--font-d);font-size:26px;font-weight:700;color:var(--text);margin-top:6px}
    .sec-link{font-size:14px;font-weight:600;color:var(--p);transition:color var(--tr)}
    .sec-link:hover{color:var(--p-dark)}  
/* ── Hero ──────────────────────────────────────────────────────── */
.sd-hero {
  position: relative;
  min-height: 100vh;
  background-image:
    linear-gradient(120deg, rgba(8,20,60,.82) 0%, rgba(15,40,100,.55) 50%, rgba(0,0,0,.28) 100%),
    url('https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=2070&q=80');
  background-size: cover;
  background-position: center 40%;
  display: flex;
  align-items: flex-start;
  padding: clamp(70px,10vh,110px) clamp(20px,5vw,80px) clamp(60px,8vh,100px) clamp(24px,10vw,140px);
  overflow: hidden;
}
.sd-hero__overlay {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,.18);
  z-index: 1;
}
.sd-hero__content {
  position: relative;
  z-index: 2;
  width: min(560px, 100%);
}
.sd-hero__title {
  color: #fff;
  font-family: var(--font-d);
  font-size: clamp(22px, calc(18px + 1.8vw), 40px);
  font-weight: 800;
  line-height: 1.05;
  margin-bottom: clamp(8px, 1.2vh, 14px);
  letter-spacing: -1px;
}
.sd-hero__sub {
  color: #fff;
  font-size: clamp(14px, calc(12px + 0.6vw), 20px);
  font-weight: 400;
  margin-bottom: clamp(22px, 3.5vh, 38px);
}
.sd-hero__sub em { font-style: italic; font-weight: 500; }

/* Search card */
.sd-search-card {
  width: 100%;
  background: #fff;
  border-radius: clamp(16px, 2vw, 26px);
  padding: clamp(14px, 2vw, 20px);
  box-shadow: 0 24px 56px rgba(8,20,60,.3), 0 4px 14px rgba(0,0,0,.12);
}
.sd-search-top { position: relative; margin-bottom: clamp(12px,1.8vh,18px); }
.sd-search-input {
  width: 100%;
  height: clamp(50px, 7vh, 64px);
  border: 2px solid #ececec;
  border-radius: clamp(12px, 1.5vw, 18px);
  padding: 0 clamp(50px, 7vw, 68px) 0 clamp(44px, 6vw, 58px);
  font-size: clamp(14px, 1.4vw, 16px);
  outline: none;
  transition: border-color .2s;
  color: #444;
  background: #fff;
  font-family: var(--sans);
}
.sd-search-input:focus { border-color: var(--p); }
.sd-search-icon {
  position: absolute;
  left: clamp(14px, 1.8vw, 20px);
  top: 50%;
  transform: translateY(-50%);
  font-size: clamp(16px, 1.6vw, 21px);
  color: var(--p);
}
.sd-history-btn {
  position: absolute;
  right: clamp(10px, 1.2vw, 16px);
  top: 50%;
  transform: translateY(-50%);
  width: clamp(32px, 4vw, 40px);
  height: clamp(32px, 4vw, 40px);
  border-radius: 50%;
  border: 2px solid #ddd;
  background: #fff;
  cursor: pointer;
  font-size: clamp(15px, 1.5vw, 19px);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: border-color .2s, color .2s;
  color: #888;
}
.sd-history-btn:hover { border-color: var(--p); color: var(--p); }
.sd-search-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: clamp(10px, 1.4vw, 16px);
  margin-bottom: clamp(12px, 1.6vh, 16px);
}
.sd-search-select, .sd-search-field {
  width: 100%;
  height: clamp(42px, 5.5vh, 50px);
  border: none;
  border-radius: var(--r-md);
  background: #f2f3f5;
  padding: 0 clamp(12px, 1.4vw, 18px);
  font-size: clamp(13px, 1.2vw, 15px);
  color: #444;
  outline: none;
  font-family: var(--sans);
  transition: background .2s;
}
.sd-search-select:focus, .sd-search-field:focus { background: #e8eaf0; }
.sd-search-divider { height: 1px; background: #e7e7e7; margin: clamp(6px,.8vh,10px) 0 clamp(12px,1.6vh,18px); }
.sd-search-radio-row {
  display: flex;
  align-items: center;
  gap: clamp(12px, 2vw, 20px);
  margin-bottom: clamp(12px, 1.6vh, 18px);
  color: #444;
  font-size: clamp(13px, 1.2vw, 15px);
}
.sd-search-radio-row label {
  display: flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
}
.sd-search-radio-row input[type="radio"] {
  accent-color: #222;
  width: clamp(15px, 1.4vw, 18px);
  height: clamp(15px, 1.4vw, 18px);
}
.sd-info-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px; height: 16px;
  border-radius: 50%;
  background: #222;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
}
.sd-search-bottom-row {
  display: flex;
  justify-content: flex-end;
  margin: clamp(4px,.5vh,6px) 0 clamp(14px,2vh,20px);
}
.sd-reset-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--p);
  font-size: clamp(13px, 1.2vw, 16px);
  font-weight: 500;
  background: none;
  border: none;
  cursor: pointer;
  font-family: var(--sans);
  transition: opacity .2s;
}
.sd-reset-btn:hover { opacity: .75; }
.sd-search-submit {
  width: 100%;
  height: clamp(48px, 6.5vh, 58px);
  border: none;
  border-radius: var(--r-md);
  background: var(--p);
  color: #fff;
  font-family: var(--font-d);
  font-size: clamp(16px, 1.6vw, 22px);
  font-weight: 700;
  cursor: pointer;
  transition: .25s ease;
  letter-spacing: .01em;
}
.sd-search-submit:hover { background: var(--p-dark); }
.sd-hero__hints {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: clamp(10px, 1.8vw, 18px);
  margin-top: clamp(14px, 2vh, 20px);
  font-size: clamp(11px, 1vw, 13px);
  color: rgba(255,255,255,.78);
}
.sd-hero__hints span { display: flex; align-items: center; gap: 5px; }

/* ── Shared section layout ────────────────────────────────────── */
.sd-section { padding: 64px 0; }
.sd-section--white  { background: #fff; }
.sd-section--subtle { background: #f8faff; }

.sd-sec-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 28px;
}
.sd-eyebrow {
  display: block;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--p);
}
.sd-sec-title {
  font-family: var(--font-d);
  font-size: 26px;
  font-weight: 700;
  color: var(--text);
  margin-top: 6px;
}
.sd-sec-link {
  font-size: 14px;
  font-weight: 600;
  color: var(--p);
  transition: color .2s;
  text-decoration: none;
}
.sd-sec-link:hover { color: var(--p-dark); text-decoration: none; }

/* ── Activity tabs ────────────────────────────────────────────── */
.sd-tab-nav {
  display: flex;
  gap: 4px;
  border-bottom: 2px solid var(--border);
  margin-bottom: 28px;
  overflow-x: auto;
}
.sd-tab-btn {
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 500;
  color: var(--muted);
  border: none;
  background: none;
  cursor: pointer;
  border-bottom: 2.5px solid transparent;
  margin-bottom: -2px;
  transition: color .2s, border-color .2s;
  white-space: nowrap;
  font-family: var(--sans);
}
.sd-tab-btn:hover { color: var(--p); }
.sd-tab-btn.active { color: var(--p); border-bottom-color: var(--p); font-weight: 600; }
.sd-tab-panel { display: none; }
.sd-tab-panel.active { display: block; }
.sd-pickup-empty {
  padding: 48px 24px;
  text-align: center;
  border: 1.5px dashed var(--border);
  border-radius: var(--r-lg);
  background: #fafbff;
}
.sd-pickup-empty__icon  { font-size: 32px; color: var(--border); margin-bottom: 14px; }
.sd-pickup-empty__title { font-family: var(--font-d); font-size: 16px; font-weight: 600; color: var(--muted); margin-bottom: 8px; }
.sd-pickup-empty__sub   { font-size: 13px; color: var(--faint); max-width: 360px; margin: 0 auto 20px; line-height: 1.65; }
.sd-pickup-ip-note {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: var(--faint);
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}
.sd-pickup-ip-note i { color: var(--p); }

/* ── Shop by condition ────────────────────────────────────────── */
.sd-shop-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 22px; }
.sd-shop-card {
  position: relative;
  overflow: hidden;
  border-radius: var(--r-xl);
  min-height: 420px;
  padding: 32px;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  color: #fff;
  isolation: isolate;
  transition: transform .35s cubic-bezier(.2,0,0,1), box-shadow .35s cubic-bezier(.2,0,0,1);
  cursor: pointer;
  text-decoration: none;
}
.sd-shop-card:hover { transform: translateY(-5px); box-shadow: 0 22px 48px rgba(0,0,0,.22); text-decoration: none; }
.sd-shop-card::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
  background: linear-gradient(to top, rgba(8,20,60,.84) 0%, rgba(8,20,60,.18) 60%, transparent 100%);
}
.sd-shop-card--used { background: url('https://images.unsplash.com/photo-1503376780353-7e6692767b70?q=80&w=1400&auto=format&fit=crop') center/cover no-repeat; }
.sd-shop-card--new  { background: url('https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?q=80&w=1400&auto=format&fit=crop') center/cover no-repeat; }
.sd-shop-badge {
  position: absolute;
  top: 24px;
  left: 24px;
  background: rgba(255,255,255,.13);
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255,255,255,.22);
  padding: 8px 16px;
  border-radius: var(--r-full);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .05em;
}
.sd-shop-title {
  font-family: var(--font-d);
  font-size: clamp(30px, 3.5vw, 42px);
  font-weight: 800;
  line-height: 1;
  margin-bottom: 14px;
  letter-spacing: -1px;
}
.sd-shop-desc {
  font-size: 14px;
  line-height: 1.75;
  color: rgba(255,255,255,.88);
  margin-bottom: 26px;
  max-width: 420px;
}
.sd-shop-actions  { display: flex; flex-wrap: wrap; gap: 12px; }
.sd-shop-btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--p);
  color: #fff;
  border-radius: var(--r-md);
  padding: 12px 22px;
  font-size: 14px;
  font-weight: 600;
  font-family: var(--sans);
  text-decoration: none;
  border: none;
  cursor: pointer;
  transition: background .2s, transform .2s;
}
.sd-shop-btn-primary:hover { background: var(--p-dark); transform: translateY(-1px); }

/* ── Pills ────────────────────────────────────────────────────── */
.sd-pill-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
.sd-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 18px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-full);
  font-size: 13px;
  font-weight: 500;
  color: var(--muted);
  background: #fff;
  cursor: pointer;
  transition: all .2s;
  text-decoration: none;
  white-space: nowrap;
}
.sd-pill i { font-size: 11px; }
.sd-pill:hover, .sd-pill.active {
  background: var(--p);
  color: #fff;
  border-color: var(--p);
  box-shadow: 0 4px 12px rgba(15,76,158,.25);
  text-decoration: none;
}

/* ── Vehicle cards ────────────────────────────────────────────── */
.sd-vehicle-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 20px; }
.sd-vcard {
  display: block;
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  transition: transform .35s cubic-bezier(.2,0,0,1), box-shadow .35s cubic-bezier(.2,0,0,1), border-color .2s;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
  text-decoration: none;
  color: inherit;
}
.sd-vcard:hover {
  transform: translateY(-5px);
  box-shadow: 0 16px 32px rgba(15,76,158,.13);
  border-color: #c7d6f5;
  text-decoration: none;
}
.sd-vcard__img-wrap  { position: relative; height: 192px; overflow: hidden; background: var(--bg); }
.sd-vcard__img       { width: 100%; height: 100%; object-fit: cover; transition: transform .35s cubic-bezier(.2,0,0,1); }
.sd-vcard:hover .sd-vcard__img { transform: scale(1.06); }
.sd-vcard__img-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 36px; color: var(--border);
}
.sd-vcard__year { position: absolute; top: 12px; left: 12px; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); color: #fff; font-family: var(--font-d); font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: var(--r-full); }
.sd-vcard__prov { position: absolute; top: 12px; right: 12px; background: rgba(255,255,255,.95); font-size: 11px; font-weight: 600; color: var(--text); padding: 3px 10px; border-radius: var(--r-full); box-shadow: 0 2px 6px rgba(0,0,0,.08); }
.sd-vcard__km   { position: absolute; bottom: 12px; right: 12px; background: rgba(255,255,255,.95); font-size: 11px; font-weight: 600; color: var(--text); padding: 3px 10px; border-radius: var(--r-full); box-shadow: 0 2px 6px rgba(0,0,0,.08); }
.sd-vcard__ev   { position: absolute; bottom: 12px; left: 12px; background: #15803d; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: var(--r-full); }
.sd-vcard__body { padding: 16px; }
.sd-vcard__top  { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
.sd-vcard__name   { font-family: var(--font-d); font-size: 15px; font-weight: 700; line-height: 1.25; color: var(--text); margin-bottom: 3px; }
.sd-vcard__dealer { font-size: 11px; color: var(--faint); }
.sd-vcard__price  { font-family: var(--font-d); font-size: 17px; font-weight: 700; color: var(--p); white-space: nowrap; flex-shrink: 0; }
.sd-vcard__desk-row { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.sd-badge-desk { display: inline-flex; align-items: center; gap: 4px; background: var(--p-light); color: var(--p); border: 1px solid var(--p-b); font-size: 11px; font-weight: 600; font-family: var(--font-d); padding: 3px 9px; border-radius: var(--r-full); }
.sd-badge-desk i { font-size: 9px; }
.sd-badge-v { display: inline-flex; align-items: center; gap: 3px; background: var(--gr-bg); color: var(--green); border: 1px solid var(--gr-b); font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: var(--r-full); }
.sd-badge-v i { font-size: 9px; }
.sd-vcard__specs { display: flex; flex-wrap: wrap; gap: 6px; }
.sd-spec { display: inline-flex; align-items: center; gap: 4px; background: var(--bg); border: 1px solid var(--border); color: var(--muted); font-size: 11px; padding: 3px 9px; border-radius: var(--r-full); }
.sd-spec i { font-size: 10px; }
.sd-view-all { text-align: center; margin-top: 32px; }

/* ── Province grid ────────────────────────────────────────────── */
.sd-prov-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 12px; }
.sd-prov-chip {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 8px; aspect-ratio: 1/1; background: #fff; border: 1.5px solid var(--border);
  border-radius: var(--r-full); padding: 16px 12px; font-size: 13px; font-weight: 500;
  color: var(--muted); cursor: pointer; transition: all .2s; text-decoration: none; text-align: center;
}
.sd-prov-chip i { color: var(--p); font-size: 20px; line-height: 1; }
.sd-prov-chip__label { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.25; }
.sd-prov-chip__n     { font-size: 11px; color: var(--faint); line-height: 1; }
.sd-prov-chip:hover  {
  background: var(--p); color: #fff; border-color: var(--p);
  box-shadow: 0 6px 20px rgba(15,76,158,.22); transform: translateY(-3px); text-decoration: none;
}
.sd-prov-chip:hover i,
.sd-prov-chip:hover .sd-prov-chip__label,
.sd-prov-chip:hover .sd-prov-chip__n { color: rgba(255,255,255,.92); }

/* ── Tools ────────────────────────────────────────────────────── */
.sd-tools-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 18px; }
.sd-tool-card {
  background: var(--p-light); border: 1.5px solid var(--p-b); border-radius: var(--r-xl);
  padding: 28px; transition: transform .35s cubic-bezier(.2,0,0,1), box-shadow .35s cubic-bezier(.2,0,0,1), border-color .2s;
  box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.sd-tool-card:hover { transform: translateY(-4px); box-shadow: 0 16px 32px rgba(15,76,158,.13); border-color: #b8d0f8; }
.sd-tool-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
.sd-tool-icon-wrap {
  width: 44px; height: 44px; flex-shrink: 0; display: flex; align-items: center;
  justify-content: center; background: var(--p); color: #fff; border-radius: var(--r-md);
}
.sd-tool-title { font-family: var(--font-d); font-size: 18px; font-weight: 700; color: var(--text); }
.sd-tool-desc  { font-size: 14px; line-height: 1.65; color: var(--muted); margin-bottom: 20px; }
.sd-tool-btn-group { display: flex; flex-wrap: wrap; gap: 10px; }
.sd-tool-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 10px 20px; border: 1.5px solid var(--p); border-radius: var(--r-md);
  text-decoration: none; color: var(--p); background: #fff; font-size: 13px;
  font-weight: 600; font-family: var(--sans);
  transition: background .2s, color .2s, box-shadow .2s;
}
.sd-tool-btn:hover { background: var(--p); color: #fff; box-shadow: 0 4px 14px rgba(15,76,158,.25); text-decoration: none; }

/* ── News ─────────────────────────────────────────────────────── */
.sd-news-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.sd-news-see-all { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: var(--p); font-size: 14px; font-weight: 600; transition: opacity .2s; }
.sd-news-see-all:hover { opacity: .75; text-decoration: none; }
.sd-news-arrow { width: 32px; height: 32px; border-radius: 50%; background: var(--p); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; flex-shrink: 0; }
.sd-news-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 18px; }
.sd-news-card {
  background: #fff; border-radius: var(--r-lg); overflow: hidden; border: 1px solid var(--border);
  box-shadow: 0 2px 10px rgba(0,0,0,.05); transition: transform .35s cubic-bezier(.2,0,0,1), box-shadow .35s cubic-bezier(.2,0,0,1), border-color .2s;
  cursor: pointer;
}
.sd-news-card:hover { transform: translateY(-4px); box-shadow: 0 16px 32px rgba(15,76,158,.13); border-color: #c7d6f5; }
.sd-news-img { width: 100%; height: 210px; overflow: hidden; background: var(--bg); }
.sd-news-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s cubic-bezier(.2,0,0,1); }
.sd-news-card:hover .sd-news-img img { transform: scale(1.04); }
.sd-news-body { padding: 18px; }
.sd-news-title { font-family: var(--font-d); font-size: 16px; font-weight: 700; line-height: 1.3; margin-bottom: 10px; color: var(--text); }
.sd-news-desc  { font-size: 13px; line-height: 1.65; color: var(--muted); margin-bottom: 14px; }
.sd-news-meta  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; }
.sd-news-category { color: var(--p); font-weight: 600; }
.sd-news-dot   { width: 3px; height: 3px; background: var(--faint); border-radius: 50%; flex-shrink: 0; }
.sd-news-read  { color: var(--faint); }

/* ── Broker cards ─────────────────────────────────────────────── */
.sd-broker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px,1fr)); gap: 16px; }
.sd-bcard {
  background: #fff; border: 1px solid var(--border); border-radius: var(--r-lg);
  padding: 20px; transition: box-shadow .2s, transform .2s; box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.sd-bcard:hover { box-shadow: 0 8px 24px rgba(15,76,158,.1); transform: translateY(-3px); }
.sd-bcard__top  { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.sd-bcard__av   {
  width: 42px; height: 42px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-d); font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0;
  overflow: hidden;
}
.sd-bcard__info  { flex: 1; min-width: 0; }
.sd-bcard__name  { font-family: var(--font-d); font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
.sd-bcard__loc   { font-size: 11px; color: var(--faint); }
.sd-bcard__stats { display: flex; gap: 16px; margin-bottom: 14px; }
.sd-bcard__num   { font-family: var(--font-d); font-size: 22px; font-weight: 700; color: var(--text); line-height: 1; }
.sd-bcard__lbl   { font-size: 11px; color: var(--faint); margin-top: 2px; }
.sd-bcard__link  { font-size: 12px; font-weight: 600; color: var(--p); transition: color .2s; text-decoration: none; }
.sd-bcard__link:hover { color: var(--p-dark); text-decoration: none; }

/* ── Animations (reuse public.css pattern) ────────────────────── */
@keyframes fadeUp { from { opacity:0; transform:translateY(20px) } to { opacity:1; transform:translateY(0) } }
.anim-up { animation: fadeUp .65s ease both; }
.d1 { animation-delay: .1s; }
.d2 { animation-delay: .25s; }
.d3 { animation-delay: .4s; }

/* ── Responsive ───────────────────────────────────────────────── */
@media (max-width: 1024px) {
  .sd-hero { padding: clamp(56px,8vh,90px) clamp(24px,4vw,60px) clamp(48px,7vh,80px); align-items: center; justify-content: center; }
  .sd-hero__content { width: min(580px, 100%); }
}
@media (max-width: 900px) {
  .sd-news-grid { grid-template-columns: 1fr 1fr; }
  .sd-shop-grid { grid-template-columns: 1fr; }
  .sd-shop-card { min-height: 360px; }
}
@media (max-width: 768px) {
  .sd-hero { min-height: calc(var(--vh,1vh)*100); padding: clamp(48px,8vh,72px) clamp(20px,5vw,40px) clamp(40px,6vh,60px); align-items: flex-start; justify-content: center; }
  .sd-hero__content { width: 100%; max-width: 500px; margin: 0 auto; }
  .sd-search-grid { grid-template-columns: 1fr 1fr; }
  .sd-section { padding: clamp(32px,5vh,48px) 0; }
  .sd-tools-grid { grid-template-columns: 1fr; }
  .sd-broker-grid { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .sd-news-grid { grid-template-columns: 1fr; }
  .sd-news-img { height: 190px; }
}
@media (max-width: 480px) {
  .sd-hero { padding: clamp(40px,12vw,60px) clamp(16px,5vw,24px) clamp(32px,8vw,48px); }
  .sd-hero__content { width: 100%; }
  .sd-search-card { border-radius: 20px; padding: clamp(14px,4vw,18px); }
  .sd-search-input { height: clamp(48px,13vw,58px); border-radius: 14px; font-size: clamp(13px,3.5vw,15px); }
  .sd-search-grid { grid-template-columns: 1fr; gap: 10px; }
  .sd-search-select, .sd-search-field { height: clamp(42px,11vw,50px); font-size: clamp(13px,3.5vw,15px); }
  .sd-search-submit { height: clamp(50px,13vw,60px); font-size: clamp(15px,4.5vw,19px); border-radius: 12px; }
  .sd-vehicle-grid { grid-template-columns: 1fr; }
  .sd-section { padding: 28px 0; }
  .sd-tool-btn { width: 100%; }
  .sd-shop-actions { flex-direction: column; }
  .sd-shop-btn-primary { width: 100%; justify-content: center; }
  .sd-shop-title { font-size: 30px; }
}
@media (max-height: 500px) and (orientation: landscape) {
  .sd-hero { min-height: auto; padding: clamp(16px,4vh,32px) clamp(20px,6vw,60px); align-items: flex-start; }
  .sd-search-card { padding: clamp(10px,1.5vw,16px); }
  .sd-search-input { height: clamp(40px,5vh,50px); }
}
@media (max-width: 360px) {
  .sd-search-grid { grid-template-columns: 1fr; }
  .sd-search-card { padding: 12px; }
  .sd-search-submit { font-size: 14px; }
  .sd-hero__hints { flex-direction: column; align-items: flex-start; gap: 7px; }
}

/* ── Global custom scrollbar ──────────────────────────────────── */

/* Firefox — thin styled track */
* {
  scrollbar-width: thin;
  scrollbar-color: rgba(15,76,158,.35) transparent;
}

/* WebKit (Chrome, Safari, Edge, Opera) */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: rgba(15,76,158,.3);
  border-radius: 999px;
  transition: background .2s;
}
::-webkit-scrollbar-thumb:hover {
  background: rgba(15,76,158,.6);
}
::-webkit-scrollbar-corner {
  background: transparent;
}

/* Utility class — fully hidden scrollbar (horizontal carousels etc.) */
.hide-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
.hide-scrollbar::-webkit-scrollbar {
  display: none;
}
</style>

<!-- ════════════════════════════════════════════════
     PAGE-SPECIFIC SCRIPTS
     ════════════════════════════════════════════════ -->
<script>
/* Tab navigation */
document.querySelectorAll('.sd-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const t = btn.dataset.tab;
    document.querySelectorAll('.sd-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.sd-tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + t).classList.add('active');
  });
});

/* Hero search — builds query string and navigates to /c/ */
document.getElementById('hSearch').addEventListener('click', () => {
  const params = new URLSearchParams();
  const q        = document.getElementById('heroQ').value.trim();
  const make     = document.getElementById('heroMake').value;
  const province = document.getElementById('heroProvince').value;
  const minPrice = document.getElementById('heroMinPrice').value;
  const maxPrice = document.getElementById('heroMaxPrice').value;
  const minYear  = document.getElementById('heroMinYear').value;
  const maxYear  = document.getElementById('heroMaxYear').value;

  if (q)        params.set('q',         q);
  if (make)     params.set('make',      make);
  if (province) params.set('province',  province);
  if (minPrice) params.set('price_min', minPrice);
  if (maxPrice) params.set('price_max', maxPrice);
  if (minYear)  params.set('year_min',  minYear);
  if (maxYear)  params.set('year_max',  maxYear);

  const qs = params.toString();
  window.location.href = '/c/' + (qs ? '?' + qs : '');
});

/* Enter key in any hero field triggers search */
document.querySelectorAll(
  '#heroQ,#heroMake,#heroProvince,#heroMinPrice,#heroMaxPrice,#heroMinYear,#heroMaxYear'
).forEach(el => el.addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('hSearch').click();
}));

/* Hero reset */
document.getElementById('heroReset').addEventListener('click', () => {
  document.getElementById('heroQ').value = '';
  document.getElementById('heroMake').selectedIndex = 0;
  document.getElementById('heroProvince').selectedIndex = 0;
  document.getElementById('heroMinPrice').value = '';
  document.getElementById('heroMaxPrice').value = '';
  document.getElementById('heroMinYear').value = '';
  document.getElementById('heroMaxYear').value = '';
  document.querySelector('input[name="pricing"][value="price"]').checked = true;
});

/* Dynamic VH — address-bar-aware on mobile */
function setVH() {
  document.documentElement.style.setProperty('--vh', window.innerHeight * 0.01 + 'px');
}
setVH();
window.addEventListener('resize', setVH);
window.addEventListener('orientationchange', () => setTimeout(setVH, 150));
</script>

<?php
$pageContent = ob_get_clean();
require_once 'views/layout-public.php';
