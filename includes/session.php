<?php
/**
 * SalesDesk — Session bootstrap.
 * T1 owns this file.
 *
 * FIXES APPLIED:
 *   FIX-02: Session timeout key normalised to '_created' throughout.
 *           authentication.php was writing $_SESSION['created'] (no underscore)
 *           while session.php checked $_SESSION['_created'] (with underscore).
 *           Result: the timeout check found no key on first load, stamped
 *           '_created', but the login handler then stamped a separate 'created'
 *           key that was never checked — sessions never expired. This file is
 *           the canonical definition; authentication.php is updated to match.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {

    // ── Redis session handler ─────────────────────────────────
    if (defined('USE_REDIS_SESSIONS') && USE_REDIS_SESSIONS) {
        $redisHost   = defined('REDIS_HOST')           ? REDIS_HOST           : '127.0.0.1';
        $redisPort   = defined('REDIS_PORT')           ? (int) REDIS_PORT     : 6379;
        $redisPrefix = defined('REDIS_SESSION_PREFIX') ? REDIS_SESSION_PREFIX : 'salesdesk_sess_';

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
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('SD_SESS');
    session_start();
}

// ── Absolute session timeout ──────────────────────────────────
// FIX-02: Canonical key is '_created' (with leading underscore).
// authentication.php previously used 'created' — now corrected there too.
if (isset($_SESSION['_created'])) {
    $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
    if ((time() - $_SESSION['_created']) > $timeout) {
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
