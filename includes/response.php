<?php
/**
 * SalesDesk — HTTP Response helpers.
 *
 * Extracted from config.php so configuration and HTTP response control
 * live in separate files (single responsibility).
 *
 * All other files that need redirect() or jsonResponse() should:
 *   require_once __DIR__ . '/response.php';
 *
 * config.php still defines SITE_URL, APP_NAME etc. but no longer
 * contains response logic.
 */

/**
 * Issue a Location redirect and exit immediately.
 * Flushes any open output buffers first.
 *
 * @param string $url  Absolute path (starting with /) or full URL.
 */
function redirect(string $url): never
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Emit a JSON response with the correct Content-Type header and exit.
 *
 * @param array $data   Associative array to encode.
 * @param int   $status HTTP status code (default 200).
 */
function jsonResponse(array $data, int $status = 200): never
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Emit a 429 Too Many Requests JSON response and exit.
 * Used by checkApiRateLimit().
 */
function rateLimitResponse(): never
{
    jsonResponse(
        ['error' => 'Too many requests. Please slow down.'],
        429
    );
}
