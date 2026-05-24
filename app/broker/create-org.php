<?php
/**
 * SalesDesk — Create Organisation Wizard.
 * T4 owns this file. Route: /app/broker/create-org.php
 *
 * Task o2: 3-step org creation wizard.
 *   Step 1: Organisation name, CIPC number
 *   Step 2: Business address
 *   Step 3: Logo + description (optional) → create + redirect
 *
 * Task o1: Entry point shown post-onboarding when broker has no org.
 * The broker can dismiss ("I'm a solo broker") or proceed here.
 *
 * On completion:
 *   - Creates organizations row (owner = current user)
 *   - Inserts organization_members row (role = owner)
 *   - Sets $_SESSION['org_context'] = new org id
 *   - Redirects to org-context.php dashboard
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
$csrf   = generateCSRFToken();

// ── Wizard state ──────────────────────────────────────────────
if (empty($_SESSION['org_wz'])) {
    $_SESSION['org_wz'] = [
        'step'        => 1,
        'name'        => '',
        'cipc_number' => '',
        'province'    => '',
        'city'        => '',
        'suburb'      => '',
        'description' => '',
        'logo_url'    => null,
    ];
}
$wz   = &$_SESSION['org_wz'];
$step = (int) ($wz['step'] ?? 1);

$error = $_GET['error'] ?? '';
$info  = $_GET['info']  ?? '';

// ── Handle reset ──────────────────────────────────────────────
if (isset($_GET['cancel'])) {
    unset($_SESSION['org_wz']);
    redirect('/app/broker/dashboard.php');
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'step1') {
        $name       = trim($_POST['org_name']    ?? '');
        $cipc       = trim($_POST['cipc_number'] ?? '');

        if (!$name || strlen($name) < 2) {
            redirect('/app/broker/create-org.php?error=' . urlencode('Please enter your organisation name (at least 2 characters).'));
        }

        // Check name not already taken
        $nameCheck = $pdo->prepare("SELECT id FROM organizations WHERE name = ? LIMIT 1");
        $nameCheck->execute([$name]);
        if ($nameCheck->fetch()) {
            redirect('/app/broker/create-org.php?error=' . urlencode('An organisation with that name already exists. Please choose a different name.'));
        }

        $wz['name']        = $name;
        $wz['cipc_number'] = $cipc ?: null;
        $wz['step']        = 2;
        redirect('/app/broker/create-org.php');
    }

    if ($action === 'step2') {
        $wz['province'] = trim($_POST['province'] ?? '');
        $wz['city']     = trim($_POST['city']     ?? '');
        $wz['suburb']   = trim($_POST['suburb']   ?? '');
        $wz['step']     = 3;
        redirect('/app/broker/create-org.php');
    }

    if ($action === 'step3' || $action === 'skip_step3') {
        $description = trim($_POST['description'] ?? '');
        $wz['description'] = $description;

        // Handle logo upload
        $logoUrl = null;
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $maxBytes  = 2097152;
            $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if ($_FILES['logo']['size'] <= $maxBytes) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['logo']['tmp_name']);
                finfo_close($finfo);
                if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $ext  = match($mime) {'image/png' => '.png','image/webp' => '.webp', default => '.jpg'};
                    $name = generateUuidV4() . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $name)) {
                        $logoUrl = '/uploads/logos/' . $name;
                    }
                }
            }
        }

        // ── Create the organisation ───────────────────────────
        $pdo->beginTransaction();
        try {
            // Generate slug from name
            $baseSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $wz['name']));
            $baseSlug = trim(substr($baseSlug, 0, 60), '-') ?: 'org';
            $slug     = $baseSlug;
            $suffix   = 2;
            while (true) {
                $sc = $pdo->prepare("SELECT id FROM organizations WHERE slug = ? LIMIT 1");
                $sc->execute([$slug]);
                if (!$sc->fetch()) break;
                $slug = $baseSlug . '-' . $suffix++;
            }

            // Insert address if provided
            $addressId = null;
            if ($wz['province'] || $wz['city']) {
                $pdo->prepare("
                    INSERT INTO addresses (province, city, suburb, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ")->execute([$wz['province'] ?: null, $wz['city'] ?: null, $wz['suburb'] ?: null]);
                $addressId = (int) $pdo->lastInsertId();
            }

            // Insert organisation
            $uuid = generateUuidV4();
            $pdo->prepare("
                INSERT INTO organizations
                    (uuid, name, slug, cipc_number, owner_user_id, address_id,
                     verification_status, logo_url, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'unverified', ?, 1, NOW(), NOW())
            ")->execute([
                $uuid,
                $wz['name'],
                $slug,
                $wz['cipc_number'] ?: null,
                $userId,
                $addressId,
                $logoUrl,
            ]);
            $orgId = (int) $pdo->lastInsertId();

            // Make creator the owner
            $pdo->prepare("
                INSERT INTO organization_members
                    (organization_id, user_id, role, invited_by, joined_at)
                VALUES (?, ?, 'owner', ?, NOW())
            ")->execute([$orgId, $userId, $userId]);

            writeAuditLog('org.created', 'organization', $orgId,
                null, ['name' => $wz['name'], 'slug' => $slug]);

            $pdo->commit();

            // Set org context and clean up wizard
            $_SESSION['org_context'] = $orgId;
            unset($_SESSION['org_wz']);

            $_SESSION['flash_ok'] = $wz['name'] . ' has been created. You are now the owner.';
            redirect('/app/broker/org-context.php');

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[SalesDesk create-org] ' . $e->getMessage());
            redirect('/app/broker/create-org.php?error=' . urlencode('Something went wrong. Please try again.'));
        }
    }

    if ($action === 'back') {
        $wz['step'] = max(1, $step - 1);
        redirect('/app/broker/create-org.php');
    }
}

$stepLabels  = ['details', 'location', 'branding'];
$pageTitle   = 'Create Organisation';

ob_start();
?>

<div style="max-width:560px;margin:0 auto;">

  <!-- Progress bar -->
  <div class="progress-wrap" style="margin-bottom:2rem;">
    <div class="progress-pips">
      <?php foreach ($stepLabels as $i => $label):
        $n   = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
        if ($i > 0): ?>
          <div class="connector <?= $n <= $step ? 'done' : '' ?>"></div>
        <?php endif; ?>
        <div class="pip <?= $cls ?>">
          <?php if ($n < $step): ?>
            <i class="fa-solid fa-check" style="font-size:10px"></i>
          <?php else: ?><?= $n ?><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="progress-labels">
      <?php foreach ($stepLabels as $i => $label): ?>
      <div class="pip-label <?= ($i+1) === $step ? 'active' : '' ?>"><?= $label ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Error / info -->
  <?php if ($error): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem">
    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
  <!-- ══════════════════════════
       STEP 1 — Org details
       ══════════════════════════ -->
  <div class="card card-body">
    <h1 style="font-family:var(--serif);font-size:1.35rem;font-weight:300;margin-bottom:.5rem;">
      Create your <em style="font-style:italic;">organisation</em>
    </h1>
    <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.65;">
      Organisations let you work as a team — share leads, pool commissions, and track performance together.
    </p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"     value="step1">

      <div class="fgroup">
        <label class="flabel" for="org_name">Organisation name *</label>
        <input class="finput" type="text" id="org_name" name="org_name"
               required maxlength="120" autofocus
               placeholder="e.g. Gauteng Auto Brokers"
               value="<?= htmlspecialchars($wz['name']) ?>">
        <div style="font-size:11px;color:var(--faint);margin-top:5px;">
          This is the public name of your brokerage organisation.
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="cipc_number">
          CIPC registration number <span class="flabel-opt">(optional)</span>
        </label>
        <input class="finput" type="text" id="cipc_number" name="cipc_number"
               maxlength="30" placeholder="e.g. 2024/000000/07"
               value="<?= htmlspecialchars($wz['cipc_number'] ?? '') ?>">
        <div class="cipc-hint">
          Adding your CIPC number allows admin to verify your organisation,
          which displays a verified badge in the platform.
        </div>
      </div>

      <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:1.5rem;">
        <a href="?cancel=1" class="btn btn-ghost" style="text-decoration:none;">
          Cancel
        </a>
        <button class="btn btn-primary" type="submit">
          Continue <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </form>
  </div>

  <?php elseif ($step === 2): ?>
  <!-- ══════════════════════════
       STEP 2 — Location
       ══════════════════════════ -->
  <div class="card card-body">
    <h1 style="font-family:var(--serif);font-size:1.35rem;font-weight:300;margin-bottom:.5rem;">
      Business <em style="font-style:italic;">location</em>
    </h1>
    <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.65;">
      Where is your organisation based? This helps match you with relevant dealers and buyers.
      You can skip this and add it later.
    </p>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"     value="step2">

      <div class="frow">
        <div class="fgroup">
          <label class="flabel" for="province">Province</label>
          <select class="finput" id="province" name="province">
            <option value="">— Select —</option>
            <?php foreach (['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'] as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"
                    <?= $wz['province'] === $p ? 'selected' : '' ?>>
              <?= htmlspecialchars($p) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel" for="city">City / Town</label>
          <input class="finput" type="text" id="city" name="city"
                 maxlength="80" placeholder="e.g. Johannesburg"
                 value="<?= htmlspecialchars($wz['city']) ?>">
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="suburb">
          Suburb <span class="flabel-opt">(optional)</span>
        </label>
        <input class="finput" type="text" id="suburb" name="suburb"
               maxlength="80" placeholder="e.g. Sandton"
               value="<?= htmlspecialchars($wz['suburb']) ?>">
      </div>

      <div style="display:flex;gap:8px;margin-top:1.5rem;">
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="back">
          <button class="btn btn-ghost" type="submit">← Back</button>
        </form>
        <button class="btn btn-primary" type="submit" style="flex:1;">
          Continue <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </form>
  </div>

  <?php elseif ($step === 3): ?>
  <!-- ══════════════════════════
       STEP 3 — Branding (optional)
       ══════════════════════════ -->
  <div class="card card-body">
    <h1 style="font-family:var(--serif);font-size:1.35rem;font-weight:300;margin-bottom:.5rem;">
      Organisation <em style="font-style:italic;">branding</em>
      <span style="font-size:0.9rem;color:var(--faint);font-family:var(--sans);font-weight:300;"> — optional</span>
    </h1>
    <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.65;">
      Add a logo and description for your organisation. You can skip this and add them later
      from organisation settings.
    </p>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action"     value="step3">

      <!-- Logo preview -->
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:1.5rem;">
        <div style="width:72px;height:72px;border-radius:var(--r-lg);flex-shrink:0;
                    background:var(--p-light);color:var(--p);
                    display:flex;align-items:center;justify-content:center;
                    font-size:26px;overflow:hidden;border:1px solid var(--p-b);"
             id="logoPreview">
          <i class="fa-solid fa-building"></i>
        </div>
        <div>
          <label for="logo" class="btn btn-ghost btn-sm"
                 style="cursor:pointer;display:inline-flex;">
            <i class="fa-solid fa-image"></i> Upload logo
          </label>
          <input type="file" id="logo" name="logo"
                 accept="image/jpeg,image/png,image/webp"
                 style="display:none;"
                 onchange="previewLogo(this)">
          <div style="font-size:11px;color:var(--faint);margin-top:5px;">
            JPG, PNG, WebP · max 2MB
          </div>
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel" for="description">
          Description <span class="flabel-opt">(optional)</span>
        </label>
        <textarea class="finput" id="description" name="description"
                  maxlength="500" rows="3"
                  placeholder="Brief description of your brokerage organisation…"><?= htmlspecialchars($wz['description']) ?></textarea>
      </div>

      <!-- Summary of what will be created -->
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);
                  padding:14px 16px;margin-bottom:1.5rem;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                    color:var(--faint);margin-bottom:10px;">Creating organisation</div>
        <?php
        $summaryRows = [
          ['Name',     $wz['name']],
          ['CIPC',     $wz['cipc_number'] ?: '—'],
          ['Location', implode(', ', array_filter([$wz['city'], $wz['province']])) ?: '—'],
        ];
        foreach ($summaryRows as [$lbl, $val]):
        ?>
        <div style="display:flex;justify-content:space-between;padding:5px 0;
                    border-bottom:1px solid var(--border);font-size:12px;">
          <span style="color:var(--faint);"><?= $lbl ?></span>
          <span style="color:var(--text);font-weight:500;"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;gap:8px;">
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="back">
          <button class="btn btn-ghost" type="submit">← Back</button>
        </form>
        <button class="btn btn-primary" type="submit" style="flex:1;">
          <i class="fa-solid fa-building"></i> Create organisation
        </button>
      </div>
    </form>
  </div>

  <?php endif; ?>

</div>

<script>
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var preview = document.getElementById('logoPreview');
    preview.innerHTML = '';
    var img = document.createElement('img');
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
    img.src = e.target.result;
    preview.appendChild(img);
  };
  reader.readAsDataURL(input.files[0]);
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
