<?php
/**
 * SalesDesk — Cookie Consent CSRF Token
 * Route: GET /api/consent/token.php
 *
 * WHY THIS EXISTS AS ITS OWN ENDPOINT:
 * Most public pages in this codebase call applyCachePolicy('public'),
 * which is intended to let a reverse proxy/CDN cache the rendered HTML.
 * If the cookie-consent banner's CSRF token were embedded directly in
 * that HTML (the way outreach/index.php embeds one, since that page is
 * NOT cached), the token from whichever visitor's request happened to
 * populate the cache would be served to every subsequent visitor —
 * breaking CSRF protection and potentially letting one visitor's
 * consent-save request be replayed under another's session.
 *
 * Fetching the token via a small, never-cached JSON endpoint keeps the
 * banner working correctly on cached pages without having to disable
 * caching on every public page just to accommodate the banner.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}

echo json_encode(['csrf_token' => $_SESSION[CSRF_TOKEN_NAME]]);
