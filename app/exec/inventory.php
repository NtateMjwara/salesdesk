<?php
/**
 * SalesDesk — Sales Exec Inventory.
 * T3 owns this file.
 *
 * Task sep2: WHERE uploaded_by_exec_id = se.id only.
 * Suspension banner shown if exec is suspended (handled by exec_guard
 * before this page is reached, but guard re-checks on every load).
 *
 * Exec cannot change status to sold — only view/upload.
 * Status change actions are limited to pause/resume (own listings only).
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/exec_guard.php';

applyCachePolicy('auth');

$exec     = requireExecVerified();
$execId   = (int) $exec['id'];
$dealerId = (int) $exec['dealer_id'];
$pdo      = Database::getInstance();
$csrf     = generateCSRFToken();

// ── Handle status actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $carId  = (int) ($_POST['car_id'] ?? 0);

    // Verify car belongs to this exec
    if ($carId > 0) {
        $check = $pdo->prepare("SELECT id, status FROM cars WHERE id = ? AND uploaded_by_exec_id = ?");
        $check->execute([$carId, $execId]);
        $carRow = $check->fetch();

        if ($carRow) {
            $newStatus = null;
            if ($action === 'pause'  && $carRow['status'] === 'active') $newStatus = 'paused';
            if ($action === 'resume' && $carRow['status'] === 'paused') $newStatus = 'active';

            if ($newStatus) {
                $pdo->prepare("UPDATE cars SET status = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$newStatus, $carId]);
                writeAuditLog('car.status_changed', 'car', $carId,
                    ['status' => $carRow['status']], ['status' => $newStatus]);
                $_SESSION['flash_ok'] = "Listing " . ($newStatus === 'paused' ? 'paused' : 'resumed') . ".";
            }
        }
    }
    redirect('/app/exec/inventory.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ── Filters ───────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

// ── Status counts ─────────────────────────────────────────────
$countsStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt FROM cars
    WHERE uploaded_by_exec_id = ? GROUP BY status
");
$countsStmt->execute([$execId]);
$statusCounts = ['active' => 0, 'paused' => 0, 'sold' => 0];
foreach ($countsStmt->fetchAll() as $r) {
    $statusCounts[$r['status']] = (int)$r['cnt'];
}

// ── Load cars — exec-scoped ───────────────────────────────────
$where  = ['c.uploaded_by_exec_id = ?'];
$params = [$execId];
if ($filterStatus) { $where[] = 'c.status = ?'; $params[] = $filterStatus; }
if ($search) {
    $where[] = '(c.make LIKE ? OR c.model LIKE ? OR c.colour LIKE ?)';
    $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%";
}
$wc = implode(' AND ', $where);

$carsStmt = $pdo->prepare("
    SELECT
        c.id, c.make, c.model, c.year, c.price, c.mileage,
        c.condition_type, c.colour, c.transmission, c.fuel_type,
        c.commission_type, c.commission_value,
        c.image_urls, c.status, c.created_at,
        COUNT(DISTINCT bi.id)  AS broker_count,
        COUNT(DISTINCT l.id)   AS lead_count
    FROM cars c
    LEFT JOIN broker_inventory bi ON bi.car_id = c.id
    LEFT JOIN leads l ON l.car_id = c.id
    WHERE {$wc}
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 100
");
$carsStmt->execute($params);
$cars = $carsStmt->fetchAll();

$pageTitle = 'My Listings';
ob_start();
?>

<!-- ── Header ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;
            margin-bottom:1.5rem;flex-wrap:wrap;gap:10px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.5rem;font-weight:300;margin-bottom:2px;">
      My <em style="font-style:italic;">listings</em>
    </h1>
    <p style="font-size:13px;color:var(--muted);">
      <?= htmlspecialchars($exec['dealer_name']) ?> ·
      <?= $statusCounts['active'] ?> active ·
      <?= $statusCounts['paused'] ?> paused ·
      <?= $statusCounts['sold'] ?> sold
    </p>
  </div>
  <a href="/app/exec/car-upload.php" class="btn btn-primary" style="text-decoration:none;">
    <i class="fa-solid fa-plus"></i> List a car
  </a>
</div>

<!-- ── Filters ───────────────────────────────────────────────── -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;">
  <div style="display:flex;gap:4px;">
    <?php foreach ([''=>'All', 'active'=>'Active', 'paused'=>'Paused', 'sold'=>'Sold'] as $val => $lbl): ?>
    <a href="?status=<?= urlencode($val) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
       class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-ghost' ?>"
       style="text-decoration:none;">
      <?= $lbl ?>
      <span style="font-size:10px;font-family:var(--mono);">
        (<?= $val ? $statusCounts[$val] : array_sum($statusCounts) ?>)
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
  <input type="text" name="q" class="finput" placeholder="Search make, model…"
         value="<?= htmlspecialchars($search) ?>" style="max-width:200px;">
  <button class="btn btn-ghost btn-sm" type="submit">Search</button>
  <?php if ($search): ?>
  <a href="?status=<?= urlencode($filterStatus) ?>" class="btn btn-ghost btn-sm"
     style="text-decoration:none;">Clear</a>
  <?php endif; ?>
</form>

<!-- ── Car grid ───────────────────────────────────────────────── -->
<?php if (empty($cars)): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-solid fa-car"></i></span>
  No listings match your filters.
  <?php if (!$search && !$filterStatus): ?>
  <div style="margin-top:12px;">
    <a href="/app/exec/car-upload.php" class="btn btn-primary btn-sm" style="text-decoration:none;">
      <i class="fa-solid fa-plus"></i> List your first car
    </a>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">
  <?php foreach ($cars as $car):
    $images     = json_decode($car['image_urls'] ?? '[]', true);
    $thumb      = !empty($images[0]) ? $images[0] : null;
    $commission = $car['commission_type'] === 'fixed'
      ? 'R ' . number_format($car['commission_value'], 0)
      : $car['commission_value'] . '%';
  ?>
  <div class="card" style="overflow:hidden;display:flex;flex-direction:column;">
    <!-- Image -->
    <div style="height:150px;background:var(--bg);position:relative;flex-shrink:0;">
      <?php if ($thumb): ?>
      <img src="<?= htmlspecialchars($thumb) ?>" alt=""
           style="width:100%;height:100%;object-fit:cover;">
      <?php else: ?>
      <div style="width:100%;height:100%;display:flex;align-items:center;
                  justify-content:center;font-size:28px;color:var(--faint);">
        <i class="fa-solid fa-car-side"></i>
      </div>
      <?php endif; ?>
      <div style="position:absolute;top:8px;left:8px;">
        <span class="badge badge-<?= $car['status'] ?>"><?= $car['status'] ?></span>
      </div>
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
          <?php $parts = array_filter([$car['colour'], $car['transmission'],
            $car['mileage'] ? number_format($car['mileage']) . ' km' : null]);
          ?>
          <?= htmlspecialchars(implode(' · ', $parts)) ?: '—' ?>
        </div>
      </div>

      <!-- Stats -->
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
        <?php elseif ($car['status'] === 'paused'): ?>
        <form method="POST" style="margin:0;flex:1;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="resume">
          <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
          <button class="btn btn-success btn-sm btn-full" type="submit">
            <i class="fa-solid fa-play"></i> Resume
          </button>
        </form>
        <?php else: ?>
        <div style="font-size:11px;color:var(--faint);padding:6px 0;">
          Sold — managed by dealer
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
