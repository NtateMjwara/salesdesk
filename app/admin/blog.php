<?php
/**
 * SalesDesk — Admin: Blog Post Management
 *
 * Lists all posts with status, category, and quick-action controls.
 * Write actions: publish, unpublish (→ draft), delete.
 * Create / edit routes to blog-edit.php.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';
require_once '../../includes/newsletter.php';  // for generateBlogSlug

applyCachePolicy('auth');
requireRole('admin');

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $postId = (int) ($_POST['post_id'] ?? 0);

    if ($postId > 0) {
        if ($action === 'publish') {
            $pdo->prepare("
                UPDATE blog_posts
                SET status = 'published',
                    published_at = IFNULL(published_at, NOW()),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$postId]);
            writeAuditLog('blog.published', 'blog_post', $postId, ['status' => 'draft'], ['status' => 'published'], $adminId);
            $_SESSION['flash_ok'] = 'Post published.';
        }

        if ($action === 'unpublish') {
            $pdo->prepare("
                UPDATE blog_posts SET status = 'draft', updated_at = NOW() WHERE id = ?
            ")->execute([$postId]);
            writeAuditLog('blog.unpublished', 'blog_post', $postId, ['status' => 'published'], ['status' => 'draft'], $adminId);
            $_SESSION['flash_ok'] = 'Post moved back to draft.';
        }

        if ($action === 'delete') {
            // Soft-delete: just remove the row for MVP (no soft-delete column in schema).
            // Audit log captures the deletion for dispute resolution.
            $row = $pdo->prepare('SELECT title FROM blog_posts WHERE id = ?');
            $row->execute([$postId]);
            $title = $row->fetchColumn() ?: '(unknown)';
            $pdo->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$postId]);
            writeAuditLog('blog.deleted', 'blog_post', $postId, ['title' => $title], null, $adminId);
            $_SESSION['flash_ok'] = "Post \"{$title}\" deleted.";
        }
    }
    redirect('/app/admin/blog.php');
}

// ── Filters ───────────────────────────────────────────────────
$statusFilter   = $_GET['status']   ?? '';
$categoryFilter = (int) ($_GET['category'] ?? 0);
$search         = trim($_GET['q'] ?? '');
$page           = max(1, (int) ($_GET['page'] ?? 1));
$perPage        = 25;
$offset         = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($statusFilter) {
    $where[]  = 'p.status = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter) {
    $where[]  = 'p.category_id = ?';
    $params[] = $categoryFilter;
}
if ($search) {
    $where[]  = 'p.title LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereClause = implode(' AND ', $where);

$total = (int) $pdo->prepare("SELECT COUNT(*) FROM blog_posts p WHERE {$whereClause}")
    ->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM blog_posts p WHERE {$whereClause}")
    ->execute($params) : 0;

// Re-run count properly
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM blog_posts p WHERE {$whereClause}");
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($total / $perPage);

$rowStmt = $pdo->prepare("
    SELECT
        p.id, p.uuid, p.slug, p.title, p.status,
        p.published_at, p.view_count, p.created_at,
        c.name  AS category_name,
        c.slug  AS category_slug,
        u.email AS author_email
    FROM blog_posts p
    LEFT JOIN blog_categories c ON c.id = p.category_id
    JOIN users u ON u.id = p.author_id
    WHERE {$whereClause}
    ORDER BY p.updated_at DESC
    LIMIT ? OFFSET ?
");
$rowStmt->execute(array_merge($params, [$perPage, $offset]));
$posts = $rowStmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM blog_categories ORDER BY sort_order")->fetchAll();

// Status counts for tab badges
$countsByStatus = [];
foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM blog_posts GROUP BY status")->fetchAll() as $r) {
    $countsByStatus[$r['status']] = (int) $r['n'];
}
$totalPosts = array_sum($countsByStatus);

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<div class="section-head">
  <h1 class="section-title">Blog Posts</h1>
  <span class="section-count"><?= number_format($total) ?></span>
  <a href="/app/admin/blog-edit.php" class="btn btn-primary btn-sm" style="margin-left:auto;">
    + New post
  </a>
</div>

<!-- ── Status filter tabs ── -->
<div style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:1.25rem;padding-bottom:0;">
  <?php
  $tabs = ['' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'scheduled' => 'Scheduled'];
  foreach ($tabs as $v => $label):
    $count   = $v === '' ? $totalPosts : ($countsByStatus[$v] ?? 0);
    $isActive = $statusFilter === $v;
    $href     = '?' . http_build_query(array_filter(['status' => $v, 'category' => $categoryFilter ?: null, 'q' => $search ?: null]));
  ?>
  <a href="<?= htmlspecialchars($href) ?>"
     style="padding:8px 16px;font-size:13px;font-weight:<?= $isActive ? '600' : '400' ?>;
            color:<?= $isActive ? 'var(--p)' : 'var(--muted)' ?>;
            border-bottom:<?= $isActive ? '2px solid var(--p)' : 'none' ?>;
            text-decoration:none;margin-bottom:-1px;white-space:nowrap;">
    <?= $label ?>
    <?php if ($count > 0): ?>
    <span style="background:<?= $isActive ? 'var(--p)' : 'var(--bg)' ?>;
                 color:<?= $isActive ? '#fff' : 'var(--faint)' ?>;
                 border-radius:999px;font-size:10px;font-family:var(--mono);
                 padding:1px 7px;margin-left:4px;"><?= $count ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── Filter bar ── -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;align-items:flex-end;">
  <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
  <input class="finput" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Search title…" style="max-width:240px;">
  <select class="finput" name="category" style="max-width:180px;">
    <option value="">All categories</option>
    <?php foreach ($categories as $cat): ?>
    <option value="<?= $cat['id'] ?>" <?= $categoryFilter === (int)$cat['id'] ? 'selected' : '' ?>>
      <?= htmlspecialchars($cat['name']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
  <?php if ($search || $categoryFilter): ?>
  <a href="?<?= $statusFilter ? 'status=' . urlencode($statusFilter) : '' ?>" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>

<!-- ── Posts table ── -->
<?php if (empty($posts)): ?>
<div class="empty">
  <span class="empty-icon">✍️</span>
  No posts found.
  <?php if (!$statusFilter && !$search && !$categoryFilter): ?>
  <a href="/app/admin/blog-edit.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Write your first post</a>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Title</th>
        <th>Category</th>
        <th>Status</th>
        <th>Published</th>
        <th style="text-align:right;">Views</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($posts as $p): ?>
    <tr>
      <td style="max-width:340px;">
        <div style="font-weight:600;color:var(--text);font-size:13px;
                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          <?= htmlspecialchars($p['title']) ?>
        </div>
        <div style="font-size:10px;color:var(--faint);font-family:var(--mono);margin-top:2px;">
          /news/<?= htmlspecialchars($p['slug']) ?>/
        </div>
      </td>
      <td>
        <?php if ($p['category_name']): ?>
        <span style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($p['category_name']) ?></span>
        <?php else: ?>
        <span style="color:var(--faint);font-size:12px;">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php
        $statusColor = match($p['status']) {
            'published' => 'badge-active',
            'draft'     => 'badge-pending',
            'scheduled' => 'badge-new',
            default     => '',
        };
        ?>
        <span class="badge <?= $statusColor ?>"><?= $p['status'] ?></span>
      </td>
      <td style="font-size:12px;color:var(--faint);">
        <?= $p['published_at'] ? date('d M Y', strtotime($p['published_at'])) : '—' ?>
      </td>
      <td style="font-family:var(--mono);font-size:12px;text-align:right;color:var(--muted);">
        <?= number_format((int)$p['view_count']) ?>
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <a href="/app/admin/blog-edit.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>

          <?php if ($p['status'] !== 'published'): ?>
          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="publish">
            <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
            <button class="btn btn-success btn-sm" type="submit"
                    onclick="return confirm('Publish this post?')">Publish</button>
          </form>
          <?php else: ?>
          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="unpublish">
            <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
            <button class="btn btn-warn btn-sm" type="submit">Unpublish</button>
          </form>
          <a href="/news/<?= htmlspecialchars($p['slug']) ?>/" target="_blank" rel="noopener"
             class="btn btn-ghost btn-sm">View ↗</a>
          <?php endif; ?>

          <form method="POST" style="display:inline;">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit"
                    onclick="return confirm('Delete this post? This cannot be undone.')">Delete</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Pagination ── -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
  <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
  <?php
  $isActive = $pg === $page;
  $qs = http_build_query(array_filter(['status' => $statusFilter, 'category' => $categoryFilter ?: null, 'q' => $search ?: null, 'page' => $pg]));
  ?>
  <a href="?<?= $qs ?>"
     style="padding:5px 11px;border-radius:6px;font-size:12px;font-family:var(--mono);
            border:1px solid <?= $isActive ? 'var(--p)' : 'var(--border)' ?>;
            background:<?= $isActive ? 'var(--p)' : 'transparent' ?>;
            color:<?= $isActive ? '#fff' : 'var(--muted)' ?>;
            text-decoration:none;">
    <?= $pg ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Blog Posts | Admin';
require_once '../../views/layout-app.php';
