<?php
/**
 * SalesDesk — Listing consistency check (admin-facing).
 *
 * Gives an admin a button that scans every active car platform-wide and
 * verifies its browse-card identity (make, model, year, variant, dealer
 * company name) against what cars-for-sale/car-detail/index.php's own
 * slug lookup actually resolves to. See includes/listing_consistency.php
 * for exactly what's compared, and why (short version: cars.slug is only
 * unique per-dealer, and car-detail's lookup query doesn't filter on
 * dealer_id, so two dealers' cars can collide on slug and the detail page
 * can silently show the wrong dealer's vehicle).
 *
 * Any car where the two disagree is marked 'sold' immediately (removing
 * it from the active browse listing) and logged to audit_logs so an
 * admin can see exactly what happened and why.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/listing_consistency.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo    = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];

$csrf   = generateCSRFToken();
$error  = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_check') {
    validateCSRF();
    try {
        // No $dealerId — platform-wide, across every dealer's active cars.
        $result = checkListingConsistency($pdo, null);
    } catch (Throwable $e) {
        error_log('[SalesDesk admin listing-check] ' . $e->getMessage());
        $error = 'Could not run the check right now. Please try again.';
    }
}

$pageTitle = 'Listing consistency check';

ob_start();
?>

<div style="max-width:820px;margin:0 auto;">

  <div style="margin-bottom:1.5rem;">
    <h2 style="font-family:var(--serif);font-size:1.3rem;font-weight:300;margin-bottom:.4rem;">
      Listing <em style="font-style:italic;">consistency check</em>
    </h2>
    <p style="font-size:13px;color:var(--muted);line-height:1.6;">
      Scans every active car across every dealer and compares its browse-card identity
      (make, model, year, variant, dealership name) against what its own detail page
      actually resolves to. Because <code>cars.slug</code> is only unique
      <strong>per dealer</strong>, two different dealers' cars can share the same slug
      string — when that happens, the detail page's lookup (which doesn't filter by
      dealer) can silently show the wrong vehicle. Any car where the two disagree is
      marked <strong>sold</strong> immediately and removed from the active listing.
    </p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem;">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <h3 style="font-size:14px;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
      <i class="fa-solid <?= empty($result['mismatches']) ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"
         style="color:<?= empty($result['mismatches']) ? 'var(--green)' : 'var(--red)' ?>;"></i>
      Check complete
    </h3>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:1rem;">
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;"><?= (int) $result['checked'] ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Checked</div>
      </div>
      <div style="background:<?= empty($result['mismatches']) ? 'var(--bg)' : 'var(--red-bg)' ?>;
                  border:1px solid <?= empty($result['mismatches']) ? 'var(--border)' : 'var(--red-b)' ?>;
                  border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;
                    color:<?= empty($result['mismatches']) ? 'var(--muted)' : 'var(--red)' ?>;">
          <?= count($result['mismatches']) ?>
        </div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Mismatches</div>
      </div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);padding:12px;text-align:center;">
        <div style="font-family:var(--mono);font-size:20px;font-weight:700;"><?= count($result['auto_sold']) ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Marked sold</div>
      </div>
    </div>

    <?php if (!empty($result['mismatches'])): ?>
    <div style="font-size:12px;font-weight:700;color:var(--red);margin-bottom:6px;">
      Details
    </div>
    <div style="border:1px solid var(--border);border-radius:var(--r-md);overflow:hidden;">
      <?php foreach ($result['mismatches'] as $i => $m): ?>
      <div style="padding:10px 14px;font-size:12px;<?= $i > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
        <div style="font-weight:700;margin-bottom:4px;">
          Car #<?= (int) $m['car_id'] ?> — <code><?= htmlspecialchars($m['slug']) ?></code>
        </div>
        <?php if ($m['reason'] === 'detail_not_found'): ?>
          <div style="color:var(--text2);">Card is active, but the detail page 404s for this slug.</div>
        <?php else: ?>
          <?php foreach ($m['diffs'] as $field => $vals): ?>
          <div style="color:var(--text2);">
            <strong><?= htmlspecialchars($field) ?>:</strong>
            card = <code><?= htmlspecialchars((string) $vals['card']) ?></code>,
            detail = <code><?= htmlspecialchars((string) $vals['detail']) ?></code>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="font-size:13px;color:var(--muted);">All active listings are consistent. Nothing was changed.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card card-body">
    <form method="POST" id="checkForm">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="run_check">
      <button class="btn btn-primary btn-full" type="submit">
        <i class="fa-solid fa-magnifying-glass"></i> Run consistency check (all dealers)
      </button>
      <p style="font-size:11px;color:var(--faint);margin-top:10px;">
        Checks every active car platform-wide. Any mismatch found is marked sold immediately —
        this can't be previewed first.
      </p>
    </form>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
