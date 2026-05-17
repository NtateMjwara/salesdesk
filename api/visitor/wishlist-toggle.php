<?php
/**
 * SalesDesk — Visitor Wishlist Toggle API
 * POST /api/visitor/wishlist-toggle.php
 *
 * Adds or removes a car from the anonymous visitor's wishlist.
 * No authentication required — uses the sd_vid cookie.
 *
 * Request (form POST or JSON):
 *   car_id   int   required
 *
 * Response JSON:
 *   { wishlisted: bool, car_id: int }
 *
 * Rate limited: 60 requests per minute per IP (standard API limit).
 * CSRF: accepts X-Requested-With: XMLHttpRequest as a CORS double-submit
 * guard (sufficient for a same-origin cookie-based anonymous endpoint).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/visitor.php';
require_once '../../includes/functions.php';
require_once '../../includes/response.php';

applyCachePolicy('api');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Method guard ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// ── CSRF-equivalent: require XHR header ──────────────────────
// Same-origin XHR requests always send X-Requested-With.
// This prevents simple cross-origin form submissions from triggering
// wishlist changes. Not a substitute for CSRF tokens on account actions.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request origin.']);
    exit;
}

// ── Rate limit ────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'wishlist_toggle')) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests.']);
    exit;
}

// ── Parse input ───────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $carId = (int) ($body['car_id'] ?? 0);
} else {
    $carId = (int) ($_POST['car_id'] ?? 0);
}

if ($carId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'car_id is required.']);
    exit;
}

// ── Verify car exists and is active ──────────────────────────
try {
    $pdo     = Database::getInstance();
    $carStmt = $pdo->prepare("SELECT id FROM cars WHERE id = ? AND status = 'active' LIMIT 1");
    $carStmt->execute([$carId]);
    if (!$carStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Car not found or unavailable.']);
        exit;
    }

    // ── Visitor session ───────────────────────────────────────
    $visitor      = initVisitorSession();
    $trackingCode = getActiveTrackingCode($visitor);

    // ── Toggle ────────────────────────────────────────────────
    $isNowWishlisted = toggleWishlist(
        $visitor['id'],
        $carId,
        $trackingCode
    );

    echo json_encode([
        'wishlisted' => $isNowWishlisted,
        'car_id'     => $carId,
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('[SalesDesk wishlist-toggle] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update wishlist. Please try again.']);
}
