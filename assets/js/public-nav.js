/**
 * SalesDesk — Public Nav Controller  (v2)
 * assets/js/public-nav.js
 *
 * SINGLE OWNER of nav behaviour for views/layout-public.php.
 * No page script may bind click handlers to the hamburger, the
 * mobile drawer, the search sheet or any mega panel.
 *
 * v2 (public UX/UI overhaul):
 *   – Hover-intent opening on pointer devices (click still works).
 *   – Drawer: close button [data-nav-close], backdrop click, focus trap.
 *   – Mobile search sheet: [data-search-open] / [data-search-close].
 *   – .is-scrolled on .pub-nav once the page scrolls.
 *   – Initialises every input[data-typeahead-box] on the page
 *     (nav, search sheet, browse sidebar) exactly once.
 *
 * Loaded with `defer` from layout-public.php.
 */
(function () {
  'use strict';

  if (window.__sdPublicNavReady) return;
  window.__sdPublicNavReady = true;

  /* Keep in sync with the 1100px collapse point in public-shell.css §10 */
  var NAV_DESKTOP_MQ = window.matchMedia('(min-width: 1101px)');
  var HOVER_MQ       = window.matchMedia('(hover: hover) and (pointer: fine)');
  var VIEWPORT_PAD   = 12;
  var LOCK_CLASS     = 'pub-scroll-lock';

  var root = document.documentElement;
  var nav  = document.querySelector('.pub-nav');
  if (!nav) return;

  function lockScroll(on) { root.classList.toggle(LOCK_CLASS, !!on); }

  /* Keep keyboard focus inside an open dialog. */
  function trapFocus(container, e) {
    if (e.key !== 'Tab') return;
    var f = Array.prototype.filter.call(
      container.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, summary, [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetParent !== null; }
    );
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }


  /* ═══════════════════════════════════════════
     1. DROPDOWN PANELS (mega + account)
     ═══════════════════════════════════════════ */
  var zones = [];

  nav.querySelectorAll('.pub-nav__browse, .pub-nav__acct-wrap').forEach(function (wrap) {
    var btn   = wrap.querySelector('button[aria-haspopup="true"]');
    var panel = wrap.querySelector('.pub-mega-panel');
    if (btn && panel) zones.push({ wrap: wrap, btn: btn, panel: panel, timer: null });
  });

  function closeZone(z) {
    clearTimeout(z.timer);
    z.btn.classList.remove('open');
    z.btn.setAttribute('aria-expanded', 'false');
    z.panel.classList.remove('open');
  }

  function closeAllZones(except) {
    zones.forEach(function (z) { if (z !== except) closeZone(z); });
  }

  function fitPanel(panel) {
    if (panel.classList.contains('pub-acct-panel')) return;
    panel.style.setProperty('--pub-panel-shift', '0px');
    var rect  = panel.getBoundingClientRect();
    var vw    = document.documentElement.clientWidth;
    var shift = 0;
    if (rect.left < VIEWPORT_PAD) shift = VIEWPORT_PAD - rect.left;
    else if (rect.right > vw - VIEWPORT_PAD) shift = (vw - VIEWPORT_PAD) - rect.right;
    if (shift !== 0) panel.style.setProperty('--pub-panel-shift', shift + 'px');
  }

  function openZone(z) {
    closeAllZones(z);
    z.btn.classList.add('open');
    z.btn.setAttribute('aria-expanded', 'true');
    z.panel.classList.add('open');
    fitPanel(z.panel);
  }

  zones.forEach(function (z) {
    z.btn.addEventListener('click', function (e) {
      e.stopPropagation();
      z.panel.classList.contains('open') ? closeZone(z) : openZone(z);
    });

    z.panel.addEventListener('click', function (e) {
      if (!e.target.closest('a')) e.stopPropagation();
    });

    // Hover intent (desktop pointers only)
    z.wrap.addEventListener('mouseenter', function () {
      if (!HOVER_MQ.matches || !NAV_DESKTOP_MQ.matches) return;
      clearTimeout(z.timer);
      z.timer = setTimeout(function () { openZone(z); }, 90);
    });
    z.wrap.addEventListener('mouseleave', function () {
      if (!HOVER_MQ.matches || !NAV_DESKTOP_MQ.matches) return;
      clearTimeout(z.timer);
      z.timer = setTimeout(function () { closeZone(z); }, 180);
    });

    // Arrow-down from trigger moves into the panel
    z.btn.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown') return;
      e.preventDefault();
      openZone(z);
      var first = z.panel.querySelector('a, button');
      if (first) first.focus();
    });
  });


  /* ═══════════════════════════════════════════
     2. MOBILE DRAWER
     ═══════════════════════════════════════════ */
  var hamburger = document.getElementById('pubNavHamburger');
  var drawer    = document.getElementById('pubMobileNav');

  function isDrawerOpen() { return !!drawer && drawer.classList.contains('open'); }

  function openMobileNav() {
    if (!hamburger || !drawer) return;
    closeAllZones(null);
    closeSearch(false);
    hamburger.classList.add('open');
    hamburger.setAttribute('aria-expanded', 'true');
    drawer.classList.add('open');
    drawer.removeAttribute('aria-hidden');
    lockScroll(true);
    var closeBtn = drawer.querySelector('[data-nav-close]');
    setTimeout(function () { (closeBtn || drawer).focus({ preventScroll: true }); }, 60);
  }

  function closeMobileNav(returnFocus) {
    if (!hamburger || !isDrawerOpen()) return;
    hamburger.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    lockScroll(false);
    if (returnFocus) hamburger.focus();
  }

  if (hamburger && drawer) {
    drawer.setAttribute('aria-hidden', 'true');

    hamburger.addEventListener('click', function (e) {
      e.stopPropagation();
      isDrawerOpen() ? closeMobileNav(false) : openMobileNav();
    });

    drawer.addEventListener('click', function (e) {
      if (e.target === drawer || e.target.closest('[data-nav-close]')) { closeMobileNav(true); return; }
      if (e.target.closest('a')) closeMobileNav(false);
    });

    drawer.addEventListener('keydown', function (e) {
      if (isDrawerOpen()) trapFocus(drawer.querySelector('.pub-mobile-nav__panel') || drawer, e);
    });
  }


  /* ═══════════════════════════════════════════
     3. SEARCH SHEET (mobile)
     ═══════════════════════════════════════════ */
  var sheet       = document.getElementById('pubSearchSheet');
  var sheetInput  = document.getElementById('sheetSearch');
  var lastOpener  = null;

  function isSearchOpen() { return !!sheet && sheet.classList.contains('open'); }

  function openSearch(opener) {
    if (!sheet) return;
    closeMobileNav(false);
    lastOpener = opener || null;
    sheet.classList.add('open');
    sheet.removeAttribute('aria-hidden');
    lockScroll(true);
    if (sheetInput) setTimeout(function () { sheetInput.focus(); }, 80);
  }

  function closeSearch(returnFocus) {
    if (!isSearchOpen()) return;
    sheet.classList.remove('open');
    sheet.setAttribute('aria-hidden', 'true');
    lockScroll(false);
    if (returnFocus && lastOpener) lastOpener.focus();
  }

  if (sheet) {
    sheet.setAttribute('aria-hidden', 'true');
    document.addEventListener('click', function (e) {
      var open = e.target.closest('[data-search-open]');
      if (open) { e.preventDefault(); openSearch(open); return; }
      if (e.target.closest('[data-search-close]')) { e.preventDefault(); closeSearch(true); }
    });
    sheet.addEventListener('keydown', function (e) { if (isSearchOpen()) trapFocus(sheet, e); });
  }


  /* ═══════════════════════════════════════════
     4. GLOBAL CLOSERS
     ═══════════════════════════════════════════ */
  document.addEventListener('click', function () { closeAllZones(null); });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var openRef = zones.filter(function (z) { return z.panel.classList.contains('open'); })[0];
    if (openRef)             { closeZone(openRef); openRef.btn.focus(); }
    else if (isSearchOpen()) { closeSearch(true); }
    else if (isDrawerOpen()) { closeMobileNav(true); }
  });

  function onBreakpointChange() {
    closeAllZones(null);
    closeMobileNav(false);
    closeSearch(false);
  }

  if (NAV_DESKTOP_MQ.addEventListener) NAV_DESKTOP_MQ.addEventListener('change', onBreakpointChange);
  else if (NAV_DESKTOP_MQ.addListener) NAV_DESKTOP_MQ.addListener(onBreakpointChange);

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      zones.forEach(function (z) { if (z.panel.classList.contains('open')) fitPanel(z.panel); });
    }, 120);
  });


  /* ═══════════════════════════════════════════
     5. SCROLLED STATE
     ═══════════════════════════════════════════ */
  var ticking = false;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      nav.classList.toggle('is-scrolled', window.scrollY > 4);
      ticking = false;
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();


  /* ═══════════════════════════════════════════
     6. TYPEAHEADS (nav, sheet, browse sidebar…)
     ═══════════════════════════════════════════ */
  if (typeof window.initSearchTypeahead === 'function') {
    document.querySelectorAll('input[data-typeahead-box]').forEach(function (input) {
      if (!input.id) return;
      var extra = {};
      var raw = input.getAttribute('data-typeahead-params');
      if (raw) { try { extra = JSON.parse(raw) || {}; } catch (err) { /* ignore */ } }
      window.initSearchTypeahead({
        inputId: input.id,
        boxId: input.getAttribute('data-typeahead-box'),
        extraParams: extra
      });
    });
  }

})();
