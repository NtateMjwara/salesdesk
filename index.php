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
     ════════════════════════════════════════════════ -->
<link rel="stylesheet" href="/assets/css/home.css">
<script src="/assets/js/home.js" defer></script>

<?php
$pageContent = ob_get_clean();
require_once 'views/layout-public.php';
