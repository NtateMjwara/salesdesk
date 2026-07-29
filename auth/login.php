<?php
/**
 * SalesDesk — Login page.
 * T2 owns this file.
 *
 * Renders the login form. POST handling is in authentication.php.
 * Uses layout-auth.php shell — no inline <style> blocks.
 *
 * CHANGES:
 *   LOADING-01: data-loading-label="Signing in…" added to <form>.
 *               layout-auth.php's initAuthLoadingStates() reads this
 *               attribute and shows "Signing in…" + spinner in the
 *               submit button as soon as the form is submitted.
 *               On server-side failure the page reloads with ?error=…
 *               which naturally resets the button state.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';

applyCachePolicy('auth');

// Already logged in — route to dashboard.
if (!empty($_SESSION['user_id'])) {
    redirect(match($_SESSION['user_role'] ?? '') {
        'dealer'     => '/app/dealer/dashboard.php',
        'sales_exec' => '/app/exec/dashboard.php',
        'admin'      => '/app/admin/users.php',
        default      => '/app/broker/dashboard.php',
    });
}

$csrf    = generateCSRFToken();
$error   = $_GET['error']   ?? '';
$info    = $_GET['info']    ?? '';
$timeout = !empty($_GET['timeout']);
$reset   = !empty($_GET['reset']);
$verified = !empty($_GET['verified']);

// Pre-fill redirect so authentication.php can bounce back after login.
$redirectAfterLogin = '';
$raw = $_GET['redirect'] ?? '';
if ($raw !== '' && str_starts_with($raw, '/')) {
    $redirectAfterLogin = $raw;
}

$pageTitle    = 'Sign in';
$cardMaxWidth = '460px';

ob_start();
?>

<div class="login-heading">
  <h1>Sign in to <em>SalesDesk</em></h1>
  <p>Welcome back. Enter your credentials to continue.</p>
</div>

<?php if ($timeout): ?>
<div class="alert alert-warn">
  <i class="fa-solid fa-clock alert-icon"></i>
  Your session expired. Please sign in again.
</div>
<?php endif; ?>

<?php if ($reset): ?>
<div class="alert alert-success">
  <i class="fa-solid fa-circle-check alert-icon"></i>
  Password updated successfully. You can now sign in with your new password.
</div>
<?php endif; ?>

<?php if ($verified): ?>
<div class="alert alert-success">
  <i class="fa-solid fa-circle-check alert-icon"></i>
  Email verified. Welcome to SalesDesk — please sign in to continue.
</div>
<?php endif; ?>

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

<!--
  LOADING-01: data-loading-label tells initAuthLoadingStates() what
  message to display inside the submit button while authentication is
  in progress. The page reloads on any server-side failure (redirect
  with ?error=…), which naturally resets the button to its original state.
-->
<form method="POST" action="/auth/authentication.php?action=login" novalidate
      data-loading-label="Signing in…">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <?php if ($redirectAfterLogin): ?>
  <input type="hidden" name="redirect_after_login" value="<?= htmlspecialchars($redirectAfterLogin) ?>">
  <?php endif; ?>

  <div class="fgroup">
    <label class="flabel" for="email">Email address</label>
    <input class="finput" type="email" id="email" name="email"
           required autocomplete="email" autofocus
           placeholder="you@example.com">
  </div>

  <div class="fgroup">
    <label class="flabel" for="password">Password</label>
    <div class="pw-wrap">
      <input class="finput" type="password" id="password" name="password"
             required autocomplete="current-password">
      <button type="button" class="pw-toggle" onclick="togglePw()" aria-label="Show password">
        <i class="fa-regular fa-eye" id="pw-eye"></i>
      </button>
    </div>
  </div>

  <button class="btn-auth" type="submit">
    Sign in <i class="fa-solid fa-arrow-right"></i>
  </button>

  <div class="login-footer">
    <a href="/auth/reset_password.php">Forgot password?</a>
    <a href="/auth/register.php">Create account</a>
  </div>
</form>

<script>
function togglePw() {
  var input = document.getElementById('password');
  var eye   = document.getElementById('pw-eye');
  var show  = input.type === 'password';
  input.type = show ? 'text' : 'password';
  eye.className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
}
</script>

<?php
$cardContent = ob_get_clean();
require_once '../views/layout-auth.php';
