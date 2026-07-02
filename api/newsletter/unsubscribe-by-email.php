<?php
/**
 * SalesDesk — API: Unsubscribe by Email Address
 * Route: POST /api/newsletter/unsubscribe-by-email.php
 *
 * Fallback endpoint called when the user reaches the unsubscribe page
 * without a valid token (forwarded link, expired URL, etc.).
 *
 * No auth required — any valid email can be unsubscribed.
 * Returns JSON { success: bool, message: string }.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/response.php';

applyCachePolicy('api');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Rate limit: 10 unsubscribe attempts / IP / minute (generous — legitimate use)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'newsletter_unsub_email', 10)) {
    jsonResponse(['success' => false, 'message' => 'Too many requests. Please wait a moment.'], 429);
}

$email = trim($_POST['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
}

$pdo  = Database::getInstance();
$stmt = $pdo->prepare(
    "SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1"
);
$stmt->execute([$email]);
$sub = $stmt->fetch();

if (!$sub) {
    // Don't expose whether the email is in the DB or not (privacy)
    jsonResponse([
        'success' => true,
        'message' => 'If that address was subscribed, it has been removed.',
    ]);
}

if ($sub['status'] === 'unsubscribed') {
    jsonResponse([
        'success' => true,
        'message' => 'You are already unsubscribed.',
    ]);
}

$pdo->prepare(
    "UPDATE newsletter_subscribers SET status = 'unsubscribed', updated_at = NOW() WHERE id = ?"
)->execute([$sub['id']]);

writeAuditLog(
    'newsletter.unsubscribed_by_email',
    'newsletter_subscriber',
    (int) $sub['id'],
    ['status' => $sub['status']],
    ['status' => 'unsubscribed', 'method' => 'email_form'],
    null
);

jsonResponse([
    'success' => true,
    'message' => htmlspecialchars($email) . ' has been unsubscribed. You won\'t hear from us again.',
]);
