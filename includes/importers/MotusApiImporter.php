<?php
/**
 * SalesDesk — Motus platform "VLPData" importer.
 *
 * Reuses PersistsVehicleImports (dedup, slug generation, vehicle_imports
 * logging) so this behaves identically to every other importer in that
 * respect. Image handling is DELIBERATELY simpler than CsvImporter/
 * WebsiteImporter — see "IMAGE STRATEGY" below.
 *
 * WHAT THIS ACTUALLY DOES:
 *   Motus-platform dealer sites (motusvw.co.za confirmed; likely other
 *   motus*.co.za brand sites sharing the same "Digital Dealer" platform)
 *   render their vehicle grid client-side from a JS array referenced as
 *   `const VLPData = JSON.parse(localStorage.getItem(...))`. The listing
 *   page that ORIGINALLY populates that value is the target of this
 *   importer's bulk crawl.
 *
 * TWO DATA SOURCES, DELIBERATELY COMBINED:
 *   1. VLPData (the bulk listing array) — thin: brand, model, price,
 *      year, mileage, colour, transmission, fuel type, ONE cover photo,
 *      stock id, condition, variant. Confirmed against a real 810-vehicle
 *      payload across 10 dealerships. Fast to fetch (one request for the
 *      whole dealership).
 *   2. Each vehicle's own detail page — richer: engine capacity,
 *      cylinders, power output, CO2, doors, seats, dealer comments/
 *      features text, and the FULL photo gallery (VLPData's `image`
 *      field is only ever a single cover photo). MotusShowroomParser
 *      extracts this from the rendered "Additional Specifications"
 *      section — see that class's docblock for the real markup shape
 *      this was built and tested against.
 *   parseVehicle() maps (1); sync() now also fetches (2) per vehicle
 *   (budget permitting) via mergeDetailPageSpecs() below, rather than
 *   throwing away everything except the image list the way an earlier
 *   version of this class did.
 *
 * FIELDS THAT WILL NEVER BE POPULATED FROM MOTUS, BY DESIGN NOT OVERSIGHT:
 *   VIN, mm_code, torque_nm, warranty_type/expiry, service_plan_expiry.
 *   Confirmed directly against real Motus detail-page markup — none of
 *   these fields exist anywhere on the page. A dealer site that DOES
 *   publish them (e.g. via its own schema.org markup, handled by
 *   WebsiteImporter/SchemaOrgVehicleParser instead) will naturally fill
 *   more columns; that's a difference in what the source site publishes,
 *   not something this importer is failing to extract. These stay NULL
 *   here exactly as CsvImporter leaves them NULL for a source CSV that
 *   simply doesn't have that column.
 *
 * IMAGE STRATEGY — CHANGED FROM DOWNLOAD/RE-HOST TO DIRECT URL STORAGE:
 *   Earlier versions of this class ran every image through
 *   ImageRehostService (download -> validate -> save under
 *   /uploads/cars/{uuid}/ -> point cars.image_urls at our own domain),
 *   same as CsvImporter/WebsiteImporter. This class now stores the
 *   source CDN URLs directly in cars.image_urls instead — no download,
 *   no local copy, no ImageRehostService dependency.
 *   Trade-offs, so this is a deliberate choice and not an oversight:
 *     + Much simpler and faster (no per-image HTTP fetch, no disk I/O).
 *     + No dependency on stock-images.cdn.motus.cars / cdn.cmscloud.co.za
 *       being reachable from OUR server at import time.
 *     - If the dealer's listing is later removed or the vehicle sells,
 *       the hotlinked image can go dead (previously worked around by
 *       having our own permanent copy).
 *     - VLPData's own cover-photo CDN (stock-images.cdn.motus.cars) was
 *       confirmed to return HTTP 403 to requests with no Referer header.
 *       That check applied to OUR SERVER downloading the image — a browser
 *       loading the hotlinked <img src> directly sends whatever Referer
 *       the browser itself generates (typically our own domain), which
 *       we do not control from PHP. If that CDN — or cdn.cmscloud.co.za,
 *       the gallery CDN used on detail pages — enforces the same
 *       referer check against ordinary browser requests, hotlinked
 *       images could fail to display for site visitors. This has not
 *       been confirmed either way; worth spot-checking a live imported
 *       listing after this change ships, and reverting to
 *       ImageRehostService for this importer if it turns out to be a
 *       real problem.
 */

require_once __DIR__ . '/InventoryImporterInterface.php';
require_once __DIR__ . '/NormalizesVehicleFields.php';
require_once __DIR__ . '/PersistsVehicleImports.php';
require_once __DIR__ . '/MotusShowroomParser.php';
require_once __DIR__ . '/../functions.php';

class MotusApiImporter implements InventoryImporterInterface
{
    use NormalizesVehicleFields;
    use PersistsVehicleImports;

    private const REQUEST_TIMEOUT_S = 10;
    private const MAX_PAGE_BYTES     = 5 * 1024 * 1024; // 5MB — VLPData for a large dealer could be sizeable
    /**
     * CHANGED from a self-identifying bot UA ("SalesDeskImporter/1.0
     * (+https://salesdesk.co.za)") to a browser-shaped string. Confirmed
     * via a live test (curl, from the actual production host, against
     * this exact site) that this site is Cloudflare-fronted, and that a
     * plain browser UA + Referer got a clean HTTP 200 with real page
     * content — while this class's own fetchPage(), sending the old
     * bot-identifying UA with no Referer at all, is the most likely
     * explanation for specs/gallery silently never arriving in practice.
     * This is a same-site fetch the dealer principal has explicitly
     * confirmed ownership of and consented to (see the "confirm_ownership"
     * checkbox in app/dealer/import-motus.php) — not scraping a third
     * party without permission — so presenting a browser-shaped UA to get
     * past basic bot-fight-mode heuristics is a pragmatic trade-off, not
     * a deceptive one.
     */
    private const USER_AGENT         = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    /**
     * Wall-clock budget (seconds) for the whole sync() call — fetching
     * the listing page plus one detail-page fetch per vehicle (for specs
     * + gallery). No longer needs to budget for image downloads (see
     * IMAGE STRATEGY above), but the per-vehicle detail-page fetch is
     * still the dominant cost, so this stays conservative.
     */
    private const DEFAULT_TIME_BUDGET_SECONDS = 35;

    /**
     * Courtesy delay before each per-vehicle detail-page fetch. See the
     * usage site in sync() for why this exists — real dealer sites are
     * often fronted by bot-protection (Cloudflare etc.) that can start
     * blocking or challenging rapid unthrottled sequential requests after
     * only a handful of vehicles, which silently degrades every remaining
     * vehicle in the run to VLPData-only. 300ms is a deliberately modest
     * floor — enough to look like ordinary traffic, not so much that a
     * 40-vehicle dealer blows the whole time budget on sleeping alone.
     */
    private const DETAIL_PAGE_DELAY_US = 300_000;

    private PDO $pdo;
    private MotusShowroomParser $showroomParser;

    public function __construct(?PDO $pdo = null, ?MotusShowroomParser $showroomParser = null)
    {
        $this->pdo            = $pdo ?? Database::getInstance();
        $this->showroomParser = $showroomParser ?? new MotusShowroomParser();
    }

    public function getSourceName(): string
    {
        return 'motus';
    }

    /**
     * TEMPORARY DIAGNOSTIC — writes to both error_log() (in case it
     * becomes visible later, or on a different host) AND a plain file
     * next to this class, since error_log() output has been confirmed
     * invisible on the current production host across several rounds of
     * debugging. This makes a real live sync() run's behavior directly
     * readable by opening motus-debug.log in a browser, no server log
     * access required.
     *
     * REMOVE OR BLOCK THIS FILE FROM WEB ACCESS once debugging is done —
     * it will contain vehicle stock numbers and dealer URLs, which is low
     * sensitivity but still shouldn't sit in a permanently public path
     * indefinitely.
     */
    private function debugLog(string $message): void
    {
        error_log($message);
        @file_put_contents(
            __DIR__ . '/motus-debug.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @param string $sourceRef The dealer's showroom LISTING page, e.g.
     *                          "https://www.motusvw.co.za/midrand/showroom/"
     *                          — not an individual vehicle detail page.
     */
    public function crawlDealership(string $sourceRef, ?float $deadlineTs = null): array
    {
        $deadline = $deadlineTs ?? (microtime(true) + self::DEFAULT_TIME_BUDGET_SECONDS);

        $host = parse_url($sourceRef, PHP_URL_HOST);
        if (!$host || !$this->isPubliclyRoutable($host)) {
            throw new RuntimeException(
                "Refusing to fetch \"{$sourceRef}\" — not a public website address."
            );
        }

        $scheme    = parse_url($sourceRef, PHP_URL_SCHEME) ?: 'https';
        $homepage  = "{$scheme}://{$host}/";
        $html = $this->fetchPage($sourceRef, $homepage);
        if ($html === null) {
            throw new RuntimeException("Could not fetch \"{$sourceRef}\".");
        }

        $vehicles = $this->extractVlpData($html);

        if (empty($vehicles)) {
            // No bulk VLPData array on this page — it may still be a
            // perfectly valid target if $sourceRef is actually a SINGLE
            // vehicle's own detail-page permalink (e.g.
            // ".../midrand/showroom/625-vwmdf893255/0/") rather than the
            // branch's "/showroom/" LISTING page. MotusShowroomParser
            // already knows how to read exactly that page shape (it's
            // the same parser mergeDetailPageSpecs() uses for enrichment
            // below) — so try that before giving up entirely.
            $detailNode = $this->showroomParser->extract($html);
            if (!empty($detailNode)) {
                $node                    = $detailNode[0];
                $node['_is_detail_node'] = true; // sync() branches on this
                $node['_source_url']     = $sourceRef;
                return [$node];
            }

            throw new RuntimeException(
                "No VLPData array found in \"{$sourceRef}\", and it also couldn't be parsed as a single " .
                "vehicle detail page. If this is meant to be a branch's stock listing, it most likely " .
                "populates that data via an asynchronous API call after the page loads, rather than " .
                "embedding it in the initial HTML — in which case this extraction approach cannot work " .
                "as written. To confirm and fix: open this URL in a browser, DevTools -> Network -> " .
                "Fetch/XHR, reload, and find the request that returns the vehicle array as JSON. That " .
                "request's URL and response shape are what crawlDealership() actually needs to target. " .
                "If this was meant to be a single vehicle's own page instead, double-check the URL is a " .
                "real, currently-live vehicle permalink."
            );
        }

        $scheme = parse_url($sourceRef, PHP_URL_SCHEME) ?: 'https';
        foreach ($vehicles as &$v) {
            if (!is_array($v)) {
                continue;
            }
            $v['_source_ref'] = $sourceRef;
            $v['_base_url']   = "{$scheme}://{$host}";
        }
        unset($v);

        return array_values(array_filter($vehicles, 'is_array'));
    }

    /**
     * Extract and parse the `const VLPData = [...];` array embedded in
     * the page. See prior revision's docblock for the confirmed JS-literal
     * quirks this repairs (single-quoted mileagePretty, "X" || "Y"
     * fallback expressions, scientific-notation stockId).
     * @return array<int, array<string, mixed>>
     */
    private function extractVlpData(string $html): array
    {
        if (!preg_match('/const\s+VLPData\s*=\s*(\[.*?\]);/s', $html, $matches)) {
            return [];
        }

        $jsonStr = trim($matches[1]);
        if ($jsonStr === '' || $jsonStr === '[]') {
            return [];
        }

        $repaired = $this->repairVlpDataLiteral($jsonStr);
        $data     = json_decode($repaired, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                'Found a VLPData array in the page but could not parse it as JSON, even after repairing the ' .
                'known JS-literal quirks (single-quoted mileagePretty, "X" || "Y" fallback expressions, ' .
                'trailing commas). JSON error: ' . json_last_error_msg() . '. The page may have changed shape ' .
                'since this was last verified — capture a fresh sample (view-source, search for "const VLPData") ' .
                'so the repair can be corrected against it.'
            );
        }

        foreach ($data as &$row) {
            if (is_array($row) && isset($row['stockId'])) {
                $row['stockId'] = $this->normalizeStockId((string) $row['stockId']);
            }
        }
        unset($row);

        return $data;
    }

    private function repairVlpDataLiteral(string $raw): string
    {
        $repaired = preg_replace_callback(
            '/:\s*\'((?:\\\\.|[^\'\\\\])*)\'/',
            static function (array $m): string {
                $inner = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
                return ': "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $inner) . '"';
            },
            $raw
        );

        $repaired = preg_replace_callback(
            '/"((?:\\\\.|[^"\\\\])*)"\s*\|\|\s*"((?:\\\\.|[^"\\\\])*)"/',
            static function (array $m): string {
                $chosen = $m[1] !== '' ? $m[1] : $m[2];
                return '"' . $chosen . '"';
            },
            $repaired
        );

        $repaired = preg_replace('/,\s*([\]}])/', '$1', $repaired);

        return $repaired;
    }

    private function normalizeStockId(string $raw): string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?e[+\-]?\d+$/i', trim($raw))) {
            return (string) (int) round((float) $raw);
        }
        return $raw;
    }

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
     * @param string      $url
     * @param string|null $referer Sent as the Referer header when
     *                             provided. Confirmed via a live test
     *                             (see USER_AGENT's docblock) that this
     *                             Cloudflare-fronted site returns a clean
     *                             200 with a browser-shaped UA + a
     *                             plausible Referer, but was returning
     *                             something fetchPage() silently treated
     *                             as failure without either. null (the
     *                             default) sends no Referer — used for
     *                             the initial listing-page fetch, where
     *                             there's no natural referring page.
     */
    protected function fetchPage(string $url, ?string $referer = null): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !$this->isPubliclyRoutable($host)) {
            return null;
        }

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT_S,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RANGE          => '0-' . self::MAX_PAGE_BYTES,
            // A browser making this same request always sends Accept /
            // Accept-Language headers too — a request missing them
            // entirely is itself a small bot-detection signal on top of
            // the UA string, so these are set unconditionally rather
            // than only alongside an explicit Referer.
            CURLOPT_HTTPHEADER      => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-ZA,en;q=0.9',
            ],
        ];
        if ($referer !== null && $referer !== '') {
            $curlOpts[CURLOPT_REFERER] = $referer;
        }
        curl_setopt_array($ch, $curlOpts);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $httpCode < 200 || $httpCode >= 300) {
            // Previously silent — this is exactly the failure that was
            // indistinguishable from "the parser found nothing." Now it
            // says specifically whether it was a transport error (SSL,
            // DNS, timeout — surfaced in $err) or a non-2xx HTTP response
            // (surfaced in $httpCode; 403/503 here often means Cloudflare
            // is still blocking this request despite the UA/Referer fix).
            $this->debugLog(
                "[MotusApiImporter] fetchPage failed for {$url} — HTTP {$httpCode}" .
                ($err !== '' ? ", curl error: {$err}" : ', no curl error (likely a non-2xx HTTP response — check for a Cloudflare challenge/block page)')
            );
            return null;
        }

        if (strlen($body) > self::MAX_PAGE_BYTES) {
            $body = substr($body, 0, self::MAX_PAGE_BYTES);
        }
        return $body;
    }

    /**
     * Maps a MotusShowroomParser node (the shape produced when
     * crawlDealership() falls back to single-detail-page mode — see that
     * method) into the same canonical vehicle shape parseVehicle() below
     * produces from VLPData. NOT a drop-in replacement for parseVehicle()
     * — the two input shapes are genuinely different (VLPData is a flat
     * bulk-listing record; this is the same schema.org-ish node shape
     * SchemaOrgVehicleParser/WebsiteImporter use), so this is its own
     * method rather than a branch inside parseVehicle().
     *
     * Fields VLPData normally supplies but a lone detail page's markup
     * doesn't carry a label for at all — condition (new/used/demo) and
     * variant/trim — are left at their honest defaults (condition_type
     * defaults to 'used' via normalizeCondition(''); variant stays null)
     * rather than guessed.
     */
    private function parseVehicleFromDetailNode(array $node, string $sourceUrl): array
    {
        $model = trim((string) ($node['model'] ?? ''));
        $name  = trim((string) ($node['name'] ?? ''));
        $year  = null;
        if (isset($node['vehicleModelDate']) && preg_match('/(19|20)\d{2}/', (string) $node['vehicleModelDate'], $m)) {
            $year = (int) $m[0];
        }

        $make = $this->extractMakeFromHeroName($name, $model, $year);

        if ($make === '' || $model === '') {
            throw new InvalidArgumentException(
                "Could not determine make/model from this detail page's markup ({$sourceUrl})."
            );
        }
        $currentYearPlus1 = (int) date('Y') + 1;
        if ($year === null || $year < 1990 || $year > $currentYearPlus1) {
            throw new InvalidArgumentException("Year out of range or missing (1990-{$currentYearPlus1}) at {$sourceUrl}.");
        }

        $priceRaw = is_array($node['offers'] ?? null) ? ($node['offers']['price'] ?? null) : null;
        $price    = $priceRaw !== null ? $this->parseNumber((string) $priceRaw) : null;
        if ($price === null || $price <= 0) {
            throw new InvalidArgumentException("Missing or invalid price in detail-page markup ({$sourceUrl}).");
        }

        $mileageRaw = $node['mileageFromOdometer']['value'] ?? null;
        $mileage    = $mileageRaw !== null ? $this->parseSmallUint((string) $mileageRaw) : null;

        $stockNumber = trim((string) ($node['sku'] ?? ''));
        if (strlen($stockNumber) > 60) {
            $stockNumber = substr($stockNumber, 0, 60);
        }

        $images = (!empty($node['image']) && is_array($node['image']))
            ? array_values(array_unique(array_filter($node['image'])))
            : [];

        return [
            'make'               => $make,
            'model'              => $model,
            'year'               => $year,
            'vin'                => null, // not present anywhere on Motus — see class docblock
            'dealer_stock_no'    => $stockNumber !== '' ? $stockNumber : null,
            'price'              => $price,
            'mileage'            => $mileage,
            // Lone detail pages carry no new/used/demo label at all
            // (confirmed — TOP_LEVEL_LABELS has no such entry), unlike
            // VLPData's explicit newUsed field. Honest default, not a guess.
            'condition_type'     => $this->normalizeCondition(''),
            'body_type'          => $this->normalizeBodyType((string) ($node['bodyType'] ?? '')) ?: null,
            'colour'             => trim((string) ($node['color'] ?? '')) ?: null,
            'transmission'       => $this->normalizeTransmission((string) ($node['vehicleTransmission'] ?? '')) ?: null,
            'fuel_type'          => $this->normalizeFuelType((string) ($node['fuelType'] ?? '')) ?: null,
            'drivetrain'         => $this->normalizeDrivetrain((string) ($node['driveWheelConfiguration'] ?? '')) ?: null,
            // CORRECTED: MotusShowroomParser now captures the free-text
            // trim/variant heading that sits immediately under the H1
            // hero title (e.g. "Hatch 1.0TSI Life Manual") — see that
            // class's extract() docblock. An earlier version of this
            // method assumed no such data was ever recoverable from a
            // lone detail page's markup at all, which was wrong; it just
            // hadn't been looked for in the right place yet.
            'variant'            => trim((string) ($node['variant'] ?? '')) ?: null,
            'description'        => trim((string) ($node['description'] ?? '')) ?: null,
            'image_urls'         => $images,
            'source_url'         => $sourceUrl,
            'dealer_name'        => trim((string) ($node['dealerName'] ?? '')) ?: null,
            'engine_capacity_cc' => $this->parseSmallUint((string) ($node['engineCapacityRaw'] ?? '')),
            'cylinders'          => $this->parseSmallUint((string) ($node['cylinders'] ?? '')),
            'power_kw'           => $this->parseSmallUint((string) ($node['powerKwRaw'] ?? '')),
            'gears'              => $this->parseSmallUint((string) ($node['gearsRaw'] ?? '')),
            'co2_emissions_gkm'  => $this->parseSmallUint((string) ($node['co2EmissionsRaw'] ?? '')),
            'doors'              => $this->parseSmallUint((string) ($node['doors'] ?? '')),
            'seats'              => $this->parseSmallUint((string) ($node['seats'] ?? '')),
        ];
    }

    /**
     * MotusShowroomParser::extractHeroName() surfaces the page's H1 (e.g.
     * "2019 VOLKSWAGEN POLO VIVO") as 'name' — this recovers just the make
     * from it by stripping the already-known year prefix and the
     * already-known model suffix (both matched case-insensitively, since
     * the H1 is upper-cased but 'model' comes from the page's own
     * mixed-case "Model:" value), leaving whatever's left in the middle.
     */
    private function extractMakeFromHeroName(string $name, string $model, ?int $year): string
    {
        $t = $name;
        if ($year !== null) {
            $t = preg_replace('/^\s*' . preg_quote((string) $year, '/') . '\s*/', '', $t);
        }
        if ($model !== '') {
            $t = preg_replace('/\s*' . preg_quote($model, '/') . '\s*$/i', '', $t);
        }
        return trim($t);
    }

    // ============================================================
    // PARSE — VLPData entry -> canonical vehicle shape
    // ============================================================

    public function parseVehicle(array $rawRow): array
    {
        $make  = trim((string) ($rawRow['brand'] ?? ''));
        $model = trim((string) ($rawRow['model'] ?? ''));
        $year  = isset($rawRow['year']) && is_numeric($rawRow['year']) ? (int) $rawRow['year'] : 0;

        if ($make === '' || $model === '') {
            throw new InvalidArgumentException('Missing make or model in VLPData entry.');
        }
        $currentYearPlus1 = (int) date('Y') + 1;
        if ($year < 1990 || $year > $currentYearPlus1) {
            throw new InvalidArgumentException("Year out of range or missing (1990-{$currentYearPlus1}): {$year}");
        }

        $price = isset($rawRow['priceUnformatted']) ? $this->parseNumber((string) $rawRow['priceUnformatted']) : null;
        if ($price === null || $price <= 0) {
            throw new InvalidArgumentException('Missing or invalid price (priceUnformatted) in VLPData entry.');
        }

        $mileageRaw = isset($rawRow['mileage']) ? $this->parseNumber((string) $rawRow['mileage']) : null;
        if ($mileageRaw !== null && $mileageRaw < 0) {
            throw new InvalidArgumentException('Mileage cannot be negative.');
        }

        $relPermalink = trim((string) ($rawRow['relPermalink'] ?? ''));
        $stockNumber  = trim((string) ($rawRow['stockId'] ?? ''));
        if ($stockNumber === '' && $relPermalink !== '') {
            $stockNumber = $this->extractStockId($relPermalink);
        }
        if (strlen($stockNumber) > 60) {
            $stockNumber = substr($stockNumber, 0, 60);
        }

        // CORRECTED: cars.variant IS a real column (VARCHAR(150) — see
        // schema_consolidated.sql) — an earlier version of this class
        // wrongly assumed there was no dedicated column and folded
        // modelRange/variant into `description` instead. VLPData's
        // `variant` and `modelRange` fields were confirmed identical in
        // every sample seen so far (e.g. both "Hatch 1.0TSI Life Manual"),
        // so `variant` alone is used, falling back to `modelRange` only if
        // `variant` is ever empty. `description` is left null here and
        // filled ONLY from the detail page's actual dealer-comments text
        // by mergeDetailPageSpecs() below — VLPData has no free-text
        // description field of its own.
        $variant      = trim((string) ($rawRow['variant'] ?? ''));
        $modelRange   = trim((string) ($rawRow['modelRange'] ?? ''));
        $variantValue = $variant !== '' ? $variant : ($modelRange !== '' ? $modelRange : null);

        // Cover photo from VLPData — used as a fallback if the detail-page
        // gallery fetch (see mergeDetailPageSpecs()) doesn't happen or
        // doesn't find anything.
        $imageUrl  = trim((string) ($rawRow['image'] ?? ''));
        $imageUrls = ($imageUrl !== '' && preg_match('#^https?://#i', $imageUrl)) ? [$imageUrl] : [];

        $baseUrl   = (string) ($rawRow['_base_url'] ?? '');
        $sourceUrl = ($baseUrl !== '' && $relPermalink !== '')
            ? $baseUrl . '/' . ltrim($relPermalink, '/')
            : (string) ($rawRow['_source_ref'] ?? '');

        return [
            'make'              => $make,
            'model'             => $model,
            'year'              => $year,
            'vin'               => null, // not present anywhere on Motus — see class docblock
            'dealer_stock_no'   => $stockNumber !== '' ? $stockNumber : null,
            'price'             => $price,
            'mileage'           => $mileageRaw !== null ? (int) $mileageRaw : null,
            'condition_type'    => $this->normalizeCondition((string) ($rawRow['newUsed'] ?? '')),
            'body_type'         => $this->normalizeBodyType((string) ($rawRow['bodyType'] ?? '')) ?: null,
            'colour'            => trim((string) ($rawRow['colour'] ?? '')) ?: null,
            'transmission'      => $this->normalizeTransmission((string) ($rawRow['gearshift'] ?? '')) ?: null,
            'fuel_type'         => $this->normalizeFuelType((string) ($rawRow['fuelType'] ?? '')) ?: null,
            'drivetrain'        => null, // not present in VLPData; may be filled from detail page below
            'variant'           => $variantValue,
            'description'       => null, // filled from detail page's dealer-comments text, if any — see mergeDetailPageSpecs()
            'image_urls'        => $imageUrls,
            'source_url'        => $sourceUrl,
            // NOT a DB column — transient, used only by
            // detailPageMatchesVehicle() to verify a fetched detail page
            // actually belongs to the branch VLPData said it did, and
            // persisted into import_raw_payload (not a dedicated cars
            // column) purely so enrichNextBatch() can re-run the same
            // check later, long after this row was first inserted.
            'dealer_name'       => trim((string) ($rawRow['dealerName'] ?? '')) ?: null,

            // Filled in from the detail page by mergeDetailPageSpecs()
            // below when that fetch succeeds; left null (an honest
            // "unknown") otherwise, same as before.
            'engine_capacity_cc' => null,
            'cylinders'          => null,
            'power_kw'           => null,
            'gears'              => null,
            'co2_emissions_gkm'  => null,
            'doors'              => null,
            'seats'              => null,
        ];
    }

    /**
     * Fetches a VLPData-derived vehicle's own detail page (budget
     * permitting) and merges in its richer spec fields + full gallery via
     * mergeDetailPageSpecs(). Not used for the single-detail-node crawl
     * path (see crawlDealership()'s fallback + parseVehicleFromDetailNode())
     * — that path already has everything this method would fetch, in the
     * one page crawlDealership() already read.
     *
     * Every failure path here is logged — an earlier version silently fell
     * back to VLPData-only on ANY fetch failure with zero visibility into
     * why, which made a systemic problem (e.g. the dealer site rate-
     * limiting or bot-challenging our unthrottled per-vehicle requests)
     * indistinguishable from "there's just nothing there." Check the error
     * log after a run if specs/gallery are still missing — it will now say
     * exactly which of these three things happened.
     */
    private function enrichFromDetailPage(array $vehicle, float $deadline, string $listingUrl): array
    {
        if (microtime(true) >= $deadline) {
            $this->debugLog("[MotusApiImporter] Skipped detail-page fetch for stock #{$vehicle['dealer_stock_no']} — time budget already exhausted.");
            return $vehicle;
        }
        if ($vehicle['source_url'] === '') {
            $this->debugLog("[MotusApiImporter] No source_url resolved for stock #{$vehicle['dealer_stock_no']} — VLPData row likely missing relPermalink.");
            return $vehicle;
        }

        // Small courtesy delay before EVERY detail-page request, not just
        // between candidate pages the way WebsiteImporter throttles — a
        // real dealer site (often fronted by Cloudflare or similar) can
        // rate-limit or bot-challenge rapid unthrottled sequential requests
        // from a generic UA, which would silently degrade every single
        // vehicle in the run to VLPData-only with no error of its own (the
        // fetch just starts returning null/blocked bodies).
        usleep(self::DETAIL_PAGE_DELAY_US);

        $detailHtml = $this->fetchPage($vehicle['source_url'], $listingUrl);
        if ($detailHtml === null) {
            $this->debugLog("[MotusApiImporter] Detail-page fetch failed for stock #{$vehicle['dealer_stock_no']} ({$vehicle['source_url']}) — got no response, non-2xx, or oversized body. If this happens for EVERY vehicle in a run, suspect bot/rate-limit blocking rather than a one-off network blip.");
            return $vehicle;
        }

        $beforeMerge = $vehicle;
        $vehicle     = $this->mergeDetailPageSpecs($vehicle, $detailHtml);
        if ($vehicle === $beforeMerge) {
            $this->debugLog("[MotusApiImporter] Detail page fetched OK for stock #{$vehicle['dealer_stock_no']} ({$vehicle['source_url']}) but MotusShowroomParser found no vehicle node in it — the page's markup may not match what this parser expects (e.g. a bot-challenge/interstitial page instead of the real vehicle page).");

            // TEMPORARY DIAGNOSTIC: dump the actual raw bytes we received
            // to a file, ONCE per run (not once per vehicle — 119 full
            // page dumps would be unwieldy), so we can directly compare
            // what production's curl fetch actually receives against the
            // static HTML file this parser has already been proven to
            // handle correctly. If these two differ, that difference IS
            // the bug; if they're identical, the bug is somewhere else
            // entirely (e.g. DOMDocument behaving differently on this
            // host's libxml version).
            $dumpFile = __DIR__ . '/motus-debug-failed-page.html';
            if (!file_exists($dumpFile)) {
                @file_put_contents($dumpFile, $detailHtml);
                $this->debugLog("[MotusApiImporter] Dumped raw HTML for stock #{$vehicle['dealer_stock_no']} to {$dumpFile} (" . strlen($detailHtml) . " bytes) for comparison against the known-good static file.");
            }
        }

        return $vehicle;
    }

    /**
     * Cross-checks a freshly-fetched detail-page node against the vehicle
     * we ALREADY expected it to be, before trusting any of its data.
     *
     * WHY THIS EXISTS: confirmed via a real user report — some VLPData
     * rows are STALE, referencing vehicles that have already been sold or
     * removed from the live site. Fetching that dead relPermalink URL
     * doesn't reliably 404; Motus's site appears to fall back to SOME
     * other real, valid vehicle page instead (observed landing on a real
     * Motus Midrand vehicle regardless of which branch's stale listing
     * triggered it — the exact mechanism on Motus's side isn't something
     * we control or need to fully understand). Without this check, that
     * fallback page's genuinely real specs get silently merged onto the
     * WRONG car — a Polo's row ending up with a T-Cross's engine specs
     * and photos, which is exactly what was reported.
     *
     * CORRECTED: an earlier version only compared model name. Confirmed
     * that alone isn't strict enough — checks make, model, year, AND
     * dealer/branch name now; ALL FOUR must agree (whichever of them are
     * actually available to compare) before any data from the page is
     * trusted. A mismatch on any single field is enough to reject the
     * whole page.
     *
     * @param array $expected Must contain 'make', 'model', 'year',
     *        'dealer_name' keys (any may be null/empty if unknown).
     * @return string Empty string if everything checkable matches; a
     *         short, specific reason string otherwise (used directly in
     *         the caller's log message).
     */
    private function detailPageMismatchReason(array $expected, array $node): string
    {
        $expModel = strtolower(trim((string) ($expected['model'] ?? '')));
        $actModel = strtolower(trim((string) ($node['model'] ?? '')));
        if ($expModel === '' || $actModel === '') {
            return 'model could not be verified (missing on one side)';
        }
        $modelMatches = $expModel === $actModel || str_contains($expModel, $actModel) || str_contains($actModel, $expModel);
        if (!$modelMatches) {
            return "model mismatch: expected \"{$expected['model']}\", page showed \"" . ($node['model'] ?? '?') . "\"";
        }

        if (!empty($expected['make'])) {
            // The detail page never labels "Make:" separately — it only
            // ever appears embedded in the H1 hero title (captured as
            // 'name', e.g. "2025 VOLKSWAGEN POLO"), so this checks that
            // the expected make is AT LEAST mentioned there rather than
            // trying to isolate it precisely.
            $actualHero = strtolower(trim((string) ($node['name'] ?? '')));
            $expMake    = strtolower(trim((string) $expected['make']));
            if ($actualHero !== '' && !str_contains($actualHero, $expMake)) {
                return "make mismatch: expected \"{$expected['make']}\" not found in page title \"" . ($node['name'] ?? '?') . "\"";
            }
        }

        if (!empty($expected['year']) && isset($node['vehicleModelDate'])) {
            $actYear = (int) $node['vehicleModelDate'];
            if ($actYear > 0 && $actYear !== (int) $expected['year']) {
                return "year mismatch: expected {$expected['year']}, page showed {$actYear}";
            }
        }

        if (!empty($expected['dealer_name']) && !empty($node['dealerName'])) {
            $expDealer = strtolower(trim((string) $expected['dealer_name']));
            $actDealer = strtolower(trim((string) $node['dealerName']));
            if ($expDealer !== $actDealer) {
                return "dealer/branch mismatch: expected \"{$expected['dealer_name']}\", page showed \"{$node['dealerName']}\"";
            }
        }

        return '';
    }

    private function mergeDetailPageSpecs(array $vehicle, string $detailHtml): array
    {
        $nodes = $this->showroomParser->extract($detailHtml);
        if (empty($nodes)) {
            return $vehicle;
        }
        $node = $nodes[0];

        $mismatchReason = $this->detailPageMismatchReason(
            ['make' => $vehicle['make'], 'model' => $vehicle['model'], 'year' => $vehicle['year'], 'dealer_name' => $vehicle['dealer_name'] ?? null],
            $node
        );
        if ($mismatchReason !== '') {
            $this->debugLog(
                "[MotusApiImporter] mergeDetailPageSpecs(): MISMATCH for stock #{$vehicle['dealer_stock_no']} — {$mismatchReason}. " .
                "This usually means the source listing is stale/removed and the site fell back to a " .
                "different real vehicle. Discarding this fetch entirely rather than merging mismatched data."
            );
            return $vehicle;
        }

        foreach ([
            'engineCapacityRaw' => 'engine_capacity_cc',
            'cylinders'         => 'cylinders',
            'powerKwRaw'        => 'power_kw',
            'gearsRaw'          => 'gears',
            'co2EmissionsRaw'   => 'co2_emissions_gkm',
            'doors'             => 'doors',
            'seats'             => 'seats',
        ] as $nodeKey => $vehicleKey) {
            if ($vehicle[$vehicleKey] === null && isset($node[$nodeKey])) {
                $vehicle[$vehicleKey] = $this->parseSmallUint((string) $node[$nodeKey]);
            }
        }

        if ($vehicle['drivetrain'] === null && isset($node['driveWheelConfiguration'])) {
            $vehicle['drivetrain'] = $this->normalizeDrivetrain((string) $node['driveWheelConfiguration']) ?: null;
        }

        // VLPData's own variant/modelRange field is the authoritative
        // source when present — only fall back to the detail page's
        // heading-derived variant (see MotusShowroomParser::extract())
        // when VLPData genuinely had nothing.
        if ($vehicle['variant'] === null && !empty($node['variant'])) {
            $vehicle['variant'] = trim((string) $node['variant']);
        }

        if (!empty($node['description'])) {
            $vehicle['description'] = $vehicle['description']
                ? $vehicle['description'] . "\n\n" . $node['description']
                : $node['description'];
        }

        if (!empty($node['image']) && is_array($node['image'])) {
            $vehicle['image_urls'] = array_values(array_unique(array_filter($node['image'])));
        }

        return $vehicle;
    }

    private function extractStockId(string $relPermalink): string
    {
        $parts = array_values(array_filter(explode('/', trim($relPermalink, '/'))));
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '-' . $parts[count($parts) - 1];
        }
        return $parts[0] ?? '';
    }

    private function filterByDealerName(array $rows, string $dealerNameFilter): array
    {
        $matched = array_values(array_filter(
            $rows,
            static fn (array $r): bool => isset($r['dealerName'])
                && strcasecmp(trim((string) $r['dealerName']), $dealerNameFilter) === 0
        ));

        if (empty($matched)) {
            $foundNames = array_values(array_unique(array_map(
                static fn (array $r): string => trim((string) ($r['dealerName'] ?? '')),
                array_filter($rows, static fn ($r) => is_array($r))
            )));
            sort($foundNames);
            $foundNames = array_values(array_filter($foundNames, static fn (string $n): bool => $n !== ''));

            throw new InvalidArgumentException(
                "No vehicles found matching dealer/branch name \"{$dealerNameFilter}\". " .
                ($foundNames !== []
                    ? 'Branch names found on this page: ' . implode(', ', $foundNames) . '. Use one of these exactly (case doesn\'t matter).'
                    : 'No dealer names were found in this page\'s data at all — check the source URL is correct.')
            );
        }

        return $matched;
    }

    // ============================================================
    // SYNC / PERSISTENCE
    // ============================================================

    public function sync(int $dealerId, string $sourceRef, array $options = []): array
    {
        $this->debugLog("[MotusApiImporter] sync() STARTED — dealerId={$dealerId}, sourceRef={$sourceRef}");

        $initiatedBy            = $options['initiated_by']            ?? null;
        $defaultCommissionType  = $options['default_commission_type'] ?? 'fixed';
        $defaultCommissionValue = isset($options['default_commission_value'])
            ? (float) $options['default_commission_value'] : null;
        $execId          = $options['exec_id'] ?? null;
        $dealerNameFilter = trim((string) ($options['motus_dealer_name'] ?? ''));

        if ($dealerNameFilter === '') {
            throw new InvalidArgumentException(
                'A Motus dealer/branch name is required (e.g. "Motus VW Midrand") via the ' .
                'motus_dealer_name option. This listing page\'s data spans every branch in the group, ' .
                'not just one dealership — importing without this filter would import every other ' .
                'branch\'s stock under this account too.'
            );
        }

        $timeBudget = (float) ($options['time_budget_seconds'] ?? self::DEFAULT_TIME_BUDGET_SECONDS);
        $deadline   = microtime(true) + $timeBudget;
        $this->debugLog("[MotusApiImporter] Time budget: {$timeBudget}s, dealerNameFilter=\"{$dealerNameFilter}\"");

        $importId = $this->createImportRun($dealerId, $sourceRef, $initiatedBy);

        $errors = [];
        $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $rows = $this->crawlDealership($sourceRef, $deadline);
            $this->debugLog("[MotusApiImporter] crawlDealership() returned " . count($rows) . " row(s) before dealer-name filtering.");
            // filterByDealerName only makes sense against a bulk LISTING
            // page's rows (which span every branch in the group — see that
            // method's docblock). A single detail-page node (see
            // crawlDealership()'s fallback) is already exactly the one
            // vehicle the caller pointed at; there is no dealerName field
            // to filter on nor any other branch's stock to accidentally
            // pull in, so filtering would either no-op or wrongly reject it.
            $isSingleDetailNode = count($rows) === 1 && !empty($rows[0]['_is_detail_node']);
            if (!$isSingleDetailNode) {
                $rows = $this->filterByDealerName($rows, $dealerNameFilter);
                $this->debugLog("[MotusApiImporter] After filterByDealerName(): " . count($rows) . " row(s) remain.");
            } else {
                $this->debugLog("[MotusApiImporter] Single-detail-node mode — skipping dealer-name filter.");
            }
        } catch (Throwable $e) {
            $this->debugLog("[MotusApiImporter] crawlDealership()/filterByDealerName() THREW: " . $e->getMessage());
            $this->failImportRun($importId, $e->getMessage());
            throw $e;
        }

        // Which of this dealer's Motus-sourced cars already have detail-page
        // enrichment (using engine_capacity_cc IS NOT NULL as the signal —
        // that field can only ever be populated by the detail-page merge,
        // never by VLPData alone). WITHOUT this, sync() always starts
        // fetching from row 0 every single run — with a time budget that
        // only covers a fraction of a large inventory, re-running the
        // import forever just re-enriches the SAME early vehicles and never
        // reaches the rest. Skipping the fetch entirely for already-done
        // vehicles means each run's budget goes toward genuinely new ones,
        // so repeated runs (or a scheduled retry) make real forward
        // progress instead of spinning in place.
        $alreadyEnriched = [];
        try {
            $stmt = $this->pdo->prepare("
                SELECT dealer_stock_no FROM cars
                WHERE dealer_id = ? AND source_platform = ? AND dealer_stock_no IS NOT NULL
                  AND engine_capacity_cc IS NOT NULL
            ");
            $stmt->execute([$dealerId, $this->getSourceName()]);
            $alreadyEnriched = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
            $this->debugLog("[MotusApiImporter] " . count($alreadyEnriched) . " vehicle(s) already enriched for this dealer — their detail-page fetch will be skipped this run.");
        } catch (Throwable $e) {
            // Non-fatal — worst case we just re-enrich everything from
            // scratch this run, same as before this optimization existed.
            $this->debugLog("[MotusApiImporter] Could not look up already-enriched vehicles (non-fatal): " . $e->getMessage());
        }

        foreach ($rows as $i => $rawRow) {
            $stockForLog = $rawRow['stockId'] ?? $rawRow['sku'] ?? ('row#' . $i);
            $this->debugLog("[MotusApiImporter] --- Processing row {$i} (stock/sku: {$stockForLog}) ---");
            try {
                $skippedEnrichmentThisRun = false;

                if (!empty($rawRow['_is_detail_node'])) {
                    // Already the full, enriched detail-page node — no
                    // separate parseVehicle() + mergeDetailPageSpecs() pass
                    // needed, since crawlDealership() got everything this
                    // page has to offer in one fetch.
                    $vehicle = $this->parseVehicleFromDetailNode($rawRow, (string) $rawRow['_source_url']);
                } else {
                    $vehicle = $this->parseVehicle($rawRow);
                    $this->debugLog("[MotusApiImporter] parseVehicle() OK — make={$vehicle['make']}, model={$vehicle['model']}, source_url={$vehicle['source_url']}, image_urls count=" . count($vehicle['image_urls']));

                    if ($vehicle['dealer_stock_no'] !== null && isset($alreadyEnriched[$vehicle['dealer_stock_no']])) {
                        $this->debugLog("[MotusApiImporter] Stock #{$vehicle['dealer_stock_no']} already enriched from a previous run — skipping detail-page fetch to save budget for un-enriched vehicles.");
                        $skippedEnrichmentThisRun = true;
                    } else {
                        $vehicle = $this->enrichFromDetailPage($vehicle, $deadline, $sourceRef);
                        $this->debugLog("[MotusApiImporter] After enrichFromDetailPage() — engine_capacity_cc=" . var_export($vehicle['engine_capacity_cc'], true) . ", image_urls count=" . count($vehicle['image_urls']));
                    }
                }

                $commType  = in_array($defaultCommissionType, ['fixed', 'percentage'], true) ? $defaultCommissionType : 'fixed';
                $commValue = $defaultCommissionValue;
                if ($commValue === null || $commValue <= 0) {
                    throw new InvalidArgumentException('No default_commission_value option supplied.');
                }
                if ($commType === 'percentage' && $commValue > 30) {
                    throw new InvalidArgumentException('Percentage commission must be 30% or less.');
                }

                $existing = $this->findExistingCar($dealerId, $vehicle['vin'], $vehicle['dealer_stock_no']);
                $this->debugLog("[MotusApiImporter] findExistingCar() -> " . ($existing !== null ? "EXISTING car id={$existing['id']}" : "no match, will INSERT"));

                if ($existing !== null) {
                    // See updateCar()'s docblock: passing false here is
                    // the ONLY thing that prevents skipping the fetch
                    // above from also silently wiping out the enrichment
                    // data that fetch would otherwise have refreshed.
                    $this->updateCar($existing, $dealerId, $vehicle, $commType, $commValue, $importId, !$skippedEnrichmentThisRun);
                    $counts['updated']++;
                    $this->debugLog("[MotusApiImporter] updateCar() completed for existing id={$existing['id']}" . ($skippedEnrichmentThisRun ? ' (enrichment columns left untouched)' : '') . '.');
                } else {
                    $this->insertCar($dealerId, $vehicle, $commType, $commValue, $importId, $execId);
                    $counts['imported']++;
                    $this->debugLog("[MotusApiImporter] insertCar() completed.");
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                $errors[] = ['row' => $i + 1, 'message' => $e->getMessage()];
                $this->debugLog("[MotusApiImporter] Row {$i} FAILED: " . $e->getMessage());
            }
        }

        $this->debugLog("[MotusApiImporter] sync() FINISHED — " . json_encode($counts));

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
            'time_boxed' => microtime(true) >= $deadline,
        ];
    }

    // ============================================================
    // DISCOVER + ENRICH — the two-phase replacement for sync()'s
    // all-in-one-request approach on a BULK listing crawl.
    //
    // sync() above is still used, unchanged, for the single-permalink
    // path (crawlDealership()'s VLPData-not-found fallback) — that case
    // is inherently one vehicle, so it always finishes comfortably within
    // one request and doesn't need splitting.
    //
    // For a full branch listing (potentially 100+ vehicles), trying to
    // both discover AND enrich every vehicle within one HTTP request will
    // always eventually lose to either PHP's execution limit or a
    // reverse-proxy/gateway timeout, no matter how fast any individual
    // fetch is — that was the actual ceiling the old sync()-does-
    // everything approach kept hitting. Splitting into two independently-
    // callable phases removes that ceiling entirely:
    //
    //   discover()      — ONE listing-page fetch, no per-vehicle network
    //                      calls at all. Upserts make/model/price/mileage/
    //                      colour/condition/etc. for every vehicle found.
    //                      Always fast; safe to call every day.
    //   enrichNextBatch() — pulls a small batch of not-yet-enriched cars
    //                      (engine_capacity_cc IS NULL is the signal —
    //                      that column can ONLY ever be set by detail-page
    //                      enrichment, never by discover()) and fetches
    //                      just those vehicles' own detail pages. Designed
    //                      to be called repeatedly (e.g. by a JS polling
    //                      loop after a single "Update" click) until its
    //                      'remaining' count reaches 0.
    // ============================================================

    /**
     * Phase 1: crawl the branch listing, upsert basic fields for every
     * vehicle found. Deliberately NEVER touches enrichment columns
     * (drivetrain/description/engine specs/gallery) on an existing row —
     * see updateCar()'s $writeEnrichment=false below — so running this
     * daily can never wipe out enrichment that enrichNextBatch() already
     * filled in on a previous day.
     *
     * @return array{
     *   import_id:int, total:int, imported:int, updated:int, skipped:int,
     *   failed:int, errors:array, pending_enrichment:int
     * } pending_enrichment is how many of this dealer's Motus cars still
     *   need enrichNextBatch() to run — surface this to the UI so it knows
     *   whether to start polling at all.
     */
    public function discover(int $dealerId, string $sourceRef, array $options = []): array
    {
        $this->debugLog("[MotusApiImporter] discover() STARTED — dealerId={$dealerId}, sourceRef={$sourceRef}");

        $initiatedBy            = $options['initiated_by']            ?? null;
        $defaultCommissionType  = $options['default_commission_type'] ?? 'fixed';
        $defaultCommissionValue = isset($options['default_commission_value'])
            ? (float) $options['default_commission_value'] : null;
        $execId           = $options['exec_id'] ?? null;
        $dealerNameFilter = trim((string) ($options['motus_dealer_name'] ?? ''));

        if ($dealerNameFilter === '') {
            throw new InvalidArgumentException(
                'A Motus dealer/branch name is required (e.g. "Motus VW Midrand") via the ' .
                'motus_dealer_name option. This listing page\'s data spans every branch in the group, ' .
                'not just one dealership — importing without this filter would import every other ' .
                'branch\'s stock under this account too.'
            );
        }

        // Discovery is one page fetch + JSON parsing — no per-vehicle
        // network calls at all — so it needs nowhere near the time budget
        // enrichment did. Kept generous purely as a safety net for the
        // listing-page fetch itself, not because discovery is expected to
        // take anywhere close to this long.
        $timeBudget = (float) ($options['time_budget_seconds'] ?? 25.0);
        $deadline   = microtime(true) + $timeBudget;

        $importId = $this->createImportRun($dealerId, $sourceRef, $initiatedBy);
        $errors        = [];
        $counts        = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $seenStockNos  = [];

        try {
            $rows = $this->crawlDealership($sourceRef, $deadline);
            $this->debugLog("[MotusApiImporter] discover(): crawlDealership() returned " . count($rows) . " row(s).");
            $isSingleDetailNode = count($rows) === 1 && !empty($rows[0]['_is_detail_node']);
            if (!$isSingleDetailNode) {
                $rows = $this->filterByDealerName($rows, $dealerNameFilter);
            }
        } catch (Throwable $e) {
            $this->debugLog("[MotusApiImporter] discover() crawl/filter THREW: " . $e->getMessage());
            $this->failImportRun($importId, $e->getMessage());
            throw $e;
        }

        foreach ($rows as $i => $rawRow) {
            try {
                if (!empty($rawRow['_is_detail_node'])) {
                    $vehicle = $this->parseVehicleFromDetailNode($rawRow, (string) $rawRow['_source_url']);
                } else {
                    $vehicle = $this->parseVehicle($rawRow);
                    // Deliberately NO enrichFromDetailPage() call — that's
                    // the whole point of splitting this out. Enrichment
                    // happens later, in small batches, via
                    // enrichNextBatch().
                }

                $commType  = in_array($defaultCommissionType, ['fixed', 'percentage'], true) ? $defaultCommissionType : 'fixed';
                $commValue = $defaultCommissionValue;
                if ($commValue === null || $commValue <= 0) {
                    throw new InvalidArgumentException('No default_commission_value option supplied.');
                }
                if ($commType === 'percentage' && $commValue > 30) {
                    throw new InvalidArgumentException('Percentage commission must be 30% or less.');
                }

                if ($vehicle['dealer_stock_no'] !== null) {
                    $seenStockNos[] = $vehicle['dealer_stock_no'];
                }

                $existing = $this->findExistingCar($dealerId, $vehicle['vin'], $vehicle['dealer_stock_no']);
                if ($existing !== null) {
                    // false = never touch enrichment columns here — see
                    // this method's docblock and updateCar()'s docblock.
                    $this->updateCar($existing, $dealerId, $vehicle, $commType, $commValue, $importId, false);
                    $counts['updated']++;
                } else {
                    $this->insertCar($dealerId, $vehicle, $commType, $commValue, $importId, $execId);
                    $counts['imported']++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                $errors[] = ['row' => $i + 1, 'message' => $e->getMessage()];
                $this->debugLog("[MotusApiImporter] discover() row {$i} FAILED: " . $e->getMessage());
            }
        }

        // Mark vehicles no longer present in the source as sold — but
        // ONLY when this run crawled the FULL branch listing.
        // $isSingleDetailNode means the caller pointed discover() at one
        // specific vehicle's own permalink, not the branch's stock list —
        // $seenStockNos in that case would contain just ONE stock number,
        // and running markMissingAsSold() against that would wrongly mark
        // every OTHER vehicle in the dealer's entire catalog as sold. This
        // guard is what prevents that.
        $markedSold = 0;
        if (!$isSingleDetailNode) {
            $markedSold = $this->markMissingAsSold($dealerId, $seenStockNos);
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

        $pending = $this->countPendingEnrichment($dealerId);
        $this->debugLog("[MotusApiImporter] discover() FINISHED — " . json_encode($counts) . ", pending_enrichment={$pending}, marked_sold={$markedSold}");

        return [
            'import_id'          => $importId,
            'total'              => count($rows),
            'imported'           => $counts['imported'],
            'updated'            => $counts['updated'],
            'skipped'            => $counts['skipped'],
            'failed'             => $counts['failed'],
            'errors'             => $errors,
            'pending_enrichment' => $pending,
            'marked_sold'        => $markedSold,
        ];
    }

    /**
     * Marks vehicles as sold when they've disappeared from a full branch
     * crawl — the site's own signal that a listing is gone is simply "it's
     * no longer in VLPData," there's no separate delisted/sold flag to
     * read. Cars are never deleted (leads/commissions may reference them
     * via FK RESTRICT, and a sold car is still meaningful sales history) —
     * only transitioned active -> sold, with sold_at stamped.
     *
     * Deliberately conservative in two ways:
     *   - Only ever moves 'active' -> 'sold'. A car a dealer manually set
     *     to 'paused' is left alone — that was a deliberate choice on
     *     their part, not something a re-crawl should override.
     *   - Refuses to do anything at all if $seenStockNos is empty, since
     *     that almost certainly means something went wrong with the crawl
     *     itself rather than "every single vehicle sold at once" — acting
     *     on an empty set here would be catastrophic (every active car
     *     for this dealer marked sold in one query).
     *
     * @param string[] $seenStockNos Every dealer_stock_no found in THIS
     *        run's full branch crawl (see discover()'s $isSingleDetailNode
     *        guard — this must never be called with a partial/single-
     *        vehicle result set).
     * @return int How many cars were newly marked sold.
     */
    private function markMissingAsSold(int $dealerId, array $seenStockNos): int
    {
        if (empty($seenStockNos)) {
            $this->debugLog("[MotusApiImporter] markMissingAsSold(): seenStockNos was empty — refusing to run (would mark EVERY active car sold). This suggests the crawl itself returned no usable stock numbers.");
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($seenStockNos), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id FROM cars
            WHERE dealer_id = ? AND source_platform = ? AND status = 'active'
              AND dealer_stock_no IS NOT NULL
              AND dealer_stock_no NOT IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$dealerId, $this->getSourceName()], $seenStockNos));
        $missingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($missingIds)) {
            return 0;
        }

        $idPlaceholders = implode(',', array_fill(0, count($missingIds), '?'));
        $this->pdo->prepare("
            UPDATE cars SET status = 'sold', sold_at = NOW(), updated_at = NOW()
            WHERE id IN ({$idPlaceholders})
        ")->execute($missingIds);

        writeAuditLog(
            'vehicle_import.marked_sold',
            'dealer',
            $dealerId,
            null,
            ['source' => $this->getSourceName(), 'count' => count($missingIds), 'car_ids' => $missingIds],
            null
        );

        $this->debugLog("[MotusApiImporter] markMissingAsSold(): marked " . count($missingIds) . " car(s) as sold (no longer present in source).");

        return count($missingIds);
    }

    /**
     * Phase 2: enrich a small batch of not-yet-enriched cars. Call this
     * repeatedly (e.g. from a JS polling loop) until the returned
     * 'remaining' is 0. Each call is independent and self-contained —
     * safe to call from a short-lived AJAX request.
     *
     * @return array{processed:int, succeeded:int, failed:int, remaining:int}
     */
    public function enrichNextBatch(int $dealerId, int $batchSize = 15, float $timeBudgetSeconds = 25.0): array
    {
        $deadline = microtime(true) + $timeBudgetSeconds;

        // JSON_EXTRACT(...) IS NULL excludes cars already flagged by
        // flagEnrichmentBlocked() below — without this, a permanently
        // stale/mismatched listing would be re-fetched and re-rejected on
        // every single batch forever, wasting a fetch + throttle delay on
        // a hopeless case each time.
        $stmt = $this->pdo->prepare("
            SELECT id, uuid, dealer_stock_no, make, model, year, variant, description, import_raw_payload
            FROM cars
            WHERE dealer_id = ? AND source_platform = ? AND engine_capacity_cc IS NULL
              AND JSON_EXTRACT(import_raw_payload, '$.enrichment_blocked') IS NULL
            ORDER BY id ASC
            LIMIT " . max(1, (int) $batchSize) . "
        ");
        $stmt->execute([$dealerId, $this->getSourceName()]);
        $carRows = $stmt->fetchAll();

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;

        foreach ($carRows as $carRow) {
            if (microtime(true) >= $deadline) {
                $this->debugLog("[MotusApiImporter] enrichNextBatch(): stopped early after {$processed} vehicle(s) — time budget reached.");
                break;
            }
            $processed++;

            $payload   = json_decode($carRow['import_raw_payload'] ?? '{}', true) ?: [];
            $sourceUrl = trim((string) ($payload['source_url'] ?? ''));
            $dealerName = trim((string) ($payload['dealer_name'] ?? ''));

            if ($sourceUrl === '') {
                $this->debugLog("[MotusApiImporter] enrichNextBatch(): car id={$carRow['id']} has no stored source_url — cannot enrich, skipping.");
                $failed++;
                continue;
            }

            // Same courtesy delay as the old per-vehicle loop — still
            // needed here for the same reason (avoid looking like a burst
            // of automated requests to a Cloudflare-fronted site).
            usleep(self::DETAIL_PAGE_DELAY_US);

            $html = $this->fetchPage($sourceUrl);
            if ($html === null) {
                $this->debugLog("[MotusApiImporter] enrichNextBatch(): fetch failed for car id={$carRow['id']} ({$sourceUrl}).");
                $failed++;
                continue;
            }

            $nodes = $this->showroomParser->extract($html);
            if (empty($nodes)) {
                $this->debugLog("[MotusApiImporter] enrichNextBatch(): parser found nothing for car id={$carRow['id']} ({$sourceUrl}).");
                $failed++;
                continue;
            }

            $mismatchReason = $this->detailPageMismatchReason(
                ['make' => $carRow['make'], 'model' => $carRow['model'], 'year' => $carRow['year'], 'dealer_name' => $dealerName],
                $nodes[0]
            );
            if ($mismatchReason !== '') {
                $this->debugLog(
                    "[MotusApiImporter] enrichNextBatch(): MISMATCH for car id={$carRow['id']} (stock #{$carRow['dealer_stock_no']}) " .
                    "— {$mismatchReason} ({$sourceUrl}). Likely a stale/removed listing that fell back to a " .
                    "different real vehicle. Flagging so this isn't retried every batch — not applying any of this page's data."
                );
                $this->flagEnrichmentBlocked($carRow['id'], $payload, $mismatchReason);
                $failed++;
                continue;
            }

            $this->applyDetailNodeToExistingCar($carRow, $nodes[0]);
            $succeeded++;
            $this->debugLog("[MotusApiImporter] enrichNextBatch(): enriched car id={$carRow['id']} OK.");
        }

        $remaining = $this->countPendingEnrichment($dealerId);

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'remaining' => $remaining,
        ];
    }

    /**
     * Applies a MotusShowroomParser node directly onto an EXISTING car row
     * already fetched by enrichNextBatch() — a leaner cousin of
     * mergeDetailPageSpecs() (which operates on a fresh $vehicle array
     * mid-parse) since here we're working from already-persisted DB
     * values instead. variant/description follow the same "existing value
     * wins, detail page only fills a genuine gap" rule as elsewhere.
     */
    private function applyDetailNodeToExistingCar(array $carRow, array $node): void
    {
        $engineCapacityCc = $this->parseSmallUint((string) ($node['engineCapacityRaw'] ?? ''));
        $cylinders        = $this->parseSmallUint((string) ($node['cylinders'] ?? ''));
        $powerKw          = $this->parseSmallUint((string) ($node['powerKwRaw'] ?? ''));
        $gears            = $this->parseSmallUint((string) ($node['gearsRaw'] ?? ''));
        $co2              = $this->parseSmallUint((string) ($node['co2EmissionsRaw'] ?? ''));
        $doors            = $this->parseSmallUint((string) ($node['doors'] ?? ''));
        $seats            = $this->parseSmallUint((string) ($node['seats'] ?? ''));
        $drivetrain       = isset($node['driveWheelConfiguration'])
            ? ($this->normalizeDrivetrain((string) $node['driveWheelConfiguration']) ?: null)
            : null;

        $variant = $carRow['variant'];
        if (($variant === null || $variant === '') && !empty($node['variant'])) {
            $variant = trim((string) $node['variant']);
        }

        $description = $carRow['description'];
        if (($description === null || $description === '') && !empty($node['description'])) {
            $description = trim((string) $node['description']);
        }

        $images = (!empty($node['image']) && is_array($node['image']))
            ? array_values(array_unique(array_filter($node['image'])))
            : [];

        $sql = "
            UPDATE cars SET
                engine_capacity_cc = ?, cylinders = ?, power_kw = ?, gears = ?,
                co2_emissions_gkm = ?, doors = ?, seats = ?, drivetrain = ?,
                variant = ?, description = ?";
        $params = [
            $engineCapacityCc, $cylinders, $powerKw, $gears,
            $co2, $doors, $seats, $drivetrain,
            $variant, $description,
        ];

        if (!empty($images)) {
            $sql .= ", image_urls = ?, source_image_urls = ?";
            $params[] = json_encode($images);
            $params[] = json_encode($images);
        }

        $sql .= ", updated_at = NOW() WHERE id = ?";
        $params[] = $carRow['id'];

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Marks a car as permanently unable to be enriched (a stale VLPData
     * entry whose permalink resolves to a different, unrelated real
     * vehicle — see detailPageMismatchReason()'s docblock) so future
     * enrichNextBatch() calls stop retrying it. Stashed inside the
     * existing import_raw_payload JSON column rather than a new dedicated
     * column, to avoid a schema migration for what's fundamentally just
     * bookkeeping.
     *
     * engine_capacity_cc deliberately stays NULL — this is NOT "enriched
     * with zero specs," it's "we gave up trying," and the two must stay
     * distinguishable (countPendingEnrichment()'s NULL check would
     * otherwise never be reachable for a permanently-blocked car if we'd
     * instead tried to fake a non-null sentinel value here).
     */
    private function flagEnrichmentBlocked(int $carId, array $existingPayload, string $reason): void
    {
        $payload                              = $existingPayload;
        $payload['enrichment_blocked']        = true;
        $payload['enrichment_blocked_reason'] = $reason;
        $payload['enrichment_blocked_at']     = date('Y-m-d H:i:s');

        $this->pdo->prepare("UPDATE cars SET import_raw_payload = ? WHERE id = ?")
            ->execute([json_encode($payload), $carId]);
    }

    /** How many of this dealer's Motus cars still need enrichNextBatch(). */
    private function countPendingEnrichment(int $dealerId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM cars
            WHERE dealer_id = ? AND source_platform = ? AND engine_capacity_cc IS NULL
              AND JSON_EXTRACT(import_raw_payload, '$.enrichment_blocked') IS NULL
        ");
        $stmt->execute([$dealerId, $this->getSourceName()]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Images are stored directly as the source CDN URLs — NO download,
     * NO ImageRehostService, NO local copy under /uploads/cars/. See the
     * class docblock's "IMAGE STRATEGY" section for the trade-offs this
     * accepts. source_image_urls is set to the same list as image_urls
     * (there is no "our copy" vs "their copy" distinction anymore — both
     * columns point at the same dealer-hosted URLs).
     */
    private function insertCar(int $dealerId, array $v, string $commType, float $commValue, int $importId, ?int $execId): void
    {
        $uuid      = generateUuidV4();
        $slug      = $this->generateUniqueSlug($dealerId, $v['year'], $v['make'], $v['model'], $v['colour']);
        $imageUrls = array_values(array_unique(array_filter(array_map('trim', $v['image_urls']))));

        $this->pdo->prepare("
            INSERT INTO cars
                (uuid, dealer_id, uploaded_by_exec_id, slug, make, model, variant, year, price,
                 mileage, condition_type, body_type, colour, transmission, fuel_type,
                 drivetrain, description, vin,
                 engine_capacity_cc, cylinders, power_kw, gears, co2_emissions_gkm, doors, seats,
                 commission_type, commission_value,
                 image_urls, source_image_urls, status,
                 source_platform, source_external_id, dealer_stock_no, import_id,
                 last_imported_at, import_raw_payload,
                 created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'active',
                    ?, ?, ?, ?, NOW(), ?, NOW(), NOW())
        ")->execute([
            $uuid, $dealerId, $execId, $slug,
            $v['make'], $v['model'], $v['variant'], $v['year'], $v['price'],
            $v['mileage'], $v['condition_type'], $v['body_type'], $v['colour'],
            $v['transmission'], $v['fuel_type'], $v['drivetrain'], $v['description'], $v['vin'],
            $v['engine_capacity_cc'], $v['cylinders'], $v['power_kw'], $v['gears'],
            $v['co2_emissions_gkm'], $v['doors'], $v['seats'],
            $commType, $commValue,
            json_encode($imageUrls),
            json_encode($imageUrls),
            $this->getSourceName(),
            $v['dealer_stock_no'], $v['dealer_stock_no'], $importId,
            json_encode(['source_url' => $v['source_url'], 'dealer_name' => $v['dealer_name'] ?? null]),
        ]);
    }

    /**
     * @param bool $writeEnrichment Whether $v's detail-page-derived fields
     *        (drivetrain, description, engine specs, images) should be
     *        written at all. Pass false when this run deliberately skipped
     *        re-fetching this vehicle's detail page because it was already
     *        enriched from a previous run (see sync()'s $alreadyEnriched
     *        lookup) — in that case $v's enrichment fields are just
     *        whatever parseVehicle() defaulted them to (null / cover-photo-
     *        only), NOT "this vehicle genuinely has no specs," and writing
     *        them would silently destroy the good data already in the row.
     *        true is safe in every other case: either enrichment was
     *        actually attempted this run (so $v holds real, current data),
     *        or it was attempted and failed/skipped-by-deadline for a
     *        vehicle that had no prior enrichment anyway (so writing null
     *        over null changes nothing).
     */
    /**
     * @param string $commType  Kept as a parameter for signature
     *        consistency with insertCar(), but DELIBERATELY NOT WRITTEN
     *        here — see below.
     * @param float  $commValue Same.
     */
    private function updateCar(array $existing, int $dealerId, array $v, string $commType, float $commValue, int $importId, bool $writeEnrichment = true): void
    {
        $carId = $existing['id'];

        $imageUrls = array_values(array_unique(array_filter(array_map('trim', $v['image_urls']))));
        // Only touch the image columns if this run actually found some AND
        // enrichment writes are allowed at all — never wipe out existing
        // images because a detail-page fetch failed/timed out this run, or
        // because this run deliberately skipped an already-enriched car.
        $updateImages = $writeEnrichment && !empty($imageUrls);

        $enrichmentCols = $writeEnrichment
            ? "drivetrain = ?, description = ?,
               engine_capacity_cc = ?, cylinders = ?, power_kw = ?, gears = ?,
               co2_emissions_gkm = ?, doors = ?, seats = ?,"
            : "";

        // COMMISSION IS STICKY: commission_type/commission_value are set
        // ONCE, in insertCar(), when the car first enters the system.
        // updateCar() (every re-import after that) deliberately leaves
        // them alone. Previously this method overwrote them on every run
        // with whatever value happened to be in that run's form — so a
        // dealer manually adjusting a car's commission would have it
        // silently reset back to the default the next time the import
        // ran. $commType/$commValue are still accepted as parameters (so
        // every call site doesn't need touching) but are simply unused
        // for the UPDATE itself now.
        $sql = "
            UPDATE cars SET
                make = ?, model = ?, variant = ?, year = ?, price = ?, mileage = ?,
                condition_type = ?, body_type = ?, colour = ?, transmission = ?,
                fuel_type = ?,
                {$enrichmentCols}
                " . ($updateImages ? "image_urls = ?, source_image_urls = ?," : "") . "
                source_platform = ?,
                source_external_id = COALESCE(?, source_external_id),
                dealer_stock_no = COALESCE(?, dealer_stock_no),
                import_id = ?, last_imported_at = NOW(), import_raw_payload = ?,
                updated_at = NOW()
            WHERE id = ? AND dealer_id = ?
        ";

        $params = [
            $v['make'], $v['model'], $v['variant'], $v['year'], $v['price'], $v['mileage'],
            $v['condition_type'], $v['body_type'], $v['colour'], $v['transmission'],
            $v['fuel_type'],
        ];
        if ($writeEnrichment) {
            $params[] = $v['drivetrain'];
            $params[] = $v['description'];
            $params[] = $v['engine_capacity_cc'];
            $params[] = $v['cylinders'];
            $params[] = $v['power_kw'];
            $params[] = $v['gears'];
            $params[] = $v['co2_emissions_gkm'];
            $params[] = $v['doors'];
            $params[] = $v['seats'];
        }
        if ($updateImages) {
            $params[] = json_encode($imageUrls);
            $params[] = json_encode($imageUrls);
        }
        $params[] = $this->getSourceName();
        $params[] = $v['dealer_stock_no'];
        $params[] = $v['dealer_stock_no'];
        $params[] = $importId;
        $params[] = json_encode(['source_url' => $v['source_url'], 'dealer_name' => $v['dealer_name'] ?? null]);
        $params[] = $carId;
        $params[] = $dealerId;

        $this->pdo->prepare($sql)->execute($params);
    }
}
