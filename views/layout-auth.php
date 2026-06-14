<?php
/**
 * SalesDesk — Auth Layout Shell
 * T1 owns this file.
 *
 * FIXES APPLIED:
 *   FIX-01: global.js moved from body footer to <head> (no defer).
 *           Inline scripts in $cardContent call initOTPWidget() which is
 *           defined in global.js. When global.js was at the bottom of <body>,
 *           the inline script ran first and threw "initOTPWidget is not defined",
 *           meaning the hidden OTP field was never populated → POST always had
 *           an empty otp value → "Please enter the 6-digit code" error on every
 *           valid OTP submission.
 *
 *   FIX-LOADING: initAuthLoadingStates() added before </body>.
 *           Attaches submit listeners to every auth form on the page so that
 *           .btn-auth and .btn-auth-ghost buttons enter a loading state
 *           immediately on submit and restore their original content if the
 *           server responds with an error (page reload with ?error=…).
 *           The implementation lives here rather than global.js because:
 *             - global.js signature is frozen.
 *             - The loading behaviour is auth-specific and shares the
 *               auth.css spinner classes defined in section 13.
 */

$pageTitle        = $pageTitle        ?? 'SalesDesk';
$cardContent      = $cardContent      ?? '';
$cardMaxWidth     = $cardMaxWidth     ?? '520px';
$assetVersion     = $assetVersion     ?? date('Ymd');
$needsOTP         = $needsOTP         ?? false;
$needsLocations   = $needsLocations   ?? false;
$needsDealerSearch = $needsDealerSearch ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('generateCSRFToken') ? generateCSRFToken() : '') ?>">
  <title><?= htmlspecialchars($pageTitle) ?> | SalesDesk</title>

  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/components.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/auth.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/logo.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/logo.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/logo.png"> 
    
  <!--
    FIX-01: global.js loaded in <head> (synchronously, no defer/async).
    This guarantees initOTPWidget() is defined before any inline <script>
    in $cardContent executes. Previously it was at the bottom of <body>,
    causing a "initOTPWidget is not defined" ReferenceError on OTP pages.
  -->
  <script src="/assets/js/global.js?v=<?= $assetVersion ?>"></script>

  <?php if ($needsLocations): ?>
  <script src="/assets/js/sa-locations.js?v=<?= $assetVersion ?>"></script>
  <?php endif; ?>

  <?php if ($needsDealerSearch): ?>
  <script src="/assets/js/dealer-search.js?v=<?= $assetVersion ?>"></script>
  <?php endif; ?>

  <style>
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      background: var(--bg);
    }

    .auth-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--r-xl);
      padding: 2rem 2.25rem 2.25rem;
      width: 100%;
      max-width: <?= htmlspecialchars($cardMaxWidth) ?>;
      box-shadow: var(--shadow-lg);
    }

    .auth-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 1.75rem;
    }

    .auth-brand-logo {
      width: 34px;
      height: 34px;
      background: var(--p);
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 14px;
      flex-shrink: 0;
    }

    .auth-brand-name {
      font-family: var(--serif);
      font-size: 1.15rem;
      font-weight: 500;
      color: var(--text);
    }

    .auth-brand-name em { font-style: italic; color: var(--p); }

    .auth-brand-badge {
      font-size: 9px;
      font-weight: 700;
      letter-spacing: .06em;
      background: var(--gr-bg);
      color: var(--green);
      border: 1px solid var(--gr-b);
      border-radius: var(--r-full);
      padding: 2px 7px;
    }
  </style>
</head>
<body>

<div class="auth-card">

  <!-- Shared brand header -->
  <div class="auth-brand">
    <div class="auth-brand-logo">
      <i class="fa-solid fa-car-side"></i>
    </div>
    <div class="auth-brand-name">Sales<em>Desk</em></div>
    <div class="auth-brand-badge">ZA</div>
  </div>

  <!-- Page content injected here -->
  <?= $cardContent ?>

</div>

<!--
  FIX-LOADING: Auth-wide button loading states.

  Strategy
  ────────
  On every form submit inside .auth-card:
    1. Find the submit button (.btn-auth or .btn-auth-ghost).
    2. Snapshot its current HTML so we can restore it on failure.
    3. Replace its content with a spinner + status label.
    4. Disable it and add the CSS loading modifier class.

  Restoration
  ───────────
  The server always redirects on success, so the button never needs
  to "un-load" on success — the page navigates away.
  On failure the server redirects back to the same page with ?error=…
  which causes a full page reload, naturally restoring every button.

  Therefore no AJAX success/failure hook is needed here:
    - Success  → server redirect → new page load → buttons fresh.
    - Failure  → server redirect with ?error → page reloads → buttons fresh.

  Per-step label map
  ──────────────────
  Each form carries a data-loading-label attribute (set in login.php and
  register.php) so the spinner text matches the action being performed.
  Falls back to "Processing…" if the attribute is absent.

  Ghost buttons (Resend code, Back)
  ──────────────────────────────────
  Ghost buttons that submit their own <form> (e.g. wizard_resend,
  wizard_skip_dealership) also get a loading state so the user cannot
  double-tap them. Their spinner uses the ghost colour variant.

  OTP forms
  ─────────
  The OTP form auto-submits when all 6 digits are filled (via global.js
  requestSubmit()). The loading state fires on that synthetic submit event
  exactly as it would for a manual click, because we listen to 'submit'
  on the form element rather than 'click' on the button.

  Disabled submit buttons (e.g. desk slug checking)
  ──────────────────────────────────────────────────
  We skip disabled buttons — they cannot be the intended submit target.
  The button becomes enabled by the slug-availability JS before the
  user can submit, so by the time the form submits the button is enabled.
-->
<script>
(function () {
  'use strict';

  /**
   * Create a spinner element matching .btn-auth-spinner from auth.css §13.
   * @returns {HTMLSpanElement}
   */
  function makeSpinner() {
    var s = document.createElement('span');
    s.className = 'btn-auth-spinner';
    s.setAttribute('aria-hidden', 'true');
    return s;
  }

  /**
   * Put a submit button into loading state.
   *
   * @param {HTMLButtonElement} btn   The button to mutate.
   * @param {string}            label Status text to display alongside spinner.
   * @param {boolean}           ghost True for .btn-auth-ghost style.
   */
  function startLoading(btn, label, ghost) {
    // Freeze current dimensions so the button doesn't resize.
    var rect = btn.getBoundingClientRect();
    btn.style.minWidth  = rect.width  + 'px';
    btn.style.minHeight = rect.height + 'px';

    btn.setAttribute('data-original-html', btn.innerHTML);
    btn.disabled = true;

    // Build: [spinner] [label text]
    var fragment = document.createDocumentFragment();
    fragment.appendChild(makeSpinner());

    var text = document.createElement('span');
    text.textContent = label || 'Processing\u2026';
    fragment.appendChild(text);

    btn.innerHTML = '';
    btn.appendChild(fragment);

    if (ghost) {
      btn.classList.add('btn-auth-ghost--loading');
    } else {
      btn.classList.add('btn-auth--loading');
    }
  }

  /**
   * Attach loading-state logic to all auth forms currently in the DOM.
   * Called once after DOMContentLoaded.
   */
  function initAuthLoadingStates() {
    var card = document.querySelector('.auth-card');
    if (!card) { return; }

    var forms = card.querySelectorAll('form');

    forms.forEach(function (form) {
      form.addEventListener('submit', function (e) {
        // Determine which button triggered this submit.
        // requestSubmit() (used by OTP widget) does not set submitter in all
        // browsers, so we fall back to the first non-disabled submit button.
        var btn = (e.submitter instanceof HTMLButtonElement && e.submitter.type !== 'button')
          ? e.submitter
          : form.querySelector('button[type="submit"]:not(:disabled), button:not([type]):not(:disabled)');

        if (!btn) { return; }

        // Don't start loading on buttons that are explicitly disabled
        // (e.g. desk slug button before availability is confirmed).
        if (btn.disabled) { return; }

        var label  = form.getAttribute('data-loading-label') || 'Processing\u2026';
        var isGhost = btn.classList.contains('btn-auth-ghost');

        startLoading(btn, label, isGhost);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuthLoadingStates);
  } else {
    // DOMContentLoaded already fired (e.g. script at end of body).
    initAuthLoadingStates();
  }

})();
</script>

</body>
</html>
