<?php
/**
 * SalesDesk — Asset URLs  (includes/assets.php)
 * T1 owns this file.
 *
 *   <link rel="stylesheet" href="<?= sdAsset('/assets/css/home.css') ?>">
 *   → /assets/css/home.css?v=3f9a1c2e
 *
 * WHY: .htaccess serves CSS/JS with "Cache-Control: max-age=31536000,
 * immutable", so a file's URL MUST change whenever its contents change.
 * The old cache-buster was ?v=date('Ymd'). Any file deployed twice on the
 * same day kept the same URL, and browsers, LiteSpeed Cache or Cloudflare
 * went on serving the old copy for up to a year. That is exactly what broke
 * the homepage (new markup, old home.css).
 *
 * The version is derived from the file's modification time + size, so it
 * changes automatically on every upload. No manual bumping, ever.
 * Result is memoised per request; cost is one stat() per asset.
 */

declare(strict_types=1);

if (!function_exists('sdAsset')) {

    function sdAsset(string $path): string
    {
        static $memo = [];
        if (isset($memo[$path])) {
            return $memo[$path];
        }

        $clean = '/' . ltrim(strtok($path, '?') ?: $path, '/');
        $file  = dirname(__DIR__) . $clean;

        if (is_file($file)) {
            $stat    = @stat($file);
            $version = $stat ? substr(md5($stat['mtime'] . '-' . $stat['size']), 0, 8) : date('YmdHi');
        } else {
            // Missing file: still emit a URL (so the 404 is visible in DevTools)
            // but never a stable one that could get cached.
            $version = 'missing-' . date('YmdHi');
        }

        return $memo[$path] = htmlspecialchars($clean . '?v=' . $version, ENT_QUOTES, 'UTF-8');
    }
}
