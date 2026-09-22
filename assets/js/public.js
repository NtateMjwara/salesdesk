/**
 * SalesDesk — Public Pages JavaScript  (v3)
 * assets/js/public.js
 *
 * Shared behaviour for every public page. Everything is opt-in through
 * data attributes, so a module does nothing on pages without its markup.
 *
 * Modules:
 *   1.  Toasts            window.sdToast(msg, {icon, href, linkText})
 *   2.  Wishlist          [data-wishlist="carId"] (+ legacy window.toggleWishlist)
 *   3.  Share             [data-share-open] / [data-share-copy] / [data-share-close]
 *   4.  Lightbox          [data-lightbox-images='[…]'] + [data-lightbox-open="i"]
 *   5.  Carousel counter  [data-carousel] > [data-carousel-track] + [data-carousel-count]
 *   6.  Enquiry form      #enquiryForm
 *   7.  Read-more clamp   [data-clamp] + [data-clamp-toggle]
 *   8.  Finance           [data-finance] (mode: "repay" | "afford")
 *   9.  Tabs              [data-tabs] > [role=tab] + [role=tabpanel]
 *  10.  Rails             [data-rail-prev="id"] / [data-rail-next="id"]
 *  11.  Sticky CTA        [data-sticky-cta][data-sticky-cta-target="#id"]
 *  12.  Section nav       [data-scrollspy] a[href^="#"] (click + spy)
 *  13.  Scroll reveal     .pub-reveal
 *  13b. GET forms        [data-autosubmit], [data-clean-submit], .sort-form
 *
 * v3 (public UX/UI overhaul): rewritten around data attributes; no more
 * inline onclick handlers or inline style writes. Loaded with `defer`
 * from layout-public.php after global.js (CSRF interceptor).
 */
(function () {
  'use strict';

  var root    = document.documentElement;
  var ZAR     = new Intl.NumberFormat('en-ZA', { maximumFractionDigits: 0 });
  var REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

  function rand(n) { return 'R ' + ZAR.format(Math.round(n)).replace(/,/g, ' '); }

  function isTypingTarget(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }


  /* ═══════════════════════════════════════════
     1. TOASTS
     ═══════════════════════════════════════════ */
  var toastRegion = document.getElementById('pubToasts');

  window.sdToast = function (msg, opts) {
    if (!toastRegion) return;
    opts = opts || {};
    var el = document.createElement('div');
    el.className = 'pub-toast';
    el.innerHTML = (opts.icon ? '<i class="fa-solid ' + esc(opts.icon) + '" aria-hidden="true"></i>' : '')
      + '<span>' + esc(msg) + '</span>'
      + (opts.href ? '<a href="' + esc(opts.href) + '">' + esc(opts.linkText || 'View') + '</a>' : '');
    toastRegion.appendChild(el);
    setTimeout(function () {
      el.classList.add('is-leaving');
      setTimeout(function () { el.remove(); }, 260);
    }, opts.duration || 3200);
  };


  /* ═══════════════════════════════════════════
     2. WISHLIST
     ═══════════════════════════════════════════ */
  var pendingWish = {};

  function setWishState(carId, on) {
    document.querySelectorAll('[data-wishlist="' + carId + '"]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.classList.toggle('wishlisted', on);
      var icon = btn.querySelector('i');
      if (icon) icon.className = (on ? 'fa-solid' : 'fa-regular') + ' fa-heart';
      var label = btn.querySelector('[data-wishlist-label]');
      if (label) label.textContent = on ? 'Saved' : 'Save';
      var aria = btn.getAttribute('aria-label') || '';
      if (aria) {
        btn.setAttribute('aria-label', on
          ? aria.replace(/^Save /, 'Remove ').replace(/(?: from saved cars)?$/, ' from saved cars')
          : aria.replace(/^Remove /, 'Save ').replace(/ from saved cars$/, ''));
      }
    });
  }

  function bumpNavCount(delta) {
    document.querySelectorAll('[data-wishlist-count]').forEach(function (el) {
      var n = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
      el.textContent = n;
      el.hidden = n === 0;
      el.classList.remove('is-bump');
      void el.offsetWidth;
      el.classList.add('is-bump');
      var link = el.closest('[data-wishlist-link]');
      if (link) link.setAttribute('aria-label', 'Saved cars (' + n + ')');
    });
  }

  function toggleWish(btn, carId) {
    if (!carId || pendingWish[carId]) return;
    pendingWish[carId] = true;

    var wasOn = btn.getAttribute('aria-pressed') === 'true' || btn.classList.contains('wishlisted');
    setWishState(carId, !wasOn);                 // optimistic
    btn.classList.remove('is-pop'); void btn.offsetWidth; btn.classList.add('is-pop');

    fetch('/api/visitor/wishlist-toggle.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: 'car_id=' + encodeURIComponent(carId)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (typeof data.wishlisted !== 'boolean') throw new Error(data.error || 'Failed');
        setWishState(carId, data.wishlisted);
        if (data.wishlisted !== wasOn) bumpNavCount(data.wishlisted ? 1 : -1);
        window.sdToast(data.wishlisted ? 'Saved to your cars' : 'Removed from saved cars', {
          icon: data.wishlisted ? 'fa-heart' : 'fa-heart-crack',
          href: data.wishlisted ? '/account/wishlist/' : null,
          linkText: 'View saved'
        });
      })
      .catch(function () {
        setWishState(carId, wasOn);              // roll back
        window.sdToast("Couldn't update saved cars. Please try again.", { icon: 'fa-circle-exclamation' });
      })
      .finally(function () { delete pendingWish[carId]; });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-wishlist]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    toggleWish(btn, btn.getAttribute('data-wishlist'));
  });

  // Legacy API (broker storefront markup still calls this)
  window.toggleWishlist = function (btn, carId) { if (btn) toggleWish(btn, String(carId)); };


  /* ═══════════════════════════════════════════
     3. SHARE
     ═══════════════════════════════════════════ */
  var shareOverlay = document.getElementById('shareOverlay');
  var shareOpener  = null;

  window.openShareSheet = function (opener) {
    if (!shareOverlay) return;
    shareOpener = opener || document.activeElement;
    shareOverlay.classList.add('open');
    root.classList.add('pub-scroll-lock');
    var first = shareOverlay.querySelector('a, button');
    if (first) setTimeout(function () { first.focus(); }, 60);
  };

  window.closeShareSheet = function () {
    if (!shareOverlay || !shareOverlay.classList.contains('open')) return;
    shareOverlay.classList.remove('open');
    root.classList.remove('pub-scroll-lock');
    if (shareOpener && shareOpener.focus) shareOpener.focus();
  };

  window.copyShareUrl = function (triggerEl) {
    var input = document.getElementById('shareUrlInput');
    if (!input) return;
    function done() {
      var t = triggerEl || document.querySelector('[data-share-copy]');
      if (t) {
        t.classList.add('is-copied');
        setTimeout(function () { t.classList.remove('is-copied'); }, 1800);
      }
      window.sdToast('Link copied', { icon: 'fa-link' });
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(done).catch(function () { input.select(); document.execCommand('copy'); done(); });
    } else {
      input.select(); document.execCommand('copy'); done();
    }
  };

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-share-open], [data-share-copy], [data-share-close]');
    if (!el) return;
    e.preventDefault();

    if (el.hasAttribute('data-share-open')) {
      // Native share sheet on devices that have one (mostly mobile)
      var input = document.getElementById('shareUrlInput');
      var title = document.getElementById('shareSheetTitle');
      var coarse = window.matchMedia('(pointer: coarse)').matches;
      if (coarse && navigator.share && input) {
        navigator.share({ title: document.title, text: title ? title.nextElementSibling.textContent : '', url: input.value })
          .catch(function () { /* user cancelled */ });
      } else {
        window.openShareSheet(el);
      }
    }
    if (el.hasAttribute('data-share-copy'))  window.copyShareUrl(el);
    if (el.hasAttribute('data-share-close')) window.closeShareSheet();
  });

  if (shareOverlay) {
    shareOverlay.addEventListener('click', function (e) { if (e.target === shareOverlay) window.closeShareSheet(); });
  }


  /* ═══════════════════════════════════════════
     4. LIGHTBOX
     Root: [data-lightbox-images='["url",…]'] [data-lightbox-title="…"]
     Openers inside root: [data-lightbox-open="index"]
     ═══════════════════════════════════════════ */
  function initLightbox() {
    var host = document.querySelector('[data-lightbox-images]');
    if (!host) return;

    var images;
    try { images = JSON.parse(host.getAttribute('data-lightbox-images')) || []; } catch (err) { images = []; }
    if (!images.length) return;

    var title = host.getAttribute('data-lightbox-title') || '';
    var idx = 0, opener = null, touchX = null;

    var lb = document.createElement('div');
    lb.className = 'pub-lightbox';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-label', 'Photo viewer');
    lb.innerHTML =
      '<div class="pub-lightbox__bar">'
      + '<span class="pub-lightbox__count" aria-live="polite"></span>'
      + '<span class="pub-lightbox__title">' + esc(title) + '</span>'
      + '<button class="pub-lightbox__btn" type="button" data-lb-close aria-label="Close photos"><i class="fa-solid fa-xmark"></i></button>'
      + '</div>'
      + '<div class="pub-lightbox__stage">'
      + '<button class="pub-lightbox__btn pub-lightbox__prev" type="button" data-lb-prev aria-label="Previous photo"><i class="fa-solid fa-chevron-left"></i></button>'
      + '<img class="pub-lightbox__img" alt="">'
      + '<button class="pub-lightbox__btn pub-lightbox__next" type="button" data-lb-next aria-label="Next photo"><i class="fa-solid fa-chevron-right"></i></button>'
      + '</div>'
      + '<div class="pub-lightbox__thumbs">'
      + images.map(function (src, i) {
          return '<button class="pub-lightbox__thumb" type="button" data-lb-go="' + i + '" aria-label="Photo ' + (i + 1) + '"><img src="' + esc(src) + '" alt="" loading="lazy"></button>';
        }).join('')
      + '</div>';
    document.body.appendChild(lb);

    var img    = lb.querySelector('.pub-lightbox__img');
    var count  = lb.querySelector('.pub-lightbox__count');
    var thumbs = lb.querySelectorAll('.pub-lightbox__thumb');

    function show(i) {
      idx = (i + images.length) % images.length;
      img.classList.add('is-loading');
      img.onload = function () { img.classList.remove('is-loading'); };
      img.src = images[idx];
      img.alt = title + ' — photo ' + (idx + 1);
      count.textContent = (idx + 1) + ' / ' + images.length;
      thumbs.forEach(function (t, k) { t.classList.toggle('active', k === idx); });
      if (thumbs[idx]) keepInViewX(lb.querySelector('.pub-lightbox__thumbs'), thumbs[idx]);
      // Preload neighbours
      [idx + 1, idx - 1].forEach(function (n) { var p = new Image(); p.src = images[(n + images.length) % images.length]; });
    }

    function open(i, from) {
      opener = from || null;
      lb.classList.add('open');
      root.classList.add('pub-scroll-lock');
      show(i);
      lb.querySelector('[data-lb-close]').focus();
    }

    function close() {
      lb.classList.remove('open');
      root.classList.remove('pub-scroll-lock');
      if (opener) opener.focus();
    }

    host.addEventListener('click', function (e) {
      var o = e.target.closest('[data-lightbox-open]');
      if (!o) return;
      e.preventDefault();
      open(parseInt(o.getAttribute('data-lightbox-open'), 10) || 0, o);
    });

    lb.addEventListener('click', function (e) {
      if (e.target.closest('[data-lb-close]') || e.target.classList.contains('pub-lightbox__stage')) { close(); return; }
      if (e.target.closest('[data-lb-prev]')) show(idx - 1);
      if (e.target.closest('[data-lb-next]')) show(idx + 1);
      var go = e.target.closest('[data-lb-go]');
      if (go) show(parseInt(go.getAttribute('data-lb-go'), 10));
    });

    lb.addEventListener('touchstart', function (e) { touchX = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (touchX === null) return;
      var d = touchX - e.changedTouches[0].clientX;
      if (Math.abs(d) > 40) show(d > 0 ? idx + 1 : idx - 1);
      touchX = null;
    }, { passive: true });

    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('open')) return;
      if (e.key === 'Escape')     close();
      if (e.key === 'ArrowRight') show(idx + 1);
      if (e.key === 'ArrowLeft')  show(idx - 1);
      if (e.key === 'Tab') {
        var f = lb.querySelectorAll('button');
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    });
  }


  /* ═══════════════════════════════════════════
     5. CAROUSEL COUNTER (native scroll-snap)
     ═══════════════════════════════════════════ */
  function initCarousels() {
    document.querySelectorAll('[data-carousel]').forEach(function (c) {
      var track = c.querySelector('[data-carousel-track]');
      var count = c.querySelector('[data-carousel-count]');
      if (!track) return;
      var slides = track.children.length;

      function update() {
        var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
        if (count) count.textContent = (i + 1) + ' / ' + slides;
      }
      track.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });

      c.addEventListener('click', function (e) {
        var dir = e.target.closest('[data-carousel-prev]') ? -1 : e.target.closest('[data-carousel-next]') ? 1 : 0;
        if (!dir) return;
        e.preventDefault();
        track.scrollBy({ left: dir * track.clientWidth, behavior: 'smooth' });
      });
      update();
    });
  }


  /* ═══════════════════════════════════════════
     6. ENQUIRY FORM
     ═══════════════════════════════════════════ */
  function initEnquiryForm() {
    var form = document.getElementById('enquiryForm');
    if (!form) return;

    var submitBtn = document.getElementById('enquirySubmit');
    var successEl = document.getElementById('enquirySuccess');
    var globalErr = document.getElementById('enquiryGlobalError');
    var btnHtml   = submitBtn ? submitBtn.innerHTML : '';

    function fieldError(input, msg) {
      if (!input) return;
      var err = document.getElementById(input.getAttribute('aria-describedby') || '') ||
                (input.parentElement && input.parentElement.querySelector('.pub-form-error'));
      if (err) err.textContent = msg;
      input.setAttribute('aria-invalid', 'true');
      input.addEventListener('input', function () {
        input.removeAttribute('aria-invalid');
        if (err) err.textContent = '';
      }, { once: true });
    }

    function showGlobal(msg) {
      if (!globalErr) return;
      globalErr.querySelector('span').textContent = msg;
      globalErr.hidden = false;
    }

    function resetBtn() {
      if (!submitBtn) return;
      submitBtn.disabled = false;
      submitBtn.innerHTML = btnHtml;
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (globalErr) globalErr.hidden = true;
      form.querySelectorAll('.pub-form-error').forEach(function (el) { el.textContent = ''; });

      var name    = form.querySelector('[name="buyer_name"]');
      var phone   = form.querySelector('[name="buyer_phone"]');
      var email   = form.querySelector('[name="buyer_email"]');
      var consent = form.querySelector('[name="consent_given"]');
      var firstBad = null;

      if (!name || name.value.trim().length < 2) { fieldError(name, 'Please enter your name.'); firstBad = firstBad || name; }
      var digits = phone ? phone.value.replace(/\D/g, '') : '';
      if (digits.length < 9) { fieldError(phone, 'Please enter a valid phone number.'); firstBad = firstBad || phone; }
      if (email && email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
        fieldError(email, 'That email address doesn’t look right.'); firstBad = firstBad || email;
      }
      if (!consent || !consent.checked) {
        var ce = document.getElementById('consentError');
        if (ce) ce.textContent = 'Please accept to send your enquiry.';
        firstBad = firstBad || consent;
      }
      if (firstBad) { firstBad.focus(); return; }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Sending…';
      }

      fetch('/api/leads/submit.php', { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            form.hidden = true;
            if (successEl) { successEl.hidden = false; successEl.focus(); }
            document.querySelectorAll('[data-enquiry-hide-on-success]').forEach(function (el) { el.hidden = true; });
            return;
          }
          resetBtn();
          if (res.duplicate)      showGlobal('You’ve already enquired about this car — the seller will be in touch shortly.');
          else if (res.stale)     showGlobal('This listing is no longer available (' + (res.car_status || 'sold') + ').');
          else if (res.not_found) showGlobal('This tracking link has expired. Please find the car directly.');
          else                    showGlobal(res.error || 'Something went wrong. Please try again.');
        })
        .catch(function () {
          resetBtn();
          showGlobal('Connection error. Please check your internet and try again.');
        });
    });

    // Intent chips (radio group) – nothing to do; native radios.
    // Focus the form from any [data-enquiry-focus] trigger (e.g. sticky mobile bar)
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-enquiry-focus]');
      if (!t) return;
      e.preventDefault();
      var card = document.getElementById('enquiry');
      if (card) {
        var navEl = document.querySelector('.pub-nav');
        var top = window.scrollY + card.getBoundingClientRect().top - ((navEl ? navEl.getBoundingClientRect().height : 0) + 12);
        window.scrollTo({ top: Math.max(0, top), behavior: REDUCED.matches ? 'auto' : 'smooth' });
      }
      var first = form.querySelector('input:not([type=hidden])');
      if (first && !form.hidden) setTimeout(function () { first.focus({ preventScroll: true }); }, 450);
    });
  }


  /* ═══════════════════════════════════════════
     7. READ-MORE CLAMP
     ═══════════════════════════════════════════ */
  function initClamp() {
    document.querySelectorAll('[data-clamp]').forEach(function (text) {
      var toggle = document.querySelector('[data-clamp-toggle="' + text.id + '"]');
      if (!toggle) return;
      // Hide toggle when the text isn't actually overflowing
      if (text.scrollHeight <= text.clientHeight + 4) { toggle.hidden = true; text.classList.remove('is-clamped'); return; }
      toggle.addEventListener('click', function () {
        var expanded = text.classList.toggle('is-clamped') === false;
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.querySelector('span').textContent = expanded ? 'Show less' : 'Read more';
      });
    });
  }


  /* ═══════════════════════════════════════════
     8. FINANCE
     mode "repay":  price fixed → monthly instalment
     mode "afford": monthly budget → max car price (+ browse link)
     ═══════════════════════════════════════════ */
  function pmt(principal, ratePct, months, balloon) {
    var r = ratePct / 100 / 12;
    balloon = balloon || 0;
    if (r <= 0) return (principal - balloon) / months;
    var f = Math.pow(1 + r, months);
    return (principal - balloon / f) * r / (1 - 1 / f);
  }

  function presentValue(monthly, ratePct, months, balloonPctOfPrice, depositPct) {
    // Solve price P: pmt(P*(1-d), rate, n, P*b) = monthly  → linear in P
    var unit = pmt(1 - depositPct / 100, ratePct, months, balloonPctOfPrice / 100);
    return unit > 0 ? monthly / unit : 0;
  }

  function paintRange(el) {
    var min = parseFloat(el.min) || 0, max = parseFloat(el.max) || 100;
    el.style.setProperty('--fill', ((parseFloat(el.value) - min) / (max - min) * 100) + '%');
  }

  function initFinance() {
    document.querySelectorAll('[data-finance]').forEach(function (w) {
      var mode    = w.getAttribute('data-finance') || 'repay';
      var price   = parseFloat(w.getAttribute('data-price') || '0');
      var q       = function (s) { return w.querySelector(s); };
      var dep     = q('[data-fin-deposit]');
      var bal     = q('[data-fin-balloon]');
      var rate    = q('[data-fin-rate]');
      var budget  = q('[data-fin-budget]');
      var link    = q('[data-fin-link]');

      function term() {
        var t = w.querySelector('[data-fin-term]:checked');
        return t ? parseInt(t.value, 10) : 60;
      }

      function set(sel, text) { var el = q(sel); if (el) el.textContent = text; }

      function compute() {
        var d = dep ? parseFloat(dep.value) : 10;
        var b = bal ? parseFloat(bal.value) : 0;
        var r = rate ? parseFloat(rate.value) : 13.25;
        var n = term();
        w.querySelectorAll('input[type=range]').forEach(paintRange);

        set('[data-fin-rate-out]', r.toFixed(2).replace(/\.?0+$/, '') + '%');

        if (mode === 'afford') {
          var m = budget ? parseFloat(budget.value) : 6000;
          var maxPrice = presentValue(m, r, n, b, d);
          maxPrice = Math.floor(maxPrice / 5000) * 5000;
          set('[data-fin-budget-out]', rand(m) + ' p/m');
          set('[data-fin-deposit-out]', d + '%');
          set('[data-fin-balloon-out]', b + '%');
          set('[data-fin-result]', rand(maxPrice));
          if (link) {
            link.href = '/cars-for-sale/?price_max=' + maxPrice + '&sort=price_desc';
            var lbl = link.querySelector('span');
            if (lbl) lbl.textContent = 'Show cars under ' + rand(maxPrice);
          }
          return;
        }

        var deposit = price * d / 100;
        var balloon = price * b / 100;
        var loan    = price - deposit;
        var monthly = pmt(loan, r, n, balloon);
        set('[data-fin-deposit-out]', d + '% · ' + rand(deposit));
        set('[data-fin-balloon-out]', b ? b + '% · ' + rand(balloon) : 'None');
        set('[data-fin-result]', rand(monthly));
        set('[data-fin-loan]', rand(loan));
        set('[data-fin-total]', rand(monthly * n + deposit + balloon));
        document.querySelectorAll('[data-fin-mirror]').forEach(function (el) { el.textContent = rand(monthly); });
      }

      w.addEventListener('input', compute);
      w.addEventListener('change', compute);
      compute();
    });
  }


  /* ═══════════════════════════════════════════
     9. TABS (ARIA tablist)
     ═══════════════════════════════════════════ */
  function initTabs() {
    document.querySelectorAll('[data-tabs]').forEach(function (group) {
      var tabs = Array.prototype.slice.call(group.querySelectorAll('[role="tab"]'));
      function activate(tab, focus) {
        tabs.forEach(function (t) {
          var on = t === tab;
          t.setAttribute('aria-selected', on ? 'true' : 'false');
          t.tabIndex = on ? 0 : -1;
          var panel = document.getElementById(t.getAttribute('aria-controls'));
          if (panel) panel.hidden = !on;
        });
        if (focus) tab.focus();
      }
      tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { activate(t); });
        t.addEventListener('keydown', function (e) {
          var n = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
          if (!n) return;
          e.preventDefault();
          activate(tabs[(i + n + tabs.length) % tabs.length], true);
        });
      });
    });
  }


  /* ═══════════════════════════════════════════
     10. RAIL BUTTONS
     ═══════════════════════════════════════════ */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-rail-prev], [data-rail-next]');
    if (!b) return;
    var id = b.getAttribute('data-rail-prev') || b.getAttribute('data-rail-next');
    var rail = document.getElementById(id);
    if (!rail) return;
    var dir = b.hasAttribute('data-rail-prev') ? -1 : 1;
    rail.scrollBy({ left: dir * rail.clientWidth * .85, behavior: 'smooth' });
  });


  /* ═══════════════════════════════════════════
     11. STICKY CTA (mobile)
     Shows the bar while its target (e.g. the enquiry card) is off screen.
     ═══════════════════════════════════════════ */
  function initStickyCta() {
    var bar = document.querySelector('[data-sticky-cta]');
    if (!bar || !('IntersectionObserver' in window)) return;
    var target = document.querySelector(bar.getAttribute('data-sticky-cta-target'));
    var hero   = document.querySelector(bar.getAttribute('data-sticky-cta-after') || 'null');
    var targetVisible = false, heroVisible = !!hero;

    function sync() {
      var show = !targetVisible && !heroVisible;
      bar.classList.toggle('is-visible', show);
      root.classList.toggle('has-sticky-cta', show);
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.target === target) targetVisible = en.isIntersecting;
        if (en.target === hero)   heroVisible   = en.isIntersecting;
      });
      sync();
    }, { threshold: 0.05 });

    if (target) io.observe(target);
    if (hero) io.observe(hero);
  }


  /* ═══════════════════════════════════════════
     12. SECTION NAV + SCROLLSPY  (car detail)
     FIX (v3.1): the previous version called
     link.scrollIntoView({block:'nearest'}) every time a section became
     current. .cd-subnav is a sticky horizontal scroller, so that call
     also scrolled the DOCUMENT — and because html has
     scroll-behavior:smooth, each observer callback started a new
     animation that fought the user's own scroll. Result: clicking a
     section scrolled past it and snapped back, and plain wheel/touch
     scrolling stalled around the nav.
     Now: the strip is scrolled horizontally by hand (scrollLeft only,
     never scrollIntoView), clicks are handled here with an offset that
     clears both sticky bars, and the observer is muted while a
     programmatic scroll is running.
     ═══════════════════════════════════════════ */
  /* Scroll a horizontal strip so `el` is visible — without ever
     touching the page's own scroll position. */
  function keepInViewX(container, el) {
    if (!container || !el) return;
    if (container.scrollWidth <= container.clientWidth + 1) return;
    var cRect = container.getBoundingClientRect();
    var eRect = el.getBoundingClientRect();
    var pad = 16;
    var delta = 0;
    if (eRect.left < cRect.left + pad)        delta = eRect.left - cRect.left - pad;
    else if (eRect.right > cRect.right - pad) delta = eRect.right - cRect.right + pad;
    if (!delta) return;
    if (container.scrollBy) container.scrollBy({ left: delta, behavior: REDUCED.matches ? 'auto' : 'smooth' });
    else container.scrollLeft += delta;
  }

  function stickyOffset() {
    var nav = document.querySelector('.pub-nav');
    var sub = document.querySelector('[data-scrollspy]');
    var navH = nav ? nav.getBoundingClientRect().height : 0;
    var subH = 0;
    if (sub && getComputedStyle(sub).position === 'sticky') subH = sub.getBoundingClientRect().height;
    return navH + subH + 10;
  }

  function initScrollspy() {
    var spy = document.querySelector('[data-scrollspy]');
    if (!spy) return;

    var links = Array.prototype.slice.call(spy.querySelectorAll('a[href^="#"]'));
    if (!links.length) return;

    var map = {};
    links.forEach(function (a) {
      var s = document.getElementById(a.getAttribute('href').slice(1));
      if (s) map[s.id] = a;
    });

    var muted = false, muteTimer = null;

    function setCurrent(id, scrollStrip) {
      links.forEach(function (a) { a.removeAttribute('aria-current'); });
      var a = map[id];
      if (!a) return;
      a.setAttribute('aria-current', 'true');
      if (scrollStrip) keepInViewX(spy, a);
    }

    /* Click: one controlled scroll, observer muted until it settles. */
    spy.addEventListener('click', function (e) {
      var a = e.target.closest('a[href^="#"]');
      if (!a) return;
      var id = a.getAttribute('href').slice(1);
      var section = document.getElementById(id);
      if (!section) return;

      e.preventDefault();
      muted = true;
      clearTimeout(muteTimer);
      setCurrent(id, true);

      var top = Math.max(0, window.scrollY + section.getBoundingClientRect().top - stickyOffset());
      window.scrollTo({ top: top, behavior: REDUCED.matches ? 'auto' : 'smooth' });

      if (history.replaceState) history.replaceState(null, '', '#' + id);

      // Un-mute once the scroll has settled (scrollend where supported).
      var done = function () { muted = false; window.removeEventListener('scrollend', done); };
      if ('onscrollend' in window) {
        window.addEventListener('scrollend', done, { once: true });
        muteTimer = setTimeout(done, 1200);          // safety net
      } else {
        muteTimer = setTimeout(done, 700);
      }
    });

    if (!('IntersectionObserver' in window)) return;

    var io = new IntersectionObserver(function (entries) {
      if (muted) return;
      // Pick the entry closest to the top of the reading area.
      var best = null;
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        if (!best || en.boundingClientRect.top < best.boundingClientRect.top) best = en;
      });
      if (best) setCurrent(best.target.id, true);
    }, { rootMargin: '-35% 0px -55% 0px', threshold: 0 });

    Object.keys(map).forEach(function (id) { io.observe(document.getElementById(id)); });

    // Deep link (/…/#finance): land below the sticky bars.
    if (location.hash && map[location.hash.slice(1)]) {
      var id = location.hash.slice(1);
      setTimeout(function () {
        var section = document.getElementById(id);
        if (!section) return;
        window.scrollTo({ top: Math.max(0, window.scrollY + section.getBoundingClientRect().top - stickyOffset()), behavior: 'auto' });
        setCurrent(id, true);
      }, 60);
    }
  }


  /* ═══════════════════════════════════════════
     13b. GET FORMS — clean submit + auto-apply
     Shared by /cars-for-sale/, the broker storefront and /desks/.
     A form marked [data-autosubmit-suspended] (browse.js sets this
     while the mobile filter sheet is open) is left alone so the sheet
     can show a live count and apply on tap instead.
     ═══════════════════════════════════════════ */
  window.sdSubmitClean = function (form) {
    if (!form) return;
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.type === 'submit' || el.type === 'button') return;
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      if (String(el.value).trim() === '') el.disabled = true;
    });
    form.submit();
    setTimeout(function () {
      Array.prototype.forEach.call(form.elements, function (el) { el.disabled = false; });
    }, 1000);
  };

  function initGetForms() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if ((form.method || 'get').toLowerCase() !== 'get') return;
      if (!form.matches('[data-clean-submit], [data-browse-filters], .sort-form')) return;
      e.preventDefault();
      window.sdSubmitClean(form);
    });

    document.addEventListener('change', function (e) {
      var el = e.target;
      if (!el || !el.hasAttribute || !el.hasAttribute('data-autosubmit') || !el.form) return;

      // Keep chip / tile state in sync for browsers without :has()
      var chip = el.closest('.filter-chip, .body-tile, .pub-chip');
      if (chip && (el.type === 'checkbox' || el.type === 'radio')) chip.classList.toggle('active', el.checked);

      if (el.form.hasAttribute('data-autosubmit-suspended')) return;   // browse.js owns it
      window.sdSubmitClean(el.form);
    });
  }


  /* ═══════════════════════════════════════════
     13. SCROLL REVEAL
     ═══════════════════════════════════════════ */
  function initScrollReveal() {
    if (!('IntersectionObserver' in window)) return;
    var els = document.querySelectorAll('.pub-reveal');
    if (!els.length) return;
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('pub-revealed');
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    root.classList.add('pub-reveal-ready');
    els.forEach(function (el) { obs.observe(el); });
  }


  /* ═══════════════════════════════════════════
     GLOBAL KEYS
     ═══════════════════════════════════════════ */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && shareOverlay && shareOverlay.classList.contains('open')) window.closeShareSheet();
    if (e.key === '/' && !isTypingTarget(e.target)) {
      var s = document.getElementById('navSearch') || document.getElementById('hq');
      if (s && s.offsetParent !== null) { e.preventDefault(); s.focus(); }
    }
  });


  /* BOOT (deferred — DOM is parsed) */
  initLightbox();
  initCarousels();
  initEnquiryForm();
  initClamp();
  initFinance();
  initTabs();
  initStickyCta();
  initScrollspy();
  initGetForms();
  initScrollReveal();

})();
