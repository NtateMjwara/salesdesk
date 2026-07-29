<?php
/**
 * SalesDesk — Slug availability check
 * GET /api/salesdesks/check-slug.php?slug={slug}
 * T4 owns this file.
 *
 * Used by the broker wizard (step 3) for live slug uniqueness checking.
 * Also used by the slug rename flow (D-02).
 *
 * Response JSON:
 *   { available: true }   — slug is free
 *   { available: false }  — slug is taken
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/response.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

applyCachePolicy('api');
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'slug_check', 120)) {
    http_response_code(429);
    echo json_encode(['available' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$slug = trim($_GET['slug'] ?? '');

// Validate slug format.
if (!$slug || !preg_match('/^[a-z0-9][a-z0-9\-]{1,59}$/', $slug)) {
    echo json_encode(['available' => false, 'reason' => 'invalid_format']);
    exit;
}

/**
 * MIGRATION 0012 (v2): 'car' is reserved because
 * /cars-for-sale/car/{car-slug}/ is now a real route (platform-
 * attributed, desk-less car detail pages) — a broker registering
 * slug "car" would collide with every platform car URL.
 *
 * 'platform' is NOT structurally required here (v2 of this migration
 * doesn't create a real salesdesk row for platform attribution — see
 * db/0012_platform_attribution.sql), but is kept reserved anyway since
 * it's SalesDesk's own name for this feature and a broker owning
 * salesdesk.co.za/platform/ would be confusing regardless.
 */
$reserved = [
    'auth', 'app', 'api', 'admin', 'assets', 'uploads', 'public',
    'cars-for-sale', 'broker', 'dealer', 'exec', 'org', 'help', 'about',
    'privacy', 'terms', 'support', 'contact', 'login', 'register',
    'car', 'platform',
];
if (in_array($slug, $reserved, true)) {
    echo json_encode(['available' => false, 'reason' => 'reserved']);
    exit;
}

try {
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("SELECT 1 FROM salesdesks WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $taken = (bool) $stmt->fetchColumn();

    echo json_encode(['available' => !$taken]);
} catch (Throwable $e) {
    error_log('[SalesDesk slug-check] ' . $e->getMessage());
    // Fail open — don't block the wizard if DB is momentarily unavailable.
    echo json_encode(['available' => true]);
}
