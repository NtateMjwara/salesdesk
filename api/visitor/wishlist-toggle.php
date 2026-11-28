<?php
/**
 * SalesDesk — Visitor Wishlist Toggle API
 * POST /api/visitor/wishlist-toggle.php
 *
 * Adds or removes a car from the anonymous visitor's wishlist.
 * No authentication required — uses the sd_vid cookie.
 *
 * Request (form POST or JSON):
 *   car_id   int     required
 *   ref      string  optional — the page's ?ref= tracking code (32 hex)
 *
 * Response JSON:
 *   { wishlisted: bool, car_id: int, count: int }
 *   count = how many cars the visitor now has saved (drives the nav badge)
 *
 * v2 (Nov 2026, WISH-1):
 *   – Rate-limit check moved inside the try. If it threw (e.g. a DB
 *     hiccup on api_rate_limits) PHP printed an HTML fatal error, the
 *     browser couldn't parse it and every heart rolled back with
 *     "Couldn't update saved cars".
 *   – Only ADDING requires an active car. A saved car that has since
 *     sold or been withdrawn can now be removed (it used to 404, so it
 *     was stuck on the Saved cars page forever).
 *   – Returns the new total so the nav count is exact, not +1/-1 guesses.
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

// ── Parse input ───────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $carId = (int) ($body['car_id'] ?? 0);
    $ref   = (string) ($body['ref'] ?? '');
} else {
    $carId = (int) ($_POST['car_id'] ?? 0);
    $ref   = (string) ($_POST['ref'] ?? '');
}
// The page's own ?ref= (sent by public.js). A POST to this endpoint has
// no ?ref= of its own, so without this a car saved from a broker's share
// link lost its attribution whenever the visitor hadn't (yet) accepted
// functional cookies (getActiveTrackingCode() only persists with consent).
$pageRef = preg_match('/^[a-f0-9]{32}$/i', $ref) ? $ref : null;

if ($carId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'car_id is required.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // ── Rate limit (never fatal — a limiter failure must not break saving)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $allowed = checkApiRateLimit($ip, 'wishlist_toggle');
    } catch (Throwable $rlErr) {
        error_log('[SalesDesk wishlist-toggle] rate limiter unavailable: ' . $rlErr->getMessage());
        $allowed = true;
    }
    if (!$allowed) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests.']);
        exit;
    }

    // ── Visitor session ───────────────────────────────────────
    $visitor   = initVisitorSession();
    $visitorId = (int) $visitor['id'];

    // ── Adding needs a live car; removing never does ─────────
    if (!isCarWishlisted($visitorId, $carId)) {
        $carStmt = $pdo->prepare("SELECT id FROM cars WHERE id = ? AND status = 'active' LIMIT 1");
        $carStmt->execute([$carId]);
        if (!$carStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Car not found or unavailable.']);
            exit;
        }
    }

    // ── Toggle ────────────────────────────────────────────────
    $trackingCode    = $pageRef ?? getActiveTrackingCode($visitor);
    $isNowWishlisted = toggleWishlist($visitorId, $carId, $trackingCode);

    echo json_encode([
        'wishlisted' => $isNowWishlisted,
        'car_id'     => $carId,
        'count'      => count(getWishlistCarIds($visitorId)),
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('[SalesDesk wishlist-toggle] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update wishlist. Please try again.']);
}
