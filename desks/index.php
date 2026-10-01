<?php
/**
 * SalesDesk — Find a SalesDesk (Organisations + Broker Directory)  (v3)
 * Route: /desks/  →  /desks/index.php
 *
 * Public, searchable directory. Linked from the site footer
 * ("Find a SalesDesk").
 *
 *   1. Organisations — one card per desk organisation with live desks,
 *      plus an "Independent brokers" tile. Each links to its hub
 *      (/desks/{org-slug}/, /desks/independent/ → desks/org.php).
 *      Shown on the unsearched first page; a province filter narrows
 *      it to orgs with desks in that province.
 *   2. All brokers — every desk, searchable by name, filterable by
 *      province, sortable. Unchanged behaviour from v2.
 *
 * Filters:  q (name/broker search), province, sort
 * Sort:     active (most cars) | popular (most views) | newest | name
 *
 * v3 (organisations as the directory's top level):
 *   ORG-1  Organisation cards + independent tile above the desk list.
 *   ORG-2  Queries moved to includes/desk-directory.php and cards to
 *          views/partials/desk-card.php — shared with the org hubs.
 *   ORG-3  Desk org badge shows for every approved agent (verified
 *          orgs keep the green check; others get the building icon).
 *   SEO-6  Canonical no longer echoes every query string: search and
 *          non-default sorts are noindex,follow and canonicalise to
 *          /desks/ (+ province / page). Title no longer says
 *          "Independent" now that most desks belong to organisations.
 *   FIX-1  Views / closed deals no longer multiply each other (the old
 *          single GROUP BY joined inventory AND leads).
 *
 * v2: DK-1 … DK-5 (design-system rebuild, photo previews, no drawer,
 *     no inline CSS/JS, active-only car counts) — carried forward.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/visitor.php';
require_once '../includes/desk-directory.php';
require_once '../views/partials/desk-card.php';

applyCachePolicy('public');

$pdo     = Database::getInstance();
$visitor = initVisitorSession();

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

// ============================================================
// PROVINCES (chips + select) — only accept a real one
// ============================================================
$provinceCounts = sdDeskProvinceCounts(null);
$provinces      = $provinceCounts ? array_keys($provinceCounts) : sdAllProvinces();
if ($province !== '' && !in_array($province, $provinces, true)) {
    $province = '';
}

// ============================================================
// ORGANISATIONS (unsearched first page only)
// ============================================================
$showOrgs    = ($q === '' && $page === 1);
$orgs        = $showOrgs ? sdOrgDirectory($province) : [];
$orgPreviews = $orgs ? sdOrgPreviews(array_map(static fn($o) => (int) $o['id'], $orgs)) : [];
$indStats    = $showOrgs ? sdIndependentStats($province) : ['desk_count' => 0, 'car_count' => 0];
$showOrgs    = $showOrgs && ($orgs || $indStats['desk_count'] > 0);

// ============================================================
// ALL BROKERS
// ============================================================
$listing = sdDeskListing([
    'scope'    => null,
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

// ============================================================
// PLATFORM-WIDE STAT STRIP
// ============================================================
$globalStatsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT sd.id) AS desk_count,
        COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN bi.id END) AS listing_count
    FROM salesdesks sd
    JOIN users u ON u.id = sd.user_id AND u.status = 'active'
    LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
    LEFT JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
    WHERE sd.is_active = 1
");
$globalStatsStmt->execute();
$globalStats = $globalStatsStmt->fetch() ?: ['desk_count' => 0, 'listing_count' => 0];
$globalStats['closed_count'] = (int) $pdo->query("
    SELECT COUNT(*) FROM leads l
    JOIN salesdesks sd ON sd.id = l.salesdesk_id AND sd.is_active = 1
    WHERE l.status = 'closed'
")->fetchColumn();
$orgCount = ($q === '' && $page === 1 && $province === '') ? count($orgs) : count(sdOrgDirectory(''));

// ============================================================
// URL HELPERS (preserve filters across pagination / sort links)
// ============================================================
function desksQueryString(array $overrides = []): string
{
    $current = [
        'q'        => $_GET['q']        ?? '',
        'province' => $_GET['province'] ?? '',
        'sort'     => $_GET['sort']     ?? '',
        'page'     => $_GET['page']     ?? '',
    ];
    $merged = array_merge($current, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
}

function desksUrl(array $overrides = []): string
{
    $qs = desksQueryString($overrides);
    return '/desks/' . ($qs !== '' ? '?' . $qs : '');
}

// ============================================================
// PAGE META
// ============================================================
$siteUrl       = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$pageTitle     = sdSeoTitle(
    $province ? 'Car Brokers in ' . $province : 'Find a Car Broker',
    ['Organisations & Desks', 'SalesDesk'],
    60
);
$ogTitle       = 'Find a Broker — SalesDesk Directory';
$ogDescription = $province
    ? 'Car broker organisations and desks in ' . $province . ': ' . number_format($totalDesks)
      . ' broker' . ($totalDesks === 1 ? '' : 's') . ' selling cars from verified dealers. Find a trusted SalesDesk near you.'
    : 'Browse ' . number_format($orgCount) . ' car broker organisation' . ($orgCount === 1 ? '' : 's')
      . ' and ' . number_format((int) $globalStats['desk_count'])
      . ' broker desks across South Africa. Find a trusted SalesDesk near you.';

// SEO-6: canonical = /desks/ (+ province, + page). Name searches and
// non-default sorts are thin duplicates → noindex,follow.
$canonicalParams = array_filter(['province' => $province, 'page' => $page > 1 ? $page : null]);
$canonicalUrl    = $siteUrl . '/desks/' . ($canonicalParams ? '?' . http_build_query($canonicalParams) : '');
$metaRobotsNoindex = ($q !== '' || $sort !== 'active');

$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [['Find a SalesDesk', null]];

$assetVersion         = $assetVersion ?? date('Ymd');
$extraCss             = '<link rel="stylesheet" href="/assets/css/desks.css?v=' . $assetVersion . '">' . "\n";
$includeBrowseCss     = false;
$includeHowItWorksCss = false;

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

ob_start();
?>

<!-- ══════════════════════════════════════════════════════
     HERO
     ══════════════════════════════════════════════════════ -->
<section class="dk-hero" aria-labelledby="dkTitle">
  <div class="dk-hero__glow" aria-hidden="true"></div>
  <div class="sd-container dk-hero__inner">

    <span class="dk-hero__pill">
      <i class="fa-solid fa-id-card" aria-hidden="true"></i>
      <?= number_format((int) $globalStats['desk_count']) ?> active broker<?= (int) $globalStats['desk_count'] === 1 ? '' : 's' ?> on SalesDesk
    </span>

    <h1 class="dk-hero__title" id="dkTitle">
      Find a <span class="dk-hero__accent">SalesDesk</span> near you
    </h1>

    <p class="dk-hero__sub">
      Browse broker organisations by the brands they sell, or find an individual
      broker by name or province — every desk verified, commission-protected and
      backed by real dealer stock.
    </p>

    <form class="dk-search" method="GET" action="/desks/" role="search" data-clean-submit>
      <?php if ($sort !== 'active'): ?>
      <input type="hidden" name="sort" value="<?= $e($sort) ?>">
      <?php endif; ?>

      <div class="dk-search__field">
        <i class="fa-solid fa-magnifying-glass dk-search__icon" aria-hidden="true"></i>
        <label class="sr-only" for="deskSearchInput">Broker or desk name</label>
        <input class="dk-search__input" type="search" id="deskSearchInput" name="q"
               value="<?= $e($q) ?>" placeholder="Broker or desk name" autocomplete="off" enterkeyhint="search">
      </div>

      <div class="dk-search__field dk-search__field--select">
        <i class="fa-solid fa-location-dot dk-search__icon" aria-hidden="true"></i>
        <label class="sr-only" for="deskProvinceSelect">Province</label>
        <select class="dk-search__input dk-search__select" id="deskProvinceSelect" name="province" data-autosubmit>
          <option value="">All provinces</option>
          <?php foreach ($provinces as $prov): ?>
          <option value="<?= $e($prov) ?>" <?= $province === $prov ? 'selected' : '' ?>>
            <?= $e($prov) ?><?= isset($provinceCounts[$prov]) ? ' (' . (int) $provinceCounts[$prov] . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <button class="pub-btn pub-btn-accent dk-search__btn" type="submit">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search
      </button>
    </form>

    <ul class="dk-hero__stats">
      <?php if ($orgCount > 0): ?>
      <li><strong><?= number_format($orgCount) ?></strong> organisation<?= $orgCount === 1 ? '' : 's' ?></li>
      <?php endif; ?>
      <li><strong><?= number_format((int) $globalStats['desk_count']) ?></strong> active brokers</li>
      <li><strong><?= number_format((int) $globalStats['listing_count']) ?></strong> cars on desks</li>
      <li><strong><?= number_format((int) $globalStats['closed_count']) ?></strong> deals closed</li>
    </ul>
  </div>
</section>


<div class="sd-container dk-body">

  <?php if ($showOrgs): ?>
  <!-- ══════════════════════════════════
       ORGANISATIONS
       ══════════════════════════════════ -->
  <section class="dk-orgs" aria-labelledby="dkOrgsTitle">
    <div class="pub-section__head dk-section-head">
      <div>
        <span class="pub-eyebrow">Organisations</span>
        <h2 class="pub-section__title pub-section__title--sm" id="dkOrgsTitle">
          Browse by organisation<?= $province ? ' in ' . $e($province) : '' ?>
        </h2>
        <p class="pub-section__sub">Each organisation sells up to three brands through its own network of broker desks.</p>
      </div>
    </div>

    <div class="dk-grid">
      <?php foreach ($orgs as $org): ?>
      <?= sdOrgCard($org, $orgPreviews[(int) $org['id']] ?? [], ['heading' => 'h3']) ?>
      <?php endforeach; ?>
      <?php if ($indStats['desk_count'] > 0): ?>
      <?= sdIndependentCard($indStats, ['heading' => 'h3']) ?>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>


  <!-- ══════════════════════════════════
       ALL BROKERS — TOOLBAR
       ══════════════════════════════════ -->
  <h2 class="pub-section__title pub-section__title--sm dk-section-title" id="dkAllTitle">All brokers</h2>

  <div class="dk-toolbar">
    <p class="dk-toolbar__count">
      <strong><?= number_format($totalDesks) ?></strong>
      broker<?= $totalDesks === 1 ? '' : 's' ?><?= $province ? ' in ' . $e($province) : '' ?><?= $q ? ' matching “' . $e($q) . '”' : '' ?>
    </p>

    <form class="sort-form dk-toolbar__sort" method="GET" action="/desks/" data-clean-submit>
      <?php if ($q): ?><input type="hidden" name="q" value="<?= $e($q) ?>"><?php endif; ?>
      <?php if ($province): ?><input type="hidden" name="province" value="<?= $e($province) ?>"><?php endif; ?>
      <label class="sr-only" for="deskSort">Sort brokers</label>
      <i class="fa-solid fa-arrow-down-wide-short sort-form__icon" aria-hidden="true"></i>
      <select class="sort-select" id="deskSort" name="sort" data-autosubmit>
        <?php foreach (sdDeskSorts() as $key => $label): ?>
        <option value="<?= $e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="pub-btn pub-btn-ghost pub-btn-sm" type="submit">Sort</button></noscript>
    </form>
  </div>

  <!-- Province chips -->
  <div class="dk-provinces" aria-label="Filter by province">
    <a class="pub-chip <?= $province === '' ? 'is-active' : '' ?>" href="<?= $e(desksUrl(['province' => null, 'page' => null])) ?>">
      All provinces
    </a>
    <?php foreach ($provinces as $prov): ?>
    <a class="pub-chip <?= $province === $prov ? 'is-active' : '' ?>"
       href="<?= $e($province === $prov ? desksUrl(['province' => null, 'page' => null]) : desksUrl(['province' => $prov, 'page' => null])) ?>"
       <?= $province === $prov ? 'aria-current="true"' : '' ?>>
      <?= $e($prov) ?>
      <?php if (isset($provinceCounts[$prov])): ?><span class="dk-provinces__n"><?= (int) $provinceCounts[$prov] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($q || $province): ?>
  <div class="active-filter-tags">
    <?php if ($q): ?>
    <a class="active-filter-tag" href="<?= $e(desksUrl(['q' => null, 'page' => null])) ?>" aria-label="Remove name filter">
      “<?= $e($q) ?>” <i class="fa-solid fa-xmark active-filter-tag__dismiss" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
    <?php if ($province): ?>
    <a class="active-filter-tag" href="<?= $e(desksUrl(['province' => null, 'page' => null])) ?>" aria-label="Remove province filter">
      <?= $e($province) ?> <i class="fa-solid fa-xmark active-filter-tag__dismiss" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
    <a class="active-filter-clear" href="/desks/">Clear all</a>
  </div>
  <?php endif; ?>


  <!-- ══════════════════════════════════
       RESULTS
       ══════════════════════════════════ -->
  <?php if (empty($desks)): ?>

  <div class="pub-empty dk-empty">
    <span class="pub-empty__icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
    <h3 class="pub-empty__title">No brokers match that search</h3>
    <p class="pub-empty__sub">Try a different name, or look at every desk in the country.</p>
    <div class="pub-empty__actions">
      <?php if ($province): ?>
      <a class="pub-chip" href="<?= $e(desksUrl(['province' => null, 'page' => null])) ?>"><i class="fa-solid fa-xmark"></i> <?= $e($province) ?></a>
      <?php endif; ?>
      <?php if ($q): ?>
      <a class="pub-chip" href="<?= $e(desksUrl(['q' => null, 'page' => null])) ?>"><i class="fa-solid fa-xmark"></i> “<?= $e($q) ?>”</a>
      <?php endif; ?>
    </div>
    <a href="/desks/" class="pub-btn pub-btn-primary dk-empty__btn">See all brokers</a>
  </div>

  <?php else: ?>

  <div class="dk-grid">
    <?php foreach ($desks as $desk): ?>
    <?= sdDeskCard($desk, $deskPreviews[(int) $desk['id']] ?? [], ['heading' => 'h3', 'show_org' => true]) ?>
    <?php endforeach; ?>
  </div>

  <?= sdDirectoryPagination($page, $totalPages, static fn(int $p): string => desksUrl(['page' => $p > 1 ? $p : null])) ?>

  <?php endif; ?>


  <!-- ══════════════════════════════════
       CTA
       ══════════════════════════════════ -->
  <section class="dk-cta pub-reveal" aria-labelledby="dkCtaTitle">
    <div class="dk-cta__text">
      <span class="pub-eyebrow">Brokers &amp; sales executives</span>
      <h2 class="dk-cta__title" id="dkCtaTitle">Your desk could be on this page.</h2>
      <p class="dk-cta__sub">
        Create a free SalesDesk, join an organisation or stay independent, add cars from
        verified dealerships, share your link — and earn commission on every deal you source.
        No stock, no showroom, no monthly fee.
      </p>
    </div>
    <div class="dk-cta__actions">
      <a href="/auth/register" class="pub-btn pub-btn-accent pub-btn-lg">Create your SalesDesk</a>
      <a href="/how-it-works/brokers" class="pub-btn pub-btn-on-ink pub-btn-lg">How it works</a>
    </div>
  </section>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
