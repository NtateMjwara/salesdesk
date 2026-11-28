/**
 * SalesDesk — printable business invoice  (0026)
 * assets/js/invoice.js — loaded by /app/billing/invoice: the Print button.
 */
(function () {
  'use strict';
  var btn = document.querySelector('[data-print]');
  if (btn) btn.addEventListener('click', function () { window.print(); });
})();
