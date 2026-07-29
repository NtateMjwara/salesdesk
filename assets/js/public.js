/**
 * SalesDesk — Public Pages JavaScript
 * T1 owns this file.
 *
 * Modules (all IIFE-scoped, no globals except the init functions):
 *   1. Gallery          — thumbnail switching, keyboard nav
 *   2. Nav dropdowns    — Browse menu + Account menu
 *   3. Share sheet      — open/close, copy URL, platform links
 *   4. Wishlist toggle  — API call to api/visitor/wishlist-toggle.php
 *   5. Enquiry form     — async submit to api/leads/submit.php, validation
 *   6. Description clamp — read-more toggle
 *   7. Finance slider   — live monthly estimate update
 *   8. Scroll reveal    — lightweight IntersectionObserver animations
 *
 * Usage: loaded at bottom of layout-public.php.
 * No external dependencies required (Font Awesome loaded separately).
 */

(function () {
  'use strict';

  /* ═══════════════════════════════════════════
     1. GALLERY
     ═══════════════════════════════════════════ */
  function initGallery() {
    var mainImg   = document.getElementById('galleryMain');
    var mainWrap  = document.getElementById('galleryMainWrap');
    var countEl   = document.getElementById('galleryCount');
    var thumbs    = Array.from(document.querySelectorAll('.pub-gallery__thumb'));
    if (!mainImg || !thumbs.length) return;

    var currentIdx = 0;
    var images = thumbs.map(function(t) {
      return t.getAttribute('data-src') || t.querySelector('img').src;
    });

    function goTo(idx) {
      if (idx < 0) idx = images.length - 1;
      if (idx >= images.length) idx = 0;
      currentIdx = idx;

      mainImg.style.opacity = '0';
      mainImg.style.transform = 'scale(1.02)';

      setTimeout(function() {
        mainImg.src = images[idx];
        mainImg.style.opacity = '1';
        mainImg.style.transform = 'scale(1)';
      }, 120);

      thumbs.forEach(function(t, i) {
        t.classList.toggle('active', i === idx);
        if (i === idx) {
          t.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        }
      });

      if (countEl) {
        countEl.textContent = (idx + 1) + ' / ' + images.length;
      }
    }

    // Thumb clicks.
    thumbs.forEach(function(thumb, i) {
      thumb.addEventListener('click', function() { goTo(i); });
    });

    // Main image click — open full screen (native browser trick).
    if (mainWrap) {
      mainWrap.addEventListener('click', function() {
        // Open current image in new tab for full-screen view.
        window.open(images[currentIdx], '_blank', 'noopener');
      });
    }

    // Touch swipe on main image.
    var touchStartX = 0;
    mainWrap && mainWrap.addEventListener('touchstart', function(e) {
      touchStartX = e.touches[0].clientX;
    }, { passive: true });
    mainWrap && mainWrap.addEventListener('touchend', function(e) {
      var diff = touchStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 40) goTo(diff > 0 ? currentIdx + 1 : currentIdx - 1);
    }, { passive: true });

    // Keyboard navigation.
    document.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowLeft')  goTo(currentIdx - 1);
      if (e.key === 'ArrowRight') goTo(currentIdx + 1);
    });

    // Smooth opacity transition on img.
    mainImg.style.transition = 'opacity .15s ease, transform .15s ease';

    // Init count.
    if (countEl && images.length > 1) {
      countEl.textContent = '1 / ' + images.length;
    } else if (countEl) {
      countEl.style.display = 'none';
    }
  }


  /* ═══════════════════════════════════════════
     2. NAV DROPDOWNS
     ═══════════════════════════════════════════ */
  function initNavDropdowns() {
    // Generic dropdown: button toggles panel, click-outside closes.
    var dropdowns = [
      { btn: 'browseBtn',   panel: 'browsePanel'   },
      { btn: 'accountBtn',  panel: 'accountPanel'  },
    ];

    dropdowns.forEach(function(d) {
      var btn   = document.getElementById(d.btn);
      var panel = document.getElementById(d.panel);
      if (!btn || !panel) return;

      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var isOpen = panel.classList.contains('open');

        // Close all other dropdowns first.
        document.querySelectorAll('.pub-nav__dropdown, .pub-nav__browse-panel').forEach(function(p) {
          p.classList.remove('open');
        });
        document.querySelectorAll('.pub-nav__browse-btn, .pub-nav__account-btn').forEach(function(b) {
          b.classList.remove('open');
        });

        if (!isOpen) {
          panel.classList.add('open');
          btn.classList.add('open');
        }
      });
    });

    // Close on outside click.
    document.addEventListener('click', function() {
      document.querySelectorAll('.pub-nav__dropdown, .pub-nav__browse-panel').forEach(function(p) {
        p.classList.remove('open');
      });
      document.querySelectorAll('.pub-nav__browse-btn, .pub-nav__account-btn').forEach(function(b) {
        b.classList.remove('open');
      });
    });

    // Close on Escape.
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.pub-nav__dropdown, .pub-nav__browse-panel').forEach(function(p) {
          p.classList.remove('open');
        });
        document.querySelectorAll('.pub-nav__browse-btn, .pub-nav__account-btn').forEach(function(b) {
          b.classList.remove('open');
        });
      }
    });
  }


  /* ═══════════════════════════════════════════
     3. SHARE SHEET
     ═══════════════════════════════════════════ */
  window.openShareSheet = function() {
    var overlay = document.getElementById('shareOverlay');
    if (overlay) overlay.classList.add('open');
  };

  window.closeShareSheet = function() {
    var overlay = document.getElementById('shareOverlay');
    if (overlay) overlay.classList.remove('open');
  };

  window.copyShareUrl = function() {
    var input = document.getElementById('shareUrlInput');
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(function() {
      var btn = document.getElementById('copyUrlBtn');
      if (btn) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        btn.style.color = 'var(--green)';
        setTimeout(function() {
          btn.innerHTML = orig;
          btn.style.color = '';
        }, 1800);
      }
    }).catch(function() {
      // Fallback for older browsers.
      input.select();
      document.execCommand('copy');
    });
  };

  function initShareSheet() {
    var overlay = document.getElementById('shareOverlay');
    if (!overlay) return;

    // Close on backdrop click.
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) window.closeShareSheet();
    });

    // Close on Escape.
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') window.closeShareSheet();
    });
  }


  /* ═══════════════════════════════════════════
     4. WISHLIST TOGGLE
     ═══════════════════════════════════════════ */
  window.toggleWishlist = function(btn, carId) {
    if (!btn || !carId) return;

    btn.disabled = true;
    btn.style.opacity = '.5';

    fetch('/api/visitor/wishlist-toggle.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: 'car_id=' + encodeURIComponent(carId),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      btn.style.opacity = '1';

      if (data.wishlisted) {
        btn.classList.add('wishlisted');
        btn.title = 'Remove from saved';
        var icon = btn.querySelector('i');
        if (icon) { icon.className = 'fa-solid fa-heart'; }
      } else {
        btn.classList.remove('wishlisted');
        btn.title = 'Save car';
        var icon2 = btn.querySelector('i');
        if (icon2) { icon2.className = 'fa-regular fa-heart'; }
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.style.opacity = '1';
    });
  };


  /* ═══════════════════════════════════════════
     5. ENQUIRY FORM
     ═══════════════════════════════════════════ */
  function initEnquiryForm() {
    var form = document.getElementById('enquiryForm');
    if (!form) return;

    var submitBtn = form.querySelector('#enquirySubmit');
    var successEl = document.getElementById('enquirySuccess');

    form.addEventListener('submit', function(e) {
      e.preventDefault();

      // Clear previous errors.
      form.querySelectorAll('.pub-form-error').forEach(function(el) {
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

      // Disable button + show loading.
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sending…';
      }

      var data = new FormData(form);

      fetch('/api/leads/submit.php', {
        method: 'POST',
        body: data,
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          // Show success state.
          form.style.display = 'none';
          if (successEl) successEl.style.display = 'block';
        } else if (res.duplicate) {
          resetSubmitBtn(submitBtn);
          showGlobalError(form, 'Your enquiry for this car is already with the dealer. They will contact you shortly.');
        } else if (res.stale) {
          resetSubmitBtn(submitBtn);
          showGlobalError(form, 'This listing is no longer available (' + (res.car_status || 'sold') + ').');
        } else if (res.not_found) {
          resetSubmitBtn(submitBtn);
          showGlobalError(form, 'This tracking link has expired. Please find the car directly.');
        } else if (res.error) {
          resetSubmitBtn(submitBtn);
          showGlobalError(form, res.error);
        } else {
          resetSubmitBtn(submitBtn);
          showGlobalError(form, 'Something went wrong. Please try again.');
        }
      })
      .catch(function() {
        resetSubmitBtn(submitBtn);
        showGlobalError(form, 'Connection error. Please check your internet and try again.');
      });
    });

    function showFieldError(input, msg) {
      if (!input) return;
      var err = input.parentElement.querySelector('.pub-form-error');
      if (err) err.textContent = msg;
      input.style.borderColor = 'var(--red)';
      input.addEventListener('input', function() {
        input.style.borderColor = '';
        if (err) err.textContent = '';
      }, { once: true });
    }

    function showGlobalError(form, msg) {
      var global = document.getElementById('enquiryGlobalError');
      if (global) {
        global.textContent = msg;
        global.style.display = 'block';
      }
    }

    function resetSubmitBtn(btn) {
      if (!btn) return;
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Enquiry';
    }
  }


  /* ═══════════════════════════════════════════
     6. DESCRIPTION CLAMP
     ═══════════════════════════════════════════ */
  function initDescClamp() {
    var toggle = document.getElementById('descToggle');
    var text   = document.getElementById('descText');
    if (!toggle || !text) return;

    var expanded = false;
    toggle.addEventListener('click', function() {
      expanded = !expanded;
      text.classList.toggle('clamped', !expanded);
      toggle.innerHTML = expanded
        ? '<i class="fa-solid fa-chevron-up"></i> Show less'
        : '<i class="fa-solid fa-chevron-down"></i> Read more';
    });
  }


  /* ═══════════════════════════════════════════
     7. FINANCE SLIDER
     ═══════════════════════════════════════════ */
  function initFinanceSlider() {
    var slider  = document.getElementById('depositSlider');
    var dispDep = document.getElementById('depositDisplay');
    var dispPM  = document.getElementById('monthlyDisplay');
    if (!slider || !dispPM) return;

    var price    = parseFloat(slider.getAttribute('data-price') || '0');
    var rate     = parseFloat(slider.getAttribute('data-rate')  || '13.25');
    var term     = parseInt(slider.getAttribute('data-term')    || '60', 10);

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
    compute(); // init
  }


  /* ═══════════════════════════════════════════
     8. SCROLL REVEAL
     ═══════════════════════════════════════════ */
  function initScrollReveal() {
    if (!window.IntersectionObserver) return;
    var els = document.querySelectorAll('.pub-reveal');
    if (!els.length) return;

    var obs = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('pub-revealed');
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1 });

    els.forEach(function(el) {
      el.style.opacity = '0';
      el.style.transform = 'translateY(16px)';
      el.style.transition = 'opacity .45s ease, transform .45s ease';
      obs.observe(el);
    });

    // Inject revealed class CSS.
    var style = document.createElement('style');
    style.textContent = '.pub-revealed { opacity: 1 !important; transform: none !important; }';
    document.head.appendChild(style);
  }


  /* ═══════════════════════════════════════════
     BOOT
     ═══════════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function() {
    initGallery();
    initNavDropdowns();
    initShareSheet();
    initEnquiryForm();
    initDescClamp();
    initFinanceSlider();
    initScrollReveal();
  });

})();
