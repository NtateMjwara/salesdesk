<?php
/**
 * SalesDesk — Organisation Settings.
 * T4 owns this file. Route: /app/broker/org-settings.php
 *
 * Accessible only to org owner/admin.
 * Sections:
 *   - General (name, description, logo)
 *   - CIPC verification
 *   - Address
 *   - Danger zone (leave / delete org — owner only)
 */
declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$orgId  = (int) ($_SESSION['org_context'] ?? 0);

if (!$orgId) {
    redirect('/app/broker/dashboard.php');
}

// Verify membership + role
$orgStmt = $pdo->prepare("
    SELECT o.id, o.name, o.slug, o.cipc_number, o.verification_status,
           o.logo_url, o.is_active, o.created_at,
           a.province, a.city, a.suburb, o.address_id,
           om.role AS my_role
    FROM organizations o
    JOIN organization_members om ON om.organization_id = o.id AND om.user_id = ?
    LEFT JOIN addresses a ON a.id = o.address_id
    WHERE o.id = ? AND o.is_active = 1
    LIMIT 1
");
$orgStmt->execute([$userId, $orgId]);
$org = $orgStmt->fetch();

if (!$org || !in_array($org['my_role'], ['owner','admin'], true)) {
    redirect('/app/broker/org-context.php');
}

$isOwner = ($org['my_role'] === 'owner');
$csrf    = generateCSRFToken();
$section = $_GET['section'] ?? 'general';

$flash      = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $name = trim($_POST['org_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (!$name || strlen($name) < 2) {
            $_SESSION['flash_error'] = 'Organisation name must be at least 2 characters.';
            redirect('/app/broker/org-settings.php?section=general');
        }

        // Check name uniqueness (excluding self)
        $nc = $pdo->prepare("SELECT id FROM organizations WHERE name=? AND id!=? LIMIT 1");
        $nc->execute([$name, $orgId]);
        if ($nc->fetch()) {
            $_SESSION['flash_error'] = 'That organisation name is already taken.';
            redirect('/app/broker/org-settings.php?section=general');
        }

        // Logo upload
        $logoUrl = $org['logo_url'];
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $maxBytes  = 2097152;
            $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            if ($_FILES['logo']['size'] <= $maxBytes) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['logo']['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $ext  = match($mime) {'image/png'=>'.png','image/webp'=>'.webp',default=>'.jpg'};
                    $file = generateUuidV4() . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $file)) {
                        $logoUrl = '/uploads/logos/' . $file;
                    }
                }
            }
        }

        $pdo->prepare("
            UPDATE organizations
            SET name=?, logo_url=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$name, $logoUrl, $orgId]);

        writeAuditLog('org.updated', 'organization', $orgId,
            ['name' => $org['name']], ['name' => $name]);

        $_SESSION['flash_ok'] = 'Organisation updated.';
        redirect('/app/broker/org-settings.php?section=general');
    }

    if ($action === 'upload_cipc') {
        if (empty($_FILES['cipc_doc']['tmp_name']) || $_FILES['cipc_doc']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please select a PDF file.';
            redirect('/app/broker/org-settings.php?section=verification');
        }
        $maxBytes  = defined('MAX_PDF_SIZE_BYTES') ? MAX_PDF_SIZE_BYTES : 5242880;
        $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/cipc/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if ($_FILES['cipc_doc']['size'] > $maxBytes) {
            $_SESSION['flash_error'] = 'Document must be under 5MB.';
            redirect('/app/broker/org-settings.php?section=verification');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['cipc_doc']['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') {
            $_SESSION['flash_error'] = 'Only PDF files are accepted.';
            redirect('/app/broker/org-settings.php?section=verification');
        }
        $filename = generateUuidV4() . '.pdf';
        if (move_uploaded_file($_FILES['cipc_doc']['tmp_name'], $uploadDir . $filename)) {
            $pdo->prepare("
                UPDATE organizations
                SET verification_status='pending', updated_at=NOW()
                WHERE id=?
            ")->execute([$orgId]);
            writeAuditLog('org.cipc_submitted', 'organization', $orgId,
                null, ['verification_status' => 'pending']);
            $_SESSION['flash_ok'] = 'CIPC document submitted. We\'ll review it shortly.';
        } else {
            $_SESSION['flash_error'] = 'Upload failed — please try again.';
        }
        redirect('/app/broker/org-settings.php?section=verification');
    }

    if ($action === 'save_cipc_number') {
        $cipcNum = trim($_POST['cipc_number'] ?? '');
        $pdo->prepare("UPDATE organizations SET cipc_number=?, updated_at=NOW() WHERE id=?")
            ->execute([$cipcNum ?: null, $orgId]);
        $_SESSION['flash_ok'] = 'CIPC number saved.';
        redirect('/app/broker/org-settings.php?section=verification');
    }

    if ($action === 'save_address') {
        $province = trim($_POST['province'] ?? '');
        $city     = trim($_POST['city']     ?? '');
        $suburb   = trim($_POST['suburb']   ?? '');

        if ($org['address_id']) {
            $pdo->prepare("
                UPDATE addresses
                SET province=?, city=?, suburb=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$province ?: null, $city ?: null, $suburb ?: null, $org['address_id']]);
        } else {
            $pdo->prepare("
                INSERT INTO addresses (province, city, suburb, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ")->execute([$province ?: null, $city ?: null, $suburb ?: null]);
            $addrId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE organizations SET address_id=? WHERE id=?")->execute([$addrId, $orgId]);
        }
        $_SESSION['flash_ok'] = 'Address updated.';
        redirect('/app/broker/org-settings.php?section=address');
    }

    if ($action === 'leave_org' && !$isOwner) {
        $pdo->prepare("
            DELETE FROM organization_members
            WHERE organization_id=? AND user_id=? AND role!='owner'
        ")->execute([$orgId, $userId]);
        unset($_SESSION['org_context']);
        writeAuditLog('org.member_left', 'organization', $orgId, null, ['user_id' => $userId]);
        $_SESSION['flash_ok'] = 'You have left the organisation.';
        redirect('/app/broker/dashboard.php');
    }
}

$pageTitle = 'Organisation Settings';
ob_start();
?>

<!-- Back link -->
<div style="margin-bottom:1.25rem;">
  <a href="/app/broker/org-context.php"
     style="font-size:13px;color:var(--muted);text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
    <i class="fa-solid fa-arrow-left" style="font-size:11px;"></i>
    Back to <?= htmlspecialchars($org['name']) ?>
  </a>
</div>

<div style="display:grid;grid-template-columns:200px 1fr;gap:1.5rem;align-items:start;">

  <!-- Sidebar -->
  <div class="card card-body" style="padding:10px;">
    <nav class="settings-nav">
      <?php
      $navItems = [
        'general'      => ['fa-building',     'General'],
        'verification' => ['fa-shield-halved','Verification'],
        'address'      => ['fa-location-dot', 'Address'],
        'danger'       => ['fa-triangle-exclamation', 'Danger zone'],
      ];
      foreach ($navItems as $key => [$icon, $label]):
        $isDanger = $key === 'danger';
      ?>
      <a href="?section=<?= $key ?>"
         class="settings-nav-link <?= $section === $key ? 'active' : '' ?>"
         <?= $isDanger ? 'style="color:var(--red);"' : '' ?>>
        <span class="snl-icon"><i class="fa-solid <?= $icon ?>"></i></span>
        <?= $label ?>
        <?php if ($key === 'verification' && $org['verification_status'] === 'pending'): ?>
        <span style="margin-left:auto;width:7px;height:7px;border-radius:50%;
                     background:var(--amber);flex-shrink:0;"></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <!-- Content -->
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

    <?php if ($section === 'general'): ?>
    <div class="card card-body">
      <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
        General <em style="font-style:italic;">settings</em>
      </h2>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="save_general">

        <!-- Logo -->
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:1.5rem;
                    padding-bottom:1.5rem;border-bottom:1px solid var(--border);">
          <div style="width:72px;height:72px;border-radius:var(--r-lg);flex-shrink:0;
                      background:var(--p-light);color:var(--p);
                      display:flex;align-items:center;justify-content:center;
                      font-size:26px;overflow:hidden;border:1px solid var(--p-b);"
               id="logoPreview">
            <?php if ($org['logo_url']): ?>
            <img src="<?= htmlspecialchars($org['logo_url']) ?>" alt=""
                 style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
            <i class="fa-solid fa-building"></i>
            <?php endif; ?>
          </div>
          <div>
            <label for="logo" class="btn btn-ghost btn-sm" style="cursor:pointer;display:inline-flex;">
              <i class="fa-solid fa-image"></i> Upload logo
            </label>
            <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp"
                   style="display:none;" onchange="previewLogo(this)">
            <div style="font-size:11px;color:var(--faint);margin-top:5px;">
              JPG, PNG, WebP · max 2MB
            </div>
          </div>
        </div>

        <div class="fgroup">
          <label class="flabel" for="org_name">Organisation name *</label>
          <input class="finput" type="text" id="org_name" name="org_name"
                 required maxlength="120"
                 value="<?= htmlspecialchars($org['name']) ?>">
        </div>

        <div class="fgroup">
          <label class="flabel">URL slug</label>
          <div style="padding:10px 13px;border:1px solid var(--border);border-radius:var(--r-md);
                      font-size:13px;font-family:var(--mono);color:var(--muted);background:var(--bg);">
            <?= htmlspecialchars($org['slug']) ?>
          </div>
          <div style="font-size:11px;color:var(--faint);margin-top:4px;">
            Contact support to change the slug.
          </div>
        </div>

        <button class="btn btn-primary" type="submit">
          <i class="fa-solid fa-check"></i> Save changes
        </button>
      </form>
    </div>

    <?php elseif ($section === 'verification'): ?>
    <div class="card card-body">
      <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
        CIPC <em style="font-style:italic;">verification</em>
      </h2>

      <?php
      $vs = $org['verification_status'];
      $vc = match($vs) {
        'verified'  => ['fa-circle-check','var(--gr-bg)','var(--green)','var(--gr-b)',  'Verified',     'Your organisation is verified.'],
        'pending'   => ['fa-clock',        'var(--amb-bg)','var(--amber)','var(--amb-b)','Under review', 'Your CIPC document is being reviewed.'],
        'rejected'  => ['fa-circle-xmark', 'var(--red-bg)','var(--red)',  'var(--red-b)', 'Rejected',   'Please re-upload a clear document.'],
        default     => ['fa-circle-info',  'var(--bg)',   'var(--faint)','var(--border)','Unverified',  'Upload your CIPC certificate to get verified.'],
      };
      [$vi,$vbg,$vc2,$vb,$vl,$vm] = $vc;
      ?>
      <div class="cipc-block" style="background:<?= $vbg ?>;border-color:<?= $vb ?>;margin-bottom:1.5rem;">
        <div class="cipc-block-icon" style="background:<?= $vbg ?>;color:<?= $vc2 ?>;">
          <i class="fa-solid <?= $vi ?>"></i>
        </div>
        <div class="cipc-block-info">
          <div class="cipc-block-title" style="color:<?= $vc2 ?>;"><?= $vl ?></div>
          <div class="cipc-block-sub"><?= $vm ?></div>
        </div>
      </div>

      <!-- CIPC number -->
      <form method="POST" style="margin-bottom:1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save_cipc_number">
        <div class="fgroup">
          <label class="flabel" for="cipc_number">CIPC registration number</label>
          <input class="finput" type="text" id="cipc_number" name="cipc_number"
                 maxlength="30" placeholder="e.g. 2024/000000/07"
                 value="<?= htmlspecialchars($org['cipc_number'] ?? '') ?>">
        </div>
        <button class="btn btn-ghost btn-sm" type="submit">Save number</button>
      </form>

      <?php if ($vs !== 'verified'): ?>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="upload_cipc">
        <div class="fgroup">
          <label class="flabel" for="cipc_doc">Upload CIPC certificate (PDF)</label>
          <input class="finput" type="file" id="cipc_doc" name="cipc_doc"
                 accept="application/pdf" required
                 style="padding:8px 13px;cursor:pointer;">
          <div style="font-size:11px;color:var(--faint);margin-top:5px;">PDF only · max 5MB</div>
        </div>
        <button class="btn btn-primary" type="submit">
          <i class="fa-solid fa-upload"></i> Submit for verification
        </button>
      </form>
      <?php endif; ?>
    </div>

    <?php elseif ($section === 'address'): ?>
    <div class="card card-body">
      <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
        Business <em style="font-style:italic;">address</em>
      </h2>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save_address">
        <div class="frow">
          <div class="fgroup">
            <label class="flabel" for="province">Province</label>
            <select class="finput" id="province" name="province">
              <option value="">— Select —</option>
              <?php foreach (['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'] as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"
                      <?= ($org['province'] ?? '') === $p ? 'selected' : '' ?>>
                <?= htmlspecialchars($p) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel" for="city">City / Town</label>
            <input class="finput" type="text" id="city" name="city"
                   maxlength="80" value="<?= htmlspecialchars($org['city'] ?? '') ?>">
          </div>
        </div>
        <div class="fgroup">
          <label class="flabel" for="suburb">Suburb <span class="flabel-opt">(optional)</span></label>
          <input class="finput" type="text" id="suburb" name="suburb"
                 maxlength="80" value="<?= htmlspecialchars($org['suburb'] ?? '') ?>">
        </div>
        <button class="btn btn-primary" type="submit">
          <i class="fa-solid fa-check"></i> Save address
        </button>
      </form>
    </div>

    <?php elseif ($section === 'danger'): ?>
    <div class="card card-body">
      <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;color:var(--red);">
        Danger <em style="font-style:italic;">zone</em>
      </h2>

      <?php if (!$isOwner): ?>
      <div style="background:var(--red-bg);border:1px solid var(--red-b);border-radius:var(--r-md);
                  padding:16px 18px;margin-bottom:1rem;">
        <div style="font-size:14px;font-weight:600;color:var(--red);margin-bottom:5px;">
          Leave organisation
        </div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:1rem;line-height:1.6;">
          You will lose access to the organisation's shared leads and commissions.
          Your personal desk and history are not affected.
        </p>
        <form method="POST"
              onsubmit="return confirm('Leave <?= htmlspecialchars(addslashes($org['name'])) ?>? This cannot be undone.')">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="leave_org">
          <button class="btn btn-danger" type="submit">
            <i class="fa-solid fa-right-from-bracket"></i> Leave organisation
          </button>
        </form>
      </div>
      <?php else: ?>
      <div class="alert alert-info">
        <i class="fa-solid fa-circle-info alert-icon"></i>
        As the owner you cannot leave the organisation. Transfer ownership to another admin first,
        or contact support to dissolve the organisation.
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var p = document.getElementById('logoPreview');
    p.innerHTML = '';
    var img = document.createElement('img');
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
    img.src = e.target.result;
    p.appendChild(img);
  };
  reader.readAsDataURL(input.files[0]);
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
