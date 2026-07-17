<?php
/**
 * SalesDesk — Inventory Importer contract.
 *
 * Every inventory source (CSV today; a dealer's own API, a dealer
 * website, or a marketplace feed later) implements this interface so
 * the rest of the platform — the admin import UI, a future sync
 * scheduler, the audit trail — never needs to know which source it's
 * talking to. New sources are added by writing one more class, not by
 * touching this file or the admin UI.
 *
 * Lifecycle for one call to sync():
 *   1. crawlDealership() — gather raw rows/records from the source.
 *   2. parseVehicle()    — normalize ONE raw row into the canonical
 *                          `cars` table shape (see CsvImporter::REQUIRED_FIELDS
 *                          and the normalize*() helpers for the mapping rules).
 *   3. sync()            — orchestrates 1+2, de-dupes against existing
 *                          `cars` rows (by VIN, then by source+source_ref),
 *                          persists, and logs the whole run to
 *                          `vehicle_imports` (see db/0003_inventory_import.sql).
 *
 * Implementations MUST NOT write directly to $_SESSION, echo HTML, or
 * call exit/redirect() — they are called from the web admin UI today
 * and should be safely callable from a CLI/cron context later without
 * any changes.
 */

interface InventoryImporterInterface
{
    /**
     * Short identifier for this importer, stored in cars.source and
     * vehicle_imports.source — e.g. 'csv', 'cars_co_za', 'dealer_api'.
     */
    public function getSourceName(): string;

    /**
     * Gather raw vehicle records from the source. Pure I/O — no DB writes,
     * no normalization. Throws if the source itself can't be read at all
     * (bad file, unreachable feed) — row-level problems are handled later
     * in parseVehicle()/sync(), not here.
     *
     * @param string $sourceRef Source-specific locator — a file path for
     *                          CsvImporter, a dealership URL for a future
     *                          scraper-based importer, a feed URL for an API.
     * @return array<int, array<string, mixed>> List of raw, un-normalized rows.
     *
     * @throws RuntimeException if the source cannot be read at all.
     */
    public function crawlDealership(string $sourceRef): array;

    /**
     * Normalize ONE raw row into the canonical vehicle shape. Pure
     * transformation only — must not touch the database.
     *
     * @param array<string, mixed> $rawRow
     * @return array<string, mixed> Canonical fields matching the `cars`
     *                              columns this importer knows how to fill.
     *
     * @throws InvalidArgumentException on unrecoverable validation failure
     *         for this row (missing required field, bad type, etc). The
     *         caller (sync()) is expected to catch this per-row and keep going.
     */
    public function parseVehicle(array $rawRow): array;

    /**
     * Run a full import: crawl -> parse -> dedup -> persist -> log.
     * A single bad row must never abort the whole run.
     *
     * @param int    $dealerId
     * @param string $sourceRef Same locator passed to crawlDealership().
     * @param array  $options   Importer-specific options — see CsvImporter
     *                          for the options it accepts.
     * @return array{
     *   import_id: int,
     *   total: int,
     *   imported: int,
     *   updated: int,
     *   skipped: int,
     *   failed: int,
     *   errors: array<int, array{row:int, message:string}>
     * }
     */
    public function sync(int $dealerId, string $sourceRef, array $options = []): array;
}
