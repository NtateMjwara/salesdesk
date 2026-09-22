<?php
/**
 * SalesDesk — 404 / 500 page  (v2)
 * Wired as ErrorDocument 404 and 500 in .htaccess.
 *
 * v2 (public UX/UI overhaul — desk pages pass):
 *   – Rebuilt on the shared system; no inline styles.
 *   – Offers the routes people actually want (browse, find a desk,
 *     search) instead of a dead-end message. The "Browse cars" button
 *     pointed at the retired /c/ route — now /cars-for-sale/.
 *   – Keeps the requested path in view so a mistyped desk slug is
 *     obvious, and searches it against the car catalogue.
 */
declare(strict_types=1);
require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

http_response_code(404);
applyCachePolicy('public');
$visitor = initVisitorSession();

// The slug the visitor tried — useful as a search term ("thabo-drves").
$requested = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '', '/');
$lastSeg   = basename($requested);
$lastSeg   = preg_replace('/\.(php|html?)$/i', '', $lastSeg);
$guess     = trim(preg_replace('/[^a-z0-9]+/i', ' ', $lastSeg));
// Don't prefill with our own error-page path or anything overly long.
if (strlen($guess) > 40 || in_array(strtolower($guess), ['404', '500', 'broker 404', 'index'], true)) $guess = '';

$pageTitle         = 'Page not found | SalesDesk';
$ogDescription     = 'That page has moved or no longer exists. Browse cars for sale on SalesDesk instead.';
$metaRobotsNoindex = true;
$layoutVariant     = 'wide';

$includeBrowseCss     = false;
$includeHowItWorksCss = false;

$assetVersion = $assetVersion ?? date('Ymd');
$extraCss     = '<link rel="stylesheet" href="/assets/css/desks.css?v=' . $assetVersion . '">' . "\n";

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="sd-container">
  <section class="err pub-anim" aria-labelledby="errTitle">
    <span class="err__code" aria-hidden="true">404</span>
    <h1 class="err__title" id="errTitle">We can’t find that page</h1>
    <p class="err__sub">
      <?php if ($requested !== ''): ?>
      Nothing lives at <code class="err__path">/<?= $e($requested) ?></code> — a desk may have been renamed, or the link is mistyped.
      <?php else: ?>
      That page has moved, been removed, or never existed.
      <?php endif; ?>
    </p>

    <form class="err__search" action="/cars-for-sale/" method="get" role="search" data-clean-submit>
      <i class="fa-solid fa-magnifying-glass err__search-icon" aria-hidden="true"></i>
      <label class="sr-only" for="errSearch">Search cars</label>
      <input class="err__search-input" type="search" id="errSearch" name="q" value="<?= $e($guess) ?>"
             placeholder="Search make or model" autocomplete="off" enterkeyhint="search"
             data-typeahead-box="errSearchBox">
      <div id="errSearchBox" class="typeahead-box" role="listbox" aria-label="Search suggestions"></div>
      <button class="pub-btn pub-btn-primary err__search-btn" type="submit">Search</button>
    </form>

    <div class="err__links">
      <a class="err__link" href="/cars-for-sale/">
        <span class="err__link-icon"><i class="fa-solid fa-car" aria-hidden="true"></i></span>
        <span><strong>Browse all cars</strong><span>Every verified dealer, one search</span></span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
      <a class="err__link" href="/desks/">
        <span class="err__link-icon"><i class="fa-solid fa-id-card" aria-hidden="true"></i></span>
        <span><strong>Find a SalesDesk</strong><span>Brokers near you</span></span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
      <a class="err__link" href="/">
        <span class="err__link-icon"><i class="fa-solid fa-house" aria-hidden="true"></i></span>
        <span><strong>Go to the homepage</strong><span>Start again from the top</span></span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>
  </section>
</div>
<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
