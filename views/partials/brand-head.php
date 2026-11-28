<?php
/**
 * SalesDesk — Brand <head> tags (favicons, app icons, manifest)
 * views/partials/brand-head.php
 *
 * ONE source for the icon tags every layout prints
 * (layout-public.php, layout-app.php, layout-auth.php).
 *
 * Asset set (Nov 2026 rebrand — "S" speed mark):
 *   /favicon.ico                          16/32/48 tile, legacy + default /favicon.ico requests
 *   /assets/img/brand/favicon.svg         adaptive: ink mark on light tabs, white on dark tabs
 *   /assets/img/brand/favicon-32.png      PNG fallback (rounded ink tile, white mark)
 *   /assets/img/brand/favicon-16.png
 *   /apple-touch-icon.png                 180×180, full-bleed (iOS applies its own mask)
 *   /site.webmanifest                     Android / PWA icons (192, 512, 512 maskable)
 *
 * Bump SD_BRAND_V when any icon file changes — images are cached for a
 * day with a week of stale-while-revalidate (.htaccess PERF-1).
 */
if (!defined('SD_BRAND_V')) {
    define('SD_BRAND_V', '20261126');
}
$sdBv = SD_BRAND_V;
?>
  <link rel="icon" href="/favicon.ico?v=<?= $sdBv ?>" sizes="48x48">
  <link rel="icon" href="/assets/img/brand/favicon.svg?v=<?= $sdBv ?>" type="image/svg+xml">
  <link rel="icon" href="/assets/img/brand/favicon-32.png?v=<?= $sdBv ?>" type="image/png" sizes="32x32">
  <link rel="icon" href="/assets/img/brand/favicon-16.png?v=<?= $sdBv ?>" type="image/png" sizes="16x16">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=<?= $sdBv ?>" sizes="180x180">
  <link rel="manifest" href="/site.webmanifest?v=<?= $sdBv ?>">
