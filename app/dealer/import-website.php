<?php
/**
 * SalesDesk — Website Inventory Import (dealer-facing).
 * T3 owns this file (mirrors app/dealer/import.php's ownership/pattern).
 *
 * Lets a dealer principal point SalesDesk at their own dealership website
 * and bulk-import listings via WebsiteImporter
 * (includes/importers/WebsiteImporter.php), which extracts schema.org
 * Vehicle markup — see that file's docblock for the full Phase 1 scope
 * and limitations.
 *
 * SCOPE / KNOWN LIMITATIONS (read before extending this file — mirrors
 * import.php's docblock, adjusted for what's different about a website
 * source vs a CSV upload):
 *
 *   1. SYNCHRONOUS, BUT NOW WALL-CLOCK-BOUNDED. sync() crawls the site,
 *      extracts markup, and re-hosts images inline within this request —
 *      there is still no job queue in this codebase. Earlier versions of
 *      this page relied on set_time_limit() plus MAX_PAGES to keep things
 *      bounded, but neither actually controls how long the REQUEST takes
 *      from a reverse proxy's point of view: set_time_limit() only caps
 *      PHP's own execution time, and MAX_PAGES doesn't bound wall-clock
 *      time (a hanging page fetch or a car with several large photos to
 *      re-host can each burn many seconds on their own). In practice this
 *      meant a normal-sized dealer site could run past nginx's
 *      fastcgi_read_timeout / Apache's ProxyTimeout (both commonly 60s by
 *      default) and the gateway would return its own 504 — independent of
 *      whatever PHP was still doing.
 *
 *      WebsiteImporter now enforces its own wall-clock budget internally
 *      (see WebsiteImporter::DEFAULT_TIME_BUDGET_SECONDS, 35s by default,
 *      covering crawling AND image re-hosting combined) and stops early
 *      rather than running past it, returning whatever it found so far —
 *      a partial import is far better than a request that 504s with
 *      nothing imported at all. set_time_limit(300) below is now just a
 *      generous backstop so PHP itself never becomes the bottleneck; it
 *      is NOT the thing keeping this page under your gateway's timeout —
 *      that's WebsiteImporter's internal deadline. If you've raised your
 *      gateway's own timeout, you can raise 'time_budget_seconds' to
 *      match when calling sync() below.
 *
 *      OPERATOR ACTION STILL RECOMMENDED: confirm your reverse proxy's
 *      read timeout is at least ~60s (nginx: fastcgi_read_timeout /
 *      proxy_read_timeout; Apache+mod_proxy_fcgi: ProxyTimeout) so the
 *      ~35s internal budget plus DB write overhead has comfortable
 *      margin. If your platform hard-caps gateway timeouts below that
 *      (some managed hosts do), lower 'time_budget_seconds' to match.
 *
 *   2. DEALER-PRINCIPAL ONLY, SAME AS CSV IMPORT. No sales_exec branch —
 *      pointing SalesDesk at a whole website's worth of stock felt like
 *      the same kind of decision as bulk CSV import, which made the same
 *      call. Easy to mirror car-upload.php's exec-guard pattern later if
 *      that's wanted.
 *
 *   3. OWNERSHIP CONFIRMATION IS REQUIRED, NOT JUST TECHNICAL. WebsiteImporter
 *      already refuses to crawl anything that isn't a publicly-routable
 *      address (see its isPubliclyRoutable() — that's an SSRF guard, not
 *      an ownership check), and refuses anything robots.txt disallows for
 *      our user-agent. Neither of those actually confirms the dealer has
 *      the right to import from the URL they typed — a same-origin check
 *      can't distinguish "my own dealership site" from "a competitor's
 *      site I don't control". This page therefore requires an explicit,
 *      logged confirmation checkbox before sync() is ever called. Do not
 *      remove this checkbox as a "friction reduction" — it's the one
 *      layer that addresses the actual question (consent/ownership)
 *      rather than the technical question (is this reachable).
 *
 *   4. NO DRY-RUN / PREVIEW, SAME AS CSV IMPORT. WebsiteImporter's
 *      crawlDealership()/parseVehicle() are pure enough that a preview
 *      step is buildable later, just not included in this pass — adding
 *      one now would mean crawling the site twice (once to preview, once
 *      to actually import) with no caching layer to avoid that cost.
 *
 * File storage: nothing is written to disk by this page itself — unlike
 * CSV import, there's no uploaded file to store under storage/imports/.
 * Re-hosted vehicle images still land under uploads/cars/{uuid}/ via the
 * same ImageRehostService both importers share.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/importers/WebsiteImporter.php';

// Wall-clock budget (seconds) for the whole crawl + image re-host — see
// this file's docblock point 1. Defaults to WebsiteImporter's own
// DEFAULT_TIME_BUDGET_SECONDS (35s) if not overridden here; declared
// explicitly at the page level, rather than left implicit, so raising it
// to match a raised gateway timeout is a one-line change.
const WEBSITE_IMPORT_TIME_BUDGET_SECONDS = 35;

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

    $websiteUrl             = trim($_POST['website_url'] ?? '');
    $confirmedOwnership      = !empty($_POST['confirm_ownership']);
    $defaultCommissionType   = in_array($_POST['default_commission_type'] ?? '', ['fixed', 'percentage'], true)
        ? $_POST['default_commission_type'] : 'fixed';
    $defaultCommissionValue  = (float) str_replace([',', ' '], '', $_POST['default_commission_value'] ?? '0');

    if ($websiteUrl === '') {
        $error = 'Please enter your dealership website\'s address.';
    } elseif (!preg_match('#^https?://#i', $websiteUrl) && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $websiteUrl)) {
        $error = 'That doesn\'t look like a website address. Try something like "https://yourdealer.co.za".';
    } elseif (!$confirmedOwnership) {
        // See point 3 in this file's docblock — this is a deliberate gate,
        // not a formality. Do not bypass it programmatically.
        $error = 'Please confirm this is your own dealership\'s website before importing from it.';
    } elseif ($defaultCommissionValue <= 0) {
        $error = 'Please set a default commission value — used for any vehicle where we can\'t infer one.';
    } elseif ($defaultCommissionType === 'percentage' && $defaultCommissionValue > 30) {
        $error = 'Default percentage commission must be 30% or less.';
    } else {
        // WebsiteImporter internally caps itself to WEBSITE_IMPORT_TIME_BUDGET_SECONDS
        // wall-clock seconds (crawling + image re-hosting combined) and
        // returns whatever it found so far once that's reached — see this
        // file's docblock point 1 for why that, not set_time_limit(), is
        // what actually keeps this request under your gateway's timeout.
        // set_time_limit(300) here is just a generous backstop so PHP
        // itself is never the bottleneck.
        set_time_limit(300);

        try {
            $importer = new WebsiteImporter($pdo);
            $result   = $importer->sync($dealerId, $websiteUrl, [
                'initiated_by'             => $userId,
                'default_commission_type'  => $defaultCommissionType,
                'default_commission_value' => $defaultCommissionValue,
                'time_budget_seconds'      => WEBSITE_IMPORT_TIME_BUDGET_SECONDS,
            ]);

            writeAuditLog(
                'vehicle_import.ownership_confirmed',
                'dealer',
                $dealerId,
                null,
                ['source_url' => $websiteUrl],
                $userId
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Both are thrown for reasons the dealer can act on directly —
            // bad URL, robots.txt disallow, SSRF-guard refusal, unreadable
            // site — so their message is safe and useful to show as-is,
            // same policy as import.php's handling of CsvImporter's
            // "source can't be read at all" exception.
            error_log('[SalesDesk website-import] ' . $e->getMessage());
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[SalesDesk website-import] ' . $e->getMessage());
            $error = 'Something went wrong importing from that website. Please try again or contact support.';
        }
    }
}

// ── Import history — shared with CSV import, distinguished by source ──
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

$pageTitle = 'Import from website';

ob_start();
?>

<div style="max-width:760px;margin:0 auto;">

  <div style="margin-bottom:1.5rem;">
    <h2 style="font-family:var(--serif);font-size:1.3rem;font-weight:300;margin-bottom:.4rem;">
      Import from <em style="font-style:italic;">your website</em>
    </h2>
    <p style="font-size:13px;color:var(--muted);line-height:1.6;">
      Enter your dealership's own website address and we'll read your vehicle listings directly —
      no CSV export needed. We look for structured vehicle data (schema.org markup) that most
      dealer site platforms already publish for Google. Matching vehicles (by VIN or stock number)
      update existing listings instead of duplicating them.
    </p>
    <p style="font-size:12px;color:var(--faint);line-height:1.6;margin-top:8px;">
      <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
      If your site doesn't publish this kind of markup, we won't find any vehicles — in that case,
      <a href="/app/dealer/import.php" style="color:var(--p);font-weight:600;">CSV import</a> is the
      more reliable option today. On the Motus platform (motusvw.co.za and similar)? Use
      <a href="/app/dealer/import-motus.php" style="color:var(--p);font-weight:600;">Motus import</a>
      instead — built specifically for that platform.
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
      Your site has a lot to work through, so we stopped within our safety window rather than risk the
      page timing out. Everything below was imported successfully — re-run the import to pick up
      anything we didn't get to; already-imported vehicles won't be duplicated.
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

    <?php if ($result['total'] === 0): ?>
    <div class="alert alert-info" style="margin-bottom:0;">
      <i class="fa-solid fa-circle-info alert-icon"></i>
      We couldn't find any vehicle markup on that site. It may not publish schema.org Vehicle data —
      try <a href="/app/dealer/import.php" style="color:var(--p);font-weight:600;">CSV import</a> instead.
    </div>
    <?php endif; ?>

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
    <form method="POST" id="websiteImportForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="import">

      <div class="fgroup" style="margin-bottom:1rem;">
        <label class="flabel" for="website_url">Your dealership website *</label>
        <input class="finput" type="text" id="website_url" name="website_url" required
               placeholder="https://yourdealer.co.za"
               value="<?= htmlspecialchars($_POST['website_url'] ?? '') ?>">
        <p style="font-size:11px;color:var(--faint);margin-top:6px;">
          We'll only read pages on this domain — nothing off-site is followed.
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
        Your website's vehicle markup doesn't usually carry commission data, so this default is
        applied to everything we import.
      </p>

      <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:1.25rem;
                    padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);">
        <input type="checkbox" name="confirm_ownership" value="1" required style="margin-top:2px;">
        <span style="font-size:12px;color:var(--text2);line-height:1.6;">
          I confirm this is my own dealership's website and I have the right to import its
          listings into SalesDesk.
        </span>
      </label>

      <button class="btn btn-primary btn-full" type="submit" id="websiteImportSubmitBtn">
        <i class="fa-solid fa-globe"></i> Import from website
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
     Same reasoning as import.php's overlay: this is a synchronous
     request (see file docblock) — the page genuinely sits on the POST
     until sync() finishes. Purely reassurance/anti-double-submit, not a
     performance fix. -->
<div id="websiteImportOverlay" style="display:none;position:fixed;inset:0;background:rgba(255,255,255,.92);
     z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;">
  <i class="fa-solid fa-circle-notch fa-spin" style="font-size:32px;color:var(--p);"></i>
  <div style="font-size:14px;font-weight:600;color:var(--text);">Reading your website…</div>
  <div style="font-size:12px;color:var(--muted);max-width:320px;text-align:center;">
    This usually finishes within about half a minute. For sites with a lot of listings, we import what
    we can find within a safety window rather than risk timing out — please don't close this tab.
  </div>
</div>
<script>
document.getElementById('websiteImportForm').addEventListener('submit', function () {
  document.getElementById('websiteImportSubmitBtn').disabled = true;
  document.getElementById('websiteImportOverlay').style.display = 'flex';
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
