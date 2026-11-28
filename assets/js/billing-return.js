/* ============================================================
   SalesDesk — waiting for PayFast's confirmation  (plans Phase 3)
   app/broker/plan.php?checkout=return: PayFast sends its notification
   (ITN) separately from the browser redirect, usually within seconds.
   Re-check a few times, then stop and let the in-app notice take over.
   ============================================================ */
(function () {
  'use strict';
  var el = document.querySelector('[data-billing-waiting]');
  if (!el) return;
  var key = 'sdBillingWait:' + (el.getAttribute('data-billing-waiting') || '');
  var tries = 0;
  try { tries = parseInt(window.sessionStorage.getItem(key) || '0', 10) || 0; } catch (e) { tries = 6; }
  if (tries >= 6) {
    var note = el.querySelector('[data-billing-slow]');
    if (note) note.hidden = false;
    return;
  }
  try { window.sessionStorage.setItem(key, String(tries + 1)); } catch (e) { /* storage off: one retry only */ }
  window.setTimeout(function () { window.location.reload(); }, 5000);
})();
