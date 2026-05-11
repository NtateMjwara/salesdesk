<?php
/**
 * SalesDesk — Password recovery flow.
 * T2 owns this file.
 *
 * FIXES APPLIED:
 *   FIX-04: _handleReset() used a raw interpolated query
 *           `"SELECT email FROM users WHERE id = {$uid}"` — SQL injection.
 *           Replaced with a prepared statement.
 *
 *   FIX-03: password_hash() now uses PASSWORD_ALGO constant (Argon2id)
 *           instead of PASSWORD_BCRYPT, consistent with config.php and
 *           the corrected authentication.php.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
require_once '../includes/response.php';

applyCachePolicy('auth');

if (!empty($_SESSION['user_id'])) {
    redirect('/app/settings.php');
}

$csrf  = generateCSRFToken();
$mode  = $_GET['mode'] ?? 'request';
$error = $_GET['error'] ?? '';
$info  = $_GET['info']  ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    match ($_POST['action'] ?? '') {
        'request' => _handleRequest(),
        'verify'  => _handleVerify(),
        'resend'  => _handleResend(),
        'reset'   => _handleReset(),
        default   => redirect('/auth/reset_password.php'),
    };
}

$uid        = (int) ($_GET['uid'] ?? 0);
$emailParam = filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
$resetToken = $_GET['token'] ?? '';

// ── Handlers ──────────────────────────────────────────────────

function _handleRequest(): never
{
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        redirect('/auth/reset_password.php?error=' . urlencode('Please enter a valid email address.'));
    }
    $user = getUserByEmail($email);
    if ($user && $user['status'] === 'active') {
        $otp = generateAndStoreOTP((int) $user['id'], 'password_reset');
        sendPasswordResetOTP($email, $otp);
        redirect(
            '/auth/reset_password.php?mode=verify'
            . '&uid=' . (int) $user['id']
            . '&email=' . urlencode($email)
            . '&info=' . urlencode('A 6-digit reset code has been sent to ' . $email . '.')
        );
    }
    redirect(
        '/auth/reset_password.php?mode=verify'
        . '&uid=0&email=' . urlencode($email)
        . '&info=' . urlencode('If that email is registered, a reset code has been sent.')
    );
}

function _handleVerify(): never
{
    $uid       = (int) ($_POST['uid'] ?? 0);
    $email     = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $submitted = trim($_POST['otp'] ?? '');
    $base      = '/auth/reset_password.php?mode=verify&uid=' . $uid . '&email=' . urlencode($email ?: '');

    if (!$uid || !$email) {
        redirect('/auth/reset_password.php?error=' . urlencode('Invalid reset link. Please request a new code.'));
    }
    if (!preg_match('/^\d{6}$/', $submitted)) {
        redirect($base . '&error=' . urlencode('Please enter the 6-digit code.'));
    }
    if (!verifyOTP($uid, 'password_reset', $submitted)) {
        redirect($base . '&error=' . urlencode('That code is incorrect or has expired.'));
    }

    $token = bin2hex(random_bytes(24));
    $_SESSION['pw_reset'] = ['uid' => $uid, 'token' => $token, 'expires' => time() + 900];

    redirect('/auth/reset_password.php?mode=reset&uid=' . $uid . '&token=' . urlencode($token));
}

function _handleResend(): never
{
    $uid   = (int) ($_POST['uid'] ?? 0);
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $base  = '/auth/reset_password.php?mode=verify&uid=' . $uid . '&email=' . urlencode($email ?: '');

    if (!$uid || !$email) {
        redirect('/auth/reset_password.php?error=' . urlencode('Session expired. Please start again.'));
    }
    $otp = generateAndStoreOTP($uid, 'password_reset');
    sendPasswordResetOTP($email, $otp);
    redirect($base . '&info=' . urlencode('A new code has been sent to ' . $email . '.'));
}

function _handleReset(): never
{
    $uid   = (int) ($_POST['uid'] ?? 0);
    $token = $_POST['token'] ?? '';
    $pw    = $_POST['password'] ?? '';
    $conf  = $_POST['confirm_password'] ?? '';
    $base  = '/auth/reset_password.php?mode=reset&uid=' . $uid . '&token=' . urlencode($token);

    $stored = $_SESSION['pw_reset'] ?? [];
    if (
        empty($stored) ||
        (int) ($stored['uid'] ?? 0)   !== $uid ||
        !hash_equals($stored['token'] ?? '', $token) ||
        time() > ($stored['expires'] ?? 0)
    ) {
        redirect('/auth/reset_password.php?error=' . urlencode('Your reset link has expired. Please request a new one.'));
    }

    if (
        strlen($pw) < 8 ||
        !preg_match('/[A-Z]/', $pw) ||
        !preg_match('/[a-z]/', $pw) ||
        !preg_match('/[0-9]/', $pw) ||
        !preg_match('/[^A-Za-z0-9]/', $pw)
    ) {
        redirect($base . '&error=' . urlencode('Password must be at least 8 characters with uppercase, lowercase, number, and special character.'));
    }
    if ($pw !== $conf) {
        redirect($base . '&error=' . urlencode('Passwords do not match.'));
    }

    $pdo = Database::getInstance();

    // FIX-03: Use PASSWORD_ALGO constant (Argon2id).
    $hash = password_hash($pw, PASSWORD_ALGO);
    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$hash, $uid]);
    $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE user_id = ? AND purpose = 'password_reset' AND used = 0")
        ->execute([$uid]);
    unset($_SESSION['pw_reset']);

    // FIX-04: Replaced raw interpolated query with a prepared statement.
    // Original: $pdo->query("SELECT email FROM users WHERE id = {$uid}")->fetch()
    // That was a SQL injection vulnerability — $uid comes from user-controlled POST data,
    // cast to int here but relying on the cast alone is fragile.
    $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $emailStmt->execute([$uid]);
    $row = $emailStmt->fetch();
    if ($row && !empty($row['email'])) {
        sendPasswordChangedNotice($row['email']);
    }

    redirect('/auth/login.php?reset=1');
}

// ── Render ────────────────────────────────────────────────────
$pageTitle    = 'Reset password';
$cardMaxWidth = '460px';

$tokenOk = false;
if ($mode === 'reset') {
    $stored   = $_SESSION['pw_reset'] ?? [];
    $tokenOk  = (
        !empty($stored) &&
        (int) ($stored['uid'] ?? 0) === $uid &&
        hash_equals($stored['token'] ?? '', $resetToken) &&
        time() <= ($stored['expires'] ?? 0)
    );
}

ob_start();
?>

<?php if ($error): ?>
<div class="alert alert-error">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i>
  <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if ($info): ?>
<div class="alert alert-info">
  <i class="fa-solid fa-circle-info alert-icon"></i>
  <?= htmlspecialchars($info) ?>
</div>
<?php endif; ?>


<?php if ($mode === 'request'): ?>
<div class="auth-heading">
  <h1>Reset your <em>password</em></h1>
  <p>Enter your email and we'll send you a 6-digit code.</p>
</div>

<form method="POST" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action"     value="request">
  <div class="fgroup">
    <label class="flabel" for="email">Email address</label>
    <input class="finput" type="email" id="email" name="email"
           required autocomplete="email" autofocus placeholder="you@example.com">
  </div>
  <button class="btn-auth" type="submit">
    <i class="fa-solid fa-paper-plane"></i> Send reset code
  </button>
</form>

<p style="text-align:center;margin-top:1.25rem;font-size:13px;color:var(--muted);">
  <a href="/auth/login.php" style="color:var(--muted);text-decoration:none;">
    <i class="fa-solid fa-arrow-left" style="font-size:11px"></i> Back to sign in
  </a>
</p>


<?php elseif ($mode === 'verify'): ?>
<div class="auth-heading">
  <h1>Check your <em>email</em></h1>
  <p>We sent a 6-digit code to:</p>
</div>

<div class="reset-email-chip"><?= htmlspecialchars($emailParam ?: 'your email') ?></div>

<form method="POST" id="otpForm" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action"     value="verify">
  <input type="hidden" name="uid"        value="<?= $uid ?>">
  <input type="hidden" name="email"      value="<?= htmlspecialchars($emailParam) ?>">
  <input type="hidden" name="otp"        id="otpHidden">
  <div class="otp-row">
    <?php for ($i = 0; $i < 6; $i++): ?>
    <input class="otp-d" type="text" inputmode="numeric" pattern="[0-9]"
           maxlength="1" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>">
    <?php endfor; ?>
  </div>
  <button class="btn-auth" type="submit">
    <i class="fa-solid fa-shield-halved"></i> Verify code
  </button>
</form>

<form method="POST" style="margin-top:0">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action"     value="resend">
  <input type="hidden" name="uid"        value="<?= $uid ?>">
  <input type="hidden" name="email"      value="<?= htmlspecialchars($emailParam) ?>">
  <button class="btn-auth-ghost" type="submit">
    <i class="fa-solid fa-rotate-right" style="font-size:11px"></i> Resend code
  </button>
</form>

<p style="text-align:center;margin-top:1rem;font-size:12px;color:var(--muted);">
  <a href="/auth/reset_password.php" style="color:var(--muted);text-decoration:none;">
    <i class="fa-solid fa-arrow-left" style="font-size:11px"></i> Use a different email
  </a>
</p>

<script>initOTPWidget('otpForm', 'otpHidden');</script>


<?php elseif ($mode === 'reset' && !$tokenOk): ?>
<div style="text-align:center;">
  <div style="font-size:40px;margin-bottom:1rem;color:var(--faint);">⏱</div>
  <h1 style="font-family:var(--serif);font-size:1.4rem;font-weight:300;margin-bottom:.5rem;">
    Link <em style="font-style:italic;">expired</em>
  </h1>
  <p style="font-size:13px;color:var(--muted);margin-bottom:1.5rem;">
    This reset link is no longer valid. Please request a new one.
  </p>
  <a href="/auth/reset_password.php" class="btn-auth" style="text-decoration:none;">
    <i class="fa-solid fa-rotate-right"></i> Request new link
  </a>
</div>


<?php elseif ($mode === 'reset' && $tokenOk): ?>
<div class="auth-heading">
  <h1>Set new <em>password</em></h1>
  <p>Create a strong password you haven't used before.</p>
</div>

<form method="POST" id="pwForm" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action"     value="reset">
  <input type="hidden" name="uid"        value="<?= $uid ?>">
  <input type="hidden" name="token"      value="<?= htmlspecialchars($resetToken) ?>">

  <div class="fgroup">
    <label class="flabel" for="password">New password</label>
    <div class="pw-wrap">
      <input class="finput" type="password" id="password" name="password"
             required minlength="8" autocomplete="new-password" autofocus>
      <button type="button" class="pw-toggle" onclick="togglePw('password',this)"
              aria-label="Show password">
        <i class="fa-regular fa-eye"></i>
      </button>
    </div>
    <ul class="pw-reqs">
      <li class="pw-req" id="r-len"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>At least 8 characters</li>
      <li class="pw-req" id="r-up"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Uppercase letter</li>
      <li class="pw-req" id="r-low"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Lowercase letter</li>
      <li class="pw-req" id="r-num"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Number</li>
      <li class="pw-req" id="r-spc"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Special character</li>
    </ul>
  </div>

  <div class="fgroup">
    <label class="flabel" for="confirm_password">Confirm new password</label>
    <div class="pw-wrap">
      <input class="finput" type="password" id="confirm_password"
             name="confirm_password" required autocomplete="new-password">
      <button type="button" class="pw-toggle" onclick="togglePw('confirm_password',this)"
              aria-label="Show confirm password">
        <i class="fa-regular fa-eye"></i>
      </button>
    </div>
    <p class="match-hint" id="matchHint">
      <i class="fa-regular fa-circle" style="font-size:10px"></i> Passwords must match
    </p>
  </div>

  <button class="btn-auth" type="submit">
    <i class="fa-solid fa-lock"></i> Set new password
  </button>
</form>

<script>
(function () {
  var pw      = document.getElementById('password');
  var confirm = document.getElementById('confirm_password');
  var hint    = document.getElementById('matchHint');
  var checks  = {
    'r-len': function (v) { return v.length >= 8; },
    'r-up':  function (v) { return /[A-Z]/.test(v); },
    'r-low': function (v) { return /[a-z]/.test(v); },
    'r-num': function (v) { return /[0-9]/.test(v); },
    'r-spc': function (v) { return /[^A-Za-z0-9]/.test(v); },
  };
  function evalPw() {
    var v = pw.value, ok = true;
    Object.keys(checks).forEach(function (id) {
      var el = document.getElementById(id), met = checks[id](v);
      el.classList.toggle('met', met);
      el.querySelector('.r-icon').innerHTML = met
        ? '<i class="fa-solid fa-circle-check"></i>'
        : '<i class="fa-regular fa-circle"></i>';
      if (!met) ok = false;
    });
    evalMatch(); return ok;
  }
  function evalMatch() {
    var ok = pw.value && pw.value === confirm.value;
    hint.classList.toggle('ok', ok);
    hint.innerHTML = ok
      ? '<i class="fa-solid fa-circle-check" style="font-size:10px"></i> Passwords match'
      : '<i class="fa-regular fa-circle" style="font-size:10px"></i> Passwords must match';
  }
  pw.addEventListener('input', evalPw);
  confirm.addEventListener('input', evalMatch);
  document.getElementById('pwForm').addEventListener('submit', function (e) {
    if (!evalPw()) { e.preventDefault(); pw.focus(); return; }
    if (pw.value !== confirm.value) { e.preventDefault(); confirm.focus(); }
  });
  pw.focus();
})();

function togglePw(id, btn) {
  var input   = document.getElementById(id);
  var showing = input.type === 'text';
  input.type  = showing ? 'password' : 'text';
  btn.innerHTML = showing
    ? '<i class="fa-regular fa-eye"></i>'
    : '<i class="fa-regular fa-eye-slash"></i>';
}
</script>

<?php endif; ?>

<?php
$cardContent = ob_get_clean();
require_once '../views/layout-auth.php';
