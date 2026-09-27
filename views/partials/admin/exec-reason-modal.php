<?php
/**
 * Admin partial — reason modal for declining / suspending a sales exec
 * or a desk organisation agent.  (0012, generalised in 0013)
 * Opened by any element with data-reason-modal="reject|suspend",
 * data-exec-id (the record id), data-exec-name and optional
 * data-reason-kind="exec|agent" (default exec) — see assets/js/admin.js.
 * Posts to the current page with action + kind + target_id + reason.
 */
?>
<div class="modal-bg" id="execReasonModal">
  <div class="modal">
    <div class="modal-title adm-text-red" data-reason-title>Decline request</div>
    <p class="modal-sub" data-reason-sub></p>
    <form method="POST">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="reject" data-reason-action>
      <input type="hidden" name="kind" value="exec" data-reason-kind>
      <input type="hidden" name="target_id" value="" data-reason-exec>
      <div class="fgroup">
        <label class="flabel" for="execReasonInput">Reason <span class="flabel-opt">shared with the applicant</span></label>
        <textarea class="finput" id="execReasonInput" name="reason" rows="3" maxlength="255"
                  placeholder="e.g. We couldn't verify your details."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger" data-reason-submit>Decline &amp; notify</button>
      </div>
    </form>
  </div>
</div>
