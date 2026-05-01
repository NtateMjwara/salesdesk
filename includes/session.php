<?php
/**
 * SalesDesk — Session bootstrap.
 * Must be required before any output.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = SESSION_TIMEOUT;

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

// ── Absolute session timeout ─────────────────────────────────
if (isset($_SESSION['created'])) {
    if ((time() - $_SESSION['created']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        header('Location: /auth/login.php?timeout=1');
        exit;
    }
} else {
    $_SESSION['created'] = time();
}