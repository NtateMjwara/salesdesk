/* ============================================================
   SalesDesk — PayFast hand-off  (plans Phase 3)
   app/billing/pay.php: submit the signed form straight away. The button
   stays as a fallback (no JS, pop-up blockers, slow networks).
   ============================================================ */
(function () {
  'use strict';
  var form = document.querySelector('[data-pay-handoff]');
  if (!form) return;
  window.setTimeout(function () {
    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Opening PayFast…'; }
    form.submit();
  }, 600);
})();
