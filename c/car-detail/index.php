<?php
/**
 * SalesDesk — LEGACY route shim: c/car-detail/  →  /cars-for-sale/…   (301)
 *
 * The car detail page moved to cars-for-sale/car-detail/index.php
 * (ROUTE-1). This file used to be a full, stale copy of the old detail
 * page (it still built /c/ related-car links and had no migration-0012
 * platform route). It is now a permanent redirect only.
 *
 * How requests reach this file:
 *   Pretty URLs /c/{desk}/{car}/ are already 301'd by .htaccess's LEGACY
 *   block (not a real directory, so that rule runs). This shim covers the
 *   direct form, which bypasses that rule because the file exists:
 *     /c/car-detail/?desk_slug={desk}&car_slug={car}[&ref=…]
 *
 * Behaviour:
 *   desk_slug + car_slug  → /cars-for-sale/{desk}/{car}/
 *   car_slug only         → /cars-for-sale/car/{car}/   (platform-attributed)
 *   missing/invalid slugs → /cars-for-sale/
 *   Every other query param (e.g. ?ref= tracking code) is preserved so
 *   broker attribution survives the redirect.
 *
 * Slug validation mirrors .htaccess exactly:
 *   desk-slug: [a-z0-9][a-z0-9-]{1,59}   car-slug: [a-z0-9][a-z0-9-]{1,99}
 *
 * Deliberately loads nothing from includes/ — no DB, session or visitor
 * tracking for a request that only exists to redirect.
 */

declare(strict_types=1);

$deskSlug = strtolower(trim((string) ($_GET['desk_slug'] ?? '')));
$carSlug  = strtolower(trim((string) ($_GET['car_slug']  ?? '')));

$deskValid = (bool) preg_match('/^[a-z0-9][a-z0-9\-]{1,59}$/', $deskSlug);
$carValid  = (bool) preg_match('/^[a-z0-9][a-z0-9\-]{1,99}$/', $carSlug);

if ($deskValid && $carValid) {
    $target = '/cars-for-sale/' . $deskSlug . '/' . $carSlug . '/';
} elseif ($carValid) {
    $target = '/cars-for-sale/car/' . $carSlug . '/';
} else {
    $target = '/cars-for-sale/';
}

// Carry through everything except the routing params (?ref= etc.).
$rest = $_GET;
unset($rest['desk_slug'], $rest['car_slug']);

$query = http_build_query($rest);
// body_type[0]=SUV → body_type[]=SUV, matching the site's own filter links.
$query = preg_replace('/%5B\d+%5D=/', '%5B%5D=', $query);
if ($query !== '') {
    $target .= '?' . $query;
}

header('Cache-Control: public, max-age=86400');
header('Location: ' . $target, true, 301);
exit;
