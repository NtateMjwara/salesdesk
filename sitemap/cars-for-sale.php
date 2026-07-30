<?php
/**
 * SalesDesk — Sitemap: /cars-for-sale/ section
 * Route: /sitemap-cars-for-sale-{N}.xml   (N = ?page=N, 1-indexed)
 *
 * Consolidates what used to be split between static.php (browse root
 * + curated facet pages) and cars.php (car detail rows) into a single
 * owner for every /cars-for-sale/... URL. The curated-facet list now
 * lives in exactly one place — smCarsForSaleExtras() in
 * sitemap-functions.php — so it can't quietly drift out of sync with
 * includes/seo-canonical.php's seoCuratedBrowseFacets() the way it
 * could when two separate generator files each hardcoded their own
 * copy.
 *
 * URL shape for car details must exactly match .htaccess's attribution
 * route: /cars-for-sale/{desk-slug}/{car-slug}/ — the same
 * "earliest-listed desk wins" subquery used by cars-for-sale/index.php
 * is reused here so the sitemap never points crawlers at a URL the
 * site itself wouldn't attribute that way.
 *
 * Chunking: the curated extras (browse root + facets) are small and
 * only ever emitted on page 1, ahead of the car rows, so a crawler
 * hitting page 1 sees the highest-priority URLs first. Every
 * subsequent page is 100% car-detail rows. $extrasCount is computed
 * on every page — not just page 1 — purely so the LIMIT/OFFSET math
 * below stays correct across the chunk boundary; it's simply not
 * emitted when $page !== 1.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$page = smPageParam();

$xml = smCached("sitemap-cars-for-sale-{$page}", SITEMAP_CACHE_TTL, function () use ($page): string {
    $pdo  = Database::getInstance();
    $base = smBaseUrl();
    $now  = date('c');

    $extras      = smCarsForSaleExtras($pdo, $base, $now);
    $extrasCount = count($extras);

    // Page 1's car quota shrinks by $extrasCount so the file still fits
    // under SITEMAP_MAX_URLS_PER_FILE with the extras included. Every
    // later page's offset is shifted back by that same amount so no
    // car row is skipped or duplicated across the boundary.
    $carsLimit = SITEMAP_MAX_URLS_PER_FILE - ($page === 1 ? $extrasCount : 0);
    $offset    = $page === 1
        ? 0
        : (SITEMAP_MAX_URLS_PER_FILE - $extrasCount) + ($page - 2) * SITEMAP_MAX_URLS_PER_FILE;

    $lastmodExpr = smLastmodExpr($pdo, 'cars', 'c');

    $sql = "
        SELECT
            c.slug AS car_slug,
            {$lastmodExpr} AS lastmod,
            first_desk.desk_slug
        FROM cars c
        JOIN dealers d ON d.id = c.dealer_id
        LEFT JOIN (
            SELECT bi2.car_id, sd2.slug AS desk_slug
            FROM broker_inventory bi2
            JOIN salesdesks sd2 ON sd2.id = bi2.salesdesk_id
            WHERE bi2.added_at = (
                SELECT MIN(bi3.added_at)
                FROM broker_inventory bi3
                WHERE bi3.car_id = bi2.car_id
            )
            GROUP BY bi2.car_id
        ) first_desk ON first_desk.car_id = c.id
        WHERE c.status = 'active'
          AND d.is_active = 1
          AND first_desk.desk_slug IS NOT NULL
        ORDER BY c.id ASC
        LIMIT ? OFFSET ?
    ";

    $out = smUrlsetOpen();

    // ── Page 1 only: browse root + curated facet landing pages ───
    if ($page === 1) {
        foreach ($extras as [$loc, $lastmod, $class]) {
            $p    = smPolicy($class);
            $out .= smEmitUrl($loc, smW3cDate($lastmod), $p['changefreq'], $p['priority']);
        }
    }

    // ── Car detail rows ────────────────────────────────────────
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $carsLimit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $recentCutoff = strtotime('-7 days');

        while ($row = $stmt->fetch()) {
            $loc = $base . '/cars-for-sale/'
                 . rawurlencode($row['desk_slug']) . '/'
                 . rawurlencode($row['car_slug']) . '/';

            $lastmodTs = strtotime((string) $row['lastmod']) ?: time();
            $class     = $lastmodTs >= $recentCutoff ? 'car_recent' : 'car';
            $p         = smPolicy($class);

            $out .= smEmitUrl($loc, smW3cDate($row['lastmod']), $p['changefreq'], $p['priority']);
        }
    } catch (Throwable) {
        // Fail soft: an empty (but valid) urlset rather than a 500,
        // so crawlers don't get penalized for a transient DB error.
    }

    $out .= smUrlsetClose();
    return $out;
});

smOutput($xml);

/**
 * NOTE — mid-migration from /c/ to /cars-for-sale/:
 *
 * This generator only ever emits the NEW /cars-for-sale/ path — a
 * sitemap should always advertise the canonical URL you want indexed,
 * never a legacy one, so nothing here needs a "both paths" mode.
 *
 * What you still need on the .htaccess side (outside this file) is a
 * 301 redirect from the old pattern to the new one, e.g.:
 *
 *   RewriteRule ^c/([a-z0-9][a-z0-9\-]{1,59})/([a-z0-9][a-z0-9\-]{1,99})/?$ \
 *     /cars-for-sale/$1/$2/ [R=301,L]
 *
 * placed above the old /c/... routing rule. Without that redirect,
 * search engines that already indexed /c/{desk}/{car}/ URLs will hit
 * whatever /c/ now resolves to (likely a 404) instead of transferring
 * their ranking signal to the new URL. Once that redirect is live and
 * Search Console shows the old URLs deindexed, this file needs no
 * further changes — it's already only pointing at /cars-for-sale/.
 *
 * ── Supersedes cars.php ────────────────────────────────────────
 * This file replaces sitemap/cars.php AND the /cars-for-sale/ entries
 * that used to live in sitemap/static.php (browse root + curated
 * condition/body_type/make facets). See sitemap/index.php for the
 * updated chunk-count math and sitemap/static.php for the trimmed
 * static page list. If old /sitemap-cars-{N}.xml URLs are already
 * indexed in Search Console, consider leaving cars.php in place
 * temporarily (pointed at nothing new) with a deprecation comment,
 * same pattern as the /c/ → /cars-for-sale/ route rename above,
 * rather than deleting it outright.
 */
