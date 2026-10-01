<?php
/**
 * SalesDesk — Listing helpers  (includes/listing-helpers.php)
 * T1 owns this file.
 *
 * Shared by every page that shows vehicle cards outside the browse page:
 * the homepage (featured cars) and api/visitor/activity.php (recently
 * viewed / wishlist). Card markup itself lives in
 * views/partials/vehicle-card.php.
 *
 * PERF: sdAttachFirstDesks() replaces the correlated
 *   LEFT JOIN (SELECT … WHERE bi2.added_at = (SELECT MIN(bi3.added_at) …))
 * subquery the homepage ran three times per request. That derived table
 * scanned ALL of broker_inventory on every run. Now: one indexed
 * `WHERE car_id IN (…)` lookup for just the cars on screen, and the
 * earliest-listed desk is picked in PHP. Same attribution rule as
 * cars-for-sale/index.php ("first desk to list the car wins").
 */

declare(strict_types=1);

if (!function_exists('sdAttachFirstDesks')) {

    /**
     * Adds desk_slug / desk_name (null when no broker has the car) to each row.
     *
     * @param array<int, array> $cars  rows with an 'id' key
     * @return array<int, array>
     */
    function sdAttachFirstDesks(PDO $pdo, array $cars): array
    {
        $ids = array_values(array_unique(array_map(static fn ($c) => (int) $c['id'], $cars)));
        if (!$ids) {
            return $cars;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT bi.car_id, sd.slug AS desk_slug, sd.display_name AS desk_name
            FROM broker_inventory bi
            JOIN salesdesks sd ON sd.id = bi.salesdesk_id
            WHERE bi.car_id IN ({$placeholders})
            ORDER BY bi.car_id ASC, bi.added_at ASC, bi.id ASC
        ");
        $stmt->execute($ids);

        $first = [];
        foreach ($stmt->fetchAll() as $row) {
            $carId = (int) $row['car_id'];
            if (!isset($first[$carId])) {
                $first[$carId] = $row;   // earliest listing wins
            }
        }

        foreach ($cars as &$car) {
            $desk = $first[(int) $car['id']] ?? null;
            $car['desk_slug'] = $desk['desk_slug'] ?? null;
            $car['desk_name'] = $desk['desk_name'] ?? null;
        }
        unset($car);

        return $cars;
    }

    /**
     * Canonical detail URL (already HTML-escaped for use in href="").
     *   desk-attributed → /cars-for-sale/{desk}/{car}/
     *   no desk yet     → /cars-for-sale/car/{car}/
     */
    function sdCarUrl(array $car): string
    {
        $carSlug = rawurlencode((string) ($car['car_slug'] ?? ''));
        return !empty($car['desk_slug'])
            ? '/cars-for-sale/' . rawurlencode((string) $car['desk_slug']) . '/' . $carSlug . '/'
            : '/cars-for-sale/car/' . $carSlug . '/';
    }

    /** First image URL or null. */
    function sdCarThumb(array $car): ?string
    {
        $imgs = json_decode((string) ($car['image_urls'] ?? '[]'), true);
        return (is_array($imgs) && !empty($imgs[0]) && is_string($imgs[0])) ? $imgs[0] : null;
    }

    /**
     * Font Awesome icon for a fuel type. Substring matching, because the
     * upload wizard stores values like 'Plug-in Hybrid (PHEV)' and
     * 'LPG (Autogas)'.
     */
    function sdFuelIcon(string $fuel): string
    {
        $v = strtolower($fuel);
        return match (true) {
            str_contains($v, 'electric') => 'fa-bolt',
            str_contains($v, 'hybrid')   => 'fa-leaf',
            str_contains($v, 'hydrogen') => 'fa-droplet',
            str_contains($v, 'diesel')   => 'fa-oil-can',
            str_contains($v, 'lpg'), str_contains($v, 'cng'),
            str_contains($v, 'autogas'), str_contains($v, 'natural gas') => 'fa-fire-flame-simple',
            default                      => 'fa-gas-pump',
        };
    }

    /** @return array<string, string> province name → abbreviation */
    function sdProvinceAbbr(): array
    {
        return [
            'Gauteng'       => 'GP',  'Western Cape'  => 'WC',  'KwaZulu-Natal' => 'KZN',
            'Eastern Cape'  => 'EC',  'Limpopo'       => 'LP',  'Mpumalanga'    => 'MP',
            'North West'    => 'NW',  'Free State'    => 'FS',  'Northern Cape' => 'NC',
        ];
    }

    /** "R 349 900" with non-breaking spaces. */
    function sdPrice(float|int|string|null $price): string
    {
        return 'R&nbsp;' . number_format((float) $price, 0, '.', '&nbsp;');
    }
}
