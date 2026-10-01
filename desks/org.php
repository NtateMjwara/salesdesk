<?php
/**
 * SalesDesk — Desk organisation hub  (v1)
 * Routes (.htaccess):
 *   /desks/{org-slug}/     → desks/org.php?slug={org-slug}
 *   /desks/independent/    → desks/org.php?slug=independent
 *
 * One organisation's broker desks (approved agents only), or every
 * independent broker's desk. Same look as /desks/ (desks.css §1–4):
 * ink hero, sticky toolbar, province chips, desk cards, CTA.
 *
 * Lists DESKS, not cars — a car stocked by 40 agents would need a rule
 * for which desk gets the click; linking to desks sidesteps that.
 *
 * Ordering is neutral and merit-based (live cars, then views, then
 * oldest desk id) — see includes/desk-directory.php.
 *
 * SEO: hub is self-canonical (+ province / page). Name searches,
 * non-default sorts and empty hubs are noindex,follow. Title:
 *   "{Org} — {Brands} Car Brokers | SalesDesk"
 *   "Independent Car Brokers in South Africa | SalesDesk"
 *
 * Presentation only — nothing here touches attribution.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/visitor.php';
require_once '../includes/structured-data.php';
require_once '../includes/desk-directory.php';
require_once '../views/partials/desk-card.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ============================================================
// RESOLVE HUB
// ============================================================
$slug          = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
$isIndependent = ($slug === SD_INDEPENDENT_SLUG);
$org           = null;

if (!$isIndependent) {
    $org = null;
    if ($slug !== '') {
        $cols = sdOrgSchemaReady() ? 'o.brands, o.description, o.accepting_applications' : 'NULL AS brands, NULL AS description, 0 AS accepting_applications';
        $orgStmt = $pdo->prepare("
            SELECT o.id, o.name, o.slug, o.logo_url, o.verification_status, {$cols},
                   a.city, a.province
            FROM organizations o
            LEFT JOIN addresses a ON a.id = o.address_id
            WHERE o.slug = ? AND o.is_active = 1
            LIMIT 1
        ");
        $orgStmt->execute([$slug]);
        $org = $orgStmt->fetch() ?: null;
    }
    if (!$org) {
        require __DIR__ . '/../broker/404.php';
        exit;
    }
    $org['id']         = (int) $org['id'];
    $org['brand_list'] = sdOrgBrandList($org['brands'] ?? null);
}

$scope    = $isIndependent ? SD_INDEPENDENT_SLUG : $org['id'];
$hubPath  = sdOrgHubPath($org);
$hubName  = $org['name'] ?? SD_INDEPENDENT_LABEL;
$brands   = $org ? sdBrandPhrase($org['brand_list']) : '';
$visitor  = initVisitorSession();

// ============================================================
// INPUT
// ============================================================
$q        = trim($_GET['q'] ?? '');
$province = trim($_GET['province'] ?? '');
$sort     = trim($_GET['sort'] ?? 'active');
$page     = max(1, (int) ($_GET['page'] ?? 1));

if (!array_key_exists($sort, sdDeskSorts())) {
    $sort = 'active';
}

$provinceCounts = sdDeskProvinceCounts($scope);
$provinces      = array_keys($provinceCounts);
if ($province !== '' && !isset($provinceCounts[$province])) {
    $province = '';
}

// ============================================================
// DESKS
// ============================================================
$listing = sdDeskListing([
    'scope'    => $scope,
    'q'        => $q,
    'province' => $province,
    'sort'     => $sort,
    'page'     => $page,
    'per_page' => 12,
]);
$desks        = $listing['desks'];
$deskPreviews = $listing['previews'];
$totalDesks   = $listing['total'];
$totalPages   = $listing['total_pages'];
$page         = $listing['page'];

// Hub-wide stats (whole hub, ignoring filters)
if ($isIndependent) {
    $hubStats = sdIndependentStats('');
} else {
    $hubStats = ['desk_count' => 0, 'car_count' => 0];
    foreach (sdOrgDirectory('') as $row) {
        if ((int) $row['id'] === $org['id']) {
            $hubStats = ['desk_count' => (int) $row['desk_count'], 'car_count' => (int) $row['car_count']];
            break;
        }
    }
}
$provinceTotal = count($provinceCounts);

// ============================================================
// URL HELPER
// ============================================================
$hubUrl = static function (array $overrides = []) use ($hubPath): string {
    $current = [
        'q'        => $_GET['q']        ?? '',
        'province' => $_GET['province'] ?? '',
        'sort'     => $_GET['sort']     ?? '',
        'page'     => $_GET['page']     ?? '',
    ];
    $merged = array_filter(array_merge($current, $overrides), fn($v) => $v !== '' && $v !== null);
    return $hubPath . ($merged ? '?' . http_build_query($merged) : '');
};

// ============================================================
// PAGE META
// ============================================================
$siteUrl = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';

if ($isIndependent) {
    $primary       = 'Independent Car Brokers in ' . ($province ?: 'South Africa');
    $ogDescription = 'Independent car brokers on SalesDesk: ' . number_format($hubStats['desk_count'])
                   . ' desks' . ($province ? ' including ' . $province : ' across South Africa')
                   . ', ' . number_format($hubStats['car_count'])
                   . ' cars from verified dealers, any make. Pick a broker and enquire directly.';
} else {
    $primary       = $hubName . ($brands !== '' && mb_stripos($hubName, $brands) === false ? ' — ' . $brands : '')
                   . ' Car Brokers' . ($province ? ' in ' . $province : '');
    $provList      = array_slice($provinces, 0, 3);
    $ogDescription = 'Find a ' . ($brands !== '' ? $brands . ' ' : '') . 'car broker from ' . $hubName . ': '
                   . number_format($hubStats['desk_count']) . ' desk' . ($hubStats['desk_count'] === 1 ? '' : 's')
                   . ($provList ? ' in ' . implode(', ', $provList) . ($provinceTotal > 3 ? ' and more' : '') : '')
                   . ', ' . number_format($hubStats['car_count']) . ' cars in stock. Compare desks and enquire directly.';
}
$pageTitle     = sdSeoTitle($primary, ['SalesDesk'], 60);
$ogTitle       = $hubName . ' — SalesDesk';
$ogDescription = mb_strimwidth($ogDescription, 0, 160, '…');
$ogImage       = $org['logo_url'] ?? '';

$canonicalParams   = array_filter(['province' => $province, 'page' => $page > 1 ? $page : null]);
$canonicalUrl      = $siteUrl . $hubPath . ($canonicalParams ? '?' . http_build_query($canonicalParams) : '');
$metaRobotsNoindex = ($q !== '' || $sort !== 'active' || $hubStats['desk_count'] === 0);

$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [['Find a SalesDesk', '/desks/'], [$hubName, null]];

$shareUrl   = $siteUrl . $hubPath;
$shareTitle = $ogTitle;

$assetVersion         = $assetVersion ?? date('Ymd');
$extraCss             = '<link rel="stylesheet" href="/assets/css/desks.css?v=' . $assetVersion . '">' . "\n";
$includeBrowseCss     = false;
$includeHowItWorksCss = false;

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

ob_start();

// Organization JSON-LD with this page's desks as members.
if ($org) {
    echo renderDeskOrgSchema(
        $org,
        $siteUrl . $hubPath,
        array_map(static fn(array $d): array => [
            'name' => $d['display_name'],
            'url'  => $siteUrl . '/' . rawurlencode($d['slug']) . '/',
        ], $desks)
    );
}
?>

<!-- ══════════════════════════════════════════════════════
     HERO
     ══════════════════════════════════════════════════════ -->
<section class="dk-hero" aria-labelledby="dkTitle">
  <div class="dk-hero__glow" aria-hidden="true"></div>
  <div class="sd-container dk-hero__inner">

    <span class="dk-hero__pill">
      <?php if ($isIndependent): ?>
      <i class="fa-solid fa-user-tie" aria-hidden="true"></i> Independent brokers
      <?php else: ?>
      <i class="fa-solid <?= ($org['verification_status'] ?? '') === 'verified' ? 'fa-circle-check' : 'fa-building' ?>" aria-hidden="true"></i>
      Desk organisation<?= ($org['verification_status'] ?? '') === 'verified' ? ' · Verified' : '' ?>
      <?php endif; ?>
    </span>

    <h1 class="dk-hero__title" id="dkTitle">
      <?php if ($isIndependent): ?>
      <span class="dk-hero__accent">Independent</span> car brokers
      <?php else: ?>
      <?= $e($hubName) ?>
      <?php endif; ?>
    </h1>

    <?php if (!empty($org['brand_list'])): ?>
    <ul class="dk-hero__brands" aria-label="Brands sold">
      <?php foreach ($org['brand_list'] as $b): ?>
      <li class="dk-hero__brand"><?= $e($b) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p class="dk-hero__sub">
      <?php if ($isIndependent): ?>
      Brokers who run their own desk and aren’t tied to an organisation — any make on
      SalesDesk, one broker to deal with from enquiry to delivery.
      <?php elseif (!empty($org['description'])): ?>
      <?= $e(mb_strimwidth((string) $org['description'], 0, 260, '…')) ?>
      <?php else: ?>
      <?= $e($brands !== '' ? $brands . ' specialists. ' : '') ?>Pick a desk near you — every agent
      sells <?= $e($hubName) ?> stock from verified dealers and handles your enquiry personally.
      <?php endif; ?>
    </p>

    <form class="dk-search" method="GET" action="<?= $e($hubPath) ?>" role="search" data-clean-submit>
      <?php if ($sort !== 'active'): ?>
      <input type="hidden" name="sort" value="<?= $e($sort) ?>">
      <?php endif; ?>

      <div class="dk-search__field">
        <i class="fa-solid fa-magnifying-glass dk-search__icon" aria-hidden="true"></i>
        <label class="sr-only" for="deskSearchInput">Broker or desk name</label>
        <input class="dk-search__input" type="search" id="deskSearchInput" name="q"
               value="<?= $e($q) ?>" placeholder="Broker or desk name" autocomplete="off" enterkeyhint="search">
      </div>

      <?php if ($provinces): ?>
      <div class="dk-search__field dk-search__field--select">
        <i class="fa-solid fa-location-dot dk-search__icon" aria-hidden="true"></i>
        <label class="sr-only" for="deskProvinceSelect">Province</label>
        <select class="dk-search__input dk-search__select" id="deskProvinceSelect" name="province" data-autosubmit>
          <option value="">All provinces</option>
          <?php foreach ($provinces as $prov): ?>
          <option value="<?= $e($prov) ?>" <?= $province === $prov ? 'selected' : '' ?>><?= $e($prov) ?> (<?= (int) $provinceCounts[$prov] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <button class="pub-btn pub-btn-accent dk-search__btn" type="submit">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search
      </button>
    </form>

    <ul class="dk-hero__stats">
      <li><strong><?= number_format($hubStats['desk_count']) ?></strong> desk<?= $hubStats['desk_count'] === 1 ? '' : 's' ?></li>
      <li><strong><?= number_format($hubStats['car_count']) ?></strong> cars in stock</li>
      <?php if ($provinceTotal > 0): ?>
      <li><strong><?= $provinceTotal ?></strong> province<?= $provinceTotal === 1 ? '' : 's' ?></li>
      <?php endif; ?>
    </ul>
  </div>
</section>


<div class="sd-container dk-body">

  <!-- ══════════════════════════════════
       TOOLBAR
       ══════════════════════════════════ -->
  <div class="dk-toolbar">
    <p class="dk-toolbar__count">
      <strong><?= number_format($totalDesks) ?></strong>
      desk<?= $totalDesks === 1 ? '' : 's' ?><?= $province ? ' in ' . $e($province) : '' ?><?= $q ? ' matching “' . $e($q) . '”' : '' ?>
    </p>

    <form class="sort-form dk-toolbar__sort" method="GET" action="<?= $e($hubPath) ?>" data-clean-submit>
      <?php if ($q): ?><input type="hidden" name="q" value="<?= $e($q) ?>"><?php endif; ?>
      <?php if ($province): ?><input type="hidden" name="province" value="<?= $e($province) ?>"><?php endif; ?>
      <label class="sr-only" for="deskSort">Sort desks</label>
      <i class="fa-solid fa-arrow-down-wide-short sort-form__icon" aria-hidden="true"></i>
      <select class="sort-select" id="deskSort" name="sort" data-autosubmit>
        <?php foreach (sdDeskSorts() as $key => $label): ?>
        <option value="<?= $e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="pub-btn pub-btn-ghost pub-btn-sm" type="submit">Sort</button></noscript>
    </form>
  </div>

  <?php if (count($provinces) > 1): ?>
  <div class="dk-provinces" aria-label="Filter by province">
    <a class="pub-chip <?= $province === '' ? 'is-active' : '' ?>" href="<?= $e($hubUrl(['province' => null, 'page' => null])) ?>">
      All provinces
    </a>
    <?php foreach ($provinces as $prov): ?>
    <a class="pub-chip <?= $province === $prov ? 'is-active' : '' ?>"
       href="<?= $e($province === $prov ? $hubUrl(['province' => null, 'page' => null]) : $hubUrl(['province' => $prov, 'page' => null])) ?>"
       <?= $province === $prov ? 'aria-current="true"' : '' ?>>
      <?= $e($prov) ?><span class="dk-provinces__n"><?= (int) $provinceCounts[$prov] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($q || $province): ?>
  <div class="active-filter-tags">
    <?php if ($q): ?>
    <a class="active-filter-tag" href="<?= $e($hubUrl(['q' => null, 'page' => null])) ?>" aria-label="Remove name filter">
      “<?= $e($q) ?>” <i class="fa-solid fa-xmark active-filter-tag__dismiss" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
    <?php if ($province): ?>
    <a class="active-filter-tag" href="<?= $e($hubUrl(['province' => null, 'page' => null])) ?>" aria-label="Remove province filter">
      <?= $e($province) ?> <i class="fa-solid fa-xmark active-filter-tag__dismiss" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
    <a class="active-filter-clear" href="<?= $e($hubPath) ?>">Clear all</a>
  </div>
  <?php endif; ?>


  <!-- ══════════════════════════════════
       RESULTS
       ══════════════════════════════════ -->
  <?php if (empty($desks)): ?>

  <div class="pub-empty dk-empty">
    <span class="pub-empty__icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
    <?php if ($q || $province): ?>
    <h2 class="pub-empty__title">No desks match that search</h2>
    <p class="pub-empty__sub">Try a different name, or see every <?= $e($hubName) ?> desk.</p>
    <a href="<?= $e($hubPath) ?>" class="pub-btn pub-btn-primary dk-empty__btn">See all desks</a>
    <?php else: ?>
    <h2 class="pub-empty__title">No desks here yet</h2>
    <p class="pub-empty__sub">Agents are still joining <?= $e($hubName) ?>. Browse every broker on SalesDesk in the meantime.</p>
    <a href="/desks/" class="pub-btn pub-btn-primary dk-empty__btn">Find a SalesDesk</a>
    <?php endif; ?>
  </div>

  <?php else: ?>

  <div class="dk-grid">
    <?php foreach ($desks as $desk): ?>
    <?= sdDeskCard($desk, $deskPreviews[(int) $desk['id']] ?? [], ['heading' => 'h2', 'show_org' => false]) ?>
    <?php endforeach; ?>
  </div>

  <?= sdDirectoryPagination($page, $totalPages, static fn(int $p): string => $hubUrl(['page' => $p > 1 ? $p : null])) ?>

  <?php endif; ?>


  <!-- ══════════════════════════════════
       CTA
       ══════════════════════════════════ -->
  <section class="dk-cta pub-reveal" aria-labelledby="dkCtaTitle">
    <div class="dk-cta__text">
      <span class="pub-eyebrow">Brokers &amp; sales executives</span>
      <?php if ($org && !empty($org['accepting_applications'])): ?>
      <h2 class="dk-cta__title" id="dkCtaTitle"><?= $brands !== '' ? 'Sell ' . $e($brands) . ' as a ' . $e($hubName) . ' agent.' : 'Become a ' . $e($hubName) . ' agent.' ?></h2>
      <p class="dk-cta__sub">
        Create a free SalesDesk, apply to join <?= $e($hubName) ?>, and earn the full commission
        on every deal you source — the organisation takes no cut.
      </p>
      <?php else: ?>
      <h2 class="dk-cta__title" id="dkCtaTitle">Your desk could be on this page.</h2>
      <p class="dk-cta__sub">
        Create a free SalesDesk, add cars from verified dealerships, share your link —
        and earn commission on every deal you source. No stock, no showroom, no monthly fee.
      </p>
      <?php endif; ?>
    </div>
    <div class="dk-cta__actions">
      <a href="/auth/register" class="pub-btn pub-btn-accent pub-btn-lg">Create your SalesDesk</a>
      <a href="/desks/" class="pub-btn pub-btn-on-ink pub-btn-lg">All organisations</a>
    </div>
  </section>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
