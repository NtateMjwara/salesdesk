<?php
/**
 * SalesDesk — Public Browse / Search Page  (v2.5)
 * Route: /cars-for-sale/  (via .htaccess → cars-for-sale/index.php)
 *
 * v3 (public UX/UI overhaul):
 *   UX-1   Page rebuilt: descriptive H1 ("Used Toyota SUVs for sale in
 *          Gauteng"), sticky results toolbar with removable filter chips,
 *          sort + grid/list toggle, quick budget chips, shared vehicle
 *          card with save button + monthly estimate, richer pagination
 *          ("Showing 1–24 of 240") and a helpful empty state that offers
 *          one-tap filter removal.
 *   UX-2   Sidebar: collapsible sections, segmented condition control,
 *          preset price / mileage selects, body-type tiles with icons.
 *          Desktop auto-applies; the mobile sheet shows a live
 *          "Show N cars" count and applies on tap.
 *   UX-3   ALL inline <style>, <script>, style="" and onchange="" removed.
 *          The inline hamburger handler (a duplicate of public-nav.js that
 *          cancelled it out — tap opened then instantly closed the drawer)
 *          is gone. Page JS: assets/js/browse.js. Page CSS: browse.css.
 *   DATA-1 Filter option lists (makes, body types, provinces, year range)
 *          no longer JOIN broker_inventory. Since migration 0012 this page
 *          lists every active car, so a car not yet on a desk was visible
 *          in results but its make/body type was missing from the filters.
 *
 * FIX LOG v2.5:
 *   SEO-2  $pageTitle / $ogTitle / $ogDescription previously used ONLY
 *           the result count ("72 New and Used Cars for Sale in South
 *           Africa | SalesDesk") for every filtered variant of this page
 *           — ?make=Toyota, ?condition=used, ?body_type[]=SUV all
 *           rendered the exact same boilerplate title text apart from
 *           the leading number. Since $headingLabel (built further
 *           below from the active facets) was already computed for the
 *           on-page heading but never fed into the title/meta, Google's
 *           sitelink algorithm had nothing distinguishing to grab for
 *           each curated facet page except the number itself — which is
 *           exactly what showed up as bare "72" / "426" / "67" sitelinks
 *           in search results instead of "Toyota" / "Used Cars" / "SUV".
 *           $pageTitle/$ogTitle/$ogDescription now incorporate
 *           $headingLabel (aliased as $facetLabel) whenever a curated
 *           facet is active, so each indexable facet page gets a
 *           genuinely distinct, human-readable title that matches what
 *           the page itself displays.
 *
 * FIX LOG v2.4 (prior pass, preserved for audit trail):
 *   SEARCH-1  Sidebar search box no longer auto-submits the whole page
 *              on a debounced keystroke. It previously did
 *              `setTimeout(() => inp.form.submit(), 350)` on every
 *              `input` event, which reloaded the entire page mid-typing
 *              and made it impossible to finish a search query. Replaced
 *              with assets/js/search-typeahead.js: debounces a fetch to
 *              the new /api/cars/suggest.php (live suggestions from the
 *              DB — make / model / body type), and only navigates when
 *              the user explicitly picks a suggestion or presses Enter.
 *   SEARCH-2  fuel_type / transmission / drivetrain whitelists moved out
 *              to includes/filter-whitelists.php (shared with
 *              broker/index.php). These were previously two independent
 *              hardcoded copies that had already drifted once (see the
 *              WHITELIST FIX comment below, preserved for audit trail) —
 *              broker/index.php's copy was still stale. One shared file
 *              means this class of bug can't recur.
 *
 * FIX LOG v2.3 (prior pass, preserved for audit trail):
 *   FIX-A  Numeric filters ($priceMin, $priceMax, $mileageMin, $mileageMax,
 *           $yearMin, $yearMax) now default to null instead of 0.
 *           All downstream consumers updated to use !== null guards.
 *           Root cause: (int)("") === 0 was indistinguishable from
 *           "user set filter to zero", causing (a) wrong WHERE clauses,
 *           (b) silent filter loss in filterQs/sortPreserveScalars/dismissQs,
 *           and (c) wrong activeFilterCount badge.
 *
 *   FIX-B  count query and fetch query now use identical WHERE + JOIN
 *           structure. Previously the count query was missing the
 *           "first_desk.car_id IS NOT NULL" condition that the fetch
 *           query had, causing "3 vehicles found" with 0 cards rendered.
 *           Both queries now share the same $wc WHERE clause and the
 *           same LEFT JOIN on $firstDeskSub.
 *
 *   FIX-C  $filterQs, $sortPreserveScalars, and dismissQs() now use
 *           ?? '' (null coalescing) instead of ?: '' (falsy coalescing)
 *           so that a legitimate integer value of 0 is never silently
 *           dropped. array_filter() only removes true nulls/empty strings.
 *
 *   FIX-D  $activeFilterCount guards updated to !== null so a zero
 *           numeric value still increments the mobile badge correctly.
 *
 *   FIX-E  Price pill links on the results page now build their QS
 *           correctly: $min === 0 is preserved as '0' not dropped.
 *
 * Attribution model v2:
 *   Each car is shown ONCE, attributed to the desk that listed it first
 *   (MIN(bi.added_at)). Card links are /cars-for-sale/{desk-slug}/{car-slug}/ so the
 *   desk is embedded in the URL — no ?ref= required for browse attribution.
 *
 *   ?ref={tracking_code} is preserved in links only when the buyer arrived
 *   via a broker share link, ensuring externally shared links still credit
 *   the sharing broker even if they were not the first to list.
 *
 * Filters: make, condition, body_type[], price_min, price_max,
 *          mileage_min, mileage_max, year_min, year_max,
 *          fuel_type[], transmission[], drivetrain[],
 *          province, sort, q (search), page, desk (by desk slug)
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';
require_once '../includes/filter-whitelists.php';

applyCachePolicy('public');

$pdo     = Database::getInstance();
$visitor = initVisitorSession();

// ── Tracking code — carried forward from an external share link ──
$ref = trim($_GET['ref'] ?? $visitor['last_tracking_code'] ?? '');
if ($ref && !preg_match('/^[a-f0-9]{32}$/i', $ref)) $ref = '';

// ── Scalar string filters ─────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$make      = trim($_GET['make']      ?? '');
$condition = trim($_GET['condition'] ?? '');
$province  = trim($_GET['province']  ?? '');
$deskSlug  = trim($_GET['desk']      ?? '');
$sort      = trim($_GET['sort']      ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

// ── FIX-A: Numeric filters — null means "not set" ────────────
// Using isset() + !== '' guard means:
//   - absent key          → null  (filter not applied)
//   - key present, empty  → null  (filter not applied)
//   - key present, "0"    → 0     (filter applied at zero)
//   - key present, "350000" → 350000 (filter applied)
$priceMin   = (isset($_GET['price_min'])   && $_GET['price_min']   !== '')
                ? (int) $_GET['price_min']   : null;
$priceMax   = (isset($_GET['price_max'])   && $_GET['price_max']   !== '')
                ? (int) $_GET['price_max']   : null;
$mileageMin = (isset($_GET['mileage_min']) && $_GET['mileage_min'] !== '')
                ? (int) $_GET['mileage_min'] : null;
$mileageMax = (isset($_GET['mileage_max']) && $_GET['mileage_max'] !== '')
                ? (int) $_GET['mileage_max'] : null;
$yearMin    = (isset($_GET['year_min'])    && $_GET['year_min']    !== '')
                ? (int) $_GET['year_min']    : null;
$yearMax    = (isset($_GET['year_max'])    && $_GET['year_max']    !== '')
                ? (int) $_GET['year_max']    : null;

// ── Whitelists ────────────────────────────────────────────────
$validConditions = ['new', 'demo', 'used'];
$validSorts      = ['newest', 'price_asc', 'price_desc', 'mileage_asc'];
if (!in_array($condition, $validConditions, true)) $condition = '';
if (!in_array($sort, $validSorts, true))           $sort = 'newest';

// ── Filter options fetched from DB ───────────────────────────
// DATA-1: no broker_inventory JOIN — options must match what the page lists.
$makes = $pdo->query("
    SELECT c.make, COUNT(*) AS cnt FROM cars c
    JOIN dealers d ON d.id = c.dealer_id
    WHERE c.status = 'active' AND d.is_active = 1
    GROUP BY c.make
    ORDER BY c.make
")->fetchAll(PDO::FETCH_KEY_PAIR);
$makeCounts = $makes;
$makes      = array_keys($makes);

$bodyTypes = $pdo->query("
    SELECT DISTINCT c.body_type FROM cars c
    JOIN dealers d ON d.id = c.dealer_id
    WHERE c.status = 'active' AND d.is_active = 1 AND c.body_type IS NOT NULL
    ORDER BY c.body_type
")->fetchAll(PDO::FETCH_COLUMN);

$provinces = $pdo->query("
    SELECT DISTINCT a.province
    FROM dealers d
    JOIN addresses a ON a.id = d.address_id
    JOIN cars c ON c.dealer_id = d.id
    WHERE d.is_active = 1 AND a.province IS NOT NULL AND c.status = 'active'
    ORDER BY a.province
")->fetchAll(PDO::FETCH_COLUMN);

$desks = $pdo->query("
    SELECT DISTINCT sd.slug, sd.display_name
    FROM salesdesks sd
    JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
    JOIN cars c ON c.id = bi.car_id
    WHERE sd.is_active = 1 AND c.status = 'active'
    ORDER BY sd.display_name
")->fetchAll();

// Year range from live inventory
$yearRange = $pdo->query("
    SELECT MIN(c.year) AS min_year, MAX(c.year) AS max_year
    FROM cars c
    JOIN dealers d ON d.id = c.dealer_id
    WHERE c.status = 'active' AND d.is_active = 1
")->fetch();
$yearFloor   = (int) ($yearRange['min_year'] ?? (int) date('Y') - 10);
$yearCeiling = (int) ($yearRange['max_year'] ?? (int) date('Y'));

// ── Multi-value array filters ─────────────────────────────────
$bodyTypesSelected = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['body_type'] ?? []))),
    $bodyTypes
));

/**
 * SEARCH-2 (this pass): fuel_type / transmission / drivetrain
 * whitelists now live in includes/filter-whitelists.php, shared with
 * broker/index.php. See that file's header comment for why: these two
 * pages previously kept independent copies that drifted — this page's
 * copy got a correction (matching what app/dealer/car-upload.php's
 * wizard actually writes) that broker/index.php's copy never received.
 * One shared source means that can't happen again.
 *
 * WHITELIST FIX (prior pass, preserved for audit trail): these three
 * whitelists previously used values that don't match what
 * app/dealer/car-upload.php's wizard actually writes to
 * cars.fuel_type / cars.transmission / cars.drivetrain — confirmed by
 * reading that file directly. Since these arrays are fixed PHP lists
 * (not pulled from the DB the way $bodyTypes above is), a mismatch
 * here means the corresponding sidebar checkbox can NEVER match a
 * real row: array_intersect() against $_GET only lets through values
 * already in the whitelist, and the SQL IN-clause then searches for
 * that exact whitelist string. Corrected to match the wizard:
 *   fuel_type:    'Plug-in Hybrid' -> 'Plug-in Hybrid (PHEV)'
 *                 'LPG'            -> 'LPG (Autogas)'
 *                 added: 'Hydrogen', 'CNG (Natural Gas)', 'Flex Fuel (E85/Ethanol)'
 *                 (car-upload.php offers these; this filter never did)
 *   transmission: 'DSG/Dual-clutch' -> 'DSG'
 *                 'Semi-automatic'  -> 'Semi-Automatic' (casing, cosmetic —
 *                 MySQL's _ci collation already matched either way, but
 *                 aligning it removes a confusing false lead for the next
 *                 person reading this file)
 *   drivetrain:   '4×4' (Unicode multiplication sign) -> '4WD'
 */
$fuelTypeWhitelist = sdFuelTypeWhitelist();
$fuelTypes = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['fuel_type'] ?? []))),
    $fuelTypeWhitelist
));

$transmissionWhitelist = sdTransmissionWhitelist();
$transmissions = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['transmission'] ?? []))),
    $transmissionWhitelist
));

$drivetrainWhitelist = sdDrivetrainWhitelist();
$drivetrains = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['drivetrain'] ?? []))),
    $drivetrainWhitelist
));

// ── Attribution v2: subquery picks the earliest-listed desk per car ──
$firstDeskSub = "
    (
        SELECT
            bi_inner.car_id,
            sd_inner.slug          AS desk_slug,
            sd_inner.display_name  AS desk_name,
            bi_inner.tracking_code AS desk_tracking_code,
            sd_inner.id            AS salesdesk_id
        FROM broker_inventory bi_inner
        JOIN salesdesks sd_inner ON sd_inner.id = bi_inner.salesdesk_id
        WHERE bi_inner.added_at = (
            SELECT MIN(bi2.added_at)
            FROM broker_inventory bi2
            WHERE bi2.car_id = bi_inner.car_id
        )
        GROUP BY bi_inner.car_id
    ) AS first_desk
";

// ── Build WHERE ───────────────────────────────────────────────
// MIGRATION 0012: /cars-for-sale/ is now the platform's own browse
// page and shows every active car regardless of desk attribution —
// the "first_desk.car_id IS NOT NULL" gate from FIX-B is removed.
// first_desk is still LEFT JOINed below (unchanged) purely as
// optional metadata: when a car IS also on a desk, the card can show
// "Listed by {broker}" and link through their tracking code; when it
// isn't, the card links to /cars-for-sale/car/{slug}/ and is
// attributed to no broker (leads.broker_id NULL) on submit. Count
// and fetch queries still share this identical $where array/JOIN
// structure — that discipline from FIX-B stays, only the gate itself
// is gone.
$where  = ["c.status = 'active'", "d.is_active = 1"];
$params = [];

if ($q) {
    $where[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year,' ',c.make,' ',c.model) LIKE ?)";
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($make)      { $where[] = 'c.make = ?';               $params[] = $make; }
if ($condition) { $where[] = 'c.condition_type = ?';      $params[] = $condition; }
if ($province)  { $where[] = 'a.province = ?';            $params[] = $province; }
if ($deskSlug)  { $where[] = 'first_desk.desk_slug = ?';  $params[] = $deskSlug; }

// FIX-A: Use !== null so zero is a valid filter value
if ($priceMin !== null)   { $where[] = 'c.price >= ?';    $params[] = $priceMin; }
if ($priceMax !== null)   { $where[] = 'c.price <= ?';    $params[] = $priceMax; }
if ($mileageMin !== null) { $where[] = 'c.mileage >= ?';  $params[] = $mileageMin; }
if ($mileageMax !== null) { $where[] = 'c.mileage <= ?';  $params[] = $mileageMax; }
if ($yearMin !== null)    { $where[] = 'c.year >= ?';     $params[] = $yearMin; }
if ($yearMax !== null)    { $where[] = 'c.year <= ?';     $params[] = $yearMax; }

if (!empty($bodyTypesSelected)) {
    $placeholders = implode(',', array_fill(0, count($bodyTypesSelected), '?'));
    $where[]      = "c.body_type IN ({$placeholders})";
    $params       = array_merge($params, $bodyTypesSelected);
}
if (!empty($fuelTypes)) {
    $placeholders = implode(',', array_fill(0, count($fuelTypes), '?'));
    $where[]      = "c.fuel_type IN ({$placeholders})";
    $params       = array_merge($params, $fuelTypes);
}
if (!empty($transmissions)) {
    $placeholders = implode(',', array_fill(0, count($transmissions), '?'));
    $where[]      = "c.transmission IN ({$placeholders})";
    $params       = array_merge($params, $transmissions);
}
if (!empty($drivetrains)) {
    $placeholders = implode(',', array_fill(0, count($drivetrains), '?'));
    $where[]      = "c.drivetrain IN ({$placeholders})";
    $params       = array_merge($params, $drivetrains);
}

$wc = 'WHERE ' . implode(' AND ', $where);

$commExpr = "CASE c.commission_type
    WHEN 'fixed'      THEN c.commission_value
    WHEN 'percentage' THEN c.price * (c.commission_value / 100)
    ELSE 0 END";

$sortSql = match($sort) {
    'price_asc'   => 'c.price ASC',
    'price_desc'  => 'c.price DESC',
    'mileage_asc' => 'c.mileage ASC',
    default       => 'c.created_at DESC',
};

// ── FIX-B: Count query — identical JOIN + WHERE to fetch query ──
// Previously this query lacked the LEFT JOIN on $firstDeskSub, so
// "first_desk.car_id IS NOT NULL" in $wc would have caused a SQL error
// OR the count excluded that condition entirely, producing a mismatch.
$countSql = "
    SELECT COUNT(DISTINCT c.id)
    FROM cars c
    JOIN dealers d             ON d.id = c.dealer_id
    LEFT JOIN addresses a      ON a.id = d.address_id
    LEFT JOIN {$firstDeskSub}  ON first_desk.car_id = c.id
    {$wc}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

// ── Fetch cars ────────────────────────────────────────────────
$listParams = array_merge($params, [$perPage, $offset]);
$carsSql    = "
    SELECT
        c.id, c.slug AS car_slug, c.make, c.model, c.variant, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour,
        c.transmission, c.fuel_type, c.drivetrain,
        c.commission_type, c.commission_value,
        c.image_urls, c.created_at,
        ({$commExpr})          AS commission_rand,
        d.id                   AS dealer_id,
        d.company_name         AS dealer_name,
        d.verification_status  AS dealer_verified,
        a.city                 AS dealer_city,
        a.province              AS dealer_province,
        first_desk.desk_slug,
        first_desk.desk_name,
        first_desk.desk_tracking_code,
        first_desk.salesdesk_id,
        (SELECT COUNT(*) FROM broker_inventory bi_cnt
         WHERE bi_cnt.car_id = c.id) AS broker_count
    FROM cars c
    JOIN dealers d             ON d.id = c.dealer_id
    LEFT JOIN addresses a      ON a.id = d.address_id
    LEFT JOIN {$firstDeskSub}  ON first_desk.car_id = c.id
    {$wc}
    ORDER BY {$sortSql}
    LIMIT ? OFFSET ?
";
$carsStmt = $pdo->prepare($carsSql);
$carsStmt->execute($listParams);
$cars = $carsStmt->fetchAll();

// ── FIX-D: Active filter count — !== null guards ──────────────
// Previously used truthiness checks, so a value of 0 would not
// increment the badge even though the filter was actively applied.
$activeFilterCount = 0;
if ($q)                              $activeFilterCount++;
if ($make)                           $activeFilterCount++;
if ($condition)                      $activeFilterCount++;
if ($province)                       $activeFilterCount++;
if ($deskSlug)                       $activeFilterCount++;
if ($priceMin !== null || $priceMax !== null)     $activeFilterCount++;
if ($mileageMin !== null || $mileageMax !== null) $activeFilterCount++;
if ($yearMin !== null || $yearMax !== null)        $activeFilterCount++;
$activeFilterCount += count($bodyTypesSelected);
$activeFilterCount += count($fuelTypes);
$activeFilterCount += count($transmissions);
$activeFilterCount += count($drivetrains);

// ── Active filter label ───────────────────────────────────────
$activeFilters = array_filter([
    $q, $make, $condition, $province, $deskSlug,
    !empty($bodyTypesSelected) ? 'body' : '',
    !empty($fuelTypes)         ? 'fuel' : '',
    !empty($transmissions)     ? 'trans' : '',
    !empty($drivetrains)       ? 'drive' : '',
]);
$headingLabel = !empty($activeFilters)
    ? implode(' · ', array_filter([
        $condition ? ucfirst($condition) : '',
        $make,
        implode(', ', $bodyTypesSelected),
        $province,
        $deskSlug,
        $q ? "\"{$q}\"" : '',
    ]))
    : 'All Cars';

// ── FIX-C: Pagination base query string ──────────────────────
// Changed ?: '' to ?? '' throughout so integer 0 is preserved as
// the string '0' in the URL rather than being dropped by array_filter.
// array_filter() still removes true null values (unset filters).
$filterQs = http_build_query(array_filter([
    'q'            => $q ?: null,
    'make'         => $make ?: null,
    'condition'    => $condition ?: null,
    'body_type'    => $bodyTypesSelected ?: null,
    'fuel_type'    => $fuelTypes         ?: null,
    'transmission' => $transmissions     ?: null,
    'drivetrain'   => $drivetrains       ?: null,
    'province'     => $province ?: null,
    'desk'         => $deskSlug ?: null,
    'price_min'    => $priceMin,    // null if not set, int otherwise (incl. 0)
    'price_max'    => $priceMax,
    'mileage_min'  => $mileageMin,
    'mileage_max'  => $mileageMax,
    'year_min'     => $yearMin,
    'year_max'     => $yearMax,
    'sort'         => $sort !== 'newest' ? $sort : null,
    'ref'          => $ref ?: null,
], fn($v) => $v !== null && $v !== '' && $v !== []));

// ── Province abbreviations (for card badges) ──────────────────
$provAbbr = [
    'Gauteng'       => 'GP',  'Western Cape'  => 'WC',  'KwaZulu-Natal' => 'KZN',
    'Eastern Cape'  => 'EC',  'Limpopo'       => 'LP',  'Mpumalanga'    => 'MP',
    'North West'    => 'NW',  'Free State'    => 'FS',  'Northern Cape' => 'NC',
];

/**
 * FIX-C: Build a dismissal query-string that removes one multi-select
 * value while preserving everything else.
 * Uses ?? '' (null coalescing) instead of ?: '' (falsy coalescing)
 * so integer 0 values are preserved in the reconstructed URL.
 */
function dismissQs(
    string $removeKey,
    string $removeVal,
    array  $bodyTypesSelected,
    array  $fuelTypes,
    array  $transmissions,
    array  $drivetrains,
    string $q,
    string $make,
    string $condition,
    string $province,
    string $deskSlug,
    ?int   $priceMin,
    ?int   $priceMax,
    ?int   $mileageMin,
    ?int   $mileageMax,
    ?int   $yearMin,
    ?int   $yearMax,
    string $sort,
    string $ref
): string {
    $filter = static function (array $arr, string $val): array {
        return array_values(array_filter($arr, fn($x) => $x !== $val));
    };

    // Build base scalar params — only include if non-null/non-empty
    $base = array_filter([
        'q'          => $q ?: null,
        'make'       => $make ?: null,
        'condition'  => $condition ?: null,
        'province'   => $province ?: null,
        'desk'       => $deskSlug ?: null,
        // FIX-C: ?? null preserves 0 as a value; only drops true nulls
        'price_min'  => $priceMin,
        'price_max'  => $priceMax,
        'mileage_min'=> $mileageMin,
        'mileage_max'=> $mileageMax,
        'year_min'   => $yearMin,
        'year_max'   => $yearMax,
        'sort'       => $sort !== 'newest' ? $sort : null,
        'ref'        => $ref ?: null,
    ], fn($v) => $v !== null && $v !== '');

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

// ── Page meta ─────────────────────────────────────────────────
require_once '../includes/seo-canonical.php';

$siteBaseUrl = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';

// This page has ~10 independent filter dimensions (make, condition,
// body_type[], fuel_type[], transmission[], drivetrain[], province,
// desk, price/mileage/year ranges, sort, page) — left with one fixed
// canonical regardless of which filters are active (the previous
// behaviour here), every filtered variation of this page claimed the
// SAME canonical, which isn't how canonical is supposed to work.
// seoResolveBrowseCanonical() decides per-request whether this exact
// combination of filters is a real, curated landing page (self-
// canonical — e.g. ?condition=used, a single ?make=, or one of the
// four curated body types) or should roll up to the base listing
// with noindex,follow (crawlable so link equity still flows to every
// car detail page linked from it, just not competing in search
// results itself). See includes/seo-canonical.php for the full policy.
$seoCanonical = seoResolveBrowseCanonical(
    $siteBaseUrl, $q, $make, $condition, $province, $deskSlug,
    $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains,
    $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax,
    $sort, $page
);

/**
 * SEO-2 (this pass): $headingLabel already computed above ("Used",
 * "Toyota", "SUV", "Gauteng", combinations thereof, or the literal
 * "All Cars" when nothing is filtered) is now the basis for the page
 * title/meta instead of the bare result count. Previously EVERY
 * filtered variant of this page — curated (indexable) or not —
 * shared the exact same title text apart from the leading number,
 * which is what left Google's sitelinks with nothing to display for
 * each facet page except that number (see the "72 / 426 / 67" bare
 * sitelinks this was diagnosed from). $facetLabel is null only for
 * the unfiltered "All Cars" case, which keeps its original generic
 * title — every other case (curated or noindexed multi-filter combo
 * alike) now gets a title that actually names what's being shown.
 */
$facetLabel = ($headingLabel !== 'All Cars') ? $headingLabel : null;

$pageTitle = $facetLabel
    ? number_format($total) . ' ' . $facetLabel . ' Cars for Sale in South Africa | SalesDesk'
    : number_format($total) . ' New and Used Cars for Sale in South Africa | SalesDesk';

$ogTitle = $facetLabel
    ? number_format($total) . ' ' . $facetLabel . ' Cars for Sale — SalesDesk'
    : number_format($total) . ' New and Used Cars for Sale — SalesDesk';

$ogDescription = $facetLabel
    ? 'Browse ' . number_format($total) . ' ' . $facetLabel . ' cars for sale on SalesDesk, South Africa\'s broker car sales platform.'
    : number_format($total) . ' New and used cars available on SalesDesk, South Africa\'s broker car sales platform.';

$canonicalUrl  = $seoCanonical['canonical'];
$metaRobotsNoindex = $seoCanonical['noindex'];

// og:image — first result's photo when there is one, otherwise the
// sitewide fallback banner.
$ogImage = null;
if (!empty($cars)) {
    $firstImgs = json_decode($cars[0]['image_urls'] ?? '[]', true) ?: [];
    $ogImage   = $firstImgs[0] ?? null;
}
$ogImage = $ogImage ?: $siteBaseUrl . '/assets/img/og-default.jpg';


// ── v3: canonical param set + URL builder ─────────────────────
// One source of truth for every link on this page (chip removal,
// budget chips, pagination, empty-state suggestions). Replaces the
// preg_replace()-based dismissal links, which could also strip a
// neighbouring param whose name ended in the same letters.
$currentParams = array_filter([
    'q'            => $q ?: null,
    'make'         => $make ?: null,
    'condition'    => $condition ?: null,
    'body_type'    => $bodyTypesSelected ?: null,
    'fuel_type'    => $fuelTypes ?: null,
    'transmission' => $transmissions ?: null,
    'drivetrain'   => $drivetrains ?: null,
    'province'     => $province ?: null,
    'desk'         => $deskSlug ?: null,
    'price_min'    => $priceMin,
    'price_max'    => $priceMax,
    'mileage_min'  => $mileageMin,
    'mileage_max'  => $mileageMax,
    'year_min'     => $yearMin,
    'year_max'     => $yearMax,
    'sort'         => $sort !== 'newest' ? $sort : null,
    'ref'          => $ref ?: null,
], fn($v) => $v !== null && $v !== '' && $v !== []);

/**
 * Build a /cars-for-sale/ URL from the current params.
 *   $set    keys to set/replace (null removes the key)
 *   $remove [key => value] pairs to drop from an array param
 */
$browseUrl = static function (array $set = [], array $remove = []) use ($currentParams): string {
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
    return '/cars-for-sale/' . ($qs !== '' ? '?' . $qs : '');
};

// ── v3: human heading ("Used Toyota SUVs for sale in Gauteng") ─
$deskName = '';
foreach ($desks as $dk) { if ($dk['slug'] === $deskSlug) { $deskName = $dk['display_name']; break; } }

$bodyPlural = static function (string $b): string {
    return match (strtolower($b)) {
        'suv' => 'SUVs', 'mpv' => 'MPVs', 'bakkie' => 'bakkies', 'hatchback' => 'hatchbacks',
        'sedan' => 'sedans', 'crossover' => 'crossovers', 'coupe' => 'coupes', 'convertible' => 'convertibles',
        'station wagon' => 'station wagons', 'van' => 'vans', 'minibus' => 'minibuses', 'truck' => 'trucks',
        default => $b,
    };
};
$h1Parts = array_filter([
    $condition ? ['new' => 'New', 'used' => 'Used', 'demo' => 'Demo'][$condition] : '',
    count($fuelTypes) === 1 ? $fuelTypes[0] : '',
    $make,
    count($bodyTypesSelected) === 1 ? $bodyPlural($bodyTypesSelected[0]) : 'cars',
]);
$h1 = ucfirst(implode(' ', $h1Parts)) . ' for sale' . ($province ? ' in ' . $province : ' in South Africa');
if ($q) $h1 = 'Cars matching “' . $q . '”' . ($province ? ' in ' . $province : '');

// ── v3: active filter chips ────────────────────────────────────
$fmtR = static fn(int $n): string => $n >= 1000000
    ? 'R' . rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'm'
    : 'R' . number_format($n / 1000) . 'k';
$fmtKm = static fn(int $n): string => number_format($n / 1000) . 'k km';

$chips = [];
if ($q)         $chips[] = ['“' . $q . '”', $browseUrl(['q' => null])];
if ($condition) $chips[] = [ucfirst($condition), $browseUrl(['condition' => null])];
if ($make)      $chips[] = [$make, $browseUrl(['make' => null])];
foreach ($bodyTypesSelected as $v) $chips[] = [$v, $browseUrl([], ['body_type' => $v])];
if ($priceMin !== null || $priceMax !== null) {
    $chips[] = [
        $priceMin !== null && $priceMax !== null ? $fmtR($priceMin) . ' – ' . $fmtR($priceMax)
            : ($priceMax !== null ? 'Under ' . $fmtR($priceMax) : 'From ' . $fmtR($priceMin)),
        $browseUrl(['price_min' => null, 'price_max' => null]),
    ];
}
if ($yearMin !== null || $yearMax !== null) {
    $chips[] = [
        $yearMin !== null && $yearMax !== null ? $yearMin . ' – ' . $yearMax : ($yearMin !== null ? $yearMin . ' or newer' : $yearMax . ' or older'),
        $browseUrl(['year_min' => null, 'year_max' => null]),
    ];
}
if ($mileageMin !== null || $mileageMax !== null) {
    $chips[] = [
        $mileageMax !== null ? 'Under ' . $fmtKm($mileageMax) : 'Over ' . $fmtKm((int) $mileageMin),
        $browseUrl(['mileage_min' => null, 'mileage_max' => null]),
    ];
}
foreach ($fuelTypes as $v)     $chips[] = [$v, $browseUrl([], ['fuel_type' => $v])];
foreach ($transmissions as $v) $chips[] = [$v, $browseUrl([], ['transmission' => $v])];
foreach ($drivetrains as $v)   $chips[] = [$v, $browseUrl([], ['drivetrain' => $v])];
if ($province) $chips[] = [$province, $browseUrl(['province' => null])];
if ($deskSlug) $chips[] = [$deskName ?: $deskSlug, $browseUrl(['desk' => null])];

$resetUrl = '/cars-for-sale/' . ($ref ? '?ref=' . rawurlencode($ref) : '');

// ── Wishlist state for card hearts ──────────────────────────────
$wishIds = array_map('intval', getWishlistCarIds((int) $visitor['id']));

// ── Select presets ──────────────────────────────────────────────
$pricePresets   = [50000, 100000, 150000, 200000, 250000, 300000, 400000, 500000, 600000, 750000, 1000000, 1500000, 2000000];
$mileagePresets = [10000, 30000, 50000, 80000, 100000, 150000, 200000];
foreach ([$priceMin, $priceMax] as $v) if ($v !== null && !in_array($v, $pricePresets, true)) $pricePresets[] = $v;
foreach ([$mileageMin, $mileageMax] as $v) if ($v !== null && !in_array($v, $mileagePresets, true)) $mileagePresets[] = $v;
sort($pricePresets);
sort($mileagePresets);

$firstShown = $total > 0 ? $offset + 1 : 0;
$lastShown  = min($offset + $perPage, $total);

// Sections that start open: the essentials, plus any with an active value.
$openSection = [
    'condition' => true,
    'price'     => true,
    'make'      => true,
    'body'      => true,
    'year'      => $yearMin !== null || $yearMax !== null,
    'mileage'   => $mileageMin !== null || $mileageMax !== null,
    'fuel'      => !empty($fuelTypes),
    'trans'     => !empty($transmissions),
    'drive'     => !empty($drivetrains),
    'province'  => (bool) $province,
    'desk'      => (bool) $deskSlug,
];

$showBreadcrumb = true;
$breadcrumbs    = $currentParams
    ? [['Cars for sale', '/cars-for-sale/'], [$h1, null]]
    : [['Cars for sale', null]];
$layoutVariant  = 'wide';
$includeHowItWorksCss = false;

$assetVersion = $assetVersion ?? date('Ymd');
$extraJs      = ['/assets/js/browse.js'];

require_once __DIR__ . '/../views/partials/vehicle-card.php';
require_once __DIR__ . '/../views/partials/body-type-icon.php';

ob_start();
?>

<div class="browse-page">

  <!-- ══════════════════════════════════
       PAGE HEADER
       ══════════════════════════════════ -->
  <header class="browse-head sd-container">
    <div class="browse-head__text">
      <h1 class="browse-head__title"><?= htmlspecialchars($h1) ?></h1>
      <p class="browse-head__sub">
        <strong><?= number_format($total) ?></strong> <?= $total === 1 ? 'car' : 'cars' ?> from verified dealerships
        <span class="browse-head__dot" aria-hidden="true">·</span>
        <span class="browse-head__live"><span class="browse-head__pulse" aria-hidden="true"></span> Updated live</span>
      </p>
    </div>
    <div class="browse-head__actions">
      <button class="pub-btn pub-btn-ghost pub-btn-sm" type="button" data-share-open>
        <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Share search
      </button>
    </div>
  </header>

  <div class="browse-layout sd-container">

    <div class="browse-sidebar-overlay" id="browseSidebarOverlay" aria-hidden="true"></div>

    <!-- ══════════════════════════════════
         FILTER SIDEBAR (drawer on mobile)
         ══════════════════════════════════ -->
    <aside class="browse-sidebar" id="browseSidebar" aria-label="Filter cars">

      <form method="GET" action="/cars-for-sale/" id="filterForm" class="sidebar-card" data-browse-filters
            data-total="<?= $total ?>">
        <?php if ($ref): ?>
        <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
        <?php endif; ?>
        <?php if ($sort !== 'newest'): ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <?php endif; ?>

        <div class="sidebar-card__header">
          <span class="sidebar-card__title">Filters<?php if ($activeFilterCount): ?> <span class="sidebar-card__count"><?= $activeFilterCount ?></span><?php endif; ?></span>
          <a href="<?= htmlspecialchars($resetUrl) ?>" class="sidebar-card__reset" <?= $activeFilterCount ? '' : 'hidden' ?>>Reset</a>
          <button class="browse-sidebar__close" id="browseSidebarClose" type="button" aria-label="Close filters">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>

        <div class="sidebar-card__body">

          <!-- Search -->
          <div class="filter-section filter-section--static">
            <label class="filter-section-label" for="sidebarSearch">Keyword</label>
            <div class="search-input-wrap">
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input class="search-input-sidebar" type="search" name="q" id="sidebarSearch"
                     placeholder="Make, model, year…" value="<?= htmlspecialchars($q) ?>"
                     autocomplete="off" enterkeyhint="search" data-typeahead-box="sidebarSearchBox">
              <div id="sidebarSearchBox" class="typeahead-box" role="listbox" aria-label="Search suggestions"></div>
            </div>
          </div>

          <!-- Condition -->
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

          <!-- Price -->
          <details class="filter-section" <?= $openSection['price'] ? 'open' : '' ?>>
            <summary class="filter-section-label">Price</summary>
            <div class="min-max-row">
              <select class="filter-select" name="price_min" aria-label="Minimum price" data-autosubmit>
                <option value="">No min</option>
                <?php foreach ($pricePresets as $p): ?>
                <option value="<?= $p ?>" <?= $priceMin === $p ? 'selected' : '' ?>><?= $fmtR($p) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="min-max-sep" aria-hidden="true">–</span>
              <select class="filter-select" name="price_max" aria-label="Maximum price" data-autosubmit>
                <option value="">No max</option>
                <?php foreach ($pricePresets as $p): ?>
                <option value="<?= $p ?>" <?= $priceMax === $p ? 'selected' : '' ?>><?= $fmtR($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </details>

          <!-- Make -->
          <details class="filter-section" <?= $openSection['make'] ? 'open' : '' ?>>
            <summary class="filter-section-label">Make</summary>
            <select class="filter-select" name="make" aria-label="Make" data-autosubmit>
              <option value="">All makes</option>
              <?php foreach ($makes as $m): ?>
              <option value="<?= htmlspecialchars($m) ?>" <?= $make === $m ? 'selected' : '' ?>>
                <?= htmlspecialchars($m) ?> (<?= (int) ($makeCounts[$m] ?? 0) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </details>

          <!-- Body type -->
          <?php if (!empty($bodyTypes)): ?>
          <details class="filter-section" <?= $openSection['body'] ? 'open' : '' ?>>
            <summary class="filter-section-label">Body type</summary>
            <div class="body-tile-grid">
              <?php foreach ($bodyTypes as $bt): $on = in_array($bt, $bodyTypesSelected, true); ?>
              <label class="body-tile <?= $on ? 'active' : '' ?>">
                <input class="sr-only" type="checkbox" name="body_type[]" value="<?= htmlspecialchars($bt) ?>" data-autosubmit <?= $on ? 'checked' : '' ?>>
                <?= sdBodyTypeIcon($bt, 'body-tile__icon') ?>
                <span><?= htmlspecialchars($bt) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endif; ?>

          <!-- Year -->
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

          <!-- Mileage -->
          <details class="filter-section" <?= $openSection['mileage'] ? 'open' : '' ?>>
            <summary class="filter-section-label">Mileage</summary>
            <div class="min-max-row">
              <select class="filter-select" name="mileage_min" aria-label="Minimum mileage" data-autosubmit>
                <option value="">No min</option>
                <?php foreach ($mileagePresets as $m): ?>
                <option value="<?= $m ?>" <?= $mileageMin === $m ? 'selected' : '' ?>><?= $fmtKm($m) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="min-max-sep" aria-hidden="true">–</span>
              <select class="filter-select" name="mileage_max" aria-label="Maximum mileage" data-autosubmit>
                <option value="">No max</option>
                <?php foreach ($mileagePresets as $m): ?>
                <option value="<?= $m ?>" <?= $mileageMax === $m ? 'selected' : '' ?>><?= $fmtKm($m) ?></option>
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
                <input class="sr-only" type="checkbox" name="<?= $name ?>" value="<?= htmlspecialchars($opt) ?>" data-autosubmit <?= $on ? 'checked' : '' ?>>
                <?= htmlspecialchars($opt) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </details>
          <?php endforeach; ?>

          <!-- Province -->
          <?php if (!empty($provinces)): ?>
          <details class="filter-section" <?= $openSection['province'] ? 'open' : '' ?>>
            <summary class="filter-section-label">Province</summary>
            <select class="filter-select" name="province" aria-label="Province" data-autosubmit>
              <option value="">All provinces</option>
              <?php foreach ($provinces as $pv): ?>
              <option value="<?= htmlspecialchars($pv) ?>" <?= $province === $pv ? 'selected' : '' ?>><?= htmlspecialchars($pv) ?></option>
              <?php endforeach; ?>
            </select>
          </details>
          <?php endif; ?>

          <!-- SalesDesk broker -->
          <?php if (!empty($desks)): ?>
          <details class="filter-section" <?= $openSection['desk'] ? 'open' : '' ?>>
            <summary class="filter-section-label">SalesDesk broker</summary>
            <select class="filter-select" name="desk" aria-label="SalesDesk broker" data-autosubmit>
              <option value="">All SalesDesks</option>
              <?php foreach ($desks as $dk): ?>
              <option value="<?= htmlspecialchars($dk['slug']) ?>" <?= $deskSlug === $dk['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($dk['display_name']) ?></option>
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


    <!-- ══════════════════════════════════
         RESULTS
         ══════════════════════════════════ -->
    <div class="browse-results">

      <div class="results-bar" id="resultsBar">
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

        <form method="GET" action="/cars-for-sale/" class="sort-form">
          <?php foreach ($currentParams as $k => $v):
            if ($k === 'sort') continue;
            foreach ((array) $v as $vv): ?>
          <input type="hidden" name="<?= htmlspecialchars($k) . (is_array($v) ? '[]' : '') ?>" value="<?= htmlspecialchars((string) $vv) ?>">
          <?php endforeach; endforeach; ?>
          <label class="sr-only" for="sortSelect">Sort by</label>
          <i class="fa-solid fa-arrow-down-wide-short sort-form__icon" aria-hidden="true"></i>
          <select class="sort-select" id="sortSelect" name="sort" data-autosubmit>
            <option value="newest"      <?= $sort === 'newest'      ? 'selected' : '' ?>>Newest listed</option>
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
        <a class="active-filter-tag" href="<?= htmlspecialchars($url) ?>" aria-label="Remove filter: <?= htmlspecialchars($label) ?>">
          <?= htmlspecialchars($label) ?> <i class="fa-solid fa-xmark active-filter-tag__dismiss" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
        <a class="active-filter-clear" href="<?= htmlspecialchars($resetUrl) ?>">Clear all</a>
      </div>
      <?php endif; ?>

      <div class="price-pill-row" aria-label="Quick budgets">
        <?php
        $priceRanges = [
            ['Under R200k',   null,   200000],
            ['R200k – R350k', 200000, 350000],
            ['R350k – R500k', 350000, 500000],
            ['R500k – R800k', 500000, 800000],
            ['Over R800k',    800000, null],
        ];
        foreach ($priceRanges as [$label, $min, $max]):
            $isActive = $priceMin === $min && $priceMax === $max; ?>
        <a href="<?= htmlspecialchars($isActive ? $browseUrl(['price_min' => null, 'price_max' => null]) : $browseUrl(['price_min' => $min, 'price_max' => $max])) ?>"
           class="price-pill <?= $isActive ? 'active' : '' ?>" <?= $isActive ? 'aria-current="true"' : '' ?>>
          <?= htmlspecialchars($label) ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($cars)): ?>

      <div class="pub-empty browse-empty">
        <span class="pub-empty__icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
        <h2 class="pub-empty__title">No cars match all of those filters</h2>
        <p class="pub-empty__sub">Try removing one of these — each tap shows you more cars.</p>
        <div class="pub-empty__actions">
          <?php foreach (array_slice($chips, 0, 6) as [$label, $url]): ?>
          <a class="pub-chip" href="<?= htmlspecialchars($url) ?>"><i class="fa-solid fa-xmark"></i> <?= htmlspecialchars($label) ?></a>
          <?php endforeach; ?>
        </div>
        <a href="<?= htmlspecialchars($resetUrl) ?>" class="pub-btn pub-btn-primary browse-empty__reset">See all cars</a>
      </div>

      <?php else: ?>

      <div class="pub-browse-grid" id="carGrid" data-view-root>
        <?php foreach ($cars as $i => $car) {
            echo sdVehicleCard($car, [
                'ref'        => $ref,
                'wishlisted' => in_array((int) $car['id'], $wishIds, true),
                'eager'      => $i < 3,
                'heading'    => 'h2',
            ]);
        } ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <nav class="pagination" aria-label="Pages">
        <?php
        $pageUrl = static function (int $p) use ($browseUrl): string {
            $u = $browseUrl();
            return $p === 1 ? $u : $u . (str_contains($u, '?') ? '&' : '?') . 'page=' . $p;
        };
        ?>
        <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="pagination__page pagination__page--nav" rel="prev">
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
        <a href="<?= htmlspecialchars($pageUrl($p)) ?>" class="pagination__page <?= $p === $page ? 'active' : '' ?>"
           <?= $p === $page ? 'aria-current="page"' : '' ?> aria-label="Page <?= $p ?>"><?= $p ?></a>
        <?php $prev = $p; endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="pagination__page pagination__page--nav" rel="next">
          <span class="pagination__label">Next</span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>

      <p class="browse-foot-note">Showing <?= number_format($firstShown) ?>–<?= number_format($lastShown) ?> of <?= number_format($total) ?> cars. Monthly amounts are indicative finance estimates — the dealer or your bank will confirm a formal quote.</p>

      <?php endif; ?>

    </div><!-- /browse-results -->
  </div><!-- /browse-layout -->
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
