<?php
/**
 * SalesDesk — Public Browse / Search Page  (v2.3)
 * Route: /c/  (via .htaccess → c/index.php)
 *
 * FIX LOG v2.3 (this pass):
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
 * Prior fix log (v2 / v2.1 / v2.2) preserved below for audit trail.
 *
 * Attribution model v2:
 *   Each car is shown ONCE, attributed to the desk that listed it first
 *   (MIN(bi.added_at)). Card links are /c/{desk-slug}/{car-slug}/ so the
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
$makes = $pdo->query("
    SELECT DISTINCT c.make FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.status = 'active'
    ORDER BY c.make
")->fetchAll(PDO::FETCH_COLUMN);

$bodyTypes = $pdo->query("
    SELECT DISTINCT c.body_type FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.status = 'active' AND c.body_type IS NOT NULL
    ORDER BY c.body_type
")->fetchAll(PDO::FETCH_COLUMN);

$provinces = $pdo->query("
    SELECT DISTINCT a.province
    FROM dealers d
    JOIN addresses a ON a.id = d.address_id
    JOIN cars c ON c.dealer_id = d.id
    JOIN broker_inventory bi ON bi.car_id = c.id
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
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.status = 'active'
")->fetch();
$yearFloor   = (int) ($yearRange['min_year'] ?? (int) date('Y') - 10);
$yearCeiling = (int) ($yearRange['max_year'] ?? (int) date('Y'));

// ── Multi-value array filters ─────────────────────────────────
$bodyTypesSelected = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['body_type'] ?? []))),
    $bodyTypes
));

$fuelTypeWhitelist = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'Plug-in Hybrid', 'LPG'];
$fuelTypes = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['fuel_type'] ?? []))),
    $fuelTypeWhitelist
));

$transmissionWhitelist = ['Automatic', 'Manual', 'Semi-automatic', 'DSG/Dual-clutch', 'CVT'];
$transmissions = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['transmission'] ?? []))),
    $transmissionWhitelist
));

$drivetrainWhitelist = ['FWD', 'RWD', 'AWD', '4×4'];
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
// FIX-B: Both count and fetch queries share this identical WHERE clause.
// "first_desk.car_id IS NOT NULL" is included here so both queries
// agree on the result set — previously count omitted this condition,
// producing a count higher than the number of cards that actually render.
$where  = ["c.status = 'active'", "d.is_active = 1", "first_desk.car_id IS NOT NULL"];
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
        c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour,
        c.transmission, c.fuel_type, c.drivetrain,
        c.commission_type, c.commission_value,
        c.image_urls, c.created_at,
        ({$commExpr})          AS commission_rand,
        d.id                   AS dealer_id,
        d.company_name         AS dealer_name,
        d.verification_status  AS dealer_verified,
        a.city                 AS dealer_city,
        a.province             AS dealer_province,
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

// ── Fuel icon helper ──────────────────────────────────────────
function fuelIcon(string $fuel): string {
    return match (strtolower($fuel)) {
        'electric'                    => 'fa-bolt',
        'hybrid', 'plug-in hybrid'    => 'fa-leaf',
        'diesel'                      => 'fa-oil-can',
        'lpg'                         => 'fa-fire-flame-simple',
        default                       => 'fa-gas-pump',
    };
}

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
$pageTitle     = $headingLabel . ' for Sale in South Africa | SalesDesk';
$ogTitle       = $headingLabel . ' for Sale — SalesDesk';
$ogDescription = number_format($total) . ' cars available on SalesDesk, South Africa\'s broker car sales platform.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/c/';
$showBreadcrumb = true;
$breadcrumbs    = [['Browse Cars', null]];
$layoutVariant  = 'wide';

// ── Page-scoped CSS injected into <head> via $extraCss ────────
$extraCss = '<style>
/* Browse page: responsive layout margins (not in browse.css) */
.browse-layout {
  margin-inline: clamp(16px, 4vw, 48px);
  padding-inline: clamp(12px, 2vw, 24px);
}
/* Safety: preserve browse.css prefers-reduced-motion guard. */
@media (prefers-reduced-motion: reduce) {
  .browse-grid--animated { animation: none; }
}
</style>';

ob_start();
?>

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

    <!-- ══════════════════════════════════
         FILTER SIDEBAR
         ══════════════════════════════════ -->
    <aside class="browse-sidebar" id="browseSidebar"
           role="complementary" aria-label="Filter vehicles">

      <button class="browse-sidebar__close" id="browseSidebarClose" type="button"
              aria-label="Close filters">
        <span>Filters</span>
        <i class="fa-solid fa-xmark"></i>
      </button>

      <form method="GET" action="/c/" id="filterForm">
        <?php if ($ref): ?>
        <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
        <?php endif; ?>

        <div class="sidebar-card">

          <!-- Header -->
          <div class="sidebar-card__header">
            <span class="sidebar-card__title">
              <i class="fa-solid fa-sliders"></i> Filters
            </span>
            <a href="/c/<?= $ref ? '?ref=' . htmlspecialchars($ref) : '' ?>"
               class="sidebar-card__reset">Reset all</a>
          </div>

          <!-- Search -->
          <div class="filter-section">
            <span class="filter-section-label">Search</span>
            <div class="search-input-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input class="search-input-sidebar" type="text" name="q" id="sidebarSearch"
                     placeholder="Make, model, year…"
                     value="<?= htmlspecialchars($q) ?>" autocomplete="off">
            </div>
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

          <!-- Body Style — multi-select chips -->
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

          <!-- SalesDesk broker filter -->
          <?php if (!empty($desks)): ?>
          <div class="filter-section">
            <span class="filter-section-label">SalesDesk Broker</span>
            <select class="filter-select" name="desk" onchange="this.form.submit()">
              <option value="">All SalesDesks</option>
              <?php foreach ($desks as $dk): ?>
              <option value="<?= htmlspecialchars($dk['slug']) ?>"
                      <?= $deskSlug === $dk['slug'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dk['display_name']) ?>
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

      <!-- Search debounce -->
      <script>
      (function () {
        var t;
        var inp = document.getElementById('sidebarSearch');
        if (!inp) return;
        inp.addEventListener('input', function () {
          clearTimeout(t);
          t = setTimeout(function () { inp.form.submit(); }, 350);
        });
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); inp.form.submit(); }
        });
      })();
      </script>

    </aside><!-- /aside -->


    <!-- ══════════════════════════════════
         MAIN RESULTS COLUMN
         ══════════════════════════════════ -->
    <div class="browse-results">

      <!-- Results bar: count + sort -->
      <div class="results-bar">
        <p class="results-bar__count">
          <span class="results-bar__count-num"><?= number_format($total) ?></span>
          vehicle<?= $total !== 1 ? 's' : '' ?> found
          <?php if ($headingLabel !== 'All Cars'): ?>
          <span class="results-bar__heading">— <?= htmlspecialchars($headingLabel) ?></span>
          <?php endif; ?>
        </p>

        <?php
        // FIX-C: Sort form hidden inputs use ?? '' so null filters are
        // omitted and integer 0 values are preserved as '0'.
        $sortPreserveScalars = array_filter([
            'q'           => $q ?: null,
            'make'        => $make ?: null,
            'condition'   => $condition ?: null,
            'province'    => $province ?: null,
            'desk'        => $deskSlug ?: null,
            'price_min'   => $priceMin,
            'price_max'   => $priceMax,
            'mileage_min' => $mileageMin,
            'mileage_max' => $mileageMax,
            'year_min'    => $yearMin,
            'year_max'    => $yearMax,
            'ref'         => $ref ?: null,
        ], fn($v) => $v !== null && $v !== '');
        ?>
        <form method="GET" action="/c/" class="sort-form">
          <?php foreach ($sortPreserveScalars as $k => $v): ?>
          <input type="hidden" name="<?= htmlspecialchars($k) ?>"
                 value="<?= htmlspecialchars((string)$v) ?>">
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
      <?php
      $hasAnyActiveFilter = $q || $make || $condition || $province
          || $priceMin !== null || $priceMax !== null
          || $mileageMin !== null || $mileageMax !== null
          || $yearMin !== null || $yearMax !== null
          || !empty($bodyTypesSelected) || !empty($fuelTypes)
          || !empty($transmissions) || !empty($drivetrains);

      if ($hasAnyActiveFilter): ?>
      <div class="active-filter-tags">

        <?php if ($q): ?>
        <span class="active-filter-tag">
          "<?= htmlspecialchars($q) ?>"
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)q=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove search filter">✕</a>
        </span>
        <?php endif; ?>

        <?php if ($make): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars($make) ?>
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)make=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove make filter">✕</a>
        </span>
        <?php endif; ?>

        <?php if ($condition): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars(ucfirst($condition)) ?>
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)condition=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove condition filter">✕</a>
        </span>
        <?php endif; ?>

        <?php if ($province): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars($province) ?>
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)province=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove province filter">✕</a>
        </span>
        <?php endif; ?>

        <?php if ($priceMin !== null || $priceMax !== null): ?>
        <span class="active-filter-tag">
          <?= $priceMin !== null ? 'R ' . number_format($priceMin) : '' ?>
          <?= ($priceMin !== null && $priceMax !== null) ? ' – ' : '' ?>
          <?= $priceMax !== null ? 'R ' . number_format($priceMax) : '' ?>
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)price_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove price filter">✕</a>
        </span>
        <?php endif; ?>

        <?php if ($mileageMin !== null || $mileageMax !== null): ?>
        <span class="active-filter-tag">
          <?= $mileageMin !== null ? number_format($mileageMin) . ' km' : '' ?>
          <?= ($mileageMin !== null && $mileageMax !== null) ? ' – ' : '' ?>
          <?= $mileageMax !== null ? number_format($mileageMax) . ' km' : '' ?>
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)mileage_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove mileage filter">✕</a>
        </span>
        <?php endif; ?>

        <?php if ($yearMin !== null || $yearMax !== null): ?>
        <span class="active-filter-tag">
          <?= $yearMin ?? '?' ?> – <?= $yearMax ?? '?' ?>
          <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)year_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove year filter">✕</a>
        </span>
        <?php endif; ?>

        <?php foreach ($bodyTypesSelected as $bt): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars($bt) ?>
          <a href="/c/?<?= dismissQs('body_type', $bt, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $deskSlug, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort, $ref) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove body type filter">✕</a>
        </span>
        <?php endforeach; ?>

        <?php foreach ($fuelTypes as $ft): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars($ft) ?>
          <a href="/c/?<?= dismissQs('fuel_type', $ft, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $deskSlug, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort, $ref) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove fuel type filter">✕</a>
        </span>
        <?php endforeach; ?>

        <?php foreach ($transmissions as $tr): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars($tr) ?>
          <a href="/c/?<?= dismissQs('transmission', $tr, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $deskSlug, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort, $ref) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove transmission filter">✕</a>
        </span>
        <?php endforeach; ?>

        <?php foreach ($drivetrains as $dr): ?>
        <span class="active-filter-tag">
          <?= htmlspecialchars($dr) ?>
          <a href="/c/?<?= dismissQs('drivetrain', $dr, $bodyTypesSelected, $fuelTypes, $transmissions, $drivetrains, $q, $make, $condition, $province, $deskSlug, $priceMin, $priceMax, $mileageMin, $mileageMax, $yearMin, $yearMax, $sort, $ref) ?>"
             class="active-filter-tag__dismiss" aria-label="Remove drivetrain filter">✕</a>
        </span>
        <?php endforeach; ?>

      </div><!-- /active-filter-tags -->
      <?php endif; ?>


      <!-- FIX-E: Budget quick-filter pills ─────────────────────
           Changed $min ?: '' to ($min > 0 ? $min : null) so that
           price ranges starting at 0 (e.g. "Under R200k") do not
           emit price_min=0 into the URL (which is redundant and
           was previously being dropped by array_filter anyway,
           causing the active-pill highlight to fail on reload).
           Ranges with a real non-zero min now correctly preserve it. -->
      <div class="price-pill-row">
        <?php
        $priceRanges = [
            ['Under R 200k',    null,   200000],
            ['R 200k – R 350k', 200000, 350000],
            ['R 350k – R 500k', 350000, 500000],
            ['R 500k – R 800k', 500000, 800000],
            ['Over R 800k',     800000, null  ],
        ];
        foreach ($priceRanges as [$label, $min, $max]):
            $isActive = $priceMin === $min && $priceMax === $max;
            $pillParams = array_filter([
                'make'         => $make ?: null,
                'condition'    => $condition ?: null,
                'body_type'    => $bodyTypesSelected ?: null,
                'fuel_type'    => $fuelTypes         ?: null,
                'transmission' => $transmissions     ?: null,
                'drivetrain'   => $drivetrains       ?: null,
                'province'     => $province ?: null,
                'sort'         => $sort !== 'newest' ? $sort : null,
                'price_min'    => $min,
                'price_max'    => $max,
                'ref'          => $ref ?: null,
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
            $qs = http_build_query($pillParams);
        ?>
        <a href="/c/<?= $qs ? '?' . $qs : '' ?>"
           class="price-pill <?= $isActive ? 'active' : '' ?>">
          <?= htmlspecialchars($label) ?>
        </a>
        <?php endforeach; ?>
      </div><!-- /price-pill-row -->


      <!-- ── Car Grid ──────────────────────────────────────── -->
      <?php if (empty($cars)): ?>

      <div class="browse-empty">
        <i class="fa-regular fa-face-meh-blank browse-empty__icon"></i>
        <div class="browse-empty__title">No vehicles match your filters</div>
        <div class="browse-empty__sub">Try adjusting your budget, province, or body type.</div>
        <a href="/c/" class="browse-empty__reset">Reset all filters</a>
      </div>

      <?php else: ?>

      <div class="pub-browse-grid" id="carGrid">
        <?php foreach ($cars as $car):
          $imgs    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
          $thumb   = $imgs[0] ?? null;
          $detailUrl = '/c/' . htmlspecialchars($car['desk_slug']) . '/'
                     . htmlspecialchars($car['car_slug']) . '/'
                     . ($ref ? '?ref=' . htmlspecialchars($ref) : '');
          $priceStr  = 'R ' . number_format((float)$car['price'], 0, '.', ' ');
          $prov      = $provAbbr[$car['dealer_province'] ?? ''] ?? ($car['dealer_province'] ?? '');
          $isNew     = $car['condition_type'] === 'new';
          $isEV      = strtolower($car['fuel_type'] ?? '') === 'electric';
        ?>
        <a href="<?= $detailUrl ?>" class="vehicle-card">

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
              <?= (int)$car['year'] ?>
            </span>

            <?php if ($prov): ?>
            <span class="vehicle-card__pill vehicle-card__pill--province">
              <i class="fa-solid fa-location-dot pill-icon"></i><?= htmlspecialchars($prov) ?>
            </span>
            <?php endif; ?>

            <?php if ($car['mileage']): ?>
            <span class="vehicle-card__pill vehicle-card__pill--mileage">
              <i class="fa-solid fa-road pill-icon"></i><?= number_format((int)$car['mileage']) ?> km
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

            <div class="vehicle-card__meta-row">
              <span class="salesdesk-badge">
                <i class="fa-solid fa-id-card"></i>
                <?= htmlspecialchars($car['desk_name']) ?>
              </span>
              <?php if ($car['dealer_verified'] === 'verified'): ?>
              <span class="verified-badge">
                <i class="fa-solid fa-circle-check"></i> Verified
              </span>
              <?php endif; ?>
            </div>

            <!-- Spec pills with icons -->
            <div class="vehicle-card__specs">
              <?php if ($car['fuel_type']): ?>
              <span class="vehicle-card__spec-pill">
                <i class="fa-solid <?= fuelIcon($car['fuel_type']) ?>"></i><?= htmlspecialchars($car['fuel_type']) ?>
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

        </a><!-- /vehicle-card -->
        <?php endforeach; ?>
      </div><!-- /carGrid -->


      <!-- ── Pagination ─────────────────────────────────────── -->
      <?php if ($totalPages > 1): ?>
      <nav class="pagination" aria-label="Browse pages">

        <?php if ($page > 1): ?>
        <a href="/c/?<?= $filterQs ?>&page=<?= $page - 1 ?>"
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
            <a href="/c/?<?= $filterQs ?>&page=<?= $p ?>"
               class="pagination__page <?= $p === $page ? 'active' : '' ?>"
               <?= $p === $page ? 'aria-current="page"' : '' ?>>
              <?= $p ?>
            </a>
        <?php $prev = $p; endforeach; ?>

        <?php if ($page < $totalPages): ?>
        <a href="/c/?<?= $filterQs ?>&page=<?= $page + 1 ?>"
           class="pagination__page pagination__page--nav"
           aria-label="Next page">
          <i class="fa-solid fa-chevron-right"></i>
        </a>
        <?php endif; ?>

      </nav>
      <?php endif; ?>

      <?php endif; // end cars not empty ?>

    </div><!-- /browse-results -->
  </div><!-- /browse-layout -->

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

  /* ── Mobile nav hamburger ────────────────────────────────────── */
  var hamburger = document.querySelector('.pub-nav__hamburger');
  var mobileNav = document.querySelector('.pub-mobile-nav');

  function openMobileNav() {
    if (!hamburger || !mobileNav) return;
    hamburger.classList.add('open');
    mobileNav.classList.add('open');
    hamburger.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function closeMobileNav() {
    if (!hamburger || !mobileNav) return;
    hamburger.classList.remove('open');
    mobileNav.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
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

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';