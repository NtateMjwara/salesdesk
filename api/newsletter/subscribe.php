<?php
/**
 * SalesDesk — API: Newsletter Subscribe
 * Route: POST /api/newsletter/subscribe.php
 *
 * Called from:
 *   - Footer subscribe form (layout-public.php)
 *   - News listing page strip (news/index.php)
 *   - Article page strip (news/article.php)
 *
 * Flow:
 *   1. Validate email
 *   2. Insert / re-subscribe via subscribeEmail()
 *   3. Send double opt-in confirmation email
 *   4. Return JSON
 *
 * Returns JSON:
 *   { success: bool, state: string, message: string }
 *
 * Rate limited to 5 subscribe attempts per IP per minute
 * (reuses checkApiRateLimit from functions.php).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/response.php';
require_once '../../includes/newsletter.php';

applyCachePolicy('api');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Rate limit: 5 subscribe attempts / IP / minute
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'newsletter_subscribe', 5)) {
    jsonResponse(['success' => false, 'message' => 'Too many requests. Please slow down.'], 429);
}

// Input
$email     = trim($_POST['email']      ?? '');
$firstName = trim($_POST['first_name'] ?? '') ?: null;
$source    = trim($_POST['source']     ?? 'footer');

// Sanitise source so only known values reach the DB
$allowedSources = ['footer', 'news_page', 'article_page', 'checkout', 'popup'];
if (!in_array($source, $allowedSources, true)) {
    $source = 'footer';
}

// Validate email
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
}

if (strlen($email) > 254) {
    jsonResponse(['success' => false, 'message' => 'Email address is too long.']);
}

// Subscribe
$result = subscribeEmail($email, $firstName, $source);

if (!$result['ok']) {
    jsonResponse(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}

// Already active — no need to re-send confirmation
if ($result['state'] === 'already_active') {
    jsonResponse([
        'success' => true,
        'state'   => 'already_active',
        'message' => 'You\'re already subscribed — thank you!',
    ]);
}

// Already pending — tell user to check their inbox rather than spamming
if ($result['state'] === 'pending') {
    jsonResponse([
        'success' => true,
        'state'   => 'pending',
        'message' => 'We already sent you a confirmation email. Please check your inbox (or spam folder).',
    ]);
}

// New or re-subscribe — send confirmation email
$token   = $result['token'];
$mailOk  = sendNewsletterConfirmation($email, $firstName, $token);

if (!$mailOk) {
    // Don't expose internal error — subscriber is saved, just email failed.
    // Log and return a softer message.
    error_log("[SalesDesk Newsletter] Confirmation email failed for {$email}");
    jsonResponse([
        'success' => true,
        'state'   => $result['state'],
        'message' => 'Almost there! We had trouble sending your confirmation email. '
                   . 'Please try subscribing again in a moment.',
    ]);
}

$message = $result['state'] === 'resubscribed'
    ? 'Welcome back! Check your inbox to re-confirm your subscription.'
    : 'Check your inbox for a confirmation link — then you\'re all set!';

jsonResponse([
    'success' => true,
    'state'   => $result['state'],
    'message' => $message,
]);
