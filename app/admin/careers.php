<?php
/**
 * SalesDesk — Admin: Careers (postings + applications)
 *
 * Tabs:
 *   postings     — all job_postings regardless of status, quick publish/
 *                  close/delete, link to careers-edit.php
 *   applications — all job_applications, filter by job/status/search,
 *                  inline status change, resume download, delete
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';
require_once '../../includes/careers.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];
$tab     = $_GET['tab'] ?? 'postings';

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_posting_status') {
        $id     = (int) ($_POST['posting_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0 && setJobPostingStatus($id, $status, $adminId)) {
            $_SESSION['flash_ok'] = 'Posting updated.';
        } else {
            $_SESSION['flash_error'] = 'Could not update posting.';
        }
        redirect('/app/admin/careers.php?tab=postings');
    }

    if ($action === 'delete_posting') {
        $id = (int) ($_POST['posting_id'] ?? 0);
        if ($id > 0 && deleteJobPosting($id, $adminId)) {
            $_SESSION['flash_ok'] = 'Posting deleted.';
        }
        redirect('/app/admin/careers.php?tab=postings');
    }

    if ($action === 'set_application_status') {
        $id     = (int) ($_POST['application_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['admin_notes'] ?? '');
        if ($id > 0 && updateApplicationStatus($id, $status, $adminId, $notes !== '' ? $notes : null)) {
            $_SESSION['flash_ok'] = 'Application updated.';
        } else {
            $_SESSION['flash_error'] = 'Could not update application.';
        }
        redirect('/app/admin/careers.php?tab=applications');
    }

    if ($action === 'delete_application') {
        $id = (int) ($_POST['application_id'] ?? 0);
        if ($id > 0 && deleteApplication($id, $adminId)) {
            $_SESSION['flash_ok'] = 'Application deleted.';
        }
        redirect('/app/admin/careers.php?tab=applications');
    }
}

// ── Data for current tab ────────────────────────────────────────
$postings          = [];
$applications      = [];
$appTotal          = 0;
$jobFilterOptions  = [];
$tableMissing      = false;

try {
    $postings = getJobPostingsForAdmin();
    $jobFilterOptions = array_map(
        fn($p) => ['id' => $p['id'], 'title' => $p['title']],
        $postings
    );
} catch (Throwable) {
    $tableMissing = true;
}

if ($tab === 'applications' && !$tableMissing) {
    $jobFilter    = (int) ($_GET['job'] ?? 0) ?: null;
    $statusFilter = $_GET['status'] ?? '';
    $search       = trim($_GET['q'] ?? '');
    $page         = max(1, (int) ($_GET['page'] ?? 1));
    $perPage      = 30;
    $offset       = ($page - 1) * $perPage;

    try {
        $applications = getApplicationsForAdmin($jobFilter, $statusFilter ?: null, $search ?: null, $perPage, $offset);
        $appTotal     = countApplicationsForAdmin($jobFilter, $statusFilter ?: null, $search ?: null);
    } catch (Throwable) {
        $tableMissing = true;
    }
    $totalPages = (int) ceil($appTotal / $perPage);
}

// Status counts for postings tab badges.
$postingCounts = ['draft' => 0, 'published' => 0, 'closed' => 0];
foreach ($postings as $p) {
    $postingCounts[$p['status']] = ($postingCounts[$p['status']] ?? 0) + 1;
}

// New-application count for nav badge.
$newAppCount = 0;
if (!$tableMissing) {
    try {
        $newAppCount = countApplicationsForAdmin(null, 'new', null);
    } catch (Throwable) {}
}

ob_start();
?>

<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title">Careers</h1>
  <?php if ($newAppCount > 0): ?>
  <span class="section-count" title="New applications"><?= $newAppCount ?> new</span>
  <?php endif; ?>
  <a href="/app/admin/careers-edit.php" class="btn btn-primary btn-sm" style="margin-left:auto;">
    + New posting
  </a>
</div>

<?php if ($tableMissing): ?>
<div class="alert alert-warn" style="margin-bottom:1.25rem;">
  <span class="alert-icon">⚠</span>
  <div>
    The careers tables don't exist yet. Run <code>db/0009_careers.sql</code> against the
    database, then refresh this page.
  </div>
</div>
<?php else: ?>

<!-- Tab nav -->
<div style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:1.5rem;padding-bottom:0;">
  <?php foreach (['postings' => 'Postings', 'applications' => 'Applications'] as $t => $label): ?>
  <a href="?tab=<?= $t ?>"
     style="padding:8px 16px;font-size:13px;font-weight:<?= $tab === $t ? '600' : '400' ?>;
            color:<?= $tab === $t ? 'var(--p)' : 'var(--muted)' ?>;
            border-bottom:<?= $tab === $t ? '2px solid var(--p)' : 'none' ?>;
            text-decoration:none;margin-bottom:-1px;">
    <?= $label ?>
    <?php if ($t === 'applications' && $newAppCount > 0): ?>
    <span style="background:var(--red);color:#fff;border-radius:999px;font-size:10px;
                 font-family:var(--mono);padding:1px 7px;margin-left:4px;"><?= $newAppCount ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'postings'): ?>

<!-- ── POSTINGS TAB ── -->
<?php if (empty($postings)): ?>
<div class="empty">
  <span class="empty-icon">💼</span>
  No postings yet.
  <a href="/app/admin/careers-edit.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Create your first posting</a>
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Title</th>
        <th>Department</th>
        <th>Status</th>
        <th>Applications</th>
        <th>Posted</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($postings as $p): ?>
    <tr>
      <td style="max-width:280px;">
        <div style="font-weight:600;color:var(--text);font-size:13px;
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          <?= htmlspecialchars($p['title']) ?>
        </div>
        <div style="font-size:10px;color:var(--faint);font-family:var(--mono);margin-top:2px;">
          /careers/apply/?role=<?= htmlspecialchars($p['slug']) ?>
        </div>
      </td>
      <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($p['department']) ?></td>
      <td>
        <?php
        $statusBadge = match($p['status']) {
            'published' => 'badge-active',
            'draft'     => 'badge-pending',
            'closed'    => 'badge-suspended',
            default     => '',
        };
        ?>
        <span class="badge <?= $statusBadge ?>"><?= $p['status'] ?></span>
      </td>
      <td>
        <a href="?tab=applications&job=<?= $p['id'] ?>" style="font-family:var(--mono);font-size:12px;">
          <?= (int) $p['application_count'] ?>
          <?php if ((int) $p['new_application_count'] > 0): ?>
          <span style="color:var(--red);">(<?= (int) $p['new_application_count'] ?> new)</span>
          <?php endif; ?>
        </a>
      </td>
      <td style="font-size:12px;color:var(--faint);">
        <?= $p['posted_at'] ? date('d M Y', strtotime($p['posted_at'])) : '—' ?>
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <a href="/app/admin/careers-edit.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>

          <?php if ($p['status'] !== 'published'): ?>
          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="set_posting_status">
            <input type="hidden" name="posting_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="status" value="published">
            <button class="btn btn-success btn-sm" type="submit">Publish</button>
          </form>
          <?php else: ?>
          <a href="/careers/apply/?role=<?= htmlspecialchars($p['slug']) ?>" target="_blank" rel="noopener"
             class="btn btn-ghost btn-sm">View ↗</a>
          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="set_posting_status">
            <input type="hidden" name="posting_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="status" value="closed">
            <button class="btn btn-warn btn-sm" type="submit"
                    onclick="return confirm('Close this posting? It will no longer show on /careers/.')">Close</button>
          </form>
          <?php endif; ?>

          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="delete_posting">
            <input type="hidden" name="posting_id" value="<?= $p['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit"
                    onclick="return confirm('Delete this posting? Existing applications are kept.')">Delete</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php else: /* tab = applications */ ?>

<!-- ── APPLICATIONS TAB ── -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;">
  <input type="hidden" name="tab" value="applications">
  <select class="finput" name="job" style="max-width:220px;">
    <option value="">All postings</option>
    <?php foreach ($jobFilterOptions as $opt): ?>
    <option value="<?= $opt['id'] ?>" <?= ($_GET['job'] ?? '') == $opt['id'] ? 'selected' : '' ?>>
      <?= htmlspecialchars($opt['title']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <select class="finput" name="status" style="max-width:160px;">
    <option value="">All statuses</option>
    <?php foreach (JOB_APPLICATION_STATUSES as $sv => $sl): ?>
    <option value="<?= $sv ?>" <?= ($_GET['status'] ?? '') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
    <?php endforeach; ?>
  </select>
  <input class="finput" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
         placeholder="Search name or email…" style="max-width:240px;">
  <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
  <?php if (!empty($_GET['job']) || !empty($_GET['status']) || !empty($_GET['q'])): ?>
  <a href="?tab=applications" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($applications)): ?>
<div class="empty">
  <span class="empty-icon">📥</span>No applications match your filters.
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Applicant</th>
        <th>Role</th>
        <th>Status</th>
        <th>Resume</th>
        <th>Applied</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($applications as $a): ?>
    <tr>
      <td>
        <div style="font-weight:600;font-size:13px;color:var(--text);"><?= htmlspecialchars($a['full_name']) ?></div>
        <div style="font-size:11px;color:var(--faint);font-family:var(--mono);"><?= htmlspecialchars($a['email']) ?></div>
        <?php if ($a['phone']): ?>
        <div style="font-size:11px;color:var(--faint);"><?= htmlspecialchars($a['phone']) ?></div>
        <?php endif; ?>
        <?php if ($a['linkedin_url'] || $a['portfolio_url']): ?>
        <div style="display:flex;gap:8px;margin-top:4px;">
          <?php if ($a['linkedin_url']): ?>
          <a href="<?= htmlspecialchars($a['linkedin_url']) ?>" target="_blank" rel="noopener" style="font-size:11px;">
            <i class="fa-brands fa-linkedin"></i> LinkedIn
          </a>
          <?php endif; ?>
          <?php if ($a['portfolio_url']): ?>
          <a href="<?= htmlspecialchars($a['portfolio_url']) ?>" target="_blank" rel="noopener" style="font-size:11px;">
            <i class="fa-solid fa-link"></i> Portfolio
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted);max-width:180px;">
        <?= $a['job_title'] ? htmlspecialchars($a['job_title']) : '<span style="color:var(--faint);">Speculative</span>' ?>
      </td>
      <td>
        <form method="POST" style="display:flex;gap:4px;align-items:center;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="set_application_status">
          <input type="hidden" name="application_id" value="<?= $a['id'] ?>">
          <select name="status" class="finput" style="font-size:11px;padding:4px 8px;height:auto;"
                  onchange="this.form.submit()">
            <?php foreach (JOB_APPLICATION_STATUSES as $sv => $sl): ?>
            <option value="<?= $sv ?>" <?= $a['status'] === $sv ? 'selected' : '' ?>><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td>
        <a href="<?= htmlspecialchars($a['resume_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">
          <i class="fa-solid fa-file-arrow-down"></i> View
        </a>
      </td>
      <td style="font-size:11px;color:var(--faint);">
        <?= date('d M Y', strtotime($a['created_at'])) ?>
      </td>
      <td>
        <form method="POST" style="display:inline;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="delete_application">
          <input type="hidden" name="application_id" value="<?= $a['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit"
                  onclick="return confirm('Delete this application? (POPIA right to erasure)')">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (($totalPages ?? 1) > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
  <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
  <?php $qs = http_build_query(array_filter(['tab' => 'applications', 'job' => $_GET['job'] ?? null, 'status' => $_GET['status'] ?? null, 'q' => $_GET['q'] ?? null, 'page' => $pg])); ?>
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
  Showing <?= count($applications) ?> of <?= number_format($appTotal) ?> application(s)
</div>
<?php endif; ?>

<?php endif; // end tab switch ?>
<?php endif; // end tableMissing ?>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Careers | Admin';
require_once '../../views/layout-app.php';