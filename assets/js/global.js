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
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // Intercept fetch() for POST/PUT/PATCH/DELETE.
  const originalFetch = window.fetch;
  window.fetch = function (resource, init) {
    init = init || {};
    const method = (init.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      if (!init.headers) {
        init.headers = {};
      }
      // Support both Headers objects and plain objects.
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

  // Intercept non-GET form submits — append hidden CSRF field if missing.
  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    const method = (form.method || 'GET').toUpperCase();
    if (method === 'GET') return;

    const tokenName = 'csrf_token';
    if (form.querySelector('input[name="' + tokenName + '"]')) return;

    const token = getCSRFToken();
    if (!token) return;

    const hidden = document.createElement('input');
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

  /**
   * Wire up a multi-digit OTP input widget.
   *
   * @param {string} formId    ID of the <form> element.
   * @param {string} hiddenId  ID of the hidden <input> that receives the joined value.
   * @param {number} [length=6]  Number of digit inputs.
   */
  window.initOTPWidget = function initOTPWidget(formId, hiddenId, length) {
    length = length || 6;

    const form   = document.getElementById(formId);
    const hidden = document.getElementById(hiddenId);
    if (!form || !hidden) {
      console.warn('[initOTPWidget] Form or hidden input not found:', formId, hiddenId);
      return;
    }

    const digits = Array.from(form.querySelectorAll('.otp-d'));
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

      // Input: allow only one numeric digit, advance focus.
      el.addEventListener('input', function (e) {
        var raw = e.target.value.replace(/\D/g, '');
        e.target.value = raw.slice(-1);
        if (raw && i < digits.length - 1) {
          digits[i + 1].focus();
        }
        syncHidden();
        autoSubmitIfComplete();
      });

      // Backspace on empty field: go back.
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !el.value && i > 0) {
          digits[i - 1].focus();
          digits[i - 1].value = '';
          syncHidden();
        }
      });

      // Paste: distribute across fields.
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

    // Focus the first empty digit (or first field on fresh load).
    var firstEmpty = digits.find(function (d) { return !d.value; }) || digits[0];
    firstEmpty.focus();
  };

})();
