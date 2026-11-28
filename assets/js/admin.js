/* ============================================================
   SalesDesk — Admin: dealerships, desk orgs, exec approvals (0012)
   Loaded via $pageScripts (deferred) by the app/admin/ pages that
   include assets/css/admin.css.

   Hooks (no inline handlers):
     [data-modal-open="id"]   opens .modal-bg#id
     [data-modal-close]       closes the enclosing .modal-bg
     .modal-bg backdrop click / Escape closes
     form[data-confirm="…"]   confirm() before submit
     [data-reason-modal="reject|suspend"][data-exec-id][data-exec-name]
     [data-reason-kind="exec|agent"] (optional, default exec)
                              fills + opens #execReasonModal
     select[data-autosubmit]  submits its form on change
   ============================================================ */
(function () {
  'use strict';

  function openModal(el) {
    if (!el) return;
    el.classList.add('open');
    var first = el.querySelector('input:not([type=hidden]), textarea, select');
    if (first) setTimeout(function () { first.focus(); }, 30);
  }
  function closeModal(el) { if (el) el.classList.remove('open'); }

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-modal-open]');
    if (opener) {
      e.preventDefault();
      openModal(document.getElementById(opener.getAttribute('data-modal-open')));
      return;
    }

    var closer = e.target.closest('[data-modal-close]');
    if (closer) {
      e.preventDefault();
      closeModal(closer.closest('.modal-bg'));
      return;
    }

    if (e.target.classList && e.target.classList.contains('modal-bg')) {
      closeModal(e.target);
      return;
    }

    var reasonBtn = e.target.closest('[data-reason-modal]');
    if (reasonBtn) {
      e.preventDefault();
      var modal = document.getElementById('execReasonModal');
      if (!modal) return;
      var mode   = reasonBtn.getAttribute('data-reason-modal'); // reject | suspend
      var name   = reasonBtn.getAttribute('data-exec-name') || 'this person';
      var isSusp = mode === 'suspend';

      modal.querySelector('[data-reason-action]').value = mode;
      modal.querySelector('[data-reason-exec]').value   = reasonBtn.getAttribute('data-exec-id');
      var kindInput = modal.querySelector('[data-reason-kind]');
      var kind      = reasonBtn.getAttribute('data-reason-kind') || 'exec';
      if (kindInput) kindInput.value = kind;
      modal.querySelector('[data-reason-title]').textContent  = isSusp
        ? (kind === 'agent' ? 'Suspend network broker' : 'Suspend sales exec')
        : (kind === 'agent' ? 'Decline application' : 'Decline request');
      modal.querySelector('[data-reason-sub]').textContent    = isSusp
        ? (kind === 'agent'
            ? name + ' won’t count as part of this network until reinstated. They can still share any car.'
            : name + '’s live listings will be paused until they are reinstated.')
        : name + ' will be emailed that their request was declined.';
      modal.querySelector('[data-reason-submit]').textContent = isSusp ? 'Suspend' : 'Decline & notify';
      modal.querySelector('textarea').value = '';
      openModal(modal);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal-bg.open').forEach(closeModal);
  });

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  document.addEventListener('change', function (e) {
    if (e.target.matches('select[data-autosubmit]') && e.target.form) {
      e.target.form.submit();
    }
  });

  /* ── Users page (0019) ──────────────────────────────────────
     [data-modal-open="statusModal"][data-mode][data-user-id][data-name][data-dealer]
        fills the suspend / reactivate modal (dealer → cascade warning)
     [data-modal-open="rejectModal"][data-reject-action][data-dealer-id][data-org-id][data-name]
        fills the CIPC reject modal
     [data-check-all] / [data-check-row] / [data-bulk-count] / [data-bulk-submit]
        (bulk selection lives in its own block below)
  ──────────────────────────────────────────────────────────── */
  function setText(root, sel, text) { var el = root.querySelector(sel); if (el) el.textContent = text; }
  function setVal(root, sel, val)   { var el = root.querySelector(sel); if (el) el.value = val; }

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-modal-open]');
    if (!opener) return;
    var id    = opener.getAttribute('data-modal-open');
    var modal = document.getElementById(id);
    if (!modal) return;
    var name  = opener.getAttribute('data-name') || 'this user';

    if (id === 'statusModal') {
      var suspend  = opener.getAttribute('data-mode') === 'suspend_user';
      var isDealer = opener.getAttribute('data-dealer') === '1';
      setVal(modal, '[data-status-action]', suspend ? 'suspend_user' : 'reactivate_user');
      setVal(modal, '[data-status-user]', opener.getAttribute('data-user-id') || '');
      setText(modal, '[data-status-title]', (suspend ? 'Suspend ' : 'Reactivate ') + (isDealer ? 'dealer' : 'user'));
      setText(modal, '[data-status-sub]', 'You are about to ' + (suspend ? 'suspend ' : 'reactivate ') + name + '.');
      setText(modal, '[data-status-dealer-text]', suspend
        ? 'All live listings will be paused and pending commissions frozen. Broker attribution is never affected.'
        : 'Paused listings will be restored and frozen commissions unfrozen.');
      var note = modal.querySelector('[data-status-dealer-note]');
      if (note) note.hidden = !isDealer;
      var btn = modal.querySelector('[data-status-submit]');
      if (btn) {
        btn.textContent = suspend ? 'Suspend' : 'Reactivate';
        btn.className   = 'btn ' + (suspend ? 'btn-danger' : 'btn-success');
      }
    }

    if (id === 'rejectModal') {
      setVal(modal, '[data-reject-action-input]', opener.getAttribute('data-reject-action') || '');
      setVal(modal, '[data-reject-dealer-input]', opener.getAttribute('data-dealer-id') || '');
      setVal(modal, '[data-reject-org-input]', opener.getAttribute('data-org-id') || '');
      setText(modal, '[data-reject-sub]', 'Rejecting verification for: ' + name + '. Your reason is included in the email.');
      setVal(modal, 'textarea', '');
    }
  });

  /* ── Bulk actions (users page, revised Oct 2026) ────────────
     form[data-bulk-form]#bulkForm          hidden until a row is ticked
       select[data-bulk-action]             options carry data-email="1|0"
       [data-bulk-limit]                    car-limit input (set_car_limit only)
       [data-bulk-count] [data-bulk-hint] [data-bulk-submit] [data-bulk-clear]
     input[data-check-row][data-bulk-ok="key key…"][data-dealer="0|1"]
                                            the actions each row is eligible for
                                            (computed server-side, uaBulkEligibleKeys)
     #bulkModal                             confirmation: how many change, how
                                            many are skipped, cascade / email notes
  ──────────────────────────────────────────────────────────── */
  var bulkForm = document.querySelector('[data-bulk-form]');
  if (bulkForm) {
    var checkAll  = document.querySelector('[data-check-all]');
    var actionSel = bulkForm.querySelector('[data-bulk-action]');
    var limitWrap = bulkForm.querySelector('[data-bulk-limit]');
    var hintEl    = bulkForm.querySelector('[data-bulk-hint]');
    var submitBtn = bulkForm.querySelector('[data-bulk-submit]');
    var bulkModal = document.getElementById('bulkModal');
    var emailMax  = parseInt(bulkForm.getAttribute('data-email-max') || '25', 10);

    var rows     = function () { return Array.prototype.slice.call(document.querySelectorAll('[data-check-row]')); };
    var selected = function () { return rows().filter(function (c) { return c.checked; }); };
    var appliesTo = function (c, key) {
      return (' ' + (c.getAttribute('data-bulk-ok') || '') + ' ').indexOf(' ' + key + ' ') !== -1;
    };
    var plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); };

    // Remember each option's base label so counts can be appended.
    Array.prototype.forEach.call(actionSel.options, function (o) {
      if (o.value) o.setAttribute('data-label', o.textContent);
    });

    var refresh = function () {
      var all = rows(), sel = selected(), n = sel.length, act = actionSel.value;

      bulkForm.hidden = n === 0;
      setText(bulkForm, '[data-bulk-count]', String(n));
      all.forEach(function (c) {
        var tr = c.closest('tr');
        if (tr) tr.classList.toggle('is-selected', c.checked);
      });
      if (checkAll) {
        checkAll.checked       = n > 0 && n === all.length;
        checkAll.indeterminate = n > 0 && n < all.length;
      }

      // Each option shows how many of the selection it applies to;
      // options that apply to none are disabled.
      Array.prototype.forEach.call(actionSel.options, function (o) {
        if (!o.value) return;
        var k = sel.filter(function (c) { return appliesTo(c, o.value); }).length;
        o.textContent = o.getAttribute('data-label') + (n && k < n ? ' (' + k + ' of ' + n + ')' : '');
        o.disabled = n > 0 && k === 0;
      });
      if (actionSel.selectedOptions[0] && actionSel.selectedOptions[0].disabled) {
        actionSel.value = '';
        act = '';
      }

      if (limitWrap) limitWrap.hidden = act !== 'set_car_limit';
      var eligible = act ? sel.filter(function (c) { return appliesTo(c, act); }).length : 0;
      var opt      = actionSel.selectedOptions[0];
      var isEmail  = opt && opt.getAttribute('data-email') === '1';
      var hint     = '';
      if (act && eligible < n) hint = eligible + ' of ' + n + ' apply';
      if (act && isEmail && eligible > emailMax) hint = 'Emails go to at most ' + emailMax + ' at a time';
      if (hintEl) hintEl.textContent = hint;
      if (submitBtn) submitBtn.disabled = n === 0 || !act;
    };

    if (checkAll) {
      checkAll.addEventListener('change', function () {
        rows().forEach(function (c) { c.checked = checkAll.checked; });
        refresh();
      });
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches('[data-check-row]') || e.target === actionSel) refresh();
    });
    var clearBtn = bulkForm.querySelector('[data-bulk-clear]');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        rows().forEach(function (c) { c.checked = false; });
        if (checkAll) checkAll.checked = false;
        refresh();
      });
    }

    // Confirm before applying. Without JS the form posts directly and the
    // server applies the same eligibility rules.
    bulkForm.addEventListener('submit', function (e) {
      if (!bulkModal || bulkForm.getAttribute('data-confirmed') === '1') return;
      e.preventDefault();

      var act   = actionSel.value;
      var opt   = actionSel.selectedOptions[0];
      if (!act || !opt) return;
      var label = (opt.getAttribute('data-label') || opt.textContent).replace(/…$/, '');
      var sel   = selected();
      var go    = sel.filter(function (c) { return appliesTo(c, act); });
      var skip  = sel.length - go.length;
      var dealers = go.filter(function (c) { return c.getAttribute('data-dealer') === '1'; }).length;
      var isEmail = opt.getAttribute('data-email') === '1';
      var limitIn = limitWrap ? limitWrap.querySelector('input') : null;
      var blocked = go.length === 0 || (isEmail && go.length > emailMax);

      if (act === 'set_car_limit' && limitIn) {
        var v = parseInt(limitIn.value, 10);
        if (!(v >= 1 && v <= 200)) { limitIn.focus(); limitIn.reportValidity && limitIn.reportValidity(); return; }
      }

      setText(bulkModal, '[data-bulk-title]', label + ' — ' + plural(go.length, 'user') + '?');

      var list = bulkModal.querySelector('[data-bulk-summary]');
      list.innerHTML = '';
      var addLine = function (cls, icon, text) {
        var li = document.createElement('li');
        li.className = cls;
        var i = document.createElement('i');
        i.className = 'fa-solid ' + icon;
        i.setAttribute('aria-hidden', 'true');
        li.appendChild(i);
        li.appendChild(document.createTextNode(' ' + text));
        list.appendChild(li);
      };
      if (act === 'export_csv') {
        addLine('is-go', 'fa-check', plural(go.length, 'user') + ' will be downloaded as CSV.');
      } else if (act === 'set_car_limit') {
        addLine('is-go', 'fa-check', plural(go.length, 'broker') + ' will get a limit of ' + (limitIn ? limitIn.value : '') + ' cars.');
      } else {
        addLine('is-go', 'fa-check', plural(go.length, 'user') + ' will be updated.');
      }
      if (skip > 0) {
        addLine('is-skip', 'fa-minus', skip + ' skipped — the action doesn’t apply to ' + (skip === 1 ? 'that user' : 'them') + ' (for example, already in that state).');
      }

      var warn = '';
      if (go.length === 0) {
        warn = 'None of the selected users can take this action, so there’s nothing to do.';
      } else if (isEmail && go.length > emailMax) {
        warn = 'This action emails each user, so it runs on at most ' + emailMax + ' at a time. Select fewer users — nothing will be sent.';
      } else if (act === 'suspend' && dealers > 0) {
        warn = plural(dealers, 'dealer principal') + ': all their live listings will be paused and pending commissions frozen. Broker attribution is never affected.';
      } else if (act === 'reactivate' && dealers > 0) {
        warn = plural(dealers, 'dealer principal') + ': paused listings will be restored and frozen commissions unfrozen.';
      } else if (act === 'suspend') {
        warn = 'Suspended users can’t sign in, and brokers’ desks are hidden from the public site until they’re reactivated.';
      } else if (isEmail) {
        warn = 'Each user receives an email.';
      } else if (act === 'unverify_email') {
        warn = 'They’ll be asked to confirm their email with a code at their next sign-in.';
      } else if (act === 'export_csv') {
        warn = 'This is personal information. You may be asked to confirm it’s you first.';
      }
      var warnBox = bulkModal.querySelector('[data-bulk-warn]');
      if (warnBox) warnBox.hidden = warn === '';
      setText(bulkModal, '[data-bulk-warn-text]', warn);

      var ok = bulkModal.querySelector('[data-bulk-confirm]');
      if (ok) {
        var danger = act === 'suspend' || act === 'unverify_email';
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        ok.textContent = blocked ? 'Nothing to apply' : label;
        ok.disabled = blocked;
      }
      openModal(bulkModal);
      if (ok && !blocked) setTimeout(function () { ok.focus(); }, 40);
    });

    if (bulkModal) {
      var confirmBtn = bulkModal.querySelector('[data-bulk-confirm]');
      if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
          if (confirmBtn.disabled) return;
          confirmBtn.disabled = true;
          bulkForm.setAttribute('data-confirmed', '1');
          bulkForm.submit();                 // native submit: no second submit event
        });
        // Coming back via the browser's Back button must ask again.
        window.addEventListener('pageshow', function () {
          bulkForm.removeAttribute('data-confirmed');
          confirmBtn.disabled = false;
        });
      }
    }

    refresh();
  }
})();
