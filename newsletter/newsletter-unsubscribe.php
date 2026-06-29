<?php
/**
 * SalesDesk — Newsletter: Unsubscribe
 * Route: /newsletter/unsubscribe/?token={unsubscribe_token}
 *
 * One-click unsubscribe — no login required.
 * Token is embedded in every outgoing campaign email by sendNewsletterBroadcast().
 *
 * POPIA: subscribers have the right to withdraw consent at any time.
 * This page honours that immediately with no dark patterns.
 *
 * GET with no token  → show "enter your email" fallback form
 * GET with token     → unsubscribe immediately and show confirmation
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';
require_once '../includes/newsletter.php';
require_once '../includes/response.php';

applyCachePolicy('public');

$token = trim($_GET['token'] ?? '');

// Handle re-subscribe (POST) without a token — edge-case recovery
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['resub_email'])) {
    $email = trim($_POST['resub_email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result = subscribeEmail($email, null, 'unsubscribe_page');
        if ($result['ok'] && isset($result['token'])) {
            sendNewsletterConfirmation($email, null, $result['token']);
        }
    }
    redirect('/newsletter/unsubscribe/?resubscribed=1');
}

$resubscribed = !empty($_GET['resubscribed']);

// Process unsubscribe
$result     = ['ok' => false];
$subscriber = null;

if ($token) {
    $result     = unsubscribeByToken($token);
    $subscriber = $result['subscriber'] ?? null;
}

$pageTitle    = 'Unsubscribe | SalesDesk Newsletter';
$ogTitle      = 'Unsubscribe from SalesDesk Newsletter';
$ogDescription = 'Manage your SalesDesk newsletter subscription.';
$canonicalUrl = (defined('SITE_URL') ? SITE_URL : '') . '/newsletter/unsubscribe/';
$layoutVariant = 'narrow';

ob_start();
?>

<div style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:48px 24px;">
  <div style="max-width:460px;width:100%;text-align:center;">

    <?php if ($resubscribed): ?>
    <!-- ── Re-subscribed ── -->
    <div style="font-size:48px;margin-bottom:18px;">🎉</div>
    <h1 style="font-family:var(--font-d);font-size:24px;font-weight:700;color:#15803d;margin-bottom:12px;">
      Check your inbox!
    </h1>
    <p style="font-size:15px;color:var(--muted);line-height:1.7;margin-bottom:28px;">
      We've sent you a fresh confirmation link. Click it to re-activate your subscription.
    </p>

    <?php elseif ($token && $result['ok']): ?>
    <!-- ── Successfully unsubscribed ── -->
    <div style="font-size:48px;margin-bottom:18px;">👋</div>
    <h1 style="font-family:var(--font-d);font-size:24px;font-weight:700;color:var(--text);margin-bottom:12px;">
      You've been unsubscribed
    </h1>
    <p style="font-size:15px;color:var(--muted);line-height:1.7;margin-bottom:28px;">
      <?php if ($subscriber && $subscriber['email']): ?>
      <strong><?= htmlspecialchars($subscriber['email']) ?></strong> has been removed from
      all SalesDesk marketing emails. This takes effect immediately.
      <?php else: ?>
      You've been removed from our mailing list. This takes effect immediately.
      <?php endif; ?>
    </p>

    <div style="background:#f8faff;border:1px solid var(--border);border-radius:var(--r-lg);
                padding:20px 24px;margin-bottom:28px;text-align:left;">
      <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:10px;">Changed your mind?</div>
      <form method="POST">
        <input type="hidden" name="resub_email"
               value="<?= htmlspecialchars($subscriber['email'] ?? '') ?>">
        <button type="submit" class="pub-btn pub-btn-ghost" style="width:100%;font-size:13px;">
          Re-subscribe <?= $subscriber ? htmlspecialchars($subscriber['email']) : '' ?>
        </button>
      </form>
      <p style="font-size:11px;color:var(--faint);margin-top:8px;">
        We'll send you a confirmation email first.
      </p>
    </div>

    <?php elseif ($token && !$result['ok']): ?>
    <!-- ── Invalid / already unsubscribed ── -->
    <div style="font-size:48px;margin-bottom:18px;">🔗</div>
    <h1 style="font-family:var(--font-d);font-size:24px;font-weight:700;color:var(--muted);margin-bottom:12px;">
      Link not recognised
    </h1>
    <p style="font-size:15px;color:var(--muted);line-height:1.7;margin-bottom:28px;">
      This unsubscribe link may have already been used, or it doesn't match any active subscription.
      If you're still receiving emails from us, enter your address below.
    </p>

    <!-- Fallback: unsubscribe by email address -->
    <div id="emailUnsubForm">
      <form id="fallbackUnsubForm" style="display:flex;flex-direction:column;gap:10px;max-width:340px;margin:0 auto 8px;">
        <input type="email" id="fallbackEmail" placeholder="your@email.com" required
               style="height:44px;border:1.5px solid var(--border);border-radius:8px;
                      padding:0 14px;font-size:14px;font-family:var(--sans);outline:none;width:100%;box-sizing:border-box;">
        <button type="submit" class="pub-btn pub-btn-ghost" style="width:100%;font-size:13px;">
          Unsubscribe this email address
        </button>
      </form>
      <p id="fallbackMsg" style="font-size:12px;color:var(--muted);min-height:16px;"></p>
    </div>

    <?php else: ?>
    <!-- ── No token — manual unsubscribe form ── -->
    <div style="font-size:48px;margin-bottom:18px;">✉️</div>
    <h1 style="font-family:var(--font-d);font-size:24px;font-weight:700;color:var(--text);margin-bottom:12px;">
      Unsubscribe
    </h1>
    <p style="font-size:15px;color:var(--muted);line-height:1.7;margin-bottom:28px;">
      Enter your email address and we'll remove you from all SalesDesk marketing emails immediately.
    </p>
    <div id="emailUnsubForm">
      <form id="fallbackUnsubForm" style="display:flex;flex-direction:column;gap:10px;max-width:340px;margin:0 auto 8px;">
        <input type="email" id="fallbackEmail" placeholder="your@email.com" required
               style="height:44px;border:1.5px solid var(--border);border-radius:8px;
                      padding:0 14px;font-size:14px;font-family:var(--sans);outline:none;width:100%;box-sizing:border-box;">
        <button type="submit" class="pub-btn pub-btn-ghost" style="width:100%;font-size:13px;">
          Unsubscribe me
        </button>
      </form>
      <p id="fallbackMsg" style="font-size:12px;color:var(--muted);min-height:16px;"></p>
    </div>
    <?php endif; ?>

    <!-- Always show bottom nav -->
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:32px;">
      <a href="/news/" class="pub-btn pub-btn-ghost" style="font-size:13px;">Latest news</a>
      <a href="/c/"   class="pub-btn pub-btn-ghost" style="font-size:13px;">Browse cars</a>
    </div>

    <p style="margin-top:24px;font-size:11px;color:var(--faint);line-height:1.6;">
      Your data is processed in line with our
      <a href="/privacy" style="color:var(--p);">Privacy Policy</a>
      and South Africa's POPIA legislation.
    </p>

  </div>
</div>

<script>
/* Fallback email unsubscribe (AJAX) — used when token is missing/invalid */
var fallbackForm = document.getElementById('fallbackUnsubForm');
if (fallbackForm) {
  fallbackForm.addEventListener('submit', function(e) {
    e.preventDefault();
    var email = document.getElementById('fallbackEmail').value.trim();
    var msgEl = document.getElementById('fallbackMsg');
    var btn   = this.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Processing…';

    fetch('/api/newsletter/unsubscribe-by-email.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'email=' + encodeURIComponent(email),
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      msgEl.style.color = d.success ? 'var(--green)' : 'var(--red)';
      msgEl.textContent = d.message;
      if (d.success) {
        document.getElementById('emailUnsubForm').style.opacity = '.5';
        document.getElementById('emailUnsubForm').style.pointerEvents = 'none';
      }
      btn.disabled = false;
      btn.textContent = 'Unsubscribe me';
    })
    .catch(function() {
      msgEl.style.color = 'var(--red)';
      msgEl.textContent = 'Something went wrong — please try again.';
      btn.disabled = false;
      btn.textContent = 'Unsubscribe me';
    });
  });
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
