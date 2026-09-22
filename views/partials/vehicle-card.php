<?php
/**
 * SalesDesk — Shared Vehicle Card  (v1)
 * views/partials/vehicle-card.php
 *
 * ONE card markup for every public surface: homepage (latest listings,
 * activity rails), /cars-for-sale/ grid, car detail "more from dealer".
 * Styles: assets/css/public.css §5 (.vc). Behaviour: public.js
 * (wishlist button — [data-wishlist]).
 *
 * Usage:
 *   require_once __DIR__ . '/vehicle-card.php';
 *   echo sdVehicleCard($car, ['wishlisted' => in_array($car['id'], $wishIds)]);
 *
 * Accepted $car keys (all optional except id/make/model/year/price):
 *   id, car_slug|slug, make, model, variant, year, price, mileage,
 *   condition_type, fuel_type, transmission, body_type, image_urls,
 *   dealer_name, dealer_verified|dealer_verification, dealer_city,
 *   dealer_province, desk_slug, desk_name
 *
 * $opts:
 *   bool   wishlisted   heart pre-filled
 *   string ref          ?ref= tracking code to append to desk URLs
 *   string variant      '' | 'compact'
 *   bool   eager        first-row images load eagerly (LCP)
 *   string heading      heading tag, default 'h3'
 */

declare(strict_types=1);

if (!function_exists('sdVehicleCardUrl')) {

    /** Detail URL — desk-attributed when the car is on a desk, platform route otherwise. */
    function sdVehicleCardUrl(array $car, string $ref = ''): string
    {
        $slug = (string) ($car['car_slug'] ?? $car['slug'] ?? '');
        if (!empty($car['desk_slug'])) {
            return '/cars-for-sale/' . rawurlencode((string) $car['desk_slug']) . '/' . rawurlencode($slug) . '/'
                 . ($ref !== '' ? '?ref=' . rawurlencode($ref) : '');
        }
        return '/cars-for-sale/car/' . rawurlencode($slug) . '/';
    }

    /** "R 689 900" with non-breaking thin grouping. */
    function sdRand(float|int|string|null $amount): string
    {
        return 'R' . "\u{00A0}" . number_format((float) $amount, 0, '.', "\u{00A0}");
    }

    function sdFuelIconClass(?string $fuel): string
    {
        $v = strtolower((string) $fuel);
        return match (true) {
            str_contains($v, 'electric') => 'fa-bolt',
            str_contains($v, 'hybrid')   => 'fa-leaf',
            str_contains($v, 'hydrogen') => 'fa-droplet',
            str_contains($v, 'diesel')   => 'fa-oil-can',
            default                      => 'fa-gas-pump',
        };
    }

    function sdProvinceAbbr(?string $province): string
    {
        static $map = [
            'Gauteng' => 'GP', 'Western Cape' => 'WC', 'KwaZulu-Natal' => 'KZN',
            'Eastern Cape' => 'EC', 'Limpopo' => 'LP', 'Mpumalanga' => 'MP',
            'North West' => 'NW', 'Free State' => 'FS', 'Northern Cape' => 'NC',
        ];
        return $map[(string) $province] ?? (string) $province;
    }

    function sdVehicleCard(array $car, array $opts = []): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $images   = json_decode((string) ($car['image_urls'] ?? '[]'), true) ?: [];
        $thumb    = $images[0] ?? null;
        $url      = sdVehicleCardUrl($car, (string) ($opts['ref'] ?? ''));
        $name     = trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''));
        $variant  = trim((string) ($car['variant'] ?? ''));
        $cond     = (string) ($car['condition_type'] ?? '');
        $fuel     = (string) ($car['fuel_type'] ?? '');
        $isEV     = str_contains(strtolower($fuel), 'electric');
        $mileage  = isset($car['mileage']) ? (int) $car['mileage'] : null;
        $price    = (float) ($car['price'] ?? 0);
        $tag      = in_array($opts['heading'] ?? 'h3', ['h2', 'h3', 'h4'], true) ? ($opts['heading'] ?? 'h3') : 'h3';
        $compact  = ($opts['variant'] ?? '') === 'compact';
        $saved    = !empty($opts['wishlisted']);
        $verified = ($car['dealer_verified'] ?? $car['dealer_verification'] ?? '') === 'verified'
                 || ($car['dealer_verified'] ?? null) === true;

        $monthly = null;
        if ($price > 0 && function_exists('estimateMonthlyPayment')) {
            try { $monthly = estimateMonthlyPayment($price); } catch (Throwable) { $monthly = null; }
        }

        $loc = implode(', ', array_filter([
            $car['dealer_city'] ?? null,
            !empty($car['dealer_province']) ? sdProvinceAbbr($car['dealer_province']) : null,
        ]));

        $seller = !empty($car['desk_name']) ? (string) $car['desk_name'] : (string) ($car['dealer_name'] ?? '');

        $specs = [];
        if ($mileage !== null) {
            $specs[] = ['fa-road', $mileage === 0 ? '0 km' : number_format($mileage, 0, '.', ' ') . ' km'];
        }
        if (!empty($car['transmission'])) $specs[] = ['fa-gear', $car['transmission']];
        if ($fuel !== '')                 $specs[] = [sdFuelIconClass($fuel), $fuel];

        ob_start(); ?>
<article class="vc<?= $compact ? ' vc--compact' : '' ?>" data-car-id="<?= (int) ($car['id'] ?? 0) ?>">
  <div class="vc__media">
    <?php if ($thumb): ?>
    <img class="vc__img" src="<?= $e($thumb) ?>" alt="<?= $e($name) ?>"
         width="640" height="480" decoding="async" loading="<?= !empty($opts['eager']) ? 'eager' : 'lazy' ?>">
    <?php else: ?>
    <div class="vc__placeholder" aria-hidden="true"><i class="fa-solid fa-car-side"></i></div>
    <?php endif; ?>

    <div class="vc__badges">
      <?php if ($cond === 'new'): ?><span class="vc__badge vc__badge--new">New</span><?php endif; ?>
      <?php if ($cond === 'demo'): ?><span class="vc__badge vc__badge--demo">Demo</span><?php endif; ?>
      <?php if ($isEV): ?><span class="vc__badge vc__badge--ev"><i class="fa-solid fa-bolt" aria-hidden="true"></i> EV</span><?php endif; ?>
    </div>

    <?php if (!empty($car['id'])): ?>
    <button class="vc__save" type="button"
            data-wishlist="<?= (int) $car['id'] ?>"
            aria-pressed="<?= $saved ? 'true' : 'false' ?>"
            aria-label="<?= $saved ? 'Remove ' . $e($name) . ' from saved cars' : 'Save ' . $e($name) ?>">
      <i class="fa-<?= $saved ? 'solid' : 'regular' ?> fa-heart" aria-hidden="true"></i>
    </button>
    <?php endif; ?>

    <?php if (count($images) > 1 && !$compact): ?>
    <span class="vc__photos" aria-label="<?= count($images) ?> photos"><i class="fa-solid fa-camera" aria-hidden="true"></i> <?= count($images) ?></span>
    <?php endif; ?>
  </div>

  <div class="vc__body">
    <<?= $tag ?> class="vc__title"><a class="vc__link" href="<?= $e($url) ?>"><?= $e($name) ?></a></<?= $tag ?>>
    <?php if ($variant !== ''): ?>
    <p class="vc__variant"><?= $e($variant) ?></p>
    <?php endif; ?>

    <?php if ($specs): ?>
    <ul class="vc__specs">
      <?php foreach ($specs as [$icon, $label]): ?>
      <li><i class="fa-solid <?= $e($icon) ?>" aria-hidden="true"></i><?= $e($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <div class="vc__price-row">
      <div>
        <div class="vc__price"><?= $e(sdRand($price)) ?></div>
        <?php if ($monthly): ?>
        <div class="vc__pm">est. <strong><?= $e(sdRand(round($monthly))) ?></strong> p/m</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$compact && ($loc !== '' || $seller !== '')): ?>
    <div class="vc__foot">
      <span class="vc__loc"><?php if ($loc !== ''): ?><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $e($loc) ?><?php endif; ?></span>
      <?php if ($seller !== ''): ?>
      <span class="vc__seller<?= $verified ? ' vc__seller--verified' : '' ?>" title="<?= $verified ? 'Verified dealer' : '' ?>">
        <i class="fa-solid <?= $verified ? 'fa-circle-check' : (!empty($car['desk_name']) ? 'fa-id-card' : 'fa-shop') ?>" aria-hidden="true"></i>
        <span><?= $e($seller) ?></span>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</article>
<?php
        return (string) ob_get_clean();
    }
}
