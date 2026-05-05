<?php
/**
 * SalesDesk — Notifications inbox.
 * T1 owns this file.
 *
 * Displays all notifications for the current user, paginated.
 * Marks all as read on page load via the API endpoint.
 * The bell link in layout-app.php points here.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';

applyCachePolicy('auth');
requireLogin();

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

// Total count for pagination.
$total = (int) $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?")
    ->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$userId}")->fetchColumn() : 0;

// Proper parameterised count.
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$countStmt->execute([$userId]);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

// Fetch this page.
$notifStmt = $pdo->prepare("
    SELECT id, type, title, body, meta, is_read, read_at, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$notifStmt->execute([$userId, $perPage, $offset]);
$notifications = $notifStmt->fetchAll();

// Render.
$pageTitle = 'Notifications';

ob_start();
?>

<div class="section-head">
  <h1 class="section-title">Notifications</h1>
  <?php if ($total > 0): ?>
  <span class="section-count"><?= $total ?></span>
  <?php endif; ?>
  <span style="flex:1"></span>
  <button class="btn btn-ghost btn-sm" id="markAllReadBtn">
    Mark all read
  </button>
</div>

<?php if (empty($notifications)): ?>
<div class="empty">
  <span class="empty-icon"><i class="fa-regular fa-bell"></i></span>
  You have no notifications yet.
</div>
<?php else: ?>

<div class="roster-wrap">
  <?php foreach ($notifications as $n): ?>
  <?php
    $isUnread = !(bool) $n['is_read'];
    $meta     = $n['meta'] ? json_decode($n['meta'], true) : [];
    $age      = strtotime($n['created_at']);
    $ageLabel = match(true) {
      (time() - $age) < 3600   => 'Just now',
      (time() - $age) < 86400  => round((time() - $age) / 3600) . 'h ago',
      (time() - $age) < 604800 => round((time() - $age) / 86400) . 'd ago',
      default                   => date('d M Y', $age),
    };
  ?>
  <div class="notif-row <?= $isUnread ? 'notif-unread' : '' ?>"
       data-id="<?= $n['id'] ?>"
       style="padding:14px 16px;border-bottom:1px solid var(--border);
              display:flex;gap:12px;align-items:flex-start;
              background:<?= $isUnread ? 'var(--p-light)' : 'var(--white)' ?>;">

    <!-- Icon -->
    <div style="width:36px;height:36px;border-radius:50%;flex-shrink:0;
                display:flex;align-items:center;justify-content:center;font-size:15px;
                background:<?= match($n['type']) {
                  'new_lead'         => 'var(--gr-bg)',
                  'payout_paid'      => 'var(--gr-bg)',
                  'payout_scheduled' => 'var(--p-light)',
                  'payout_failed'    => 'var(--red-bg)',
                  'lead_nudge'       => 'var(--amb-bg)',
                  'lead_stale_flag'  => 'var(--red-bg)',
                  default            => 'var(--bg)',
                } ?>;color:<?= match($n['type']) {
                  'new_lead'         => 'var(--green)',
                  'payout_paid'      => 'var(--green)',
                  'payout_scheduled' => 'var(--p)',
                  'payout_failed'    => 'var(--red)',
                  'lead_nudge'       => 'var(--amber)',
                  'lead_stale_flag'  => 'var(--red)',
                  default            => 'var(--muted)',
                } ?>;">
      <i class="fa-solid <?= match($n['type']) {
        'new_lead'         => 'fa-user-plus',
        'payout_paid'      => 'fa-circle-check',
        'payout_scheduled' => 'fa-clock',
        'payout_failed'    => 'fa-triangle-exclamation',
        'lead_nudge'       => 'fa-bell',
        'lead_stale_flag'  => 'fa-flag',
        default            => 'fa-circle-info',
      } ?>"></i>
    </div>

    <!-- Content -->
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:<?= $isUnread ? '600' : '400' ?>;
                  color:var(--text);margin-bottom:2px;">
        <?= htmlspecialchars($n['title']) ?>
      </div>
      <?php if ($n['body']): ?>
      <div style="font-size:12px;color:var(--muted);line-height:1.5;">
        <?= htmlspecialchars($n['body']) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Age + unread dot -->
    <div style="flex-shrink:0;text-align:right;">
      <div style="font-size:11px;color:var(--faint);font-family:var(--mono);"><?= $ageLabel ?></div>
      <?php if ($isUnread): ?>
      <div style="width:8px;height:8px;border-radius:50%;background:var(--p);
                  margin:4px 0 0 auto;"></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <a href="?page=<?= $p ?>"
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

<script>
// Mark all read via API, then update UI without a reload.
document.getElementById('markAllReadBtn').addEventListener('click', function () {
  fetch('/api/notifications/mark-read.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    // CSRF token injected automatically by global.js X-CSRF-Token header.
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    if (data.success) {
      // Visually clear unread state on all rows.
      document.querySelectorAll('.notif-unread').forEach(function (row) {
        row.style.background = 'var(--white)';
        row.classList.remove('notif-unread');
        var dot = row.querySelector('[style*="border-radius:50%;background:var(--p)"]');
        if (dot) dot.remove();
        var title = row.querySelector('[style*="font-weight:600"]');
        if (title) title.style.fontWeight = '400';
      });
      // Update nav badge — trigger a soft refresh of the badge count.
      var badge = document.querySelector('.notif-badge');
      if (badge) badge.remove();
    }
  })
  .catch(function () {
    // Silent fail — page reload will reflect correct state.
  });
});

// Auto-mark all read on page load (page 1 only, so pagination
// visits don't reset the read state for older pages).
<?php if ($page === 1): ?>
fetch('/api/notifications/mark-read.php', { method: 'POST' });
<?php endif; ?>
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-app.php';
