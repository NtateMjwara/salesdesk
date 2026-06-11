<?php
/**
 * SalesDesk — Auth Layout Shell
 * T1 owns this file.
 *
 * FIXES APPLIED:
 *   FIX-01: global.js moved from body footer to <head> (no defer).
 *           Inline scripts in $cardContent call initOTPWidget() which is
 *           defined in global.js. When global.js was at the bottom of <body>,
 *           the inline script ran first and threw "initOTPWidget is not defined",
 *           meaning the hidden OTP field was never populated → POST always had
 *           an empty otp value → "Please enter the 6-digit code" error on every
 *           valid OTP submission.
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

  <!--
    FIX-01: global.js loaded in <head> (synchronously, no defer/async).
    This guarantees initOTPWidget() is defined before any inline <script>
    in $cardContent executes. Previously it was at the bottom of <body>,
    causing a "initOTPWidget is not defined" ReferenceError on OTP pages.
  -->
  <script src="/assets/js/global.js?v=<?= $assetVersion ?>"></script>

  <?php if ($needsLocations): ?>
  <script src="/assets/js/sa-locations.js?v=<?= $assetVersion ?>"></script>
  <?php endif; ?>

  <?php if ($needsDealerSearch): ?>
  <script src="/assets/js/dealer-search.js?v=<?= $assetVersion ?>"></script>
  <?php endif; ?>

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

</body>
</html>
