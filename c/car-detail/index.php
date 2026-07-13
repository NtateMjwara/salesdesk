<?php
/**
 * SalesDesk — Public Car Detail Page  (v2)
 * Route: /c/{desk-slug}/{car-slug}/
 *        (via .htaccess → c/car-detail/index.php?desk_slug=…&car_slug=…)
 *
 * Attribution model v2:
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
$deskSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['desk_slug'] ?? '')));
$carSlug  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['car_slug']  ?? '')));

if (!$deskSlug || !$carSlug) {
    http_response_code(404);
    exit('Not found.');
}

// ── Load desk + verify car is on this desk ────────────────────
// This is the core v2 constraint: car detail is only accessible
// through a desk that has the car in its inventory.
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
    // Either desk doesn't exist, desk is inactive, or car is not on this desk.
    http_response_code(404);
    exit('This listing was not found or is no longer available on this desk.');
}

// ── Load car ──────────────────────────────────────────────────
$carStmt = $pdo->prepare("
    SELECT
        c.id, c.uuid, c.slug, c.make, c.model, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour,
        c.transmission, c.fuel_type, c.drivetrain, c.description,
        c.commission_type, c.commission_value,
        c.image_urls, c.status, c.created_at,
        d.id                    AS dealer_id,
        d.company_name          AS dealer_name,
        d.slug                  AS dealer_slug,
        d.verification_status   AS dealer_verification,
        d.brand_focus,
        a.city                  AS dealer_city,
        a.province              AS dealer_province,
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

$activeTrackingCode = $deskRow['desk_tracking_code']; // default: desk in URL

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

// ── Org membership (for desk badge) ───────────────────────────
$orgStmt = $pdo->prepare("
    SELECT o.name, o.slug, o.verification_status
    FROM organization_members om
    JOIN organizations o ON o.id = om.organization_id
    WHERE om.user_id = ? AND o.is_active = 1
    LIMIT 1
");
$orgStmt->execute([(int)$deskRow['broker_user_id']]);
$org = $orgStmt->fetch();

// ── Page meta computations ────────────────────────────────────
$images    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
$coverImg  = $images[0] ?? '';

$carTitle    = "{$car['year']} {$car['make']} {$car['model']}";
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

$brokerInitials = strtoupper(
    substr($deskRow['broker_first'] ?? '', 0, 1) .
    substr($deskRow['broker_last']  ?? '', 0, 1)
) ?: 'SD';
$brokerDisplayName = trim(($deskRow['broker_first'] ?? '') . ' ' . ($deskRow['broker_last'] ?? '')) ?: $deskRow['desk_name'];

$conditionLabel = match($car['condition_type']) {
    'new'   => 'New',
    'demo'  => 'Demo',
    default => 'Used',
};

// Canonical URL always uses desk slug in path (no ?ref=)
$siteUrl      = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$canonicalUrl = "{$siteUrl}/c/{$deskSlug}/{$carSlug}/";

// Share URL: canonical + tracking code if available
$shareUrl   = $activeTrackingCode
    ? "{$canonicalUrl}?ref={$activeTrackingCode}"
    : $canonicalUrl;
$shareTitle = "{$carTitle} — {$priceDisp}";

// ── SEO / OG ──────────────────────────────────────────────────
$pageTitle     = "{$carTitle} — {$priceDisp} | SalesDesk";
$ogTitle       = $shareTitle;
$ogDescription = "Listed by {$deskRow['desk_name']}"
    . ($dealerLoc ? " · {$dealerLoc}" : '')
    . ". {$conditionLabel}"
    . ($car['mileage'] ? ', ' . number_format((int)$car['mileage']) . ' km.' : '.')
    . ' Enquire on SalesDesk.';
$ogImage = $coverImg;

$showBreadcrumb = true;
$breadcrumbs    = [
    ['Browse',       '/c/'],
    [$car['make'],   '/c/?make=' . urlencode($car['make'])],
    ["{$carTitle}",  null],
];

$isAvailable = ($car['status'] === 'active');

// ── CSRF token ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[CSRF_TOKEN_NAME];

ob_start();
?>

<?php if (!$isAvailable): ?>
<div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 0;text-align:center;
            font-size:13px;color:#b45309;font-weight:500;">
  <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
  This listing is currently <?= htmlspecialchars($car['status']) ?>.
  <a href="/c/" style="color:#0f4c9e;margin-left:8px;">Browse available cars →</a>
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
        <!-- v2: desk attribution badge always visible -->
        <span class="pub-badge pub-badge-desk">
          <i class="fa-solid fa-id-card"></i>
          Listed by <?= htmlspecialchars($deskRow['desk_name']) ?>
        </span>
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

    <!-- Full spec table -->
    <div class="pub-spec-section pub-reveal">
      <div class="pub-spec-section__title">Full specification</div>
      <div class="pub-spec-table">
        <?php
        $specs = [
            ['Make',         $car['make']],
            ['Model',        $car['model']],
            ['Year',         $car['year']],
            ['Condition',    ucfirst($car['condition_type'])],
            ['Body type',    $car['body_type']    ?: '—'],
            ['Colour',       $car['colour']       ?: '—'],
            ['Transmission', $car['transmission'] ?: '—'],
            ['Fuel type',    $car['fuel_type']    ?: '—'],
            ['Drivetrain',   $car['drivetrain']   ?: '—'],
            ['Mileage',      $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : '—'],
            ['Price',        $priceDisp, true],
        ];
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
      <a href="/c/?dealer=<?= (int)$car['dealer_id'] ?>"
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
            <input type="hidden" name="tracking_code" value="<?= htmlspecialchars($activeTrackingCode) ?>">
            <input type="hidden" name="desk_slug"     value="<?= htmlspecialchars($deskSlug) ?>">
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
                <?= htmlspecialchars($deskRow['desk_name']) ?> will be in touch soon.
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
      $relUrl = '/c/' . htmlspecialchars($rel['rel_desk_slug']) . '/'
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
</style>

<?php
$pageContent = ob_get_clean();
$layoutVariant = 'wide';
require_once '../../views/layout-public.php';
