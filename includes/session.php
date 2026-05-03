<?php
/**
 * SalesDesk — Session bootstrap.
 * T1 owns this file.
 *
 * Must be required before any output.
 * Supports both file-based sessions (dev) and Redis sessions (production).
 *
 * To enable Redis:
 *   1. Set USE_REDIS_SESSIONS = true in config.php
 *   2. Install php-redis extension: sudo apt install php-redis
 *   3. Ensure Redis is running: sudo systemctl start redis-server
 *   4. Confirm REDIS_HOST, REDIS_PORT, REDIS_SESSION_PREFIX are set in config.php
 *
 * T2 must test the full wizard flow under Redis before merging Phase B.
 * File sessions create exclusive locks that deadlock concurrent wizard POSTs.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {

    // ── Redis session handler ─────────────────────────────────
    // Activated when USE_REDIS_SESSIONS = true in config.php.
    // Redis eliminates file-lock deadlocks on concurrent wizard POSTs.
    if (defined('USE_REDIS_SESSIONS') && USE_REDIS_SESSIONS) {
        $redisHost   = defined('REDIS_HOST')           ? REDIS_HOST           : '127.0.0.1';
        $redisPort   = defined('REDIS_PORT')           ? (int) REDIS_PORT     : 6379;
        $redisPrefix = defined('REDIS_SESSION_PREFIX') ? REDIS_SESSION_PREFIX : 'salesdesk_sess_';

        // php-redis extension must be installed.
        if (!extension_loaded('redis')) {
            error_log('[SalesDesk session] php-redis extension not loaded — falling back to file sessions.');
        } else {
            ini_set('session.save_handler', 'redis');
            ini_set(
                'session.save_path',
                "tcp://{$redisHost}:{$redisPort}?prefix={$redisPrefix}&timeout=2&read_timeout=2"
            );
        }
    }

    // ── Cookie parameters ─────────────────────────────────────
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',          // current domain only
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('SD_SESS');
    session_start();
}

// ── Absolute session timeout ──────────────────────────────────
// Enforced in application layer regardless of session backend.
if (isset($_SESSION['_created'])) {
    $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
    if ((time() - $_SESSION['_created']) > $timeout) {
        // Session expired — destroy and redirect to login.
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: /auth/login.php?timeout=1&redirect=' . $redirect);
        exit;
    }
} else {
    // Stamp the session creation time on first use.
    $_SESSION['_created'] = time();
}
