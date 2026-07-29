<?php
/**
 * SalesDesk — Sitemap: Car Detail Pages
 * Route: /sitemap-cars-{N}.xml   (N = ?page=N, 1-indexed)
 *
 * URL shape must exactly match .htaccess's attribution route:
 *   /cars-for-sale/{desk-slug}/{car-slug}/
 * (renamed from the old /c/ path — see NOTE at the bottom of this file
 * if the migration is still mid-flight and both paths need to resolve)
 * — the same "earliest-listed desk wins" subquery used by
 *   cars-for-sale/index.php and index.php (homepage) is reused here so
 *   the sitemap never points crawlers at a URL the site itself wouldn't
 *   attribute that way.
 *
 * Chunked at SITEMAP_MAX_URLS_PER_FILE per file, ordered by c.id so
 * pagination is stable even if rows are added/removed between requests.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$page = smPageParam();

$xml = smCached("sitemap-cars-{$page}", SITEMAP_CACHE_TTL, function () use ($page): string {
    $pdo    = Database::getInstance();
    $base   = smBaseUrl();
    $offset = ($page - 1) * SITEMAP_MAX_URLS_PER_FILE;

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

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, SITEMAP_MAX_URLS_PER_FILE, PDO::PARAM_INT);
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
 */

