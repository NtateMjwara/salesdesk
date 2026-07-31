<?php
/**
 * SalesDesk — Search Suggestions API
 * GET /api/cars/suggest.php?q={query}&salesdesk_id={optional}
 * T4 owns this file.
 *
 * Powers the sidebar search typeahead on /cars-for-sale/ and broker
 * storefronts (assets/js/search-typeahead.js). Suggestions are pulled
 * live from the cars table so they can never drift from what's actually
 * in inventory — unlike a hardcoded JS array (see hero-search-widget.php's
 * MAKES/BODY_TYPES/FUEL_TYPES, which needed a bugfix once already for
 * exactly this reason).
 *
 * salesdesk_id, when present, scopes every suggestion to that desk's own
 * inventory (broker storefront use case) — same discipline broker/index.php
 * already applies to its own filter option queries.
 *
 * Response JSON:
 *   { suggestions: [ { label: string, type: 'make'|'model'|'body_type' }, ... ] }
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/response.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

applyCachePolicy('api');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Rate limit ─────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'cars_suggest', 90)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests.']);
    exit;
}

// ── Method guard ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['suggestions' => []]);
    exit;
}

// Strip characters that have no business in a make/model search —
// same conservative allowlist api/dealers/search.php already uses.
$q = preg_replace('/[^\p{L}\p{N}\s\-\.&\']/u', '', $q);
if ($q === '') {
    echo json_encode(['suggestions' => []]);
    exit;
}

// Optional: scope suggestions to one broker's desk inventory only.
$deskId = (int) ($_GET['salesdesk_id'] ?? 0);

try {
    $pdo  = Database::getInstance();
    $like = '%' . $q . '%';

    // When scoped to a desk, every query below joins broker_inventory
    // and filters to that salesdesk_id — mirrors broker/index.php's
    // own $makesStmt / $bodyTypesStmt pattern exactly, so a desk-scoped
    // suggestion box can never surface a car outside that broker's
    // own inventory.
    $deskJoin  = $deskId ? 'JOIN broker_inventory bi ON bi.car_id = c.id AND bi.salesdesk_id = ?' : '';
    $deskParam = $deskId ? [$deskId] : [];

    // ── Makes ────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.make AS label
        FROM cars c {$deskJoin}
        WHERE c.status = 'active' AND c.make LIKE ?
        ORDER BY c.make
        LIMIT 5
    ");
    $stmt->execute(array_merge($deskParam, [$like]));
    $makes = array_map(fn($r) => ['label' => $r['label'], 'type' => 'make'], $stmt->fetchAll());

    // ── Make + Model combos ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DISTINCT CONCAT(c.make, ' ', c.model) AS label
        FROM cars c {$deskJoin}
        WHERE c.status = 'active' AND CONCAT(c.make, ' ', c.model) LIKE ?
        ORDER BY label
        LIMIT 6
    ");
    $stmt->execute(array_merge($deskParam, [$like]));
    $models = array_map(fn($r) => ['label' => $r['label'], 'type' => 'model'], $stmt->fetchAll());

    // ── Body types ────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.body_type AS label
        FROM cars c {$deskJoin}
        WHERE c.status = 'active' AND c.body_type IS NOT NULL AND c.body_type LIKE ?
        ORDER BY c.body_type
        LIMIT 4
    ");
    $stmt->execute(array_merge($deskParam, [$like]));
    $bodyTypes = array_map(fn($r) => ['label' => $r['label'], 'type' => 'body_type'], $stmt->fetchAll());

    // De-dupe (a make/model string could in principle collide with a
    // body_type string) while preserving priority order: makes → models
    // → body types.
    $seen = [];
    $suggestions = [];
    foreach (array_merge($makes, $models, $bodyTypes) as $s) {
        $key = $s['type'] . '|' . mb_strtolower($s['label']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $suggestions[] = $s;
    }

    echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('[SalesDesk cars/suggest] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['suggestions' => []]);
}
