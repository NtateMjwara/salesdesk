<?php
/**
 * SalesDesk — Broker Public Storefront  (v2)
 * Route: /{broker-slug}/   (via .htaccess → broker/index.php?slug=…)
 *
 * Attribution model v2:
 *   All car links from the storefront use /c/{desk-slug}/{car-slug}/
 *   so the desk is baked into every URL. ?ref={tracking_code} is
 *   appended so externally shared storefront links still carry the
 *   broker's tracking code for analytics.
 *
 * No auth required. SQL fully parameterised.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── Resolve slug ──────────────────────────────────────────────
$slug = trim($_GET['slug'] ?? basename(dirname($_SERVER['PHP_SELF'])));
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if (!$slug) {
    http_response_code(404);
    exit('Not found.');
}

// ── Load salesdesk + broker profile ──────────────────────────
$deskStmt = $pdo->prepare("
    SELECT
        sd.id, sd.uuid, sd.slug, sd.display_name, sd.tagline,
        sd.logo_url, sd.primary_colour, sd.is_active,
        u.id            AS user_id,
        p.first_name, p.last_name, p.avatar_url, p.bio, p.phone,
        a.city, a.province, a.suburb
    FROM salesdesks sd
    JOIN users u         ON u.id  = sd.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN addresses a ON a.id = p.address_id
    WHERE sd.slug = ? AND sd.is_active = 1
    LIMIT 1
");
$deskStmt->execute([$slug]);
$desk = $deskStmt->fetch();

if (!$desk) {
    http_response_code(404);
    exit('This SalesDesk was not found or is no longer active.');
}

$salesdeskId = (int) $desk['id'];

// ── Visitor session ───────────────────────────────────────────
$visitor = initVisitorSession();

// ── Load broker's active inventory ───────────────────────────
$invStmt = $pdo->prepare("
    SELECT
        c.id, c.slug AS car_slug, c.make, c.model, c.year,
        c.price, c.mileage, c.condition_type, c.body_type,
        c.colour, c.transmission, c.fuel_type,
        c.commission_type, c.commission_value,
        c.image_urls, c.status,
        bi.tracking_code, bi.views AS desk_views, bi.added_at,
        d.company_name  AS dealer_name,
        d.verification_status AS dealer_verified,
        da.city         AS dealer_city,
        da.province     AS dealer_province,
        (SELECT COUNT(*) FROM leads l
         WHERE l.salesdesk_id = ? AND l.car_id = c.id) AS leads_from_desk
    FROM broker_inventory bi
    JOIN cars c    ON c.id  = bi.car_id
    JOIN dealers d ON d.id  = c.dealer_id
    LEFT JOIN addresses da ON da.id = d.address_id
    WHERE bi.salesdesk_id = ?
      AND c.status = 'active'
    ORDER BY bi.added_at DESC
");
$invStmt->execute([$salesdeskId, $salesdeskId]);
$inventory = $invStmt->fetchAll();

// ── Desk stats ─────────────────────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT bi.id)                                       AS cars_on_desk,
        COUNT(DISTINCT l.id)                                        AS total_leads,
        COUNT(DISTINCT CASE WHEN l.status='closed' THEN l.id END)   AS deals_closed,
        COALESCE(SUM(bi.views), 0)                                  AS total_views
    FROM salesdesks sd
    LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
    LEFT JOIN cars c  ON c.id = bi.car_id AND c.status = 'active'
    LEFT JOIN leads l ON l.salesdesk_id = sd.id
    WHERE sd.id = ?
");
$statsStmt->execute([$salesdeskId]);
$stats = $statsStmt->fetch();

// ── Org membership ─────────────────────────────────────────────
$orgStmt = $pdo->prepare("
    SELECT o.name, o.slug, o.verification_status
    FROM organization_members om
    JOIN organizations o ON o.id = om.organization_id
    WHERE om.user_id = ? AND o.is_active = 1
    LIMIT 1
");
$orgStmt->execute([(int)$desk['user_id']]);
$org = $orgStmt->fetch();

// ── Wishlist state ─────────────────────────────────────────────
$wishlistedIds = [];
if (!empty($inventory)) {
    $wishlistedIds = getWishlistCarIds($visitor['id']);
}

// ── Page meta ──────────────────────────────────────────────────
$brokerName     = trim(($desk['first_name'] ?? '') . ' ' . ($desk['last_name'] ?? ''))
                  ?: $desk['display_name'];
$brokerInitials = strtoupper(
    substr($desk['first_name'] ?? '', 0, 1) . substr($desk['last_name'] ?? '', 0, 1)
) ?: 'SD';
$location = implode(', ', array_filter([$desk['suburb'], $desk['city'], $desk['province']]));

$siteUrl       = defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za';
$pageTitle     = htmlspecialchars($desk['display_name']) . ' | SalesDesk';
$ogTitle       = $desk['display_name'] . ' — Car Broker on SalesDesk';
$ogDescription = ($desk['tagline'] ?: 'Browse ' . count($inventory) . ' cars listed by '
    . $brokerName . ' on SalesDesk South Africa.');
$ogImage       = $desk['logo_url'] ?: '';
$canonicalUrl  = $siteUrl . '/' . $desk['slug'] . '/';
$layoutVariant  = 'narrow';
$showBreadcrumb = false;

$shareUrl   = $canonicalUrl;
$shareTitle = $desk['display_name'] . ' — Car Broker on SalesDesk';

ob_start();
?>

<!-- ── Broker hero ───────────────────────────────────────────── -->
<div class="pub-broker-hero pub-anim">

  <div class="pub-broker-hero__avatar">
    <?php if ($desk['avatar_url']): ?>
    <img src="<?= htmlspecialchars($desk['avatar_url']) ?>"
         alt="<?= htmlspecialchars($brokerName) ?>"
         style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
    <?php else: ?>
    <?= htmlspecialchars($brokerInitials) ?>
    <?php endif; ?>
  </div>

  <div class="pub-broker-hero__name"><?= htmlspecialchars($desk['display_name']) ?></div>

  <?php if ($desk['tagline']): ?>
  <div class="pub-broker-hero__tagline"><?= htmlspecialchars($desk['tagline']) ?></div>
  <?php elseif ($desk['bio']): ?>
  <div class="pub-broker-hero__tagline">
    <?= htmlspecialchars(mb_strimwidth($desk['bio'], 0, 120, '…')) ?>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;position:relative;z-index:1;">
    <?php if ($location): ?>
    <span style="font-size:11px;color:rgba(255,255,255,.55);display:flex;align-items:center;gap:4px;">
      <i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($location) ?>
    </span>
    <?php endif; ?>
    <?php if ($org && $org['verification_status'] === 'verified'): ?>
    <span style="font-size:11px;background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);
                 border:1px solid rgba(255,255,255,.2);border-radius:var(--r-full);padding:2px 10px;">
      <i class="fa-solid fa-building" style="font-size:9px;margin-right:3px;"></i>
      <?= htmlspecialchars($org['name']) ?>
    </span>
    <?php endif; ?>
  </div>

  <div class="pub-broker-hero__stats">
    <div>
      <div class="pub-broker-hero__stat-num"><?= (int)($stats['cars_on_desk'] ?? 0) ?></div>
      <div class="pub-broker-hero__stat-lbl">Cars listed</div>
    </div>
    <div>
      <div class="pub-broker-hero__stat-num"><?= number_format((int)($stats['total_views'] ?? 0)) ?></div>
      <div class="pub-broker-hero__stat-lbl">Total views</div>
    </div>
    <div>
      <div class="pub-broker-hero__stat-num"><?= (int)($stats['deals_closed'] ?? 0) ?></div>
      <div class="pub-broker-hero__stat-lbl">Deals closed</div>
    </div>
  </div>

  <div style="position:absolute;top:20px;right:20px;z-index:2;">
    <button onclick="openShareSheet()" type="button"
            style="width:36px;height:36px;border-radius:50%;
                   background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
                   color:#fff;display:flex;align-items:center;justify-content:center;
                   cursor:pointer;font-size:14px;transition:background .18s;">
      <i class="fa-solid fa-share-nodes"></i>
    </button>
  </div>

</div>

<!-- ── Contact strip ──────────────────────────────────────────── -->
<?php if ($desk['phone']): ?>
<div style="background:white;border:1px solid var(--border);border-radius:var(--r-lg);
            padding:14px 18px;margin-bottom:1.5rem;display:flex;align-items:center;
            justify-content:space-between;gap:12px;flex-wrap:wrap;
            box-shadow:var(--shadow-sm);" class="pub-anim pub-d1">
  <div style="display:flex;align-items:center;gap:10px;">
    <div style="width:36px;height:36px;border-radius:50%;background:var(--p-light);
                color:var(--p);display:flex;align-items:center;justify-content:center;">
      <i class="fa-solid fa-user-tie" style="font-size:13px;"></i>
    </div>
    <div>
      <div style="font-size:13px;font-weight:600;color:var(--text);"><?= htmlspecialchars($brokerName) ?></div>
      <div style="font-size:11px;color:var(--faint);">Independent auto broker</div>
    </div>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $desk['phone'])) ?>"
       class="pub-btn pub-btn-ghost" style="padding:8px 16px;font-size:12px;">
      <i class="fa-solid fa-phone"></i> Call
    </a>
    <a href="https://wa.me/27<?= ltrim(preg_replace('/\D/', '', $desk['phone']), '0') ?>"
       target="_blank" rel="noopener"
       style="display:inline-flex;align-items:center;gap:7px;padding:8px 16px;
              background:#25d366;color:#fff;border-radius:var(--r-md);font-size:12px;
              font-weight:600;font-family:var(--sans);text-decoration:none;">
      <i class="fa-brands fa-whatsapp"></i> WhatsApp
    </a>
  </div>
</div>
<?php endif; ?>

<!-- ── Inventory ──────────────────────────────────────────────── -->
<div style="margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;
            gap:10px;flex-wrap:wrap;" class="pub-anim pub-d2">
  <div>
    <span style="font-family:var(--font-d);font-size:16px;font-weight:700;">Available cars</span>
    <span style="font-size:13px;color:var(--faint);margin-left:8px;">
      <?= count($inventory) ?> listing<?= count($inventory) !== 1 ? 's' : '' ?>
    </span>
  </div>
  <a href="/c/" style="font-size:12px;color:var(--p);text-decoration:none;">Browse all cars →</a>
</div>

<?php if (empty($inventory)): ?>
<div style="text-align:center;padding:3rem 1rem;color:var(--faint);
            background:white;border:1px solid var(--border);border-radius:var(--r-lg);">
  <i class="fa-solid fa-car" style="font-size:36px;display:block;margin-bottom:12px;color:var(--border);"></i>
  <div style="font-family:var(--font-d);font-size:16px;font-weight:700;color:var(--text);margin-bottom:6px;">
    No listings yet
  </div>
  <div style="font-size:13px;">
    Check back soon — <?= htmlspecialchars($brokerName) ?> is adding cars to their desk.
  </div>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;"
     id="storeInventory">
  <?php foreach ($inventory as $car):
    $imgs    = json_decode($car['image_urls'] ?? '[]', true) ?: [];
    $thumb   = $imgs[0] ?? null;

    // v2: URL uses desk slug in path + tracking code as ?ref=
    // This means every link from a broker's storefront is fully attributed:
    //   - desk_slug in path → canonical desk attribution
    //   - tracking_code as ref → analytics + honours the specific share
    $carUrl  = '/c/' . htmlspecialchars($desk['slug']) . '/'
             . htmlspecialchars($car['car_slug']) . '/'
             . '?ref=' . urlencode($car['tracking_code']);

    $priceStr = 'R ' . number_format((float)$car['price'], 0, '.', ' ');
    $isWl     = in_array((int)$car['id'], $wishlistedIds, true);

    $commRand = $car['commission_type'] === 'fixed'
        ? (float)$car['commission_value']
        : round((float)$car['price'] * (float)$car['commission_value'] / 100, 2);

    $metaParts = array_filter([
        ucfirst($car['condition_type']),
        $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : null,
        $car['transmission'],
    ]);
    $dealerLoc = implode(', ', array_filter([$car['dealer_city'], $car['dealer_province']]));

    // Share URL for this specific car from this desk
    $carShareUrl   = $siteUrl . '/c/' . htmlspecialchars($desk['slug']) . '/'
                   . htmlspecialchars($car['car_slug']) . '/'
                   . '?ref=' . urlencode($car['tracking_code']);
    $carShareTitle = "{$car['year']} {$car['make']} {$car['model']} — {$priceStr}";
    $waShareText   = "{$carShareTitle}: {$carShareUrl}";
  ?>
  <div class="pub-browse-card pub-reveal" style="position:relative;">

    <!-- Wishlist button -->
    <button onclick="toggleWishlist(this, <?= (int)$car['id'] ?>); event.preventDefault();"
            type="button"
            class="pub-nav-icon-btn <?= $isWl ? 'wishlisted' : '' ?>"
            title="<?= $isWl ? 'Remove from saved' : 'Save car' ?>"
            style="position:absolute;top:10px;right:10px;z-index:2;background:rgba(255,255,255,.92);">
      <i class="fa-<?= $isWl ? 'solid' : 'regular' ?> fa-heart"></i>
    </button>

    <a href="<?= $carUrl ?>" style="text-decoration:none;display:contents;">

      <!-- Image -->
      <div class="pub-browse-card__img">
        <?php if ($thumb): ?>
        <img src="<?= htmlspecialchars($thumb) ?>"
             alt="<?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>"
             loading="lazy">
        <?php else: ?>
        <div class="pub-browse-card__img-placeholder">
          <i class="fa-solid fa-car-side"></i>
        </div>
        <?php endif; ?>

        <div style="position:absolute;top:10px;left:10px;">
          <span class="pub-badge" style="font-size:9px;
            background:<?= $car['condition_type'] === 'new' ? 'var(--p)' : 'rgba(0,0,0,.55)' ?>;
            color:#fff;border-color:transparent;">
            <?= ucfirst($car['condition_type']) ?>
          </span>
        </div>
        <?php if ($car['dealer_verified'] === 'verified'): ?>
        <div style="position:absolute;bottom:10px;left:10px;">
          <span class="pub-badge pub-badge-verified" style="font-size:9px;">
            <i class="fa-solid fa-circle-check" style="font-size:8px;"></i> Verified Dealer
          </span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Body -->
      <div class="pub-browse-card__body">
        <div class="pub-browse-card__name">
          <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
        </div>
        <div class="pub-browse-card__price"><?= $priceStr ?></div>

        <div class="pub-browse-card__meta">
          <?php foreach ($metaParts as $part): ?>
          <span><?= htmlspecialchars($part) ?></span>
          <?php endforeach; ?>
        </div>

        <!-- Broker-assisted chip -->
        <div style="display:flex;align-items:center;gap:5px;background:var(--gr-bg);
                    border:1px solid var(--gr-b);border-radius:var(--r-sm);
                    padding:4px 8px;margin-top:4px;">
          <i class="fa-solid fa-handshake" style="font-size:10px;color:var(--green);"></i>
          <span style="font-size:11px;color:var(--green);font-weight:500;">
            Broker-assisted sale
          </span>
        </div>

        <!-- Dealer + location -->
        <div class="pub-browse-card__broker">
          <i class="fa-solid fa-building-user" style="color:var(--faint);font-size:10px;"></i>
          <?= htmlspecialchars($car['dealer_name']) ?>
          <?php if ($dealerLoc): ?>
          <span style="color:var(--faint);margin-left:auto;font-size:10px;">
            <i class="fa-solid fa-location-dot" style="font-size:9px;"></i>
            <?= htmlspecialchars($dealerLoc) ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

    </a>

    <!-- Quick share button per card -->
    <div style="padding:0 12px 12px;display:flex;gap:6px;">
      <a href="<?= htmlspecialchars($carUrl) ?>"
         class="pub-btn pub-btn-primary"
         style="flex:1;padding:8px;font-size:12px;justify-content:center;text-decoration:none;">
        <i class="fa-solid fa-paper-plane" style="font-size:11px;"></i> Enquire
      </a>
      <a href="https://wa.me/?text=<?= urlencode($waShareText) ?>"
         target="_blank" rel="noopener"
         title="Share on WhatsApp"
         style="display:inline-flex;align-items:center;justify-content:center;
                width:36px;height:36px;background:#25d366;color:#fff;
                border-radius:var(--r-md);flex-shrink:0;text-decoration:none;">
        <i class="fa-brands fa-whatsapp" style="font-size:15px;"></i>
      </a>
    </div>

  </div>
  <?php endforeach; ?>
</div>

<?php endif; // inventory ?>

<!-- ── Bio section ───────────────────────────────────────────── -->
<?php if ($desk['bio']): ?>
<div style="margin-top:2rem;background:white;border:1px solid var(--border);
            border-radius:var(--r-lg);padding:20px;box-shadow:var(--shadow-sm);" class="pub-reveal">
  <div style="font-family:var(--font-d);font-size:14px;font-weight:700;margin-bottom:10px;">
    About <?= htmlspecialchars($brokerName) ?>
  </div>
  <div style="font-size:13px;color:var(--muted);line-height:1.75;">
    <?= nl2br(htmlspecialchars($desk['bio'])) ?>
  </div>
</div>
<?php endif; ?>

<!-- ── CTA ───────────────────────────────────────────────────── -->
<div class="pub-reveal" style="margin-top:2rem;text-align:center;
     background:linear-gradient(135deg,#08143c,var(--p));
     border-radius:var(--r-xl);padding:2rem 1.5rem;color:#fff;">
  <div style="font-family:var(--font-d);font-size:18px;font-weight:700;margin-bottom:6px;">
    Looking for a specific car?
  </div>
  <p style="font-size:13px;color:rgba(255,255,255,.65);margin-bottom:1.25rem;">
    Browse our full catalogue across all broker desks.
  </p>
  <a href="/c/" class="pub-btn pub-btn-ghost"
     style="display:inline-flex;padding:10px 24px;font-size:13px;
            background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);color:#fff;">
    Browse all cars →
  </a>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
