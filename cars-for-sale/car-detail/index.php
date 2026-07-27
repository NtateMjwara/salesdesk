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
 * CHANGES IN v3 (this pass):
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
        c2.id, c2.slug AS car_slug, c2.make, c2.model, c2.year,
        c2.price, c2.mileage, c2.image_urls, c2.condition_type,
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
      AND first_rel.desk_slug IS NOT NULL
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

$carTitle    = "{$car['year']} {$car['make']} {$car['model']}" . ($car['variant'] ? " {$car['variant']}" : '');
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
    ['Browse',       '/cars-for-sale/'],
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

ob_start();
?>

<script type="application/ld+json">
<?= json_encode($vehicleJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<?php if (!$isAvailable): ?>
<div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 0;text-align:center;
            font-size:13px;color:#b45309;font-weight:500;">
  <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
  This listing is currently <?= htmlspecialchars($car['status']) ?>.
  <a href="/cars-for-sale/" style="color:#0f4c9e;margin-left:8px;">Browse available cars →</a>
</div>
<?php endif; ?>

<?php if (!empty($car['is_written_off'])): ?>
<div style="background:#fef2f2;border-bottom:1px solid #fecaca;padding:10px 0;text-align:center;
            font-size:13px;color:#dc2626;font-weight:600;">
  <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
  This vehicle has been declared an insurance write-off. See the History &amp; Warranty tab for details.
</div>
<?php endif; ?>

<div class="pub-detail-grid pub-anim">

  <!-- ═══════════════════════════════════
       LEFT COLUMN
       ═══════════════════════════════════ -->
  <div>

    <!-- Gallery -->
    <div class="pub-gallery pub-anim pub-d1">
      <div class="pub-gallery__main" id="galleryMainWrap">
        <?php if ($coverImg): ?>
        <img src="<?= htmlspecialchars($coverImg) ?>"
             id="galleryMain" alt="<?= htmlspecialchars($carTitle) ?>" loading="eager">
        <?php else: ?>
        <div class="pub-gallery__placeholder">
          <i class="fa-solid fa-car-side"></i>
        </div>
        <?php endif; ?>
        <div class="pub-gallery__badge-row">
          <span class="pub-gallery__year"><?= (int)$car['year'] ?></span>
          <span class="pub-gallery__prov"><?= htmlspecialchars($conditionLabel) ?></span>
        </div>
        <?php if ($car['mileage']): ?>
        <span class="pub-gallery__km">
          <i class="fa-solid fa-gauge" style="font-size:10px;margin-right:4px;"></i>
          <?= number_format((int)$car['mileage']) ?> km
        </span>
        <?php endif; ?>
        <?php if (count($images) > 1): ?>
        <span class="pub-gallery__count" id="galleryCount">1 / <?= count($images) ?></span>
        <?php endif; ?>
      </div>
      <?php if (count($images) > 1): ?>
      <div class="pub-gallery__thumbs">
        <?php foreach ($images as $i => $url): ?>
        <div class="pub-gallery__thumb <?= $i === 0 ? 'active' : '' ?>"
             data-src="<?= htmlspecialchars($url) ?>">
          <img src="<?= htmlspecialchars($url) ?>"
               alt="<?= htmlspecialchars($carTitle) ?> image <?= $i + 1 ?>"
               loading="lazy">
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Car header -->
    <div class="pub-car-header pub-anim pub-d2">
      <div class="pub-car-header__top">
        <div>
          <h1 class="pub-car-header__name"><?= htmlspecialchars($carTitle) ?></h1>
          <div class="pub-car-header__dealer">
            <i class="fa-solid fa-building-user"></i>
            <?= htmlspecialchars($car['dealer_name']) ?>
            <?php if ($dealerLoc): ?>
            · <i class="fa-solid fa-location-dot"></i>
            <?= htmlspecialchars($dealerLoc) ?>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div class="pub-car-header__price"><?= $priceDisp ?></div>
          <div class="pub-car-header__pm">~<?= $monthlyDisp ?> est.</div>
        </div>
      </div>
      <div class="pub-car-header__badges">
        <?php if ($isAvailable): ?>
        <span class="pub-badge pub-badge-avail"><i class="fa-solid fa-circle-check"></i> Available</span>
        <?php else: ?>
        <span class="pub-badge pub-badge-<?= $car['status'] ?>"><?= ucfirst($car['status']) ?></span>
        <?php endif; ?>
        <?php if ($car['dealer_verification'] === 'verified'): ?>
        <span class="pub-badge pub-badge-verified"><i class="fa-solid fa-circle-check"></i> Verified Dealer</span>
        <?php endif; ?>
        <?php if ($serviceHistoryLabel === 'Full service history'): ?>
        <span class="pub-badge pub-badge-verified"><i class="fa-solid fa-file-circle-check"></i> Full Service History</span>
        <?php endif; ?>
        <!-- v2: desk attribution badge always visible -->
        <?php if (!$isPlatformCar): ?>
        <span class="pub-badge pub-badge-desk">
          <i class="fa-solid fa-id-card"></i>
          Listed by <?= htmlspecialchars($deskRow['desk_name']) ?>
        </span>
        <?php else: ?>
        <span class="pub-badge pub-badge-desk">
          <i class="fa-solid fa-shop"></i>
          Direct from SalesDesk
        </span>
        <?php endif; ?>
        <button class="pub-nav-icon-btn <?= $isWishlisted ? 'wishlisted' : '' ?>"
                onclick="toggleWishlist(this, <?= (int)$car['id'] ?>)"
                title="<?= $isWishlisted ? 'Remove from saved' : 'Save car' ?>"
                style="margin-left:auto;">
          <i class="fa-<?= $isWishlisted ? 'solid' : 'regular' ?> fa-heart"></i>
        </button>
        <button class="pub-nav-icon-btn" onclick="openShareSheet()" title="Share listing">
          <i class="fa-solid fa-share-nodes"></i>
        </button>
      </div>
    </div>

    <!-- Spec chips -->
    <?php
    $chips = array_filter([
        $car['transmission'] ? ['fa-gears',              $car['transmission']]                       : null,
        $car['fuel_type']    ? ['fa-gas-pump',            $car['fuel_type']]                          : null,
        $car['body_type']    ? ['fa-car-side',            $car['body_type']]                          : null,
        $car['colour']       ? ['fa-palette',             $car['colour']]                             : null,
        $car['drivetrain']   ? ['fa-arrow-rotate-right',  $car['drivetrain']]                         : null,
        $car['mileage']      ? ['fa-gauge',               number_format((int)$car['mileage']) . ' km'] : null,
    ]);
    ?>
    <?php if ($chips): ?>
    <div class="pub-spec-chips pub-anim pub-d2">
      <?php foreach ($chips as [$icon, $value]): ?>
      <span class="pub-spec-chip">
        <i class="fa-solid <?= $icon ?>"></i>
        <?= htmlspecialchars($value) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Description -->
    <?php if ($car['description']): ?>
    <div class="pub-desc-block pub-reveal">
      <div class="pub-desc-block__title">About this car</div>
      <div class="pub-desc-block__text clamped" id="descText">
        <?= nl2br(htmlspecialchars($car['description'])) ?>
      </div>
      <button class="pub-desc-block__toggle" id="descToggle" type="button">
        <i class="fa-solid fa-chevron-down"></i> Read more
      </button>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════
         UX-1: Specifications / Features / History tab group
         Replaces the old single long spec table — same data plus
         car_features (grouped by category) and ownership/warranty
         disclosure, all reachable without endless scrolling.
         ═══════════════════════════════════ -->
    <div class="pub-tabs pub-reveal" id="specTabs">
      <div class="pub-tabs__nav" role="tablist" aria-label="Vehicle details">
        <button class="pub-tabs__btn active" type="button" data-tab="specs" role="tab" aria-selected="true">
          <i class="fa-solid fa-list-check"></i> Specifications
        </button>
        <button class="pub-tabs__btn" type="button" data-tab="features" role="tab" aria-selected="false">
          <i class="fa-solid fa-star"></i> Features
          <?php if ($totalFeatureCount): ?>
          <span class="pub-tabs__count"><?= $totalFeatureCount ?></span>
          <?php endif; ?>
        </button>
        <button class="pub-tabs__btn" type="button" data-tab="history" role="tab" aria-selected="false">
          <i class="fa-solid fa-shield-halved"></i> History &amp; Warranty
          <?php if (!empty($car['is_written_off'])): ?>
          <span class="pub-tabs__count pub-tabs__count--warn"><i class="fa-solid fa-triangle-exclamation"></i></span>
          <?php endif; ?>
        </button>
      </div>

      <!-- ── Specifications panel ── -->
      <div class="pub-tabs__panel active" data-panel="specs" role="tabpanel">
        <div class="pub-spec-table">
          <?php
          $specs = array_filter([
              ['Make',            $car['make']],
              ['Model',           $car['model']],
              ['Variant',         $car['variant'] ?: null],
              ['Year',            $car['year']],
              ['Condition',       ucfirst($car['condition_type'])],
              ['Body type',       $car['body_type']    ?: null],
              ['Exterior colour', $car['colour']       ?: null],
              ['Interior colour', $car['interior_colour'] ?: null],
              ['Doors',           $car['doors']        ?: null],
              ['Seats',           $car['seats']        ?: null],
              ['Transmission',    $car['transmission'] ?: null],
              ['Gears',           $car['gears']        ?: null],
              ['Fuel type',       $car['fuel_type']    ?: null],
              ['Drivetrain',      $car['drivetrain']   ?: null],
              ['Engine capacity', $car['engine_capacity_cc'] ? number_format((int)$car['engine_capacity_cc']) . ' cc' : null],
              ['Cylinders',       $car['cylinders']    ?: null],
              ['Induction',       $car['induction'] ? ucwords(str_replace('_', ' ', $car['induction'])) : null],
              ['Power',           $car['power_kw']  ? (int)$car['power_kw'] . ' kW' : null],
              ['Torque',          $car['torque_nm'] ? (int)$car['torque_nm'] . ' Nm' : null],
              ['Fuel consumption',$car['fuel_consumption_l100km'] ? $car['fuel_consumption_l100km'] . ' L/100km' : null],
              ['CO₂ emissions',   $car['co2_emissions_gkm'] ? (int)$car['co2_emissions_gkm'] . ' g/km' : null],
              ['Mileage',         $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : null],
              ['VAT',             $car['vat_inclusive'] ? 'VAT inclusive' : 'Second-hand goods scheme (margin VAT)'],
              ['Price',           $priceDisp, true],
          ], fn($row) => $row[1] !== null);
          foreach ($specs as $row):
              [$key, $val, $highlight] = array_pad($row, 3, false);
          ?>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key"><?= htmlspecialchars($key) ?></span>
            <span class="pub-spec-table__val <?= $highlight ? 'highlight' : '' ?>">
              <?= htmlspecialchars((string)$val) ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ── Features panel ── -->
      <div class="pub-tabs__panel" data-panel="features" role="tabpanel">
        <?php if ($totalFeatureCount): ?>
        <div class="pub-feat-groups">
          <?php foreach ($featuresByCategory as $category => $items):
              $catIcon = $categoryIcons[$category] ?? 'fa-tag';
          ?>
          <div class="pub-feat-group">
            <div class="pub-feat-group__title">
              <i class="fa-solid <?= $catIcon ?>"></i>
              <?= htmlspecialchars($category) ?>
              <span class="pub-feat-group__count"><?= count($items) ?></span>
            </div>
            <ul class="pub-feat-list">
              <?php foreach ($items as $item): ?>
              <li class="pub-feat-item">
                <i class="fa-solid fa-check"></i>
                <?= htmlspecialchars($item['name']) ?>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="pub-feat-empty">
          <i class="fa-solid fa-circle-info"></i>
          The dealer hasn't listed detailed features for this car yet.
          Ask <?= htmlspecialchars($brokerDisplayName) ?> about specific options via the enquiry form.
        </div>
        <?php endif; ?>
      </div>

      <!-- ── History & Warranty panel ── -->
      <div class="pub-tabs__panel" data-panel="history" role="tabpanel">

        <?php if (!empty($car['is_written_off'])): ?>
        <div class="pub-history-alert">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <div>
            <strong>Insurance write-off disclosed.</strong>
            This vehicle has previously been declared an insurance write-off.
            Ask the dealer for the full assessment report before proceeding.
          </div>
        </div>
        <?php endif; ?>

        <div class="pub-spec-table">
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">Previous owners</span>
            <span class="pub-spec-table__val"><?= $car['previous_owners'] !== null ? (int)$car['previous_owners'] : 'Not disclosed' ?></span>
          </div>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">Service history</span>
            <span class="pub-spec-table__val"><?= htmlspecialchars($serviceHistoryLabel) ?></span>
          </div>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">Service book</span>
            <span class="pub-spec-table__val"><?= !empty($car['has_service_book']) ? 'Present' : 'Not available' ?></span>
          </div>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">Warranty</span>
            <span class="pub-spec-table__val"><?= htmlspecialchars($warrantyTypeLabel) ?></span>
          </div>
          <?php if (($car['warranty_type'] ?? 'none') !== 'none'): ?>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">Warranty expires</span>
            <span class="pub-spec-table__val"><?= htmlspecialchars($warrantyDisplay) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($servicePlanDisplay !== '—'): ?>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">Service plan expires</span>
            <span class="pub-spec-table__val"><?= htmlspecialchars($servicePlanDisplay) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($vinDisplay): ?>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">VIN</span>
            <span class="pub-spec-table__val" style="font-family:var(--mono);letter-spacing:.03em;">
              <?= htmlspecialchars($vinDisplay) ?>
            </span>
          </div>
          <?php endif; ?>
          <?php if ($car['mm_code']): ?>
          <div class="pub-spec-table__row">
            <span class="pub-spec-table__key">M&amp;M code</span>
            <span class="pub-spec-table__val"><?= htmlspecialchars($car['mm_code']) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <p class="pub-history-note">
          <i class="fa-solid fa-circle-info"></i>
          Ownership, warranty and service details are supplied by the dealer.
          Always request supporting documentation before purchase.
        </p>
      </div>

    </div><!-- /pub-tabs -->

    <!-- Finance estimator -->
    <div class="pub-spec-section pub-reveal">
      <div class="pub-spec-section__title">Finance estimate</div>
      <div style="background:white;border:1px solid #e8ecf4;border-radius:16px;padding:20px;box-shadow:0 2px 12px rgba(15,76,158,.06);">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;font-weight:700;">Estimated monthly</div>
            <div id="monthlyDisplay"
                 style="font-family:'Sora',sans-serif;font-size:26px;font-weight:800;color:#0f4c9e;letter-spacing:-.02em;margin-top:2px;">
              ~<?= $monthlyDisp ?>
            </div>
          </div>
          <div id="depositDisplay" style="font-size:12px;color:#94a3b8;text-align:right;">20% deposit</div>
        </div>
        <input type="range" id="depositSlider" min="0" max="50" step="5" value="20"
               data-price="<?= (float)$car['price'] ?>"
               data-rate="13.25" data-term="60"
               style="width:100%;accent-color:#0f4c9e;margin-bottom:6px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:#94a3b8;">
          <span>0% deposit</span><span>50% deposit</span>
        </div>
        <p style="font-size:10px;color:#94a3b8;margin-top:10px;line-height:1.5;">
          Estimate based on NCA rate ~13.25% p.a., 60 months, selected deposit.
          Contact the dealer for a formal finance quote.
        </p>
      </div>
    </div>

    <!-- SalesDesk info card (v2: replaces old "broker chip" — desk is primary) -->
    <?php if (!$isPlatformCar): ?>
    <div class="pub-reveal" style="background:white;border:1px solid #e8ecf4;border-radius:16px;
         padding:20px;margin-bottom:24px;box-shadow:0 2px 12px rgba(15,76,158,.06);">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
        <!-- Desk avatar -->
        <div style="width:52px;height:52px;border-radius:50%;flex-shrink:0;overflow:hidden;
                    background:linear-gradient(135deg,#0f4c9e,#3b82f6);
                    display:flex;align-items:center;justify-content:center;
                    font-family:'Sora',sans-serif;font-size:16px;font-weight:700;color:#fff;">
          <?php if ($deskRow['broker_avatar']): ?>
          <img src="<?= htmlspecialchars($deskRow['broker_avatar']) ?>"
               style="width:100%;height:100%;object-fit:cover;" alt="">
          <?php else: ?>
          <?= htmlspecialchars($brokerInitials) ?>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:#1e293b;">
            <?= htmlspecialchars($deskRow['desk_name']) ?>
          </div>
          <?php if ($brokerDisplayName !== $deskRow['desk_name']): ?>
          <div style="font-size:12px;color:#64748b;">
            <?= htmlspecialchars($brokerDisplayName) ?> · Independent auto broker
          </div>
          <?php endif; ?>
          <?php if ($org && $org['verification_status'] === 'verified'): ?>
          <div style="margin-top:3px;">
            <span style="font-size:10px;background:#eff4ff;color:#0f4c9e;border:1px solid #dbeafe;
                         border-radius:20px;padding:2px 8px;font-family:'Sora',sans-serif;">
              <i class="fa-solid fa-building" style="font-size:9px;margin-right:3px;"></i>
              <?= htmlspecialchars($org['name']) ?>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Desk stats strip -->
      <div style="display:flex;gap:16px;padding:12px 0;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;margin-bottom:12px;">
        <div style="text-align:center;flex:1;">
          <div style="font-family:'Sora',sans-serif;font-size:18px;font-weight:700;color:#1e293b;">
            <?= (int)($deskStats['active_cars'] ?? 0) ?>
          </div>
          <div style="font-size:10px;color:#94a3b8;margin-top:1px;">Active listings</div>
        </div>
        <div style="text-align:center;flex:1;">
          <div style="font-family:'Sora',sans-serif;font-size:18px;font-weight:700;color:#1e293b;">
            <?= (int)($deskStats['desk_closed'] ?? 0) ?>
          </div>
          <div style="font-size:10px;color:#94a3b8;margin-top:1px;">Deals closed</div>
        </div>
        <div style="text-align:center;flex:1;">
          <div style="font-family:'Sora',sans-serif;font-size:18px;font-weight:700;color:#1e293b;">
            <?= (int)($deskStats['desk_leads'] ?? 0) ?>
          </div>
          <div style="font-size:10px;color:#94a3b8;margin-top:1px;">Total enquiries</div>
        </div>
      </div>

      <div style="display:flex;gap:8px;">
        <a href="/<?= htmlspecialchars($deskRow['desk_slug']) ?>/"
           class="pub-btn pub-btn-ghost" style="flex:1;justify-content:center;font-size:12px;padding:8px;">
          <i class="fa-solid fa-id-card"></i> View all from this desk
        </a>
        <?php if ($deskRow['broker_phone']): ?>
        <a href="https://wa.me/27<?= ltrim(preg_replace('/\D/', '', $deskRow['broker_phone']), '0') ?>"
           target="_blank" rel="noopener"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
                  background:#25d366;color:#fff;border-radius:12px;font-size:12px;
                  font-weight:600;font-family:'DM Sans',sans-serif;text-decoration:none;">
          <i class="fa-brands fa-whatsapp"></i> WhatsApp
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Dealer card -->
    <div class="pub-dealer-card pub-reveal">
      <div class="pub-dealer-card__header">
        <div class="pub-dealer-card__icon"><i class="fa-solid fa-building-user"></i></div>
        <div>
          <div class="pub-dealer-card__name"><?= htmlspecialchars($car['dealer_name']) ?></div>
          <div class="pub-dealer-card__loc">
            <?php if ($dealerLoc): ?>
            <i class="fa-solid fa-location-dot" style="font-size:10px;margin-right:3px;"></i>
            <?= htmlspecialchars($dealerLoc) ?>
            <?php endif; ?>
            <?php if ($car['dealer_verification'] === 'verified'): ?>
            &nbsp;<span class="pub-badge pub-badge-verified" style="font-size:9px;">
              <i class="fa-solid fa-circle-check"></i> Verified
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="pub-dealer-card__stats">
        <div class="pub-dealer-stat">
          <div class="pub-dealer-stat__num"><?= (int)($dealerStats['active_listings'] ?? 0) ?></div>
          <div class="pub-dealer-stat__lbl">Active listings</div>
        </div>
        <div class="pub-dealer-stat">
          <div class="pub-dealer-stat__num"><?= (int)($dealerStats['total_leads'] ?? 0) ?></div>
          <div class="pub-dealer-stat__lbl">Enquiries handled</div>
        </div>
      </div>
      <a href="/cars-for-sale/?dealer=<?= (int)$car['dealer_id'] ?>"
         class="pub-btn pub-btn-ghost" style="font-size:12px;width:100%;justify-content:center;">
        <i class="fa-solid fa-grid-2"></i> View all cars from this dealer
      </a>
    </div>

  </div><!-- /left col -->


  <!-- ═══════════════════════════════════
       RIGHT COLUMN — Sticky enquiry card
       ═══════════════════════════════════ -->
  <div>
    <div class="pub-enquiry-sticky">
      <div class="pub-enquiry-card pub-anim pub-d3">

        <!-- Card header -->
        <div class="pub-enquiry-card__head">
          <div class="pub-enquiry-card__price"><?= $priceDisp ?></div>
          <div class="pub-enquiry-card__pm">~<?= $monthlyDisp ?> estimated</div>
          <div class="pub-enquiry-card__commission">
            <span class="pub-enquiry-card__comm-label">Broker earns</span>
            <span class="pub-enquiry-card__comm-val"><?= htmlspecialchars($commRandDisp) ?></span>
          </div>
        </div>

        <div class="pub-enquiry-card__body">

          <!-- v2: Desk attribution chip (always present — desk is in URL) -->
          <?php if (!$isPlatformCar): ?>
          <div class="pub-enquiry-broker" style="margin-bottom:14px;">
            <div class="pub-enquiry-broker__av">
              <?php if ($deskRow['broker_avatar']): ?>
              <img src="<?= htmlspecialchars($deskRow['broker_avatar']) ?>"
                   alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
              <?php else: ?>
              <?= htmlspecialchars($brokerInitials) ?>
              <?php endif; ?>
            </div>
            <div>
              <div class="pub-enquiry-broker__name">
                <?= htmlspecialchars($deskRow['desk_name']) ?>
              </div>
              <div class="pub-enquiry-broker__sub">
                <?= htmlspecialchars($brokerDisplayName) ?>
              </div>
            </div>
          </div>
          <?php else: ?>
          <p style="font-size:12px;color:#94a3b8;margin:0 0 14px;">
            <i class="fa-solid fa-shop" style="margin-right:5px;"></i>
            Enquiry goes directly to the dealer
          </p>
          <?php endif; ?>

          <?php if (!$isAvailable): ?>
          <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;
                      padding:12px 14px;margin-bottom:14px;font-size:12px;color:#b45309;text-align:center;">
            <i class="fa-solid fa-circle-exclamation"></i>
            This car is <?= htmlspecialchars($car['status']) ?>. Enquiries are paused.
          </div>
          <?php else: ?>

          <div id="enquiryGlobalError"
               style="display:none;background:#fef2f2;border:1px solid #fecaca;
                      border-radius:12px;padding:10px 12px;margin-bottom:12px;
                      font-size:12px;color:#dc2626;"></div>

          <!-- Enquiry form -->
          <!-- v2: tracking_code now carries either the ?ref= code or the desk's
               own tracking code. desk_slug is also posted for fallback resolution
               in api/leads/submit.php -->
          <form id="enquiryForm" novalidate>
            <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="tracking_code" value="<?= htmlspecialchars((string)$activeTrackingCode) ?>">
            <?php if (!$isPlatformCar): ?>
            <input type="hidden" name="desk_slug"     value="<?= htmlspecialchars($deskSlug) ?>">
            <?php endif; ?>
            <input type="hidden" name="car_slug"      value="<?= htmlspecialchars($carSlug) ?>">

            <label class="pub-form-label" for="buyer_name">Your name</label>
            <input class="pub-form-input" type="text" id="buyer_name"
                   name="buyer_name" placeholder="Thabo Nkosi" autocomplete="name" required>
            <div class="pub-form-error" id="nameError"></div>

            <label class="pub-form-label" for="buyer_phone">Phone number</label>
            <input class="pub-form-input" type="tel" id="buyer_phone"
                   name="buyer_phone" placeholder="082 000 0000"
                   autocomplete="tel" required>
            <div class="pub-form-error" id="phoneError"></div>

            <label class="pub-form-label" for="buyer_email">
              Email <span style="font-weight:400;font-size:10px;color:#94a3b8;">(optional)</span>
            </label>
            <input class="pub-form-input" type="email" id="buyer_email"
                   name="buyer_email" placeholder="you@example.com" autocomplete="email">

            <label class="pub-form-label" for="buyer_intent">When are you looking to buy?</label>
            <select class="pub-form-input" id="buyer_intent" name="buyer_intent">
              <option value="within_30d">🔥 Within the next 30 days</option>
              <option value="one_to_3mo">🗓️ 1–3 months</option>
              <option value="browsing" selected>👀 Just browsing</option>
            </select>

            <label class="pub-form-label" for="buyer_message">
              Message <span style="font-weight:400;font-size:10px;color:#94a3b8;">(optional)</span>
            </label>
            <textarea class="pub-form-input" id="buyer_message"
                      name="buyer_message" rows="3"
                      placeholder="Any specific questions…"></textarea>

            <div class="pub-form-consent">
              <input type="checkbox" id="consent_given" name="consent_given" value="1">
              <label for="consent_given">
                I consent to my details being shared with the dealer and listing broker.
                <a href="/privacy" target="_blank" style="color:#0f4c9e;">Privacy Policy</a>.
              </label>
            </div>
            <div class="pub-form-error" id="consentError"></div>

            <button class="pub-form-submit" id="enquirySubmit" type="submit">
              <i class="fa-solid fa-paper-plane"></i> Send Enquiry
            </button>

            <div class="pub-form-or">or</div>

            <?php
            $waMsg = "Hi, I'm interested in the {$carTitle} listed on SalesDesk for {$priceDisp}. {$shareUrl}";
            ?>
            <a href="https://wa.me/?text=<?= urlencode($waMsg) ?>"
               target="_blank" rel="noopener" class="pub-form-whatsapp">
              <i class="fa-brands fa-whatsapp"></i> WhatsApp enquiry
            </a>
          </form>

          <div id="enquirySuccess" style="display:none;">
            <div class="pub-enquiry-success">
              <div class="pub-enquiry-success__icon">
                <i class="fa-solid fa-check"></i>
              </div>
              <div style="font-family:'Sora',sans-serif;font-size:16px;font-weight:700;
                          margin-bottom:8px;color:#15803d;">Enquiry sent!</div>
              <p style="font-size:13px;color:#64748b;line-height:1.65;">
                <?= htmlspecialchars($brokerDisplayName) ?> will be in touch soon.
              </p>
              <p style="font-size:11px;color:#94a3b8;margin-top:8px;">
                Check your email for a confirmation.
              </p>
            </div>
          </div>

          <?php endif; ?>

          <!-- Trust signals -->
          <div class="pub-enquiry-trust">
            <span><i class="fa-solid fa-lock"></i> Your details are safe</span>
            <span><i class="fa-solid fa-shield-halved"></i> POPIA compliant</span>
            <span><i class="fa-solid fa-link"></i> Attribution protected</span>
          </div>

        </div>
      </div>
    </div>
  </div><!-- /right col -->

</div><!-- /pub-detail-grid -->

<!-- Related cars -->
<?php if (!empty($relatedCars)): ?>
<section class="pub-related pub-reveal">
  <div class="pub-related__title">More from <?= htmlspecialchars($car['dealer_name']) ?></div>
  <div class="pub-related-grid">
    <?php foreach ($relatedCars as $rel):
      $relImgs  = json_decode($rel['image_urls'] ?? '[]', true) ?: [];
      $relThumb = $relImgs[0] ?? null;
      // Related car URL: use that car's first-listed desk slug
      $relUrl = '/cars-for-sale/' . htmlspecialchars($rel['rel_desk_slug']) . '/'
              . htmlspecialchars($rel['car_slug']) . '/';
      // Carry ?ref= forward if we have one (sharing broker gets credit on related clicks too)
      if ($activeTrackingCode && $activeTrackingCode !== $rel['rel_tracking_code']) {
          $relUrl .= '?ref=' . urlencode($activeTrackingCode);
      }
    ?>
    <a href="<?= $relUrl ?>" class="pub-rel-card">
      <div class="pub-rel-card__img">
        <?php if ($relThumb): ?>
        <img src="<?= htmlspecialchars($relThumb) ?>"
             alt="<?= htmlspecialchars("{$rel['year']} {$rel['make']} {$rel['model']}") ?>"
             loading="lazy">
        <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;
                    font-size:28px;color:#e2e8f0;background:#f3f4f8;">
          <i class="fa-solid fa-car-side"></i>
        </div>
        <?php endif; ?>
      </div>
      <div class="pub-rel-card__body">
        <div class="pub-rel-card__name">
          <?= htmlspecialchars("{$rel['year']} {$rel['make']} {$rel['model']}") ?>
        </div>
        <div class="pub-rel-card__price">
          R <?= number_format((float)$rel['price'], 0, '.', ' ') ?>
        </div>
        <div class="pub-rel-card__meta">
          <?= htmlspecialchars(ucfirst($rel['condition_type'])) ?>
          <?php if ($rel['mileage']): ?>
          · <?= number_format((int)$rel['mileage']) ?> km
          <?php endif; ?>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<style>
/* Two-column detail grid */
  .pub-detail-grid {
    margin-inline: clamp(16px, 4vw, 48px);
    padding-inline: clamp(12px, 2vw, 24px);  
  }

  .pub-related {
    margin-inline: clamp(16px, 4vw, 48px);
    padding-inline: clamp(12px, 2vw, 24px);   
  }

  /* ═══════════════════════════════════════════
     UX-1: Specifications / Features / History tabs
     ═══════════════════════════════════════════ */
  .pub-tabs {
    background: var(--white, #fff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: var(--r-lg, 14px);
    box-shadow: 0 2px 12px rgba(15,76,158,.06);
    margin-bottom: 24px;
    overflow: hidden;
  }

  .pub-tabs__nav {
    display: flex;
    gap: 2px;
    padding: 6px;
    background: var(--bg, #f3f4f8);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  .pub-tabs__nav::-webkit-scrollbar { display: none; }

  .pub-tabs__btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    flex: 1;
    justify-content: center;
    white-space: nowrap;
    padding: 10px 16px;
    border: none;
    background: transparent;
    border-radius: 10px;
    font-family: var(--sans, 'DM Sans', sans-serif);
    font-size: 13px;
    font-weight: 600;
    color: var(--muted, #64748b);
    cursor: pointer;
    transition: background .15s, color .15s;
  }

  .pub-tabs__btn:hover { color: var(--p, #0f4c9e); }

  .pub-tabs__btn.active {
    background: var(--white, #fff);
    color: var(--p, #0f4c9e);
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
  }

  .pub-tabs__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--p-light, #eff4ff);
    color: var(--p, #0f4c9e);
    font-size: 10px;
    font-weight: 700;
    font-family: var(--mono, monospace);
  }
  .pub-tabs__btn.active .pub-tabs__count { background: var(--p, #0f4c9e); color: #fff; }
  .pub-tabs__count--warn { background: #fef2f2; color: #dc2626; }

  .pub-tabs__panel { display: none; padding: 20px; }
  .pub-tabs__panel.active { display: block; }

  /* Feature groups */
  .pub-feat-groups {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px 28px;
  }
  .pub-feat-group__title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: var(--font-d, 'Sora', sans-serif);
    font-size: 13px;
    font-weight: 700;
    color: var(--text, #1e293b);
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border, #e2e8f0);
  }
  .pub-feat-group__title i { color: var(--p, #0f4c9e); font-size: 12px; }
  .pub-feat-group__count {
    margin-left: auto;
    font-size: 10px;
    color: var(--faint, #94a3b8);
    font-family: var(--mono, monospace);
  }
  .pub-feat-list { list-style: none; display: flex; flex-direction: column; gap: 7px; }
  .pub-feat-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 12.5px;
    color: var(--text2, #3d4663);
    line-height: 1.4;
  }
  .pub-feat-item i { color: var(--green, #15803d); font-size: 10px; margin-top: 3px; flex-shrink: 0; }

  .pub-feat-empty {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: var(--bg, #f3f4f8);
    border: 1px dashed var(--border2, #cbd5e1);
    border-radius: 12px;
    padding: 16px;
    font-size: 13px;
    color: var(--muted, #64748b);
    line-height: 1.6;
  }
  .pub-feat-empty i { color: var(--p, #0f4c9e); margin-top: 2px; }

  /* History & warranty */
  .pub-history-alert {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #991b1b;
    line-height: 1.55;
  }
  .pub-history-alert i { color: #dc2626; font-size: 16px; margin-top: 1px; flex-shrink: 0; }

  .pub-history-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 11.5px;
    color: var(--faint, #94a3b8);
    margin-top: 14px;
    line-height: 1.6;
  }
  .pub-history-note i { margin-top: 2px; flex-shrink: 0; }

  @media (max-width: 640px) {
    .pub-feat-groups { grid-template-columns: 1fr; }
    .pub-tabs__btn { padding: 10px 12px; font-size: 12px; }
    .pub-tabs__panel { padding: 16px; }
  }
</style>

<script>
(function () {
  'use strict';
  var root = document.getElementById('specTabs');
  if (!root) return;

  var btns   = Array.from(root.querySelectorAll('.pub-tabs__btn'));
  var panels = Array.from(root.querySelectorAll('.pub-tabs__panel'));

  btns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.getAttribute('data-tab');

      btns.forEach(function (b) {
        var isActive = b === btn;
        b.classList.toggle('active', isActive);
        b.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });

      panels.forEach(function (p) {
        p.classList.toggle('active', p.getAttribute('data-panel') === target);
      });
    });
  });
})();
</script>

<?php
$pageContent = ob_get_clean();
$layoutVariant = 'wide';
require_once '../../views/layout-public.php';
