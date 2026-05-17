<?php
/**
 * SalesDesk — Public Browse / Search Page
 * Route: /c/index.php  (or /c/ via .htaccess)
 *
 * Buyer-facing car catalogue. No auth required.
 * Supports GET filters: make, condition, body_type, price_min,
 * price_max, province, dealer, sort, q (search), page.
 *
 * Tracking code (?ref=) is preserved across pagination so broker
 * attribution is not lost when a buyer pages through results.
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

// ── Filters ───────────────────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$make      = trim($_GET['make']      ?? '');
$condition = trim($_GET['condition'] ?? '');
$bodyType  = trim($_GET['body_type'] ?? '');
$province  = trim($_GET['province']  ?? '');
$dealerId  = (int) ($_GET['dealer']  ?? 0);
$priceMin  = (int) ($_GET['price_min'] ?? 0);
$priceMax  = (int) ($_GET['price_max'] ?? 0);
$sort      = trim($_GET['sort']      ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 24;
$offset    = ($page - 1) * $perPage;

// Tracking code — preserve for links.
$ref = trim($_GET['ref'] ?? $visitor['last_tracking_code'] ?? '');
if ($ref && !preg_match('/^[a-f0-9]{32}$/i', $ref)) $ref = '';

// ── Whitelist filter values ───────────────────────────────────
$validConditions = ['new', 'demo', 'used'];
$validSorts      = ['newest', 'price_asc', 'price_desc', 'mileage_asc'];
if (!in_array($condition, $validConditions, true)) $condition = '';
if (!in_array($sort, $validSorts, true))           $sort = 'newest';

// ── Build WHERE ───────────────────────────────────────────────
$where  = ["c.status = 'active'", "d.is_active = 1"];
$params = [];

if ($q) {
    $where[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year,' ',c.make,' ',c.model) LIKE ?)";
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($make)      { $where[] = 'c.make = ?';         $params[] = $make; }
if ($condition) { $where[] = 'c.condition_type = ?';$params[] = $condition; }
if ($bodyType)  { $where[] = 'c.body_type = ?';    $params[] = $bodyType; }
if ($province)  { $where[] = 'a.province = ?';     $params[] = $province; }
if ($dealerId)  { $where[] = 'd.id = ?';           $params[] = $dealerId; }
if ($priceMin)  { $where[] = 'c.price >= ?';       $params[] = $priceMin; }
if ($priceMax)  { $where[] = 'c.price <= ?';       $params[] = $priceMax; }

$wc = 'WHERE ' . implode(' AND ', $where);

$sortSql = match($sort) {
    'price_asc'   => 'c.price ASC',
    'price_desc'  => 'c.price DESC',
    'mileage_asc' => 'c.mileage ASC',
    default       => 'c.created_at DESC',
};

// ── Count total ───────────────────────────────────────────────
$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.id)
    FROM cars c
    JOIN dealers d   ON d.id = c.dealer_id
    LEFT JOIN addresses a ON a.id = d.address_id
    {$wc}
");
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

// ── Fetch cars ────────────────────────────────────────────────
$listParams   = array_merge($params, [$perPage, $offset]);
$carsStmt     = $pdo->prepare("
    SELECT
        c.id, c.slug, c.make, c.model, c.year, c.price, c.mileage,
        c.condition_type, c.body_type, c.image_urls, c.created_at,
        d.id            AS dealer_id,
        d.company_name  AS dealer_name,
        d.verification_status AS dealer_verified,
        a.city          AS dealer_city,
        a.province      AS dealer_province,
        (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.car_id = c.id) AS broker_count
    FROM cars c
    JOIN dealers d   ON d.id = c.dealer_id
    LEFT JOIN addresses a ON a.id = d.address_id
    {$wc}
    ORDER BY {$sortSql}
    LIMIT ? OFFSET ?
");
$carsStmt->execute($listParams);
$cars = $carsStmt->fetchAll();

// ── Filter options (for dropdowns) ───────────────────────────
$makes = $pdo->query("
    SELECT DISTINCT make FROM cars WHERE status='active' ORDER BY make
")->fetchAll(PDO::FETCH_COLUMN);

$bodyTypes = $pdo->query("
    SELECT DISTINCT body_type FROM cars WHERE status='active' AND body_type IS NOT NULL ORDER BY body_type
")->fetchAll(PDO::FETCH_COLUMN);

$provinces = $pdo->query("
    SELECT DISTINCT a.province
    FROM dealers d JOIN addresses a ON a.id = d.address_id
    WHERE d.is_active = 1 AND a.province IS NOT NULL
    ORDER BY a.province
")->fetchAll(PDO::FETCH_COLUMN);

// ── Active filter label for heading ──────────────────────────
$activeFilters = array_filter([$q, $make, $condition, $bodyType, $province]);
$headingLabel  = !empty($activeFilters)
    ? implode(' · ', array_filter([
        $condition ? ucfirst($condition) : '',
        $make,
        $bodyType,
        $province,
        $q ? "\"$q\"" : '',
    ]))
    : 'All Cars';

// ── Build base query string for pagination links ──────────────
$filterQs = http_build_query(array_filter([
    'q'         => $q,
    'make'      => $make,
    'condition' => $condition,
    'body_type' => $bodyType,
    'province'  => $province,
    'dealer'    => $dealerId ?: '',
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
$breadcrumbs   = [['Browse Cars', null]];
$layoutVariant = 'wide';

ob_start();
?>

<!-- Browse header -->
<div class="pub-browse-header pub-anim">
  <h1 class="pub-browse-title">
    <?= htmlspecialchars($headingLabel) ?>
    <span style="font-size:14px;font-weight:400;color:var(--faint);margin-left:8px;">
      <?= number_format($total) ?> car<?= $total !== 1 ? 's' : '' ?>
    </span>
  </h1>
</div>

<!-- Filter bar -->
<form method="GET" action="/c/" id="filterForm" class="pub-filter-bar pub-anim pub-d1">
  <?php if ($ref): ?>
  <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">
  <?php endif; ?>

  <!-- Search -->
  <div class="pub-filter-group" style="flex:3;min-width:200px;">
    <label class="pub-filter-label">Search</label>
    <input class="pub-search-input" type="text" name="q"
           placeholder="Make, model, year…"
           value="<?= htmlspecialchars($q) ?>">
  </div>

  <!-- Make -->
  <div class="pub-filter-group">
    <label class="pub-filter-label">Make</label>
    <select class="pub-filter-select" name="make">
      <option value="">All makes</option>
      <?php foreach ($makes as $m): ?>
      <option value="<?= htmlspecialchars($m) ?>" <?= $make === $m ? 'selected' : '' ?>>
        <?= htmlspecialchars($m) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Condition -->
  <div class="pub-filter-group">
    <label class="pub-filter-label">Condition</label>
    <select class="pub-filter-select" name="condition">
      <option value="">Any</option>
      <option value="new"  <?= $condition === 'new'  ? 'selected' : '' ?>>New</option>
      <option value="demo" <?= $condition === 'demo' ? 'selected' : '' ?>>Demo</option>
      <option value="used" <?= $condition === 'used' ? 'selected' : '' ?>>Used</option>
    </select>
  </div>

  <!-- Body type -->
  <?php if (!empty($bodyTypes)): ?>
  <div class="pub-filter-group">
    <label class="pub-filter-label">Body type</label>
    <select class="pub-filter-select" name="body_type">
      <option value="">All types</option>
      <?php foreach ($bodyTypes as $bt): ?>
      <option value="<?= htmlspecialchars($bt) ?>" <?= $bodyType === $bt ? 'selected' : '' ?>>
        <?= htmlspecialchars($bt) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <!-- Province -->
  <?php if (!empty($provinces)): ?>
  <div class="pub-filter-group">
    <label class="pub-filter-label">Province</label>
    <select class="pub-filter-select" name="province">
      <option value="">All provinces</option>
      <?php foreach ($provinces as $pv): ?>
      <option value="<?= htmlspecialchars($pv) ?>" <?= $province === $pv ? 'selected' : '' ?>>
        <?= htmlspecialchars($pv) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <!-- Sort -->
  <div class="pub-filter-group">
    <label class="pub-filter-label">Sort by</label>
    <select class="pub-filter-select" name="sort">
      <option value="newest"     <?= $sort === 'newest'      ? 'selected' : '' ?>>Newest first</option>
      <option value="price_asc"  <?= $sort === 'price_asc'   ? 'selected' : '' ?>>Price (low → high)</option>
      <option value="price_desc" <?= $sort === 'price_desc'  ? 'selected' : '' ?>>Price (high → low)</option>
      <option value="mileage_asc"<?= $sort === 'mileage_asc' ? 'selected' : '' ?>>Lowest mileage</option>
    </select>
  </div>

  <!-- Actions -->
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button class="pub-btn pub-btn-primary" type="submit"
            style="padding:9px 20px;font-size:13px;white-space:nowrap;">
      <i class="fa-solid fa-magnifying-glass"></i> Search
    </button>
    <?php if (!empty($activeFilters)): ?>
    <a href="/c/<?= $ref ? '?ref=' . htmlspecialchars($ref) : '' ?>"
       class="pub-btn pub-btn-ghost"
       style="padding:9px 16px;font-size:13px;white-space:nowrap;">
      Clear
    </a>
    <?php endif; ?>
  </div>
</form>

<!-- Price range quick filters -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.5rem;" class="pub-anim pub-d1">
  <?php
  $priceRanges = [
    ['Under R 200k',    '',      200000],
    ['R 200k – 350k',  200000,  350000],
    ['R 350k – 500k',  350000,  500000],
    ['R 500k – 800k',  500000,  800000],
    ['Over R 800k',    800000,  ''],
  ];
  foreach ($priceRanges as [$label, $min, $max]):
    $isActive = (string)$priceMin === (string)$min && (string)$priceMax === (string)$max;
    $qs = http_build_query(array_filter([
      'make'      => $make,
      'condition' => $condition,
      'sort'      => $sort !== 'newest' ? $sort : '',
      'price_min' => $min,
      'price_max' => $max,
      'ref'       => $ref,
    ]));
  ?>
  <a href="/c/?<?= $qs ?>"
     style="display:inline-flex;align-items:center;padding:6px 14px;
            border-radius:var(--r-full);font-size:12px;font-weight:500;
            text-decoration:none;border:1px solid;transition:all .18s;
            background:<?= $isActive ? 'var(--p)' : 'var(--white)' ?>;
            color:<?= $isActive ? '#fff' : 'var(--muted)' ?>;
            border-color:<?= $isActive ? 'var(--p)' : 'var(--border)' ?>;">
    <?= htmlspecialchars($label) ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Results grid -->
<?php if (empty($cars)): ?>
<div style="text-align:center;padding:4rem 1rem;color:var(--faint);">
  <i class="fa-solid fa-car-on" style="font-size:40px;margin-bottom:16px;display:block;
                                        color:var(--border);"></i>
  <div style="font-family:var(--font-d);font-size:18px;font-weight:700;
              color:var(--text);margin-bottom:8px;">
    No cars found
  </div>
  <div style="font-size:14px;margin-bottom:20px;">
    Try adjusting your filters or search terms.
  </div>
  <a href="/c/" class="pub-btn pub-btn-primary" style="display:inline-flex;">
    Clear all filters
  </a>
</div>
<?php else: ?>

<div class="pub-browse-grid" id="carsGrid">
  <?php foreach ($cars as $car):
    $imgs  = json_decode($car['image_urls'] ?? '[]', true) ?: [];
    $thumb = $imgs[0] ?? null;
    $carUrl = '/c/' . htmlspecialchars($car['slug']) . '/'
              . ($ref ? '?ref=' . htmlspecialchars($ref) : '');
    $priceStr = 'R ' . number_format((float)$car['price'], 0, '.', ' ');
    $metaParts = array_filter([
      ucfirst($car['condition_type']),
      $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : null,
      $car['body_type'],
    ]);
    $location = implode(', ', array_filter([$car['dealer_city'], $car['dealer_province']]));
  ?>
  <a href="<?= $carUrl ?>" class="pub-browse-card pub-reveal">

    <!-- Image -->
    <div class="pub-browse-card__img">
      <?php if ($thumb): ?>
      <img src="<?= htmlspecialchars($thumb) ?>"
           alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
           loading="lazy">
      <?php else: ?>
      <div class="pub-browse-card__img-placeholder">
        <i class="fa-solid fa-car-side"></i>
      </div>
      <?php endif; ?>

      <!-- Condition badge overlay -->
      <div style="position:absolute;top:10px;left:10px;">
        <span class="pub-badge" style="font-size:9px;
          background:<?= $car['condition_type'] === 'new' ? 'var(--p)' : 'rgba(0,0,0,.55)' ?>;
          color:#fff;border-color:transparent;">
          <?= ucfirst($car['condition_type']) ?>
        </span>
      </div>

      <!-- Verified badge overlay -->
      <?php if ($car['dealer_verified'] === 'verified'): ?>
      <div style="position:absolute;top:10px;right:10px;">
        <span class="pub-badge pub-badge-verified" style="font-size:9px;">
          <i class="fa-solid fa-circle-check" style="font-size:8px;"></i> Verified
        </span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Body -->
    <div class="pub-browse-card__body">
      <div class="pub-browse-card__name">
        <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
      </div>
      <div class="pub-browse-card__price"><?= $priceStr ?></div>
      <div class="pub-browse-card__meta">
        <?php foreach ($metaParts as $part): ?>
        <span><?= htmlspecialchars($part) ?></span>
        <?php endforeach; ?>
      </div>

      <!-- Broker count + dealer info -->
      <div class="pub-browse-card__broker">
        <i class="fa-solid fa-building-user" style="color:var(--faint);font-size:10px;"></i>
        <?= htmlspecialchars($car['dealer_name']) ?>
        <?php if ($location): ?>
        <span style="color:var(--faint);margin-left:auto;font-size:10px;">
          <i class="fa-solid fa-location-dot" style="font-size:9px;"></i>
          <?= htmlspecialchars($location) ?>
        </span>
        <?php endif; ?>
      </div>
    </div>

  </a>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;
            margin-top:2rem;flex-wrap:wrap;" class="pub-reveal">

  <?php if ($page > 1): ?>
  <a href="/c/?<?= $filterQs ?>&page=<?= $page - 1 ?>"
     class="pub-btn pub-btn-ghost" style="padding:8px 16px;font-size:13px;">
    <i class="fa-solid fa-chevron-left"></i> Prev
  </a>
  <?php endif; ?>

  <?php
  // Show at most 7 page numbers with ellipsis.
  $pageRange = [];
  for ($p = 1; $p <= $totalPages; $p++) {
    if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) {
      $pageRange[] = $p;
    }
  }
  $prev = null;
  foreach ($pageRange as $p):
    if ($prev !== null && $p - $prev > 1): ?>
    <span style="padding:8px 6px;color:var(--faint);font-size:13px;">…</span>
    <?php endif; ?>
    <a href="/c/?<?= $filterQs ?>&page=<?= $p ?>"
       style="padding:8px 14px;border-radius:var(--r-md);font-size:13px;
              font-family:var(--mono);text-decoration:none;transition:all .15s;
              border:1px solid <?= $p === $page ? 'var(--p)' : 'var(--border)' ?>;
              background:<?= $p === $page ? 'var(--p)' : 'var(--white)' ?>;
              color:<?= $p === $page ? '#fff' : 'var(--muted)' ?>;">
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

<!-- Bottom CTA for brokers -->
<div class="pub-reveal" style="margin-top:3rem;background:linear-gradient(135deg,#08143c,var(--p));
     border-radius:var(--r-xl);padding:2.5rem;text-align:center;color:#fff;">
  <div style="font-family:var(--font-d);font-size:22px;font-weight:800;
              margin-bottom:8px;letter-spacing:-.02em;">
    Are you a car broker?
  </div>
  <p style="font-size:14px;color:rgba(255,255,255,.65);margin-bottom:1.5rem;max-width:420px;margin-left:auto;margin-right:auto;">
    Add these cars to your SalesDesk and earn commission on every sale you close.
  </p>
  <a href="/brokers.php" class="pub-btn pub-btn-ghost"
     style="display:inline-flex;padding:11px 28px;font-size:14px;
            background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);
            color:#fff;">
    Learn how it works →
  </a>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
