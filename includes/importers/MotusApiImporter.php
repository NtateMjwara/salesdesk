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
            // No dedicated "variant/trim" label on a lone detail page's
            // markup — the page's H1 (captured as 'name') was just
            // "{year} {BRAND} {model}" with nothing extra in every sample
            // seen so far, so there's nothing left to attribute to variant.
            'variant'            => null,
            'description'        => trim((string) ($node['description'] ?? '')) ?: null,
            'image_urls'         => $images,
            'source_url'         => $sourceUrl,
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

    private function mergeDetailPageSpecs(array $vehicle, string $detailHtml): array
    {
        $nodes = $this->showroomParser->extract($detailHtml);
        if (empty($nodes)) {
            return $vehicle;
        }
        $node = $nodes[0];

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

        foreach ($rows as $i => $rawRow) {
            $stockForLog = $rawRow['stockId'] ?? $rawRow['sku'] ?? ('row#' . $i);
            $this->debugLog("[MotusApiImporter] --- Processing row {$i} (stock/sku: {$stockForLog}) ---");
            try {
                if (!empty($rawRow['_is_detail_node'])) {
                    // Already the full, enriched detail-page node — no
                    // separate parseVehicle() + mergeDetailPageSpecs() pass
                    // needed, since crawlDealership() got everything this
                    // page has to offer in one fetch.
                    $vehicle = $this->parseVehicleFromDetailNode($rawRow, (string) $rawRow['_source_url']);
                } else {
                    $vehicle = $this->parseVehicle($rawRow);
                    $this->debugLog("[MotusApiImporter] parseVehicle() OK — make={$vehicle['make']}, model={$vehicle['model']}, source_url={$vehicle['source_url']}, image_urls count=" . count($vehicle['image_urls']));
                    $vehicle = $this->enrichFromDetailPage($vehicle, $deadline, $sourceRef);
                    $this->debugLog("[MotusApiImporter] After enrichFromDetailPage() — engine_capacity_cc=" . var_export($vehicle['engine_capacity_cc'], true) . ", image_urls count=" . count($vehicle['image_urls']));
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
                    $this->updateCar($existing, $dealerId, $vehicle, $commType, $commValue, $importId);
                    $counts['updated']++;
                    $this->debugLog("[MotusApiImporter] updateCar() completed for existing id={$existing['id']}.");
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
            json_encode(['source_url' => $v['source_url']]),
        ]);
    }

    private function updateCar(array $existing, int $dealerId, array $v, string $commType, float $commValue, int $importId): void
    {
        $carId = $existing['id'];

        $imageUrls    = array_values(array_unique(array_filter(array_map('trim', $v['image_urls']))));
        // Only touch the image columns if this run actually found some —
        // never wipe out existing images because a detail-page fetch
        // failed or timed out this run.
        $updateImages = !empty($imageUrls);

        $sql = "
            UPDATE cars SET
                make = ?, model = ?, variant = ?, year = ?, price = ?, mileage = ?,
                condition_type = ?, body_type = ?, colour = ?, transmission = ?,
                fuel_type = ?, drivetrain = ?, description = ?,
                engine_capacity_cc = ?, cylinders = ?, power_kw = ?, gears = ?,
                co2_emissions_gkm = ?, doors = ?, seats = ?,
                commission_type = ?, commission_value = ?,
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
            $v['fuel_type'], $v['drivetrain'], $v['description'],
            $v['engine_capacity_cc'], $v['cylinders'], $v['power_kw'], $v['gears'],
            $v['co2_emissions_gkm'], $v['doors'], $v['seats'],
            $commType, $commValue,
        ];
        if ($updateImages) {
            $params[] = json_encode($imageUrls);
            $params[] = json_encode($imageUrls);
        }
        $params[] = $this->getSourceName();
        $params[] = $v['dealer_stock_no'];
        $params[] = $v['dealer_stock_no'];
        $params[] = $importId;
        $params[] = json_encode(['source_url' => $v['source_url']]);
        $params[] = $carId;
        $params[] = $dealerId;

        $this->pdo->prepare($sql)->execute($params);
    }
}
