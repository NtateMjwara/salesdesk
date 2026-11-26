<?php
/**
 * SalesDesk — API: Outreach Registration
 * Route: POST /api/outreach/register.php
 *
 * Called by outreach/index.php's registration form (fetch + FormData,
 * same pattern as the enquiry form in assets/js/public.js).
 *
 * Returns JSON:
 *   { success: true,  duplicate: false }                  — registered
 *   { success: true,  duplicate: true  }                  — already on file
 *   { success: false, errors: { field: message, ... } }   — validation failed
 *   { success: false, message: string }                   — hard error
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';
require_once '../../includes/outreach.php';

applyCachePolicy('api');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Rate limit: 5 registration attempts / IP / minute — generous enough
// for a genuine retry after a typo, tight enough to blunt scripted abuse.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'outreach_register', 5)) {
    rateLimitResponse();
}

validateCSRF();

// Registrations can be paused from the admin side without a code
// deploy (see platform_config.outreach_registrations_open).
if (getPlatformConfig('outreach_registrations_open', '1') !== '1') {
    jsonResponse([
        'success' => false,
        'message' => 'Registrations are temporarily closed while we confirm partnership details. Please check back soon.',
    ]);
}

$result = registerOutreachInterest($_POST);

if (!$result['ok']) {
    jsonResponse(['success' => false, 'errors' => $result['errors']]);
}

if ($result['duplicate']) {
    jsonResponse([
        'success'   => true,
        'duplicate' => true,
        'message'   => 'This ID number is already registered — you\'re on the list, no need to submit again.',
    ]);
}

jsonResponse([
    'success'   => true,
    'duplicate' => false,
    'message'   => 'Thank you for registering your interest. We\'ll be in touch once participant recruitment begins.',
]);
