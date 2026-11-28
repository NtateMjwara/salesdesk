<?php
/**
 * SalesDesk — Recently viewed  (RV-1, Nov 2026)
 * URL: /account/recently-viewed/   (real directory — served by .htaccess §4)
 *
 * Every car this browser (sd_vid visitor session) opened in the last
 * RECENTLY_VIEWED_DAYS (30) days, newest first, grouped Today /
 * Yesterday / Last 7 days / Earlier.
 *
 * 30-day window: a car drops off 30 days after the visitor last opened
 * it, so after a month away the list is empty and starts over. The
 * car_views rows themselves are kept — dealer and broker view analytics
 * read the same table — only this buyer-facing list is windowed.
 *
 * Sold / withdrawn cars and cars from inactive dealers are left out
 * (same rule as the homepage strip).
 *
 * Attribution: if the visitor arrived at a car through a broker's share
 * link, the card links back to THAT desk with ?ref= (the newest tracking
 * code seen for the car in the window), so a later enquiry still credits
 * the broker. Otherwise first-desk attribution, then the platform route.
 *
 * Caching: personal → applyCachePolicy('auth'); never indexed.
 * Styles: public.css (cards/grid/empty) + account.css §4.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once __DIR__ . '/../../includes/vip_network.php';   // 4a: network-only cars stay off platform listings
require_once '../../includes/visitor.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';
require_once '../../includes/listing-helpers.php';
require_once '../../includes/assets.php';
require_once '../../views/partials/vehicle-card.php';

applyCachePolicy('auth');
header('X-Robots-Tag: noindex, nofollow', true);

$pdo       = Database::getInstance();
$visitor   = initVisitorSession();
$visitorId = (int) $visitor['id'];
$cutoff    = recentlyViewedCutoff();
$maxCars   = 120;

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ── Distinct cars viewed in the window, newest view first ─────
$cars = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            rv.last_viewed,
            c.id, c.slug AS car_slug, c.make, c.model, c.variant, c.year, c.price,
            c.mileage, c.condition_type, c.body_type, c.transmission, c.fuel_type,
            c.image_urls,
            d.company_name         AS dealer_name,
            d.verification_status  AS dealer_verified,
            a.city                 AS dealer_city,
            a.province             AS dealer_province
        FROM (
            SELECT car_id, MAX(viewed_at) AS last_viewed
            FROM car_views
            WHERE visitor_session_id = ?
              AND viewed_at >= ?
            GROUP BY car_id
        ) rv
        JOIN cars c           ON c.id = rv.car_id AND c.status = 'active' AND " . sdPublicCarSql('c') . "
        JOIN dealers d        ON d.id = c.dealer_id AND d.is_active = 1
        LEFT JOIN addresses a ON a.id = d.address_id
        ORDER BY rv.last_viewed DESC
        LIMIT {$maxCars}
    ");
    $stmt->execute([$visitorId, $cutoff]);
    $cars = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('[SalesDesk recently-viewed] ' . $ex->getMessage());
    $cars = [];
}

// ── Attribution: newest broker link the visitor used per car ──
if ($cars) {
    $ids  = array_map(static fn ($c) => (int) $c['id'], $cars);
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $refs = [];
    try {
        $refStmt = $pdo->prepare("
            SELECT cv.car_id, cv.tracking_code, sd.slug AS desk_slug, sd.display_name AS desk_name
            FROM car_views cv
            JOIN broker_inventory bi ON bi.tracking_code = cv.tracking_code AND bi.car_id = cv.car_id
            JOIN salesdesks sd       ON sd.id = bi.salesdesk_id AND sd.is_active = 1
            WHERE cv.visitor_session_id = ?
              AND cv.viewed_at >= ?
              AND cv.tracking_code IS NOT NULL
              AND cv.car_id IN ({$in})
            ORDER BY cv.viewed_at DESC
        ");
        $refStmt->execute(array_merge([$visitorId, $cutoff], $ids));
        foreach ($refStmt->fetchAll() as $r) {
            $refs[(int) $r['car_id']] ??= $r;   // newest wins
        }
    } catch (Throwable) { /* fall through to first-desk attribution */ }

    $needFirst  = array_values(array_filter($cars, static fn ($c) => !isset($refs[(int) $c['id']])));
    $firstDesks = [];
    if ($needFirst) {
        try {
            foreach (sdAttachFirstDesks($pdo, $needFirst) as $c) {
                $firstDesks[(int) $c['id']] = [$c['desk_slug'] ?? null, $c['desk_name'] ?? null];
            }
        } catch (Throwable) { /* platform route */ }
    }

    foreach ($cars as &$car) {
        $id = (int) $car['id'];
        if (isset($refs[$id])) {
            $car['desk_slug'] = $refs[$id]['desk_slug'];
            $car['desk_name'] = $refs[$id]['desk_name'];
            $car['_ref']      = (string) $refs[$id]['tracking_code'];
        } else {
            [$car['desk_slug'], $car['desk_name']] = $firstDesks[$id] ?? [null, null];
            $car['_ref'] = '';
        }
    }
    unset($car);
}

// ── Group by how long ago ─────────────────────────────────────
$today     = new DateTimeImmutable('today');
$groups    = ['today' => [], 'yesterday' => [], 'week' => [], 'month' => []];
foreach ($cars as $car) {
    $seen = new DateTimeImmutable((string) $car['last_viewed']);
    $days = (int) $today->diff($seen->setTime(0, 0))->format('%a');
    $key  = match (true) {
        $seen >= $today => 'today',
        $days <= 1      => 'yesterday',
        $days <= 6      => 'week',
        default         => 'month',
    };
    $groups[$key][] = $car;
}
$groupLabels = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'week'      => 'Last 7 days',
    'month'     => 'Earlier',
];

$wishIds = array_map('intval', getWishlistCarIds($visitorId));
$total   = count($cars);

// ── Layout variables ──────────────────────────────────────────
$pageTitle            = 'Recently viewed | SalesDesk';
$ogDescription        = 'Cars you have looked at on SalesDesk in the last 30 days.';
$metaRobotsNoindex    = true;
$layoutVariant        = 'wide';
$showBreadcrumb       = true;
$breadcrumbs          = [['Recently viewed', null]];
$includeBrowseCss     = false;
$includeHowItWorksCss = false;
$bodyClass            = 'page-recently-viewed';
$extraCss             = '<link rel="stylesheet" href="' . sdAsset('/assets/css/account.css') . '">' . "\n";

ob_start();
$i = 0;
?>
<div class="sd-container" data-wishlist-fresh>
  <section class="pub-section pub-section--tight acct-saved acct-recent" aria-labelledby="recentTitle">

    <header class="pub-section__head acct-saved__head">
      <div>
        <p class="pub-eyebrow"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> My activity</p>
        <h1 class="pub-section__title" id="recentTitle">Recently viewed</h1>
        <p class="pub-section__sub">
          <?php if ($total): ?>
          <strong class="acct-saved__count"><?= $total === 1 ? '1 car' : $total . ' cars' ?></strong>
          you’ve looked at on this device in the last <?= (int) RECENTLY_VIEWED_DAYS ?> days.
          <?php else: ?>
          Cars you open will show up here for <?= (int) RECENTLY_VIEWED_DAYS ?> days.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($total): ?>
      <a href="/cars-for-sale/" class="pub-btn pub-btn-ghost pub-section__link">
        Keep browsing <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
      <?php endif; ?>
    </header>

    <?php if ($total): ?>
      <?php foreach ($groups as $key => $list): if (!$list) continue; ?>
      <div class="acct-recent__group" aria-labelledby="rv-<?= $key ?>">
        <h2 class="acct-recent__day" id="rv-<?= $key ?>">
          <?= $e($groupLabels[$key]) ?>
          <span class="acct-recent__day-count"><?= count($list) ?></span>
        </h2>
        <div class="vc-grid">
          <?php foreach ($list as $car):
              echo sdVehicleCard($car, [
                  'wishlisted' => in_array((int) $car['id'], $wishIds, true),
                  'ref'        => $car['_ref'],
                  'eager'      => $i++ < 4,
                  'heading'    => 'h3',
              ]);
          endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <p class="acct-recent__note">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        Cars drop off this list <?= (int) RECENTLY_VIEWED_DAYS ?> days after you last viewed them.
        <?php if ($total >= $maxCars): ?>Showing your <?= $maxCars ?> most recent.<?php endif; ?>
      </p>

    <?php else: ?>
    <div class="pub-empty acct-saved__empty">
      <span class="pub-empty__icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
      <h2 class="pub-empty__title">Nothing viewed recently</h2>
      <p class="pub-empty__sub">
        Open any car and it’ll be kept here for <?= (int) RECENTLY_VIEWED_DAYS ?> days, so you can find it again quickly.
      </p>
      <div class="pub-empty__actions">
        <a href="/cars-for-sale/" class="pub-btn pub-btn-primary">Browse cars</a>
        <a href="/account/wishlist/" class="pub-btn pub-btn-ghost">Saved cars</a>
      </div>
    </div>
    <?php endif; ?>

  </section>
</div>
<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-public.php';
