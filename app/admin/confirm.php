<?php
/**
 * SalesDesk — Admin: Confirm it's you (step-up)  (0014)
 * Route: /app/admin/confirm?next=/app/admin/…
 *
 * Sensitive superadmin actions (admin accounts, payouts, the database
 * tool) call sdRequireStepUp(). If the admin hasn't confirmed in the
 * last SD_STEPUP_TTL seconds, they land here: we email a one-time code
 * to their account address, they enter it, and we send them back to
 * `next` (always inside /app/admin/) to repeat the action.
 *
 * Codes use the existing otp_codes table (purpose 'admin_stepup').
 * Wrong codes are limited per session (SD_STEPUP_MAX_TRIES); after that
 * a new code must be requested.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/superadmin.php';

applyCachePolicy('auth');
requireRole('admin');

$adminId = (int) $_SESSION['user_id'];
$next    = sdSafeAdminPath((string) ($_GET['next'] ?? $_POST['next'] ?? '/app/admin/dealerships'));
$self    = '/app/admin/confirm?next=' . urlencode($next);

$me = Database::getInstance()->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
$me->execute([$adminId]);
$myEmail = (string) $me->fetchColumn();

if (sdStepUpFresh() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'send_code') {
        // Light throttle: one code per 30 seconds.
        if (isset($_SESSION['stepup_sent']) && time() - (int) $_SESSION['stepup_sent'] < 30) {
            $_SESSION['flash_error'] = 'A code was just sent — check your inbox (and spam) before asking for another.';
            redirect($self);
        }
        $code = generateAndStoreOTP($adminId, SD_STEPUP_PURPOSE);
        $sent = sendStepUpCode($myEmail, $code);
        $_SESSION['stepup_sent']  = time();
        $_SESSION['stepup_tries'] = 0;
        writeAuditLog('admin.stepup_code_sent', 'user', $adminId, null, ['sent' => $sent], $adminId);
        $_SESSION[$sent ? 'flash_ok' : 'flash_error'] = $sent
            ? 'We’ve emailed a 6-digit code to ' . $myEmail . '.'
            : 'We couldn’t send the email. Check the SMTP settings in config.php and try again.';
        redirect($self . '&sent=1');
    }

    if ($action === 'verify_code') {
        $tries = (int) ($_SESSION['stepup_tries'] ?? 0);
        if ($tries >= SD_STEPUP_MAX_TRIES) {
            $_SESSION['flash_error'] = 'Too many wrong codes. Request a new one.';
            redirect($self);
        }
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        if ($code !== '' && verifyOTP($adminId, SD_STEPUP_PURPOSE, $code)) {
            session_regenerate_id(true);
            $_SESSION['stepup_at']    = time();
            $_SESSION['stepup_user']  = $adminId;
            unset($_SESSION['stepup_tries'], $_SESSION['stepup_sent']);
            writeAuditLog('admin.stepup_confirmed', 'user', $adminId, null, ['next' => $next], $adminId);
            $_SESSION['flash_ok'] = 'Confirmed. You can repeat that action now — this lasts ' . (int) (SD_STEPUP_TTL / 60) . ' minutes.';
            redirect($next);
        }
        $_SESSION['stepup_tries'] = $tries + 1;
        writeAuditLog('admin.stepup_failed', 'user', $adminId, null, ['try' => $tries + 1], $adminId);
        $_SESSION['flash_error'] = 'That code is wrong or has expired.';
        redirect($self . '&sent=1');
    }

    redirect($self);
}

$codeSent = isset($_GET['sent']);

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Confirm it’s you</h1>
</div>

<div class="card adm-card">
  <div class="card-body">
    <p class="adm-muted adm-gap">
      You’re about to change admin accounts, payouts or the database. For your protection we’ll
      email a one-time code to <strong><?= htmlspecialchars($myEmail) ?></strong>. Once confirmed,
      you won’t be asked again for <?= (int) (SD_STEPUP_TTL / 60) ?> minutes.
    </p>

    <?php if ($codeSent): ?>
    <form method="POST" class="adm-inline-form adm-gap" autocomplete="off">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="verify_code">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <label class="flabel" for="stepupCode">6-digit code</label>
      <div class="adm-inline">
        <input class="finput adm-code-input" id="stepupCode" name="code" inputmode="numeric"
               pattern="[0-9 ]{6,8}" maxlength="8" required autofocus autocomplete="one-time-code">
        <button class="btn btn-primary btn-sm" type="submit">Confirm</button>
      </div>
    </form>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_hidden_field() ?>
      <input type="hidden" name="action" value="send_code">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <button class="btn <?= $codeSent ? 'btn-ghost' : 'btn-primary' ?> btn-sm" type="submit">
        <?= $codeSent ? 'Send a new code' : 'Email me a code' ?>
      </button>
      <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars(adminHomePath()) ?>">Cancel</a>
    </form>
  </div>
</div>
<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Confirm it’s you | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
