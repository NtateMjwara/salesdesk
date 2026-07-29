<?php
/**
 * SalesDesk — Sitemap: Broker Storefronts
 * Route: /sitemap-desks.xml
 *
 * URL shape: /{broker-slug}/  — the .htaccess catch-all route.
 * Only active desks belonging to active users are included, and only
 * desks with at least one active listing get the higher "desk_active"
 * priority — an empty storefront is still worth indexing (it's a real
 * public page) but shouldn't outrank one with live inventory.
 *
 * Desk counts are typically far below the 50k/file cap, so this stays
 * a single file, but the loop is written the same streaming way as
 * cars.php in case that ever changes.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$xml = smCached('sitemap-desks', SITEMAP_CACHE_TTL, function (): string {
    $pdo  = Database::getInstance();
    $base = smBaseUrl();

    $lastmodExpr = smLastmodExpr($pdo, 'salesdesks', 'sd');

    $sql = "
        SELECT
            sd.slug,
            {$lastmodExpr} AS lastmod,
            COUNT(DISTINCT bi.id) AS active_listings
        FROM salesdesks sd
        JOIN users u ON u.id = sd.user_id
        LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
        LEFT JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
        WHERE sd.is_active = 1 AND u.status = 'active'
        GROUP BY sd.id
        ORDER BY sd.id ASC
    ";

    $out = smUrlsetOpen();

    try {
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch()) {
            $loc   = $base . '/' . rawurlencode($row['slug']) . '/';
            $class = ((int) $row['active_listings']) > 0 ? 'desk_active' : 'desk';
            $p     = smPolicy($class);
            $out  .= smEmitUrl($loc, smW3cDate($row['lastmod']), $p['changefreq'], $p['priority']);
        }
    } catch (Throwable) {
        // Fail soft — see cars.php for rationale.
    }

    $out .= smUrlsetClose();
    return $out;
});

smOutput($xml);
