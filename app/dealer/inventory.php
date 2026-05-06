<?php
/**
 * SalesDesk — Dealer Inventory.
 * T3 owns this file.
 *
 * Task d3: All dealer cars with per-exec filter chip row.
 * Actions: Edit (inline status toggle), Pause/Resume, Mark Sold.
 * Filter chips: "All cars" / "My uploads" / [exec names]
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Dealer record ─────────────────────────────────────────────
$dealerStmt = $pdo->prepare("SELECT id AS dealer_id, company_name FROM dealers WHERE user_id = ? AND is_active = 1");
$dealerStmt->execute([$userId]);
$dealer = $dealerStmt->fetch();
if (!$dealer) redirect('/app/dealer/dashboard.php');

$dealerId   = (int) $dealer['dealer_id'];
$csrf       = generateCSRFToken();

// ── Handle status change actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $carId  = (int) ($_POST['car_id'] ?? 0);

    // Verify car belongs to this dealer.
    if ($carId > 0) {
        $carCheck = $pdo->prepare("SELECT id, status FROM cars WHERE id = ? AND dealer_id = ?");
        $carCheck->execute([$carId, $dealerId]);
        $carRow = $carCheck->fetch();

        if ($carRow) {
            $newStatus = null;
            if ($action === 'pause'  && $carRow['status'] === 'active')  $newStatus = 'paused';
            if ($action === 'resume' && $carRow['status'] === 'paused')  $newStatus = 'active';
            if ($action === 'sold'   && in_array($carRow['status'], ['active','paused'], true)) {
                $newStatus = 'sold';
            }

            if ($newStatus) {
                $pdo->prepare("
                    UPDATE cars
                    SET status = ?, sold_at = IF(? = 'sold', NOW(), sold_at), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$newStatus, $newStatus, $carId]);

                writeAuditLog("car.status_changed", 'car', $carId,
                    ['status' => $carRow['status']], ['status' => $newStatus]);
                $_SESSION['flash_ok'] = "Listing updated to " . $newStatus . ".";
            }
        }
    }
    redirect('/app/dealer/inventory.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
}

// ── Filter state ──────────────────────────────────────────────
$filterExec   = $_GET['exec']   ?? 'all';   // 'all', 'mine', or exec id (int)
$filterStatus = $_GET['status'] ?? '';       // 'active', 'paused', 'sold', ''
$search       = trim($_GET['q'] ?? '');

// ── Load verified execs for filter chips ─────────────────────
$execsStmt = $pdo->prepare("
    SELECT se.id, p.first_name, p.last_name
    FROM sales_executives se
    LEFT JOIN profiles p ON p.user_id = se.user_id
    WHERE se.dealer_id = ? AND se.verification_status = 'verified'
    ORDER BY p.first_name, p.last_name
");
$execsStmt->execute([$dealerId]);
$execs = $execsStmt->fetchAll();

// ── Build WHERE clause ────────────────────────────────────────
$where  = ['c.dealer_id = ?'];
$params = [$dealerId];

if ($filterExec === 'mine') {
    $where[]  = 'c.uploaded_by_exec_id IS NULL';
} elseif (is_numeric($filterExec) && (int)$filterExec > 0) {
    $where[]  = 'c.uploaded_by_exec_id = ?';
    $params[] = (int)$filterExec;
}

if ($filterStatus) {
    $where[]  = 'c.status = ?';
    $params[] = $filterStatus;
}

if ($search) {
    $where[]  = '(c.make LIKE ? OR c.model LIKE ? OR c.colour LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// ── Count by status ───────────────────────────────────────────
$countStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt FROM cars WHERE dealer_id = ? GROUP BY status
");
$countStmt->execute([$dealerId]);
$statusCounts = ['active' => 0, 'paused' => 0, 'sold' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}
$totalCars = array_sum($statusCounts);

// ── Load cars ─────────────────────────────────────────────────
$carsStmt = $pdo->prepare("
    SELECT
        c.id, c.uuid, c.make, c.model, c.year, c.price, c.mileage,
        c.condition_type, c.body_type, c.colour, c.transmission, c.fuel_type,
        c.commission_type, c.commission_value,
        c.image_urls, c.status, c.created_at, c.sold_at,
        c.uploaded_by_exec_id,
        COUNT(DISTINCT bi.id) AS broker_count,
        COUNT(DISTINCT l.id)  AS lead_count,
        COUNT(DISTINCT CASE WHEN l.status = 'closed' THEN l.id END) AS deals_count,
        -- exec info
        CONCAT(p.first_name, ' ', p.last_name) AS exec_name
    FROM cars c
    LEFT JOIN broker_inventory bi ON bi.car_id = c.id
    LEFT JOIN leads l ON l.car_id = c.id
    LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
    LEFT JOIN profiles p ON p.user_id = se.user_id
    WHERE {$whereClause}
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 100
");
array_push($params);
$carsStmt->execute($params);
$cars = $carsStmt->fetchAll();

$pageTitle = 'Inventory';

ob_start();
?>

<!-- ── Header ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;
            margin-bottom:1.5rem;flex-wrap:wrap;gap:10px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.5rem;font-weight:300;margin-bottom:2px;">
      My <em style="font-style:italic;">inventory</em>
    </h1>
    <p style="font-size:13px;color:var(--muted);">
      <?= $statusCounts['active'] ?> active ·
      <?= $statusCounts['paused'] ?> paused ·
      <?= $statusCounts['sold'] ?> sold
    </p>
  </div>
  <a href="/app/dealer/car-upload.php" class="btn btn-primary" style="text-decoration:none;">
    <i class="fa-solid fa-plus"></i> List a car
  </a>
</div>

<!-- ── Filter chips + search ─────────────────────────────────── -->
<form method="GET" style="margin-bottom:1.25rem;">

  <!-- Exec filter chips -->
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
    <?php
    $chipBase = ['status' => $filterStatus, 'q' => $search];
    $chips    = [
      ['val' => 'all',  'label' => 'All cars',   'count' => $totalCars],
      ['val' => 'mine', 'label' => 'My uploads',  'count' => null],
    ];
    foreach ($execs as $e) {
        $chips[] = [
            'val'   => $e['id'],
            'label' => trim("{$e['first_name']} {$e['last_name']}"),
            'count' => null,
        ];
    }
    foreach ($chips as $chip):
      $isActive = (string)$filterExec === (string)$chip['val'];
    ?>
    <a href="?<?= http_build_query(array_merge($chipBase, ['exec' => $chip['val']])) ?>"
       style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;
              border-radius:var(--r-full);font-size:12px;font-weight:500;
              text-decoration:none;border:1px solid;
              background:<?= $isActive ? 'var(--p)' : 'transparent' ?>;
              color:<?= $isActive ? '#fff' : 'var(--muted)' ?>;
              border-color:<?= $isActive ? 'var(--p)' : 'var(--border)' ?>;">
      <?= htmlspecialchars($chip['label']) ?>
      <?php if ($chip['count'] !== null): ?>
      <span style="font-size:10px;font-family:var(--mono);"><?= $chip['count'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Status + search bar -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <select name="status" class="finput" style="max-width:130px;"
            onchange="this.form.submit()">
      <option value="" <?= !$filterStatus ? 'selected' : '' ?>>All statuses</option>
      <option value="active"  <?= $filterStatus === 'active'  ? 'selected' : '' ?>>Active</option>
      <option value="paused"  <?= $filterStatus === 'paused'  ? 'selected' : '' ?>>Paused</option>
      <option value="sold"    <?= $filterStatus === 'sold'    ? 'selected' : '' ?>>Sold</option>
    </select>
    <input type="hidden" name="exec" value="<?= htmlspecialchars($filterExec) ?>">
    <input type="text" name="q" class="finput" placeholder="Search make, model, colour…"
           value="<?= htmlspecialchars($search) ?>" style="max-width:220px;">
    <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    <?php if ($search || $filterStatus): ?>
    <a href="?exec=<?= htmlspecialchars($filterExec) ?>" class="btn btn-ghost btn-sm"
       style="text-decoration:none;">Clear</a>
    <?php endif; ?>
  </div>
</form>

<!-- ── Car grid ───────────────────────────────────────────────── -->
<?php if (empty($cars)): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-solid fa-car"></i></span>
  No listings match your filters.
  <?php if (!$search && !$filterStatus && $filterExec === 'all'): ?>
  <div style="margin-top:12px;">
    <a href="/app/dealer/car-upload.php" class="btn btn-primary btn-sm" style="text-decoration:none;">
      <i class="fa-solid fa-plus"></i> List your first car
    </a>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
  <?php foreach ($cars as $car):
    $images = json_decode($car['image_urls'] ?? '[]', true);
    $thumb  = !empty($images[0]) ? $images[0] : null;
    $commission = $car['commission_type'] === 'fixed'
      ? 'R ' . number_format($car['commission_value'], 0)
      : $car['commission_value'] . '% (R ' . number_format($car['price'] * $car['commission_value'] / 100, 0) . ')';
  ?>
  <div class="card" style="overflow:hidden;display:flex;flex-direction:column;">
    <!-- Image -->
    <div style="height:160px;background:var(--bg);position:relative;flex-shrink:0;">
      <?php if ($thumb): ?>
      <img src="<?= htmlspecialchars($thumb) ?>" alt=""
           style="width:100%;height:100%;object-fit:cover;">
      <?php else: ?>
      <div style="width:100%;height:100%;display:flex;align-items:center;
                  justify-content:center;font-size:32px;color:var(--faint);">
        <i class="fa-solid fa-car-side"></i>
      </div>
      <?php endif; ?>
      <!-- Status badge overlay -->
      <div style="position:absolute;top:8px;left:8px;">
        <span class="badge badge-<?= $car['status'] ?>"><?= $car['status'] ?></span>
      </div>
      <!-- Exec badge -->
      <?php if ($car['exec_name'] && trim($car['exec_name']) !== ' '): ?>
      <div style="position:absolute;top:8px;right:8px;font-size:9px;font-family:var(--mono);
                  background:rgba(0,0,0,.6);color:#fff;padding:2px 7px;border-radius:4px;">
        <i class="fa-solid fa-user-tie" style="font-size:8px;margin-right:3px;"></i>
        <?= htmlspecialchars($car['exec_name']) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Content -->
    <div style="padding:12px 14px;flex:1;display:flex;flex-direction:column;gap:8px;">
      <div>
        <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:2px;">
          <?= htmlspecialchars("{$car['year']} {$car['make']} {$car['model']}") ?>
        </div>
        <div style="font-size:13px;color:var(--p);font-weight:600;">
          R <?= number_format($car['price'], 0) ?>
        </div>
        <div style="font-size:11px;color:var(--faint);margin-top:2px;">
          <?php $specParts = array_filter([
            $car['colour'],
            $car['transmission'],
            $car['mileage'] ? number_format($car['mileage']) . ' km' : null,
          ]); ?>
          <?= htmlspecialchars(implode(' · ', $specParts)) ?>
        </div>
      </div>

      <!-- Stats row -->
      <div style="display:flex;gap:12px;">
        <div style="font-size:11px;color:var(--muted);">
          <i class="fa-solid fa-users" style="font-size:10px;margin-right:3px;color:var(--faint)"></i>
          <?= $car['broker_count'] ?> broker<?= $car['broker_count'] != 1 ? 's' : '' ?>
        </div>
        <div style="font-size:11px;color:var(--muted);">
          <i class="fa-solid fa-user-plus" style="font-size:10px;margin-right:3px;color:var(--faint)"></i>
          <?= $car['lead_count'] ?> lead<?= $car['lead_count'] != 1 ? 's' : '' ?>
        </div>
      </div>

      <!-- Commission -->
      <div style="font-size:11px;background:var(--gr-bg);border:1px solid var(--gr-b);
                  border-radius:var(--r-sm);padding:5px 9px;color:var(--green);">
        <i class="fa-solid fa-money-bill-wave" style="font-size:10px;margin-right:4px;"></i>
        <?= htmlspecialchars($commission) ?> commission
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:6px;margin-top:auto;">
        <?php if ($car['status'] === 'active'): ?>
        <form method="POST" style="margin:0;flex:1;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="pause">
          <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
          <button class="btn btn-warn btn-sm btn-full" type="submit">
            <i class="fa-solid fa-pause"></i> Pause
          </button>
        </form>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="sold">
          <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"
                  onclick="return confirm('Mark this car as sold?')">
            <i class="fa-solid fa-check"></i> Sold
          </button>
        </form>
        <?php elseif ($car['status'] === 'paused'): ?>
        <form method="POST" style="margin:0;flex:1;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="resume">
          <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
          <button class="btn btn-success btn-sm btn-full" type="submit">
            <i class="fa-solid fa-play"></i> Resume
          </button>
        </form>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="sold">
          <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit"
                  onclick="return confirm('Mark this car as sold?')">
            <i class="fa-solid fa-check"></i> Sold
          </button>
        </form>
        <?php else: /* sold */ ?>
        <div style="font-size:11px;color:var(--faint);padding:6px 0;">
          Sold <?= $car['sold_at'] ? date('d M Y', strtotime($car['sold_at'])) : '' ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
