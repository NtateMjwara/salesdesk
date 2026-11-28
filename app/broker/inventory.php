<?php
/**
 * SalesDesk — Broker Inventory Marketplace
 * T4 owns this file. Route: /app/broker/inventory.php
 *
 * Two tabs:
 *   "Marketplace" — browse all active dealer cars (b2, b3)
 *   "My Desk"     — cars already added by this broker (b5)
 *
 * Car limit enforced on "Add to Desk" (b10, D-10).
 * Share sheet rendered inline per car (b4).
 *
 * DESK-1 (Nov 2026): "Add to Desk" works in place (no reload, stays in
 * the Marketplace) — assets/js/broker-inventory.js. The page's inline
 * <style> / <script> moved to assets/css|js/broker-inventory.*.
 *
 * PLANS-1 (0019): the desk car limit comes from the broker's plan
 * (Free 150 · Pro unlimited) or a superadmin custom limit, via
 * brokerDeskCarLimit() in includes/entitlements.php. The desk org's
 * agent car limit still caps it until plans Phase 2. A null limit
 * means unlimited.
 *
 * PLANS-3b (0023): cars over the limit after a downgrade are paused
 * ("parked" in broker_inventory_parked), never deleted. My Desk lists
 * them with Bring back / Remove; adding a parked car from the
 * Marketplace brings the same row (views, link) back.
 *
 * PLANS-4a (0024): network-only cars show in the Marketplace only to
 * verified members of their business (badge "Network only"); cars of a
 * business whose network the broker is in show its network bonus. The
 * "Networks" filter narrows to those businesses' cars (net=mine | id).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/org_membership.php';
require_once '../../includes/entitlements.php';
require_once '../../includes/plan_grace.php';
require_once '../../includes/vip_network.php';   // 4a

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Load broker salesdesk ─────────────────────────────────────
$deskStmt = $pdo->prepare("
    SELECT sd.id, sd.slug, sd.display_name, p.car_limit
    FROM salesdesks sd
    LEFT JOIN profiles p ON p.user_id = sd.user_id
    WHERE sd.user_id = ? AND sd.is_active = 1
    LIMIT 1
");
$deskStmt->execute([$userId]);
$desk = $deskStmt->fetch();

if (!$desk) {
    redirect('/auth/register.php');
}

$salesdeskId  = (int) $desk['id'];

// 0013: desk organisation catalogue rules.
//   independent → full marketplace
//   agent       → only the org's brands (empty list = legacy org, unrestricted)
//   pending / suspended → can browse the org's brands, can't add
// PLANS-1: car limit = plan / custom limit, capped by the org agent limit (null = unlimited).
$rules    = brokerCatalogueRules($userId);
$brands   = $rules['mode'] === 'independent' ? [] : $rules['brands'];
$carLimit = brokerDeskCarLimit($userId, $rules['car_limit']);
$myPlan   = sdAccountPlan('broker', $userId);
$csrf         = generateCSRFToken();

// ── Handle POST: Add to Desk / Remove from Desk ───────────────
// DESK-1 (Nov 2026): the Marketplace adds cars in place.
// assets/js/broker-inventory.js posts with X-Requested-With and gets JSON
// back, so the broker stays on the same page, filters and scroll position.
// Without JS the form posts normally and lands back on the same
// Marketplace page (not "My Desk") via the "back" field.
$flash      = '';
$flashType  = 'ok';

/** Marketplace query string to return to (allow-listed keys only). */
function invBackQs(string $raw): string
{
    parse_str($raw, $in);
    $keep = array_intersect_key($in, array_flip(['tab', 'q', 'make', 'province', 'comm_type', 'net', 'sort', 'page']));
    $keep = array_filter(array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 80) : '', $keep), 'strlen');
    return http_build_query($keep);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action    = $_POST['action'] ?? '';
    $carId     = (int) ($_POST['car_id'] ?? 0);
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $backQs    = invBackQs((string) ($_POST['back'] ?? ''));

    $ok  = false;
    $msg = 'Nothing changed.';

    if ($action === 'add_to_desk' && $carId) {
        $currentCount = brokerDeskCarCount($salesdeskId);
        if (!$rules['can_add']) {
            $msg = $rules['message'];
        } elseif (!sdHasRoom($carLimit, $currentCount)) {
            // b10 / PLANS-1: enforce the desk car limit (null = unlimited).
            $onPlanLimit = $myPlan && $myPlan['source'] === 'default'
                && sdPlanLimit($myPlan, 'max_desk_cars') === $carLimit;
            $msg = $onPlanLimit
                ? "Your desk is full — the {$myPlan['name']} plan holds {$carLimit} cars. Remove a car, or see Pro on the Plan page for unlimited cars."
                : "Your desk is full ({$carLimit} cars). Remove a car to add another.";
        } else {
            // Car must be active. 0013: agents may only add their org's brands.
            [$brandSql, $brandParams] = brandFilterSql($brands);
            $carStmt = $pdo->prepare("SELECT c.id FROM cars c WHERE c.id = ? AND c.status = 'active'"
                . ($brandSql ? " AND {$brandSql}" : ''));
            $carStmt->execute(array_merge([$carId], $brandParams));
            $carFound = (bool) $carStmt->fetch();
            if ($carFound && !sdBrokerMayListCar($userId, $carId)) {
                $msg = 'That car is for its business’s network brokers only.';   // 4a
            } elseif (!$carFound) {
                $msg = $brands
                    ? 'As an agent at ' . $rules['org']['org_name'] . ' you can only add ' . implode(', ', $brands) . ' cars.'
                    : 'That car is no longer available.';
            } else {
                $checkStmt = $pdo->prepare("SELECT id FROM broker_inventory WHERE salesdesk_id = ? AND car_id = ? LIMIT 1");
                $checkStmt->execute([$salesdeskId, $carId]);
                if ($checkStmt->fetch()) {
                    $ok  = true;                       // already there = the outcome they wanted
                    $msg = 'Already on your desk.';
                } elseif (($parkedId = sdParkedIdForCar($userId, $carId)) && sdRestoreParkedRow($salesdeskId, $parkedId)) {
                    // 3b: a paused car comes back with its link and views.
                    $ok  = true;
                    $msg = 'Back on your desk — same link as before.';
                } else {
                    $pdo->prepare("
                        INSERT INTO broker_inventory (salesdesk_id, car_id, tracking_code, views, added_at)
                        VALUES (?, ?, ?, 0, NOW())
                    ")->execute([$salesdeskId, $carId, bin2hex(random_bytes(16))]);
                    $ok  = true;
                    $msg = 'Added to your desk.';
                }
            }
        }
    } elseif ($action === 'remove_from_desk' && $carId) {
        // Prevent removal if car is sold (b5).
        $soldCheck = $pdo->prepare("SELECT c.status FROM broker_inventory bi JOIN cars c ON c.id = bi.car_id WHERE bi.salesdesk_id = ? AND bi.car_id = ?");
        $soldCheck->execute([$salesdeskId, $carId]);
        $carStatus = $soldCheck->fetchColumn();
        if ($carStatus === 'sold') {
            $msg = 'Sold cars cannot be removed from your desk.';
        } else {
            $pdo->prepare("DELETE FROM broker_inventory WHERE salesdesk_id = ? AND car_id = ?")
                ->execute([$salesdeskId, $carId]);
            $ok  = true;
            $msg = 'Removed from your desk.';
            sdGraceCheckBroker($userId, false);   // 3b: back under the limit closes the window
        }
    } elseif ($action === 'resume_parked') {
        // 3b: bring a paused car back (needs a free slot, org cap included).
        $invId = (int) ($_POST['inv_id'] ?? 0);
        if (!sdHasRoom($carLimit, brokerDeskCarCount($salesdeskId))) {
            $msg = 'Your desk is full (' . sdLimitText($carLimit) . ' cars). Remove a car or upgrade to bring this one back.';
        } else {
            [$ok, $msg] = sdBrokerResumeCar($userId, $invId);
        }
        $action = 'remove_from_desk';   // land back on My Desk
    } elseif ($action === 'drop_parked') {
        [$ok, $msg] = sdBrokerDropParked($userId, (int) ($_POST['inv_id'] ?? 0));
        $action = 'remove_from_desk';
    }

    if ($wantsJson) {
        $count = brokerDeskCarCount($salesdeskId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'         => $ok,
            'message'    => $msg,
            'car_id'     => $carId,
            'desk_count' => $count,
            'limit'      => $carLimit,
            'limit_text' => sdLimitText($carLimit),
            'at_limit'   => !sdHasRoom($carLimit, $count),
        ]);
        exit;
    }

    $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    // Removing happens on My Desk; adding stays in the Marketplace.
    redirect('/app/broker/inventory.php?' . ($action === 'remove_from_desk' ? 'tab=desk' : ($backQs !== '' ? $backQs : 'tab=market')));
}

$flash      = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$tab = $_GET['tab'] ?? 'market'; // 'market' | 'desk'

// ── Marketplace filters ───────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$make      = trim($_GET['make']      ?? '');
$province  = trim($_GET['province']  ?? '');
$commType  = trim($_GET['comm_type'] ?? '');
// 4a: 'mine' = every business whose network I'm in; an id = one of them
$myOrgs    = sdBrokerVerifiedOrgIds($userId);
$net       = (string) ($_GET['net'] ?? '');
$net       = $net === 'mine' || in_array((int) $net, $myOrgs, true) ? $net : '';
$sort      = trim($_GET['sort']      ?? 'commission_desc');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$mktBackQs = http_build_query(array_filter([
    'tab' => 'market', 'q' => $q, 'make' => $make, 'province' => $province,
    'comm_type' => $commType, 'net' => $net, 'sort' => $sort, 'page' => $page > 1 ? $page : null,
], static fn ($v) => $v !== null && $v !== ''));
$perPage   = 12;
$offset    = ($page - 1) * $perPage;

// ── My Desk cars ──────────────────────────────────────────────
$deskCarsStmt = $pdo->prepare("
    SELECT
        bi.id AS bi_id,
        bi.tracking_code,
        bi.views,
        bi.added_at,
        c.id, c.make, c.model, c.year, c.price, c.image_urls,
        c.commission_type, c.commission_value, c.status AS car_status,
        c.slug AS car_slug,
        d.company_name AS dealer_name,
        d.verification_status AS dealer_verification,
        (SELECT COUNT(*) FROM leads l WHERE l.salesdesk_id = ? AND l.car_id = c.id) AS lead_count
    FROM broker_inventory bi
    JOIN cars c ON c.id = bi.car_id
    JOIN dealers d ON d.id = c.dealer_id
    WHERE bi.salesdesk_id = ?
    ORDER BY bi.added_at DESC
");
$deskCarsStmt->execute([$salesdeskId, $salesdeskId]);
$deskCars    = $deskCarsStmt->fetchAll();
$deskCount   = count($deskCars);

// 3b: paused (parked) cars and the downgrade window.
$graceState  = sdBrokerDeskState($userId);
$parkedCars  = $tab === 'desk' ? sdParkedCars($userId) : [];

// ── Marketplace cars ──────────────────────────────────────────
$mktWhere  = ["c.status = 'active'", "d.is_active = 1"];
$mktParams = [];

// 0013: org brand rule.
[$brandSql, $brandParams] = brandFilterSql($brands);
if ($brandSql) {
    $mktWhere[] = $brandSql;
    array_push($mktParams, ...$brandParams);
}

if ($q) {
    $mktWhere[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year,' ',c.make,' ',c.model) LIKE ?)";
    $mktParams[] = '%' . $q . '%';
    $mktParams[] = '%' . $q . '%';
    $mktParams[] = '%' . $q . '%';
}
if ($make)     {   // 0013: include alternative spellings (Volkswagen ↔ VW)
    $variants    = sdMakeVariants($make);
    $mktWhere[]  = 'c.make IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
    array_push($mktParams, ...$variants);
}
if ($province) { $mktWhere[] = "a.province = ?";            $mktParams[] = $province; }
if ($commType) { $mktWhere[] = "c.commission_type = ?";     $mktParams[] = $commType; }
// 4a: network-only cars for members of their business only
[$netSql, $netParams] = sdMarketplaceNetworkSql($userId);
$mktWhere[] = $netSql;
array_push($mktParams, ...$netParams);
if ($net !== '') {
    $netIds = $net === 'mine' ? ($myOrgs ?: [0]) : [(int) $net];
    $mktWhere[] = 'd.organization_id IN (' . implode(',', array_fill(0, count($netIds), '?')) . ')';
    array_push($mktParams, ...$netIds);
}

$whereClause = 'WHERE ' . implode(' AND ', $mktWhere);
$commExpr = "CASE c.commission_type WHEN 'fixed' THEN c.commission_value WHEN 'percentage' THEN c.price*(c.commission_value/100) ELSE 0 END";
$sortSql  = match($sort) {
    'newest'         => 'c.created_at DESC',
    'fewest_brokers' => 'broker_count ASC, c.created_at DESC',
    'price_asc'      => 'c.price ASC',
    'price_desc'     => 'c.price DESC',
    default          => "({$commExpr}) DESC, c.created_at DESC",
};

$countMkt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.id)
    FROM cars c JOIN dealers d ON d.id=c.dealer_id
    LEFT JOIN addresses a ON a.id=d.address_id
    {$whereClause}
");
$countMkt->execute($mktParams);
$totalMkt = (int) $countMkt->fetchColumn();

// FIX: placeholders must be bound in SQL order. The SELECT list's
// on_desk subquery (?) comes BEFORE the WHERE placeholders, so the desk id
// goes first. It used to be appended after them, which shifted every value
// whenever a filter (search, make, brand rule) added WHERE parameters —
// cars went missing and "on your desk" flags were wrong.
$mktParams = array_merge([$salesdeskId], $mktParams, [$perPage, $offset]);
$mktStmt = $pdo->prepare("
    SELECT
        c.id, c.make, c.model, c.year, c.price, c.image_urls, c.slug AS car_slug,
        c.commission_type, c.commission_value, c.mileage, c.condition_type,
        ({$commExpr}) AS comm_rand,
        " . (sdVipReady() ? 'c.network_exclusive' : '0 AS network_exclusive') . ", d.organization_id AS org_id,
        d.id AS dealer_id, d.company_name AS dealer_name,
        d.verification_status AS dealer_verification,
        a.city AS dealer_city,
        (SELECT COUNT(*) FROM broker_inventory bi2 WHERE bi2.car_id=c.id) AS broker_count,
        CASE WHEN EXISTS(SELECT 1 FROM broker_inventory bi3 WHERE bi3.car_id=c.id AND bi3.salesdesk_id=?) THEN 1 ELSE 0 END AS on_desk
    FROM cars c JOIN dealers d ON d.id=c.dealer_id
    LEFT JOIN addresses a ON a.id=d.address_id
    {$whereClause}
    ORDER BY {$sortSql}
    LIMIT ? OFFSET ?
");
$mktStmt->execute($mktParams);
$mktCars = $mktStmt->fetchAll();

// 4a: network bonuses of the businesses whose network I'm in, and their names for the filter
$myBonuses = sdOrgBonuses($myOrgs);
$myNetworks = [];
if ($myOrgs) {
    $nn = $pdo->query('SELECT id, name FROM organizations WHERE id IN (' . implode(',', $myOrgs) . ') ORDER BY name');
    $myNetworks = $nn->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Distinct makes for filter.
$makesStmt = $pdo->query("SELECT DISTINCT make FROM cars WHERE status='active' ORDER BY make");
$allMakes  = $makesStmt->fetchAll(PDO::FETCH_COLUMN);
if ($brands) {
    $allMakes = $brands; // 0013: agents only ever see their org's brands
}

$totalPages = max(1, (int)ceil($totalMkt / $perPage));

// ── Render ────────────────────────────────────────────────────
$pageTitle = 'Inventory | ' . $desk['display_name'];
ob_start();
?>

<!-- Flash messages -->
<?php if ($flash): ?>
<div class="alert alert-success" style="margin-bottom:1rem">
  <i class="fa-solid fa-circle-check alert-icon"></i> <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error" style="margin-bottom:1rem">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i> <?= htmlspecialchars($flashError) ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../views/partials/grace-banner.php'; ?>

<!-- Page header with slot counter -->
<div class="inv-header">
  <div>
    <h1 class="inv-title">Inventory</h1>
    <p class="inv-sub">
      <span class="slot-count <?= sdHasRoom($carLimit, $deskCount) ? '' : 'at-limit' ?>" data-slot-count aria-live="polite">
        <span data-slot-used><?= $deskCount ?></span> / <span data-slot-limit><?= sdLimitText($carLimit) ?></span> desk slots used
      </span>
      <?php if ($myPlan): ?>
      · <a class="plan-chip" href="/app/broker/plan"><?= htmlspecialchars($myPlan['name']) ?> plan</a>
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:6px;">
    <a href="?tab=market<?= $q || $make || $province || $commType ? '&q='.urlencode($q).'&make='.urlencode($make).'&province='.urlencode($province) : '' ?>"
       class="btn <?= $tab !== 'desk' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
      <i class="fa-solid fa-shop"></i> Marketplace
    </a>
    <a href="?tab=desk" class="btn <?= $tab === 'desk' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
      <i class="fa-solid fa-id-card"></i> My Desk
      <?php if ($deskCount): ?>
      <span style="background:rgba(255,255,255,.25);border-radius:9999px;padding:0 6px;font-size:10px;">
        <?= $deskCount ?>
      </span>
      <?php endif; ?>
    </a>
  </div>
</div>

<?php if ($tab === 'desk'): ?>
<!-- ══════════════════════════════════
     MY DESK TAB
     ══════════════════════════════════ -->
<?php if (empty($deskCars)): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-solid fa-id-card"></i></span>
  Your desk is empty.<br>
  <a href="?tab=market" style="color:var(--p)">Browse the marketplace</a> to add your first car.
</div>
<?php else: ?>
<div class="car-grid">
  <?php foreach ($deskCars as $car):
    $imgs     = json_decode($car['image_urls'] ?? '[]', true) ?: [];
    $img      = $imgs[0] ?? null;
    $commRand = $car['commission_type'] === 'fixed'
      ? (float)$car['commission_value']
      : round((float)$car['price'] * ((float)$car['commission_value'] / 100), 2);
    $publicCarUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za')
                     . '/c/' . htmlspecialchars($car['car_slug']) . '/';
    $trackedCarUrl = $publicCarUrl . '?ref=' . urlencode($car['tracking_code']);
    $carLabel      = "{$car['year']} {$car['make']} {$car['model']}";
    $priceLabel    = 'R ' . number_format((float)$car['price'], 0, '.', ' ');
    $waText        = "Check out this {$carLabel} for {$priceLabel}: {$trackedCarUrl}";
    $isSold   = $car['car_status'] === 'sold';
    $isPaused = $car['car_status'] === 'paused';
  ?>
  <div class="car-card <?= $isSold ? 'car-sold' : ($isPaused ? 'car-paused' : '') ?>">
    <div class="car-card-img">
      <?php if ($img): ?>
      <img src="<?= htmlspecialchars($img) ?>" alt="">
      <?php else: ?>
      <div class="car-img-placeholder"><i class="fa-solid fa-car-side"></i></div>
      <?php endif; ?>
      <div class="car-status-badge">
        <?php if ($isSold): ?>
        <span class="badge badge-sold">Sold</span>
        <?php elseif ($isPaused): ?>
        <span class="badge badge-paused">Paused</span>
        <?php else: ?>
        <span class="badge badge-active">Active</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="car-card-body">
      <div class="car-card-title"><?= htmlspecialchars($car['year'].' '.$car['make'].' '.$car['model']) ?></div>
      <div class="car-card-price">R <?= number_format((float)$car['price'], 0, '.', ',') ?></div>
      <div class="car-card-dealer"><?= htmlspecialchars($car['dealer_name']) ?></div>
      <div class="car-card-stats">
        <span><i class="fa-solid fa-eye" style="font-size:10px"></i> <?= (int)$car['views'] ?> views</span>
        <span><i class="fa-solid fa-user" style="font-size:10px"></i> <?= (int)$car['lead_count'] ?> leads</span>
        <span style="color:var(--green);font-weight:600">
          <?= $car['commission_type'] === 'fixed'
            ? 'R '.number_format($commRand, 0, '.', ',').' earn'
            : number_format((float)$car['commission_value'],1).'% earn' ?>
        </span>
      </div>

      <!-- Share sheet (b4) -->
      <?php if (!$isSold && !$isPaused): ?>
      <div class="share-sheet" id="share-<?= $car['id'] ?>" style="display:none;margin-bottom:8px;">

        <!-- URL row -->
        <div class="share-url-row">
          <input type="text" class="finput"
                 value="<?= htmlspecialchars($trackedCarUrl) ?>"
                 id="shareUrl-<?= $car['id'] ?>" readonly
                 style="font-size:11px;font-family:var(--mono);">
          <button class="btn btn-ghost btn-sm"
                  onclick="copyShare(<?= $car['id'] ?>)"
                  title="Copy link">
            <i class="fa-solid fa-copy"></i>
          </button>
        </div>

        <!-- Share options row -->
        <div style="display:flex;gap:6px;margin-top:6px;">
          <!-- WhatsApp -->
          <a href="https://wa.me/?text=<?= urlencode($waText) ?>"
             target="_blank" rel="noopener"
             class="btn btn-sm btn-full"
             style="background:#25D366;color:#fff;border:none;text-decoration:none;flex:1;">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp
          </a>

          <!-- View public listing -->
          <a href="<?= htmlspecialchars($trackedCarUrl) ?>"
             target="_blank" rel="noopener"
             class="btn btn-ghost btn-sm"
             title="Preview public listing">
            <i class="fa-solid fa-arrow-up-right-from-square"></i>
          </a>
        </div>

        <!-- Commission reminder -->
        <div style="font-size:10px;color:var(--green);margin-top:6px;text-align:center;
                    font-family:var(--mono);">
          You earn <?= htmlspecialchars($commDisp ?? formatZAR($commRand)) ?> if this lead closes
        </div>
      </div>
      <?php endif; ?>

      <!-- Card actions: Share toggle + copy quick action + remove -->
      <div class="car-card-actions">
        <?php if (!$isSold && !$isPaused): ?>
        <button class="btn btn-primary btn-sm" style="flex:1;"
                onclick="toggleShare(<?= $car['id'] ?>)">
          <i class="fa-solid fa-share-nodes"></i> Share
        </button>
        <button class="btn btn-ghost btn-sm"
                onclick="copyShare(<?= $car['id'] ?>)"
                title="Quick copy link">
          <i class="fa-solid fa-copy"></i>
        </button>
        <?php endif; ?>
        <?php if (!$isSold): ?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action"  value="remove_from_desk">
          <input type="hidden" name="car_id"  value="<?= $car['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"
                  onclick="return confirm('Remove this car from your desk?')">
            <i class="fa-solid fa-minus"></i>
          </button>
        </form>
        <?php endif; ?>
      </div><!-- /car-card-actions -->
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($parkedCars): ?>
<!-- 3b: cars paused by the plan limit -->
<section class="parked" aria-labelledby="parkedTitle">
  <h2 class="parked-title" id="parkedTitle">
    <i class="fa-solid fa-pause"></i> Paused cars <span class="parked-count"><?= count($parkedCars) ?></span>
  </h2>
  <p class="parked-sub">Hidden from your desk and links — over your plan's limit, or made network-only by their business. Nothing was deleted: bring one back when you have a free slot.</p>
  <ul class="parked-list">
    <?php foreach ($parkedCars as $pc):
      $pImgs = json_decode($pc['image_urls'] ?? '[]', true) ?: [];
      $pImg  = $pImgs[0] ?? null;
      $pGone = $pc['status'] !== 'active';
    ?>
    <li class="parked-row<?= $pGone ? ' is-gone' : '' ?>">
      <span class="parked-thumb">
        <?php if ($pImg): ?><img src="<?= htmlspecialchars($pImg) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-car-side"></i><?php endif; ?>
      </span>
      <span class="parked-info">
        <span class="parked-name"><?= htmlspecialchars($pc['year'] . ' ' . $pc['make'] . ' ' . $pc['model']) ?></span>
        <span class="parked-meta">R <?= number_format((float) $pc['price'], 0, '.', ',') ?>
          · <?= (int) $pc['views'] ?> views · <?= (int) $pc['leads'] ?> leads
          <?php if ($pGone): ?>· <strong><?= htmlspecialchars(ucfirst((string) $pc['status'])) ?> by the dealer</strong><?php endif; ?>
          <?php if (!$pc['may_list']): /* 4a */ ?>· <strong class="parked-net">Network only — <?= htmlspecialchars((string) ($pc['org_name'] ?: 'its business')) ?></strong><?php endif; ?>
        </span>
      </span>
      <span class="parked-actions">
        <?php if (!$pGone && !$pc['may_list']): /* 4a */ ?>
        <a class="btn btn-ghost btn-sm" href="/app/broker/networks">Join network</a>
        <?php elseif (!$pGone): ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="resume_parked">
          <input type="hidden" name="inv_id" value="<?= (int) $pc['inv_id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"<?= sdHasRoom($carLimit, $deskCount) ? '' : ' disabled title="Your desk is full"' ?>>
            <i class="fa-solid fa-play"></i> Bring back
          </button>
        </form>
        <?php endif; ?>
        <form method="POST" data-confirm="Remove this paused car for good? Its link stops working.">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="drop_parked">
          <input type="hidden" name="inv_id" value="<?= (int) $pc['inv_id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit" aria-label="Remove for good"><i class="fa-solid fa-xmark"></i></button>
        </form>
      </span>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php else: ?>
<!-- ══════════════════════════════════
     MARKETPLACE TAB
     ══════════════════════════════════ -->

<!-- 0013: desk organisation catalogue notice -->
<?php if ($rules['mode'] === 'agent' && $brands): ?>
<div class="dorg-banner">
  <i class="fa-solid fa-building"></i>
  <span>Showing <strong><?= htmlspecialchars($rules['org']['org_name']) ?></strong> stock:</span>
  <span class="dorg-brands"><?php foreach ($brands as $b): ?><span class="dorg-brand"><?= htmlspecialchars($b) ?></span><?php endforeach; ?></span>
</div>
<?php elseif (!$rules['can_add']): ?>
<div class="dorg-banner <?= $rules['mode'] === 'pending' ? 'dorg-banner-pending' : 'dorg-banner-suspended' ?>">
  <i class="fa-solid <?= $rules['mode'] === 'pending' ? 'fa-clock' : 'fa-pause' ?>"></i>
  <span><?= htmlspecialchars($rules['message']) ?></span>
  <a href="/app/broker/networks">Details</a>
</div>
<?php endif; ?>

<!-- Filter bar -->
<form method="GET" action="" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem;align-items:flex-end;">
  <input type="hidden" name="tab" value="market">
  <div>
    <div class="flabel" style="margin-bottom:4px;">Search</div>
    <input class="finput" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="Make, model…" style="width:180px;">
  </div>
  <div>
    <div class="flabel" style="margin-bottom:4px;">Make</div>
    <select class="finput" name="make" style="width:140px;">
      <option value="">All makes</option>
      <?php foreach ($allMakes as $m): ?>
      <option value="<?= htmlspecialchars($m) ?>" <?= $make === $m ? 'selected' : '' ?>>
        <?= htmlspecialchars($m) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <div class="flabel" style="margin-bottom:4px;">Commission</div>
    <select class="finput" name="comm_type" style="width:130px;">
      <option value="">Any type</option>
      <option value="fixed"      <?= $commType === 'fixed'      ? 'selected' : '' ?>>Fixed (R)</option>
      <option value="percentage" <?= $commType === 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
    </select>
  </div>
  <?php if ($myNetworks): /* 4a */ ?>
  <div>
    <div class="flabel" style="margin-bottom:4px;">Networks</div>
    <select class="finput mkt-net-select" name="net">
      <option value="">All cars</option>
      <option value="mine" <?= $net === 'mine' ? 'selected' : '' ?>>My networks’ cars</option>
      <?php foreach ($myNetworks as $nid => $nname): ?>
      <option value="<?= (int) $nid ?>" <?= $net === (string) $nid ? 'selected' : '' ?>><?= htmlspecialchars($nname) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div>
    <div class="flabel" style="margin-bottom:4px;">Sort</div>
    <select class="finput" name="sort" style="width:160px;">
      <option value="commission_desc" <?= $sort === 'commission_desc' ? 'selected' : '' ?>>Commission (high→low)</option>
      <option value="newest"          <?= $sort === 'newest'          ? 'selected' : '' ?>>Newest first</option>
      <option value="fewest_brokers"  <?= $sort === 'fewest_brokers'  ? 'selected' : '' ?>>Fewest brokers</option>
      <option value="price_asc"       <?= $sort === 'price_asc'       ? 'selected' : '' ?>>Price (low→high)</option>
      <option value="price_desc"      <?= $sort === 'price_desc'      ? 'selected' : '' ?>>Price (high→low)</option>
    </select>
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
    <?php if ($q || $make || $province || $commType || $net !== ''): ?>
    <a href="?tab=market" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
  </div>
</form>

<!-- Results count -->
<p style="font-size:12px;color:var(--muted);margin-bottom:.75rem;">
  <?= number_format($totalMkt) ?> car<?= $totalMkt !== 1 ? 's' : '' ?> available
</p>

<?php if (empty($mktCars)): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-solid fa-car-on"></i></span>
  No cars match your filters.
</div>
<?php else: ?>
<div class="car-grid">
  <?php foreach ($mktCars as $car):
    $imgs     = json_decode($car['image_urls'] ?? '[]', true) ?: [];
    $img      = $imgs[0] ?? null;
    $commRand = $car['commission_type'] === 'fixed'
      ? (float)$car['commission_value']
      : round((float)$car['price'] * ((float)$car['commission_value'] / 100), 2);
    $onDesk   = (bool)((int)$car['on_desk']);
    $atLimit  = !sdHasRoom($carLimit, $deskCount);
  ?>
  <div class="car-card">
    <div class="car-card-img">
      <?php if ($img): ?>
      <img src="<?= htmlspecialchars($img) ?>" alt="">
      <?php else: ?>
      <div class="car-img-placeholder"><i class="fa-solid fa-car-side"></i></div>
      <?php endif; ?>
      <?php if ($car['dealer_verification'] === 'verified'): ?>
      <div class="car-status-badge">
        <span class="badge badge-verified" style="font-size:9px"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> Verified</span>
      </div>
      <?php endif; ?>
      <?php if ((int) $car['network_exclusive']): /* 4a */ ?>
      <span class="net-only-badge" title="Only your network’s brokers can list this car"><i class="fa-solid fa-lock" aria-hidden="true"></i> Network only</span>
      <?php endif; ?>
    </div>
    <div class="car-card-body">
      <div class="car-card-title"><?= htmlspecialchars($car['year'].' '.$car['make'].' '.$car['model']) ?></div>
      <div class="car-card-price">R <?= number_format((float)$car['price'], 0, '.', ',') ?></div>
      <div class="car-card-dealer">
        <?= htmlspecialchars($car['dealer_name']) ?>
        <?php if ($car['dealer_city']): ?>
        · <?= htmlspecialchars($car['dealer_city']) ?>
        <?php endif; ?>
      </div>

      <div class="comm-chip">
        <i class="fa-solid fa-sack-dollar" style="font-size:10px"></i>
        <?php if ($car['commission_type'] === 'fixed'): ?>
          Earn <strong>R <?= number_format($commRand, 0, '.', ',') ?></strong>
        <?php else: ?>
          Earn <strong><?= number_format((float)$car['commission_value'], 1) ?>%</strong>
          <span style="color:var(--faint)">(R <?= number_format($commRand, 0, '.', ',') ?>)</span>
        <?php endif; ?>
      </div>
      <?php if ($b = $myBonuses[(int) $car['org_id']] ?? null): /* 4a */ ?>
      <div class="net-bonus-chip" title="Network bonus from <?= htmlspecialchars($myNetworks[(int) $car['org_id']] ?? 'your network', ENT_QUOTES) ?>">
        <i class="fa-solid fa-gift" aria-hidden="true"></i> Network bonus <strong>+R <?= number_format(sdBonusAmount($b['type'], $b['value'], $car['price']), 0, '.', ',') ?></strong>
        <?php if ($b['type'] === 'percentage'): ?><span class="net-bonus-chip__rand">(<?= htmlspecialchars(rtrim(rtrim(number_format((float) $b['value'], 2, '.', ''), '0'), '.')) ?>%)</span><?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="car-card-stats">
        <?php if ($car['mileage']): ?>
        <span><?= number_format((int)$car['mileage']) ?> km</span>
        <?php endif; ?>
        <span><?= ucfirst($car['condition_type']) ?></span>
        <span><i class="fa-solid fa-users" style="font-size:10px"></i> <?= (int)$car['broker_count'] ?> broker<?= $car['broker_count'] !== 1 ? 's' : '' ?></span>
      </div>

      <?php if ($onDesk): ?>
      <button class="btn btn-ghost btn-sm" disabled style="width:100%;margin-top:8px;opacity:.6">
        <i class="fa-solid fa-check"></i> Already on your desk
      </button>
      <?php elseif (!$rules['can_add']): ?>
      <button class="btn btn-ghost btn-sm" disabled style="width:100%;margin-top:8px">
        <i class="fa-solid fa-lock"></i> <?= $rules['mode'] === 'pending' ? 'Awaiting approval' : 'Unavailable' ?>
      </button>
      <?php elseif ($atLimit): ?>
      <button class="btn btn-ghost btn-sm" disabled style="width:100%;margin-top:8px"
              title="Desk full (<?= sdLimitText($carLimit) ?> cars max)">
        <i class="fa-solid fa-lock"></i> Desk full
      </button>
      <?php else: ?>
      <form method="POST" class="inv-add-form" data-add-to-desk>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_to_desk">
        <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
        <input type="hidden" name="back" value="<?= htmlspecialchars($mktBackQs) ?>">
        <button class="btn btn-primary btn-sm inv-add-btn" type="submit">
          <i class="fa-solid fa-plus"></i> Add to Desk
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <?php $pqs = http_build_query(array_filter(['tab'=>'market','q'=>$q,'make'=>$make,'province'=>$province,'comm_type'=>$commType,'sort'=>$sort,'page'=>$p])); ?>
  <a href="?<?= $pqs ?>"
     style="padding:5px 11px;border-radius:6px;font-size:12px;font-family:var(--mono);
            border:1px solid <?= $p === $page ? 'var(--p)' : 'var(--border)' ?>;
            background:<?= $p === $page ? 'var(--p)' : 'transparent' ?>;
            color:<?= $p === $page ? '#fff' : 'var(--muted)' ?>;
            text-decoration:none;">
    <?= $p ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; /* tab */ ?>



<?php
$pageContent = ob_get_clean();
$pageStyles  = ['/assets/css/desk-org.css', '/assets/css/plans.css', '/assets/css/broker-inventory.css'];
$pageScripts = ['/assets/js/broker-inventory.js'];
$flashError  = '';   // already shown above — stop layout-app printing it a second time
require_once '../../views/layout-app.php';
