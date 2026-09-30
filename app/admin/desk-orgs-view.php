<?php
/**
 * SalesDesk — Admin: Manage one desk organisation  (0012, reworked in 0013)
 * Route: /app/admin/desk-orgs-view?id={org_id}[&tab=requests|agents|details|managers]
 *
 * Tabs:
 *   requests  — brokers who applied to join as agents: approve / decline
 *   agents    — approved + suspended agents: suspend / reinstate / remove;
 *               add an independent broker directly (approved immediately)
 *   details   — name, 1–3 brands, description, agent car limit,
 *               open/closed to applications, location, logo, verified,
 *               active
 *   managers  — co-managing admins
 *
 * Scope: only reachable with an organization_managers row (getManagedOrg);
 * every agent action re-checks scope in adminReviewAgent().
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';

applyCachePolicy('auth');
requireRole('admin');
adminRequireSchema('Desk organisation');   // clear 'run migration' screen if the DB is behind

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];
$orgId   = (int) ($_GET['id'] ?? $_POST['org_id'] ?? 0);

$org = $orgId ? getManagedOrg($adminId, $orgId) : false;
if (!$org) {
    $_SESSION['flash_error'] = 'That organisation was not found, or you don\'t manage it.';
    redirect('/app/admin/desk-orgs');
}

$self   = '/app/admin/desk-orgs-view?id=' . $orgId;
$brands = orgBrands($org['brands']);

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // Agent review: approve / reject / suspend / reinstate / remove
    if (in_array($action, ['approve', 'reject', 'suspend', 'reinstate', 'remove'], true)) {
        $memberId = (int) ($_POST['member_id'] ?? $_POST['target_id'] ?? 0);
        // Make sure the member belongs to THIS org (scope is re-checked inside too).
        $belongs = $pdo->prepare("SELECT 1 FROM organization_members WHERE id = ? AND organization_id = ?");
        $belongs->execute([$memberId, $orgId]);
        if (!$belongs->fetchColumn()) {
            $_SESSION['flash_error'] = 'That agent isn\'t part of this organisation.';
        } else {
            [$ok, $msg] = adminReviewAgent($adminId, $memberId, $action, (string) ($_POST['reason'] ?? ''));
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        }
        redirect($self . '&tab=' . (in_array($action, ['approve', 'reject'], true) ? 'requests' : 'agents'));
    }

    // Add an independent broker directly as an approved agent
    if ($action === 'add_agent') {
        $email = trim($_POST['email'] ?? '');
        $u = $pdo->prepare("SELECT id, email, status FROM users WHERE email = ? AND role = 'broker' LIMIT 1");
        $u->execute([$email]);
        $broker = $u->fetch();

        if (!$broker || $broker['status'] !== 'active') {
            $_SESSION['flash_error'] = 'No active broker account uses that email. They need to sign up as a broker first.';
            redirect($self . '&tab=agents');
        }
        $current = getBrokerMembership((int) $broker['id']);
        if ($current && $current['status'] !== 'rejected') {
            $_SESSION['flash_error'] = (int) $current['org_id'] === $orgId
                ? ($current['status'] === 'pending'
                    ? 'That broker has already applied — approve them from Requests.'
                    : 'That broker is already an agent here.')
                : "That broker belongs to {$current['org_name']}. They must leave it first.";
            redirect($self . '&tab=agents');
        }

        $pdo->prepare("
            INSERT INTO organization_members
                (organization_id, user_id, role, status, verified_by, verified_at, rejection_reason, invited_by, joined_at)
            VALUES (?, ?, 'agent', 'verified', ?, NOW(), NULL, ?, NOW())
            ON DUPLICATE KEY UPDATE
                organization_id = VALUES(organization_id), role = 'agent', status = 'verified',
                verified_by = VALUES(verified_by), verified_at = NOW(), rejection_reason = NULL,
                invited_by = VALUES(invited_by), joined_at = NOW()
        ")->execute([$orgId, (int) $broker['id'], $adminId, $adminId]);

        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, body, meta, created_at)
            VALUES (?, 'agent_status', ?, ?, ?, NOW())
        ")->execute([
            (int) $broker['id'],
            "You're an agent at {$org['name']}",
            'SalesDesk added you to ' . $org['name'] . '. You can now add its cars to your desk.',
            json_encode(['org_id' => $orgId]),
        ]);
        sendAgentApproved($broker['email'], '', $org['name'], $brands);

        writeAuditLog('org.agent_added', 'organization', $orgId, null,
            ['user_email' => $broker['email'], 'via' => 'admin'], $adminId);
        $_SESSION['flash_ok'] = "{$broker['email']} added as an agent.";
        redirect($self . '&tab=agents');
    }

    // Edit details (logic lives in adminSaveOrgDetails() so the Database
    // updates page can dry-run exactly the same save).
    if ($action === 'save_details') {
        [$ok, $msg] = adminSaveOrgDetails($org, $_POST, $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=details');
    }

    // Activate / deactivate
    if ($action === 'deactivate' || $action === 'activate') {
        $active = $action === 'activate' ? 1 : 0;
        $pdo->prepare("UPDATE organizations SET is_active = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$active, $orgId]);
        writeAuditLog($active ? 'org.activated' : 'org.deactivated', 'organization', $orgId,
            ['is_active' => (int) $org['is_active']], ['is_active' => $active], $adminId);
        $_SESSION['flash_ok'] = $active
            ? 'Organisation reactivated.'
            : 'Organisation deactivated. Agents keep their desks but can\'t add its cars, and it\'s hidden from applications.';
        redirect($self . '&tab=details');
    }

    // Co-managers — 0014: assigning admins is a superadmin action
    if (in_array($action, ['add_manager', 'remove_manager'], true) && !isSuperadmin($adminId)) {
        $_SESSION['flash_error'] = 'Only a superadmin can change who manages this.';
        redirect($self . '&tab=managers');
    }
    if ($action === 'add_manager') {
        [$ok, $msg] = adminAddManager('org', $orgId, (string) ($_POST['email'] ?? ''), $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($self . '&tab=managers');
    }
    if ($action === 'remove_manager') {
        $target = (int) ($_POST['admin_user_id'] ?? 0);
        [$ok, $msg] = adminRemoveManager('org', $orgId, $target, $adminId);
        $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
        redirect($ok && $target === $adminId ? '/app/admin/desk-orgs' : $self . '&tab=managers');
    }

    redirect($self);
}

// ── Data ──────────────────────────────────────────────────────
$agentPending = adminPendingAgentApplications($adminId, $orgId);

$agentsStmt = $pdo->prepare("
    SELECT m.id AS member_id, m.user_id, m.status, m.rejection_reason, m.verified_at, m.joined_at,
           u.email, u.status AS user_status,
           p.first_name, p.last_name,
           sd.display_name AS desk_name, sd.slug AS desk_slug,
           (SELECT COUNT(*) FROM broker_inventory bi JOIN cars c ON c.id = bi.car_id
             WHERE bi.salesdesk_id = sd.id AND c.status = 'active') AS live_cars,
           (SELECT COUNT(*) FROM leads l WHERE l.broker_id = m.user_id AND l.organization_id = m.organization_id) AS leads
    FROM organization_members m
    JOIN users u            ON u.id = m.user_id
    LEFT JOIN profiles p    ON p.user_id = m.user_id
    LEFT JOIN salesdesks sd ON sd.user_id = m.user_id
    WHERE m.organization_id = ? AND m.status IN ('verified', 'suspended')
    ORDER BY FIELD(m.status, 'verified', 'suspended'), m.verified_at DESC
");
$agentsStmt->execute([$orgId]);
$agents = $agentsStmt->fetchAll();

$managers = adminListManagers('org', $orgId);

$tab = $_GET['tab'] ?? '';
if (!in_array($tab, ['requests', 'agents', 'details', 'managers'], true)) {
    $tab = $agentPending ? 'requests' : ($brands ? 'agents' : 'details');
}

ob_start();
?>
<a class="adm-back" href="/app/admin/desk-orgs"><i class="fa-solid fa-arrow-left"></i> All organisations</a>

<div class="section-head">
  <h1 class="section-title"><?= htmlspecialchars($org['name']) ?></h1>
  <?= adminStatusBadge($org['is_active'] ? 'active' : 'inactive') ?>
  <?= adminStatusBadge($org['verification_status']) ?>
  <?php if ($brands): ?>
    <span class="adm-chips"><?php foreach ($brands as $b): ?><span class="adm-chip"><?= htmlspecialchars($b) ?></span><?php endforeach; ?></span>
  <?php endif; ?>
</div>

<?php if (!$brands): ?>
<div class="alert alert-warn adm-gap">
  <span class="alert-icon">⚠</span>
  <div>This organisation has no brands yet, so its agents can add any car. Set 1–3 brands under <a href="<?= $self ?>&amp;tab=details">Details</a>.</div>
</div>
<?php endif; ?>

<div class="adm-stats">
  <div class="adm-stat"><span class="adm-stat-n"><?= count($agentPending) ?></span><span class="adm-stat-l">Applications</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= count(array_filter($agents, fn($a) => $a['status'] === 'verified')) ?></span><span class="adm-stat-l">Agents</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= array_sum(array_column($agents, 'live_cars')) ?></span><span class="adm-stat-l">Cars on agent desks</span></div>
  <div class="adm-stat"><span class="adm-stat-n"><?= array_sum(array_column($agents, 'leads')) ?></span><span class="adm-stat-l">Leads</span></div>
</div>

<nav class="adm-tabs" aria-label="Organisation sections">
  <?php foreach (['requests' => 'Applications', 'agents' => 'Agents', 'details' => 'Details', 'managers' => 'Managers'] as $key => $label): ?>
  <a href="<?= $self ?>&amp;tab=<?= $key ?>" class="adm-tab<?= $tab === $key ? ' is-active' : '' ?>"
     <?= $tab === $key ? 'aria-current="page"' : '' ?>>
    <?= $label ?>
    <?php if ($key === 'requests' && $agentPending): ?><span class="adm-tab-count"><?= count($agentPending) ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'requests'): ?>

  <?php if (!$agentPending): ?>
    <div class="empty"><span class="empty-icon">✓</span>No applications waiting<?= $org['accepting_applications'] ? '.' : ' — this organisation is closed to applications.' ?></div>
  <?php else: ?>
    <?php $showOrg = false; include __DIR__ . '/../../views/partials/admin/agent-requests-table.php'; ?>
  <?php endif; ?>

<?php elseif ($tab === 'agents'): ?>

  <div class="card adm-card">
    <form method="POST" class="card-body adm-inline-form">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="org_id" value="<?= $orgId ?>">
      <input type="hidden" name="action" value="add_agent">
      <label class="flabel" for="addAgentEmail">Add a broker directly <span class="flabel-opt">they're approved straight away</span></label>
      <div class="adm-inline">
        <input class="finput" id="addAgentEmail" type="email" name="email" required placeholder="broker@example.com">
        <button class="btn btn-primary btn-sm" type="submit">Add agent</button>
      </div>
    </form>
  </div>

  <?php if (!$agents): ?>
    <div class="empty"><span class="empty-icon"><i class="fa-solid fa-user-plus"></i></span>No agents yet.</div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead><tr><th>Agent</th><th>SalesDesk</th><th>Status</th><th>Live cars</th><th>Leads</th><th>Since</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($agents as $a):
        $aName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: $a['email']; ?>
        <tr>
          <td>
            <div class="adm-strong"><?= htmlspecialchars($aName) ?></div>
            <div class="adm-sub"><?= htmlspecialchars($a['email']) ?><?= $a['user_status'] !== 'active' ? ' · account ' . htmlspecialchars($a['user_status']) : '' ?></div>
          </td>
          <td class="adm-muted">
            <?php if ($a['desk_slug']): ?>
              <a href="/<?= htmlspecialchars($a['desk_slug']) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($a['desk_name']) ?> ↗</a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?= adminStatusBadge($a['status']) ?>
            <?php if ($a['rejection_reason']): ?><div class="adm-sub"><?= htmlspecialchars($a['rejection_reason']) ?></div><?php endif; ?>
          </td>
          <td class="adm-mono"><?= (int) $a['live_cars'] ?></td>
          <td class="adm-mono"><?= (int) $a['leads'] ?></td>
          <td class="adm-muted"><?= date('d M Y', strtotime($a['verified_at'] ?? $a['joined_at'])) ?></td>
          <td><div class="adm-actions">
            <?php if ($a['status'] === 'verified'): ?>
              <button type="button" class="btn btn-warn btn-sm"
                      data-reason-modal="suspend" data-reason-kind="agent"
                      data-exec-id="<?= (int) $a['member_id'] ?>" data-exec-name="<?= htmlspecialchars($aName) ?>">Suspend</button>
            <?php else: ?>
              <form method="POST">
                <?= csrf_hidden_field() ?>
                <input type="hidden" name="org_id" value="<?= $orgId ?>">
                <input type="hidden" name="member_id" value="<?= (int) $a['member_id'] ?>">
                <input type="hidden" name="action" value="reinstate">
                <button class="btn btn-info btn-sm" type="submit">Reinstate</button>
              </form>
            <?php endif; ?>
            <form method="POST" data-confirm="Remove <?= htmlspecialchars($aName) ?> from <?= htmlspecialchars($org['name']) ?>? They become an independent broker; cars on their desk stay.">
              <?= csrf_hidden_field() ?>
              <input type="hidden" name="org_id" value="<?= $orgId ?>">
              <input type="hidden" name="member_id" value="<?= (int) $a['member_id'] ?>">
              <input type="hidden" name="action" value="remove">
              <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'details'): ?>

  <div class="card adm-card">
    <form method="POST" class="card-body">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="org_id" value="<?= $orgId ?>">
      <input type="hidden" name="action" value="save_details">
      <div class="fgroup">
        <label class="flabel" for="od_name">Organisation name</label>
        <input class="finput" id="od_name" name="name" required maxlength="120" value="<?= htmlspecialchars($org['name']) ?>">
      </div>

      <?php $selectedBrands = $brands; $brandIdPrefix = 'od_brand'; include __DIR__ . '/../../views/partials/admin/org-brand-fields.php'; ?>

      <div class="fgroup">
        <label class="flabel" for="od_desc">Description</label>
        <textarea class="finput" id="od_desc" name="description" rows="3" maxlength="1000"><?= htmlspecialchars($org['description'] ?? '') ?></textarea>
      </div>
      <div class="adm-grid-2">
        <div class="fgroup">
          <label class="flabel" for="od_limit">Agent car limit <span class="flabel-opt">blank = platform default</span></label>
          <input class="finput" id="od_limit" name="agent_car_limit" type="number" min="1" max="500" value="<?= htmlspecialchars((string) ($org['agent_car_limit'] ?? '')) ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="od_cipc">CIPC number</label>
          <input class="finput adm-mono" id="od_cipc" name="cipc_number" maxlength="30" value="<?= htmlspecialchars($org['cipc_number'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="od_province">Province</label>
          <select class="finput" id="od_province" name="province">
            <option value="">Nationwide / not set</option>
            <?php foreach (SD_PROVINCES as $prov): ?>
            <option value="<?= $prov ?>" <?= ($org['province'] ?? '') === $prov ? 'selected' : '' ?>><?= $prov ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="od_city">City / town</label>
          <input class="finput" id="od_city" name="city" maxlength="80" value="<?= htmlspecialchars($org['city'] ?? '') ?>">
        </div>
      </div>
      <div class="fgroup">
        <label class="flabel" for="od_logo">Logo URL</label>
        <input class="finput" id="od_logo" name="logo_url" type="url" value="<?= htmlspecialchars($org['logo_url'] ?? '') ?>">
      </div>
      <div class="adm-checks">
        <label><input type="checkbox" name="accepting_applications" value="1" <?= $org['accepting_applications'] ? 'checked' : '' ?>> Open to agent applications</label>
        <label><input type="checkbox" name="verification_status" value="verified" <?= $org['verification_status'] === 'verified' ? 'checked' : '' ?>> Verified</label>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn btn-primary">Save details</button>
      </div>
    </form>
  </div>

  <div class="card adm-card adm-danger">
    <div class="card-body">
      <?php if ($org['is_active']): ?>
        <h2 class="adm-h2">Deactivate</h2>
        <p class="adm-muted">Hides the organisation from applications. Agents keep their desks and cars but can't add new ones from it.</p>
        <form method="POST" data-confirm="Deactivate <?= htmlspecialchars($org['name']) ?>?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="org_id" value="<?= $orgId ?>">
          <input type="hidden" name="action" value="deactivate">
          <button class="btn btn-danger btn-sm" type="submit">Deactivate</button>
        </form>
      <?php else: ?>
        <h2 class="adm-h2">Reactivate</h2>
        <form method="POST">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="org_id" value="<?= $orgId ?>">
          <input type="hidden" name="action" value="activate">
          <button class="btn btn-success btn-sm" type="submit">Reactivate</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <?php $managerEntityField = 'org_id'; $managerEntityId = $orgId; include __DIR__ . '/../../views/partials/admin/managers-panel.php'; ?>

<?php endif; ?>

<?php include __DIR__ . '/../../views/partials/admin/exec-reason-modal.php'; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = $org['name'] . ' | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
