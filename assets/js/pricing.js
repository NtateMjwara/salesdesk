/**
 * SalesDesk — /pricing audience switch (brokers / dealerships)
 *
 * Without this script both audiences show, one under the other, and the
 * switch buttons are plain links (?for=…#…). With it, one audience shows
 * at a time; the choice follows the URL (#brokers, #dealerships, or any
 * anchor inside a panel such as #plan-business_pro or #vip-perks).
 */
(function () {
  'use strict';

  var sw = document.querySelector('[data-pr-switch]');
  if (!sw) return;

  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-pr-panel]'));
  var btns   = Array.prototype.slice.call(sw.querySelectorAll('[data-pr-target]'));
  if (panels.length < 2) return;

  var names = panels.map(function (p) { return p.getAttribute('data-pr-panel'); });

  function show(name) {
    if (names.indexOf(name) === -1) return false;
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-pr-panel') !== name; });
    btns.forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-pr-target') === name ? 'true' : 'false');
    });
    return true;
  }

  /** The panel a URL hash points into, if any. */
  function panelForHash(hash) {
    if (!hash || hash.length < 2) return null;
    var el = null;
    try { el = document.getElementById(decodeURIComponent(hash.slice(1))); } catch (e) { return null; }
    if (!el) return null;
    var panel = el.closest('[data-pr-panel]');
    return panel ? panel.getAttribute('data-pr-panel') : null;
  }

  function fromUrl() {
    var name = panelForHash(window.location.hash);
    if (!name) {
      var m = /[?&]for=([a-z]+)/.exec(window.location.search);
      name = m ? m[1] : sw.getAttribute('data-initial');
    }
    if (!show(name)) show(names[0]);
  }

  btns.forEach(function (b) {
    b.addEventListener('click', function (e) {
      var name = b.getAttribute('data-pr-target');
      if (!show(name)) return;
      e.preventDefault();
      try {
        window.history.replaceState(null, '', window.location.pathname + '?for=' + encodeURIComponent(name));
      } catch (err) { /* file:// or sandboxed: the switch still works */ }
    });
  });

  window.addEventListener('hashchange', function () {
    var name = panelForHash(window.location.hash);
    if (name) show(name);
  });

  document.documentElement.classList.add('pr-js');
  fromUrl();
})();
