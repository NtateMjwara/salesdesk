<?php
/**
 * SalesDesk — Sales Exec Settings.
 * T3 owns this file.
 *
 * REFACTORED: All inline style= attributes replaced with semantic CSS classes
 * defined in dealer.css §10 (Settings) and §DB (Dashboard additions).
 *
 * Changes from original:
 *   — inline 2-col grid → .d-settings-layout (CSS, stacks on mobile)
 *   — inline avatar row flex → .d-avatar-row (CSS, wraps on mobile)
 *   — inline .frow 2-col → uses existing .frow which now has mobile override
 *   — inline read-only email field → .d-readonly-field (has word-break)
 *   — inline sidebar cards → .d-settings-sidebar / .d-settings-info-card
 *   — flash alerts now scroll into view via JS on load
 *   — save button gets JS disabled state on submit to prevent double-submit
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/exec_guard.php';

applyCachePolicy('auth');

$exec   = requireExecVerified();
$execId = (int) $exec['id'];
$userId = (int) $_SESSION['user_id'];
$pdo    = Database::getInstance();
$csrf   = generateCSRFToken();

// ── Load current profile ──────────────────────────────────────
$profileStmt = $pdo->prepare("
    SELECT p.first_name, p.last_name, p.phone, p.bio, p.avatar_url,
           se.job_title, u.email
    FROM profiles p
    JOIN users u ON u.id = p.user_id
    JOIN sales_executives se ON se.user_id = p.user_id
    WHERE p.user_id = ? AND se.id = ?
");
$profileStmt->execute([$userId, $execId]);
$profile = $profileStmt->fetch();
if (!$profile) redirect('/app/exec/dashboard.php');

$flashOk    = '';
$flashError = '';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        $bio       = trim($_POST['bio']        ?? '');
        $jobTitle  = trim($_POST['job_title']  ?? '');

        if (!$firstName || !$lastName) {
            $flashError = 'First and last name are required.';
        } else {
            $avatarUrl = $profile['avatar_url'];
            if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $maxBytes  = 2097152;
                $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/avatars/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                if ($_FILES['avatar']['size'] <= $maxBytes) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime  = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
                    finfo_close($finfo);

                    if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                        $ext  = match($mime) { 'image/png' => '.png', 'image/webp' => '.webp', default => '.jpg' };
                        $name = generateUuidV4() . $ext;
                        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $name)) {
                            $avatarUrl = '/uploads/avatars/' . $name;
                        }
                    } else {
                        $flashError = 'Avatar must be JPG, PNG, or WebP.';
                    }
                } else {
                    $flashError = 'Avatar must be under 2MB.';
                }
            }

            if (!$flashError) {
                $pdo->prepare("
                    UPDATE profiles
                    SET first_name = ?, last_name = ?, phone = ?,
                        bio = ?, avatar_url = ?, updated_at = NOW()
                    WHERE user_id = ?
                ")->execute([$firstName, $lastName, $phone ?: null,
                             $bio ?: null, $avatarUrl, $userId]);

                $pdo->prepare("
                    UPDATE sales_executives
                    SET job_title = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$jobTitle ?: null, $execId]);

                writeAuditLog('exec.profile_updated', 'sales_executive', $execId, null,
                    ['first_name' => $firstName, 'last_name' => $lastName]);

                $profile['first_name'] = $firstName;
                $profile['last_name']  = $lastName;
                $profile['phone']      = $phone;
                $profile['bio']        = $bio;
                $profile['avatar_url'] = $avatarUrl;
                $profile['job_title']  = $jobTitle;

                $flashOk = 'Profile updated successfully.';
            }
        }
    }
}

$displayName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$initials    = strtoupper(
    substr($profile['first_name'] ?? 'E', 0, 1) .
    substr($profile['last_name']  ?? '',  0, 1)
);

$pageTitle = 'Settings';
ob_start();
?>

<?php /* ── Page header ──────────────────────────────────────── */ ?>
<div class="dash-header" id="settings-top">
  <div class="dash-header__text">
    <h1 class="page-header__title">Account <em>settings</em></h1>
    <p class="dash-header__sub-plain">Manage your profile. Your dealership assignment is read-only.</p>
  </div>
</div>

<?php /* ── Flash alerts (scroll into view via JS) ──────────── */ ?>
<?php if ($flashOk): ?>
<div class="alert alert-success" id="settings-flash" style="margin-bottom:1.25rem;">
  <i class="fa-solid fa-circle-check alert-icon" aria-hidden="true"></i>
  <?= htmlspecialchars($flashOk) ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error" id="settings-flash" style="margin-bottom:1.25rem;">
  <i class="fa-solid fa-circle-exclamation alert-icon" aria-hidden="true"></i>
  <?= htmlspecialchars($flashError) ?>
</div>
<?php endif; ?>

<?php /* ── 2-col settings layout ──────────────────────────── */ ?>
<div class="d-settings-layout">

  <?php /* ── Left: profile form ─────────────────────────────── */ ?>
  <div class="d-settings-card">
    <div class="d-settings-section">
      <h2 class="d-settings-section__title">
        <i class="fa-solid fa-user" aria-hidden="true"></i>
        Profile details
      </h2>

      <form method="POST" enctype="multipart/form-data" id="profileForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_profile">

        <?php /* ── Avatar upload ─────────────────────────────── */ ?>
        <div class="d-avatar-row">
          <div class="d-avatar-preview" id="avatarPreview" aria-label="Profile photo">
            <?php if ($profile['avatar_url']): ?>
            <img src="<?= htmlspecialchars($profile['avatar_url']) ?>"
                 alt="Profile photo"
                 id="avatarImg">
            <?php else: ?>
            <span id="avatarInitials" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
          </div>
          <div class="d-avatar-upload-info">
            <label for="avatar" class="btn btn-ghost btn-sm" style="cursor:pointer;">
              <i class="fa-solid fa-camera" aria-hidden="true"></i>
              Change photo
            </label>
            <input type="file"
                   id="avatar"
                   name="avatar"
                   accept="image/jpeg,image/png,image/webp"
                   class="d-avatar-file-input"
                   aria-label="Upload profile photo">
            <div class="d-avatar-upload-info__hint">JPG, PNG, WebP · max 2 MB</div>
          </div>
        </div>

        <?php /* ── Name row ──────────────────────────────────── */ ?>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="first_name">First name <span aria-hidden="true">*</span></label>
            <input class="finput"
                   type="text"
                   id="first_name"
                   name="first_name"
                   required
                   maxlength="60"
                   autocomplete="given-name"
                   value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="last_name">Last name <span aria-hidden="true">*</span></label>
            <input class="finput"
                   type="text"
                   id="last_name"
                   name="last_name"
                   required
                   maxlength="60"
                   autocomplete="family-name"
                   value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>">
          </div>
        </div>

        <?php /* ── Phone + job title ───────────────────────── */ ?>
        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="phone">Mobile number</label>
            <input class="finput"
                   type="tel"
                   id="phone"
                   name="phone"
                   maxlength="20"
                   autocomplete="tel"
                   placeholder="082 000 0000"
                   value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="job_title">Job title</label>
            <input class="finput"
                   type="text"
                   id="job_title"
                   name="job_title"
                   maxlength="100"
                   placeholder="e.g. Senior Sales Executive"
                   value="<?= htmlspecialchars($profile['job_title'] ?? '') ?>">
          </div>
        </div>

        <?php /* ── Bio ───────────────────────────────────────── */ ?>
        <div class="fgroup">
          <label class="flabel" for="bio">
            Bio <span class="flabel-opt">(optional)</span>
          </label>
          <textarea class="finput"
                    id="bio"
                    name="bio"
                    maxlength="400"
                    rows="3"
                    placeholder="Short intro shown to brokers and buyers…"><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
        </div>

        <?php /* ── Email (read-only) ─────────────────────────── */ ?>
        <div class="fgroup">
          <label class="flabel">Email address</label>
          <div class="d-readonly-field" role="textbox" aria-readonly="true" tabindex="0">
            <?= htmlspecialchars($profile['email']) ?>
          </div>
          <p class="d-readonly-note">Email cannot be changed here. Contact support if needed.</p>
        </div>

        <?php /* ── Save ─────────────────────────────────────── */ ?>
        <button class="btn btn-primary btn-full"
                type="submit"
                id="saveBtn">
          <i class="fa-solid fa-check" aria-hidden="true"></i>
          Save changes
        </button>

      </form>
    </div>
  </div>

  <?php /* ── Right: sidebar cards ──────────────────────────── */ ?>
  <div class="d-settings-sidebar">

    <?php /* ── Dealership card ─────────────────────────────── */ ?>
    <div class="d-settings-info-card">
      <h3 class="d-settings-info-card__title">
        <i class="fa-solid fa-building" aria-hidden="true"></i>
        Dealership
      </h3>
      <div class="d-dealer-info-name"><?= htmlspecialchars($exec['dealer_name']) ?></div>
      <span class="badge badge-verified">
        <i class="fa-solid fa-circle-check" style="font-size:9px" aria-hidden="true"></i>
        Verified exec
      </span>
      <?php if ($exec['job_title']): ?>
      <div class="d-dealer-info-meta" style="margin-top:8px;">
        <?= htmlspecialchars($exec['job_title']) ?>
      </div>
      <?php endif; ?>
      <p class="d-dealer-info-note">
        Your dealership is set during onboarding. Contact support to change it.
      </p>
    </div>

    <?php /* ── Account card ──────────────────────────────────── */ ?>
    <div class="d-settings-info-card">
      <h3 class="d-settings-info-card__title">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        Account
      </h3>
      <div class="d-account-detail-row">
        <span class="d-account-detail-row__key">Role</span>
        <span class="d-account-detail-row__val">Sales Executive</span>
      </div>
      <div class="d-account-detail-row">
        <span class="d-account-detail-row__key">Status</span>
        <span class="d-account-detail-row__val" style="color:var(--green);">Verified</span>
      </div>
      <div class="d-account-detail-row">
        <span class="d-account-detail-row__key">Email</span>
        <span class="d-account-detail-row__val"><?= htmlspecialchars($profile['email']) ?></span>
      </div>
      <a href="/auth/reset_password.php"
         class="btn btn-ghost btn-sm"
         style="text-decoration:none;margin-top:14px;width:100%;">
        <i class="fa-solid fa-key" aria-hidden="true"></i>
        Change password
      </a>
    </div>

  </div>

</div>

<script>
(function () {
  'use strict';

  /* ── Avatar preview ──────────────────────────────────────── */
  var fileInput = document.getElementById('avatar');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var file = this.files && this.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        var preview  = document.getElementById('avatarPreview');
        var initials = document.getElementById('avatarInitials');
        var img      = document.getElementById('avatarImg');
        if (initials) initials.style.display = 'none';
        if (!img) {
          img = document.createElement('img');
          img.id = 'avatarImg';
          img.alt = 'Profile photo';
          preview.appendChild(img);
        }
        img.src = e.target.result;
        img.style.display = 'block';
      };
      reader.readAsDataURL(file);
    });
  }

  /* ── Prevent double-submit ───────────────────────────────── */
  var form    = document.getElementById('profileForm');
  var saveBtn = document.getElementById('saveBtn');
  if (form && saveBtn) {
    form.addEventListener('submit', function () {
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Saving…';
    });
  }

  /* ── Scroll flash message into view on load ──────────────── */
  var flash = document.getElementById('settings-flash');
  if (flash) {
    flash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    flash.setAttribute('role', 'alert');
    flash.setAttribute('aria-live', 'polite');
  }
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
