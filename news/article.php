<?php
/**
 * SalesDesk — Public: Blog Article Detail
 * Route: /news/{slug}/  →  news/article.php?slug={slug}
 *
 * Increments view_count on every page load (simple MVP counter).
 * Shows related posts from the same category.
 * Wired into layout-public.php.
 *
 * FIX LOG (this pass):
 *   FIX-1  Removed the page-local "newsletter subscribe strip" at the
 *          bottom — it was a full-width dark-navy block with its own
 *          "subscribe" form, duplicating the real site footer's
 *          newsletter form (layout-public.php), which produced a
 *          visual "double footer". Same bug as news/index.php.
 *   FIX-2  `.container` was previously an UNSTYLED class on this page
 *          (it's only ever defined in home.css, which is homepage-
 *          only). The inline `style="max-width:780px"` therefore
 *          constrained the width but never centered it — the article
 *          was pinned to the left edge on any viewport wider than
 *          780px instead of sitting in the middle of the page. Fixed
 *          with a proper self-contained `.news-article-container`.
 *   FIX-3  Editorial typography pass: Fraunces (serif, already
 *          loaded site-wide via global.css but unused anywhere) for
 *          the headline and a drop cap on the opening paragraph,
 *          tightened line length for readability (~72ch), refined
 *          spacing rhythm, blockquote, and related-post cards to
 *          match the redesigned /news/ index.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';
require_once '../includes/structured-data.php';

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
// PRE-EXISTING BUG, fixed here: this fell back to an empty string
// instead of a real domain, meaning $canonicalUrl below could render
// as a bare '/news/{slug}/' (relative, not absolute) whenever
// SITE_URL isn't defined — invalid for a canonical tag, which must
// be absolute. Every other page in this codebase falls back to
// 'https://salesdesk.co.za'; this page is the one exception.
$siteUrl    = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';

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

<?php
// Article structured data — reuses the exact $post row already
// fetched above and the $canonicalUrl / $siteUrl already computed in
// the page-meta block. No new queries needed.
echo renderArticleSchema($post, $canonicalUrl, $siteUrl);
?>

<article class="news-article">
  <div class="news-article-container">

    <!-- ── Article header ── -->
    <header class="news-article__header">
      <?php if ($post['category_name']): ?>
      <a href="/news/?category=<?= urlencode($post['category_slug']) ?>" class="news-article__kicker">
        <?= htmlspecialchars($post['category_name']) ?>
      </a>
      <?php endif; ?>

      <h1 class="news-article__title"><?= htmlspecialchars($post['title']) ?></h1>

      <?php if ($post['excerpt']): ?>
      <p class="news-article__lede"><?= htmlspecialchars($post['excerpt']) ?></p>
      <?php endif; ?>

      <div class="news-article__meta">
        <div class="news-article__meta-author">
          <div class="news-article__avatar"><?= strtoupper(substr($authorName, 0, 1)) ?></div>
          <div>
            <div class="news-article__author-name"><?= htmlspecialchars($authorName) ?></div>
            <div class="news-article__author-sub">
              <?= date('d F Y', strtotime($post['published_at'])) ?>
              &middot; <?= $readTime ?>
              &middot; <?= number_format((int)$post['view_count']) ?> views
            </div>
          </div>
        </div>
        <button class="news-article__share-btn" onclick="openShareSheet()" type="button"
                aria-label="Share this article">
          <i class="fa-solid fa-share-nodes"></i>
          Share
        </button>
      </div>
    </header>

    <!-- ── Featured image ── -->
    <?php if ($post['featured_image_url']): ?>
    <div class="news-article__hero">
      <img src="<?= htmlspecialchars($post['featured_image_url']) ?>"
           alt="<?= htmlspecialchars($post['title']) ?>"
           loading="lazy">
    </div>
    <?php endif; ?>

    <!-- ── Article body ── -->
    <div class="news-article__body">
      <?= $post['content'] /* HTML from Quill — admin-generated, trusted */ ?>
    </div>

    <!-- ── Tags / share footer ── -->
    <div class="news-article__footer">
      <?php if ($post['category_name']): ?>
      <div class="news-article__filed-under">
        <span>Filed under</span>
        <a href="/news/?category=<?= urlencode($post['category_slug']) ?>" class="news-article__tag">
          <?= htmlspecialchars($post['category_name']) ?>
        </a>
      </div>
      <?php endif; ?>

      <button class="news-article__share-btn news-article__share-btn--outline"
              onclick="openShareSheet()" type="button">
        <i class="fa-solid fa-share-nodes"></i> Share this article
      </button>
    </div>

  </div><!-- /news-article-container -->
</article>

<!-- ── Related posts ─────────────────────────────────────────── -->
<?php if (!empty($related)): ?>
<section class="news-related">
  <div class="news-related-container">
    <div class="news-related__head">
      <h2 class="news-related__title">More from <?= htmlspecialchars($post['category_name']) ?></h2>
      <a href="/news/?category=<?= urlencode($post['category_slug']) ?>" class="news-related__link">
        View all <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>
    <div class="news-related__grid">
      <?php foreach ($related as $rel): ?>
      <a href="/news/<?= htmlspecialchars($rel['slug']) ?>/" class="news-related__card pub-reveal">
        <div class="news-related__img">
          <img src="<?= htmlspecialchars($rel['featured_image_url'] ?: 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?q=80&w=600&auto=format&fit=crop') ?>"
               alt="<?= htmlspecialchars($rel['title']) ?>" loading="lazy">
        </div>
        <div class="news-related__body">
          <h3 class="news-related__card-title"><?= htmlspecialchars($rel['title']) ?></h3>
          <?php if ($rel['excerpt']): ?>
          <p class="news-related__excerpt"><?= htmlspecialchars($rel['excerpt']) ?></p>
          <?php endif; ?>
          <div class="news-related__meta">
            <?= date('d M Y', strtotime($rel['published_at'])) ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!--
  REMOVED: <section class="news-subscribe-strip"> newsletter block
  and its #articleSubForm script. It duplicated the real site footer
  (layout-public.php), which already carries its own "Get car news &
  deal alerts" newsletter form in the same dark-navy full-width style,
  producing a double-footer effect at the bottom of the page.
-->

<style>
/* ══════════════════════════════════════════════════════════
   SHARED CONTAINERS
   Fixes: `.container` (used previously with an inline
   `style="max-width:780px"`) was never actually styled anywhere
   for this page — home.css owns `.container` and is homepage-only —
   so the article had a max-width but no `margin: auto`, pinning it
   to the left edge instead of centering it. Self-contained here.
   ══════════════════════════════════════════════════════════ */
.news-article-container {
  max-width: 720px;
  margin-inline: auto;
  padding-inline: clamp(20px, 4vw, 24px);
  overflow-x: hidden;
}
.news-related-container {
  max-width: 1120px;
  margin-inline: auto;
  padding-inline: clamp(20px, 4vw, 48px);
}

/* ══════════════════════════════════════════════════════════
   ARTICLE HEADER
   ══════════════════════════════════════════════════════════ */
.news-article { padding: clamp(40px, 6vw, 64px) 0 0; }

.news-article__header { margin-bottom: clamp(28px, 4vw, 40px); }

.news-article__kicker {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-d);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--p);
  text-decoration: none;
  margin-bottom: 18px;
}
.news-article__kicker::before {
  content: '';
  width: 20px; height: 1.5px;
  background: var(--p);
  display: inline-block;
}
.news-article__kicker:hover { text-decoration: none; opacity: .75; }

.news-article__title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: clamp(30px, 4.6vw, 48px);
  line-height: 1.12;
  letter-spacing: -.015em;
  color: #08143c;
  margin-bottom: 18px;
}

.news-article__lede {
  font-family: var(--serif);
  font-style: italic;
  font-size: clamp(17px, 1.9vw, 20px);
  line-height: 1.6;
  color: var(--text2);
  margin-bottom: 26px;
}

.news-article__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 0;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}
.news-article__meta-author { display: flex; align-items: center; gap: 12px; }
.news-article__avatar {
  width: 38px; height: 38px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--p), #1d4ed8);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-d);
  font-size: 14px; font-weight: 700;
  flex-shrink: 0;
}
.news-article__author-name { font-size: 13px; font-weight: 600; color: var(--text); }
.news-article__author-sub  { font-size: 12px; color: var(--faint); margin-top: 1px; }

.news-article__share-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 16px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  background: #fff;
  font-size: 13px;
  font-weight: 500;
  color: var(--muted);
  cursor: pointer;
  font-family: var(--sans);
  transition: border-color .18s ease, color .18s ease;
  flex-shrink: 0;
}
.news-article__share-btn:hover { border-color: var(--p); color: var(--p); }
.news-article__share-btn--outline { width: 100%; justify-content: center; }

/* ══════════════════════════════════════════════════════════
   HERO IMAGE
   ══════════════════════════════════════════════════════════ */
.news-article__hero {
  border-radius: var(--r-xl);
  overflow: hidden;
  margin-bottom: clamp(32px, 5vw, 48px);
  aspect-ratio: 16/9;
  box-shadow: 0 12px 32px rgba(8,20,60,.10);
}
.news-article__hero img { width: 100%; height: 100%; object-fit: cover; }

/* ══════════════════════════════════════════════════════════
   BODY TYPOGRAPHY
   Drop cap on the opening paragraph is the one deliberate
   "signature" flourish — Fraunces is already loaded site-wide
   but otherwise unused, so it reads as a genuine editorial cue
   rather than decoration.
   ══════════════════════════════════════════════════════════ */
.news-article__body {
  font-size: 18px;
  line-height: 1.85;
  color: #334155;
  margin-bottom: clamp(40px, 6vw, 56px);
}
.news-article__body > p:first-of-type::first-letter {
  font-family: var(--serif);
  font-weight: 600;
  font-size: 3.6em;
  line-height: .82;
  float: left;
  padding: .05em .07em 0 0;
  color: #08143c;
}
.news-article__body h2 {
  font-family: var(--serif);
  font-weight: 500;
  font-size: 27px;
  color: #08143c;
  margin: 1.9em 0 .7em;
  letter-spacing: -.01em;
}
.news-article__body h3 {
  font-family: var(--font-d);
  font-size: 19px;
  font-weight: 700;
  color: var(--text);
  margin: 1.7em 0 .6em;
}
.news-article__body p { margin-bottom: 1.35em; }
.news-article__body a { color: var(--p); text-underline-offset: 2px; }
.news-article__body a:hover { text-decoration: underline; }
.news-article__body strong { font-weight: 700; color: var(--text); }
.news-article__body blockquote {
  position: relative;
  margin: 2.2em 0;
  padding: 6px 0 6px 28px;
  border-left: 3px solid var(--p);
  font-family: var(--serif);
  font-style: italic;
  font-size: 1.15em;
  line-height: 1.6;
  color: #08143c;
}
.news-article__body ul, .news-article__body ol { margin: 1.2em 0 1.4em 1.4em; }
.news-article__body li { margin-bottom: .5em; }
.news-article__body pre, .news-article__body code {
  font-family: var(--mono);
  font-size: 14px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 6px;
}
.news-article__body pre  { padding: 16px; overflow-x: auto; margin: 1.5em 0; }
.news-article__body code { padding: 2px 6px; }
.news-article__body img  { max-width: 100%; border-radius: var(--r-lg); margin: 1.5em 0; }

/* ══════════════════════════════════════════════════════════
   FOOTER (tags + share)
   ══════════════════════════════════════════════════════════ */
.news-article__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  padding: 24px 0 clamp(56px, 8vw, 88px);
  border-top: 1px solid var(--border);
}
.news-article__filed-under { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--faint); }
.news-article__tag {
  display: inline-block;
  background: var(--bg);
  border: 1px solid var(--border);
  color: var(--muted);
  font-size: 12px;
  padding: 4px 12px;
  border-radius: 999px;
  text-decoration: none;
  transition: all .2s ease;
}
.news-article__tag:hover { background: var(--p); color: #fff; border-color: var(--p); text-decoration: none; }

/* ══════════════════════════════════════════════════════════
   RELATED POSTS
   Same visual language as the redesigned /news/ index cards.
   ══════════════════════════════════════════════════════════ */
.news-related { background: var(--bg2); padding: clamp(48px, 7vw, 72px) 0; }

.news-related__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 26px;
}
.news-related__title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: 22px;
  color: #08143c;
}
.news-related__link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-d);
  font-size: 13px;
  font-weight: 600;
  color: var(--p);
  text-decoration: none;
  white-space: nowrap;
}
.news-related__link:hover { color: var(--p-dark); text-decoration: none; }
.news-related__link i { font-size: 11px; }

.news-related__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: clamp(18px, 2.4vw, 24px);
}
.news-related__card {
  display: flex;
  flex-direction: column;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  box-shadow: 0 1px 2px rgba(8,20,60,.03);
  transition: box-shadow .3s cubic-bezier(.16,1,.3,1), transform .3s cubic-bezier(.16,1,.3,1), border-color .2s;
}
.news-related__card:hover {
  text-decoration: none;
  transform: translateY(-4px);
  box-shadow: 0 16px 32px rgba(8,20,60,.10);
  border-color: #d6dfef;
}
.news-related__img { aspect-ratio: 16/10; overflow: hidden; background: var(--bg); }
.news-related__img img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s cubic-bezier(.16,1,.3,1); }
.news-related__card:hover .news-related__img img { transform: scale(1.06); }
.news-related__body { padding: 16px 18px 18px; display: flex; flex-direction: column; gap: 8px; }
.news-related__card-title { font-family: var(--font-d); font-size: 14.5px; font-weight: 700; line-height: 1.35; color: var(--text); }
.news-related__excerpt {
  font-size: 12.5px; line-height: 1.6; color: var(--muted);
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.news-related__meta { font-size: 11.5px; color: var(--faint); margin-top: auto; padding-top: 2px; }

/* ══════════════════════════════════════════════════════════
   MOTION
   ══════════════════════════════════════════════════════════ */
@media (prefers-reduced-motion: reduce) {
  .news-related__card, .news-related__img img,
  .news-article__share-btn, .news-article__tag { transition: none !important; }
}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════════════════════ */
@media (max-width: 900px) {
  .news-related__grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
  .news-article__meta { flex-direction: column; align-items: flex-start; gap: 14px; }
  .news-article__share-btn { width: 100%; justify-content: center; }
  .news-article__body { font-size: 16.5px; line-height: 1.78; }
  .news-article__body > p:first-of-type::first-letter { font-size: 3em; }
  .news-related__grid { grid-template-columns: 1fr; }
  .news-related__head { flex-direction: column; align-items: flex-start; gap: 8px; }
}
</style>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
