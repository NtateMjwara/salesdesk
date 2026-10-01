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
        ? (kind === 'agent' ? 'Suspend agent' : 'Suspend sales exec')
        : (kind === 'agent' ? 'Decline application' : 'Decline request');
      modal.querySelector('[data-reason-sub]').textContent    = isSusp
        ? (kind === 'agent'
            ? name + ' won’t be able to add new cars until they are reinstated.'
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
        bulk selection for #bulkForm
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

  var bulkForm = document.querySelector('[data-bulk-form]');
  if (bulkForm) {
    var rows     = function () { return document.querySelectorAll('[data-check-row]'); };
    var checkAll = document.querySelector('[data-check-all]');
    var refresh  = function () {
      var all = rows(), n = 0;
      all.forEach(function (c) { if (c.checked) n++; });
      setText(bulkForm, '[data-bulk-count]', String(n));
      var submit = bulkForm.querySelector('[data-bulk-submit]');
      if (submit) submit.disabled = n === 0;
      bulkForm.classList.toggle('has-selection', n > 0);
      if (checkAll) {
        checkAll.checked       = n > 0 && n === all.length;
        checkAll.indeterminate = n > 0 && n < all.length;
      }
    };
    if (checkAll) {
      checkAll.addEventListener('change', function () {
        rows().forEach(function (c) { c.checked = checkAll.checked; });
        refresh();
      });
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches('[data-check-row]')) refresh();
    });
    refresh();
  }
})();
