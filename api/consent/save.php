<?php
/**
 * SalesDesk — Save Cookie Consent
 * Route: POST /api/consent/save.php
 *
 * Called by assets/js/cookie-consent.js (or the reload of any page
 * that already has a functions cookie-consent.php require) whenever
 * the visitor accepts all, rejects all, or saves custom preferences.
 *
 * Follows the same CSRF pattern already used by
 * api/outreach/register.php and api/outreach/partner-enquiry.php —
 * session-stored token, checked against the POSTed value.
 *
 * Sets the consent cookie server-side (in addition to the client-side
 * set the JS does immediately, for instant script-blocking) so it's
 * also correct on the very next server-rendered request, and logs one
 * row to cookie_consents for POPIA/GDPR accountability.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';
require_once '../../includes/visitor.php';
require_once '../../includes/cookie-consent.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION[CSRF_TOKEN_NAME]) || !hash_equals($_SESSION[CSRF_TOKEN_NAME], (string) $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session. Please refresh and try again.']);
    exit;
}

$action = trim($_POST['action'] ?? '');
if (!in_array($action, ['accept_all', 'reject_all', 'custom'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

// ── Resolve the decision per category ──────────────────────────
$categories = array_keys(cookieConsentCategories());
$decision   = [];

foreach ($categories as $cat) {
    if ($cat === 'necessary') { $decision[$cat] = true; continue; }

    $decision[$cat] = match ($action) {
        'accept_all' => true,
        'reject_all' => false,
        // 'custom' — read each toggle explicitly; anything not sent
        // is treated as declined, never assumed accepted.
        default      => in_array($_POST[$cat] ?? '', ['1', 'true', 'on'], true),
    };
}

// ── Persist the cookie (1 year, matches typical consent-refresh cycles) ──
$cookiePayload = array_merge(['v' => COOKIE_CONSENT_POLICY_VERSION], $decision);
$cookieValue   = json_encode($cookiePayload);
$oneYear       = 60 * 60 * 24 * 365;

setcookie(COOKIE_CONSENT_COOKIE_NAME, $cookieValue, [
    'expires'  => time() + $oneYear,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false, // JS must be able to read this to gate scripts client-side
    'samesite' => 'Lax',
]);

// ── Log the event for auditability ─────────────────────────────
try {
    $pdo     = Database::getInstance();
    $visitor = initVisitorSession();

    recordCookieConsent(
        $pdo,
        $visitor['id'] ?? null,
        $decision,
        $action,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );
} catch (Throwable) {
    // Fail soft: the visitor's browser cookie is already set above,
    // which is what actually governs behavior on their device. A
    // failed audit-log insert shouldn't block them from dismissing
    // the banner — but it's worth alerting on in your error logs.
    error_log('cookie_consents insert failed');
}

echo json_encode(['success' => true, 'decision' => $decision]);
