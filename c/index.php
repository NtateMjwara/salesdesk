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
 * Filters: make, condition, body_type, price_min, price_max,
 *          province, sort, q (search), page, desk (by desk slug)
 *
 * UI: matches index_template.php design — sidebar filters, card grid,
 *     salesdesk badge on every card, lead modal.
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
// If the buyer arrived via ?ref= we keep it so the sharing broker
// gets credit even though the browse page itself shows first-lister URLs.
$ref = trim($_GET['ref'] ?? $visitor['last_tracking_code'] ?? '');
if ($ref && !preg_match('/^[a-f0-9]{32}$/i', $ref)) $ref = '';

// ── Filters ───────────────────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$make      = trim($_GET['make']      ?? '');
$condition = trim($_GET['condition'] ?? '');
$bodyType  = trim($_GET['body_type'] ?? '');
$province  = trim($_GET['province']  ?? '');
$deskSlug  = trim($_GET['desk']      ?? '');
$priceMin  = (int) ($_GET['price_min'] ?? 0);
$priceMax  = (int) ($_GET['price_max'] ?? 0);
$sort      = trim($_GET['sort']      ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

// ── Whitelist ─────────────────────────────────────────────────
$validConditions = ['new', 'demo', 'used'];
$validSorts      = ['newest', 'price_asc', 'price_desc', 'mileage_asc', 'commission_desc'];
if (!in_array($condition, $validConditions, true)) $condition = '';
if (!in_array($sort, $validSorts, true))           $sort = 'newest';

// ── Attribution v2: subquery picks the earliest-listed desk per car ──
// We JOIN to a derived table (first_desk) that resolves desk slug + tracking
// code from the broker who first added the car.
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
if ($bodyType)  { $where[] = 'c.body_type = ?';       $params[] = $bodyType; }
if ($province)  { $where[] = 'a.province = ?';        $params[] = $province; }
if ($deskSlug)  { $where[] = 'first_desk.desk_slug = ?'; $params[] = $deskSlug; }
if ($priceMin)  { $where[] = 'c.price >= ?';          $params[] = $priceMin; }
if ($priceMax)  { $where[] = 'c.price <= ?';          $params[] = $priceMax; }

$wc = 'WHERE ' . implode(' AND ', $where);

$commExpr = "CASE c.commission_type
    WHEN 'fixed'      THEN c.commission_value
    WHEN 'percentage' THEN c.price * (c.commission_value / 100)
    ELSE 0 END";

$sortSql = match($sort) {
    'price_asc'       => 'c.price ASC',
    'price_desc'      => 'c.price DESC',
    'mileage_asc'     => 'c.mileage ASC',
    'commission_desc' => "({$commExpr}) DESC, c.created_at DESC",
    default           => 'c.created_at DESC',
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

// ── Filter options ────────────────────────────────────────────
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

// ── Active filter label ───────────────────────────────────────
$activeFilters = array_filter([$q, $make, $condition, $bodyType, $province, $deskSlug]);
$headingLabel  = !empty($activeFilters)
    ? implode(' · ', array_filter([
        $condition ? ucfirst($condition) : '',
        $make, $bodyType, $province,
        $deskSlug,
        $q ? "\"{$q}\"" : '',
    ]))
    : 'All Cars';

// ── Pagination base query string ─────────────────────────────
$filterQs = http_build_query(array_filter([
    'q'         => $q,
    'make'      => $make,
    'condition' => $condition,
    'body_type' => $bodyType,
    'province'  => $province,
    'desk'      => $deskSlug,
    'price_min' => $priceMin ?: '',
    'price_max' => $priceMax ?: '',
    'sort'      => $sort !== 'newest' ? $sort : '',
    'ref'       => $ref,
]));

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

<!-- ══════════════════════════════════════════════════════════════
     BROWSE PAGE  —  sidebar + grid layout (matches template design)
     ══════════════════════════════════════════════════════════════ -->

<div style="display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;">

  <!-- ── Sidebar ──────────────────────────────────────────────── -->
  <aside class="pub-browse-sidebar sidebar-scroll" style="position:sticky;top:76px;max-height:calc(100vh - 96px);overflow-y:auto;">
    <form method="GET" action="/c/" id="filterForm">
      <?php if ($ref): ?>
      <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
      <?php endif; ?>

      <!-- Search -->
      <div class="sidebar-card" style="padding:18px;margin-bottom:14px;">
        <div class="filter-section-label">Search</div>
        <div style="position:relative;">
          <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;pointer-events:none;"></i>
          <input class="search-input-sidebar" type="text" name="q" id="sidebarSearch"
                 placeholder="Make, model, year…"
                 value="<?= htmlspecialchars($q) ?>">
        </div>
      </div>

      <!-- Filters card -->
      <div class="sidebar-card" style="padding:18px;margin-bottom:14px;">

        <!-- Condition chips -->
        <div class="filter-section-label">Condition</div>
        <div style="display:flex;gap:6px;margin-bottom:16px;">
          <?php foreach (['new' => 'New', 'demo' => 'Demo', 'used' => 'Used'] as $val => $label): ?>
          <label class="condition-chip <?= $condition === $val ? 'active' : '' ?>" style="flex:1;text-align:center;cursor:pointer;">
            <input type="radio" name="condition" value="<?= $val ?>"
                   <?= $condition === $val ? 'checked' : '' ?>
                   style="display:none;" onchange="this.form.submit()">
            <?= $label ?>
          </label>
          <?php endforeach; ?>
          <?php if ($condition): ?>
          <button type="button" onclick="document.querySelector('[name=condition]:checked').checked=false;document.querySelector('[name=condition]').value='';this.form.submit();"
                  style="font-size:11px;color:#94a3b8;background:none;border:none;cursor:pointer;padding:0 4px;white-space:nowrap;">✕ All</button>
          <?php endif; ?>
        </div>

        <!-- Make -->
        <div class="filter-section-label">Make</div>
        <select class="filter-select" name="make" style="margin-bottom:14px;" onchange="this.form.submit()">
          <option value="">All makes</option>
          <?php foreach ($makes as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>" <?= $make === $m ? 'selected' : '' ?>>
            <?= htmlspecialchars($m) ?>
          </option>
          <?php endforeach; ?>
        </select>

        <!-- Body type -->
        <?php if (!empty($bodyTypes)): ?>
        <div class="filter-section-label">Body Type</div>
        <select class="filter-select" name="body_type" style="margin-bottom:14px;" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach ($bodyTypes as $bt): ?>
          <option value="<?= htmlspecialchars($bt) ?>" <?= $bodyType === $bt ? 'selected' : '' ?>>
            <?= htmlspecialchars($bt) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- Province -->
        <?php if (!empty($provinces)): ?>
        <div class="filter-section-label">Province</div>
        <select class="filter-select" name="province" style="margin-bottom:14px;" onchange="this.form.submit()">
          <option value="">All provinces</option>
          <?php foreach ($provinces as $pv): ?>
          <option value="<?= htmlspecialchars($pv) ?>" <?= $province === $pv ? 'selected' : '' ?>>
            <?= htmlspecialchars($pv) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- SalesDesk filter -->
        <?php if (!empty($desks)): ?>
        <div class="filter-section-label">SalesDesk</div>
        <select class="filter-select" name="desk" style="margin-bottom:14px;" onchange="this.form.submit()">
          <option value="">All desks</option>
          <?php foreach ($desks as $dk): ?>
          <option value="<?= htmlspecialchars($dk['slug']) ?>" <?= $deskSlug === $dk['slug'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($dk['display_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- Price range -->
        <div class="filter-section-label">Price Range (R)</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:4px;">
          <input class="min-max-input" type="number" name="price_min" placeholder="Min"
                 value="<?= $priceMin ?: '' ?>" min="0" step="10000">
          <input class="min-max-input" type="number" name="price_max" placeholder="Max"
                 value="<?= $priceMax ?: '' ?>" min="0" step="10000">
        </div>

        <!-- Apply / Reset -->
        <div style="display:flex;gap:8px;margin-top:14px;">
          <button type="submit" class="pub-btn pub-btn-primary" style="flex:1;padding:9px;font-size:13px;">
            Apply filters
          </button>
          <?php if (!empty($activeFilters) || $priceMin || $priceMax): ?>
          <a href="/c/<?= $ref ? '?ref=' . htmlspecialchars($ref) : '' ?>"
             class="pub-btn pub-btn-ghost" style="padding:9px 14px;font-size:13px;white-space:nowrap;">
            Clear
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Sort -->
      <div class="sidebar-card" style="padding:18px;margin-bottom:14px;">
        <div class="filter-section-label">Sort By</div>
        <select class="filter-select" name="sort" onchange="this.form.submit()">
          <option value="newest"          <?= $sort === 'newest'          ? 'selected' : '' ?>>Newest first</option>
          <option value="price_asc"       <?= $sort === 'price_asc'       ? 'selected' : '' ?>>Price (low → high)</option>
          <option value="price_desc"      <?= $sort === 'price_desc'      ? 'selected' : '' ?>>Price (high → low)</option>
          <option value="mileage_asc"     <?= $sort === 'mileage_asc'     ? 'selected' : '' ?>>Lowest mileage</option>
          <option value="commission_desc" <?= $sort === 'commission_desc' ? 'selected' : '' ?>>Highest commission</option>
        </select>
      </div>

    </form>

    <!-- Price quick filters -->
    <div class="sidebar-card" style="padding:14px 18px;">
      <div class="filter-section-label">Quick Price Filters</div>
      <div style="display:flex;flex-direction:column;gap:4px;">
        <?php
        $priceRanges = [
            ['Under R 200k',   '',      200000],
            ['R 200k – R 350k', 200000, 350000],
            ['R 350k – R 500k', 350000, 500000],
            ['R 500k – R 800k', 500000, 800000],
            ['Over R 800k',    800000,  ''],
        ];
        foreach ($priceRanges as [$label, $min, $max]):
            $isActive = (string)$priceMin === (string)$min && (string)$priceMax === (string)$max;
            $qs = http_build_query(array_filter([
                'make' => $make, 'condition' => $condition, 'sort' => $sort !== 'newest' ? $sort : '',
                'price_min' => $min, 'price_max' => $max, 'ref' => $ref,
            ]));
        ?>
        <a href="/c/?<?= $qs ?>"
           style="display:flex;align-items:center;padding:7px 10px;border-radius:10px;font-size:12px;
                  font-weight:500;text-decoration:none;border:1.5px solid;transition:all .18s;
                  background:<?= $isActive ? '#0f4c9e' : 'white' ?>;
                  color:<?= $isActive ? '#fff' : '#64748b' ?>;
                  border-color:<?= $isActive ? '#0f4c9e' : '#e2e8f0' ?>;">
          <?= htmlspecialchars($label) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  </aside>

  <!-- ── Main content ─────────────────────────────────────────── -->
  <div>

    <!-- Header row -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;" class="pub-anim">
      <div>
        <h1 style="font-family:'Sora',sans-serif;font-size:22px;font-weight:800;letter-spacing:-.02em;color:#1e293b;">
          <?= htmlspecialchars($headingLabel) ?>
          <span style="font-size:14px;font-weight:400;color:#94a3b8;margin-left:8px;">
            <?= number_format($total) ?> car<?= $total !== 1 ? 's' : '' ?>
          </span>
        </h1>
      </div>
      <!-- Active filter tags -->
      <?php if (!empty($activeFilters)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:6px;" id="activeFiltersBar">
        <?php if ($q): ?>
        <span class="active-filter-tag"><?= htmlspecialchars($q) ?>
          <a href="<?= htmlspecialchars(preg_replace('/[?&]q=[^&]*/', '', '?' . $filterQs)) ?>" style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
        </span>
        <?php endif; ?>
        <?php if ($make): ?>
        <span class="active-filter-tag"><?= htmlspecialchars($make) ?>
          <a href="<?= htmlspecialchars(preg_replace('/[?&]make=[^&]*/', '', '?' . $filterQs)) ?>" style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
        </span>
        <?php endif; ?>
        <?php if ($condition): ?>
        <span class="active-filter-tag"><?= htmlspecialchars(ucfirst($condition)) ?>
          <a href="<?= htmlspecialchars(preg_replace('/[?&]condition=[^&]*/', '', '?' . $filterQs)) ?>" style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
        </span>
        <?php endif; ?>
        <?php if ($province): ?>
        <span class="active-filter-tag"><?= htmlspecialchars($province) ?>
          <a href="<?= htmlspecialchars(preg_replace('/[?&]province=[^&]*/', '', '?' . $filterQs)) ?>" style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
        </span>
        <?php endif; ?>
        <?php if ($deskSlug): ?>
        <span class="active-filter-tag">
          <i class="fa-solid fa-id-card" style="font-size:10px;"></i>
          <?= htmlspecialchars($deskSlug) ?>
          <a href="<?= htmlspecialchars(preg_replace('/[?&]desk=[^&]*/', '', '?' . $filterQs)) ?>" style="color:#6b8ad4;text-decoration:none;font-size:14px;line-height:1;">✕</a>
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Car Grid -->
    <?php if (empty($cars)): ?>
    <div style="text-align:center;padding:4rem 1rem;background:white;border-radius:16px;border:1px solid #e8ecf4;">
      <i class="fa-solid fa-car-on" style="font-size:40px;margin-bottom:16px;display:block;color:#e2e8f0;"></i>
      <div style="font-family:'Sora',sans-serif;font-size:18px;font-weight:700;color:#1e293b;margin-bottom:8px;">No cars found</div>
      <div style="font-size:14px;color:#94a3b8;margin-bottom:20px;">Try adjusting your filters.</div>
      <a href="/c/" class="pub-btn pub-btn-primary" style="display:inline-flex;">Clear all filters</a>
    </div>
    <?php else: ?>

    <div class="pub-browse-grid" id="carGrid">
      <?php foreach ($cars as $car):
        $imgs    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
        $thumb   = $imgs[0] ?? null;

        // Attribution v2: desk slug is always in the URL.
        // If the buyer arrived via a broker ?ref= link, we carry that forward
        // so the sharing broker gets credit (may differ from first-lister).
        $detailUrl = '/c/' . htmlspecialchars($car['desk_slug']) . '/'
                   . htmlspecialchars($car['car_slug']) . '/'
                   . ($ref ? '?ref=' . htmlspecialchars($ref) : '');

        $priceStr  = 'R ' . number_format((float)$car['price'], 0, '.', ' ');
        $commRand  = $car['commission_type'] === 'fixed'
            ? (float)$car['commission_value']
            : round((float)$car['price'] * (float)$car['commission_value'] / 100, 2);
        $metaParts = array_filter([
            ucfirst($car['condition_type']),
            $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : null,
            $car['transmission'],
        ]);
        $location = implode(', ', array_filter([$car['dealer_city'], $car['dealer_province']]));
      ?>
      <a href="<?= $detailUrl ?>" class="vehicle-card pub-reveal" style="text-decoration:none;display:flex;flex-direction:column;">

        <!-- Image -->
        <div style="position:relative;height:195px;overflow:hidden;background:#f3f4f8;flex-shrink:0;">
          <?php if ($thumb): ?>
          <img src="<?= htmlspecialchars($thumb) ?>"
               alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
               loading="lazy"
               style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:36px;color:#e2e8f0;">
            <i class="fa-solid fa-car-side"></i>
          </div>
          <?php endif; ?>

          <!-- Condition badge -->
          <div style="position:absolute;top:10px;left:10px;">
            <span style="background:<?= $car['condition_type'] === 'new' ? '#0f4c9e' : 'rgba(0,0,0,.55)' ?>;
                         color:#fff;font-size:10px;font-weight:700;padding:3px 10px;
                         border-radius:20px;font-family:'Sora',sans-serif;letter-spacing:.02em;">
              <?= ucfirst($car['condition_type']) ?>
            </span>
          </div>

          <!-- Verified badge -->
          <?php if ($car['dealer_verified'] === 'verified'): ?>
          <div style="position:absolute;top:10px;right:10px;">
            <span class="verified-badge">
              <i class="fa-solid fa-circle-check" style="font-size:9px;"></i> Verified
            </span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Card body -->
        <div style="padding:14px;flex:1;display:flex;flex-direction:column;gap:8px;">

          <!-- Name + price -->
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
            <div style="font-family:'Sora',sans-serif;font-size:14px;font-weight:700;color:#1e293b;line-height:1.25;">
              <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
            </div>
          </div>
          <div class="price-tag" style="font-size:18px;"><?= $priceStr ?></div>

          <!-- SalesDesk badge — the key attribution element -->
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <span class="salesdesk-badge">
              <i class="fa-solid fa-id-card" style="font-size:9px;"></i>
              <?= htmlspecialchars($car['desk_name']) ?>
            </span>
            <?php if ($car['dealer_verified'] === 'verified'): ?>
            <span class="verified-badge" style="font-size:9px;">
              <i class="fa-solid fa-circle-check" style="font-size:8px;"></i> Verified
            </span>
            <?php endif; ?>
          </div>

          <!-- Spec pills -->
          <div style="display:flex;flex-wrap:wrap;gap:5px;">
            <?php foreach ($metaParts as $part): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#64748b;font-size:11px;
                         padding:2px 8px;border-radius:20px;">
              <?= htmlspecialchars($part) ?>
            </span>
            <?php endforeach; ?>
            <?php if ($car['fuel_type']): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#64748b;font-size:11px;
                         padding:2px 8px;border-radius:20px;">
              <?= htmlspecialchars($car['fuel_type']) ?>
            </span>
            <?php endif; ?>
            <?php if ($car['body_type']): ?>
            <span style="background:#f8faff;border:1px solid #f1f5f9;color:#64748b;font-size:11px;
                         padding:2px 8px;border-radius:20px;">
              <?= htmlspecialchars($car['body_type']) ?>
            </span>
            <?php endif; ?>
          </div>

          <!-- Dealer + location -->
          <div style="font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:5px;
                      padding-top:8px;border-top:1px solid #f1f5f9;margin-top:auto;">
            <i class="fa-solid fa-building-user" style="font-size:10px;"></i>
            <?= htmlspecialchars($car['dealer_name']) ?>
            <?php if ($location): ?>
            <span style="margin-left:auto;">
              <i class="fa-solid fa-location-dot" style="font-size:9px;"></i>
              <?= htmlspecialchars($location) ?>
            </span>
            <?php endif; ?>
          </div>

          <!-- Enquire button -->
          <button class="enquire-btn"
                  data-url="<?= htmlspecialchars($detailUrl) ?>"
                  data-car="<?= htmlspecialchars(json_encode([
                      'make'     => $car['make'],
                      'model'    => $car['model'],
                      'year'     => $car['year'],
                      'price'    => $priceStr,
                      'dealer'   => $car['dealer_name'],
                      'province' => $car['dealer_province'],
                      'desk'     => $car['desk_name'],
                      'desk_slug'=> $car['desk_slug'],
                      'car_slug' => $car['car_slug'],
                      'ref'      => $ref,
                  ])) ?>"
                  onclick="event.preventDefault();event.stopPropagation();openEnquiryModal(this);"
                  style="margin-top:4px;width:100%;background:#0f4c9e;color:#fff;font-size:13px;
                         font-weight:600;padding:10px;border-radius:12px;border:none;cursor:pointer;
                         display:flex;align-items:center;justify-content:center;gap:7px;
                         transition:background .18s;font-family:'DM Sans',sans-serif;">
            <i class="fa-solid fa-paper-plane" style="font-size:11px;"></i>
            Enquire via SalesDesk
          </button>

        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:2rem;flex-wrap:wrap;" class="pub-reveal">
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

    <?php endif; // cars not empty ?>

    <!-- Broker CTA -->
    <div class="pub-reveal" style="margin-top:2.5rem;background:linear-gradient(135deg,#08143c,#0f4c9e);
         border-radius:20px;padding:2.5rem;text-align:center;color:#fff;">
      <div style="font-family:'Sora',sans-serif;font-size:22px;font-weight:800;margin-bottom:8px;letter-spacing:-.02em;">
        Are you a car broker?
      </div>
      <p style="font-size:14px;color:rgba(255,255,255,.65);margin-bottom:1.5rem;max-width:420px;margin-left:auto;margin-right:auto;">
        Add cars to your SalesDesk and earn commission on every sale you close.
      </p>
      <a href="/brokers.php" class="pub-btn pub-btn-ghost"
         style="display:inline-flex;padding:11px 28px;font-size:14px;
                background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff;">
        Learn how it works →
      </a>
    </div>

  </div><!-- /main col -->
</div><!-- /grid -->

<!-- ── Enquiry modal (quick-enquire from browse, redirects to detail page) ── -->
<div id="enquiryModal" class="modal-bg" style="display:none;" onclick="if(event.target===this)closeEnquiryModal()">
  <div class="modal" style="max-width:480px;" onclick="event.stopPropagation()">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
      <div>
        <h3 class="modal-title" id="eModalTitle" style="font-family:'Sora',sans-serif;"></h3>
        <p style="font-size:12px;color:#64748b;margin-top:2px;" id="eModalMeta"></p>
      </div>
      <button onclick="closeEnquiryModal()" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1;padding:0;">&times;</button>
    </div>
    <div id="eModalDesk" style="margin-bottom:14px;"></div>
    <p style="font-size:12px;color:#64748b;margin-bottom:14px;line-height:1.6;">
      Complete your enquiry on the car's detail page — your broker attribution is preserved.
    </p>
    <a id="eModalBtn" href="#" class="btn btn-primary btn-full" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-radius:12px;font-size:14px;text-decoration:none;background:#0f4c9e;color:#fff;">
      <i class="fa-solid fa-paper-plane"></i> Go to listing &amp; enquire
    </a>
    <button onclick="closeEnquiryModal()" class="btn btn-ghost btn-full" style="margin-top:8px;padding:11px;border-radius:12px;font-size:13px;">Cancel</button>
  </div>
</div>

<style>
/* Inherit template design tokens */
.filter-section-label { font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:.07em;text-transform:uppercase;margin-bottom:8px; }
.sidebar-card { background:white;border-radius:16px;border:1px solid #e8ecf4;box-shadow:0 2px 12px rgba(15,76,158,.06); }
.vehicle-card { background:white;border-radius:16px;border:1px solid #e8ecf4;overflow:hidden;transition:all .28s cubic-bezier(.2,0,0,1);cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.04); }
.vehicle-card:hover { transform:translateY(-4px);box-shadow:0 16px 32px rgba(15,76,158,.13);border-color:#c7d6f5; }
.vehicle-card img { transition:transform .5s ease; }
.vehicle-card:hover img { transform:scale(1.06); }
.min-max-input { width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;background:#f8faff;color:#1e293b;outline:none;transition:border-color .15s;font-family:'DM Sans',sans-serif; }
.min-max-input:focus { border-color:#0f4c9e;background:white; }
.min-max-input::placeholder { color:#94a3b8; }
select.filter-select { width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;background:#f8faff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 12px center;color:#1e293b;outline:none;appearance:none;padding-right:32px; }
select.filter-select:focus { border-color:#0f4c9e;background-color:white; }
.search-input-sidebar { width:100%;padding:10px 14px 10px 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;background:#f8faff;color:#1e293b;outline:none;transition:border-color .15s,box-shadow .15s;font-family:'DM Sans',sans-serif; }
.search-input-sidebar:focus { border-color:#0f4c9e;box-shadow:0 0 0 3px rgba(15,76,158,.08);background:white; }
.search-input-sidebar::placeholder { color:#94a3b8; }
.salesdesk-badge { display:inline-flex;align-items:center;gap:4px;background:#eff4ff;color:#0f4c9e;font-size:11px;font-weight:600;padding:3px 8px;border-radius:20px;border:1px solid #dbeafe;font-family:'Sora',sans-serif;letter-spacing:.01em; }
.verified-badge { display:inline-flex;align-items:center;gap:3px;background:#f0fdf4;color:#15803d;font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;border:1px solid #bbf7d0; }
.price-tag { font-family:'Sora',sans-serif;font-weight:700;color:#0f4c9e; }
.active-filter-tag { display:inline-flex;align-items:center;gap:5px;background:#eff4ff;color:#0f4c9e;border:1px solid #bfcfef;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:500; }
.condition-chip { flex:1;text-align:center;cursor:pointer;transition:all .18s ease;background:white;border:1.5px solid #e2e8f0;padding:7px 12px;border-radius:10px;font-size:12px;font-weight:600;color:#64748b; }
.condition-chip:hover { border-color:#0f4c9e;color:#0f4c9e; }
.condition-chip.active { background:#0f4c9e;color:white;border-color:#0f4c9e;box-shadow:0 2px 8px rgba(15,76,158,.25); }
@media (max-width:900px) {
  .pub-browse-sidebar { display:none; }
  div[style*="grid-template-columns:280px"] { grid-template-columns:1fr !important; }
}
</style>

<script>
function openEnquiryModal(btn) {
    var car = JSON.parse(btn.dataset.car);
    document.getElementById('eModalTitle').textContent = car.year + ' ' + car.make + ' ' + car.model;
    document.getElementById('eModalMeta').innerHTML =
        '<i class="fa-solid fa-location-dot" style="color:#3b82f6;margin-right:4px;"></i>' +
        car.dealer + (car.province ? ' · ' + car.province : '') + ' · ' + car.price;
    document.getElementById('eModalDesk').innerHTML =
        '<span class="salesdesk-badge"><i class="fa-solid fa-id-card" style="font-size:9px;"></i> Listed by: ' +
        car.desk + '</span>';
    // Build detail URL — desk slug always in path
    var url = '/c/' + car.desk_slug + '/' + car.car_slug + '/';
    if (car.ref) url += '?ref=' + encodeURIComponent(car.ref);
    document.getElementById('eModalBtn').href = url;
    document.getElementById('enquiryModal').style.display = 'flex';
}
function closeEnquiryModal() {
    document.getElementById('enquiryModal').style.display = 'none';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeEnquiryModal(); });
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
