<?php
/**
 * SalesDesk — Sitemap engine (shared helpers)
 *
 * Used by every file in /sitemap/. Not routed to directly.
 *
 * Provides:
 *   - Streaming XML writers (index + urlset) so we never build a giant
 *     string in memory for large tables.
 *   - Disk-backed caching with flock() so concurrent hits during a cache
 *     miss don't all hammer the DB at once (thundering-herd guard).
 *   - Gzip negotiation.
 *   - Safe "does this column exist" introspection, so we can prefer
 *     updated_at for <lastmod> when a table has it, and fall back to
 *     created_at when it doesn't, without hardcoding schema assumptions.
 *   - Centralised priority/changefreq policy per URL class.
 *   - smCarsForSaleExtras() — the single source of truth for which
 *     /cars-for-sale/ URLs are curated, self-canonical landing pages
 *     (browse root + condition/body_type/make facets), consumed by
 *     cars-for-sale.php and index.php so the facet list can't drift
 *     out of sync the way it could when static.php and cars.php each
 *     maintained their own copies.
 */

declare(strict_types=1);

const SITEMAP_MAX_URLS_PER_FILE = 45000; // protocol cap is 50,000 — leave headroom
const SITEMAP_CACHE_TTL         = 21600; // 6 hours
const SITEMAP_CACHE_DIR         = __DIR__ . '/../cache';
const SITEMAP_XML_NS            = 'http://www.sitemaps.org/schemas/sitemap/0.9';

// ── XML escaping ────────────────────────────────────────────────
function smXmlEscape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ── W3C datetime for <lastmod> ──────────────────────────────────
function smW3cDate(?string $dateTime): string
{
    $ts = $dateTime ? strtotime($dateTime) : false;
    if ($ts === false) $ts = time();
    return date('c', $ts);
}

// ── Column introspection (cached per-request) ───────────────────
function smColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Returns a SQL fragment selecting the best available "last modified"
 * column for a table, aliased as `lastmod`, preferring updated_at when
 * present and falling back to created_at, then NOW().
 */
function smLastmodExpr(PDO $pdo, string $table, string $alias): string
{
    if (smColumnExists($pdo, $table, 'updated_at')) {
        return "COALESCE({$alias}.updated_at, {$alias}.created_at)";
    }
    if (smColumnExists($pdo, $table, 'created_at')) {
        return "{$alias}.created_at";
    }
    return 'NOW()';
}

// ── Priority / changefreq policy, centralised so every generator agrees ──
function smPolicy(string $urlClass): array
{
    return match ($urlClass) {
        'home'        => ['priority' => '1.0', 'changefreq' => 'daily'],
        'browse'      => ['priority' => '0.9', 'changefreq' => 'hourly'],
        'directory'   => ['priority' => '0.7', 'changefreq' => 'daily'],
        'info'        => ['priority' => '0.6', 'changefreq' => 'weekly'],
        'legal'       => ['priority' => '0.3', 'changefreq' => 'yearly'],
        'car'         => ['priority' => '0.6', 'changefreq' => 'weekly'],
        'car_recent'  => ['priority' => '0.8', 'changefreq' => 'daily'],
        'desk'        => ['priority' => '0.5', 'changefreq' => 'weekly'],
        'desk_active' => ['priority' => '0.65','changefreq' => 'daily'],
        'news'        => ['priority' => '0.6', 'changefreq' => 'monthly'],
        'news_recent' => ['priority' => '0.75','changefreq' => 'weekly'],
        default       => ['priority' => '0.5', 'changefreq' => 'weekly'],
    };
}

// ── Streaming <url> emitter ──────────────────────────────────────
function smEmitUrl(string $loc, string $lastmod, string $changefreq, string $priority): string
{
    return
        "  <url>\n" .
        "    <loc>" . smXmlEscape($loc) . "</loc>\n" .
        "    <lastmod>" . smXmlEscape($lastmod) . "</lastmod>\n" .
        "    <changefreq>{$changefreq}</changefreq>\n" .
        "    <priority>{$priority}</priority>\n" .
        "  </url>\n";
}

function smEmitSitemapEntry(string $loc, string $lastmod): string
{
    return
        "  <sitemap>\n" .
        "    <loc>" . smXmlEscape($loc) . "</loc>\n" .
        "    <lastmod>" . smXmlEscape($lastmod) . "</lastmod>\n" .
        "  </sitemap>\n";
}

function smUrlsetOpen(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
        '<?xml-stylesheet type="text/xsl" href="/sitemap/sitemap.xsl"?>' . "\n" .
        '<urlset xmlns="' . SITEMAP_XML_NS . '">' . "\n";
}

function smUrlsetClose(): string
{
    return "</urlset>\n";
}

function smIndexOpen(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
        '<?xml-stylesheet type="text/xsl" href="/sitemap/sitemap.xsl"?>' . "\n" .
        '<sitemapindex xmlns="' . SITEMAP_XML_NS . '">' . "\n";
}

function smIndexClose(): string
{
    return "</sitemapindex>\n";
}

// ── Site base URL ────────────────────────────────────────────────
function smBaseUrl(): string
{
    return defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://salesdesk.co.za';
}

/**
 * Disk cache with a lock file to avoid a thundering herd of concurrent
 * cache-miss regenerations. $builder is only invoked on a real miss.
 *
 * Usage:
 *   smCached('sitemap-cars-1', 21600, function () { ... return $xmlString; });
 */
function smCached(string $key, int $ttl, callable $builder): string
{
    $safeKey  = preg_replace('/[^a-z0-9\-_]/i', '_', $key);
    $dir      = SITEMAP_CACHE_DIR;
    $file     = $dir . '/' . $safeKey . '.xml';
    $lockFile = $dir . '/' . $safeKey . '.lock';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // Fresh cache hit — serve immediately.
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $contents = @file_get_contents($file);
        if ($contents !== false) return $contents;
    }

    // Cache miss / stale — acquire an exclusive lock so only one process
    // regenerates. Others fall back to the stale file if present, or
    // block briefly and then build in-process as a last resort.
    $fp = @fopen($lockFile, 'c');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
        try {
            $content = $builder();
            @file_put_contents($file, $content, LOCK_EX);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $content;
    }

    // Someone else is regenerating — serve stale copy if we have one.
    if (is_file($file)) {
        $contents = @file_get_contents($file);
        if ($contents !== false) return $contents;
    }

    // No cache at all yet and lock unavailable — build directly,
    // uncached, rather than showing an empty sitemap.
    if ($fp) fclose($fp);
    return $builder();
}

// ── Output with gzip negotiation + correct headers ──────────────
function smOutput(string $xml): void
{
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex'); // the sitemap itself shouldn't be indexed as a page

    $acceptsGzip = isset($_SERVER['HTTP_ACCEPT_ENCODING'])
        && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip');

    if ($acceptsGzip && function_exists('gzencode')) {
        header('Content-Encoding: gzip');
        echo gzencode($xml, 6);
    } else {
        echo $xml;
    }
}

/** Sanitize the ?page= query param used by chunked generators. */
function smPageParam(): int
{
    $p = (int) ($_GET['page'] ?? 1);
    return max(1, $p);
}

/**
 * Curated, self-canonical /cars-for-sale/ landing pages: the browse
 * root plus every facet that seoResolveBrowseCanonical() (see
 * includes/seo-canonical.php) treats as indexable rather than
 * noindex,follow. This is the ONLY place that list is hardcoded —
 * cars-for-sale.php and index.php both call this instead of keeping
 * their own copies, so the fixed facets (condition, body_type) and
 * the DB-driven facet (make) can never drift out of sync the way
 * static.php and cars.php could when each maintained it separately.
 *
 * Returns a flat list of [loc, lastmod, urlClass] tuples. $now is
 * passed in (rather than computed here) so a single call gives every
 * caller an identical result — cars-for-sale.php needs both the
 * entries themselves AND their count (for pagination offset math),
 * and a second independent call could theoretically race against a
 * makes table update between the two.
 */
function smCarsForSaleExtras(PDO $pdo, string $base, string $now): array
{
    $entries   = [];
    $entries[] = [$base . '/cars-for-sale/', $now, 'browse'];

    foreach (['new', 'demo', 'used'] as $cond) {
        $entries[] = [$base . '/cars-for-sale/?condition=' . $cond, $now, 'browse'];
    }

    foreach (['Bakkie', 'Hatchback', 'Sedan', 'SUV'] as $bt) {
        $entries[] = [
            $base . '/cars-for-sale/?body_type%5B%5D=' . rawurlencode($bt),
            $now,
            'browse',
        ];
    }

    try {
        $makes = $pdo->query("
            SELECT DISTINCT c.make FROM cars c
            JOIN broker_inventory bi ON bi.car_id = c.id
            WHERE c.status = 'active'
            ORDER BY c.make
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($makes as $make) {
            $entries[] = [
                $base . '/cars-for-sale/?make=' . rawurlencode($make),
                $now,
                'browse',
            ];
        }
    } catch (Throwable) {
        // Fail soft — see cars.php's rationale elsewhere in this codebase.
    }

    return $entries;
}
