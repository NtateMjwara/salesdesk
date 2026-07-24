<?php
/**
 * SalesDesk — Dealer search API.
 * GET /api/dealers/search.php?q={query}
 *
 * Used by:
 *   - Sales exec signup wizard (dealership selection step)
 *   - T4 broker marketplace (future dealer filter)
 *
 * Returns JSON array of matching active dealers.
 * No auth required — dealer company names are not sensitive.
 *
 * Rate-limited: max 60 requests per minute per IP (BUG-05 fixed).
 *
 * Response shape (array of objects):
 *   id                   int
 *   company_name         string
 *   city                 string|null
 *   province             string|null
 *   verification_status  string    'unverified'|'pending'|'verified'
 *   slug                 string
 *
 * CONTRACT: This response shape is frozen.
 * Adding fields is OK. Removing or renaming is a breaking change.
 * Notify all team leads before modifying.
 */

require_once '../../includes/security.php';
require_once '../../includes/response.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

// Cache policy — API: no-store.
applyCachePolicy('api');

// Headers.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Only GET allowed.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Rate limiting (BUG-05) ────────────────────────────────────────────────
// Every /api/ file calls this before any other logic.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'dealers_search')) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Too many requests. Please wait before searching again.']);
    exit;
}

// ── Query validation ──────────────────────────────────────────────────────
$query = trim($_GET['q'] ?? '');

// Require at least 2 characters.
if (mb_strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Strip unsafe search characters — allow letters, numbers, spaces,
// hyphens, periods, ampersands, apostrophes (e.g. "O'Brien Motors").
$query = preg_replace('/[^\p{L}\p{N}\s\-\.&\']/u', '', $query);

if ($query === '') {
    echo json_encode([]);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Search active dealers by company name (partial match).
    // JOIN addresses to surface city for display disambiguation.
    // Verified dealers ranked first, then alphabetical.
    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.company_name,
            d.slug,
            d.verification_status,
            a.city,
            a.province
        FROM dealers d
        LEFT JOIN addresses a ON a.id = d.address_id
        WHERE d.is_active = 1
          AND d.company_name LIKE ?
        ORDER BY
            CASE d.verification_status WHEN 'verified' THEN 0 ELSE 1 END ASC,
            d.company_name ASC
        LIMIT 10
    ");

    $stmt->execute(['%' . $query . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast id to int, normalise output — only defined keys.
    $output = array_map(static function (array $row): array {
        return [
            'id'                  => (int) $row['id'],
            'company_name'        => $row['company_name'],
            'slug'                => $row['slug'],
            'verification_status' => $row['verification_status'],
            'city'                => $row['city'],
            'province'            => $row['province'],
        ];
    }, $results);

    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('[SalesDesk dealers/search] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search temporarily unavailable. Please try again.']);
}
