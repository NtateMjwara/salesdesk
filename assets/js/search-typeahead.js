/**
 * SalesDesk — Search Typeahead
 * T1 owns this file.
 *
 * Shared autocomplete component for the sidebar search box on
 * /cars-for-sale/ and broker storefronts. Replaces the previous
 * "debounce then form.submit()" pattern, which reloaded the whole
 * page mid-keystroke and never let the user finish typing.
 *
 * Behaviour:
 *   - Debounces the FETCH to /api/cars/suggest.php (200ms), never
 *     the page navigation.
 *   - Renders a dropdown of live suggestions (make / model / body type).
 *   - The form only submits when the user explicitly picks a
 *     suggestion, presses Enter, or clicks "Apply filters" —
 *     never on a bare keystroke.
 *
 * Usage:
 *   <input type="text" name="q" id="sidebarSearch" ...>
 *   <div id="sidebarSearchBox" class="typeahead-box"></div>
 *   <script src="/assets/js/search-typeahead.js"></script>
 *   <script>
 *     initSearchTypeahead({
 *       inputId: 'sidebarSearch',
 *       boxId:   'sidebarSearchBox',
 *       extraParams: { salesdesk_id: 12 } // optional, broker storefront only
 *     });
 *   </script>
 */

(function (global) {
  'use strict';

  function initSearchTypeahead(opts) {
    var input = document.getElementById(opts.inputId);
    var box   = document.getElementById(opts.boxId);
    var form  = input ? input.closest('form') : null;

    if (!input || !box || !form) {
      console.warn('[SearchTypeahead] Required elements not found:', opts);
      return;
    }

    var endpoint    = opts.endpoint    || '/api/cars/suggest.php';
    var extraParams = opts.extraParams || {};
    var debounceMs  = opts.debounceMs  || 200;

    var debounceTimer = null;
    var items         = [];
    var focusIdx      = -1;
    var abortCtrl     = null;

    var TYPE_ICONS  = { make: 'fa-car', model: 'fa-car-side', body_type: 'fa-shapes' };
    var TYPE_LABELS = { make: 'Make', model: 'Model', body_type: 'Body type' };

    function esc(str) {
      return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function render() {
      if (!items.length) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
      }

      box.innerHTML = items.map(function (item, i) {
        var icon = TYPE_ICONS[item.type] || 'fa-magnifying-glass';
        var typeLabel = TYPE_LABELS[item.type] || '';
        return '<div class="typeahead-item' + (i === focusIdx ? ' focused' : '') + '"'
          + ' data-idx="' + i + '" role="option">'
          + '<i class="fa-solid ' + icon + ' typeahead-icon"></i>'
          + '<span class="typeahead-label">' + esc(item.label) + '</span>'
          + '<span class="typeahead-type">' + esc(typeLabel) + '</span>'
          + '</div>';
      }).join('');

      box.style.display = 'block';

      Array.from(box.querySelectorAll('.typeahead-item')).forEach(function (el) {
        el.addEventListener('mousedown', function (e) {
          e.preventDefault(); // fires before input's blur
          select(items[parseInt(el.dataset.idx, 10)]);
        });
      });
    }

    function select(item) {
      input.value = item.label;
      items = [];
      focusIdx = -1;
      render();
      // Explicit, deliberate submit — the one and only navigation
      // trigger, fired by a real user choice, not a keystroke.
      if (form.requestSubmit) form.requestSubmit();
      else form.submit();
    }

    function fetchSuggestions(q) {
      if (abortCtrl) { try { abortCtrl.abort(); } catch (e) {} }
      abortCtrl = window.AbortController ? new AbortController() : null;

      var params = new URLSearchParams(Object.assign({ q: q }, extraParams));
      var fetchOpts = abortCtrl ? { signal: abortCtrl.signal } : {};

      fetch(endpoint + '?' + params.toString(), fetchOpts)
        .then(function (r) { return r.json(); })
        .then(function (data) {
          items = data.suggestions || [];
          focusIdx = -1;
          render();
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') return;
          items = [];
          render();
        });
    }

    input.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      var q = input.value.trim();

      if (q.length < 2) {
        items = [];
        render();
        return;
      }

      // Debounce the NETWORK CALL only. The page never navigates
      // from this handler — that was the bug being fixed.
      debounceTimer = setTimeout(function () { fetchSuggestions(q); }, debounceMs);
    });

    input.addEventListener('keydown', function (e) {
      if (!items.length || box.style.display === 'none') {
        if (e.key === 'Escape') { items = []; render(); }
        // Enter with no suggestions showing: let the form submit
        // normally as a free-text search (q=whatever-they-typed).
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusIdx = Math.min(focusIdx + 1, items.length - 1);
        render();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusIdx = Math.max(focusIdx - 1, -1);
        render();
      } else if (e.key === 'Enter') {
        if (focusIdx >= 0) {
          e.preventDefault();
          select(items[focusIdx]);
        }
        // else: let the natural form submit happen with typed text
      } else if (e.key === 'Escape') {
        items = [];
        render();
      }
    });

    input.addEventListener('blur', function () {
      // Delay so a mousedown on a suggestion registers first.
      setTimeout(function () { items = []; render(); }, 150);
    });

    document.addEventListener('click', function (e) {
      if (!box.contains(e.target) && e.target !== input) {
        items = [];
        render();
      }
    });
  }

  global.initSearchTypeahead = initSearchTypeahead;

})(window);
