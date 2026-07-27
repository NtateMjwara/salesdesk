<?php
/**
 * SalesDesk — Motus Platform Inventory Import (dealer-facing).
 * T3 owns this file (mirrors app/dealer/import-website.php's pattern).
 *
 * ARCHITECTURE (unchanged from the previous revision — this pass is UI/UX
 * polish plus surfacing the new sold-vehicle detection, not a logic
 * change):
 *
 *   1. Submitting the form calls MotusApiImporter::discover() — ONE
 *      listing-page fetch, no per-vehicle network calls, so it can never
 *      time out regardless of catalog size. Upserts basic fields for
 *      every vehicle, and marks any previously-active vehicle that's
 *      disappeared from the source as sold (see discover()'s docblock).
 *   2. A JS polling loop then calls api/motus-enrich-batch.php repeatedly,
 *      a small batch (~15 vehicles) at a time, until nothing's left — no
 *      further clicks needed, no cron required. Closing the tab early
 *      just means enrichment resumes wherever it left off next time
 *      "Import" is clicked.
 *
 * SCOPE / KNOWN LIMITATIONS (mirrors import-website.php's docblock,
 * adjusted for what's different here):
 *
 *   1. THE INPUT IS A LISTING PAGE, NOT A VEHICLE PAGE. See the form's
 *      own help text below. (A single vehicle detail-page URL still
 *      works via crawlDealership()'s single-permalink fallback, but
 *      that's an internal robustness feature, not a distinct UI option.)
 *   2. A BRANCH/DEALER NAME IS REQUIRED — a listing page's data spans
 *      every branch in the group, not just one dealership.
 *   3. COMMISSION IS STICKY — set once on first import, never reset by a
 *      later re-import (see MotusApiImporter::updateCar()'s docblock).
 *   4. SOLD-VEHICLE DETECTION runs every discover() call against a FULL
 *      branch crawl only — a vehicle no longer present in the source is
 *      marked sold (never deleted; sold_at is stamped). This is
 *      deliberately conservative: only ever moves active -> sold, never
 *      touches a manually-paused car, and refuses to run at all if the
 *      crawl itself came back empty (see markMissingAsSold()'s docblock).
 *   5. FULL GALLERY, NOT JUST A COVER PHOTO, stored as direct dealer-CDN
 *      URLs — see MotusApiImporter's "IMAGE STRATEGY" docblock.
 *   6. SAME OWNERSHIP CONFIRMATION GATE, DEALER-PRINCIPAL ONLY — same
 *      reasoning as the other two import pages.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/importers/MotusApiImporter.php';

// Discovery is one page fetch + JSON parsing — this is a generous
// backstop, not a real constraint.
const MOTUS_DISCOVER_TIME_BUDGET_SECONDS = 25;

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

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

/**
 * TEMPORARY DIAGNOSTIC — mirrors MotusApiImporter's own debugLog() so an
 * exception caught here (outside that class entirely) still ends up in
 * the same, actually-readable-in-a-browser log file.
 */
function motusWriteDebugLog(string $message): void
{
    @file_put_contents(
        __DIR__ . '/../../includes/importers/motus-debug.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ── Handle discovery (fast: basic fields only, no per-vehicle fetches) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    validateCSRF();

    $showroomUrl             = trim($_POST['showroom_url'] ?? '');
    $branchName              = trim($_POST['branch_name'] ?? '');
    $confirmedOwnership      = !empty($_POST['confirm_ownership']);
    $defaultCommissionType   = in_array($_POST['default_commission_type'] ?? '', ['fixed', 'percentage'], true)
        ? $_POST['default_commission_type'] : 'fixed';
    $defaultCommissionValue  = (float) str_replace([',', ' '], '', $_POST['default_commission_value'] ?? '0');

    if ($showroomUrl === '') {
        $error = 'Please enter your Motus showroom listing page address.';
    } elseif (!preg_match('#^https?://#i', $showroomUrl)) {
        $error = 'That doesn\'t look like a web address. Try something like "https://www.motusvw.co.za/midrand/showroom/".';
    } elseif ($branchName === '') {
        $error = 'Please enter your dealership\'s exact branch name as it appears on the Motus site (e.g. "Motus VW Midrand").';
    } elseif (!$confirmedOwnership) {
        $error = 'Please confirm this is your own dealership\'s stock before importing from it.';
    } elseif ($defaultCommissionValue <= 0) {
        $error = 'Please set a default commission value — Motus listing pages don\'t carry commission data of their own.';
    } elseif ($defaultCommissionType === 'percentage' && $defaultCommissionValue > 30) {
        $error = 'Default percentage commission must be 30% or less.';
    } else {
        set_time_limit(60);

        try {
            $importer = new MotusApiImporter($pdo);
            $result   = $importer->discover($dealerId, $showroomUrl, [
                'initiated_by'             => $userId,
                'default_commission_type'  => $defaultCommissionType,
                'default_commission_value' => $defaultCommissionValue,
                'time_budget_seconds'      => MOTUS_DISCOVER_TIME_BUDGET_SECONDS,
                'motus_dealer_name'        => $branchName,
            ]);

            writeAuditLog(
                'vehicle_import.ownership_confirmed',
                'dealer',
                $dealerId,
                null,
                ['source_url' => $showroomUrl, 'source_platform' => 'motus'],
                $userId
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            error_log('[SalesDesk motus-import] ' . $e->getMessage());
            motusWriteDebugLog('[import-motus.php] ' . get_class($e) . ': ' . $e->getMessage());
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[SalesDesk motus-import] ' . $e->getMessage());
            motusWriteDebugLog(
                "[import-motus.php] UNCAUGHT " . get_class($e) . ": " . $e->getMessage() .
                " in " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString()
            );
            $error = 'Something went wrong importing from that page. Please try again or contact support.';
        }
    }
}

// ── Import history — shared across all three import methods ────────
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

/** Tiny relative-time helper for the history list — "2h ago" reads
 * friendlier than a bare timestamp when scanning a list at a glance. */
function motusTimeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

$pageTitle = 'Import from Motus';

ob_start();
?>

<style>
/* ── Scoped to this page — small, purposeful additions only ─────────── */
@keyframes motusFadeSlideIn {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}
.motus-animate-in { animation: motusFadeSlideIn .35s ease both; }

@keyframes motusShimmer {
  0%   { background-position: -120px 0; }
  100% { background-position: 120px 0; }
}
.motus-bar-active {
  background-image: linear-gradient(
    90deg,
    var(--p) 0%, var(--p) 40%,
    #6a93cf 50%,
    var(--p) 60%, var(--p) 100%
  );
  background-size: 200px 100%;
  animation: motusShimmer 1.6s linear infinite;
}
.motus-bar-done {
  background: var(--green) !important;
  animation: none !important;
}

.motus-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  flex: 1;
  position: relative;
}
.motus-step-circle {
  width: 34px; height: 34px;
  border-radius: 50%;
  background: var(--p-light);
  border: 1.5px solid var(--p-b);
  color: var(--p);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  margin-bottom: 8px;
  flex-shrink: 0;
}
.motus-step-line {
  position: absolute;
  top: 17px;
  left: calc(50% + 24px);
  right: calc(-50% + 24px);
  height: 1.5px;
  background: var(--border);
}
.motus-step:last-child .motus-step-line { display: none; }
.motus-step-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text2);
}
.motus-step-sub {
  font-size: 10px;
  color: var(--faint);
  margin-top: 2px;
  max-width: 110px;
}

.motus-stat {
  border-radius: var(--r-md);
  padding: 12px;
  text-align: center;
  transition: transform .15s ease;
}
</style>

<div style="max-width:760px;margin:0 auto;">

  <div style="margin-bottom:1.5rem;">
    <h2 style="font-family:var(--serif);font-size:1.3rem;font-weight:300;margin-bottom:.4rem;">
      Import from <em style="font-style:italic;">Motus</em>
    </h2>
    <p style="font-size:13px;color:var(--muted);line-height:1.6;">
      If your stock is listed on a Motus Group dealer site (motusvw.co.za and similar), enter your
      showroom's listing page below. Matching vehicles (by stock number) update existing listings
      instead of duplicating them — and vehicles that disappear from the source get marked sold
      automatically.
    </p>
    <p style="font-size:12px;color:var(--faint);line-height:1.6;margin-top:8px;">
      <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
      Not on the Motus platform? Try
      <a href="/app/dealer/import-website.php" style="color:var(--p);font-weight:600;">website import</a>
      or <a href="/app/dealer/import.php" style="color:var(--p);font-weight:600;">CSV import</a> instead.
    </p>
  </div>

  <!-- ── How it works — 3-step explainer, shown when there's no active
       result to look at yet, so first-time dealers know what to expect
       before they click anything ────────────────────────────────────── -->
  <?php if ($result === null): ?>
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <div style="display:flex;gap:8px;">
      <div class="motus-step">
        <div class="motus-step-line"></div>
        <div class="motus-step-circle"><i class="fa-solid fa-list"></i></div>
        <div class="motus-step-label">1. Discover</div>
        <div class="motus-step-sub">Reads your listing page — takes a few seconds</div>
      </div>
      <div class="motus-step">
        <div class="motus-step-line"></div>
        <div class="motus-step-circle"><i class="fa-solid fa-gear"></i></div>
        <div class="motus-step-label">2. Enrich</div>
        <div class="motus-step-sub">Fills in specs &amp; photos automatically, a few at a time</div>
      </div>
      <div class="motus-step">
        <div class="motus-step-circle" style="background:var(--gr-bg);border-color:var(--gr-b);color:var(--green);">
          <i class="fa-solid fa-check"></i>
        </div>
        <div class="motus-step-label">3. Done</div>
        <div class="motus-step-sub">No need to click anything else</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-error motus-animate-in" style="margin-bottom:1.25rem;">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= nl2br(htmlspecialchars($error)) ?>
  </div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
  <!-- ── Discovery result + enrichment progress ─────────────────── -->
  <div class="card card-body motus-animate-in" style="margin-bottom:1.5rem;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
      <i class="fa-solid fa-circle-check" style="color:var(--green);"></i>
      Found <?= (int) $result['total'] ?> vehicle<?= $result['total'] === 1 ? '' : 's' ?>
    </h3>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1rem;">
      <div class="motus-stat" style="background:var(--gr-bg);border:1px solid var(--gr-b);">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--green);"><?= (int) $result['imported'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">New</div>
      </div>
      <div class="motus-stat" style="background:var(--p-light);border:1px solid var(--p-b);">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--p);"><?= (int) $result['updated'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Updated</div>
      </div>
      <div class="motus-stat" style="background:<?= $result['marked_sold'] > 0 ? 'var(--amb-bg)' : 'var(--bg)' ?>;
                  border:1px solid <?= $result['marked_sold'] > 0 ? 'var(--amb-b)' : 'var(--border)' ?>;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;
                    color:<?= $result['marked_sold'] > 0 ? 'var(--amber)' : 'var(--muted)' ?>;"><?= (int) $result['marked_sold'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Sold</div>
      </div>
      <div class="motus-stat" style="background:<?= $result['failed'] > 0 ? 'var(--red-bg)' : 'var(--bg)' ?>;
                  border:1px solid <?= $result['failed'] > 0 ? 'var(--red-b)' : 'var(--border)' ?>;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;
                    color:<?= $result['failed'] > 0 ? 'var(--red)' : 'var(--muted)' ?>;"><?= (int) $result['failed'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Failed</div>
      </div>
    </div>

    <?php if ((int) $result['marked_sold'] > 0): ?>
    <div style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--text2);
                background:var(--amb-bg);border:1px solid var(--amb-b);border-radius:var(--r-md);
                padding:10px 12px;margin-bottom:1rem;line-height:1.6;">
      <i class="fa-solid fa-tag" style="color:var(--amber);margin-top:2px;"></i>
      <span>
        <?= (int) $result['marked_sold'] ?> vehicle<?= $result['marked_sold'] === 1 ? '' : 's' ?> no longer appear
        on the Motus listing and <?= $result['marked_sold'] === 1 ? 'has' : 'have' ?> been marked
        <strong>sold</strong> — nothing was deleted, so their sales history stays intact.
      </span>
    </div>
    <?php endif; ?>

    <?php if (!empty($result['errors'])): ?>
    <div style="font-size:12px;font-weight:700;color:var(--red);margin-bottom:6px;">
      Vehicle-level errors <?= count($result['errors']) > 50 ? '(showing first 50 of ' . count($result['errors']) . ')' : '' ?>
    </div>
    <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r-md);margin-bottom:1rem;">
      <?php foreach (array_slice($result['errors'], 0, 50) as $i => $err): ?>
      <div style="display:flex;gap:10px;padding:8px 12px;font-size:12px;
                  <?= $i > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
        <span style="font-family:var(--mono);color:var(--faint);flex-shrink:0;">#<?= (int) $err['row'] ?></span>
        <span style="color:var(--text2);"><?= htmlspecialchars($err['message']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Enrichment progress (JS-driven, auto-continuing) ────────── -->
    <div id="motusEnrichPanel" style="border-top:1px solid var(--border);padding-top:1rem;">
      <?php if ((int) $result['pending_enrichment'] === 0): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--green);font-weight:600;">
          <i class="fa-solid fa-circle-check"></i> All vehicles already have full details — nothing more to do.
        </div>
      <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <div style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:7px;">
            <i class="fa-solid fa-gear fa-spin" id="motusEnrichSpinner" style="color:var(--p);font-size:12px;"></i>
            <span>Fetching full details (specs, drivetrain, photos)…</span>
          </div>
          <span id="motusEnrichPct" style="font-family:var(--mono);font-size:12px;font-weight:700;color:var(--p);">0%</span>
        </div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-full);height:10px;overflow:hidden;margin-bottom:8px;">
          <div id="motusEnrichBar" class="motus-bar-active" style="height:100%;width:0%;transition:width .4s ease;"></div>
        </div>
        <div id="motusEnrichStatus" style="font-size:12px;color:var(--muted);">
          Starting… (<?= (int) $result['pending_enrichment'] ?> vehicle<?= $result['pending_enrichment'] === 1 ? '' : 's' ?> remaining)
        </div>
        <p style="font-size:11px;color:var(--faint);margin-top:10px;line-height:1.6;">
          <i class="fa-solid fa-circle-info" style="margin-right:3px;"></i>
          This keeps running by itself — no need to click anything else. Feel free to leave this tab
          open in the background, or come back later; anything not finished yet simply continues
          from where it left off next time you click Import.
        </p>
      <?php endif; ?>
    </div>

    <div style="margin-top:1rem;">
      <a href="/app/dealer/inventory.php" class="btn btn-primary">View inventory <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Import form ────────────────────────────────────────── -->
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <form method="POST" id="motusImportForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="import">

      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin-bottom:.75rem;">
        Connection details
      </div>

      <div class="fgroup" style="margin-bottom:1rem;">
        <label class="flabel" for="showroom_url">Your Motus showroom listing page *</label>
        <input class="finput" type="text" id="showroom_url" name="showroom_url" required
               placeholder="https://www.motusvw.co.za/midrand/showroom/"
               value="<?= htmlspecialchars($_POST['showroom_url'] ?? '') ?>">
        <p style="font-size:11px;color:var(--faint);margin-top:6px;">
          This should be your dealership's <strong>stock listing</strong> page (showing all your
          vehicles), not a single vehicle's page.
        </p>
      </div>

      <div class="fgroup" style="margin-bottom:1.5rem;">
        <label class="flabel" for="branch_name">Your exact branch name on Motus *</label>
        <input class="finput" type="text" id="branch_name" name="branch_name" required
               placeholder="e.g. Motus VW Midrand"
               value="<?= htmlspecialchars($_POST['branch_name'] ?? '') ?>">
        <p style="font-size:11px;color:var(--faint);margin-top:6px;">
          This listing page includes stock from every Motus branch, not just yours — we use this to
          import only your own dealership's vehicles. Copy it exactly as it appears on your site
          (e.g. under "Dealership" on one of your own vehicle pages). If it doesn't match, we'll show
          you the branch names we actually found so you can correct it.
        </p>
      </div>

      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin-bottom:.75rem;">
        Commission defaults
      </div>

      <div class="frow" style="margin-bottom:1rem;">
        <div class="fgroup">
          <label class="flabel" for="default_commission_type">Type</label>
          <select class="finput" id="default_commission_type" name="default_commission_type">
            <option value="fixed">Fixed (R)</option>
            <option value="percentage">Percentage (%)</option>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="default_commission_value">Value *</label>
          <input class="finput" type="number" id="default_commission_value" name="default_commission_value"
                 min="0.01" step="any" placeholder="e.g. 5000" required>
        </div>
      </div>
      <p style="font-size:11px;color:var(--faint);margin-bottom:1.5rem;line-height:1.6;">
        <i class="fa-solid fa-lock" style="margin-right:3px;"></i>
        Applied once, when a vehicle is first imported. Adjusting a specific car's commission
        afterward sticks — re-running this import later won't reset it back to this default.
      </p>

      <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:1.25rem;
                    padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);">
        <input type="checkbox" name="confirm_ownership" value="1" required style="margin-top:2px;">
        <span style="font-size:12px;color:var(--text2);line-height:1.6;">
          I confirm this is my own dealership's stock listing and I have the right to import it
          into SalesDesk.
        </span>
      </label>

      <button class="btn btn-primary btn-full" type="submit" id="motusImportSubmitBtn">
        <i class="fa-solid fa-car-side"></i>
        <span id="motusImportSubmitLabel"><?= $result !== null ? 'Import again / check for updates' : 'Import from Motus' ?></span>
      </button>
    </form>
  </div>

  <!-- ── Recent imports (shared history — CSV + website + Motus) ─── -->
  <?php if (!empty($history)): ?>
  <div class="card card-body">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;">Recent imports</h3>
    <div style="border:1px solid var(--border);border-radius:var(--r-md);overflow:hidden;">
      <?php foreach ($history as $i => $run): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;
                  <?= $i > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
        <div>
          <div style="font-size:12px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:6px;">
            <i class="fa-solid <?= match($run['source_platform']) {
              'motus'   => 'fa-car-side',
              'website' => 'fa-globe',
              default   => 'fa-file-csv',
            } ?>" style="font-size:10px;color:var(--faint);"></i>
            <?= htmlspecialchars(date('d M Y, H:i', strtotime($run['started_at']))) ?>
            <span style="color:var(--faint);font-weight:400;">· <?= motusTimeAgo($run['started_at']) ?></span>
          </div>
          <div style="font-size:11px;color:var(--faint);">
            <?= (int) $run['total_rows'] ?> vehicles found
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

<!-- ── Submit-time processing overlay (discovery only) ─────────────── -->
<div id="motusImportOverlay" style="display:none;position:fixed;inset:0;background:rgba(255,255,255,.92);
     z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;">
  <i class="fa-solid fa-circle-notch fa-spin" style="font-size:32px;color:var(--p);"></i>
  <div style="font-size:14px;font-weight:600;color:var(--text);">Reading your Motus listing…</div>
  <div style="font-size:12px;color:var(--muted);max-width:320px;text-align:center;">
    This is just the quick discovery step — usually a few seconds. Fetching full details for each
    vehicle happens next, automatically, without blocking this page.
  </div>
</div>
<script>
document.getElementById('motusImportForm').addEventListener('submit', function () {
  document.getElementById('motusImportSubmitBtn').disabled = true;
  document.getElementById('motusImportOverlay').style.display = 'flex';
});

// ── Enrichment polling loop ──────────────────────────────────────────
(function () {
  var statusEl  = document.getElementById('motusEnrichStatus');
  var barEl     = document.getElementById('motusEnrichBar');
  var pctEl     = document.getElementById('motusEnrichPct');
  var spinnerEl = document.getElementById('motusEnrichSpinner');
  if (!statusEl || !barEl) return; // nothing to enrich this time

  var initialRemaining = <?= (int) ($result['pending_enrichment'] ?? 0) ?>;
  var csrfToken = document.querySelector('meta[name="csrf-token"]');
  csrfToken = csrfToken ? csrfToken.content : <?= json_encode($csrf) ?>;

  var pollDelayMs = 800;

  function pollOnce() {
    fetch('/app/dealer/api/motus-enrich-batch.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrfToken,
      },
      body: 'csrf_token=' + encodeURIComponent(csrfToken),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) {
          statusEl.textContent = 'Paused: ' + data.error + ' (will retry shortly)';
          setTimeout(pollOnce, 5000);
          return;
        }

        var remaining = data.remaining || 0;
        var doneCount = Math.max(0, initialRemaining - remaining);
        var pct = initialRemaining > 0 ? Math.min(100, Math.round((doneCount / initialRemaining) * 100)) : 100;
        barEl.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';

        if (remaining <= 0) {
          statusEl.innerHTML = '<i class="fa-solid fa-circle-check" style="color:var(--green);margin-right:4px;"></i> All done — every vehicle now has full details.';
          barEl.style.width = '100%';
          barEl.classList.remove('motus-bar-active');
          barEl.classList.add('motus-bar-done');
          if (pctEl) { pctEl.textContent = '100%'; pctEl.style.color = 'var(--green)'; }
          if (spinnerEl) {
            spinnerEl.classList.remove('fa-spin', 'fa-gear');
            spinnerEl.classList.add('fa-circle-check');
            spinnerEl.style.color = 'var(--green)';
          }
          return; // stop looping
        }

        statusEl.textContent = doneCount + ' of ' + initialRemaining + ' done — ' + remaining + ' remaining…';
        setTimeout(pollOnce, pollDelayMs);
      })
      .catch(function () {
        statusEl.textContent = 'Connection hiccup — retrying…';
        setTimeout(pollOnce, 5000);
      });
  }

  pollOnce();
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
