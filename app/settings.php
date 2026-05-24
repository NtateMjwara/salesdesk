<?php
/**
 * SalesDesk — Account Settings (Broker).
 * T4 owns this file. Route: /app/settings.php
 * Linked from the nav avatar on all authenticated pages.
 *
 * Sections:
 *   - Profile (name, phone, bio, avatar)
 *   - SalesDesk branding (display name, tagline, primary colour, logo)
 *   - Slug rename (one-time, D-02)
 *   - Password change
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$role   = $_SESSION['user_role'] ?? 'broker';
$csrf   = generateCSRFToken();

// Role redirects — dealer and exec have their own settings pages
if ($role === 'dealer') {
    redirect('/app/dealer/settings.php');
}
if ($role === 'sales_exec') {
    redirect('/app/exec/settings.php');
}
if ($role === 'admin') {
    redirect('/app/admin/users.php');
}

// ── Load broker data ──────────────────────────────────────────
$dataStmt = $pdo->prepare("
    SELECT
        p.first_name, p.last_name, p.phone, p.bio, p.avatar_url,
        u.email,
        sd.id AS desk_id, sd.slug, sd.display_name, sd.tagline,
        sd.logo_url AS desk_logo, sd.primary_colour
    FROM users u
    LEFT JOIN profiles  p  ON p.user_id  = u.id
    LEFT JOIN salesdesks sd ON sd.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$dataStmt->execute([$userId]);
$data = $dataStmt->fetch();
if (!$data) redirect('/auth/register.php');

$deskId = (int) $data['desk_id'];

$flash      = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$section = $_GET['section'] ?? 'profile';

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // ── Save profile ──────────────────────────────────────────
    if ($action === 'save_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        $bio       = trim($_POST['bio']        ?? '');

        if (!$firstName || !$lastName) {
            $_SESSION['flash_error'] = 'First and last name are required.';
            redirect('/app/settings.php?section=profile');
        }

        $avatarUrl = $data['avatar_url'];
        if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $maxBytes  = 2097152;
            $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if ($_FILES['avatar']['size'] <= $maxBytes) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $ext  = match($mime) {'image/png' => '.png','image/webp' => '.webp', default => '.jpg'};
                    $name = generateUuidV4() . $ext;
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $name)) {
                        $avatarUrl = '/uploads/avatars/' . $name;
                    }
                } else {
                    $_SESSION['flash_error'] = 'Avatar must be JPG, PNG, or WebP.';
                    redirect('/app/settings.php?section=profile');
                }
            } else {
                $_SESSION['flash_error'] = 'Avatar must be under 2MB.';
                redirect('/app/settings.php?section=profile');
            }
        }

        $pdo->prepare("
            UPDATE profiles
            SET first_name=?, last_name=?, phone=?, bio=?, avatar_url=?, updated_at=NOW()
            WHERE user_id=?
        ")->execute([$firstName, $lastName, $phone ?: null, $bio ?: null, $avatarUrl, $userId]);

        $_SESSION['flash_ok'] = 'Profile updated.';
        redirect('/app/settings.php?section=profile');
    }

    // ── Save desk branding ────────────────────────────────────
    if ($action === 'save_desk') {
        $displayName   = trim($_POST['display_name'] ?? '');
        $tagline       = trim($_POST['tagline']      ?? '');
        $primaryColour = trim($_POST['primary_colour'] ?? '#0f4c9e');

        if (!$displayName) {
            $_SESSION['flash_error'] = 'Desk name is required.';
            redirect('/app/settings.php?section=desk');
        }

        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primaryColour)) {
            $primaryColour = '#0f4c9e';
        }

        // Handle desk logo upload
        $deskLogo = $data['desk_logo'];
        if (!empty($_FILES['desk_logo']['tmp_name']) && $_FILES['desk_logo']['error'] === UPLOAD_ERR_OK) {
            $maxBytes  = 2097152;
            $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if ($_FILES['desk_logo']['size'] <= $maxBytes) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['desk_logo']['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $ext  = match($mime) {'image/png' => '.png','image/webp' => '.webp', default => '.jpg'};
                    $name = generateUuidV4() . $ext;
                    if (move_uploaded_file($_FILES['desk_logo']['tmp_name'], $uploadDir . $name)) {
                        $deskLogo = '/uploads/logos/' . $name;
                    }
                }
            }
        }

        $pdo->prepare("
            UPDATE salesdesks
            SET display_name=?, tagline=?, primary_colour=?, logo_url=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$displayName, $tagline ?: null, $primaryColour, $deskLogo, $deskId]);

        $_SESSION['flash_ok'] = 'SalesDesk branding updated.';
        redirect('/app/settings.php?section=desk');
    }

    // ── Rename slug (D-02) ────────────────────────────────────
    if ($action === 'rename_slug') {
        $newSlug = strtolower(trim($_POST['new_slug'] ?? ''));

        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,59}$/', $newSlug)) {
            $_SESSION['flash_error'] = 'Slug must be 2–60 characters: lowercase letters, numbers, and hyphens only.';
            redirect('/app/settings.php?section=desk');
        }

        $reserved = ['auth','app','api','admin','assets','uploads','public','c','broker','dealer','exec','org','help','about','privacy','terms','support','contact','login','register'];
        if (in_array($newSlug, $reserved, true)) {
            $_SESSION['flash_error'] = 'That slug is reserved. Please choose another.';
            redirect('/app/settings.php?section=desk');
        }

        // Check availability (excluding self)
        $check = $pdo->prepare("SELECT id FROM salesdesks WHERE slug=? AND user_id!=? LIMIT 1");
        $check->execute([$newSlug, $userId]);
        if ($check->fetch()) {
            $_SESSION['flash_error'] = 'That slug is already taken. Please try a different one.';
            redirect('/app/settings.php?section=desk');
        }

        $oldSlug = $data['slug'];
        $pdo->prepare("UPDATE salesdesks SET slug=?, updated_at=NOW() WHERE id=?")->execute([$newSlug, $deskId]);

        writeAuditLog('salesdesk.slug_renamed', 'salesdesk', $deskId,
            ['slug' => $oldSlug], ['slug' => $newSlug]);

        $_SESSION['flash_ok'] = 'Your SalesDesk URL has been updated to: salesdesk.co.za/' . $newSlug . '/';
        redirect('/app/settings.php?section=desk');
    }
}

$displayName  = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
$initials     = strtoupper(substr($data['first_name'] ?? 'B', 0, 1) . substr($data['last_name'] ?? '', 0, 1));

$pageTitle = 'Account Settings';
ob_start();
?>

<div style="display:grid;grid-template-columns:200px 1fr;gap:1.5rem;align-items:start;">

  <!-- ── Sidebar nav ─────────────────────────────────────── -->
  <div class="card card-body" style="padding:10px;">
    <nav class="settings-nav">
      <?php
      $navItems = [
        'profile' => ['fa-user',       'My Profile'],
        'desk'    => ['fa-id-card',    'SalesDesk'],
      ];
      foreach ($navItems as $key => [$icon, $label]):
      ?>
      <a href="?section=<?= $key ?>"
         class="settings-nav-link <?= $section === $key ? 'active' : '' ?>">
        <span class="snl-icon"><i class="fa-solid <?= $icon ?>"></i></span>
        <?= $label ?>
      </a>
      <?php endforeach; ?>
      <div style="border-top:1px solid var(--border);margin:8px 0;"></div>
      <a href="/auth/reset_password.php" class="settings-nav-link">
        <span class="snl-icon"><i class="fa-solid fa-key"></i></span>
        Password
      </a>
      <a href="/auth/logout.php" class="settings-nav-link" style="color:var(--red);">
        <span class="snl-icon"><i class="fa-solid fa-right-from-bracket"></i></span>
        Sign out
      </a>
    </nav>
  </div>

  <!-- ── Content ─────────────────────────────────────────── -->
  <div>
    <?php if ($flash): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem">
      <i class="fa-solid fa-circle-check alert-icon"></i> <?= htmlspecialchars($flash) ?>
    </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
      <i class="fa-solid fa-circle-exclamation alert-icon"></i> <?= htmlspecialchars($flashError) ?>
    </div>
    <?php endif; ?>

    <?php if ($section === 'profile'): ?>
    <!-- ══════════════════════════════
         MY PROFILE
         ══════════════════════════════ -->
    <div class="card card-body">
      <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
        My <em style="font-style:italic;">profile</em>
      </h2>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="save_profile">

        <!-- Avatar -->
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:1.5rem;
                    padding-bottom:1.5rem;border-bottom:1px solid var(--border);">
          <div style="width:72px;height:72px;border-radius:50%;flex-shrink:0;
                      background:var(--p-light);color:var(--p);
                      display:flex;align-items:center;justify-content:center;
                      font-size:22px;font-weight:700;font-family:var(--mono);
                      overflow:hidden;border:2px solid var(--p-b);" id="avatarPreview">
            <?php if ($data['avatar_url']): ?>
            <img src="<?= htmlspecialchars($data['avatar_url']) ?>" alt="Avatar"
                 style="width:100%;height:100%;object-fit:cover;" id="avatarImg">
            <?php else: ?>
            <span id="avatarInitials"><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
          </div>
          <div>
            <label for="avatar" class="btn btn-ghost btn-sm" style="cursor:pointer;display:inline-flex;">
              <i class="fa-solid fa-camera"></i> Change photo
            </label>
            <input type="file" id="avatar" name="avatar"
                   accept="image/jpeg,image/png,image/webp"
                   style="display:none;" onchange="previewAvatar(this)">
            <div style="font-size:11px;color:var(--faint);margin-top:5px;">
              JPG, PNG, WebP · max 2MB · Shown on your public car pages
            </div>
          </div>
        </div>

        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="first_name">First name *</label>
            <input class="finput" type="text" id="first_name" name="first_name"
                   required maxlength="60" autocomplete="given-name"
                   value="<?= htmlspecialchars($data['first_name'] ?? '') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="last_name">Last name *</label>
            <input class="finput" type="text" id="last_name" name="last_name"
                   required maxlength="60" autocomplete="family-name"
                   value="<?= htmlspecialchars($data['last_name'] ?? '') ?>">
          </div>
        </div>

        <div class="fgroup">
          <label class="flabel" for="phone">Mobile number</label>
          <input class="finput" type="tel" id="phone" name="phone"
                 maxlength="20" autocomplete="tel" placeholder="082 000 0000"
                 value="<?= htmlspecialchars($data['phone'] ?? '') ?>">
        </div>

        <div class="fgroup">
          <label class="flabel" for="bio">
            Bio <span class="flabel-opt">(shown on your public desk page)</span>
          </label>
          <textarea class="finput" id="bio" name="bio" maxlength="400" rows="3"
                    placeholder="e.g. Passionate about connecting buyers with their perfect car…"><?= htmlspecialchars($data['bio'] ?? '') ?></textarea>
        </div>

        <div class="fgroup">
          <label class="flabel">Email address</label>
          <div style="padding:10px 13px;border:1px solid var(--border);border-radius:var(--r-md);
                      font-size:14px;color:var(--muted);background:var(--bg);font-family:var(--mono);">
            <?= htmlspecialchars($data['email']) ?>
          </div>
          <div style="font-size:11px;color:var(--faint);margin-top:4px;">
            Contact support to change your email address.
          </div>
        </div>

        <button class="btn btn-primary" type="submit">
          <i class="fa-solid fa-check"></i> Save changes
        </button>
      </form>
    </div>

    <?php elseif ($section === 'desk'): ?>
    <!-- ══════════════════════════════
         SALESDESK BRANDING
         ══════════════════════════════ -->
    <div class="card card-body" style="margin-bottom:1rem;">
      <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
        SalesDesk <em style="font-style:italic;">branding</em>
      </h2>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="save_desk">

        <!-- Logo -->
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:1.5rem;
                    padding-bottom:1.5rem;border-bottom:1px solid var(--border);">
          <div style="width:72px;height:72px;border-radius:var(--r-lg);flex-shrink:0;
                      background:<?= htmlspecialchars($data['primary_colour'] ?? '#0f4c9e') ?>14;
                      display:flex;align-items:center;justify-content:center;
                      font-size:28px;overflow:hidden;border:1px solid var(--border);"
               id="logoPreview">
            <?php if ($data['desk_logo']): ?>
            <img src="<?= htmlspecialchars($data['desk_logo']) ?>" alt="Logo"
                 style="width:100%;height:100%;object-fit:cover;" id="logoImg">
            <?php else: ?>
            <i class="fa-solid fa-id-card" style="color:<?= htmlspecialchars($data['primary_colour'] ?? '#0f4c9e') ?>"></i>
            <?php endif; ?>
          </div>
          <div>
            <label for="desk_logo" class="btn btn-ghost btn-sm" style="cursor:pointer;display:inline-flex;">
              <i class="fa-solid fa-image"></i> Upload logo
            </label>
            <input type="file" id="desk_logo" name="desk_logo"
                   accept="image/jpeg,image/png,image/webp"
                   style="display:none;" onchange="previewLogo(this)">
            <div style="font-size:11px;color:var(--faint);margin-top:5px;">
              JPG, PNG, WebP · max 2MB
            </div>
          </div>
        </div>

        <div class="fgroup">
          <label class="flabel" for="display_name">Desk display name *</label>
          <input class="finput" type="text" id="display_name" name="display_name"
                 required maxlength="120"
                 value="<?= htmlspecialchars($data['display_name'] ?? '') ?>">
        </div>

        <div class="fgroup">
          <label class="flabel" for="tagline">
            Tagline <span class="flabel-opt">(shown on your public page)</span>
          </label>
          <input class="finput" type="text" id="tagline" name="tagline"
                 maxlength="255" placeholder="e.g. Your trusted Gauteng car broker"
                 value="<?= htmlspecialchars($data['tagline'] ?? '') ?>">
        </div>

        <div class="fgroup">
          <label class="flabel" for="primary_colour">Brand colour</label>
          <div style="display:flex;align-items:center;gap:10px;">
            <input type="color" id="primary_colour" name="primary_colour"
                   value="<?= htmlspecialchars($data['primary_colour'] ?? '#0f4c9e') ?>"
                   style="width:44px;height:44px;border-radius:var(--r-md);
                          border:1px solid var(--border);padding:2px;cursor:pointer;
                          background:var(--bg);"
                   oninput="document.getElementById('colourHex').textContent=this.value">
            <span id="colourHex" style="font-family:var(--mono);font-size:13px;color:var(--muted);">
              <?= htmlspecialchars($data['primary_colour'] ?? '#0f4c9e') ?>
            </span>
            <span style="font-size:12px;color:var(--faint);">Used on your public car pages and share links</span>
          </div>
        </div>

        <button class="btn btn-primary" type="submit">
          <i class="fa-solid fa-check"></i> Save branding
        </button>
      </form>
    </div>

    <!-- Slug rename (D-02) -->
    <div class="card card-body">
      <h3 style="font-size:14px;font-weight:600;margin-bottom:4px;">Your SalesDesk URL</h3>
      <p style="font-size:13px;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
        Your current public URL is
        <a href="/<?= htmlspecialchars($data['slug']) ?>/"
           target="_blank" style="font-family:var(--mono);font-size:12px;">
          salesdesk.co.za/<?= htmlspecialchars($data['slug']) ?>/
        </a>.
        You can rename it once — anyone using your old links will need the new URL.
      </p>

      <form method="POST" id="slugForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="rename_slug">

        <div class="fgroup">
          <label class="flabel" for="new_slug">New slug</label>
          <div style="display:flex;align-items:center;gap:0;">
            <span style="padding:10px 13px;background:var(--bg);border:1px solid var(--border);
                         border-right:none;border-radius:var(--r-md) 0 0 var(--r-md);
                         font-size:12px;color:var(--faint);white-space:nowrap;">
              salesdesk.co.za/
            </span>
            <input class="finput" type="text" id="new_slug" name="new_slug"
                   maxlength="60" placeholder="your-desk-name"
                   style="border-radius:0 var(--r-md) var(--r-md) 0;"
                   oninput="slugCheck(this.value)"
                   value="">
          </div>
          <p id="slugHint" style="font-size:12px;margin-top:6px;min-height:18px;color:var(--faint);">
            2–60 characters · lowercase letters, numbers, and hyphens only
          </p>
        </div>

        <div class="alert alert-warn" style="margin-bottom:1rem;">
          <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
          <div>
            <strong>This can only be done once.</strong>
            Old links will stop working. Share the new URL with anyone who has your current link.
          </div>
        </div>

        <button class="btn btn-danger" type="submit" id="slugSubmit" disabled>
          <i class="fa-solid fa-link"></i> Rename my SalesDesk URL
        </button>
      </form>
    </div>

    <?php endif; ?>
  </div>
</div>

<script>
function previewAvatar(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var preview  = document.getElementById('avatarPreview');
    var initials = document.getElementById('avatarInitials');
    var img      = document.getElementById('avatarImg');
    if (initials) initials.style.display = 'none';
    if (!img) {
      img = document.createElement('img');
      img.id = 'avatarImg';
      img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
      preview.appendChild(img);
    }
    img.src = e.target.result;
    img.style.display = 'block';
  };
  reader.readAsDataURL(input.files[0]);
}

function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var preview = document.getElementById('logoPreview');
    var img     = document.getElementById('logoImg');
    preview.innerHTML = '';
    img = document.createElement('img');
    img.id = 'logoImg';
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
    img.src = e.target.result;
    preview.appendChild(img);
  };
  reader.readAsDataURL(input.files[0]);
}

var slugTimer;
function slugCheck(val) {
  var hint   = document.getElementById('slugHint');
  var btn    = document.getElementById('slugSubmit');
  val = val.toLowerCase().replace(/[^a-z0-9-]/g, '');
  document.getElementById('new_slug').value = val;
  btn.disabled = true;

  if (!val || val.length < 2) {
    hint.textContent = '2–60 characters · lowercase letters, numbers, and hyphens only';
    hint.style.color = 'var(--faint)';
    return;
  }

  hint.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin" style="font-size:10px;margin-right:4px;"></i>Checking availability…';
  hint.style.color = 'var(--faint)';
  clearTimeout(slugTimer);
  slugTimer = setTimeout(function() {
    fetch('/api/salesdesks/check-slug.php?slug=' + encodeURIComponent(val))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d.available) {
          hint.innerHTML = '<i class="fa-solid fa-circle-check" style="font-size:10px;color:var(--green);margin-right:4px;"></i>'
            + '<span style="color:var(--green)">salesdesk.co.za/' + val + '/ is available</span>';
          btn.disabled = false;
        } else {
          hint.innerHTML = '<i class="fa-solid fa-circle-xmark" style="font-size:10px;color:var(--red);margin-right:4px;"></i>'
            + '<span style="color:var(--red)">That slug is taken</span>';
          btn.disabled = true;
        }
      })
      .catch(function() {
        hint.textContent = 'Could not check — please try again.';
        btn.disabled = false;
      });
  }, 350);
}

// Confirm slug rename
var slugForm = document.getElementById('slugForm');
if (slugForm) {
  slugForm.addEventListener('submit', function(e) {
    var slug = document.getElementById('new_slug').value.trim();
    if (!confirm('Rename your SalesDesk URL to:\nsalesdesk.co.za/' + slug + '/\n\nThis cannot be undone.')) {
      e.preventDefault();
    }
  });
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-app.php';
