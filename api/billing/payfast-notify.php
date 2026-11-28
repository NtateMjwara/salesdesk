<?php
/**
 * SalesDesk — PayFast ITN (Instant Transaction Notification)  (plans Phase 3)
 * Route: POST /api/billing/payfast-notify   (notify_url sent with every checkout)
 *
 * PayFast posts here after every payment event of a subscription: the first
 * payment, each monthly renewal, a failed payment, a cancellation. We answer
 * 200 straight away (PayFast retries until it gets one), then validate and
 * apply in sdBillingProcessItn() — signature + passphrase, PayFast source,
 * amount, server confirmation — and log the result in billing_events.
 *
 * No session, no CSRF: this is PayFast's server talking to ours.
 */

declare(strict_types=1);

require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/billing.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Tell PayFast we got it, then do the work.
ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$outcome = sdBillingProcessItn($_POST, sdPayfastRemoteIp());
echo 'OK';
if (!str_starts_with($outcome, 'activated') && !in_array($outcome, ['renewed', 'cancelled', 'failed', 'duplicate'], true)) {
    error_log('[SalesDesk PayFast ITN] ' . $outcome);
}
