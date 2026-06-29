<?php
/**
 * SalesDesk — Public: Blog Article Detail
 * Route: /news/{slug}/  →  news/article.php?slug={slug}
 *
 * Increments view_count on every page load (simple MVP counter).
 * Shows related posts from the same category.
 * Wired into layout-public.php.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');

$pdo  = Database::getInstance();
$slug = trim($_GET['slug'] ?? '');

if (!$slug) {
    header('Location: /news/');
    exit;
}

// ── Fetch post ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        p.id, p.slug, p.title, p.excerpt, p.content,
        p.featured_image_url, p.published_at, p.view_count,
        p.meta_title, p.meta_description,
        c.slug AS category_slug, c.name AS category_name,
        pr.first_name AS author_first, pr.last_name AS author_last
    FROM blog_posts p
    LEFT JOIN blog_categories c ON c.id = p.category_id
    JOIN users u ON u.id = p.author_id
    LEFT JOIN profiles pr ON pr.user_id = p.author_id
    WHERE p.slug = ?
      AND p.status = 'published'
      AND p.published_at <= NOW()
    LIMIT 1
");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    include '../404.php';
    exit;
}

// ── Increment view count (fire-and-forget, ignore errors) ─────
try {
    $pdo->prepare('UPDATE blog_posts SET view_count = view_count + 1 WHERE id = ?')
        ->execute([$post['id']]);
} catch (Throwable) {}

// ── Related posts (same category, excluding this one) ─────────
$related = [];
if ($post['category_slug']) {
    $relStmt = $pdo->prepare("
        SELECT p.slug, p.title, p.excerpt, p.featured_image_url, p.published_at
        FROM blog_posts p
        JOIN blog_categories c ON c.id = p.category_id
        WHERE c.slug = ?
          AND p.slug != ?
          AND p.status = 'published'
          AND p.published_at <= NOW()
        ORDER BY p.published_at DESC
        LIMIT 3
    ");
    $relStmt->execute([$post['category_slug'], $slug]);
    $related = $relStmt->fetchAll();
}

// ── Helpers ───────────────────────────────────────────────────
function articleReadTime(string $content): string {
    $words = str_word_count(strip_tags($content));
    return max(1, (int) ceil($words / 220)) . ' min read';
}

function articleAuthor(array $post): string {
    $name = trim(($post['author_first'] ?? '') . ' ' . ($post['author_last'] ?? ''));
    return $name ?: 'SalesDesk Editorial';
}

function articleThumb(array $post, string $size = 'large'): string {
    return $post['featured_image_url']
        ?: 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?q=80&w='
           . ($size === 'large' ? '1400' : '600') . '&auto=format&fit=crop';
}

$readTime   = articleReadTime($post['content']);
$authorName = articleAuthor($post);
$siteUrl    = defined('SITE_URL') ? SITE_URL : '';

// ── Page meta ─────────────────────────────────────────────────
$pageTitle    = ($post['meta_title'] ?: $post['title']) . ' | SalesDesk';
$ogTitle      = $post['meta_title'] ?: $post['title'];
$ogDescription = $post['meta_description'] ?: ($post['excerpt'] ?: 'Read the latest car news and reviews on SalesDesk South Africa.');
$ogImage      = articleThumb($post);
$canonicalUrl = $siteUrl . '/news/' . $post['slug'] . '/';
$shareUrl     = $canonicalUrl;
$shareTitle   = $post['title'];
$breadcrumbs  = [
    ['News', '/news/'],
    $post['category_name'] ? [$post['category_name'], '/news/?category=' . $post['category_slug']] : null,
    [$post['title'], null],
];
$breadcrumbs    = array_filter($breadcrumbs);
$showBreadcrumb = true;

ob_start();
?>

<article class="article-wrap">
  <div class="container" style="max-width:780px;">

    <!-- ── Article header ── -->
    <header class="article-header">
      <?php if ($post['category_name']): ?>
      <a href="/news/?category=<?= urlencode($post['category_slug']) ?>" class="article-cat">
        <?= htmlspecialchars($post['category_name']) ?>
      </a>
      <?php endif; ?>

      <h1 class="article-title"><?= htmlspecialchars($post['title']) ?></h1>

      <?php if ($post['excerpt']): ?>
      <p class="article-lede"><?= htmlspecialchars($post['excerpt']) ?></p>
      <?php endif; ?>

      <div class="article-meta">
        <div class="article-meta__author">
          <div class="article-meta__av">
            <?= strtoupper(substr($authorName, 0, 1)) ?>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--text);"><?= htmlspecialchars($authorName) ?></div>
            <div style="font-size:12px;color:var(--faint);">
              <?= date('d F Y', strtotime($post['published_at'])) ?>
              &middot; <?= $readTime ?>
              &middot; <?= number_format((int)$post['view_count']) ?> views
            </div>
          </div>
        </div>
        <button class="article-share-btn" onclick="openShareSheet()" type="button"
                aria-label="Share this article">
          <i class="fa-solid fa-share-nodes"></i>
          Share
        </button>
      </div>
    </header>

    <!-- ── Featured image ── -->
    <?php if ($post['featured_image_url']): ?>
    <div class="article-hero-img">
      <img src="<?= htmlspecialchars($post['featured_image_url']) ?>"
           alt="<?= htmlspecialchars($post['title']) ?>"
           loading="lazy">
    </div>
    <?php endif; ?>

    <!-- ── Article body ── -->
    <div class="article-body">
      <?= $post['content'] /* HTML from Quill — admin-generated, trusted */ ?>
    </div>

    <!-- ── Tags / share footer ── -->
    <div class="article-footer">
      <?php if ($post['category_name']): ?>
      <div>
        <span style="font-size:12px;color:var(--faint);margin-right:8px;">Filed under:</span>
        <a href="/news/?category=<?= urlencode($post['category_slug']) ?>" class="article-tag">
          <?= htmlspecialchars($post['category_name']) ?>
        </a>
      </div>
      <?php endif; ?>

      <button class="pub-btn pub-btn-ghost" onclick="openShareSheet()" type="button"
              style="display:inline-flex;align-items:center;gap:8px;font-size:13px;">
        <i class="fa-solid fa-share-nodes"></i> Share this article
      </button>
    </div>

  </div><!-- /container -->
</article>

<!-- ── Related posts ─────────────────────────────────────────── -->
<?php if (!empty($related)): ?>
<section class="sd-section" style="background:var(--bg);padding-top:48px;">
  <div class="container">
    <div class="sd-sec-header" style="margin-bottom:24px;">
      <h2 class="sd-sec-title" style="font-size:20px;">More from <?= htmlspecialchars($post['category_name']) ?></h2>
      <a href="/news/?category=<?= urlencode($post['category_slug']) ?>" class="sd-sec-link">
        View all →
      </a>
    </div>
    <div class="news-grid news-grid--related">
      <?php foreach ($related as $rel): ?>
      <a href="/news/<?= htmlspecialchars($rel['slug']) ?>/" class="news-card pub-reveal">
        <div class="news-card__img">
          <img src="<?= htmlspecialchars($rel['featured_image_url'] ?: 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?q=80&w=600&auto=format&fit=crop') ?>"
               alt="<?= htmlspecialchars($rel['title']) ?>" loading="lazy">
        </div>
        <div class="news-card__body">
          <h3 class="news-card__title"><?= htmlspecialchars($rel['title']) ?></h3>
          <?php if ($rel['excerpt']): ?>
          <p class="news-card__excerpt"><?= htmlspecialchars($rel['excerpt']) ?></p>
          <?php endif; ?>
          <div class="news-meta-row" style="margin-top:auto;">
            <span><?= date('d M Y', strtotime($rel['published_at'])) ?></span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── Newsletter strip ───────────────────────────────────────── -->
<section class="news-subscribe-strip">
  <div class="container" style="max-width:580px;text-align:center;">
    <h2 style="font-family:var(--font-d);font-size:22px;font-weight:700;color:#fff;margin-bottom:8px;">
      Enjoyed this article?
    </h2>
    <p style="font-size:14px;color:rgba(255,255,255,.75);margin-bottom:22px;line-height:1.65;">
      Get weekly car news and deal alerts straight to your inbox.
    </p>
    <form id="articleSubForm" style="display:flex;gap:0;max-width:400px;margin:0 auto 10px;">
      <input type="email" id="artSubEmail" placeholder="Your email" required
             style="flex:1;height:46px;border:none;border-radius:8px 0 0 8px;
                    padding:0 14px;font-size:14px;font-family:var(--sans);outline:none;">
      <button type="submit"
              style="height:46px;padding:0 18px;background:var(--p);color:#fff;border:none;
                     border-radius:0 8px 8px 0;font-size:14px;font-weight:600;
                     font-family:var(--sans);cursor:pointer;">
        Subscribe
      </button>
    </form>
    <p id="artSubMsg" style="font-size:12px;color:rgba(255,255,255,.6);min-height:16px;"></p>
  </div>
</section>

<style>
/* ── Article layout ──────────────────────────────────────────── */
.article-wrap { padding: 40px 0 0; }

.article-header { margin-bottom: 32px; }

.article-cat {
  display:inline-block;background:var(--p-light);color:var(--p);
  border:1px solid var(--p-b);font-size:11px;font-weight:700;letter-spacing:.05em;
  padding:4px 12px;border-radius:999px;text-decoration:none;margin-bottom:18px;
  transition:background .2s;
}
.article-cat:hover { background:var(--p);color:#fff;text-decoration:none; }

.article-title {
  font-family: var(--font-d);
  font-size: clamp(26px, 4vw, 42px);
  font-weight: 800;
  line-height: 1.15;
  color: var(--text);
  letter-spacing: -.5px;
  margin-bottom: 18px;
}

.article-lede {
  font-size: 18px;
  line-height: 1.7;
  color: var(--muted);
  margin-bottom: 24px;
  font-weight: 400;
}

.article-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 0;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  margin-bottom: 32px;
}
.article-meta__author { display:flex;align-items:center;gap:12px; }
.article-meta__av {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--p), #1d4ed8);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-d);
  font-size: 14px;
  font-weight: 700;
  flex-shrink: 0;
}
.article-share-btn {
  display:inline-flex;align-items:center;gap:7px;
  padding:8px 16px;border:1.5px solid var(--border);border-radius:8px;
  background:#fff;font-size:13px;font-weight:500;color:var(--muted);
  cursor:pointer;font-family:var(--sans);transition:all .2s;
}
.article-share-btn:hover { border-color:var(--p);color:var(--p); }

/* ── Hero image ──────────────────────────────────────────────── */
.article-hero-img {
  border-radius: var(--r-xl);
  overflow: hidden;
  margin-bottom: 40px;
  aspect-ratio: 16 / 7;
}
.article-hero-img img { width:100%;height:100%;object-fit:cover; }

/* ── Article body typography ─────────────────────────────────── */
.article-body {
  font-size: 17px;
  line-height: 1.82;
  color: #334155;
  margin-bottom: 40px;
}
.article-body h2 {
  font-family: var(--font-d);
  font-size: 24px;
  font-weight: 700;
  color: var(--text);
  margin: 2.2em 0 .8em;
  letter-spacing: -.3px;
}
.article-body h3 {
  font-family: var(--font-d);
  font-size: 20px;
  font-weight: 700;
  color: var(--text);
  margin: 1.8em 0 .6em;
}
.article-body p { margin-bottom: 1.4em; }
.article-body a { color: var(--p); }
.article-body a:hover { text-decoration: underline; }
.article-body strong { font-weight: 700; color: var(--text); }
.article-body blockquote {
  border-left: 4px solid var(--p);
  margin: 2em 0;
  padding: 14px 22px;
  background: var(--p-light);
  border-radius: 0 8px 8px 0;
  font-style: italic;
  color: var(--muted);
}
.article-body ul, .article-body ol {
  margin: 1.2em 0 1.4em 1.4em;
}
.article-body li { margin-bottom: .5em; }
.article-body pre, .article-body code {
  font-family: var(--mono);
  font-size: 14px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 6px;
}
.article-body pre { padding: 16px; overflow-x: auto; margin: 1.5em 0; }
.article-body code { padding: 2px 6px; }
.article-body img {
  max-width: 100%;
  border-radius: var(--r-lg);
  margin: 1.5em 0;
}

/* ── Footer ──────────────────────────────────────────────────── */
.article-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  padding: 24px 0;
  border-top: 1px solid var(--border);
  margin-bottom: 48px;
}
.article-tag {
  display:inline-block;background:var(--bg);border:1px solid var(--border);
  color:var(--muted);font-size:12px;padding:4px 12px;border-radius:999px;
  text-decoration:none;transition:all .2s;
}
.article-tag:hover { background:var(--p);color:#fff;border-color:var(--p);text-decoration:none; }

/* ── Related grid ────────────────────────────────────────────── */
.news-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:20px; }
.news-grid--related {}

/* ── Subscribe strip ─────────────────────────────────────────── */
.news-subscribe-strip {
  background:linear-gradient(135deg,#08143c 0%,#0f2460 60%,#1a3a8a 100%);
  padding:56px 24px;margin-top:0;
}

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 900px) {
  .news-grid { grid-template-columns:repeat(2,1fr); }
}
@media (max-width: 640px) {
  .article-meta { flex-direction:column;align-items:flex-start;gap:12px; }
  .article-body { font-size:16px;line-height:1.75; }
  .news-grid--related { grid-template-columns:1fr; }
}
</style>

<script>
/* Article page newsletter subscribe */
document.getElementById('articleSubForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var email = document.getElementById('artSubEmail').value.trim();
  var msgEl = document.getElementById('artSubMsg');
  var btn   = this.querySelector('button');
  btn.disabled = true;

  fetch('/api/newsletter/subscribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'email=' + encodeURIComponent(email) + '&source=article_page',
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    msgEl.style.color = d.success ? '#4ade80' : '#fca5a5';
    msgEl.textContent = d.message;
    if (d.success) document.getElementById('artSubEmail').value = '';
    btn.disabled = false;
  })
  .catch(function() {
    msgEl.style.color = '#fca5a5';
    msgEl.textContent = 'Something went wrong — please try again.';
    btn.disabled = false;
  });
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
