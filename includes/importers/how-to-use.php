<?php
/**
 * Example — run from CLI (or wire into a page like app/dealer/import-website.php).
 *
 * IMPORTANT #1: $dealerId must be a real, already-registered SalesDesk dealer —
 * this importer never creates dealer records (see MotusApiImporter's
 * docblock for why). In a real page this comes from the logged-in
 * session, exactly like WebsiteImporter/CsvImporter — it is hardcoded
 * here only because this is a standalone CLI example.
 *
 * IMPORTANT #2: 'motus_dealer_name' is REQUIRED. Confirmed against a real
 * production payload: a Motus showroom listing page's embedded vehicle
 * data spans EVERY branch in the group (one real sample had 810 vehicles
 * across 10 different dealerships), not just the one whose URL you
 * fetched. Omitting this option throws immediately, before any network
 * request — it must match a dealerName exactly as it appears on the
 * Motus site (case-insensitive), e.g. "Motus VW Midrand". If it doesn't
 * match anything, the exception message lists every branch name actually
 * found in the data, so you can copy the correct one.
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/MotusApiImporter.php';

$db       = Database::getInstance();
$dealerId = 4; // must already exist in `dealers` — this importer will not create one

$importer = new MotusApiImporter($db);

$result = $importer->sync($dealerId, 'https://www.motusvw.co.za/midrand/showroom/', [
    'initiated_by'             => null, // pass the acting user_id if run from a web request
    'default_commission_type'  => 'fixed',
    'default_commission_value' => 5000.0,
    'motus_dealer_name'        => 'Motus VW Midrand', // REQUIRED — see IMPORTANT #2 above
    // 'time_budget_seconds'   => 35, // override if your gateway timeout allows more
]);

echo "Imported: {$result['imported']} new, {$result['updated']} updated, "
   . "{$result['skipped']} skipped, {$result['failed']} failed.\n";

if ($result['time_boxed']) {
    echo "Note: stopped early within the safety time window — re-run to pick up any remaining vehicles.\n";
}

if (!empty($result['errors'])) {
    echo "Errors:\n";
    foreach ($result['errors'] as $err) {
        echo "  Row {$err['row']}: {$err['message']}\n";
    }
}
