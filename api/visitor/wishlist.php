<?php
/**
 * SalesDesk — Visitor Wishlist State API  (WISH-1, Nov 2026)
 * GET /api/visitor/wishlist.php
 *
 * Returns the current visitor's saved car IDs so public.js can paint
 * every heart and the nav badge correctly.
 *
 * WHY: public pages are sent with Cache-Control: public, max-age=300
 * (applyCachePolicy('public')). The hearts and the nav count that PHP
 * renders into that HTML can therefore be up to 5 minutes stale — save
 * a car on the browse page, open its detail page (or press Back) and
 * the heart showed as not saved, the badge showed the old number.
 * This endpoint is never cached, so the page corrects itself on load.
 *
 * Response JSON (200):
 *   { ids: int[], count: int }
 *
 * A visitor with no sd_vid cookie yet gets { ids: [], count: 0 } without
 * a DB write (no session row is created just to say "nothing saved").
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/database.php';
require_once '../../includes/visitor.php';
require_once '../../includes/functions.php';

applyCachePolicy('api');
header('Cache-Control: no-store, private');
header('Vary: Cookie');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// Same-origin XHR only (same guard as wishlist-toggle.php).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request origin.']);
    exit;
}

$token = $_COOKIE[VISITOR_COOKIE_NAME] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    echo json_encode(['ids' => [], 'count' => 0]);
    exit;
}

try {
    // Read-only lookup: don't refresh last_seen / re-set the cookie here,
    // the page request that triggered this call already did that.
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id FROM visitor_sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $visitorId = (int) ($stmt->fetchColumn() ?: 0);

    $ids = $visitorId > 0 ? array_map('intval', getWishlistCarIds($visitorId)) : [];

    echo json_encode(['ids' => $ids, 'count' => count($ids)], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[SalesDesk wishlist-state] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unavailable.']);
}
