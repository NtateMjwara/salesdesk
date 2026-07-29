<?php
/**
 * SalesDesk — Sitemap: Static & Marketing Pages
 * Route: /sitemap-static.xml
 *
 * Every URL here is a fixed route (not driven by a DB table). Adding a
 * new static page to the site means adding one line here.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$xml = smCached('sitemap-static', SITEMAP_CACHE_TTL, function (): string {
    $base = smBaseUrl();
    $now  = date('c');

    // path => url class (drives priority/changefreq — see smPolicy())
    $pages = [
        '/'                              => 'home',
        '/cars-for-sale/'                => 'browse',

        // Curated single-facet landing pages — these are the ONLY
        // filtered /cars-for-sale/ combinations that self-canonicalize
        // rather than noindex (see includes/seo-canonical.php's
        // seoCuratedBrowseFacets() — keep this list in sync with that
        // one; a URL added to one but not the other either gets
        // sitemap'd-but-noindexed or is a real page missing from the
        // sitemap).
        '/cars-for-sale/?condition=new'  => 'browse',
        '/cars-for-sale/?condition=demo' => 'browse',
        '/cars-for-sale/?condition=used' => 'browse',
        '/cars-for-sale/?body_type%5B%5D=Bakkie'    => 'browse',
        '/cars-for-sale/?body_type%5B%5D=Hatchback' => 'browse',
        '/cars-for-sale/?body_type%5B%5D=Sedan'     => 'browse',
        '/cars-for-sale/?body_type%5B%5D=SUV'       => 'browse',

        '/desks/'                        => 'directory',
        '/news/'                         => 'directory',
        '/outreach/'                     => 'info',
        '/how-it-works/brokers.php'      => 'info',
        '/how-it-works/dealers.php'      => 'info',
        '/how-it-works/sales-exec.php'   => 'info',
        '/brokers.php'                   => 'info',
        '/dealers.php'                   => 'info',
        '/tools/compare/'                => 'info',
        '/tools/finance-calculator/'     => 'info',
        '/privacy'                       => 'legal',
        '/terms'                         => 'legal',
    ];

    $out = smUrlsetOpen();
    foreach ($pages as $path => $class) {
        $p = smPolicy($class);
        $out .= smEmitUrl($base . $path, $now, $p['changefreq'], $p['priority']);
    }

    // ── Single-make landing pages ────────────────────────────────
    // Unlike body_type (a small fixed whitelist, hardcoded above),
    // `make` is open-ended and DB-driven — seoResolveBrowseCanonical()
    // treats any single `?make=` value as a curated, self-canonical
    // landing page, so every active make gets one here too. Kept in
    // its own try/catch so a DB hiccup degrades to "fewer sitemap
    // entries" rather than breaking the whole static sitemap.
    try {
        $pdo   = Database::getInstance();
        $makes = $pdo->query("
            SELECT DISTINCT c.make FROM cars c
            JOIN broker_inventory bi ON bi.car_id = c.id
            WHERE c.status = 'active'
            ORDER BY c.make
        ")->fetchAll(PDO::FETCH_COLUMN);

        $p = smPolicy('browse');
        foreach ($makes as $make) {
            $url = $base . '/cars-for-sale/?make=' . rawurlencode($make);
            $out .= smEmitUrl($url, $now, $p['changefreq'], $p['priority']);
        }
    } catch (Throwable) {
        // Fail soft — see cars.php for rationale.
    }

    $out .= smUrlsetClose();
    return $out;
});

smOutput($xml);
