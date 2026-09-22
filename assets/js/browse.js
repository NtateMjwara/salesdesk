/**
 * SalesDesk — Browse Page Behaviour  (v2)
 * assets/js/browse.js
 *
 * Shared by every page that renders the browse sidebar + grid
 * (/cars-for-sale/, broker storefront, /desks/).
 * Load via $extraJs (layout-public.php renders it deferred, after
 * public-nav.js — which also initialises the sidebar typeahead).
 *
 * v2 (public UX/UI overhaul):
 *   – Drawer breakpoint moved to 1024px (sheet on tablets too), with a
 *     focus trap and swipe-free close (overlay / Esc / close button).
 *   – Inside the open drawer, filter changes DON'T reload the page:
 *     a live "Show N cars" count is fetched from /api/cars/search.php
 *     and the sheet applies on tap. On desktop changes auto-apply.
 *   – Grid / list view toggle, remembered per browser.
 *   – Clean submit + auto-apply moved to public.js (every public page
 *     with a GET filter form gets them); this file only suspends them
 *     while the sheet is open and shows the live count instead.
 *   – Typeahead init removed here (public-nav.js owns it).
 *
 * Markup contract:
 *   #browseSidebar, #browseSidebarOverlay, #browseFilterToggle,
 *   #browseSidebarClose                       — mobile filter drawer
 *   [data-autosubmit]                         — applies its form on change (public.js)
 *   [data-browse-filters] [data-filter-apply-label] — live count target
 *   [data-view-root] + [data-view="grid|list"]      — layout toggle
 */
(function () {
  'use strict';

  var LOCK_CLASS = 'pub-scroll-lock';
  var DRAWER_MQ  = window.matchMedia('(max-width: 1024px)');   // browse.css §11
  var VIEW_KEY   = 'sd_browse_view';
  var root       = document.documentElement;
  var fmt        = new Intl.NumberFormat('en-ZA');

  var sidebar   = document.getElementById('browseSidebar');
  var overlay   = document.getElementById('browseSidebarOverlay');
  var toggleBtn = document.getElementById('browseFilterToggle');
  var closeBtn  = document.getElementById('browseSidebarClose');


  /* ═══════════════════════════════════════════
     1. FILTER DRAWER (≤ 1024px)
     ═══════════════════════════════════════════ */
  function isOpen() { return !!sidebar && sidebar.classList.contains('drawer-open'); }

  function openDrawer() {
    if (!sidebar) return;
    sidebar.classList.add('drawer-open');
    if (overlay) { overlay.classList.add('open'); overlay.removeAttribute('aria-hidden'); }
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    sidebar.setAttribute('role', 'dialog');
    sidebar.setAttribute('aria-modal', 'true');
    root.classList.add(LOCK_CLASS);
    document.querySelectorAll('[data-browse-filters]').forEach(function (f) { f.setAttribute('data-autosubmit-suspended', ''); });
    setTimeout(function () { if (closeBtn) closeBtn.focus({ preventScroll: true }); }, 60);
  }

  function closeDrawer(returnFocus) {
    if (!isOpen()) return;
    sidebar.classList.remove('drawer-open');
    if (overlay) { overlay.classList.remove('open'); overlay.setAttribute('aria-hidden', 'true'); }
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    sidebar.removeAttribute('role');
    sidebar.removeAttribute('aria-modal');
    root.classList.remove(LOCK_CLASS);
    document.querySelectorAll('[data-browse-filters]').forEach(function (f) { f.removeAttribute('data-autosubmit-suspended'); });
    if (returnFocus && toggleBtn) toggleBtn.focus();
  }

  if (sidebar) {
    if (toggleBtn) toggleBtn.addEventListener('click', openDrawer);
    if (closeBtn)  closeBtn.addEventListener('click', function () { closeDrawer(true); });
    if (overlay)   overlay.addEventListener('click', function () { closeDrawer(true); });

    document.addEventListener('keydown', function (e) {
      if (!isOpen()) return;
      if (e.key === 'Escape') { closeDrawer(true); return; }
      if (e.key !== 'Tab') return;
      var f = Array.prototype.filter.call(
        sidebar.querySelectorAll('a[href], button, input:not([type=hidden]), select, summary'),
        function (el) { return el.offsetParent !== null && !el.disabled; }
      );
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });

    var onBp = function (e) { if (!e.matches) closeDrawer(false); };
    if (DRAWER_MQ.addEventListener) DRAWER_MQ.addEventListener('change', onBp);
    else if (DRAWER_MQ.addListener) DRAWER_MQ.addListener(onBp);
  }


  /* ═══════════════════════════════════════════
     2. LIVE COUNT INSIDE THE OPEN SHEET
     Clean submit + auto-apply themselves live in public.js
     (window.sdSubmitClean + the [data-autosubmit] listener); while the
     sheet is open this file suspends that and counts instead.
     ═══════════════════════════════════════════ */
  var countTimer = null, countCtrl = null;

  function liveCount(form) {
    var label = form.querySelector('[data-filter-apply-label]');
    if (!label) return;
    clearTimeout(countTimer);
    countTimer = setTimeout(function () {
      var p = new URLSearchParams();
      new FormData(form).forEach(function (v, k) {
        v = String(v).trim();
        if (v !== '' && k !== 'ref' && k !== 'sort') p.append(k, v);
      });
      // The API has no desk filter — show a generic label rather than a wrong number.
      if (p.has('desk') || form.hasAttribute('data-desk-scoped')) { label.textContent = 'Show results'; return; }
      p.set('per_page', '1');

      if (countCtrl && countCtrl.abort) countCtrl.abort();
      countCtrl = window.AbortController ? new AbortController() : null;
      label.textContent = 'Counting…';

      fetch('/api/cars/search.php?' + p.toString(), countCtrl ? { signal: countCtrl.signal } : {})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var n = typeof d.total === 'number' ? d.total : null;
          label.textContent = n === null ? 'Show results'
            : n === 0 ? 'No matches — adjust filters'
            : 'Show ' + fmt.format(n).replace(/,/g, '\u00a0') + ' ' + (n === 1 ? 'car' : 'cars');
        })
        .catch(function (err) { if (!err || err.name !== 'AbortError') label.textContent = 'Show results'; });
    }, 220);
  }

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.form || !el.hasAttribute || !el.hasAttribute('data-autosubmit')) return;
    if (isOpen() && el.form.hasAttribute('data-browse-filters')) liveCount(el.form);
  });


  /* ═══════════════════════════════════════════
     4. GRID / LIST VIEW
     ═══════════════════════════════════════════ */
  var grid = document.querySelector('[data-view-root]');
  var viewBtns = document.querySelectorAll('[data-view]');

  function setView(view, persist) {
    if (!grid) return;
    grid.classList.toggle('is-list', view === 'list');
    viewBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false'); });
    if (persist) { try { localStorage.setItem(VIEW_KEY, view); } catch (err) { /* private mode */ } }
  }

  if (grid && viewBtns.length) {
    var saved = null;
    try { saved = localStorage.getItem(VIEW_KEY); } catch (err) { saved = null; }
    if (saved === 'list' || saved === 'grid') setView(saved, false);
    viewBtns.forEach(function (b) {
      b.addEventListener('click', function () { setView(b.getAttribute('data-view'), true); });
    });
  }


  /* ═══════════════════════════════════════════
     5. LEGACY TYPEAHEAD HOOK
     Older markup (no data-typeahead-box) relied on this file; keep a
     no-op-safe init for any input public-nav.js didn't see.
     ═══════════════════════════════════════════ */
  if (typeof window.initSearchTypeahead === 'function') {
    document.querySelectorAll('input[data-typeahead-box]:not([data-typeahead-ready])').forEach(function (input) {
      if (!input.id) return;
      window.initSearchTypeahead({ inputId: input.id, boxId: input.getAttribute('data-typeahead-box') });
    });
  }

})();
