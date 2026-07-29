<?php
/**
 * SalesDesk — Authentication handler.
 *
 * FIXES APPLIED:
 *   FIX-02: $_SESSION['created'] → $_SESSION['_created'] in handleLogin()
 *           to match the canonical key defined in session.php.
 *
 *   FIX-03: password_hash() / password_needs_rehash() use PASSWORD_ALGO
 *           (Argon2id) instead of the hardcoded PASSWORD_BCRYPT literal.
 *
 *   FIX-07: finaliseOnboarding() now redirects directly to the role-specific
 *           dashboard instead of bouncing through register.php.
 *
 *   FIX-06: Role-scoped record activation in finaliseOnboarding().
 *
 *   FIX-SKIP-01: wizard_skip_dealership converted to POST-only with CSRF
 *           validation and session guard.
 *
 *   FIX-SKIP-02: wizard_skip_address converted to POST-only with CSRF
 *           validation and session guard.
 *
 *   FIX-09: Infinite redirect loop for sales_exec who skipped dealership
 *           selection during onboarding.
 *
 *           When an exec skips dealership selection, no row is inserted into
 *           sales_executives. After onboarding completes they land at
 *           /app/exec/dashboard.php. requireExecVerified() finds no
 *           sales_executives record and renders the "No dealership linked"
 *           status page, which points back to /auth/register.php. The
 *           FIX-08 guard in register.php now detects this case, initialises
 *           a relink wizard session (relink=true) at step 3, and lets the
 *           exec pick a dealership.
 *
 *           Changes in this file (three functions):
 *
 *           handleWizardDealership():
 *             After upserting the sales_executives row, check $wz['relink'].
 *             If set, call finaliseOnboarding() immediately — the exec's
 *             profile / password / address steps are already complete.
 *
 *           handleWizardSkipDealership():
 *             Same: if relink=true, call finaliseOnboarding() so the exec
 *             lands on the step-99 "no dealership" screen rather than being
 *             pushed into wizard steps they already completed.
 *
 *           finaliseOnboarding():
 *             In relink mode the exec is ALREADY logged in. Skip the welcome
 *             email (already sent), skip activating records (already done),
 *             skip session_regenerate_id (session is live). Just set step=99
 *             and redirect to register.php which renders the confirmation.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

// ── GET-only actions (no session mutation, no CSRF needed) ────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['action'] ?? '') !== 'wizard_reset') {
    redirect('/auth/login.php');
}

$action = $_GET['action'] ?? '';

if ($action === 'wizard_reset') {
    $_SESSION['wz'] = null;
    unset($_SESSION['wz']);
    redirect('/auth/register.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/auth/login.php');
}

validateCSRF();

match ($action) {
    'wizard_email'            => handleWizardEmail(),
    'wizard_otp'              => handleWizardOTP(),
    'wizard_resend'           => handleWizardResend(),
    'wizard_dealership'       => handleWizardDealership(),
    'wizard_skip_dealership'  => handleWizardSkipDealership(),
    'wizard_company'          => handleWizardCompany(),
    'wizard_desk'             => handleWizardDesk(),
    'wizard_profile'          => handleWizardProfile(),
    'wizard_password'         => handleWizardPassword(),
    'wizard_address'          => handleWizardAddress(),
    'wizard_skip_address'     => handleWizardSkipAddress(),
    'login'                   => handleLogin(),
    default                   => redirect('/auth/login.php'),
};


// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function wizardRedirect(int $step, string $error = '', string $info = ''): never
{
    $_SESSION['wz']['step'] = $step;
    $qs = $error ? ('?error=' . urlencode($error)) : ($info ? ('?info=' . urlencode($info)) : '');
    redirect('/auth/register.php' . $qs);
}

function isSalesExec(): bool
{
    return ($_SESSION['wz']['role'] ?? '') === 'sales_exec';
}

function isDealer(): bool
{
    return ($_SESSION['wz']['role'] ?? '') === 'dealer';
}


// ═══════════════════════════════════════════════════════════
// STEP 1 — Email + role
// ═══════════════════════════════════════════════════════════
function handleWizardEmail(): never
{
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $role  = in_array($_POST['role'] ?? '', ['broker', 'dealer', 'sales_exec'], true)
             ? $_POST['role'] : 'broker';

    if (!$email) {
        wizardRedirect(1, 'Please enter a valid email address.');
    }

    if (empty($_SESSION['wz'])) {
        $_SESSION['wz'] = [];
    }
    $_SESSION['wz']['role']  = $role;
    $_SESSION['wz']['email'] = $email;

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT id, email_verified, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing && $existing['email_verified'] && $existing['status'] === 'active') {
        wizardRedirect(1, 'That email is already registered. Please sign in instead.');
    }

    if ($existing) {
        $userId = (int) $existing['id'];
        $pdo->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$role, $userId]);
    } else {
        $uuid = generateUuidV4();
        $pdo->prepare("
            INSERT INTO users (uuid, email, role, password_hash, status, email_verified, created_at, updated_at)
            VALUES (?, ?, ?, '', 'pending', 0, NOW(), NOW())
        ")->execute([$uuid, $email, $role]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO profiles (user_id, onboarding_step, onboarding_completed, created_at, updated_at)
            VALUES (?, 1, 0, NOW(), NOW())
        ")->execute([$userId]);

        if ($role === 'broker') {
            $slug = 'desk-' . substr(str_replace('-', '', $uuid), 0, 8);
            $pdo->prepare("
                INSERT INTO salesdesks (uuid, user_id, slug, display_name, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 'My SalesDesk', 0, NOW(), NOW())
            ")->execute([generateUuidV4(), $userId, $slug]);
        }

        if ($role === 'dealer') {
            $dSlug = 'dealer-' . substr(str_replace('-', '', $uuid), 0, 8);
            $pdo->prepare("
                INSERT INTO dealers (uuid, user_id, slug, company_name, verification_status, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 'My Dealership', 'unverified', 0, NOW(), NOW())
            ")->execute([generateUuidV4(), $userId, $dSlug]);
        }
    }

    $_SESSION['wz']['user_id'] = $userId;

    $otp = generateAndStoreOTP($userId, 'email_verify');
    sendVerificationOTP($email, $otp);

    wizardRedirect(2, info: 'A 6-digit code has been sent to ' . $email);
}


// ═══════════════════════════════════════════════════════════
// STEP 2 — OTP verification
// ═══════════════════════════════════════════════════════════
function handleWizardOTP(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $submitted = trim($_POST['otp'] ?? '');

    if (!preg_match('/^\d{6}$/', $submitted)) {
        wizardRedirect(2, 'Please enter the 6-digit code.');
    }

    if (!verifyOTP($userId, 'email_verify', $submitted)) {
        wizardRedirect(2, 'That code is incorrect or has expired. Request a new one.');
    }

    $pdo = Database::getInstance();
    $pdo->prepare("UPDATE users SET email_verified = 1, status = 'active', updated_at = NOW() WHERE id = ?")
        ->execute([$userId]);
    $pdo->prepare("UPDATE profiles SET onboarding_step = 2 WHERE user_id = ?")
        ->execute([$userId]);

    wizardRedirect(3);
}


// ═══════════════════════════════════════════════════════════
// STEP 2 — Resend OTP
// ═══════════════════════════════════════════════════════════
function handleWizardResend(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);
    $email  = $wz['email'] ?? '';

    if (!$userId || !$email) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $otp = generateAndStoreOTP($userId, 'email_verify');
    sendVerificationOTP($email, $otp);

    wizardRedirect(2, info: 'A new code has been sent to ' . $email);
}


// ═══════════════════════════════════════════════════════════
// STEP 3 (broker only) — SalesDesk name + slug
// ═══════════════════════════════════════════════════════════
function handleWizardDesk(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId || isSalesExec() || isDealer()) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $displayName = trim($_POST['desk_display_name'] ?? '');

    if (!$displayName) {
        wizardRedirect(3, 'Please enter a name for your SalesDesk.');
    }
    if (strlen($displayName) > 120) {
        wizardRedirect(3, 'Desk name must be 120 characters or fewer.');
    }

    $slug = strtolower($displayName);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 60);

    if (!$slug) {
        $slug = strtolower(preg_replace('/[^a-z0-9]/', '-', explode('@', $wz['email'] ?? 'desk')[0]));
        $slug = trim(substr($slug, 0, 60), '-') ?: 'my-desk';
    }

    $pdo = Database::getInstance();

    $baseSlug = $slug;
    $suffix   = 2;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM salesdesks WHERE slug = ? AND user_id != ? LIMIT 1");
        $stmt->execute([$slug, $userId]);
        if (!$stmt->fetch()) {
            break;
        }
        $slug = $baseSlug . '-' . $suffix++;
    }

    $pdo->prepare("
        UPDATE salesdesks
        SET slug = ?, display_name = ?, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$slug, $displayName, $userId]);

    $pdo->prepare("UPDATE profiles SET onboarding_step = 3 WHERE user_id = ?")
        ->execute([$userId]);

    $wz['desk_display_name'] = $displayName;
    $wz['desk_slug']         = $slug;

    wizardRedirect(4);
}


// ═══════════════════════════════════════════════════════════
// STEP 3 (dealer only) — Company profile
// ═══════════════════════════════════════════════════════════
function handleWizardCompany(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId || !isDealer()) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $companyName = trim($_POST['company_name'] ?? '');
    $brandFocus  = trim($_POST['brand_focus']  ?? '');
    $firstName   = trim($_POST['first_name']   ?? '');
    $lastName    = trim($_POST['last_name']    ?? '');
    $phone       = trim($_POST['phone']        ?? '');

    if (!$companyName) {
        wizardRedirect(3, 'Please enter your dealership name.');
    }
    if (!$firstName || !$lastName) {
        wizardRedirect(3, 'Please enter the account holder\'s first and last name.');
    }

    $brandFocusJson = null;
    if ($brandFocus) {
        $brands = array_filter(array_map('trim', explode(',', $brandFocus)));
        $brandFocusJson = json_encode(array_values($brands));
    }

    $pdo = Database::getInstance();

    $pdo->prepare("
        UPDATE dealers
        SET company_name = ?, brand_focus = ?, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$companyName, $brandFocusJson, $userId]);

    $pdo->prepare("
        UPDATE profiles
        SET first_name = ?, last_name = ?, phone = ?, onboarding_step = 3, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$firstName, $lastName, $phone ?: null, $userId]);

    $cipcDocUrl = null;
    if (!empty($_FILES['cipc_doc']['tmp_name']) && $_FILES['cipc_doc']['error'] === UPLOAD_ERR_OK) {
        $maxSize = defined('MAX_PDF_SIZE_BYTES') ? MAX_PDF_SIZE_BYTES : 5242880;
        if ($_FILES['cipc_doc']['size'] <= $maxSize) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['cipc_doc']['tmp_name']);
            finfo_close($finfo);
            if ($mime === 'application/pdf') {
                $uploadDir = (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__) . '/uploads') . '/cipc/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename   = generateUuidV4() . '.pdf';
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['cipc_doc']['tmp_name'], $targetPath)) {
                    $cipcDocUrl = '/uploads/cipc/' . $filename;
                    $pdo->prepare("
                        UPDATE dealers
                        SET cipc_doc_url = ?, verification_status = 'pending', updated_at = NOW()
                        WHERE user_id = ?
                    ")->execute([$cipcDocUrl, $userId]);
                }
            }
        }
    }

    $wz['company_name']  = $companyName;
    $wz['brand_focus']   = $brandFocus;
    $wz['first_name']    = $firstName;
    $wz['last_name']     = $lastName;
    $wz['phone']         = $phone;
    $wz['cipc_doc_url']  = $cipcDocUrl;

    wizardRedirect(4);
}


// ═══════════════════════════════════════════════════════════
// STEP 3 (sales_exec) — Dealership selection
//
// FIX-09: If $wz['relink'] is true the exec is already fully onboarded
// and just needs the sales_executives row. After upserting it, call
// finaliseOnboarding() directly instead of advancing through steps the
// exec already completed.
// ═══════════════════════════════════════════════════════════
function handleWizardDealership(): never
{
    $wz       = &$_SESSION['wz'];
    $userId   = (int) ($wz['user_id'] ?? 0);
    $dealerId = (int) ($_POST['dealer_id'] ?? 0);
    $isRelink = !empty($wz['relink']); // FIX-09

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    if ($dealerId) {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, company_name, user_id FROM dealers WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$dealerId]);
        $dealer = $stmt->fetch();

        if (!$dealer) {
            wizardRedirect(3, 'That dealership was not found or is not currently active. Please try another.');
        }

        $dealerName = $dealer['company_name'];
        $jobTitle   = trim($_POST['job_title'] ?? '');

        $pdo->prepare("
            INSERT INTO sales_executives
                (user_id, dealer_id, job_title, verification_status, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                dealer_id           = VALUES(dealer_id),
                job_title           = VALUES(job_title),
                verification_status = 'pending',
                rejection_reason    = NULL,
                updated_at          = NOW()
        ")->execute([$userId, $dealerId, $jobTitle ?: null]);

        $pdo->prepare("UPDATE profiles SET onboarding_step = 3 WHERE user_id = ?")
            ->execute([$userId]);

        $principalStmt = $pdo->prepare("SELECT u.email FROM users u WHERE u.id = ?");
        $principalStmt->execute([$dealer['user_id']]);
        $principal = $principalStmt->fetch();

        if ($principal) {
            $execEmail = $wz['email'] ?? '';
            sendSalesExecJoinRequest($principal['email'], $dealerName, $execEmail);
        }

        $wz['dealer_id']   = $dealerId;
        $wz['dealer_name'] = $dealerName;
        $wz['job_title']   = $jobTitle;
    }

    // FIX-09: relink mode — skip profile/password/address, go to confirmation.
    if ($isRelink) {
        finaliseOnboarding();
    }

    wizardRedirect(4);
}


// ═══════════════════════════════════════════════════════════
// STEP 3 (sales_exec only) — Skip dealership selection
//
// FIX-SKIP-01: Converted to POST-only with CSRF validation.
//
// FIX-09: If relink=true, call finaliseOnboarding() directly so the exec
// lands on the step-99 fallback screen instead of being pushed into wizard
// steps they already completed.
// ═══════════════════════════════════════════════════════════
function handleWizardSkipDealership(): never
{
    $wz       = &$_SESSION['wz'];
    $userId   = (int) ($wz['user_id'] ?? 0);
    $isRelink = !empty($wz['relink']); // FIX-09

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    if (!isSalesExec()) {
        redirect('/auth/register.php');
    }

    $wz['dealer_id']   = null;
    $wz['dealer_name'] = '';
    $wz['job_title']   = '';

    // FIX-09: relink mode — skip already-completed steps.
    if ($isRelink) {
        finaliseOnboarding();
    }

    $wz['step'] = 4;
    wizardRedirect(4);
}


// ═══════════════════════════════════════════════════════════
// STEP 3/4 — Profile (name, phone, bio)
// ═══════════════════════════════════════════════════════════
function handleWizardProfile(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $phone     = trim($_POST['phone']      ?? '');
    $bio       = trim($_POST['bio']        ?? '');

    if (!$firstName || !$lastName) {
        wizardRedirect(4, 'Please enter your first and last name.');
    }

    $pdo = Database::getInstance();

    $pdo->prepare("
        UPDATE profiles
        SET first_name = ?, last_name = ?, phone = ?, bio = ?, onboarding_step = 4, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$firstName, $lastName, $phone ?: null, $bio ?: null, $userId]);

    $wz['first_name'] = $firstName;
    $wz['last_name']  = $lastName;
    $wz['phone']      = $phone;
    $wz['bio']        = $bio;

    wizardRedirect(5);
}


// ═══════════════════════════════════════════════════════════
// STEP 4/5 — Password
//
// FIX-03: Use PASSWORD_ALGO constant (Argon2id).
// ═══════════════════════════════════════════════════════════
function handleWizardPassword(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $pw      = $_POST['password']         ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (
        strlen($pw) < 8 ||
        !preg_match('/[A-Z]/', $pw) ||
        !preg_match('/[a-z]/', $pw) ||
        !preg_match('/[0-9]/', $pw) ||
        !preg_match('/[^A-Za-z0-9]/', $pw)
    ) {
        $pwStep = isDealer() ? 4 : 5;
        wizardRedirect($pwStep, 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.');
    }

    if ($pw !== $confirm) {
        $pwStep = isDealer() ? 4 : 5;
        wizardRedirect($pwStep, 'Passwords do not match.');
    }

    $hash = password_hash($pw, PASSWORD_ALGO);
    $pdo  = Database::getInstance();
    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$hash, $userId]);
    $pdo->prepare("UPDATE profiles SET onboarding_step = ? WHERE user_id = ?")
        ->execute([isDealer() ? 4 : 5, $userId]);

    wizardRedirect(isDealer() ? 5 : 6);
}


// ═══════════════════════════════════════════════════════════
// STEP 5/6 — Address
// ═══════════════════════════════════════════════════════════
function handleWizardAddress(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    $province     = trim($_POST['province']     ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $city         = trim($_POST['city']         ?? '');
    $suburb       = trim($_POST['suburb']       ?? '');

    if ($province) {
        $pdo = Database::getInstance();
        $pdo->prepare("
            INSERT INTO addresses (province, municipality, city, suburb, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ")->execute([$province, $municipality ?: null, $city ?: null, $suburb ?: null]);
        $addressId = (int) $pdo->lastInsertId();

        if (isDealer()) {
            $pdo->prepare("UPDATE dealers SET address_id = ?, updated_at = NOW() WHERE user_id = ?")
                ->execute([$addressId, $userId]);
        } else {
            $pdo->prepare("UPDATE profiles SET address_id = ? WHERE user_id = ?")
                ->execute([$addressId, $userId]);
        }

        $wz['province']     = $province;
        $wz['municipality'] = $municipality;
        $wz['city']         = $city;
        $wz['suburb']       = $suburb;
    }

    finaliseOnboarding();
}


// ═══════════════════════════════════════════════════════════
// STEP 5/6 — Skip address
//
// FIX-SKIP-02: Converted to POST-only with CSRF validation.
// ═══════════════════════════════════════════════════════════
function handleWizardSkipAddress(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    finaliseOnboarding();
}


// ═══════════════════════════════════════════════════════════
// Finalise — mark onboarding complete, send welcome email,
// log the user in, redirect to role-specific dashboard.
//
// FIX-07: No longer bounces through register.php. Step-99 screen is
//         preserved only for sales_exec pending approval.
//
// FIX-09: Relink mode — exec is already logged in and their account is
//         fully set up. Do NOT re-send welcome email, re-activate records,
//         or regenerate session. Just set step=99 and redirect to
//         register.php to show the confirmation screen.
// ═══════════════════════════════════════════════════════════
function finaliseOnboarding(): never
{
    $wz       = &$_SESSION['wz'];
    $userId   = (int) ($wz['user_id'] ?? 0);
    $email    = $wz['email']  ?? '';
    $role     = $wz['role']   ?? 'broker';
    $isRelink = !empty($wz['relink']); // FIX-09

    if (!$userId) {
        redirect('/auth/register.php');
    }

    $pdo = Database::getInstance();

    // FIX-09: skip all account-setup DB writes in relink mode — they were
    // completed during the original onboarding flow.
    if (!$isRelink) {
        $pdo->prepare("
            UPDATE profiles
            SET onboarding_step = 99, onboarding_completed = 1, updated_at = NOW()
            WHERE user_id = ?
        ")->execute([$userId]);

        // FIX-06: only activate the record type that belongs to this role.
        if ($role === 'broker') {
            $pdo->prepare("UPDATE salesdesks SET is_active = 1, updated_at = NOW() WHERE user_id = ?")
                ->execute([$userId]);
        } elseif ($role === 'dealer') {
            $pdo->prepare("UPDATE dealers SET is_active = 1, updated_at = NOW() WHERE user_id = ?")
                ->execute([$userId]);
        }

        if ($role === 'dealer') {
            $displayName = $wz['company_name'] ?? 'your dealership';
        } else {
            $displayName = trim(($wz['first_name'] ?? '') . ' ' . ($wz['last_name'] ?? '')) ?: 'there';
        }
        if ($email) {
            sendWelcomeEmail($email, $displayName, $role);
        }
    }

    // FIX-09: relink mode — exec session is already live; just show
    // the step-99 confirmation and let them dismiss it.
    if ($isRelink) {
        $_SESSION['wz']['step'] = 99;
        redirect('/auth/register.php');
    }

    // Normal sales_exec path: NOT auto-logged-in; show pending-approval screen.
    if ($role === 'sales_exec') {
        $_SESSION['wz']['step'] = 99;
        redirect('/auth/register.php');
    }

    // Broker / dealer: log in and go straight to their dashboard (FIX-07).
    $user = getUserById($userId);
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $userId;
        $_SESSION['user_uuid'] = $user['uuid'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['_created']  = time(); // FIX-02
    }

    unset($_SESSION['wz']);

    redirect(match($role) {
        'dealer' => '/app/dealer/dashboard.php',
        'admin'  => '/app/admin/users.php',
        default  => '/app/broker/dashboard.php',
    });
}


// ═══════════════════════════════════════════════════════════
// LOGIN
// ═══════════════════════════════════════════════════════════
function handleLogin(): never
{
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $pw    = $_POST['password'] ?? '';
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!$email || !$pw) {
        redirect('/auth/login.php?error=' . urlencode('Please enter your email and password.'));
    }

    if (!checkRateLimit($email, $ip)) {
        redirect('/auth/login.php?error=' . urlencode(
            'Too many failed attempts. Please wait 15 minutes before trying again.'
        ));
    }

    $user = getUserByEmail($email);

    if (!$user) {
        recordFailedAttempt($email, $ip);
        redirect('/auth/login.php?error=' . urlencode('Invalid email or password.'));
    }

    if ($user['status'] !== 'active') {
        redirect('/auth/login.php?error=' . urlencode('Your account is not active. Please contact support.'));
    }

    if (!$user['email_verified']) {
        $_SESSION['wz'] = [
            'step'    => 2,
            'role'    => $user['role'],
            'email'   => $email,
            'user_id' => (int) $user['id'],
        ];
        $otp = generateAndStoreOTP((int) $user['id'], 'email_verify');
        sendVerificationOTP($email, $otp);
        redirect('/auth/register.php?info=' . urlencode('Please verify your email to continue.'));
    }

    if (!password_verify($pw, $user['password_hash'])) {
        recordFailedAttempt($email, $ip);
        redirect('/auth/login.php?error=' . urlencode('Invalid email or password.'));
    }

    $pdo = Database::getInstance();
    // FIX-03: use PASSWORD_ALGO for rehash check.
    if (password_needs_rehash($user['password_hash'], PASSWORD_ALGO)) {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($pw, PASSWORD_ALGO), $user['id']]);
    }

    clearFailedAttempts($email);
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    $redirectAfterLogin = '';
    $raw = trim($_POST['redirect_after_login'] ?? '');
    if ($raw !== '' && str_starts_with($raw, '/')) {
        $redirectAfterLogin = $raw;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_uuid'] = $user['uuid'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['_created']  = time(); // FIX-02

    $profile = $pdo->prepare("SELECT onboarding_completed FROM profiles WHERE user_id = ?");
    $profile->execute([$user['id']]);
    $prof = $profile->fetch();

    if ($redirectAfterLogin) redirect($redirectAfterLogin);

    if (empty($prof['onboarding_completed'])) {
        $profileData = $pdo->prepare("
            SELECT p.first_name, p.last_name, p.phone, p.onboarding_step,
                   d.company_name, d.brand_focus
            FROM profiles p
            LEFT JOIN dealers d ON d.user_id = p.user_id
            WHERE p.user_id = ?
        ");
        $profileData->execute([$user['id']]);
        $pd = $profileData->fetch() ?: [];

        $_SESSION['wz'] = [
            'step'         => max(1, (int)($pd['onboarding_step'] ?? 1)),
            'role'         => $user['role'],
            'email'        => $email,
            'user_id'      => (int) $user['id'],
            'first_name'   => $pd['first_name']   ?? '',
            'last_name'    => $pd['last_name']     ?? '',
            'phone'        => $pd['phone']         ?? '',
            'bio'          => '',
            'company_name' => $pd['company_name']  ?? '',
            'brand_focus'  => $pd['brand_focus']   ?? '',
            'province'     => '', 'municipality' => '', 'city' => '', 'suburb' => '',
            'dealer_id'    => null, 'dealer_name' => '', 'job_title' => '',
        ];
        redirect('/auth/register.php');
    }

    redirect(match($user['role']) {
        'dealer'     => '/app/dealer/dashboard.php',
        'sales_exec' => '/app/exec/dashboard.php',
        'admin'      => '/app/admin/users.php',
        default      => '/app/broker/dashboard.php',
    });
}
