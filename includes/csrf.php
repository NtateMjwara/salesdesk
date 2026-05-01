<?php
/**
 * SalesDesk — CSRF protection.
 * Tokens are per-session (not per-form), rotated on each login.
 */
require_once __DIR__ . '/session.php';

/**
 * Generate (or return existing) CSRF token for the current session.
 */
function generateCSRFToken(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Validate the CSRF token submitted with a POST request.
 * Terminates with 403 on failure — never redirect, to prevent loops.
 */
function validateCSRF(): void
{
    $submitted = $_POST[CSRF_TOKEN_NAME] ?? '';
    $stored    = $_SESSION[CSRF_TOKEN_NAME] ?? '';

    if (empty($submitted) || empty($stored) || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        exit('Invalid or missing CSRF token.');
    }
}