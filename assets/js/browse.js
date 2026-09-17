/**
 * SalesDesk — Browse Page Behaviour  (v1)
 * assets/js/browse.js
 *
 * Shared by every page that renders the browse sidebar + grid
 * (/cars-for-sale/ now; broker storefront in the broker pass).
 * Replaces the inline <script> blocks and onchange="" attributes
 * that each page used to carry.
 *
 * Load via $extraJs in the page (layout-public.php renders it with
 * `defer`, after public-nav.js). If the page uses the typeahead, load
 * /assets/js/search-typeahead.js BEFORE this file.
 *
 * Markup contract:
 *   #browseSidebar, #browseSidebarOverlay,
 *   #browseFilterToggle, #browseSidebarClose     — mobile filter drawer
 *   [data-autosubmit]                            — submits its form on change
 *   input[data-typeahead-box="<boxId>"]          — typeahead input
 *     optional data-typeahead-params='{"salesdesk_id":12}'
 *
 * Modules:
 *   1. Filter drawer (mobile)
 *   2. Auto-submit controls
 *   3. Search typeahead init
 */
(function () {
  'use strict';

  var LOCK_CLASS = 'pub-scroll-lock';          // defined in public-shell.css §12
  var DRAWER_MQ  = window.matchMedia('(max-width: 768px)');  // browse.css §13
  var root       = document.documentElement;


  /* ═══════════════════════════════════════════
     1. FILTER DRAWER (MOBILE)
     ═══════════════════════════════════════════ */
  function initFilterDrawer() {
    var sidebar   = document.getElementById('browseSidebar');
    var overlay   = document.getElementById('browseSidebarOverlay');
    var toggleBtn = document.getElementById('browseFilterToggle');
    var closeBtn  = document.getElementById('browseSidebarClose');
    if (!sidebar || !overlay) return;

    function isOpen() { return sidebar.classList.contains('drawer-open'); }

    function openDrawer() {
      sidebar.classList.add('drawer-open');
      overlay.classList.add('open');
      overlay.removeAttribute('aria-hidden');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
      root.classList.add(LOCK_CLASS);
      if (closeBtn) closeBtn.focus({ preventScroll: true });
    }

    function closeDrawer(returnFocus) {
      if (!isOpen()) return;
      sidebar.classList.remove('drawer-open');
      overlay.classList.remove('open');
      overlay.setAttribute('aria-hidden', 'true');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
      root.classList.remove(LOCK_CLASS);
      if (returnFocus && toggleBtn) toggleBtn.focus();
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openDrawer);
    if (closeBtn)  closeBtn.addEventListener('click', function () { closeDrawer(true); });
    overlay.addEventListener('click', function () { closeDrawer(true); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen()) closeDrawer(true);
    });

    // Growing past the drawer breakpoint must not leave the page scroll-locked.
    function onBreakpointChange(e) {
      if (!e.matches) closeDrawer(false);
    }

    if (DRAWER_MQ.addEventListener) {
      DRAWER_MQ.addEventListener('change', onBreakpointChange);
    } else if (DRAWER_MQ.addListener) {
      DRAWER_MQ.addListener(onBreakpointChange);   // Safari < 14
    }
  }


  /* ═══════════════════════════════════════════
     2. AUTO-SUBMIT CONTROLS
     One delegated listener instead of an onchange="" per input.
     ═══════════════════════════════════════════ */
  function initAutoSubmit() {
    document.addEventListener('change', function (e) {
      var el = e.target;
      if (!el || !el.hasAttribute || !el.hasAttribute('data-autosubmit')) return;
      if (!el.form) return;

      if (typeof el.form.requestSubmit === 'function') {
        el.form.requestSubmit();
      } else {
        el.form.submit();
      }
    });
  }


  /* ═══════════════════════════════════════════
     3. SEARCH TYPEAHEAD INIT
     Replaces the inline initSearchTypeahead({...}) call.
     ═══════════════════════════════════════════ */
  function initTypeaheads() {
    if (typeof window.initSearchTypeahead !== 'function') return;

    document.querySelectorAll('input[data-typeahead-box]').forEach(function (input) {
      if (!input.id) return;

      var extraParams = {};
      var raw = input.getAttribute('data-typeahead-params');
      if (raw) {
        try { extraParams = JSON.parse(raw) || {}; }
        catch (err) { console.warn('[browse.js] Bad data-typeahead-params on #' + input.id); }
      }

      window.initSearchTypeahead({
        inputId:     input.id,
        boxId:       input.getAttribute('data-typeahead-box'),
        extraParams: extraParams
      });
    });
  }


  initFilterDrawer();
  initAutoSubmit();
  initTypeaheads();

})();
