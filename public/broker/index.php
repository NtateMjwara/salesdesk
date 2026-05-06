<?php
/**
 * SalesDesk — Broker Public Desk Storefront
 * T4 owns this file. Route: /{broker-slug}/index.php (via .htaccess)
 *
 * Decision D-01: This is Route B — broker's curated public inventory page.
 * URL format: salesdesk.co.za/{broker-slug}/
 *
 * Each car card links to /c/{car-slug}?ref={tracking_code}
 * so attribution is preserved when buyers click through.
 *
 * No auth required. SEO-friendly server-rendered HTML.
 */

declare(strict_types=1);

// Resolve broker slug from the URL path.
// When Apache rewrites /{broker-slug}/ → /broker/{slug}/index.php
// the original request URI still carries the slug.
$requestUri  = $_SERVER['REQUEST_URI'] ?? '';
$pathParts   = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
$brokerSlug  = $pathParts[0] ?? '';

if (!$brokerSlug || !preg_match('/^[a-z0-9][a-z0-9\-]{1,59}$/', $brokerSlug)) {
    http_response_code(404);
    exit('Not found');
}

require_once '../../../includes/security.php';
require_once '../../../includes/database.php';
require_once '../../../includes/functions.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Load desk by slug ─────────────────────────────────────────
$deskStmt = $pdo->prepare("
    SELECT
        sd.id, sd.slug, sd.display_name, sd.tagline, sd.logo_url, sd.primary_colour,
        p.first_name, p.last_name, p.bio, p.avatar_url
    FROM salesdesks sd
    JOIN users u ON u.id = sd.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE sd.slug = ? AND sd.is_active = 1
    LIMIT 1
");
$deskStmt->execute([$brokerSlug]);
$desk = $deskStmt->fetch();

if (!$desk) {
    http_response_code(404);
    // Fallback render.
    $pageTitle   = 'Not Found | SalesDesk';
    $pageContent = '<div style="text-align:center;padding:3rem 1rem;"><h1 style="font-family:var(--serif);font-weight:300;">Page not found</h1></div>';
    require_once '../../../views/layout-public.php';
    exit;
}

$salesdeskId  = (int) $desk['id'];
$deskColour   = $desk['primary_colour'] ?: '#0f4c9e';
$brokerName   = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? '')) ?: $desk['display_name'];

// ── Load active inventory with tracking codes ─────────────────
$inventoryStmt = $pdo->prepare("
    SELECT
        bi.tracking_code,
        bi.views,
        c.id AS car_id,
        c.slug AS car_slug,
        c.make, c.model, c.year, c.price,
        c.mileage, c.condition_type, c.body_type, c.colour, c.transmission, c.fuel_type,
        c.commission_type, c.commission_value,
        c.image_urls,
        d.company_name AS dealer_name,
        d.verification_status AS dealer_verified,
        a.city AS dealer_city
    FROM broker_inventory bi
    JOIN cars c      ON c.id = bi.car_id
    JOIN dealers d   ON d.id = c.dealer_id
    LEFT JOIN addresses a ON a.id = d.address_id
    WHERE bi.salesdesk_id = ? AND c.status = 'active'
    ORDER BY bi.added_at DESC
");
$inventoryStmt->execute([$salesdeskId]);
$inventory = $inventoryStmt->fetchAll();

$carCount   = count($inventory);
$assetVersion = date('Ymd');

// OG metadata.
$pageTitle   = $desk['display_name'] . ' | SalesDesk';
$ogTitle     = $desk['display_name'];
$ogDesc      = $desk['tagline'] ?: ($carCount . ' car' . ($carCount !== 1 ? 's' : '') . ' available from ' . $brokerName . ' on SalesDesk.');
$ogImage     = $desk['logo_url'] ?: '';
$canonicalUrl = 'https://salesdesk.co.za/' . $desk['slug'] . '/';

ob_start();
?>

<!-- Broker hero -->
<div class="desk-hero" style="border-top:4px solid <?= htmlspecialchars($deskColour) ?>">
  <div class="desk-hero-inner">
    <?php if ($desk['avatar_url']): ?>
    <img src="<?= htmlspecialchars($desk['avatar_url']) ?>" alt=""
         class="desk-avatar">
    <?php else: ?>
    <div class="desk-avatar desk-avatar-placeholder"
         style="background:<?= htmlspecialchars($deskColour) ?>">
      <?= strtoupper(substr($brokerName, 0, 2)) ?>
    </div>
    <?php endif; ?>
    <div class="desk-hero-info">
      <h1 class="desk-name"><?= htmlspecialchars($desk['display_name']) ?></h1>
      <?php if ($desk['tagline']): ?>
      <p class="desk-tagline"><?= htmlspecialchars($desk['tagline']) ?></p>
      <?php endif; ?>
      <?php if ($desk['bio']): ?>
      <p class="desk-bio"><?= htmlspecialchars($desk['bio']) ?></p>
      <?php endif; ?>
    </div>
    <div class="desk-hero-stats">
      <div class="desk-stat">
        <div class="desk-stat-num"><?= $carCount ?></div>
        <div class="desk-stat-label">car<?= $carCount !== 1 ? 's' : '' ?> listed</div>
      </div>
    </div>
  </div>
</div>

<!-- Inventory grid -->
<?php if (empty($inventory)): ?>
<div style="text-align:center;padding:3rem 1rem;color:var(--faint);">
  <i class="fa-solid fa-car-side" style="font-size:36px;margin-bottom:12px;display:block"></i>
  <p>No cars listed yet. Check back soon!</p>
</div>
<?php else: ?>
<div class="pub-inventory-header">
  <p style="font-size:13px;color:var(--muted);">
    <?= $carCount ?> car<?= $carCount !== 1 ? 's' : '' ?> available
  </p>
</div>
<div class="pub-car-grid">
  <?php foreach ($inventory as $car):
    $imgs     = json_decode($car['image_urls'] ?? '[]', true) ?: [];
    $img      = $imgs[0] ?? null;
    $shareUrl = 'https://salesdesk.co.za/c/' . $car['car_slug'] . '?ref=' . $car['tracking_code'];
    $commRand = $car['commission_type'] === 'fixed'
      ? (float)$car['commission_value']
      : round((float)$car['price'] * ((float)$car['commission_value'] / 100), 2);
  ?>
  <a href="<?= htmlspecialchars($shareUrl) ?>" class="pub-car-card">
    <div class="pub-car-img">
      <?php if ($img): ?>
      <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($car['year'].' '.$car['make'].' '.$car['model']) ?>">
      <?php else: ?>
      <div class="pub-car-img-placeholder">
        <i class="fa-solid fa-car-side"></i>
      </div>
      <?php endif; ?>
      <?php if ($car['dealer_verified'] === 'verified'): ?>
      <span class="pub-verified-badge">
        <i class="fa-solid fa-circle-check" style="font-size:9px"></i> Verified dealer
      </span>
      <?php endif; ?>
    </div>
    <div class="pub-car-body">
      <div class="pub-car-title">
        <?= htmlspecialchars($car['year'] . ' ' . $car['make'] . ' ' . $car['model']) ?>
      </div>
      <div class="pub-car-price">R <?= number_format((float)$car['price'], 0, '.', ',') ?></div>
      <div class="pub-car-meta">
        <?php if ($car['mileage']): ?>
        <span><i class="fa-solid fa-gauge-high" style="font-size:10px"></i> <?= number_format((int)$car['mileage']) ?> km</span>
        <?php endif; ?>
        <?php if ($car['transmission']): ?>
        <span><?= htmlspecialchars($car['transmission']) ?></span>
        <?php endif; ?>
        <?php if ($car['fuel_type']): ?>
        <span><?= htmlspecialchars($car['fuel_type']) ?></span>
        <?php endif; ?>
      </div>
      <div class="pub-car-dealer">
        <?= htmlspecialchars($car['dealer_name']) ?>
        <?php if ($car['dealer_city']): ?>
        · <?= htmlspecialchars($car['dealer_city']) ?>
        <?php endif; ?>
      </div>
      <div class="pub-car-enquire" style="border-top-color:<?= htmlspecialchars($deskColour) ?>">
        Enquire →
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.desk-hero { background:var(--white); border:1px solid var(--border); border-radius:var(--r-lg); margin-bottom:1.5rem; overflow:hidden; }
.desk-hero-inner { padding:1.5rem; display:flex; align-items:flex-start; gap:1.25rem; flex-wrap:wrap; }
.desk-avatar { width:72px; height:72px; border-radius:50%; object-fit:cover; flex-shrink:0; }
.desk-avatar-placeholder { display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:700; font-family:var(--mono); color:#fff; }
.desk-hero-info { flex:1; min-width:0; }
.desk-name    { font-family:var(--serif); font-size:1.4rem; font-weight:300; margin-bottom:4px; }
.desk-tagline { font-size:14px; color:var(--muted); margin-bottom:4px; }
.desk-bio     { font-size:13px; color:var(--muted); line-height:1.6; max-width:480px; }
.desk-hero-stats { text-align:right; flex-shrink:0; }
.desk-stat-num   { font-size:1.5rem; font-weight:700; font-family:var(--mono); }
.desk-stat-label { font-size:11px; color:var(--faint); }

.pub-inventory-header { margin-bottom:.75rem; }
.pub-car-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; }

.pub-car-card { background:var(--white); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; text-decoration:none; display:block; transition:box-shadow .15s, border-color .15s; }
.pub-car-card:hover { box-shadow:var(--shadow-md); border-color:var(--border2); }
.pub-car-img { position:relative; height:160px; background:var(--bg); }
.pub-car-img img { width:100%; height:100%; object-fit:cover; }
.pub-car-img-placeholder { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:32px; color:var(--border); }
.pub-verified-badge { position:absolute; bottom:8px; left:8px; background:rgba(255,255,255,.9); border:1px solid var(--gr-b); color:var(--green); font-size:10px; font-family:var(--mono); padding:2px 7px; border-radius:9999px; }
.pub-car-body { padding:12px; }
.pub-car-title { font-size:13px; font-weight:600; color:var(--text); margin-bottom:2px; }
.pub-car-price { font-size:16px; font-weight:700; font-family:var(--mono); color:var(--text); margin-bottom:6px; }
.pub-car-meta  { display:flex; gap:8px; flex-wrap:wrap; font-size:11px; color:var(--faint); margin-bottom:4px; }
.pub-car-dealer{ font-size:11px; color:var(--muted); margin-bottom:10px; }
.pub-car-enquire { font-size:13px; font-weight:600; padding-top:10px; border-top:2px solid var(--p); color:var(--p); }
</style>

<?php
$pageContent = ob_get_clean();
require_once '../../../views/layout-public.php';
