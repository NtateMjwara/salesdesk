/**
 * SalesDesk — Choose which cars stay live  (PLANS-3b)
 * assets/js/keep-cars.js
 *
 * Loaded on /app/broker/keep-cars via $pageScripts.
 *   - Live "n of limit chosen" counter as boxes are ticked.
 *   - Over the limit: the count turns red, the submit buttons are
 *     disabled and a short message shows. The server checks again.
 *   - A submit button with data-confirm asks before submitting.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-keep-form]');
  if (!form) return;

  var limit   = parseInt(form.getAttribute('data-limit'), 10) || 0;
  var countEl = form.querySelector('[data-keep-count]');
  var errorEl = form.querySelector('[data-keep-error]');
  var buttons = form.querySelectorAll('[data-keep-submit]');

  function update() {
    var n    = form.querySelectorAll('input[name="keep[]"]:checked').length;
    var over = n > limit;
    if (countEl) {
      countEl.textContent = String(n);
      countEl.parentNode.classList.toggle('is-over', over);
    }
    if (errorEl) errorEl.hidden = !over;
    for (var i = 0; i < buttons.length; i++) buttons[i].disabled = over;
  }

  form.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'keep[]') update();
  });

  form.addEventListener('submit', function (e) {
    var btn = e.submitter;
    var msg = btn && btn.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    // One request per click.
    for (var i = 0; i < buttons.length; i++) {
      if (buttons[i] !== btn) buttons[i].disabled = true;
    }
    if (btn) {
      // Disabling the clicked button drops its name/value — carry it over.
      var hidden = document.createElement('input');
      hidden.type  = 'hidden';
      hidden.name  = btn.name;
      hidden.value = btn.value;
      form.appendChild(hidden);
      btn.disabled = true;
    }
  });

  update();
})();
