/**
 * SalesDesk — Footer Newsletter Subscribe  (v1)
 * assets/js/footer-newsletter.js
 *
 * Moved from the inline NL-1 <script> in views/layout-public.php.
 * Behaviour unchanged: POST to /api/newsletter/subscribe.php with
 * source=footer. CSRF header is auto-injected by global.js's fetch()
 * interceptor, so global.js must load before this file.
 *
 * Change: status colours now come from .is-success / .is-error in
 * public-shell.css §11 instead of note.style.color hex values.
 */
(function () {
  'use strict';

  var form = document.getElementById('footerNlForm');
  if (!form) return;

  var input       = document.getElementById('footerNlEmail');
  var btn         = document.getElementById('footerNlBtn');
  var note        = document.getElementById('footerNlNote');
  if (!input || !btn || !note) return;

  var defaultNote = note.textContent;

  function setNote(msg, state) {
    note.textContent = msg;
    note.classList.remove('is-success', 'is-error');
    if (state) note.classList.add('is-' + state);
  }

  function resetButton(label) {
    btn.disabled = false;
    btn.textContent = label;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var email = input.value.trim();
    if (!email) {
      setNote('Please enter your email address.', 'error');
      input.focus();
      return;
    }

    var origLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Subscribing…';
    setNote('Sending…');

    fetch('/api/newsletter/subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'email=' + encodeURIComponent(email) + '&source=footer'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        resetButton(origLabel);
        if (data.success) {
          input.value = '';
          setNote(data.message, 'success');
        } else {
          setNote(data.message || 'Something went wrong. Please try again.', 'error');
        }
      })
      .catch(function () {
        resetButton(origLabel);
        setNote('Connection error — please try again.', 'error');
      });
  });

  // Restore the default note once the person edits the field again.
  input.addEventListener('input', function () {
    if (note.textContent !== defaultNote) setNote(defaultNote);
  });

})();
