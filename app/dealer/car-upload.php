<?php
/**
 * SalesDesk — Car Upload Wizard.
 * T3 owns this file. Also used by exec portal (sets uploaded_by_exec_id).
 *
 * CHANGES (this pass — feature completion + sanity-check fixes):
 *   FEAT-1  Wired up car_features / car_feature_links (migration 0009) —
 *           these tables have existed since 0009 with ZERO UI touching
 *           them. New Step 4 ("Features") lets the dealer tick which
 *           features the car has; publish() now writes the corresponding
 *           car_feature_links rows inside the same transaction as the car.
 *   FEAT-2  Added every migration-0010 field that had no UI at all:
 *           variant, VIN, mm_code (Step 1); engine/drivetrain detail,
 *           doors/seats/interior colour, ownership/service history,
 *           warranty + service plan expiry, VAT treatment (new Step 2,
 *           "Specs & Condition"). None of these existed anywhere in the
 *           wizard before, despite the columns existing since 0010.
 *   BUG-WZ-08  Publish had NO error handling around the INSERT at all.
 *           A duplicate VIN (unique per dealer_id+vin since migration
 *           0011) would throw an uncaught PDOException and crash the
 *           page. Wrapped the whole publish in a transaction with
 *           try/catch — on a duplicate-key error the dealer sees a
 *           friendly message and lands back on the review step with
 *           everything they entered still intact, instead of a blank
 *           fatal-error page.
 *   BUG-WZ-09  Image cap read `defined('MAX_CAR_IMAGES') ? MAX_CAR_IMAGES
 *           : 10` — a constant that, as far as this file's own requires
 *           show, is never actually defined anywhere, so it silently fell
 *           back to a hardcoded 10 every time. Meanwhile CsvImporter
 *           (built separately) correctly reads the real admin-editable
 *           platform_config.max_images_per_car value. The two entry
 *           points could silently disagree if an admin ever changed that
 *           config. Now both read the same source of truth via
 *           getPlatformConfigInt('max_images_per_car', 10).
 *   BUG-WZ-10  VIN and mm_code now insert as NULL when left blank, not
 *           '' — multiple blank-string VINs across different cars for
 *           the same dealer would otherwise collide against the
 *           (dealer_id, vin) unique constraint added in migration 0011
 *           (MySQL does not exempt empty strings from a unique index the
 *           way it exempts true NULLs).
 *
 * Task d2: 6-step wizard (was 4 — Specs & Condition and Features are new)
 *   Step 1: Details — make/model/variant/year/price/mileage/condition/
 *           body/colour/transmission/fuel/drivetrain/vin/mm_code/description
 *   Step 2: Specs & Condition — engine detail, doors/seats/interior colour,
 *           ownership/service history, warranty + service plan, VAT treatment
 *   Step 3: Images (up to platform_config.max_images_per_car, max 2MB each, UUID filenames)
 *   Step 4: Features — ticks against the car_features catalogue (migration 0009)
 *   Step 5: Commission — Fixed (R) or Percentage with live preview
 *   Step 6: Review + publish
 *
 * Called by:
 *   app/dealer/car-upload.php   → uploaded_by_exec_id = NULL
 *   app/exec/car-upload.php     → requires exec guard, sets uploaded_by_exec_id = se.id
 *
 * Role detection: if $_SESSION['user_role'] === 'sales_exec', loads exec mode.
 *
 * ── PRIOR BUG FIXES (kept for audit trail — all still in effect) ──
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
 *              (Now Step 3 — the image step — but the fix and its reasoning
 *              carry forward unchanged; every new step below follows the same
 *              standalone-top-level-form rule from the outset.)
 *
 *   BUG-WZ-02: Same nested-form problem existed in the 10-image-cap branch of
 *              step 2 (the "Continue" form was also nested). Fixed same way.
 *
 *   BUG-WZ-03: After publish, the flash message was built from $wz references
 *              AFTER unset($_SESSION['car_wz']), leaving $wz as a dangling
 *              reference and producing an empty flash string. Fixed by
 *              capturing the display string before unsetting the session key.
 *
 *   BUG-WZ-04: array_push($params) with no second argument (near the car SELECT)
 *              was a no-op left in by mistake. Removed.
 *
 * BUG FIXES (2025-05-11 round 2):
 *   BUG-WZ-05: Step 3 "Back" button was again a nested <form> inside #commForm.
 *              When a browser's HTML parser encounters a <form> inside a <form>,
 *              it implicitly closes the outer form at that point. Fixed by
 *              closing #commForm before the action row and making the Continue
 *              button its own submit form, mirroring the pattern used to fix
 *              step 2 in BUG-WZ-01. (Now Step 5.)
 *
 *   BUG-WZ-07: $commPreview was always blank on the step 4 review page because
 *              the guard was `$wz['commission_value'] > 0` and commission_value
 *              is stored as a float after step 3 saves it, but on first render
 *              of step 4 it comes straight from the session which holds a float.
 *              The real failure mode: if commission_value was saved as the string
 *              '' (the session default), PHP evaluates '' > 0 as false. Fixed by
 *              casting to float explicitly before the comparison so both the empty
 *              string default and a genuine zero are handled consistently.
 *              (Now Step 6.)
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

        // Step 1 — identity + details
        'make'             => '',
        'model'            => '',
        'variant'          => '',
        'vin'              => '',
        'mm_code'          => '',
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

        // Step 2 — specs & condition (FEAT-2)
        'engine_capacity_cc'       => null,
        'cylinders'                => null,
        'induction'                => null,
        'power_kw'                 => null,
        'torque_nm'                => null,
        'gears'                    => null,
        'fuel_consumption_l100km'  => null,
        'co2_emissions_gkm'        => null,
        'doors'                    => null,
        'seats'                    => null,
        'interior_colour'          => '',
        'previous_owners'          => null,
        'service_history'          => 'unknown',
        'has_service_book'         => 0,
        'is_written_off'           => 0,
        'warranty_type'            => 'none',
        'warranty_expiry_date'     => '',
        'warranty_expiry_km'       => null,
        'service_plan_expiry_date' => '',
        'service_plan_expiry_km'   => null,
        'vat_inclusive'            => 1,

        // Step 3 — images
        'image_urls'       => [],

        // Step 4 — features (FEAT-1)
        'feature_ids'      => [],

        // Step 5 — commission
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
        $wz['variant']     = trim($_POST['variant']   ?? '');
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

        // FEAT-2 / BUG-WZ-10: VIN uppercased and length-validated if
        // provided (optional field — only rejected when non-blank AND
        // the wrong length, never required outright).
        $vinInput    = strtoupper(trim($_POST['vin'] ?? ''));
        $mmCodeInput = trim($_POST['mm_code'] ?? '');

        if (!$wz['make'] || !$wz['model'] || !$wz['year'] || $wz['price'] <= 0) {
            $error = 'Please fill in make, model, year, and price.';
        } elseif ($vinInput !== '' && strlen($vinInput) !== 17) {
            $error = 'VIN must be exactly 17 characters if provided.';
        } else {
            $wz['vin']     = $vinInput;
            $wz['mm_code'] = $mmCodeInput;
            $wz['step'] = 2;
            redirect('/app/dealer/car-upload.php');
        }
    }

    // ── Step 2: specs & condition (NEW — FEAT-2) ───────────────
    if ($action === 'step2') {
        $toIntOrNull   = static fn(string $k): ?int   => strlen(trim($_POST[$k] ?? '')) > 0 ? (int) $_POST[$k]   : null;
        $toFloatOrNull = static fn(string $k): ?float => strlen(trim($_POST[$k] ?? '')) > 0 ? (float) $_POST[$k] : null;

        $wz['engine_capacity_cc']      = $toIntOrNull('engine_capacity_cc');
        $wz['cylinders']               = $toIntOrNull('cylinders');
        $wz['induction']               = in_array($_POST['induction'] ?? '', ['na','turbo','twin_turbo','supercharged'], true)
                                          ? $_POST['induction'] : null;
        $wz['power_kw']                = $toIntOrNull('power_kw');
        $wz['torque_nm']                = $toIntOrNull('torque_nm');
        $wz['gears']                    = $toIntOrNull('gears');
        $wz['fuel_consumption_l100km']  = $toFloatOrNull('fuel_consumption_l100km');
        $wz['co2_emissions_gkm']        = $toIntOrNull('co2_emissions_gkm');
        $wz['doors']                    = $toIntOrNull('doors');
        $wz['seats']                    = $toIntOrNull('seats');
        $wz['interior_colour']          = trim($_POST['interior_colour'] ?? '');
        $wz['previous_owners']          = $toIntOrNull('previous_owners');
        $wz['service_history']          = in_array($_POST['service_history'] ?? '', ['full','partial','none','unknown'], true)
                                          ? $_POST['service_history'] : 'unknown';
        $wz['has_service_book']         = !empty($_POST['has_service_book']) ? 1 : 0;
        $wz['is_written_off']           = !empty($_POST['is_written_off']) ? 1 : 0;
        $wz['warranty_type']            = in_array($_POST['warranty_type'] ?? '', ['none','manufacturer','extended','dealer'], true)
                                          ? $_POST['warranty_type'] : 'none';
        $wz['warranty_expiry_date']      = trim($_POST['warranty_expiry_date'] ?? '');
        $wz['warranty_expiry_km']        = $toIntOrNull('warranty_expiry_km');
        $wz['service_plan_expiry_date']  = trim($_POST['service_plan_expiry_date'] ?? '');
        $wz['service_plan_expiry_km']    = $toIntOrNull('service_plan_expiry_km');
        $wz['vat_inclusive']             = !empty($_POST['vat_inclusive']) ? 1 : 0;

        $wz['step'] = 3;
        redirect('/app/dealer/car-upload.php');
    }

    // ── Step 3: image upload (was step2) ───────────────────────
    if ($action === 'step3') {
        // BUG-WZ-09 fix: read the real platform_config value instead of an
        // undefined-by-default constant that silently always fell back to 10.
        $maxImages  = getPlatformConfigInt('max_images_per_car', 10);
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

        if (!$uploadError) {
            $wz['step'] = 4;
            redirect('/app/dealer/car-upload.php');
        }
        $error = $uploadError;
    }

    // ── Step 3: remove an image (was step2_remove) ─────────────
    if ($action === 'step3_remove') {
        $removeIdx = (int) ($_POST['remove_idx'] ?? -1);
        if (isset($wz['image_urls'][$removeIdx])) {
            array_splice($wz['image_urls'], $removeIdx, 1);
        }
        redirect('/app/dealer/car-upload.php');
    }

    // ── Step 4: features (NEW — FEAT-1) ────────────────────────
    if ($action === 'step4') {
        $submittedIds = array_map('intval', (array) ($_POST['features'] ?? []));
        $submittedIds = array_values(array_unique(array_filter($submittedIds, static fn($id) => $id > 0)));

        // Never trust raw POST ids straight into car_feature_links —
        // validate against the real catalogue first. Also keeps the
        // review step (and eventual publish) honest if the catalogue
        // changed between page loads.
        if (!empty($submittedIds)) {
            $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
            $validStmt = $pdo->prepare("SELECT id FROM car_features WHERE id IN ({$placeholders})");
            $validStmt->execute($submittedIds);
            $wz['feature_ids'] = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $wz['feature_ids'] = [];
        }

        $wz['step'] = 5;
        redirect('/app/dealer/car-upload.php');
    }

    // ── Step 5: commission (was step3) ─────────────────────────
    if ($action === 'step5') {
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
            $wz['step'] = 6;
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

        // BUG-WZ-03 FIX (carried forward): capture display values from $wz
        // BEFORE unsetting $_SESSION['car_wz'], since $wz becomes a
        // dangling reference afterward and reads back empty.
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

        /*
         * BUG-WZ-08 fix: everything from here down previously had no
         * error handling at all. A duplicate VIN (unique per
         * dealer_id+vin since migration 0011) — e.g. the dealer fat-
         * fingering the same VIN twice, or a data-entry mistake copying
         * an existing listing — would throw an uncaught PDOException and
         * crash with a raw fatal-error page, silently losing everything
         * the dealer had entered across all six steps.
         *
         * Now: the car insert and its feature links are one transaction.
         * On any failure we roll back, show a friendly message, and stay
         * on the review step (step 6) with the session state untouched
         * so the dealer can just fix the VIN and hit publish again.
         */
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO cars
                    (uuid, dealer_id, uploaded_by_exec_id, slug, make, model, variant, year, price,
                     mileage, condition_type, body_type, colour, transmission, fuel_type,
                     drivetrain, description, vin, mm_code,
                     engine_capacity_cc, cylinders, induction, power_kw, torque_nm, gears,
                     fuel_consumption_l100km, co2_emissions_gkm,
                     previous_owners, service_history, has_service_book, is_written_off,
                     interior_colour, doors, seats,
                     warranty_type, warranty_expiry_date, warranty_expiry_km,
                     service_plan_expiry_date, service_plan_expiry_km, vat_inclusive,
                     commission_type, commission_value,
                     image_urls, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, 'active', NOW(), NOW())
            ")->execute([
                $uuid, $dealerId, $execId, $slug,
                $wz['make'], $wz['model'], $wz['variant'] ?: null, (int)$wz['year'], (float)$wz['price'],
                $wz['mileage'], $wz['condition'], $wz['body_type'] ?: null,
                $wz['colour'] ?: null, $wz['transmission'] ?: null,
                $wz['fuel_type'] ?: null, $wz['drivetrain'] ?: null,
                $wz['description'] ?: null,
                // BUG-WZ-10 fix: VIN/mm_code become NULL when blank rather than ''.
                $wz['vin'] ?: null, $wz['mm_code'] ?: null,

                $wz['engine_capacity_cc'], $wz['cylinders'], $wz['induction'], $wz['power_kw'],
                $wz['torque_nm'], $wz['gears'], $wz['fuel_consumption_l100km'], $wz['co2_emissions_gkm'],

                $wz['previous_owners'], $wz['service_history'], $wz['has_service_book'], $wz['is_written_off'],
                $wz['interior_colour'] ?: null, $wz['doors'], $wz['seats'],

                $wz['warranty_type'], $wz['warranty_expiry_date'] ?: null, $wz['warranty_expiry_km'],
                $wz['service_plan_expiry_date'] ?: null, $wz['service_plan_expiry_km'], $wz['vat_inclusive'],

                $wz['commission_type'], (float)$wz['commission_value'],

                json_encode($wz['image_urls']),
            ]);

            $carId = (int) $pdo->lastInsertId();

            // FEAT-1: write the selected features into car_feature_links —
            // this table has existed since migration 0009 with no UI ever
            // populating it until now.
            if (!empty($wz['feature_ids'])) {
                $linkStmt = $pdo->prepare("
                    INSERT INTO car_feature_links (car_id, feature_id) VALUES (?, ?)
                ");
                foreach ($wz['feature_ids'] as $featureId) {
                    $linkStmt->execute([$carId, (int) $featureId]);
                }
            }

            $pdo->commit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            // SQLSTATE 23000 = integrity constraint violation — covers both
            // the dealer_id+vin unique key and the dealer_id+slug one.
            if ($e->getCode() === '23000') {
                $error = 'A car with this VIN already exists in your inventory. Please check the VIN and try again.';
            } else {
                error_log('[SalesDesk car-upload] publish failed: ' . $e->getMessage());
                $error = 'Something went wrong publishing this listing. Please try again.';
            }
            $wz['step'] = 6;
            $step       = 6; // no redirect on this path, so update the local render variable directly
        }

        // Only clear the wizard and redirect away on genuine success —
        // on failure, $error is set above and execution falls through to
        // the normal render path below, landing back on step 6.
        if (!isset($error)) {
            unset($_SESSION['car_wz']);
            $_SESSION['flash_ok'] = "{$flashYear} {$flashMake} {$flashModel} listed successfully.";
            redirect($isExec ? '/app/exec/inventory.php' : '/app/dealer/inventory.php');
        }
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

// FEAT-2: enum-backed dropdown options — kept as PHP arrays here (rather
// than querying the DB) since these mirror fixed ENUM columns, matching
// how condition_type/commission_type are already handled below.
$inductionOptions = [
    ''             => '— Select —',
    'na'           => 'Naturally Aspirated',
    'turbo'        => 'Turbocharged',
    'twin_turbo'   => 'Twin-Turbo',
    'supercharged' => 'Supercharged',
];
$serviceHistoryOptions = [
    'unknown' => 'Unknown',
    'full'    => 'Full service history',
    'partial' => 'Partial service history',
    'none'    => 'No service history',
];
$warrantyTypeOptions = [
    'none'         => 'No warranty',
    'manufacturer' => 'Manufacturer warranty',
    'extended'     => 'Extended warranty',
    'dealer'       => 'Dealer warranty',
];

// FEAT-1: features catalogue, grouped by category for the Step 4 UI.
$featureRows = $pdo->query("
    SELECT id, category, name, slug, is_popular
    FROM car_features
    ORDER BY category, sort_order
")->fetchAll();

$popularFeatures = [];
$featuresByCategory = [];
foreach ($featureRows as $f) {
    if ((int) $f['is_popular'] === 1) {
        $popularFeatures[] = $f;
    }
    $featuresByCategory[$f['category']][] = $f;
}

$stepLabels = ['details', 'specs', 'images', 'features', 'commission', 'review'];
$pageTitle  = 'List a car';

// Commission preview calc (for step 5 / review)
// BUG-WZ-07 FIX (carried forward): cast both values to float before
// comparison — the session initialises commission_value as '' (empty
// string); PHP evaluates '' > 0 as false, so $commPreview stayed blank on
// the review page even after the user had entered a valid value.
$commPreview = '';
if ((float)$wz['price'] > 0 && (float)$wz['commission_value'] > 0) {
    if ($wz['commission_type'] === 'fixed') {
        $commPreview = 'R ' . number_format((float)$wz['commission_value'], 0);
    } else {
        $commPreview = 'R ' . number_format((float)$wz['price'] * (float)$wz['commission_value'] / 100, 0)
            . ' (' . (float)$wz['commission_value'] . '% of R ' . number_format((float)$wz['price'], 0) . ')';
    }
}

// Selected feature names/categories for the review step.
$selectedFeatures = [];
if (!empty($wz['feature_ids'])) {
    $selectedFeatures = array_values(array_filter(
        $featureRows,
        static fn($f) => in_array((int) $f['id'], array_map('intval', $wz['feature_ids']), true)
    ));
}

ob_start();
?>

<!-- ── Progress pip bar ────────────────────────────────────── -->
<div style="max-width:680px;margin:0 auto 2rem;">
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
<div class="alert alert-error" style="max-width:680px;margin:0 auto 1rem;">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i>
  <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div style="max-width:680px;margin:0 auto;">

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

    <div class="fgroup">
      <label class="flabel" for="variant">Variant / Trim <span class="flabel-opt">(optional)</span></label>
      <input class="finput" type="text" id="variant" name="variant"
             maxlength="150" placeholder="e.g. 1.4T Comfortline DSG"
             value="<?= htmlspecialchars($wz['variant']) ?>">
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

    <!-- FEAT-2: VIN + M&M code — new identity fields from migration 0010 -->
    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="vin">VIN <span class="flabel-opt">(optional, 17 characters)</span></label>
        <input class="finput" type="text" id="vin" name="vin"
               maxlength="17" placeholder="e.g. 1HGCM82633A004352"
               style="text-transform:uppercase;font-family:var(--mono);"
               value="<?= htmlspecialchars($wz['vin']) ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="mm_code">M&amp;M code <span class="flabel-opt">(optional)</span></label>
        <input class="finput" type="text" id="mm_code" name="mm_code"
               maxlength="20" placeholder="TransUnion vehicle code"
               value="<?= htmlspecialchars($wz['mm_code']) ?>">
      </div>
    </div>

    <div class="fgroup">
      <label class="flabel" for="description">Description <span class="flabel-opt">(optional)</span></label>
      <textarea class="finput" id="description" name="description"
                maxlength="2000" rows="4"
                placeholder="Service history, features, condition notes…"><?= htmlspecialchars($wz['description']) ?></textarea>
    </div>

    <button class="btn btn-primary btn-full" type="submit">
      Continue — specs &amp; condition <i class="fa-solid fa-arrow-right"></i>
    </button>
  </form>
</div>

<?php elseif ($step === 2): ?>
<!-- ═══════════════════════════════
     STEP 2 — Specs & condition (NEW — FEAT-2)
     ═══════════════════════════════ -->
<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:.5rem;">
    Specs &amp; <em style="font-style:italic;">condition</em>
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.6;">
    Every field on this step is optional — fill in what you know. More detail
    here means better search results and buyer confidence, but nothing here
    blocks publishing.
  </p>

  <!-- Inputs-only form — Back/Continue are standalone forms below (BUG-WZ-01 pattern) -->
  <form method="POST" id="specsForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step2">

    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                color:var(--faint);margin-bottom:10px;">Engine &amp; drivetrain</div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="engine_capacity_cc">Engine capacity (cc)</label>
        <input class="finput" type="number" id="engine_capacity_cc" name="engine_capacity_cc"
               min="0" step="1" placeholder="e.g. 1998"
               value="<?= $wz['engine_capacity_cc'] !== null ? $wz['engine_capacity_cc'] : '' ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="cylinders">Cylinders</label>
        <input class="finput" type="number" id="cylinders" name="cylinders"
               min="0" step="1" placeholder="e.g. 4"
               value="<?= $wz['cylinders'] !== null ? $wz['cylinders'] : '' ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="induction">Induction</label>
        <select class="finput" id="induction" name="induction">
          <?php foreach ($inductionOptions as $val => $label): ?>
          <option value="<?= htmlspecialchars($val) ?>" <?= $wz['induction'] === $val ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fgroup">
        <label class="flabel" for="power_kw">Power (kW)</label>
        <input class="finput" type="number" id="power_kw" name="power_kw"
               min="0" step="1" placeholder="e.g. 110"
               value="<?= $wz['power_kw'] !== null ? $wz['power_kw'] : '' ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="torque_nm">Torque (Nm)</label>
        <input class="finput" type="number" id="torque_nm" name="torque_nm"
               min="0" step="1" placeholder="e.g. 250"
               value="<?= $wz['torque_nm'] !== null ? $wz['torque_nm'] : '' ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="gears">Gears</label>
        <input class="finput" type="number" id="gears" name="gears"
               min="0" step="1" placeholder="e.g. 7"
               value="<?= $wz['gears'] !== null ? $wz['gears'] : '' ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="fuel_consumption_l100km">Fuel consumption (L/100km)</label>
        <input class="finput" type="number" id="fuel_consumption_l100km" name="fuel_consumption_l100km"
               min="0" step="0.1" placeholder="e.g. 6.5"
               value="<?= $wz['fuel_consumption_l100km'] !== null ? $wz['fuel_consumption_l100km'] : '' ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="co2_emissions_gkm">CO₂ emissions (g/km)</label>
        <input class="finput" type="number" id="co2_emissions_gkm" name="co2_emissions_gkm"
               min="0" step="1" placeholder="e.g. 145"
               value="<?= $wz['co2_emissions_gkm'] !== null ? $wz['co2_emissions_gkm'] : '' ?>">
      </div>
    </div>

    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                color:var(--faint);margin:1.5rem 0 10px;">Interior &amp; ownership</div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="doors">Doors</label>
        <input class="finput" type="number" id="doors" name="doors"
               min="0" max="6" step="1" placeholder="e.g. 5"
               value="<?= $wz['doors'] !== null ? $wz['doors'] : '' ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="seats">Seats</label>
        <input class="finput" type="number" id="seats" name="seats"
               min="0" max="17" step="1" placeholder="e.g. 5"
               value="<?= $wz['seats'] !== null ? $wz['seats'] : '' ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="interior_colour">Interior colour</label>
        <input class="finput" type="text" id="interior_colour" name="interior_colour"
               maxlength="40" placeholder="e.g. Black"
               value="<?= htmlspecialchars($wz['interior_colour']) ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="previous_owners">Previous owners</label>
        <input class="finput" type="number" id="previous_owners" name="previous_owners"
               min="0" step="1" placeholder="e.g. 1"
               value="<?= $wz['previous_owners'] !== null ? $wz['previous_owners'] : '' ?>">
      </div>
    </div>

    <div class="fgroup">
      <label class="flabel" for="service_history">Service history</label>
      <select class="finput" id="service_history" name="service_history">
        <?php foreach ($serviceHistoryOptions as $val => $label): ?>
        <option value="<?= htmlspecialchars($val) ?>" <?= $wz['service_history'] === $val ? 'selected' : '' ?>>
          <?= htmlspecialchars($label) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;gap:20px;margin-bottom:1.25rem;">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);cursor:pointer;">
        <input type="checkbox" name="has_service_book" value="1"
               <?= $wz['has_service_book'] ? 'checked' : '' ?>>
        Full service book present
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--red);cursor:pointer;">
        <input type="checkbox" name="is_written_off" value="1"
               <?= $wz['is_written_off'] ? 'checked' : '' ?>>
        Insurance write-off / accident-damaged
      </label>
    </div>

    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                color:var(--faint);margin-bottom:10px;">Warranty &amp; service plan</div>

    <div class="fgroup">
      <label class="flabel" for="warranty_type">Warranty</label>
      <select class="finput" id="warranty_type" name="warranty_type">
        <?php foreach ($warrantyTypeOptions as $val => $label): ?>
        <option value="<?= htmlspecialchars($val) ?>" <?= $wz['warranty_type'] === $val ? 'selected' : '' ?>>
          <?= htmlspecialchars($label) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="warranty_expiry_date">Warranty expires (date)</label>
        <input class="finput" type="date" id="warranty_expiry_date" name="warranty_expiry_date"
               value="<?= htmlspecialchars($wz['warranty_expiry_date']) ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="warranty_expiry_km">Warranty expires (km)</label>
        <input class="finput" type="number" id="warranty_expiry_km" name="warranty_expiry_km"
               min="0" step="1" placeholder="e.g. 100000"
               value="<?= $wz['warranty_expiry_km'] !== null ? $wz['warranty_expiry_km'] : '' ?>">
      </div>
    </div>

    <div class="frow">
      <div class="fgroup">
        <label class="flabel" for="service_plan_expiry_date">Service plan expires (date)</label>
        <input class="finput" type="date" id="service_plan_expiry_date" name="service_plan_expiry_date"
               value="<?= htmlspecialchars($wz['service_plan_expiry_date']) ?>">
      </div>
      <div class="fgroup">
        <label class="flabel" for="service_plan_expiry_km">Service plan expires (km)</label>
        <input class="finput" type="number" id="service_plan_expiry_km" name="service_plan_expiry_km"
               min="0" step="1" placeholder="e.g. 90000"
               value="<?= $wz['service_plan_expiry_km'] !== null ? $wz['service_plan_expiry_km'] : '' ?>">
      </div>
    </div>

    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);
                  cursor:pointer;margin-bottom:.5rem;">
      <input type="checkbox" name="vat_inclusive" value="1"
             <?= $wz['vat_inclusive'] ? 'checked' : '' ?>>
      Price includes VAT (uncheck if sold under the second-hand/margin scheme)
    </label>

  </form><!-- /specsForm — closed here; Back/Continue are standalone forms below -->

  <div style="display:flex;gap:8px;margin-top:1rem;">
    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>
    <button class="btn btn-primary" type="button" style="flex:1;"
            onclick="document.getElementById('specsForm').requestSubmit();">
      Continue — add images <i class="fa-solid fa-arrow-right"></i>
    </button>
  </div>
</div>

<?php elseif ($step === 3): ?>
<!-- ═══════════════════════════════
     STEP 3 — Images (was step 2)
     ═══════════════════════════════ -->

<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:.5rem;">
    Vehicle <em style="font-style:italic;">photos</em>
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
    Upload up to <?= (int) getPlatformConfigInt('max_images_per_car', 10) ?> photos.
    JPG, PNG, WebP — max 2MB each. The first image will be the cover photo.
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
        <input type="hidden" name="action" value="step3_remove">
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

  <?php $imageCount = count($wz['image_urls']); $maxImagesConfig = getPlatformConfigInt('max_images_per_car', 10); ?>

  <?php if ($imageCount < $maxImagesConfig): ?>

  <!--
    Upload form — standalone, no other forms nested inside.
    The Back and Continue actions are handled by separate forms below.
  -->
  <form method="POST" enctype="multipart/form-data" id="uploadForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step3">

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

    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>

    <!--
      "Continue" submits the upload form above via JS so the
      multipart/form-data enctype is preserved for file delivery.
      If no files were chosen, the form posts with an empty files
      array — the handler sets step=4 and redirects as expected,
      allowing users to skip images if they have none to add yet.
    -->
    <button class="btn btn-primary" type="button" style="flex:1;"
            onclick="document.getElementById('uploadForm').submit();">
      Continue — features <i class="fa-solid fa-arrow-right"></i>
    </button>

  </div>

  <?php else: /* imageCount === max */ ?>

  <div class="alert alert-info">
    <i class="fa-solid fa-circle-info alert-icon"></i>
    Maximum <?= (int) $maxImagesConfig ?> images uploaded. Remove one to add another.
  </div>

  <div style="display:flex;gap:8px;margin-top:1rem;">

    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>

    <form method="POST" style="margin:0;flex:1;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="step3">
      <button class="btn btn-primary btn-full" type="submit">
        Continue — features <i class="fa-solid fa-arrow-right"></i>
      </button>
    </form>

  </div>

  <?php endif; ?>

</div>

<?php elseif ($step === 4): ?>
<!-- ═══════════════════════════════
     STEP 4 — Features (NEW — FEAT-1)
     ═══════════════════════════════ -->
<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:.5rem;">
    Vehicle <em style="font-style:italic;">features</em>
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
    Optional, but listings with features ticked get more buyer engagement.
    Popular features are shown first — expand a category below for the full list.
  </p>

  <form method="POST" id="featuresForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step4">

    <?php if (!empty($popularFeatures)): ?>
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                color:var(--faint);margin-bottom:10px;">Popular</div>
    <div class="chip-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:1.5rem;">
      <?php foreach ($popularFeatures as $f):
        $checked = in_array((int) $f['id'], array_map('intval', $wz['feature_ids']), true);
      ?>
      <label class="feature-chip" style="display:inline-flex;align-items:center;cursor:pointer;
             padding:7px 12px;border-radius:var(--r-full);font-size:12px;font-weight:600;
             border:1.5px solid <?= $checked ? 'var(--p)' : 'var(--border)' ?>;
             background:<?= $checked ? 'var(--p-light)' : 'var(--white)' ?>;
             color:<?= $checked ? 'var(--p)' : 'var(--muted)' ?>;">
        <input type="checkbox" name="features[]" value="<?= (int) $f['id'] ?>"
               <?= $checked ? 'checked' : '' ?>
               style="display:none;" class="feature-checkbox"
               onchange="
                 this.closest('label').style.borderColor = this.checked ? 'var(--p)' : 'var(--border)';
                 this.closest('label').style.background  = this.checked ? 'var(--p-light)' : 'var(--white)';
                 this.closest('label').style.color        = this.checked ? 'var(--p)' : 'var(--muted)';
               ">
        <?= htmlspecialchars($f['name']) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                color:var(--faint);margin-bottom:10px;">All categories</div>

    <?php foreach ($featuresByCategory as $category => $features): ?>
    <details style="border:1px solid var(--border);border-radius:var(--r-md);margin-bottom:8px;overflow:hidden;">
      <summary style="padding:10px 14px;font-size:13px;font-weight:600;color:var(--text);
                       cursor:pointer;background:var(--bg);list-style:none;
                       display:flex;align-items:center;justify-content:space-between;">
        <?= htmlspecialchars($category) ?>
        <?php
          $catSelectedCount = count(array_filter($features, static fn($f) =>
              in_array((int) $f['id'], array_map('intval', $wz['feature_ids']), true)));
        ?>
        <?php if ($catSelectedCount > 0): ?>
        <span style="font-size:11px;font-weight:700;background:var(--p);color:#fff;
                     border-radius:var(--r-full);padding:1px 8px;"><?= $catSelectedCount ?> selected</span>
        <?php endif; ?>
      </summary>
      <div style="padding:12px 14px;display:grid;grid-template-columns:repeat(2,1fr);gap:6px 12px;">
        <?php foreach ($features as $f): ?>
        <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text2);cursor:pointer;">
          <input type="checkbox" name="features[]" value="<?= (int) $f['id'] ?>"
                 <?= in_array((int) $f['id'], array_map('intval', $wz['feature_ids']), true) ? 'checked' : '' ?>>
          <?= htmlspecialchars($f['name']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>

  </form><!-- /featuresForm — Back/Continue are standalone forms below -->

  <div style="display:flex;gap:8px;margin-top:1.25rem;">
    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>
    <button class="btn btn-primary" type="button" style="flex:1;"
            onclick="document.getElementById('featuresForm').requestSubmit();">
      Continue — set commission <i class="fa-solid fa-arrow-right"></i>
    </button>
  </div>
</div>

<?php elseif ($step === 5): ?>
<!-- ═══════════════════════════════
     STEP 5 — Commission (was step 3)
     ═══════════════════════════════ -->
<div class="card card-body">
  <h2 style="font-family:var(--serif);font-size:1.2rem;font-weight:300;margin-bottom:.5rem;">
    Broker <em style="font-style:italic;">commission</em>
  </h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;line-height:1.6;">
    Set how much brokers earn per sale. A competitive commission attracts more broker activity.
  </p>

  <!-- Inputs-only form — closed before any action buttons (BUG-WZ-05 pattern) -->
  <form method="POST" id="commForm">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="step5">

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

  <div style="display:flex;gap:8px;margin-top:0;">

    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="back">
      <button class="btn btn-ghost" type="submit">← Back</button>
    </form>

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

<?php elseif ($step === 6): ?>
<!-- ═══════════════════════════════
     STEP 6 — Review + publish (was step 4)
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
      ['Vehicle',      "{$wz['year']} {$wz['make']} {$wz['model']}" . ($wz['variant'] ? " {$wz['variant']}" : '')],
      ['Price',        'R ' . number_format((float)$wz['price'], 0) . ($wz['vat_inclusive'] ? ' (incl. VAT)' : ' (margin scheme, excl. VAT)')],
      ['Condition',    ucfirst($wz['condition'])],
      ['Mileage',      $wz['mileage'] !== null ? number_format($wz['mileage']) . ' km' : '—'],
      ['Body type',    $wz['body_type'] ?: '—'],
      ['Colour',       $wz['colour'] ?: '—'],
      ['Transmission', $wz['transmission'] ?: '—'],
      ['Fuel',         $wz['fuel_type'] ?: '—'],
      ['Drivetrain',   $wz['drivetrain'] ?: '—'],
      ['VIN',          $wz['vin'] ?: '—'],
      ['M&M code',     $wz['mm_code'] ?: '—'],
      ['Engine',       $wz['engine_capacity_cc'] !== null
                           ? number_format($wz['engine_capacity_cc']) . ' cc'
                             . ($wz['cylinders'] !== null ? ", {$wz['cylinders']}-cyl" : '')
                             . ($wz['power_kw'] !== null ? ", {$wz['power_kw']} kW" : '')
                             . ($wz['torque_nm'] !== null ? ", {$wz['torque_nm']} Nm" : '')
                           : '—'],
      ['Doors / Seats', ($wz['doors'] !== null || $wz['seats'] !== null)
                           ? ($wz['doors'] ?? '—') . ' / ' . ($wz['seats'] ?? '—')
                           : '—'],
      ['Service history', $serviceHistoryOptions[$wz['service_history']] ?? ucfirst($wz['service_history'])],
      ['Written off',  $wz['is_written_off'] ? 'Yes' : 'No'],
      ['Warranty',     $warrantyTypeOptions[$wz['warranty_type']] ?? ucfirst($wz['warranty_type'])],
      ['Commission',   $commPreview],
      ['Features',     count($selectedFeatures) . ' selected'],
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

  <?php if (!empty($selectedFeatures)): ?>
  <div style="font-size:13px;margin-bottom:1.25rem;">
    <div style="font-weight:600;color:var(--text);margin-bottom:8px;">Selected features</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($selectedFeatures as $f): ?>
      <span style="background:var(--p-light);color:var(--p);font-size:11px;font-weight:600;
                   padding:3px 10px;border-radius:var(--r-full);border:1px solid var(--p-b);">
        <?= htmlspecialchars($f['name']) ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

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

// Cover photo picker (step 6)
var _coverIdx = 0;
function setCoverPhoto(idx) {
  if (idx === _coverIdx) return;
  // Update big preview
  var imgs = <?= $step === 6 ? json_encode($wz['image_urls']) : '[]' ?>;
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
