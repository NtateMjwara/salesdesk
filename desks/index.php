<?php
/**
 * SalesDesk — Find a SalesDesk (Broker Directory)  (v2)
 * Route: /desks/  →  /desks/index.php
 *
 * Public, searchable directory of active broker storefronts.
 * Linked from the site footer ("Find a SalesDesk").
 *
 * Filters:  q (name/broker search), province, sort
 * Sort:     active (most cars) | popular (most views) | newest | name
 *
 * v2 (public UX/UI overhaul — desk pages pass):
 *   DK-1  Page rebuilt on the shared design system: ink hero with the
 *         search + province in one form, sticky results toolbar with
 *         province chips and sort, redesigned desk cards, helpful empty
 *         state, CTA band.
 *   DK-2  Desk cards now show a 3-photo preview of that desk's newest
 *         cars (one extra grouped query for the whole page, not one per
 *         card) — a directory of brokers is much easier to scan when you
 *         can see what they actually have in stock.
 *   DK-3  The two-filter sidebar + its drawer are gone: with only a name
 *         search and a province, a full filter drawer was more chrome
 *         than content. Both now live in the hero / toolbar and apply
 *         with the shared [data-autosubmit] handler in public.js — so
 *         this page needs no page-level JS at all.
 *   DK-4  ALL inline <style>, <script> and style="" removed.
 *         Page CSS: assets/css/desks.css.
 *   DK-5  Cars count now only counts ACTIVE cars (the old query counted
 *         every broker_inventory row, so a desk whose cars had been sold
 *         or removed still advertised them).
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/visitor.php';

applyCachePolicy('public');

$pdo     = Database::getInstance();
$visitor = initVisitorSession();

// ============================================================
// INPUT
// ============================================================
$q         = trim($_GET['q'] ?? '');
$province  = trim($_GET['province'] ?? '');
$sort      = trim($_GET['sort'] ?? 'active');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 12;

$allowedSorts = ['active', 'popular', 'newest', 'name'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'active';
}

// ============================================================
// WHERE CLAUSE (shared between count + main query)
// ============================================================
$where  = ['sd.is_active = 1', "u.status = 'active'"];
$params = [];

if ($q !== '') {
    $where[]  = '(sd.display_name LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($province !== '') {
    $where[]  = 'a.province = ?';
    $params[] = $province;
}

$whereSql = implode(' AND ', $where);

// ============================================================
// TOTAL COUNT (for pagination)
// ============================================================
$countStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sd.id) AS total
    FROM salesdesks sd
    JOIN users u          ON u.id = sd.user_id
    LEFT JOIN profiles p  ON p.user_id = u.id
    LEFT JOIN addresses a ON a.id = p.address_id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalDesks = (int) ($countStmt->fetch()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalDesks / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ============================================================
// SORT MAP
// ============================================================
$orderBy = match ($sort) {
    'popular' => 'total_views DESC, cars_count DESC',
    'newest'  => 'sd.created_at DESC',
    'name'    => 'sd.display_name ASC',
    default   => 'cars_count DESC, total_views DESC', // 'active'
};

// ============================================================
// MAIN QUERY
// DK-5: cars_count counts only cars that are still active.
// ============================================================
$sql = "
    SELECT
        sd.id, sd.uuid, sd.slug, sd.display_name, sd.tagline,
        sd.logo_url, sd.primary_colour, sd.created_at,
        p.first_name, p.last_name, p.avatar_url,
        a.city, a.province, a.suburb,
        o.name                 AS org_name,
        o.verification_status  AS org_verification,
        COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN bi.id END) AS cars_count,
        COALESCE(SUM(bi.views), 0) AS total_views,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END) AS deals_closed
    FROM salesdesks sd
    JOIN users u                     ON u.id = sd.user_id
    LEFT JOIN profiles p             ON p.user_id = u.id
    LEFT JOIN addresses a            ON a.id = p.address_id
    LEFT JOIN organization_members om ON om.user_id = u.id
    LEFT JOIN organizations o        ON o.id = om.organization_id AND o.is_active = 1
    LEFT JOIN broker_inventory bi    ON bi.salesdesk_id = sd.id
    LEFT JOIN cars c                 ON c.id = bi.car_id AND c.status = 'active'
    LEFT JOIN leads l                ON l.salesdesk_id = sd.id
    WHERE {$whereSql}
    GROUP BY sd.id
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$desks = $stmt->fetchAll();

// ============================================================
// DK-2: newest 3 car photos per desk on this page — ONE query for
// the whole page (not one per card).
// ============================================================
$deskPreviews = [];
if ($desks) {
    $ids = array_map(static fn($d) => (int) $d['id'], $desks);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $prevStmt = $pdo->prepare("
            SELECT salesdesk_id, image_urls, make, model
            FROM (
                SELECT bi.salesdesk_id, c.image_urls, c.make, c.model,
                       ROW_NUMBER() OVER (PARTITION BY bi.salesdesk_id ORDER BY bi.added_at DESC) AS rn
                FROM broker_inventory bi
                JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
                WHERE bi.salesdesk_id IN ({$ph})
            ) ranked
            WHERE rn <= 3
        ");
        $prevStmt->execute($ids);
        foreach ($prevStmt->fetchAll() as $row) {
            $imgs = json_decode($row['image_urls'] ?? '[]', true) ?: [];
            if (!empty($imgs[0])) {
                $deskPreviews[(int) $row['salesdesk_id']][] = [
                    'src' => $imgs[0],
                    'alt' => trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')),
                ];
            }
        }
    } catch (Throwable) {
        $deskPreviews = [];   // window functions unavailable — cards render without previews
    }
}

// ============================================================
// PLATFORM-WIDE STAT STRIP
// ============================================================
$globalStatsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT sd.id) AS desk_count,
        COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN bi.id END) AS listing_count,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END) AS closed_count
    FROM salesdesks sd
    JOIN users u ON u.id = sd.user_id AND u.status = 'active'
    LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
    LEFT JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
    LEFT JOIN leads l ON l.salesdesk_id = sd.id
    WHERE sd.is_active = 1
");
$globalStatsStmt->execute();
$globalStats = $globalStatsStmt->fetch() ?: ['desk_count' => 0, 'listing_count' => 0, 'closed_count' => 0];

// ============================================================
// PROVINCE LIST (for filter — distinct provinces actually in use)
// ============================================================
$provinceStmt = $pdo->prepare("
    SELECT a.province, COUNT(DISTINCT sd.id) AS desk_count
    FROM salesdesks sd
    JOIN users u ON u.id = sd.user_id AND u.status = 'active'
    JOIN profiles p ON p.user_id = u.id
    JOIN addresses a ON a.id = p.address_id
    WHERE sd.is_active = 1 AND a.province IS NOT NULL AND a.province != ''
    GROUP BY a.province
    ORDER BY a.province ASC
");
$provinceStmt->execute();
$provinceCounts = $provinceStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$provinces      = array_keys($provinceCounts);

// Fallback to the full canonical SA province list if the DB has none yet.
if (empty($provinces)) {
    $provinces = [
        'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
        'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
    ];
}

// ============================================================
// QUERY-STRING HELPER (preserves filters across pagination/sort links)
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

// Compact number for the stat rows: 1 200 → 1.2k
function desksCompact(int $n): string
{
    return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : (string) $n;
}

// ============================================================
// PAGE META
// ============================================================
$siteUrl        = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$pageTitle      = ($province ? 'Car Brokers in ' . $province : 'Find a SalesDesk')
                . ' | Independent Car Brokers in South Africa';
$ogTitle        = 'Find a Broker — SalesDesk Directory';
$ogDescription  = 'Browse ' . number_format((int) $globalStats['desk_count'])
                 . ' independent car brokers across South Africa. Find a trusted SalesDesk near you.';
$canonicalUrl   = $siteUrl . '/desks/' . (desksQueryString() ? '?' . desksQueryString() : '');
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
      Every broker here runs their own storefront — verified, commission-protected and
      backed by real dealer stock. Search by name, or browse by province.
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
      <li><strong><?= number_format((int) $globalStats['desk_count']) ?></strong> active brokers</li>
      <li><strong><?= number_format((int) $globalStats['listing_count']) ?></strong> cars on desks</li>
      <li><strong><?= number_format((int) $globalStats['closed_count']) ?></strong> deals closed</li>
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
      broker<?= $totalDesks === 1 ? '' : 's' ?><?= $province ? ' in ' . $e($province) : '' ?><?= $q ? ' matching “' . $e($q) . '”' : '' ?>
    </p>

    <form class="sort-form dk-toolbar__sort" method="GET" action="/desks/" data-clean-submit>
      <?php if ($q): ?><input type="hidden" name="q" value="<?= $e($q) ?>"><?php endif; ?>
      <?php if ($province): ?><input type="hidden" name="province" value="<?= $e($province) ?>"><?php endif; ?>
      <label class="sr-only" for="deskSort">Sort brokers</label>
      <i class="fa-solid fa-arrow-down-wide-short sort-form__icon" aria-hidden="true"></i>
      <select class="sort-select" id="deskSort" name="sort" data-autosubmit>
        <option value="active"  <?= $sort === 'active'  ? 'selected' : '' ?>>Most cars listed</option>
        <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most viewed</option>
        <option value="newest"  <?= $sort === 'newest'  ? 'selected' : '' ?>>Newest desks</option>
        <option value="name"    <?= $sort === 'name'    ? 'selected' : '' ?>>Name (A–Z)</option>
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
    <h2 class="pub-empty__title">No brokers match that search</h2>
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
    <?php foreach ($desks as $desk):
      $brokerName = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? '')) ?: $desk['display_name'];
      $initials   = strtoupper(substr($desk['first_name'] ?? '', 0, 1) . substr($desk['last_name'] ?? '', 0, 1)) ?: 'SD';
      $location   = implode(', ', array_filter([$desk['city'], $desk['province']]));
      $isOrgVerified = ($desk['org_verification'] ?? null) === 'verified';
      $deskUrl    = '/' . rawurlencode($desk['slug']) . '/';
      $previews   = $deskPreviews[(int) $desk['id']] ?? [];
      $carsCount  = (int) $desk['cars_count'];
    ?>
    <article class="dk-card pub-reveal">
      <div class="dk-card__head">
        <span class="dk-avatar">
          <?php if ($desk['avatar_url']): ?>
          <img src="<?= $e($desk['avatar_url']) ?>" alt="" width="56" height="56" loading="lazy">
          <?php elseif ($desk['logo_url']): ?>
          <img src="<?= $e($desk['logo_url']) ?>" alt="" width="56" height="56" loading="lazy">
          <?php else: ?>
          <?= $e($initials) ?>
          <?php endif; ?>
        </span>

        <span class="dk-card__id">
          <h2 class="dk-card__name"><a class="dk-card__link" href="<?= $e($deskUrl) ?>"><?= $e($desk['display_name']) ?></a></h2>
          <span class="dk-card__broker"><?= $e($brokerName) ?></span>
          <?php if ($location): ?>
          <span class="dk-card__loc"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $e($location) ?></span>
          <?php endif; ?>
        </span>
      </div>

      <?php if ($isOrgVerified || $desk['tagline']): ?>
      <div class="dk-card__meta">
        <?php if ($isOrgVerified): ?>
        <span class="pub-badge pub-badge-verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= $e($desk['org_name']) ?></span>
        <?php endif; ?>
        <?php if ($desk['tagline']): ?>
        <p class="dk-card__tagline"><?= $e(mb_strimwidth($desk['tagline'], 0, 90, '…')) ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="dk-card__preview" aria-hidden="true">
        <?php if ($previews): ?>
          <?php foreach (array_slice($previews, 0, 3) as $pv): ?>
          <span class="dk-card__thumb"><img src="<?= $e($pv['src']) ?>" alt="" width="180" height="135" loading="lazy"></span>
          <?php endforeach; ?>
          <?php for ($i = count($previews); $i < 3; $i++): ?>
          <span class="dk-card__thumb dk-card__thumb--empty"><i class="fa-solid fa-car-side"></i></span>
          <?php endfor; ?>
        <?php else: ?>
          <?php for ($i = 0; $i < 3; $i++): ?>
          <span class="dk-card__thumb dk-card__thumb--empty"><i class="fa-solid fa-car-side"></i></span>
          <?php endfor; ?>
        <?php endif; ?>
      </div>

      <div class="dk-card__stats">
        <span><strong><?= $carsCount ?></strong> car<?= $carsCount === 1 ? '' : 's' ?></span>
        <span><strong><?= (int) $desk['deals_closed'] ?></strong> closed</span>
        <span><strong><?= desksCompact((int) $desk['total_views']) ?></strong> views</span>
        <span class="dk-card__cta">Visit desk <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Pages">
    <?php if ($page > 1): ?>
    <a class="pagination__page pagination__page--nav" rel="prev" href="<?= $e(desksUrl(['page' => $page > 2 ? $page - 1 : null])) ?>">
      <i class="fa-solid fa-chevron-left" aria-hidden="true"></i><span class="pagination__label">Previous</span>
    </a>
    <?php endif; ?>

    <?php
    $prev = null;
    for ($i = 1; $i <= $totalPages; $i++):
        if (!($i === 1 || $i === $totalPages || abs($i - $page) <= 1)) continue;
        if ($prev !== null && $i - $prev > 1): ?>
    <span class="pagination__ellipsis" aria-hidden="true">…</span>
        <?php endif; ?>
    <a class="pagination__page <?= $i === $page ? 'active' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>
       href="<?= $e(desksUrl(['page' => $i > 1 ? $i : null])) ?>" aria-label="Page <?= $i ?>"><?= $i ?></a>
    <?php $prev = $i; endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a class="pagination__page pagination__page--nav" rel="next" href="<?= $e(desksUrl(['page' => $page + 1])) ?>">
      <span class="pagination__label">Next</span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php endif; ?>


  <!-- ══════════════════════════════════
       CTA
       ══════════════════════════════════ -->
  <section class="dk-cta pub-reveal" aria-labelledby="dkCtaTitle">
    <div class="dk-cta__text">
      <span class="pub-eyebrow">Brokers &amp; sales executives</span>
      <h2 class="dk-cta__title" id="dkCtaTitle">Your desk could be on this page.</h2>
      <p class="dk-cta__sub">
        Create a free SalesDesk, add cars from verified dealerships, share your link —
        and earn commission on every deal you source. No stock, no showroom, no monthly fee.
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
