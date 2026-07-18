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
 * CHANGES IN THIS PASS (adopting shared traits):
 *   REFACTOR-1  normalizeCondition, normalizeCommissionType,
 *               normalizeFuelType, normalizeTransmission,
 *               normalizeDrivetrain, normalizeBodyType, parseNumber,
 *               parseSmallUint, parseIntOrNull, parseDecimal, parseBool,
 *               and parseDateSafe moved to NormalizesVehicleFields — the
 *               same trait WebsiteImporter now uses, so a car imported
 *               from a dealer's website is guaranteed the same vocabulary
 *               as one imported from CSV. Every method is a verbatim copy
 *               of what was here before; nothing about the normalization
 *               rules changed, only where they live.
 *   REFACTOR-2  findExistingCar, hydrateExistingCarRow, rehostImages,
 *               generateUniqueSlug, createImportRun, completeImportRun,
 *               and failImportRun moved to PersistsVehicleImports — same
 *               reasoning, for the dedup/persistence layer instead of the
 *               field-normalization layer.
 *   REFACTOR-3  insertCar()/updateCar() now bind cars.source_platform to
 *               $this->getSourceName() via a placeholder instead of the
 *               literal string 'csv', so it can never drift out of sync
 *               with what getSourceName() actually returns.
 *   insertCar()/updateCar() otherwise stay in THIS class, not the shared
 *   trait — the column lists (engine/warranty/ownership detail columns
 *   this importer alone knows how to fill) differ too much from
 *   WebsiteImporter's narrower Phase 1 field set to be worth merging.
 *
 * Everything below this point (parseVehicle's field mapping, sync()'s
 * orchestration, the CSV-specific normalize-/parse-prefixed helpers that
 * stayed here, header aliasing) is UNCHANGED from the previous version.
 *
 * Expected CSV headers (case-insensitive, aliases in parens). Everything
 * below "status" is optional — omit entirely if your feed doesn't carry
 * it:
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
require_once __DIR__ . '/NormalizesVehicleFields.php';
require_once __DIR__ . '/PersistsVehicleImports.php';
require_once __DIR__ . '/../functions.php';

class CsvImporter implements InventoryImporterInterface
{
    use NormalizesVehicleFields;
    use PersistsVehicleImports;

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

        // Migration 0010 fields — all optional.
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

        // Presence is driven by the REQUIRED_FIELDS constant (previously
        // checked via separate hardcoded if-statements that didn't
        // actually consult the constant). The extra type/range validation
        // for year and price, which presence-checking alone can't cover,
        // stays layered on top immediately after.
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

        $vin = strtoupper($get('vin'));
        if ($vin !== '' && strlen($vin) !== 17) {
            // Don't hard-fail the row over a malformed VIN — it's a nice-to-have
            // dedup/display field, not one of the REQUIRED_FIELDS. Drop it and
            // let the row import without one rather than losing the whole car.
            $vin = '';
        }
        $stockNumber = $get('stock_number');
        $listingId   = $get('listing_id');
        $mmCode      = $get('mm_code');
        // These map to fixed-width VARCHAR columns (mm_code 20,
        // dealer_stock_no 60, source_external_id 100 — see migration
        // 0010) with no length check before insert. Truncating
        // defensively here is safer than losing the whole row over a
        // field that's a dedup/display convenience, not one of
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
    // PERSISTENCE — insertCar/updateCar stay here (see REFACTOR-2 note
    // at the top of this file for why these aren't in the shared trait).
    // dedup (findExistingCar), image re-hosting (rehostImages), slug
    // generation, and the vehicle_imports lifecycle methods now live in
    // PersistsVehicleImports (used above).
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
                    ?, ?, ?, ?,
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

            // REFACTOR-3: bound to getSourceName() instead of a literal 'csv'.
            $this->getSourceName(),
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
        // REFACTOR-3: bound to getSourceName() instead of a literal 'csv'.
        $params[] = $this->getSourceName();
        $params[] = $v['source_external_id'];
        $params[] = $v['dealer_stock_no'];
        $params[] = $importId;
        $params[] = json_encode($rawRow);
        $params[] = $carId;
        $params[] = $dealerId;

        $this->pdo->prepare($sql)->execute($params);
    }

    // ============================================================
    // NORMALIZATION — CSV-specific, stays here (not in the shared
    // trait — these fields don't currently apply to WebsiteImporter's
    // Phase 1 scope; see NormalizesVehicleFields.php's migration note).
    // ============================================================

    private function isBlankRow(array $rawRow): bool
    {
        foreach ($rawRow as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
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
}
