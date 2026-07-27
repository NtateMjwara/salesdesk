<?php
/**
 * SalesDesk — Motus batch-enrichment endpoint.
 * T3 owns this file (mirrors app/dealer/import-motus.php's ownership).
 *
 * Called repeatedly via a short JS polling loop from import-motus.php's
 * UI, after a "discover" pass has already inserted/updated every
 * vehicle's basic fields (with engine_capacity_cc left NULL, meaning
 * "still needs enrichment"). Each call here processes one small, fixed-
 * size batch and returns how many vehicles are still pending — the JS
 * loop keeps calling this until 'remaining' reaches 0.
 *
 * This is what makes a single "Update" click complete a whole day's
 * enrichment without needing cron access or any actual server-side
 * background process: the "background" is really just this endpoint
 * being called every couple of seconds for as long as the dealer's
 * browser tab stays open. If the tab is closed before 'remaining' hits
 * 0, enrichment simply resumes wherever it left off the next time
 * "Update" is clicked (discover() is safe to re-run any time — see its
 * docblock — and already-enriched vehicles are never touched again).
 */

require_once '../../../includes/security.php';
require_once '../../../includes/session.php';
require_once '../../../includes/database.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/csrf.php';
require_once '../../../includes/response.php';
require_once '../../../includes/importers/MotusApiImporter.php';

applyCachePolicy('api');
requireLogin();
requireRole('dealer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required.'], 405);
}
validateCSRF();

if (!checkApiRateLimit($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 'motus_enrich_batch', 30)) {
    rateLimitResponse();
}

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

$dealerStmt = $pdo->prepare("
    SELECT id AS dealer_id FROM dealers WHERE user_id = ? AND is_active = 1
");
$dealerStmt->execute([$userId]);
$dealerRow = $dealerStmt->fetch();
if (!$dealerRow) {
    jsonResponse(['error' => 'No active dealer account found.'], 403);
}
$dealerId = (int) $dealerRow['dealer_id'];

// Small batch, short-per-call time budget — this endpoint is designed to
// return quickly and be called again, not to do everything in one shot.
// 15 vehicles at roughly 1.5-2s each (throttle + real fetch time, per
// everything observed against the real production site) comfortably
// finishes well inside any reasonable request timeout.
const BATCH_SIZE               = 15;
const BATCH_TIME_BUDGET_SECONDS = 25.0;

try {
    $importer = new MotusApiImporter($pdo);
    $result   = $importer->enrichNextBatch($dealerId, BATCH_SIZE, BATCH_TIME_BUDGET_SECONDS);
    jsonResponse($result);
} catch (Throwable $e) {
    error_log('[SalesDesk motus-enrich-batch] ' . $e->getMessage());
    jsonResponse(['error' => 'Something went wrong processing this batch.'], 500);
}
