/* ============================================================
   SalesDesk — Broker: My networks  (plans Phase 2b)
   Province filter submits as soon as it changes (the Filter
   button stays for no-JS visitors and is hidden via .js).
   ============================================================ */
(function () {
  'use strict';
  document.documentElement.classList.add('js');
  var form = document.querySelector('[data-net-filter]');
  if (!form) return;
  var select = form.querySelector('select');
  if (select) {
    select.addEventListener('change', function () { form.submit(); });
  }
})();
