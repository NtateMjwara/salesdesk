<?php
/**
 * SalesDesk — Auth Layout Shell
 * T1 owns this file.
 *
 * Card-centred layout for all auth pages:
 *   auth/login.php, auth/register.php, auth/verify_email.php,
 *   auth/reset_password.php
 *
 * USAGE:
 *
 *   <?php
 *   require_once '../includes/security.php';
 *   require_once '../includes/session.php';
 *   require_once '../includes/functions.php';
 *   applyCachePolicy('auth');
 *
 *   $pageTitle    = 'Sign in';
 *   $cardMaxWidth = '480px'; // optional, default 520px
 *
 *   ob_start();
 *   // ... card inner HTML ...
 *   $cardContent = ob_get_clean();
 *
 *   require_once '../views/layout-auth.php';
 *
 * VARIABLES consumed:
 *   string $pageTitle      — <title>
 *   string $cardContent    — inner HTML of the card
 *   string $cardMaxWidth   — optional CSS max-width (default '520px')
 *   string $assetVersion   — cache-buster (default today YYYYMMDD)
 *   bool   $needsOTP       — if true, loads global.js for initOTPWidget()
 *   bool   $needsLocations — if true, loads sa-locations.js
 *   bool   $needsDealerSearch — if true, loads dealer-search.js
 */

$pageTitle        = $pageTitle        ?? 'SalesDesk';
$cardContent      = $cardContent      ?? '';
$cardMaxWidth     = $cardMaxWidth     ?? '520px';
$assetVersion     = $assetVersion     ?? date('Ymd');
$needsOTP         = $needsOTP         ?? false;
$needsLocations   = $needsLocations   ?? false;
$needsDealerSearch = $needsDealerSearch ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('generateCSRFToken') ? generateCSRFToken() : '') ?>">
  <title><?= htmlspecialchars($pageTitle) ?> | SalesDesk</title>

  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/components.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/auth.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      background: var(--bg);
    }

    .auth-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--r-xl);
      padding: 2rem 2.25rem 2.25rem;
      width: 100%;
      max-width: <?= htmlspecialchars($cardMaxWidth) ?>;
      box-shadow: var(--shadow-lg);
    }

    .auth-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 1.75rem;
    }

    .auth-brand-logo {
      width: 34px;
      height: 34px;
      background: var(--p);
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 14px;
      flex-shrink: 0;
    }

    .auth-brand-name {
      font-family: var(--serif);
      font-size: 1.15rem;
      font-weight: 500;
      color: var(--text);
    }

    .auth-brand-name em { font-style: italic; color: var(--p); }

    .auth-brand-badge {
      font-size: 9px;
      font-weight: 700;
      letter-spacing: .06em;
      background: var(--gr-bg);
      color: var(--green);
      border: 1px solid var(--gr-b);
      border-radius: var(--r-full);
      padding: 2px 7px;
    }
  </style>
</head>
<body>

<div class="auth-card">

  <!-- Shared brand header -->
  <div class="auth-brand">
    <div class="auth-brand-logo">
      <i class="fa-solid fa-car-side"></i>
    </div>
    <div class="auth-brand-name">Sales<em>Desk</em></div>
    <div class="auth-brand-badge">ZA</div>
  </div>

  <!-- Page content injected here -->
  <?= $cardContent ?>

</div>

<!-- Global JS (CSRF + OTP widget) -->
<script src="/assets/js/global.js?v=<?= $assetVersion ?>"></script>

<?php if ($needsLocations): ?>
<script src="/assets/js/sa-locations.js?v=<?= $assetVersion ?>"></script>
<?php endif; ?>

<?php if ($needsDealerSearch): ?>
<script src="/assets/js/dealer-search.js?v=<?= $assetVersion ?>"></script>
<?php endif; ?>

</body>
</html>
