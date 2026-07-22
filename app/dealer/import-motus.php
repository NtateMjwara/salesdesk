<?php
/**
 * SalesDesk — Motus Platform Inventory Import (dealer-facing).
 * T3 owns this file (mirrors app/dealer/import-website.php's pattern).
 *
 * Lets a dealer principal whose stock is listed on a Motus-network
 * showroom site (motusvw.co.za confirmed; likely other motus*.co.za
 * brand sites sharing the same "Digital Dealer" platform) import that
 * stock via MotusApiImporter (includes/importers/MotusApiImporter.php),
 * which extracts the platform's embedded VLPData vehicle array rather
 * than scraping individual vehicle pages one at a time.
 *
 * SCOPE / KNOWN LIMITATIONS — read before extending this file (mirrors
 * import-website.php's docblock, adjusted for what's different here):
 *
 *   1. THE INPUT IS A LISTING PAGE, NOT A VEHICLE PAGE. MotusApiImporter
 *      needs the dealer's showroom LISTING url (e.g.
 *      "https://www.motusvw.co.za/midrand/showroom/"), not a single
 *      vehicle's detail page — the form below says so explicitly and
 *      validates for the word "showroom" as a soft hint, not a hard
 *      requirement (some Motus sites may structure this differently).
 *
 *   2. THIS MAY NOT WORK AT ALL, AND WILL SAY SO CLEARLY IF NOT.
 *      MotusApiImporter's extraction has never been confirmed against a
 *      live VLPData payload — see that class's docblock. If the listing
 *      page populates its vehicle grid via an asynchronous API call
 *      rather than embedding the array in the page's initial HTML,
 *      crawlDealership() throws a specific, actionable RuntimeException
 *      instead of silently returning nothing — this page surfaces that
 *      message in full rather than a generic "something went wrong",
 *      since the dealer (or whoever's helping them) may need to act on
 *      it directly (capture a Network-tab request and pass it along).
 *
 *   3. SAME WALL-CLOCK BUDGET / 504 REASONING AS THE OTHER TWO IMPORT
 *      PAGES. MotusApiImporter enforces its own internal deadline
 *      (MotusApiImporter::DEFAULT_TIME_BUDGET_SECONDS) covering the
 *      fetch plus every vehicle's cover-photo re-host — set_time_limit()
 *      here is a backstop, not the actual control lever. See
 *      import-website.php's docblock point 1 for the full explanation;
 *      identical reasoning applies here.
 *
 *   4. SAME OWNERSHIP CONFIRMATION GATE AS import-website.php, FOR THE
 *      SAME REASON. MotusApiImporter's SSRF guard confirms the URL is a
 *      reachable public address — it does not and cannot confirm the
 *      dealer has the right to import from it. This page requires the
 *      same explicit, logged checkbox before sync() is ever called.
 *
 *   5. DEALER-PRINCIPAL ONLY, NO EXEC BRANCH — same call as both other
 *      import pages, same reasoning (bulk-importing a dealer's whole
 *      stock in one action is a decision that shouldn't silently extend
 *      to execs without that being deliberate).
 *
 *   6. COVER PHOTO ONLY, NOT A FULL GALLERY. MotusApiImporter pulls one
 *      image per vehicle (VLPData's own `image` field) — see that
 *      class's docblock for why a full gallery isn't fetched here.
 *
 *   7. A BRANCH/DEALER NAME IS REQUIRED, NOT OPTIONAL. Confirmed against
 *      a real production payload: a Motus showroom listing page's
 *      embedded vehicle data spans EVERY branch in the group (one real
 *      sample had 810 vehicles across 10 different dealerships), not
 *      just the branch whose URL was fetched. Without this filter,
 *      importing would pull every competing branch's stock into this
 *      dealer's own SalesDesk account. MotusApiImporter::sync() enforces
 *      this itself (throws immediately if omitted), but the form below
 *      also makes it a required field so the dealer sees why it's
 *      needed rather than hitting a raw exception message first.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/importers/MotusApiImporter.php';

// Wall-clock budget (seconds) for the whole fetch + image re-host — see
// this file's docblock point 3. Declared explicitly at the page level,
// same pattern as import-website.php's WEBSITE_IMPORT_TIME_BUDGET_SECONDS,
// so raising it to match a raised gateway timeout is a one-line change.
const MOTUS_IMPORT_TIME_BUDGET_SECONDS = 35;

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

// ── Handle import ────────────────────────────────────────────────
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
        // See docblock point 7 — this listing spans every branch in the
        // group; without this, we'd import every competing branch's
        // stock into this dealer's account too.
        $error = 'Please enter your dealership\'s exact branch name as it appears on the Motus site (e.g. "Motus VW Midrand").';
    } elseif (!$confirmedOwnership) {
        // See docblock point 4 — a deliberate gate, not a formality.
        $error = 'Please confirm this is your own dealership\'s stock before importing from it.';
    } elseif ($defaultCommissionValue <= 0) {
        $error = 'Please set a default commission value — Motus listing pages don\'t carry commission data of their own.';
    } elseif ($defaultCommissionType === 'percentage' && $defaultCommissionValue > 30) {
        $error = 'Default percentage commission must be 30% or less.';
    } else {
        // See docblock point 3 — the internal deadline is what actually
        // bounds this request; this is just a generous backstop.
        set_time_limit(300);

        try {
            $importer = new MotusApiImporter($pdo);
            $result   = $importer->sync($dealerId, $showroomUrl, [
                'initiated_by'             => $userId,
                'default_commission_type'  => $defaultCommissionType,
                'default_commission_value' => $defaultCommissionValue,
                'time_budget_seconds'      => MOTUS_IMPORT_TIME_BUDGET_SECONDS,
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
            // See docblock point 2 — this often IS the diagnostic the
            // dealer (or support) needs to act on, not a generic failure.
            error_log('[SalesDesk motus-import] ' . $e->getMessage());
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[SalesDesk motus-import] ' . $e->getMessage());
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

$pageTitle = 'Import from Motus';

ob_start();
?>

<div style="max-width:760px;margin:0 auto;">

  <div style="margin-bottom:1.5rem;">
    <h2 style="font-family:var(--serif);font-size:1.3rem;font-weight:300;margin-bottom:.4rem;">
      Import from <em style="font-style:italic;">Motus</em>
    </h2>
    <p style="font-size:13px;color:var(--muted);line-height:1.6;">
      If your stock is listed on a Motus Group dealer site (motusvw.co.za and similar), enter your
      showroom's listing page below. Matching vehicles (by stock number) update existing listings
      instead of duplicating them.
    </p>
    <p style="font-size:12px;color:var(--faint);line-height:1.6;margin-top:8px;">
      <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
      Not on the Motus platform? Try
      <a href="/app/dealer/import-website.php" style="color:var(--p);font-weight:600;">website import</a>
      or <a href="/app/dealer/import.php" style="color:var(--p);font-weight:600;">CSV import</a> instead.
    </p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem;">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= nl2br(htmlspecialchars($error)) ?>
  </div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
  <!-- ── Result summary ─────────────────────────────────────── -->
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
      <?php if (!empty($result['time_boxed'])): ?>
      <i class="fa-solid fa-hourglass-half" style="color:var(--amber);"></i>
      Import stopped early
      <?php else: ?>
      <i class="fa-solid fa-circle-check" style="color:var(--green);"></i>
      Import complete
      <?php endif; ?>
    </h3>
    <?php if (!empty($result['time_boxed'])): ?>
    <div class="alert alert-info" style="margin-bottom:1rem;">
      <i class="fa-solid fa-circle-info alert-icon"></i>
      Your stock list is large, so we stopped within our safety window rather than risk the page
      timing out. Everything below was imported successfully — re-run the import to pick up anything
      we didn't get to; already-imported vehicles won't be duplicated.
    </div>
    <?php endif; ?>
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
      Vehicle-level errors <?= count($result['errors']) > 50 ? '(showing first 50 of ' . count($result['errors']) . ')' : '' ?>
    </div>
    <div style="max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r-md);">
      <?php foreach (array_slice($result['errors'], 0, 50) as $i => $err): ?>
      <div style="display:flex;gap:10px;padding:8px 12px;font-size:12px;
                  <?= $i > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
        <span style="font-family:var(--mono);color:var(--faint);flex-shrink:0;">#<?= (int) $err['row'] ?></span>
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

  <!-- ── Import form ────────────────────────────────────────── -->
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <form method="POST" id="motusImportForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="import">

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

      <div class="fgroup" style="margin-bottom:1rem;">
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
                 min="0.01" step="any" placeholder="e.g. 5000" required>
        </div>
      </div>
      <p style="font-size:11px;color:var(--faint);margin-bottom:1.25rem;">
        Applied to every vehicle we import, since the source listing doesn't carry its own commission data.
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
        <i class="fa-solid fa-car-side"></i> Import from Motus
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

<!-- ── Submit-time processing overlay ─────────────────────────
     Same reasoning as the other two import pages: synchronous request,
     internally time-boxed. Purely reassurance/anti-double-submit. -->
<div id="motusImportOverlay" style="display:none;position:fixed;inset:0;background:rgba(255,255,255,.92);
     z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;">
  <i class="fa-solid fa-circle-notch fa-spin" style="font-size:32px;color:var(--p);"></i>
  <div style="font-size:14px;font-weight:600;color:var(--text);">Reading your Motus listing…</div>
  <div style="font-size:12px;color:var(--muted);max-width:320px;text-align:center;">
    This usually finishes within about half a minute. Please don't close this tab.
  </div>
</div>
<script>
document.getElementById('motusImportForm').addEventListener('submit', function () {
  document.getElementById('motusImportSubmitBtn').disabled = true;
  document.getElementById('motusImportOverlay').style.display = 'flex';
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
