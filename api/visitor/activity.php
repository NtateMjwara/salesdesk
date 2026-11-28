<?php
/**
 * SalesDesk — Visitor Activity fragment
 * GET /api/visitor/activity.php
 *
 * Returns the homepage "Pick up where you left off" section as an HTML
 * fragment for the current anonymous visitor (sd_vid cookie).
 *
 * WHY AN ENDPOINT: the homepage is served with Cache-Control: public so
 * a proxy/CDN may cache it. Personal data (recently viewed, wishlist,
 * saved searches) must never live in that cached HTML, so assets/js/home.js
 * fetches it from here after the page renders. See index.php HOME-9.
 *
 * Responses:
 *   200 text/html   section inner markup (tabs + panels)
 *   204             no cookie yet, or no activity to show → section stays hidden
 *   403             missing X-Requested-With header
 *   429             rate limited
 *
 * Never cached (no-store, private).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once __DIR__ . '/../../includes/vip_network.php';   // 4a: network-only cars stay off platform listings
require_once '../../includes/functions.php';
require_once '../../includes/visitor.php';
require_once '../../includes/listing-helpers.php';
require_once '../../views/partials/vehicle-card.php';

applyCachePolicy('api');
header('Cache-Control: no-store, private');
header('Vary: Cookie');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// Same-origin XHR only (same guard as wishlist-toggle.php).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    exit;
}

// A brand-new visitor has no history — answer without touching the DB
// and without creating a session row.
$token = $_COOKIE[VISITOR_COOKIE_NAME] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(204);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (function_exists('checkApiRateLimit') && !checkApiRateLimit($ip, 'visitor_activity')) {
    http_response_code(429);
    exit;
}

$carColumns = "
    c.id, c.slug AS car_slug, c.make, c.model, c.year, c.price,
    c.mileage, c.condition_type, c.body_type, c.fuel_type,
    c.transmission, c.drivetrain, c.image_urls,
    d.company_name        AS dealer_name,
    d.verification_status AS dealer_verified,
    a.province            AS dealer_province
";

try {
    $pdo       = Database::getInstance();
    $visitor   = initVisitorSession();
    $visitorId = (int) $visitor['id'];

    // ── Recently viewed (8 distinct cars, newest view first) ────
    $stmt = $pdo->prepare("
        SELECT {$carColumns}
        FROM (
            SELECT car_id, MAX(viewed_at) AS last_viewed
            FROM car_views
            WHERE visitor_session_id = ?
            GROUP BY car_id
            ORDER BY last_viewed DESC
            LIMIT 16
        ) v
        JOIN cars c           ON c.id = v.car_id AND c.status = 'active' AND " . sdPublicCarSql('c') . "
        JOIN dealers d        ON d.id = c.dealer_id AND d.is_active = 1
        LEFT JOIN addresses a ON a.id = d.address_id
        ORDER BY v.last_viewed DESC
        LIMIT 8
    ");
    $stmt->execute([$visitorId]);
    $recent = $stmt->fetchAll();

    // ── Wishlist (keeps the order saved) ────────────────────────
    $wishIds  = array_slice(getWishlistCarIds($visitorId), 0, 8);
    $wishlist = [];
    if ($wishIds) {
        $in   = implode(',', array_fill(0, count($wishIds), '?'));
        $stmt = $pdo->prepare("
            SELECT {$carColumns}
            FROM cars c
            JOIN dealers d        ON d.id = c.dealer_id AND d.is_active = 1
            LEFT JOIN addresses a ON a.id = d.address_id
            WHERE c.id IN ({$in}) AND c.status = 'active' AND " . sdPublicCarSql('c') . "
        ");
        $stmt->execute($wishIds);
        $order = array_flip($wishIds);
        $wishlist = $stmt->fetchAll();
        usort($wishlist, static fn ($x, $y) => ($order[$x['id']] ?? PHP_INT_MAX) <=> ($order[$y['id']] ?? PHP_INT_MAX));
    }

    // Attribute desks for both lists in ONE query.
    $attributed = sdAttachFirstDesks($pdo, array_merge($recent, $wishlist));
    $recent     = array_slice($attributed, 0, count($recent));
    $wishlist   = array_slice($attributed, count($recent));

    // ── Saved searches ──────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT label, query_string, created_at
        FROM saved_searches
        WHERE visitor_session_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$visitorId]);
    $saved = $stmt->fetchAll();

} catch (Throwable $ex) {
    error_log('[SalesDesk visitor-activity] ' . $ex->getMessage());
    http_response_code(204);
    exit;
}

// Only tabs that have something in them.
$tabs = array_filter([
    'recent'   => ['Recently viewed', 'fa-eye',      $recent],
    'wishlist' => ['Wishlist',        'fa-heart',    $wishlist],
    'saved'    => ['Saved searches',  'fa-bookmark', $saved],
], static fn ($t) => !empty($t[2]));

if (!$tabs) {
    http_response_code(204);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$e     = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$first = array_key_first($tabs);
?>
<div class="sd-container">
  <header class="sd-sec-head">
    <div>
      <p class="sd-sec-head__eyebrow"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Your activity</p>
      <h2 class="sd-sec-head__title" id="activityTitle">Pick up where you left off</h2>
    </div>
  </header>

  <?php if (count($tabs) > 1): ?>
  <div class="sd-tabs" role="tablist" aria-label="Your activity">
    <?php foreach ($tabs as $key => [$label, $icon, $items]): ?>
    <button class="sd-tab" type="button" role="tab" id="act-tab-<?= $key ?>"
            aria-controls="act-panel-<?= $key ?>"
            aria-selected="<?= $key === $first ? 'true' : 'false' ?>"
            tabindex="<?= $key === $first ? '0' : '-1' ?>">
      <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i><?= $e($label) ?>
      <span class="sd-tab__count"><?= count($items) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php foreach ($tabs as $key => [$label, $icon, $items]): ?>
  <div class="sd-tab-panel" role="tabpanel" id="act-panel-<?= $key ?>"
       <?= count($tabs) > 1 ? 'aria-labelledby="act-tab-' . $key . '"' : 'aria-label="' . $e($label) . '"' ?>
       <?= $key === $first ? '' : 'hidden' ?>>

    <?php if ($key === 'saved'): ?>
    <ul class="sd-saved">
      <?php foreach ($items as $search): ?>
      <li>
        <a class="sd-saved__item" href="/cars-for-sale/?<?= $e($search['query_string']) ?>">
          <span class="sd-saved__icon"><i class="fa-solid fa-bookmark" aria-hidden="true"></i></span>
          <span class="sd-saved__text">
            <span class="sd-saved__label"><?= $e($search['label']) ?></span>
            <span class="sd-saved__meta">Saved <?= $e(date('j M Y', strtotime((string) $search['created_at']))) ?></span>
          </span>
          <i class="fa-solid fa-arrow-right sd-saved__arrow" aria-hidden="true"></i>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="sd-car-grid">
      <?php foreach ($items as $car) echo sdRenderVehicleCard($car); ?>
    </div>
    <?php endif; ?>

  </div>
  <?php endforeach; ?>
</div>
