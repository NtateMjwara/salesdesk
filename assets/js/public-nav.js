/**
 * SalesDesk — Public Nav Controller  (v1)
 * assets/js/public-nav.js
 *
 * SINGLE OWNER of nav behaviour for views/layout-public.php.
 * No page script may bind click handlers to the hamburger, the
 * mobile drawer or any mega panel. (Duplicate handlers cancel each
 * other out: two toggles on one click = open then instantly close.)
 *
 * Replaces:
 *   layout-public.php — inline mega-nav + hamburger <script>
 *   public.js         — initNavDropdowns() (targeted retired IDs)
 *   cars-for-sale/index.php — duplicate hamburger block
 *
 * Markup contract (no IDs required):
 *   .pub-nav__browse / .pub-nav__acct-wrap
 *       > button[aria-haspopup="true"]
 *       > .pub-mega-panel
 *   #pubNavHamburger  (aria-controls="pubMobileNav")
 *   #pubMobileNav
 *
 * Loaded with `defer` from layout-public.php.
 */
(function () {
  'use strict';

  if (window.__sdPublicNavReady) return;   // guard against double include
  window.__sdPublicNavReady = true;

  /* Keep in sync with the 1100px collapse point in public-shell.css §8 */
  var NAV_DESKTOP_MQ = window.matchMedia('(min-width: 1101px)');
  var VIEWPORT_PAD   = 12;   // px kept clear of the viewport edge
  var LOCK_CLASS     = 'pub-scroll-lock';

  var root = document.documentElement;
  var nav  = document.querySelector('.pub-nav');
  if (!nav) return;


  /* ═══════════════════════════════════════════
     1. DROPDOWN PANELS (mega + account)
     ═══════════════════════════════════════════ */
  var zones = [];

  nav.querySelectorAll('.pub-nav__browse, .pub-nav__acct-wrap').forEach(function (wrap) {
    var btn   = wrap.querySelector('button[aria-haspopup="true"]');
    var panel = wrap.querySelector('.pub-mega-panel');
    if (btn && panel) zones.push({ btn: btn, panel: panel });
  });

  function closeZone(z) {
    z.btn.classList.remove('open');
    z.btn.setAttribute('aria-expanded', 'false');
    z.panel.classList.remove('open');
  }

  function closeAllZones(except) {
    zones.forEach(function (z) { if (z !== except) closeZone(z); });
  }

  /* Nudge a centred panel back inside the viewport. */
  function fitPanel(panel) {
    if (panel.classList.contains('pub-acct-panel')) return; // right-anchored
    panel.style.setProperty('--pub-panel-shift', '0px');

    var rect  = panel.getBoundingClientRect();
    var vw    = document.documentElement.clientWidth;
    var shift = 0;

    if (rect.left < VIEWPORT_PAD) {
      shift = VIEWPORT_PAD - rect.left;
    } else if (rect.right > vw - VIEWPORT_PAD) {
      shift = (vw - VIEWPORT_PAD) - rect.right;
    }

    if (shift !== 0) panel.style.setProperty('--pub-panel-shift', shift + 'px');
  }

  function openZone(z) {
    closeAllZones(z);
    closeMobileNav();
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

    // Clicks inside a panel must not bubble to the document closer.
    z.panel.addEventListener('click', function (e) { e.stopPropagation(); });
  });


  /* ═══════════════════════════════════════════
     2. MOBILE DRAWER
     ═══════════════════════════════════════════ */
  var hamburger = document.getElementById('pubNavHamburger');
  var drawer    = document.getElementById('pubMobileNav');

  function isDrawerOpen() {
    return !!drawer && drawer.classList.contains('open');
  }

  function openMobileNav() {
    if (!hamburger || !drawer) return;
    closeAllZones(null);
    hamburger.classList.add('open');
    hamburger.setAttribute('aria-expanded', 'true');
    hamburger.setAttribute('aria-label', 'Close navigation menu');
    drawer.classList.add('open');
    drawer.removeAttribute('aria-hidden');
    root.classList.add(LOCK_CLASS);

    var first = drawer.querySelector('a, button');
    if (first) first.focus({ preventScroll: true });
  }

  function closeMobileNav(returnFocus) {
    if (!hamburger || !isDrawerOpen()) return;
    hamburger.classList.remove('open');
    hamburger.setAttribute('aria-expanded', 'false');
    hamburger.setAttribute('aria-label', 'Open navigation menu');
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    root.classList.remove(LOCK_CLASS);
    if (returnFocus) hamburger.focus();
  }

  if (hamburger && drawer) {
    drawer.setAttribute('aria-hidden', 'true');

    hamburger.addEventListener('click', function (e) {
      e.stopPropagation();
      isDrawerOpen() ? closeMobileNav(false) : openMobileNav();
    });

    drawer.addEventListener('click', function (e) {
      if (e.target.closest('a')) closeMobileNav(false);
    });
  }


  /* ═══════════════════════════════════════════
     3. GLOBAL CLOSERS
     ═══════════════════════════════════════════ */
  document.addEventListener('click', function () { closeAllZones(null); });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;

    var openZoneRef = zones.filter(function (z) {
      return z.panel.classList.contains('open');
    })[0];

    if (openZoneRef) {
      closeZone(openZoneRef);
      openZoneRef.btn.focus();
    } else if (isDrawerOpen()) {
      closeMobileNav(true);
    }
  });

  // Crossing the breakpoint: drawer can't stay open on desktop,
  // mega panels can't stay open on mobile.
  function onBreakpointChange() {
    closeAllZones(null);
    closeMobileNav(false);
  }

  if (NAV_DESKTOP_MQ.addEventListener) {
    NAV_DESKTOP_MQ.addEventListener('change', onBreakpointChange);
  } else if (NAV_DESKTOP_MQ.addListener) {
    NAV_DESKTOP_MQ.addListener(onBreakpointChange);   // Safari < 14
  }

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      zones.forEach(function (z) {
        if (z.panel.classList.contains('open')) fitPanel(z.panel);
      });
    }, 120);
  });

})();
