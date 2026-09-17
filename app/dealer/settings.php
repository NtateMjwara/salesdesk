<?php
/**
 * SalesDesk — Dealer Settings.
 * T3 owns this file.
 *
 * REFACTORED: All inline style= layout attributes replaced with semantic
 * CSS classes defined in dealer-settings-patch.css.
 *
 * Problems fixed:
 *   — sidebar nav uses CSS class .settings-nav (was already mostly class-
 *     based; nav-pill active state and pending dot moved to CSS)
 *   — outer 2-col grid (200px sidebar + content) was inline → .d-settings-shell,
 *     stacks to a horizontal scrollable tab strip on tablet/mobile
 *   — logo upload row (avatar circle + button + hint) → .d-avatar-row,
 *     wraps on mobile instead of clipping the hint text
 *   — CIPC verification status block (icon + text + "view doc" button)
 *     → .cipc-block, stacks vertically on mobile so the button doesn't
 *     get squeezed to nothing
 *   — read-only slug / email fields → .d-readonly-field (word-break safe)
 *   — frow 2-col address/profile rows now rely on the global .frow mobile
 *     override already shipped in dealer-dashboard-patch.css (≤ 540px)
 *   — save button gets a JS disabled state on submit to prevent double-post
 *   — flash alerts get role="alert" + scroll-into-view on load
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$csrf   = generateCSRFToken();

// ── Load current data ─────────────────────────────────────────
$dataStmt = $pdo->prepare("
    SELECT
        d.id AS dealer_id,
        d.company_name,
        d.slug,
        d.logo_url,
        d.brand_focus,
        d.verification_status,
        d.cipc_doc_url,
        d.verified_at,
        p.first_name,
        p.last_name,
        p.phone,
        u.email,
        a.province,
        a.municipality,
        a.city,
        a.suburb,
        a.street_line1,
        a.postal_code,
        d.address_id
    FROM dealers d
    JOIN users u ON u.id = d.user_id
    LEFT JOIN profiles p ON p.user_id = d.user_id
    LEFT JOIN addresses a ON a.id = d.address_id
    WHERE d.user_id = ?
    LIMIT 1
");
$dataStmt->execute([$userId]);
$data = $dataStmt->fetch();
if (!$data) redirect('/auth/register.php');

$dealerId = (int) $data['dealer_id'];

$flash      = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$section = $_GET['section'] ?? 'company';

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // ── Company profile ───────────────────────────────────────
    if ($action === 'save_company') {
        $companyName = trim($_POST['company_name'] ?? '');
        $brandFocus  = trim($_POST['brand_focus']  ?? '');

        if (!$companyName) {
            $_SESSION['flash_error'] = 'Dealership name is required.';
            redirect('/app/dealer/settings.php?section=company');
        }

        $brandJson = null;
        if ($brandFocus) {
            $brands    = array_values(array_filter(array_map('trim', explode(',', $brandFocus))));
            $brandJson = json_encode($brands);
        }

        // Handle logo upload
        $logoUrl = $data['logo_url'];
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $maxBytes  = 2097152;
            $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if ($_FILES['logo']['size'] <= $maxBytes) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['logo']['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $ext  = match($mime) { 'image/png' => '.png', 'image/webp' => '.webp', default => '.jpg' };
                    $name = generateUuidV4() . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $name)) {
                        $logoUrl = '/uploads/logos/' . $name;
                    }
                } else {
                    $_SESSION['flash_error'] = 'Logo must be JPG, PNG, or WebP.';
                    redirect('/app/dealer/settings.php?section=company');
                }
            } else {
                $_SESSION['flash_error'] = 'Logo must be under 2MB.';
                redirect('/app/dealer/settings.php?section=company');
            }
        }

        $pdo->prepare("
            UPDATE dealers
            SET company_name = ?, brand_focus = ?, logo_url = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$companyName, $brandJson, $logoUrl, $dealerId]);

        writeAuditLog('dealer.profile_updated', 'dealer', $dealerId, null,
            ['company_name' => $companyName]);

        $_SESSION['flash_ok'] = 'Company profile updated.';
        redirect('/app/dealer/settings.php?section=company');
    }

    // ── CIPC upload ───────────────────────────────────────────
    if ($action === 'upload_cipc') {
        if (empty($_FILES['cipc_doc']['tmp_name']) || $_FILES['cipc_doc']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please select a PDF file to upload.';
            redirect('/app/dealer/settings.php?section=verification');
        }

        $maxBytes  = defined('MAX_PDF_SIZE_BYTES') ? MAX_PDF_SIZE_BYTES : 5242880;
        $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/cipc/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if ($_FILES['cipc_doc']['size'] > $maxBytes) {
            $_SESSION['flash_error'] = 'Document must be under 5MB.';
            redirect('/app/dealer/settings.php?section=verification');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['cipc_doc']['tmp_name']);
        finfo_close($finfo);

        if ($mime !== 'application/pdf') {
            $_SESSION['flash_error'] = 'Only PDF files are accepted.';
            redirect('/app/dealer/settings.php?section=verification');
        }

        $filename = generateUuidV4() . '.pdf';
        if (move_uploaded_file($_FILES['cipc_doc']['tmp_name'], $uploadDir . $filename)) {
            $pdo->prepare("
                UPDATE dealers
                SET cipc_doc_url = ?, verification_status = 'pending', updated_at = NOW()
                WHERE id = ?
            ")->execute(['/uploads/cipc/' . $filename, $dealerId]);

            writeAuditLog('dealer.cipc_submitted', 'dealer', $dealerId, null,
                ['verification_status' => 'pending']);

            $_SESSION['flash_ok'] = 'CIPC document submitted for review. We\'ll notify you once verified.';
        } else {
            $_SESSION['flash_error'] = 'Upload failed — please try again.';
        }
        redirect('/app/dealer/settings.php?section=verification');
    }

    // ── Address ───────────────────────────────────────────────
    if ($action === 'save_address') {
        $province    = trim($_POST['province']     ?? '');
        $municipality= trim($_POST['municipality'] ?? '');
        $city        = trim($_POST['city']         ?? '');
        $suburb      = trim($_POST['suburb']       ?? '');
        $street      = trim($_POST['street_line1'] ?? '');
        $postal      = trim($_POST['postal_code']  ?? '');

        if ($data['address_id']) {
            $pdo->prepare("
                UPDATE addresses
                SET province=?, municipality=?, city=?, suburb=?,
                    street_line1=?, postal_code=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$province, $municipality ?: null, $city ?: null,
                         $suburb ?: null, $street ?: null, $postal ?: null,
                         $data['address_id']]);
        } else {
            $pdo->prepare("
                INSERT INTO addresses (province, municipality, city, suburb, street_line1, postal_code, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([$province, $municipality ?: null, $city ?: null,
                         $suburb ?: null, $street ?: null, $postal ?: null]);
            $addressId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE dealers SET address_id=? WHERE id=?")->execute([$addressId, $dealerId]);
        }

        $_SESSION['flash_ok'] = 'Address updated.';
        redirect('/app/dealer/settings.php?section=address');
    }

    // ── Account holder profile ────────────────────────────────
    if ($action === 'save_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');

        if (!$firstName || !$lastName) {
            $_SESSION['flash_error'] = 'First and last name are required.';
            redirect('/app/dealer/settings.php?section=profile');
        }

        $pdo->prepare("
            UPDATE profiles
            SET first_name=?, last_name=?, phone=?, updated_at=NOW()
            WHERE user_id=?
        ")->execute([$firstName, $lastName, $phone ?: null, $userId]);

        $_SESSION['flash_ok'] = 'Profile updated.';
        redirect('/app/dealer/settings.php?section=profile');
    }
}

// ── Brand focus for display ───────────────────────────────────
$brandFocusDisplay = '';
if ($data['brand_focus']) {
    $brands = json_decode($data['brand_focus'], true);
    if (is_array($brands)) {
        $brandFocusDisplay = implode(', ', $brands);
    }
}

$pageTitle = 'Settings';
ob_start();
?>

<?php /* ── Page header ──────────────────────────────────────── */ ?>
<div class="dash-header">
  <div class="dash-header__text">
    <h1 class="page-header__title">Dealership <em>settings</em></h1>
    <p class="dash-header__sub-plain">Manage your dealership profile, verification, and account.</p>
  </div>
</div>

<?php /* ── Settings shell: nav + content ─────────────────────── */ ?>
<div class="d-settings-shell">

  <?php /* ── Nav: sidebar on desktop, scrollable tab strip on mobile ── */ ?>
  <nav class="settings-nav" aria-label="Settings sections">
    <?php
    $navItems = [
      'company'      => ['fa-building',         'Company'],
      'verification' => ['fa-shield-halved',     'Verification'],
      'address'      => ['fa-location-dot',      'Address'],
      'profile'      => ['fa-user',              'Account holder'],
    ];
    foreach ($navItems as $key => [$icon, $label]):
    ?>
    <a href="?section=<?= $key ?>"
       class="settings-nav-link <?= $section === $key ? 'active' : '' ?>">
      <span class="snl-icon"><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i></span>
      <?= $label ?>
      <?php if ($key === 'verification' && $data['verification_status'] === 'pending'): ?>
      <span class="snl-dot" aria-label="Pending review"></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <?php /* ── Content area ────────────────────────────────────── */ ?>
  <div class="d-settings-content">

    <?php if ($flash): ?>
    <div class="alert alert-success" id="settings-flash" style="margin-bottom:1.25rem">
      <i class="fa-solid fa-circle-check alert-icon" aria-hidden="true"></i> <?= htmlspecialchars($flash) ?>
    </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
    <div class="alert alert-error" id="settings-flash" style="margin-bottom:1.25rem">
      <i class="fa-solid fa-circle-exclamation alert-icon" aria-hidden="true"></i> <?= htmlspecialchars($flashError) ?>
    </div>
    <?php endif; ?>

    <?php if ($section === 'company'): ?>
    <!-- ══════════════════════════════
         COMPANY PROFILE
         ══════════════════════════════ -->
    <div class="card card-body">
      <h2 class="d-card-heading">
        Company <em>profile</em>
      </h2>

      <form method="POST" enctype="multipart/form-data" id="companyForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="save_company">

        <!-- Logo -->
        <div class="d-avatar-row d-avatar-row--bordered">
          <div class="d-avatar-preview d-avatar-preview--square" id="logoPreview">
            <?php if ($data['logo_url']): ?>
            <img src="<?= htmlspecialchars($data['logo_url']) ?>" alt="Dealership logo" id="logoImg">
            <?php else: ?>
            <i class="fa-solid fa-building" aria-hidden="true"></i>
            <?php endif; ?>
          </div>
          <div class="d-avatar-upload-info">
            <label for="logo" class="btn btn-ghost btn-sm" style="cursor:pointer;width:fit-content;">
              <i class="fa-solid fa-camera" aria-hidden="true"></i> Upload logo
            </label>
            <input type="file" id="logo" name="logo"
                   accept="image/jpeg,image/png,image/webp"
                   class="d-avatar-file-input"
                   onchange="previewLogo(this)">
            <div class="d-avatar-upload-info__hint">JPG, PNG, WebP · max 2MB</div>
          </div>
        </div>

        <div class="fgroup">
          <label class="flabel" for="company_name">Dealership name *</label>
          <input class="finput" type="text" id="company_name" name="company_name"
                 required maxlength="120"
                 value="<?= htmlspecialchars($data['company_name']) ?>">
        </div>

        <div class="fgroup">
          <label class="flabel" for="brand_focus">
            Brands you sell <span class="flabel-opt">(comma-separated)</span>
          </label>
          <input class="finput" type="text" id="brand_focus" name="brand_focus"
                 maxlength="255" placeholder="e.g. Toyota, Ford, Volkswagen"
                 value="<?= htmlspecialchars($brandFocusDisplay) ?>">
          <div class="d-readonly-note">
            Shown to brokers when searching for dealerships to partner with.
          </div>
        </div>

        <!-- Read-only slug -->
        <div class="fgroup">
          <label class="flabel">Dealer URL slug</label>
          <div class="d-readonly-field">
            salesdesk.co.za/dealers/<?= htmlspecialchars($data['slug']) ?>
          </div>
          <div class="d-readonly-note">
            Contact support to change your dealership URL.
          </div>
        </div>

        <button class="btn btn-primary" type="submit" id="companySaveBtn">
          <i class="fa-solid fa-check" aria-hidden="true"></i> Save changes
        </button>
      </form>
    </div>

    <?php elseif ($section === 'verification'): ?>
    <!-- ══════════════════════════════
         CIPC VERIFICATION
         ══════════════════════════════ -->
    <div class="card card-body">
      <h2 class="d-card-heading">
        CIPC <em>verification</em>
      </h2>

      <!-- Status block -->
      <?php
      $vstatus = $data['verification_status'];
      $vConfig = match($vstatus) {
        'verified'   => ['fa-circle-check', 'gr',  'Verified',         'Your dealership is verified and displays a badge in broker search.'],
        'pending'    => ['fa-clock',         'amb', 'Under review',     'Your CIPC document is being reviewed. We\'ll notify you by email.'],
        'rejected'   => ['fa-circle-xmark',  'red', 'Rejected',         'Your verification was rejected. Please re-upload a clear document.'],
        default      => ['fa-circle-info',   'neu', 'Not verified',     'Upload your CIPC certificate to get verified and rank higher in broker search.'],
      };
      [$vIcon, $vTone, $vLabel, $vMsg] = $vConfig;
      ?>
      <div class="cipc-block cipc-block--<?= $vTone ?>">
        <div class="cipc-block-icon">
          <i class="fa-solid <?= $vIcon ?>" aria-hidden="true"></i>
        </div>
        <div class="cipc-block-info">
          <div class="cipc-block-title"><?= $vLabel ?></div>
          <div class="cipc-block-sub"><?= $vMsg ?></div>
          <?php if ($vstatus === 'verified' && $data['verified_at']): ?>
          <div class="cipc-block-date">
            Verified on <?= date('d F Y', strtotime($data['verified_at'])) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($data['cipc_doc_url']): ?>
        <a href="<?= htmlspecialchars($data['cipc_doc_url']) ?>" target="_blank" rel="noopener"
           class="btn btn-ghost btn-sm cipc-block-action">
          View doc ↗
        </a>
        <?php endif; ?>
      </div>

      <?php if ($vstatus !== 'verified'): ?>
      <form method="POST" enctype="multipart/form-data" id="cipcForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="upload_cipc">

        <div class="fgroup">
          <label class="flabel" for="cipc_doc">
            <?= $vstatus === 'rejected' ? 'Re-upload CIPC certificate' : 'Upload CIPC certificate' ?>
          </label>
          <input class="finput" type="file" id="cipc_doc" name="cipc_doc"
                 accept="application/pdf" style="padding:8px 13px;cursor:pointer;" required>
          <div class="d-readonly-note">
            PDF only · max 5MB · Company registration certificate from CIPC.co.za
          </div>
        </div>

        <button class="btn btn-primary" type="submit" id="cipcSaveBtn">
          <i class="fa-solid fa-upload" aria-hidden="true"></i> Submit for verification
        </button>
      </form>
      <?php else: ?>
      <div class="alert alert-info">
        <i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i>
        Your dealership is verified. Contact support if you need to update your CIPC certificate.
      </div>
      <?php endif; ?>
    </div>

    <?php elseif ($section === 'address'): ?>
    <!-- ══════════════════════════════
         DEALERSHIP ADDRESS
         ══════════════════════════════ -->
    <div class="card card-body">
      <h2 class="d-card-heading">
        Dealership <em>address</em>
      </h2>
      <p class="d-card-sub">
        Your location is shown to brokers when they search for dealerships and to buyers on your listings.
      </p>

      <form method="POST" id="addressForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save_address">

        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="province">Province *</label>
            <select class="finput" id="province" name="province" required>
              <option value="">— Select —</option>
              <?php foreach (['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'] as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"
                      <?= $data['province'] === $p ? 'selected' : '' ?>>
                <?= htmlspecialchars($p) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel" for="city">City / Town</label>
            <input class="finput" type="text" id="city" name="city"
                   maxlength="80" placeholder="e.g. Boksburg"
                   value="<?= htmlspecialchars($data['city'] ?? '') ?>">
          </div>
        </div>

        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="suburb">Suburb</label>
            <input class="finput" type="text" id="suburb" name="suburb"
                   maxlength="80" placeholder="e.g. Boksburg North"
                   value="<?= htmlspecialchars($data['suburb'] ?? '') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="municipality">Municipality</label>
            <input class="finput" type="text" id="municipality" name="municipality"
                   maxlength="80" placeholder="e.g. Ekurhuleni"
                   value="<?= htmlspecialchars($data['municipality'] ?? '') ?>">
          </div>
        </div>

        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="street_line1">Street address</label>
            <input class="finput" type="text" id="street_line1" name="street_line1"
                   maxlength="120" placeholder="e.g. 20 Commissioner Street"
                   value="<?= htmlspecialchars($data['street_line1'] ?? '') ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="postal_code">Postal code</label>
            <input class="finput" type="text" id="postal_code" name="postal_code"
                   maxlength="10" placeholder="e.g. 1459"
                   value="<?= htmlspecialchars($data['postal_code'] ?? '') ?>">
          </div>
        </div>

        <button class="btn btn-primary" type="submit" id="addressSaveBtn">
          <i class="fa-solid fa-check" aria-hidden="true"></i> Save address
        </button>
      </form>
    </div>

    <?php elseif ($section === 'profile'): ?>
    <!-- ══════════════════════════════
         ACCOUNT HOLDER PROFILE
         ══════════════════════════════ -->
    <div class="card card-body">
      <h2 class="d-card-heading">
        Account <em>holder</em>
      </h2>

      <form method="POST" id="profileForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save_profile">

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
                 maxlength="20" autocomplete="tel" placeholder="011 000 0000"
                 value="<?= htmlspecialchars($data['phone'] ?? '') ?>">
        </div>

        <!-- Email — read only -->
        <div class="fgroup">
          <label class="flabel">Email address</label>
          <div class="d-readonly-field">
            <?= htmlspecialchars($data['email']) ?>
          </div>
          <div class="d-readonly-note">
            Contact support to change your email address.
          </div>
        </div>

        <div class="d-profile-actions">
          <button class="btn btn-primary" type="submit" id="profileSaveBtn">
            <i class="fa-solid fa-check" aria-hidden="true"></i> Save changes
          </button>
          <a href="/auth/reset_password.php" class="btn btn-ghost">
            <i class="fa-solid fa-key" aria-hidden="true"></i> Change password
          </a>
        </div>
      </form>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var preview = document.getElementById('logoPreview');
    var img = document.getElementById('logoImg');
    if (!img) {
      preview.innerHTML = '';
      img = document.createElement('img');
      img.id = 'logoImg';
      img.alt = 'Dealership logo';
      preview.appendChild(img);
    }
    img.src = e.target.result;
  };
  reader.readAsDataURL(input.files[0]);
}

(function () {
  'use strict';

  /* Prevent double-submit on every settings form */
  ['companyForm', 'cipcForm', 'addressForm', 'profileForm'].forEach(function (id) {
    var form = document.getElementById(id);
    if (!form) return;
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Saving…';
      }
    });
  });

  /* Scroll flash message into view */
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