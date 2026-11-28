/**
 * SalesDesk — Broker Marketplace & My Desk  (DESK-1, Nov 2026)
 * assets/js/broker-inventory.js
 *
 * Loaded on /app/broker/inventory via $pageScripts.
 *
 *   1. Share sheet helpers — toggleShare(id) / copyShare(id), called from
 *      onclick attributes in the page (moved here from an inline <script>).
 *   2. Add to Desk in place — forms with [data-add-to-desk] post with
 *      X-Requested-With and get JSON back (app/broker/inventory.php), so
 *      the broker stays in the Marketplace: the card flips to "On your
 *      desk" with Undo, the slot counter updates, and when the desk fills
 *      every other Add button becomes "Desk full". One request per click:
 *      a busy button ignores further clicks. If anything goes wrong with
 *      the request itself, the form falls back to a normal submit.
 *   3. PLANS-3b: confirm before a paused car is removed for good.
 */

/* ═══════════════════════════════════════════
   1. SHARE SHEET (moved from inline <script>)
   ═══════════════════════════════════════════ */
function toggleShare(id) {
  var el = document.getElementById('share-' + id);
  if (!el) return;
  var showing = el.style.display !== 'none';
  // Close all open share sheets first.
  document.querySelectorAll('.share-sheet').forEach(function(s) {
    s.style.display = 'none';
  });
  el.style.display = showing ? 'none' : 'block';
}

function copyShare(id) {
  var input = document.getElementById('shareUrl-' + id);
  if (!input) return;
  navigator.clipboard.writeText(input.value).then(function () {
    // Flash all copy buttons for this card.
    document.querySelectorAll('[onclick="copyShare(' + id + ')"] i').forEach(function(icon) {
      var orig = icon.className;
      icon.className = 'fa-solid fa-check';
      setTimeout(function () { icon.className = orig; }, 1600);
    });
  }).catch(function () {
    input.select();
    document.execCommand('copy');
  });
}


/* ═══════════════════════════════════════════
   2. ADD TO DESK IN PLACE
   ═══════════════════════════════════════════ */
(function () {
  'use strict';

  var ENDPOINT = '/app/broker/inventory.php';
  var toastBox = null;

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function toast(msg, opts) {
    opts = opts || {};
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'inv-toasts';
      toastBox.setAttribute('role', 'status');
      toastBox.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastBox);
    }
    var el = document.createElement('div');
    el.className = 'inv-toast' + (opts.error ? ' is-error' : '');
    el.innerHTML = '<i class="fa-solid ' + (opts.error ? 'fa-circle-exclamation' : 'fa-circle-check') + '" aria-hidden="true"></i>'
      + '<span>' + esc(msg) + '</span>'
      + (opts.link ? '<a href="' + esc(opts.link.href) + '">' + esc(opts.link.text) + '</a>' : '');
    toastBox.appendChild(el);
    setTimeout(function () {
      el.classList.add('is-leaving');
      setTimeout(function () { el.remove(); }, 200);
    }, opts.duration || 3200);
  }

  function setBusy(btn, on, label) {
    if (on) {
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
      btn.setAttribute('data-html', btn.innerHTML);
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> ' + esc(label || 'Adding…');
    } else {
      btn.removeAttribute('aria-busy');
      btn.disabled = false;
      if (btn.hasAttribute('data-html')) {
        btn.innerHTML = btn.getAttribute('data-html');
        btn.removeAttribute('data-html');
      }
    }
  }

  function updateSlots(data) {
    var box = document.querySelector('[data-slot-count]');
    if (!box) return;
    var used = box.querySelector('[data-slot-used]');
    var lim  = box.querySelector('[data-slot-limit]');
    if (used) used.textContent = data.desk_count;
    if (lim && data.limit_text) lim.textContent = data.limit_text;
    box.classList.toggle('at-limit', !!data.at_limit);
    box.classList.remove('is-bump'); void box.offsetWidth; box.classList.add('is-bump');
  }

  /* When the desk is full, every remaining Add form becomes "Desk full"
     (and back again after an Undo frees a slot). */
  function setDeskFull(full, limitText) {
    document.querySelectorAll('form[data-add-to-desk]').forEach(function (form) {
      var btn = form.querySelector('button[type="submit"]');
      if (!btn || btn.hasAttribute('aria-busy')) return;
      if (full) {
        if (!btn.hasAttribute('data-full')) {
          btn.setAttribute('data-full', btn.innerHTML);
          btn.innerHTML = '<i class="fa-solid fa-lock" aria-hidden="true"></i> Desk full';
          btn.classList.replace('btn-primary', 'btn-ghost');
          btn.title = 'Desk full (' + (limitText || '') + ' cars max)';
        }
        btn.disabled = true;
      } else if (btn.hasAttribute('data-full')) {
        btn.innerHTML = btn.getAttribute('data-full');
        btn.removeAttribute('data-full');
        btn.classList.replace('btn-ghost', 'btn-primary');
        btn.removeAttribute('title');
        btn.disabled = false;
      }
    });
  }

  function post(form, action) {
    var fd = new FormData(form);
    fd.set('action', action);
    return fetch(ENDPOINT, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) {
      var type = r.headers.get('Content-Type') || '';
      if (!r.ok || type.indexOf('application/json') === -1) throw new Error('bad response ' + r.status);
      return r.json();
    });
  }

  /* Card flips to "On your desk" + Undo; the original form is kept hidden
     so Undo can bring it back. */
  function markAdded(form) {
    var card = form.closest('.car-card');
    if (card) card.classList.add('is-added');
    form.hidden = true;
    var row = document.createElement('div');
    row.className = 'inv-added-row';
    row.innerHTML = '<button class="btn btn-ghost btn-sm inv-added" type="button" disabled>'
      + '<i class="fa-solid fa-check" aria-hidden="true"></i> On your desk</button>'
      + '<button class="btn btn-ghost btn-sm inv-undo" type="button" data-undo>Undo</button>';
    form.insertAdjacentElement('afterend', row);

    row.querySelector('[data-undo]').addEventListener('click', function () {
      var undo = this;
      if (undo.hasAttribute('aria-busy')) return;
      setBusy(undo, true, 'Undoing…');
      post(form, 'remove_from_desk').then(function (data) {
        if (!data.ok) { setBusy(undo, false); toast(data.message, { error: true }); return; }
        row.remove();
        form.hidden = false;
        if (card) card.classList.remove('is-added');
        updateSlots(data);
        setDeskFull(!!data.at_limit, data.limit_text);
        toast('Removed from your desk.');
      }).catch(function () {
        setBusy(undo, false);
        toast('Couldn’t undo that. Please try again.', { error: true });
      });
    });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-add-to-desk')) return;
    if (!window.fetch || !window.FormData) return;          // very old browser: normal submit
    e.preventDefault();

    var btn = form.querySelector('button[type="submit"]');
    if (!btn || btn.hasAttribute('aria-busy') || btn.disabled) return;   // one request per click
    setBusy(btn, true);

    post(form, 'add_to_desk').then(function (data) {
      setBusy(btn, false);
      if (!data.ok) {
        toast(data.message, { error: true, duration: 5200 });
        if (data.at_limit) { updateSlots(data); setDeskFull(true, data.limit_text); }
        return;
      }
      markAdded(form);
      updateSlots(data);
      setDeskFull(!!data.at_limit, data.limit_text);
      toast(data.message, { link: { href: '?tab=desk', text: 'View desk' } });
    }).catch(function () {
      // Network or server hiccup: let the browser do a normal POST
      // (the server sends the broker back to this Marketplace page).
      setBusy(btn, false);
      form.submit();
    });
  });
})();

/* ═══════════════════════════════════════════
   3. PAUSED CARS (PLANS-3b) — confirm before removing a paused car
   for good: form[data-confirm="…"].
   ═══════════════════════════════════════════ */
(function () {
  'use strict';
  document.addEventListener('submit', function (e) {
    var form = e.target.closest ? e.target.closest('form[data-confirm]') : null;
    if (form && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  }, true);
})();
