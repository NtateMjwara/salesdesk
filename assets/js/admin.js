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
})();
