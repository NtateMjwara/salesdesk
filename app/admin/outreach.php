<?php
/**
 * SalesDesk — Admin: Outreach Programme Management
 *
 * Two tabs:
 *   Registrations — pipeline list (new → shortlisted → contacted →
 *                   enrolled/rejected), search + filters, CSV export,
 *                   detail view with status update.
 *   Partners      — dealer/employer enquiries from the "Get Involved"
 *                   cards on /outreach/.
 *
 * v2: no ID number field, no encryption, no "reveal" action — dedupe
 * is on mobile number. See db/0004_outreach_remove_id_number.sql.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';
require_once '../../includes/outreach.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];
$tab     = $_GET['tab'] ?? 'registrations';

// ── CSV export (must run before any HTML output) ───────────────
if (isset($_GET['export']) && $tab === 'registrations') {
    $filters = [
        'status'   => $_GET['status']   ?? '',
        'province' => $_GET['province'] ?? '',
        'search'   => trim($_GET['q'] ?? ''),
    ];
    $rows = getOutreachRegistrations($filters, 5000, 0);

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outreach-registrations-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Full name', 'DOB', 'Mobile', 'Email', 'Province', 'Municipality',
        'Qualification', 'Employment status', 'Learner\'s licence', 'Driver\'s licence',
        'Licence code', 'Status', 'Registered at',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['full_name'], $r['date_of_birth'], $r['mobile'], $r['email'],
            $r['province'], $r['municipality'],
            OUTREACH_QUALIFICATIONS[$r['qualification']] ?? $r['qualification'],
            OUTREACH_EMPLOYMENT_STATUSES[$r['employment_status']] ?? $r['employment_status'],
            $r['has_learners_licence'] ? 'Yes' : 'No',
            $r['has_drivers_licence']  ? 'Yes' : 'No',
            $r['licence_code'], $r['status'], $r['created_at'],
        ]);
    }
    fclose($out);
    writeAuditLog('outreach.exported_csv', 'outreach_registration', 0, null, ['count' => count($rows)], $adminId);
    exit;
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $id     = (int) ($_POST['registration_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['admin_notes'] ?? '') ?: null;

        if ($id > 0 && updateOutreachRegistrationStatus($id, $status, $adminId, $notes)) {
            $_SESSION['flash_ok'] = 'Status updated.';
        } else {
            $_SESSION['flash_error'] = 'Could not update status — please check the value and try again.';
        }
        redirect('/app/admin/outreach.php?tab=registrations&id=' . $id);
    }

    if ($action === 'update_partner_status') {
        $id     = (int) ($_POST['enquiry_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0 && in_array($status, ['new', 'contacted', 'closed'], true)) {
            $pdo->prepare("UPDATE outreach_partner_enquiries SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$status, $id]);
            writeAuditLog('outreach.partner_status_changed', 'outreach_partner_enquiry', $id, null, ['status' => $status], $adminId);
            $_SESSION['flash_ok'] = 'Enquiry status updated.';
        }
        redirect('/app/admin/outreach.php?tab=partners');
    }
}

// ── Detail view (registrations tab) ─────────────────────────────
$viewId  = (int) ($_GET['id'] ?? 0);
$viewRow = $viewId > 0 ? getOutreachRegistrationById($viewId) : false;

// ── Stats ─────────────────────────────────────────────────────
$stats = getOutreachStatsSummary();

// ── Filters + list (registrations tab) ──────────────────────────
$filters = [
    'status'   => $_GET['status']   ?? '',
    'province' => $_GET['province'] ?? '',
    'search'   => trim($_GET['q'] ?? ''),
];
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$total      = countOutreachRegistrations($filters);
$totalPages = max(1, (int) ceil($total / $perPage));
$rows       = getOutreachRegistrations($filters, $perPage, $offset);

// ── Partner enquiries (partners tab) ────────────────────────────
$partnerRows = $tab === 'partners' ? getOutreachPartnerEnquiries(100, 0) : [];

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title">Outreach Programme</h1>
</div>

<!-- Stat cards -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:1.75rem;">
  <?php
  $statCards = [
    ['label' => 'Total registered', 'value' => $stats['total'],       'color' => 'var(--text)'],
    ['label' => 'New',              'value' => $stats['new'],         'color' => 'var(--p)'],
    ['label' => 'Shortlisted',      'value' => $stats['shortlisted'], 'color' => 'var(--amber)'],
    ['label' => 'Contacted',        'value' => $stats['contacted'],   'color' => 'var(--teal)'],
    ['label' => 'Enrolled',         'value' => $stats['enrolled'],    'color' => 'var(--green)'],
    ['label' => 'Rejected',         'value' => $stats['rejected'],    'color' => 'var(--red)'],
  ];
  foreach ($statCards as $sc):
  ?>
  <div class="card card-body" style="text-align:center;">
    <div style="font-size:20px;font-weight:700;color:<?= $sc['color'] ?>;font-family:var(--mono);"><?= number_format($sc['value']) ?></div>
    <div style="font-size:10.5px;color:var(--muted);margin-top:4px;"><?= $sc['label'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tab nav -->
<div style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:1.5rem;">
  <?php foreach (['registrations' => 'Registrations', 'partners' => 'Partner Enquiries'] as $t => $label): ?>
  <a href="?tab=<?= $t ?>"
     style="padding:8px 16px;font-size:13px;font-weight:<?= $tab === $t ? '600' : '400' ?>;
            color:<?= $tab === $t ? 'var(--p)' : 'var(--muted)' ?>;
            border-bottom:<?= $tab === $t ? '2px solid var(--p)' : 'none' ?>;
            text-decoration:none;margin-bottom:-1px;">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!empty($_SESSION['flash_ok'])): ?>
<div class="alert alert-info" style="margin-bottom:1.25rem;"><span class="alert-icon">✓</span><div><?= htmlspecialchars($_SESSION['flash_ok']) ?></div></div>
<?php unset($_SESSION['flash_ok']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert alert-warn" style="margin-bottom:1.25rem;"><span class="alert-icon">⚠</span><div><?= htmlspecialchars($_SESSION['flash_error']) ?></div></div>
<?php unset($_SESSION['flash_error']); endif; ?>

<?php if ($tab === 'registrations'): ?>

  <?php if ($viewRow): ?>
  <!-- ══════════════ DETAIL VIEW ══════════════ -->
  <div class="card card-body" style="margin-bottom:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;">
      <div>
        <div style="font-family:var(--font-d);font-size:17px;font-weight:700;color:var(--text);"><?= htmlspecialchars($viewRow['full_name']) ?></div>
        <div style="font-size:12px;color:var(--faint);margin-top:2px;">Registered <?= date('d M Y H:i', strtotime($viewRow['created_at'])) ?></div>
      </div>
      <a href="?tab=registrations" class="btn btn-ghost btn-sm">← Back to list</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;font-size:13px;margin-bottom:1.25rem;">
      <div><div style="color:var(--faint);font-size:11px;">Date of birth</div><div><?= date('d M Y', strtotime($viewRow['date_of_birth'])) ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Mobile</div><div><?= htmlspecialchars($viewRow['mobile']) ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Email</div><div><?= htmlspecialchars($viewRow['email'] ?: '—') ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Province</div><div><?= htmlspecialchars($viewRow['province']) ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Municipality</div><div><?= htmlspecialchars($viewRow['municipality']) ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Qualification</div><div><?= htmlspecialchars(OUTREACH_QUALIFICATIONS[$viewRow['qualification']] ?? $viewRow['qualification']) ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Employment status</div><div><?= htmlspecialchars(OUTREACH_EMPLOYMENT_STATUSES[$viewRow['employment_status']] ?? $viewRow['employment_status']) ?></div></div>
      <div><div style="color:var(--faint);font-size:11px;">Licences</div><div>
        Learner's: <?= $viewRow['has_learners_licence'] ? 'Yes' : 'No' ?> ·
        Driver's: <?= $viewRow['has_drivers_licence'] ? 'Yes' : 'No' ?> ·
        Interested in Code <?= htmlspecialchars($viewRow['licence_code']) ?>
      </div></div>
    </div>

    <div style="margin-bottom:1.25rem;">
      <div style="color:var(--faint);font-size:11px;margin-bottom:4px;">Motivation</div>
      <div style="font-size:13.5px;line-height:1.7;background:var(--bg);border-radius:8px;padding:14px;white-space:pre-wrap;"><?= htmlspecialchars($viewRow['motivation']) ?></div>
    </div>

    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="registration_id" value="<?= $viewRow['id'] ?>">
      <div>
        <label class="flabel">Status</label>
        <select class="finput" name="status">
          <?php foreach (OUTREACH_STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= $viewRow['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:220px;">
        <label class="flabel">Admin notes</label>
        <input class="finput" type="text" name="admin_notes" value="<?= htmlspecialchars($viewRow['admin_notes'] ?? '') ?>" placeholder="Internal notes…">
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <form method="GET" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;">
    <input type="hidden" name="tab" value="registrations">
    <input class="finput" name="q" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Search name or phone…" style="max-width:260px;">
    <select class="finput" name="status" style="max-width:160px;">
      <option value="">All statuses</option>
      <?php foreach (OUTREACH_STATUSES as $s): ?>
      <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="finput" name="province" style="max-width:180px;">
      <option value="">All provinces</option>
      <?php foreach (OUTREACH_PROVINCES as $p): ?>
      <option value="<?= htmlspecialchars($p) ?>" <?= $filters['province'] === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
    <?php if ($filters['search'] || $filters['status'] || $filters['province']): ?>
    <a href="?tab=registrations" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
    <a href="?<?= http_build_query(array_merge(['tab' => 'registrations', 'export' => 1], array_filter($filters))) ?>"
       class="btn btn-ghost btn-sm" style="margin-left:auto;">⬇ Export CSV</a>
  </form>

  <?php if (empty($rows)): ?>
  <div class="empty"><span class="empty-icon">📋</span>No registrations match your filters.</div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead>
        <tr>
          <th>Name</th><th>Province</th><th>Licence</th><th>Status</th><th>Registered</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <?php
      $statusBadge = match($r['status']) {
        'enrolled'    => 'badge-active',
        'shortlisted' => 'badge-new',
        'contacted'   => 'badge-pending',
        'rejected'    => 'badge-suspended',
        default       => '',
      };
      ?>
      <tr>
        <td>
          <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($r['full_name']) ?></div>
          <div style="font-size:11px;color:var(--faint);font-family:var(--mono);"><?= htmlspecialchars($r['mobile']) ?></div>
        </td>
        <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($r['province']) ?></td>
        <td style="font-size:12px;">Code <?= htmlspecialchars($r['licence_code']) ?></td>
        <td><span class="badge <?= $statusBadge ?>"><?= $r['status'] ?></span></td>
        <td style="font-size:11px;color:var(--faint);"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
        <td><a href="?tab=registrations&id=<?= $r['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
    <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
    <?php $qs = http_build_query(array_merge(['tab' => 'registrations', 'page' => $pg], array_filter($filters))); ?>
    <a href="?<?= $qs ?>"
       style="padding:5px 11px;border-radius:6px;font-size:12px;font-family:var(--mono);
              border:1px solid <?= $pg === $page ? 'var(--p)' : 'var(--border)' ?>;
              background:<?= $pg === $page ? 'var(--p)' : 'transparent' ?>;
              color:<?= $pg === $page ? '#fff' : 'var(--muted)' ?>;text-decoration:none;">
      <?= $pg ?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <div style="font-size:12px;color:var(--faint);margin-top:12px;text-align:right;">
    Showing <?= count($rows) ?> of <?= number_format($total) ?> registration(s)
  </div>
  <?php endif; ?>

<?php else: /* tab = partners */ ?>

  <?php if (empty($partnerRows)): ?>
  <div class="empty"><span class="empty-icon">🤝</span>No partner enquiries yet.</div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead>
        <tr><th>Type</th><th>Contact</th><th>Company</th><th>Email</th><th>Status</th><th>Received</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($partnerRows as $pr): ?>
      <?php
      $pBadge = match($pr['status']) { 'closed' => 'badge-suspended', 'contacted' => 'badge-pending', default => 'badge-new' };
      ?>
      <tr>
        <td><span class="badge"><?= ucfirst($pr['enquiry_type']) ?></span></td>
        <td style="font-size:13px;font-weight:600;"><?= htmlspecialchars($pr['contact_name']) ?></td>
        <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($pr['company_name'] ?: '—') ?></td>
        <td style="font-size:12px;font-family:var(--mono);"><?= htmlspecialchars($pr['email']) ?></td>
        <td><span class="badge <?= $pBadge ?>"><?= $pr['status'] ?></span></td>
        <td style="font-size:11px;color:var(--faint);"><?= date('d M Y', strtotime($pr['created_at'])) ?></td>
        <td>
          <form method="POST" style="display:flex;gap:6px;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="update_partner_status">
            <input type="hidden" name="enquiry_id" value="<?= $pr['id'] ?>">
            <select class="finput" name="status" style="font-size:12px;padding:6px 8px;">
              <?php foreach (['new','contacted','closed'] as $s): ?>
              <option value="<?= $s ?>" <?= $pr['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Save</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Outreach Programme | Admin';
require_once '../../views/layout-app.php';
