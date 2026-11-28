/**
 * SalesDesk — dealer portal Network → VIP perks  (PLANS-4a)
 * assets/js/dealer-perks.js
 *
 * Loaded on /app/dealer/network via $pageScripts.
 *   - Network bonus: show the value field only when a bonus type is
 *     picked, switch its label / limit, and preview the bonus on a
 *     R300,000 car.
 *   - Network-only stock: live count of ticked cars and "Select all".
 *   - 4b Dashboard: period picker auto-submit, announcement character count.
 * The server checks everything again (includes/vip_network.php).
 */
(function () {
  'use strict';

  var bonus = document.querySelector('[data-perk-bonus]');
  if (bonus) {
    var wrap  = bonus.querySelector('[data-perk-value]');
    var label = bonus.querySelector('[data-perk-value-label]');
    var input = bonus.querySelector('#bonus_value');
    var ex    = bonus.querySelector('[data-perk-example]');
    var fmt   = function (n) { return 'R' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); };

    var update = function () {
      var picked = bonus.querySelector('input[name="bonus_type"]:checked');
      var type   = picked ? picked.value : 'none';
      if (wrap) wrap.hidden = type === 'none';
      if (!input) return;
      input.required = type !== 'none';
      if (type === 'percentage') {
        label.textContent = 'Percentage of the price';
        input.max = input.getAttribute('data-max-pct');
        input.placeholder = '0.5';
      } else if (type === 'fixed') {
        label.textContent = 'Amount per sale (R)';
        input.max = input.getAttribute('data-max-fixed');
        input.placeholder = '1000';
      }
      var v = parseFloat(input.value);
      if (ex) {
        ex.textContent = type === 'none' || !(v > 0) ? ''
          : 'On a R300,000 car your network broker earns ' + fmt(type === 'percentage' ? 300000 * v / 100 : v)
            + ' on top of the commission.';
      }
    };
    bonus.addEventListener('change', update);
    if (input) input.addEventListener('input', update);
    update();
  }

  var stock = document.querySelector('[data-perk-stock]');
  if (stock) {
    var count = stock.querySelector('[data-perk-count]');
    var all   = stock.querySelector('[data-perk-all]');
    var boxes = function () { return stock.querySelectorAll('input[name="network_only[]"]:not(:disabled)'); };
    var recount = function () {
      var list = boxes(), n = 0;
      for (var i = 0; i < list.length; i++) if (list[i].checked) n++;
      if (count) count.textContent = String(n);
      if (all) {
        all.checked = list.length > 0 && n === list.length;
        all.indeterminate = n > 0 && n < list.length;
      }
    };
    stock.addEventListener('change', function (e) {
      if (e.target === all) {
        var list = boxes();
        for (var i = 0; i < list.length; i++) list[i].checked = all.checked;
      }
      recount();
    });
    recount();
  }
})();

/* ── PLANS-4b: Dashboard tab ────────────────────────────────────
   - the period picker [data-auto-submit] reloads on change
   - live "n / max characters" under the announcement message */
(function () {
  'use strict';
  var pick = document.querySelectorAll('[data-auto-submit]');
  for (var i = 0; i < pick.length; i++) {
    pick[i].addEventListener('change', function () { if (this.form) this.form.submit(); });
  }
  var hints = document.querySelectorAll('[data-count-for]');
  for (var j = 0; j < hints.length; j++) {
    (function (hint) {
      var field = document.getElementById(hint.getAttribute('data-count-for'));
      if (!field) return;
      var max = parseInt(field.getAttribute('data-count-max'), 10) || 0;
      var update = function () {
        var n = field.value.length;
        hint.textContent = n ? n.toLocaleString() + ' / ' + max.toLocaleString() + ' characters' : '';
      };
      field.addEventListener('input', update);
      update();
    })(hints[j]);
  }
})();
