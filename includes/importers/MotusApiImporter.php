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
    private const USER_AGENT         = 'SalesDeskImporter/1.0 (+https://salesdesk.co.za)';

    /**
     * Wall-clock budget (seconds) for the whole sync() call — fetching
     * the listing page plus one detail-page fetch per vehicle (for specs
     * + gallery). No longer needs to budget for image downloads (see
     * IMAGE STRATEGY above), but the per-vehicle detail-page fetch is
     * still the dominant cost, so this stays conservative.
     */
    private const DEFAULT_TIME_BUDGET_SECONDS = 35;

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

        $html = $this->fetchPage($sourceRef);
        if ($html === null) {
            throw new RuntimeException("Could not fetch \"{$sourceRef}\".");
        }

        $vehicles = $this->extractVlpData($html);
        if (empty($vehicles)) {
            throw new RuntimeException(
                "No VLPData array found in \"{$sourceRef}\". This most likely means the listing page " .
                "populates that data via an asynchronous API call after the page loads, rather than " .
                "embedding it in the initial HTML — in which case this extraction approach cannot work " .
                "as written. To confirm and fix: open this URL in a browser, DevTools -> Network -> " .
                "Fetch/XHR, reload, and find the request that returns the vehicle array as JSON. That " .
                "request's URL and response shape are what crawlDealership() actually needs to target."
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

    protected function fetchPage(string $url): ?string
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
     * Enrich a VLPData-derived vehicle array with whatever
     * MotusShowroomParser can additionally pull off that vehicle's own
     * detail page: engine capacity, cylinders, power output, CO2, doors,
     * seats, the full photo gallery, and dealer comments/features text.
     *
     * Only fills fields that are still null/empty on $vehicle — VLPData's
     * own values (make/model/price/mileage/etc.) always win if present,
     * since VLPData is the authoritative bulk source and the detail-page
     * label scraper is comparatively fragile (see MotusShowroomParser's
     * docblock). The one deliberate exception is the photo gallery: the
     * detail page's gallery always replaces VLPData's single cover photo
     * when the detail page yields any images at all, since a full gallery
     * is strictly more useful than one cover shot.
     */
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

        $importId = $this->createImportRun($dealerId, $sourceRef, $initiatedBy);

        $errors = [];
        $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $rows = $this->crawlDealership($sourceRef, $deadline);
            $rows = $this->filterByDealerName($rows, $dealerNameFilter);
        } catch (Throwable $e) {
            $this->failImportRun($importId, $e->getMessage());
            throw $e;
        }

        foreach ($rows as $i => $rawRow) {
            try {
                $vehicle = $this->parseVehicle($rawRow);

                // Detail-page fetch: adds engine/spec fields + full gallery
                // (see mergeDetailPageSpecs()) — budget permitting. If we've
                // run out of time, fall back to whatever VLPData alone gave
                // us (cover photo only, spec fields null) rather than block
                // this vehicle; a future sync() run will pick up the rest.
                if (microtime(true) < $deadline && $vehicle['source_url'] !== '') {
                    $detailHtml = $this->fetchPage($vehicle['source_url']);
                    if ($detailHtml !== null) {
                        $vehicle = $this->mergeDetailPageSpecs($vehicle, $detailHtml);
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

                if ($existing !== null) {
                    $this->updateCar($existing, $dealerId, $vehicle, $commType, $commValue, $importId);
                    $counts['updated']++;
                } else {
                    $this->insertCar($dealerId, $vehicle, $commType, $commValue, $importId, $execId);
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
