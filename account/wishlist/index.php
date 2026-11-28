<?php
/**
 * SalesDesk — Saved cars  (WISH-1, Nov 2026)
 * URL: /account/wishlist/   (real directory — served by .htaccess §4)
 *
 * Every link to "Saved cars" (nav heart, account menu, mobile drawer,
 * the "View saved" toast) pointed here, but the page never existed:
 * /account is a reserved word in .htaccess §7, so it 404'd.
 *
 * Anonymous, cookie-based, same as the hearts: lists the cars saved
 * against this browser's sd_vid visitor session (visitor_wishlist).
 * No login needed — the copy says so, so nobody expects it to sync
 * across devices.
 *
 * Attribution: a car saved from a broker's share link stores that
 * tracking code (visitor_wishlist.tracking_code). The card links back
 * to THAT desk with ?ref=, so a lead sent later from the saved list
 * still credits the broker who shared it. Otherwise the usual
 * first-desk rule applies (sdAttachFirstDesks), then the platform route.
 *
 * Cars that sold or were withdrawn stay listed under "No longer
 * available" with a Remove button (wishlist-toggle.php allows removal
 * of inactive cars).
 *
 * Caching: personal → applyCachePolicy('auth') (no-store, private), so
 * the page carries [data-wishlist-fresh] and public.js skips the
 * hydrate round-trip. Never indexed.
 *
 * Styles: public.css (cards, grid, empty state) + account.css.
 * Behaviour: public.js §2 (remove + undo, live counts).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/visitor.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';
require_once '../../includes/listing-helpers.php';
require_once '../../includes/assets.php';
require_once '../../views/partials/vehicle-card.php';

applyCachePolicy('auth');
header('X-Robots-Tag: noindex, nofollow', true);

$pdo     = Database::getInstance();
$visitor = initVisitorSession();

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ── Saved cars, newest save first ─────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            vw.tracking_code       AS saved_ref,
            vw.added_at            AS saved_at,
            c.id, c.slug AS car_slug, c.make, c.model, c.variant, c.year, c.price,
            c.mileage, c.condition_type, c.body_type, c.transmission, c.fuel_type,
            c.image_urls, c.status,
            d.company_name         AS dealer_name,
            d.verification_status  AS dealer_verified,
            d.is_active            AS dealer_active,
            a.city                 AS dealer_city,
            a.province             AS dealer_province,
            ref_sd.slug            AS ref_desk_slug,
            ref_sd.display_name    AS ref_desk_name
        FROM visitor_wishlist vw
        JOIN cars c                  ON c.id = vw.car_id
        JOIN dealers d               ON d.id = c.dealer_id
        LEFT JOIN addresses a        ON a.id = d.address_id
        LEFT JOIN broker_inventory ref_bi
               ON ref_bi.tracking_code = vw.tracking_code
              AND ref_bi.car_id        = c.id
        LEFT JOIN salesdesks ref_sd  ON ref_sd.id = ref_bi.salesdesk_id
                                    AND ref_sd.is_active = 1
        WHERE vw.visitor_session_id = ?
        ORDER BY vw.added_at DESC, vw.id DESC
        LIMIT 200
    ");
    $stmt->execute([(int) $visitor['id']]);
    $rows = $stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('[SalesDesk saved-cars] ' . $ex->getMessage());
    $rows = [];
}

$available   = [];
$unavailable = [];
foreach ($rows as $row) {
    if (($row['status'] ?? '') === 'active' && (int) ($row['dealer_active'] ?? 0) === 1) {
        $available[] = $row;
    } else {
        $unavailable[] = $row;
    }
}

// Desk attribution: the sharing broker's desk first, else first desk to list it.
if ($available) {
    $needFirstDesk = array_values(array_filter($available, static fn ($c) => empty($c['ref_desk_slug'])));
    $firstDesks    = [];
    if ($needFirstDesk) {
        try {
            foreach (sdAttachFirstDesks($pdo, $needFirstDesk) as $c) {
                $firstDesks[(int) $c['id']] = [$c['desk_slug'] ?? null, $c['desk_name'] ?? null];
            }
        } catch (Throwable) { /* platform route fallback */ }
    }
    foreach ($available as &$car) {
        if (!empty($car['ref_desk_slug'])) {
            $car['desk_slug'] = $car['ref_desk_slug'];
            $car['desk_name'] = $car['ref_desk_name'];
            $car['_ref']      = (string) $car['saved_ref'];
        } else {
            [$car['desk_slug'], $car['desk_name']] = $firstDesks[(int) $car['id']] ?? [null, null];
            $car['_ref'] = '';
        }
    }
    unset($car);
}

$total = count($available) + count($unavailable);
$countLabel = static fn (int $n): string => $n === 1 ? '1 car' : $n . ' cars';

// ── Layout variables ──────────────────────────────────────────
$pageTitle            = 'Saved cars | SalesDesk';
$ogDescription        = 'The cars you have saved on SalesDesk.';
$metaRobotsNoindex    = true;
$layoutVariant        = 'wide';
$showBreadcrumb       = true;
$breadcrumbs          = [['Saved cars', null]];
$includeBrowseCss     = false;
$includeHowItWorksCss = false;
$bodyClass            = 'page-saved-cars';
$extraCss             = '<link rel="stylesheet" href="' . sdAsset('/assets/css/account.css') . '">' . "\n";

ob_start();
?>
<div class="sd-container" data-wishlist-fresh>
  <section class="pub-section pub-section--tight acct-saved" aria-labelledby="savedTitle">

    <header class="pub-section__head acct-saved__head">
      <div>
        <p class="pub-eyebrow"><i class="fa-solid fa-heart" aria-hidden="true"></i> My activity</p>
        <h1 class="pub-section__title" id="savedTitle">Saved cars</h1>
        <p class="pub-section__sub">
          <?php if ($total): ?>
          <strong class="acct-saved__count" data-wishlist-total><?= $e($countLabel(count($available) + count($unavailable))) ?></strong>
          saved on this device. Tap the heart on any car to remove it.
          <?php else: ?>
          Tap the heart on any car to keep it here. No account needed.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($total): ?>
      <a href="/cars-for-sale/" class="pub-btn pub-btn-ghost pub-section__link">
        Keep browsing <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
      <?php endif; ?>
    </header>

    <div data-wishlist-grid>
      <?php if ($available): ?>
      <div class="vc-grid acct-saved__grid" data-wishlist-group>
        <?php foreach ($available as $i => $car): ?>
        <div class="acct-saved__item" data-wish-item>
          <?= sdVehicleCard($car, [
              'wishlisted' => true,
              'ref'        => $car['_ref'],
              'eager'      => $i < 4,
              'heading'    => 'h2',
          ]) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($unavailable): ?>
      <div class="acct-saved__gone" data-wishlist-group>
        <h2 class="acct-saved__gone-title">No longer available</h2>
        <p class="acct-saved__gone-sub">These cars have sold or been taken off the market.</p>
        <ul class="acct-gone-list">
          <?php foreach ($unavailable as $car):
              $name  = trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''));
              $thumb = sdCarThumb($car); ?>
          <li class="acct-gone" data-wish-item data-wish-noundo>
            <span class="acct-gone__thumb" aria-hidden="true">
              <?php if ($thumb): ?>
              <img src="<?= $e($thumb) ?>" alt="" width="96" height="72" loading="lazy" decoding="async">
              <?php else: ?>
              <i class="fa-solid fa-car-side"></i>
              <?php endif; ?>
            </span>
            <span class="acct-gone__text">
              <span class="acct-gone__name"><?= $e($name) ?></span>
              <span class="acct-gone__meta">
                <?= $e(trim((string) ($car['variant'] ?? ''))) ?: 'Saved ' . $e(date('j M Y', strtotime((string) $car['saved_at']))) ?>
              </span>
            </span>
            <span class="acct-gone__badge">Unavailable</span>
            <button class="acct-gone__remove" type="button"
                    data-wishlist="<?= (int) $car['id'] ?>" aria-pressed="true"
                    aria-label="Remove <?= $e($name) ?> from saved cars">
              <i class="fa-solid fa-heart" aria-hidden="true"></i>
              <span>Remove</span>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <div class="pub-empty acct-saved__empty" data-wishlist-empty <?= $total ? 'hidden' : '' ?>>
      <span class="pub-empty__icon" aria-hidden="true"><i class="fa-regular fa-heart"></i></span>
      <h2 class="pub-empty__title">No saved cars yet</h2>
      <p class="pub-empty__sub">
        See something you like? Tap the <i class="fa-regular fa-heart" aria-hidden="true"></i>
        on any listing and it’ll wait for you here.
      </p>
      <div class="pub-empty__actions">
        <a href="/cars-for-sale/" class="pub-btn pub-btn-primary">Browse cars</a>
        <a href="/cars-for-sale/?condition=new" class="pub-btn pub-btn-ghost">New cars</a>
      </div>
    </div>

  </section>
</div>
<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-public.php';
