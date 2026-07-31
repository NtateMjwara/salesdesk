<?php
/**
 * SalesDesk — Broker Public Storefront  (v4)
 * Route: /{broker-slug}/   (via .htaccess → broker/index.php?slug=…)
 *
 * v4 CHANGES (this pass):
 *   ROUTE-2   Every car-detail link on this page now points at
 *              /cars-for-sale/{desk-slug}/{car-slug}/ instead of the
 *              retired /c/{desk-slug}/{car-slug}/ path — this file was
 *              the last place in the codebase still building /c/ links
 *              directly (see .htaccess and cars-for-sale/index.php for
 *              the rest of the ROUTE-1 rename). The legacy /c/ → 
 *              /cars-for-sale/ redirect in .htaccess still catches any
 *              old bookmarked/shared links, but this page no longer
 *              generates new ones, avoiding an unnecessary redirect hop
 *              on every card click-through.
 *   SEARCH-1  Sidebar search box no longer auto-submits the whole page
 *              on a debounced keystroke (see cars-for-sale/index.php's
 *              own SEARCH-1 note — same fix, same reasoning, applied
 *              here). Now uses assets/js/search-typeahead.js, scoped to
 *              this desk's own inventory via salesdesk_id so suggestions
 *              can never surface cars outside this broker's desk.
 *   SEARCH-2  fuel_type / transmission / drivetrain whitelists moved to
 *              includes/filter-whitelists.php (shared with
 *              cars-for-sale/index.php). This page's own copies had
 *              silently drifted from the corrected values already in
 *              use on the main browse page ('Plug-in Hybrid' vs the real
 *              'Plug-in Hybrid (PHEV)', 'DSG/Dual-clutch' vs the real
 *              'DSG', '4×4' vs the real '4WD') — meaning those filter
 *              checkboxes here could never actually match a real row.
 *              Fixed by switching to the shared whitelist functions.
 *
 * v3 CHANGES (prior pass, preserved for audit trail):
 *   The inventory section now reuses the exact same filter sidebar,
 *   query logic, and .vehicle-card markup/classes as the main browse
 *   page (/cars-for-sale/index.php), so buyers get one consistent
 *   browsing UX whether they're on a broker's desk or the global
 *   catalogue. Every filter/count/fetch query is scoped to this desk's
 *   inventory only via `bi.salesdesk_id = ?` — nothing here can leak
 *   another desk's cars.
 *
 *   Hero and contact strip are UNCHANGED from v2.
 *
 * Attribution:
 *   A visitor on a broker's own storefront is always attributed to
 *   that broker, so car links use this desk's own bi.tracking_code
 *   directly (no "first-listed desk" lookup needed — that's only
 *   relevant on the global /cars-for-sale/ page where a car can appear
 *   on many desks). ?ref= is still appended for analytics continuity
 *   with externally shared links.
 *
 * No auth required. SQL fully parameterised.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';
require_once '../includes/filter-whitelists.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Resolve slug ──────────────────────────────────────────────
$slug = trim($_GET['slug'] ?? basename(dirname($_SERVER['PHP_SELF'])));
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if (!$slug) {
    http_response_code(404);
    exit('Not found.');
}

// ── Load salesdesk + broker profile ──────────────────────────
$deskStmt = $pdo->prepare("
    SELECT
        sd.id, sd.uuid, sd.slug, sd.display_name, sd.tagline,
        sd.logo_url, sd.primary_colour, sd.is_active,
        u.id            AS user_id,
        p.first_name, p.last_name, p.avatar_url, p.bio, p.phone,
        a.city, a.province, a.suburb
    FROM salesdesks sd
    JOIN users u         ON u.id  = sd.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN addresses a ON a.id = p.address_id
    WHERE sd.slug = ? AND sd.is_active = 1
    LIMIT 1
");
$deskStmt->execute([$slug]);
$desk = $deskStmt->fetch();

if (!$desk) {
    http_response_code(404);
    exit('This SalesDesk was not found or is no longer active.');
}

$salesdeskId = (int) $desk['id'];
$deskPath    = '/' . $desk['slug'] . '/';

// ── Visitor session ───────────────────────────────────────────
$visitor = initVisitorSession();


// ══════════════════════════════════════════════════════════════
// FILTERS — identical shape to /cars-for-sale/index.php, scoped to
// this desk
// ══════════════════════════════════════════════════════════════

$q         = trim($_GET['q']         ?? '');
$make      = trim($_GET['make']      ?? '');
$condition = trim($_GET['condition'] ?? '');
$province  = trim($_GET['province']  ?? '');
$sort      = trim($_GET['sort']      ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

// Numeric filters: null means "not set" (preserves an explicit 0).
$priceMin   = (isset($_GET['price_min'])   && $_GET['price_min']   !== '') ? (int) $_GET['price_min']   : null;
$priceMax   = (isset($_GET['price_max'])   && $_GET['price_max']   !== '') ? (int) $_GET['price_max']   : null;
$mileageMin = (isset($_GET['mileage_min']) && $_GET['mileage_min'] !== '') ? (int) $_GET['mileage_min'] : null;
$mileageMax = (isset($_GET['mileage_max']) && $_GET['mileage_max'] !== '') ? (int) $_GET['mileage_max'] : null;
$yearMin    = (isset($_GET['year_min'])    && $_GET['year_min']    !== '') ? (int) $_GET['year_min']    : null;
$yearMax    = (isset($_GET['year_max'])    && $_GET['year_max']    !== '') ? (int) $_GET['year_max']    : null;

$validConditions = ['new', 'demo', 'used'];
$validSorts      = ['newest', 'price_asc', 'price_desc', 'mileage_asc'];
if (!in_array($condition, $validConditions, true)) $condition = '';
if (!in_array($sort, $validSorts, true))           $sort = 'newest';

/**
 * SEARCH-2 (this pass): these three whitelists now come from
 * includes/filter-whitelists.php, shared with cars-for-sale/index.php.
 * This page's own copies had drifted from the corrected values already
 * live on the main browse page — 'Plug-in Hybrid' vs the real
 * 'Plug-in Hybrid (PHEV)', 'DSG/Dual-clutch' vs the real 'DSG', '4×4'
 * (Unicode multiplication sign) vs the real '4WD' — so checking any of
 * those boxes here could never actually match a row in cars.fuel_type /
 * cars.transmission / cars.drivetrain. One shared file now prevents
 * this class of bug from recurring.
 */
$fuelTypeWhitelist     = sdFuelTypeWhitelist();
$transmissionWhitelist = sdTransmissionWhitelist();
$drivetrainWhitelist   = sdDrivetrainWhitelist();

$fuelTypes = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['fuel_type'] ?? []))),
    $fuelTypeWhitelist
));
$transmissions = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['transmission'] ?? []))),
    $transmissionWhitelist
));
$drivetrains = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['drivetrain'] ?? []))),
    $drivetrainWhitelist
));

// ── Filter option sources — scoped to THIS desk's inventory only ──
$makesStmt = $pdo->prepare("
    SELECT DISTINCT c.make
    FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE bi.salesdesk_id = ? AND c.status = 'active'
    ORDER BY c.make
");
$makesStmt->execute([$salesdeskId]);
$makes = $makesStmt->fetchAll(PDO::FETCH_COLUMN);

$bodyTypesStmt = $pdo->prepare("
    SELECT DISTINCT c.body_type
    FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE bi.salesdesk_id = ? AND c.status = 'active' AND c.body_type IS NOT NULL
    ORDER BY c.body_type
");
$bodyTypesStmt->execute([$salesdeskId]);
$bodyTypes = $bodyTypesStmt->fetchAll(PDO::FETCH_COLUMN);

$provincesStmt = $pdo->prepare("
    SELECT DISTINCT da.province
    FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    JOIN dealers d           ON d.id = c.dealer_id
    LEFT JOIN addresses da   ON da.id = d.address_id
    WHERE bi.salesdesk_id = ? AND c.status = 'active' AND da.province IS NOT NULL
    ORDER BY da.province
");
$provincesStmt->execute([$salesdeskId]);
$provinces = $provincesStmt->fetchAll(PDO::FETCH_COLUMN);

$bodyTypesSelected = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['body_type'] ?? []))),
    $bodyTypes
));

$yearRangeStmt = $pdo->prepare("
    SELECT MIN(c.year) AS min_year, MAX(c.year) AS max_year
    FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE bi.salesdesk_id = ? AND c.status = 'active'
");
$yearRangeStmt->execute([$salesdeskId]);
$yearRange   = $yearRangeStmt->fetch();
$yearFloor   = (int) ($yearRange['min_year'] ?? (int) date('Y') - 10);
$yearCeiling = (int) ($yearRange['max_year'] ?? (int) date('Y'));

// ── Build WHERE — bi.salesdesk_id pins every query to this desk ──
$where  = ['bi.salesdesk_id = ?', "c.status = 'active'", 'd.is_active = 1'];
$params = [$salesdeskId];

if ($q) {
    $where[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year,' ',c.make,' ',c.model) LIKE ?)";
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($make)      { $where[] = 'c.make = ?';           $params[] = $make; }
if ($condition) { $where[] = 'c.condition_type = ?';  $params[] = $condition; }
if ($province)  { $where[] = 'da.province = ?';       $params[] = $province; }

if ($priceMin !== null)   { $where[] = 'c.price >= ?';   $params[] = $priceMin; }
if ($priceMax !== null)   { $where[] = 'c.price <= ?';   $params[] = $priceMax; }
if ($mileageMin !== null) { $where[] = 'c.mileage >= ?'; $params[] = $mileageMin; }
if ($mileageMax !== null) { $where[] = 'c.mileage <= ?'; $params[] = $mileageMax; }
if ($yearMin !== null)    { $where[] = 'c.year >= ?';    $params[] = $yearMin; }
if ($yearMax !== null)    { $where[] = 'c.year <= ?';    $params[] = $yearMax; }

if (!empty($bodyTypesSelected)) {
    $ph = implode(',', array_fill(0, count($bodyTypesSelected), '?'));
    $where[] = "c.body_type IN ({$ph})";
    $params  = array_merge($params, $bodyTypesSelected);
}
if (!empty($fuelTypes)) {
    $ph = implode(',', array_fill(0, count($fuelTypes), '?'));
    $where[] = "c.fuel_type IN ({$ph})";
    $params  = array_merge($params, $fuelTypes);
}
if (!empty($transmissions)) {
    $ph = implode(',', array_fill(0, count($transmissions), '?'));
    $where[] = "c.transmission IN ({$ph})";
    $params  = array_merge($params, $transmissions);
}
if (!empty($drivetrains)) {
    $ph = implode(',', array_fill(0, count($drivetrains), '?'));
    $where[] = "c.drivetrain IN ({$ph})";
    $params  = array_merge($params, $drivetrains);
}

$wc = 'WHERE ' . implode(' AND ', $where);

$sortSql = match ($sort) {
    'price_asc'   => 'c.price ASC',
    'price_desc'  => 'c.price DESC',
    'mileage_asc' => 'c.mileage ASC',
    default       => 'bi.added_at DESC',
};

// ── Count ──────────────────────────────────────────────────────
$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.id)
    FROM broker_inventory bi
    JOIN cars c            ON c.id = bi.car_id
    JOIN dealers d         ON d.id = c.dealer_id
    LEFT JOIN addresses da ON da.id = d.address_id
    {$wc}
");
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

// ── Fetch page ─────────────────────────────────────────────────
$listParams = array_merge($params, [$perPage, $offset]);
$carsStmt = $pdo->prepare("
    SELECT
        c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour,
        c.transmission, c.fuel_type, c.drivetrain,
        c.commission_type, c.commission_value,
        c.image_urls,
        bi.tracking_code, bi.added_at,
        d.company_name         AS dealer_name,
        d.verification_status  AS dealer_verified,
        da.city                AS dealer_city,
        da.province             AS dealer_province
    FROM broker_inventory bi
    JOIN cars c            ON c.id = bi.car_id
    JOIN dealers d         ON d.id = c.dealer_id
    LEFT JOIN addresses da ON da.id = d.address_id
    {$wc}
    ORDER BY {$sortSql}
    LIMIT ? OFFSET ?
");
$carsStmt->execute($listParams);
$inventory = $carsStmt->fetchAll();

// ── Desk stats (unfiltered — describes the whole desk, not the
//     current filter selection, so the hero numbers never jump
//     around as a buyer filters) ─────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT bi.id)                                       AS cars_on_desk,
        COUNT(DISTINCT l.id)                                        AS total_leads,
        COUNT(DISTINCT CASE WHEN l.status='closed' THEN l.id END)   AS deals_closed,
        COALESCE(SUM(bi.views), 0)                                  AS total_views
    FROM salesdesks sd
    LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
    LEFT JOIN cars c  ON c.id = bi.car_id AND c.status = 'active'
    LEFT JOIN leads l ON l.salesdesk_id = sd.id
    WHERE sd.id = ?
");
$statsStmt->execute([$salesdeskId]);
$stats = $statsStmt->fetch();

// ── Org membership ─────────────────────────────────────────────
$orgStmt = $pdo->prepare("
    SELECT o.name, o.slug, o.verification_status
    FROM organization_members om
    JOIN organizations o ON o.id = om.organization_id
    WHERE om.user_id = ? AND o.is_active = 1
    LIMIT 1
");
$orgStmt->execute([(int) $desk['user_id']]);
$org = $orgStmt->fetch();

// ── Wishlist state ─────────────────────────────────────────────
$wishlistedIds = [];
if (!empty($inventory)) {
    $wishlistedIds = getWishlistCarIds($visitor['id']);
}

// ── Active-filter count + query string builder (mirrors /cars-for-sale/) ──
$activeFilterCount = 0;
if ($q)         $activeFilterCount++;
if ($make)      $activeFilterCount++;
if ($condition) $activeFilterCount++;
if ($province)  $activeFilterCount++;
if ($priceMin !== null || $priceMax !== null)     $activeFilterCount++;
if ($mileageMin !== null || $mileageMax !== null) $activeFilterCount++;
if ($yearMin !== null || $yearMax !== null)        $activeFilterCount++;
$activeFilterCount += count($bodyTypesSelected);
$activeFilterCount += count($fuelTypes);
$activeFilterCount += count($transmissions);
$activeFilterCount += count($drivetrains);

$hasAnyActiveFilter = $activeFilterCount > 0;

$filterQs = http_build_query(array_filter([
    'q'            => $q ?: null,
    'make'         => $make ?: null,
    'condition'    => $condition ?: null,
    'body_type'    => $bodyTypesSelected ?: null,
    'fuel_type'    => $fuelTypes         ?: null,
    'transmission' => $transmissions     ?: null,
    'drivetrain'   => $drivetrains       ?: null,
    'province'     => $province ?: null,
    'price_min'    => $priceMin,
    'price_max'    => $priceMax,
    'mileage_min'  => $mileageMin,
    'mileage_max'  => $mileageMax,
    'year_min'     => $yearMin,
    'year_max'     => $yearMax,
    'sort'         => $sort !== 'newest' ? $sort : null,
], fn ($v) => $v !== null && $v !== '' && $v !== []));

/** Fuel icon helper — local copy so this file has no dependency on /cars-for-sale/. */
function brokerFuelIcon(string $fuel): string
{
    return match (strtolower($fuel)) {
        'electric'                 => 'fa-bolt',
        'hybrid', 'plug-in hybrid' => 'fa-leaf',
        'diesel'                   => 'fa-oil-can',
        'lpg'                      => 'fa-fire-flame-simple',
        default                    => 'fa-gas-pump',
    };
}

/**
 * Build a dismissal query string that removes one multi-select value
 * while preserving everything else. Same null-safe pattern as
 * /cars-for-sale/index.php.
 */
function brokerDismissQs(
    string $removeKey, string $removeVal,
    array $bodyTypesSelected, array $fuelTypes, array $transmissions, array $drivetrains,
    string $q, string $make, string $condition, string $province,
    ?int $priceMin, ?int $priceMax, ?int $mileageMin, ?int $mileageMax,
    ?int $yearMin, ?int $yearMax, string $sort
): string {
    $filter = static fn (array $arr, string $val): array =>
        array_values(array_filter($arr, fn ($x) => $x !== $val));

    $base = array_filter([
        'q'           => $q ?: null,
        'make'        => $make ?: null,
        'condition'   => $condition ?: null,
        'province'    => $province ?: null,
        'price_min'   => $priceMin,
        'price_max'   => $priceMax,
        'mileage_min' => $mileageMin,
        'mileage_max' => $mileageMax,
        'year_min'    => $yearMin,
        'year_max'    => $yearMax,
        'sort'        => $sort !== 'newest' ? $sort : null,
    ], fn ($v) => $v !== null && $v !== '');

    $arrays = [
        'body_type'    => $removeKey === 'body_type'    ? $filter($bodyTypesSelected, $removeVal) : $bodyTypesSelected,
        'fuel_type'    => $removeKey === 'fuel_type'    ? $filter($fuelTypes,          $removeVal) : $fuelTypes,
        'transmission' => $removeKey === 'transmission' ? $filter($transmissions,      $removeVal) : $transmissions,
        'drivetrain'   => $removeKey === 'drivetrain'   ? $filter($drivetrains,        $removeVal) : $drivetrains,
    ];

    $merged = $base;
    foreach ($arrays as $k => $v) {
        if (!empty($v)) $merged[$k] = $v;
    }
    return http_build_query($merged);
}

$provAbbr = [
    'Gauteng'       => 'GP',  'Western Cape'  => 'WC',  'KwaZulu-Natal' => 'KZN',
    'Eastern Cape'  => 'EC',  'Limpopo'       => 'LP',  'Mpumalanga'    => 'MP',
    'North West'    => 'NW',  'Free State'    => 'FS',  'Northern Cape' => 'NC',
];

// ── Page meta ──────────────────────────────────────────────────
$brokerName     = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? ''))
                  ?: $desk['display_name'];
$brokerInitials = strtoupper(
    substr($desk['first_name'] ?? '', 0, 1) . substr($desk['last_name'] ?? '', 0, 1)
) ?: 'SD';
$location = implode(', ', array_filter([$desk['suburb'], $desk['city'], $desk['province']]));

$siteUrl       = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$pageTitle     = htmlspecialchars($desk['display_name']) . ' | SalesDesk';
$ogTitle       = $desk['display_name'] . ' — Car Broker on SalesDesk';
$ogDescription = ($desk['tagline'] ?: 'Browse ' . (int) ($stats['cars_on_desk'] ?? 0) . ' cars listed by '
    . $brokerName . ' on SalesDesk South Africa.');
$ogImage       = $desk['logo_url'] ?: '';
$canonicalUrl  = $siteUrl . $deskPath;
$layoutVariant  = 'wide';
$showBreadcrumb = false;

$shareUrl   = $canonicalUrl;
$shareTitle = $desk['display_name'] . ' — Car Broker on SalesDesk';

// ── Page-scoped CSS ──────────────────────────────────────────────
// The hero / contact strip / bio / CTA sit inside a centred 1240px
// column. The filter sidebar + results grid below are pinned to the
// SAME max-width and side padding (overriding browse.css's own
// full-bleed margin-inline/padding-inline rhythm) so every section
// of the page shares identical left/right edges. On desktop the
// vehicle grid is also capped at 3 columns — instead of browse.css's
// auto-fill behaviour, which could stretch to 4-5 narrow columns on
// wide screens — so cards read wider and more substantial.
// SEARCH-1: .typeahead-box / .typeahead-item styles added here too,
// shared visually with cars-for-sale/index.php's own copy.
$extraCss = '<style>
.broker-container {
  max-width: 1240px;
  margin: 0 auto;
  padding: 28px 24px 0;
}
.broker-inventory-head {
  max-width: 1240px;
  margin: 0 auto 14px;
  padding: 0 24px;
}

/* Pin the filter sidebar + results grid to the same 1240px column
   and side padding as the hero/contact strip above, instead of
   browse.css\'s clamp()-based full-bleed margins. */
.browse-layout {
  max-width: 1240px;
  margin-left: auto;
  margin-right: auto;
  padding-left: 24px;
  padding-right: 24px;
}

/* Desktop: cap the vehicle grid at 3 columns (was auto-fill, which
   could produce 4-5 narrow columns on wide screens) so each card
   gets noticeably more width. Applies from tablet-landscape up;
   browse.css\'s own ≤768px single/2-column mobile rules are untouched. */
@media (min-width: 769px) {
  .pub-browse-grid {
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
  }
}

@media (max-width: 768px) {
  .broker-container      { padding: 16px 16px 0; }
  .broker-inventory-head { padding: 0 16px; margin-bottom: 10px; }
  .browse-layout          { padding-left: 16px; padding-right: 16px; }
}

/* SEARCH-1: search suggestion dropdown, shared with cars-for-sale/index.php */
.typeahead-box {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  box-shadow: var(--shadow-lg, 0 12px 32px rgba(0,0,0,.12));
  z-index: 40;
  overflow: hidden;
  display: none;
}
.typeahead-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  font-size: 13px;
  cursor: pointer;
}
.typeahead-item:hover,
.typeahead-item.focused { background: var(--bg); }
.typeahead-icon { font-size: 12px; color: var(--faint); flex-shrink: 0; }
.typeahead-label { flex: 1; color: var(--text); }
.typeahead-type {
  font-size: 10px; font-weight: 600; color: var(--faint);
  background: var(--bg); border-radius: var(--r-full);
  padding: 2px 8px;
}
</style>';

ob_start();
?>

<div class="broker-container">

  <!-- ── Broker hero ───────────────────────────────────────────── -->
  <div class="pub-broker-hero pub-anim">

    <div class="pub-broker-hero__avatar">
      <?php if ($desk['avatar_url']): ?>
      <img src="<?= htmlspecialchars($desk['avatar_url']) ?>"
           alt="<?= htmlspecialchars($brokerName) ?>"
           style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
      <?php else: ?>
      <?= htmlspecialchars($brokerInitials) ?>
      <?php endif; ?>
    </div>

    <div class="pub-broker-hero__name"><?= htmlspecialchars($desk['display_name']) ?></div>

    <?php if ($desk['tagline']): ?>
    <div class="pub-broker-hero__tagline"><?= htmlspecialchars($desk['tagline']) ?></div>
    <?php elseif ($desk['bio']): ?>
    <div class="pub-broker-hero__tagline">
      <?= htmlspecialchars(mb_strimwidth($desk['bio'], 0, 120, '…')) ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;position:relative;z-index:1;">
      <?php if ($location): ?>
      <span style="font-size:11px;color:rgba(255,255,255,.55);display:flex;align-items:center;gap:4px;">
        <i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($location) ?>
      </span>
      <?php endif; ?>
      <?php if ($org && $org['verification_status'] === 'verified'): ?>
      <span style="font-size:11px;background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);
                   border:1px solid rgba(255,255,255,.2);border-radius:var(--r-full);padding:2px 10px;">
        <i class="fa-solid fa-building" style="font-size:9px;margin-right:3px;"></i>
        <?= htmlspecialchars($org['name']) ?>
      </span>
      <?php endif; ?>
    </div>

    <div class="pub-broker-hero__stats">
      <div>
        <div class="pub-broker-hero__stat-num"><?= (int) ($stats['cars_on_desk'] ?? 0) ?></div>
        <div class="pub-broker-hero__stat-lbl">Cars listed</div>
      </div>
      <div>
        <div class="pub-broker-hero__stat-num"><?= number_format((int) ($stats['total_views'] ?? 0)) ?></div>
        <div class="pub-broker-hero__stat-lbl">Total views</div>
      </div>
      <div>
        <div class="pub-broker-hero__stat-num"><?= (int) ($stats['deals_closed'] ?? 0) ?></div>
        <div class="pub-broker-hero__stat-lbl">Deals closed</div>
      </div>
    </div>

    <div style="position:absolute;top:20px;right:20px;z-index:2;">
      <button onclick="openShareSheet()" type="button"
              style="width:36px;height:36px;border-radius:50%;
                     background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
                     color:#fff;display:flex;align-items:center;justify-content:center;
                     cursor:pointer;font-size:14px;transition:background .18s;">
        <i class="fa-solid fa-share-nodes"></i>
      </button>
    </div>

  </div>

  <!-- ── Contact strip ──────────────────────────────────────────── -->
  <?php if ($desk['phone']): ?>
  <div style="background:white;border:1px solid var(--border);border-radius:var(--r-lg);
              padding:14px 18px;margin-bottom:1.5rem;display:flex;align-items:center;
              justify-content:space-between;gap:12px;flex-wrap:wrap;
              box-shadow:var(--shadow-sm);" class="pub-anim pub-d1">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;border-radius:50%;background:var(--p-light);
                  color:var(--p);display:flex;align-items:center;justify-content:center;">
        <i class="fa-solid fa-user-tie" style="font-size:13px;"></i>
      </div>
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--text);"><?= htmlspecialchars($brokerName) ?></div>
        <div style="font-size:11px;color:var(--faint);">Independent auto broker</div>
      </div>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $desk['phone'])) ?>"
         class="pub-btn pub-btn-ghost" style="padding:8px 16px;font-size:12px;">
        <i class="fa-solid fa-phone"></i> Call
      </a>
      <a href="https://wa.me/27<?= ltrim(preg_replace('/\D/', '', $desk['phone']), '0') ?>"
         target="_blank" rel="noopener"
         style="display:inline-flex;align-items:center;gap:7px;padding:8px 16px;
                background:#25d366;color:#fff;border-radius:var(--r-md);font-size:12px;
                font-weight:600;font-family:var(--sans);text-decoration:none;">
        <i class="fa-brands fa-whatsapp"></i> WhatsApp
      </a>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /broker-container (hero + contact strip) -->


<!-- ══════════════════════════════════════════════════════
     INVENTORY — same sidebar filters + vehicle-card grid as /cars-for-sale/
     ══════════════════════════════════════════════════════ -->

<div class="broker-inventory-head pub-anim pub-d2">
  <span style="font-family:var(--font-d);font-size:16px;font-weight:700;">Available cars</span>
</div>

<button class="browse-filter-toggle" id="browseFilterToggle" type="button"
        aria-expanded="false" aria-controls="browseSidebar">
  <span>
    <i class="fa-solid fa-sliders" style="margin-right:6px;"></i>
    Filters
    <?php if ($activeFilterCount > 0): ?>
    <span class="browse-filter-toggle__badge"><?= $activeFilterCount ?></span>
    <?php endif; ?>
  </span>
  <i class="fa-solid fa-chevron-right" style="font-size:11px;color:var(--faint);"></i>
</button>

<div class="browse-sidebar-overlay" id="browseSidebarOverlay" aria-hidden="true"></div>

<div class="browse-layout">

  <!-- ══════════════════════════════
       FILTER SIDEBAR
       ══════════════════════════════ -->
  <aside class="browse-sidebar" id="browseSidebar"
         role="complementary" aria-label="Filter vehicles">

    <button class="browse-sidebar__close" id="browseSidebarClose" type="button"
            aria-label="Close filters">
      <span>Filters</span>
      <i class="fa-solid fa-xmark"></i>
    </button>

    <form method="GET" action="" id="filterForm">
      <div class="sidebar-card">

        <div class="sidebar-card__header">
          <span class="sidebar-card__title"><i class="fa-solid fa-sliders"></i> Filters</span>
          <a href="<?= htmlspecialchars($deskPath) ?>" class="sidebar-card__reset">Reset all</a>
        </div>

        <!-- Search -->
        <div class="filter-section" style="position:relative;">
          <span class="filter-section-label">Search</span>
          <div class="search-input-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input class="search-input-sidebar" type="text" name="q" id="sidebarSearch"
                   placeholder="Make, model, year…"
                   value="<?= htmlspecialchars($q) ?>" autocomplete="off">
          </div>
          <!-- SEARCH-1: live suggestions, scoped to this desk's own
               inventory via salesdesk_id (see script block below). -->
          <div id="sidebarSearchBox" class="typeahead-box" role="listbox"
               aria-label="Search suggestions"></div>
        </div>

        <!-- Condition -->
        <div class="filter-section">
          <span class="filter-section-label">Condition</span>
          <div class="condition-chip-row">
            <?php foreach (['new' => 'New', 'demo' => 'Demo', 'used' => 'Pre-Owned'] as $val => $label): ?>
            <label class="filter-chip <?= $condition === $val ? 'active' : '' ?>">
              <input class="sr-only" type="radio" name="condition" value="<?= $val ?>"
                     <?= $condition === $val ? 'checked' : '' ?>
                     onchange="this.form.submit()">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Budget -->
        <div class="filter-section">
          <span class="filter-section-label">Budget (ZAR)</span>
          <div class="min-max-row">
            <input class="min-max-input" type="number" name="price_min" placeholder="Min"
                   value="<?= $priceMin !== null ? $priceMin : '' ?>" min="0" step="1">
            <span class="min-max-sep">—</span>
            <input class="min-max-input" type="number" name="price_max" placeholder="Max"
                   value="<?= $priceMax !== null ? $priceMax : '' ?>" min="0" step="1">
          </div>
        </div>

        <!-- Mileage -->
        <div class="filter-section">
          <span class="filter-section-label">Mileage (km)</span>
          <div class="min-max-row">
            <input class="min-max-input" type="number" name="mileage_min" placeholder="Min"
                   value="<?= $mileageMin !== null ? $mileageMin : '' ?>" min="0" step="1">
            <span class="min-max-sep">—</span>
            <input class="min-max-input" type="number" name="mileage_max" placeholder="Max"
                   value="<?= $mileageMax !== null ? $mileageMax : '' ?>" min="0" step="1">
          </div>
        </div>

        <!-- Year of manufacture -->
        <div class="filter-section">
          <span class="filter-section-label">Year of manufacture</span>
          <div class="year-row">
            <select class="filter-select" name="year_min" onchange="this.form.submit()">
              <option value="">From</option>
              <?php for ($y = $yearFloor; $y <= $yearCeiling; $y++): ?>
              <option value="<?= $y ?>" <?= $yearMin === $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
            <select class="filter-select" name="year_max" onchange="this.form.submit()">
              <option value="">To</option>
              <?php for ($y = $yearFloor; $y <= $yearCeiling; $y++): ?>
              <option value="<?= $y ?>" <?= $yearMax === $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <!-- Make -->
        <?php if (!empty($makes)): ?>
        <div class="filter-section">
          <span class="filter-section-label">Brand / Make</span>
          <select class="filter-select" name="make" onchange="this.form.submit()">
            <option value="">All brands</option>
            <?php foreach ($makes as $m): ?>
            <option value="<?= htmlspecialchars($m) ?>" <?= $make === $m ? 'selected' : '' ?>>
              <?= htmlspecialchars($m) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <!-- Body Style -->
        <?php if (!empty($bodyTypes)): ?>
        <div class="filter-section">
          <span class="filter-section-label">Body Style</span>
          <div class="chip-row">
            <?php foreach ($bodyTypes as $bt): ?>
            <label class="filter-chip <?= in_array($bt, $bodyTypesSelected, true) ? 'active' : '' ?>">
              <input class="sr-only" type="checkbox" name="body_type[]"
                     value="<?= htmlspecialchars($bt) ?>"
                     <?= in_array($bt, $bodyTypesSelected, true) ? 'checked' : '' ?>
                     onchange="this.form.submit()">
              <?= htmlspecialchars($bt) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Fuel / Powertrain -->
        <div class="filter-section">
          <span class="filter-section-label">Fuel / Powertrain</span>
          <div class="chip-row">
            <?php foreach ($fuelTypeWhitelist as $ft): ?>
            <label class="filter-chip <?= in_array($ft, $fuelTypes, true) ? 'active' : '' ?>">
              <input class="sr-only" type="checkbox" name="fuel_type[]"
                     value="<?= htmlspecialchars($ft) ?>"
                     <?= in_array($ft, $fuelTypes, true) ? 'checked' : '' ?>
                     onchange="this.form.submit()">
              <?= htmlspecialchars($ft) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Transmission -->
        <div class="filter-section">
          <span class="filter-section-label">Transmission</span>
          <div class="chip-row">
            <?php foreach ($transmissionWhitelist as $tr): ?>
            <label class="filter-chip <?= in_array($tr, $transmissions, true) ? 'active' : '' ?>">
              <input class="sr-only" type="checkbox" name="transmission[]"
                     value="<?= htmlspecialchars($tr) ?>"
                     <?= in_array($tr, $transmissions, true) ? 'checked' : '' ?>
                     onchange="this.form.submit()">
              <?= htmlspecialchars($tr) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Drivetrain -->
        <div class="filter-section">
          <span class="filter-section-label">Drivetrain</span>
          <div class="chip-row">
            <?php foreach ($drivetrainWhitelist as $dr): ?>
            <label class="filter-chip <?= in_array($dr, $drivetrains, true) ? 'active' : '' ?>">
              <input class="sr-only" type="checkbox" name="drivetrain[]"
                     value="<?= htmlspecialchars($dr) ?>"
                     <?= in_array($dr, $drivetrains, true) ? 'checked' : '' ?>
                     onchange="this.form.submit()">
              <?= htmlspecialchars($dr) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Province -->
        <?php if (!empty($provinces)): ?>
        <div class="filter-section">
          <span class="filter-section-label">Province</span>
          <select class="filter-select" name="province" onchange="this.form.submit()">
            <option value="">All provinces</option>
            <?php foreach ($provinces as $pv): ?>
            <option value="<?= htmlspecialchars($pv) ?>" <?= $province === $pv ? 'selected' : '' ?>>
              <?= htmlspecialchars($pv) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="filter-apply-btn">Apply filters</button>

        <div class="sidebar-card__footer">
          <i class="fa-solid fa-bolt"></i>
          Filters submit instantly on select changes
        </div>

      </div><!-- /sidebar-card -->
    </form>

    <!-- SEARCH-1: search typeahead — replaces the old debounce-then-submit
         script. Scoped to THIS desk via salesdesk_id so suggestions can
         never surface a car outside this broker's own inventory. -->
    <script src="/assets/js/search-typeahead.js"></script>
    <script>
      initSearchTypeahead({
        inputId: 'sidebarSearch',
        boxId:   'sidebarSearchBox',
        extraParams: { salesdesk_id: <?= (int) $salesdeskId ?> }
      });
    </script>

  </aside><!-- /browseSidebar -->


  <!-- ══════════════════════════════
       MAIN RESULTS COLUMN
       ══════════════════════════════ -->
  <div class="browse-results">

    <div class="results-bar">
      <p class="results-bar__count">
        <span class="results-bar__count-num"><?= number_format($total) ?></span>
        vehicle<?= $total !== 1 ? 's' : '' ?> found
      </p>

      <?php
      $sortPreserve = array_filter([
          'q'           => $q ?: null,
          'make'        => $make ?: null,
          'condition'   => $condition ?: null,
          'province'    => $province ?: null,
          'price_min'   => $priceMin,
          'price_max'   => $priceMax,
          'mileage_min' => $mileageMin,
          'mileage_max' => $mileageMax,
          'year_min'    => $yearMin,
          'year_max'    => $yearMax,
      ], fn ($v) => $v !== null && $v !== '');
      ?>
      <form method="GET" action="" class="sort-form">
        <?php foreach ($sortPreserve as $k => $v): ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>"
               value="<?= htmlspecialchars((string) $v) ?>">
        <?php endforeach; ?>
        <?php foreach ($bodyTypesSelected as $bt): ?>
        <input type="hidden" name="body_type[]" value="<?= htmlspecialchars($bt) ?>">
        <?php endforeach; ?>
        <?php foreach ($fuelTypes as $ft): ?>
        <input type="hidden" name="fuel_type[]" value="<?= htmlspecialchars($ft) ?>">
        <?php endforeach; ?>
        <?php foreach ($transmissions as $tr): ?>
        <input type="hidden" name="transmission[]" value="<?= htmlspecialchars($tr) ?>">
        <?php endforeach; ?>
        <?php foreach ($drivetrains as $dr): ?>
        <input type="hidden" name="drivetrain[]" value="<?= htmlspecialchars($dr) ?>">
        <?php endforeach; ?>

        <select class="sort-select" name="sort" onchange="this.form.submit()"
                aria-label="Sort vehicles">
          <option value="newest"      <?= $sort === 'newest'      ? 'selected' : '' ?>>Newest first</option>
          <option value="price_asc"   <?= $sort === 'price_asc'   ? 'selected' : '' ?>>Price: low → high</option>
          <option value="price_desc"  <?= $sort === 'price_desc'  ? 'selected' : '' ?>>Price: high → low</option>
          <option value="mileage_asc" <?= $sort === 'mileage_asc' ? 'selected' : '' ?>>Lowest mileage</option>
        </select>
      </form>
    </div><!-- /results-bar -->

    <!-- Active filter dismissal tags -->
    <?php if ($hasAnyActiveFilter): ?>
    <div class="active-filter-tags">

      <?php if ($q): ?>
      <span class="active-filter-tag">
        "<?= htmlspecialchars($q) ?>"
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)q=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove search filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($make): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($make) ?>
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)make=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove make filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($condition): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars(ucfirst($condition)) ?>
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)condition=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove condition filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($province): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($province) ?>
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)province=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove province filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($priceMin !== null || $priceMax !== null): ?>
      <span class="active-filter-tag">
        <?= $priceMin !== null ? 'R ' . number_format($priceMin) : '' ?>
        <?= ($priceMin !== null && $priceMax !== null) ? ' – ' : '' ?>
        <?= $priceMax !== null ? 'R ' . number_format($priceMax) : '' ?>
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)price_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove price filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($mileageMin !== null || $mileageMax !== null): ?>
      <span class="active-filter-tag">
        <?= $mileageMin !== null ? number_format($mileageMin) . ' km' : '' ?>
        <?= ($mileageMin !== null && $mileageMax !== null) ? ' – ' : '' ?>
        <?= $mileageMax !== null ? number_format($mileageMax) . ' km' : '' ?>
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)mileage_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove mileage filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($yearMin !== null || $yearMax !== null): ?>
      <span class="active-filter-tag">
        <?= $yearMin ?? '?' ?> – <?= $yearMax ?? '?' ?>
        <a href="?<?= htmlspecialchars(preg_replace('/(?:^|&)year_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove year filter">✕</a>
      </span>
      <?php endif; ?>

      <?php foreach ($bodyTypesSelected as $bt): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($bt) ?>
        <a href="?<?= brokerDismissQs('body_type', $bt, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove body type filter">✕</a>
      </span>
      <?php endforeach; ?>

      <?php foreach ($fuelTypes as $ft): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($ft) ?>
        <a href="?<?= brokerDismissQs('fuel_type', $ft, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove fuel type filter">✕</a>
      </span>
      <?php endforeach; ?>

      <?php foreach ($transmissions as $tr): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($tr) ?>
        <a href="?<?= brokerDismissQs('transmission', $tr, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove transmission filter">✕</a>
      </span>
      <?php endforeach; ?>

      <?php foreach ($drivetrains as $dr): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($dr) ?>
        <a href="?<?= brokerDismissQs('drivetrain', $dr, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove drivetrain filter">✕</a>
      </span>
      <?php endforeach; ?>

    </div><!-- /active-filter-tags -->
    <?php endif; ?>


    <!-- ── Car Grid ──────────────────────────────────────── -->
    <?php if (empty($inventory)): ?>

      <?php if ((int) ($stats['cars_on_desk'] ?? 0) === 0): ?>
      <div class="empty">
        <i class="fa-solid fa-car empty-icon"></i>
        <div style="font-family:var(--font-d);font-size:16px;font-weight:700;color:var(--text);margin-bottom:6px;">
          No listings yet
        </div>
        <div>Check back soon — <?= htmlspecialchars($brokerName) ?> is adding cars to their desk.</div>
      </div>
      <?php else: ?>
      <div class="browse-empty">
        <i class="fa-regular fa-face-meh-blank browse-empty__icon"></i>
        <div class="browse-empty__title">No vehicles match your filters</div>
        <div class="browse-empty__sub">Try adjusting your budget, province, or body type.</div>
        <a href="<?= htmlspecialchars($deskPath) ?>" class="browse-empty__reset">Reset all filters</a>
      </div>
      <?php endif; ?>

    <?php else: ?>

    <div class="pub-browse-grid" id="carGrid"
         style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr));">
      <?php foreach ($inventory as $car):
        $imgs   = json_decode($car['image_urls'] ?? '[]', true) ?: [];
        $thumb  = $imgs[0] ?? null;
        // ROUTE-2: was '/c/' . slug . '/' . slug . '/' — the retired
        // pre-rename path. Every car-detail link elsewhere in the
        // codebase already builds /cars-for-sale/{desk}/{car}/; this
        // was the last holdout still generating the old form. The
        // .htaccess legacy redirect still catches any bookmarked /c/
        // links, but new links generated here skip that extra hop.
        $carUrl = '/cars-for-sale/' . htmlspecialchars($desk['slug']) . '/'
                . htmlspecialchars($car['car_slug']) . '/'
                . '?ref=' . urlencode($car['tracking_code']);
        $priceStr = 'R ' . number_format((float) $car['price'], 0, '.', ' ');
        $prov     = $provAbbr[$car['dealer_province'] ?? ''] ?? ($car['dealer_province'] ?? '');
        $isNew    = $car['condition_type'] === 'new';
        $isEV     = strtolower($car['fuel_type'] ?? '') === 'electric';
        $isWl     = in_array((int) $car['id'], $wishlistedIds, true);
      ?>
      <div class="vehicle-card" style="position:relative;">

        <!-- Wishlist toggle (kept — sits above the card link) -->
        <button onclick="toggleWishlist(this, <?= (int) $car['id'] ?>); event.preventDefault();"
                type="button"
                class="pub-nav-icon-btn <?= $isWl ? 'wishlisted' : '' ?>"
                title="<?= $isWl ? 'Remove from saved' : 'Save car' ?>"
                style="position:absolute;top:10px;right:10px;z-index:2;background:rgba(255,255,255,.92);">
          <i class="fa-<?= $isWl ? 'solid' : 'regular' ?> fa-heart"></i>
        </button>

        <a href="<?= $carUrl ?>" style="display:contents;">

          <!-- Image block -->
          <div class="vehicle-card__img">
            <?php if ($thumb): ?>
            <img src="<?= htmlspecialchars($thumb) ?>"
                 alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
                 loading="lazy" width="400" height="225">
            <?php else: ?>
            <div class="vehicle-card__img-placeholder">
              <i class="fa-solid fa-car-side"></i>
            </div>
            <?php endif; ?>

            <span class="vehicle-card__pill vehicle-card__pill--year">
              <?= (int) $car['year'] ?>
            </span>

            <?php if ($prov): ?>
            <span class="vehicle-card__pill vehicle-card__pill--province">
              <i class="fa-solid fa-location-dot pill-icon"></i><?= htmlspecialchars($prov) ?>
            </span>
            <?php endif; ?>

            <?php if ($car['mileage']): ?>
            <span class="vehicle-card__pill vehicle-card__pill--mileage">
              <i class="fa-solid fa-road pill-icon"></i><?= number_format((int) $car['mileage']) ?> km
            </span>
            <?php endif; ?>

            <?php if ($isNew): ?>
            <span class="vehicle-card__pill vehicle-card__pill--new">NEW</span>
            <?php elseif ($isEV): ?>
            <span class="vehicle-card__pill vehicle-card__pill--ev">EV</span>
            <?php endif; ?>
          </div><!-- /vehicle-card__img -->

          <!-- Card body -->
          <div class="vehicle-card__body">
            <div class="vehicle-card__name-row">
              <div class="vehicle-card__name-group">
                <div class="vehicle-card__name">
                  <?= htmlspecialchars("{$car['make']} {$car['model']}") ?>
                </div>
                <div class="vehicle-card__dealer">
                  <i class="fa-regular fa-building"></i><?= htmlspecialchars($car['dealer_name']) ?>
                </div>
              </div>
              <span class="vehicle-card__price"><?= $priceStr ?></span>
            </div>

            <?php if ($car['dealer_verified'] === 'verified'): ?>
            <div class="vehicle-card__meta-row">
              <span class="verified-badge">
                <i class="fa-solid fa-circle-check"></i> Verified
              </span>
            </div>
            <?php endif; ?>

            <!-- Spec pills with icons -->
            <div class="vehicle-card__specs">
              <?php if ($car['fuel_type']): ?>
              <span class="vehicle-card__spec-pill">
                <i class="fa-solid <?= brokerFuelIcon($car['fuel_type']) ?>"></i><?= htmlspecialchars($car['fuel_type']) ?>
              </span>
              <?php endif; ?>
              <?php if ($car['transmission']): ?>
              <span class="vehicle-card__spec-pill">
                <i class="fa-solid fa-gear"></i><?= htmlspecialchars($car['transmission']) ?>
              </span>
              <?php endif; ?>
              <?php if ($car['drivetrain']): ?>
              <span class="vehicle-card__spec-pill"><?= htmlspecialchars($car['drivetrain']) ?></span>
              <?php endif; ?>
              <?php if ($car['body_type']): ?>
              <span class="vehicle-card__spec-pill"><?= htmlspecialchars($car['body_type']) ?></span>
              <?php endif; ?>
            </div>
          </div><!-- /vehicle-card__body -->

        </a><!-- /vehicle-card link -->
      </div><!-- /vehicle-card -->
      <?php endforeach; ?>
    </div><!-- /carGrid -->


    <!-- ── Pagination ─────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Browse pages">

      <?php if ($page > 1): ?>
      <a href="?<?= $filterQs ?>&page=<?= $page - 1 ?>"
         class="pagination__page pagination__page--nav"
         aria-label="Previous page">
        <i class="fa-solid fa-chevron-left"></i>
      </a>
      <?php endif; ?>

      <?php
      $prev      = null;
      $pageRange = [];
      for ($p = 1; $p <= $totalPages; $p++) {
          if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) $pageRange[] = $p;
      }
      foreach ($pageRange as $p):
          if ($prev !== null && $p - $prev > 1): ?>
          <span class="pagination__ellipsis">…</span>
          <?php endif; ?>
          <a href="?<?= $filterQs ?>&page=<?= $p ?>"
             class="pagination__page <?= $p === $page ? 'active' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?>>
            <?= $p ?>
          </a>
      <?php $prev = $p; endforeach; ?>

      <?php if ($page < $totalPages): ?>
      <a href="?<?= $filterQs ?>&page=<?= $page + 1 ?>"
         class="pagination__page pagination__page--nav"
         aria-label="Next page">
        <i class="fa-solid fa-chevron-right"></i>
      </a>
      <?php endif; ?>

    </nav>
    <?php endif; ?>

    <?php endif; // end inventory not empty ?>

  </div><!-- /browse-results -->
</div><!-- /browse-layout -->


<div class="broker-container">

  <!-- ── Bio section ───────────────────────────────────────────── -->
  <?php if ($desk['bio']): ?>
  <div style="margin-top:2rem;background:white;border:1px solid var(--border);
              border-radius:var(--r-lg);padding:20px;box-shadow:var(--shadow-sm);" class="pub-reveal">
    <div style="font-family:var(--font-d);font-size:14px;font-weight:700;margin-bottom:10px;">
      About <?= htmlspecialchars($brokerName) ?>
    </div>
    <div style="font-size:13px;color:var(--muted);line-height:1.75;">
      <?= nl2br(htmlspecialchars($desk['bio'])) ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── CTA ───────────────────────────────────────────────────── -->
  <div class="pub-reveal" style="margin-top:2rem;text-align:center;
       background:linear-gradient(135deg,#08143c,var(--p));
       border-radius:var(--r-xl);padding:2rem 1.5rem;color:#fff;">
    <div style="font-family:var(--font-d);font-size:18px;font-weight:700;margin-bottom:6px;">
      Looking for a specific car?
    </div>
    <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:1.25rem;">
      Browse our full catalogue across all broker desks.
    </p>
    <!-- ROUTE-2: was href="/c/" — updated to the current browse route. -->
    <a href="/cars-for-sale/" class="pub-btn pub-btn-ghost"
       style="display:inline-flex;padding:10px 24px;font-size:13px;
              background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff;">
      Browse all cars →
    </a>
  </div>

</div><!-- /broker-container (bio + cta) -->

<script>
(function () {
  'use strict';

  /* ── Filter sidebar drawer (mobile) ────────────────────────── */
  var sidebar   = document.getElementById('browseSidebar');
  var overlay   = document.getElementById('browseSidebarOverlay');
  var toggleBtn = document.getElementById('browseFilterToggle');
  var closeBtn  = document.getElementById('browseSidebarClose');

  function openDrawer() {
    if (!sidebar || !overlay) return;
    sidebar.classList.add('drawer-open');
    overlay.classList.add('open');
    overlay.removeAttribute('aria-hidden');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('drawer-open');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', openDrawer);
  if (closeBtn)  closeBtn.addEventListener('click', closeDrawer);
  if (overlay)   overlay.addEventListener('click', closeDrawer);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('drawer-open')) {
      closeDrawer();
    }
  });

})();
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
