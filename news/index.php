<?php
/**
 * SalesDesk — Public: Blog / News Listing
 * Route: /news/
 *
 * Shows published posts newest-first with optional category filter.
 * First post gets a featured hero card; rest in a 3-column grid.
 * Wired into layout-public.php.
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

<!-- ── Category pills ─────────────────────────────────────────── -->
<div class="sd-section" style="padding-bottom:0;">
  <div class="container">
    <div class="sd-sec-header" style="margin-bottom:20px;">
      <div>
        <span class="sd-eyebrow">Stay informed</span>
        <h1 class="sd-sec-title">
          <?= $activeCategory ? htmlspecialchars($activeCategory['name']) : 'Car News &amp; Reviews' ?>
        </h1>
      </div>
    </div>

    <div class="news-pill-row">
      <a href="/news/" class="news-pill <?= !$categorySlug ? 'active' : '' ?>">All</a>
      <?php foreach ($categories as $cat): ?>
      <a href="/news/?category=<?= urlencode($cat['slug']) ?>"
         class="news-pill <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>">
        <?= htmlspecialchars($cat['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (empty($featuredPost) && empty($posts)): ?>
<!-- ── Empty state ── -->
<div class="sd-section">
  <div class="container">
    <div class="sd-pickup-empty">
      <div class="sd-pickup-empty__icon">📰</div>
      <div class="sd-pickup-empty__title">No articles yet</div>
      <div class="sd-pickup-empty__sub">Check back soon — new content is on its way.</div>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ── Featured post ─────────────────────────────────────────── -->
<?php if ($featuredPost): ?>
<div class="sd-section" style="padding-top:32px;">
  <div class="container">
    <a href="/news/<?= htmlspecialchars($featuredPost['slug']) ?>/" class="news-featured pub-reveal">
      <div class="news-featured__img">
        <img src="<?= htmlspecialchars(newsThumb($featuredPost)) ?>"
             alt="<?= htmlspecialchars($featuredPost['title']) ?>" loading="lazy">
      </div>
      <div class="news-featured__body">
        <?php if ($featuredPost['category_name']): ?>
        <span class="news-cat-badge"><?= htmlspecialchars($featuredPost['category_name']) ?></span>
        <?php endif; ?>
        <h2 class="news-featured__title"><?= htmlspecialchars($featuredPost['title']) ?></h2>
        <?php if ($featuredPost['excerpt']): ?>
        <p class="news-featured__desc"><?= htmlspecialchars($featuredPost['excerpt']) ?></p>
        <?php endif; ?>
        <div class="news-meta-row">
          <span><?= htmlspecialchars(newsAuthorName($featuredPost)) ?></span>
          <span class="news-meta-dot"></span>
          <span><?= date('d M Y', strtotime($featuredPost['published_at'])) ?></span>
          <span class="news-meta-dot"></span>
          <span><?= number_format((int)$featuredPost['view_count']) ?> views</span>
        </div>
      </div>
    </a>
  </div>
</div>
<?php endif; ?>

<!-- ── Article grid ──────────────────────────────────────────── -->
<?php if (!empty($posts)): ?>
<div class="sd-section" style="padding-top:24px;">
  <div class="container">
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
          <div class="news-meta-row" style="margin-top:auto;">
            <span><?= date('d M Y', strtotime($post['published_at'])) ?></span>
            <span class="news-meta-dot"></span>
            <span><?= number_format((int)$post['view_count']) ?> views</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- ── Pagination ── -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:3rem;flex-wrap:wrap;">
      <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_filter(['category' => $categorySlug, 'page' => $page - 1])) ?>"
         class="pub-btn pub-btn-ghost" style="padding:9px 20px;font-size:13px;">← Previous</a>
      <?php endif; ?>

      <?php for ($pg = max(1, $page - 2); $pg <= min($totalPages, $page + 2); $pg++): ?>
      <?php $qs = http_build_query(array_filter(['category' => $categorySlug, 'page' => $pg])); ?>
      <a href="?<?= $qs ?>"
         style="padding:9px 14px;border-radius:8px;font-size:13px;font-family:var(--mono);
                text-decoration:none;border:1px solid <?= $pg === $page ? 'var(--p)' : 'var(--border)' ?>;
                background:<?= $pg === $page ? 'var(--p)' : '#fff' ?>;
                color:<?= $pg === $page ? '#fff' : 'var(--muted)' ?>;">
        <?= $pg ?>
      </a>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_filter(['category' => $categorySlug, 'page' => $page + 1])) ?>"
         class="pub-btn pub-btn-ghost" style="padding:9px 20px;font-size:13px;">Next →</a>
      <?php endif; ?>
    </div>
    <div style="text-align:center;margin-top:12px;font-size:12px;color:var(--faint);">
      Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($totalPosts) ?> articles
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Newsletter subscribe strip ─────────────────────────────── -->
<section class="news-subscribe-strip">
  <div class="container" style="max-width:640px;text-align:center;">
    <div style="font-size:28px;margin-bottom:12px;">📬</div>
    <h2 style="font-family:var(--font-d);font-size:22px;font-weight:700;
               color:#fff;margin-bottom:8px;">Never miss a deal or launch</h2>
    <p style="font-size:14px;color:rgba(255,255,255,.75);margin-bottom:24px;line-height:1.65;">
      Join our newsletter for weekly car news, SA price updates, and exclusive broker tips.
    </p>
    <form id="newsSubscribeForm" style="display:flex;gap:0;max-width:420px;margin:0 auto 10px;">
      <input type="email" id="newsSubEmail" placeholder="Your email address" required
             style="flex:1;height:48px;border:none;border-radius:10px 0 0 10px;
                    padding:0 16px;font-size:14px;font-family:var(--sans);outline:none;">
      <button type="submit"
              style="height:48px;padding:0 20px;background:var(--p);color:#fff;
                     border:none;border-radius:0 10px 10px 0;font-size:14px;
                     font-weight:600;font-family:var(--sans);cursor:pointer;
                     transition:background .2s;white-space:nowrap;">
        Subscribe
      </button>
    </form>
    <p id="newsSubMsg" style="font-size:12px;color:rgba(255,255,255,.6);min-height:18px;"></p>
    <p style="font-size:11px;color:rgba(255,255,255,.4);margin-top:8px;">
      POPIA compliant &middot; Unsubscribe any time
    </p>
  </div>
</section>

<style>
/* ── Category pills ──────────────────────────────────────────── */
.news-pill-row { display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px; }
.news-pill {
  padding:7px 16px;border:1.5px solid var(--border);border-radius:999px;
  font-size:13px;font-weight:500;color:var(--muted);background:#fff;
  text-decoration:none;transition:all .2s;white-space:nowrap;
}
.news-pill:hover, .news-pill.active {
  background:var(--p);color:#fff;border-color:var(--p);text-decoration:none;
}

/* ── Featured post ───────────────────────────────────────────── */
.news-featured {
  display:grid;grid-template-columns:1fr 1fr;gap:0;
  border-radius:var(--r-xl);overflow:hidden;border:1px solid var(--border);
  box-shadow:0 4px 20px rgba(0,0,0,.07);text-decoration:none;color:inherit;
  transition:transform .3s ease,box-shadow .3s ease;
}
.news-featured:hover { transform:translateY(-4px);box-shadow:0 12px 36px rgba(15,76,158,.12);text-decoration:none; }
.news-featured__img { overflow:hidden;aspect-ratio:16/10; }
.news-featured__img img { width:100%;height:100%;object-fit:cover;transition:transform .4s ease; }
.news-featured:hover .news-featured__img img { transform:scale(1.04); }
.news-featured__body {
  background:#fff;padding:36px;display:flex;flex-direction:column;justify-content:center;
}
.news-featured__title {
  font-family:var(--font-d);font-size:clamp(20px,2.2vw,28px);font-weight:700;
  line-height:1.25;color:var(--text);margin:12px 0 14px;
}
.news-featured__desc { font-size:15px;line-height:1.7;color:var(--muted);margin-bottom:20px; }

/* ── Article grid ────────────────────────────────────────────── */
.news-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:22px; }
.news-card {
  background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);
  overflow:hidden;text-decoration:none;color:inherit;display:flex;flex-direction:column;
  transition:transform .3s ease,box-shadow .3s ease,border-color .2s;
  box-shadow:0 2px 10px rgba(0,0,0,.05);
}
.news-card:hover { transform:translateY(-4px);box-shadow:0 12px 32px rgba(15,76,158,.12);border-color:#c7d6f5;text-decoration:none; }
.news-card__img { overflow:hidden;aspect-ratio:16/9;position:relative; }
.news-card__img img { width:100%;height:100%;object-fit:cover;transition:transform .35s ease; }
.news-card:hover .news-card__img img { transform:scale(1.04); }
.news-card__cat {
  position:absolute;top:12px;left:12px;
  background:var(--p);color:#fff;font-size:10px;font-weight:700;letter-spacing:.04em;
  padding:3px 9px;border-radius:999px;
}
.news-card__body { padding:18px;flex:1;display:flex;flex-direction:column; }
.news-card__title { font-family:var(--font-d);font-size:16px;font-weight:700;line-height:1.3;color:var(--text);margin-bottom:8px; }
.news-card__excerpt { font-size:13px;line-height:1.65;color:var(--muted);margin-bottom:14px;
  display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden; }

/* ── Meta row ────────────────────────────────────────────────── */
.news-cat-badge {
  display:inline-block;background:var(--p-light);color:var(--p);border:1px solid var(--p-b);
  font-size:11px;font-weight:700;letter-spacing:.04em;padding:3px 10px;border-radius:999px;
  margin-bottom:10px;
}
.news-meta-row { display:flex;align-items:center;flex-wrap:wrap;gap:6px;font-size:12px;color:var(--faint); }
.news-meta-dot { width:3px;height:3px;background:var(--faint);border-radius:50%;flex-shrink:0; }

/* ── Subscribe strip ─────────────────────────────────────────── */
.news-subscribe-strip {
  background:linear-gradient(135deg,#08143c 0%,#0f2460 60%,#1a3a8a 100%);
  padding:64px 24px;margin-top:56px;
}

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width:900px) {
  .news-grid { grid-template-columns:repeat(2,1fr); }
  .news-featured { grid-template-columns:1fr; }
  .news-featured__img { aspect-ratio:16/7; }
}
@media (max-width:600px) {
  .news-grid { grid-template-columns:1fr; }
  .news-featured__body { padding:22px; }
}
</style>

<script>
/* Newsletter subscribe strip */
document.getElementById('newsSubscribeForm').addEventListener('submit', function(e) {
  e.preventDefault();
  var email  = document.getElementById('newsSubEmail').value.trim();
  var msgEl  = document.getElementById('newsSubMsg');
  var btn    = this.querySelector('button');

  btn.disabled = true;
  btn.textContent = '…';

  fetch('/api/newsletter/subscribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'email=' + encodeURIComponent(email) + '&source=news_page',
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.success || d.state === 'already_active') {
      msgEl.style.color = '#4ade80';
      msgEl.textContent = d.message || 'Check your inbox to confirm your subscription!';
      document.getElementById('newsSubEmail').value = '';
    } else {
      msgEl.style.color = '#fca5a5';
      msgEl.textContent = d.message || 'Something went wrong — please try again.';
    }
    btn.disabled = false;
    btn.textContent = 'Subscribe';
  })
  .catch(function() {
    msgEl.style.color = '#fca5a5';
    msgEl.textContent = 'Connection error — please try again.';
    btn.disabled = false;
    btn.textContent = 'Subscribe';
  });
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
