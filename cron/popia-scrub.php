<?php
/**
 * SalesDesk — POPIA Buyer Data Scrub Cron Job
 * T1 owns this file.
 *
 * Run weekly via crontab (Sundays at 02:00):
 *   0 2 * * 0 php /var/www/salesdesk/cron/popia-scrub.php >> /var/log/salesdesk/popia.log 2>&1
 *
 * What this does:
 *   Nullifies personal buyer data from leads older than the
 *   POPIA retention period (platform_config.popia_retention_days,
 *   default 365 days per D-07).
 *
 *   Fields nullified (buyer personally identifiable info):
 *     buyer_name, buyer_phone, buyer_email, buyer_message
 *
 *   NOT deleted or changed:
 *     - lead row itself (attribution, financial records preserved)
 *     - broker_id, dealer_id, car_id (immutable attribution)
 *     - consent_given, consent_at (legal record of consent)
 *     - status, commission data (financial audit trail)
 *
 *   Every run writes to popia_audit with record counts and timestamp.
 *
 * South African POPIA compliance note:
 *   This implements the "storage limitation" principle — personal data
 *   may not be retained longer than necessary for the purpose for which
 *   it was collected. The 365-day default covers the dealership's
 *   reasonable after-sale contact window. Legal team must confirm
 *   the exact retention period before go-live.
 *
 * Idempotency:
 *   Only targets rows where buyer_name IS NOT NULL (i.e., not yet scrubbed).
 *   Safe to re-run — already-scrubbed rows are untouched.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/functions.php';

$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("POPIA scrub started.");

try {
    $pdo = Database::getInstance();

    // ── Read retention period ────────────────────────────────────
    $retentionDays = getPlatformConfigInt('popia_retention_days', DEFAULT_POPIA_RETENTION_DAYS);
    $log("Retention period: {$retentionDays} days.");

    // ── Dry-run count first (log without touching) ───────────────
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS due
        FROM leads
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
          AND buyer_name IS NOT NULL
    ");
    $countStmt->execute([$retentionDays]);
    $due = (int) $countStmt->fetchColumn();
    $log("Records due for scrubbing: {$due}.");

    if ($due === 0) {
        // Nothing to scrub — log the run and exit cleanly.
        $pdo->prepare("
            INSERT INTO popia_audit (run_at, records_scrubbed, operator, notes)
            VALUES (NOW(), 0, 'cron', ?)
        ")->execute(["No records due. Retention: {$retentionDays} days."]);

        $log("Nothing to scrub. Run recorded.");
        exit(0);
    }

    // ── Perform scrub in batches to avoid long table locks ───────
    $batchSize     = 500;
    $totalScrubbed = 0;
    $batchNum      = 0;

    do {
        $scrubStmt = $pdo->prepare("
            UPDATE leads
            SET
                buyer_name    = NULL,
                buyer_phone   = NULL,
                buyer_email   = NULL,
                buyer_message = NULL,
                updated_at    = NOW()
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
              AND buyer_name IS NOT NULL
            LIMIT ?
        ");
        $scrubStmt->execute([$retentionDays, $batchSize]);
        $rowsAffected = $scrubStmt->rowCount();
        $totalScrubbed += $rowsAffected;
        $batchNum++;

        $log("Batch {$batchNum}: scrubbed {$rowsAffected} record(s) (total: {$totalScrubbed}).");

        // Pause briefly between batches to reduce DB pressure.
        if ($rowsAffected === $batchSize) {
            usleep(100_000); // 100ms
        }

    } while ($rowsAffected === $batchSize);

    // ── Write audit record ────────────────────────────────────────
    $pdo->prepare("
        INSERT INTO popia_audit (run_at, records_scrubbed, operator, notes)
        VALUES (NOW(), ?, 'cron', ?)
    ")->execute([
        $totalScrubbed,
        "Retention: {$retentionDays} days. Batches: {$batchNum}.",
    ]);

    $log("Scrub complete. Total records scrubbed: {$totalScrubbed}.");

} catch (Throwable $e) {
    error_log('[SalesDesk popia-scrub] ' . $e->getMessage());
    echo '[FATAL] ' . $e->getMessage() . PHP_EOL;

    // Still try to record the failed run in popia_audit.
    try {
        $pdo->prepare("
            INSERT INTO popia_audit (run_at, records_scrubbed, operator, notes)
            VALUES (NOW(), 0, 'cron', ?)
        ")->execute(['FAILED: ' . substr($e->getMessage(), 0, 200)]);
    } catch (Throwable) {
        // Audit write failed too — already logged above.
    }

    exit(1);
}
