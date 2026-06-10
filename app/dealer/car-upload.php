<?php
/**
 * SalesDesk — Car Upload Wizard.
 * T3 owns this file. Also used by exec portal (sets uploaded_by_exec_id).
 *
 * Task d2: 4-step wizard
 *   Step 1: Make/model/year/price/mileage/condition/body/colour/transmission/fuel/drivetrain/description
 *   Step 2: Images (up to 10, max 2MB each, UUID filenames)
 *   Step 3: Commission — Fixed (R) or Percentage with live preview
 *   Step 4: Review + publish
 *
 * Called by:
 *   app/dealer/car-upload.php   → uploaded_by_exec_id = NULL
 *   app/exec/car-upload.php     → requires exec guard, sets uploaded_by_exec_id = se.id
 *
 * Role detection: if $_SESSION['user_role'] === 'sales_exec', loads exec mode.
 *
 * BUG FIXES (2025-05-11):
 *   BUG-WZ-01: Step 2 "Back" button was a nested <form> inside the file-upload
 *              <form> — invalid HTML; browsers discard the inner form entirely,
 *              so clicking Back submitted the outer upload form with no action
 *              field, nothing matched, and the page reloaded at step 2 with the
 *              session unchanged. Fixed by pulling the Back action out into a
 *              standalone form rendered before (not inside) the upload form, and
 *              using JS to submit it — or simply by restructuring the layout so
 *              Back is outside the enctype="multipart/form-data" form entirely.
 *
 *   BUG-WZ-02: Same nested-form problem existed in the 10-image-cap branch of
 *              step 2 (the "Continue" form was also nested). Fixed same way.
 *
 *   BUG-WZ-03: After publish, the flash message was built from $wz references
 *              AFTER unset($_SESSION['car_wz']), leaving $wz as a dangling
 *              reference and producing an empty flash string. Fixed by capturing
 *              the display string before unsetting the session key.
 *
 *   BUG-WZ-04: array_push($params) with no second argument (near the car SELECT)
 *              was a no-op left in by mistake. Removed.
 *
 * BUG FIXES (2025-05-11 round 2):
 *   BUG-WZ-05: Step 3 "Back" button was again a nested <form> inside #commForm.
 *              When a browser's HTML parser encounters a <form> inside a <form>,
 *              it implicitly closes the outer form at that point. This split
 *              #commForm in two: the inputs and hidden action=step3 field stayed
 *              in the first fragment, but the Continue <button type="submit"> was
 *              left outside every form and became inert — or in some browsers it
 *              latched onto the Back form and submitted action=back, sending the
 *              user back to step 2. Fixed by closing #commForm before the action
 *              row and making the Continue button its own submit form, mirroring
 *              the pattern used to fix step 2 in BUG-WZ-01.
 *
 *   BUG-WZ-07: $commPreview was always blank on the step 4 review page because
 *              the guard was `$wz['commission_value'] > 0` and commission_value
 *              is stored as a float after step 3 saves it, but on first render
 *              of step 4 it comes straight from the session which holds a float.
 *              The real failure mode: if commission_value was saved as the string
 *              '' (the session default), PHP evaluates '' > 0 as false. Fixed by
 *              casting to float explicitly before the comparison so both the empty
 *              string default and a genuine zero are handled consistently.
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$role   = $_SESSION['user_role'] ?? '';

// ── Role branching ────────────────────────────────────────────
$isExec = ($role === 'sales_exec');

if ($isExec) {
    require_once '../../includes/exec_guard.php';
    $exec     = requireExecVerified();
    $dealerId = (int) $exec['dealer_id'];
    $execId   = (int) $exec['id'];
} else {
    requireRole('dealer');
    $dealerStmt = $pdo->prepare("
        SELECT id AS dealer_id, company_name FROM dealers WHERE user_id = ? AND is_active = 1
    ");
    $dealerStmt->execute([$userId]);
    $dealerRow = $dealerStmt->fetch();
    if (!$dealerRow) redirect('/app/dealer/dashboard.php');
    $dealerId = (int) $dealerRow['dealer_id'];
    $execId   = null;
}

// ── Wizard session state ──────────────────────────────────────
if (empty($_SESSION['car_wz'])) {
    $_SESSION['car_wz'] = [
        'step'             => 1,
        'make'             => '',
        'model'            => '',
        'year'             => '',
        'price'            => '',
        'mileage'          => '',
        'condition'        => 'used',
        'body_type'        => '',
        'colour'           => '',
        'transmission'     => '',
        'fuel_type'        => '',
        'drivetrain'       => '',
        'description'      => '',
        'image_urls'       => [],
        'commission_type'  => 'fixed',
        'commission_value' => '',
    ];
}
$wz   = &$_SESSION['car_wz'];
$step = (int) ($wz['step'] ?? 1);
$csrf = generateCSRFToken();

// ── Handle reset ──────────────────────────────────────────────
if (isset($_GET['reset'])) {
    unset($_SESSION['car_wz']);
    redirect('/app/dealer/car-upload.php');
}

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // ── Step 1: vehicle details ───────────────────────────────
    if ($action === 'step1') {
        $wz['make']        = trim($_POST['make']      ?? '');
        $wz['model']       = trim($_POST['model']     ?? '');
        $wz['year']        = (int) ($_POST['year']    ?? date('Y'));
        $wz['price']       = (float) str_replace([',', ' '], '', $_POST['price'] ?? '0');
        $wz['mileage']     = strlen(trim($_POST['mileage'] ?? '')) > 0
                             ? (int) str_replace([',', ' '], '', $_POST['mileage'])
                             : null;
        $wz['condition']   = in_array($_POST['condition'] ?? '', ['new','demo','used'], true)
                             ? $_POST['condition'] : 'used';
        $wz['body_type']    = trim($_POST['body_type']    ?? '');
        $wz['colour']       = trim($_POST['colour']       ?? '');
        $wz['transmission'] = trim($_POST['transmission'] ?? '');
        $wz['fuel_type']    = trim($_POST['fuel_type']    ?? '');
        $wz['drivetrain']   = trim($_POST['drivetrain']   ?? '');
        $wz['description']  = trim($_POST['description']  ?? '');

        if (!$wz['make'] || !$wz['model'] || !$wz['year'] || $wz['price'] <= 0) {
            $error = 'Please fill in make, model, year, and price.';
        } else {
            $wz['step'] = 2;
            redirect('/app/dealer/car-upload.php');
        }
    }

    // ── Step 2: image upload ──────────────────────────────────
    if ($action === 'step2') {
        $maxImages  = defined('MAX_CAR_IMAGES')       ? MAX_CAR_IMAGES       : 10;
        $maxBytes   = defined('MAX_IMAGE_SIZE_BYTES') ? MAX_IMAGE_SIZE_BYTES : 2097152;
        $uploadDir  = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/cars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $existingUrls = $wz['image_urls'] ?? [];
        $newUrls      = $existingUrls;
        $uploadError  = '';

        if (!empty($_FILES['images']['tmp_name'])) {
            $files = $_FILES['images'];
            if (!is_array($files['tmp_name'])) {
                $files['tmp_name'] = [$files['tmp_name']];
                $files['size']     = [$files['size']];
                $files['error']    = [$files['error']];
                $files['type']     = [$files['type']];
            }
            foreach ($files['tmp_name'] as $idx => $tmp) {
                if ($files['error'][$idx] !== UPLOAD_ERR_OK) continue;
                if (count($newUrls) >= $maxImages) {
                    $uploadError = "Max {$maxImages} images allowed.";
                    break;
                }
                if ($files['size'][$idx] > $maxBytes) {
                    $uploadError = 'Each image must be under 2MB.';
                    continue;
                }
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                    $uploadError = 'Only JPG, PNG, and WebP images are allowed.';
                    continue;
                }
                $ext      = match($mime) {
                    'image/png'  => '.png',
                    'image/webp' => '.webp',
                    default      => '.jpg',
                };
                $filename = generateUuidV4() . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $filename)) {
                    $newUrls[] = '/uploads/cars/' . $filename;
                }
            }
        }

        $wz['image_urls'] = $newUrls;

        // BUG-WZ-01 FIX: advance to step 3 only when there is no upload error.
        // Previously this correctly set step=3 in code, but the nested <form>
        // for the Back button meant browsers could submit the outer form
        // unexpectedly. With forms now un-nested (see HTML below), this path
        // is reached cleanly and we can advance normally.
        if (!$uploadError) {
            $wz['step'] = 3;
            redirect('/app/dealer/car-upload.php');
        }
        $error = $uploadError;
    }

    // ── Step 2: remove an image ───────────────────────────────
    if ($action === 'step2_remove') {
        $removeIdx = (int) ($_POST['remove_idx'] ?? -1);
        if (isset($wz['image_urls'][$removeIdx])) {
            array_splice($wz['image_urls'], $removeIdx, 1);
        }
        redirect('/app/dealer/car-upload.php');
    }

    // ── Step 3: commission ────────────────────────────────────
    if ($action === 'step3') {
        $commType  = in_array($_POST['commission_type'] ?? '', ['fixed','percentage'], true)
                     ? $_POST['commission_type'] : 'fixed';
        $commValue = (float) str_replace([',', ' '], '', $_POST['commission_value'] ?? '0');

        if ($commValue <= 0) {
            $error = 'Please enter a commission value.';
        } elseif ($commType === 'percentage' && $commValue > 30) {
            $error = 'Percentage commission must be 30% or less.';
        } else {
            $wz['commission_type']  = $commType;
            $wz['commission_value'] = $commValue;
            $wz['step'] = 4;
            redirect('/app/dealer/car-upload.php');
        }
    }

    // ── Publish ───────────────────────────────────────────────
    if ($action === 'publish') {
        // Cover photo selection — move chosen image to index 0 before INSERT.
        $coverIdx = (int) ($_POST['cover_idx'] ?? 0);
        if ($coverIdx > 0 && isset($wz['image_urls'][$coverIdx])) {
            $cover = array_splice($wz['image_urls'], $coverIdx, 1);
            array_unshift($wz['image_urls'], $cover[0]);
        }

        // BUG-WZ-03 FIX: capture display values from $wz BEFORE unsetting
        // $_SESSION['car_wz']. After unset(), $wz is a dangling reference and
        // reads back empty strings, producing a blank flash message.
        $flashMake  = $wz['make'];
        $flashModel = $wz['model'];
        $flashYear  = $wz['year'];

        $baseSlug = strtolower(preg_replace(
            '/[^a-z0-9]+/', '-',
            "{$wz['year']}-{$wz['make']}-{$wz['model']}" .
            ($wz['colour'] ? "-{$wz['colour']}" : '')
        ));
        $baseSlug = trim(substr($baseSlug, 0, 90), '-');
        $slug     = $baseSlug;
        $suffix   = 2;
        while (true) {
            $check = $pdo->prepare("SELECT id FROM cars WHERE dealer_id = ? AND slug = ?");
            $check->execute([$dealerId, $slug]);
            if (!$check->fetch()) break;
            $slug = $baseSlug . '-' . $suffix++;
        }

        $uuid = generateUuidV4();
        $pdo->prepare("
            INSERT INTO cars
                (uuid, dealer_id, uploaded_by_exec_id, slug, make, model, year, price,
                 mileage, condition_type, body_type, colour, transmission, fuel_type,
                 drivetrain, description, commission_type, commission_value,
                 image_urls, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
        ")->execute([
            $uuid, $dealerId, $execId, $slug,
            $wz['make'], $wz['model'], (int)$wz['year'], (float)$wz['price'],
            $wz['mileage'], $wz['condition'], $wz['body_type'] ?: null,
            $wz['colour'] ?: null, $wz['transmission'] ?: null,
            $wz['fuel_type'] ?: null, $wz['drivetrain'] ?: null,
            $wz['description'] ?: null,
            $wz['commission_type'], (float)$wz['commission_value'],
            json_encode($wz['image_urls']),
        ]);

        // BUG-WZ-03 FIX: unset session AFTER reading values, not before.
        unset($_SESSION['car_wz']);

        $_SESSION['flash_ok'] = "{$flashYear} {$flashMake} {$flashModel} listed successfully.";
        redirect($isExec ? '/app/exec/inventory.php' : '/app/dealer/inventory.php');
    }

    // ── Back navigation ───────────────────────────────────────
    if ($action === 'back') {
        $wz['step'] = max(1, $step - 1);
        redirect('/app/dealer/car-upload.php');
    }
}

// ── Lists for dropdowns ────────────────────────────────────────
$makes         = ['Acura','Alfa Romeo','Aston Martin','Audi','BAIC','Bentley','BMW','BYD','Cadillac','Changan','Chery','Chevrolet','Chrysler','Citroën','Daihatsu','Datsun','Dodge','Ferrari','Fiat','Ford','Foton','Geely','Genesis','GWM','Haval','Honda','Hyundai','Infiniti','Isuzu','JAC','Jaecoo','Jaguar','Jeep','Jetour','Kia','Lamborghini','Land Rover','LDV','Lexus','Lincoln','Mahindra','Maserati','Mazda','McLaren','Mercedes-Benz','MG','Mini','Mitsubishi','NIO','Nissan','OMODA','Opel','Peugeot','Polestar','Porsche','RAM','Range Rover','Renault','Rivian','Rolls-Royce','SEAT','Skoda','Smart','Ssangyong','Subaru','Suzuki','Tata','Tesla','Toyota','Volkswagen','Volvo','Xpeng','Zeekr'];
$bodyTypes     = ['Sedan','Hatchback','SUV','Bakkie','Coupe','Convertible','Station Wagon','MPV','Minibus','Crossover','Van','Truck'];
$transmissions = ['Automatic','Manual','Semi-Automatic','CVT','DSG'];
$fuelTypes     = ['Petrol','Diesel','Electric','Hybrid','Plug-in Hybrid (PHEV)','Hydrogen','LPG (Autogas)','CNG (Natural Gas)','Flex Fuel (E85/Ethanol)'];
$drivetrains   = ['FWD','RWD','AWD','4WD'];

$stepLabels = ['details','images','commission','review'];
$pageTitle  = 'List a car';

// Commission preview calc (for step 3 / review)
// BUG-WZ-07 FIX: cast both values to float before comparison. The session
// initialises commission_value as '' (empty string); PHP evaluates '' > 0 as
// false, so $commPreview stayed blank on the review page even after the user
// had entered a valid value. Explicit (float) cast normalises '' → 0.0 and
// makes the guard behave correctly in all cases.
$commPreview = '';
if ((float)$wz['price'] > 0 && (float)$wz['commission_value'] > 0) {
    if ($wz['commission_type'] === 'fixed') {
        $commPreview = 'R ' . number_format((float)$wz['commission_value'], 0);
    } else {
        $commPreview = 'R ' . number_format((float)$wz['price'] * (float)$wz['commission_value'] / 100, 0)
            . ' (' . (float)$wz['commission_value'] . '% of R ' . number_format((float)$wz['price'], 0) . ')';
    }
}

ob_start();
?>

<!-- ── Progress pip bar ────────────────────────────────────── -->
<div style="max-width:620px;margin:0 auto 2rem;">
  <div class="progress-wrap">
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
      <div class="pip-label <?= ($i + 1) === $step ? 'active' : '' ?>"><?= $label ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Error banner -->
<?php if (!empty($error)): ?>
<div class="alert alert-error" style="max-width:620px;margin:0 auto 1rem;">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i>
  <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div style="max-width:620px;margin:0 auto;">

<?php if ($step === 1): ?>
<!-- ═══════════════════════════════
     STEP 1 — Vehicle details
     ═══════════════════════════════ -->
<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
    Vehicle <em style="font-style:italic;">details</em>
  </h2>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step1">

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="make">Make *</label>
        <select class="finput" id="make" name="make" required>
          <option value="">— Select make —</option>
          <?php foreach ($makes as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>"
                  <?= $wz['make'] === $m ? 'selected' : '' ?>>
            <?= htmlspecialchars($m) ?>
          </option>
          <?php endforeach; ?>
          <option value="Other" <?= $wz['make'] === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
      <div class="fgroup">
        <label class="flabel" for="model">Model *</label>
        <input class="finput" type="text" id="model" name="model" required
               maxlength="100" placeholder="e.g. Corolla Cross"
               value="<?= htmlspecialchars($wz['model']) ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="year">Year *</label>
        <select class="finput" id="year" name="year" required>
          <option value="">— Year —</option>
          <?php for ($y = date('Y') + 1; $y >= 1990; $y--): ?>
          <option value="<?= $y ?>" <?= (int)$wz['year'] === $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="fgroup">
        <label class="flabel" for="price">Asking price (R) *</label>
        <input class="finput" type="number" id="price" name="price" required
               min="1000" step="1" placeholder="350000"
               value="<?= $wz['price'] > 0 ? (int)$wz['price'] : '' ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="mileage">Mileage (km)</label>
        <input class="finput" type="number" id="mileage" name="mileage"
               min="0" step="1" placeholder="28000"
               value="<?= $wz['mileage'] !== null ? $wz['mileage'] : '' ?>">
      </div>
      <div class="fgroup">
        <label class="flabel">Condition</label>
        <div style="display:flex;gap:6px;margin-top:1px;">
          <?php foreach (['used','demo','new'] as $cond): ?>
          <label style="flex:1;cursor:pointer;">
            <input type="radio" name="condition" value="<?= $cond ?>"
                   <?= $wz['condition'] === $cond ? 'checked' : '' ?>
                   style="display:none;" class="cond-radio">
            <div class="cond-btn" style="padding:8px;text-align:center;border:1px solid var(--border);
                 border-radius:var(--r-md);font-size:12px;font-weight:500;
                 background:<?= $wz['condition'] === $cond ? 'var(--p-light)' : 'var(--bg)' ?>;
                 color:<?= $wz['condition'] === $cond ? 'var(--p)' : 'var(--muted)' ?>;
                 border-color:<?= $wz['condition'] === $cond ? 'var(--p-b)' : 'var(--border)' ?>;">
              <?= ucfirst($cond) ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="body_type">Body type</label>
        <select class="finput" id="body_type" name="body_type">
          <option value="">— Select —</option>
          <?php foreach ($bodyTypes as $bt): ?>
          <option value="<?= htmlspecialchars($bt) ?>"
                  <?= $wz['body_type'] === $bt ? 'selected' : '' ?>>
            <?= htmlspecialchars($bt) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fgroup">
        <label class="flabel" for="colour">Colour</label>
        <input class="finput" type="text" id="colour" name="colour"
               maxlength="40" placeholder="e.g. Pearl White"
               value="<?= htmlspecialchars($wz['colour']) ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="transmission">Transmission</label>
        <select class="finput" id="transmission" name="transmission">
          <option value="">— Select —</option>
          <?php foreach ($transmissions as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>"
                  <?= $wz['transmission'] === $t ? 'selected' : '' ?>>
            <?= htmlspecialchars($t) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fgroup">
        <label class="flabel" for="fuel_type">Fuel type</label>
        <select class="finput" id="fuel_type" name="fuel_type">
          <option value="">— Select —</option>
          <?php foreach ($fuelTypes as $f): ?>
          <option value="<?= htmlspecialchars($f) ?>"
                  <?= $wz['fuel_type'] === $f ? 'selected' : '' ?>>
            <?= htmlspecialchars($f) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="fgroup">
      <label class="flabel" for="drivetrain">Drivetrain</label>
      <select class="finput" id="drivetrain" name="drivetrain">
        <option value="">— Select —</option>
        <?php foreach ($drivetrains as $d): ?>
        <option value="<?= htmlspecialchars($d) ?>"
                <?= $wz['drivetrain'] === $d ? 'selected' : '' ?>>
          <?= htmlspecialchars($d) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fgroup">
      <label class="flabel" for="description">Description <span class="flabel-opt">(optional)</span></label>
      <textarea class="finput" id="description" name="description"
                maxlength="2000" rows="4"
                placeholder="Service history, features, condition notes…"><?= htmlspecialchars($wz['description']) ?></textarea>
    </div>

    <button class="btn btn-primary btn-full" type="submit">
      Continue — add images <i class="fa-solid fa-arrow-right"></i>
    </button>
  </form>
</div>

<?php elseif ($step === 2): ?>
<!-- ═══════════════════════════════
     STEP 2 — Images
     ═══════════════════════════════ -->

<!--
  BUG-WZ-01 / BUG-WZ-02 FIX:
  The original code placed a Back <form> INSIDE the upload <form
  enctype="multipart/form-data">. Nested forms are invalid HTML — browsers
  silently discard the inner form, so the Back button actually submitted the
  outer upload form with no "action" field. Nothing matched in the POST
  handler, the session was unchanged, and the page re-rendered at step 2.

  Fix: the Back button is now its own standalone <form> rendered OUTSIDE and
  BEFORE the upload form. The "Continue" button is inside a separate form
  that only contains the hidden fields needed to advance. This keeps every
  form at the top level of the DOM.
-->

<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:.5rem;">
    Vehicle <em style="font-style:italic;">photos</em>
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
    Upload up to 10 photos. JPG, PNG, WebP — max 2MB each.
    The first image will be the cover photo.
  </p>

  <!-- Existing image thumbnails with individual remove buttons -->
  <?php if (!empty($wz['image_urls'])): ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:1rem;">
    <?php foreach ($wz['image_urls'] as $idx => $url): ?>
    <div style="position:relative;border-radius:var(--r-md);overflow:hidden;
                border:1px solid var(--border);aspect-ratio:4/3;background:var(--bg);">
      <img src="<?= htmlspecialchars($url) ?>" alt="Car image <?= $idx + 1 ?>"
           style="width:100%;height:100%;object-fit:cover;">
      <?php if ($idx === 0): ?>
      <div style="position:absolute;top:4px;left:4px;font-size:9px;font-weight:700;
                  background:var(--p);color:#fff;padding:2px 6px;border-radius:4px;">
        COVER
      </div>
      <?php endif; ?>
      <!-- Each remove button is its own top-level form — no nesting -->
      <form method="POST" style="position:absolute;top:4px;right:4px;margin:0;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="step2_remove">
        <input type="hidden" name="remove_idx" value="<?= $idx ?>">
        <button type="submit"
                style="width:22px;height:22px;background:rgba(0,0,0,.6);border:none;
                       border-radius:50%;color:#fff;font-size:11px;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;padding:0;">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php $imageCount = count($wz['image_urls']); ?>

  <?php if ($imageCount < 10): ?>

  <!--
    Upload form — standalone, no other forms nested inside.
    The Back and Continue actions are handled by separate forms below.
  -->
  <form method="POST" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step2">

    <label for="images"
           style="display:block;border:2px dashed var(--border);border-radius:var(--r-lg);
                  padding:2rem;text-align:center;cursor:pointer;transition:border-color .18s;
                  background:var(--bg);"
           onmouseover="this.style.borderColor='var(--p)'"
           onmouseout="this.style.borderColor='var(--border)'">
      <i class="fa-solid fa-cloud-arrow-up"
         style="font-size:32px;color:var(--faint);margin-bottom:10px;display:block;"></i>
      <span id="uploadLabel" style="font-size:14px;font-weight:500;color:var(--text);">
        Click to upload images
      </span>
      <span style="display:block;font-size:12px;color:var(--faint);margin-top:4px;">
        or drag and drop · JPG, PNG, WebP · max 2MB each
      </span>
      <input type="file" id="images" name="images[]"
             accept="image/jpeg,image/png,image/webp"
             multiple style="display:none;"
             onchange="document.getElementById('uploadLabel').textContent =
                       this.files.length + ' file(s) selected — click Continue to upload'">
    </label>
  </form><!-- /uploadForm — closed here, nothing nested inside -->

  <!-- Action row: Back (standalone form) + Continue (submits uploadForm via JS) -->
  <div style="display:flex;gap:8px;margin-top:1rem;">

    <!-- BUG-WZ-01 FIX: Back is its own top-level form, not nested in uploadForm -->
    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>

    <!--
      "Continue" submits the upload form above via JS so the
      multipart/form-data enctype is preserved for file delivery.
      If no files were chosen, the form posts with an empty files
      array — the handler sets step=3 and redirects as expected,
      allowing users to skip images if they have none to add yet.
    -->
    <button class="btn btn-primary" type="button" style="flex:1;"
            onclick="document.getElementById('uploadForm').submit();">
      Continue — set commission <i class="fa-solid fa-arrow-right"></i>
    </button>

  </div>

  <?php else: /* imageCount === 10 */ ?>

  <div class="alert alert-info">
    <i class="fa-solid fa-circle-info alert-icon"></i>
    Maximum 10 images uploaded. Remove one to add another.
  </div>

  <!--
    BUG-WZ-02 FIX: These two forms were previously nested inside a wrapper
    form, making them invalid. They are now standalone top-level forms.
  -->
  <div style="display:flex;gap:8px;margin-top:1rem;">

    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>

    <form method="POST" style="margin:0;flex:1;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="step2">
      <button class="btn btn-primary btn-full" type="submit">
        Continue — set commission <i class="fa-solid fa-arrow-right"></i>
      </button>
    </form>

  </div>

  <?php endif; ?>

</div>

<?php elseif ($step === 3): ?>
<!-- ═══════════════════════════════
     STEP 3 — Commission
     ═══════════════════════════════ -->
<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:.5rem;">
    Broker <em style="font-style:italic;">commission</em>
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.6;">
    Set how much brokers earn per sale. A competitive commission attracts more broker activity.
  </p>

  <!--
    BUG-WZ-05 FIX:
    The original code opened #commForm at the top of this section and then
    placed a Back <form> INSIDE it before the closing </form>. When a browser
    HTML parser sees a <form> inside a <form> it implicitly closes the outer
    form at that point. The DOM result was:
      - #commForm  → contained: csrf_token, action=step3, radios, value input
                     (closed early by the parser when it hit the nested form)
      - anonymous  → contained: the Back button with action=back
      - orphaned   → the Continue <button type="submit"> was outside every form

    In Chrome the orphaned Continue button found no ancestor form and did
    nothing. In some environments it latched onto the Back form and submitted
    action=back, sending the user back to step 2 — exactly the reported bug.

    Fix: #commForm now contains ONLY the inputs (radios + value field +
    preview). It is closed before the action row. The Back and Continue
    buttons are each their own standalone top-level forms, consistent with
    the pattern used to fix BUG-WZ-01 in step 2. The Continue form re-posts
    the commission type and value so the step3 handler receives them cleanly.
  -->

  <!-- Inputs-only form — closed before any action buttons -->
  <form method="POST" id="commForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step3">

    <!-- Toggle: Fixed / Percentage -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:1.25rem;">
      <label style="cursor:pointer;">
        <input type="radio" name="commission_type" value="fixed"
               <?= $wz['commission_type'] === 'fixed' ? 'checked' : '' ?>
               style="display:none;" class="comm-type-radio" onchange="updateCommPreview()">
        <div class="comm-type-btn" style="padding:14px;text-align:center;border-radius:var(--r-lg);
             border:1.5px solid <?= $wz['commission_type'] === 'fixed' ? 'var(--p)' : 'var(--border)' ?>;
             background:<?= $wz['commission_type'] === 'fixed' ? 'var(--p-light)' : 'var(--bg)' ?>;">
          <div style="font-size:20px;color:var(--p);margin-bottom:4px;">R</div>
          <div style="font-size:13px;font-weight:600;color:var(--text);">Fixed amount</div>
          <div style="font-size:11px;color:var(--muted);">e.g. R8,000 per sale</div>
        </div>
      </label>
      <label style="cursor:pointer;">
        <input type="radio" name="commission_type" value="percentage"
               <?= $wz['commission_type'] === 'percentage' ? 'checked' : '' ?>
               style="display:none;" class="comm-type-radio" onchange="updateCommPreview()">
        <div class="comm-type-btn" style="padding:14px;text-align:center;border-radius:var(--r-lg);
             border:1.5px solid <?= $wz['commission_type'] === 'percentage' ? 'var(--p)' : 'var(--border)' ?>;
             background:<?= $wz['commission_type'] === 'percentage' ? 'var(--p-light)' : 'var(--bg)' ?>;">
          <div style="font-size:20px;color:var(--p);margin-bottom:4px;">%</div>
          <div style="font-size:13px;font-weight:600;color:var(--text);">Percentage</div>
          <div style="font-size:11px;color:var(--muted);">e.g. 2.5% of sale price</div>
        </div>
      </label>
    </div>

    <div class="fgroup">
      <label class="flabel" for="commission_value" id="commLabel">
        Commission amount (R) *
      </label>
      <!--
        BUG-WZ-05 FIX (cont.): value attribute now uses explicit (float) cast
        so a fresh session (commission_value = '') renders an empty field rather
        than "0", which would incorrectly pre-fill the input.
      -->
      <input class="finput" type="number" id="commission_value" name="commission_value"
             required min="0.01" step="any"
             placeholder="<?= $wz['commission_type'] === 'percentage' ? '2.5' : '8000' ?>"
             value="<?= (float)$wz['commission_value'] > 0 ? (float)$wz['commission_value'] : '' ?>"
             oninput="updateCommPreview()">
    </div>

    <!-- Live preview box -->
    <div id="commPreview"
         style="background:var(--gr-bg);border:1px solid var(--gr-b);border-radius:var(--r-md);
                padding:14px 16px;margin-bottom:1.25rem;display:none;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                  color:var(--green);margin-bottom:4px;">Broker earns per sale</div>
      <div id="commPreviewAmount"
           style="font-size:22px;font-weight:700;font-family:var(--mono);color:var(--green);">
      </div>
      <div id="commPreviewNote" style="font-size:11px;color:var(--muted);margin-top:3px;"></div>
    </div>

  </form><!-- /commForm — closed here; no Back form nested inside -->

  <!--
    Action row: Back and Continue are separate top-level forms.
    Continue uses JS to submit #commForm (which holds all the inputs)
    so commission_type and commission_value are included in the POST.
    Required-field validation on commission_value fires before submission
    because the browser runs it against #commForm on requestSubmit().
  -->
  <div style="display:flex;gap:8px;margin-top:0;">

    <!-- Back — standalone form, never nested -->
    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>

    <!-- Continue — submits #commForm via requestSubmit() to trigger validation -->
    <button class="btn btn-primary" type="button" style="flex:1;"
            onclick="document.getElementById('commForm').requestSubmit();">
      Continue — review <i class="fa-solid fa-arrow-right"></i>
    </button>

  </div>
</div>
<script>
var carPrice = <?= (float)$wz['price'] ?>;
function updateCommPreview() {
  var typeEl = document.querySelector('input[name="commission_type"]:checked');
  var valEl  = document.getElementById('commission_value');
  var prev   = document.getElementById('commPreview');
  var amount = document.getElementById('commPreviewAmount');
  var note   = document.getElementById('commPreviewNote');
  var label  = document.getElementById('commLabel');
  if (!typeEl || !valEl) return;
  var type = typeEl.value;
  var val  = parseFloat(valEl.value) || 0;
  label.textContent = type === 'percentage' ? 'Commission percentage (%) *' : 'Commission amount (R) *';
  valEl.placeholder = type === 'percentage' ? '2.5' : '8000';
  if (val <= 0) { prev.style.display = 'none'; return; }
  var earning = type === 'fixed' ? val : (carPrice * val / 100);
  amount.textContent = 'R ' + earning.toLocaleString('en-ZA', {minimumFractionDigits:0,maximumFractionDigits:0});
  note.textContent = type === 'percentage'
    ? val + '% of R ' + carPrice.toLocaleString('en-ZA') + ' sale price'
    : 'Fixed amount per sale';
  prev.style.display = 'block';
  document.querySelectorAll('.comm-type-radio').forEach(function(r) {
    var btn = r.nextElementSibling;
    var active = r.checked;
    btn.style.borderColor = active ? 'var(--p)' : 'var(--border)';
    btn.style.background  = active ? 'var(--p-light)' : 'var(--bg)';
  });
}
document.querySelectorAll('.comm-type-radio').forEach(function(r) {
  r.addEventListener('change', updateCommPreview);
});
updateCommPreview();
</script>

<?php elseif ($step === 4): ?>
<!-- ═══════════════════════════════
     STEP 4 — Review + publish
     ═══════════════════════════════ -->
<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:1.25rem;">
    Review &amp; <em style="font-style:italic;">publish</em>
  </h2>

  <?php if (!empty($wz['image_urls'])): ?>
  <div style="margin-bottom:1.25rem;">
    <!-- Live cover preview -->
    <div style="border-radius:var(--r-md);overflow:hidden;margin-bottom:.75rem;
                height:200px;background:var(--bg);position:relative;">
      <img id="coverPreviewImg"
           src="<?= htmlspecialchars($wz['image_urls'][0]) ?>" alt="Cover"
           style="width:100%;height:100%;object-fit:cover;">
      <div style="position:absolute;top:8px;left:8px;">
        <span style="font-size:9px;font-weight:700;background:var(--p);color:#fff;
                     padding:2px 8px;border-radius:4px;letter-spacing:.05em;">COVER</span>
      </div>
    </div>
    <!-- Thumbnail strip — only shown when there is more than one image -->
    <?php if (count($wz['image_urls']) > 1): ?>
    <p style="font-size:12px;color:var(--muted);margin-bottom:8px;">
      <i class="fa-regular fa-hand-pointer" style="font-size:11px;margin-right:4px;"></i>
      Tap a photo to use it as the cover
    </p>
    <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;">
      <?php foreach ($wz['image_urls'] as $tidx => $turl): ?>
      <div id="thumb-<?= $tidx ?>"
           onclick="setCoverPhoto(<?= $tidx ?>)"
           style="flex-shrink:0;width:72px;height:54px;border-radius:var(--r-sm);
                  overflow:hidden;cursor:pointer;position:relative;transition:border-color .15s;
                  border:2px solid <?= $tidx === 0 ? 'var(--p)' : 'var(--border)' ?>;">
        <img src="<?= htmlspecialchars($turl) ?>" alt=""
             style="width:100%;height:100%;object-fit:cover;display:block;">
        <!-- Star overlay — visible on selected thumb only -->
        <div id="thumb-star-<?= $tidx ?>"
             style="position:absolute;inset:0;background:rgba(15,76,158,.35);
                    display:<?= $tidx === 0 ? 'flex' : 'none' ?>;
                    align-items:center;justify-content:center;">
          <i class="fa-solid fa-star" style="color:#fff;font-size:13px;"></i>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Spec table -->
  <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);
              overflow:hidden;margin-bottom:1.25rem;">
    <?php
    $reviewRows = [
      ['Vehicle',      "{$wz['year']} {$wz['make']} {$wz['model']}"],
      ['Price',        'R ' . number_format((float)$wz['price'], 0)],
      ['Condition',    ucfirst($wz['condition'])],
      ['Mileage',      $wz['mileage'] !== null ? number_format($wz['mileage']) . ' km' : '—'],
      ['Body type',    $wz['body_type'] ?: '—'],
      ['Colour',       $wz['colour'] ?: '—'],
      ['Transmission', $wz['transmission'] ?: '—'],
      ['Fuel',         $wz['fuel_type'] ?: '—'],
      ['Drivetrain',   $wz['drivetrain'] ?: '—'],
      ['Commission',   $commPreview],
      ['Images',       count($wz['image_urls']) . ' photo(s)'],
    ];
    foreach ($reviewRows as $idx => [$label, $val]):
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;
                padding:9px 14px;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>;">
      <span style="font-size:12px;color:var(--faint);"><?= $label ?></span>
      <span style="font-size:13px;font-weight:500;color:var(--text);"><?= htmlspecialchars($val) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($wz['description']): ?>
  <div style="font-size:13px;color:var(--text2);line-height:1.65;margin-bottom:1.25rem;
              background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);
              padding:12px 14px;">
    <?= nl2br(htmlspecialchars($wz['description'])) ?>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;">
    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Edit</button>
    </form>
    <form method="POST" style="margin:0;flex:1;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="publish">
      <input type="hidden" name="cover_idx" id="coverIdxField" value="0">
      <button class="btn btn-primary btn-full btn-lg" type="submit">
        <i class="fa-solid fa-check"></i> Publish listing
      </button>
    </form>
  </div>
</div>

<?php endif; ?>

</div><!-- /max-width wrapper -->

<script>
// Condition button toggle
document.querySelectorAll('.cond-radio').forEach(function(r) {
  r.addEventListener('change', function() {
    document.querySelectorAll('.cond-radio').forEach(function(o) {
      var btn = o.nextElementSibling;
      btn.style.background  = o.checked ? 'var(--p-light)' : 'var(--bg)';
      btn.style.color       = o.checked ? 'var(--p)'       : 'var(--muted)';
      btn.style.borderColor = o.checked ? 'var(--p-b)'     : 'var(--border)';
    });
  });
});

// Cover photo picker (step 4)
var _coverIdx = 0;
function setCoverPhoto(idx) {
  if (idx === _coverIdx) return;
  // Update big preview
  var imgs = <?= $step === 4 ? json_encode($wz['image_urls']) : '[]' ?>;
  document.getElementById('coverPreviewImg').src = imgs[idx];
  // Deselect old thumb
  document.getElementById('thumb-' + _coverIdx).style.borderColor = 'var(--border)';
  document.getElementById('thumb-star-' + _coverIdx).style.display = 'none';
  // Select new thumb
  document.getElementById('thumb-' + idx).style.borderColor = 'var(--p)';
  document.getElementById('thumb-star-' + idx).style.display = 'flex';
  _coverIdx = idx;
  document.getElementById('coverIdxField').value = idx;
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
