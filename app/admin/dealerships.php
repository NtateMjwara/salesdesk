<?php
/**
 * SalesDesk — Admin: Dealerships  (0012)
 * Route: /app/admin/dealerships
 *
 * Lists the dealerships this admin manages and lets them create new ones
 * without waiting for a dealer principal to sign up. A dealership created
 * here has dealers.user_id = NULL ("admin-managed"): it goes live at once,
 * shows up in the sales exec signup search, and everything that would
 * normally go to the principal (exec join requests, lead emails, nudges)
 * goes to its managing admins instead. See includes/admin_scope.php.
 *
 * Guards: requireRole('admin'), CSRF on every POST, audit log on create.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';

applyCachePolicy('auth');
requireRole('admin');
adminRequireSchema('Dealerships');   // clear 'run migration' screen if the DB is behind

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];
$old     = [];
$formError = '';

// ── POST: create dealership ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    if (($_POST['action'] ?? '') === 'create_dealer') {
        $old         = $_POST;
        $companyName = mb_substr(trim($_POST['company_name'] ?? ''), 0, 120);
        $verified    = ($_POST['verification_status'] ?? '') === 'verified';
        $isActive    = !empty($_POST['is_active']);

        if (mb_strlen($companyName) < 2) {
            $formError = 'Please enter the dealership name.';
        } else {
            $dupe = $pdo->prepare("SELECT id FROM dealers WHERE company_name = ? LIMIT 1");
            $dupe->execute([$companyName]);
            if ($dupe->fetch() && empty($_POST['confirm_duplicate'])) {
                $formError = 'A dealership with this exact name already exists. '
                           . 'Tick "Create anyway" if this is a different branch.';
            }
        }

        if (!$formError) {
            $pdo->beginTransaction();
            try {
                $addressId = adminSaveAddress(null, $_POST);
                $slug      = adminUniqueSlug('dealers', $companyName, 80);

                $pdo->prepare("
                    INSERT INTO dealers
                        (uuid, user_id, company_name, slug, logo_url, address_id, brand_focus,
                         verification_status, verified_at, is_active, created_by_admin_id,
                         created_at, updated_at)
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ")->execute([
                    generateUuidV4(),
                    $companyName,
                    $slug,
                    adminCleanUrl($_POST['logo_url'] ?? ''),
                    $addressId,
                    adminBrandFocusJson($_POST['brand_focus'] ?? ''),
                    $verified ? 'verified' : 'unverified',
                    $verified ? date('Y-m-d H:i:s') : null,
                    $isActive ? 1 : 0,
                    $adminId,
                ]);
                $dealerId = (int) $pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO dealer_managers (dealer_id, admin_user_id, added_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ")->execute([$dealerId, $adminId, $adminId]);

                writeAuditLog('dealer.created_by_admin', 'dealer', $dealerId, null, [
                    'company_name'        => $companyName,
                    'slug'                => $slug,
                    'verification_status' => $verified ? 'verified' : 'unverified',
                    'is_active'           => $isActive ? 1 : 0,
                ], $adminId);

                $pdo->commit();
                $_SESSION['flash_ok'] = "{$companyName} created. Sales execs can now apply to join it.";
                redirect('/app/admin/dealerships-view?id=' . $dealerId);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $formError = adminDbErrorMessage($e, 'admin/dealerships create', 'create the dealership');
            }
        }
    }
}

// ── GET: my dealerships ───────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$sql = "
    SELECT d.id, d.company_name, d.slug, d.verification_status, d.is_active,
           d.user_id, d.created_at,
           a.city, a.province,
           (SELECT COUNT(*) FROM sales_executives se
             WHERE se.dealer_id = d.id AND se.verification_status = 'pending')  AS pending_execs,
           (SELECT COUNT(*) FROM sales_executives se
             WHERE se.dealer_id = d.id AND se.verification_status = 'verified') AS team_size,
           (SELECT COUNT(*) FROM cars c
             WHERE c.dealer_id = d.id AND c.status = 'active')                  AS active_cars
    FROM dealers d
    JOIN dealer_managers dm ON dm.dealer_id = d.id AND dm.admin_user_id = ?
    LEFT JOIN addresses a   ON a.id = d.address_id
";
$params = [$adminId];
if ($search !== '') {
    $sql     .= " WHERE d.company_name LIKE ?";
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY pending_execs DESC, d.company_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dealers = $stmt->fetchAll();

$totalPending = array_sum(array_column($dealers, 'pending_execs'));
$openForm     = $formError !== '' || isset($_GET['new']);

// ── Render ────────────────────────────────────────────────────
ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Dealerships</h1>
  <span class="section-count"><?= count($dealers) ?> managed by you</span>
  <?php if ($totalPending > 0): ?>
  <a class="section-count alert-count adm-link" href="/app/admin/approvals?tab=execs">
    <?= (int) $totalPending ?> exec request<?= $totalPending === 1 ? '' : 's' ?> waiting
  </a>
  <?php endif; ?>
  <button type="button" class="btn btn-primary btn-sm adm-head-action" data-modal-open="createDealerModal">
    <i class="fa-solid fa-plus"></i> Add dealership
  </button>
</div>

<form method="GET" class="adm-filter">
  <input class="finput" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search dealerships…">
  <button class="btn btn-ghost btn-sm" type="submit">Search</button>
  <?php if ($search !== ''): ?><a class="btn btn-ghost btn-sm" href="/app/admin/dealerships">Clear</a><?php endif; ?>
</form>

<?php if (!$dealers): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-solid fa-building"></i></span>
  <?= $search !== '' ? 'No dealerships match that search.' : 'You don\'t manage any dealerships yet. Add one to get started.' ?>
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Dealership</th>
        <th>Location</th>
        <th>Status</th>
        <th>Principal</th>
        <th>Team</th>
        <th>Live cars</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($dealers as $d): ?>
      <tr>
        <td>
          <a class="adm-strong" href="/app/admin/dealerships-view?id=<?= (int) $d['id'] ?>">
            <?= htmlspecialchars($d['company_name']) ?>
          </a>
          <?php if ((int) $d['pending_execs'] > 0): ?>
          <span class="badge badge-pending"><?= (int) $d['pending_execs'] ?> pending</span>
          <?php endif; ?>
        </td>
        <td class="adm-muted"><?= htmlspecialchars(implode(', ', array_filter([$d['city'], $d['province']])) ?: '—') ?></td>
        <td>
          <?= adminStatusBadge($d['is_active'] ? 'active' : 'inactive') ?>
          <?= $d['verification_status'] === 'verified' ? adminStatusBadge('verified') : '' ?>
        </td>
        <td class="adm-muted"><?= $d['user_id'] ? 'Linked' : 'Admin-managed' ?></td>
        <td class="adm-mono"><?= (int) $d['team_size'] ?></td>
        <td class="adm-mono"><?= (int) $d['active_cars'] ?></td>
        <td><a class="btn btn-ghost btn-sm" href="/app/admin/dealerships-view?id=<?= (int) $d['id'] ?>">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══ Create dealership modal ══ -->
<div class="modal-bg<?= $openForm ? ' open' : '' ?>" id="createDealerModal">
  <div class="modal adm-modal-wide">
    <div class="modal-title">Add a dealership</div>
    <p class="modal-sub">
      Goes live straight away — no dealer principal needed. You'll review sales exec requests for it.
    </p>

    <?php if ($formError): ?>
    <div class="alert alert-error"><span class="alert-icon">!</span><div><?= htmlspecialchars($formError) ?></div></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="create_dealer">

      <div class="fgroup">
        <label class="flabel" for="dl_name">Dealership name</label>
        <input class="finput" id="dl_name" name="company_name" required maxlength="120"
               value="<?= htmlspecialchars($old['company_name'] ?? '') ?>" placeholder="e.g. Sandton Toyota">
      </div>

      <div class="adm-grid-2">
        <div class="fgroup">
          <label class="flabel" for="dl_province">Province</label>
          <select class="finput" id="dl_province" name="province">
            <option value="">Select…</option>
            <?php foreach (SD_PROVINCES as $prov): ?>
            <option value="<?= $prov ?>" <?= ($old['province'] ?? '') === $prov ? 'selected' : '' ?>><?= $prov ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="dl_city">City / town</label>
          <input class="finput" id="dl_city" name="city" maxlength="80" value="<?= htmlspecialchars($old['city'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="dl_suburb">Suburb <span class="flabel-opt">optional</span></label>
          <input class="finput" id="dl_suburb" name="suburb" maxlength="80" value="<?= htmlspecialchars($old['suburb'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="dl_street">Street address <span class="flabel-opt">optional</span></label>
          <input class="finput" id="dl_street" name="street_line1" maxlength="120" value="<?= htmlspecialchars($old['street_line1'] ?? '') ?>">
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="dl_brands">Brand focus <span class="flabel-opt">comma-separated</span></label>
        <input class="finput" id="dl_brands" name="brand_focus" value="<?= htmlspecialchars($old['brand_focus'] ?? '') ?>" placeholder="Toyota, Lexus">
      </div>

      <div class="fgroup">
        <label class="flabel" for="dl_logo">Logo URL <span class="flabel-opt">optional</span></label>
        <input class="finput" id="dl_logo" name="logo_url" type="url" value="<?= htmlspecialchars($old['logo_url'] ?? '') ?>" placeholder="https://…">
      </div>

      <div class="adm-checks">
        <label><input type="checkbox" name="verification_status" value="verified"
               <?= ($old['verification_status'] ?? '') === 'verified' ? 'checked' : '' ?>> Mark as verified (I've checked their CIPC details)</label>
        <label><input type="checkbox" name="is_active" value="1"
               <?= !$old || !empty($old['is_active']) ? 'checked' : '' ?>> Active — visible to sales execs</label>
        <?php if ($formError && str_contains($formError, 'already exists')): ?>
        <label><input type="checkbox" name="confirm_duplicate" value="1"> Create anyway</label>
        <?php endif; ?>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create dealership</button>
      </div>
    </form>
  </div>
</div>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Dealerships | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
