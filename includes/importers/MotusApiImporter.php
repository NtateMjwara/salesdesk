<?php
/**
 * SalesDesk — Motus platform "VLPData" importer.
 *
 * Reuses the same shared machinery as CsvImporter/WebsiteImporter
 * (NormalizesVehicleFields, PersistsVehicleImports, ImageRehostService)
 * rather than being a one-off script, so dedup, slug generation, image
 * re-hosting, and vehicle_imports logging all behave identically to
 * every other importer in this codebase.
 *
 * WHAT THIS ACTUALLY DOES:
 *   Motus-platform dealer sites (motusvw.co.za confirmed; likely other
 *   motus*.co.za brand sites sharing the same "Digital Dealer" platform)
 *   render their vehicle grid entirely client-side from a JS array
 *   referenced as `const VLPData = JSON.parse(localStorage.getItem(...))`
 *   on detail pages. The listing page that ORIGINALLY populates that
 *   localStorage value is the target of this importer. Two possible
 *   mechanisms were considered:
 *     (a) The listing page's initial HTML embeds `const VLPData = [...]`
 *         directly in an inline <script> tag (SSR hydration payload).
 *     (b) The listing page fetches it asynchronously from a JSON API
 *         after the page loads, and never appears in the initial HTML
 *         response at all.
 *   A plain fetch of https://www.motusvw.co.za/midrand/showroom/ (no JS
 *   execution) returned "There are no used vehicles matching your
 *   filters" with an empty vehicle grid — consistent with (b), but NOT
 *   conclusive, since (a) would also require JS to RENDER the grid even
 *   though the data would already be sitting in the page source. This
 *   class assumes (a) and looks for the inline array; if crawlDealership()
 *   consistently throws the "no VLPData array found" exception below
 *   against a real listing page, that confirms (b) instead, and this
 *   class cannot work without finding the actual async endpoint (see
 *   that exception's message for what to capture next).
 *
 * WHAT THIS DOES NOT DO (by design, not oversight):
 *   - Does NOT create dealer records. $dealerId is supplied by the
 *     caller — this importer is for a SalesDesk dealer (already
 *     registered, verified, logged in) importing THEIR OWN stock from
 *     their own Motus-platform site, exactly like WebsiteImporter and
 *     CsvImporter. A dealer record with no real user behind it can't log
 *     in, can't receive leads, can't get paid — auto-creating one from
 *     scraped data would produce inventory nobody can actually manage.
 *     If bulk-onboarding OTHER Motus franchises as SalesDesk dealers is
 *     ever wanted, that is a deliberate product decision (consent,
 *     verification, account ownership/claiming) and needs its own
 *     design — it is not something an importer should do as a side effect.
 *   - Does NOT fetch additional photos per vehicle. VLPData's `image`
 *     field is a single cover photo (confirmed by the destructured field
 *     list in the site's own JS) — a full gallery would require a
 *     follow-up fetch of each vehicle's own detail page, which this
 *     class does not attempt. MotusShowroomParser (the HTML label
 *     scraper, already built and tested against a real page) can supply
 *     the full gallery plus richer spec fields (engine capacity, power,
 *     CO2, etc.) as a secondary per-vehicle pass if that's wanted later —
 *     not implemented here to keep this class's scope to "get the bulk
 *     listing imported quickly and reliably."
 *   - Does NOT check robots.txt. Unlike WebsiteImporter (which crawls up
 *     to MAX_PAGES pages and so carries a real politeness obligation),
 *     this class makes exactly ONE request per sync() call. Trivial to
 *     add if wanted — see WebsiteImporter's loadRobotsTxt()/
 *     isAllowedByRobots() for the pattern to reuse.
 *
 * JSON PARSING CAVEAT — READ BEFORE TRUSTING THIS AGAINST A REAL SITE:
 *   extractVlpData() has never been run against a confirmed real
 *   `VLPData` payload. It assumes the array is machine-generated (most
 *   likely via the platform's own JSON.stringify() during server-side
 *   rendering) and therefore valid JSON — trailing-comma and unquoted-
 *   key repairs are included as a defensive fallback ONLY, not because
 *   either has been observed. If the repaired parse still fails, this
 *   throws with the raw json_last_error_msg() rather than guessing
 *   further or silently returning partial data — capture a real sample
 *   and both the extraction regex and the repair logic can be corrected
 *   against it directly.
 */

require_once __DIR__ . '/InventoryImporterInterface.php';
require_once __DIR__ . '/NormalizesVehicleFields.php';
require_once __DIR__ . '/PersistsVehicleImports.php';
require_once __DIR__ . '/ImageRehostService.php';
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
     * the listing page plus re-hosting every vehicle's cover photo. Same
     * reasoning as WebsiteImporter::DEFAULT_TIME_BUDGET_SECONDS: a
     * reverse proxy's read timeout (nginx/Apache commonly default to
     * 60s) doesn't care how "small" this importer's scope is — a dealer
     * with 80 vehicles is still 80 sequential image downloads unless we
     * stop ourselves first.
     */
    private const DEFAULT_TIME_BUDGET_SECONDS = 35;

    private PDO $pdo;
    private ImageRehostService $imageRehost;

    public function __construct(?PDO $pdo = null, ?ImageRehostService $imageRehost = null)
    {
        $this->pdo         = $pdo ?? Database::getInstance();
        $this->imageRehost = $imageRehost ?? new ImageRehostService();
    }

    public function getSourceName(): string
    {
        // Platform-level name, not brand-specific — the VLPData mechanism
        // is presumed shared across every Motus-network site, not unique
        // to motusvw.co.za. If that turns out to be wrong for some brand,
        // this is the one place to reconsider.
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
     * the page. See this file's top-level docblock for the JSON-parsing
     * caveat — this has not been verified against a real payload.
     *
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

        // Attempt 1: strict JSON. Most likely case — this is almost
        // certainly machine-generated via JSON.stringify() during
        // server-side rendering, not hand-typed JS with unquoted keys.
        $data = json_decode($jsonStr, true);
        if (is_array($data)) {
            return $data;
        }

        // Attempt 2: best-effort repair for JS-object-literal syntax
        // (trailing commas, unquoted keys). UNVERIFIED — if this
        // produces a result, treat that result with some suspicion until
        // it's been checked against a couple of real vehicles; if it
        // still doesn't parse, fail loudly rather than guess further.
        $repaired = preg_replace('/,\s*([\]}])/', '$1', $jsonStr);
        $repaired = preg_replace('/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $repaired);
        $data = json_decode($repaired, true);
        if (is_array($data)) {
            return $data;
        }

        throw new RuntimeException(
            'Found a VLPData array in the page but could not parse it as JSON, even after a best-effort ' .
            'repair for trailing commas and unquoted keys. JSON error: ' . json_last_error_msg() . '. The ' .
            'literal may use single-quoted strings or other syntax this repair does not handle — capture ' .
            'a real sample (view-source, search for "const VLPData") so the parser can be corrected against it.'
        );
    }

    /**
     * Basic SSRF guard — identical reasoning and logic to
     * WebsiteImporter::isPubliclyRoutable(). Duplicated here rather than
     * shared via a trait for now since it's a single small method; worth
     * extracting if a third importer ends up needing the same guard.
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

        // VLPData carries modelRange/variant as separate fields with no
        // dedicated column on the canonical shape (WebsiteImporter itself
        // has no `variant` column, unlike CsvImporter) — fold into the
        // description rather than silently drop them.
        $variant    = trim((string) ($rawRow['variant'] ?? ''));
        $modelRange = trim((string) ($rawRow['modelRange'] ?? ''));
        $descriptionParts = array_values(array_filter([$modelRange, $variant]));
        $description = $descriptionParts !== [] ? implode(' ', $descriptionParts) : null;

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
            'vin'               => null, // not present in VLPData
            'dealer_stock_no'   => $stockNumber !== '' ? $stockNumber : null,
            'price'             => $price,
            'mileage'           => $mileageRaw !== null ? (int) $mileageRaw : null,
            'condition_type'    => $this->normalizeCondition((string) ($rawRow['newUsed'] ?? '')),
            'body_type'         => $this->normalizeBodyType((string) ($rawRow['bodyType'] ?? '')) ?: null,
            'colour'            => trim((string) ($rawRow['colour'] ?? '')) ?: null,
            'transmission'      => $this->normalizeTransmission((string) ($rawRow['gearshift'] ?? '')) ?: null,
            'fuel_type'         => $this->normalizeFuelType((string) ($rawRow['fuelType'] ?? '')) ?: null,
            'drivetrain'        => null, // not present in VLPData
            'description'       => $description,
            'image_urls'        => $imageUrls,
            'source_url'        => $sourceUrl,

            // Richer detail fields WebsiteImporter also carries — VLPData
            // genuinely doesn't have these, so they stay null (an honest
            // "unknown") rather than asserting a specific value the way
            // the earlier draft did (is_written_off => 0, warranty_type
            // => 'none') when the source data says nothing about either.
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
     * Extract a fallback stock identifier from relPermalink when VLPData's
     * own `stockId` field is absent, e.g. "/showroom/625-859179/4/" ->
     * "625-859179-4". Joins with a hyphen, not the original slash — a
     * slash in dealer_stock_no is harmless for the DB column itself, but
     * there's no reason to keep a URL path separator in what is now just
     * an opaque identifier string, and it avoids any downstream surprise
     * if this value is ever used to build a URL or file path later.
     */
    private function extractStockId(string $relPermalink): string
    {
        $parts = array_values(array_filter(explode('/', trim($relPermalink, '/'))));
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2] . '-' . $parts[count($parts) - 1];
        }
        return $parts[0] ?? '';
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
        $execId = $options['exec_id'] ?? null;

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
            'time_boxed' => microtime(true) >= $deadline,
        ];
    }

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
        $params[] = $this->getSourceName();
        $params[] = $v['dealer_stock_no'];
        $params[] = $importId;
        $params[] = json_encode(['source_url' => $v['source_url']]);
        $params[] = $carId;
        $params[] = $dealerId;

        $this->pdo->prepare($sql)->execute($params);
    }
}
