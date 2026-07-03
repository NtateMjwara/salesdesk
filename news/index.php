<?php
/**
 * SalesDesk — Public: Blog / News Listing
 * Route: /news/
 *
 * Shows published posts newest-first with optional category filter.
 * First post gets a featured "cover story" treatment; rest in a grid.
 * Wired into layout-public.php.
 *
 * FIX LOG (this pass):
 *   FIX-1  Removed the page-local "newsletter subscribe strip" — it
 *          duplicated the real site footer's newsletter form (same
 *          dark-navy full-width block), producing a "double footer"
 *          at the bottom of the page.
 *   FIX-2  .container, .sd-section, .sd-sec-header, .sd-eyebrow,
 *          .sd-sec-title and .sd-pickup-empty were all previously
 *          UNSTYLED on this page — those rules only exist in
 *          home.css, which is only ever <link>ed from index.php
 *          (the homepage). On /news/ those classes resolved to
 *          nothing, so the page had no max-width, no centering, and
 *          no eyebrow/title/empty-state typography at all. This pass
 *          replaces them with self-contained news-* classes defined
 *          in this file's own <style> block, so /news/ no longer
 *          silently depends on another page's stylesheet.
 *   FIX-3  New editorial visual pass: proper spacing scale, an
 *          asymmetric "cover story" featured card, quieter tab-style
 *          category pills, and a Fraunces (serif) display face for
 *          headlines — that font is already loaded site-wide via
 *          global.css but was never actually used anywhere, so News
 *          now has a distinct "magazine" register instead of
 *          borrowing the storefront's Sora/DM Sans treatment 1:1.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Category filter ───────────────────────────────────────────
$categorySlug = trim($_GET['category'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 12;
$offset       = ($page - 1) * $perPage;

$activeCategory = null;
if ($categorySlug) {
    $catStmt = $pdo->prepare('SELECT * FROM blog_categories WHERE slug = ? LIMIT 1');
    $catStmt->execute([$categorySlug]);
    $activeCategory = $catStmt->fetch();
    // Silently ignore invalid category slugs
    if (!$activeCategory) $categorySlug = '';
}

// ── Fetch all categories (for filter pills) ───────────────────
$categories = $pdo->query(
    "SELECT id, slug, name FROM blog_categories ORDER BY sort_order"
)->fetchAll();

// ── Total count ───────────────────────────────────────────────
$countSql = "
    SELECT COUNT(*)
    FROM blog_posts p
    WHERE p.status = 'published' AND p.published_at <= NOW()
" . ($categorySlug ? " AND p.category_id = (SELECT id FROM blog_categories WHERE slug = ?)" : "");

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($categorySlug ? [$categorySlug] : []);
$totalPosts = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalPosts / $perPage);

// ── Fetch posts ───────────────────────────────────────────────
$postSql = "
    SELECT
        p.id, p.slug, p.title, p.excerpt, p.featured_image_url,
        p.published_at, p.view_count,
        c.slug AS category_slug, c.name AS category_name,
        pr.first_name AS author_first, pr.last_name AS author_last
    FROM blog_posts p
    LEFT JOIN blog_categories c ON c.id = p.category_id
    JOIN users u  ON u.id = p.author_id
    LEFT JOIN profiles pr ON pr.user_id = p.author_id
    WHERE p.status = 'published' AND p.published_at <= NOW()
" . ($categorySlug ? " AND c.slug = ?" : "") . "
    ORDER BY p.published_at DESC
    LIMIT ? OFFSET ?
";

$postStmt = $pdo->prepare($postSql);
$postStmt->execute(
    $categorySlug
        ? [$categorySlug, $perPage, $offset]
        : [$perPage, $offset]
);
$posts = $postStmt->fetchAll();

// Separate featured (first post on page 1) from the rest
$featuredPost = ($page === 1 && !empty($posts)) ? array_shift($posts) : null;

// ── Helpers ───────────────────────────────────────────────────
function newsReadTime(string $content): string {
    $words = str_word_count(strip_tags($content));
    return max(1, (int) ceil($words / 220)) . ' min read';
}

function newsAuthorName(array $post): string {
    $name = trim(($post['author_first'] ?? '') . ' ' . ($post['author_last'] ?? ''));
    return $name ?: 'SalesDesk';
}

function newsThumb(array $post): string {
    return $post['featured_image_url']
        ?: 'https://images.unsplash.com/photo-1494976388531-d1058494cdd8?q=80&w=800&auto=format&fit=crop';
}

// ── Page meta ─────────────────────────────────────────────────
$pageTitle     = $activeCategory
    ? $activeCategory['name'] . ' | SalesDesk News'
    : 'Car News & Reviews | SalesDesk';
$ogTitle       = $activeCategory
    ? $activeCategory['name'] . ' — Latest from SalesDesk'
    : 'Car News, Reviews & Guides | SalesDesk South Africa';
$ogDescription = 'Stay informed with the latest South African car news, new launches, expert reviews, and buying guides from SalesDesk.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : '') . '/news/'
               . ($categorySlug ? '?category=' . urlencode($categorySlug) : '');
$shareUrl      = $canonicalUrl;
$shareTitle    = $pageTitle;

ob_start();
?>

<!-- ── Page head: eyebrow, title, category tabs ────────────────── -->
<header class="news-head">
  <div class="news-container">
    <span class="news-eyebrow">Stay informed</span>
    <h1 class="news-title">
      <?= $activeCategory ? htmlspecialchars($activeCategory['name']) : 'Car News &amp; Reviews' ?>
    </h1>

    <nav class="news-pills" aria-label="Filter by category">
      <a href="/news/" class="news-pill <?= !$categorySlug ? 'is-active' : '' ?>">All</a>
      <?php foreach ($categories as $cat): ?>
      <a href="/news/?category=<?= urlencode($cat['slug']) ?>"
         class="news-pill <?= $categorySlug === $cat['slug'] ? 'is-active' : '' ?>">
        <?= htmlspecialchars($cat['name']) ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<?php if (empty($featuredPost) && empty($posts)): ?>

<!-- ── Empty state ──────────────────────────────────────────────── -->
<div class="news-container">
  <div class="news-empty">
    <div class="news-empty__icon">📰</div>
    <div class="news-empty__title">No articles yet</div>
    <div class="news-empty__sub">Check back soon — new content is on its way.</div>
  </div>
</div>

<?php else: ?>

<!-- ── Featured / cover story ───────────────────────────────────── -->
<?php if ($featuredPost): ?>
<section class="news-container news-featured-wrap">
  <a href="/news/<?= htmlspecialchars($featuredPost['slug']) ?>/" class="news-featured pub-reveal">
    <div class="news-featured__img">
      <img src="<?= htmlspecialchars(newsThumb($featuredPost)) ?>"
           alt="<?= htmlspecialchars($featuredPost['title']) ?>" loading="lazy">
    </div>
    <div class="news-featured__body">
      <span class="news-featured__kicker">
        Latest story
        <?php if ($featuredPost['category_name']): ?>
        <span class="news-featured__kicker-dot"></span><?= htmlspecialchars($featuredPost['category_name']) ?>
        <?php endif; ?>
      </span>
      <h2 class="news-featured__title"><?= htmlspecialchars($featuredPost['title']) ?></h2>
      <?php if ($featuredPost['excerpt']): ?>
      <p class="news-featured__desc"><?= htmlspecialchars($featuredPost['excerpt']) ?></p>
      <?php endif; ?>
      <div class="news-meta">
        <span class="news-meta__author"><?= htmlspecialchars(newsAuthorName($featuredPost)) ?></span>
        <span class="news-meta__dot"></span>
        <span><?= date('d M Y', strtotime($featuredPost['published_at'])) ?></span>
        <span class="news-meta__dot"></span>
        <span><?= number_format((int)$featuredPost['view_count']) ?> views</span>
      </div>
      <span class="news-featured__cta">Read the full story <i class="fa-solid fa-arrow-right"></i></span>
    </div>
  </a>
</section>
<?php endif; ?>

<!-- ── Article grid ──────────────────────────────────────────────── -->
<?php if (!empty($posts)): ?>
<section class="news-container news-grid-wrap">
  <div class="news-grid">
    <?php foreach ($posts as $i => $post): ?>
    <a href="/news/<?= htmlspecialchars($post['slug']) ?>/" class="news-card pub-reveal"
       style="animation-delay:<?= ($i % 3) * 0.08 ?>s">
      <div class="news-card__img">
        <img src="<?= htmlspecialchars(newsThumb($post)) ?>"
             alt="<?= htmlspecialchars($post['title']) ?>" loading="lazy">
        <?php if ($post['category_name']): ?>
        <span class="news-card__cat"><?= htmlspecialchars($post['category_name']) ?></span>
        <?php endif; ?>
      </div>
      <div class="news-card__body">
        <h3 class="news-card__title"><?= htmlspecialchars($post['title']) ?></h3>
        <?php if ($post['excerpt']): ?>
        <p class="news-card__excerpt"><?= htmlspecialchars($post['excerpt']) ?></p>
        <?php endif; ?>
        <div class="news-meta news-card__meta">
          <span><?= date('d M Y', strtotime($post['published_at'])) ?></span>
          <span class="news-meta__dot"></span>
          <span><?= number_format((int)$post['view_count']) ?> views</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── Pagination ── -->
  <?php if ($totalPages > 1): ?>
  <nav class="news-pagination" aria-label="News pages">
    <?php if ($page > 1): ?>
    <a href="?<?= http_build_query(array_filter(['category' => $categorySlug, 'page' => $page - 1])) ?>"
       class="news-pagination__nav">
      <i class="fa-solid fa-arrow-left"></i> Previous
    </a>
    <?php endif; ?>

    <div class="news-pagination__pages">
      <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
      <?php $qs = http_build_query(array_filter(['category' => $categorySlug, 'page' => $pg])); ?>
      <a href="?<?= $qs ?>"
         class="news-pagination__page <?= $pg === $page ? 'is-active' : '' ?>"
         <?= $pg === $page ? 'aria-current="page"' : '' ?>>
        <?= $pg ?>
      </a>
      <?php endfor; ?>
    </div>

    <?php if ($page < $totalPages): ?>
    <a href="?<?= http_build_query(array_filter(['category' => $categorySlug, 'page' => $page + 1])) ?>"
       class="news-pagination__nav">
      Next <i class="fa-solid fa-arrow-right"></i>
    </a>
    <?php endif; ?>
  </nav>
  <div class="news-pagination__count">
    Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($totalPosts) ?> articles
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<style>
/* ══════════════════════════════════════════════════════════
   NEWS — shared container
   Fixes: this page previously relied on `.container`, `.sd-*`
   classes that only exist in home.css (homepage-only stylesheet).
   Self-contained here so /news/ never silently inherits — or
   fails to inherit — another page's layout rules.
   ══════════════════════════════════════════════════════════ */
.news-container {
  max-width: 1120px;
  margin-inline: auto;
  padding-inline: clamp(20px, 4vw, 48px);
  overflox-x: hidden;
}

/* ══════════════════════════════════════════════════════════
   PAGE HEAD
   ══════════════════════════════════════════════════════════ */
.news-head {
  padding: clamp(40px, 6vw, 64px) 0 clamp(24px, 3vw, 32px);
}

.news-eyebrow {
  display: inline-block;
  font-family: var(--font-d);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--p);
  margin-bottom: 10px;
}

.news-title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: clamp(30px, 4.2vw, 46px);
  line-height: 1.08;
  letter-spacing: -.01em;
  color: #08143c;
  margin-bottom: clamp(20px, 3vw, 28px);
}

/* Quiet, editorial tab-style pills — underline, not filled blob */
.news-pills {
  display: flex;
  flex-wrap: wrap;
  gap: clamp(18px, 3vw, 28px);
  border-bottom: 1px solid var(--border);
  padding-bottom: 2px;
}

.news-pill {
  position: relative;
  padding: 0 0 14px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--muted);
  text-decoration: none;
  white-space: nowrap;
  transition: color .18s ease;
}

.news-pill::after {
  content: '';
  position: absolute;
  left: 0; right: 0; bottom: -1px;
  height: 2px;
  background: var(--p);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform .22s cubic-bezier(.16,1,.3,1);
}

.news-pill:hover           { color: var(--p); text-decoration: none; }
.news-pill:hover::after    { transform: scaleX(1); }
.news-pill.is-active       { color: #08143c; }
.news-pill.is-active::after{ transform: scaleX(1); background: #08143c; }

.news-pill:focus-visible {
  outline: 2px solid var(--p);
  outline-offset: 4px;
  border-radius: 2px;
}

/* ══════════════════════════════════════════════════════════
   EMPTY STATE
   ══════════════════════════════════════════════════════════ */
.news-empty {
  text-align: center;
  padding: clamp(56px, 10vw, 96px) 24px;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  margin-bottom: clamp(56px, 8vw, 96px);
}
.news-empty__icon  { font-size: 36px; margin-bottom: 14px; }
.news-empty__title { font-family: var(--font-d); font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.news-empty__sub   { font-size: 13px; color: var(--faint); }

/* ══════════════════════════════════════════════════════════
   FEATURED / COVER STORY
   Asymmetric 7:5 split — reads as a genuine "cover story" rather
   than an oversized card.
   ══════════════════════════════════════════════════════════ */
.news-featured-wrap { padding-bottom: clamp(48px, 6vw, 72px); }

.news-featured {
  display: grid;
  grid-template-columns: 7fr 5fr;
  align-items: stretch;
  gap: 0;
  border-radius: var(--r-xl);
  overflow: hidden;
  border: 1px solid var(--border);
  background: var(--white);
  text-decoration: none;
  color: inherit;
  box-shadow: 0 1px 2px rgba(8,20,60,.04);
  transition: box-shadow .35s cubic-bezier(.16,1,.3,1), transform .35s cubic-bezier(.16,1,.3,1);
}
.news-featured:hover {
  text-decoration: none;
  box-shadow: 0 24px 48px rgba(8,20,60,.14);
  transform: translateY(-3px);
}

.news-featured__img { overflow: hidden; background: var(--bg); min-height: 320px; }
.news-featured__img img {
  width: 100%; height: 100%; object-fit: cover;
  transition: transform .6s cubic-bezier(.16,1,.3,1);
}
.news-featured:hover .news-featured__img img { transform: scale(1.045); }

.news-featured__body {
  padding: clamp(28px, 3.5vw, 48px);
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 14px;
}

.news-featured__kicker {
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-d);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--p);
}
.news-featured__kicker-dot {
  width: 3px; height: 3px; border-radius: 50%;
  background: var(--faint);
  flex-shrink: 0;
}

.news-featured__title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: clamp(22px, 2.6vw, 32px);
  line-height: 1.18;
  letter-spacing: -.01em;
  color: #08143c;
}

.news-featured__desc {
  font-size: 15px;
  line-height: 1.7;
  color: var(--muted);
}

.news-featured__cta {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
  font-family: var(--font-d);
  font-size: 13px;
  font-weight: 700;
  color: #08143c;
  transition: gap .2s ease;
}
.news-featured:hover .news-featured__cta { gap: 12px; }
.news-featured__cta i { font-size: 11px; color: var(--p); }

/* ══════════════════════════════════════════════════════════
   ARTICLE GRID
   ══════════════════════════════════════════════════════════ */
.news-grid-wrap { padding-bottom: clamp(64px, 9vw, 104px); }

.news-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: clamp(20px, 2.4vw, 28px);
}

.news-card {
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
.news-card:hover {
  text-decoration: none;
  transform: translateY(-4px);
  box-shadow: 0 16px 32px rgba(8,20,60,.10);
  border-color: #d6dfef;
}

.news-card__img { position: relative; aspect-ratio: 16/10; overflow: hidden; background: var(--bg); }
.news-card__img img {
  width: 100%; height: 100%; object-fit: cover;
  transition: transform .5s cubic-bezier(.16,1,.3,1);
}
.news-card:hover .news-card__img img { transform: scale(1.06); }

.news-card__cat {
  position: absolute;
  top: 12px; left: 12px;
  background: rgba(8,20,60,.72);
  backdrop-filter: blur(6px);
  color: #fff;
  font-family: var(--font-d);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  padding: 4px 10px;
  border-radius: var(--r-full);
}

.news-card__body {
  padding: 18px 20px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  flex: 1;
}

.news-card__title {
  font-family: var(--font-d);
  font-size: 15.5px;
  font-weight: 700;
  line-height: 1.35;
  color: var(--text);
}

.news-card__excerpt {
  font-size: 13px;
  line-height: 1.65;
  color: var(--muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.news-card__meta { margin-top: auto; padding-top: 4px; }

/* ══════════════════════════════════════════════════════════
   META ROW (shared by featured + cards)
   ══════════════════════════════════════════════════════════ */
.news-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
  font-size: 12px;
  color: var(--faint);
}
.news-meta__author { color: var(--text2); font-weight: 600; }
.news-meta__dot { width: 3px; height: 3px; border-radius: 50%; background: var(--faint); flex-shrink: 0; }

/* ══════════════════════════════════════════════════════════
   PAGINATION
   ══════════════════════════════════════════════════════════ */
.news-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: clamp(40px, 5vw, 56px);
  padding-top: 24px;
  border-top: 1px solid var(--border);
}

.news-pagination__nav {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-d);
  font-size: 13px;
  font-weight: 600;
  color: #08143c;
  text-decoration: none;
  transition: gap .2s ease, color .2s ease;
}
.news-pagination__nav:hover { color: var(--p); text-decoration: none; }
.news-pagination__nav i { font-size: 11px; }

.news-pagination__pages { display: flex; gap: 4px; }
.news-pagination__page {
  min-width: 30px;
  height: 30px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--r-sm);
  font-family: var(--mono);
  font-size: 12px;
  color: var(--muted);
  text-decoration: none;
  transition: background .15s ease, color .15s ease;
}
.news-pagination__page:hover      { background: var(--p-light); color: var(--p); text-decoration: none; }
.news-pagination__page.is-active  { background: #08143c; color: #fff; }

.news-pagination__count {
  text-align: center;
  margin-top: 14px;
  font-size: 11.5px;
  color: var(--faint);
}

/* ══════════════════════════════════════════════════════════
   MOTION
   ══════════════════════════════════════════════════════════ */
@media (prefers-reduced-motion: reduce) {
  .news-featured, .news-featured__img img,
  .news-card, .news-card__img img,
  .news-featured__cta, .news-pill::after { transition: none !important; }
}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════════════════════ */
@media (max-width: 960px) {
  .news-grid { grid-template-columns: repeat(2, 1fr); }
  .news-featured { grid-template-columns: 1fr; }
  .news-featured__img { min-height: 220px; aspect-ratio: 16/9; }
}

@media (max-width: 640px) {
  .news-container { padding-inline: 18px; }
  .news-grid { grid-template-columns: 1fr; gap: 18px; }
  .news-featured__body { padding: 22px; gap: 12px; }
  .news-pills { gap: 16px; overflow-x: auto; scrollbar-width: none; }
  .news-pills::-webkit-scrollbar { display: none; }
  .news-pagination { flex-wrap: wrap; justify-content: center; }
  .news-pagination__nav { order: 3; flex: 1 1 100%; justify-content: center; padding-top: 10px; }
}
</style>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';