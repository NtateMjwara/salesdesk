<?php
/**
 * SalesDesk — Sitemap: Blog / News Articles
 * Route: /sitemap-news.xml
 *
 * URL shape: /news/{slug}/  — matches the .htaccess rule for
 * news/article.php. Only posts that are actually publicly visible
 * (status = 'published' AND published_at <= NOW()) are included —
 * scheduled/future posts and drafts are deliberately excluded so we
 * never hand a crawler a 404 or a page it can't yet see.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$xml = smCached('sitemap-news', SITEMAP_CACHE_TTL, function (): string {
    $pdo  = Database::getInstance();
    $base = smBaseUrl();

    $lastmodExpr = smLastmodExpr($pdo, 'blog_posts', 'p');

    $sql = "
        SELECT p.slug, {$lastmodExpr} AS lastmod, p.published_at
        FROM blog_posts p
        WHERE p.status = 'published' AND p.published_at <= NOW()
        ORDER BY p.published_at DESC
    ";

    $out = smUrlsetOpen();

    try {
        $stmt = $pdo->query($sql);
        $recentCutoff = strtotime('-14 days');

        while ($row = $stmt->fetch()) {
            $loc       = $base . '/news/' . rawurlencode($row['slug']) . '/';
            $publishTs = strtotime((string) $row['published_at']) ?: 0;
            $class     = $publishTs >= $recentCutoff ? 'news_recent' : 'news';
            $p         = smPolicy($class);
            $out      .= smEmitUrl($loc, smW3cDate($row['lastmod']), $p['changefreq'], $p['priority']);
        }
    } catch (Throwable) {
        // Fail soft — see cars.php for rationale.
    }

    $out .= smUrlsetClose();
    return $out;
});

smOutput($xml);
