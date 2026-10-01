<?php
/**
 * SalesDesk — One-time browser cache purge  (includes/cache-reset.php)
 * T1 owns this file.
 *
 * Tells each visitor's browser, ONCE, to delete everything it has cached
 * for salesdesk.co.za, so nobody keeps running stale CSS/JS from before
 * the asset-versioning fix.
 *
 *   require_once __DIR__ . '/../includes/cache-reset.php';
 *   sdPurgeBrowserCacheOnce();              // before any output
 *
 * HOW
 *   Sends  Clear-Site-Data: "cache"  on the visitor's next page load, then
 *   sets a small cookie holding SD_CACHE_EPOCH so it doesn't repeat.
 *   Only the HTTP cache is cleared: cookies, logins, localStorage and the
 *   visitor session are untouched ("cache" only, never "*").
 *
 * WHEN TO USE AGAIN
 *   Change SD_CACHE_EPOCH (any new string). Every visitor gets exactly
 *   one more purge. With sdAsset() versioning in place this should
 *   rarely, if ever, be needed again.
 *
 * LIMITS
 *   – HTTPS only (browsers ignore the header on plain HTTP).
 *   – Chrome, Edge, Firefox and Opera honour it; Safari support for
 *     "cache" is partial. Those visitors still recover automatically,
 *     because every asset URL is now new.
 *   – Clears the BROWSER only. A server cache (cPanel LiteSpeed Cache)
 *     or Cloudflare must be purged from its own dashboard.
 */

declare(strict_types=1);

if (!defined('SD_CACHE_EPOCH')) {
    // Bump this string to trigger one more purge for every visitor.
    define('SD_CACHE_EPOCH', '2026-09-17-assets');
}

if (!function_exists('sdPurgeBrowserCacheOnce')) {

    function sdPurgeBrowserCacheOnce(): void
    {
        $cookie = 'sd_cache_epoch';

        if (headers_sent()) {
            return;
        }

        // Page navigations only — never API calls, XHR/fetch, or POSTs.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'document')) {
            return;
        }

        if (($_COOKIE[$cookie] ?? '') === SD_CACHE_EPOCH) {
            return;   // this browser has already been purged for this epoch
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        header('Clear-Site-Data: "cache"');

        // This response must not be stored anywhere either, or a shared
        // cache could replay the purge header / cookie to other visitors.
        header('Cache-Control: no-store, private');

        setcookie($cookie, SD_CACHE_EPOCH, [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
