<?php
/**
 * SalesDesk — Public Layout Shell
 * T4 owns this file for usage; T1 owns the definition.
 *
 * Minimal shell for buyer-facing pages:
 *   public/c/{car-slug}/index.php   — share link / lead form
 *   public/{broker-slug}/index.php  — broker public storefront
 *
 * No authenticated nav. Caches for 5 minutes (public CDN-friendly).
 *
 * USAGE:
 *
 *   $pageTitle       = 'Toyota Corolla Cross | SalesDesk';
 *   $ogTitle         = '2022 Toyota Corolla Cross — R349,900';
 *   $ogDescription   = 'Listed by Sipho\'s SalesDesk';
 *   $ogImage         = 'https://...';
 *   $canonicalUrl    = 'https://salesdesk.co.za/...';
 *
 *   ob_start();
 *   // page HTML
 *   $pageContent = ob_get_clean();
 *
 *   require_once '../../views/layout-public.php';
 *
 * VARIABLES consumed:
 *   string $pageTitle      — <title>
 *   string $pageContent    — page HTML
 *   string $ogTitle        — og:title (falls back to $pageTitle)
 *   string $ogDescription  — og:description
 *   string $ogImage        — og:image URL
 *   string $canonicalUrl   — canonical URL
 *   string $assetVersion   — cache-buster
 */

$pageTitle      = $pageTitle      ?? 'SalesDesk';
$pageContent    = $pageContent    ?? '';
$ogTitle        = $ogTitle        ?? $pageTitle;
$ogDescription  = $ogDescription  ?? 'South Africa\'s trusted car sales platform.';
$ogImage        = $ogImage        ?? '';
$canonicalUrl   = $canonicalUrl   ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
$assetVersion   = $assetVersion   ?? date('Ymd');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- SEO / Open Graph -->
  <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">

  <meta property="og:type"        content="website">
  <meta property="og:title"       content="<?= htmlspecialchars($ogTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta property="og:url"         content="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php if ($ogImage): ?>
  <meta property="og:image"       content="<?= htmlspecialchars($ogImage) ?>">
  <?php endif; ?>

  <!-- Twitter card -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:title"       content="<?= htmlspecialchars($ogTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <?php if ($ogImage): ?>
  <meta name="twitter:image"       content="<?= htmlspecialchars($ogImage) ?>">
  <?php endif; ?>

  <!-- Assets -->
  <link rel="stylesheet" href="/assets/css/global.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/components.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="/assets/css/broker.css?v=<?= $assetVersion ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    /* Public shell-specific styles */
    .pub-header {
      background: var(--white);
      border-bottom: 1px solid var(--border);
      padding: 14px 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .pub-brand {
      font-family: var(--serif);
      font-size: 1.05rem;
      font-weight: 500;
      color: var(--text);
      text-decoration: none;
    }

    .pub-brand em { font-style: italic; color: var(--p); }

    .pub-body {
      max-width: 780px;
      margin: 0 auto;
      padding: 1.5rem 1rem 4rem;
    }

    .pub-footer {
      text-align: center;
      padding: 1.5rem 1rem;
      font-size: 12px;
      color: var(--faint);
      border-top: 1px solid var(--border);
      margin-top: 3rem;
    }

    .pub-footer a { color: var(--muted); text-decoration: none; }
    .pub-footer a:hover { color: var(--text); }
  </style>
</head>
<body>

<header class="pub-header">
  <a href="/" class="pub-brand">Sales<em>Desk</em></a>
  <a href="/auth/login.php"
     style="font-size:13px;color:var(--muted);text-decoration:none;">
    Sign in
  </a>
</header>

<main class="pub-body" id="main-content">
  <?= $pageContent ?>
</main>

<footer class="pub-footer">
  <p>
    &copy; <?= date('Y') ?> SalesDesk (Pty) Ltd &middot; South Africa &middot;
    <a href="/privacy">Privacy Policy</a> &middot;
    <a href="/terms">Terms of Service</a>
  </p>
  <p style="margin-top:5px">
    All leads are cryptographically attributed at submission — your commission is protected.
  </p>
</footer>

<script src="/assets/js/global.js?v=<?= $assetVersion ?>"></script>

</body>
</html>
