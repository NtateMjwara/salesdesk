<?php
/**
 * SalesDesk — Admin: Newsletter Compose & Send
 *
 * GET  ?id={n}           — edit draft campaign
 * GET  ?id={n}&preview=1 — read-only preview (for sent/cancelled)
 * POST action=save       — save / update draft
 * POST action=send_test  — send test email to the logged-in admin
 * POST action=send       — send to all active subscribers (synchronous MVP)
 *
 * Send strategy: synchronous PHP loop with set_time_limit(0).
 * Suitable for lists up to ~2 000 subscribers on a standard shared host.
 * For larger lists, migrate to a background queue (Redis/BeanstalkD/cron).
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';
require_once '../../includes/newsletter.php';
require_once '../../includes/mailer.php';

applyCachePolicy('auth');
requireRole('admin');

$pdo      = Database::getInstance();
$adminId  = (int) $_SESSION['user_id'];
$editId   = (int) ($_GET['id'] ?? 0);
$preview  = !empty($_GET['preview']);
$errors   = [];
$campaign = [];

// Fetch existing campaign
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $campaign = $stmt->fetch();
    if (!$campaign) {
        $_SESSION['flash_error'] = 'Campaign not found.';
        redirect('/app/admin/newsletter.php?tab=campaigns');
    }
}

// Read-only preview mode (for sent/cancelled campaigns)
$isReadOnly = $preview || (isset($campaign['status']) && $campaign['status'] === 'sent');

// Admin email for test sends
$adminEmail = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$adminEmail->execute([$adminId]);
$adminEmailStr = $adminEmail->fetchColumn() ?: '';

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isReadOnly) {
    validateCSRF();
    $action      = $_POST['action'] ?? '';
    $subject     = trim($_POST['subject']      ?? '');
    $previewText = trim($_POST['preview_text'] ?? '');
    $content     = trim($_POST['content']      ?? '');

    // Validate
    if (!$subject)  $errors[] = 'Subject line is required.';
    if (!$content)  $errors[] = 'Email body cannot be empty.';

    if (empty($errors)) {

        // ── Save draft ─────────────────────────────────────
        if (in_array($action, ['save', 'send', 'send_test'])) {
            if ($editId > 0) {
                $pdo->prepare("
                    UPDATE newsletter_campaigns
                    SET subject = ?, preview_text = ?, content = ?, updated_at = NOW()
                    WHERE id = ? AND status = 'draft'
                ")->execute([$subject, $previewText ?: null, $content, $editId]);
            } else {
                $uuid = generateUuidV4();
                $pdo->prepare("
                    INSERT INTO newsletter_campaigns
                        (uuid, subject, preview_text, content, status, created_by, created_at, updated_at)
                    VALUES (?,?,?,?,'draft',?,NOW(),NOW())
                ")->execute([$uuid, $subject, $previewText ?: null, $content, $adminId]);
                $editId = (int) $pdo->lastInsertId();
                // Reload for further actions
                $stmt = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ?');
                $stmt->execute([$editId]);
                $campaign = $stmt->fetch();
            }

            writeAuditLog('newsletter.campaign_saved', 'newsletter_campaign', $editId,
                null, ['subject' => $subject], $adminId);
        }

        // ── Send test email ────────────────────────────────
        if ($action === 'send_test') {
            $testSub = [
                'email'             => $adminEmailStr,
                'first_name'        => 'Admin',
                'unsubscribe_token' => 'TEST-TOKEN-NOT-LIVE',
            ];
            $c = ['subject' => '[TEST] ' . $subject, 'content' => $content];
            if (sendNewsletterBroadcast($c, $testSub)) {
                $_SESSION['flash_ok'] = 'Test email sent to ' . $adminEmailStr . '.';
            } else {
                $_SESSION['flash_error'] = 'Test send failed — check SMTP config.';
            }
            redirect('/app/admin/newsletter-compose.php?id=' . $editId);
        }

        // ── Send to all active subscribers ─────────────────
        if ($action === 'send') {
            // Count active subscribers first
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'");
            $countStmt->execute();
            $totalRecipients = (int) $countStmt->fetchColumn();

            if ($totalRecipients === 0) {
                $_SESSION['flash_error'] = 'No active subscribers to send to.';
                redirect('/app/admin/newsletter-compose.php?id=' . $editId);
            }

            // Mark as sending + lock recipients count
            $pdo->prepare("
                UPDATE newsletter_campaigns
                SET status = 'sending', total_recipients = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$totalRecipients, $editId]);

            // Extend execution time for large lists
            set_time_limit(0);
            ignore_user_abort(true);

            $campaignRow = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ?');
            $campaignRow->execute([$editId]);
            $campaignData = $campaignRow->fetch();

            // Stream subscribers in batches to avoid loading entire list into memory
            $batchSize  = 50;
            $offset     = 0;
            $sentCount  = 0;
            $failCount  = 0;

            do {
                $batchStmt = $pdo->prepare("
                    SELECT email, first_name, unsubscribe_token
                    FROM newsletter_subscribers
                    WHERE status = 'active'
                    ORDER BY id ASC
                    LIMIT ? OFFSET ?
                ");
                $batchStmt->execute([$batchSize, $offset]);
                $batch = $batchStmt->fetchAll();

                foreach ($batch as $sub) {
                    if (sendNewsletterBroadcast($campaignData, $sub)) {
                        $sentCount++;
                    } else {
                        $failCount++;
                        error_log('[SalesDesk Newsletter] Failed to send to ' . $sub['email']);
                    }
                }

                // Update progress every batch
                $pdo->prepare("
                    UPDATE newsletter_campaigns SET sent_count = ?, updated_at = NOW() WHERE id = ?
                ")->execute([$sentCount, $editId]);

                $offset += $batchSize;
            } while (count($batch) === $batchSize);

            // Mark as sent
            $pdo->prepare("
                UPDATE newsletter_campaigns
                SET status = 'sent', sent_at = NOW(), sent_count = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$sentCount, $editId]);

            writeAuditLog('newsletter.campaign_sent', 'newsletter_campaign', $editId,
                ['status' => 'draft'],
                ['status' => 'sent', 'sent' => $sentCount, 'failed' => $failCount],
                $adminId
            );

            $msg = "Campaign sent to {$sentCount} subscriber(s).";
            if ($failCount > 0) {
                $msg .= " {$failCount} failed (check error log).";
            }
            $_SESSION['flash_ok'] = $msg;
            redirect('/app/admin/newsletter.php?tab=campaigns');
        }

        if ($action === 'save') {
            $_SESSION['flash_ok'] = 'Campaign saved as draft.';
            redirect('/app/admin/newsletter-compose.php?id=' . $editId);
        }
    }

    // Re-populate fields after error
    $campaign = array_merge($campaign, [
        'subject'      => $subject,
        'preview_text' => $previewText,
        'content'      => $content,
    ]);
}

// Active subscriber count for send button label
$activeSubs = (int) $pdo->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn();

$isEdit     = !empty($campaign['id']);
$pageHeading = match(true) {
    $isReadOnly && $isEdit => 'View Campaign',
    $isEdit                => 'Edit Campaign',
    default                => 'New Campaign',
};

ob_start();
?>

<!-- Quill CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css"
      crossorigin="anonymous" referrerpolicy="no-referrer">

<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title"><?= $pageHeading ?></h1>
  <a href="/app/admin/newsletter.php?tab=campaigns" class="btn btn-ghost btn-sm" style="margin-left:auto;">
    ← Back to campaigns
  </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-warn" style="margin-bottom:1.25rem;">
  <span class="alert-icon">⚠</span>
  <div><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div>
</div>
<?php endif; ?>

<?php if ($activeSubs === 0 && !$isReadOnly): ?>
<div class="alert alert-info" style="margin-bottom:1.25rem;">
  <span class="alert-icon">ℹ</span>
  <div>
    No active subscribers yet. You can still save and send test emails,
    but the <strong>Send to all</strong> button will be disabled until someone subscribes.
  </div>
</div>
<?php endif; ?>

<form method="POST" id="campaignForm">
  <?= csrf_hidden_field() ?>
  <?php if ($editId): ?><input type="hidden" name="campaign_id" value="<?= $editId ?>"><?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;">

    <!-- ── Main column ─────────────────────────────────────── -->
    <div>

      <!-- Subject -->
      <div class="fgroup">
        <label class="flabel" for="subject">Subject line <span style="color:var(--red);">*</span></label>
        <input class="finput" type="text" id="subject" name="subject" maxlength="250"
               value="<?= htmlspecialchars($campaign['subject'] ?? '') ?>"
               placeholder="e.g. This week's top car deals in South Africa 🚗"
               <?= $isReadOnly ? 'readonly' : '' ?>>
        <div style="font-size:11px;color:var(--faint);margin-top:4px;" id="subjectCount"></div>
      </div>

      <!-- Preview text -->
      <div class="fgroup">
        <label class="flabel" for="preview_text">
          Preview text
          <span style="color:var(--faint);font-weight:400;">(shown in inbox beneath subject)</span>
        </label>
        <input class="finput" type="text" id="preview_text" name="preview_text" maxlength="250"
               value="<?= htmlspecialchars($campaign['preview_text'] ?? '') ?>"
               placeholder="e.g. Ford Ranger review, 5 tips on negotiating, and more inside…"
               <?= $isReadOnly ? 'readonly' : '' ?>>
      </div>

      <!-- Email body -->
      <div class="fgroup">
        <label class="flabel">Email body <span style="color:var(--red);">*</span></label>
        <?php if ($isReadOnly): ?>
        <div style="border:1px solid var(--border);border-radius:var(--r-md);padding:20px;
                    background:#fff;min-height:300px;font-size:14px;line-height:1.75;">
          <?= $campaign['content'] ?? '' ?>
        </div>
        <?php else: ?>
        <div id="quillEditor" style="min-height:400px;border:1px solid var(--border);
                                      border-radius:var(--r-md);background:#fff;font-size:15px;"></div>
        <textarea name="content" id="contentInput" hidden><?= htmlspecialchars($campaign['content'] ?? '') ?></textarea>
        <div style="font-size:11px;color:var(--faint);margin-top:6px;">
          The SalesDesk header and footer are added automatically to every email.
          An unsubscribe link is also appended to every send for POPIA compliance.
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- ── Sidebar ──────────────────────────────────────────── -->
    <div>

      <!-- Campaign info (if existing) -->
      <?php if ($isEdit): ?>
      <div class="card card-body" style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px;">Campaign info</div>
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
          <tr>
            <td style="color:var(--faint);padding:4px 0;width:50%;">Status</td>
            <td>
              <?php
              $statusBadge = match($campaign['status'] ?? 'draft') {
                'sent'    => 'badge-active',
                'sending' => 'badge-new',
                'draft'   => 'badge-pending',
                default   => '',
              };
              ?>
              <span class="badge <?= $statusBadge ?>"><?= $campaign['status'] ?? 'draft' ?></span>
            </td>
          </tr>
          <?php if ($campaign['status'] === 'sent'): ?>
          <tr>
            <td style="color:var(--faint);padding:4px 0;">Sent at</td>
            <td><?= $campaign['sent_at'] ? date('d M Y H:i', strtotime($campaign['sent_at'])) : '—' ?></td>
          </tr>
          <tr>
            <td style="color:var(--faint);padding:4px 0;">Delivered</td>
            <td style="font-family:var(--mono);">
              <?= number_format((int)$campaign['sent_count']) ?>
              / <?= number_format((int)$campaign['total_recipients']) ?>
            </td>
          </tr>
          <?php endif; ?>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!$isReadOnly): ?>
      <!-- Action panel -->
      <div class="card card-body" style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:14px;">Actions</div>

        <!-- Save draft -->
        <button type="submit" name="action" value="save" class="btn btn-ghost"
                style="width:100%;margin-bottom:8px;">
          💾 Save draft
        </button>

        <!-- Send test -->
        <button type="submit" name="action" value="send_test" class="btn btn-ghost"
                style="width:100%;margin-bottom:8px;"
                onclick="document.getElementById('contentInput').value = quill ? quill.root.innerHTML : ''">
          🔍 Send test to me
        </button>
        <div style="font-size:11px;color:var(--faint);margin-bottom:16px;">
          Test email goes to <strong><?= htmlspecialchars($adminEmailStr) ?></strong>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:0 0 14px;">

        <!-- Send to all -->
        <button type="button" class="btn btn-success" style="width:100%;"
                onclick="openSendModal()" <?= $activeSubs === 0 ? 'disabled' : '' ?>>
          📤 Send to <?= number_format($activeSubs) ?> subscriber<?= $activeSubs !== 1 ? 's' : '' ?>
        </button>
        <?php if ($activeSubs === 0): ?>
        <div style="font-size:11px;color:var(--faint);margin-top:6px;">
          No active subscribers yet.
        </div>
        <?php else: ?>
        <div style="font-size:11px;color:var(--faint);margin-top:6px;">
          Sends to all <strong>active</strong> subscribers only.
          This cannot be undone.
        </div>
        <?php endif; ?>
      </div>

      <!-- Tips -->
      <div class="alert alert-info">
        <span class="alert-icon">💡</span>
        <div style="font-size:12px;line-height:1.6;">
          <strong>Tips:</strong><br>
          • Keep subject lines under 60 chars<br>
          • Use preview text to tease the content<br>
          • Always send a test first<br>
          • Unsubscribe link is added automatically
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</form>

<!-- Send confirmation modal -->
<?php if (!$isReadOnly): ?>
<div class="modal-bg" id="sendModal">
  <div class="modal">
    <div class="modal-title">Send campaign?</div>
    <p class="modal-sub">
      This will immediately send <strong><?= htmlspecialchars($campaign['subject'] ?? '(unsaved)') ?></strong>
      to <strong><?= number_format($activeSubs) ?> active subscriber<?= $activeSubs !== 1 ? 's' : '' ?></strong>.
      This action cannot be undone.
    </p>
    <div class="alert alert-warn" style="margin-bottom:1rem;">
      <span class="alert-icon">⚠</span>
      <div>The page may take a moment — do not close it until the send completes.</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="closeModal('sendModal')">Cancel</button>
      <button type="button" class="btn btn-success" onclick="doSend()">
        Yes, send now
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Quill JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
(function () {
  'use strict';

  var quill = null;

  /* ── Quill editor (only for editable mode) ────────────────── */
  var editorEl = document.getElementById('quillEditor');
  if (editorEl) {
    quill = new Quill('#quillEditor', {
      theme: 'snow',
      placeholder: 'Write your email content here…',
      modules: {
        toolbar: [
          [{ header: [2, 3, false] }],
          ['bold', 'italic', 'underline'],
          ['link', 'blockquote'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['clean']
        ]
      }
    });

    var existing = document.getElementById('contentInput').value;
    if (existing) {
      quill.root.innerHTML = existing;
    }

    document.getElementById('campaignForm').addEventListener('submit', function () {
      document.getElementById('contentInput').value = quill.root.innerHTML;
    });
  }

  /* ── Subject char counter ─────────────────────────────────── */
  var subjectEl  = document.getElementById('subject');
  var subCountEl = document.getElementById('subjectCount');
  if (subjectEl && subCountEl) {
    function updateCount() {
      var len   = subjectEl.value.length;
      var color = len > 60 ? 'var(--amber)' : 'var(--faint)';
      subCountEl.innerHTML = '<span style="color:' + color + '">' + len + ' / 60 chars</span>';
    }
    subjectEl.addEventListener('input', updateCount);
    updateCount();
  }

  /* ── Modal helpers ────────────────────────────────────────── */
  window.openSendModal = function () {
    // Copy Quill content before showing modal
    if (quill) {
      document.getElementById('contentInput').value = quill.root.innerHTML;
    }
    document.getElementById('sendModal').classList.add('open');
  };

  window.closeModal = function (id) {
    document.getElementById(id).classList.remove('open');
  };

  window.doSend = function () {
    // Build a hidden input and submit the form with action=send
    var form = document.getElementById('campaignForm');
    var inp  = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'action';
    inp.value = 'send';
    form.appendChild(inp);
    form.submit();
  };

  document.querySelectorAll('.modal-bg').forEach(function (bg) {
    bg.addEventListener('click', function (e) {
      if (e.target === bg) bg.classList.remove('open');
    });
  });

})();
</script>

<style>
.ql-toolbar.ql-snow { border:none;border-bottom:1px solid var(--border);border-radius:var(--r-md) var(--r-md) 0 0;background:var(--bg); }
.ql-container.ql-snow { border:none;font-family:var(--sans);font-size:14px;border-radius:0 0 var(--r-md) var(--r-md); }
.ql-editor { min-height:370px;padding:18px;line-height:1.75; }
.ql-editor p { margin-bottom:1em; }
</style>

<?php
$pageContent = ob_get_clean();
$pageTitle   = $pageHeading . ' | Newsletter | Admin';
require_once '../../views/layout-app.php';
