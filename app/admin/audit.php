<?php
/**
 * SalesDesk — Admin: Audit Trail
 * T1 owns this file.
 *
 * Read-only paginated view of audit_logs.
 * Filters: entity_type, actor, date range.
 * Shows before → after JSON diff for dispute resolution.
 *
 * No write actions — audit_logs is append-only by architecture rule.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/response.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo = Database::getInstance();

// ── Filters ───────────────────────────────────────────────────
$entityType = $_GET['entity'] ?? '';
$search     = trim($_GET['q'] ?? '');
$dateFrom   = $_GET['from'] ?? '';
$dateTo     = $_GET['to']   ?? '';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 50;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($entityType) {
    $where[]  = 'al.entity_type = ?';
    $params[] = $entityType;
}
if ($search) {
    // Search by actor email or action name.
    $where[]  = '(u.email LIKE ? OR al.action LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($dateFrom) {
    $where[]  = 'al.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[]  = 'al.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// Total count for pagination.
$countParams = $params;
$countStmt   = $pdo->prepare("
    SELECT COUNT(*) FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_id
    WHERE {$whereClause}
");
$countStmt->execute($countParams);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalRows / $perPage);

// Fetch page.
$rowParams   = array_merge($params, [$perPage, $offset]);
$rowsStmt    = $pdo->prepare("
    SELECT
        al.id,
        al.action,
        al.entity_type,
        al.entity_id,
        al.before_data,
        al.after_data,
        al.ip_address,
        al.created_at,
        u.email    AS actor_email,
        u.role     AS actor_role
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_id
    WHERE {$whereClause}
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$rowsStmt->execute($rowParams);
$logs = $rowsStmt->fetchAll();

// Distinct entity types for filter dropdown.
$entityTypes = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

// ── Render ────────────────────────────────────────────────────
ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Audit Trail</h1>
  <span class="section-count"><?= number_format($totalRows) ?> entries</span>
</div>

<div class="alert alert-info" style="margin-bottom:1.25rem;">
  <span class="alert-icon">ℹ</span>
  <div>Audit logs are <strong>append-only</strong>. No entries can be deleted or modified.
  This log is the authoritative record for dispute resolution.</div>
</div>

<!-- ── Filter bar ── -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;align-items:flex-end;">
  <div>
    <div class="flabel" style="margin-bottom:4px;">Search action or actor</div>
    <input class="finput" name="q" value="<?= htmlspecialchars($search) ?>"
           placeholder="e.g. commission.approved or admin@…" style="width:240px;">
  </div>
  <div>
    <div class="flabel" style="margin-bottom:4px;">Entity type</div>
    <select class="finput" name="entity" style="width:160px;">
      <option value="">All types</option>
      <?php foreach ($entityTypes as $et): ?>
      <option value="<?= htmlspecialchars($et) ?>" <?= $entityType === $et ? 'selected' : '' ?>>
        <?= htmlspecialchars($et) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <div class="flabel" style="margin-bottom:4px;">From</div>
    <input class="finput" type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" style="width:140px;">
  </div>
  <div>
    <div class="flabel" style="margin-bottom:4px;">To</div>
    <input class="finput" type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" style="width:140px;">
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
    <?php if ($search || $entityType || $dateFrom || $dateTo): ?>
    <a href="/app/admin/audit.php" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
  </div>
</form>

<!-- ── Log table ── -->
<?php if (empty($logs)): ?>
<div class="empty"><span class="empty-icon">—</span>No audit entries match your filters.</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th style="width:150px;">Timestamp</th>
        <th>Actor</th>
        <th>Action</th>
        <th>Entity</th>
        <th>IP</th>
        <th style="width:80px;">Diff</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
    <?php
      $hasData = $log['before_data'] || $log['after_data'];
      $diffId  = 'diff-' . $log['id'];
    ?>
    <tr>
      <td style="font-family:var(--mono);font-size:11px;color:var(--faint);white-space:nowrap;">
        <?= date('d M Y H:i:s', strtotime($log['created_at'])) ?>
      </td>
      <td>
        <?php if ($log['actor_email']): ?>
        <div style="font-size:12px;"><?= htmlspecialchars($log['actor_email']) ?></div>
        <div style="font-size:10px;color:var(--faint);"><?= htmlspecialchars($log['actor_role'] ?? '') ?></div>
        <?php else: ?>
        <span style="font-size:11px;color:var(--faint);font-style:italic;">system</span>
        <?php endif; ?>
      </td>
      <td>
        <span style="font-family:var(--mono);font-size:11px;background:var(--bg);
                     padding:2px 7px;border-radius:4px;color:var(--p);">
          <?= htmlspecialchars($log['action']) ?>
        </span>
      </td>
      <td style="font-size:12px;">
        <span style="color:var(--muted);"><?= htmlspecialchars($log['entity_type']) ?></span>
        <span style="font-family:var(--mono);color:var(--faint);"> #<?= (int)$log['entity_id'] ?></span>
      </td>
      <td style="font-family:var(--mono);font-size:11px;color:var(--faint);">
        <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
      </td>
      <td>
        <?php if ($hasData): ?>
        <button class="btn btn-ghost btn-sm"
                onclick="toggleDiff('<?= $diffId ?>')"
                style="font-size:10px;padding:3px 8px;">
          View
        </button>
        <?php else: ?>
        <span style="color:var(--faint);font-size:11px;">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($hasData): ?>
    <tr id="<?= $diffId ?>" style="display:none;">
      <td colspan="6" style="background:var(--bg);padding:12px 16px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <div style="font-size:10px;font-weight:700;color:var(--faint);
                        text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">Before</div>
            <pre style="font-size:11px;font-family:var(--mono);color:var(--text2);
                        background:var(--white);padding:10px;border-radius:6px;
                        border:1px solid var(--border);overflow-x:auto;margin:0;"><?= $log['before_data']
  ? htmlspecialchars(json_encode(json_decode($log['before_data']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
  : '(none)' ?></pre>
          </div>
          <div>
            <div style="font-size:10px;font-weight:700;color:var(--faint);
                        text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">After</div>
            <pre style="font-size:11px;font-family:var(--mono);color:var(--text2);
                        background:var(--white);padding:10px;border-radius:6px;
                        border:1px solid var(--border);overflow-x:auto;margin:0;"><?= $log['after_data']
  ? htmlspecialchars(json_encode(json_decode($log['after_data']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
  : '(none)' ?></pre>
          </div>
        </div>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Pagination ── -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <?php
    $isActive  = $p === $page;
    $params2   = array_filter(['entity' => $entityType, 'q' => $search, 'from' => $dateFrom, 'to' => $dateTo, 'page' => $p]);
    $queryStr  = http_build_query($params2);
  ?>
  <a href="?<?= $queryStr ?>"
     style="padding:5px 11px;border-radius:6px;font-size:12px;font-family:var(--mono);
            border:1px solid <?= $isActive ? 'var(--p)' : 'var(--border)' ?>;
            background:<?= $isActive ? 'var(--p)' : 'transparent' ?>;
            color:<?= $isActive ? '#fff' : 'var(--muted)' ?>;
            text-decoration:none;">
    <?= $p ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function toggleDiff(id) {
  var row = document.getElementById(id);
  if (row) {
    row.style.display = row.style.display === 'none' ? '' : 'none';
  }
}
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Audit Trail | Admin';
require_once '../../views/layout-app.php';
