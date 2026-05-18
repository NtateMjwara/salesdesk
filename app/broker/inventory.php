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
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Load broker salesdesk ─────────────────────────────────────
$deskStmt = $pdo->prepare("
    SELECT sd.id, sd.slug, sd.display_name
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
$defaultLimit = getPlatformConfigInt('broker_car_limit_default', 10);
$carLimit     = $desk['car_limit'] ?? $defaultLimit;
$csrf         = generateCSRFToken();

// ── Handle POST: Add to Desk / Remove from Desk ───────────────
$flash      = '';
$flashType  = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $carId  = (int) ($_POST['car_id'] ?? 0);

    if ($action === 'add_to_desk' && $carId) {
        // b10: enforce car limit
        $currentCount = (int) $pdo->prepare("
            SELECT COUNT(*) FROM broker_inventory bi
            JOIN cars c ON c.id = bi.car_id
            WHERE bi.salesdesk_id = ? AND c.status = 'active'
        ")->execute([$salesdeskId]) ? $pdo->query(
            "SELECT COUNT(*) FROM broker_inventory bi
             JOIN cars c ON c.id = bi.car_id
             WHERE bi.salesdesk_id = {$salesdeskId} AND c.status = 'active'"
        )->fetchColumn() : 0;

        // Proper parameterised count.
        $cntStmt = $pdo->prepare("
            SELECT COUNT(*) FROM broker_inventory bi
            JOIN cars c ON c.id = bi.car_id
            WHERE bi.salesdesk_id = ? AND c.status = 'active'
        ");
        $cntStmt->execute([$salesdeskId]);
        $currentCount = (int) $cntStmt->fetchColumn();

        if ($currentCount >= $carLimit) {
            $_SESSION['flash_error'] = "You've reached your car limit ({$carLimit} cars). Ask an admin to increase your limit.";
        } else {
            // Check car is active and not already on desk.
            $carStmt = $pdo->prepare("SELECT id, status FROM cars WHERE id = ? AND status = 'active'");
            $carStmt->execute([$carId]);
            $car = $carStmt->fetch();

            if ($car) {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM broker_inventory WHERE salesdesk_id = ? AND car_id = ? LIMIT 1
                ");
                $checkStmt->execute([$salesdeskId, $carId]);
                if ($checkStmt->fetch()) {
                    $_SESSION['flash_error'] = 'This car is already on your desk.';
                } else {
                    $trackingCode = bin2hex(random_bytes(16));
                    $pdo->prepare("
                        INSERT INTO broker_inventory (salesdesk_id, car_id, tracking_code, views, added_at)
                        VALUES (?, ?, ?, 0, NOW())
                    ")->execute([$salesdeskId, $carId, $trackingCode]);
                    $_SESSION['flash_ok'] = 'Car added to your desk!';
                }
            }
        }
        redirect('/app/broker/inventory.php?tab=desk');
    }

    if ($action === 'remove_from_desk' && $carId) {
        // Prevent removal if car is sold (b5).
        $soldCheck = $pdo->prepare("SELECT c.status FROM broker_inventory bi JOIN cars c ON c.id = bi.car_id WHERE bi.salesdesk_id = ? AND bi.car_id = ?");
        $soldCheck->execute([$salesdeskId, $carId]);
        $carStatus = $soldCheck->fetchColumn();
        if ($carStatus === 'sold') {
            $_SESSION['flash_error'] = 'Sold cars cannot be removed from your desk.';
        } else {
            $pdo->prepare("DELETE FROM broker_inventory WHERE salesdesk_id = ? AND car_id = ?")
                ->execute([$salesdeskId, $carId]);
            $_SESSION['flash_ok'] = 'Car removed from your desk.';
        }
        redirect('/app/broker/inventory.php?tab=desk');
    }
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
$sort      = trim($_GET['sort']      ?? 'commission_desc');
$page      = max(1, (int) ($_GET['page'] ?? 1));
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

// ── Marketplace cars ──────────────────────────────────────────
$mktWhere  = ["c.status = 'active'", "d.is_active = 1"];
$mktParams = [];

if ($q) {
    $mktWhere[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year,' ',c.make,' ',c.model) LIKE ?)";
    $mktParams[] = '%' . $q . '%';
    $mktParams[] = '%' . $q . '%';
    $mktParams[] = '%' . $q . '%';
}
if ($make)     { $mktWhere[] = "c.make = ?";               $mktParams[] = $make; }
if ($province) { $mktWhere[] = "a.province = ?";            $mktParams[] = $province; }
if ($commType) { $mktWhere[] = "c.commission_type = ?";     $mktParams[] = $commType; }

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

$mktParams[] = $salesdeskId;
$mktParams[] = $perPage;
$mktParams[] = $offset;
$mktStmt = $pdo->prepare("
    SELECT
        c.id, c.make, c.model, c.year, c.price, c.image_urls, c.slug AS car_slug,
        c.commission_type, c.commission_value, c.mileage, c.condition_type,
        ({$commExpr}) AS comm_rand,
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

// Distinct makes for filter.
$makesStmt = $pdo->query("SELECT DISTINCT make FROM cars WHERE status='active' ORDER BY make");
$allMakes  = $makesStmt->fetchAll(PDO::FETCH_COLUMN);

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

<!-- Page header with slot counter -->
<div class="inv-header">
  <div>
    <h1 class="inv-title">Inventory</h1>
    <p class="inv-sub">
      <span class="slot-count <?= $deskCount >= $carLimit ? 'at-limit' : '' ?>">
        <?= $deskCount ?> / <?= $carLimit ?> desk slots used
      </span>
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

<?php else: ?>
<!-- ══════════════════════════════════
     MARKETPLACE TAB
     ══════════════════════════════════ -->

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
    <?php if ($q || $make || $province || $commType): ?>
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
    $atLimit  = $deskCount >= $carLimit;
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
      <?php elseif ($atLimit): ?>
      <button class="btn btn-ghost btn-sm" disabled style="width:100%;margin-top:8px"
              title="Desk full (<?= $carLimit ?> cars max)">
        <i class="fa-solid fa-lock"></i> Desk full
      </button>
      <?php else: ?>
      <form method="POST" style="margin-top:8px">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_to_desk">
        <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
        <button class="btn btn-primary btn-sm" type="submit" style="width:100%">
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

<style>
.inv-header { display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1.25rem; }
.inv-title  { font-family:var(--serif);font-size:1.5rem;font-weight:300; }
.inv-sub    { font-size:12px;color:var(--muted);margin-top:2px; }
.slot-count { font-family:var(--mono);font-size:11px;padding:2px 8px;border-radius:var(--r-full);background:var(--bg);border:1px solid var(--border);color:var(--muted); }
.slot-count.at-limit { background:var(--amb-bg);border-color:var(--amb-b);color:var(--amber); }

.car-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px; }
.car-card { background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden; }
.car-card.car-sold   { opacity:.65; }
.car-card.car-paused { border-color:var(--amb-b); }
.car-card-img { position:relative;height:160px;background:var(--bg); }
.car-card-img img { width:100%;height:100%;object-fit:cover; }
.car-img-placeholder { width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px;color:var(--border); }
.car-status-badge { position:absolute;top:8px;right:8px; }
.car-card-body { padding:12px; }
.car-card-title  { font-size:13px;font-weight:600;color:var(--text);margin-bottom:2px; }
.car-card-price  { font-size:15px;font-weight:700;font-family:var(--mono);color:var(--text);margin-bottom:2px; }
.car-card-dealer { font-size:11px;color:var(--muted);margin-bottom:8px; }
.car-card-stats  { display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--faint);margin-bottom:8px; }
.car-card-actions { display:flex;gap:6px;margin-top:8px; }
.comm-chip { background:var(--gr-bg);border:1px solid var(--gr-b);border-radius:var(--r-sm);padding:5px 9px;font-size:11px;color:var(--green);margin-bottom:8px;display:flex;align-items:center;gap:5px; }

.share-sheet { background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);padding:10px;margin-bottom:6px; }
.share-url-row { display:flex;gap:6px; }
</style>

<script>
function toggleShare(id) {
  var el = document.getElementById('share-' + id);
  if (!el) return;
  var showing = el.style.display !== 'none';
  // Close all open share sheets first.
  document.querySelectorAll('.share-sheet').forEach(function(s) {
    s.style.display = 'none';
  });
  el.style.display = showing ? 'none' : 'block';
}

function copyShare(id) {
  var input = document.getElementById('shareUrl-' + id);
  if (!input) return;
  navigator.clipboard.writeText(input.value).then(function () {
    // Flash all copy buttons for this card.
    document.querySelectorAll('[onclick="copyShare(' + id + ')"] i').forEach(function(icon) {
      var orig = icon.className;
      icon.className = 'fa-solid fa-check';
      setTimeout(function () { icon.className = orig; }, 1600);
    });
  }).catch(function () {
    input.select();
    document.execCommand('copy');
  });
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
