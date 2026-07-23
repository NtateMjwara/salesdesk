<?php
/**
 * SalesDesk — Website Inventory Importer (Phase 1).
 *
 * Second concrete implementation of InventoryImporterInterface, alongside
 * CsvImporter. Lets a dealer import their own live inventory by pointing
 * this at their own website instead of exporting a CSV.
 *
 * SCOPE — READ BEFORE EXTENDING:
 *
 *   1. DEALER'S OWN SITE ONLY. crawlDealership() enforces same-registrable-
 *      domain fetching (see isSameSite()) and will not follow links off the
 *      starting host. This is not a general-purpose scraper and must never
 *      become one — it exists to let a dealer who already maintains a
 *      website avoid re-typing their stock, not to pull listings from a
 *      marketplace or a competitor's site. Do not add cross-domain
 *      following, and do not remove the SSRF guard in isPubliclyRoutable().
 *
 *   2. schema.org / JSON-LD ONLY. Extraction is delegated entirely to
 *      SchemaOrgVehicleParser — no site-specific CSS-selector scraping, no
 *      headless-browser JS rendering. A site with no structured vehicle
 *      markup at all will import zero vehicles, and that's the correct,
 *      honest result for Phase 1 rather than a brittle guess. Sites that
 *      render markup client-side, or that never emit schema.org data, are
 *      Phase 2+ (a known-CMS adapter list, or a rendering pipeline).
 *
 *   3. ROBOTS.TXT + RATE LIMITING. Every fetch is checked against the
 *      target's robots.txt for our user-agent, and requests are throttled
 *      (respecting Crawl-delay when present, a floor otherwise). This is
 *      a dealer's own site, but it may still be shared hosting or a small
 *      VPS — hammering it is not acceptable regardless of ownership.
 *
 *   4. SYNCHRONOUS, BOUNDED CRAWL. Like CsvImporter's sync() (see
 *      app/dealer/import.php's docblock on why), this runs inline within
 *      one request. MAX_PAGES and per-request timeouts keep worst-case
 *      time bounded; there is still no background job queue in this
 *      codebase, so a genuinely large dealer site should be pointed at a
 *      sitemap.xml (checked first, see discoverCandidateUrls()) rather
 *      than relying on the BFS fallback to explore very deep sites.
 *
 *   5. DEDUP AND PERSISTENCE mirror CsvImporter exactly: VIN first, then
 *      dealer_stock_no, both scoped to (dealer_id, ...) per the DB unique
 *      keys in migration 0011. Images are re-hosted via the existing
 *      ImageRehostService rather than hotlinking the dealer's own site —
 *      same reasoning as CsvImporter (dead links if they redesign, no
 *      third-party dependency for serving car photos).
 *
 * NOT DONE HERE (left for later phases, see WebsiteImporter's docblock in
 * the original design conversation):
 *   - JSON API / GraphQL / XML feed detection.
 *   - Scheduled re-sync (hourly/daily) — this class only supports an
 *     on-demand sync() call, same as CsvImporter today.
 *   - A CMS-specific adapter fallback for sites without schema.org markup.
 */

require_once __DIR__ . '/InventoryImporterInterface.php';
require_once __DIR__ . '/SchemaOrgVehicleParser.php';
require_once __DIR__ . '/MotusShowroomParser.php';
require_once __DIR__ . '/NormalizesVehicleFields.php';
require_once __DIR__ . '/PersistsVehicleImports.php';
require_once __DIR__ . '/ImageRehostService.php';
require_once __DIR__ . '/../functions.php';

class WebsiteImporter implements InventoryImporterInterface
{
    use NormalizesVehicleFields;
    use PersistsVehicleImports;

    private const MAX_PAGES           = 60;   // hard cap on pages fetched per sync() call
    private const MAX_CRAWL_DEPTH     = 3;     // BFS fallback depth from the homepage
    private const REQUEST_TIMEOUT_S   = 10;
    private const MAX_PAGE_BYTES      = 3 * 1024 * 1024; // 3MB
    private const MIN_DELAY_US        = 400_000;         // 0.4s floor between requests
    private const USER_AGENT          = 'SalesDeskImporter/1.0 (+https://salesdesk.co.za)';

    /**
     * Wall-clock budget (seconds) for the WHOLE sync() call — crawling
     * plus image re-hosting combined — not per-request. MAX_PAGES alone
     * doesn't bound wall-clock time: a page that hangs near its own
     * REQUEST_TIMEOUT_S, or a car with several large images to re-host,
     * can each eat many seconds on their own, and there's no cap on how
     * many of those a single sync() call can hit in sequence. Without
     * this, a normal-sized dealer site can easily run past a reverse
     * proxy's read timeout (nginx's fastcgi_read_timeout and Apache's
     * ProxyTimeout both commonly default to 60s) — set_time_limit() in
     * import-website.php controls PHP's OWN execution limit only; it has
     * no effect on a gateway sitting in front of PHP-FPM, which will
     * return its own 504 independently of whatever PHP is still doing.
     *
     * 35s leaves meaningful margin under a 60s gateway default once you
     * account for DB writes and general overhead after the deadline is
     * reached. Callers can override via sync()'s
     * $options['time_budget_seconds'] if their gateway timeout has been
     * raised to allow more.
     */
    private const DEFAULT_TIME_BUDGET_SECONDS = 35;

    /** URL-path keywords that bump a candidate page's crawl priority. */
    private const LISTING_KEYWORDS = [
        'vehicle', 'car', 'cars', 'stock', 'inventory', 'used', 'listing',
        'for-sale', 'demo', 'new-vehicles',
    ];

    private PDO $pdo;
    private ImageRehostService $imageRehost;
    private SchemaOrgVehicleParser $parser;

    /**
     * Additional extraction strategies tried, in order, only when the
     * primary schema.org parser finds nothing on a page — the "known-CMS
     * adapter" chain flagged as future work in this file's original
     * design and now started with MotusShowroomParser. Each must expose
     * an extract(string $html): array method with the same contract as
     * SchemaOrgVehicleParser::extract(). Kept as a constructor-injectable
     * list (not hardcoded inline) so a specific site's crawl can disable
     * or extend it without touching this class.
     *
     * @var array<int, object{extract: callable}>
     */
    private array $fallbackParsers;

    /** @var array<string, string[]> robots.txt disallow rules, keyed by host */
    private array $robotsCache = [];

    public function __construct(
        ?PDO $pdo = null,
        ?ImageRehostService $imageRehost = null,
        ?SchemaOrgVehicleParser $parser = null,
        ?array $fallbackParsers = null
    ) {
        $this->pdo              = $pdo ?? Database::getInstance();
        $this->imageRehost      = $imageRehost ?? new ImageRehostService();
        $this->parser           = $parser ?? new SchemaOrgVehicleParser();
        $this->fallbackParsers  = $fallbackParsers ?? [new MotusShowroomParser()];
    }

    public function getSourceName(): string
    {
        return 'website';
    }

    // ============================================================
    // CRAWL
    // ============================================================

    /**
     * @param string $sourceRef The dealer's website base URL, e.g.
     *                          "https://dealer.co.za".
     * @param float|null $deadlineTs Optional microtime(true)-style
     *        wall-clock deadline. When omitted, defaults to
     *        DEFAULT_TIME_BUDGET_SECONDS from the moment this method is
     *        called — so crawlDealership() is always internally bounded
     *        even if called standalone (outside sync()). sync() computes
     *        one deadline covering the WHOLE call (crawl + image
     *        re-hosting) and passes it in here so time spent crawling
     *        correctly reduces what's left for downloading images
     *        afterward, rather than each phase getting its own separate
     *        budget and doubling the worst case.
     * @return array<int, array<string, mixed>> Raw schema.org nodes, each
     *         annotated with '_source_url' for traceability/debugging.
     */
    public function crawlDealership(string $sourceRef, ?float $deadlineTs = null): array
    {
        $deadline = $deadlineTs ?? (microtime(true) + self::DEFAULT_TIME_BUDGET_SECONDS);

        $base = $this->normalizeBaseUrl($sourceRef);
        $host = parse_url($base, PHP_URL_HOST);

        if (!$host || !$this->isPubliclyRoutable($host)) {
            throw new RuntimeException(
                "Refusing to crawl \"{$sourceRef}\" — not a public website address."
            );
        }

        $this->loadRobotsTxt($base);
        if (!$this->isAllowedByRobots($base, '/')) {
            throw new RuntimeException(
                "This site's robots.txt disallows automated access for our importer. " .
                "Ask the dealer to allow user-agent \"" . self::USER_AGENT . "\", or use the CSV import instead."
            );
        }

        $candidates = $this->discoverCandidateUrls($base, $host, $deadline);

        $records = [];
        $seen    = [];   // dedup key => index into $records, so a car found
                         // on both a listing page and its own detail page
                         // keeps only the richer record.
        $fetched = 0;

        foreach ($candidates as $url) {
            if ($fetched >= self::MAX_PAGES) {
                break;
            }
            if (microtime(true) >= $deadline) {
                // Time budget reached — stop fetching further candidate
                // pages. Whatever we've already extracted still gets
                // returned and imported; a partial import is far better
                // than a request that runs long enough to 504.
                break;
            }
            if (!$this->isAllowedByRobots($base, parse_url($url, PHP_URL_PATH) ?: '/')) {
                continue;
            }

            $html = $this->fetchPage($url);
            $fetched++;
            $this->throttle($base);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractVehicleNodes($html) as $node) {
                $node['_source_url'] = $url;
                $key = $this->dedupKey($node);

                if (!isset($seen[$key])) {
                    $seen[$key] = count($records);
                    $records[]  = $node;
                    continue;
                }

                // Keep whichever version of this vehicle has more fields —
                // detail pages are typically richer than listing-card markup.
                $existingIdx = $seen[$key];
                if (count($node) > count($records[$existingIdx])) {
                    $records[$existingIdx] = $node;
                }
            }
        }

        return $records;
    }

    /**
     * Try the primary schema.org parser first; only fall through to the
     * known-CMS adapter chain (MotusShowroomParser today) when it finds
     * absolutely nothing on the page. schema.org markup, when present, is
     * more reliable and more broadly applicable than any single-platform
     * adapter, so it always wins when both would otherwise match.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractVehicleNodes(string $html): array
    {
        $nodes = $this->parser->extract($html);
        if (!empty($nodes)) {
            return $nodes;
        }

        foreach ($this->fallbackParsers as $fallback) {
            $nodes = $fallback->extract($html);
            if (!empty($nodes)) {
                return $nodes;
            }
        }

        return [];
    }

    private function normalizeBaseUrl(string $sourceRef): string
    {
        $url = trim($sourceRef);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException("Not a valid URL: {$sourceRef}");
        }
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Basic SSRF guard: refuse to crawl anything that resolves to a
     * private/loopback/link-local address. This targets a dealer's own
     * public website — it should never legitimately point at internal
     * infrastructure, so there's no functionality lost by blocking it.
     */
    private function isPubliclyRoutable(string $host): bool
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return false; // DNS resolution failed
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Sitemap first (fast, complete, and the dealer's own signal of what
     * their real inventory pages are); BFS same-host crawl as a fallback
     * for sites without one. Candidate URLs are ranked so listing/detail-
     * looking paths are fetched before generic pages, which matters once
     * MAX_PAGES is the limiting factor on a larger site.
     *
     * @return string[]
     */
    private function discoverCandidateUrls(string $base, string $host, float $deadline): array
    {
        $fromSitemap = $this->urlsFromSitemap($base, $deadline);
        if (count($fromSitemap) >= 5) {
            return $this->rankByListingKeywords($fromSitemap);
        }

        // Fallback: BFS from the homepage, same host only.
        $visited = [];
        $queue   = [[$base, 0]];
        $urls    = [];

        while (!empty($queue) && count($urls) < self::MAX_PAGES * 2) {
            if (microtime(true) >= $deadline) {
                break;
            }

            [$url, $depth] = array_shift($queue);
            $normalized = rtrim($url, '/');
            if (isset($visited[$normalized]) || $depth > self::MAX_CRAWL_DEPTH) {
                continue;
            }
            $visited[$normalized] = true;
            $urls[] = $url;

            if (count($urls) >= self::MAX_PAGES) {
                break;
            }

            $html = $this->fetchPage($url);
            $this->throttle($base);
            if ($html === null) {
                continue;
            }

            foreach ($this->extractLinks($html, $url) as $link) {
                if ($this->isSameSite($link, $host) && !isset($visited[rtrim($link, '/')])) {
                    $queue[] = [$link, $depth + 1];
                }
            }
        }

        return $this->rankByListingKeywords(array_values(array_unique($urls)));
    }

    /** @return string[] */
    private function urlsFromSitemap(string $base, float $deadline): array
    {
        foreach (['/sitemap.xml', '/sitemap_index.xml'] as $path) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $xml = $this->fetchPage($base . $path);
            if ($xml === null) {
                continue;
            }
            $urls = $this->parseSitemapXml($xml, $base, $deadline);
            if (!empty($urls)) {
                return $urls;
            }
        }
        return [];
    }

    /** @return string[] */
    private function parseSitemapXml(string $xml, string $base, float $deadline): array
    {
        $prevErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prevErrors);
        if ($doc === false) {
            return [];
        }

        $urls = [];

        // Sitemap index — recurse one level into child sitemaps.
        if (isset($doc->sitemap)) {
            foreach ($doc->sitemap as $entry) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $childUrl = (string) ($entry->loc ?? '');
                if ($childUrl === '' || !$this->isSameSite($childUrl, parse_url($base, PHP_URL_HOST))) {
                    continue;
                }
                $childXml = $this->fetchPage($childUrl);
                if ($childXml !== null) {
                    $urls = array_merge($urls, $this->parseSitemapXml($childXml, $base, $deadline));
                }
                if (count($urls) >= self::MAX_PAGES * 3) {
                    break;
                }
            }
            return $urls;
        }

        if (isset($doc->url)) {
            foreach ($doc->url as $entry) {
                $loc = (string) ($entry->loc ?? '');
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }

        return $urls;
    }

    /** @param string[] $urls @return string[] */
    private function rankByListingKeywords(array $urls): array
    {
        usort($urls, function (string $a, string $b): int {
            return $this->listingScore($b) <=> $this->listingScore($a);
        });
        return $urls;
    }

    private function listingScore(string $url): int
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $score = 0;
        foreach (self::LISTING_KEYWORDS as $kw) {
            if (str_contains($path, $kw)) {
                $score++;
            }
        }
        return $score;
    }

    /** @return string[] absolute URLs found in this page's <a href> tags */
    private function extractLinks(string $html, string $currentUrl): array
    {
        if (!preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $matches)) {
            return [];
        }
        $links = [];
        foreach ($matches[1] as $href) {
            $href = trim($href);
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $resolved = $this->resolveUrl($href, $currentUrl);
            if ($resolved !== null) {
                $links[] = $resolved;
            }
        }
        return $links;
    }

    private function resolveUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return strtok($href, '#'); // strip fragment
        }
        $baseParts = parse_url($base);
        if ($baseParts === false || empty($baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'] ?? 'https';
        $host   = $baseParts['host'];
        $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . strtok($href, '#');
        }
        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$port}" . strtok($href, '#');
        }
        // Relative path — resolve against the base path's directory.
        $basePath = rtrim(dirname($baseParts['path'] ?? '/'), '/');
        return "{$scheme}://{$host}{$port}{$basePath}/" . strtok($href, '#');
    }

    private function isSameSite(string $url, ?string $host): bool
    {
        if ($host === null) {
            return false;
        }
        $urlHost = parse_url($url, PHP_URL_HOST);
        return $urlHost !== null && strtolower($urlHost) === strtolower($host);
    }

    private function dedupKey(array $node): string
    {
        $vin = strtoupper(trim((string) ($node['vehicleIdentificationNumber'] ?? '')));
        if ($vin !== '') {
            return 'vin:' . $vin;
        }
        $name  = strtolower(trim((string) ($node['name'] ?? '')));
        $price = $this->extractPrice($node) ?? 0;
        return 'namePrice:' . $name . ':' . $price;
    }

    // ============================================================
    // HTTP (fetch, robots.txt, throttling)
    // ============================================================

    private function fetchPage(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !$this->isPubliclyRoutable($host)) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT_S,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RANGE          => '0-' . self::MAX_PAGE_BYTES,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        if (strlen($body) > self::MAX_PAGE_BYTES) {
            $body = substr($body, 0, self::MAX_PAGE_BYTES);
        }
        return $body;
    }

    private function throttle(string $base): void
    {
        $host  = parse_url($base, PHP_URL_HOST) ?: '';
        $delay = $this->robotsCache[$host]['crawl_delay'] ?? null;
        usleep($delay !== null ? max(self::MIN_DELAY_US, (int) ($delay * 1_000_000)) : self::MIN_DELAY_US);
    }

    private function loadRobotsTxt(string $base): void
    {
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        if (isset($this->robotsCache[$host])) {
            return;
        }

        $rules = ['disallow' => [], 'crawl_delay' => null];
        $body  = $this->fetchPage($base . '/robots.txt');

        if ($body !== null) {
            $rules = $this->parseRobotsTxt($body);
        }

        $this->robotsCache[$host] = $rules;
    }

    /**
     * Per the robots.txt spec, a crawler follows the rules of the single
     * MOST SPECIFIC group whose user-agent line matches it — never the
     * union of every group that happens to mention it. A naive line-by-
     * line accumulator (an earlier draft of this method) can merge a
     * wildcard group's Disallow lines with a later, more specific group's
     * lines; that fails safe (over-restrictive) rather than unsafe, but
     * it's not spec-correct and a site relying on its specific group being
     * more *permissive* than "*" would be wrongly blocked.
     *
     * Two-pass fix: parse the whole file into a list of groups first, each
     * with its user-agent tokens and rules; then pick the best-matching
     * group (an exact/substring match for our UA, else "*") and return
     * only that group's rules.
     *
     * @return array{disallow: string[], crawl_delay: float|null}
     */
    private function parseRobotsTxt(string $body): array
    {
        $groups        = []; // list of ['agents' => string[], 'disallow' => string[], 'crawl_delay' => ?float]
        $current       = null;
        $sawUserAgent  = false; // whether the current group has started collecting agent lines

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'user-agent') {
                // A new User-agent line starts a new group UNLESS the
                // previous line was also a User-agent line (consecutive
                // UA lines share one group, per spec).
                if ($current === null || !$sawUserAgent) {
                    $current = ['agents' => [], 'disallow' => [], 'crawl_delay' => null];
                    $groups[] = &$current;
                }
                $current['agents'][] = strtolower($value);
                $sawUserAgent = true;
                continue;
            }

            $sawUserAgent = false;
            if ($current === null) {
                continue; // rule line before any User-agent line — malformed, ignore
            }

            if ($key === 'disallow' && $value !== '') {
                $current['disallow'][] = $value;
            } elseif ($key === 'crawl-delay') {
                $current['crawl_delay'] = (float) $value;
            }
        }
        unset($current);

        $ourUa = strtolower(self::USER_AGENT);

        // Prefer a group with an explicit, non-wildcard match for our UA.
        foreach ($groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent !== '*' && str_contains($ourUa, $agent)) {
                    return ['disallow' => $group['disallow'], 'crawl_delay' => $group['crawl_delay']];
                }
            }
        }
        // Otherwise fall back to the wildcard group, if any.
        foreach ($groups as $group) {
            if (in_array('*', $group['agents'], true)) {
                return ['disallow' => $group['disallow'], 'crawl_delay' => $group['crawl_delay']];
            }
        }
        return ['disallow' => [], 'crawl_delay' => null];
    }

    private function isAllowedByRobots(string $base, string $path): bool
    {
        $host  = parse_url($base, PHP_URL_HOST) ?: '';
        $rules = $this->robotsCache[$host]['disallow'] ?? [];
        foreach ($rules as $disallowed) {
            if ($disallowed === '/') {
                return false;
            }
            if ($disallowed !== '' && str_starts_with($path, $disallowed)) {
                return false;
            }
        }
        return true;
    }

    // ============================================================
    // PARSE — schema.org node -> canonical vehicle shape
    // ============================================================

    public function parseVehicle(array $rawRow): array
    {
        $name = trim((string) ($rawRow['name'] ?? ''));

        [$make, $model, $year] = $this->splitMakeModelYear($rawRow, $name);

        if ($make === '' || $model === '' || $year === null) {
            throw new InvalidArgumentException(
                "Could not determine make/model/year from this page's markup" .
                ($rawRow['_source_url'] ?? '' ? " ({$rawRow['_source_url']})" : '')
            );
        }

        $price = $this->extractPrice($rawRow);
        if ($price === null || $price <= 0) {
            throw new InvalidArgumentException('Missing or invalid price in vehicle markup.');
        }

        $mileage = $this->extractMileage($rawRow);
        $vin     = strtoupper(trim((string) ($rawRow['vehicleIdentificationNumber'] ?? '')));
        if ($vin !== '' && strlen($vin) !== 17) {
            $vin = ''; // malformed — drop rather than fail the whole row, same policy as CsvImporter
        }

        $stockNumber = trim((string) ($rawRow['sku'] ?? $rawRow['productID'] ?? ''));

        if ($vin === '' && $stockNumber === '') {
            // No VIN and no sku/productID anywhere in this page's markup —
            // common for dealer sites that publish schema.org data without
            // either. Without SOME dedup key, findExistingCar() can never
            // match this car back to itself on a later sync(), so every
            // re-crawl of the same site would insert a fresh duplicate
            // instead of updating the existing row (caught by the
            // integration test in the original design pass — the Polo in
            // that test had neither field and kept duplicating on re-sync).
            //
            // Fall back to a synthetic key derived from the vehicle's own
            // detail-page URL plus its name: the URL alone isn't safe if a
            // single listing page embeds several vehicles' markup (they'd
            // share a _source_url and collide onto one row); combining it
            // with the name disambiguates that case while still being
            // stable if only the price changes between runs, which is the
            // most common thing to change about a real listing.
            $sourceUrl = (string) ($rawRow['_source_url'] ?? '');
            if ($sourceUrl !== '') {
                $stockNumber = 'web-' . substr(md5($sourceUrl . '|' . strtolower($name)), 0, 40);
            }
        }
        if (strlen($stockNumber) > 60) {
            $stockNumber = substr($stockNumber, 0, 60);
        }

        $images = $this->extractImageUrls($rawRow);

        return [
            'make'                => $make,
            'model'               => $model,
            'year'                => $year,
            'vin'                 => $vin !== '' ? $vin : null,
            'dealer_stock_no'     => $stockNumber !== '' ? $stockNumber : null,
            'price'               => $price,
            'mileage'             => $mileage,
            'condition_type'      => $this->normalizeCondition($this->extractItemCondition($rawRow)),
            'body_type'           => $this->normalizeBodyType((string) ($rawRow['bodyType'] ?? '')) ?: null,
            'colour'              => trim((string) ($rawRow['color'] ?? '')) ?: null,
            'transmission'        => $this->normalizeTransmission((string) ($rawRow['vehicleTransmission'] ?? '')) ?: null,
            'fuel_type'           => $this->normalizeFuelType((string) ($rawRow['fuelType'] ?? '')) ?: null,
            'drivetrain'          => $this->normalizeDrivetrain((string) ($rawRow['driveWheelConfiguration'] ?? '')) ?: null,
            'description'         => trim((string) ($rawRow['description'] ?? '')) ?: null,
            'image_urls'          => $images,
            'source_url'          => (string) ($rawRow['_source_url'] ?? ''),

            // Richer detail fields — all optional. Not part of the
            // schema.org Vehicle vocabulary SchemaOrgVehicleParser reads,
            // so these will simply be absent (null) for most sites. Added
            // specifically so extraction strategies like MotusShowroomParser
            // (which DOES surface this level of detail) don't have it
            // silently discarded — see that file's docblock. Uses the same
            // parseSmallUint()/parseNumber() helpers CsvImporter uses, via
            // the shared NormalizesVehicleFields trait, so a "1398 cc" or
            // "55 kW" string parses identically regardless of which
            // importer encountered it.
            'engine_capacity_cc'  => $this->parseSmallUint((string) ($rawRow['engineCapacityRaw'] ?? '')),
            'cylinders'           => $this->parseSmallUint((string) ($rawRow['cylinders'] ?? '')),
            'power_kw'            => $this->parseSmallUint((string) ($rawRow['powerKwRaw'] ?? '')),
            'gears'               => $this->parseSmallUint((string) ($rawRow['gearsRaw'] ?? '')),
            'co2_emissions_gkm'   => $this->parseSmallUint((string) ($rawRow['co2EmissionsRaw'] ?? '')),
            'doors'               => $this->parseSmallUint((string) ($rawRow['doors'] ?? '')),
            'seats'               => $this->parseSmallUint((string) ($rawRow['seats'] ?? '')),
        ];
    }

    /** @return array{0:string,1:string,2:?int} */
    private function splitMakeModelYear(array $node, string $name): array
    {
        $make = '';
        if (isset($node['brand'])) {
            $make = is_array($node['brand']) ? (string) ($node['brand']['name'] ?? '') : (string) $node['brand'];
        }
        $model = trim((string) ($node['model'] ?? ''));
        $model = is_array($node['model'] ?? null) ? (string) ($node['model']['name'] ?? '') : $model;

        $year = null;
        $yearRaw = (string) ($node['vehicleModelDate'] ?? $node['modelDate'] ?? $node['dateVehicleFirstRegistered'] ?? '');
        if (preg_match('/(19|20)\d{2}/', $yearRaw, $m)) {
            $year = (int) $m[0];
        }

        // Fallback: parse "2023 Toyota Corolla Cross 1.8 XR" style names
        // when brand/model/year properties are missing or incomplete.
        if (($make === '' || $model === '' || $year === null) && $name !== '') {
            if ($year === null && preg_match('/\b(19|20)\d{2}\b/', $name, $m)) {
                $year = (int) $m[0];
            }
            $rest = trim(preg_replace('/\b(19|20)\d{2}\b/', '', $name));
            $parts = preg_split('/\s+/', $rest, 3);
            if ($make === '' && !empty($parts[0])) {
                $make = $parts[0];
            }
            if ($model === '' && !empty($parts[1])) {
                $model = trim(($parts[1] ?? '') . ' ' . ($parts[2] ?? ''));
            }
        }

        $currentYearPlus1 = (int) date('Y') + 1;
        if ($year !== null && ($year < 1990 || $year > $currentYearPlus1)) {
            $year = null;
        }

        return [trim($make), trim($model), $year];
    }

    private function extractPrice(array $node): ?float
    {
        $offers = $node['offers'] ?? null;
        if (is_array($offers) && array_is_list($offers)) {
            $offers = $offers[0] ?? null;
        }
        $priceRaw = is_array($offers) ? ($offers['price'] ?? null) : ($node['price'] ?? null);
        if ($priceRaw === null) {
            return null;
        }
        return $this->parseNumber((string) $priceRaw);
    }

    private function extractMileage(array $node): ?int
    {
        $raw = $node['mileageFromOdometer'] ?? null;
        if (is_array($raw)) {
            $raw = $raw['value'] ?? null;
        }
        if ($raw === null) {
            return null;
        }
        $n = $this->parseNumber((string) $raw);
        return $n !== null ? (int) $n : null;
    }

    private function extractItemCondition(array $node): string
    {
        $raw = (string) ($node['itemCondition'] ?? '');
        return match (true) {
            str_contains($raw, 'NewCondition')                        => 'new',
            str_contains($raw, 'UsedCondition')                        => 'used',
            str_contains($raw, 'RefurbishedCondition')                 => 'demo',
            default                                                     => 'used',
        };
    }

    /** @return string[] */
    private function extractImageUrls(array $node): array
    {
        $raw = $node['image'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $urls = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $urls[] = $item;
            } elseif (is_array($item) && !empty($item['url'])) {
                $urls[] = (string) $item['url'];
            }
        }
        return array_values(array_filter(array_unique($urls), fn($u) => preg_match('#^https?://#i', $u)));
    }

    // ============================================================
    // SYNC / PERSISTENCE — mirrors CsvImporter's dedup + insert/update
    // ============================================================

    public function sync(int $dealerId, string $sourceRef, array $options = []): array
    {
        $initiatedBy            = $options['initiated_by']            ?? null;
        $defaultCommissionType  = $options['default_commission_type'] ?? 'fixed';
        $defaultCommissionValue = isset($options['default_commission_value'])
            ? (float) $options['default_commission_value'] : null;
        $execId                 = $options['exec_id'] ?? null;

        // ONE deadline for the whole call — crawling and image re-hosting
        // both draw from the same budget, so time spent crawling reduces
        // what's left for downloading images rather than each phase
        // getting its own separate allowance (which would double the
        // worst case). See DEFAULT_TIME_BUDGET_SECONDS for why this
        // exists at all — this is the fix for requests 504ing at the
        // reverse-proxy layer regardless of PHP's own set_time_limit().
        $timeBudget = (float) ($options['time_budget_seconds'] ?? self::DEFAULT_TIME_BUDGET_SECONDS);
        $deadline   = microtime(true) + $timeBudget;

        $importId = $this->createImportRun($dealerId, $sourceRef, $initiatedBy);

        $errors = [];
        $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $rows = $this->crawlDealership($sourceRef, $deadline);
        } catch (Throwable $e) {
            $this->failImportRun($importId, $e->getMessage());
            throw $e;
        }

        if (empty($rows)) {
            $this->completeImportRun($importId, 0, $counts, []);
            return [
                'import_id' => $importId, 'total' => 0, 'imported' => 0,
                'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [],
                'time_boxed' => microtime(true) >= $deadline,
            ];
        }

        foreach ($rows as $i => $rawRow) {
            try {
                $vehicle = $this->parseVehicle($rawRow);

                $commType  = in_array($defaultCommissionType, ['fixed', 'percentage'], true) ? $defaultCommissionType : 'fixed';
                $commValue = $defaultCommissionValue;
                if ($commValue === null || $commValue <= 0) {
                    throw new InvalidArgumentException('No default_commission_value option supplied.');
                }
                if ($commType === 'percentage' && $commValue > 30) {
                    throw new InvalidArgumentException('Percentage commission must be 30% or less.');
                }

                $existing = $this->findExistingCar($dealerId, $vehicle['vin'], $vehicle['dealer_stock_no']);

                if ($existing !== null) {
                    $this->updateCar($existing, $dealerId, $vehicle, $commType, $commValue, $importId, $deadline);
                    $counts['updated']++;
                } else {
                    $this->insertCar($dealerId, $vehicle, $commType, $commValue, $importId, $execId, $deadline);
                    $counts['imported']++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                $errors[] = ['row' => $i + 1, 'message' => $e->getMessage()];
            }
        }

        $this->completeImportRun($importId, count($rows), $counts, $errors);

        writeAuditLog(
            'vehicle_import.completed',
            'vehicle_import',
            $importId,
            null,
            ['source' => $this->getSourceName(), 'dealer_id' => $dealerId, 'total' => count($rows), ...$counts],
            $initiatedBy
        );

        return [
            'import_id'  => $importId,
            'total'      => count($rows),
            'imported'   => $counts['imported'],
            'updated'    => $counts['updated'],
            'skipped'    => $counts['skipped'],
            'failed'     => $counts['failed'],
            'errors'     => $errors,
            // If wall-clock time is already past the deadline by the time
            // we're done, something along the way (crawling, an image
            // batch, or both) had to stop early. Surfaced to the UI so
            // the dealer knows re-running may pick up more — see
            // import-website.php's handling of this flag.
            'time_boxed' => microtime(true) >= $deadline,
        ];
    }

    // findExistingCar, hydrateExistingCarRow, and rehostImages now live in
    // PersistsVehicleImports (used above) — identical dedup/re-host logic
    // as CsvImporter, so the two can never disagree on what counts as
    // "the same car" or how images get re-hosted.

    private function insertCar(int $dealerId, array $v, string $commType, float $commValue, int $importId, ?int $execId, ?float $deadlineTs = null): void
    {
        $uuid   = generateUuidV4();
        $slug   = $this->generateUniqueSlug($dealerId, $v['year'], $v['make'], $v['model'], $v['colour']);
        $images = $this->rehostImages($uuid, $v['image_urls'], [], $deadlineTs);

        $this->pdo->prepare("
            INSERT INTO cars
                (uuid, dealer_id, uploaded_by_exec_id, slug, make, model, year, price,
                 mileage, condition_type, body_type, colour, transmission, fuel_type,
                 drivetrain, description, vin,
                 engine_capacity_cc, cylinders, power_kw, gears, co2_emissions_gkm, doors, seats,
                 commission_type, commission_value,
                 image_urls, source_image_urls, status,
                 source_platform, dealer_stock_no, import_id,
                 last_imported_at, import_raw_payload,
                 created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'active',
                    ?, ?, ?, NOW(), ?, NOW(), NOW())
        ")->execute([
            $uuid, $dealerId, $execId, $slug,
            $v['make'], $v['model'], $v['year'], $v['price'],
            $v['mileage'], $v['condition_type'], $v['body_type'], $v['colour'],
            $v['transmission'], $v['fuel_type'], $v['drivetrain'], $v['description'], $v['vin'],
            $v['engine_capacity_cc'], $v['cylinders'], $v['power_kw'], $v['gears'],
            $v['co2_emissions_gkm'], $v['doors'], $v['seats'],
            $commType, $commValue,
            json_encode($images['image_urls'] ?? []),
            json_encode($images['source_image_urls'] ?? []),
            // Bound to getSourceName() instead of a literal 'website'.
            $this->getSourceName(),
            $v['dealer_stock_no'], $importId,
            json_encode(['source_url' => $v['source_url']]),
        ]);
    }

    private function updateCar(array $existing, int $dealerId, array $v, string $commType, float $commValue, int $importId, ?float $deadlineTs = null): void
    {
        $carId = $existing['id'];
        $uuid  = $existing['uuid'];

        $images       = $this->rehostImages($uuid, $v['image_urls'], $existing['source_image_urls'], $deadlineTs);
        $updateImages = $images !== null;

        $sql = "
            UPDATE cars SET
                make = ?, model = ?, year = ?, price = ?, mileage = ?,
                condition_type = ?, body_type = ?, colour = ?, transmission = ?,
                fuel_type = ?, drivetrain = ?, description = ?,
                engine_capacity_cc = ?, cylinders = ?, power_kw = ?, gears = ?,
                co2_emissions_gkm = ?, doors = ?, seats = ?,
                commission_type = ?, commission_value = ?,
                " . ($updateImages ? "image_urls = ?, source_image_urls = ?," : "") . "
                source_platform = ?,
                dealer_stock_no = COALESCE(?, dealer_stock_no),
                import_id = ?, last_imported_at = NOW(), import_raw_payload = ?,
                updated_at = NOW()
            WHERE id = ? AND dealer_id = ?
        ";

        $params = [
            $v['make'], $v['model'], $v['year'], $v['price'], $v['mileage'],
            $v['condition_type'], $v['body_type'], $v['colour'], $v['transmission'],
            $v['fuel_type'], $v['drivetrain'], $v['description'],
            $v['engine_capacity_cc'], $v['cylinders'], $v['power_kw'], $v['gears'],
            $v['co2_emissions_gkm'], $v['doors'], $v['seats'],
            $commType, $commValue,
        ];
        if ($updateImages) {
            $params[] = json_encode($images['image_urls']);
            $params[] = json_encode($images['source_image_urls']);
        }
        // Bound to getSourceName() instead of a literal 'website'.
        $params[] = $this->getSourceName();
        $params[] = $v['dealer_stock_no'];
        $params[] = $importId;
        $params[] = json_encode(['source_url' => $v['source_url']]);
        $params[] = $carId;
        $params[] = $dealerId;

        $this->pdo->prepare($sql)->execute($params);
    }

    // generateUniqueSlug and the vehicle_imports lifecycle methods
    // (createImportRun/completeImportRun/failImportRun) now live in
    // PersistsVehicleImports (used above). createImportRun there stamps
    // source_platform via $this->getSourceName() automatically.
}
