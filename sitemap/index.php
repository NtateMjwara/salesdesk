<?php
/**
 * SalesDesk — Sitemap Index
 * Route: /sitemap.xml  →  sitemap/index.php  (see .htaccess additions)
 *
 * Lists every child sitemap. Crawlers hit this file first; each entry
 * below is generated (and cached) lazily by its own script, so this
 * index only needs to know how many CAR chunks currently exist.
 *
 * Static sub-sitemaps:
 *   /sitemap-static.xml   — homepage, browse, marketing/how-it-works, legal
 *   /sitemap-desks.xml    — broker storefronts  (/{slug}/)
 *   /sitemap-news.xml     — blog articles       (/news/{slug}/)
 * Dynamic, chunked sub-sitemap:
 *   /sitemap-cars-N.xml   — car detail pages    (/cars-for-sale/{desk}/{car}/)
 *
 * The whole index is itself cached for SITEMAP_CACHE_TTL so a crawl
 * doesn't repeatedly hit the DB just to count car pages.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$xml = smCached('sitemap-index', SITEMAP_CACHE_TTL, function (): string {
    $pdo  = Database::getInstance();
    $base = smBaseUrl();
    $now  = date('c');

    $out = smIndexOpen();

    // ── Static + desks + news are single files ──────────────────
    $out .= smEmitSitemapEntry("{$base}/sitemap-static.xml", $now);
    $out .= smEmitSitemapEntry("{$base}/sitemap-desks.xml", $now);
    $out .= smEmitSitemapEntry("{$base}/sitemap-news.xml", $now);

    // ── Cars are chunked — work out how many chunk files exist ──
    try {
        $total = (int) $pdo->query("
            SELECT COUNT(DISTINCT c.id)
            FROM cars c
            JOIN broker_inventory bi ON bi.car_id = c.id
            JOIN dealers d           ON d.id = c.dealer_id
            WHERE c.status = 'active' AND d.is_active = 1
        ")->fetchColumn();
    } catch (Throwable) {
        $total = 0;
    }

    $chunks = max(1, (int) ceil($total / SITEMAP_MAX_URLS_PER_FILE));
    for ($i = 1; $i <= $chunks; $i++) {
        $out .= smEmitSitemapEntry("{$base}/sitemap-cars-{$i}.xml", $now);
    }

    $out .= smIndexClose();
    return $out;
});

smOutput($xml);
