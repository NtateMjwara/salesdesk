<?php
/**
 * SalesDesk — Careers: Apply
 * Route: /careers/apply/?role={slug}   (role param optional — omit for a
 *        speculative application not tied to any specific posting)
 *
 * POST handling:
 *   1. Rate-limit by IP (reuses checkApiRateLimit — 5/min is plenty for
 *      a human filling in a form, blocks basic bot hammering).
 *   2. Honeypot field ("website") — bots fill every input, humans never
 *      see it (visually hidden, not display:none which some bots skip).
 *   3. Validate required fields.
 *   4. Upload + validate resume (see includes/careers.php::uploadResumeFile).
 *   5. Insert job_applications row.
 *   6. Best-effort confirmation email to the applicant — failure to send
 *      never blocks the successful application state.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/careers.php';
require_once '../../includes/mailer.php';

applyCachePolicy('public');

$roleSlug = trim($_GET['role'] ?? '');
$job      = null;
$jobError = false;

if ($roleSlug) {
    try {
        $job = getJobPostingBySlug($roleSlug, true);
        if (!$job) {
            $jobError = true; // slug given but not found / not published
        }
    } catch (Throwable) {
        $jobError = true; // table missing etc. — degrade to speculative form
    }
}

$errors  = [];
$success = false;
$old     = ['full_name' => '', 'email' => '', 'phone' => '', 'linkedin_url' => '', 'portfolio_url' => '', 'cover_note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkApiRateLimit($ip, 'careers_apply', 5)) {
        $errors[] = 'Too many submissions from this connection — please wait a minute and try again.';
    }

    // Honeypot — bots tend to fill every field they find.
    if (!empty($_POST['website'])) {
        $errors[] = 'Something went wrong — please try again.';
    }

    $old['full_name']    = trim($_POST['full_name'] ?? '');
    $old['email']        = trim($_POST['email'] ?? '');
    $old['phone']        = trim($_POST['phone'] ?? '');
    $old['linkedin_url'] = trim($_POST['linkedin_url'] ?? '');
    $old['portfolio_url'] = trim($_POST['portfolio_url'] ?? '');
    $old['cover_note']   = trim($_POST['cover_note'] ?? '');
    $jobPostingId        = (int) ($_POST['job_posting_id'] ?? 0) ?: null;

    if (!$old['full_name']) {
        $errors[] = 'Please enter your full name.';
    }
    if (!$old['email'] || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    foreach (['linkedin_url', 'portfolio_url'] as $urlField) {
        if ($old[$urlField] && !filter_var($old[$urlField], FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid URL for ' . ($urlField === 'linkedin_url' ? 'LinkedIn' : 'your portfolio') . '.';
        }
    }

    $resumeResult = ['ok' => false];
    if (empty($errors)) {
        $resumeResult = uploadResumeFile($_FILES['resume'] ?? []);
        if (!$resumeResult['ok']) {
            $errors[] = $resumeResult['error'];
        }
    }

    if (empty($errors)) {
        try {
            createJobApplication([
                'job_posting_id'       => $jobPostingId,
                'full_name'            => $old['full_name'],
                'email'                => $old['email'],
                'phone'                => $old['phone'],
                'linkedin_url'         => $old['linkedin_url'],
                'portfolio_url'        => $old['portfolio_url'],
                'cover_note'           => $old['cover_note'],
                'resume_url'           => $resumeResult['url'],
                'resume_original_name' => $resumeResult['original_name'],
                'ip_address'           => $ip,
            ]);

            // Best-effort confirmation — never block success on mail failure.
            try {
                $roleLabel = $job['title'] ?? 'a role at SalesDesk';
                sendEmail(
                    $old['email'],
                    'We received your application — SalesDesk',
                    '<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">Thanks for applying!</h2>'
                    . '<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 16px;">'
                    . 'Hi ' . htmlspecialchars($old['full_name']) . ', we\'ve received your application for '
                    . '<strong>' . htmlspecialchars($roleLabel) . '</strong>. Our team reviews every application '
                    . 'personally — if there\'s a fit, we\'ll be in touch.</p>'
                    . '<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">'
                    . 'No need to reply to this email — we\'ll reach out from a member of the team directly.</p>'
                );
            } catch (Throwable) {
                // Swallow — confirmation email is a nice-to-have, not a blocker.
            }

            $success = true;
        } catch (Throwable $e) {
            error_log('[SalesDesk careers] application insert failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong submitting your application. Please try again.';
        }
    }
}

// ── Page meta ────────────────────────────────────────────────
$roleTitle     = $job['title'] ?? 'General Application';
$pageTitle     = 'Apply — ' . $roleTitle . ' | SalesDesk Careers';
$ogTitle       = $pageTitle;
$ogDescription = 'Apply to join SalesDesk.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : '') . '/careers/apply/' . ($roleSlug ? '?role=' . urlencode($roleSlug) : '');
$layoutVariant = 'narrow';
$showBreadcrumb = false;

ob_start();
?>

<div class="cra-wrap">
  <a href="/careers/" class="cra-back"><i class="fa-solid fa-arrow-left"></i> Back to all roles</a>

  <?php if ($success): ?>

  <!-- ── Success state ── -->
  <div class="cra-success">
    <div class="cra-success__icon"><i class="fa-solid fa-check"></i></div>
    <h1 class="cra-success__title">Application received</h1>
    <p class="cra-success__sub">
      Thanks, <?= htmlspecialchars($old['full_name']) ?> — we've sent a confirmation to
      <strong><?= htmlspecialchars($old['email']) ?></strong>. Our team reviews every
      application personally, so it may take a little while to hear back.
    </p>
    <a href="/careers/" class="cr-btn cr-btn-primary">Browse other roles</a>
  </div>

  <?php else: ?>

  <div class="cra-head">
    <?php if ($job): ?>
    <span class="cra-eyebrow">Applying for</span>
    <h1 class="cra-title"><?= htmlspecialchars($job['title']) ?></h1>
    <div class="cra-meta">
      <span><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($job['location']) ?></span>
      <span><i class="fa-solid fa-clock"></i> <?= htmlspecialchars(jobEmploymentTypeLabel($job['employment_type'])) ?></span>
      <span><i class="fa-solid fa-house-signal"></i> <?= htmlspecialchars(jobWorkModeLabel($job['work_mode'])) ?></span>
    </div>
    <?php if (!empty($job['description'])): ?>
    <div class="cra-jd"><?= $job['description'] /* Quill HTML, admin-authored — trusted */ ?></div>
    <?php endif; ?>
    <?php else: ?>
    <span class="cra-eyebrow">Speculative application</span>
    <h1 class="cra-title">Tell us about yourself</h1>
    <p class="cra-sub">
      <?= $jobError ? 'That role isn\'t available anymore, but we\'d still love to hear from you.' : 'No open role fits? We\'re still keen to meet strong people.' ?>
    </p>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="cra-alert">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
      <?php foreach ($errors as $e): ?>
      <div><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="cra-form">
    <?= csrf_hidden_field() ?>
    <?php if ($job): ?>
    <input type="hidden" name="job_posting_id" value="<?= (int) $job['id'] ?>">
    <?php endif; ?>

    <!-- Honeypot — visually hidden, still in tab order for bots that skip display:none -->
    <div class="cra-hp" aria-hidden="true">
      <label for="website">Leave this field empty</label>
      <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
    </div>

    <div class="cra-grid">
      <div class="cra-field">
        <label class="cra-label" for="full_name">Full name <span>*</span></label>
        <input class="cra-input" type="text" id="full_name" name="full_name" required
               value="<?= htmlspecialchars($old['full_name']) ?>" placeholder="Jane Dlamini">
      </div>
      <div class="cra-field">
        <label class="cra-label" for="email">Email address <span>*</span></label>
        <input class="cra-input" type="email" id="email" name="email" required
               value="<?= htmlspecialchars($old['email']) ?>" placeholder="jane@example.com">
      </div>
    </div>

    <div class="cra-grid">
      <div class="cra-field">
        <label class="cra-label" for="phone">Phone number</label>
        <input class="cra-input" type="tel" id="phone" name="phone"
               value="<?= htmlspecialchars($old['phone']) ?>" placeholder="082 123 4567">
      </div>
      <div class="cra-field">
        <label class="cra-label" for="linkedin_url">LinkedIn profile</label>
        <input class="cra-input" type="url" id="linkedin_url" name="linkedin_url"
               value="<?= htmlspecialchars($old['linkedin_url']) ?>" placeholder="https://linkedin.com/in/…">
      </div>
    </div>

    <div class="cra-field">
      <label class="cra-label" for="portfolio_url">Portfolio / GitHub / website</label>
      <input class="cra-input" type="url" id="portfolio_url" name="portfolio_url"
             value="<?= htmlspecialchars($old['portfolio_url']) ?>" placeholder="https://…">
    </div>

    <div class="cra-field">
      <label class="cra-label" for="resume">CV / résumé <span>*</span></label>
      <div class="cra-file-wrap">
        <input class="cra-file-input" type="file" id="resume" name="resume" accept=".pdf,.doc,.docx" required>
        <label for="resume" class="cra-file-label" id="craFileLabel">
          <i class="fa-solid fa-cloud-arrow-up"></i>
          <span id="craFileLabelText">Choose a PDF, DOC, or DOCX (max 5MB)</span>
        </label>
      </div>
    </div>

    <div class="cra-field">
      <label class="cra-label" for="cover_note">Anything else we should know?</label>
      <textarea class="cra-input cra-textarea" id="cover_note" name="cover_note" rows="5"
                placeholder="A short note on why you're interested — totally optional."><?= htmlspecialchars($old['cover_note']) ?></textarea>
    </div>

    <button type="submit" class="cr-btn cr-btn-primary cra-submit" id="craSubmit">
      <i class="fa-solid fa-paper-plane"></i> Submit application
    </button>

    <p class="cra-privacy">
      By submitting, you consent to SalesDesk storing your details for recruitment purposes
      in line with our <a href="/privacy">Privacy Policy</a> and POPIA.
    </p>
  </form>

  <?php endif; ?>
</div>

<style>
.cra-wrap { max-width: 640px; margin-inline: auto; padding: clamp(32px, 5vw, 56px) 0 80px; }

.cra-back {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 600; color: var(--muted);
  text-decoration: none; margin-bottom: 28px;
}
.cra-back:hover { color: var(--p); text-decoration: none; }

.cra-head { margin-bottom: 28px; }
.cra-eyebrow {
  display: inline-block; font-family: var(--font-d); font-size: 11px; font-weight: 700;
  letter-spacing: .1em; text-transform: uppercase; color: var(--p); margin-bottom: 8px;
}
.cra-title {
  font-family: var(--serif); font-weight: 500; font-size: clamp(24px, 3.4vw, 32px);
  color: #08143c; letter-spacing: -.01em; margin-bottom: 12px;
}
.cra-sub { font-size: 14px; color: var(--muted); line-height: 1.6; }
.cra-meta { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12.5px; color: var(--faint); margin-bottom: 18px; }
.cra-meta span { display: inline-flex; align-items: center; gap: 6px; }
.cra-meta i { color: var(--p); }
.cra-jd {
  font-size: 13.5px; line-height: 1.75; color: var(--text2);
  background: var(--bg2); border: 1px solid var(--border); border-radius: var(--r-lg);
  padding: 18px 20px; margin-top: 6px; max-height: 260px; overflow-y: auto;
}
.cra-jd p { margin-bottom: .9em; }
.cra-jd ul, .cra-jd ol { margin: .6em 0 .9em 1.2em; }

.cra-alert {
  display: flex; gap: 10px; align-items: flex-start;
  background: var(--red-bg); border: 1px solid var(--red-b); color: #7f1d1d;
  border-radius: var(--r-md); padding: 12px 16px; font-size: 13px; line-height: 1.6; margin-bottom: 20px;
}
.cra-alert i { color: var(--red); margin-top: 2px; }

.cra-form { display: flex; flex-direction: column; gap: 16px; }
.cra-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.cra-field { display: flex; flex-direction: column; gap: 6px; }
.cra-label { font-size: 12.5px; font-weight: 600; color: var(--text); }
.cra-label span { color: var(--red); }
.cra-input {
  height: 44px; border: 1.5px solid var(--border); border-radius: var(--r-md);
  padding: 0 14px; font-size: 14px; font-family: var(--sans); color: var(--text);
  background: #fff; outline: none; transition: border-color .18s, box-shadow .18s;
}
.cra-input:focus { border-color: var(--p); box-shadow: 0 0 0 3px rgba(15,76,158,.08); }
.cra-textarea { height: auto; padding: 12px 14px; resize: vertical; }

.cra-file-wrap { position: relative; }
.cra-file-input {
  position: absolute; inset: 0; width: 100%; height: 100%;
  opacity: 0; cursor: pointer;
}
.cra-file-label {
  display: flex; align-items: center; gap: 10px;
  height: 52px; border: 1.5px dashed var(--border2); border-radius: var(--r-md);
  padding: 0 16px; font-size: 13px; color: var(--muted); background: var(--bg2);
  transition: border-color .18s, color .18s, background .18s;
}
.cra-file-label i { color: var(--p); font-size: 16px; }
.cra-file-wrap:hover .cra-file-label { border-color: var(--p); color: var(--p); background: var(--p-light); }

.cra-submit { width: 100%; justify-content: center; margin-top: 6px; }
.cra-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }

.cra-privacy { font-size: 11.5px; color: var(--faint); line-height: 1.6; text-align: center; }
.cra-privacy a { color: var(--p); }

/* Honeypot — visually hidden but present in DOM/tab order for basic bots */
.cra-hp {
  position: absolute; left: -9999px; top: -9999px;
  width: 1px; height: 1px; overflow: hidden;
}

/* ── Success state ── */
.cra-success { text-align: center; padding: 40px 0 20px; }
.cra-success__icon {
  width: 56px; height: 56px; border-radius: 50%;
  background: var(--gr-bg); border: 2px solid var(--gr-b);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: var(--green); margin: 0 auto 20px;
}
.cra-success__title { font-family: var(--font-d); font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 12px; }
.cra-success__sub { font-size: 14px; color: var(--muted); line-height: 1.7; margin-bottom: 28px; }

/* Reuse public button style */
.cr-btn {
  display: inline-flex; align-items: center; gap: 9px;
  font-family: var(--sans); font-weight: 600; font-size: 14px;
  padding: 13px 26px; border-radius: var(--r-md); text-decoration: none;
  border: none; cursor: pointer; transition: transform .18s ease, background .18s ease;
}
.cr-btn-primary { background: var(--p); color: #fff; }
.cr-btn-primary:hover { background: var(--p-dark); text-decoration: none; transform: translateY(-1px); }

@media (max-width: 560px) {
  .cra-grid { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
  'use strict';
  var fileInput = document.getElementById('resume');
  var labelText = document.getElementById('craFileLabelText');
  if (fileInput && labelText) {
    fileInput.addEventListener('change', function () {
      labelText.textContent = fileInput.files.length
        ? fileInput.files[0].name
        : 'Choose a PDF, DOC, or DOCX (max 5MB)';
    });
  }

  var form   = document.querySelector('.cra-form');
  var submit = document.getElementById('craSubmit');
  if (form && submit) {
    form.addEventListener('submit', function () {
      submit.disabled = true;
      submit.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Submitting…';
    });
  }
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-public.php';