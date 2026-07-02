<?php
/**
 * SalesDesk — API: Newsletter Confirm Subscription
 * Route: GET /api/newsletter/confirm.php?token={token}
 *
 * Called when the user clicks the link in their confirmation email.
 * Activates the subscriber and redirects to a thank-you page.
 *
 * Outcomes:
 *   confirmed      → redirect with ?confirmed=1  (show success state)
 *   already_active → redirect with ?confirmed=already
 *   expired        → redirect with ?error=expired
 *   not_found      → redirect with ?error=invalid
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/response.php';
require_once '../../includes/newsletter.php';

applyCachePolicy('api');

$token  = trim($_GET['token'] ?? '');
$result = confirmSubscription($token);

if (!$result['ok']) {
    $reason = $result['reason'] ?? 'invalid';
    redirect('/newsletter/confirmed.php?error=' . urlencode($reason));
}

// Fire welcome email on first confirmation (not on already_confirmed)
if ($result['reason'] === 'confirmed' && !empty($result['subscriber'])) {
    $sub = $result['subscriber'];
    sendNewsletterWelcome($sub['email'], $sub['first_name'] ?? null);
}

$state = $result['reason'] === 'already_confirmed' ? 'already' : '1';
redirect('/newsletter/confirmed.php?confirmed=' . $state);
