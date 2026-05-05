<?php
/**
 * SalesDesk — Notifications: Mark Read
 * POST /api/notifications/mark-read.php
 * T1 owns this file.
 *
 * Marks one or all notifications read for the authenticated user.
 * The nav badge count in layout-app.php queries live — calling this
 * updates the DB so the count drops on the next page load.
 *
 * POST body (JSON or form):
 *   notification_id  int|null   If omitted or null: mark ALL unread as read
 *
 * Response JSON:
 *   { success: true, unread_remaining: int }
 *
 * Requires: active session (user must be logged in).
 * Rate limited: 60 req/min per IP.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/response.php';

applyCachePolicy('api');
header('Content-Type: application/json; charset=utf-8');

// ── Rate limit ────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'notifications_mark_read')) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests.']);
    exit;
}

// ── Auth guard ────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

// ── Method ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// ── CSRF validation ───────────────────────────────────────────
// Supports both form POST and fetch() with X-CSRF-Token header
// (global.js injects the header automatically for all fetch POSTs).
$submittedToken = $_POST[CSRF_TOKEN_NAME]
    ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$storedToken    = $_SESSION[CSRF_TOKEN_NAME] ?? '';

if (empty($submittedToken) || !hash_equals($storedToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ── Parse body ────────────────────────────────────────────────
// Accept both form POST and JSON body.
$notificationId = null;
$contentType    = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $notificationId = isset($body['notification_id']) ? (int) $body['notification_id'] : null;
} else {
    $notificationId = isset($_POST['notification_id']) && $_POST['notification_id'] !== ''
        ? (int) $_POST['notification_id']
        : null;
}

try {
    $pdo = Database::getInstance();

    if ($notificationId !== null && $notificationId > 0) {
        // Mark a single notification read — only if it belongs to this user.
        $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE id = ? AND user_id = ? AND is_read = 0
        ")->execute([$notificationId, $userId]);
    } else {
        // Mark ALL unread notifications read for this user.
        $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ")->execute([$userId]);
    }

    // Return the new unread count.
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
    ");
    $countStmt->execute([$userId]);
    $remaining = (int) $countStmt->fetchColumn();

    echo json_encode([
        'success'          => true,
        'unread_remaining' => $remaining,
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('[SalesDesk notifications/mark-read] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update notifications. Please try again.']);
}
