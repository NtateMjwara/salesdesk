<?php
/**
 * SalesDesk — Sales Exec Settings.
 * T3 owns this file.
 *
 * Task sep5: Profile + job_title edit.
 * Dealership shown as read-only (cannot change after onboarding).
 * Avatar upload via multipart form.
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
            // Handle avatar upload
            $avatarUrl = $profile['avatar_url'];
            if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $maxBytes  = 2097152; // 2MB
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

                // Refresh profile data
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
$initials    = strtoupper(substr($profile['first_name'] ?? 'E', 0, 1) . substr($profile['last_name'] ?? '', 0, 1));

$pageTitle = 'Settings';
ob_start();
?>

<!-- ── Page header ────────────────────────────────────────────── -->
<div class="settings-header">
  <h1 class="settings-title">
    Account <em>settings</em>
  </h1>
  <p class="settings-subtitle">
    Manage your profile. Your dealership assignment is read-only.
  </p>
</div>

<?php if ($flashOk): ?>
<div class="alert alert-success alert-spaced">
  <i class="fa-solid fa-circle-check alert-icon"></i> <?= htmlspecialchars($flashOk) ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="alert alert-error alert-spaced">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i> <?= htmlspecialchars($flashError) ?>
</div>
<?php endif; ?>

<div class="settings-layout">

  <!-- ── Profile form ──────────────────────────────────────── -->
  <div class="card card-body">
    <h2 class="settings-section-title">
      Profile details
    </h2>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="update_profile">

      <!-- Avatar section -->
      <div class="avatar-row">
        <div class="avatar-circle" id="avatarPreview">
          <?php if ($profile['avatar_url']): ?>
          <img src="<?= htmlspecialchars($profile['avatar_url']) ?>" alt="Avatar" id="avatarImg">
          <?php else: ?>
          <span id="avatarInitials"><?= htmlspecialchars($initials) ?></span>
          <?php endif; ?>
        </div>
        <div>
          <label for="avatar" class="btn btn-ghost btn-sm avatar-upload-label">
            <i class="fa-solid fa-camera"></i> Change photo
          </label>
          <input type="file" id="avatar" name="avatar"
                 accept="image/jpeg,image/png,image/webp"
                 class="avatar-file-input"
                 onchange="previewAvatar(this)">
          <div class="avatar-hint">
            JPG, PNG, WebP · max 2MB
          </div>
        </div>
      </div>

      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="first_name">First name *</label>
          <input class="finput" type="text" id="first_name" name="first_name"
                 required maxlength="60" autocomplete="given-name"
                 value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="last_name">Last name *</label>
          <input class="finput" type="text" id="last_name" name="last_name"
                 required maxlength="60" autocomplete="family-name"
                 value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>">
        </div>
      </div>

      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="phone">Mobile number</label>
          <input class="finput" type="tel" id="phone" name="phone"
                 maxlength="20" autocomplete="tel" placeholder="082 000 0000"
                 value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
        </div>
        <div class="fgroup">
          <label class="flabel" for="job_title">Job title</label>
          <input class="finput" type="text" id="job_title" name="job_title"
                 maxlength="100" placeholder="e.g. Senior Sales Executive"
                 value="<?= htmlspecialchars($profile['job_title'] ?? '') ?>">
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="bio">Bio <span class="flabel-opt">(optional)</span></label>
        <textarea class="finput" id="bio" name="bio"
                  maxlength="400" rows="3"
                  placeholder="Short intro shown to brokers and buyers…"><?= htmlspecialchars($profile['bio'] ?? '') ?></textarea>
      </div>

      <!-- Email — read only -->
      <div class="fgroup">
        <label class="flabel">Email address</label>
        <div class="email-readonly">
          <?= htmlspecialchars($profile['email']) ?>
        </div>
        <div class="field-note">
          Email cannot be changed. Contact support if needed.
        </div>
      </div>

      <button class="btn btn-primary btn-full" type="submit">
        <i class="fa-solid fa-check"></i> Save changes
      </button>
    </form>
  </div>

  <!-- ── Sidebar: dealership info + account details ────────── -->
  <div class="sidebar-stack">

    <!-- Dealership card (read-only) -->
    <div class="card card-body">
      <h3 class="card-section-title">
        <i class="fa-solid fa-building card-section-icon"></i>
        Dealership
      </h3>
      <div class="dealer-name">
        <?= htmlspecialchars($exec['dealer_name']) ?>
      </div>
      <span class="badge badge-verified">
        <i class="fa-solid fa-circle-check badge-verified-icon"></i> Verified exec
      </span>
      <?php if ($exec['job_title']): ?>
      <div class="dealer-job-title">
        <?= htmlspecialchars($exec['job_title']) ?>
      </div>
      <?php endif; ?>
      <div class="dealer-note">
        Your dealership assignment is set during onboarding and can only be changed by
        contacting support.
      </div>
    </div>

    <!-- Account info -->
    <div class="card card-body">
      <h3 class="card-section-title">
        <i class="fa-solid fa-shield-halved card-section-icon"></i>
        Account
      </h3>
      <div class="account-info">
        <strong>Role:</strong> Sales Executive<br>
        <strong>Status:</strong>
        <span class="account-status-verified">Verified</span><br>
        <strong>Email:</strong> <?= htmlspecialchars($profile['email']) ?>
      </div>
      <a href="/auth/reset_password.php"
         class="btn btn-ghost btn-sm change-password-btn">
        <i class="fa-solid fa-key"></i> Change password
      </a>
    </div>

  </div>

</div>

<script>
function previewAvatar(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var preview = document.getElementById('avatarPreview');
    var initials = document.getElementById('avatarInitials');
    var img = document.getElementById('avatarImg');
    if (initials) initials.style.display = 'none';
    if (!img) {
      img = document.createElement('img');
      img.id = 'avatarImg';
      img.className = 'avatar-img-preview';
      preview.appendChild(img);
    }
    img.src = e.target.result;
    img.style.display = 'block';
  };
  reader.readAsDataURL(input.files[0]);
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
