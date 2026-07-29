<?php
/**
 * SalesDesk — Standalone email verification fallback.
 * T2 owns this file.
 *
 * Handles users who clicked a "verify your email" link after closing
 * the browser mid-wizard. Normal flow: inline at wizard step 2.
 * This page is the recovery path.
 *
 * Query params:
 *   ?user_id=N &email=encoded@email.com
 *
 * On success → resume wizard if onboarding incomplete, else → login.
 * Uses layout-auth.php — no inline <style> blocks.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

applyCachePolicy('auth');

$userId = (int) ($_GET['user_id'] ?? 0);
$email  = filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL);

if (!$userId || !$email) {
    redirect('/auth/login.php?error=' . urlencode('Invalid or expired verification link.'));
}

// Already logged in and onboarded — send home.
if (!empty($_SESSION['user_id'])) {
    redirect('/app/dashboard.php');
}

// ── Resend via GET ?resend=1 ──────────────────────────────────
if (!empty($_GET['resend'])) {
    $otp = generateAndStoreOTP($userId, 'email_verify');
    sendVerificationOTP($email, $otp);
    redirect(
        '/auth/verify_email.php'
        . '?user_id=' . $userId
        . '&email=' . urlencode($email)
        . '&resent=1'
    );
}

// ── Handle POST ───────────────────────────────────────────────
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $submitted = trim($_POST['otp'] ?? '');

    if (!preg_match('/^\d{6}$/', $submitted)) {
        $errorMsg = 'Please enter the 6-digit code.';
    } elseif (!verifyOTP($userId, 'email_verify', $submitted)) {
        $errorMsg = 'That code is incorrect or has expired. Request a new one below.';
    } else {
        $pdo = Database::getInstance();
        $pdo->prepare("
            UPDATE users
            SET email_verified = 1, status = 'active', updated_at = NOW()
            WHERE id = ?
        ")->execute([$userId]);

        $user = getUserById($userId);
        if ($user) {
            $profile = $pdo->prepare("
                SELECT onboarding_completed, onboarding_step, first_name, last_name
                FROM profiles WHERE user_id = ?
            ");
            $profile->execute([$userId]);
            $prof = $profile->fetch();

            if ($prof && !$prof['onboarding_completed']) {
                // Resume wizard from where they left off.
                $_SESSION['wz'] = $_SESSION['wz'] ?? [];
                $_SESSION['wz']['step']       = max(3, (int)($prof['onboarding_step'] ?? 1));
                $_SESSION['wz']['user_id']    = $userId;
                $_SESSION['wz']['email']      = $email;
                $_SESSION['wz']['role']       = $user['role'];
                $_SESSION['wz']['first_name'] = $prof['first_name'] ?? '';
                $_SESSION['wz']['last_name']  = $prof['last_name']  ?? '';
                redirect('/auth/register.php?info=' . urlencode('Email verified — continue setting up your account.'));
            }
        }

        redirect('/auth/login.php?verified=1');
    }
}

// ── Render ────────────────────────────────────────────────────
$resent    = !empty($_GET['resent']);
$pageTitle = 'Verify your email';
$cardMaxWidth = '460px';

ob_start();
?>

<div style="text-align:center;margin-bottom:1.5rem;">
  <div style="width:64px;height:64px;background:var(--p-light);border-radius:50%;
              margin:0 auto 1.25rem;display:flex;align-items:center;
              justify-content:center;font-size:26px;color:var(--p);">
    <i class="fa-solid fa-envelope-open-text"></i>
  </div>
  <h1 style="font-family:var(--serif);font-size:1.45rem;font-weight:300;margin-bottom:8px;">
    Check your <em style="font-style:italic;">email</em>
  </h1>
  <p style="font-size:13px;color:var(--muted);line-height:1.65;margin-bottom:.5rem;">
    We sent a 6-digit code to
  </p>
  <span class="email-chip"><?= htmlspecialchars($email) ?></span>
</div>

<?php if ($errorMsg): ?>
<div class="alert alert-error">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i>
  <?= htmlspecialchars($errorMsg) ?>
</div>
<?php endif; ?>

<?php if ($resent): ?>
<div class="alert alert-success">
  <i class="fa-solid fa-circle-check alert-icon"></i>
  A new code has been sent to your email.
</div>
<?php endif; ?>

<form method="POST" id="otpForm">
  <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
  <input type="hidden" name="otp" id="otpHidden">
  <div class="otp-row">
    <?php for ($i = 0; $i < 6; $i++): ?>
    <input class="otp-d" type="text" inputmode="numeric" pattern="[0-9]"
           maxlength="1"
           autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>">
    <?php endfor; ?>
  </div>
  <button class="btn-auth" type="submit">
    <i class="fa-solid fa-shield-halved"></i> Verify email
  </button>
</form>

<p style="text-align:center;margin-top:1rem;font-size:13px;color:var(--muted);">
  Didn't receive it?
  <a href="/auth/verify_email.php?user_id=<?= $userId ?>&email=<?= urlencode($email) ?>&resend=1"
     style="color:var(--p);font-weight:600;text-decoration:none;">Resend code</a>
  &nbsp;·&nbsp;
  <a href="/auth/register.php" style="color:var(--muted);text-decoration:none;">Back to sign up</a>
</p>

<script>initOTPWidget('otpForm', 'otpHidden');</script>

<?php
$cardContent = ob_get_clean();
require_once '../views/layout-auth.php';
