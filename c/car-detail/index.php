<?php
/**
 * SalesDesk — Public Car Detail Page
 * Route: /c/{car-slug}/index.php   (or /c/{car-slug}/ via .htaccess)
 *
 * The primary buyer-facing page. Loaded when someone clicks a broker
 * share link (?ref=tracking_code) or browses directly (/c/slug/).
 *
 * Attribution flow:
 *   1. initVisitorSession()          — cookie session
 *   2. getActiveTrackingCode()       — resolve ?ref= or stored code
 *   3. recordCarView()               — log the impression
 *   4. Enquiry form → api/leads/submit.php  (existing, unchanged)
 *
 * Security: no auth required. SQL is fully parameterised. No user
 * input is interpolated into HTML without htmlspecialchars().
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/visitor.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Resolve slug from URL ─────────────────────────────────────
// Works with both /c/{slug}/index.php and Apache/Nginx rewrite to /c/{slug}/.
$slug = trim($_GET['slug'] ?? basename(dirname($_SERVER['PHP_SELF'])));
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if (!$slug) {
    http_response_code(404);
    exit('Not found.');
}

// ── Load car ──────────────────────────────────────────────────
$carStmt = $pdo->prepare("
    SELECT
        c.id, c.uuid, c.slug, c.make, c.model, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour,
        c.transmission, c.fuel_type, c.drivetrain, c.description,
        c.commission_type, c.commission_value,
        c.image_urls, c.status, c.created_at,
        d.id            AS dealer_id,
        d.company_name  AS dealer_name,
        d.slug          AS dealer_slug,
        d.verification_status AS dealer_verification,
        d.brand_focus,
        a.city          AS dealer_city,
        a.province      AS dealer_province,
        a.suburb        AS dealer_suburb,
        u.email         AS dealer_email
    FROM cars c
    JOIN dealers d  ON d.id  = c.dealer_id
    JOIN users u    ON u.id  = d.user_id
    LEFT JOIN addresses a ON a.id = d.address_id
    WHERE c.slug = ?
    LIMIT 1
");
$carStmt->execute([$slug]);
$car = $carStmt->fetch();

if (!$car) {
    http_response_code(404);
    // Clean 404 — could render a proper 404 page in production.
    exit('This listing was not found or has been removed.');
}

// ── Visitor session + attribution ─────────────────────────────
$visitor      = initVisitorSession();
$trackingCode = getActiveTrackingCode($visitor);

// ── Resolve broker from tracking code ─────────────────────────
$broker = null;
if ($trackingCode) {
    $biStmt = $pdo->prepare("
        SELECT
            bi.id AS bi_id,
            bi.tracking_code,
            bi.views,
            sd.id            AS salesdesk_id,
            sd.slug          AS desk_slug,
            sd.display_name  AS desk_name,
            sd.tagline,
            sd.logo_url,
            sd.primary_colour,
            p.first_name     AS broker_first,
            p.last_name      AS broker_last,
            p.avatar_url     AS broker_avatar
        FROM broker_inventory bi
        JOIN salesdesks sd ON sd.id = bi.salesdesk_id
        JOIN users u       ON u.id  = sd.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE bi.tracking_code = ?
          AND bi.car_id = ?
        LIMIT 1
    ");
    $biStmt->execute([$trackingCode, $car['id']]);
    $broker = $biStmt->fetch() ?: null;
}

// ── Record view ───────────────────────────────────────────────
recordCarView($visitor, (int)$car['id'], $trackingCode);

// ── Wishlist state ────────────────────────────────────────────
$isWishlisted = isCarWishlisted($visitor['id'], (int)$car['id']);

// ── Related cars (same dealer, same status, exclude current) ──
$relStmt = $pdo->prepare("
    SELECT c2.id, c2.slug, c2.make, c2.model, c2.year,
           c2.price, c2.mileage, c2.image_urls, c2.condition_type
    FROM cars c2
    WHERE c2.dealer_id = ?
      AND c2.status    = 'active'
      AND c2.id       != ?
    ORDER BY c2.created_at DESC
    LIMIT 4
");
$relStmt->execute([(int)$car['dealer_id'], (int)$car['id']]);
$relatedCars = $relStmt->fetchAll();

// ── Dealer stats (listings + leads) ──────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT c3.id)                              AS total_listings,
        COUNT(DISTINCT CASE WHEN c3.status='active' THEN c3.id END) AS active_listings,
        COUNT(DISTINCT l.id)                               AS total_leads
    FROM dealers d2
    LEFT JOIN cars c3 ON c3.dealer_id = d2.id
    LEFT JOIN leads l ON l.dealer_id = d2.id
    WHERE d2.id = ?
");
$statsStmt->execute([(int)$car['dealer_id']]);
$dealerStats = $statsStmt->fetch();

// ── Page meta ─────────────────────────────────────────────────
$images    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
$coverImg  = $images[0] ?? '';

$carTitle   = htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}");
$priceDisp  = 'R ' . number_format((float)$car['price'], 0, '.', ' ');

$monthlyEst = estimateMonthlyPayment((float)$car['price']);
$monthlyDisp = 'R ' . number_format($monthlyEst, 0, '.', ' ') . ' /mo';

$dealerLoc  = implode(', ', array_filter([
    $car['dealer_suburb'],
    $car['dealer_city'],
    $car['dealer_province'],
]));

$commDisplay = $car['commission_type'] === 'fixed'
    ? 'R ' . number_format((float)$car['commission_value'], 0, '.', ' ') . ' broker earn'
    : number_format((float)$car['commission_value'], 1) . '% broker earn';

// Compute commission in Rands for display regardless of type.
$commRand = $car['commission_type'] === 'fixed'
    ? (float)$car['commission_value']
    : round((float)$car['price'] * (float)$car['commission_value'] / 100, 2);
$commRandDisp = 'R ' . number_format($commRand, 0, '.', ' ');

// Share URL — includes tracking code if we have a broker.
$publicUrl   = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za')
               . '/c/' . htmlspecialchars($car['slug']) . '/';
$shareUrl    = $trackingCode
    ? $publicUrl . '?ref=' . htmlspecialchars($trackingCode)
    : $publicUrl;
$shareTitle  = "{$car['year']} {$car['make']} {$car['model']} — {$priceDisp}";

// Broker initials.
$brokerInitials = '';
if ($broker) {
    $brokerInitials = strtoupper(
        substr($broker['broker_first'] ?? '', 0, 1) .
        substr($broker['broker_last']  ?? '', 0, 1)
    ) ?: 'SD';
}
$brokerDisplayName = $broker
    ? trim(($broker['broker_first'] ?? '') . ' ' . ($broker['broker_last'] ?? ''))
    : 'SalesDesk';

// Condition label.
$conditionLabel = match($car['condition_type']) {
    'new'  => 'New',
    'demo' => 'Demo',
    default => 'Used',
};

// ── SEO / OG ──────────────────────────────────────────────────
$pageTitle     = "{$carTitle} — {$priceDisp} | SalesDesk";
$ogTitle       = $shareTitle;
$ogDescription = "Listed by {$car['dealer_name']}"
    . ($dealerLoc ? " · {$dealerLoc}" : '')
    . ". {$conditionLabel}, "
    . ($car['mileage'] ? number_format((int)$car['mileage']) . ' km.' : 'low km.')
    . ' View and enquire on SalesDesk.';
$ogImage       = $coverImg;
$canonicalUrl  = $publicUrl;

// Breadcrumbs.
$showBreadcrumb = true;
$breadcrumbs    = [
    ['Browse', '/c/'],
    [$car['make'], '/c/?make=' . urlencode($car['make'])],
    ["{$car['year']} {$car['make']} {$car['model']}", null],
];

// Status guard (still show page but with a sold/paused banner).
$isAvailable = ($car['status'] === 'active');

// ── CSRF token for enquiry form ───────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[CSRF_TOKEN_NAME];

// ── Build page ────────────────────────────────────────────────
ob_start();
?>

<?php if (!$isAvailable): ?>
<!-- Status banner -->
<div style="background:var(--amb-bg);border-bottom:1px solid var(--amb-b);
            padding:10px 0;text-align:center;font-size:13px;color:var(--amber);font-weight:500;">
  <i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
  This listing is currently <?= htmlspecialchars($car['status']) ?>.
  <a href="/c/" style="color:var(--p);margin-left:8px;">Browse available cars →</a>
</div>
<?php endif; ?>

<div class="pub-detail-grid pub-anim">

  <!-- ══════════════════════════════════════
       LEFT COLUMN
       ══════════════════════════════════════ -->
  <div>

    <!-- Gallery -->
    <div class="pub-gallery pub-anim pub-d1">
      <div class="pub-gallery__main" id="galleryMainWrap">
        <?php if ($coverImg): ?>
        <img src="<?= htmlspecialchars($coverImg) ?>"
             id="galleryMain"
             alt="<?= $carTitle ?>"
             loading="eager">
        <?php else: ?>
        <div class="pub-gallery__placeholder">
          <i class="fa-solid fa-car-side"></i>
          <span style="font-size:14px;color:rgba(255,255,255,.25);">No images</span>
        </div>
        <?php endif; ?>

        <!-- Overlays -->
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
        <span class="pub-gallery__count" id="galleryCount">
          1 / <?= count($images) ?>
        </span>
        <?php endif; ?>
      </div>

      <!-- Thumbnails -->
      <?php if (count($images) > 1): ?>
      <div class="pub-gallery__thumbs">
        <?php foreach ($images as $i => $url): ?>
        <div class="pub-gallery__thumb <?= $i === 0 ? 'active' : '' ?>"
             data-src="<?= htmlspecialchars($url) ?>">
          <img src="<?= htmlspecialchars($url) ?>"
               alt="<?= $carTitle ?> image <?= $i + 1 ?>"
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
          <h1 class="pub-car-header__name"><?= $carTitle ?></h1>
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

      <!-- Badges row -->
      <div class="pub-car-header__badges">
        <?php if ($car['status'] === 'active'): ?>
        <span class="pub-badge pub-badge-avail">
          <i class="fa-solid fa-circle-check"></i> Available
        </span>
        <?php else: ?>
        <span class="pub-badge pub-badge-<?= $car['status'] ?>">
          <?= ucfirst($car['status']) ?>
        </span>
        <?php endif; ?>

        <?php if ($car['dealer_verification'] === 'verified'): ?>
        <span class="pub-badge pub-badge-verified">
          <i class="fa-solid fa-circle-check"></i> Verified Dealer
        </span>
        <?php endif; ?>

        <?php if ($broker): ?>
        <span class="pub-badge pub-badge-desk">
          <i class="fa-solid fa-id-card"></i>
          Listed by <?= htmlspecialchars($broker['desk_name']) ?>
        </span>
        <?php endif; ?>

        <!-- Action buttons -->
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
        $car['transmission'] ? ['fa-gears',       $car['transmission']]       : null,
        $car['fuel_type']    ? ['fa-gas-pump',     $car['fuel_type']]          : null,
        $car['body_type']    ? ['fa-car-side',     $car['body_type']]          : null,
        $car['colour']       ? ['fa-palette',      $car['colour']]             : null,
        $car['drivetrain']   ? ['fa-arrow-rotate-right', $car['drivetrain']]   : null,
        $car['mileage']      ? ['fa-gauge',        number_format((int)$car['mileage']) . ' km'] : null,
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
            ['Body type',    $car['body_type']   ?: '—'],
            ['Colour',       $car['colour']      ?: '—'],
            ['Transmission', $car['transmission'] ?: '—'],
            ['Fuel type',    $car['fuel_type']   ?: '—'],
            ['Drivetrain',   $car['drivetrain']  ?: '—'],
            ['Mileage',      $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : '—'],
            ['Price',        $priceDisp,          true],
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
      <div style="background:var(--white);border:1px solid var(--border);
                  border-radius:var(--r-lg);padding:20px;box-shadow:var(--shadow-sm);">
        <div style="display:flex;align-items:baseline;justify-content:space-between;
                    margin-bottom:16px;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-size:11px;color:var(--faint);text-transform:uppercase;
                        letter-spacing:.05em;font-weight:700;">Estimated monthly</div>
            <div id="monthlyDisplay"
                 style="font-family:var(--font-d);font-size:26px;font-weight:800;
                        color:var(--p);letter-spacing:-.02em;margin-top:2px;">
              ~<?= $monthlyDisp ?>
            </div>
          </div>
          <div id="depositDisplay" style="font-size:12px;color:var(--faint);text-align:right;">
            20% deposit
          </div>
        </div>
        <input type="range" id="depositSlider" min="0" max="50" step="5" value="20"
               data-price="<?= (float)$car['price'] ?>"
               data-rate="13.25"
               data-term="60"
               style="width:100%;accent-color:var(--p);margin-bottom:6px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--faint);">
          <span>0% deposit</span>
          <span>50% deposit</span>
        </div>
        <p style="font-size:10px;color:var(--faint);margin-top:10px;line-height:1.5;">
          Estimate based on NCA rate ~13.25% p.a., 60 months, selected deposit.
          Contact the dealer for a formal finance quote.
        </p>
      </div>
    </div>

    <!-- Dealer info card -->
    <div class="pub-dealer-card pub-reveal">
      <div class="pub-dealer-card__header">
        <div class="pub-dealer-card__icon">
          <i class="fa-solid fa-building-user"></i>
        </div>
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


  <!-- ══════════════════════════════════════
       RIGHT COLUMN — Sticky enquiry card
       ══════════════════════════════════════ -->
  <div>
    <div class="pub-enquiry-sticky">
      <div class="pub-enquiry-card pub-anim pub-d3">

        <!-- Card header (price + commission) -->
        <div class="pub-enquiry-card__head">
          <div class="pub-enquiry-card__price"><?= $priceDisp ?></div>
          <div class="pub-enquiry-card__pm">~<?= $monthlyDisp ?> estimated</div>
          <?php if ($broker): ?>
          <div class="pub-enquiry-card__commission">
            <span class="pub-enquiry-card__comm-label">Broker earns</span>
            <span class="pub-enquiry-card__comm-val"><?= htmlspecialchars($commRandDisp) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <div class="pub-enquiry-card__body">

          <?php if ($broker): ?>
          <!-- Broker attribution chip -->
          <div class="pub-enquiry-broker">
            <div class="pub-enquiry-broker__av">
              <?php if (!empty($broker['broker_avatar'])): ?>
              <img src="<?= htmlspecialchars($broker['broker_avatar']) ?>"
                   alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
              <?php else: ?>
              <?= htmlspecialchars($brokerInitials) ?>
              <?php endif; ?>
            </div>
            <div>
              <div class="pub-enquiry-broker__name">
                <?= htmlspecialchars($broker['desk_name']) ?>
              </div>
              <div class="pub-enquiry-broker__sub">
                <?= htmlspecialchars($brokerDisplayName) ?>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Status warning (sold/paused) -->
          <?php if (!$isAvailable): ?>
          <div style="background:var(--amb-bg);border:1px solid var(--amb-b);
                      border-radius:var(--r-md);padding:12px 14px;margin-bottom:14px;
                      font-size:12px;color:var(--amber);text-align:center;">
            <i class="fa-solid fa-circle-exclamation"></i>
            This car is <?= htmlspecialchars($car['status']) ?>.
            Enquiries are paused.
          </div>
          <?php else: ?>

          <!-- Global error placeholder -->
          <div id="enquiryGlobalError"
               style="display:none;background:var(--red-bg);border:1px solid var(--red-b);
                      border-radius:var(--r-md);padding:10px 12px;margin-bottom:12px;
                      font-size:12px;color:var(--red);">
          </div>

          <!-- Enquiry form -->
          <form id="enquiryForm" novalidate>
            <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="tracking_code" value="<?= htmlspecialchars($trackingCode ?? '') ?>">

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
              Email <span style="font-weight:400;font-size:10px;color:var(--faint);">(optional)</span>
            </label>
            <input class="pub-form-input" type="email" id="buyer_email"
                   name="buyer_email" placeholder="you@example.com"
                   autocomplete="email">

            <label class="pub-form-label" for="buyer_intent">When are you looking to buy?</label>
            <select class="pub-form-input" id="buyer_intent" name="buyer_intent">
              <option value="within_30d">🔥 Within the next 30 days</option>
              <option value="one_to_3mo">🗓️ 1–3 months</option>
              <option value="browsing" selected>👀 Just browsing</option>
            </select>

            <label class="pub-form-label" for="buyer_message">
              Message <span style="font-weight:400;font-size:10px;color:var(--faint);">(optional)</span>
            </label>
            <textarea class="pub-form-input" id="buyer_message"
                      name="buyer_message" rows="3"
                      placeholder="Any specific questions or requirements…"></textarea>

            <div class="pub-form-consent">
              <input type="checkbox" id="consent_given" name="consent_given" value="1">
              <label for="consent_given">
                I consent to my details being shared with the dealer and listing broker.
                <a href="/privacy" target="_blank" style="color:var(--p);">Privacy Policy</a>.
              </label>
            </div>
            <div class="pub-form-error" id="consentError"></div>

            <button class="pub-form-submit" id="enquirySubmit" type="submit">
              <i class="fa-solid fa-paper-plane"></i> Send Enquiry
            </button>

            <div class="pub-form-or">or</div>

            <?php
            $waPhone = preg_replace('/\D/', '', $car['dealer_email'] ?? '');
            $waMsg   = "Hi, I'm interested in the {$car['year']} {$car['make']} {$car['model']} listed on SalesDesk for {$priceDisp}. {$shareUrl}";
            ?>
            <a href="https://wa.me/?text=<?= urlencode($waMsg) ?>"
               target="_blank" rel="noopener"
               class="pub-form-whatsapp">
              <i class="fa-brands fa-whatsapp"></i> WhatsApp enquiry
            </a>

          </form>

          <!-- Success state (hidden until form submitted) -->
          <div id="enquirySuccess" style="display:none;">
            <div class="pub-enquiry-success">
              <div class="pub-enquiry-success__icon">
                <i class="fa-solid fa-check"></i>
              </div>
              <div style="font-family:var(--font-d);font-size:16px;font-weight:700;
                          margin-bottom:8px;color:var(--green);">
                Enquiry sent!
              </div>
              <p style="font-size:13px;color:var(--muted);line-height:1.65;">
                <?= htmlspecialchars($broker ? $broker['desk_name'] : $car['dealer_name']) ?>
                will be in touch with you soon.
              </p>
              <p style="font-size:11px;color:var(--faint);margin-top:8px;">
                Check your email for a confirmation.
              </p>
            </div>
          </div>

          <?php endif; // isAvailable ?>

          <!-- Trust signals -->
          <div class="pub-enquiry-trust">
            <span><i class="fa-solid fa-lock"></i> Your details are safe</span>
            <span><i class="fa-solid fa-shield-halved"></i> POPIA compliant</span>
            <?php if ($broker): ?>
            <span><i class="fa-solid fa-link"></i> Commission protected</span>
            <?php endif; ?>
          </div>

        </div><!-- /card body -->
      </div><!-- /enquiry card -->
    </div><!-- /sticky -->
  </div><!-- /right col -->

</div><!-- /pub-detail-grid -->

<!-- Related cars -->
<?php if (!empty($relatedCars)): ?>
<section class="pub-related pub-reveal">
  <div class="pub-related__title">
    More from <?= htmlspecialchars($car['dealer_name']) ?>
  </div>
  <div class="pub-related-grid">
    <?php foreach ($relatedCars as $rel):
      $relImgs  = json_decode($rel['image_urls'] ?? '[]', true) ?: [];
      $relThumb = $relImgs[0] ?? null;
      $relUrl   = '/c/' . htmlspecialchars($rel['slug']) . '/'
                  . ($trackingCode ? '?ref=' . htmlspecialchars($trackingCode) : '');
    ?>
    <a href="<?= $relUrl ?>" class="pub-rel-card">
      <div class="pub-rel-card__img">
        <?php if ($relThumb): ?>
        <img src="<?= htmlspecialchars($relThumb) ?>"
             alt="<?= htmlspecialchars("{$rel['year']} {$rel['make']} {$rel['model']}") ?>"
             loading="lazy">
        <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;
                    justify-content:center;font-size:28px;color:var(--faint);
                    background:var(--bg);">
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

<?php
$pageContent = ob_get_clean();

// layoutVariant is already declared above in defaults section.
$layoutVariant = 'wide';

require_once '../../views/layout-public.php';
