<?php
/**
 * SalesDesk — Organisation Context Dashboard.
 * T4 owns this file. Route: /app/broker/org-context.php
 *
 * Tasks o3, o6:
 *   - Organisation summary stats (leads, revenue, agent count)
 *   - Agent performance breakdown (owner/admin sees all; agent sees own)
 *   - Invite new members (owner/admin only)
 *   - Manage member roles (owner only)
 *   - Org CIPC verification status
 *
 * Requires active org context in $_SESSION['org_context'].
 * Context is set by the switcher in broker/dashboard.php.
 */
declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/mailer.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$orgId  = (int) ($_SESSION['org_context'] ?? 0);

// Must have an active org context
if (!$orgId) {
    redirect('/app/broker/dashboard.php');
}

// Fetch org + verify membership
$orgStmt = $pdo->prepare("
    SELECT o.id, o.name, o.slug, o.cipc_number, o.verification_status,
           o.logo_url, o.is_active, o.created_at,
           om.role AS my_role
    FROM organizations o
    JOIN organization_members om ON om.organization_id = o.id AND om.user_id = ?
    WHERE o.id = ? AND o.is_active = 1
    LIMIT 1
");
$orgStmt->execute([$userId, $orgId]);
$org = $orgStmt->fetch();

if (!$org) {
    // No longer a member — clear context
    unset($_SESSION['org_context']);
    redirect('/app/broker/dashboard.php');
}

$myRole    = $org['my_role'];
$isOwner   = ($myRole === 'owner');
$isAdmin   = ($myRole === 'owner' || $myRole === 'admin');
$csrf      = generateCSRFToken();

$flash      = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // Invite a member (o4)
    if ($action === 'invite_member' && $isAdmin) {
        $inviteEmail = filter_var(trim($_POST['invite_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $inviteRole  = in_array($_POST['invite_role'] ?? '', ['admin','agent'], true)
                       ? $_POST['invite_role'] : 'agent';

        if (!$inviteEmail) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
            redirect('/app/broker/org-context.php');
        }

        // Check if already a member
        $memberCheck = $pdo->prepare("
            SELECT om.id FROM organization_members om
            JOIN users u ON u.id = om.user_id
            WHERE om.organization_id = ? AND u.email = ?
        ");
        $memberCheck->execute([$orgId, $inviteEmail]);
        if ($memberCheck->fetch()) {
            $_SESSION['flash_error'] = 'That person is already a member of this organisation.';
            redirect('/app/broker/org-context.php');
        }

        // Check if registered broker
        $userCheck = $pdo->prepare("SELECT id, status FROM users WHERE email = ? AND role = 'broker' LIMIT 1");
        $userCheck->execute([$inviteEmail]);
        $invitee = $userCheck->fetch();

        if ($invitee && $invitee['status'] === 'active') {
            // Already registered — add directly and notify
            $pdo->prepare("
                INSERT INTO organization_members (organization_id, user_id, role, invited_by, joined_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE role = VALUES(role)
            ")->execute([$orgId, $invitee['id'], $inviteRole, $userId]);

            // In-app notification to invitee
            $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, body, meta, created_at)
                VALUES (?, 'org_invite', ?, ?, ?, NOW())
            ")->execute([
                $invitee['id'],
                'You\'ve been added to ' . $org['name'],
                'You are now a member of ' . $org['name'] . ' as ' . ucfirst($inviteRole) . '.',
                json_encode(['org_id' => $orgId]),
            ]);

            // Email notification
            $orgName  = $org['name'];
            $dashUrl  = SITE_URL . '/app/broker/dashboard.php';
            $subject  = "You've been added to {$orgName} on SalesDesk";
            $body     = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">You've been added to {$orgName}</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  You've been added as a <strong>{$inviteRole}</strong> in <strong>{$orgName}</strong> on SalesDesk.
  Switch to the organisation context from your dashboard to view shared leads and commissions.
</p>
<a href="{$dashUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Go to my dashboard →
</a>
HTML;
            sendEmail($inviteEmail, $subject, $body);

            writeAuditLog('org.member_added', 'organization', $orgId,
                null, ['user_email' => $inviteEmail, 'role' => $inviteRole], $userId);

            $_SESSION['flash_ok'] = $inviteEmail . ' has been added as ' . ucfirst($inviteRole) . '.';
        } else {
            // Not registered — send invite email with register link
            $registerUrl = SITE_URL . '/auth/register.php?org_invite=' . urlencode($org['slug']);
            $orgName     = $org['name'];
            $subject     = "You've been invited to join {$orgName} on SalesDesk";
            $body        = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">Join {$orgName} on SalesDesk</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  You've been invited to join <strong>{$orgName}</strong> as a <strong>{$inviteRole}</strong>.
  Create your SalesDesk broker account to accept the invitation.
</p>
<a href="{$registerUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Create my account →
</a>
HTML;
            sendEmail($inviteEmail, $subject, $body);
            $_SESSION['flash_ok'] = 'Invite sent to ' . $inviteEmail . '. They\'ll receive an email to create their account.';
        }
        redirect('/app/broker/org-context.php');
    }

    // Change member role (owner only)
    if ($action === 'change_role' && $isOwner) {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        $newRole      = in_array($_POST['new_role'] ?? '', ['admin','agent'], true)
                        ? $_POST['new_role'] : 'agent';

        if ($targetUserId && $targetUserId !== $userId) {
            $pdo->prepare("
                UPDATE organization_members
                SET role = ?
                WHERE organization_id = ? AND user_id = ?
            ")->execute([$newRole, $orgId, $targetUserId]);

            writeAuditLog('org.member_role_changed', 'organization', $orgId,
                null, ['user_id' => $targetUserId, 'new_role' => $newRole], $userId);

            $_SESSION['flash_ok'] = 'Member role updated.';
        }
        redirect('/app/broker/org-context.php');
    }

    // Remove member (owner only)
    if ($action === 'remove_member' && $isOwner) {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        if ($targetUserId && $targetUserId !== $userId) {
            $pdo->prepare("
                DELETE FROM organization_members
                WHERE organization_id = ? AND user_id = ? AND role != 'owner'
            ")->execute([$orgId, $targetUserId]);

            writeAuditLog('org.member_removed', 'organization', $orgId,
                null, ['user_id' => $targetUserId], $userId);

            $_SESSION['flash_ok'] = 'Member removed from organisation.';
        }
        redirect('/app/broker/org-context.php');
    }
}

// ── Org stats ─────────────────────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT om2.user_id)                                     AS member_count,
        COUNT(DISTINCT l.id)                                            AS leads_all_time,
        COUNT(DISTINCT CASE
            WHEN l.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
            THEN l.id END)                                              AS leads_this_month,
        COUNT(DISTINCT CASE WHEN l.status='closed' THEN l.id END)      AS deals_closed,
        COALESCE(SUM(CASE WHEN cm.status='paid' THEN cm.net_amount END),0) AS total_earned
    FROM organizations o
    JOIN organization_members om2 ON om2.organization_id = o.id
    LEFT JOIN leads l  ON l.organization_id = o.id
    LEFT JOIN commissions cm ON cm.lead_id = l.id AND cm.organization_id = o.id
    WHERE o.id = ?
");
$statsStmt->execute([$orgId]);
$stats = $statsStmt->fetch();

$convRate = $stats['leads_all_time'] > 0
    ? round($stats['deals_closed'] / $stats['leads_all_time'] * 100, 1) : 0;

// ── Member list with per-agent stats ─────────────────────────
$membersStmt = $pdo->prepare("
    SELECT
        u.id, u.email,
        p.first_name, p.last_name, p.avatar_url,
        om.role, om.joined_at,
        sd.display_name AS desk_name, sd.slug AS desk_slug,
        COUNT(DISTINCT l.id)                                             AS total_leads,
        SUM(CASE WHEN l.status='closed' THEN 1 ELSE 0 END)              AS deals_closed,
        COALESCE(SUM(CASE WHEN cm.status='paid' THEN cm.net_amount END),0) AS earned
    FROM organization_members om
    JOIN users u   ON u.id = om.user_id
    LEFT JOIN profiles   p  ON p.user_id  = u.id
    LEFT JOIN salesdesks sd ON sd.user_id = u.id
    LEFT JOIN leads l  ON l.broker_id = u.id AND l.organization_id = ?
    LEFT JOIN commissions cm ON cm.lead_id = l.id AND cm.organization_id = ?
    WHERE om.organization_id = ?
    GROUP BY u.id, om.role, om.joined_at
    ORDER BY FIELD(om.role,'owner','admin','agent'), p.first_name, p.last_name
");
$membersStmt->execute([$orgId, $orgId, $orgId]);
$members = $membersStmt->fetchAll();

// ── Recent org leads ──────────────────────────────────────────
$leadsStmt = $pdo->prepare("
    SELECT
        l.id, l.buyer_name, l.buyer_intent, l.status, l.created_at,
        c.make, c.model, c.year,
        p.first_name AS broker_first, p.last_name AS broker_last,
        sd.display_name AS desk_name
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    JOIN users u ON u.id = l.broker_id
    LEFT JOIN profiles p ON p.user_id = l.broker_id
    LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
    WHERE l.organization_id = ?
    ORDER BY l.created_at DESC
    LIMIT 10
");
$leadsStmt->execute([$orgId]);
$recentLeads = $leadsStmt->fetchAll();

$pageTitle = $org['name'] . ' — Organisation';
ob_start();
?>

<!-- Context bar -->
<div class="context-bar" style="margin-bottom:1.25rem;">
  <div class="context-options">
    <a href="/app/broker/dashboard.php?switch_context=personal" class="context-opt">
      <i class="fa-solid fa-id-card"></i> My Desk
    </a>
    <span class="context-opt active">
      <i class="fa-solid fa-building"></i> <?= htmlspecialchars($org['name']) ?>
    </span>
  </div>
</div>

<!-- Page header -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;
            margin-bottom:1.75rem;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-family:var(--serif);font-size:1.65rem;font-weight:300;margin-bottom:3px;">
      <?= htmlspecialchars($org['name']) ?>
    </h1>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <span style="font-size:13px;color:var(--muted);">
        <i class="fa-solid fa-users" style="font-size:11px;margin-right:4px;"></i>
        <?= $stats['member_count'] ?> member<?= $stats['member_count'] != 1 ? 's' : '' ?>
      </span>
      <?php if ($org['verification_status'] === 'verified'): ?>
      <span class="badge badge-verified">
        <i class="fa-solid fa-circle-check" style="font-size:9px;"></i> Verified
      </span>
      <?php else: ?>
      <span class="badge badge-pending">
        <i class="fa-solid fa-clock" style="font-size:9px;"></i>
        <?= ucfirst($org['verification_status']) ?>
      </span>
      <?php endif; ?>
      <span class="badge" style="background:var(--bg);color:var(--muted);border-color:var(--border);">
        <?= ucfirst($myRole) ?>
      </span>
    </div>
  </div>
  <?php if ($isAdmin): ?>
  <button class="btn btn-primary btn-sm"
          onclick="document.getElementById('inviteModal').classList.add('open')">
    <i class="fa-solid fa-user-plus"></i> Invite member
  </button>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem">
  <i class="fa-solid fa-circle-check alert-icon"></i> <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i> <?= htmlspecialchars($flashError) ?>
</div>
<?php endif; ?>

<!-- KPI strip -->
<div class="org-stat-strip">
  <div class="org-stat-box">
    <div class="org-stat-num" style="color:var(--p);"><?= $stats['leads_this_month'] ?></div>
    <div class="org-stat-label">Leads this month</div>
  </div>
  <div class="org-stat-box">
    <div class="org-stat-num" style="color:var(--green);"><?= $stats['deals_closed'] ?></div>
    <div class="org-stat-label">Deals closed</div>
  </div>
  <div class="org-stat-box">
    <div class="org-stat-num" style="color:var(--teal);"><?= $convRate ?>%</div>
    <div class="org-stat-label">Conversion rate</div>
  </div>
  <div class="org-stat-box">
    <div class="org-stat-num" style="color:var(--amber);font-size:1.1rem;"><?= formatZAR((float)$stats['total_earned']) ?></div>
    <div class="org-stat-label">Total earned (paid)</div>
  </div>
</div>

<!-- Main grid -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:1.5rem;align-items:start;">

  <!-- Left: members + recent leads -->
  <div>

    <!-- Members table -->
    <div style="margin-bottom:1.75rem;">
      <div class="section-head">
        <h2 class="section-title">Members</h2>
        <span class="section-count"><?= count($members) ?></span>
      </div>
      <div class="roster-wrap">
        <table class="roster">
          <thead>
            <tr>
              <th>Member</th>
              <th>Role</th>
              <th>Leads</th>
              <th>Closed</th>
              <th>Earned</th>
              <?php if ($isOwner): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($members as $m):
            $mName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: $m['email'];
            $mConv = $m['total_leads'] > 0 ? round($m['deals_closed'] / $m['total_leads'] * 100, 0) : 0;
            $isSelf = (int)$m['id'] === $userId;
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="avatar avatar-sm"
                     style="background:var(--p-light);color:var(--p);">
                  <?= strtoupper(substr($m['first_name'] ?? $m['email'], 0, 1) . substr($m['last_name'] ?? '', 0, 1)) ?>
                </div>
                <div>
                  <div style="font-size:13px;font-weight:500;color:var(--text);">
                    <?= htmlspecialchars($mName) ?>
                    <?php if ($isSelf): ?>
                    <span style="font-size:10px;color:var(--faint);font-family:var(--mono);margin-left:4px;">(you)</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($m['desk_name']): ?>
                  <div style="font-size:11px;color:var(--faint);"><?= htmlspecialchars($m['desk_name']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <span class="badge org-role-<?= $m['role'] ?>"><?= ucfirst($m['role']) ?></span>
            </td>
            <td style="font-family:var(--mono);font-size:12px;"><?= $m['total_leads'] ?></td>
            <td style="font-family:var(--mono);font-size:12px;color:var(--green);"><?= $m['deals_closed'] ?></td>
            <td style="font-family:var(--mono);font-size:12px;">
              <?= $m['earned'] > 0 ? 'R ' . number_format($m['earned'], 0) : '—' ?>
            </td>
            <?php if ($isOwner): ?>
            <td>
              <?php if (!$isSelf && $m['role'] !== 'owner'): ?>
              <div style="display:flex;gap:4px;">
                <form method="POST" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="target_user_id" value="<?= $m['id'] ?>">
                  <input type="hidden" name="new_role"
                         value="<?= $m['role'] === 'admin' ? 'agent' : 'admin' ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"
                          style="font-size:11px;"
                          title="<?= $m['role'] === 'admin' ? 'Demote to agent' : 'Promote to admin' ?>">
                    <?= $m['role'] === 'admin' ? '↓ Agent' : '↑ Admin' ?>
                  </button>
                </form>
                <form method="POST" style="margin:0;"
                      onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($mName)) ?> from the organisation?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="remove_member">
                  <input type="hidden" name="target_user_id" value="<?= $m['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit" style="font-size:11px;">
                    <i class="fa-solid fa-xmark"></i>
                  </button>
                </form>
              </div>
              <?php elseif ($isSelf): ?>
              <span style="font-size:11px;color:var(--faint);">—</span>
              <?php else: ?>
              <span style="font-size:11px;color:var(--faint);">Owner</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Recent leads -->
    <div>
      <div class="section-head">
        <h2 class="section-title">Recent leads</h2>
        <span class="section-count"><?= count($recentLeads) ?></span>
      </div>
      <?php if (empty($recentLeads)): ?>
      <div class="empty">
        <span class="empty-icon"><i class="fa-solid fa-inbox"></i></span>
        No leads generated under this organisation yet.
      </div>
      <?php else: ?>
      <div class="roster-wrap">
        <?php foreach ($recentLeads as $lead):
          $intentColour = match($lead['buyer_intent']) {
            'within_30d' => 'var(--green)', 'one_to_3mo' => 'var(--amber)', default => 'var(--faint)'
          };
          $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
          $ageLabel = $ageHours < 24 ? round($ageHours) . 'h ago' : round($ageHours / 24) . 'd ago';
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;
                    border-bottom:1px solid var(--border);">
          <div style="width:8px;height:8px;border-radius:50%;flex-shrink:0;
                      background:<?= $intentColour ?>"></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:500;color:var(--text);">
              <?= htmlspecialchars($lead['buyer_name']) ?>
            </div>
            <div style="font-size:11px;color:var(--muted);">
              <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
              · <?= htmlspecialchars($lead['desk_name'] ?? '') ?>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:10px;font-family:var(--mono);font-weight:600;
                        color:var(--p);text-transform:uppercase;"><?= $lead['status'] ?></div>
            <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $ageLabel ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Right: org info + agent leaderboard -->
  <div style="display:flex;flex-direction:column;gap:12px;">

    <!-- Org info card -->
    <div class="card card-body">
      <h3 style="font-size:13px;font-weight:600;margin-bottom:1rem;">
        <i class="fa-solid fa-building" style="margin-right:6px;color:var(--muted);"></i>
        Organisation details
      </h3>
      <div style="font-size:12px;color:var(--muted);line-height:2.2;">
        <strong style="color:var(--text);">Name:</strong> <?= htmlspecialchars($org['name']) ?><br>
        <?php if ($org['cipc_number']): ?>
        <strong style="color:var(--text);">CIPC:</strong>
        <span style="font-family:var(--mono);"><?= htmlspecialchars($org['cipc_number']) ?></span><br>
        <?php endif; ?>
        <strong style="color:var(--text);">Verification:</strong>
        <span style="color:<?= $org['verification_status'] === 'verified' ? 'var(--green)' : 'var(--amber)' ?>;font-weight:500;">
          <?= ucfirst($org['verification_status']) ?>
        </span><br>
        <strong style="color:var(--text);">Created:</strong>
        <?= date('d F Y', strtotime($org['created_at'])) ?>
      </div>
      <?php if ($org['verification_status'] !== 'verified' && $isOwner): ?>
      <div class="alert alert-info" style="margin-top:12px;">
        <i class="fa-solid fa-circle-info alert-icon"></i>
        <div style="font-size:12px;">
          Upload your CIPC certificate to get a verified badge.
          <a href="/app/broker/org-settings.php" style="color:var(--p);font-weight:600;">
            Organisation settings →
          </a>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Top agents leaderboard -->
    <?php if ($isAdmin && count($members) > 1): ?>
    <div class="card card-body">
      <h3 style="font-size:13px;font-weight:600;margin-bottom:1rem;">
        <i class="fa-solid fa-trophy" style="margin-right:6px;color:var(--amber);"></i>
        Top agents this month
      </h3>
      <?php
      $sorted = $members;
      usort($sorted, fn($a, $b) => $b['total_leads'] - $a['total_leads']);
      $rank = 0;
      foreach (array_slice($sorted, 0, 5) as $m):
        $rank++;
        $mName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: $m['email'];
        $medalColors = ['#FFD700','#C0C0C0','#CD7F32'];
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0;
                  border-bottom:<?= $rank < 5 ? '1px solid var(--border)' : 'none' ?>;">
        <div style="width:20px;text-align:center;font-size:12px;
                    color:<?= isset($medalColors[$rank-1]) ? $medalColors[$rank-1] : 'var(--faint)' ?>;
                    font-weight:700;flex-shrink:0;">
          <?= $rank ?>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:12px;font-weight:500;color:var(--text);
                      overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($mName) ?>
          </div>
          <div style="font-size:10px;color:var(--faint);">
            <?= $m['deals_closed'] ?> closed / <?= $m['total_leads'] ?> leads
          </div>
        </div>
        <div style="font-size:11px;font-family:var(--mono);color:var(--muted);flex-shrink:0;">
          <?= $m['total_leads'] ?> leads
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Commission summary -->
    <div class="card card-body">
      <h3 style="font-size:13px;font-weight:600;margin-bottom:.75rem;">
        <i class="fa-solid fa-coins" style="margin-right:6px;color:var(--green);"></i>
        Org commissions
      </h3>
      <?php
      $commStmt = $pdo->prepare("
          SELECT status, COUNT(*) AS cnt,
                 COALESCE(SUM(net_amount),0) AS total
          FROM commissions
          WHERE organization_id = ?
          GROUP BY status
          ORDER BY FIELD(status,'pending','approved','scheduled','processing','paid','failed')
      ");
      $commStmt->execute([$orgId]);
      $commRows = $commStmt->fetchAll();
      if (empty($commRows)):
      ?>
      <div style="font-size:12px;color:var(--faint);text-align:center;padding:.75rem 0;">
        No commissions yet.
      </div>
      <?php else: ?>
      <?php foreach ($commRows as $cr):
        $crColor = match($cr['status']) {
          'paid'      => 'var(--green)', 'pending' => 'var(--amber)',
          'failed'    => 'var(--red)', default => 'var(--muted)'
        };
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;
                  padding:7px 0;border-bottom:1px solid var(--border);">
        <div>
          <span style="font-size:12px;color:var(--muted);text-transform:capitalize;"><?= $cr['status'] ?></span>
          <span style="font-size:10px;color:var(--faint);margin-left:5px;"><?= $cr['cnt'] ?> record<?= $cr['cnt'] != 1 ? 's' : '' ?></span>
        </div>
        <span style="font-size:13px;font-weight:600;font-family:var(--mono);color:<?= $crColor ?>;">
          <?= formatZAR((float)$cr['total']) ?>
        </span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

</div>

<!-- ── Invite member modal ─────────────────────────────────── -->
<?php if ($isAdmin): ?>
<div class="modal-bg" id="inviteModal">
  <div class="modal">
    <div class="modal-title">Invite a member</div>
    <p class="modal-sub">
      Enter their email address. If they're already on SalesDesk they'll be added
      immediately — otherwise we'll send them an invite to register.
    </p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="invite_member">
      <div class="fgroup">
        <label class="flabel" for="invite_email">Email address</label>
        <input class="finput" type="email" id="invite_email" name="invite_email"
               required maxlength="255" autocomplete="email"
               placeholder="colleague@example.com">
      </div>
      <div class="fgroup">
        <label class="flabel" for="invite_role">Role</label>
        <select class="finput" id="invite_role" name="invite_role">
          <option value="agent">Agent — sees only their own leads and earnings</option>
          <?php if ($isOwner): ?>
          <option value="admin">Admin — sees all org leads and can invite members</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost"
                onclick="document.getElementById('inviteModal').classList.remove('open')">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary">
          <i class="fa-solid fa-paper-plane"></i> Send invite
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('inviteModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-bg.open')
      .forEach(function(m) { m.classList.remove('open'); });
  }
});
</script>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
