/**
 * SalesDesk — Homepage JavaScript  (v2)
 * assets/js/home.js
 *
 * Loaded via $extraJs from index.php (deferred, after public.js).
 *
 * v2 (public UX/UI overhaul):
 *   – Hero search behaviour moved here from the inline <script> in
 *     views/partials/hero-search-widget.php (now a plain GET form).
 *   – Activity tabs moved to the shared [data-tabs] module in public.js.
 *   – The dynamic --vh helper was removed: the hero no longer sizes
 *     itself from viewport height.
 *
 * Modules:
 *   1. Live result count ("Show N cars") via /api/cars/search.php
 *   2. Clean submit (empty fields are not sent → tidy URLs)
 *   3. Set-state styling for selects/chips + "More filters" badge
 *   4. Reset
 */
(function () {
  'use strict';

  var form = document.getElementById('heroSearch');
  if (!form) return;

  var label    = form.querySelector('[data-hs-label]');
  var submit   = form.querySelector('.hs__submit');
  var moreCnt  = form.querySelector('[data-hs-more-count]');
  var total    = parseInt(form.getAttribute('data-total') || '0', 10);
  var fmt      = new Intl.NumberFormat('en-ZA');
  var timer    = null;
  var ctrl     = null;

  function params() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) {
      v = String(v).trim();
      if (v !== '') p.append(k, v);
    });
    return p;
  }

  function hasFilters(p) {
    var n = 0;
    p.forEach(function () { n++; });
    return n > 0;
  }

  /* 1. Live count */
  function setLabel(n, filtered) {
    if (!label) return;
    if (n === null) { label.textContent = 'Search cars'; return; }
    if (n === 0)    { label.textContent = 'No exact matches — search anyway'; return; }
    label.textContent = 'Show ' + fmt.format(n).replace(/,/g, ' ') + ' ' + (n === 1 ? 'car' : 'cars');
    submit.setAttribute('aria-label', label.textContent + (filtered ? ' matching your filters' : ''));
  }

  function fetchCount() {
    var p = params();
    if (!hasFilters(p)) { setLabel(total || null, false); return; }

    if (ctrl && ctrl.abort) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    p.set('per_page', '1');
    submit.classList.add('is-loading');

    fetch('/api/cars/search.php?' + p.toString(), ctrl ? { signal: ctrl.signal } : {})
      .then(function (r) { return r.json(); })
      .then(function (d) { setLabel(typeof d.total === 'number' ? d.total : null, true); })
      .catch(function (e) { if (!e || e.name !== 'AbortError') setLabel(null); })
      .finally(function () { submit.classList.remove('is-loading'); });
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(fetchCount, 260);
  }

  /* 3. Visual state */
  function syncState() {
    form.querySelectorAll('select').forEach(function (s) { s.classList.toggle('is-set', s.value !== ''); });
    var more = 0;
    form.querySelectorAll('.hs__chip input').forEach(function (c) {
      c.closest('.hs__chip').classList.toggle('is-active', c.checked);
      if (c.checked) more++;
    });
    ['year_min', 'year_max'].forEach(function (n) { if (form.elements[n] && form.elements[n].value) more++; });
    if (moreCnt) { moreCnt.textContent = more; moreCnt.hidden = more === 0; }
  }

  form.addEventListener('change', function () { syncState(); schedule(); });
  form.addEventListener('input', function (e) { if (e.target.name === 'q') schedule(); });

  /* Keep min ≤ max for price and year */
  function guardRange(minName, maxName) {
    var min = form.elements[minName], max = form.elements[maxName];
    if (!min || !max) return;
    function check(changed) {
      if (min.value && max.value && parseInt(min.value, 10) > parseInt(max.value, 10)) {
        if (changed === min) max.value = ''; else min.value = '';
        syncState();
      }
    }
    min.addEventListener('change', function () { check(min); });
    max.addEventListener('change', function () { check(max); });
  }
  guardRange('price_min', 'price_max');
  guardRange('year_min', 'year_max');

  /* 2. Clean submit — disable empty fields so they are not serialised */
  form.addEventListener('submit', function () {
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) return;
      if ((el.type === 'radio' || el.type === 'checkbox') ? false : String(el.value).trim() === '') el.disabled = true;
      if (el.type === 'radio' && el.checked && el.value === '') el.disabled = true;
    });
    // Re-enable if the page is restored from bfcache
    setTimeout(function () {
      Array.prototype.forEach.call(form.elements, function (el) { el.disabled = false; });
    }, 800);
  });

  /* 4. Reset */
  form.addEventListener('reset', function () {
    setTimeout(function () { syncState(); setLabel(total || null, false); }, 0);
  });

  window.addEventListener('pageshow', syncState);
  syncState();

})();
