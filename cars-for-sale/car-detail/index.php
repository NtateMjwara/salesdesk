<?php
/**
 * SalesDesk — Public Car Detail Page  (v3)
 * Route: /cars-for-sale/{desk-slug}/{car-slug}/
 *        (via .htaccess → cars-for-sale/car-detail/index.php?desk_slug=…&car_slug=…)
 *
 * Attribution model v2 (unchanged):
 *   The desk slug is embedded in the URL path — every detail page is
 *   definitionally tied to a specific SalesDesk. No ?ref= is required
 *   for attribution, but it is still accepted and stored when present
 *   (e.g. from an externally shared broker link).
 *
 *   Resolution order on lead submit:
 *     1. ?ref={tracking_code} present + valid for this car+desk → use it
 *     2. No ref (or ref doesn't match desk) → derive salesdesk_id from
 *        desk_slug, use that desk's tracking_code for this car
 *     3. Desk doesn't have the car → 404 (the car is not on that desk)
 *
 * v4 (public UX/UI overhaul):
 *   UX-A  Layout rebuilt: title + key facts first, photo mosaic (desktop)
 *         / swipe carousel (mobile) opening a full-screen lightbox with
 *         keyboard + swipe (replaces window.open() on the raw image).
 *   UX-B  One scrolling page with a sticky section nav (Overview, Specs,
 *         Features, History, Finance, Seller) instead of hidden tabs;
 *         key-spec tiles and highlight badges up top.
 *   UX-C  Sticky price + enquiry card on desktop; on phones a sticky
 *         bottom bar (price, monthly, Enquire, WhatsApp) — previously the
 *         form sat below every other section, several screens down.
 *   UX-D  Enquiry form: 2-up name/phone, intent as tappable chips,
 *         inline validation, accessible success state.
 *   UX-E  Finance estimator gains term, balloon and rate controls.
 *   UX-F  "Broker earns R…" is shown only to signed-in trade users
 *         (broker / sales exec / dealer / admin). Buyers no longer see
 *         the commission figure next to the price.
 *   UX-G  ALL inline <style>, <script>, style="" and onclick="" removed.
 *         Page CSS: assets/css/car-detail.css. Behaviour: public.js.
 *   DATA-3 "More from this dealer" no longer drops cars that aren't on a
 *         desk (links them via the platform route instead).
 *
 * CHANGES IN v3:
 *   DATA-1  Car SELECT expanded to pull every buyer-relevant column the
 *           schema already has (variant, VIN, engine/drivetrain specs,
 *           ownership/warranty/service data) — previously only a small
 *           subset was surfaced despite being in `cars`.
 *   DATA-2  New query joins car_feature_links → car_features so the
 *           listing shows the dealer's actual selected features,
 *           grouped by category, instead of nothing at all.
 *   UX-1    "Full specification" is now one tab inside a
 *           Specifications / Features / History & Warranty tab group
 *           (previously a single long spec table with no features and
 *           no ownership/warranty disclosure anywhere on the page).
 *   UX-2    Vehicle history & warranty tab surfaces previous owners,
 *           service history, service book, warranty + service plan
 *           expiry, and — legally significant in SA — an unmissable
 *           write-off disclosure banner when is_written_off = 1.
 *   UX-3    VIN is shown masked (last 6 characters only) for privacy;
 *           full VIN stays available to the dealer/broker off-listing.
 *   SEO-1   Added schema.org Vehicle JSON-LD so search engines get
 *           structured price/mileage/condition data.
 *   Everything from v2 (attribution, related cars, enquiry form, desk
 *   card, dealer card, finance estimator) is unchanged in behaviour.
 *
 * Security: no auth required. SQL fully parameterised.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/visitor.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Resolve slugs from URL ────────────────────────────────────
// MIGRATION 0012: desk_slug is now OPTIONAL. Route map:
//   /cars-for-sale/{desk-slug}/{car-slug}/  → desk_slug + car_slug both set
//   /cars-for-sale/car/{car-slug}/          → car_slug only (platform-attributed, no broker)
$deskSlug      = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['desk_slug'] ?? '')));
$carSlug       = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['car_slug']  ?? '')));
$isPlatformCar = ($deskSlug === '');

if (!$carSlug) {
    http_response_code(404);
    exit('Not found.');
}

if (!$isPlatformCar) {
    // ── v2 behaviour, unchanged: car detail only accessible through
    //    a desk that has the car in its inventory. ─────────────────
    $deskCarStmt = $pdo->prepare("
        SELECT
            bi.id               AS bi_id,
            bi.tracking_code    AS desk_tracking_code,
            bi.views            AS desk_views,
            bi.added_at,
            sd.id               AS salesdesk_id,
            sd.slug             AS desk_slug,
            sd.display_name     AS desk_name,
            sd.tagline          AS desk_tagline,
            sd.logo_url         AS desk_logo,
            sd.primary_colour   AS desk_colour,
            sd.is_active        AS desk_active,
            u.id                AS broker_user_id,
            p.first_name        AS broker_first,
            p.last_name         AS broker_last,
            p.avatar_url        AS broker_avatar,
            p.phone             AS broker_phone
        FROM salesdesks sd
        JOIN users u              ON u.id  = sd.user_id
        LEFT JOIN profiles p      ON p.user_id = u.id
        JOIN broker_inventory bi  ON bi.salesdesk_id = sd.id
        JOIN cars c               ON c.id = bi.car_id
        WHERE sd.slug   = ?
          AND c.slug    = ?
          AND sd.is_active = 1
        LIMIT 1
    ");
    $deskCarStmt->execute([$deskSlug, $carSlug]);
    $deskRow = $deskCarStmt->fetch();

    if (!$deskRow) {
        http_response_code(404);
        exit('This listing was not found or is no longer available on this desk.');
    }
} else {
    // ── Platform-attributed branch (migration 0012, v2) ─────────
    // No broker desk required — just an active dealer car. $deskRow
    // is still populated so downstream reads below don't need a
    // second isset() check on every field, but broker/desk identity
    // fields are real null, not a fictional "SalesDesk desk" — there
    // genuinely is no broker on this listing.
    $platformCarStmt = $pdo->prepare("
        SELECT c.id
        FROM cars c
        JOIN dealers d ON d.id = c.dealer_id
        WHERE c.slug = ? AND d.is_active = 1
        LIMIT 1
    ");
    $platformCarStmt->execute([$carSlug]);
    if (!$platformCarStmt->fetchColumn()) {
        http_response_code(404);
        exit('This listing was not found or has been removed.');
    }

    $deskRow = [
        'bi_id'               => null,
        'desk_tracking_code'  => null,
        'desk_views'          => 0,
        'added_at'            => null,
        'salesdesk_id'        => null,
        'desk_slug'           => null,
        'desk_name'           => null,
        'desk_tagline'        => null,
        'desk_logo'           => null,
        'desk_colour'         => null,
        'desk_active'         => null,
        'broker_user_id'      => null,
        'broker_first'        => null,
        'broker_last'         => null,
        'broker_avatar'       => null,
        'broker_phone'        => null,
    ];
}

// ── Load car ──────────────────────────────────────────────────
// DATA-1: expanded to surface every buyer-relevant column already
// present in the `cars` table (see db/schema_consolidated.sql).
$carStmt = $pdo->prepare("
    SELECT
        c.id, c.uuid, c.slug, c.make, c.model, c.variant, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour, c.interior_colour,
        c.transmission, c.fuel_type, c.drivetrain, c.description,
        c.vin, c.mm_code,
        c.engine_capacity_cc, c.cylinders, c.induction, c.power_kw, c.torque_nm,
        c.gears, c.fuel_consumption_l100km, c.co2_emissions_gkm,
        c.previous_owners, c.service_history, c.has_service_book, c.is_written_off,
        c.doors, c.seats,
        c.warranty_type, c.warranty_expiry_date, c.warranty_expiry_km,
        c.service_plan_expiry_date, c.service_plan_expiry_km,
        c.vat_inclusive,
        c.commission_type, c.commission_value,
        c.image_urls, c.status, c.created_at,
        d.id                    AS dealer_id,
        d.company_name          AS dealer_name,
        d.slug                  AS dealer_slug,
        d.verification_status   AS dealer_verification,
        d.brand_focus,
        a.city                  AS dealer_city,
        a.province               AS dealer_province,
        a.suburb                AS dealer_suburb,
        u_d.email               AS dealer_email
    FROM cars c
    JOIN dealers d  ON d.id  = c.dealer_id
    JOIN users u_d  ON u_d.id = d.user_id
    LEFT JOIN addresses a ON a.id = d.address_id
    WHERE c.slug = ?
    LIMIT 1
");
$carStmt->execute([$carSlug]);
$car = $carStmt->fetch();

if (!$car) {
    http_response_code(404);
    exit('This listing was not found or has been removed.');
}

// ── Visitor session ────────────────────────────────────────────
$visitor = initVisitorSession();

// ── Resolve active tracking code ──────────────────────────────
// Priority:
//   1. ?ref= present + valid for this exact car+desk combination
//   2. ?ref= present but belongs to a different desk → still store it
//      (the buyer may have followed a different broker's external link),
//      but canonical desk attribution comes from the URL path
//   3. No ?ref= → use the desk's own tracking code for this car
$refParam = trim($_GET['ref'] ?? '');
if ($refParam && !preg_match('/^[a-f0-9]{32}$/i', $refParam)) $refParam = '';

// null on the platform branch — there's no broker_inventory row to
// carry a tracking code. ?ref= from an externally shared broker link
// (handled just below) can still apply even on a platform-attributed
// page; it just has nothing desk-specific to fall back to when absent.
$activeTrackingCode = $deskRow['desk_tracking_code'];

if ($refParam) {
    // Check ref belongs to this car (any desk)
    $refStmt = $pdo->prepare("
        SELECT bi.id, bi.tracking_code, sd.slug AS ref_desk_slug
        FROM broker_inventory bi
        JOIN salesdesks sd ON sd.id = bi.salesdesk_id
        WHERE bi.tracking_code = ? AND bi.car_id = ?
        LIMIT 1
    ");
    $refStmt->execute([$refParam, $car['id']]);
    $refRow = $refStmt->fetch();

    if ($refRow) {
        // Valid ref for this car — use it regardless of which desk it belongs to.
        // This honours the sharing broker's attribution even if the buyer
        // arrived at a different desk's URL.
        $activeTrackingCode = $refRow['tracking_code'];

        // Persist to visitor session for cross-page attribution
        if ($refParam !== ($visitor['last_tracking_code'] ?? '')) {
            $pdo->prepare("
                UPDATE visitor_sessions SET last_tracking_code = ? WHERE id = ?
            ")->execute([$refParam, $visitor['id']]);
        }
    }
    // If ref was invalid/not found for this car, we silently fall back
    // to the desk tracking code — no error shown to the buyer.
}

// ── Record view ────────────────────────────────────────────────
recordCarView($visitor, (int)$car['id'], $activeTrackingCode);

// ── Wishlist state ─────────────────────────────────────────────
$isWishlisted = isCarWishlisted($visitor['id'], (int)$car['id']);

// ── Car features (DATA-2) ───────────────────────────────────────
// Pulls the dealer's actual selected features, grouped by category,
// from car_feature_links → car_features. Previously nothing from
// these two tables was ever shown to buyers.
$featStmt = $pdo->prepare("
    SELECT cf.category, cf.name, cf.slug, cf.is_popular
    FROM car_feature_links cfl
    JOIN car_features cf ON cf.id = cfl.feature_id
    WHERE cfl.car_id = ?
    ORDER BY cf.category ASC, cf.sort_order ASC, cf.name ASC
");
$featStmt->execute([(int)$car['id']]);
$featureRows = $featStmt->fetchAll();

$featuresByCategory = [];
foreach ($featureRows as $f) {
    $featuresByCategory[$f['category']][] = $f;
}
$totalFeatureCount = count($featureRows);

// Icons for feature category headers (falls back to a generic tag icon).
$categoryIcons = [
    'Safety'                        => 'fa-shield-halved',
    'Security'                      => 'fa-lock',
    'Comfort & Convenience'         => 'fa-couch',
    'Seating'                       => 'fa-chair',
    'Infotainment & Connectivity'   => 'fa-satellite-dish',
    'Exterior'                      => 'fa-car-side',
    'Performance & Driving'         => 'fa-gauge-high',
    'Off-Road & Utility'            => 'fa-mountain',
    'Electric & Hybrid'             => 'fa-bolt',
    'Lighting'                      => 'fa-lightbulb',
    'Driver Assistance (ADAS)'      => 'fa-robot',
    'Commercial Vehicle'            => 'fa-truck',
    'Luxury'                        => 'fa-gem',
    'Practical'                     => 'fa-box',
];

// ── Related cars (same dealer, same status, exclude current) ──
$relStmt = $pdo->prepare("
    SELECT
        c2.id, c2.slug AS car_slug, c2.make, c2.model, c2.variant, c2.year,
        c2.price, c2.mileage, c2.image_urls, c2.condition_type,
        c2.fuel_type, c2.transmission,
        -- For related cards, use the first-listed desk for each related car
        first_rel.desk_slug AS rel_desk_slug,
        first_rel.tracking_code AS rel_tracking_code
    FROM cars c2
    LEFT JOIN (
        SELECT bi3.car_id, sd3.slug AS desk_slug, bi3.tracking_code
        FROM broker_inventory bi3
        JOIN salesdesks sd3 ON sd3.id = bi3.salesdesk_id
        WHERE bi3.added_at = (
            SELECT MIN(bi4.added_at) FROM broker_inventory bi4
            WHERE bi4.car_id = bi3.car_id
        )
        GROUP BY bi3.car_id
    ) first_rel ON first_rel.car_id = c2.id
    WHERE c2.dealer_id = ?
      AND c2.status    = 'active'
      AND c2.id       != ?
    ORDER BY c2.created_at DESC
    LIMIT 4
");
$relStmt->execute([(int)$car['dealer_id'], (int)$car['id']]);
$relatedCars = $relStmt->fetchAll();

// ── Dealer stats ───────────────────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN c3.status='active' THEN c3.id END) AS active_listings,
        COUNT(DISTINCT l.id)                                          AS total_leads
    FROM dealers d2
    LEFT JOIN cars c3 ON c3.dealer_id = d2.id
    LEFT JOIN leads l ON l.dealer_id  = d2.id
    WHERE d2.id = ?
");
$statsStmt->execute([(int)$car['dealer_id']]);
$dealerStats = $statsStmt->fetch();

// ── Desk stats ─────────────────────────────────────────────────
if (!$isPlatformCar) {
    $deskStatsStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT bi.id)                                       AS cars_on_desk,
            COUNT(DISTINCT CASE WHEN c4.status='active' THEN bi.id END) AS active_cars,
            COUNT(DISTINCT l2.id)                                       AS desk_leads,
            COUNT(DISTINCT CASE WHEN l2.status='closed' THEN l2.id END) AS desk_closed
        FROM salesdesks sd4
        LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd4.id
        LEFT JOIN cars c4 ON c4.id = bi.car_id
        LEFT JOIN leads l2 ON l2.salesdesk_id = sd4.id
        WHERE sd4.id = ?
    ");
    $deskStatsStmt->execute([(int)$deskRow['salesdesk_id']]);
    $deskStats = $deskStatsStmt->fetch();
} else {
    // No desk on the platform branch — the SalesDesk info card is
    // hidden entirely for platform cars in the markup below.
    $deskStats = ['active_cars' => 0, 'desk_leads' => 0, 'desk_closed' => 0];
}

// ── Org membership (for desk badge) ───────────────────────────
// broker_user_id is null on the platform branch — skip the lookup
// rather than querying organization_members for user_id = 0.
$org = null;
if (!$isPlatformCar) {
    $orgStmt = $pdo->prepare("
        SELECT o.name, o.slug, o.verification_status
        FROM organization_members om
        JOIN organizations o ON o.id = om.organization_id
        WHERE om.user_id = ? AND o.is_active = 1
        LIMIT 1
    ");
    $orgStmt->execute([(int)$deskRow['broker_user_id']]);
    $org = $orgStmt->fetch();
}

// ── Page meta computations ────────────────────────────────────
$images    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
$coverImg  = $images[0] ?? '';

$carNameOnly = "{$car['year']} {$car['make']} {$car['model']}";
$carTitle    = $carNameOnly . ($car['variant'] ? " {$car['variant']}" : '');
$priceDisp   = 'R ' . number_format((float)$car['price'], 0, '.', ' ');
$monthlyEst  = estimateMonthlyPayment((float)$car['price']);
$monthlyDisp = 'R ' . number_format($monthlyEst, 0, '.', ' ') . ' /mo';

$dealerLoc = implode(', ', array_filter([
    $car['dealer_suburb'], $car['dealer_city'], $car['dealer_province'],
]));

$commRand = $car['commission_type'] === 'fixed'
    ? (float)$car['commission_value']
    : round((float)$car['price'] * (float)$car['commission_value'] / 100, 2);
$commRandDisp = 'R ' . number_format($commRand, 0, '.', ' ');

// Both already degrade safely when broker_first/last/desk_name are
// null (the ?: 'SD' / ?: $deskRow['desk_name'] fallbacks existed
// already) — but $deskRow['desk_name'] is ALSO null on the platform
// branch now, so brokerDisplayName would end up '' for a platform
// car. Guarded explicitly so the "Listed by / enquiry broker" chip
// (hidden entirely below via $isPlatformCar) never has to render an
// empty string if some other spot in the template reads it directly.
$brokerInitials = strtoupper(
    substr($deskRow['broker_first'] ?? '', 0, 1) .
    substr($deskRow['broker_last']  ?? '', 0, 1)
) ?: 'SD';
$brokerDisplayName = $isPlatformCar
    ? 'SalesDesk'
    : (trim(($deskRow['broker_first'] ?? '') . ' ' . ($deskRow['broker_last'] ?? '')) ?: $deskRow['desk_name']);

$conditionLabel = match($car['condition_type']) {
    'new'   => 'New',
    'demo'  => 'Demo',
    default => 'Used',
};

// Canonical URL always uses desk slug in path (no ?ref=)
$siteUrl      = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$canonicalUrl = $isPlatformCar
    ? "{$siteUrl}/cars-for-sale/car/{$carSlug}/"
    : "{$siteUrl}/cars-for-sale/{$deskSlug}/{$carSlug}/";

// Share URL: canonical + tracking code if available
$shareUrl   = $activeTrackingCode
    ? "{$canonicalUrl}?ref={$activeTrackingCode}"
    : $canonicalUrl;
$shareTitle = "{$carTitle} — {$priceDisp}";

// ── SEO / OG ──────────────────────────────────────────────────
$pageTitle     = "{$carTitle} — {$priceDisp} | SalesDesk";
$ogTitle       = $shareTitle;
$ogDescription = "Listed by {$brokerDisplayName}"
    . ($dealerLoc ? " · {$dealerLoc}" : '')
    . ". {$conditionLabel}"
    . ($car['mileage'] ? ', ' . number_format((int)$car['mileage']) . ' km.' : '.')
    . ' Enquire on SalesDesk.';
$ogImage = $coverImg;

$showBreadcrumb = true;
$breadcrumbs    = [
    ['Cars for sale', '/cars-for-sale/'],
    [$car['make'],   '/cars-for-sale/?make=' . urlencode($car['make'])],
    ["{$carTitle}",  null],
];

$isAvailable = ($car['status'] === 'active');

// ── VIN — mask for public display; only the last 6 characters shown ──
// UX-3: full VIN is still stored/available to the dealer & broker, but a
// public listing has no reason to expose the full number.
$vinDisplay = null;
if (!empty($car['vin'])) {
    $vinLen     = strlen($car['vin']);
    $vinDisplay = $vinLen > 6
        ? str_repeat('•', $vinLen - 6) . substr($car['vin'], -6)
        : $car['vin'];
}

// ── Warranty / service-plan expiry formatting ─────────────────
function sd_format_expiry(?string $date, ?int $km): string
{
    $parts = [];
    if ($date) $parts[] = date('M Y', strtotime($date));
    if ($km)   $parts[] = number_format($km) . ' km';
    if (!$parts) return '—';
    return implode(' or ', $parts) . (count($parts) > 1 ? ' — whichever comes first' : '');
}
$warrantyDisplay    = sd_format_expiry($car['warranty_expiry_date'] ?? null, $car['warranty_expiry_km'] ?? null);
$servicePlanDisplay = sd_format_expiry($car['service_plan_expiry_date'] ?? null, $car['service_plan_expiry_km'] ?? null);

$warrantyTypeLabel = match($car['warranty_type'] ?? 'none') {
    'manufacturer' => "Manufacturer warranty",
    'extended'     => "Extended warranty",
    'dealer'       => "Dealer warranty",
    default        => "No warranty",
};

$serviceHistoryLabel = match($car['service_history'] ?? 'unknown') {
    'full'    => 'Full service history',
    'partial' => 'Partial service history',
    'none'    => 'No service history',
    default   => 'Service history unknown',
};

// ── CSRF token ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[CSRF_TOKEN_NAME];

// ── Structured data (SEO-1, extended) ──────────────────────────
// ENHANCEMENT to the existing SEO-1 implementation: added `image`
// (was missing entirely — Google explicitly wants this for vehicle/
// product-style rich results), `brand` (in addition to the existing
// `manufacturer`, both are valid schema.org properties and different
// crawlers weight them differently), `bodyType`, `driveWheelConfiguration`,
// `itemCondition`, and a `seller.address` on the offer using the same
// dealer location fields $dealerLoc above already assembles. VIN is
// deliberately NOT included — it's masked for public display
// ($vinDisplay, computed below) and the masked/bulleted form would be
// meaningless (and slightly odd-looking) inside structured data.
$conditionSchemaMap = [
    'new'  => 'https://schema.org/NewCondition',
    'demo' => 'https://schema.org/RefurbishedCondition',
    'used' => 'https://schema.org/UsedCondition',
];

$vehicleJsonLd = [
    '@context'          => 'https://schema.org',
    '@type'             => 'Vehicle',
    'name'              => $carTitle,
    'image'             => !empty($images) ? array_values($images) : null,
    'vehicleModelDate'  => (string)$car['year'],
    'manufacturer'      => $car['make'],
    'brand'             => ['@type' => 'Brand', 'name' => $car['make']],
    'model'             => $car['model'],
    'bodyType'          => $car['body_type'] ?: null,
    'driveWheelConfiguration' => $car['drivetrain'] ?: null,
    'itemCondition'     => $conditionSchemaMap[$car['condition_type']] ?? null,
    'mileageFromOdometer' => $car['mileage'] ? [
        '@type' => 'QuantitativeValue',
        'value' => (int)$car['mileage'],
        'unitCode' => 'KMT',
    ] : null,
    'vehicleTransmission' => $car['transmission'] ?: null,
    'fuelType'            => $car['fuel_type'] ?: null,
    'color'               => $car['colour'] ?: null,
    'offers' => [
        '@type'         => 'Offer',
        'price'         => (float)$car['price'],
        'priceCurrency' => 'ZAR',
        'availability'  => $isAvailable ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
        'url'           => $canonicalUrl,
        'seller'        => [
            '@type' => 'AutoDealer',
            'name'  => $car['dealer_name'],
        ],
        'availableAtOrFrom' => $dealerLoc ? [
            '@type'   => 'Place',
            'address' => [
                '@type'           => 'PostalAddress',
                'addressLocality' => $car['dealer_city'] ?? '',
                'addressRegion'   => $car['dealer_province'] ?? '',
                'addressCountry'  => 'ZA',
            ],
        ] : null,
    ],
];
$vehicleJsonLd['offers'] = array_filter($vehicleJsonLd['offers'], fn($v) => $v !== null);
$vehicleJsonLd = array_filter($vehicleJsonLd, fn($v) => $v !== null);

// ── v4 view-model ──────────────────────────────────────────────
require_once __DIR__ . '/../../views/partials/vehicle-card.php';

$isTradeViewer = !empty($_SESSION['user_id'])
    && in_array($_SESSION['user_role'] ?? '', ['broker', 'sales_exec', 'dealer', 'admin'], true);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$mileageDisp = $car['mileage'] !== null && $car['mileage'] !== ''
    ? number_format((int) $car['mileage'], 0, '.', ' ') . ' km' : null;

$brokerPhoneDigits = !empty($deskRow['broker_phone']) ? preg_replace('/\D/', '', (string) $deskRow['broker_phone']) : '';
$waNumber = $brokerPhoneDigits !== ''
    ? (str_starts_with($brokerPhoneDigits, '27') ? $brokerPhoneDigits : '27' . ltrim($brokerPhoneDigits, '0'))
    : '';
$waMsg  = "Hi, I'm interested in the {$carTitle} listed on SalesDesk for {$priceDisp}. {$shareUrl}";
$waLink = $waNumber !== '' ? 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($waMsg) : '';

$sellerName = $isPlatformCar ? $car['dealer_name'] : $deskRow['desk_name'];

// Key spec tiles (first 8 that have data)
$keyTiles = array_values(array_filter([
    $mileageDisp               ? ['fa-road',              'Mileage',      $mileageDisp] : null,
    ['fa-calendar',            'Year',         (string) $car['year']],
    $car['transmission']       ? ['fa-gear',              'Transmission', $car['transmission']] : null,
    $car['fuel_type']          ? [sdFuelIconClass($car['fuel_type']), 'Fuel', $car['fuel_type']] : null,
    $car['body_type']          ? ['fa-car-side',          'Body',         $car['body_type']] : null,
    $car['drivetrain']         ? ['fa-circle-nodes',      'Drivetrain',   $car['drivetrain']] : null,
    $car['engine_capacity_cc'] ? ['fa-gauge-high',        'Engine',       number_format((int) $car['engine_capacity_cc'] / 1000, 1) . ' L'] : null,
    $car['power_kw']           ? ['fa-bolt',              'Power',        (int) $car['power_kw'] . ' kW'] : null,
    $car['fuel_consumption_l100km'] ? ['fa-gas-pump',     'Consumption',  $car['fuel_consumption_l100km'] . ' L/100km'] : null,
    $car['seats']              ? ['fa-users',             'Seats',        (int) $car['seats']] : null,
    $car['colour']             ? ['fa-palette',           'Colour',       $car['colour']] : null,
]));
$keyTiles = array_slice($keyTiles, 0, 8);

// Highlights
$highlights = array_values(array_filter([
    $car['dealer_verification'] === 'verified' ? ['fa-circle-check', 'Verified dealer', 'good'] : null,
    ($car['service_history'] ?? '') === 'full' ? ['fa-file-circle-check', 'Full service history', 'good'] : null,
    !empty($car['has_service_book'])           ? ['fa-book', 'Service book', 'good'] : null,
    ($car['warranty_type'] ?? 'none') !== 'none' ? ['fa-shield-halved', $warrantyTypeLabel . ($car['warranty_expiry_date'] ? ' to ' . date('M Y', strtotime($car['warranty_expiry_date'])) : ''), 'good'] : null,
    $servicePlanDisplay !== '—'                ? ['fa-screwdriver-wrench', 'Service plan', 'good'] : null,
    $car['previous_owners'] !== null && (int) $car['previous_owners'] <= 1 && $car['condition_type'] !== 'new'
                                               ? ['fa-user', (int) $car['previous_owners'] === 0 ? 'No previous owners' : 'One previous owner', 'good'] : null,
    !empty($car['is_written_off'])             ? ['fa-triangle-exclamation', 'Insurance write-off', 'warn'] : null,
]));

// Spec rows (full table)
$specs = array_filter([
    ['Make',             $car['make']],
    ['Model',            $car['model']],
    ['Variant',          $car['variant'] ?: null],
    ['Year',             $car['year']],
    ['Condition',        $conditionLabel],
    ['Mileage',          $mileageDisp],
    ['Body type',        $car['body_type'] ?: null],
    ['Exterior colour',  $car['colour'] ?: null],
    ['Interior colour',  $car['interior_colour'] ?: null],
    ['Doors',            $car['doors'] ?: null],
    ['Seats',            $car['seats'] ?: null],
    ['Transmission',     $car['transmission'] ?: null],
    ['Gears',            $car['gears'] ?: null],
    ['Fuel type',        $car['fuel_type'] ?: null],
    ['Drivetrain',       $car['drivetrain'] ?: null],
    ['Engine capacity',  $car['engine_capacity_cc'] ? number_format((int) $car['engine_capacity_cc']) . ' cc' : null],
    ['Cylinders',        $car['cylinders'] ?: null],
    ['Induction',        $car['induction'] ? ucwords(str_replace('_', ' ', $car['induction'])) : null],
    ['Power',            $car['power_kw'] ? (int) $car['power_kw'] . ' kW' : null],
    ['Torque',           $car['torque_nm'] ? (int) $car['torque_nm'] . ' Nm' : null],
    ['Fuel consumption', $car['fuel_consumption_l100km'] ? $car['fuel_consumption_l100km'] . ' L/100km' : null],
    ['CO₂ emissions',    $car['co2_emissions_gkm'] ? (int) $car['co2_emissions_gkm'] . ' g/km' : null],
    ['VAT',              $car['vat_inclusive'] ? 'VAT inclusive' : 'Second-hand goods scheme (margin VAT)'],
], fn($row) => $row[1] !== null && $row[1] !== '');

$sectionNav = array_filter([
    'overview' => 'Overview',
    'specs'    => 'Specs',
    'features' => 'Features',
    'history'  => 'History',
    'finance'  => 'Finance',
    'seller'   => 'Seller',
]);

$galleryImages = array_values(array_filter($images, 'is_string'));
$wishIds       = array_map('intval', getWishlistCarIds((int) $visitor['id']));

$assetVersion         = $assetVersion ?? date('Ymd');
$extraCss             = '<link rel="stylesheet" href="/assets/css/car-detail.css?v=' . $assetVersion . '">' . "\n"
                      . ($coverImg ? '<link rel="preload" as="image" href="' . $e($coverImg) . '">' . "\n" : '');
$includeBrowseCss     = false;
$includeHowItWorksCss = false;
$bodyClass            = 'cd-page';

ob_start();
?>

<script type="application/ld+json">
<?= json_encode($vehicleJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
</script>

<div class="cd sd-container">

  <?php if (!$isAvailable): ?>
  <div class="pub-alert pub-alert--warn cd-banner" role="status">
    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
    <span>This listing is currently <strong><?= $e($car['status']) ?></strong> — enquiries are paused.
      <a href="/cars-for-sale/?make=<?= urlencode($car['make']) ?>">See similar <?= $e($car['make']) ?> cars</a></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($car['is_written_off'])): ?>
  <div class="pub-alert pub-alert--error cd-banner" role="note">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <span><strong>Insurance write-off disclosed.</strong> This vehicle was previously declared a write-off — see <a href="#history">History</a> before you proceed.</span>
  </div>
  <?php endif; ?>

  <!-- ══════════ HEADER ══════════ -->
  <header class="cd-head">
    <div class="cd-head__main">
      <div class="cd-head__tags">
        <span class="pub-badge <?= $car['condition_type'] === 'new' ? 'pub-badge-desk' : '' ?>"><?= $e($conditionLabel) ?></span>
        <?php if ($isAvailable): ?>
        <span class="pub-badge pub-badge-avail"><i class="fa-solid fa-circle" aria-hidden="true"></i> Available</span>
        <?php else: ?>
        <span class="pub-badge pub-badge-<?= $e($car['status']) ?>"><?= $e(ucfirst($car['status'])) ?></span>
        <?php endif; ?>
        <?php if ($car['dealer_verification'] === 'verified'): ?>
        <span class="pub-badge pub-badge-verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Verified dealer</span>
        <?php endif; ?>
      </div>

      <h1 class="cd-head__title">
        <?= $e($carNameOnly) ?>
        <?php if ($car['variant']): ?><span class="cd-head__variant"><?= $e($car['variant']) ?></span><?php endif; ?>
      </h1>

      <ul class="cd-head__facts">
        <?php if ($mileageDisp): ?><li><i class="fa-solid fa-road" aria-hidden="true"></i><?= $e($mileageDisp) ?></li><?php endif; ?>
        <?php if ($car['transmission']): ?><li><i class="fa-solid fa-gear" aria-hidden="true"></i><?= $e($car['transmission']) ?></li><?php endif; ?>
        <?php if ($car['fuel_type']): ?><li><i class="fa-solid <?= sdFuelIconClass($car['fuel_type']) ?>" aria-hidden="true"></i><?= $e($car['fuel_type']) ?></li><?php endif; ?>
        <?php if ($dealerLoc): ?><li><i class="fa-solid fa-location-dot" aria-hidden="true"></i><?= $e($dealerLoc) ?></li><?php endif; ?>
      </ul>

      <div class="cd-head__price">
        <strong><?= $e(sdRand((float) $car['price'])) ?></strong>
        <a href="#finance">est. <span data-fin-mirror><?= $e(sdRand(round($monthlyEst))) ?></span> p/m</a>
      </div>
    </div>

    <div class="cd-head__actions">
      <button class="pub-btn pub-btn-ghost pub-btn-sm cd-save" type="button"
              data-wishlist="<?= (int) $car['id'] ?>" aria-pressed="<?= $isWishlisted ? 'true' : 'false' ?>"
              aria-label="<?= $isWishlisted ? 'Remove ' . $e($carNameOnly) . ' from saved cars' : 'Save ' . $e($carNameOnly) ?>">
        <i class="fa-<?= $isWishlisted ? 'solid' : 'regular' ?> fa-heart" aria-hidden="true"></i>
        <span data-wishlist-label><?= $isWishlisted ? 'Saved' : 'Save' ?></span>
      </button>
      <button class="pub-btn pub-btn-ghost pub-btn-sm" type="button" data-share-open>
        <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Share
      </button>
    </div>
  </header>

  <!-- ══════════ GALLERY ══════════ -->
  <section class="cd-gallery<?= count($galleryImages) <= 1 ? ' cd-gallery--single' : '' ?>" aria-label="Photos"
           data-lightbox-images="<?= $e(json_encode($galleryImages, JSON_UNESCAPED_SLASHES)) ?>"
           data-lightbox-title="<?= $e($carTitle) ?>"
           data-carousel>
    <?php if ($galleryImages): ?>
    <div class="cd-gallery__track" data-carousel-track>
      <?php foreach ($galleryImages as $i => $src): ?>
      <button class="cd-gallery__item cd-gallery__item--<?= $i ?>" type="button" data-lightbox-open="<?= $i ?>"
              aria-label="Open photo <?= $i + 1 ?> of <?= count($galleryImages) ?>">
        <img src="<?= $e($src) ?>" alt="<?= $e($carTitle) ?> — photo <?= $i + 1 ?>"
             width="1200" height="800" decoding="async"
             loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" <?= $i === 0 ? 'fetchpriority="high"' : '' ?>>
        <?php if ($i === 4 && count($galleryImages) > 5): ?>
        <span class="cd-gallery__more">+<?= count($galleryImages) - 5 ?> photos</span>
        <?php endif; ?>
      </button>
      <?php endforeach; ?>
    </div>
    <?php if (count($galleryImages) > 1): ?>
    <span class="cd-gallery__count" data-carousel-count aria-hidden="true">1 / <?= count($galleryImages) ?></span>
    <button class="pub-btn pub-btn-ghost pub-btn-sm cd-gallery__all" type="button" data-lightbox-open="0">
      <i class="fa-solid fa-images" aria-hidden="true"></i> All <?= count($galleryImages) ?> photos
    </button>
    <?php endif; ?>
    <?php else: ?>
    <div class="cd-gallery__empty"><i class="fa-solid fa-car-side" aria-hidden="true"></i><span>Photos coming soon</span></div>
    <?php endif; ?>
  </section>

  <div class="cd-layout">

    <!-- ══════════ MAIN ══════════ -->
    <div class="cd-main">

      <nav class="cd-subnav" aria-label="On this page" data-scrollspy>
        <?php foreach ($sectionNav as $id => $label): ?>
        <a href="#<?= $id ?>"><?= $label ?><?php if ($id === 'features' && $totalFeatureCount): ?> <span><?= $totalFeatureCount ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      </nav>

      <!-- Overview -->
      <section class="cd-card" id="overview" aria-labelledby="cdOverviewTitle">
        <h2 class="cd-card__title" id="cdOverviewTitle">Overview</h2>

        <div class="cd-tiles">
          <?php foreach ($keyTiles as [$icon, $label, $value]): ?>
          <div class="cd-tile">
            <i class="fa-solid <?= $e($icon) ?>" aria-hidden="true"></i>
            <span class="cd-tile__label"><?= $e($label) ?></span>
            <span class="cd-tile__value"><?= $e($value) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($highlights): ?>
        <ul class="cd-highlights" aria-label="Highlights">
          <?php foreach ($highlights as [$icon, $label, $tone]): ?>
          <li class="cd-highlight cd-highlight--<?= $tone ?>"><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i><?= $e($label) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($car['description']): ?>
        <div class="cd-desc">
          <h3 class="cd-card__subtitle">Seller’s description</h3>
          <div class="cd-desc__text is-clamped" id="descText" data-clamp><?= nl2br($e($car['description'])) ?></div>
          <button class="pub-link cd-desc__toggle" type="button" data-clamp-toggle="descText" aria-expanded="false" aria-controls="descText">
            <span>Read more</span> <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
          </button>
        </div>
        <?php endif; ?>
      </section>

      <!-- Specs -->
      <section class="cd-card" id="specs" aria-labelledby="cdSpecsTitle">
        <h2 class="cd-card__title" id="cdSpecsTitle">Specifications</h2>
        <dl class="cd-specs">
          <?php foreach ($specs as [$k, $v]): ?>
          <div class="cd-specs__row"><dt><?= $e($k) ?></dt><dd><?= $e($v) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </section>

      <!-- Features -->
      <section class="cd-card" id="features" aria-labelledby="cdFeatTitle">
        <h2 class="cd-card__title" id="cdFeatTitle">Features <?php if ($totalFeatureCount): ?><span class="cd-card__count"><?= $totalFeatureCount ?></span><?php endif; ?></h2>
        <?php if ($totalFeatureCount): ?>
        <div class="cd-feats">
          <?php foreach ($featuresByCategory as $category => $items): ?>
          <div class="cd-feat-group">
            <h3 class="cd-feat-group__title">
              <i class="fa-solid <?= $e($categoryIcons[$category] ?? 'fa-tag') ?>" aria-hidden="true"></i>
              <?= $e($category) ?> <span><?= count($items) ?></span>
            </h3>
            <ul class="cd-feat-list">
              <?php foreach ($items as $item): ?>
              <li><i class="fa-solid fa-check" aria-hidden="true"></i><?= $e($item['name']) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="cd-muted-box"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          The seller hasn’t listed detailed features yet — ask <?= $e($brokerDisplayName) ?> about specific options when you enquire.</p>
        <?php endif; ?>
      </section>

      <!-- History -->
      <section class="cd-card" id="history" aria-labelledby="cdHistTitle">
        <h2 class="cd-card__title" id="cdHistTitle">History &amp; warranty</h2>
        <?php if (!empty($car['is_written_off'])): ?>
        <div class="pub-alert pub-alert--error cd-hist-alert">
          <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
          <span><strong>Insurance write-off disclosed.</strong> Ask the dealer for the full assessment report before proceeding.</span>
        </div>
        <?php endif; ?>
        <dl class="cd-specs">
          <div class="cd-specs__row"><dt>Previous owners</dt><dd><?= $car['previous_owners'] !== null ? (int) $car['previous_owners'] : 'Not disclosed' ?></dd></div>
          <div class="cd-specs__row"><dt>Service history</dt><dd><?= $e($serviceHistoryLabel) ?></dd></div>
          <div class="cd-specs__row"><dt>Service book</dt><dd><?= !empty($car['has_service_book']) ? 'Present' : 'Not available' ?></dd></div>
          <div class="cd-specs__row"><dt>Warranty</dt><dd><?= $e($warrantyTypeLabel) ?></dd></div>
          <?php if (($car['warranty_type'] ?? 'none') !== 'none'): ?>
          <div class="cd-specs__row"><dt>Warranty expires</dt><dd><?= $e($warrantyDisplay) ?></dd></div>
          <?php endif; ?>
          <?php if ($servicePlanDisplay !== '—'): ?>
          <div class="cd-specs__row"><dt>Service plan expires</dt><dd><?= $e($servicePlanDisplay) ?></dd></div>
          <?php endif; ?>
          <?php if ($vinDisplay): ?>
          <div class="cd-specs__row"><dt>VIN</dt><dd class="cd-mono"><?= $e($vinDisplay) ?></dd></div>
          <?php endif; ?>
          <?php if ($car['mm_code']): ?>
          <div class="cd-specs__row"><dt>M&amp;M code</dt><dd class="cd-mono"><?= $e($car['mm_code']) ?></dd></div>
          <?php endif; ?>
        </dl>
        <p class="cd-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          Ownership, warranty and service details are supplied by the dealer. Always ask for supporting documents before you buy.</p>
      </section>

      <!-- Finance -->
      <section class="cd-card" id="finance" aria-labelledby="cdFinTitle">
        <h2 class="cd-card__title" id="cdFinTitle">Finance estimate</h2>
        <div class="fin" data-finance="repay" data-price="<?= (float) $car['price'] ?>">
          <div class="fin__result">
            <div>
              <div class="fin__label">Estimated monthly repayment</div>
              <div class="fin__monthly"><span data-fin-result><?= $e(sdRand(round($monthlyEst))) ?></span> <small>p/m</small></div>
            </div>
            <div class="fin__meta">
              Loan amount <strong data-fin-loan>—</strong><br>
              Total cost <strong data-fin-total>—</strong>
            </div>
          </div>
          <div class="fin__grid">
            <div class="fin__row">
              <div class="fin__row-head"><label for="finDeposit">Deposit</label><output data-fin-deposit-out for="finDeposit">20%</output></div>
              <input type="range" id="finDeposit" min="0" max="50" step="5" value="20" data-fin-deposit>
            </div>
            <div class="fin__row">
              <div class="fin__row-head"><label for="finBalloon">Balloon payment</label><output data-fin-balloon-out for="finBalloon">None</output></div>
              <input type="range" id="finBalloon" min="0" max="40" step="5" value="0" data-fin-balloon>
            </div>
            <div class="fin__row">
              <div class="fin__row-head"><label for="finRate">Interest rate</label><output data-fin-rate-out for="finRate">13.25%</output></div>
              <input type="range" id="finRate" min="8" max="22" step="0.25" value="13.25" data-fin-rate>
            </div>
            <div class="fin__row">
              <div class="fin__row-head"><span id="finTermLbl">Term</span></div>
              <div class="pub-segment fin__terms" role="radiogroup" aria-labelledby="finTermLbl">
                <?php foreach ([36, 48, 60, 72] as $t): ?>
                <label class="pub-segment__opt"><input type="radio" name="fin_term" value="<?= $t ?>" data-fin-term <?= $t === 60 ? 'checked' : '' ?>><span><?= $t ?> mo</span></label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <p class="fin__note">Indicative only. Your bank sets the final rate based on your credit profile; the dealer can arrange a formal quote.</p>
        </div>
      </section>

      <!-- Seller -->
      <section class="cd-card" id="seller" aria-labelledby="cdSellerTitle">
        <h2 class="cd-card__title" id="cdSellerTitle">Who you’ll deal with</h2>
        <div class="cd-sellers">

          <?php if (!$isPlatformCar): ?>
          <div class="cd-seller">
            <div class="cd-seller__head">
              <span class="cd-avatar">
                <?php if ($deskRow['broker_avatar']): ?>
                <img src="<?= $e($deskRow['broker_avatar']) ?>" alt="" width="52" height="52" loading="lazy">
                <?php else: ?><?= $e($brokerInitials) ?><?php endif; ?>
              </span>
              <span class="cd-seller__id">
                <span class="cd-seller__role">Your SalesDesk broker</span>
                <span class="cd-seller__name"><?= $e($deskRow['desk_name']) ?></span>
                <?php if ($brokerDisplayName !== $deskRow['desk_name']): ?>
                <span class="cd-seller__sub"><?= $e($brokerDisplayName) ?> · Independent broker</span>
                <?php endif; ?>
                <?php if ($org && $org['verification_status'] === 'verified'): ?>
                <span class="pub-badge pub-badge-desk cd-seller__org"><i class="fa-solid fa-building" aria-hidden="true"></i><?= $e($org['name']) ?></span>
                <?php endif; ?>
              </span>
            </div>
            <div class="cd-seller__stats">
              <span><strong><?= (int) ($deskStats['active_cars'] ?? 0) ?></strong> listings</span>
              <span><strong><?= (int) ($deskStats['desk_closed'] ?? 0) ?></strong> deals closed</span>
              <span><strong><?= (int) ($deskStats['desk_leads'] ?? 0) ?></strong> enquiries</span>
            </div>
            <div class="cd-seller__actions">
              <a href="/<?= $e($deskRow['desk_slug']) ?>/" class="pub-btn pub-btn-ghost pub-btn-sm">View desk</a>
              <?php if ($waLink && $isAvailable): ?>
              <a href="<?= $e($waLink) ?>" class="pub-btn pub-btn-whatsapp pub-btn-sm" target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="cd-seller">
            <div class="cd-seller__head">
              <span class="cd-avatar cd-avatar--dealer"><i class="fa-solid fa-building-user" aria-hidden="true"></i></span>
              <span class="cd-seller__id">
                <span class="cd-seller__role">Dealership</span>
                <span class="cd-seller__name"><?= $e($car['dealer_name']) ?>
                  <?php if ($car['dealer_verification'] === 'verified'): ?><i class="fa-solid fa-circle-check cd-verified" title="Verified dealer" aria-label="Verified dealer"></i><?php endif; ?>
                </span>
                <?php if ($dealerLoc): ?><span class="cd-seller__sub"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $e($dealerLoc) ?></span><?php endif; ?>
              </span>
            </div>
            <div class="cd-seller__stats">
              <span><strong><?= (int) ($dealerStats['active_listings'] ?? 0) ?></strong> cars for sale</span>
              <span><strong><?= (int) ($dealerStats['total_leads'] ?? 0) ?></strong> enquiries handled</span>
            </div>
            <div class="cd-seller__actions">
              <a href="/cars-for-sale/?dealer=<?= (int) $car['dealer_id'] ?>" class="pub-btn pub-btn-ghost pub-btn-sm">All cars from this dealer</a>
            </div>
          </div>

        </div>
      </section>

    </div><!-- /cd-main -->

    <!-- ══════════ ASIDE: PRICE + ENQUIRY ══════════ -->
    <aside class="cd-aside" aria-label="Price and enquiry">
      <div class="cd-enquiry" id="enquiry">

        <div class="cd-enquiry__price-block">
          <div class="cd-enquiry__price"><?= $e(sdRand((float) $car['price'])) ?></div>
          <a class="cd-enquiry__pm" href="#finance">
            est. <strong data-fin-mirror><?= $e(sdRand(round($monthlyEst))) ?></strong> p/m
            <i class="fa-solid fa-calculator" aria-hidden="true"></i>
          </a>
          <?php if ($isTradeViewer): ?>
          <div class="cd-enquiry__comm" title="Visible to signed-in trade accounts only">
            <i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Broker commission <strong><?= $e($commRandDisp) ?></strong>
          </div>
          <?php endif; ?>
        </div>

        <div class="cd-enquiry__body">
          <div class="cd-enquiry__who">
            <?php if (!$isPlatformCar): ?>
            <span class="cd-avatar cd-avatar--sm">
              <?php if ($deskRow['broker_avatar']): ?><img src="<?= $e($deskRow['broker_avatar']) ?>" alt="" width="36" height="36"><?php else: ?><?= $e($brokerInitials) ?><?php endif; ?>
            </span>
            <span><span class="cd-enquiry__who-label">Enquire with</span><strong><?= $e($deskRow['desk_name']) ?></strong></span>
            <?php else: ?>
            <span class="cd-avatar cd-avatar--sm cd-avatar--dealer"><i class="fa-solid fa-shop" aria-hidden="true"></i></span>
            <span><span class="cd-enquiry__who-label">Goes directly to</span><strong><?= $e($car['dealer_name']) ?></strong></span>
            <?php endif; ?>
          </div>

          <?php if (!$isAvailable): ?>
          <div class="pub-alert pub-alert--warn">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <span>This car is <?= $e($car['status']) ?>. Enquiries are paused.</span>
          </div>
          <a class="pub-btn pub-btn-primary pub-btn-full" href="/cars-for-sale/?make=<?= urlencode($car['make']) ?>">See similar cars</a>
          <?php else: ?>

          <div class="pub-alert pub-alert--error cd-enquiry__error" id="enquiryGlobalError" role="alert" hidden>
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span></span>
          </div>

          <form id="enquiryForm" class="cd-form" novalidate>
            <input type="hidden" name="csrf_token"    value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="tracking_code" value="<?= $e((string) $activeTrackingCode) ?>">
            <?php if (!$isPlatformCar): ?>
            <input type="hidden" name="desk_slug"     value="<?= $e($deskSlug) ?>">
            <?php endif; ?>
            <input type="hidden" name="car_slug"      value="<?= $e($carSlug) ?>">

            <div class="cd-form__grid">
              <div class="pub-field">
                <label class="pub-form-label" for="buyer_name">Name</label>
                <input class="pub-form-input" type="text" id="buyer_name" name="buyer_name"
                       autocomplete="name" required aria-describedby="nameError">
                <div class="pub-form-error" id="nameError"></div>
              </div>
              <div class="pub-field">
                <label class="pub-form-label" for="buyer_phone">Phone</label>
                <input class="pub-form-input" type="tel" id="buyer_phone" name="buyer_phone"
                       inputmode="tel" autocomplete="tel" placeholder="082 000 0000" required aria-describedby="phoneError">
                <div class="pub-form-error" id="phoneError"></div>
              </div>
            </div>

            <div class="pub-field">
              <label class="pub-form-label" for="buyer_email">Email <small>(optional)</small></label>
              <input class="pub-form-input" type="email" id="buyer_email" name="buyer_email"
                     autocomplete="email" inputmode="email" aria-describedby="emailError">
              <div class="pub-form-error" id="emailError"></div>
            </div>

            <fieldset class="pub-field cd-form__intent">
              <legend class="pub-form-label">When are you looking to buy?</legend>
              <div class="cd-intent">
                <?php foreach (['within_30d' => 'This month', 'one_to_3mo' => '1–3 months', 'browsing' => 'Just looking'] as $val => $label): ?>
                <label class="cd-intent__opt">
                  <input type="radio" name="buyer_intent" value="<?= $val ?>" <?= $val === 'browsing' ? 'checked' : '' ?>>
                  <span><?= $label ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </fieldset>

            <details class="cd-form__msg">
              <summary><i class="fa-solid fa-plus" aria-hidden="true"></i> Add a message</summary>
              <label class="sr-only" for="buyer_message">Message</label>
              <textarea class="pub-form-input" id="buyer_message" name="buyer_message" rows="3"
                        placeholder="Is it still available? Can I book a test drive on Saturday?"></textarea>
            </details>

            <div class="pub-form-consent">
              <input type="checkbox" id="consent_given" name="consent_given" value="1" aria-describedby="consentError">
              <label for="consent_given">
                Share my details with the dealer<?= $isPlatformCar ? '' : ' and listing broker' ?> for this car.
                <a href="/privacy" target="_blank" rel="noopener">Privacy policy</a>
              </label>
            </div>
            <div class="pub-form-error" id="consentError"></div>

            <button class="pub-btn pub-btn-primary pub-btn-lg pub-btn-full cd-form__submit" id="enquirySubmit" type="submit">
              <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send enquiry
            </button>

            <?php if ($waLink): ?>
            <a href="<?= $e($waLink) ?>" target="_blank" rel="noopener" class="pub-btn pub-btn-ghost pub-btn-full cd-form__wa">
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Chat on WhatsApp
            </a>
            <?php endif; ?>
          </form>

          <div class="cd-success" id="enquirySuccess" tabindex="-1" hidden>
            <span class="cd-success__icon"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
            <h3 class="cd-success__title">Enquiry sent</h3>
            <p><?= $e($brokerDisplayName === 'SalesDesk' ? $car['dealer_name'] : $brokerDisplayName) ?> will contact you shortly. A confirmation is on its way to your email.</p>
            <a class="pub-link" href="/cars-for-sale/?make=<?= urlencode($car['make']) ?>">Keep browsing <?= $e($car['make']) ?> <i class="fa-solid fa-arrow-right"></i></a>
          </div>

          <?php endif; ?>

          <ul class="cd-trust">
            <li><i class="fa-solid fa-lock" aria-hidden="true"></i> Details only go to this car’s seller</li>
            <li><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> POPIA compliant · no cost to you</li>
          </ul>
        </div>
      </div>
    </aside>

  </div><!-- /cd-layout -->

  <?php if (!empty($relatedCars)): ?>
  <section class="pub-section cd-related" aria-labelledby="cdRelTitle">
    <div class="pub-section__head">
      <div>
        <span class="pub-eyebrow">Same dealership</span>
        <h2 class="pub-section__title pub-section__title--sm" id="cdRelTitle">More from <?= $e($car['dealer_name']) ?></h2>
      </div>
      <a class="pub-link pub-section__link" href="/cars-for-sale/?dealer=<?= (int) $car['dealer_id'] ?>">View all <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="vc-grid vc-grid--4">
      <?php foreach ($relatedCars as $rel) {
          $rel['desk_slug'] = $rel['rel_desk_slug'] ?? null;
          $relRef = ($activeTrackingCode && $activeTrackingCode !== ($rel['rel_tracking_code'] ?? null)) ? (string) $activeTrackingCode : '';
          echo sdVehicleCard($rel + ['dealer_name' => $car['dealer_name'], 'dealer_city' => $car['dealer_city'], 'dealer_province' => $car['dealer_province']], [
              'ref'        => $relRef,
              'wishlisted' => in_array((int) $rel['id'], $wishIds, true),
          ]);
      } ?>
    </div>
  </section>
  <?php endif; ?>

</div><!-- /cd -->

<?php if ($isAvailable): ?>
<!-- ══════════ STICKY MOBILE CTA ══════════ -->
<div class="cd-sticky" data-sticky-cta data-sticky-cta-target="#enquiry" data-sticky-cta-after=".cd-head">
  <div class="cd-sticky__price">
    <strong><?= $e(sdRand((float) $car['price'])) ?></strong>
    <span>est. <span data-fin-mirror><?= $e(sdRand(round($monthlyEst))) ?></span> p/m</span>
  </div>
  <?php if ($waLink): ?>
  <a class="pub-btn pub-btn-whatsapp pub-btn-icon" href="<?= $e($waLink) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
  </a>
  <?php endif; ?>
  <button class="pub-btn pub-btn-primary" type="button" data-enquiry-focus data-enquiry-hide-on-success>Enquire now</button>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$layoutVariant = 'wide';
require_once '../../views/layout-public.php';
