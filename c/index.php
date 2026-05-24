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
$mileageMin= (int) ($_GET['mileage_min'] ?? 0);   // §1.1
$mileageMax= (int) ($_GET['mileage_max'] ?? 0);   // §1.1
$yearMin   = (int) ($_GET['year_min']    ?? 0);   // §1.2
$yearMax   = (int) ($_GET['year_max']    ?? 0);   // §1.2
$sort      = trim($_GET['sort']      ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

// ── Whitelists ────────────────────────────────────────────────
$validConditions = ['new', 'demo', 'used'];
// §2.2: commission_desc removed from public sort options
$validSorts = ['newest', 'price_asc', 'price_desc', 'mileage_asc'];
if (!in_array($condition, $validConditions, true)) $condition = '';
if (!in_array($sort, $validSorts, true))           $sort = 'newest';

// ── Filter options fetched from DB ───────────────────────────
// Must be fetched before multi-value filter extraction so that
// body_type whitelist is available for array_intersect (§2.1).
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
$yearRange   = $pdo->query("
    SELECT MIN(c.year) AS min_year, MAX(c.year) AS max_year
    FROM cars c
    JOIN broker_inventory bi ON bi.car_id = c.id
    WHERE c.status = 'active'
")->fetch();
$yearFloor   = (int) ($yearRange['min_year'] ?? (int) date('Y') - 10);
$yearCeiling = (int) ($yearRange['max_year'] ?? (int) date('Y'));

// ── Multi-value array filters ─────────────────────────────────

// §2.1 — Body style multi-select (was single <select>)
$bodyTypesSelected = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['body_type'] ?? []))),
    $bodyTypes
));

// §1.3 — Fuel type multi-select chips
$fuelTypeWhitelist = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'Plug-in Hybrid', 'LPG'];
$fuelTypes = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['fuel_type'] ?? []))),
    $fuelTypeWhitelist
));

// §1.4 — Transmission multi-select chips
$transmissionWhitelist = ['Automatic', 'Manual', 'Semi-automatic', 'DSG/Dual-clutch', 'CVT'];
$transmissions = array_values(array_intersect(
    array_filter(array_map('trim', (array) ($_GET['transmission'] ?? []))),
    $transmissionWhitelist
));

// §1.5 — Drivetrain multi-select chips
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
if ($make)      { $where[] = 'c.make = ?';           $params[] = $make; }
if ($condition) { $where[] = 'c.condition_type = ?';  $params[] = $condition; }
if ($province)  { $where[] = 'a.province = ?';        $params[] = $province; }
if ($deskSlug)  { $where[] = 'first_desk.desk_slug = ?'; $params[] = $deskSlug; }
if ($priceMin)  { $where[] = 'c.price >= ?';          $params[] = $priceMin; }
if ($priceMax)  { $where[] = 'c.price <= ?';          $params[] = $priceMax; }

// §1.1 — Mileage range
if ($mileageMin) { $where[] = 'c.mileage >= ?'; $params[] = $mileageMin; }
if ($mileageMax) { $where[] = 'c.mileage <= ?'; $params[] = $mileageMax; }

// §1.2 — Year range
if ($yearMin) { $where[] = 'c.year >= ?'; $params[] = $yearMin; }
if ($yearMax) { $where[] = 'c.year <= ?'; $params[] = $yearMax; }

// §2.1 — Body style IN clause (multi-value)
if (!empty($bodyTypesSelected)) {
    $placeholders = implode(',', array_fill(0, count($bodyTypesSelected), '?'));
    $where[]      = "c.body_type IN ({$placeholders})";
    $params       = array_merge($params, $bodyTypesSelected);
}

// §1.3 — Fuel type IN clause
if (!empty($fuelTypes)) {
    $placeholders = implode(',', array_fill(0, count($fuelTypes), '?'));
    $where[]      = "c.fuel_type IN ({$placeholders})";
    $params       = array_merge($params, $fuelTypes);
}

// §1.4 — Transmission IN clause
if (!empty($transmissions)) {
    $placeholders = implode(',', array_fill(0, count($transmissions), '?'));
    $where[]      = "c.transmission IN ({$placeholders})";
    $params       = array_merge($params, $transmissions);
}

// §1.5 — Drivetrain IN clause
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
$headingLabel  = !empty($activeFilters)
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
// Multi-value array params are passed as PHP arrays so http_build_query
// serialises them as body_type[]=SUV&body_type[]=Bakkie etc.
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
    'Gauteng'       => 'GP', 'Western Cape'  => 'WC', 'KwaZulu-Natal' => 'KZN',
    'Eastern Cape'  => 'EC', 'Limpopo'       => 'LP', 'Mpumalanga'    => 'MP',
    'North West'    => 'NW', 'Free State'    => 'FS', 'Northern Cape' => 'NC',
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

<div style="display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start;">

  <!-- ══════════════════════════════════
       FILTER SIDEBAR
       ══════════════════════════════════ -->
  <aside class="sidebar-scroll" style="position:sticky;top:76px;max-height:calc(100vh - 96px);overflow-y:auto;">
    <form method="GET" action="/c/" id="filterForm">
      <?php if ($ref): ?>
      <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
      <?php endif; ?>

      <div class="sidebar-card" style="padding:20px;">

        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:center;
                    padding-bottom:14px;border-bottom:1px solid #f1f5f9;margin-bottom:18px;">
          <span style="font-weight:700;font-size:14px;color:#1e293b;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-sliders" style="color:#0f4c9e;font-size:13px;"></i> Filters
          </span>
          <a href="/c/<?= $ref ? '?ref='.htmlspecialchars($ref) : '' ?>"
             style="font-size:12px;font-weight:600;color:#0f4c9e;
                    background:#eff4ff;padding:4px 12px;border-radius:20px;
                    text-decoration:none;transition:background .15s;">
            Reset all
          </a>
        </div>

        <!-- Search -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Search</div>
          <div style="position:relative;">
            <i class="fa-solid fa-magnifying-glass"
               style="position:absolute;left:12px;top:50%;transform:translateY(-50%);
                      color:#94a3b8;font-size:12px;pointer-events:none;"></i>
            <input class="search-input-sidebar" type="text" name="q" id="sidebarSearch"
                   placeholder="Make, model, year…"
                   value="<?= htmlspecialchars($q) ?>">
          </div>
        </div>

        <!-- Condition -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Condition</div>
          <div style="display:flex;gap:6px;">
            <?php foreach (['new' => 'New', 'demo' => 'Demo', 'used' => 'Pre-Owned'] as $val => $label): ?>
            <label class="condition-chip <?= $condition === $val ? 'active' : '' ?>" style="flex:1;cursor:pointer;">
              <input type="radio" name="condition" value="<?= $val ?>"
                     <?= $condition === $val ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Budget -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Budget (ZAR)</div>
          <div style="display:flex;gap:8px;align-items:center;">
            <input class="min-max-input" type="number" name="price_min" placeholder="Min"
                   value="<?= $priceMin ?: '' ?>" min="0" step="10000" style="flex:1;">
            <span style="color:#cbd5e1;font-weight:300;">—</span>
            <input class="min-max-input" type="number" name="price_max" placeholder="Max"
                   value="<?= $priceMax ?: '' ?>" min="0" step="10000" style="flex:1;">
          </div>
        </div>

        <!-- §1.1 Mileage filter -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Mileage (km)</div>
          <div style="display:flex;gap:8px;align-items:center;">
            <input class="min-max-input" type="number" name="mileage_min" placeholder="Min"
                   value="<?= $mileageMin ?: '' ?>" min="0" step="5000" style="flex:1;">
            <span style="color:#cbd5e1;font-weight:300;">—</span>
            <input class="min-max-input" type="number" name="mileage_max" placeholder="Max"
                   value="<?= $mileageMax ?: '' ?>" min="0" step="5000" style="flex:1;">
          </div>
        </div>

        <!-- §1.2 Year of manufacture filter -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Year of manufacture</div>
          <div style="display:flex;gap:8px;">
            <select class="filter-select" name="year_min" onchange="this.form.submit()" style="flex:1;">
              <option value="">From</option>
              <?php for ($y = $yearFloor; $y <= $yearCeiling; $y++): ?>
              <option value="<?= $y ?>" <?= $yearMin === $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
            <select class="filter-select" name="year_max" onchange="this.form.submit()" style="flex:1;">
              <option value="">To</option>
              <?php for ($y = $yearFloor; $y <= $yearCeiling; $y++): ?>
              <option value="<?= $y ?>" <?= $yearMax === $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <!-- Make -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Brand / Make</div>
          <select class="filter-select" name="make" onchange="this.form.submit()">
            <option value="">All brands</option>
            <?php foreach ($makes as $m): ?>
            <option value="<?= htmlspecialchars($m) ?>" <?= $make === $m ? 'selected' : '' ?>>
              <?= htmlspecialchars($m) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- §2.1 Body Style — multi-select chips (replaces single <select>) -->
        <?php if (!empty($bodyTypes)): ?>
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Body Style</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($bodyTypes as $bt): ?>
            <label class="condition-chip <?= in_array($bt, $bodyTypesSelected, true) ? 'active' : '' ?>"
                   style="flex:none;cursor:pointer;">
              <input type="checkbox" name="body_type[]" value="<?= htmlspecialchars($bt) ?>"
                     <?= in_array($bt, $bodyTypesSelected, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= htmlspecialchars($bt) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- §1.3 Fuel / Powertrain multi-select chips -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Fuel / Powertrain</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($fuelTypeWhitelist as $ft): ?>
            <label class="condition-chip <?= in_array($ft, $fuelTypes, true) ? 'active' : '' ?>"
                   style="flex:none;cursor:pointer;">
              <input type="checkbox" name="fuel_type[]" value="<?= htmlspecialchars($ft) ?>"
                     <?= in_array($ft, $fuelTypes, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= htmlspecialchars($ft) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- §1.4 Transmission multi-select chips -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Transmission</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($transmissionWhitelist as $tr): ?>
            <label class="condition-chip <?= in_array($tr, $transmissions, true) ? 'active' : '' ?>"
                   style="flex:none;cursor:pointer;">
              <input type="checkbox" name="transmission[]" value="<?= htmlspecialchars($tr) ?>"
                     <?= in_array($tr, $transmissions, true) ? 'checked' : '' ?>
                     style="display:none;" onchange="this.form.submit()">
              <?= htmlspecialchars($tr) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- §1.5 Drivetrain multi-select chips -->
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Drivetrain</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($drivetrainWhitelist as $dr): ?>
            <label class="condition-chip <?= in_array($dr, $drivetrains, true) ? 'active' : '' ?>"
                   style="flex:none;cursor:pointer;">
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
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">Province</div>
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

        <!-- SalesDesk filter -->
        <?php if (!empty($desks)): ?>
        <div style="margin-bottom:18px;">
          <div class="filter-section-label">SalesDesk Broker</div>
          <select class="filter-select" name="desk" onchange="this.form.submit()">
            <option value="">All SalesDesks</option>
            <?php foreach ($desks as $dk): ?>
            <option value="<?= htmlspecialchars($dk['slug']) ?>" <?= $deskSlug === $dk['slug'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($dk['display_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <!-- §2.2: Sort removed from sidebar — it lives in the results bar now -->

        <!-- Apply -->
        <button type="submit"
                style="width:100%;padding:10px;background:#0f4c9e;color:#fff;
                       font-size:13px;font-weight:600;border:none;border-radius:12px;
                       cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .18s;">
          Apply filters
        </button>

        <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;
                    font-size:11px;color:#94a3b8;">
          <i class="fa-solid fa-bolt" style="color:#facc15;margin-right:4px;"></i>
          Filters submit instantly on select changes
        </div>

      </div>
    </form>

    <!-- §2.3 Search debounce — added after the closing </form> tag -->
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

  </aside>

  <!-- ══════════════════════════════════
       MAIN CONTENT
       ══════════════════════════════════ -->
  <div>

    <!-- §2.2 Results bar: count + active filter tags + SORT CONTROL (moved from sidebar) -->
    <div style="display:flex;align-items:center;justify-content:space-between;
                flex-wrap:wrap;gap:10px;margin-bottom:16px;" class="pub-anim">

      <!-- Left: count + active heading -->
      <p style="font-size:14px;color:#64748b;font-weight:500;margin:0;">
        <span style="font-family:'Sora',sans-serif;font-size:24px;font-weight:800;
                     color:#1e293b;margin-right:6px;">
          <?= number_format($total) ?>
        </span>
        vehicle<?= $total !== 1 ? 's' : '' ?> found
        <?php if ($headingLabel !== 'All Cars'): ?>
        <span style="color:#94a3b8;font-weight:400;">— <?= htmlspecialchars($headingLabel) ?></span>
        <?php endif; ?>
      </p>

      <!-- Right: sort — standalone form so it preserves all active filters -->
      <form method="GET" action="/c/" style="display:flex;align-items:center;gap:8px;">
        <?php
        // Re-emit all current filters as hidden fields so sort preserves them.
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

        <select class="filter-select" name="sort" onchange="this.form.submit()"
                style="width:auto;font-size:13px;">
          <option value="newest"      <?= $sort === 'newest'      ? 'selected' : '' ?>>Newest first</option>
          <option value="price_asc"   <?= $sort === 'price_asc'   ? 'selected' : '' ?>>Price: low → high</option>
          <option value="price_desc"  <?= $sort === 'price_desc'  ? 'selected' : '' ?>>Price: high → low</option>
          <option value="mileage_asc" <?= $sort === 'mileage_asc' ? 'selected' : '' ?>>Lowest mileage</option>
        </select>
      </form>

    </div>

    <!-- Active filter tags -->
    <?php
    $hasAnyActiveFilter = $q || $make || $condition || $province || $priceMin || $priceMax
        || $mileageMin || $mileageMax || $yearMin || $yearMax
        || !empty($bodyTypesSelected) || !empty($fuelTypes)
        || !empty($transmissions) || !empty($drivetrains);
    if ($hasAnyActiveFilter): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">

      <?php if ($q): ?>
      <span class="active-filter-tag">"<?= htmlspecialchars($q) ?>"
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)q=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($make): ?>
      <span class="active-filter-tag"><?= htmlspecialchars($make) ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)make=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($condition): ?>
      <span class="active-filter-tag"><?= htmlspecialchars(ucfirst($condition)) ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)condition=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($province): ?>
      <span class="active-filter-tag"><?= htmlspecialchars($province) ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)province=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php if ($priceMin || $priceMax): ?>
      <span class="active-filter-tag">
        <?= $priceMin ? 'R '.number_format($priceMin) : '' ?>
        <?= ($priceMin && $priceMax) ? ' – ' : '' ?>
        <?= $priceMax ? 'R '.number_format($priceMax) : '' ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)price_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php // §1.1 — Mileage active tag
      if ($mileageMin || $mileageMax): ?>
      <span class="active-filter-tag">
        <?= $mileageMin ? number_format($mileageMin).' km' : '' ?>
        <?= ($mileageMin && $mileageMax) ? ' – ' : '' ?>
        <?= $mileageMax ? number_format($mileageMax).' km' : '' ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)mileage_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php // §1.2 — Year active tag
      if ($yearMin || $yearMax): ?>
      <span class="active-filter-tag">
        <?= $yearMin ?: '?' ?> – <?= $yearMax ?: '?' ?>
        <a href="<?= htmlspecialchars('?' . preg_replace('/(?:^|&)year_(?:min|max)=[^&]*/', '', $filterQs)) ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endif; ?>

      <?php // §2.1 — Body style active tags (one per selection)
      foreach ($bodyTypesSelected as $bt):
        $remaining = array_filter($bodyTypesSelected, fn($x) => $x !== $bt);
        $dismissQs = http_build_query(array_merge(
            array_filter(['q'=>$q,'make'=>$make,'condition'=>$condition,'province'=>$province,
                          'desk'=>$deskSlug,'price_min'=>$priceMin?:'','price_max'=>$priceMax?:'',
                          'mileage_min'=>$mileageMin?:'','mileage_max'=>$mileageMax?:'',
                          'year_min'=>$yearMin?:'','year_max'=>$yearMax?:'',
                          'sort'=>$sort!=='newest'?$sort:'','ref'=>$ref]),
            $remaining ? ['body_type' => array_values($remaining)] : [],
            $fuelTypes      ? ['fuel_type'    => $fuelTypes]      : [],
            $transmissions  ? ['transmission' => $transmissions]  : [],
            $drivetrains    ? ['drivetrain'   => $drivetrains]    : []
        ));
      ?>
      <span class="active-filter-tag"><?= htmlspecialchars($bt) ?>
        <a href="/c/?<?= $dismissQs ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endforeach; ?>

      <?php // §1.3 — Fuel type active tags (one per selection)
      foreach ($fuelTypes as $ft):
        $remaining = array_filter($fuelTypes, fn($x) => $x !== $ft);
        $dismissQs = http_build_query(array_merge(
            array_filter(['q'=>$q,'make'=>$make,'condition'=>$condition,'province'=>$province,
                          'desk'=>$deskSlug,'price_min'=>$priceMin?:'','price_max'=>$priceMax?:'',
                          'mileage_min'=>$mileageMin?:'','mileage_max'=>$mileageMax?:'',
                          'year_min'=>$yearMin?:'','year_max'=>$yearMax?:'',
                          'sort'=>$sort!=='newest'?$sort:'','ref'=>$ref]),
            $bodyTypesSelected ? ['body_type'   => $bodyTypesSelected] : [],
            $remaining         ? ['fuel_type'   => array_values($remaining)] : [],
            $transmissions     ? ['transmission'=> $transmissions]     : [],
            $drivetrains       ? ['drivetrain'  => $drivetrains]       : []
        ));
      ?>
      <span class="active-filter-tag"><?= htmlspecialchars($ft) ?>
        <a href="/c/?<?= $dismissQs ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endforeach; ?>

      <?php // §1.4 — Transmission active tags
      foreach ($transmissions as $tr):
        $remaining = array_filter($transmissions, fn($x) => $x !== $tr);
        $dismissQs = http_build_query(array_merge(
            array_filter(['q'=>$q,'make'=>$make,'condition'=>$condition,'province'=>$province,
                          'desk'=>$deskSlug,'price_min'=>$priceMin?:'','price_max'=>$priceMax?:'',
                          'mileage_min'=>$mileageMin?:'','mileage_max'=>$mileageMax?:'',
                          'year_min'=>$yearMin?:'','year_max'=>$yearMax?:'',
                          'sort'=>$sort!=='newest'?$sort:'','ref'=>$ref]),
            $bodyTypesSelected ? ['body_type'   => $bodyTypesSelected] : [],
            $fuelTypes         ? ['fuel_type'   => $fuelTypes]         : [],
            $remaining         ? ['transmission'=> array_values($remaining)] : [],
            $drivetrains       ? ['drivetrain'  => $drivetrains]       : []
        ));
      ?>
      <span class="active-filter-tag"><?= htmlspecialchars($tr) ?>
        <a href="/c/?<?= $dismissQs ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endforeach; ?>

      <?php // §1.5 — Drivetrain active tags
      foreach ($drivetrains as $dr):
        $remaining = array_filter($drivetrains, fn($x) => $x !== $dr);
        $dismissQs = http_build_query(array_merge(
            array_filter(['q'=>$q,'make'=>$make,'condition'=>$condition,'province'=>$province,
                          'desk'=>$deskSlug,'price_min'=>$priceMin?:'','price_max'=>$priceMax?:'',
                          'mileage_min'=>$mileageMin?:'','mileage_max'=>$mileageMax?:'',
                          'year_min'=>$yearMin?:'','year_max'=>$yearMax?:'',
                          'sort'=>$sort!=='newest'?$sort:'','ref'=>$ref]),
            $bodyTypesSelected ? ['body_type'   => $bodyTypesSelected] : [],
            $fuelTypes         ? ['fuel_type'   => $fuelTypes]         : [],
            $transmissions     ? ['transmission'=> $transmissions]     : [],
            $remaining         ? ['drivetrain'  => array_values($remaining)] : []
        ));
      ?>
      <span class="active-filter-tag"><?= htmlspecialchars($dr) ?>
        <a href="/c/?<?= $dismissQs ?>"
           style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
      </span>
      <?php endforeach; ?>

    </div>
    <?php endif; ?>

    <!-- ── Budget quick-filter buttons ─────────────────────── -->
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:20px;" class="pub-anim">
      <?php
      $priceRanges = [
          ['Under R 200k',    '',      200000],
          ['R 200k – R 350k', 200000,  350000],
          ['R 350k – R 500k', 350000,  500000],
          ['R 500k – R 800k', 500000,  800000],
          ['Over R 800k',     800000,  ''],
      ];
      foreach ($priceRanges as [$label, $min, $max]):
          $isActive = (string)$priceMin === (string)$min && (string)$priceMax === (string)$max;
          $qs = http_build_query(array_filter([
              'make'       => $make,
              'condition'  => $condition,
              'body_type'  => $bodyTypesSelected ?: null,
              'fuel_type'  => $fuelTypes         ?: null,
              'transmission'=> $transmissions    ?: null,
              'drivetrain' => $drivetrains       ?: null,
              'province'   => $province,
              'sort'       => $sort !== 'newest' ? $sort : '',
              'price_min'  => $min,
              'price_max'  => $max,
              'ref'        => $ref,
          ]));
      ?>
      <a href="/c/?<?= $qs ?>"
         style="display:inline-flex;align-items:center;padding:6px 14px;border-radius:20px;
                font-size:12px;font-weight:600;text-decoration:none;transition:all .18s;
                border:1.5px solid <?= $isActive ? '#0f4c9e' : '#e2e8f0' ?>;
                background:<?= $isActive ? '#0f4c9e' : '#fff' ?>;
                color:<?= $isActive ? '#fff' : '#64748b' ?>;
                box-shadow:<?= $isActive ? '0 2px 8px rgba(15,76,158,.22)' : 'none' ?>;">
        <?= htmlspecialchars($label) ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- ── Car Grid ─────────────────────────────────────────── -->
    <?php if (empty($cars)): ?>
    <div style="text-align:center;padding:5rem 1rem;background:white;
                border-radius:16px;border:1px solid #e8ecf4;">
      <i class="fa-regular fa-face-meh-blank"
         style="font-size:40px;margin-bottom:14px;display:block;color:#e2e8f0;"></i>
      <div style="font-family:'Sora',sans-serif;font-size:18px;font-weight:700;
                  color:#1e293b;margin-bottom:6px;">No vehicles match your filters</div>
      <div style="font-size:13px;color:#94a3b8;margin-bottom:20px;">
        Try adjusting your budget, province, or body type.
      </div>
      <a href="/c/" style="font-size:13px;font-weight:600;color:#0f4c9e;">
        Reset all filters
      </a>
    </div>

    <?php else: ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;"
         id="carGrid">
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
      <a href="<?= $detailUrl ?>" class="vehicle-card pub-reveal"
         style="display:block;text-decoration:none;color:inherit;">

        <!-- Image block -->
        <div style="position:relative;height:195px;overflow:hidden;background:#f3f4f8;">
          <?php if ($thumb): ?>
          <img src="<?= htmlspecialchars($thumb) ?>"
               alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
               loading="lazy"
               style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;
                      justify-content:center;font-size:42px;color:#e2e8f0;">
            <i class="fa-solid fa-car-side"></i>
          </div>
          <?php endif; ?>

          <!-- Year pill — top left -->
          <div style="position:absolute;top:12px;left:12px;
                      background:rgba(0,0,0,.55);backdrop-filter:blur(6px);
                      color:#fff;font-size:11px;font-weight:700;
                      padding:3px 10px;border-radius:20px;
                      font-family:'Sora',sans-serif;">
            <?= (int)$car['year'] ?>
          </div>

          <!-- Province pill — top right -->
          <?php if ($prov): ?>
          <div style="position:absolute;top:12px;right:12px;
                      background:rgba(255,255,255,.95);color:#1e293b;
                      font-size:11px;font-weight:600;
                      padding:3px 10px;border-radius:20px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
            <i class="fa-solid fa-location-dot" style="color:#0f4c9e;margin-right:3px;font-size:9px;"></i><?= htmlspecialchars($prov) ?>
          </div>
          <?php endif; ?>

          <!-- Mileage pill — bottom right -->
          <?php if ($car['mileage']): ?>
          <div style="position:absolute;bottom:12px;right:12px;
                      background:rgba(255,255,255,.95);color:#1e293b;
                      font-size:11px;font-weight:600;
                      padding:3px 10px;border-radius:20px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
            <i class="fa-solid fa-road" style="color:#94a3b8;margin-right:3px;font-size:9px;"></i><?= number_format((int)$car['mileage']) ?> km
          </div>
          <?php endif; ?>

          <!-- Condition pill — bottom left -->
          <?php if ($isNew): ?>
          <div style="position:absolute;bottom:12px;left:12px;
                      background:#0f4c9e;color:#fff;
                      font-size:10px;font-weight:700;
                      padding:3px 10px;border-radius:20px;font-family:'Sora',sans-serif;">
            NEW
          </div>
          <?php elseif ($isEV): ?>
          <div style="position:absolute;bottom:12px;left:12px;
                      background:#15803d;color:#fff;
                      font-size:10px;font-weight:700;
                      padding:3px 10px;border-radius:20px;">
            EV
          </div>
          <?php endif; ?>
        </div>

        <!-- Card body -->
        <div style="padding:16px;">

          <!-- Name + price row -->
          <div style="display:flex;justify-content:space-between;align-items:flex-start;
                      gap:10px;margin-bottom:8px;">
            <div style="min-width:0;">
              <h3 style="font-family:'Sora',sans-serif;font-weight:700;font-size:14px;
                         color:#1e293b;line-height:1.3;
                         overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars("{$car['make']} {$car['model']}") ?>
              </h3>
              <p style="font-size:11px;color:#94a3b8;margin-top:2px;
                        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <i class="fa-regular fa-building" style="margin-right:3px;"></i><?= htmlspecialchars($car['dealer_name']) ?>
              </p>
            </div>
            <span class="price-tag" style="font-size:16px;white-space:nowrap;flex-shrink:0;">
              <?= $priceStr ?>
            </span>
          </div>

          <!-- SalesDesk badge + verified -->
          <div style="display:flex;align-items:center;justify-content:space-between;
                      margin-bottom:10px;">
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
          <div style="display:flex;flex-wrap:wrap;gap:5px;">

            <?php if ($car['fuel_type']): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#475569;
                         font-size:11px;padding:2px 9px;border-radius:20px;">
              <i class="fa-solid <?= fuelIcon($car['fuel_type']) ?>"
                 style="margin-right:4px;font-size:10px;"></i><?= htmlspecialchars($car['fuel_type']) ?>
            </span>
            <?php endif; ?>

            <?php if ($car['transmission']): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#475569;
                         font-size:11px;padding:2px 9px;border-radius:20px;">
              <i class="fa-solid fa-gear" style="margin-right:4px;font-size:10px;"></i><?= htmlspecialchars($car['transmission']) ?>
            </span>
            <?php endif; ?>

            <?php if ($car['drivetrain']): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#475569;
                         font-size:11px;padding:2px 9px;border-radius:20px;">
              <?= htmlspecialchars($car['drivetrain']) ?>
            </span>
            <?php endif; ?>

            <?php if ($car['body_type']): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#475569;
                         font-size:11px;padding:2px 9px;border-radius:20px;">
              <?= htmlspecialchars($car['body_type']) ?>
            </span>
            <?php endif; ?>

          </div>

        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:2.5rem;
                flex-wrap:wrap;" class="pub-reveal">
      <?php if ($page > 1): ?>
      <a href="/c/?<?= $filterQs ?>&page=<?= $page - 1 ?>"
         class="pub-btn pub-btn-ghost" style="padding:8px 16px;font-size:13px;">
        <i class="fa-solid fa-chevron-left"></i> Prev
      </a>
      <?php endif; ?>
      <?php
      $prev = null;
      $pageRange = [];
      for ($p = 1; $p <= $totalPages; $p++) {
        if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) $pageRange[] = $p;
      }
      foreach ($pageRange as $p):
        if ($prev !== null && $p - $prev > 1): ?>
        <span style="padding:8px 6px;color:#94a3b8;font-size:13px;">…</span>
        <?php endif; ?>
        <a href="/c/?<?= $filterQs ?>&page=<?= $p ?>"
           style="padding:8px 14px;border-radius:10px;font-size:13px;
                  font-family:'DM Mono',monospace;text-decoration:none;
                  border:1px solid <?= $p === $page ? '#0f4c9e' : '#e2e8f0' ?>;
                  background:<?= $p === $page ? '#0f4c9e' : 'white' ?>;
                  color:<?= $p === $page ? '#fff' : '#64748b' ?>;">
          <?= $p ?>
        </a>
      <?php $prev = $p; endforeach; ?>
      <?php if ($page < $totalPages): ?>
      <a href="/c/?<?= $filterQs ?>&page=<?= $page + 1 ?>"
         class="pub-btn pub-btn-ghost" style="padding:8px 16px;font-size:13px;">
        Next <i class="fa-solid fa-chevron-right"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; // end cars not empty ?>

  </div><!-- /main col -->
</div><!-- /grid -->

<style>
/* ── Shared component styles (scoped to this page) ─────────── */
.filter-section-label {
  font-size: 12px;
  font-weight: 700;
  color: #94a3b8;
  letter-spacing: .07em;
  text-transform: uppercase;
  margin-bottom: 8px;
}
.sidebar-card {
  background: white;
  border-radius: 16px;
  border: 1px solid #e8ecf4;
  box-shadow: 0 2px 12px rgba(15,76,158,.06);
}
.vehicle-card {
  background: white;
  border-radius: 16px;
  border: 1px solid #e8ecf4;
  overflow: hidden;
  transition: all .28s cubic-bezier(.2,0,0,1);
  box-shadow: 0 2px 10px rgba(0,0,0,.04);
}
.vehicle-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 16px 32px rgba(15,76,158,.13);
  border-color: #c7d6f5;
}
.vehicle-card img { transition: transform .5s ease; }
.vehicle-card:hover img { transform: scale(1.06); }

.min-max-input {
  width: 100%;
  padding: 8px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: 13px;
  background: #f8faff;
  color: #1e293b;
  outline: none;
  transition: border-color .15s;
  font-family: 'DM Sans', sans-serif;
}
.min-max-input:focus { border-color: #0f4c9e; background: white; }
.min-max-input::placeholder { color: #94a3b8; }

select.filter-select {
  width: 100%;
  padding: 9px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: 13px;
  background: #f8faff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 12px center;
  color: #1e293b;
  outline: none;
  appearance: none;
  padding-right: 32px;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
}
select.filter-select:focus { border-color: #0f4c9e; background-color: white; }

.search-input-sidebar {
  width: 100%;
  padding: 10px 14px 10px 38px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: 13px;
  background: #f8faff;
  color: #1e293b;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
  font-family: 'DM Sans', sans-serif;
}
.search-input-sidebar:focus {
  border-color: #0f4c9e;
  box-shadow: 0 0 0 3px rgba(15,76,158,.08);
  background: white;
}
.search-input-sidebar::placeholder { color: #94a3b8; }

.salesdesk-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #eff4ff;
  color: #0f4c9e;
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 20px;
  border: 1px solid #dbeafe;
  font-family: 'Sora', sans-serif;
  letter-spacing: .01em;
}
.verified-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: #f0fdf4;
  color: #15803d;
  font-size: 10px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 20px;
  border: 1px solid #bbf7d0;
}
.price-tag {
  font-family: 'Sora', sans-serif;
  font-weight: 700;
  color: #0f4c9e;
}
.active-filter-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #eff4ff;
  color: #0f4c9e;
  border: 1px solid #bfcfef;
  border-radius: 20px;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 500;
}
.condition-chip {
  flex: 1;
  text-align: center;
  cursor: pointer;
  transition: all .18s ease;
  background: white;
  border: 1.5px solid #e2e8f0;
  padding: 7px 10px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  user-select: none;
}
.condition-chip:hover { border-color: #0f4c9e; color: #0f4c9e; }
.condition-chip.active {
  background: #0f4c9e;
  color: white;
  border-color: #0f4c9e;
  box-shadow: 0 2px 8px rgba(15,76,158,.25);
}
.sidebar-scroll::-webkit-scrollbar { width: 4px; }
.sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
.sidebar-scroll::-webkit-scrollbar-thumb { background: #dde3ef; border-radius: 4px; }

/* Responsive */
@media (max-width: 900px) {
  div[style*="grid-template-columns:300px"] {
    grid-template-columns: 1fr !important;
  }
  aside[style*="position:sticky"] {
    position: static !important;
    max-height: none !important;
  }
}
</style>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
