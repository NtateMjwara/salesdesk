/**
 * SalesDesk — Dealer Search Widget
 * T4 owns this file.
 *
 * A reusable AJAX dealership lookup widget that fetches from
 * /api/dealers/search.php and renders a dropdown of results.
 *
 * Usage (HTML setup):
 *
 *   <div class="dealer-search-wrap" id="dealerSearchWrap">
 *     <input class="finput" type="text" id="dealerSearchInput"
 *            placeholder="Search dealerships…" autocomplete="off">
 *     <div class="dealer-results" id="dealerResultsList"></div>
 *   </div>
 *   <input type="hidden" id="selectedDealerId" name="dealer_id">
 *   <div class="dealer-selected" id="dealerChip" style="display:none">
 *     <i class="fa-solid fa-building-user"></i>
 *     <span class="dealer-selected-name" id="dealerChipName"></span>
 *     <button type="button" class="dealer-selected-clear" id="dealerChipClear">Change</button>
 *   </div>
 *
 *   <script src="/assets/js/dealer-search.js"></script>
 *   <script>
 *     const widget = initDealerSearch({
 *       inputId:    'dealerSearchInput',
 *       resultsId:  'dealerResultsList',
 *       hiddenId:   'selectedDealerId',
 *       chipId:     'dealerChip',
 *       chipNameId: 'dealerChipName',
 *       chipClearId:'dealerChipClear',
 *       wrapId:     'dealerSearchWrap',
 *       onSelect: function(dealer) {
 *         // called when a dealer is selected
 *         console.log('Selected:', dealer);
 *       },
 *       onClear: function() {
 *         // called when selection is cleared
 *       }
 *     });
 *   </script>
 *
 * Public API:
 *   widget.getValue()    → { id, company_name, city, ... } | null
 *   widget.setValue(dealer)  → programmatic selection
 *   widget.clear()           → clear selection
 */

(function (global) {
  'use strict';

  /**
   * Initialise a dealer search widget.
   * Returns a controller object.
   *
   * @param {object} opts
   * @param {string} opts.inputId
   * @param {string} opts.resultsId
   * @param {string} opts.hiddenId      — hidden input for dealer_id
   * @param {string} opts.chipId        — selected dealer chip wrapper
   * @param {string} opts.chipNameId    — chip name span
   * @param {string} opts.chipClearId   — chip "Change" button
   * @param {string} opts.wrapId        — search input wrapper (hidden when chip shown)
   * @param {function} [opts.onSelect]  — callback(dealer)
   * @param {function} [opts.onClear]   — callback()
   * @returns {{ getValue, setValue, clear }}
   */
  function initDealerSearch(opts) {
    var inputEl    = document.getElementById(opts.inputId);
    var resultsEl  = document.getElementById(opts.resultsId);
    var hiddenEl   = document.getElementById(opts.hiddenId);
    var chipEl     = document.getElementById(opts.chipId);
    var chipNameEl = document.getElementById(opts.chipNameId);
    var chipClrEl  = document.getElementById(opts.chipClearId);
    var wrapEl     = document.getElementById(opts.wrapId);

    if (!inputEl || !resultsEl || !hiddenEl) {
      console.warn('[DealerSearch] Required elements not found:', opts);
      return { getValue: function() { return null; }, setValue: function() {}, clear: function() {} };
    }

    var debounceTimer  = null;
    var currentDealer  = null;
    var cache          = {}; // simple query cache

    // ── Internal helpers ──────────────────────────────────────

    function esc(str) {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function showChip(dealer) {
      if (chipNameEl) chipNameEl.textContent = dealer.company_name;
      if (chipEl)     chipEl.style.display   = 'flex';
      if (wrapEl)     wrapEl.style.display   = 'none';
    }

    function hideChip() {
      if (chipEl)  chipEl.style.display  = 'none';
      if (wrapEl)  wrapEl.style.display  = 'block';
      if (inputEl) {
        inputEl.value = '';
        inputEl.focus();
      }
    }

    function renderResults(dealers) {
      if (!dealers.length) {
        resultsEl.innerHTML = '<div class="dealer-result-empty">No dealerships found for that search.</div>';
        resultsEl.classList.add('open');
        return;
      }

      resultsEl.innerHTML = dealers.map(function (d) {
        var meta = [];
        if (d.city)     meta.push(esc(d.city));
        if (d.province) meta.push(esc(d.province));
        if (d.verification_status === 'verified') {
          meta.push('<span style="color:var(--green)"><i class="fa-solid fa-circle-check" style="font-size:9px"></i> Verified</span>');
        }

        return '<div class="dealer-result-item" role="option" tabindex="0"'
          + ' data-id="' + esc(d.id) + '"'
          + ' data-name="' + esc(d.company_name) + '"'
          + ' data-city="' + esc(d.city || '') + '"'
          + ' data-province="' + esc(d.province || '') + '"'
          + ' data-verified="' + (d.verification_status === 'verified' ? '1' : '0') + '">'
          + '<div class="dealer-result-name">' + esc(d.company_name) + '</div>'
          + (meta.length ? '<div class="dealer-result-meta">' + meta.join(' · ') + '</div>' : '')
          + '</div>';
      }).join('');

      resultsEl.classList.add('open');

      // Wire click handlers on fresh elements.
      Array.from(resultsEl.querySelectorAll('.dealer-result-item')).forEach(function (item) {
        item.addEventListener('click', function () {
          selectDealer({
            id:                  parseInt(item.dataset.id, 10),
            company_name:        item.dataset.name,
            city:                item.dataset.city,
            province:            item.dataset.province,
            verification_status: item.dataset.verified === '1' ? 'verified' : 'unverified',
          });
        });
        item.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            item.click();
          }
        });
      });
    }

    function selectDealer(dealer) {
      currentDealer    = dealer;
      hiddenEl.value   = dealer.id;
      showChip(dealer);
      resultsEl.classList.remove('open');
      resultsEl.innerHTML = '';
      if (typeof opts.onSelect === 'function') {
        opts.onSelect(dealer);
      }
    }

    async function doSearch(q) {
      if (cache[q]) {
        renderResults(cache[q]);
        return;
      }

      resultsEl.innerHTML = '<div class="dealer-result-empty">'
        + '<i class="fa-solid fa-circle-notch fa-spin"></i> Searching…'
        + '</div>';
      resultsEl.classList.add('open');

      try {
        var url  = '/api/dealers/search.php?q=' + encodeURIComponent(q);
        var resp = await fetch(url);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        var data = await resp.json();
        if (!Array.isArray(data)) throw new Error('Unexpected response');
        cache[q] = data;
        renderResults(data);
      } catch (err) {
        resultsEl.innerHTML = '<div class="dealer-result-empty" style="color:var(--red);">'
          + 'Search unavailable — please try again.'
          + '</div>';
      }
    }

    // ── Event listeners ────────────────────────────────────────

    inputEl.addEventListener('input', function () {
      var q = inputEl.value.trim();
      clearTimeout(debounceTimer);
      if (q.length < 2) {
        resultsEl.classList.remove('open');
        resultsEl.innerHTML = '';
        return;
      }
      debounceTimer = setTimeout(function () { doSearch(q); }, 280);
    });

    inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        resultsEl.classList.remove('open');
        resultsEl.innerHTML = '';
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        var first = resultsEl.querySelector('.dealer-result-item');
        if (first) first.focus();
      }
    });

    // Close dropdown when clicking outside.
    document.addEventListener('click', function (e) {
      var wrap = document.getElementById(opts.wrapId);
      if (wrap && !wrap.contains(e.target) &&
          chipEl && !chipEl.contains(e.target)) {
        resultsEl.classList.remove('open');
      }
    });

    // Chip "Change" button.
    if (chipClrEl) {
      chipClrEl.addEventListener('click', function () {
        currentDealer  = null;
        hiddenEl.value = '';
        hideChip();
        resultsEl.classList.remove('open');
        if (typeof opts.onClear === 'function') {
          opts.onClear();
        }
      });
    }

    // ── Public API ─────────────────────────────────────────────

    return {
      getValue: function () { return currentDealer; },

      setValue: function (dealer) {
        if (!dealer || !dealer.id) return;
        currentDealer  = dealer;
        hiddenEl.value = dealer.id;
        showChip(dealer);
      },

      clear: function () {
        currentDealer  = null;
        hiddenEl.value = '';
        hideChip();
      },
    };
  }

  // Export.
  global.initDealerSearch = initDealerSearch;

})(window);
