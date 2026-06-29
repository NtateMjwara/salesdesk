<?php
/**
 * SalesDesk — Newsletter: Subscription Confirmed
 * Route: /newsletter/confirmed.php
 *
 * Shown after clicking the confirm link in the opt-in email.
 * Also handles the error states (expired token, invalid token).
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');

$confirmed = $_GET['confirmed'] ?? '';
$error     = $_GET['error']     ?? '';

if ($confirmed === '1') {
    $state   = 'confirmed';
    $heading = 'You\'re subscribed!';
    $icon    = '🎉';
    $body    = 'Your subscription is confirmed. You\'ll receive our next newsletter with the latest car news, deal alerts, and market insights straight to your inbox.';
    $color   = '#15803d';
} elseif ($confirmed === 'already') {
    $state   = 'already';
    $heading = 'Already confirmed';
    $icon    = '✓';
    $body    = 'You\'re already an active subscriber — no action needed. Look out for our next newsletter!';
    $color   = '#0f4c9e';
} elseif ($error === 'expired') {
    $state   = 'error';
    $heading = 'Confirmation link expired';
    $icon    = '⏱';
    $body    = 'Confirmation links are valid for 48 hours. Please subscribe again and we\'ll send you a fresh link.';
    $color   = '#d97706';
} else {
    $state   = 'error';
    $heading = 'Invalid confirmation link';
    $icon    = '🔗';
    $body    = 'This confirmation link is invalid or has already been used. If you\'d like to subscribe, please enter your email below.';
    $color   = '#dc2626';
}

$pageTitle    = 'Newsletter Subscription | SalesDesk';
$ogTitle      = $heading . ' | SalesDesk Newsletter';
$ogDescription = 'SalesDesk car news and deal alerts newsletter.';
$canonicalUrl = (defined('SITE_URL') ? SITE_URL : '') . '/newsletter/confirmed.php';
$layoutVariant = 'narrow';

ob_start();
?>

<div style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:48px 24px;">
  <div style="max-width:480px;width:100%;text-align:center;">

    <div style="font-size:52px;margin-bottom:20px;"><?= $icon ?></div>

    <h1 style="font-family:var(--font-d);font-size:28px;font-weight:800;
               color:<?= $color ?>;margin-bottom:14px;">
      <?= htmlspecialchars($heading) ?>
    </h1>

    <p style="font-size:16px;line-height:1.7;color:var(--muted);margin-bottom:32px;">
      <?= htmlspecialchars($body) ?>
    </p>

    <?php if ($state === 'confirmed'): ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--r-lg);
                padding:18px 22px;margin-bottom:28px;text-align:left;">
      <div style="font-size:13px;font-weight:600;color:#166534;margin-bottom:8px;">What to expect:</div>
      <ul style="font-size:13px;color:#166534;line-height:1.8;margin:0;padding-left:18px;">
        <li>Weekly car news &amp; new launch alerts</li>
        <li>SA price updates and broker deal picks</li>
        <li>Market analysis and buying guides</li>
        <li>Easy one-click unsubscribe in every email</li>
      </ul>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/c/" class="pub-btn pub-btn-primary">
        Browse vehicles <i class="fa-solid fa-arrow-right"></i>
      </a>
      <a href="/news/" class="pub-btn pub-btn-ghost">
        Read the news
      </a>
    </div>

    <?php if ($state === 'error'): ?>
    <div style="margin-top:40px;padding-top:32px;border-top:1px solid var(--border);">
      <p style="font-size:14px;color:var(--muted);margin-bottom:16px;">
        Want to try again? Enter your email below:
      </p>
      <form id="resubForm" style="display:flex;gap:0;max-width:360px;margin:0 auto 10px;">
        <input type="email" id="resubEmail" placeholder="your@email.com" required
               style="flex:1;height:44px;border:1.5px solid var(--border);
                      border-right:none;border-radius:8px 0 0 8px;
                      padding:0 14px;font-size:14px;font-family:var(--sans);outline:none;">
        <button type="submit"
                style="height:44px;padding:0 18px;background:var(--p);color:#fff;
                       border:none;border-radius:0 8px 8px 0;font-size:14px;
                       font-weight:600;font-family:var(--sans);cursor:pointer;">
          Subscribe
        </button>
      </form>
      <p id="resubMsg" style="font-size:12px;color:var(--muted);min-height:16px;"></p>
    </div>
    <script>
    document.getElementById('resubForm').addEventListener('submit', function(e) {
      e.preventDefault();
      var email = document.getElementById('resubEmail').value.trim();
      var msgEl = document.getElementById('resubMsg');
      var btn   = this.querySelector('button');
      btn.disabled = true;
      fetch('/api/newsletter/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'email=' + encodeURIComponent(email) + '&source=confirmed_retry',
      })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        msgEl.style.color = d.success ? 'var(--green)' : 'var(--red)';
        msgEl.textContent = d.message;
        if (d.success) document.getElementById('resubEmail').value = '';
        btn.disabled = false;
      })
      .catch(function() {
        msgEl.style.color = 'var(--red)';
        msgEl.textContent = 'Something went wrong — please try again.';
        btn.disabled = false;
      });
    });
    </script>
    <?php endif; ?>

  </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
