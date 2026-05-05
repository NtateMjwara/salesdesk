<?php
/**
 * SalesDesk — CSRF protection.
 * T1 owns this file.
 *
 * Tokens are per-session (not per-form), rotated on each login.
 *
 * Functions:
 *   generateCSRFToken(): string          — generate / return current token
 *   validateCSRF(): void                 — validate POST token, exit 403 on failure
 *   csrf_hidden_field(): string          — render a hidden input tag (HTML-safe)
 *   csrf_meta_tag(): string              — render <meta name="csrf-token"> for JS
 *
 * Usage in forms:
 *   <form method="POST">
 *     <?= csrf_hidden_field() ?>
 *     ...
 *   </form>
 *
 * Usage in layouts (so global.js CSRF auto-inject can read it):
 *   <?= csrf_meta_tag() ?>
 *
 * The global.js CSRF auto-inject reads the meta tag and appends the
 * token to all fetch() POSTs and X-CSRF-Token headers automatically.
 * Server-side forms still need csrf_hidden_field() as a fallback for
 * non-JS form submissions.
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
 *
 * Accepts the token from:
 *   1. $_POST[CSRF_TOKEN_NAME]     — standard HTML form field
 *   2. HTTP header X-CSRF-Token    — fetch() requests via global.js auto-inject
 */
function validateCSRF(): void
{
    $submitted = $_POST[CSRF_TOKEN_NAME]
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored    = $_SESSION[CSRF_TOKEN_NAME] ?? '';

    if (empty($submitted) || empty($stored) || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        exit('Invalid or missing CSRF token.');
    }
}

/**
 * Render a hidden CSRF input field for use inside HTML forms.
 * Returns the HTML string — echo or concatenate it into your form.
 *
 * Example:
 *   <form method="POST">
 *     <?= csrf_hidden_field() ?>
 *     <button type="submit">Submit</button>
 *   </form>
 */
function csrf_hidden_field(): string
{
    $name  = htmlspecialchars(CSRF_TOKEN_NAME, ENT_QUOTES, 'UTF-8');
    $value = htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="' . $name . '" value="' . $value . '">';
}

/**
 * Render a <meta> tag carrying the CSRF token for JavaScript consumption.
 * Place in the <head> of layout-app.php and layout-auth.php so global.js
 * can read it via document.querySelector('meta[name="csrf-token"]').
 *
 * Example (in layout-app.php <head>):
 *   <?= csrf_meta_tag() ?>
 */
function csrf_meta_tag(): string
{
    $value = htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8');
    return '<meta name="csrf-token" content="' . $value . '">';
}
