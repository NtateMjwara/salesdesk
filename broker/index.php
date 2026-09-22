<?php
/**
 * SalesDesk — Broker Public Storefront  (v5)
 * Route: /{broker-slug}/   (via .htaccess → broker/index.php?slug=…)
 *
 * v5 CHANGES (public UX/UI overhaul — desk pages pass):
 *   DS-1  Page rebuilt on the shared design system: ink desk hero with
 *         stats and contact actions, About + contact panels, then the
 *         inventory section using the SAME browse UI as /cars-for-sale/
 *         (collapsible filters, mobile filter sheet, sticky results
 *         toolbar, sort, grid/list toggle, removable filter chips).
 *   DS-2  Cars render through views/partials/vehicle-card.php — the one
 *         shared card used on the homepage, browse and car detail — so a
 *         desk's listings look and behave exactly like everywhere else
 *         (save button, monthly estimate, photo count, verified seller).
 *         The desk's own bi.tracking_code is still passed as ?ref= so
 *         attribution is unchanged.
 *   DS-3  ALL inline <style>, <script>, style="" and onchange="" removed,
 *         including the duplicate filter-drawer script (browse.js owns
 *         it) and the inline typeahead init (public-nav.js owns it).
 *         Page CSS: assets/css/desks.css (+ browse.css). Page JS: browse.js.
 *   DS-4  Filter chips are built from one canonical param set (same
 *         helper shape as /cars-for-sale/), replacing the regex-based
 *         dismissal links.
 *   DS-5  Stats strip: "total views" replaced with "cars sold / deals
 *         closed" first — a buyer cares about completed deals, and view
 *         counts on a public page are vanity metrics.
 *
 * v4 CHANGES:
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
 *   WIDE-1    Storefront container/sidebar/grid max-width raised from
 *              1240px to 100% (see the three rules inside $extraCss
 *              below) so the page uses the full viewport width. The
 *              inner .pub-browse-grid still caps at 3 columns via its
 *              own @media rule below (untouched) — cards simply get
 *              wider on large screens instead of the grid gaining more
 *              columns. NOTE: the equivalent .pub-page-narrow rule in
 *              assets/css/public.css was updated the same way, but this
 *              page doesn't actually use that class ($layoutVariant is
 *              'wide' below) — these three inline rules are what govern
 *              this page's width. Kept inline here intentionally to
 *              bust cache immediately; can be removed once
 *              assets/css/public.css's own change has propagated.
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
        c.id, c.slug AS car_slug, c.make, c.model, c.variant, c.year, c.price,
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
$pageTitle     = $desk['display_name'] . ' — Cars for sale | SalesDesk';
$ogTitle       = $desk['display_name'] . ' — Car Broker on SalesDesk';
$ogDescription = ($desk['tagline'] ?: 'Browse ' . (int) ($stats['cars_on_desk'] ?? 0) . ' cars listed by '
    . $brokerName . ' on SalesDesk South Africa.');
$ogImage       = $desk['logo_url'] ?: '';
$canonicalUrl  = $siteUrl . $deskPath;
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [['Find a SalesDesk', '/desks/'], [$desk['display_name'], null]];

$shareUrl   = $canonicalUrl;
$shareTitle = $desk['display_name'] . ' — Car Broker on SalesDesk';

// ── DS-4: canonical param set + URL builder for every link here ──
$currentParams = array_filter([
    'q'            => $q ?: null,
    'make'         => $make ?: null,
    'condition'    => $condition ?: null,
    'body_type'    => $bodyTypesSelected ?: null,
    'fuel_type'    => $fuelTypes ?: null,
    'transmission' => $transmissions ?: null,
    'drivetrain'   => $drivetrains ?: null,
    'province'     => $province ?: null,
    'price_min'    => $priceMin,
    'price_max'    => $priceMax,
    'mileage_min'  => $mileageMin,
    'mileage_max'  => $mileageMax,
    'year_min'     => $yearMin,
    'year_max'     => $yearMax,
    'sort'         => $sort !== 'newest' ? $sort : null,
], fn($v) => $v !== null && $v !== '' && $v !== []);

$deskUrlFor = static function (array $set = [], array $remove = []) use ($currentParams, $deskPath): string {
    $p = $currentParams;
    unset($p['page']);
    foreach ($set as $k => $v) {
        if ($v === null) unset($p[$k]); else $p[$k] = $v;
    }
    foreach ($remove as $k => $v) {
        if (isset($p[$k]) && is_array($p[$k])) {
            $p[$k] = array_values(array_filter($p[$k], fn($x) => $x !== $v));
            if (!$p[$k]) unset($p[$k]);
        }
    }
    $qs = http_build_query($p);
    return $deskPath . ($qs !== '' ? '?' . $qs : '');
};

$fmtR  = static fn(int $n): string => $n >= 1000000
    ? 'R' . rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'm'
    : 'R' . number_format($n / 1000) . 'k';
$fmtKm = static fn(int $n): string => number_format($n / 1000) . 'k km';

$chips = [];
if ($q)         $chips[] = ['“' . $q . '”', $deskUrlFor(['q' => null])];
if ($condition) $chips[] = [ucfirst($condition), $deskUrlFor(['condition' => null])];
if ($make)      $chips[] = [$make, $deskUrlFor(['make' => null])];
foreach ($bodyTypesSelected as $v) $chips[] = [$v, $deskUrlFor([], ['body_type' => $v])];
if ($priceMin !== null || $priceMax !== null) {
    $chips[] = [
        $priceMin !== null && $priceMax !== null ? $fmtR($priceMin) . ' – ' . $fmtR($priceMax)
            : ($priceMax !== null ? 'Under ' . $fmtR($priceMax) : 'From ' . $fmtR($priceMin)),
        $deskUrlFor(['price_min' => null, 'price_max' => null]),
    ];
}
if ($yearMin !== null || $yearMax !== null) {
    $chips[] = [
        $yearMin !== null && $yearMax !== null ? $yearMin . ' – ' . $yearMax : ($yearMin !== null ? $yearMin . ' or newer' : $yearMax . ' or older'),
        $deskUrlFor(['year_min' => null, 'year_max' => null]),
    ];
}
if ($mileageMin !== null || $mileageMax !== null) {
    $chips[] = [
        $mileageMax !== null ? 'Under ' . $fmtKm($mileageMax) : 'Over ' . $fmtKm((int) $mileageMin),
        $deskUrlFor(['mileage_min' => null, 'mileage_max' => null]),
    ];
}
foreach ($fuelTypes as $v)     $chips[] = [$v, $deskUrlFor([], ['fuel_type' => $v])];
foreach ($transmissions as $v) $chips[] = [$v, $deskUrlFor([], ['transmission' => $v])];
foreach ($drivetrains as $v)   $chips[] = [$v, $deskUrlFor([], ['drivetrain' => $v])];
if ($province) $chips[] = [$province, $deskUrlFor(['province' => null])];

// ── Filter presets / open sections (same shape as /cars-for-sale/) ──
$pricePresets   = [50000, 100000, 150000, 200000, 250000, 300000, 400000, 500000, 600000, 750000, 1000000, 1500000, 2000000];
$mileagePresets = [10000, 30000, 50000, 80000, 100000, 150000, 200000];
foreach ([$priceMin, $priceMax] as $v)     if ($v !== null && !in_array($v, $pricePresets, true))   $pricePresets[] = $v;
foreach ([$mileageMin, $mileageMax] as $v) if ($v !== null && !in_array($v, $mileagePresets, true)) $mileagePresets[] = $v;
sort($pricePresets);
sort($mileagePresets);

$openSection = [
    'condition' => true,
    'price'     => true,
    'make'      => !empty($makes),
    'body'      => !empty($bodyTypes),
    'year'      => $yearMin !== null || $yearMax !== null,
    'mileage'   => $mileageMin !== null || $mileageMax !== null,
    'fuel'      => !empty($fuelTypes),
    'trans'     => !empty($transmissions),
    'drive'     => !empty($drivetrains),
    'province'  => (bool) $province,
];

$firstShown  = $total > 0 ? $offset + 1 : 0;
$lastShown   = min($offset + $perPage, $total);
$deskCars    = (int) ($stats['cars_on_desk'] ?? 0);
$phoneDigits = $desk['phone'] ? preg_replace('/\D/', '', (string) $desk['phone']) : '';
$waNumber    = $phoneDigits !== ''
    ? (str_starts_with($phoneDigits, '27') ? $phoneDigits : '27' . ltrim($phoneDigits, '0'))
    : '';
$waLink      = $waNumber !== ''
    ? 'https://wa.me/' . $waNumber . '?text=' . rawurlencode("Hi {$brokerName}, I found your SalesDesk ({$canonicalUrl}) and I'm looking for a car.")
    : '';
$isOrgVerified = $org && ($org['verification_status'] ?? '') === 'verified';

$assetVersion         = $assetVersion ?? date('Ymd');
$extraCss             = '<link rel="stylesheet" href="/assets/css/desks.css?v=' . $assetVersion . '">' . "\n";
$extraJs              = ['/assets/js/browse.js'];
$includeHowItWorksCss = false;

require_once __DIR__ . '/../views/partials/vehicle-card.php';
require_once __DIR__ . '/../views/partials/body-type-icon.php';

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

ob_start();
?>

<div class="sd-container ds">

  <!-- ══════════════════════════════════
       DESK HERO
       ══════════════════════════════════ -->
  <section class="ds-hero pub-anim" aria-labelledby="dsName">
    <div class="ds-hero__grid">
      <div class="ds-hero__id">
        <span class="ds-avatar">
          <?php if ($desk['avatar_url']): ?>
          <img src="<?= $e($desk['avatar_url']) ?>" alt="" width="84" height="84">
          <?php elseif ($desk['logo_url']): ?>
          <img src="<?= $e($desk['logo_url']) ?>" alt="" width="84" height="84">
          <?php else: ?>
          <?= $e($brokerInitials) ?>
          <?php endif; ?>
        </span>

        <div>
          <h1 class="ds-hero__name" id="dsName"><?= $e($desk['display_name']) ?></h1>
          <p class="ds-hero__broker">
            <?= $e($brokerName) ?> · Independent SalesDesk broker
          </p>
          <div class="ds-hero__tags">
            <?php if ($location): ?>
            <span class="ds-tag"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $e($location) ?></span>
            <?php endif; ?>
            <?php if ($isOrgVerified): ?>
            <span class="ds-tag ds-tag--verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= $e($org['name']) ?></span>
            <?php endif; ?>
            <span class="ds-tag"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Commission-protected</span>
          </div>
        </div>
      </div>

      <div class="ds-hero__actions">
        <?php if ($waLink): ?>
        <a class="pub-btn pub-btn-whatsapp" href="<?= $e($waLink) ?>" target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp
        </a>
        <?php endif; ?>
        <?php if ($desk['phone']): ?>
        <a class="pub-btn pub-btn-on-ink" href="tel:<?= $e($phoneDigits) ?>">
          <i class="fa-solid fa-phone" aria-hidden="true"></i> Call
        </a>
        <?php endif; ?>
        <button class="pub-btn pub-btn-on-ink" type="button" data-share-open>
          <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Share desk
        </button>
      </div>
    </div>

    <?php if ($desk['tagline']): ?>
    <p class="ds-hero__tagline"><?= $e($desk['tagline']) ?></p>
    <?php endif; ?>

    <ul class="ds-hero__stats">
      <li><strong><?= $deskCars ?></strong><span>Car<?= $deskCars === 1 ? '' : 's' ?> on this desk</span></li>
      <li><strong><?= (int) ($stats['deals_closed'] ?? 0) ?></strong><span>Deals closed</span></li>
      <li><strong><?= (int) ($stats['total_leads'] ?? 0) ?></strong><span>Buyer enquiries</span></li>
      <li><strong><?= number_format((int) ($stats['total_views'] ?? 0)) ?></strong><span>Listing views</span></li>
    </ul>
  </section>

  <!-- ══════════════════════════════════
       ABOUT + CONTACT
       ══════════════════════════════════ -->
  <div class="ds-about ds-about--split">
    <?php if ($desk['bio']): ?>
    <section class="ds-panel" aria-labelledby="dsAboutTitle">
      <h2 class="ds-panel__title" id="dsAboutTitle">About <?= $e($brokerName) ?></h2>
      <div class="ds-panel__text is-clamped" id="deskBio" data-clamp><?= $e($desk['bio']) ?></div>
      <button class="pub-link" type="button" data-clamp-toggle="deskBio" aria-expanded="false" aria-controls="deskBio">
        <span>Read more</span> <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
      </button>
    </section>
    <?php else: ?>
    <!-- No bio yet: explain the model instead of leaving a half-empty row -->
    <section class="ds-panel" aria-labelledby="dsHowTitle">
      <h2 class="ds-panel__title" id="dsHowTitle">Buying through a SalesDesk broker</h2>
      <ol class="ds-steps">
        <li><span class="ds-steps__n">1</span><span><strong>Pick a car</strong> from this desk — every listing comes from a verified dealership.</span></li>
        <li><span class="ds-steps__n">2</span><span><strong>Enquire once.</strong> <?= $e(explode(' ', $brokerName)[0]) ?> handles the dealer, the viewing and the paperwork with you.</span></li>
        <li><span class="ds-steps__n">3</span><span><strong>Pay nothing extra.</strong> The dealer pays the commission — the price you see is the price you pay.</span></li>
      </ol>
    </section>
    <?php endif; ?>

    <section class="ds-panel ds-contact" aria-labelledby="dsContactTitle">
      <h2 class="ds-panel__title" id="dsContactTitle">Talk to <?= $e(explode(' ', $brokerName)[0]) ?></h2>
      <div class="ds-contact__row">
        <span class="ds-contact__icon"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span>
        <span>
          <strong><?= $e($brokerName) ?></strong><br>
          <?= $e($location ?: 'South Africa') ?>
        </span>
      </div>
      <div class="ds-contact__actions">
        <?php if ($waLink): ?>
        <a class="pub-btn pub-btn-whatsapp pub-btn-full" href="<?= $e($waLink) ?>" target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Message on WhatsApp
        </a>
        <?php endif; ?>
        <?php if ($desk['phone']): ?>
        <a class="pub-btn pub-btn-ghost pub-btn-full" href="tel:<?= $e($phoneDigits) ?>">
          <i class="fa-solid fa-phone" aria-hidden="true"></i> <?= $e($desk['phone']) ?>
        </a>
        <?php else: ?>
        <a class="pub-btn pub-btn-primary pub-btn-full" href="#inventory">
          <i class="fa-solid fa-car" aria-hidden="true"></i> Enquire on a car
        </a>
        <?php endif; ?>
      </div>
      <ul class="ds-trust">
        <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Enquiries are tracked and commission-protected</li>
        <li><i class="fa-solid fa-lock" aria-hidden="true"></i> POPIA compliant · free for buyers</li>
      </ul>
    </section>
  </div>

  <!-- ══════════════════════════════════
       INVENTORY
       ══════════════════════════════════ -->
  <section class="ds-inventory" id="inventory" aria-labelledby="dsInvTitle">
    <div class="ds-inventory__head">
      <div>
        <h2 class="ds-inventory__title" id="dsInvTitle">Cars on this desk</h2>
        <p class="ds-inventory__sub">
          <?php if ($deskCars > 0): ?>
          <strong><?= number_format($deskCars) ?></strong> car<?= $deskCars === 1 ? '' : 's' ?> from verified dealerships, listed by <?= $e($brokerName) ?>
          <?php else: ?>
          <?= $e($brokerName) ?> is still adding cars to this desk
          <?php endif; ?>
        </p>
      </div>
    </div>

    <?php if ($deskCars === 0): ?>
    <!-- Nothing on the desk yet: filters would be an empty control panel -->
    <div class="pub-empty">
      <span class="pub-empty__icon"><i class="fa-solid fa-car-side" aria-hidden="true"></i></span>
      <h3 class="pub-empty__title">No cars on this desk yet</h3>
      <p class="pub-empty__sub">
        <?= $e($brokerName) ?> is still adding stock. Browse every car on SalesDesk in the meantime —
        <?= $e(explode(' ', $brokerName)[0]) ?> can still help you buy any of them.
      </p>
      <div class="pub-empty__actions">
        <a class="pub-btn pub-btn-primary" href="/cars-for-sale/">Browse all cars</a>
        <?php if ($waLink): ?>
        <a class="pub-btn pub-btn-whatsapp" href="<?= $e($waLink) ?>" target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp <?= $e(explode(' ', $brokerName)[0]) ?>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>

    <div class="browse-sidebar-overlay" id="browseSidebarOverlay" aria-hidden="true"></div>

    <div class="browse-layout browse-layout--flush">

      <!-- Filters -->
      <aside class="browse-sidebar" id="browseSidebar" aria-label="Filter this desk">
        <form method="GET" action="<?= $e($deskPath) ?>" id="filterForm" class="sidebar-card"
              data-browse-filters data-desk-scoped>
          <?php if ($sort !== 'newest'): ?>
          <input type="hidden" name="sort" value="<?= $e($sort) ?>">
          <?php endif; ?>

          <div class="sidebar-card__header">
            <span class="sidebar-card__title">Filters<?php if ($activeFilterCount): ?> <span class="sidebar-card__count"><?= $activeFilterCount ?></span><?php endif; ?></span>
            <a href="<?= $e($deskPath) ?>" class="sidebar-card__reset" <?= $activeFilterCount ? '' : 'hidden' ?>>Reset</a>
            <button class="browse-sidebar__close" id="browseSidebarClose" type="button" aria-label="Close filters">
              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
          </div>

          <div class="sidebar-card__body">

            <div class="filter-section filter-section--static">
              <label class="filter-section-label" for="sidebarSearch">Keyword</label>
              <div class="search-input-wrap">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input class="search-input-sidebar" type="search" name="q" id="sidebarSearch"
                       placeholder="Make, model, year…" value="<?= $e($q) ?>"
                       autocomplete="off" enterkeyhint="search"
                       data-typeahead-box="sidebarSearchBox"
                       data-typeahead-params='{"salesdesk_id": <?= (int) $salesdeskId ?>}'>
                <div id="sidebarSearchBox" class="typeahead-box" role="listbox" aria-label="Search suggestions"></div>
              </div>
            </div>

            <details class="filter-section" <?= $openSection['condition'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Condition</summary>
              <div class="pub-segment filter-segment" role="radiogroup" aria-label="Condition">
                <?php foreach (['' => 'Any', 'used' => 'Used', 'new' => 'New', 'demo' => 'Demo'] as $val => $label): ?>
                <label class="pub-segment__opt">
                  <input type="radio" name="condition" value="<?= $val ?>" data-autosubmit <?= $condition === $val ? 'checked' : '' ?>>
                  <span><?= $label ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </details>

            <details class="filter-section" <?= $openSection['price'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Price</summary>
              <div class="min-max-row">
                <select class="filter-select" name="price_min" aria-label="Minimum price" data-autosubmit>
                  <option value="">No min</option>
                  <?php foreach ($pricePresets as $pp): ?>
                  <option value="<?= $pp ?>" <?= $priceMin === $pp ? 'selected' : '' ?>><?= $fmtR($pp) ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="min-max-sep" aria-hidden="true">–</span>
                <select class="filter-select" name="price_max" aria-label="Maximum price" data-autosubmit>
                  <option value="">No max</option>
                  <?php foreach ($pricePresets as $pp): ?>
                  <option value="<?= $pp ?>" <?= $priceMax === $pp ? 'selected' : '' ?>><?= $fmtR($pp) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </details>

            <?php if (!empty($makes)): ?>
            <details class="filter-section" <?= $openSection['make'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Make</summary>
              <select class="filter-select" name="make" aria-label="Make" data-autosubmit>
                <option value="">All makes</option>
                <?php foreach ($makes as $m): ?>
                <option value="<?= $e($m) ?>" <?= $make === $m ? 'selected' : '' ?>><?= $e($m) ?></option>
                <?php endforeach; ?>
              </select>
            </details>
            <?php endif; ?>

            <?php if (!empty($bodyTypes)): ?>
            <details class="filter-section" <?= $openSection['body'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Body type</summary>
              <div class="body-tile-grid">
                <?php foreach ($bodyTypes as $bt): $on = in_array($bt, $bodyTypesSelected, true); ?>
                <label class="body-tile <?= $on ? 'active' : '' ?>">
                  <input class="sr-only" type="checkbox" name="body_type[]" value="<?= $e($bt) ?>" data-autosubmit <?= $on ? 'checked' : '' ?>>
                  <?= sdBodyTypeIcon($bt, 'body-tile__icon') ?>
                  <span><?= $e($bt) ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </details>
            <?php endif; ?>

            <details class="filter-section" <?= $openSection['year'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Year</summary>
              <div class="min-max-row">
                <select class="filter-select" name="year_min" aria-label="Year from" data-autosubmit>
                  <option value="">From</option>
                  <?php for ($y = $yearCeiling; $y >= $yearFloor; $y--): ?>
                  <option value="<?= $y ?>" <?= $yearMin === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endfor; ?>
                </select>
                <span class="min-max-sep" aria-hidden="true">–</span>
                <select class="filter-select" name="year_max" aria-label="Year to" data-autosubmit>
                  <option value="">To</option>
                  <?php for ($y = $yearCeiling; $y >= $yearFloor; $y--): ?>
                  <option value="<?= $y ?>" <?= $yearMax === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endfor; ?>
                </select>
              </div>
            </details>

            <details class="filter-section" <?= $openSection['mileage'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Mileage</summary>
              <div class="min-max-row">
                <select class="filter-select" name="mileage_min" aria-label="Minimum mileage" data-autosubmit>
                  <option value="">No min</option>
                  <?php foreach ($mileagePresets as $mm): ?>
                  <option value="<?= $mm ?>" <?= $mileageMin === $mm ? 'selected' : '' ?>><?= $fmtKm($mm) ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="min-max-sep" aria-hidden="true">–</span>
                <select class="filter-select" name="mileage_max" aria-label="Maximum mileage" data-autosubmit>
                  <option value="">No max</option>
                  <?php foreach ($mileagePresets as $mm): ?>
                  <option value="<?= $mm ?>" <?= $mileageMax === $mm ? 'selected' : '' ?>><?= $fmtKm($mm) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </details>

            <?php
            $chipGroups = [
                ['fuel',  'Fuel type',    'fuel_type[]',    $fuelTypeWhitelist,     $fuelTypes],
                ['trans', 'Transmission', 'transmission[]', $transmissionWhitelist, $transmissions],
                ['drive', 'Drivetrain',   'drivetrain[]',   $drivetrainWhitelist,   $drivetrains],
            ];
            foreach ($chipGroups as [$key, $title, $name, $options, $selected]): ?>
            <details class="filter-section" <?= $openSection[$key] ? 'open' : '' ?>>
              <summary class="filter-section-label"><?= $title ?><?php if ($selected): ?> <span class="filter-section__count"><?= count($selected) ?></span><?php endif; ?></summary>
              <div class="chip-row">
                <?php foreach ($options as $opt): $on = in_array($opt, $selected, true); ?>
                <label class="filter-chip <?= $on ? 'active' : '' ?>">
                  <input class="sr-only" type="checkbox" name="<?= $name ?>" value="<?= $e($opt) ?>" data-autosubmit <?= $on ? 'checked' : '' ?>>
                  <?= $e($opt) ?>
                </label>
                <?php endforeach; ?>
              </div>
            </details>
            <?php endforeach; ?>

            <?php if (!empty($provinces)): ?>
            <details class="filter-section" <?= $openSection['province'] ? 'open' : '' ?>>
              <summary class="filter-section-label">Province</summary>
              <select class="filter-select" name="province" aria-label="Province" data-autosubmit>
                <option value="">All provinces</option>
                <?php foreach ($provinces as $pv): ?>
                <option value="<?= $e($pv) ?>" <?= $province === $pv ? 'selected' : '' ?>><?= $e($pv) ?></option>
                <?php endforeach; ?>
              </select>
            </details>
            <?php endif; ?>
          </div>

          <div class="sidebar-card__footer">
            <button type="submit" class="pub-btn pub-btn-primary pub-btn-full filter-apply-btn">
              <span data-filter-apply-label>Show <?= number_format($total) ?> <?= $total === 1 ? 'car' : 'cars' ?></span>
            </button>
          </div>
        </form>
      </aside>

      <!-- Results -->
      <div class="browse-results">

        <div class="results-bar">
          <button class="browse-filter-toggle" id="browseFilterToggle" type="button"
                  aria-expanded="false" aria-controls="browseSidebar">
            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
            Filters
            <?php if ($activeFilterCount > 0): ?>
            <span class="browse-filter-toggle__badge"><?= $activeFilterCount ?></span>
            <?php endif; ?>
          </button>

          <p class="results-bar__count">
            <?php if ($total > 0): ?>
            <span class="results-bar__count-num"><?= number_format($firstShown) ?>–<?= number_format($lastShown) ?></span>
            of <?= number_format($total) ?>
            <?php else: ?>
            No results
            <?php endif; ?>
          </p>

          <form method="GET" action="<?= $e($deskPath) ?>" class="sort-form">
            <?php foreach ($currentParams as $k => $v):
              if ($k === 'sort') continue;
              foreach ((array) $v as $vv): ?>
            <input type="hidden" name="<?= $e($k) . (is_array($v) ? '[]' : '') ?>" value="<?= $e((string) $vv) ?>">
            <?php endforeach; endforeach; ?>
            <label class="sr-only" for="sortSelect">Sort by</label>
            <i class="fa-solid fa-arrow-down-wide-short sort-form__icon" aria-hidden="true"></i>
            <select class="sort-select" id="sortSelect" name="sort" data-autosubmit>
              <option value="newest"      <?= $sort === 'newest'      ? 'selected' : '' ?>>Newest on desk</option>
              <option value="price_asc"   <?= $sort === 'price_asc'   ? 'selected' : '' ?>>Lowest price</option>
              <option value="price_desc"  <?= $sort === 'price_desc'  ? 'selected' : '' ?>>Highest price</option>
              <option value="mileage_asc" <?= $sort === 'mileage_asc' ? 'selected' : '' ?>>Lowest mileage</option>
            </select>
            <noscript><button class="pub-btn pub-btn-ghost pub-btn-sm" type="submit">Sort</button></noscript>
          </form>

          <div class="view-toggle" role="group" aria-label="Layout">
            <button class="view-toggle__btn" type="button" data-view="grid" aria-pressed="true" aria-label="Grid view"><i class="fa-solid fa-grip" aria-hidden="true"></i></button>
            <button class="view-toggle__btn" type="button" data-view="list" aria-pressed="false" aria-label="List view"><i class="fa-solid fa-list" aria-hidden="true"></i></button>
          </div>
        </div>

        <?php if ($chips): ?>
        <div class="active-filter-tags" aria-label="Active filters">
          <?php foreach ($chips as [$label, $url]): ?>
          <a class="active-filter-tag" href="<?= $e($url) ?>" aria-label="Remove filter: <?= $e($label) ?>">
            <?= $e($label) ?> <i class="fa-solid fa-xmark active-filter-tag__dismiss" aria-hidden="true"></i>
          </a>
          <?php endforeach; ?>
          <a class="active-filter-clear" href="<?= $e($deskPath) ?>">Clear all</a>
        </div>
        <?php endif; ?>

        <?php if (empty($inventory)): ?>

          <div class="pub-empty">
            <span class="pub-empty__icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
            <h3 class="pub-empty__title">No cars on this desk match those filters</h3>
            <p class="pub-empty__sub">Try removing one — or search every desk on SalesDesk.</p>
            <div class="pub-empty__actions">
              <?php foreach (array_slice($chips, 0, 5) as [$label, $url]): ?>
              <a class="pub-chip" href="<?= $e($url) ?>"><i class="fa-solid fa-xmark"></i> <?= $e($label) ?></a>
              <?php endforeach; ?>
            </div>
            <a href="<?= $e($deskPath) ?>" class="pub-btn pub-btn-primary browse-empty__reset">Show all <?= $deskCars ?> cars</a>
          </div>

        <?php else: ?>

        <div class="pub-browse-grid" id="carGrid" data-view-root>
          <?php foreach ($inventory as $i => $car) {
              // desk_slug keeps the attributed URL; desk_name is left unset
              // so the card's seller line shows the DEALERSHIP — on this
              // page every car is already "listed by this desk".
              $car['desk_slug'] = $desk['slug'];
              echo sdVehicleCard($car, [
                  'ref'        => (string) ($car['tracking_code'] ?? ''),
                  'wishlisted' => in_array((int) $car['id'], array_map('intval', $wishlistedIds), true),
                  'eager'      => $i < 3,
                  'heading'    => 'h3',
              ]);
          } ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pages">
          <?php
          $pageUrl = static function (int $p) use ($deskUrlFor): string {
              $u = $deskUrlFor();
              return $p === 1 ? $u : $u . (str_contains($u, '?') ? '&' : '?') . 'page=' . $p;
          };
          ?>
          <?php if ($page > 1): ?>
          <a href="<?= $e($pageUrl($page - 1)) ?>" class="pagination__page pagination__page--nav" rel="prev">
            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i><span class="pagination__label">Previous</span>
          </a>
          <?php endif; ?>

          <?php
          $prev = null;
          for ($p = 1; $p <= $totalPages; $p++):
              if (!($p === 1 || $p === $totalPages || abs($p - $page) <= 1)) continue;
              if ($prev !== null && $p - $prev > 1): ?>
          <span class="pagination__ellipsis" aria-hidden="true">…</span>
              <?php endif; ?>
          <a href="<?= $e($pageUrl($p)) ?>" class="pagination__page <?= $p === $page ? 'active' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?> aria-label="Page <?= $p ?>"><?= $p ?></a>
          <?php $prev = $p; endfor; ?>

          <?php if ($page < $totalPages): ?>
          <a href="<?= $e($pageUrl($page + 1)) ?>" class="pagination__page pagination__page--nav" rel="next">
            <span class="pagination__label">Next</span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <!-- ══════════════════════════════════
       CTA
       ══════════════════════════════════ -->
  <section class="ds-cta pub-reveal">
    <div>
      <h2 class="ds-cta__title">Looking for something <?= $e($brokerName) ?> doesn't have yet?</h2>
      <p class="ds-cta__sub">Search every car on SalesDesk — <?= $e(explode(' ', $brokerName)[0]) ?> can still help you buy it.</p>
    </div>
    <a href="/cars-for-sale/" class="pub-btn pub-btn-primary pub-btn-lg">
      Browse all cars <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
    </a>
  </section>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
