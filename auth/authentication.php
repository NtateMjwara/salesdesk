<?php
/**
 * SalesDesk — Authentication handler.
 *
 * Handles POST/GET for:
 *   Wizard actions (multi-step signup):
 *     wizard_email           — step 1: validate email, create pending user, fire OTP
 *     wizard_otp             — step 2: verify OTP, mark email verified
 *     wizard_resend          — step 2: resend OTP
 *     wizard_reset           — step 2: go back to step 1 (change email)
 *     wizard_desk            — step 3 (broker only): set SalesDesk display name + slug
 *     wizard_dealership      — step 3 (sales_exec only): link to a dealership
 *     wizard_skip_dealership — step 3 (sales_exec only): skip dealership selection
 *     wizard_company         — step 3 (dealer only): company name, brand focus, CIPC upload
 *     wizard_profile         — step 3/4: save first_name, last_name, phone, bio
 *     wizard_password        — step 4/5: set password_hash
 *     wizard_address         — step 5/6: save address, complete onboarding
 *     wizard_skip_address    — step 5/6: skip address, complete onboarding
 *
 *   Standard actions:
 *     login                  — email + password login
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($_GET['action'] ?? '', [
    'wizard_reset', 'wizard_skip_address', 'wizard_skip_dealership',
], true)) {
    redirect('/auth/login.php');
}

// GET-safe actions (no CSRF needed — they only read/clear session).
$action = $_GET['action'] ?? '';

if ($action === 'wizard_reset') {
    $_SESSION['wz'] = null;
    unset($_SESSION['wz']);
    redirect('/auth/register.php');
}

if ($action === 'wizard_skip_dealership') {
    // Mark no dealership chosen, advance to profile step (step 4 for sales_exec).
    if (!empty($_SESSION['wz'])) {
        $_SESSION['wz']['dealer_id']   = null;
        $_SESSION['wz']['dealer_name'] = '';
        $_SESSION['wz']['job_title']   = '';
        $_SESSION['wz']['step']        = 4;
    }
    redirect('/auth/register.php');
}

if ($action === 'wizard_skip_address') {
    // Finalise without address. GET-based; guard via session presence.
    if (empty($_SESSION['wz']['user_id'])) {
        redirect('/auth/register.php');
    }
    finaliseOnboarding();
}

// All remaining actions require CSRF on POST.
validateCSRF();

match ($action) {
    'wizard_email'       => handleWizardEmail(),
    'wizard_otp'         => handleWizardOTP(),
    'wizard_resend'      => handleWizardResend(),
    'wizard_dealership'  => handleWizardDealership(),
    'wizard_company'     => handleWizardCompany(),
    'wizard_desk'        => handleWizardDesk(),
    'wizard_profile'     => handleWizardProfile(),
    'wizard_password'    => handleWizardPassword(),
    'wizard_address'     => handleWizardAddress(),
    'login'              => handleLogin(),
    default              => redirect('/auth/login.php'),
};


// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

/** Redirect back to register.php at a given step with optional flash message. */
function wizardRedirect(int $step, string $error = '', string $info = ''): never
{
    $_SESSION['wz']['step'] = $step;
    $qs = $error ? ('?error=' . urlencode($error)) : ($info ? ('?info=' . urlencode($info)) : '');
    redirect('/auth/register.php' . $qs);
}

/** Returns true if the current wizard role is 'sales_exec'. */
function isSalesExec(): bool
{
    return ($_SESSION['wz']['role'] ?? '') === 'sales_exec';
}

/** Returns true if the current wizard role is 'dealer'. */
function isDealer(): bool
{
    return ($_SESSION['wz']['role'] ?? '') === 'dealer';
}


// ═══════════════════════════════════════════════════════════
// STEP 1 — Email + role
// Creates a pending user row and fires the OTP email.
// ═══════════════════════════════════════════════════════════
function handleWizardEmail(): never
{
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $role  = in_array($_POST['role'] ?? '', ['broker', 'dealer', 'sales_exec'], true)
             ? $_POST['role'] : 'broker';

    if (!$email) {
        wizardRedirect(1, 'Please enter a valid email address.');
    }

    // Init / refresh wizard state.
    if (empty($_SESSION['wz'])) {
        $_SESSION['wz'] = [];
    }
    $_SESSION['wz']['role']  = $role;
    $_SESSION['wz']['email'] = $email;

    $pdo = Database::getInstance();

    // Check for an existing verified account.
    $stmt = $pdo->prepare("SELECT id, email_verified, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing && $existing['email_verified'] && $existing['status'] === 'active') {
        wizardRedirect(1, 'That email is already registered. Please sign in instead.');
    }

    // Re-use an existing pending row, or create a fresh one.
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

        // Create blank profile row.
        $pdo->prepare("
            INSERT INTO profiles (user_id, onboarding_step, onboarding_completed, created_at, updated_at)
            VALUES (?, 1, 0, NOW(), NOW())
        ")->execute([$userId]);

        // Broker: create personal SalesDesk stub.
        if ($role === 'broker') {
            $slug = 'desk-' . substr(str_replace('-', '', $uuid), 0, 8);
            $pdo->prepare("
                INSERT INTO salesdesks (uuid, user_id, slug, display_name, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 'My SalesDesk', 0, NOW(), NOW())
            ")->execute([generateUuidV4(), $userId, $slug]);
        }

        // Dealer principal: create dealer record stub.
        if ($role === 'dealer') {
            $dSlug = 'dealer-' . substr(str_replace('-', '', $uuid), 0, 8);
            $pdo->prepare("
                INSERT INTO dealers (uuid, user_id, slug, company_name, verification_status, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 'My Dealership', 'unverified', 0, NOW(), NOW())
            ")->execute([generateUuidV4(), $userId, $dSlug]);
        }

        // Sales exec: no dealer record created yet — linked in step 3.
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

    // Role-based next step:
    //   broker     → step 3 (SalesDesk name)
    //   dealer     → step 3 (company profile)
    //   sales_exec → step 3 (dealership selection)
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
//
// Broker chooses their desk display name; slug is auto-generated
// from the display name and deduplicated with a numeric suffix.
// Overwrites the stub slug (desk-{uuid8}) created at signup.
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

    // Generate slug from display name: lowercase, spaces→hyphens, strip non-alphanumeric/hyphen.
    $slug = strtolower($displayName);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 60);

    if (!$slug) {
        // Fallback: use broker's email prefix.
        $slug = strtolower(preg_replace('/[^a-z0-9]/', '-', explode('@', $wz['email'] ?? 'desk')[0]));
        $slug = trim(substr($slug, 0, 60), '-') ?: 'my-desk';
    }

    $pdo = Database::getInstance();

    // Deduplicate: if slug is taken (by another user), append -2, -3, etc.
    $baseSlug = $slug;
    $suffix   = 2;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM salesdesks WHERE slug = ? AND user_id != ? LIMIT 1");
        $stmt->execute([$slug, $userId]);
        if (!$stmt->fetch()) {
            break; // Slug is available.
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

    wizardRedirect(4); // Next: profile (step 4 in broker's 6-step flow is now profile)
}


// ═══════════════════════════════════════════════════════════
// STEP 3 (dealer only) — Company profile
//
// Dealer enters their company name and brand focus (JSON array).
// Also captures the account holder's name (first_name/last_name/phone)
// since dealers don't have a separate profile step — it's combined here.
// Optional: CIPC PDF upload (stored at /uploads/cipc/{uuid}.pdf).
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

    // Parse brand focus into JSON array.
    $brandFocusJson = null;
    if ($brandFocus) {
        $brands = array_filter(array_map('trim', explode(',', $brandFocus)));
        $brandFocusJson = json_encode(array_values($brands));
    }

    $pdo = Database::getInstance();

    // Update dealers.company_name + brand_focus.
    $pdo->prepare("
        UPDATE dealers
        SET company_name = ?, brand_focus = ?, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$companyName, $brandFocusJson, $userId]);

    // Update profiles: account holder name + phone (no bio for dealers).
    $pdo->prepare("
        UPDATE profiles
        SET first_name = ?, last_name = ?, phone = ?, onboarding_step = 3, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$firstName, $lastName, $phone ?: null, $userId]);

    // Optional CIPC PDF upload.
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

    // Next: password (step 4 — dealer has no separate profile step).
    wizardRedirect(4);
}


// ═══════════════════════════════════════════════════════════
// STEP 3 (sales_exec only) — Dealership selection
//
// The exec selects a dealership from the search results.
// This creates a sales_executives row with status = 'pending'
// and notifies the dealer principal.
// ═══════════════════════════════════════════════════════════
function handleWizardDealership(): never
{
    $wz       = &$_SESSION['wz'];
    $userId   = (int) ($wz['user_id'] ?? 0);
    $dealerId = (int) ($_POST['dealer_id'] ?? 0);

    if (!$userId) {
        wizardRedirect(1, 'Session expired. Please start again.');
    }

    // Validate the dealer_id actually exists and is active.
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

        // Insert (or update if they previously made a request) the sales_executives row.
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

        // Notify the dealer principal.
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

    // Move to profile (step 4 for sales_exec).
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
        // Profile is always step 4 for broker and exec. Dealer never reaches this.
        wizardRedirect(4, 'Please enter your first and last name.');
    }

    $pdo = Database::getInstance();

    // BUG-01 FIX: phone belongs on profiles, not users (users has no phone column).
    // Determine the correct onboarding step number for this role.
    // Broker: profile is step 4. Exec: profile is step 4. Dealer: never calls this.
    $nextOnboardingStep = 4;
    $pdo->prepare("
        UPDATE profiles
        SET first_name = ?, last_name = ?, phone = ?, bio = ?, onboarding_step = ?, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$firstName, $lastName, $phone ?: null, $bio ?: null, $nextOnboardingStep, $userId]);

    $wz['first_name'] = $firstName;
    $wz['last_name']  = $lastName;
    $wz['phone']      = $phone;
    $wz['bio']        = $bio;

    // Next: password step.
    // Broker: profile(4)→password(5). Exec: profile(4)→password(5).
    // Dealer never calls this handler (company step handles it).
    wizardRedirect(5);
}


// ═══════════════════════════════════════════════════════════
// STEP 4/5 — Password
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
        // Password step is 4 for dealer, 5 for broker and exec.
        $pwStep = isDealer() ? 4 : 5;
        wizardRedirect($pwStep, 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.');
    }

    if ($pw !== $confirm) {
        $pwStep = isDealer() ? 4 : 5;
        wizardRedirect($pwStep, 'Passwords do not match.');
    }

    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $pdo  = Database::getInstance();
    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$hash, $userId]);
    // Record onboarding step: dealer=4, broker/exec=5.
    $pdo->prepare("UPDATE profiles SET onboarding_step = ? WHERE user_id = ?")
        ->execute([isDealer() ? 4 : 5, $userId]);

    // Address step: dealer=5, broker=6, exec=6.
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

        // BUG-02 FIX: For dealers, address is the dealership's physical location
        // and must be written to dealers.address_id, NOT profiles.address_id.
        // Dealer profiles.address_id stays NULL (no personal residential address on platform).
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
// Finalise — mark onboarding complete, send welcome email,
// log the user in (or leave pending for sales_exec),
// advance to step 99 (success/pending screen).
// ═══════════════════════════════════════════════════════════
function finaliseOnboarding(): never
{
    $wz     = &$_SESSION['wz'];
    $userId = (int) ($wz['user_id'] ?? 0);
    $email  = $wz['email'] ?? '';
    $role   = $wz['role']  ?? 'broker';

    if (!$userId) {
        redirect('/auth/register.php');
    }

    $pdo = Database::getInstance();
    $pdo->prepare("
        UPDATE profiles
        SET onboarding_step = 99, onboarding_completed = 1, updated_at = NOW()
        WHERE user_id = ?
    ")->execute([$userId]);

    // Activate desk / dealer record.
    $pdo->prepare("UPDATE salesdesks SET is_active = 1, updated_at = NOW() WHERE user_id = ?")
        ->execute([$userId]);
    $pdo->prepare("UPDATE dealers SET is_active = 1, updated_at = NOW() WHERE user_id = ?")
        ->execute([$userId]);

    // BUG-03 FIX: For dealers, use company_name as the display name.
    // $wz['company_name'] is populated by handleWizardCompany() (dealer branch, task ad1).
    // For brokers/execs, use first+last name as before.
    if ($role === 'dealer') {
        $displayName = $wz['company_name'] ?? 'your dealership';
    } else {
        $displayName = trim(($wz['first_name'] ?? '') . ' ' . ($wz['last_name'] ?? '')) ?: 'there';
    }
    if ($email) {
        sendWelcomeEmail($email, $displayName, $role);
    }

    // Sales execs with a dealership selected are NOT logged in immediately —
    // their account stays active but dealer features are gated by
    // sales_executives.verification_status = 'verified'.
    // They see the "pending approval" success screen.
    if ($role === 'sales_exec') {
        $_SESSION['wz']['step'] = 99;
        redirect('/auth/register.php');
    }

    // Broker / dealer principal: log them in.
    $user = getUserById($userId);
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $userId;
        $_SESSION['user_uuid'] = $user['uuid'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['created']   = time();
    }

    $_SESSION['wz']['step'] = 99;
    redirect('/auth/register.php');
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
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($pw, PASSWORD_BCRYPT), $user['id']]);
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
    $_SESSION['created']   = time();

    $profile = $pdo->prepare("SELECT onboarding_completed FROM profiles WHERE user_id = ?");
    $profile->execute([$user['id']]);
    $prof = $profile->fetch();

    if ($redirectAfterLogin) redirect($redirectAfterLogin);

    // Task a7 — Wizard resume: if onboarding is not complete, drop back into the wizard.
    // Reconstruct $wz from DB so the user resumes from wherever they left off.
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

    // Task a8 — Role-aware post-login redirect.
    redirect(match($user['role']) {
        'dealer'     => '/app/dealer/dashboard.php',
        'sales_exec' => '/app/exec/dashboard.php',
        'admin'      => '/app/admin/users.php',
        default      => '/app/broker/dashboard.php',
    });
