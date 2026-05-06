<?php
/**
 * SalesDesk — Buyer-facing car link page
 * Route: /c/{car-slug}/index.php  (via .htaccess)
 * T4 owns this file.
 *
 * Decision D-01: This is Route A — the share link with tracking.
 * URL format: salesdesk.co.za/c/{car-slug}?ref={tracking_code}
 *
 * Page states (att1):
 *   1. Valid active car   → show broker-branded car page + lead form
 *   2. Stale car (sold/paused) → "no longer available" message (D-12)
 *   3. Invalid tracking code    → 404 page
 *
 * Attribution is resolved server-side — broker_id is never exposed
 * in HTML source or client-side JS.
 */

declare(strict_types=1);

require_once '../../../includes/security.php';
require_once '../../../includes/database.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/csrf.php';
require_once '../../../includes/session.php';

applyCachePolicy('public');

// Start session for CSRF (graceful — this is a public page).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo          = Database::getInstance();
$trackingCode = trim($_GET['ref'] ?? '');
$assetVersion = date('Ymd');

// ── Resolve tracking code ─────────────────────────────────────
if (!$trackingCode || !preg_match('/^[a-f0-9]{32}$/i', $trackingCode)) {
    // No ref param — try to resolve from car-slug to show a direct page
    // (Route B style fallback — no broker attribution).
    _render404();
}

$trackStmt = $pdo->prepare("
    SELECT
        bi.tracking_code,
        bi.views,
        c.id            AS car_id,
        c.uuid          AS car_uuid,
        c.slug          AS car_slug,
        c.make,
        c.model,
        c.year,
        c.price,
        c.mileage,
        c.condition_type,
        c.body_type,
        c.colour,
        c.transmission,
        c.fuel_type,
        c.description,
        c.commission_type,
        c.commission_value,
        c.image_urls,
        c.status        AS car_status,
        d.id            AS dealer_id,
        d.company_name  AS dealer_name,
        d.slug          AS dealer_slug,
        d.verification_status AS dealer_verification,
        sd.id           AS salesdesk_id,
        sd.display_name AS desk_name,
        sd.slug         AS desk_slug,
        sd.tagline      AS desk_tagline,
        sd.logo_url     AS desk_logo,
        sd.primary_colour AS desk_colour,
        p.first_name    AS broker_first,
        p.last_name     AS broker_last,
        p.avatar_url    AS broker_avatar,
        a.city          AS dealer_city,
        a.province      AS dealer_province
    FROM broker_inventory bi
    JOIN cars c         ON c.id   = bi.car_id
    JOIN dealers d      ON d.id   = c.dealer_id
    JOIN salesdesks sd  ON sd.id  = bi.salesdesk_id
    JOIN users u        ON u.id   = sd.user_id
    LEFT JOIN profiles p  ON p.user_id = u.id
    LEFT JOIN addresses a ON a.id = d.address_id
    WHERE bi.tracking_code = ?
    LIMIT 1
");
$trackStmt->execute([$trackingCode]);
$data = $trackStmt->fetch();

if (!$data) {
    // D-12: tracking_code does not exist → 404
    _render404();
}

// D-12: car no longer available
$carStatus = $data['car_status'];
if ($carStatus !== 'active') {
    _renderStale($data);
}

// Parse image URLs.
$images   = json_decode($data['image_urls'] ?? '[]', true) ?: [];
$firstImg = $images[0] ?? null;

// Compute commission in Rands for display.
$commRand = $data['commission_type'] === 'fixed'
    ? (float) $data['commission_value']
    : round((float)$data['price'] * ((float)$data['commission_value'] / 100), 2);

// Build OG data.
$carLabel   = $data['year'] . ' ' . $data['make'] . ' ' . $data['model'];
$priceLabel = 'R ' . number_format((float)$data['price'], 0, '.', ',');
$ogTitle    = $carLabel . ' — ' . $priceLabel;
$ogDesc     = 'Listed by ' . $data['desk_name'] . '. Contact ' . $data['dealer_name'] . ' via SalesDesk.';
$canonUrl   = 'https://salesdesk.co.za/c/' . $data['car_slug'] . '?ref=' . urlencode($trackingCode);

$pageTitle    = $ogTitle . ' | SalesDesk';
$ogImage      = $firstImg ?: '';
$canonicalUrl = $canonUrl;

// Broker display name.
$brokerName = trim(($data['broker_first'] ?? '') . ' ' . ($data['broker_last'] ?? '')) ?: $data['desk_name'];
$deskColour = $data['desk_colour'] ?: '#0f4c9e';

// CSRF token for the lead form.
$csrf = generateCSRFToken();

// ── Render lead form page ─────────────────────────────────────
ob_start();
?>
<!-- Broker brand bar -->
<div class="broker-brand" style="border-left: 3px solid <?= htmlspecialchars($deskColour) ?>">
  <?php if ($data['broker_avatar']): ?>
  <img src="<?= htmlspecialchars($data['broker_avatar']) ?>" alt=""
       style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0">
  <?php else: ?>
  <div class="broker-avatar-placeholder" style="background:<?= htmlspecialchars($deskColour) ?>">
    <?= strtoupper(substr($brokerName, 0, 2)) ?>
  </div>
  <?php endif; ?>
  <div>
    <div class="broker-desk-name"><?= htmlspecialchars($data['desk_name']) ?></div>
    <?php if ($data['desk_tagline']): ?>
    <div class="broker-desk-tagline"><?= htmlspecialchars($data['desk_tagline']) ?></div>
    <?php endif; ?>
  </div>
  <div style="margin-left:auto;">
    <a href="/<?= htmlspecialchars($data['desk_slug']) ?>/"
       style="font-size:11px;color:var(--muted);text-decoration:none;">
      View all cars →
    </a>
  </div>
</div>

<!-- Car images -->
<?php if (!empty($images)): ?>
<div class="car-images">
  <div class="car-image-main">
    <img src="<?= htmlspecialchars($images[0]) ?>"
         alt="<?= htmlspecialchars($carLabel) ?>"
         id="mainCarImage">
  </div>
  <?php if (count($images) > 1): ?>
  <div class="car-image-thumbs">
    <?php foreach ($images as $i => $imgUrl): ?>
    <img src="<?= htmlspecialchars($imgUrl) ?>" alt=""
         class="thumb <?= $i === 0 ? 'active' : '' ?>"
         onclick="showImage(<?= $i ?>, '<?= htmlspecialchars(addslashes($imgUrl)) ?>')">
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="car-image-placeholder">
  <i class="fa-solid fa-car-side"></i>
  <span>No photos yet</span>
</div>
<?php endif; ?>

<!-- Car info -->
<div class="car-info-block">
  <div class="car-price-row">
    <div class="car-price"><?= $priceLabel ?></div>
    <?php if ($data['dealer_verification'] === 'verified'): ?>
    <span class="badge badge-verified"><i class="fa-solid fa-circle-check" style="font-size:9px"></i> Verified Dealer</span>
    <?php endif; ?>
  </div>
  <h1 class="car-title"><?= htmlspecialchars($carLabel) ?></h1>
  <p class="dealer-byline">
    Listed by <a href="#" style="color:var(--p)"><?= htmlspecialchars($data['dealer_name']) ?></a>
    <?php if ($data['dealer_city']): ?>
    · <?= htmlspecialchars($data['dealer_city']) ?>
    <?php endif; ?>
  </p>

  <!-- Spec grid -->
  <div class="spec-grid">
    <?php
    $specs = [
      ['fa-gauge-high',    'Mileage',      $data['mileage'] ? number_format((int)$data['mileage']) . ' km' : null],
      ['fa-gas-pump',      'Fuel',          $data['fuel_type']],
      ['fa-gears',         'Transmission',  $data['transmission']],
      ['fa-car',           'Body',          $data['body_type']],
      ['fa-palette',       'Colour',        $data['colour']],
      ['fa-star',          'Condition',     ucfirst($data['condition_type'])],
    ];
    foreach ($specs as [$icon, $label, $value]):
      if (!$value) continue;
    ?>
    <div class="spec-item">
      <span class="spec-icon"><i class="fa-solid <?= $icon ?>"></i></span>
      <span class="spec-label"><?= $label ?></span>
      <span class="spec-value"><?= htmlspecialchars($value) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($data['description']): ?>
  <div class="car-description">
    <h3>About this car</h3>
    <p><?= nl2br(htmlspecialchars($data['description'])) ?></p>
  </div>
  <?php endif; ?>

  <!-- Commission info for brokers (only shown if logged in as broker) -->
  <?php /* Commission details not shown to public buyers */ ?>
</div>

<!-- Lead form -->
<div class="lead-form-block" id="leadForm">
  <div class="lead-form-header">
    <h2>Enquire about this car</h2>
    <p>Fill in your details and <?= htmlspecialchars($data['dealer_name']) ?> will be in touch.</p>
  </div>

  <div class="lead-form-inner" id="leadFormInner">
    <div class="fgroup">
      <label class="flabel" for="buyerName">Your name</label>
      <input class="finput" type="text" id="buyerName" name="buyer_name"
             required maxlength="120" autocomplete="name" placeholder="Your full name">
    </div>

    <div class="fgroup">
      <label class="flabel" for="buyerPhone">Mobile number</label>
      <input class="finput" type="tel" id="buyerPhone" name="buyer_phone"
             required maxlength="20" autocomplete="tel" placeholder="e.g. 082 000 0000">
    </div>

    <div class="fgroup">
      <label class="flabel" for="buyerEmail">
        Email <span class="flabel-opt">(optional — for written confirmation)</span>
      </label>
      <input class="finput" type="email" id="buyerEmail" name="buyer_email"
             maxlength="255" autocomplete="email" placeholder="you@example.com">
    </div>

    <div class="fgroup">
      <label class="flabel">When are you looking to buy?</label>
      <div class="intent-grid">
        <label class="intent-label">
          <input type="radio" name="buyer_intent" value="within_30d" class="intent-radio" checked>
          <div class="intent-card">
            <span class="intent-icon">🔥</span>
            <span>Within 30 days</span>
          </div>
        </label>
        <label class="intent-label">
          <input type="radio" name="buyer_intent" value="one_to_3mo" class="intent-radio">
          <div class="intent-card">
            <span class="intent-icon">🗓️</span>
            <span>1–3 months</span>
          </div>
        </label>
        <label class="intent-label">
          <input type="radio" name="buyer_intent" value="browsing" class="intent-radio">
          <div class="intent-card">
            <span class="intent-icon">👀</span>
            <span>Just browsing</span>
          </div>
        </label>
      </div>
    </div>

    <div class="fgroup">
      <label class="flabel" for="buyerMessage">
        Message <span class="flabel-opt">(optional)</span>
      </label>
      <textarea class="finput" id="buyerMessage" name="buyer_message"
                maxlength="1000" rows="3"
                placeholder="Any questions, e.g. availability, test drive, finance options…"></textarea>
    </div>

    <!-- att4: POPIA consent — required -->
    <div class="consent-block">
      <label class="consent-label">
        <input type="checkbox" id="consentCheck" required>
        <span>
          By submitting this form, I consent to <strong><?= htmlspecialchars($data['dealer_name']) ?></strong>
          contacting me regarding this vehicle. My information will not be shared with third parties.
          <a href="/privacy" style="color:var(--p)">Privacy Policy</a>
        </span>
      </label>
    </div>

    <div id="leadFormError" class="alert alert-error" style="display:none">
      <i class="fa-solid fa-circle-exclamation alert-icon"></i>
      <span id="leadFormErrorMsg"></span>
    </div>

    <button class="btn-submit" id="leadSubmitBtn" type="button" onclick="submitLead()">
      <i class="fa-solid fa-paper-plane"></i>
      Send enquiry
    </button>
  </div>

  <!-- Success state (replaces form) -->
  <div class="lead-success" id="leadSuccess" style="display:none">
    <div class="lead-success-icon">
      <i class="fa-solid fa-circle-check"></i>
    </div>
    <h3>Enquiry sent!</h3>
    <p>
      <strong><?= htmlspecialchars($data['dealer_name']) ?></strong> has received your details
      for the <?= htmlspecialchars($carLabel) ?> and will be in touch shortly.
    </p>
    <p style="font-size:12px;color:var(--faint);margin-top:8px;">
      Check your email for a confirmation if you provided one.
    </p>
  </div>
</div>

<style>
/* ── Page-specific styles ─────────────────────────────────── */
.broker-brand {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 1.25rem;
}
.broker-avatar-placeholder {
  width: 38px; height: 38px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: #fff; font-family: var(--mono);
  flex-shrink: 0;
}
.broker-desk-name    { font-size: 13px; font-weight: 600; color: var(--text); }
.broker-desk-tagline { font-size: 11px; color: var(--muted); margin-top: 1px; }

/* Car images */
.car-images { margin-bottom: 1.25rem; }
.car-image-main img {
  width: 100%; height: 300px;
  object-fit: cover;
  border-radius: var(--r-lg);
  border: 1px solid var(--border);
}
.car-image-thumbs {
  display: flex; gap: 8px; margin-top: 8px; overflow-x: auto; padding-bottom: 4px;
}
.car-image-thumbs .thumb {
  width: 72px; height: 52px;
  object-fit: cover;
  border-radius: var(--r-sm);
  border: 2px solid transparent;
  cursor: pointer;
  flex-shrink: 0;
  transition: border-color .15s;
}
.car-image-thumbs .thumb.active { border-color: var(--p); }
.car-image-thumbs .thumb:hover  { border-color: var(--muted); }
.car-image-placeholder {
  width: 100%; height: 200px;
  background: var(--bg); border: 1px dashed var(--border2);
  border-radius: var(--r-lg);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  color: var(--faint); font-size: 32px; gap: 8px;
  margin-bottom: 1.25rem;
}
.car-image-placeholder span { font-size: 13px; }

/* Car info */
.car-info-block {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1.25rem;
  margin-bottom: 1.25rem;
}
.car-price-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
.car-price     { font-size: 1.75rem; font-weight: 700; font-family: var(--mono); color: var(--text); }
.car-title     { font-size: 1.1rem; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.dealer-byline { font-size: 12px; color: var(--muted); margin-bottom: 1rem; }

.spec-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 1.1rem;
}
.spec-item {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 10px;
  background: var(--bg); border: 1px solid var(--border);
  border-radius: var(--r-sm);
  font-size: 12px;
}
.spec-icon  { color: var(--muted); width: 14px; text-align: center; flex-shrink: 0; }
.spec-label { color: var(--faint); }
.spec-value { font-weight: 600; color: var(--text); margin-left: auto; }

.car-description h3 { font-size: 13px; font-weight: 600; margin-bottom: 5px; }
.car-description p  { font-size: 13px; color: var(--text2); line-height: 1.65; }

/* Lead form */
.lead-form-block {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  margin-bottom: 1.25rem;
}
.lead-form-header {
  padding: 1rem 1.25rem .9rem;
  border-bottom: 1px solid var(--border);
  background: var(--p-light);
}
.lead-form-header h2 { font-size: 1rem; font-weight: 700; color: var(--p-dark); margin-bottom: 2px; }
.lead-form-header p  { font-size: 12px; color: var(--p-dark); opacity: .75; }
.lead-form-inner { padding: 1.25rem; }

/* Intent selector */
.intent-grid {
  display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;
  margin-top: 4px;
}
.intent-label  { cursor: pointer; }
.intent-radio  { display: none; }
.intent-card {
  padding: 10px 8px; border: 1px solid var(--border);
  border-radius: var(--r-md);
  background: var(--bg);
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  font-size: 11px; color: var(--muted); text-align: center;
  transition: border-color .15s, background .15s;
}
.intent-radio:checked + .intent-card {
  border-color: var(--p);
  background: var(--p-light);
  color: var(--p);
}
.intent-icon { font-size: 18px; }

/* Consent */
.consent-block {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 12px 14px;
  margin-bottom: 1rem;
}
.consent-label {
  display: flex; gap: 10px; align-items: flex-start;
  font-size: 12px; color: var(--muted); line-height: 1.55; cursor: pointer;
}
.consent-label input[type="checkbox"] { flex-shrink: 0; margin-top: 2px; }

/* Submit button */
.btn-submit {
  width: 100%; padding: 13px;
  border-radius: var(--r-md);
  background: var(--p); color: #fff;
  border: none; font-size: 14px; font-weight: 600;
  font-family: var(--sans); cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: background .18s;
}
.btn-submit:hover:not(:disabled) { background: var(--p-dark); }
.btn-submit:disabled { opacity: .5; cursor: not-allowed; }

/* Success */
.lead-success { padding: 2rem 1.25rem; text-align: center; }
.lead-success-icon {
  font-size: 40px; color: var(--green); margin-bottom: 1rem;
}
.lead-success h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: 8px; }
.lead-success p  { font-size: 13px; color: var(--muted); line-height: 1.65; }
</style>

<script>
var trackingCode = '<?= htmlspecialchars($trackingCode) ?>';

// Image gallery.
function showImage(idx, url) {
  document.getElementById('mainCarImage').src = url;
  document.querySelectorAll('.thumb').forEach(function (t, i) {
    t.classList.toggle('active', i === idx);
  });
}

// Lead form submission.
async function submitLead() {
  var name     = document.getElementById('buyerName').value.trim();
  var phone    = document.getElementById('buyerPhone').value.trim();
  var email    = document.getElementById('buyerEmail').value.trim();
  var message  = document.getElementById('buyerMessage').value.trim();
  var consent  = document.getElementById('consentCheck').checked;
  var intent   = (document.querySelector('input[name="buyer_intent"]:checked') || {}).value || 'browsing';
  var errorEl  = document.getElementById('leadFormError');
  var errorMsg = document.getElementById('leadFormErrorMsg');
  var btn      = document.getElementById('leadSubmitBtn');

  // Client-side validation.
  function showErr(msg) {
    errorMsg.textContent = msg;
    errorEl.style.display = 'flex';
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send enquiry';
  }

  if (!name)    return showErr('Please enter your name.');
  if (!phone)   return showErr('Please enter your mobile number.');
  if (!consent) return showErr('Please tick the consent checkbox to proceed.');

  errorEl.style.display = 'none';
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sending…';

  try {
    var resp = await fetch('/api/leads/submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        tracking_code: trackingCode,
        buyer_name:    name,
        buyer_phone:   phone,
        buyer_email:   email,
        buyer_intent:  intent,
        buyer_message: message,
        consent_given: '1',
      }),
    });
    var data = await resp.json();

    if (data.success) {
      document.getElementById('leadFormInner').style.display = 'none';
      document.getElementById('leadSuccess').style.display  = 'block';
      document.querySelector('.lead-form-header').style.display = 'none';
      return;
    }

    if (data.duplicate) {
      document.getElementById('leadFormInner').style.display = 'none';
      document.getElementById('leadSuccess').style.display  = 'block';
      document.querySelector('.lead-success h3').textContent = 'We already have your details!';
      document.querySelector('.lead-success p').textContent  =
        'We already have your enquiry logged. ' + (data.dealer_name || 'The dealer') + ' will be in touch soon.';
      document.querySelector('.lead-form-header').style.display = 'none';
      return;
    }

    if (data.error) {
      return showErr(data.error);
    }

    showErr('Something went wrong. Please try again.');
  } catch (err) {
    showErr('Connection error. Please check your internet and try again.');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send enquiry';
  }
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../../views/layout-public.php';

// ── Helper functions ──────────────────────────────────────────

function _render404(): never
{
    global $assetVersion;
    http_response_code(404);
    $pageTitle   = 'Listing not found | SalesDesk';
    $pageContent = <<<HTML
<div style="text-align:center;padding:3rem 1rem;">
  <div style="font-size:48px;margin-bottom:1rem;color:var(--border);">
    <i class="fa-solid fa-link-slash"></i>
  </div>
  <h1 style="font-family:var(--serif);font-size:1.4rem;font-weight:300;margin-bottom:.75rem;">
    Listing <em style="font-style:italic;">not found</em>
  </h1>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.65;">
    This link may have expired or is no longer active.
  </p>
  <a href="/" style="font-size:13px;color:var(--p);">Back to SalesDesk →</a>
</div>
HTML;
    require_once '../../../views/layout-public.php';
    exit;
}

function _renderStale(array $data): never
{
    global $assetVersion;
    http_response_code(200);
    $dealerName  = htmlspecialchars($data['dealer_name']);
    $carLabel    = htmlspecialchars($data['year'] . ' ' . $data['make'] . ' ' . $data['model']);
    $dealerSlug  = htmlspecialchars($data['dealer_slug']);
    $pageTitle   = $carLabel . ' — No Longer Available | SalesDesk';
    $pageContent = <<<HTML
<div style="text-align:center;padding:3rem 1rem;">
  <div style="font-size:48px;margin-bottom:1rem;color:var(--faint);">
    <i class="fa-solid fa-car-side"></i>
  </div>
  <h1 style="font-family:var(--serif);font-size:1.4rem;font-weight:300;margin-bottom:.75rem;">
    This car is <em style="font-style:italic;">no longer available</em>
  </h1>
  <p style="font-size:13px;color:var(--muted);margin-bottom:.5rem;line-height:1.65;">
    The {$carLabel} has been sold or removed from listing.
  </p>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.75rem;line-height:1.65;">
    <strong>{$dealerName}</strong> may have other great options available.
  </p>
  <a href="/{$dealerSlug}/"
     style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;
            background:var(--p);color:#fff;border-radius:var(--r-md);
            font-size:14px;font-weight:600;text-decoration:none;">
    <i class="fa-solid fa-building"></i>
    See more from {$dealerName}
  </a>
</div>
HTML;
    require_once '../../../views/layout-public.php';
    exit;
}
