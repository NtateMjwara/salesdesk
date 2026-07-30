<?php
/**
 * SalesDesk — Shared vehicle-import persistence.
 *
 * WHY THIS EXISTS:
 *   CsvImporter and WebsiteImporter each independently implemented
 *   near-identical dedup (VIN/stock-number), slug generation, image
 *   re-hosting, and vehicle_imports run-tracking logic. Two copies of the
 *   same DB logic is exactly the failure mode that let CsvImporter and
 *   c/index.php's whitelist drift apart before (see the docblock on
 *   NormalizesVehicleFields.php) — this trait applies the same fix to the
 *   persistence layer up front instead of waiting for the two copies to
 *   quietly disagree later.
 *
 * REQUIRES the host class to:
 *   - declare `private PDO $pdo`
 *   - implement InventoryImporterInterface::getSourceName() — used to
 *     stamp vehicle_imports.source_platform, so each importer doesn't
 *     need to repeat its own source string in two places.
 *
 * NOTE — rehostImages() below also expects a `private ImageRehostService
 * $imageRehost` property. That's only true for hosts that actually call
 * rehostImages() (CsvImporter, WebsiteImporter). MotusApiImporter does
 * NOT declare that property and does NOT call rehostImages() — it stores
 * source image URLs directly instead (see that class's "IMAGE STRATEGY"
 * docblock section) — so this method simply goes unused for that class,
 * with no error, since PHP only resolves $this->imageRehost if the method
 * is actually invoked.
 *
 * insertCar()/updateCar() are deliberately NOT part of this trait — the
 * actual INSERT/UPDATE column lists differ too much between CsvImporter's
 * full engine/warranty/ownership detail columns, WebsiteImporter's
 * narrower Phase 1 field set, and MotusApiImporter's variant/no-rehost
 * shape to be worth forcing into one shared method. Each importer keeps
 * its own; all should bind cars.source_platform to $this->getSourceName()
 * rather than a hardcoded literal, so the value can never drift out of
 * sync with what getSourceName() actually returns.
 */
trait PersistsVehicleImports
{
    /**
     * VIN match first (scoped dealer_id+vin per migration 0011's
     * uq_car_vin_dealer), then dealer_stock_no (scoped dealer_id+
     * dealer_stock_no per uq_car_dealer_stockno) — matches the DB's own
     * uniqueness rules exactly, so no importer can disagree with the
     * schema about what counts as "the same car".
     *
     * @return array{id:int, uuid:string, source_image_urls: array<int,string>}|null
     */
    private function findExistingCar(int $dealerId, ?string $vin, ?string $dealerStockNo): ?array
    {
        if ($vin !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id, uuid, source_image_urls FROM cars WHERE dealer_id = ? AND vin = ? LIMIT 1
            ");
            $stmt->execute([$dealerId, $vin]);
            $row = $stmt->fetch();
            if ($row) {
                return $this->hydrateExistingCarRow($row);
            }
        }

        if ($dealerStockNo !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id, uuid, source_image_urls FROM cars WHERE dealer_id = ? AND dealer_stock_no = ? LIMIT 1
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

    /**
     * Reads the live platform_config value on every call (rather than a
     * hardcoded constant) so an admin changing max_images_per_car takes
     * effect immediately for every importer without a deploy.
     *
     * Used by CsvImporter/WebsiteImporter only — see this trait's
     * docblock note above re: MotusApiImporter, which doesn't call this.
     *
     * @param float|null $deadlineTs Optional wall-clock deadline passed
     *                               through to ImageRehostService::rehost()
     *                               (see that file's DEADLINE-1/-2 notes).
     *                               null (the default) means unlimited —
     *                               CsvImporter, which has no gateway-
     *                               timeout concern of its own, never
     *                               passes one and is unaffected.
     * @param string|null $referer  Optional Referer header for sources with
     *                              referrer-based hotlink protection (see
     *                              ImageRehostService::rehost()'s docblock).
     *                              null (default) sends no Referer, unaffecting
     *                              any importer that doesn't need one.
     * @return array{image_urls: array<int,string>, source_image_urls: array<int,string>}|null
     *         null means "nothing to update" — no source URLs, unchanged
     *         since last run, or every download in the batch failed.
     */
    private function rehostImages(string $carUuid, array $sourceImageUrls, array $previousSourceImageUrls, ?float $deadlineTs = null, ?string $referer = null): ?array
    {
        $maxImages = getPlatformConfigInt('max_images_per_car', 10);
        try {
            return $this->imageRehost->rehost($carUuid, $sourceImageUrls, $previousSourceImageUrls, $maxImages, $deadlineTs, $referer);
        } catch (Throwable $e) {
            // Image re-hosting must never fail the whole car row.
            error_log('[' . static::class . "] Image re-host failed for car {$carUuid}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Slug format: year-make-model-variant-colour-dealername-stocknumber
     * (each segment omitted if not available — see $parts below).
     *
     * dealer name and dealer_stock_no were added specifically to close a
     * cross-dealer slug-collision gap: cars-for-sale/car-detail/index.php
     * resolves a car purely by `WHERE c.slug = ? LIMIT 1`, with no
     * dealer_id in that query at all. Two different dealers' cars could
     * previously share the same year/make/model/colour slug and the
     * detail page could silently resolve to the wrong dealer's vehicle.
     * Folding in the dealer's company name plus their own stock number
     * makes that collision extremely unlikely in practice (though, unlike
     * dealers.slug, dealers.company_name has no DB-level uniqueness
     * constraint, so this is a strong practical mitigation rather than a
     * mathematical guarantee — dealers.slug would be a stronger choice if
     * a guarantee is ever needed instead).
     *
     * The uniqueness check below is deliberately GLOBAL (not scoped to
     * dealer_id, unlike the previous version of this method) for the same
     * reason — checking only within one dealer would miss exactly the
     * cross-dealer collision this change exists to avoid. A global check
     * is strictly stronger than uq_car_dealer_slug(dealer_id, slug), so it
     * can never produce a slug that violates that constraint either.
     */
    private function generateUniqueSlug(
        int $dealerId,
        int $year,
        string $make,
        string $model,
        ?string $variant,
        ?string $colour,
        ?string $dealerStockNo
    ): string {
        $dealerStmt = $this->pdo->prepare("SELECT company_name FROM dealers WHERE id = ?");
        $dealerStmt->execute([$dealerId]);
        $dealerName = $dealerStmt->fetchColumn();
        $dealerName = $dealerName !== false ? (string) $dealerName : '';

        $parts = array_filter(
            [(string) $year, $make, $model, $variant, $colour, $dealerName, $dealerStockNo],
            static fn($p) => $p !== null && $p !== ''
        );

        $base = strtolower(implode('-', $parts));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-');
        $base = preg_replace('/-+/', '-', $base);
        // cars.slug is VARCHAR(100) — leave headroom for a "-NN" de-dup suffix.
        $base = trim(substr($base, 0, 94), '-');

        $slug = $base;
        $n    = 2;
        while (true) {
            $stmt = $this->pdo->prepare("SELECT id FROM cars WHERE slug = ?");
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = substr($base, 0, 94 - strlen((string) $n) - 1) . "-{$n}";
            $n++;
        }
    }

    private function createImportRun(int $dealerId, string $sourceRef, ?int $initiatedBy): int
    {
        $uuid = generateUuidV4();
        $this->pdo->prepare("
            INSERT INTO vehicle_imports
                (uuid, dealer_id, initiated_by, source_platform, source_ref, status, started_at, created_at)
            VALUES (?, ?, ?, ?, ?, 'processing', NOW(), NOW())
        ")->execute([$uuid, $dealerId, $initiatedBy, $this->getSourceName(), $sourceRef]);

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
}
