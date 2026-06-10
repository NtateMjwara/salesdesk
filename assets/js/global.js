/**
 * SalesDesk — Global JavaScript
 * T1 owns this file. Signature of initOTPWidget() is FROZEN after ship.
 *
 * Provides:
 *   initOTPWidget(formId, hiddenId, length?)
 *     Call on any page with a 6-digit OTP input.
 *     Wires up the digit inputs, paste handling, and auto-submit.
 *
 *   CSRF auto-inject
 *     Reads the CSRF token from <meta name="csrf-token"> and
 *     appends it to all fetch() POSTs and non-GET form submits.
 *
 *   Page-transition overlay
 *     Shows a full-screen loading overlay on internal navigation.
 *     Dismissed only after window.load fires on the new page so
 *     CSS, fonts and images are fully ready before the overlay lifts.
 *     Auth-page form submissions use a button-level spinner instead
 *     (see initAuthFormLoading() — called from layout-auth.php).
 *
 * Usage (PHP template):
 *   <meta name="csrf-token" content="<?= generateCSRFToken() ?>">
 *   <script src="/assets/js/global.js?v=20250501"></script>
 *   <script>initOTPWidget('otpForm', 'otpHidden');</script>
 */

(function () {
  'use strict';

  /* ══════════════════════════════════════════════════
     CSRF AUTO-INJECT
     Reads token from <meta name="csrf-token">.
     Intercepts all fetch() POSTs and non-GET form submits.
     Pages still include a server-side hidden field as fallback.
     ══════════════════════════════════════════════════ */

  function getCSRFToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  var originalFetch = window.fetch;
  window.fetch = function (resource, init) {
    init = init || {};
    var method = (init.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) !== -1) {
      if (!init.headers) {
        init.headers = {};
      }
      if (init.headers instanceof Headers) {
        if (!init.headers.has('X-CSRF-Token')) {
          init.headers.set('X-CSRF-Token', getCSRFToken());
        }
      } else {
        if (!init.headers['X-CSRF-Token']) {
          init.headers['X-CSRF-Token'] = getCSRFToken();
        }
      }
    }
    return originalFetch.call(this, resource, init);
  };

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var method = (form.method || 'GET').toUpperCase();
    if (method === 'GET') return;

    var tokenName = 'csrf_token';
    if (form.querySelector('input[name="' + tokenName + '"]')) return;

    var token = getCSRFToken();
    if (!token) return;

    var hidden = document.createElement('input');
    hidden.type  = 'hidden';
    hidden.name  = tokenName;
    hidden.value = token;
    form.appendChild(hidden);
  }, true);


  /* ══════════════════════════════════════════════════
     OTP WIDGET
     Exported to window.initOTPWidget so pages can call it.
     Signature is FROZEN: initOTPWidget(formId, hiddenId, length?)
     ══════════════════════════════════════════════════ */

  window.initOTPWidget = function initOTPWidget(formId, hiddenId, length) {
    length = length || 6;

    var form   = document.getElementById(formId);
    var hidden = document.getElementById(hiddenId);
    if (!form || !hidden) {
      console.warn('[initOTPWidget] Form or hidden input not found:', formId, hiddenId);
      return;
    }

    var digits = Array.from(form.querySelectorAll('.otp-d'));
    if (digits.length === 0) {
      console.warn('[initOTPWidget] No .otp-d inputs found in form:', formId);
      return;
    }

    function syncHidden() {
      hidden.value = digits.map(function (d) { return d.value; }).join('');
    }

    function autoSubmitIfComplete() {
      if (digits.every(function (d) { return d.value !== ''; })) {
        syncHidden();
        form.requestSubmit();
      }
    }

    digits.forEach(function (el, i) {
      el.addEventListener('input', function (e) {
        var raw = e.target.value.replace(/\D/g, '');
        e.target.value = raw.slice(-1);
        if (raw && i < digits.length - 1) {
          digits[i + 1].focus();
        }
        syncHidden();
        autoSubmitIfComplete();
      });

      el.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !el.value && i > 0) {
          digits[i - 1].focus();
          digits[i - 1].value = '';
          syncHidden();
        }
      });

      el.addEventListener('paste', function (e) {
        e.preventDefault();
        var pasted = (e.clipboardData || window.clipboardData)
          .getData('text')
          .replace(/\D/g, '')
          .slice(0, digits.length);
        pasted.split('').forEach(function (ch, j) {
          if (digits[j]) digits[j].value = ch;
        });
        syncHidden();
        var nextFocus = digits[pasted.length] || digits[digits.length - 1];
        nextFocus.focus();
        autoSubmitIfComplete();
      });
    });

    var firstEmpty = digits.find(function (d) { return !d.value; }) || digits[0];
    firstEmpty.focus();
  };


  /* ══════════════════════════════════════════════════
     PAGE-TRANSITION OVERLAY
     ──────────────────────────────────────────────────
     Strategy:
       1. On DOMContentLoaded, inject #sd-nav-overlay into <body>.
       2. If sessionStorage flag 'sd_navigating' is set, the overlay
          was already shown on the previous page — re-show it
          immediately (no flash of unstyled content between pages).
       3. When window.load fires (CSS + fonts + images ready),
          fade the overlay out and clear the flag.
       4. On any qualifying <a> click, set the flag and show the
          overlay, then let the browser navigate normally.

     Excluded links (overlay NOT shown):
       - target="_blank"
       - href starting with #, mailto:, tel:, javascript:
       - download attribute present
       - data-no-overlay attribute present (escape hatch for AJAX links)
       - Cross-origin links (different hostname)

     Auth pages use initAuthFormLoading() instead, which wires a
     button-level spinner and disabled state without any overlay.
     ══════════════════════════════════════════════════ */

  var OVERLAY_FLAG = 'sd_navigating';
  var overlay      = null;

  function createOverlay() {
    var el = document.createElement('div');
    el.id = 'sd-nav-overlay';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
      '<div class="sd-overlay-spinner" aria-hidden="true"></div>' +
      '<span class="sd-overlay-label">Loading\u2026</span>';
    return el;
  }

  function showOverlay() {
    if (!overlay) return;
    overlay.classList.add('sd-overlay-visible');
    overlay.classList.remove('sd-overlay-hiding');
  }

  function hideOverlay() {
    if (!overlay) return;
    overlay.classList.add('sd-overlay-hiding');
    overlay.addEventListener('animationend', function handler() {
      overlay.classList.remove('sd-overlay-visible', 'sd-overlay-hiding');
      overlay.removeEventListener('animationend', handler);
    });
  }

  function isInternalLink(anchor) {
    /* Exclude non-navigation hrefs */
    var href = anchor.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return false;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;

    /* Exclude new-tab and download links */
    if (anchor.target === '_blank') return false;
    if (anchor.hasAttribute('download')) return false;

    /* Escape hatch: data-no-overlay="true" */
    if (anchor.dataset.noOverlay) return false;

    /* Exclude cross-origin links */
    try {
      var url = new URL(anchor.href, window.location.href);
      if (url.hostname !== window.location.hostname) return false;
    } catch (e) {
      return false;
    }

    return true;
  }

  function initNavOverlay() {
    /* Inject overlay node */
    overlay = createOverlay();
    document.body.insertBefore(overlay, document.body.firstChild);

    /* If we're landing on this page mid-navigation, show overlay
       immediately so there's no unstyled flash while assets load. */
    if (sessionStorage.getItem(OVERLAY_FLAG) === '1') {
      showOverlay();
    }

    /* Dismiss once everything (CSS, fonts, images) is ready */
    window.addEventListener('load', function () {
      sessionStorage.removeItem(OVERLAY_FLAG);
      hideOverlay();
    });

    /* Safety valve: always hide after 8 s even if load never fires
       (broken image, slow CDN, etc.) */
    setTimeout(function () {
      sessionStorage.removeItem(OVERLAY_FLAG);
      hideOverlay();
    }, 8000);

    /* Intercept clicks on qualifying internal links */
    document.addEventListener('click', function (e) {
      /* Walk up the DOM in case the click landed on a child element */
      var target = e.target;
      while (target && target !== document) {
        if (target.tagName === 'A' && isInternalLink(target)) {
          /* Don't intercept if modifier keys held (open in new tab, etc.) */
          if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

          sessionStorage.setItem(OVERLAY_FLAG, '1');
          showOverlay();
          return; /* let the browser navigate normally */
        }
        target = target.parentElement;
      }
    });
  }

  /* ══════════════════════════════════════════════════
     AUTH FORM BUTTON LOADING
     ──────────────────────────────────────────────────
     Called from layout-auth.php after the DOM is ready.
     Wires every form submit on the page to:
       1. Disable the submit button
       2. Replace its contents with a spinner + "Submitting…" label
       3. Never show the full-screen overlay

     Because auth pages POST to PHP (full page reload), the button
     returns to its normal state automatically on the next render.
     No cleanup needed.

     Usage in layout-auth.php:
       <script>
         document.addEventListener('DOMContentLoaded', function () {
           if (window.initAuthFormLoading) initAuthFormLoading();
         });
       </script>
     ══════════════════════════════════════════════════ */

  window.initAuthFormLoading = function initAuthFormLoading() {
    var forms = document.querySelectorAll('form');

    forms.forEach(function (form) {
      form.addEventListener('submit', function () {
        /* Find the primary submit button in this form */
        var btn = form.querySelector(
          'button[type="submit"], button.btn-auth, input[type="submit"]'
        );
        if (!btn || btn.disabled) return;

        /* Capture original content so it can be restored on
           validation failure (if the page doesn't navigate away) */
        var originalHTML = btn.innerHTML;
        var originalDisabled = btn.disabled;

        btn.disabled = true;
        btn.innerHTML =
          '<span class="sd-btn-spinner" aria-hidden="true"></span>' +
          'Submitting\u2026';

        /* Restore after 10 s as a safety net for client-side
           validation that prevents actual submission */
        setTimeout(function () {
          btn.innerHTML   = originalHTML;
          btn.disabled    = originalDisabled;
        }, 10000);
      });
    });
  };


  /* ══════════════════════════════════════════════════
     BOOT
     ══════════════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function () {
    initNavOverlay();
  });

})();
