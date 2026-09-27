<?php
/**
 * SalesDesk — Admin: Desk organisations  (0012, reworked in 0013)
 * Route: /app/admin/desk-orgs
 *
 * A desk organisation is an ONLINE DEALERSHIP run by admins. It sells 1–3
 * brands (e.g. "VW South Africa" → Volkswagen). Brokers apply to join as
 * agents — at signup or later — and a managing admin approves them, just
 * like sales execs joining a physical dealership. Approved agents only
 * add the org's brands to their desk; commission is unchanged (no org cut).
 *
 * This page lists the orgs this admin manages and creates new ones.
 * An admin can manage any number of orgs; each org can have several
 * managing admins (organization_managers).
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';

applyCachePolicy('auth');
requireRole('admin');
adminRequireSchema('Desk organisations');   // clear 'run migration' screen if the DB is behind

$pdo       = Database::getInstance();
$adminId   = (int) $_SESSION['user_id'];
$old       = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    if (($_POST['action'] ?? '') === 'create_org') {
        $old         = $_POST;
        $name        = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $cipc        = mb_substr(trim($_POST['cipc_number'] ?? ''), 0, 30);
        $description = mb_substr(trim($_POST['description'] ?? ''), 0, 1000);
        $limitRaw    = trim($_POST['agent_car_limit'] ?? '');
        $verified    = ($_POST['verification_status'] ?? '') === 'verified';
        $accepting   = !empty($_POST['accepting_applications']);
        [$brands, $brandError] = adminParseOrgBrands((array) ($_POST['brands'] ?? []));

        if (mb_strlen($name) < 2) {
            $formError = 'Please enter the organisation name.';
        } elseif ($brands === null) {
            $formError = $brandError;
        } elseif ($cipc !== '' && !preg_match('#^[0-9][0-9/ ]{5,29}$#', $cipc)) {
            $formError = 'That CIPC number doesn\'t look right — it should look like 2019/123456/07.';
        } elseif ($limitRaw !== '' && (!ctype_digit($limitRaw) || (int) $limitRaw < 1 || (int) $limitRaw > 500)) {
            $formError = 'Agent car limit must be a number from 1 to 500, or blank for the platform default.';
        }

        if (!$formError) {
            $pdo->beginTransaction();
            try {
                $addressId = adminSaveAddress(null, $_POST);
                $slug      = adminUniqueSlug('organizations', $name, 60);

                $pdo->prepare("
                    INSERT INTO organizations
                        (uuid, name, slug, brands, description, agent_car_limit, accepting_applications,
                         cipc_number, owner_user_id, address_id,
                         verification_status, verified_at, logo_url, is_active,
                         created_by_admin_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
                ")->execute([
                    generateUuidV4(),
                    $name,
                    $slug,
                    json_encode($brands, JSON_UNESCAPED_UNICODE),
                    $description ?: null,
                    $limitRaw !== '' ? (int) $limitRaw : null,
                    $accepting ? 1 : 0,
                    $cipc ?: null,
                    $adminId,
                    $addressId,
                    $verified ? 'verified' : 'unverified',
                    $verified ? date('Y-m-d H:i:s') : null,
                    adminCleanUrl($_POST['logo_url'] ?? ''),
                    $adminId,
                ]);
                $orgId = (int) $pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO organization_managers (organization_id, admin_user_id, added_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ")->execute([$orgId, $adminId, $adminId]);

                writeAuditLog('org.created_by_admin', 'organization', $orgId, null, [
                    'name' => $name, 'slug' => $slug, 'brands' => $brands,
                    'verification_status' => $verified ? 'verified' : 'unverified',
                ], $adminId);

                $pdo->commit();
                $_SESSION['flash_ok'] = "{$name} created. Brokers can now apply to join it as agents.";
                redirect('/app/admin/desk-orgs-view?id=' . $orgId);
            } catch (Throwable $e) {
                $pdo->rollBack();
                $formError = adminDbErrorMessage($e, 'admin/desk-orgs create', 'create the organisation');
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "
    SELECT o.id, o.name, o.slug, o.brands, o.verification_status, o.is_active,
           o.accepting_applications, o.created_at,
           a.city, a.province,
           (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'verified') AS agent_count,
           (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'pending')  AS pending_count,
           (SELECT COUNT(*) FROM leads l WHERE l.organization_id = o.id)                                          AS lead_count
    FROM organizations o
    JOIN organization_managers om ON om.organization_id = o.id AND om.admin_user_id = ?
    LEFT JOIN addresses a ON a.id = o.address_id
";
$params = [$adminId];
if ($search !== '') {
    $sql     .= " WHERE o.name LIKE ?";
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY pending_count DESC, o.is_active DESC, o.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orgs = $stmt->fetchAll();

$totalPending   = array_sum(array_column($orgs, 'pending_count'));
$openForm       = $formError !== '' || isset($_GET['new']);
$selectedBrands = array_values(array_filter((array) ($old['brands'] ?? [])));
$brandIdPrefix  = 'new_brand';

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Desk organisations</h1>
  <span class="section-count"><?= count($orgs) ?> managed by you</span>
  <?php if ($totalPending > 0): ?>
  <a class="section-count alert-count adm-link" href="/app/admin/approvals?tab=agents">
    <?= (int) $totalPending ?> agent application<?= $totalPending === 1 ? '' : 's' ?> waiting
  </a>
  <?php endif; ?>
  <button type="button" class="btn btn-primary btn-sm adm-head-action" data-modal-open="createOrgModal">
    <i class="fa-solid fa-plus"></i> New organisation
  </button>
</div>

<form method="GET" class="adm-filter">
  <input class="finput" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search organisations…">
  <button class="btn btn-ghost btn-sm" type="submit">Search</button>
  <?php if ($search !== ''): ?><a class="btn btn-ghost btn-sm" href="/app/admin/desk-orgs">Clear</a><?php endif; ?>
</form>

<?php if (!$orgs): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-solid fa-people-group"></i></span>
  <?= $search !== '' ? 'No organisations match that search.' : 'You don\'t manage any desk organisations yet.' ?>
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead><tr><th>Organisation</th><th>Brands</th><th>Status</th><th>Agents</th><th>Leads</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orgs as $o): $ob = orgBrands($o['brands']); ?>
      <tr>
        <td>
          <a class="adm-strong" href="/app/admin/desk-orgs-view?id=<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></a>
          <?php if ((int) $o['pending_count'] > 0): ?>
          <span class="badge badge-pending"><?= (int) $o['pending_count'] ?> pending</span>
          <?php endif; ?>
          <div class="adm-sub"><?= htmlspecialchars(implode(', ', array_filter([$o['city'], $o['province']])) ?: '—') ?></div>
        </td>
        <td>
          <?php if ($ob): ?>
            <div class="adm-chips"><?php foreach ($ob as $b): ?><span class="adm-chip"><?= htmlspecialchars($b) ?></span><?php endforeach; ?></div>
          <?php else: ?>
            <span class="badge badge-pending">Not set</span>
          <?php endif; ?>
        </td>
        <td>
          <?= adminStatusBadge($o['is_active'] ? 'active' : 'inactive') ?>
          <?= $o['verification_status'] === 'verified' ? adminStatusBadge('verified') : '' ?>
          <?php if (!$o['accepting_applications']): ?><div class="adm-sub">Closed to applications</div><?php endif; ?>
        </td>
        <td class="adm-mono"><?= (int) $o['agent_count'] ?></td>
        <td class="adm-mono"><?= (int) $o['lead_count'] ?></td>
        <td><a class="btn btn-ghost btn-sm" href="/app/admin/desk-orgs-view?id=<?= (int) $o['id'] ?>">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ══ Create org modal ══ -->
<div class="modal-bg<?= $openForm ? ' open' : '' ?>" id="createOrgModal">
  <div class="modal adm-modal-wide">
    <div class="modal-title">New desk organisation</div>
    <p class="modal-sub">An online dealership for up to three brands. Brokers apply to join it as agents and you approve them.</p>

    <?php if ($formError): ?>
    <div class="alert alert-error"><span class="alert-icon">!</span><div><?= htmlspecialchars($formError) ?></div></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="create_org">

      <div class="fgroup">
        <label class="flabel" for="og_name">Organisation name</label>
        <input class="finput" id="og_name" name="name" required maxlength="120"
               value="<?= htmlspecialchars($old['name'] ?? '') ?>" placeholder="e.g. VW South Africa">
      </div>

      <?php include __DIR__ . '/../../views/partials/admin/org-brand-fields.php'; ?>

      <div class="fgroup">
        <label class="flabel" for="og_desc">Description <span class="flabel-opt">shown to brokers choosing an organisation</span></label>
        <textarea class="finput" id="og_desc" name="description" rows="2" maxlength="1000"
                  placeholder="e.g. Official online sales team for new and pre-owned Volkswagens."><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
      </div>

      <div class="adm-grid-2">
        <div class="fgroup">
          <label class="flabel" for="og_province">Province</label>
          <select class="finput" id="og_province" name="province">
            <option value="">Nationwide / not set</option>
            <?php foreach (SD_PROVINCES as $prov): ?>
            <option value="<?= $prov ?>" <?= ($old['province'] ?? '') === $prov ? 'selected' : '' ?>><?= $prov ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="og_city">City / town <span class="flabel-opt">optional</span></label>
          <input class="finput" id="og_city" name="city" maxlength="80" value="<?= htmlspecialchars($old['city'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="og_limit">Agent car limit <span class="flabel-opt">blank = platform default</span></label>
          <input class="finput" id="og_limit" name="agent_car_limit" type="number" min="1" max="500"
                 value="<?= htmlspecialchars($old['agent_car_limit'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="og_cipc">CIPC number <span class="flabel-opt">optional</span></label>
          <input class="finput adm-mono" id="og_cipc" name="cipc_number" maxlength="30"
                 value="<?= htmlspecialchars($old['cipc_number'] ?? '') ?>" placeholder="2019/123456/07">
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="og_logo">Logo URL <span class="flabel-opt">optional</span></label>
        <input class="finput" id="og_logo" name="logo_url" type="url" value="<?= htmlspecialchars($old['logo_url'] ?? '') ?>" placeholder="https://…">
      </div>

      <div class="adm-checks">
        <label><input type="checkbox" name="accepting_applications" value="1"
               <?= !$old || !empty($old['accepting_applications']) ? 'checked' : '' ?>> Open to agent applications</label>
        <label><input type="checkbox" name="verification_status" value="verified"
               <?= ($old['verification_status'] ?? '') === 'verified' ? 'checked' : '' ?>> Mark as verified</label>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create organisation</button>
      </div>
    </form>
  </div>
</div>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Desk organisations | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
