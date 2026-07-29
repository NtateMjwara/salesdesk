<?php
/**
 * SalesDesk — Admin: Job Posting Editor (Create + Edit)
 *
 * GET  /app/admin/careers-edit.php          — new posting form
 * GET  /app/admin/careers-edit.php?id={n}   — edit existing posting
 * POST                                      — save (draft or publish)
 *
 * Content editor: Quill.js 1.3.7 (cdnjs — within existing CSP), same
 * pattern as blog-edit.php. Slug auto-generated from title via JS,
 * editable, uniqueness enforced server-side via uniqueJobSlug().
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
$editId  = (int) ($_GET['id'] ?? 0);
$errors  = [];
$posting = [];

if ($editId > 0) {
    $posting = getJobPostingById($editId);
    if (!$posting) {
        $_SESSION['flash_error'] = 'Posting not found.';
        redirect('/app/admin/careers.php');
    }
}

// ── POST: save ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $title          = trim($_POST['title'] ?? '');
    $slug           = trim($_POST['slug'] ?? '');
    $department     = trim($_POST['department'] ?? '');
    $location       = trim($_POST['location'] ?? '');
    $employmentType = $_POST['employment_type'] ?? 'full_time';
    $workMode       = $_POST['work_mode'] ?? 'on_site';
    $blurb          = trim($_POST['blurb'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $action         = $_POST['submit_action'] ?? 'draft';

    $status = match ($action) {
        'publish' => 'published',
        'close'   => 'closed',
        default   => 'draft',
    };

    if (!$title)      $errors[] = 'Job title is required.';
    if (!$department) $errors[] = 'Department is required.';
    if (!$location)   $errors[] = 'Location is required.';
    if (!$blurb)       $errors[] = 'A short summary (blurb) is required — this shows on the job card.';
    if (mb_strlen($blurb) > 280) $errors[] = 'Blurb must be 280 characters or fewer.';
    if (!array_key_exists($employmentType, JOB_EMPLOYMENT_TYPES)) $errors[] = 'Invalid employment type.';
    if (!array_key_exists($workMode, JOB_WORK_MODES)) $errors[] = 'Invalid work mode.';

    if (empty($errors)) {
        $data = [
            'title'           => $title,
            'slug'            => $slug,
            'department'      => $department,
            'location'        => $location,
            'employment_type' => $employmentType,
            'work_mode'       => $workMode,
            'blurb'           => $blurb,
            'description'     => $description,
            'status'          => $status,
        ];

        if ($editId > 0) {
            updateJobPosting($editId, $data, $adminId);
            $_SESSION['flash_ok'] = $status === 'published' ? 'Posting published.' : 'Posting saved.';
        } else {
            $editId = createJobPosting($data, $adminId);
            $_SESSION['flash_ok'] = $status === 'published' ? 'Posting published.' : 'Draft saved.';
        }
        redirect('/app/admin/careers.php');
    }

    // Re-populate for error display
    $posting = [
        'id' => $editId, 'title' => $title, 'slug' => $slug, 'department' => $department,
        'location' => $location, 'employment_type' => $employmentType, 'work_mode' => $workMode,
        'blurb' => $blurb, 'description' => $description, 'status' => $status,
    ];
}

$isEdit      = !empty($posting['id']);
$pageHeading = $isEdit ? 'Edit Posting' : 'New Posting';

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css"
      crossorigin="anonymous" referrerpolicy="no-referrer">

<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title"><?= $pageHeading ?></h1>
  <a href="/app/admin/careers.php" class="btn btn-ghost btn-sm" style="margin-left:auto;">← Back to careers</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-warn" style="margin-bottom:1.25rem;">
  <span class="alert-icon">⚠</span>
  <div><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div>
</div>
<?php endif; ?>

<form method="POST" id="postingForm">
  <?= csrf_hidden_field() ?>

  <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">

    <!-- ── Main column ─────────────────────────────────────── -->
    <div>

      <div class="fgroup">
        <label class="flabel" for="title">Job title <span style="color:var(--red);">*</span></label>
        <input class="finput" type="text" id="title" name="title"
               value="<?= htmlspecialchars($posting['title'] ?? '') ?>"
               placeholder="e.g. Senior Backend Engineer (PHP)"
               style="font-size:18px;font-weight:600;padding:12px 14px;">
      </div>

      <div class="fgroup" style="margin-bottom:20px;">
        <label class="flabel" for="slug">URL slug</label>
        <div style="display:flex;align-items:center;background:var(--bg);border:1px solid var(--border);
                    border-radius:var(--r-md);overflow:hidden;">
          <span style="padding:0 12px;font-size:12px;color:var(--faint);white-space:nowrap;
                       border-right:1px solid var(--border);background:#f1f3f7;">
            /careers/apply/?role=
          </span>
          <input class="finput" type="text" id="slug" name="slug"
                 value="<?= htmlspecialchars($posting['slug'] ?? '') ?>"
                 placeholder="auto-generated"
                 style="border:none;border-radius:0;flex:1;background:transparent;">
        </div>
        <div style="font-size:11px;color:var(--faint);margin-top:4px;">
          Auto-generated from the title. Uniqueness is enforced automatically if it clashes.
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="fgroup">
          <label class="flabel" for="department">Department <span style="color:var(--red);">*</span></label>
          <input class="finput" type="text" id="department" name="department"
                 value="<?= htmlspecialchars($posting['department'] ?? '') ?>" placeholder="Engineering" list="deptSuggestions">
          <datalist id="deptSuggestions">
            <option value="Engineering"><option value="Design"><option value="Operations">
            <option value="Marketing"><option value="Sales"><option value="Finance">
          </datalist>
        </div>
        <div class="fgroup">
          <label class="flabel" for="location">Location <span style="color:var(--red);">*</span></label>
          <input class="finput" type="text" id="location" name="location"
                 value="<?= htmlspecialchars($posting['location'] ?? '') ?>" placeholder="Johannesburg, GP">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="fgroup">
          <label class="flabel" for="employment_type">Employment type</label>
          <select class="finput" id="employment_type" name="employment_type">
            <?php foreach (JOB_EMPLOYMENT_TYPES as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($posting['employment_type'] ?? 'full_time') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="work_mode">Work mode</label>
          <select class="finput" id="work_mode" name="work_mode">
            <?php foreach (JOB_WORK_MODES as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($posting['work_mode'] ?? 'on_site') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="blurb">
          Short summary <span style="color:var(--red);">*</span>
          <span style="color:var(--faint);font-weight:400;">(shown on the job card — max 280 chars)</span>
        </label>
        <textarea class="finput" id="blurb" name="blurb" rows="2" maxlength="280"
                  placeholder="One or two sentences that sell the role at a glance…"><?= htmlspecialchars($posting['blurb'] ?? '') ?></textarea>
        <div style="font-size:11px;color:var(--faint);margin-top:4px;text-align:right;" id="blurbCount"></div>
      </div>

      <div class="fgroup">
        <label class="flabel">Full description <span style="color:var(--faint);font-weight:400;">(optional — shown on the apply page)</span></label>
        <div id="quillEditor" style="min-height:320px;border:1px solid var(--border);border-radius:var(--r-md);background:#fff;font-size:15px;"></div>
        <textarea name="description" id="contentInput" hidden><?= htmlspecialchars($posting['description'] ?? '') ?></textarea>
      </div>

    </div>

    <!-- ── Sidebar ──────────────────────────────────────────── -->
    <div>
      <div class="card card-body" style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:14px;">Publish</div>

        <?php if ($isEdit): ?>
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">
          Current status:
          <span class="badge <?= match($posting['status'] ?? 'draft') { 'published' => 'badge-active', 'closed' => 'badge-suspended', default => 'badge-pending' } ?>">
            <?= $posting['status'] ?? 'draft' ?>
          </span>
        </div>
        <?php endif; ?>

        <div style="display:flex;flex-direction:column;gap:8px;">
          <button type="submit" name="submit_action" value="draft" class="btn btn-ghost">Save as draft</button>
          <button type="submit" name="submit_action" value="publish" class="btn btn-success">
            <?= $isEdit && ($posting['status'] ?? '') === 'published' ? 'Update (stay published)' : 'Publish' ?>
          </button>
          <?php if ($isEdit): ?>
          <button type="submit" name="submit_action" value="close" class="btn btn-warn">Close posting</button>
          <?php endif; ?>
        </div>

        <?php if ($isEdit && ($posting['status'] ?? '') === 'published'): ?>
        <a href="/careers/apply/?role=<?= htmlspecialchars($posting['slug']) ?>" target="_blank" rel="noopener"
           style="display:block;text-align:center;margin-top:10px;font-size:12px;color:var(--p);">
          View live application page ↗
        </a>
        <?php endif; ?>
      </div>

      <div class="alert alert-info">
        <span class="alert-icon">💡</span>
        <div style="font-size:12px;line-height:1.6;">
          Published postings appear on <strong>/careers/</strong> immediately.
          Applications always land in the <strong>Applications</strong> tab regardless of status.
        </div>
      </div>
    </div>
  </div>
</form>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
(function () {
  'use strict';

  var quill = new Quill('#quillEditor', {
    theme: 'snow',
    placeholder: 'Full job description, responsibilities, requirements…',
    modules: {
      toolbar: [
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline'],
        ['link', 'blockquote'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['clean']
      ]
    }
  });

  var existing = document.getElementById('contentInput').value;
  if (existing) quill.root.innerHTML = existing;

  document.getElementById('postingForm').addEventListener('submit', function () {
    document.getElementById('contentInput').value = quill.root.innerHTML;
  });

  /* Auto-slug from title */
  var titleInput = document.getElementById('title');
  var slugInput  = document.getElementById('slug');
  var slugEdited = !!slugInput.value;

  function slugify(text) {
    return text.toLowerCase()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/[\s-]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 150);
  }

  titleInput.addEventListener('input', function () {
    if (!slugEdited) slugInput.value = slugify(this.value);
  });
  slugInput.addEventListener('input', function () {
    slugEdited = true;
    this.value = slugify(this.value);
  });

  /* Blurb char counter */
  var blurb = document.getElementById('blurb');
  var count = document.getElementById('blurbCount');
  function updateCount() {
    var len = blurb.value.length;
    count.innerHTML = '<span style="color:' + (len > 280 ? 'var(--red)' : 'var(--faint)') + '">' + len + ' / 280</span>';
  }
  blurb.addEventListener('input', updateCount);
  updateCount();
})();
</script>

<style>
.ql-toolbar.ql-snow { border:none;border-bottom:1px solid var(--border);border-radius:var(--r-md) var(--r-md) 0 0;background:var(--bg); }
.ql-container.ql-snow { border:none;font-family:var(--sans);font-size:15px;border-radius:0 0 var(--r-md) var(--r-md); }
.ql-editor { min-height:280px;padding:18px;line-height:1.75; }
.ql-editor p { margin-bottom:1em; }
</style>

<?php
$pageContent = ob_get_clean();
$pageTitle   = ($isEdit ? 'Edit Posting' : 'New Posting') . ' | Admin';
require_once '../../views/layout-app.php';