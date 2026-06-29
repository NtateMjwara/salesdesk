<?php
/**
 * SalesDesk — Admin: Blog Post Editor (Create + Edit)
 *
 * GET  /app/admin/blog-edit.php          — new post form
 * GET  /app/admin/blog-edit.php?id={n}   — edit existing post
 * POST                                   — save (draft or publish)
 *
 * Content editor: Quill.js 1.3.7 (cdnjs — within existing CSP).
 * Slug: auto-generated from title via JS; editable; uniqueness enforced server-side.
 * Image: URL field (no file upload in MVP — paste from Unsplash / CDN).
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';
require_once '../../includes/newsletter.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo       = Database::getInstance();
$adminId   = (int) $_SESSION['user_id'];
$editId    = (int) ($_GET['id'] ?? 0);
$errors    = [];
$post      = [];

// Fetch existing post for edit mode
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $post = $stmt->fetch();
    if (!$post) {
        $_SESSION['flash_error'] = 'Post not found.';
        redirect('/app/admin/blog.php');
    }
}

// ── POST: save ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $title       = trim($_POST['title']            ?? '');
    $slug        = trim($_POST['slug']             ?? '');
    $excerpt     = trim($_POST['excerpt']          ?? '');
    $content     = trim($_POST['content']          ?? '');
    $imageUrl    = trim($_POST['featured_image_url'] ?? '');
    $categoryId  = (int) ($_POST['category_id']   ?? 0) ?: null;
    $status      = in_array($_POST['status'] ?? '', ['draft','published','scheduled'])
                    ? $_POST['status'] : 'draft';
    $publishedAt = trim($_POST['published_at']     ?? '');
    $metaTitle   = trim($_POST['meta_title']       ?? '');
    $metaDesc    = trim($_POST['meta_description'] ?? '');
    $action      = $_POST['submit_action']         ?? 'draft';

    // Force publish status if admin clicked Publish
    if ($action === 'publish') {
        $status = 'published';
    }

    // Validate
    if (!$title) {
        $errors[] = 'Title is required.';
    }
    if (!$content) {
        $errors[] = 'Content cannot be empty.';
    }

    // Slug: use submitted value, fall back to auto-generated
    if (!$slug) {
        $slug = uniqueBlogSlug($title, $editId ?: null);
    } else {
        $slug = generateBlogSlug($slug);
        // Ensure uniqueness
        $check = $pdo->prepare(
            'SELECT id FROM blog_posts WHERE slug = ?' .
            ($editId ? ' AND id != ?' : '') . ' LIMIT 1'
        );
        $check->execute($editId ? [$slug, $editId] : [$slug]);
        if ($check->fetch()) {
            $slug = uniqueBlogSlug($title, $editId ?: null);
        }
    }

    // Published_at
    $publishedAtValue = null;
    if ($status === 'published') {
        $publishedAtValue = $publishedAt ?: date('Y-m-d H:i:s');
    } elseif ($status === 'scheduled' && $publishedAt) {
        $publishedAtValue = $publishedAt;
    }

    if (empty($errors)) {
        if ($editId > 0) {
            // Update
            $pdo->prepare("
                UPDATE blog_posts SET
                    title = ?, slug = ?, excerpt = ?, content = ?,
                    featured_image_url = ?, category_id = ?, status = ?,
                    published_at = ?, meta_title = ?, meta_description = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $title, $slug, $excerpt ?: null, $content,
                $imageUrl ?: null, $categoryId, $status,
                $publishedAtValue, $metaTitle ?: null, $metaDesc ?: null,
                $editId,
            ]);
            writeAuditLog('blog.updated', 'blog_post', $editId,
                ['title' => $post['title'], 'status' => $post['status']],
                ['title' => $title, 'status' => $status],
                $adminId
            );
            $_SESSION['flash_ok'] = $status === 'published' ? 'Post published.' : 'Post saved as draft.';
        } else {
            // Insert
            $uuid = generateUuidV4();
            $pdo->prepare("
                INSERT INTO blog_posts
                    (uuid, slug, title, excerpt, content, featured_image_url,
                     category_id, author_id, status, published_at,
                     meta_title, meta_description, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ")->execute([
                $uuid, $slug, $title, $excerpt ?: null, $content,
                $imageUrl ?: null, $categoryId, $adminId, $status,
                $publishedAtValue, $metaTitle ?: null, $metaDesc ?: null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            writeAuditLog('blog.created', 'blog_post', $newId,
                null, ['title' => $title, 'status' => $status], $adminId
            );
            $_SESSION['flash_ok'] = $status === 'published' ? 'Post published.' : 'Draft saved.';
        }
        redirect('/app/admin/blog.php');
    }
    // Re-populate for error display
    $post = [
        'id' => $editId, 'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt,
        'content' => $content, 'featured_image_url' => $imageUrl,
        'category_id' => $categoryId, 'status' => $status,
        'published_at' => $publishedAt, 'meta_title' => $metaTitle,
        'meta_description' => $metaDesc,
    ];
}

$categories = $pdo->query("SELECT id, name FROM blog_categories ORDER BY sort_order")->fetchAll();
$isEdit     = !empty($post['id']);
$pageHeading = $isEdit ? 'Edit Post' : 'New Post';

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<!-- Quill CSS from cdnjs (within existing CSP) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css"
      crossorigin="anonymous" referrerpolicy="no-referrer">

<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title"><?= $pageHeading ?></h1>
  <a href="/app/admin/blog.php" class="btn btn-ghost btn-sm" style="margin-left:auto;">← Back to posts</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-warn" style="margin-bottom:1.25rem;">
  <span class="alert-icon">⚠</span>
  <div><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div>
</div>
<?php endif; ?>

<form method="POST" id="postForm">
  <?= csrf_hidden_field() ?>

  <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">

    <!-- ── Main column ─────────────────────────────────────── -->
    <div>

      <!-- Title -->
      <div class="fgroup">
        <label class="flabel" for="title">Title <span style="color:var(--red);">*</span></label>
        <input class="finput" type="text" id="title" name="title"
               value="<?= htmlspecialchars($post['title'] ?? '') ?>"
               placeholder="e.g. Ford Everest 2026 — Full Review"
               style="font-size:18px;font-weight:600;padding:12px 14px;">
      </div>

      <!-- Slug -->
      <div class="fgroup" style="margin-bottom:20px;">
        <label class="flabel" for="slug">URL slug</label>
        <div style="display:flex;align-items:center;background:var(--bg);border:1px solid var(--border);
                    border-radius:var(--r-md);overflow:hidden;">
          <span style="padding:0 12px;font-size:12px;color:var(--faint);white-space:nowrap;
                       border-right:1px solid var(--border);background:#f1f3f7;">
            /news/
          </span>
          <input class="finput" type="text" id="slug" name="slug"
                 value="<?= htmlspecialchars($post['slug'] ?? '') ?>"
                 placeholder="auto-generated"
                 style="border:none;border-radius:0;flex:1;background:transparent;">
          <span style="padding:0 10px;font-size:12px;color:var(--faint);">/</span>
        </div>
        <div style="font-size:11px;color:var(--faint);margin-top:4px;">
          Auto-generated from the title. Edit to customise — only lowercase letters, numbers, and hyphens.
        </div>
      </div>

      <!-- Excerpt -->
      <div class="fgroup">
        <label class="flabel" for="excerpt">Excerpt <span style="color:var(--faint);font-weight:400;">(optional – shown in card previews)</span></label>
        <textarea class="finput" id="excerpt" name="excerpt" rows="2"
                  placeholder="A short summary shown in the blog listing and social shares…"
                  style="resize:vertical;"><?= htmlspecialchars($post['excerpt'] ?? '') ?></textarea>
      </div>

      <!-- Content editor -->
      <div class="fgroup">
        <label class="flabel">Content <span style="color:var(--red);">*</span></label>
        <div id="quillEditor" style="min-height:420px;border:1px solid var(--border);border-radius:var(--r-md);background:#fff;font-size:15px;"></div>
        <!-- Hidden textarea receives Quill HTML on submit -->
        <textarea name="content" id="contentInput" hidden><?= htmlspecialchars($post['content'] ?? '') ?></textarea>
      </div>

      <!-- SEO -->
      <details style="margin-top:8px;">
        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--muted);
                        padding:10px 0;list-style:none;display:flex;align-items:center;gap:6px;">
          <span>▸</span> SEO / Meta (optional)
        </summary>
        <div style="padding-top:12px;border-top:1px solid var(--border);margin-top:8px;">
          <div class="fgroup">
            <label class="flabel" for="meta_title">Meta title <span style="color:var(--faint);font-weight:400;">(≤ 60 chars)</span></label>
            <input class="finput" type="text" id="meta_title" name="meta_title" maxlength="250"
                   value="<?= htmlspecialchars($post['meta_title'] ?? '') ?>"
                   placeholder="Overrides the page title in search results">
          </div>
          <div class="fgroup">
            <label class="flabel" for="meta_description">Meta description <span style="color:var(--faint);font-weight:400;">(≤ 160 chars)</span></label>
            <textarea class="finput" id="meta_description" name="meta_description" rows="2" maxlength="350"
                      placeholder="Short description shown in Google results…"><?= htmlspecialchars($post['meta_description'] ?? '') ?></textarea>
          </div>
        </div>
      </details>

    </div>

    <!-- ── Sidebar ──────────────────────────────────────────── -->
    <div>

      <!-- Publish actions -->
      <div class="card card-body" style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:14px;">Publish</div>

        <div class="fgroup">
          <label class="flabel" for="status">Status</label>
          <select class="finput" id="status" name="status">
            <option value="draft"     <?= ($post['status'] ?? '') === 'draft'     ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= ($post['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="scheduled" <?= ($post['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
          </select>
        </div>

        <div class="fgroup" id="publishDateGroup"
             style="display:<?= ($post['status'] ?? '') === 'scheduled' ? 'block' : 'none' ?>">
          <label class="flabel" for="published_at">Publish date &amp; time</label>
          <input class="finput" type="datetime-local" id="published_at" name="published_at"
                 value="<?= htmlspecialchars(
                   $post['published_at']
                   ? date('Y-m-d\TH:i', strtotime($post['published_at']))
                   : ''
                 ) ?>">
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;">
          <button type="submit" name="submit_action" value="draft" class="btn btn-ghost"
                  style="flex:1;">Save draft</button>
          <button type="submit" name="submit_action" value="publish" class="btn btn-success"
                  style="flex:1;">
            <?= $isEdit && ($post['status'] ?? '') === 'published' ? 'Update' : 'Publish' ?>
          </button>
        </div>

        <?php if ($isEdit && ($post['status'] ?? '') === 'published'): ?>
        <a href="/news/<?= htmlspecialchars($post['slug']) ?>/" target="_blank" rel="noopener"
           style="display:block;text-align:center;margin-top:10px;font-size:12px;color:var(--p);">
          View live post ↗
        </a>
        <?php endif; ?>
      </div>

      <!-- Category -->
      <div class="card card-body" style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:14px;">Category</div>
        <select class="finput" name="category_id">
          <option value="">— Uncategorised —</option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"
            <?= (int)($post['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Featured image -->
      <div class="card card-body">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:6px;">Featured image</div>
        <div style="font-size:11px;color:var(--faint);margin-bottom:12px;">
          Paste a URL (Unsplash, CDN, etc.). File upload coming in a future update.
        </div>
        <input class="finput" type="url" id="featured_image_url" name="featured_image_url"
               value="<?= htmlspecialchars($post['featured_image_url'] ?? '') ?>"
               placeholder="https://images.unsplash.com/…">
        <div id="imagePreview" style="margin-top:10px;display:<?= !empty($post['featured_image_url']) ? 'block' : 'none' ?>">
          <img id="imagePreviewImg"
               src="<?= htmlspecialchars($post['featured_image_url'] ?? '') ?>"
               alt="Preview"
               style="width:100%;border-radius:6px;object-fit:cover;max-height:160px;">
        </div>
      </div>

    </div>
  </div>
</form>

<!-- Quill JS from cdnjs -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
(function () {
  'use strict';

  /* ── Quill editor ─────────────────────────────────────── */
  var quill = new Quill('#quillEditor', {
    theme: 'snow',
    placeholder: 'Write your article content here…',
    modules: {
      toolbar: [
        [{ header: [2, 3, 4, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        ['link', 'blockquote', 'code-block'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['image'],
        ['clean']
      ]
    }
  });

  /* Populate editor when editing an existing post */
  var existing = document.getElementById('contentInput').value;
  if (existing) {
    quill.root.innerHTML = existing;
  }

  /* Copy Quill content to hidden textarea on submit */
  document.getElementById('postForm').addEventListener('submit', function () {
    document.getElementById('contentInput').value = quill.root.innerHTML;
  });

  /* ── Auto-slug from title ─────────────────────────────── */
  var titleInput = document.getElementById('title');
  var slugInput  = document.getElementById('slug');
  var slugEdited = !!slugInput.value; // don't overwrite pre-existing slug

  function slugify(text) {
    return text.toLowerCase()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/[\s-]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 200);
  }

  titleInput.addEventListener('input', function () {
    if (!slugEdited) {
      slugInput.value = slugify(this.value);
    }
  });

  slugInput.addEventListener('input', function () {
    slugEdited = true;
    this.value = slugify(this.value);
  });

  /* ── Scheduled date toggle ────────────────────────────── */
  document.getElementById('status').addEventListener('change', function () {
    var dateGroup = document.getElementById('publishDateGroup');
    dateGroup.style.display = this.value === 'scheduled' ? 'block' : 'none';
  });

  /* ── Image URL preview ────────────────────────────────── */
  document.getElementById('featured_image_url').addEventListener('input', function () {
    var preview    = document.getElementById('imagePreview');
    var previewImg = document.getElementById('imagePreviewImg');
    var url        = this.value.trim();
    if (url) {
      previewImg.src    = url;
      preview.style.display = 'block';
    } else {
      preview.style.display = 'none';
    }
  });

  /* ── Details toggle arrow ─────────────────────────────── */
  document.querySelector('details').addEventListener('toggle', function () {
    this.querySelector('span').textContent = this.open ? '▾' : '▸';
  });

})();
</script>

<!-- Tighten Quill's snow theme to fit the admin UI palette -->
<style>
.ql-toolbar.ql-snow {
  border: none;
  border-bottom: 1px solid var(--border);
  border-radius: var(--r-md) var(--r-md) 0 0;
  background: var(--bg);
}
.ql-container.ql-snow {
  border: none;
  font-family: var(--sans);
  font-size: 15px;
  border-radius: 0 0 var(--r-md) var(--r-md);
}
.ql-editor { min-height: 380px; padding: 20px; line-height: 1.75; }
.ql-editor p { margin-bottom: 1em; }
</style>

<?php
$pageContent = ob_get_clean();
$pageTitle   = ($isEdit ? 'Edit Post' : 'New Post') . ' | Admin';
require_once '../../views/layout-app.php';
