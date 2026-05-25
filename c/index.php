<?php
/**
 * SalesDesk — Public Browse / Search Page  (v2)
 * Route: /c/  (via .htaccess → c/index.php)
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
 * Gap-closure changes applied (see salesdesk-browse-gap-closure.md):
 *   §1.1 Mileage min/max filter
 *   §1.2 Year of manufacture min/max filter
 *   §1.3 Fuel type multi-select chips
 *   §1.4 Transmission multi-select chips
 *   §1.5 Drivetrain multi-select chips
 *   §2.1 Body style: replaced select with multi-select chips
 *   §2.2 Sort control moved to results bar (removed from sidebar)
 *   §2.3 Search input debounce (350ms)
 *   §3.1 Spec pill icons on vehicle cards
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

// ── Scalar filters ────────────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$make      = trim($_GET['make']      ?? '');
$condition = trim($_GET['condition'] ?? '');
$province  = trim($_GET['province']  ?? '');
$deskSlug  = trim($_GET['desk']      ?? '');
$priceMin  = (int) ($_GET['price_min']   ?? 0);
$priceMax  = (int) ($_GET['price_max']   ?? 0);
$mileageMin= (int) ($_GET['mileage_min'] ?? 0);
$mileageMax= (int) ($_GET['mileage_max'] ?? 0);
$yearMin   = (int) ($_GET['year_min']    ?? 0);
$yearMax   = (int) ($_GET['year_max']    ?? 0);
$sort      = trim($_GET['sort']      ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

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

// §1.2 — Year range from live inventory
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
if ($priceMin)  { $where[] = 'c.price >= ?';              $params[] = $priceMin; }
if ($priceMax)  { $where[] = 'c.price <= ?';              $params[] = $priceMax; }
if ($mileageMin){ $where[] = 'c.mileage >= ?';            $params[] = $mileageMin; }
if ($mileageMax){ $where[] = 'c.mileage <= ?';            $params[] = $mileageMax; }
if ($yearMin)   { $where[] = 'c.year >= ?';               $params[] = $yearMin; }
if ($yearMax)   { $where[] = 'c.year <= ?';               $params[] = $yearMax; }

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

// ── Count total ───────────────────────────────────────────────
$countSql = "
    SELECT COUNT(DISTINCT c.id)
    FROM cars c
    JOIN dealers d         ON d.id = c.dealer_id
    LEFT JOIN addresses a  ON a.id = d.address_id
    LEFT JOIN {$firstDeskSub} ON first_desk.car_id = c.id
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
    JOIN dealers d         ON d.id = c.dealer_id
    LEFT JOIN addresses a  ON a.id = d.address_id
    LEFT JOIN {$firstDeskSub} ON first_desk.car_id = c.id
    {$wc}
    ORDER BY {$sortSql}
    LIMIT ? OFFSET ?
";
$carsStmt = $pdo->prepare($carsSql);
$carsStmt->execute($listParams);
$cars = $carsStmt->fetchAll();

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

// ── Pagination base query string ──────────────────────────────
$filterQs = http_build_query(array_filter([
    'q'           => $q,
    'make'        => $make,
    'condition'   => $condition,
    'body_type'   => $bodyTypesSelected ?: null,
    'fuel_type'   => $fuelTypes         ?: null,
    'transmission'=> $transmissions     ?: null,
    'drivetrain'  => $drivetrains       ?: null,
    'province'    => $province,
    'desk'        => $deskSlug,
    'price_min'   => $priceMin  ?: '',
    'price_max'   => $priceMax  ?: '',
    'mileage_min' => $mileageMin ?: '',
    'mileage_max' => $mileageMax ?: '',
    'year_min'    => $yearMin   ?: '',
    'year_max'    => $yearMax   ?: '',
    'sort'        => $sort !== 'newest' ? $sort : '',
    'ref'         => $ref,
]));

// ── Province abbreviations (for card badges) ──────────────────
$provAbbr = [
    'Gauteng'       => 'GP',  'Western Cape'  => 'WC',  'KwaZulu-Natal' => 'KZN',
    'Eastern Cape'  => 'EC',  'Limpopo'       => 'LP',  'Mpumalanga'    => 'MP',
    'North West'    => 'NW',  'Free State'    => 'FS',  'Northern Cape' => 'NC',
];

// ── §3.1 — Fuel icon helper ───────────────────────────────────
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
 * Build a dismissal query-string that removes one multi-select value
 * while preserving everything else.
 */
function dismissQs(
    string $removeKey, string $removeVal,
    array $bodyTypesSelected, array $fuelTypes, array $transmissions, array $drivetrains,
    string $q, string $make, string $condition, string $province, string $deskSlug,
    int $priceMin, int $priceMax, int $mileageMin, int $mileageMax,
    int $yearMin, int $yearMax, string $sort, string $ref
): string {
    $filter = static function(array $arr, string $val): array {
        return array_values(array_filter($arr, fn($x) => $x !== $val));
    };

    $base = array_filter([
        'q'          => $q,          'make'       => $make,
        'condition'  => $condition,  'province'   => $province,
        'desk'       => $deskSlug,
        'price_min'  => $priceMin  ?: '',  'price_max'  => $priceMax  ?: '',
        'mileage_min'=> $mileageMin ?: '',  'mileage_max'=> $mileageMax ?: '',
        'year_min'   => $yearMin   ?: '',  'year_max'   => $yearMax   ?: '',
        'sort'       => $sort !== 'newest' ? $sort : '',
        'ref'        => $ref,
    ]);

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

ob_start();
?>

<div class="browse-layout" style="display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start;">

  <!-- ══════════════════════════════════
       FILTER SIDEBAR
       ══════════════════════════════════ -->
  <aside class="browse-sidebar">
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
              <input type="radio" name="condition" value="<?= $val ?>"
                     <?= $condition === $val ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
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
                   value="<?= $priceMin ?: '' ?>" min="0" step="10000">
            <span class="min-max-sep">—</span>
            <input class="min-max-input" type="number" name="price_max" placeholder="Max"
                   value="<?= $priceMax ?: '' ?>" min="0" step="10000">
          </div>
        </div>

        <!-- §1.1 Mileage -->
        <div class="filter-section">
          <span class="filter-section-label">Mileage (km)</span>
          <div class="min-max-row">
            <input class="min-max-input" type="number" name="mileage_min" placeholder="Min"
                   value="<?= $mileageMin ?: '' ?>" min="0" step="5000">
            <span class="min-max-sep">—</span>
            <input class="min-max-input" type="number" name="mileage_max" placeholder="Max"
                   value="<?= $mileageMax ?: '' ?>" min="0" step="5000">
          </div>
        </div>

        <!-- §1.2 Year of manufacture -->
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

        <!-- §2.1 Body Style — multi-select chips -->
        <?php if (!empty($bodyTypes)): ?>
        <div class="filter-section">
          <span class="filter-section-label">Body Style</span>
          <div class="chip-row">
            <?php foreach ($bodyTypes as $bt): ?>
            <label class="filter-chip <?= in_array($bt, $bodyTypesSelected, true) ? 'active' : '' ?>">
              <input type="checkbox" name="body_type[]" value="<?= htmlspecialchars($bt) ?>"
                     <?= in_array($bt, $bodyTypesSelected, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= htmlspecialchars($bt) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- §1.3 Fuel / Powertrain -->
        <div class="filter-section">
          <span class="filter-section-label">Fuel / Powertrain</span>
          <div class="chip-row">
            <?php foreach ($fuelTypeWhitelist as $ft): ?>
            <label class="filter-chip <?= in_array($ft, $fuelTypes, true) ? 'active' : '' ?>">
              <input type="checkbox" name="fuel_type[]" value="<?= htmlspecialchars($ft) ?>"
                     <?= in_array($ft, $fuelTypes, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= htmlspecialchars($ft) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- §1.4 Transmission -->
        <div class="filter-section">
          <span class="filter-section-label">Transmission</span>
          <div class="chip-row">
            <?php foreach ($transmissionWhitelist as $tr): ?>
            <label class="filter-chip <?= in_array($tr, $transmissions, true) ? 'active' : '' ?>">
              <input type="checkbox" name="transmission[]" value="<?= htmlspecialchars($tr) ?>"
                     <?= in_array($tr, $transmissions, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= htmlspecialchars($tr) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- §1.5 Drivetrain -->
        <div class="filter-section">
          <span class="filter-section-label">Drivetrain</span>
          <div class="chip-row">
            <?php foreach ($drivetrainWhitelist as $dr): ?>
            <label class="filter-chip <?= in_array($dr, $drivetrains, true) ? 'active' : '' ?>">
              <input type="checkbox" name="drivetrain[]" value="<?= htmlspecialchars($dr) ?>"
                     <?= in_array($dr, $drivetrains, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
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
          <i class="fa-solid fa-bolt" style="color:var(--amber);margin-right:4px;"></i>
          Filters submit instantly on select changes
        </div>

      </div><!-- /sidebar-card -->
    </form>

    <!-- §2.3 Search debounce -->
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
       MAIN CONTENT
       ══════════════════════════════════ -->
  <div>

    <!-- §2.2 Results bar: count + sort -->
    <div class="results-bar pub-anim">
      <p class="results-bar__count">
        <span class="results-bar__count-num"><?= number_format($total) ?></span>
        vehicle<?= $total !== 1 ? 's' : '' ?> found
        <?php if ($headingLabel !== 'All Cars'): ?>
        <span class="results-bar__heading">— <?= htmlspecialchars($headingLabel) ?></span>
        <?php endif; ?>
      </p>

      <!-- Sort — standalone form preserves all active filters -->
      <form method="GET" action="/c/" class="sort-form">
        <?php
        $sortPreserveScalars = array_filter([
            'q'          => $q,
            'make'       => $make,
            'condition'  => $condition,
            'province'   => $province,
            'desk'       => $deskSlug,
            'price_min'  => $priceMin  ?: '',
            'price_max'  => $priceMax  ?: '',
            'mileage_min'=> $mileageMin ?: '',
            'mileage_max'=> $mileageMax ?: '',
            'year_min'   => $yearMin   ?: '',
            'year_max'   => $yearMax   ?: '',
            'ref'        => $ref,
        ]);
        foreach ($sortPreserveScalars as $k => $v): ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach;
        foreach ($bodyTypesSelected as $bt): ?>
        <input type="hidden" name="body_type[]" value="<?= htmlspecialchars($bt) ?>">
        <?php endforeach;
        foreach ($fuelTypes as $ft): ?>
        <input type="hidden" name="fuel_type[]" value="<?= htmlspecialchars($ft) ?>">
        <?php endforeach;
        foreach ($transmissions as $tr): ?>
        <input type="hidden" name="transmission[]" value="<?= htmlspecialchars($tr) ?>">
        <?php endforeach;
        foreach ($drivetrains as $dr): ?>
        <input type="hidden" name="drivetrain[]" value="<?= htmlspecialchars($dr) ?>">
        <?php endforeach; ?>

        <select class="sort-select" name="sort" onchange="this.form.submit()">
          <option value="newest"      <?= $sort === 'newest'      ? 'selected' : '' ?>>Newest first</option>
          <option value="price_asc"   <?= $sort === 'price_asc'   ? 'selected' : '' ?>>Price: low → high</option>
          <option value="price_desc"  <?= $sort === 'price_desc'  ? 'selected' : '' ?>>Price: high → low</option>
          <option value="mileage_asc" <?= $sort === 'mileage_asc' ? 'selected' : '' ?>>Lowest mileage</option>
        </select>
      </form>
    </div><!-- /results-bar -->

    <!-- Active filter tags -->
    <?php
    $hasAnyActiveFilter = $q || $make || $condition || $province || $priceMin || $priceMax
        || $mileageMin || $mileageMax || $yearMin || $yearMax
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

      <?php if ($priceMin || $priceMax): ?>
      <span class="active-filter-tag">
        <?= $priceMin ? 'R ' . number_format($priceMin) : '' ?>
        <?= ($priceMin && $priceMax) ? ' – ' : '' ?>
        <?= $priceMax ? 'R ' . number_format($priceMax) : '' ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)price_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove price filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($mileageMin || $mileageMax): ?>
      <span class="active-filter-tag">
        <?= $mileageMin ? number_format($mileageMin) . ' km' : '' ?>
        <?= ($mileageMin && $mileageMax) ? ' – ' : '' ?>
        <?= $mileageMax ? number_format($mileageMax) . ' km' : '' ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)mileage_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           class="active-filter-tag__dismiss" aria-label="Remove mileage filter">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($yearMin || $yearMax): ?>
      <span class="active-filter-tag">
        <?= $yearMin ?: '?' ?> – <?= $yearMax ?: '?' ?>
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

    </div>
    <?php endif; ?>

    <!-- Budget quick-filter pills -->
    <div class="price-pill-row pub-anim">
      <?php
      $priceRanges = [
          ['Under R 200k',    0,      200000],
          ['R 200k – R 350k', 200000, 350000],
          ['R 350k – R 500k', 350000, 500000],
          ['R 500k – R 800k', 500000, 800000],
          ['Over R 800k',     800000, 0     ],
      ];
      foreach ($priceRanges as [$label, $min, $max]):
          $isActive = (string)$priceMin === (string)$min && (string)$priceMax === (string)$max;
          $qs = http_build_query(array_filter([
              'make'        => $make,
              'condition'   => $condition,
              'body_type'   => $bodyTypesSelected ?: null,
              'fuel_type'   => $fuelTypes         ?: null,
              'transmission'=> $transmissions     ?: null,
              'drivetrain'  => $drivetrains       ?: null,
              'province'    => $province,
              'sort'        => $sort !== 'newest' ? $sort : '',
              'price_min'   => $min ?: '',
              'price_max'   => $max ?: '',
              'ref'         => $ref,
          ]));
      ?>
      <a href="/c/?<?= $qs ?>" class="price-pill <?= $isActive ? 'active' : '' ?>">
        <?= htmlspecialchars($label) ?>
      </a>
      <?php endforeach; ?>
    </div>

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
      <a href="<?= $detailUrl ?>" class="vehicle-card pub-reveal">

        <!-- Image block -->
        <div class="vehicle-card__img">
          <?php if ($thumb): ?>
          <img src="<?= htmlspecialchars($thumb) ?>"
               alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
               loading="lazy">
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
            <i class="fa-solid fa-location-dot" style="color:var(--p);margin-right:3px;font-size:9px;"></i><?= htmlspecialchars($prov) ?>
          </span>
          <?php endif; ?>

          <?php if ($car['mileage']): ?>
          <span class="vehicle-card__pill vehicle-card__pill--mileage">
            <i class="fa-solid fa-road" style="color:var(--faint);margin-right:3px;font-size:9px;"></i><?= number_format((int)$car['mileage']) ?> km
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
            <div>
              <div class="vehicle-card__name">
                <?= htmlspecialchars("{$car['make']} {$car['model']}") ?>
              </div>
              <div class="vehicle-card__dealer">
                <i class="fa-regular fa-building" style="margin-right:3px;"></i><?= htmlspecialchars($car['dealer_name']) ?>
              </div>
            </div>
            <span class="vehicle-card__price"><?= $priceStr ?></span>
          </div>

          <div class="vehicle-card__meta-row">
            <span class="salesdesk-badge">
              <i class="fa-solid fa-id-card" style="font-size:9px;"></i>
              <?= htmlspecialchars($car['desk_name']) ?>
            </span>
            <?php if ($car['dealer_verified'] === 'verified'): ?>
            <span class="verified-badge">
              <i class="fa-solid fa-circle-check" style="font-size:8px;"></i> Verified
            </span>
            <?php endif; ?>
          </div>

          <!-- §3.1 Spec pills with icons -->
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

      </a>
      <?php endforeach; ?>
    </div><!-- /carGrid -->

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination pub-reveal" aria-label="Browse pages">
      <?php if ($page > 1): ?>
      <a href="/c/?<?= $filterQs ?>&page=<?= $page - 1 ?>"
         class="pub-btn pub-btn-ghost pagination__prev">
        <i class="fa-solid fa-chevron-left"></i> Prev
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
         class="pub-btn pub-btn-ghost pagination__next">
        Next <i class="fa-solid fa-chevron-right"></i>
      </a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php endif; // end cars not empty ?>

  </div><!-- /main col -->
</div><!-- /browse-layout -->

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
