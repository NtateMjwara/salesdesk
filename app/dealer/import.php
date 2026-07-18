<?php
/**
 * SalesDesk — CSV Inventory Import (dealer-facing).
 * T3 owns this file (mirrors app/dealer/car-upload.php's ownership/pattern).
 *
 * Lets a dealer principal upload a CSV export (from cars.co.za, AutoTrader,
 * their own DMS, or a hand-built spreadsheet) and bulk-import listings via
 * CsvImporter (includes/importers/CsvImporter.php).
 *
 * SCOPE / KNOWN LIMITATIONS (read before extending this file):
 *
 *   1. SYNCHRONOUS ONLY. sync() runs inline within this request — there is
 *      no job queue in this codebase yet. CsvImporter downloads every car's
 *      images over the network (via ImageRehostService) as part of the same
 *      call, so a large file can take a genuinely long time: a 100-row CSV
 *      averaging 5 images each is ~500 sequential HTTP downloads. This page
 *      mitigates that two ways — a hard row-count cap (MAX_IMPORT_ROWS
 *      below) so worst-case time stays bounded, and set_time_limit() raised
 *      well past PHP's typical 30s default. It does NOT make this scale to
 *      thousands of rows. A production-grade version of this page should
 *      hand the file off to a background worker/cron and have the dealer
 *      poll vehicle_imports.status instead of waiting on the request — that
 *      infrastructure doesn't exist yet in this codebase, so it isn't built
 *      here. Flagging it rather than quietly shipping something that will
 *      time out on a real dealer's real export.
 *
 *   2. DEALER-PRINCIPAL ONLY. Unlike car-upload.php, this does not branch
 *      for sales_exec — bulk-importing a dealer's entire stock in one shot
 *      felt like a decision that shouldn't be silently extended to execs
 *      without that being a deliberate product call. Easy to mirror the
 *      exec-guard pattern from car-upload.php later if that's wanted.
 *
 *   3. NO DRY-RUN / PREVIEW. CsvImporter's sync() always persists — there's
 *      no "show me what would happen first" mode. parseVehicle() is a pure
 *      function so a preview step is buildable later without touching the
 *      interface, just not included in this pass.
 *
 *   4. ALTERNATIVE SOURCE: app/dealer/import-website.php offers a second
 *      import path (reads schema.org Vehicle markup straight from the
 *      dealer's own website via WebsiteImporter) for dealers whose stock
 *      list only lives on their site, with no CSV export to hand. Both
 *      pages write to the same vehicle_imports table and share dedup logic
 *      via includes/importers/PersistsVehicleImports.php, so switching
 *      between the two — or using both over time — behaves consistently.
 *
 * File storage: uploaded CSVs are saved under storage/imports/{dealer_id}/,
 * NOT under uploads/ — uploads/ is served directly by .htaccess with no
 * access control (correct for public car photos, wrong for a dealer's raw
 * stock list, which can carry VINs, internal stock numbers, and commission
 * strategy). storage/ must be added to .htaccess's existing blocked-path
 * rule alongside includes/vendor/db/views — see the deployment note at the
 * bottom of this file's docblock.
 *
 * .HTACCESS CHANGE REQUIRED — add "storage" to the existing block rule:
 *   RewriteRule ^(includes|vendor|db|views)/      - [F,L]
 *   becomes:
 *   RewriteRule ^(includes|vendor|db|views|storage)/  - [F,L]
 * Without this, imported CSVs (potentially containing VINs, stock numbers,
 * pricing/commission data) would be directly downloadable by anyone who
 * finds or guesses the URL, the same way uploads/ files already are.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/importers/CsvImporter.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

const MAX_IMPORT_ROWS  = 300;                // keeps worst-case sync() time bounded — see docblock
const MAX_IMPORT_BYTES = 5 * 1024 * 1024;    // 5MB

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

$dealerStmt = $pdo->prepare("
    SELECT id AS dealer_id, company_name FROM dealers WHERE user_id = ? AND is_active = 1
");
$dealerStmt->execute([$userId]);
$dealerRow = $dealerStmt->fetch();
if (!$dealerRow) redirect('/app/dealer/dashboard.php');
$dealerId = (int) $dealerRow['dealer_id'];

$csrf   = generateCSRFToken();
$error  = '';
$result = null;

// ── CSV template download ───────────────────────────────────────
// Not gated behind CSRF/POST — it's a static, read-only download.
if (($_GET['action'] ?? '') === 'template') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salesdesk-import-template.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'make', 'model', 'variant', 'year', 'price', 'mileage', 'condition',
        'body_type', 'colour', 'transmission', 'fuel_type', 'drivetrain',
        'description', 'vin', 'stock_number', 'commission_type', 'commission_value',
        'image_urls', 'mm_code', 'engine_capacity', 'power_kw', 'previous_owners',
        'service_history', 'warranty_type',
    ]);
    fputcsv($out, [
        'Toyota', 'Corolla Cross', '1.8 XR CVT', '2023', '389000', '18500', 'used',
        'SUV', 'Pearl White', 'Automatic', 'Petrol', 'FWD',
        'One owner, full service history.', '', 'TC-2301', 'fixed', '8000',
        'https://example.com/photo1.jpg | https://example.com/photo2.jpg', '',
        '1798', '103', '1', 'full', 'manufacturer',
    ]);
    fclose($out);
    exit;
}

// ── Handle import ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    validateCSRF();

    $defaultCommissionType  = in_array($_POST['default_commission_type'] ?? '', ['fixed', 'percentage'], true)
        ? $_POST['default_commission_type'] : 'fixed';
    $defaultCommissionValue = (float) str_replace([',', ' '], '', $_POST['default_commission_value'] ?? '0');

    if (empty($_FILES['csv_file'])) {
        $error = 'Please choose a CSV file to upload.';
    } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        // Give a specific reason where we can — UPLOAD_ERR_INI_SIZE in
        // particular is a common real-world failure independent of this
        // page's own MAX_IMPORT_BYTES check: if php.ini's
        // upload_max_filesize or post_max_size is set lower than 5MB, PHP
        // rejects the file before this script ever sees it, and a generic
        // "please choose a file" message would be actively misleading here
        // since the dealer DID choose one.
        $error = match ((int) $_FILES['csv_file']['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'That file is larger than this server currently allows for uploads. Please contact support if this keeps happening.',
            UPLOAD_ERR_PARTIAL =>
                'The upload was interrupted partway through. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION =>
                'A server error prevented this upload. Please try again or contact support.',
            default => 'Could not upload that file. Please try again.',
        };
    } elseif ($defaultCommissionValue <= 0) {
        $error = 'Please set a default commission value — used for any row that doesn\'t specify its own.';
    } elseif ($defaultCommissionType === 'percentage' && $defaultCommissionValue > 30) {
        $error = 'Default percentage commission must be 30% or less.';
    } else {
        $tmpPath  = $_FILES['csv_file']['tmp_name'];
        $origName = $_FILES['csv_file']['name'];
        $size     = (int) $_FILES['csv_file']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // MIME detection for CSV is notoriously inconsistent across
        // browsers/OS (text/csv, application/csv, application/vnd.ms-excel
        // from Excel-exported files, or plain text/plain) — check the
        // actual byte content is parseable text rather than trusting any
        // single MIME string.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        $looksLikeText = in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true);

        if ($ext !== 'csv' || !$looksLikeText) {
            $error = 'That doesn\'t look like a CSV file. Please export as .csv and try again.';
        } elseif ($size > MAX_IMPORT_BYTES) {
            $error = 'File is too large (max ' . number_format(MAX_IMPORT_BYTES / 1024 / 1024, 1) . 'MB).';
        } else {
            // Row-count pre-check — keeps worst-case sync() time bounded
            // given there's no background job queue (see file docblock).
            $rowCount = 0;
            if (($h = fopen($tmpPath, 'r')) !== false) {
                while (fgets($h) !== false) $rowCount++;
                fclose($h);
            }
            $rowCount = max(0, $rowCount - 1); // minus header row

            if ($rowCount > MAX_IMPORT_ROWS) {
                $error = "This file has {$rowCount} rows — the limit per upload is " . MAX_IMPORT_ROWS .
                         ' while imports run synchronously. Please split it into smaller files.';
            } else {
                $importDir = dirname(__DIR__, 2) . '/storage/imports/' . $dealerId . '/';
                if (!is_dir($importDir)) mkdir($importDir, 0755, true);
                $savedPath = $importDir . generateUuidV4() . '.csv';

                if (!move_uploaded_file($tmpPath, $savedPath)) {
                    $error = 'Could not save the uploaded file. Please try again.';
                } else {
                    // Large files with many images can genuinely take a
                    // while — see file docblock. This is a stopgap, not a
                    // scalability fix.
                    set_time_limit(300);

                    try {
                        $importer = new CsvImporter($pdo);
                        $result   = $importer->sync($dealerId, $savedPath, [
                            'initiated_by'             => $userId,
                            'default_commission_type'  => $defaultCommissionType,
                            'default_commission_value' => $defaultCommissionValue,
                        ]);
                    } catch (Throwable $e) {
                        // sync() re-throws when the source can't be read at
                        // all (empty file, no header row, etc.) — everything
                        // else (bad rows) is caught internally and reported
                        // per-row in $result['errors'] instead.
                        error_log('[SalesDesk import] ' . $e->getMessage());
                        $error = 'Could not read that file: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ── Import history — shared with website import, distinguished by source ──
try {
    $historyStmt = $pdo->prepare("
        SELECT uuid, status, source_platform, total_rows, imported_count, updated_count,
               skipped_count, failed_count, started_at, completed_at
        FROM vehicle_imports
        WHERE dealer_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $historyStmt->execute([$dealerId]);
    $history = $historyStmt->fetchAll();
} catch (Throwable) {
    $history = [];
}

$pageTitle = 'Import inventory';

ob_start();
?>

<div style="max-width:760px;margin:0 auto;">

  <div style="margin-bottom:1.5rem;">
    <h2 style="font-family:var(--serif);font-size:1.3rem;font-weight:300;margin-bottom:.4rem;">
      Import <em style="font-style:italic;">inventory</em>
    </h2>
    <p style="font-size:13px;color:var(--muted);line-height:1.6;">
      Upload a CSV export from cars.co.za, AutoTrader, your own DMS, or a spreadsheet you've
      built yourself. Matching rows (by VIN or stock number) update existing listings instead
      of duplicating them.
    </p>
    <p style="font-size:12px;color:var(--faint);line-height:1.6;margin-top:8px;">
      <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
      Don't have a CSV handy?
      <a href="/app/dealer/import-website.php" style="color:var(--p);font-weight:600;">Import straight from your website</a>
      instead — no export needed if your site already publishes vehicle listing data.
    </p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem;">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
  <!-- ── Result summary ─────────────────────────────────────── -->
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
      <i class="fa-solid fa-circle-check" style="color:var(--green);"></i>
      Import complete
    </h3>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1rem;">
      <div style="background:var(--gr-bg);border:1px solid var(--gr-b);border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--green);"><?= (int) $result['imported'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">New</div>
      </div>
      <div style="background:var(--p-light);border:1px solid var(--p-b);border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--p);"><?= (int) $result['updated'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Updated</div>
      </div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--muted);"><?= (int) $result['skipped'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Skipped</div>
      </div>
      <div style="background:<?= $result['failed'] > 0 ? 'var(--red-bg)' : 'var(--bg)' ?>;
                  border:1px solid <?= $result['failed'] > 0 ? 'var(--red-b)' : 'var(--border)' ?>;
                  border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;
                    color:<?= $result['failed'] > 0 ? 'var(--red)' : 'var(--muted)' ?>;"><?= (int) $result['failed'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Failed</div>
      </div>
    </div>

    <?php if (!empty($result['errors'])): ?>
    <div style="font-size:12px;font-weight:700;color:var(--red);margin-bottom:6px;">
      Row-level errors <?= count($result['errors']) > 50 ? '(showing first 50 of ' . count($result['errors']) . ')' : '' ?>
    </div>
    <div style="max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r-md);">
      <?php foreach (array_slice($result['errors'], 0, 50) as $i => $err): ?>
      <div style="display:flex;gap:10px;padding:8px 12px;font-size:12px;
                  <?= $i > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
        <span style="font-family:var(--mono);color:var(--faint);flex-shrink:0;">Row <?= (int) $err['row'] ?></span>
        <span style="color:var(--text2);"><?= htmlspecialchars($err['message']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:1rem;">
      <a href="/app/dealer/inventory.php" class="btn btn-primary">View inventory <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Upload form ────────────────────────────────────────── -->
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <form method="POST" enctype="multipart/form-data" id="importForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="import">

      <div class="fgroup" style="margin-bottom:1rem;">
        <label class="flabel" for="csv_file">CSV file *</label>
        <input class="finput" type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
        <p style="font-size:11px;color:var(--faint);margin-top:6px;">
          Max <?= number_format(MAX_IMPORT_BYTES / 1024 / 1024, 1) ?>MB, up to <?= number_format(MAX_IMPORT_ROWS) ?> rows per upload.
          <a href="?action=template" style="color:var(--p);font-weight:600;">Download a template</a>
          to see the expected columns.
        </p>
      </div>

      <div class="frow" style="margin-bottom:1rem;">
        <div class="fgroup">
          <label class="flabel" for="default_commission_type">Default commission type</label>
          <select class="finput" id="default_commission_type" name="default_commission_type">
            <option value="fixed">Fixed (R)</option>
            <option value="percentage">Percentage (%)</option>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="default_commission_value">Default commission value *</label>
          <input class="finput" type="number" id="default_commission_value" name="default_commission_value"
                 min="0.01" step="any" placeholder="e.g. 8000" required>
        </div>
      </div>
      <p style="font-size:11px;color:var(--faint);margin-bottom:1.25rem;">
        Used for any row in your CSV that doesn't specify its own commission_type/commission_value.
      </p>

      <button class="btn btn-primary btn-full" type="submit" id="importSubmitBtn">
        <i class="fa-solid fa-file-import"></i> Import CSV
      </button>
    </form>
  </div>

  <!-- ── Recent imports (shared history — CSV + website) ─────── -->
  <?php if (!empty($history)): ?>
  <div class="card card-body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;">Recent imports</h3>
    <div style="border:1px solid var(--border);border-radius:var(--r-md);overflow:hidden;">
      <?php foreach ($history as $i => $run): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;
                  <?= $i > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
        <div>
          <div style="font-size:12px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:6px;">
            <i class="fa-solid <?= $run['source_platform'] === 'website' ? 'fa-globe' : 'fa-file-csv' ?>"
               style="font-size:10px;color:var(--faint);"></i>
            <?= htmlspecialchars(date('d M Y, H:i', strtotime($run['started_at']))) ?>
          </div>
          <div style="font-size:11px;color:var(--faint);">
            <?= (int) $run['total_rows'] ?> rows
            — <?= (int) $run['imported_count'] ?> new,
            <?= (int) $run['updated_count'] ?> updated,
            <?= (int) $run['failed_count'] ?> failed
          </div>
        </div>
        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
                     padding:3px 9px;border-radius:var(--r-full);
                     <?= match($run['status']) {
                       'completed' => 'background:var(--gr-bg);color:var(--green);border:1px solid var(--gr-b);',
                       'failed'    => 'background:var(--red-bg);color:var(--red);border:1px solid var(--red-b);',
                       default     => 'background:var(--amb-bg);color:var(--amber);border:1px solid var(--amb-b);',
                     } ?>">
          <?= htmlspecialchars($run['status']) ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- ── Submit-time processing overlay ─────────────────────────
     This is a synchronous request (see file docblock) — the page will
     genuinely just sit on the POST until sync() finishes server-side.
     This overlay is purely to stop the dealer thinking the click didn't
     register and to discourage double-submission; it does not make the
     underlying wait any shorter. -->
<div id="importOverlay" style="display:none;position:fixed;inset:0;background:rgba(255,255,255,.92);
     z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;">
  <i class="fa-solid fa-circle-notch fa-spin" style="font-size:32px;color:var(--p);"></i>
  <div style="font-size:14px;font-weight:600;color:var(--text);">Importing your inventory…</div>
  <div style="font-size:12px;color:var(--muted);max-width:320px;text-align:center;">
    This can take a few minutes for larger files with lots of photos. Please don't close this tab.
  </div>
</div>
<script>
document.getElementById('importForm').addEventListener('submit', function () {
  var fileInput = document.getElementById('csv_file');
  if (!fileInput.files.length) return; // let native required-field validation handle it
  document.getElementById('importSubmitBtn').disabled = true;
  document.getElementById('importOverlay').style.display = 'flex';
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
