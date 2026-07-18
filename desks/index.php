<?php
/**
 * SalesDesk — Find a SalesDesk (Broker Directory)
 * Route: /desks/  →  /desks/index.php
 *
 * Public, searchable directory of active broker storefronts.
 * Linked from the site footer ("Find a SalesDesk").
 *
 * Filters:  q (name/broker search), province, sort
 * Sort:     active (most cars) | popular (most views) | newest | name
 *
 * Follows the same query-building / pagination conventions as
 * browse pages (browse.css classes reused directly — no new
 * component CSS framework introduced).
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
// ============================================================
$sql = "
    SELECT
        sd.id, sd.uuid, sd.slug, sd.display_name, sd.tagline,
        sd.logo_url, sd.primary_colour, sd.created_at,
        p.first_name, p.last_name, p.avatar_url,
        a.city, a.province, a.suburb,
        o.name                 AS org_name,
        o.verification_status  AS org_verification,
        COUNT(DISTINCT bi.id)  AS cars_count,
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
// PLATFORM-WIDE STAT STRIP
// ============================================================
$globalStatsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT sd.id) AS desk_count,
        COUNT(DISTINCT bi.id) AS listing_count,
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
    SELECT DISTINCT a.province
    FROM salesdesks sd
    JOIN users u ON u.id = sd.user_id AND u.status = 'active'
    JOIN profiles p ON p.user_id = u.id
    JOIN addresses a ON a.id = p.address_id
    WHERE sd.is_active = 1 AND a.province IS NOT NULL AND a.province != ''
    ORDER BY a.province ASC
");
$provinceStmt->execute();
$provinces = array_column($provinceStmt->fetchAll(), 'province');

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

// ============================================================
// PAGE META
// ============================================================
$siteUrl        = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$pageTitle      = 'Find a SalesDesk | Independent Car Brokers in South Africa';
$ogTitle        = 'Find a Broker — SalesDesk Directory';
$ogDescription  = 'Browse ' . number_format((int)$globalStats['desk_count'])
                 . ' independent car brokers across South Africa. Find a trusted SalesDesk near you.';
$canonicalUrl   = $siteUrl . '/desks/' . (desksQueryString() ? '?' . desksQueryString() : '');
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [['Find a SalesDesk', null]];

ob_start();
?>

<!-- ══════════════════════════════════════════════════════
     HERO
     ══════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(140deg,#08143c 0%, var(--p) 100%);
            padding:3rem 24px 2.75rem;margin-bottom:2rem;position:relative;overflow:hidden;">

  <div style="position:absolute;top:-60px;right:-60px;width:220px;height:220px;
              border-radius:50%;background:rgba(255,255,255,.06);"></div>
  <div style="position:absolute;bottom:-40px;left:8%;width:140px;height:140px;
              border-radius:50%;background:rgba(255,255,255,.04);"></div>

  <div style="max-width:820px;margin:0 auto;text-align:center;position:relative;z-index:1;">
    <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.1);
                border:1px solid rgba(255,255,255,.18);border-radius:var(--r-full);
                padding:6px 16px;font-size:12px;font-weight:600;color:#fff;margin-bottom:1.25rem;">
      <i class="fa-solid fa-id-card"></i> <?= number_format((int)$globalStats['desk_count']) ?> active brokers on SalesDesk
    </div>

    <h1 style="font-family:var(--font-d);font-size:34px;font-weight:800;color:#fff;
               line-height:1.15;letter-spacing:-.03em;margin-bottom:.75rem;">
      Find a SalesDesk near you
    </h1>
    <p style="font-size:15px;color:rgba(255,255,255,.65);line-height:1.7;margin-bottom:1.75rem;
              max-width:560px;margin-left:auto;margin-right:auto;">
      Every broker on SalesDesk runs their own personal storefront — verified, commission-protected,
      and backed by real dealer inventory. Search by name or browse by province.
    </p>

    <!-- Search form -->
    <form method="GET" action="/desks/"
          style="display:flex;gap:8px;max-width:520px;margin:0 auto;flex-wrap:wrap;">
      <div style="flex:1;min-width:220px;position:relative;">
        <i class="fa-solid fa-magnifying-glass"
           style="position:absolute;left:16px;top:50%;transform:translateY(-50%);
                  color:rgba(255,255,255,.5);font-size:13px;"></i>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
               placeholder="Search broker or desk name…"
               style="width:100%;padding:13px 16px 13px 40px;border-radius:var(--r-md);
                      border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);
                      color:#fff;font-size:14px;font-family:var(--sans);outline:none;">
      </div>
      <?php if ($province): ?>
      <input type="hidden" name="province" value="<?= htmlspecialchars($province) ?>">
      <?php endif; ?>
      <?php if ($sort !== 'active'): ?>
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
      <?php endif; ?>
      <button type="submit" class="pub-btn pub-btn-primary"
              style="background:#fff;color:var(--p);padding:13px 26px;font-size:14px;flex-shrink:0;">
        Search
      </button>
    </form>

    <!-- Stat strip -->
    <div style="display:flex;gap:28px;justify-content:center;margin-top:2rem;flex-wrap:wrap;">
      <div>
        <div style="font-family:var(--font-d);font-size:22px;font-weight:800;color:#fff;">
          <?= number_format((int)$globalStats['desk_count']) ?>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,.55);">Active brokers</div>
      </div>
      <div>
        <div style="font-family:var(--font-d);font-size:22px;font-weight:800;color:#fff;">
          <?= number_format((int)$globalStats['listing_count']) ?>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,.55);">Cars listed</div>
      </div>
      <div>
        <div style="font-family:var(--font-d);font-size:22px;font-weight:800;color:#4ade80;">
          <?= number_format((int)$globalStats['closed_count']) ?>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,.55);">Deals closed</div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     LAYOUT: filter sidebar + results
     ══════════════════════════════════════════════════════ -->
<div class="browse-layout">

  <!-- Mobile filter toggle -->
  <button class="browse-filter-toggle" id="deskFilterToggle" type="button">
    <span><i class="fa-solid fa-sliders"></i> Filter brokers</span>
    <?php if ($province): ?>
    <span class="browse-filter-toggle__badge">1</span>
    <?php endif; ?>
  </button>

  <!-- ── Sidebar ─────────────────────────────────────────── -->
  <aside class="browse-sidebar" id="deskSidebar">
    <div class="browse-sidebar__close" id="deskSidebarClose">
      <span>Filter brokers</span>
      <i class="fa-solid fa-xmark"></i>
    </div>

    <div class="sidebar-card">
      <div class="sidebar-card__header">
        <div class="sidebar-card__title"><i class="fa-solid fa-sliders"></i> Filters</div>
        <?php if ($q || $province): ?>
        <a href="/desks/<?= $sort !== 'active' ? '?sort=' . urlencode($sort) : '' ?>"
           class="sidebar-card__reset">Reset</a>
        <?php endif; ?>
      </div>

      <form method="GET" action="/desks/" id="deskFilterForm">
        <?php if ($sort !== 'active'): ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <?php endif; ?>

        <div class="filter-section">
          <label class="filter-section-label" for="deskSearchInput">Broker / desk name</label>
          <div class="search-input-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="deskSearchInput" name="q"
                   class="search-input-sidebar"
                   value="<?= htmlspecialchars($q) ?>"
                   placeholder="e.g. Sipho, AutoLink…">
          </div>
        </div>

        <div class="filter-section">
          <label class="filter-section-label" for="deskProvinceSelect">Province</label>
          <select id="deskProvinceSelect" name="province" class="filter-select">
            <option value="">All provinces</option>
            <?php foreach ($provinces as $prov): ?>
            <option value="<?= htmlspecialchars($prov) ?>" <?= $province === $prov ? 'selected' : '' ?>>
              <?= htmlspecialchars($prov) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="filter-apply-btn">Apply filters</button>
        <p class="filter-noscript-hint">Filters apply automatically once JavaScript loads.</p>
      </form>

      <div class="sidebar-card__footer">
        <i class="fa-solid fa-shield-halved"></i>
        Every broker desk is tied to a verified SalesDesk account.
      </div>
    </div>
  </aside>

  <div class="browse-sidebar-overlay" id="deskSidebarOverlay"></div>

  <!-- ── Results ─────────────────────────────────────────── -->
  <div class="browse-results">

    <div class="results-bar">
      <p class="results-bar__count">
        <span class="results-bar__count-num"><?= number_format($totalDesks) ?></span>
        <span class="results-bar__heading">
          broker<?= $totalDesks === 1 ? '' : 's' ?> found<?= $province ? ' in ' . htmlspecialchars($province) : '' ?>
        </span>
      </p>

      <form class="sort-form" method="GET" action="/desks/" id="deskSortForm">
        <?php if ($q): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
        <?php if ($province): ?><input type="hidden" name="province" value="<?= htmlspecialchars($province) ?>"><?php endif; ?>
        <label for="deskSort" style="font-size:12px;color:var(--muted);">Sort:</label>
        <select id="deskSort" name="sort" class="sort-select">
          <option value="active"  <?= $sort === 'active'  ? 'selected' : '' ?>>Most cars listed</option>
          <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most viewed</option>
          <option value="newest"  <?= $sort === 'newest'  ? 'selected' : '' ?>>Newest desks</option>
          <option value="name"    <?= $sort === 'name'    ? 'selected' : '' ?>>Name (A–Z)</option>
        </select>
      </form>
    </div>

    <?php if ($province || $q): ?>
    <div class="active-filter-tags">
      <?php if ($q): ?>
      <span class="active-filter-tag">
        “<?= htmlspecialchars($q) ?>”
        <a class="active-filter-tag__dismiss" href="/desks/?<?= desksQueryString(['q' => null, 'page' => null]) ?>">&times;</a>
      </span>
      <?php endif; ?>
      <?php if ($province): ?>
      <span class="active-filter-tag">
        <?= htmlspecialchars($province) ?>
        <a class="active-filter-tag__dismiss" href="/desks/?<?= desksQueryString(['province' => null, 'page' => null]) ?>">&times;</a>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($desks)): ?>

    <div class="browse-empty">
      <i class="fa-solid fa-magnifying-glass-minus browse-empty__icon"></i>
      <div class="browse-empty__title">No brokers match your search</div>
      <div class="browse-empty__sub">
        Try a different name or clear your province filter.
      </div>
      <a href="/desks/" class="browse-empty__reset">Clear all filters</a>
    </div>

    <?php else: ?>

    <div class="desk-grid browse-grid--animated">
      <?php foreach ($desks as $desk):
        $brokerName = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? ''))
                      ?: $desk['display_name'];
        $initials   = strtoupper(
            substr($desk['first_name'] ?? '', 0, 1) . substr($desk['last_name'] ?? '', 0, 1)
        ) ?: 'SD';
        $location   = implode(', ', array_filter([$desk['city'], $desk['province']]));
        $accent     = $desk['primary_colour'] ?: '#0f4c9e';
        $isOrgVerified = ($desk['org_verification'] ?? null) === 'verified';
        $deskUrl    = '/' . htmlspecialchars($desk['slug']) . '/';
      ?>
      <a href="<?= $deskUrl ?>" class="desk-card">
        <div class="desk-card__banner" style="background:linear-gradient(135deg,#08143c, <?= htmlspecialchars($accent) ?>);">
          <?php if ((int)$desk['cars_count'] > 0): ?>
          <span class="desk-card__banner-tag">
            <i class="fa-solid fa-car"></i> <?= (int)$desk['cars_count'] ?> car<?= (int)$desk['cars_count'] === 1 ? '' : 's' ?>
          </span>
          <?php endif; ?>
        </div>

        <div class="desk-card__body">
          <div class="desk-card__avatar">
            <?php if ($desk['avatar_url']): ?>
            <img src="<?= htmlspecialchars($desk['avatar_url']) ?>" alt="<?= htmlspecialchars($brokerName) ?>">
            <?php else: ?>
            <?= htmlspecialchars($initials) ?>
            <?php endif; ?>
          </div>

          <div class="desk-card__name"><?= htmlspecialchars($desk['display_name']) ?></div>
          <div class="desk-card__broker"><?= htmlspecialchars($brokerName) ?></div>

          <?php if ($desk['tagline']): ?>
          <div class="desk-card__tagline"><?= htmlspecialchars(mb_strimwidth($desk['tagline'], 0, 72, '…')) ?></div>
          <?php endif; ?>

          <div class="desk-card__badges">
            <?php if ($location): ?>
            <span class="desk-card__badge"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($location) ?></span>
            <?php endif; ?>
            <?php if ($isOrgVerified): ?>
            <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($desk['org_name']) ?></span>
            <?php endif; ?>
          </div>

          <div class="desk-card__stats">
            <div class="desk-card__stat">
              <div class="desk-card__stat-num"><?= number_format((int)$desk['total_views']) ?></div>
              <div class="desk-card__stat-lbl">Views</div>
            </div>
            <div class="desk-card__stat">
              <div class="desk-card__stat-num"><?= (int)$desk['deals_closed'] ?></div>
              <div class="desk-card__stat-lbl">Closed</div>
            </div>
            <div class="desk-card__stat desk-card__stat--cta">
              Visit desk <i class="fa-solid fa-arrow-right"></i>
            </div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
      <a class="pagination__page pagination__page--nav" href="?<?= desksQueryString(['page' => $page - 1]) ?>">
        <i class="fa-solid fa-chevron-left"></i> Prev
      </a>
      <?php endif; ?>

      <?php
      $windowStart = max(1, $page - 2);
      $windowEnd   = min($totalPages, $page + 2);

      if ($windowStart > 1): ?>
        <a class="pagination__page" href="?<?= desksQueryString(['page' => 1]) ?>">1</a>
        <?php if ($windowStart > 2): ?><span class="pagination__ellipsis">…</span><?php endif; ?>
      <?php endif;

      for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
        <a class="pagination__page <?= $i === $page ? 'active' : '' ?>"
           href="?<?= desksQueryString(['page' => $i]) ?>"><?= $i ?></a>
      <?php endfor;

      if ($windowEnd < $totalPages):
        if ($windowEnd < $totalPages - 1): ?><span class="pagination__ellipsis">…</span><?php endif; ?>
        <a class="pagination__page" href="?<?= desksQueryString(['page' => $totalPages]) ?>"><?= $totalPages ?></a>
      <?php endif; ?>

      <?php if ($page < $totalPages): ?>
      <a class="pagination__page pagination__page--nav" href="?<?= desksQueryString(['page' => $page + 1]) ?>">
        Next <i class="fa-solid fa-chevron-right"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; // empty($desks) ?>

  </div><!-- /browse-results -->
</div><!-- /browse-layout -->

<!-- ══════════════════════════════════════════════════════
     CTA — become a broker
     ══════════════════════════════════════════════════════ -->
<div style="margin-inline:clamp(16px,4vw,48px);margin-top:3rem;">
  <div style="text-align:center;padding:2.25rem;background:var(--white);
              border:1px solid var(--border);border-radius:var(--r-xl);
              box-shadow:var(--shadow-md);">
    <div style="font-family:var(--font-d);font-size:20px;font-weight:800;margin-bottom:6px;">
      Don't see your desk here yet?
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:1.25rem;max-width:420px;
              margin-left:auto;margin-right:auto;">
      Create your free SalesDesk in minutes — add cars, share your link, and start earning commission.
    </p>
    <a href="/brokers.php" class="pub-btn pub-btn-primary" style="padding:12px 28px;font-size:14px;display:inline-flex;">
      <i class="fa-solid fa-id-card"></i> Create your SalesDesk
    </a>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     PAGE-SCOPED STYLES — desk directory card
     Reuses global tokens; adds only what browse.css /
     public.css don't already provide.
     ══════════════════════════════════════════════════════ -->
<style>
.desk-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 18px;
}

.desk-card {
  display: block;
  text-decoration: none;
  color: inherit;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  transition: transform .28s cubic-bezier(.2,0,0,1), box-shadow .28s, border-color .18s;
}

.desk-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 16px 32px rgba(15,76,158,.13);
  border-color: #c7d6f5;
  text-decoration: none;
}

.desk-card__banner {
  height: 64px;
  position: relative;
}

.desk-card__banner-tag {
  position: absolute;
  bottom: 10px;
  right: 12px;
  background: rgba(255,255,255,.94);
  color: var(--p);
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: var(--r-full);
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.desk-card__body {
  padding: 0 18px 18px;
  position: relative;
}

.desk-card__avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, #3b82f6, #1d4ed8);
  border: 3px solid var(--white);
  color: #fff;
  font-family: var(--font-d);
  font-size: 18px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: -28px;
  margin-bottom: 10px;
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.desk-card__avatar img { width: 100%; height: 100%; object-fit: cover; }

.desk-card__name {
  font-family: var(--font-d);
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
  letter-spacing: -.01em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.desk-card__broker {
  font-size: 12px;
  color: var(--muted);
  margin-top: 1px;
  margin-bottom: 8px;
}

.desk-card__tagline {
  font-size: 12px;
  color: var(--faint);
  line-height: 1.55;
  margin-bottom: 10px;
}

.desk-card__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 14px;
}

.desk-card__badge {
  font-size: 11px;
  color: var(--muted);
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--r-full);
  padding: 3px 9px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.desk-card__stats {
  display: flex;
  align-items: center;
  gap: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.desk-card__stat { text-align: left; }
.desk-card__stat-num { font-family: var(--font-d); font-size: 15px; font-weight: 700; color: var(--text); }
.desk-card__stat-lbl { font-size: 10px; color: var(--faint); }

.desk-card__stat--cta {
  margin-left: auto;
  font-size: 12px;
  font-weight: 600;
  color: var(--p);
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}

.desk-card__stat--cta i {
  font-size: 10px;
  transition: transform .18s;
}

.desk-card:hover .desk-card__stat--cta i { transform: translateX(3px); }

@media (max-width: 480px) {
  .desk-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ══════════════════════════════════════════════════════
     BEHAVIOUR — mobile filter drawer + auto-submit selects
     (Mirrors the pattern used on the main /c/ browse page.)
     ══════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  var toggle   = document.getElementById('deskFilterToggle');
  var sidebar  = document.getElementById('deskSidebar');
  var overlay  = document.getElementById('deskSidebarOverlay');
  var closeBtn = document.getElementById('deskSidebarClose');

  function openDrawer() {
    if (!sidebar) return;
    sidebar.classList.add('drawer-open');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    if (!sidebar) return;
    sidebar.classList.remove('drawer-open');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  if (toggle)   toggle.addEventListener('click', openDrawer);
  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (overlay)  overlay.addEventListener('click', closeDrawer);

  // Auto-submit on province / sort change (progressive enhancement —
  // the "Apply filters" button and noscript hint remain functional
  // fallbacks without JS).
  var provinceSelect = document.getElementById('deskProvinceSelect');
  if (provinceSelect) {
    provinceSelect.addEventListener('change', function () {
      document.getElementById('deskFilterForm').requestSubmit();
    });
  }

  var sortSelect = document.getElementById('deskSort');
  if (sortSelect) {
    sortSelect.addEventListener('change', function () {
      document.getElementById('deskSortForm').requestSubmit();
    });
  }
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
