<?php
require_once '../includes/security.php';
require_once '../includes/session.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $p['path'], $p['domain'],
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        true
    );
}

session_destroy();
header('Location: /auth/login.php');
exit;