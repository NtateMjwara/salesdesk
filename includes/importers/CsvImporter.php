<?php
/**
 * SalesDesk — CSV Inventory Importer.
 *
 * First concrete implementation of InventoryImporterInterface. Lets a
 * dealer bring in listings they already have elsewhere (their own
 * Cars.co.za / AutoTrader export, a spreadsheet from their DMS, a
 * manually-built sheet) without SalesDesk ever touching a third-party
 * site directly — the dealer owns the export, so there's no scraping /
 * ToS / copyright question to deal with.
 *
 * CHANGES IN THIS PASS (reconciling against migrations 0010/0011):
 *   FIX-1  Column names corrected to match the actual schema:
 *            source        -> source_platform
 *            source_ref    -> dealer_stock_no   (the CSV-row-level dedup key;
 *                                                 NOT to be confused with the
 *                                                 $sourceRef *method parameter*
 *                                                 on crawlDealership()/sync(),
 *                                                 which is the file path/locator
 *                                                 and is unrelated)
 *            last_synced_at -> last_imported_at
 *          Previously this class referenced columns that don't exist on
 *          `cars` at all and every insert/update would have thrown
 *          "Column not found".
 *   FIX-2  `variant` is now written to its own column (added in migration
 *          0010) instead of being folded into `description`. Cars with a
 *          variant no longer get a mangled description as a side effect.
 *   FIX-3  Images are now actually re-hosted via ImageRehostService rather
 *          than storing the source CSV's URLs as-is — see rehostImages().
 *          `source_image_urls` (added in migration 0011) keeps the
 *          original URLs so unchanged rows skip re-downloading, and a
 *          failed re-host can retry from the original source later.
 *   FIX-4  VIN dedup match now scoped consistently: DB constraint is
 *          (dealer_id, vin) per migration 0011, matching what
 *          findExistingCar() already assumed.
 *   FIX-5  Stock-number dedup (dealer_stock_no) no longer restricted to
 *          `source_platform = 'csv'` rows — a dealer's own stock number is
 *          unique to them regardless of which importer touched the row
 *          last, matching the DB unique key uq_car_dealer_stockno
 *          (dealer_id, dealer_stock_no) added in migration 0010.
 *   FIX-6  vehicle_imports table (created in migration 0011) now actually
 *          exists — createImportRun()/completeImportRun()/failImportRun()
 *          were previously writing to a table that didn't exist.
 *   ADD-1  Optional header aliases + parsing added for the fields added in
 *          migration 0010 that CSV feeds commonly carry: mm_code, engine
 *          detail, ownership/warranty/condition fields, and listing_id
 *          (-> source_external_id). All optional — a CSV without these
 *          columns behaves exactly as before.
 *   ADD-2  import_raw_payload is now populated (JSON of the raw CSV row)
 *          for post-hoc debugging of mapping issues.
 *
 * Expected CSV headers (case-insensitive, aliases in parens). Everything
 * below "status" is new and optional — omit entirely if your feed doesn't
 * carry it:
 *   make               (brand)
 *   model
 *   variant            (trim)
 *   year               (model_year)
 *   price              (asking_price)
 *   mileage            (odometer, km)
 *   condition          (condition_type)
 *   body_type          (body, bodystyle)
 *   colour             (color)
 *   transmission       (gearbox)
 *   fuel_type          (fuel)
 *   drivetrain         (drive_type)
 *   description        (notes)
 *   commission_type
 *   commission_value   (commission)
 *   image_urls         (images, photos)          — pipe " | " or comma separated
 *   vin
 *   stock_number       (stock_no, stockno)
 *   listing_id         (ad_id, external_id)       — source platform's own listing ID
 *   status
 *   mm_code            (mmcode)
 *   engine_capacity    (engine_size, engine_cc, cc)
 *   cylinders          (cyl)
 *   induction          (aspiration, turbo)
 *   power_kw           (power, kw)
 *   power_hp           (hp, bhp)                 — converted to kW if power_kw absent
 *   torque_nm          (torque, nm)
 *   gears              (number_of_gears, speeds)
 *   fuel_consumption   (fuel_consumption_l100km, consumption)
 *   co2_emissions      (co2)
 *   previous_owners    (owners)
 *   service_history
 *   service_book       (full_service_book)
 *   written_off        (accident_damaged, damaged)
 *   interior_colour    (interior_color)
 *   doors
 *   seats
 *   warranty_type      (warranty)
 *   warranty_expiry_date (warranty_expiry)
 *   warranty_expiry_km (warranty_km)
 *   service_plan_expiry_date (service_plan_expiry)
 *   service_plan_expiry_km  (service_plan_km)
 *   vat_inclusive      (vat_incl, includes_vat)
 *
 * Unknown columns are ignored. Required fields (make, model, year, price)
 * missing or invalid fail that row only — see sync().
 */

require_once __DIR__ . '/InventoryImporterInterface.php';
require_once __DIR__ . '/ImageRehostService.php';
require_once __DIR__ . '/../functions.php';

class CsvImporter implements InventoryImporterInterface
{
    private const REQUIRED_FIELDS = ['make', 'model', 'year', 'price'];

    /** Canonical header name => list of accepted aliases (lowercase, no spaces/dashes). */
    private const HEADER_ALIASES = [
        'make'                     => ['make', 'brand'],
        'model'                    => ['model'],
        'variant'                  => ['variant', 'trim'],
        'year'                     => ['year', 'model_year'],
        'price'                    => ['price', 'asking_price'],
        'mileage'                  => ['mileage', 'odometer', 'km'],
        'condition'                => ['condition', 'condition_type'],
        'body_type'                => ['body_type', 'body', 'bodystyle'],
        'colour'                   => ['colour', 'color'],
        'transmission'             => ['transmission', 'gearbox'],
        'fuel_type'                => ['fuel_type', 'fuel'],
        'drivetrain'               => ['drivetrain', 'drive_type'],
        'description'              => ['description', 'notes'],
        'commission_type'          => ['commission_type'],
        'commission_value'         => ['commission_value', 'commission'],
        'image_urls'               => ['image_urls', 'images', 'photos'],
        'vin'                      => ['vin'],
        'stock_number'             => ['stock_number', 'stock_no', 'stockno'],
        'listing_id'               => ['listing_id', 'ad_id', 'external_id'],
        'status'                   => ['status'],

        // Added for migration 0010 fields — all optional.
        'mm_code'                  => ['mm_code', 'mmcode'],
        'engine_capacity'          => ['engine_capacity', 'engine_size', 'engine_cc', 'cc'],
        'cylinders'                => ['cylinders', 'cyl'],
        'induction'                => ['induction', 'aspiration', 'turbo'],
        'power_kw'                 => ['power_kw', 'power', 'kw'],
        'power_hp'                 => ['power_hp', 'hp', 'bhp'],
        'torque_nm'                => ['torque_nm', 'torque', 'nm'],
        'gears'                    => ['gears', 'number_of_gears', 'speeds'],
        'fuel_consumption'         => ['fuel_consumption', 'fuel_consumption_l100km', 'consumption'],
        'co2_emissions'            => ['co2_emissions', 'co2'],
        'previous_owners'          => ['previous_owners', 'owners'],
        'service_history'          => ['service_history'],
        'service_book'             => ['service_book', 'full_service_book'],
        'written_off'              => ['written_off', 'accident_damaged', 'damaged'],
        'interior_colour'          => ['interior_colour', 'interior_color'],
        'doors'                    => ['doors'],
        'seats'                    => ['seats'],
        'warranty_type'            => ['warranty_type', 'warranty'],
        'warranty_expiry_date'     => ['warranty_expiry_date', 'warranty_expiry'],
        'warranty_expiry_km'       => ['warranty_expiry_km', 'warranty_km'],
        'service_plan_expiry_date' => ['service_plan_expiry_date', 'service_plan_expiry'],
        'service_plan_expiry_km'   => ['service_plan_expiry_km', 'service_plan_km'],
        'vat_inclusive'            => ['vat_inclusive', 'vat_incl', 'includes_vat'],
    ];

    private PDO $pdo;
    private ImageRehostService $imageRehost;

    public function __construct(?PDO $pdo = null, ?ImageRehostService $imageRehost = null)
    {
        $this->pdo         = $pdo ?? Database::getInstance();
        $this->imageRehost = $imageRehost ?? new ImageRehostService();
    }

    public function getSourceName(): string
    {
        return 'csv';
    }

    /**
     * @param string $sourceRef Absolute path to the uploaded CSV file.
     */
    public function crawlDealership(string $sourceRef): array
    {
        if (!is_readable($sourceRef)) {
            throw new RuntimeException("CSV file is not readable: {$sourceRef}");
        }

        $handle = fopen($sourceRef, 'r');
        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file: {$sourceRef}");
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false || $headerRow === null) {
            fclose($handle);
            throw new RuntimeException('CSV file is empty or has no header row.');
        }

        // Strip a UTF-8 BOM if present on the first header cell.
        $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', (string) $headerRow[0]);

        $columnMap = $this->mapHeaders($headerRow);

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            // Skip fully blank lines (fgetcsv returns [null] for these).
            if ($cells === [null] || $cells === []) {
                continue;
            }
            $row = [];
            foreach ($columnMap as $canonicalName => $colIndex) {
                $row[$canonicalName] = $cells[$colIndex] ?? null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Build canonical-name => column-index map from the CSV header row.
     *
     * @param array<int, string> $headerRow
     * @return array<string, int>
     */
    private function mapHeaders(array $headerRow): array
    {
        $normalized = array_map(
            static fn (string $h): string => strtolower(trim(preg_replace('/[\s\-]+/', '_', $h))),
            $headerRow
        );

        $map = [];
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $normalized, true);
                if ($idx !== false) {
                    $map[$canonical] = $idx;
                    break;
                }
            }
        }
        return $map;
    }

    public function parseVehicle(array $rawRow): array
    {
        $get = static fn (string $key): string => trim((string) ($rawRow[$key] ?? ''));

        // SANITY-CHECK FIX: REQUIRED_FIELDS was declared (and referenced by
        // name in InventoryImporterInterface's docblock as "the mapping
        // rule") but never actually consulted here — presence was checked
        // via separate hardcoded if-statements below instead, so editing
        // the constant would silently do nothing. Presence is now driven
        // by the constant; the extra type/range validation for year and
        // price (which presence-checking alone can't cover) stays layered
        // on top immediately after.
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($get($field) === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $make  = $get('make');
        $model = $get('model');
        $year  = $get('year');
        $price = $this->parseNumber($get('price'));

        if (!ctype_digit($year)) {
            throw new InvalidArgumentException("Invalid required field: year (\"{$year}\" is not a whole number)");
        }
        $yearInt = (int) $year;
        $currentYearPlus1 = (int) date('Y') + 1;
        if ($yearInt < 1990 || $yearInt > $currentYearPlus1) {
            throw new InvalidArgumentException("Year out of range (1990–{$currentYearPlus1}): {$yearInt}");
        }
        if ($price === null || $price <= 0) {
            throw new InvalidArgumentException('Invalid required field: price (missing, zero, or unparseable)');
        }

        $mileageRaw = $get('mileage');
        $mileage    = $mileageRaw !== '' ? $this->parseNumber($mileageRaw) : null;
        if ($mileage !== null && $mileage < 0) {
            throw new InvalidArgumentException('Mileage cannot be negative.');
        }

        // FIX-2: variant is its own column now — no longer folded into description.
        $variant     = $get('variant') ?: null;
        $description = $get('description') ?: null;

        $imageUrls = [];
        $imageRaw  = $get('image_urls');
        if ($imageRaw !== '') {
            $imageUrls = array_values(array_filter(array_map(
                'trim',
                preg_split('/[|,]/', $imageRaw)
            )));
        }

        $vin         = strtoupper($get('vin'));
        if ($vin !== '' && strlen($vin) !== 17) {
            // Don't hard-fail the row over a malformed VIN — it's a nice-to-have
            // dedup/display field, not one of the REQUIRED_FIELDS. Drop it and
            // let the row import without one rather than losing the whole car.
            $vin = '';
        }
        $stockNumber = $get('stock_number');
        $listingId   = $get('listing_id');
        $mmCode      = $get('mm_code');
        // SANITY-CHECK FIX: these map to fixed-width VARCHAR columns
        // (mm_code 20, dealer_stock_no 60, source_external_id 100 — see
        // migration 0010) with no length check before insert. An unusually
        // long source value would fail the whole row under strict SQL mode.
        // Truncating defensively here is safer than losing the row entirely
        // over a field that's a dedup/display convenience, not one of
        // REQUIRED_FIELDS.
        if (strlen($mmCode) > 20)      $mmCode = substr($mmCode, 0, 20);
        if (strlen($stockNumber) > 60) $stockNumber = substr($stockNumber, 0, 60);
        if (strlen($listingId) > 100)  $listingId = substr($listingId, 0, 100);

        return [
            // Identity
            'make'                     => $make,
            'model'                    => $model,
            'variant'                  => $variant,
            'year'                     => $yearInt,
            'vin'                      => $vin !== '' ? $vin : null,
            'mm_code'                  => $mmCode !== '' ? $mmCode : null,
            'dealer_stock_no'          => $stockNumber !== '' ? $stockNumber : null,
            'source_external_id'       => $listingId !== '' ? $listingId : null,

            // Core listing fields
            'price'                    => $price,
            'mileage'                  => $mileage,
            'condition_type'           => $this->normalizeCondition($get('condition')),
            'body_type'                => $this->normalizeBodyType($get('body_type')) ?: null,
            'colour'                   => $get('colour') ?: null,
            'transmission'             => $this->normalizeTransmission($get('transmission')) ?: null,
            'fuel_type'                => $this->normalizeFuelType($get('fuel_type')) ?: null,
            'drivetrain'               => $this->normalizeDrivetrain($get('drivetrain')) ?: null,
            'description'              => $description,
            'commission_type'          => $this->normalizeCommissionType($get('commission_type')),
            'commission_value'         => $get('commission_value') !== '' ? $this->parseNumber($get('commission_value')) : null,
            'image_urls'               => $imageUrls,
            'status'                   => $this->normalizeStatus($get('status')),

            // Engine / drivetrain detail (migration 0010) — all optional
            'engine_capacity_cc'       => $this->parseEngineCapacityCc($get('engine_capacity')),
            'cylinders'                => $this->parseSmallUint($get('cylinders')),
            'induction'                => $this->normalizeInduction($get('induction')),
            'power_kw'                 => $this->parsePowerKw($get('power_kw'), $get('power_hp')),
            'torque_nm'                => $this->parseSmallUint($get('torque_nm')),
            'gears'                    => $this->parseSmallUint($get('gears')),
            'fuel_consumption_l100km'  => $this->parseDecimal($get('fuel_consumption')),
            'co2_emissions_gkm'        => $this->parseSmallUint($get('co2_emissions')),

            // Ownership / warranty / condition (migration 0010) — all optional
            'previous_owners'          => $this->parseSmallUint($get('previous_owners')),
            'service_history'          => $this->normalizeServiceHistory($get('service_history')),
            'has_service_book'         => $this->parseBool($get('service_book')),
            'is_written_off'           => $this->parseBool($get('written_off')),
            'interior_colour'          => $get('interior_colour') ?: null,
            'doors'                    => $this->parseSmallUint($get('doors')),
            'seats'                    => $this->parseSmallUint($get('seats')),
            'warranty_type'            => $this->normalizeWarrantyType($get('warranty_type')),
            'warranty_expiry_date'     => $this->parseDateSafe($get('warranty_expiry_date')),
            'warranty_expiry_km'       => $this->parseIntOrNull($get('warranty_expiry_km')),
            'service_plan_expiry_date' => $this->parseDateSafe($get('service_plan_expiry_date')),
            'service_plan_expiry_km'   => $this->parseIntOrNull($get('service_plan_expiry_km')),
            'vat_inclusive'            => $this->parseBool($get('vat_inclusive'), true),
        ];
    }

    public function sync(int $dealerId, string $sourceRef, array $options = []): array
    {
        $initiatedBy            = $options['initiated_by']            ?? null;
        $defaultCommissionType  = $options['default_commission_type'] ?? 'fixed';
        $defaultCommissionValue = isset($options['default_commission_value'])
            ? (float) $options['default_commission_value'] : null;
        $execId                 = $options['exec_id'] ?? null;

        $importId = $this->createImportRun($dealerId, $sourceRef, $initiatedBy);

        $errors  = [];
        $counts  = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $rows = $this->crawlDealership($sourceRef);
        } catch (Throwable $e) {
            $this->failImportRun($importId, $e->getMessage());
            throw $e;
        }

        foreach ($rows as $i => $rawRow) {
            $rowNum = $i + 2; // +1 for 0-index, +1 for the header row itself.

            if ($this->isBlankRow($rawRow)) {
                $counts['skipped']++;
                continue;
            }

            try {
                $vehicle = $this->parseVehicle($rawRow);

                // Fill commission from dealer-supplied defaults when the row omits it.
                if ($vehicle['commission_type'] === null) {
                    $vehicle['commission_type'] = in_array($defaultCommissionType, ['fixed', 'percentage'], true)
                        ? $defaultCommissionType : 'fixed';
                }
                if ($vehicle['commission_value'] === null) {
                    $vehicle['commission_value'] = $defaultCommissionValue;
                }
                if ($vehicle['commission_value'] === null || $vehicle['commission_value'] <= 0) {
                    throw new InvalidArgumentException(
                        'No commission value in row and no default_commission_value option supplied.'
                    );
                }
                if ($vehicle['commission_type'] === 'percentage' && $vehicle['commission_value'] > 30) {
                    throw new InvalidArgumentException('Percentage commission must be 30% or less.');
                }

                $existing = $this->findExistingCar($dealerId, $vehicle['vin'], $vehicle['dealer_stock_no']);

                if ($existing !== null) {
                    $this->updateCar($existing, $dealerId, $vehicle, $importId, $rawRow);
                    $counts['updated']++;
                } else {
                    $this->insertCar($dealerId, $vehicle, $importId, $execId, $rawRow);
                    $counts['imported']++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
            }
        }

        $this->completeImportRun($importId, count($rows), $counts, $errors);

        writeAuditLog(
            'vehicle_import.completed',
            'vehicle_import',
            $importId,
            null,
            [
                'source'   => $this->getSourceName(),
                'dealer_id'=> $dealerId,
                'total'    => count($rows),
                ...$counts,
            ],
            $initiatedBy
        );

        return [
            'import_id' => $importId,
            'total'     => count($rows),
            'imported'  => $counts['imported'],
            'updated'   => $counts['updated'],
            'skipped'   => $counts['skipped'],
            'failed'    => $counts['failed'],
            'errors'    => $errors,
        ];
    }

    // ============================================================
    // DEDUP
    // ============================================================

    /**
     * FIX-4/FIX-5: VIN match is scoped to (dealer_id, vin), matching the
     * uq_car_vin_dealer constraint from migration 0011. Stock-number match
     * is scoped to (dealer_id, dealer_stock_no) with no source_platform
     * restriction, matching uq_car_dealer_stockno from migration 0010 — a
     * dealer's own stock number is unique to them regardless of which
     * importer last touched the row.
     *
     * @return array{id:int, uuid:string, source_image_urls: array<int,string>}|null
     */
    private function findExistingCar(int $dealerId, ?string $vin, ?string $dealerStockNo): ?array
    {
        if ($vin !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id, uuid, source_image_urls
                FROM cars WHERE dealer_id = ? AND vin = ? LIMIT 1
            ");
            $stmt->execute([$dealerId, $vin]);
            $row = $stmt->fetch();
            if ($row) {
                return $this->hydrateExistingCarRow($row);
            }
        }

        if ($dealerStockNo !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id, uuid, source_image_urls
                FROM cars WHERE dealer_id = ? AND dealer_stock_no = ? LIMIT 1
            ");
            $stmt->execute([$dealerId, $dealerStockNo]);
            $row = $stmt->fetch();
            if ($row) {
                return $this->hydrateExistingCarRow($row);
            }
        }

        return null;
    }

    /** @return array{id:int, uuid:string, source_image_urls: array<int,string>} */
    private function hydrateExistingCarRow(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'uuid'              => $row['uuid'],
            'source_image_urls' => json_decode($row['source_image_urls'] ?? '[]', true) ?: [],
        ];
    }

    // ============================================================
    // IMAGE RE-HOSTING (FIX-3)
    // ============================================================

    /**
     * SANITY-CHECK FIX: previously called ImageRehostService::rehost()
     * without a limit, so it fell back to a hardcoded default that could
     * drift from platform_config.max_images_per_car if an admin changes
     * that value. Now reads the live config on every call.
     *
     * @return array{image_urls: array<int,string>, source_image_urls: array<int,string>}|null
     *         null means "nothing to update" — no source URLs, unchanged
     *         since last run, or every download in the batch failed.
     */
    private function rehostImages(string $carUuid, array $sourceImageUrls, array $previousSourceImageUrls): ?array
    {
        $maxImages = getPlatformConfigInt('max_images_per_car', 10);
        try {
            return $this->imageRehost->rehost($carUuid, $sourceImageUrls, $previousSourceImageUrls, $maxImages);
        } catch (Throwable $e) {
            // Image re-hosting must never fail the whole car row.
            error_log("[CsvImporter] Image re-host failed for car {$carUuid}: " . $e->getMessage());
            return null;
        }
    }

    // ============================================================
    // PERSISTENCE
    // ============================================================

    private function insertCar(int $dealerId, array $v, int $importId, ?int $execId, array $rawRow): void
    {
        $uuid = generateUuidV4();
        $slug = $this->generateUniqueSlug($dealerId, $v['year'], $v['make'], $v['model'], $v['colour']);

        $images = $this->rehostImages($uuid, $v['image_urls'], []);

        $this->pdo->prepare("
            INSERT INTO cars
                (uuid, dealer_id, uploaded_by_exec_id, slug, make, model, variant, year, price,
                 mileage, condition_type, body_type, colour, transmission, fuel_type,
                 drivetrain, description, vin, mm_code,
                 engine_capacity_cc, cylinders, induction, power_kw, torque_nm, gears,
                 fuel_consumption_l100km, co2_emissions_gkm,
                 previous_owners, service_history, has_service_book, is_written_off,
                 interior_colour, doors, seats,
                 warranty_type, warranty_expiry_date, warranty_expiry_km,
                 service_plan_expiry_date, service_plan_expiry_km, vat_inclusive,
                 commission_type, commission_value,
                 image_urls, source_image_urls, status,
                 source_platform, source_external_id, dealer_stock_no, import_id,
                 last_imported_at, import_raw_payload,
                 created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    'csv', ?, ?, ?,
                    NOW(), ?,
                    NOW(), NOW())
        ")->execute([
            $uuid, $dealerId, $execId, $slug,
            $v['make'], $v['model'], $v['variant'], $v['year'], $v['price'],
            $v['mileage'], $v['condition_type'], $v['body_type'], $v['colour'],
            $v['transmission'], $v['fuel_type'], $v['drivetrain'], $v['description'],
            $v['vin'], $v['mm_code'],

            $v['engine_capacity_cc'], $v['cylinders'], $v['induction'], $v['power_kw'],
            $v['torque_nm'], $v['gears'], $v['fuel_consumption_l100km'], $v['co2_emissions_gkm'],

            $v['previous_owners'], $v['service_history'], $v['has_service_book'], $v['is_written_off'],
            $v['interior_colour'], $v['doors'], $v['seats'],

            $v['warranty_type'], $v['warranty_expiry_date'], $v['warranty_expiry_km'],
            $v['service_plan_expiry_date'], $v['service_plan_expiry_km'], $v['vat_inclusive'],

            $v['commission_type'], $v['commission_value'],

            json_encode($images['image_urls'] ?? []),
            json_encode($images['source_image_urls'] ?? []),
            $v['status'] ?? 'active',

            $v['source_external_id'], $v['dealer_stock_no'], $importId,

            json_encode($rawRow),
        ]);
    }

    private function updateCar(array $existing, int $dealerId, array $v, int $importId, array $rawRow): void
    {
        $carId = $existing['id'];
        $uuid  = $existing['uuid'];

        // Only touch image columns if the row actually supplied images AND
        // the re-host step produced something — an importer re-run with a
        // blank images column, or a run where every download failed,
        // shouldn't wipe out photos the dealer already has on the listing.
        $images       = $this->rehostImages($uuid, $v['image_urls'], $existing['source_image_urls']);
        $updateImages = $images !== null;

        $sql = "
            UPDATE cars SET
                make = ?, model = ?, variant = ?, year = ?, price = ?, mileage = ?,
                condition_type = ?, body_type = ?, colour = ?, transmission = ?,
                fuel_type = ?, drivetrain = ?, description = ?, vin = ?, mm_code = ?,
                engine_capacity_cc = ?, cylinders = ?, induction = ?, power_kw = ?,
                torque_nm = ?, gears = ?, fuel_consumption_l100km = ?, co2_emissions_gkm = ?,
                previous_owners = ?, service_history = ?, has_service_book = ?, is_written_off = ?,
                interior_colour = ?, doors = ?, seats = ?,
                warranty_type = ?, warranty_expiry_date = ?, warranty_expiry_km = ?,
                service_plan_expiry_date = ?, service_plan_expiry_km = ?, vat_inclusive = ?,
                commission_type = ?, commission_value = ?,
                " . ($updateImages ? "image_urls = ?, source_image_urls = ?," : "") . "
                " . ($v['status'] !== null ? "status = ?," : "") . "
                source_platform = 'csv',
                source_external_id = COALESCE(?, source_external_id),
                dealer_stock_no = COALESCE(?, dealer_stock_no),
                import_id = ?, last_imported_at = NOW(), import_raw_payload = ?,
                updated_at = NOW()
            WHERE id = ? AND dealer_id = ?
        ";

        $params = [
            $v['make'], $v['model'], $v['variant'], $v['year'], $v['price'], $v['mileage'],
            $v['condition_type'], $v['body_type'], $v['colour'], $v['transmission'],
            $v['fuel_type'], $v['drivetrain'], $v['description'], $v['vin'], $v['mm_code'],
            $v['engine_capacity_cc'], $v['cylinders'], $v['induction'], $v['power_kw'],
            $v['torque_nm'], $v['gears'], $v['fuel_consumption_l100km'], $v['co2_emissions_gkm'],
            $v['previous_owners'], $v['service_history'], $v['has_service_book'], $v['is_written_off'],
            $v['interior_colour'], $v['doors'], $v['seats'],
            $v['warranty_type'], $v['warranty_expiry_date'], $v['warranty_expiry_km'],
            $v['service_plan_expiry_date'], $v['service_plan_expiry_km'], $v['vat_inclusive'],
            $v['commission_type'], $v['commission_value'],
        ];
        if ($updateImages) {
            $params[] = json_encode($images['image_urls']);
            $params[] = json_encode($images['source_image_urls']);
        }
        if ($v['status'] !== null) {
            $params[] = $v['status'];
        }
        $params[] = $v['source_external_id'];
        $params[] = $v['dealer_stock_no'];
        $params[] = $importId;
        $params[] = json_encode($rawRow);
        $params[] = $carId;
        $params[] = $dealerId;

        $this->pdo->prepare($sql)->execute($params);
    }

    private function generateUniqueSlug(int $dealerId, int $year, string $make, string $model, ?string $colour): string
    {
        $base = strtolower(preg_replace(
            '/[^a-z0-9]+/', '-',
            "{$year}-{$make}-{$model}" . ($colour ? "-{$colour}" : '')
        ));
        $base = trim(substr($base, 0, 90), '-');
        $slug = $base;
        $n    = 2;
        while (true) {
            $stmt = $this->pdo->prepare("SELECT id FROM cars WHERE dealer_id = ? AND slug = ?");
            $stmt->execute([$dealerId, $slug]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = "{$base}-{$n}";
            $n++;
        }
    }

    // ============================================================
    // vehicle_imports LIFECYCLE
    // ============================================================

    private function createImportRun(int $dealerId, string $sourceRef, ?int $initiatedBy): int
    {
        $uuid = generateUuidV4();
        $this->pdo->prepare("
            INSERT INTO vehicle_imports
                (uuid, dealer_id, initiated_by, source_platform, source_ref, status, started_at, created_at)
            VALUES (?, ?, ?, 'csv', ?, 'processing', NOW(), NOW())
        ")->execute([$uuid, $dealerId, $initiatedBy, $sourceRef]);

        return (int) $this->pdo->lastInsertId();
    }

    private function completeImportRun(int $importId, int $totalRows, array $counts, array $errors): void
    {
        $this->pdo->prepare("
            UPDATE vehicle_imports SET
                status = 'completed',
                total_rows = ?, imported_count = ?, updated_count = ?,
                skipped_count = ?, failed_count = ?, row_errors = ?,
                completed_at = NOW()
            WHERE id = ?
        ")->execute([
            $totalRows, $counts['imported'], $counts['updated'],
            $counts['skipped'], $counts['failed'],
            $errors ? json_encode($errors) : null,
            $importId,
        ]);
    }

    private function failImportRun(int $importId, string $message): void
    {
        $this->pdo->prepare("
            UPDATE vehicle_imports SET
                status = 'failed', error_message = ?, completed_at = NOW()
            WHERE id = ?
        ")->execute([$message, $importId]);
    }

    // ============================================================
    // NORMALIZATION — pre-existing
    // ============================================================

    /**
     * SANITY-CHECK FIX (round 2): the first fix for this function stripped
     * every non-digit/non-dot/non-minus character and treated what was
     * left as one number — but that breaks on units that themselves
     * contain digits, like "l/100km" (fuel consumption) or "g/km"-style
     * suffixes with numbers in them. "6.5 l/100km" would strip down to
     * "6.5100" and silently parse as 6.51 instead of 6.5. Fixed by
     * extracting only the LEADING numeric token and ignoring everything
     * after it, rather than concatenating whatever digits remain.
     *
     * Note: this assumes the platform's established convention of comma
     * as thousands separator and period as decimal point (matching
     * formatZAR()/number_format() elsewhere in the codebase) — a
     * European-style dot-grouped number like "1.998.000" would read as
     * 1.998 rather than 1998000. Not handled, since this platform doesn't
     * use that convention anywhere else either.
     */
    private function parseNumber(string $raw): ?float
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }

        // Strip thousands separators, currency symbol, and spaces first —
        // safe on their own, since none of these ever carry meaningful digits.
        $clean = str_replace([',', ' ', 'R'], '', $clean);

        // Now take ONLY the leading numeric token — anything after it
        // (units, symbols, more digit groups from a unit suffix) is ignored
        // rather than being stripped-and-appended.
        if (!preg_match('/^-?\d+(?:\.\d+)?/', $clean, $m)) {
            return null;
        }

        return (float) $m[0];
    }

    private function isBlankRow(array $rawRow): bool
    {
        foreach ($rawRow as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
    }

    private function normalizeCondition(string $raw): string
    {
        $v = strtolower(trim($raw));
        return match (true) {
            in_array($v, ['new'], true)                         => 'new',
            in_array($v, ['demo', 'demonstrator', 'pre-reg'], true) => 'demo',
            default                                              => 'used',
        };
    }

    /**
     * SANITY-CHECK FIX: commission_type maps to an ENUM('fixed','percentage')
     * column, but was previously passed straight through as raw CSV text
     * with no validation — every other enum-backed field here has a
     * normalize*() function that only ever emits a valid member of its
     * enum; this was the one exception. Unrecognized non-blank text (e.g.
     * "Flat Fee", "Percent", "R") would previously reach the INSERT/UPDATE
     * unchanged and risk a truncation error or silent enum-index coercion
     * at the DB layer. Now: recognized synonyms are mapped explicitly;
     * blank returns null (letting the existing default_commission_type
     * fallback in sync() apply, unchanged); anything else throws so the
     * row fails clearly with a readable message instead of a raw SQL error.
     *
     * @throws InvalidArgumentException if non-blank text doesn't map to either enum member.
     */
    private function normalizeCommissionType(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        return match (true) {
            in_array($v, ['fixed', 'flat', 'flat fee', 'r', 'rand', 'amount'], true) => 'fixed',
            in_array($v, ['percentage', 'percent', '%'], true)                       => 'percentage',
            default => throw new InvalidArgumentException(
                "Unrecognized commission_type \"{$raw}\" — expected fixed/flat or percentage/percent."
            ),
        };
    }

    /**
     * CORRECTED after being given app/dealer/car-upload.php (the manual
     * upload wizard) directly. Its $fuelTypes dropdown (line ~312) is:
     *   ['Petrol','Diesel','Electric','Hybrid','Plug-in Hybrid (PHEV)',
     *    'Hydrogen','LPG (Autogas)','CNG (Natural Gas)','Flex Fuel (E85/Ethanol)']
     * — the long parenthetical forms, plus three categories (Hydrogen, CNG,
     * Flex Fuel) this function previously had no branch for at all.
     *
     * c/index.php's $fuelTypeWhitelist uses SHORT forms instead
     * ('Plug-in Hybrid', 'LPG') with no Hydrogen/CNG/Flex-Fuel entries.
     * That whitelist gates which values the browse-page sidebar filter will
     * even submit — since the wizard has never written those short forms
     * to the DB, the PHEV and LPG filter checkboxes (and any car in the
     * three missing categories) can never have matched a real row. That's
     * a pre-existing mismatch between car-upload.php and c/index.php, not
     * something this importer should perpetuate by picking the broken side.
     * Matching the wizard here keeps imported and manually-listed cars in
     * the same vocabulary; c/index.php's whitelist should be corrected
     * separately to actually reflect what's in the database.
     */
    private function normalizeFuelType(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return '';
        return match (true) {
            str_contains($v, 'electric')                                    => 'Electric',
            str_contains($v, 'plug-in') || str_contains($v, 'phev')          => 'Plug-in Hybrid (PHEV)',
            str_contains($v, 'hybrid')                                      => 'Hybrid',
            str_contains($v, 'hydrogen')                                    => 'Hydrogen',
            str_contains($v, 'cng') || str_contains($v, 'natural gas')       => 'CNG (Natural Gas)',
            str_contains($v, 'flex') || str_contains($v, 'e85') || str_contains($v, 'ethanol') => 'Flex Fuel (E85/Ethanol)',
            str_contains($v, 'diesel')                                      => 'Diesel',
            str_contains($v, 'lpg') || str_contains($v, 'autogas')           => 'LPG (Autogas)',
            str_contains($v, 'petrol') || str_contains($v, 'gasoline') || str_contains($v, 'unleaded') => 'Petrol',
            default                                                         => ucfirst($v),
        };
    }

    /**
     * CORRECTED: previously emitted "DSG/Dual-clutch" to match
     * c/index.php's $transmissionWhitelist. app/dealer/car-upload.php's
     * actual dropdown (line ~311) writes bare "DSG" — the whitelist has
     * never matched real data here either. Matching the wizard.
     */
    private function normalizeTransmission(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return '';
        return match (true) {
            str_contains($v, 'dsg') || str_contains($v, 'dual-clutch') || str_contains($v, 'dual clutch') => 'DSG',
            str_contains($v, 'cvt')                                        => 'CVT',
            str_contains($v, 'semi')                                       => 'Semi-Automatic',
            str_contains($v, 'auto') || preg_match('/\ba\/t\b/', $v)       => 'Automatic',
            str_contains($v, 'manual') || preg_match('/\bm\/t\b/', $v)     => 'Manual',
            default                                                        => ucfirst($v),
        };
    }

    /**
     * CORRECTED: previously emitted "4×4" (Unicode ×) to match
     * c/index.php's $drivetrainWhitelist. app/dealer/car-upload.php's
     * actual dropdown (line ~313) writes plain "4WD" — the whitelist has
     * never matched real data here either. Matching the wizard.
     */
    private function normalizeDrivetrain(string $raw): string
    {
        $v = strtoupper(trim($raw));
        $v = str_replace([' ', '-'], '', $v);
        return match (true) {
            in_array($v, ['FWD', 'FRONTWHEELDRIVE'], true) => 'FWD',
            in_array($v, ['RWD', 'REARWHEELDRIVE'], true)  => 'RWD',
            in_array($v, ['AWD', 'ALLWHEELDRIVE'], true)   => 'AWD',
            in_array($v, ['4WD', '4X4', 'FOURWHEELDRIVE'], true) => '4WD',
            default                                          => $v ? ucfirst(strtolower($raw)) : '',
        };
    }

    /**
     * CORRECTED: previously merged "crossover" into "SUV/4x4" to match
     * layout-public.php's mega-nav link (body_type[]=SUV%2F4x4).
     * app/dealer/car-upload.php's actual dropdown (line ~310) has SUV and
     * Crossover as two SEPARATE selectable values — neither one is
     * "SUV/4x4". The nav link has apparently never matched a real
     * wizard-created row either. Matching the wizard, and keeping
     * Crossover distinct from SUV rather than merging them.
     */
    private function normalizeBodyType(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return '';
        return match (true) {
            str_contains($v, 'crossover')                                 => 'Crossover',
            str_contains($v, 'suv') || str_contains($v, '4x4')             => 'SUV',
            str_contains($v, 'bakkie') || str_contains($v, 'pickup') || str_contains($v, 'pick-up') => 'Bakkie',
            str_contains($v, 'hatch')                                     => 'Hatchback',
            str_contains($v, 'sedan') || str_contains($v, 'saloon')       => 'Sedan',
            str_contains($v, 'coupe')                                     => 'Coupe',
            str_contains($v, 'convertible') || str_contains($v, 'cabrio') => 'Convertible',
            str_contains($v, 'wagon') || str_contains($v, 'estate')       => 'Station Wagon',
            str_contains($v, 'minibus') || str_contains($v, 'taxi')       => 'Minibus',
            str_contains($v, 'van')                                       => 'Van',
            str_contains($v, 'truck')                                     => 'Truck',
            str_contains($v, 'mpv')                                       => 'MPV',
            default                                                       => ucfirst($v),
        };
    }

    private function normalizeStatus(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        return match ($v) {
            'active', 'available'          => 'active',
            'paused', 'on hold', 'on-hold' => 'paused',
            'sold'                         => 'sold',
            default                        => null, // unspecified — leave insert default / existing row untouched
        };
    }

    // ============================================================
    // NORMALIZATION — added for migration 0010 fields
    // ============================================================

    /**
     * SANITY-CHECK FIX: the previous version required a literal trailing
     * "l" for the litres branch, so common European/local notations like
     * "1.4T" (turbo) or "2.0TDI" (diesel) — which end in a letter OTHER
     * than "l" — fell through every branch and returned null, despite a
     * docblock comment claiming the turbo suffix was handled. It also had
     * no branch at all for a bare integer with no unit and no decimal
     * point (e.g. "1998" or "2000" — a very common plain export format),
     * which likewise returned null instead of being read as already-cc.
     *
     * New approach: pull out the leading numeric token regardless of what
     * follows it, then classify cc-vs-litres by (a) an explicit "cc"
     * marker anywhere in the cell, (b) a decimal point (litres are always
     * written with one in practice — "2.0", never "2"), or (c) magnitude
     * (a unitless whole number under 20 is read as litres, 20+ as cc).
     */
    private function parseEngineCapacityCc(string $raw): ?int
    {
        $v = strtolower(trim($raw));
        if ($v === '') return null;

        if (!preg_match('/(\d+(?:\.\d+)?)/', $v, $m)) {
            return null;
        }
        $num           = (float) $m[1];
        $hasDecimal    = str_contains($m[1], '.');
        $hasCcMarker   = str_contains($v, 'cc');

        if ($hasCcMarker) {
            return (int) round($num);
        }
        if ($hasDecimal || $num < 20) {
            return (int) round($num * 1000);
        }
        return (int) round($num);
    }

    private function normalizeInduction(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return null;
        return match (true) {
            str_contains($v, 'twin')                          => 'twin_turbo',
            str_contains($v, 'turbo')                         => 'turbo',
            str_contains($v, 'supercharg')                    => 'supercharged',
            str_contains($v, 'na') || str_contains($v, 'naturally') => 'na',
            default                                           => null,
        };
    }

    /**
     * Prefers an explicit kW value; falls back to converting hp if that's
     * all the CSV provides (kW = hp x 0.7457).
     */
    private function parsePowerKw(string $rawKw, string $rawHp): ?int
    {
        $kw = $this->parseNumber($rawKw);
        if ($kw !== null) {
            return (int) round($kw);
        }
        $hp = $this->parseNumber($rawHp);
        if ($hp !== null) {
            return (int) round($hp * 0.7457);
        }
        return null;
    }

    private function normalizeServiceHistory(string $raw): string
    {
        $v = strtolower(trim($raw));
        return match (true) {
            str_contains($v, 'full')            => 'full',
            str_contains($v, 'partial')         => 'partial',
            str_contains($v, 'none') || $v === 'no' => 'none',
            default                             => 'unknown',
        };
    }

    private function normalizeWarrantyType(string $raw): string
    {
        $v = strtolower(trim($raw));
        return match (true) {
            $v === '' || str_contains($v, 'none') || $v === 'no' => 'none',
            str_contains($v, 'manufacturer') || str_contains($v, 'oem') => 'manufacturer',
            str_contains($v, 'extended')      => 'extended',
            str_contains($v, 'dealer')        => 'dealer',
            default                           => 'dealer', // non-empty free text we can't classify — assume dealer-provided
        };
    }

    /**
     * Accepts common truthy/falsy tokens; returns $default (as 0/1) when blank.
     */
    private function parseBool(string $raw, bool $default = false): int
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return $default ? 1 : 0;
        }
        return in_array($v, ['1', 'yes', 'true', 'y'], true) ? 1 : 0;
    }

    private function parseSmallUint(string $raw): ?int
    {
        $n = $this->parseNumber($raw);
        return ($n !== null && $n >= 0) ? (int) $n : null;
    }

    private function parseIntOrNull(string $raw): ?int
    {
        $n = $this->parseNumber($raw);
        return $n !== null ? (int) $n : null;
    }

    private function parseDecimal(string $raw): ?float
    {
        return $this->parseNumber($raw);
    }

    /**
     * SANITY-CHECK FIX: PHP's strtotime() interprets slash-separated dates
     * as m/d/Y (US convention) regardless of server locale. A South African
     * CSV writing "06/07/2027" to mean 6 July would silently come back as
     * 7 June instead — wrong data accepted without error, which is worse
     * than a value being dropped. Dates where the day is >12 at least fail
     * safely (invalid month -> null); the dangerous range is day <= 12,
     * where the swap produces a different-but-valid date silently.
     *
     * Fix: detect dd/mm/yyyy or dd-mm-yyyy explicitly first (the
     * convention used throughout this platform) and validate it with
     * checkdate() rather than trusting strtotime's US-centric parsing.
     * Anything else (ISO "2027-06-30", textual "30 June 2027") is
     * unambiguous and safe to hand to strtotime() as before.
     */
    private function parseDateSafe(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') return null;

        if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $v, $m)) {
            [, $day, $month, $year] = $m;
            $dayI = (int) $day;
            $monthI = (int) $month;
            $yearI = (int) $year;
            if (!checkdate($monthI, $dayI, $yearI)) {
                return null; // invalid calendar date — don't guess further
            }
            return sprintf('%04d-%02d-%02d', $yearI, $monthI, $dayI);
        }

        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}
