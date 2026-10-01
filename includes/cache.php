<?php
/**
 * SalesDesk — Small data cache  (includes/cache.php)
 * T1 owns this file.
 *
 * For PUBLIC, non-personal aggregates only (listing counts, newest cars,
 * latest news, top desks). Never cache anything tied to a visitor, user
 * or session through this helper.
 *
 *   $count = sdCache('home_total_cars', 300, fn () => (int) $pdo->query(...)->fetchColumn());
 *
 * Backends, in order:
 *   1. APCu (shared memory) when the extension is enabled.
 *   2. JSON files in SD_CACHE_DIR, or a private folder under the system
 *      temp directory. JSON — not serialize() — so a tampered cache file
 *      can never instantiate PHP objects.
 *
 * Failure policy: if $build throws, the exception propagates and NOTHING
 * is cached, so a transient DB error is never pinned for the TTL. Wrap the
 * call in try/catch at the call site and fall back to a safe default.
 *
 * Values must be JSON-safe: scalars and (nested) arrays.
 */

declare(strict_types=1);

if (!function_exists('sdCache')) {

    function sdCache(string $key, int $ttl, callable $build): mixed
    {
        $key = 'sd_' . preg_replace('/[^a-z0-9_\-]/i', '_', $key);

        // ── 1. APCu ──────────────────────────────────────────────
        if (function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled()) {
            $hit   = false;
            $value = apcu_fetch($key, $hit);
            if ($hit) {
                return $value;
            }
            $value = $build();
            apcu_store($key, $value, $ttl);
            return $value;
        }

        // ── 2. JSON file ─────────────────────────────────────────
        $dir  = sdCacheDir();
        $file = $dir . '/' . $key . '.json';

        if (is_file($file) && (time() - (int) @filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && array_key_exists('v', $decoded)) {
                    return $decoded['v'];
                }
            }
        }

        $value = $build();

        $json = json_encode(['v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && is_dir($dir) && is_writable($dir)) {
            // Write to a temp file then rename, so readers never see a half-written file.
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                @rename($tmp, $file);
            } else {
                @unlink($tmp);
            }
        }

        return $value;
    }

    /** Clear one cached key (e.g. after a dealer publishes a car). */
    function sdCacheForget(string $key): void
    {
        $key = 'sd_' . preg_replace('/[^a-z0-9_\-]/i', '_', $key);

        if (function_exists('apcu_delete') && function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_delete($key);
        }
        @unlink(sdCacheDir() . '/' . $key . '.json');
    }

    function sdCacheDir(): string
    {
        static $dir = null;
        if ($dir !== null) {
            return $dir;
        }

        $dir = defined('SD_CACHE_DIR')
            ? rtrim((string) SD_CACHE_DIR, '/')
            // Per-install folder name so two sites on one host never share entries.
            : rtrim(sys_get_temp_dir(), '/') . '/salesdesk-cache-' . substr(md5(__DIR__), 0, 12);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }
}
