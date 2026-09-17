/**
 * SalesDesk — Public Pages JavaScript  (v2)
 * T1 owns this file.
 *
 * Modules (IIFE-scoped; the only globals are the legacy window.*
 * share / wishlist functions kept for markup not yet migrated):
 *   1. Gallery           — thumbnail switching, swipe, keyboard nav
 *   2. Share sheet       — open/close, copy URL
 *   3. Wishlist toggle   — API call to api/visitor/wishlist-toggle.php
 *   4. Enquiry form      — async submit to api/leads/submit.php, validation
 *   5. Description clamp — read-more toggle
 *   6. Finance slider    — live monthly estimate update
 *   7. Scroll reveal     — lightweight IntersectionObserver animations
 *
 * Loaded with `defer` from layout-public.php, after global.js.
 * Nav behaviour is NOT here — it lives in public-nav.js.
 *
 * v2 (UI consolidation, Phase 1):
 *   – Removed "Nav dropdowns" (initNavDropdowns). It targeted
 *     #browseBtn / #accountBtn, which no longer exist, and bound a
 *     second document-level click listener on every public page.
 *   – Share sheet: buttons use data-share-open / data-share-copy /
 *     data-share-close instead of onclick="". window.openShareSheet /
 *     closeShareSheet / copyShareUrl remain for pages still using
 *     onclick (car detail — Phase 4).
 *   – Inline style writes replaced with CSS state classes defined in
 *     public.css: .is-swapping (gallery), .is-invalid (form field),
 *     .is-copied (share), .pub-reveal-ready / .pub-revealed (reveal).
 *     The reveal module no longer injects a <style> tag.
 *   – Gallery arrow keys ignore typing in inputs/textareas/selects.
 *   – Boot no longer waits for DOMContentLoaded: with `defer` the DOM
 *     is already parsed when this runs.
 */

(function () {
  'use strict';

  function isTypingTarget(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  }


  /* ═══════════════════════════════════════════
     1. GALLERY
     ═══════════════════════════════════════════ */
  function initGallery() {
    var mainImg  = document.getElementById('galleryMain');
    var mainWrap = document.getElementById('galleryMainWrap');
    var countEl  = document.getElementById('galleryCount');
    var thumbs   = Array.from(document.querySelectorAll('.pub-gallery__thumb'));
    if (!mainImg || !thumbs.length) return;

    var currentIdx = 0;
    var images = thumbs.map(function (t) {
      return t.getAttribute('data-src') || t.querySelector('img').src;
    });

    function goTo(idx) {
      if (idx < 0) idx = images.length - 1;
      if (idx >= images.length) idx = 0;
      currentIdx = idx;

      mainImg.classList.add('is-swapping');
      setTimeout(function () {
        mainImg.src = images[idx];
        mainImg.classList.remove('is-swapping');
      }, 120);

      thumbs.forEach(function (t, i) {
        t.classList.toggle('active', i === idx);
        if (i === idx) {
          t.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        }
      });

      if (countEl) countEl.textContent = (idx + 1) + ' / ' + images.length;
    }

    thumbs.forEach(function (thumb, i) {
      thumb.addEventListener('click', function () { goTo(i); });
    });

    if (mainWrap) {
      // Open the current image in a new tab for a full-screen view.
      mainWrap.addEventListener('click', function () {
        window.open(images[currentIdx], '_blank', 'noopener');
      });

      var touchStartX = 0;
      mainWrap.addEventListener('touchstart', function (e) {
        touchStartX = e.touches[0].clientX;
      }, { passive: true });
      mainWrap.addEventListener('touchend', function (e) {
        var diff = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) goTo(diff > 0 ? currentIdx + 1 : currentIdx - 1);
      }, { passive: true });
    }

    document.addEventListener('keydown', function (e) {
      if (isTypingTarget(e.target)) return;
      if (e.key === 'ArrowLeft')  goTo(currentIdx - 1);
      if (e.key === 'ArrowRight') goTo(currentIdx + 1);
    });

    if (countEl) {
      if (images.length > 1) {
        countEl.textContent = '1 / ' + images.length;
      } else {
        countEl.hidden = true;
      }
    }
  }


  /* ═══════════════════════════════════════════
     2. SHARE SHEET
     ═══════════════════════════════════════════ */
  window.openShareSheet = function () {
    var overlay = document.getElementById('shareOverlay');
    if (overlay) overlay.classList.add('open');
  };

  window.closeShareSheet = function () {
    var overlay = document.getElementById('shareOverlay');
    if (overlay) overlay.classList.remove('open');
  };

  window.copyShareUrl = function (triggerEl) {
    var input = document.getElementById('shareUrlInput');
    if (!input) return;

    function flash() {
      var target = triggerEl || document.querySelector('[data-share-copy]');
      if (!target) return;
      var icon     = target.querySelector('i');
      var origIcon = icon ? icon.className : '';

      target.classList.add('is-copied');
      if (icon) icon.className = 'fa-solid fa-check';

      setTimeout(function () {
        target.classList.remove('is-copied');
        if (icon) icon.className = origIcon;
      }, 1800);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(flash).catch(function () {
        input.select();
        document.execCommand('copy');
        flash();
      });
    } else {
      input.select();
      document.execCommand('copy');
      flash();
    }
  };

  function initShareSheet() {
    document.addEventListener('click', function (e) {
      var el = e.target.closest('[data-share-open], [data-share-copy], [data-share-close]');
      if (!el) return;

      if (el.hasAttribute('data-share-open'))  { e.preventDefault(); window.openShareSheet(); }
      if (el.hasAttribute('data-share-copy'))  { e.preventDefault(); window.copyShareUrl(el); }
      if (el.hasAttribute('data-share-close')) { e.preventDefault(); window.closeShareSheet(); }
    });

    var overlay = document.getElementById('shareOverlay');
    if (!overlay) return;

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) window.closeShareSheet();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('open')) window.closeShareSheet();
    });
  }


  /* ═══════════════════════════════════════════
     3. WISHLIST TOGGLE
     ═══════════════════════════════════════════ */
  window.toggleWishlist = function (btn, carId) {
    if (!btn || !carId) return;

    btn.disabled = true;   // .pub-nav-icon-btn:disabled dims it (public.css §1)

    fetch('/api/visitor/wishlist-toggle.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: 'car_id=' + encodeURIComponent(carId)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        var icon = btn.querySelector('i');

        if (data.wishlisted) {
          btn.classList.add('wishlisted');
          btn.title = 'Remove from saved';
          if (icon) icon.className = 'fa-solid fa-heart';
        } else {
          btn.classList.remove('wishlisted');
          btn.title = 'Save car';
          if (icon) icon.className = 'fa-regular fa-heart';
        }
      })
      .catch(function () {
        btn.disabled = false;
      });
  };


  /* ═══════════════════════════════════════════
     4. ENQUIRY FORM
     ═══════════════════════════════════════════ */
  function initEnquiryForm() {
    var form = document.getElementById('enquiryForm');
    if (!form) return;

    var submitBtn = form.querySelector('#enquirySubmit');
    var successEl = document.getElementById('enquirySuccess');

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      form.querySelectorAll('.pub-form-error').forEach(function (el) {
        el.textContent = '';
      });

      var name    = form.querySelector('[name="buyer_name"]');
      var phone   = form.querySelector('[name="buyer_phone"]');
      var consent = form.querySelector('[name="consent_given"]');
      var valid   = true;

      if (!name || !name.value.trim()) {
        showFieldError(name, 'Please enter your name.');
        valid = false;
      }
      if (!phone || !phone.value.trim()) {
        showFieldError(phone, 'Please enter your phone number.');
        valid = false;
      }
      if (!consent || !consent.checked) {
        var consentErr = document.getElementById('consentError');
        if (consentErr) consentErr.textContent = 'Please accept to continue.';
        valid = false;
      }

      if (!valid) return;

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sending…';
      }

      fetch('/api/leads/submit.php', {
        method: 'POST',
        body: new FormData(form)
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            // NOTE (Phase 4): these two still toggle display inline because
            // the car-detail markup ships them with style="display:none".
            form.style.display = 'none';
            if (successEl) successEl.style.display = 'block';
            return;
          }

          resetSubmitBtn(submitBtn);

          if (res.duplicate) {
            showGlobalError('Your enquiry for this car is already with the dealer. They will contact you shortly.');
          } else if (res.stale) {
            showGlobalError('This listing is no longer available (' + (res.car_status || 'sold') + ').');
          } else if (res.not_found) {
            showGlobalError('This tracking link has expired. Please find the car directly.');
          } else if (res.error) {
            showGlobalError(res.error);
          } else {
            showGlobalError('Something went wrong. Please try again.');
          }
        })
        .catch(function () {
          resetSubmitBtn(submitBtn);
          showGlobalError('Connection error. Please check your internet and try again.');
        });
    });

    function showFieldError(input, msg) {
      if (!input) return;
      var err = input.parentElement.querySelector('.pub-form-error');
      if (err) err.textContent = msg;
      input.classList.add('is-invalid');
      input.addEventListener('input', function () {
        input.classList.remove('is-invalid');
        if (err) err.textContent = '';
      }, { once: true });
    }

    function showGlobalError(msg) {
      var global = document.getElementById('enquiryGlobalError');
      if (!global) return;
      global.textContent = msg;
      global.hidden = false;
      global.style.display = 'block';   // Phase 4: drop once markup uses [hidden]
    }

    function resetSubmitBtn(btn) {
      if (!btn) return;
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Enquiry';
    }
  }


  /* ═══════════════════════════════════════════
     5. DESCRIPTION CLAMP
     ═══════════════════════════════════════════ */
  function initDescClamp() {
    var toggle = document.getElementById('descToggle');
    var text   = document.getElementById('descText');
    if (!toggle || !text) return;

    var expanded = false;
    toggle.setAttribute('aria-expanded', 'false');

    toggle.addEventListener('click', function () {
      expanded = !expanded;
      text.classList.toggle('clamped', !expanded);
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.innerHTML = expanded
        ? '<i class="fa-solid fa-chevron-up"></i> Show less'
        : '<i class="fa-solid fa-chevron-down"></i> Read more';
    });
  }


  /* ═══════════════════════════════════════════
     6. FINANCE SLIDER
     ═══════════════════════════════════════════ */
  function initFinanceSlider() {
    var slider  = document.getElementById('depositSlider');
    var dispDep = document.getElementById('depositDisplay');
    var dispPM  = document.getElementById('monthlyDisplay');
    if (!slider || !dispPM) return;

    var price = parseFloat(slider.getAttribute('data-price') || '0');
    var rate  = parseFloat(slider.getAttribute('data-rate')  || '13.25');
    var term  = parseInt(slider.getAttribute('data-term')    || '60', 10);

    function compute() {
      var depositPct  = parseFloat(slider.value);
      var loanAmount  = price * (1 - depositPct / 100);
      var monthlyRate = (rate / 100) / 12;
      var payment;

      if (monthlyRate <= 0) {
        payment = loanAmount / term;
      } else {
        payment = loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, term))
                             / (Math.pow(1 + monthlyRate, term) - 1);
      }

      if (dispDep) dispDep.textContent = depositPct + '% deposit';
      dispPM.textContent = '~R\u00a0' + Math.round(payment).toLocaleString('en-ZA') + '\u00a0/\u00a0mo';
    }

    slider.addEventListener('input', compute);
    compute();
  }


  /* ═══════════════════════════════════════════
     7. SCROLL REVEAL
     Hidden/visible states live in public.css §16.
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
    }, { threshold: 0.1 });

    document.documentElement.classList.add('pub-reveal-ready');
    els.forEach(function (el) { obs.observe(el); });
  }


  /* ═══════════════════════════════════════════
     BOOT (script is deferred — DOM is ready)
     ═══════════════════════════════════════════ */
  initGallery();
  initShareSheet();
  initEnquiryForm();
  initDescClamp();
  initFinanceSlider();
  initScrollReveal();

})();
