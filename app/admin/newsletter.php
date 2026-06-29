<?php
/**
 * SalesDesk — Admin: Newsletter Management
 *
 * Two tabs:
 *   Subscribers — paginated list with status badges, export CSV action
 *   Campaigns   — list of all campaigns, "New campaign" button
 *
 * Write actions on subscribers: mark_bounced, delete_subscriber
 * Write actions on campaigns:   cancel (draft only), delete (draft only)
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo     = Database::getInstance();
$adminId = (int) $_SESSION['user_id'];
$tab     = $_GET['tab'] ?? 'subscribers';

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_bounced') {
        $sid = (int) ($_POST['subscriber_id'] ?? 0);
        if ($sid > 0) {
            $pdo->prepare("UPDATE newsletter_subscribers SET status='bounced', updated_at=NOW() WHERE id=?")
                ->execute([$sid]);
            $_SESSION['flash_ok'] = 'Subscriber marked as bounced.';
        }
        redirect('/app/admin/newsletter.php?tab=subscribers');
    }

    if ($action === 'delete_subscriber') {
        $sid = (int) ($_POST['subscriber_id'] ?? 0);
        if ($sid > 0) {
            $row = $pdo->prepare('SELECT email FROM newsletter_subscribers WHERE id=?');
            $row->execute([$sid]);
            $email = $row->fetchColumn();
            $pdo->prepare('DELETE FROM newsletter_subscribers WHERE id=?')->execute([$sid]);
            writeAuditLog('newsletter.subscriber_deleted', 'newsletter_subscriber', $sid,
                ['email' => $email], null, $adminId);
            $_SESSION['flash_ok'] = 'Subscriber deleted.';
        }
        redirect('/app/admin/newsletter.php?tab=subscribers');
    }

    if ($action === 'delete_campaign') {
        $cid = (int) ($_POST['campaign_id'] ?? 0);
        if ($cid > 0) {
            $row = $pdo->prepare("SELECT subject, status FROM newsletter_campaigns WHERE id=?");
            $row->execute([$cid]);
            $campaign = $row->fetch();
            if ($campaign && in_array($campaign['status'], ['draft','cancelled'])) {
                $pdo->prepare('DELETE FROM newsletter_campaigns WHERE id=?')->execute([$cid]);
                writeAuditLog('newsletter.campaign_deleted', 'newsletter_campaign', $cid,
                    ['subject' => $campaign['subject']], null, $adminId);
                $_SESSION['flash_ok'] = 'Campaign deleted.';
            } else {
                $_SESSION['flash_error'] = 'Only draft or cancelled campaigns can be deleted.';
            }
        }
        redirect('/app/admin/newsletter.php?tab=campaigns');
    }

    if ($action === 'cancel_campaign') {
        $cid = (int) ($_POST['campaign_id'] ?? 0);
        if ($cid > 0) {
            $pdo->prepare("UPDATE newsletter_campaigns SET status='cancelled', updated_at=NOW() WHERE id=? AND status='draft'")
                ->execute([$cid]);
            writeAuditLog('newsletter.campaign_cancelled', 'newsletter_campaign', $cid, null, null, $adminId);
            $_SESSION['flash_ok'] = 'Campaign cancelled.';
        }
        redirect('/app/admin/newsletter.php?tab=campaigns');
    }
}

// ── Subscriber stats (for header cards) ───────────────────────
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active')       AS active,
        SUM(status = 'pending')      AS pending,
        SUM(status = 'unsubscribed') AS unsubscribed,
        SUM(status = 'bounced')      AS bounced
    FROM newsletter_subscribers
")->fetch();

// ── Subscribers list ──────────────────────────────────────────
$subSearch  = trim($_GET['q'] ?? '');
$subStatus  = $_GET['sub_status'] ?? '';
$subPage    = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 50;
$subOffset  = ($subPage - 1) * $perPage;

$swhere  = ['1=1'];
$sparams = [];
if ($subStatus) { $swhere[] = 's.status = ?'; $sparams[] = $subStatus; }
if ($subSearch) { $swhere[] = 's.email LIKE ?'; $sparams[] = '%'.$subSearch.'%'; }
$swClause = implode(' AND ', $swhere);

$subCountStmt = $pdo->prepare("SELECT COUNT(*) FROM newsletter_subscribers s WHERE {$swClause}");
$subCountStmt->execute($sparams);
$subTotal  = (int) $subCountStmt->fetchColumn();
$subPages  = (int) ceil($subTotal / $perPage);

$subStmt = $pdo->prepare("
    SELECT id, email, first_name, status, source, confirmed_at, subscribed_at
    FROM newsletter_subscribers s
    WHERE {$swClause}
    ORDER BY s.subscribed_at DESC
    LIMIT ? OFFSET ?
");
$subStmt->execute(array_merge($sparams, [$perPage, $subOffset]));
$subscribers = $subStmt->fetchAll();

// ── Campaigns list ─────────────────────────────────────────────
$campaigns = $pdo->query("
    SELECT
        c.id, c.uuid, c.subject, c.status,
        c.total_recipients, c.sent_count,
        c.created_at, c.sent_at,
        u.email AS author_email
    FROM newsletter_campaigns c
    JOIN users u ON u.id = c.created_by
    ORDER BY c.created_at DESC
    LIMIT 100
")->fetchAll();

// ── Render ────────────────────────────────────────────────────
ob_start();
?>

<!-- Header stats cards -->
<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title">Newsletter</h1>
</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1.75rem;">
  <?php
  $statCards = [
    ['label' => 'Active subscribers', 'value' => number_format((int)$stats['active']),   'color' => 'var(--green)'],
    ['label' => 'Pending confirm',     'value' => number_format((int)$stats['pending']),  'color' => 'var(--amber)'],
    ['label' => 'Unsubscribed',        'value' => number_format((int)$stats['unsubscribed']), 'color' => 'var(--muted)'],
    ['label' => 'Bounced',             'value' => number_format((int)$stats['bounced']),  'color' => 'var(--red)'],
  ];
  foreach ($statCards as $sc):
  ?>
  <div class="card card-body" style="text-align:center;">
    <div style="font-size:22px;font-weight:700;color:<?= $sc['color'] ?>;font-family:var(--mono);">
      <?= $sc['value'] ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;"><?= $sc['label'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tab nav -->
<div style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:1.5rem;padding-bottom:0;">
  <?php foreach (['subscribers' => 'Subscribers', 'campaigns' => 'Campaigns'] as $t => $label): ?>
  <a href="?tab=<?= $t ?>"
     style="padding:8px 16px;font-size:13px;font-weight:<?= $tab === $t ? '600' : '400' ?>;
            color:<?= $tab === $t ? 'var(--p)' : 'var(--muted)' ?>;
            border-bottom:<?= $tab === $t ? '2px solid var(--p)' : 'none' ?>;
            text-decoration:none;margin-bottom:-1px;">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'subscribers'): ?>

<!-- ── SUBSCRIBERS tab ── -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;">
  <input type="hidden" name="tab" value="subscribers">
  <input class="finput" name="q" value="<?= htmlspecialchars($subSearch) ?>"
         placeholder="Search email…" style="max-width:280px;">
  <select class="finput" name="sub_status" style="max-width:160px;">
    <option value="">All statuses</option>
    <?php foreach (['active','pending','unsubscribed','bounced'] as $ss): ?>
    <option value="<?= $ss ?>" <?= $subStatus === $ss ? 'selected' : '' ?>><?= ucfirst($ss) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
  <?php if ($subSearch || $subStatus): ?>
  <a href="?tab=subscribers" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($subscribers)): ?>
<div class="empty">
  <span class="empty-icon">📧</span>No subscribers match your filters.
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Email</th>
        <th>Name</th>
        <th>Status</th>
        <th>Source</th>
        <th>Confirmed</th>
        <th>Joined</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($subscribers as $s): ?>
    <?php
    $statusBadge = match($s['status']) {
      'active'       => 'badge-active',
      'pending'      => 'badge-pending',
      'unsubscribed' => 'badge-suspended',
      'bounced'      => 'badge-suspended',
      default        => '',
    };
    ?>
    <tr>
      <td style="font-size:13px;font-family:var(--mono);"><?= htmlspecialchars($s['email']) ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars($s['first_name'] ?? '—') ?></td>
      <td><span class="badge <?= $statusBadge ?>"><?= $s['status'] ?></span></td>
      <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($s['source']) ?></td>
      <td style="font-size:12px;color:<?= $s['confirmed_at'] ? 'var(--green)' : 'var(--faint)' ?>;">
        <?= $s['confirmed_at'] ? date('d M Y', strtotime($s['confirmed_at'])) : '—' ?>
      </td>
      <td style="font-size:11px;color:var(--faint);">
        <?= date('d M Y', strtotime($s['subscribed_at'])) ?>
      </td>
      <td style="display:flex;gap:6px;">
        <?php if ($s['status'] !== 'bounced'): ?>
        <form method="POST" style="display:inline;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="mark_bounced">
          <input type="hidden" name="subscriber_id" value="<?= $s['id'] ?>">
          <button class="btn btn-warn btn-sm" type="submit"
                  onclick="return confirm('Mark as bounced?')">Bounced</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="display:inline;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="delete_subscriber">
          <input type="hidden" name="subscriber_id" value="<?= $s['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit"
                  onclick="return confirm('Permanently delete this subscriber? (POPIA right to erasure)')">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($subPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;">
  <?php for ($pg = 1; $pg <= $subPages; $pg++): ?>
  <?php $qs = http_build_query(array_filter(['tab' => 'subscribers', 'q' => $subSearch, 'sub_status' => $subStatus, 'page' => $pg])); ?>
  <a href="?<?= $qs ?>"
     style="padding:5px 11px;border-radius:6px;font-size:12px;font-family:var(--mono);
            border:1px solid <?= $pg === $subPage ? 'var(--p)' : 'var(--border)' ?>;
            background:<?= $pg === $subPage ? 'var(--p)' : 'transparent' ?>;
            color:<?= $pg === $subPage ? '#fff' : 'var(--muted)' ?>;text-decoration:none;">
    <?= $pg ?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<div style="font-size:12px;color:var(--faint);margin-top:12px;text-align:right;">
  Showing <?= count($subscribers) ?> of <?= number_format($subTotal) ?> subscriber(s)
</div>
<?php endif; ?>

<?php else: /* tab = campaigns */ ?>

<!-- ── CAMPAIGNS tab ── -->
<div style="display:flex;justify-content:flex-end;margin-bottom:1.25rem;">
  <a href="/app/admin/newsletter-compose.php" class="btn btn-primary btn-sm">+ New campaign</a>
</div>

<?php if (empty($campaigns)): ?>
<div class="empty">
  <span class="empty-icon">✉️</span>
  No campaigns yet.
  <a href="/app/admin/newsletter-compose.php" class="btn btn-primary btn-sm" style="margin-left:12px;">
    Create your first campaign
  </a>
</div>
<?php else: ?>
<div class="roster-wrap">
  <table class="roster">
    <thead>
      <tr>
        <th>Subject</th>
        <th>Status</th>
        <th>Recipients</th>
        <th>Sent</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
    <?php
    $cStatusBadge = match($c['status']) {
      'sent'      => 'badge-active',
      'sending'   => 'badge-new',
      'draft'     => 'badge-pending',
      'cancelled' => 'badge-suspended',
      default     => '',
    };
    ?>
    <tr>
      <td style="font-weight:500;font-size:13px;max-width:320px;
                 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?= htmlspecialchars($c['subject']) ?>
      </td>
      <td><span class="badge <?= $cStatusBadge ?>"><?= $c['status'] ?></span></td>
      <td style="font-family:var(--mono);font-size:12px;">
        <?php if ($c['status'] === 'sent'): ?>
          <?= number_format((int)$c['sent_count']) ?>
          <span style="color:var(--faint);"> / <?= number_format((int)$c['total_recipients']) ?></span>
        <?php else: ?>
          <?= number_format((int)$c['total_recipients']) ?>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--faint);">
        <?= $c['sent_at'] ? date('d M Y H:i', strtotime($c['sent_at'])) : '—' ?>
      </td>
      <td style="font-size:11px;color:var(--faint);">
        <?= date('d M Y', strtotime($c['created_at'])) ?>
      </td>
      <td style="display:flex;gap:6px;">
        <?php if ($c['status'] === 'draft'): ?>
        <a href="/app/admin/newsletter-compose.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
        <a href="/app/admin/newsletter-compose.php?id=<?= $c['id'] ?>&preview=1" class="btn btn-ghost btn-sm">Preview</a>
        <form method="POST" style="display:inline;">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="delete_campaign">
          <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit"
                  onclick="return confirm('Delete this draft campaign?')">Delete</button>
        </form>
        <?php elseif ($c['status'] === 'sent'): ?>
        <a href="/app/admin/newsletter-compose.php?id=<?= $c['id'] ?>&preview=1" class="btn btn-ghost btn-sm">View</a>
        <?php else: ?>
        <span style="font-size:11px;color:var(--faint);">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; // end tab switch ?>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Newsletter | Admin';
require_once '../../views/layout-app.php';
