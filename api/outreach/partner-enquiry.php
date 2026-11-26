<?php
/**
 * SalesDesk — API: Outreach Partner Enquiry
 * Route: POST /api/outreach/partner-enquiry.php
 *
 * Backs the "I'm a dealership" / "I'm an employer" cards on
 * /outreach/. Kept separate from api/leads/submit.php since this is
 * a B2B partnership enquiry, not a buyer lead.
 *
 * Expects: enquiry_type ('dealer'|'employer'), contact_name, email,
 * company_name?, phone?, message?
 *
 * Returns JSON: { success: bool, errors?: {...}, message?: string }
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

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'outreach_partner_enquiry', 8)) {
    rateLimitResponse();
}

validateCSRF();

$result = submitOutreachPartnerEnquiry($_POST);

if (!$result['ok']) {
    jsonResponse(['success' => false, 'errors' => $result['errors']]);
}

// TODO(notifications): fire an internal notification/email to the
// outreach team here (see includes/mailer.php + includes/notifications
// pattern used elsewhere) so partner enquiries don't rely on someone
// remembering to check the admin screen.

jsonResponse([
    'success' => true,
    'message' => 'Thanks for reaching out — our outreach team will be in touch shortly.',
]);
