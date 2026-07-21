<?php
/**
 * SalesDesk — Cookie Consent Banner
 * Include once from views/layout-public.php, right before the closing
 * </body> tag (after the footer, alongside your other footer scripts).
 *
 * CACHING NOTE: this partial deliberately renders NO visitor-specific
 * state. Whether the banner is shown, and what's pre-toggled if the
 * visitor reopens "Cookie preferences", is decided entirely by
 * cookie-consent.js reading document.cookie at runtime — never by PHP
 * conditionals here. That's what keeps this safe to include on pages
 * that call applyCachePolicy('public'). See api/consent/token.php for
 * the same reasoning applied to the CSRF token.
 *
 * The only PHP in this file renders category copy from
 * cookieConsentCategories() in includes/cookie-consent.php — that
 * content is identical for every visitor, so it's safe under caching.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/cookie-consent.php';

$categories = cookieConsentCategories();
?>
<div class="ckc-banner" id="ckcBanner" role="dialog" aria-live="polite"
     aria-label="Cookie consent" aria-hidden="true">
  <div class="ckc-banner__inner">
    <div class="ckc-banner__text">
      <p>
        We use cookies to run this site, remember your saved cars, and fairly credit the
        broker whose link you arrived through. You choose what beyond the essentials we use —
        see our <a href="/privacy">Privacy Policy</a> for details.
      </p>
    </div>
    <div class="ckc-banner__actions">
      <button type="button" class="pub-btn pub-btn-ghost ckc-btn-reject" id="ckcRejectAll">
        Reject non-essential
      </button>
      <button type="button" class="pub-btn pub-btn-ghost ckc-btn-manage" id="ckcManage">
        Manage preferences
      </button>
      <button type="button" class="pub-btn pub-btn-primary" id="ckcAcceptAll">
        Accept all
      </button>
    </div>
  </div>
</div>

<div class="ckc-overlay" id="ckcOverlay" aria-hidden="true">
  <div class="ckc-modal" role="dialog" aria-modal="true" aria-labelledby="ckcModalTitle">
    <div class="ckc-modal__head">
      <h2 id="ckcModalTitle">Cookie preferences</h2>
      <button type="button" class="ckc-modal__close" id="ckcModalClose" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="ckc-modal__body">
      <p class="ckc-modal__intro">
        Choose which cookies you're comfortable with. You can change this at any time from the
        "Cookie preferences" link in the footer.
      </p>

      <?php foreach ($categories as $key => $cat): ?>
      <div class="ckc-row">
        <div class="ckc-row__text">
          <div class="ckc-row__label">
            <?= htmlspecialchars($cat['label']) ?>
            <?php if ($cat['locked']): ?>
            <span class="ckc-row__badge">Always on</span>
            <?php endif; ?>
          </div>
          <p class="ckc-row__desc"><?= htmlspecialchars($cat['desc']) ?></p>
        </div>
        <label class="ckc-toggle <?= $cat['locked'] ? 'is-locked' : '' ?>">
          <input type="checkbox" data-category="<?= htmlspecialchars($key) ?>"
                 <?= $cat['locked'] ? 'checked disabled' : '' ?>>
          <span class="ckc-toggle__track"><span class="ckc-toggle__thumb"></span></span>
        </label>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="ckc-modal__foot">
      <button type="button" class="pub-btn pub-btn-ghost" id="ckcModalRejectAll">
        Reject non-essential
      </button>
      <button type="button" class="pub-btn pub-btn-primary" id="ckcModalSave">
        Save preferences
      </button>
    </div>
  </div>
</div>

<style>
/* ══════════════════════════════════════════════════════════
   COOKIE CONSENT — scoped .ckc- classes, reuses site tokens
   (--p, --font-d, --sans, --border, --r-lg, --r-full, --shadow-lg,
   --text, --muted, --faint, --white, --bg) already defined globally.
   ══════════════════════════════════════════════════════════ */

.ckc-banner {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  z-index: 500;
  background: var(--white);
  border-top: 1px solid var(--border);
  box-shadow: 0 -8px 30px rgba(8,20,60,.10);
  padding: 18px clamp(16px, 4vw, 32px);
  transform: translateY(110%);
  transition: transform .38s cubic-bezier(.16,1,.3,1);
}
.ckc-banner.is-visible { transform: translateY(0); }

.ckc-banner__inner {
  max-width: 1180px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
}

.ckc-banner__text { flex: 1; min-width: 240px; }
.ckc-banner__text p {
  font-size: 13.5px;
  line-height: 1.65;
  color: var(--muted);
  margin: 0;
}
.ckc-banner__text a { color: var(--p); font-weight: 600; text-decoration: underline; }

.ckc-banner__actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  flex-shrink: 0;
}
.ckc-banner__actions .pub-btn {
  padding: 10px 18px;
  font-size: 13px;
  white-space: nowrap;
}

/* ── Preferences modal ─────────────────────────────────────── */
.ckc-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 600;
  background: rgba(8,20,60,.5);
  backdrop-filter: blur(2px);
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.ckc-overlay.is-open { display: flex; }

.ckc-modal {
  background: var(--white);
  border-radius: var(--r-xl);
  max-width: 560px;
  width: 100%;
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-lg, 0 30px 70px rgba(0,0,0,.28));
  overflow: hidden;
}

.ckc-modal__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.ckc-modal__head h2 {
  font-family: var(--font-d);
  font-size: 17px;
  font-weight: 800;
  color: var(--text);
  margin: 0;
}
.ckc-modal__close {
  width: 32px; height: 32px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--muted);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.ckc-modal__close:hover { color: var(--text); border-color: var(--border2, var(--border)); }

.ckc-modal__body {
  padding: 8px 24px 20px;
  overflow-y: auto;
}
.ckc-modal__intro {
  font-size: 13px;
  color: var(--muted);
  line-height: 1.65;
  margin: 12px 0 18px;
}

.ckc-row {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  padding: 14px 0;
  border-top: 1px solid var(--border);
}
.ckc-row:first-of-type { border-top: none; }
.ckc-row__text { flex: 1; }
.ckc-row__label {
  font-family: var(--font-d);
  font-size: 13.5px;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.ckc-row__badge {
  font-size: 10px;
  font-weight: 700;
  color: var(--faint);
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--r-full);
  padding: 2px 8px;
}
.ckc-row__desc {
  font-size: 12.5px;
  color: var(--muted);
  line-height: 1.6;
  margin: 0;
}

/* Toggle switch */
.ckc-toggle {
  position: relative;
  flex-shrink: 0;
  width: 42px;
  height: 24px;
  cursor: pointer;
}
.ckc-toggle.is-locked { cursor: not-allowed; opacity: .55; }
.ckc-toggle input {
  position: absolute;
  opacity: 0;
  width: 100%; height: 100%;
  margin: 0;
  cursor: inherit;
}
.ckc-toggle__track {
  position: absolute; inset: 0;
  background: var(--border);
  border-radius: var(--r-full);
  transition: background .18s ease;
}
.ckc-toggle__thumb {
  position: absolute;
  top: 3px; left: 3px;
  width: 18px; height: 18px;
  border-radius: 50%;
  background: var(--white);
  box-shadow: 0 1px 3px rgba(0,0,0,.25);
  transition: transform .18s cubic-bezier(.16,1,.3,1);
}
.ckc-toggle input:checked ~ .ckc-toggle__track { background: var(--p); }
.ckc-toggle input:checked ~ .ckc-toggle__track .ckc-toggle__thumb,
.ckc-toggle input:checked + .ckc-toggle__track .ckc-toggle__thumb { transform: translateX(18px); }
.ckc-toggle input:focus-visible ~ .ckc-toggle__track { outline: 2px solid var(--p); outline-offset: 2px; }

.ckc-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 24px;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
}
.ckc-modal__foot .pub-btn { padding: 10px 20px; font-size: 13px; }

@media (max-width: 640px) {
  .ckc-banner__actions { width: 100%; }
  .ckc-banner__actions .pub-btn { flex: 1; }
  .ckc-modal__foot { flex-direction: column-reverse; }
  .ckc-modal__foot .pub-btn { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .ckc-banner, .ckc-toggle__track, .ckc-toggle__thumb { transition: none !important; }
}
</style>

<script>
(function () {
  'use strict';

  var COOKIE_NAME     = 'sd_cookie_consent';
  var POLICY_VERSION  = <?= json_encode(COOKIE_CONSENT_POLICY_VERSION) ?>;
  var CATEGORY_KEYS   = <?= json_encode(array_keys($categories)) ?>;

  var banner   = document.getElementById('ckcBanner');
  var overlay  = document.getElementById('ckcOverlay');
  var modal    = overlay ? overlay.querySelector('.ckc-modal') : null;
  var csrfToken = null;
  var lastFocused = null;

  // ── Cookie helpers ─────────────────────────────────────────
  function readConsentCookie() {
    var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE_NAME + '=([^;]*)'));
    if (!match) return null;
    try {
      var parsed = JSON.parse(decodeURIComponent(match[1]));
      if (parsed.v !== POLICY_VERSION) return null;
      return parsed;
    } catch (e) {
      return null;
    }
  }

  function writeConsentCookie(decision) {
    var payload = Object.assign({ v: POLICY_VERSION }, decision);
    var oneYear = 60 * 60 * 24 * 365;
    var secure  = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = COOKIE_NAME + '=' + encodeURIComponent(JSON.stringify(payload)) +
      '; Max-Age=' + oneYear + '; Path=/; SameSite=Lax' + secure;
  }

  function ensureCsrfToken(cb) {
    if (csrfToken) { cb(); return; }
    fetch('/api/consent/token.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { csrfToken = data.csrf_token; cb(); })
      .catch(function () { cb(); }); // proceed anyway; server will reject if token missing
  }

  function submitDecision(action, decision) {
    writeConsentCookie(decision); // instant client-side effect, no round trip needed
    dispatchUpdate(decision);

    ensureCsrfToken(function () {
      var body = new URLSearchParams();
      body.set('csrf_token', csrfToken || '');
      body.set('action', action);
      CATEGORY_KEYS.forEach(function (cat) {
        if (decision[cat]) body.set(cat, '1');
      });

      fetch('/api/consent/save.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      }).catch(function () {
        // Non-fatal: the visitor's browser cookie (source of truth for
        // their own device) is already set. A failed audit-log write
        // shouldn't be visible to the visitor at all.
      });
    });
  }

  function dispatchUpdate(decision) {
    window.dispatchEvent(new CustomEvent('sd:consent-updated', { detail: decision }));
  }

  function decisionFromAllToggles(value) {
    var decision = {};
    CATEGORY_KEYS.forEach(function (cat) { decision[cat] = true; });
    if (!value) {
      CATEGORY_KEYS.forEach(function (cat) { decision[cat] = (cat === 'necessary'); });
    }
    return decision;
  }

  function decisionFromModalToggles() {
    var decision = {};
    modal.querySelectorAll('input[data-category]').forEach(function (input) {
      decision[input.dataset.category] = input.checked;
    });
    return decision;
  }

  // ── Banner visibility ───────────────────────────────────────
  function showBanner() {
    if (!banner) return;
    banner.setAttribute('aria-hidden', 'false');
    // rAF so the transform transition actually runs from the initial state
    requestAnimationFrame(function () { banner.classList.add('is-visible'); });
  }
  function hideBanner() {
    if (!banner) return;
    banner.classList.remove('is-visible');
    banner.setAttribute('aria-hidden', 'true');
  }

  // ── Preferences modal ───────────────────────────────────────
  function openModal(prefillFromCookie) {
    if (!overlay || !modal) return;
    if (prefillFromCookie) {
      var existing = readConsentCookie();
      modal.querySelectorAll('input[data-category]').forEach(function (input) {
        if (input.disabled) return; // locked/necessary
        var cat = input.dataset.category;
        input.checked = existing ? !!existing[cat] : false;
      });
    }
    lastFocused = document.activeElement;
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    var firstToggle = modal.querySelector('input[data-category]:not([disabled])');
    (firstToggle || modal.querySelector('.ckc-modal__close')).focus();
  }

  function closeModal() {
    if (!overlay) return;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  // Simple focus trap while the modal is open.
  document.addEventListener('keydown', function (e) {
    if (!overlay || !overlay.classList.contains('is-open')) return;
    if (e.key === 'Escape') { closeModal(); return; }
    if (e.key !== 'Tab') return;
    var focusable = modal.querySelectorAll('button, input:not([disabled])');
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  // ── Wire up buttons ──────────────────────────────────────────
  var acceptAllBtn  = document.getElementById('ckcAcceptAll');
  var rejectAllBtn  = document.getElementById('ckcRejectAll');
  var manageBtn     = document.getElementById('ckcManage');
  var modalClose    = document.getElementById('ckcModalClose');
  var modalSave     = document.getElementById('ckcModalSave');
  var modalRejectAll= document.getElementById('ckcModalRejectAll');

  if (acceptAllBtn) acceptAllBtn.addEventListener('click', function () {
    submitDecision('accept_all', decisionFromAllToggles(true));
    hideBanner();
  });

  if (rejectAllBtn) rejectAllBtn.addEventListener('click', function () {
    submitDecision('reject_all', decisionFromAllToggles(false));
    hideBanner();
  });

  if (manageBtn) manageBtn.addEventListener('click', function () { openModal(true); });
  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (overlay) overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  if (modalRejectAll) modalRejectAll.addEventListener('click', function () {
    submitDecision('reject_all', decisionFromAllToggles(false));
    hideBanner();
    closeModal();
  });

  if (modalSave) modalSave.addEventListener('click', function () {
    var decision = decisionFromModalToggles();
    submitDecision('custom', decision);
    hideBanner();
    closeModal();
  });

  // ── Public API for the footer "Cookie preferences" link ──────
  // Attach this to your footer link, e.g.:
  //   <a href="#" id="cookiePreferencesLink">Cookie preferences</a>
  // and it'll auto-wire below. Or call window.sdOpenCookiePreferences()
  // from anywhere.
  window.sdOpenCookiePreferences = function () { openModal(true); };

  var footerLink = document.getElementById('cookiePreferencesLink');
  if (footerLink) {
    footerLink.addEventListener('click', function (e) {
      e.preventDefault();
      window.sdOpenCookiePreferences();
    });
  }

  // ── Decide on load whether to show the banner ────────────────
  // This is the only place visibility is decided — deliberately at
  // runtime from document.cookie, not from server-rendered markup,
  // so this partial stays correct even on cached HTML (see file
  // header comment).
  if (!readConsentCookie()) {
    showBanner();
  } else {
    dispatchUpdate(readConsentCookie()); // let analytics/attribution scripts know the standing decision on every load
  }
})();
</script>
