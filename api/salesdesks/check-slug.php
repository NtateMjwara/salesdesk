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
 * ROUTE RENAME FIX (this pass): the browse/detail routes moved from
 * /c/ to /cars-for-sale/. 'c' is no longer a reserved top-level route,
 * so a broker could legitimately register that slug now — removed it.
 * 'cars-for-sale' is now the reserved top-level route and must block
 * a broker from registering a storefront slug that would collide
 * with it.
 */
$reserved = [
    'auth', 'app', 'api', 'admin', 'assets', 'uploads', 'public',
    'cars-for-sale', 'broker', 'dealer', 'exec', 'org', 'help', 'about',
    'privacy', 'terms', 'support', 'contact', 'login', 'register',
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
