<?php
/**
 * SalesDesk — Cars Search API
 * GET /api/cars/search.php
 * T4 owns this file.
 *
 * Used by the broker marketplace and the hero search widget to browse
 * active dealer inventory. Rate-limited per architecture rules.
 *
 * Query params:
 *   q            string   optional  — search by make/model
 *   make         string   optional  — filter by make
 *   province     string   optional  — filter by dealer province
 *   body_type[]  array    optional  — filter by body style (e.g. SUV/4x4, Sedan)
 *   fuel_type[]  array    optional  — filter by fuel type (e.g. Electric, Diesel)
 *   condition    string   optional  — new|demo|used
 *   price_min    int      optional  — minimum price (R)
 *   price_max    int      optional  — maximum price (R)
 *   year_min     int      optional  — minimum year
 *   year_max     int      optional  — maximum year
 *   comm_type    string   optional  fixed|percentage
 *   sort         string   optional  commission_desc (default)|newest|fewest_brokers|
 *                                   price_asc|price_desc
 *   exclude_desk int      optional  — salesdesk_id to exclude already-added cars
 *   on_desk_only bool     optional  — 1 = only count/return cars that have been
 *                                     added to at least one broker's desk.
 *                                     See BUG-SEARCH-01 below for why this is
 *                                     opt-in rather than the default.
 *   page         int      optional  default 1
 *   per_page     int      optional  max 24
 *
 * Response JSON:
 *   { cars: [...], total: int, page: int, per_page: int, pages: int }
 *
 * CHANGES FROM PREVIOUS VERSION:
 *   - Added body_type[] filter (array param, IN clause)
 *   - Added fuel_type[] filter (array param, IN clause)
 *   - Added condition filter (maps to c.condition_type)
 *   - Added price_min / price_max filters
 *   - Added year_min / year_max filters
 *   These additions are required by hero-search-widget.php v3 which sends
 *   these params to the live count endpoint (/api/cars/search.php?per_page=1).
 *
 *   - BUG-SEARCH-01 (this pass): hero-search-widget.php's live "Browse N
 *     cars" count was calling this endpoint with no desk-attribution
 *     filter at all, so it counted every active car from every active
 *     dealer — including cars that haven't been added to any broker's
 *     desk yet. The actual destination page, /c/, only ever shows cars
 *     that ARE on at least one desk (its `first_desk` subquery requires
 *     `first_desk.car_id IS NOT NULL`). So the hero widget's live count
 *     was always >= what /c/ would actually display once the user
 *     clicked through — the exact bug reported.
 *
 *     The fix is NOT a blanket change to this endpoint's default
 *     behavior: this file's own docblock states it's shared by the
 *     broker marketplace, which deliberately needs to see active cars
 *     that AREN'T on any desk yet (that's how a broker discovers new
 *     cars to add to their own desk in the first place). Filtering those
 *     out unconditionally would break that use case.
 *
 *     Instead, added an opt-in `on_desk_only=1` parameter. When present,
 *     both the count and fetch queries require the car to exist in
 *     broker_inventory. When absent (the default, unchanged from
 *     before), behavior is identical to what every other current
 *     consumer of this endpoint already relies on. hero-search-widget.php
 *     has been updated to send `on_desk_only=1` on its live-count calls.
 *
 *     The condition is added to the shared $where array both the count
 *     and fetch queries build from — not to one query and not the other
 *     — specifically to avoid re-introducing the class of bug already
 *     documented and fixed once in c/index.php (FIX-B there: count and
 *     fetch queries drifting out of sync on WHERE/JOIN structure).
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/response.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/session.php';

applyCachePolicy('api');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Rate limit ─────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'cars_search')) {
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

// ── Parse params ───────────────────────────────────────────────
$q           = trim($_GET['q']           ?? '');
$make        = trim($_GET['make']        ?? '');
$province    = trim($_GET['province']    ?? '');
$commType    = trim($_GET['comm_type']   ?? '');
$sort        = trim($_GET['sort']        ?? 'commission_desc');
$excludeDesk = (int) ($_GET['exclude_desk'] ?? 0);
$onDeskOnly  = !empty($_GET['on_desk_only']); // BUG-SEARCH-01 fix
$page        = max(1, (int) ($_GET['page']     ?? 1));
$perPage     = min(24, max(1, (int) ($_GET['per_page'] ?? 12)));
$offset      = ($page - 1) * $perPage;

// New params added for hero-search-widget v3
$bodyTypes = [];
if (!empty($_GET['body_type'])) {
    $bodyTypes = array_values(array_filter(array_map('trim', (array) $_GET['body_type'])));
}

$fuelTypes = [];
if (!empty($_GET['fuel_type'])) {
    $fuelTypes = array_values(array_filter(array_map('trim', (array) $_GET['fuel_type'])));
}

$condition = '';
if (!empty($_GET['condition']) && in_array($_GET['condition'], ['new', 'demo', 'used'], true)) {
    $condition = $_GET['condition'];
}

$priceMin = isset($_GET['price_min']) && is_numeric($_GET['price_min'])
    ? (float) $_GET['price_min'] : null;
$priceMax = isset($_GET['price_max']) && is_numeric($_GET['price_max'])
    ? (float) $_GET['price_max'] : null;

$yearMin = isset($_GET['year_min']) && ctype_digit((string) $_GET['year_min'])
    ? (int) $_GET['year_min'] : null;
$yearMax = isset($_GET['year_max']) && ctype_digit((string) $_GET['year_max'])
    ? (int) $_GET['year_max'] : null;

// Current broker's salesdesk_id (if logged in as broker) — for on_desk flag
$currentSalesdeskId = 0;
if (!empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'broker') {
    $uid = (int) $_SESSION['user_id'];
    try {
        $pdo = Database::getInstance();
        $s   = $pdo->prepare("SELECT id FROM salesdesks WHERE user_id = ? LIMIT 1");
        $s->execute([$uid]);
        $currentSalesdeskId = (int) ($s->fetchColumn() ?: 0);
    } catch (Throwable) {}
}
if ($excludeDesk) {
    $currentSalesdeskId = $excludeDesk;
}

try {
    $pdo    = Database::getInstance();
    $where  = ["c.status = 'active'", "d.is_active = 1"];
    $params = [];

    // ── Text search ────────────────────────────────────────────
    if ($q) {
        $where[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year, ' ', c.make, ' ', c.model) LIKE ?)";
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    // ── Make ───────────────────────────────────────────────────
    if ($make) {
        $where[]  = "c.make = ?";
        $params[] = $make;
    }

    // ── Province ───────────────────────────────────────────────
    if ($province) {
        $where[]  = "a.province = ?";
        $params[] = $province;
    }

    // ── Body type (array) ──────────────────────────────────────
    if (!empty($bodyTypes)) {
        $placeholders = implode(',', array_fill(0, count($bodyTypes), '?'));
        $where[]      = "c.body_type IN ($placeholders)";
        $params       = array_merge($params, $bodyTypes);
    }

    // ── Fuel type (array) ──────────────────────────────────────
    if (!empty($fuelTypes)) {
        $placeholders = implode(',', array_fill(0, count($fuelTypes), '?'));
        $where[]      = "c.fuel_type IN ($placeholders)";
        $params       = array_merge($params, $fuelTypes);
    }

    // ── Condition ──────────────────────────────────────────────
    if ($condition) {
        $where[]  = "c.condition_type = ?";
        $params[] = $condition;
    }

    // ── Price range ────────────────────────────────────────────
    if ($priceMin !== null) {
        $where[]  = "c.price >= ?";
        $params[] = $priceMin;
    }
    if ($priceMax !== null) {
        $where[]  = "c.price <= ?";
        $params[] = $priceMax;
    }

    // ── Year range ─────────────────────────────────────────────
    if ($yearMin !== null) {
        $where[]  = "c.year >= ?";
        $params[] = $yearMin;
    }
    if ($yearMax !== null) {
        $where[]  = "c.year <= ?";
        $params[] = $yearMax;
    }

    // ── Commission type ────────────────────────────────────────
    if ($commType && in_array($commType, ['fixed', 'percentage'], true)) {
        $where[]  = "c.commission_type = ?";
        $params[] = $commType;
    }

    // ── BUG-SEARCH-01 fix: opt-in desk-attribution requirement ──
    // Added to $where (shared by both count and fetch queries below) so
    // the two can never drift out of sync on this condition — same
    // discipline as c/index.php's FIX-B.
    if ($onDeskOnly) {
        $where[] = "EXISTS (SELECT 1 FROM broker_inventory bi_chk WHERE bi_chk.car_id = c.id)";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // ── Sort ───────────────────────────────────────────────────
    $sortClause = match($sort) {
        'newest'          => 'c.created_at DESC',
        'fewest_brokers'  => 'broker_count ASC, c.created_at DESC',
        'price_asc'       => 'c.price ASC',
        'price_desc'      => 'c.price DESC',
        default           => 'commission_value_rand DESC, c.created_at DESC',
    };

    // Commission value in Rands for default sort
    $commSortExpr = "
        CASE c.commission_type
            WHEN 'fixed'      THEN c.commission_value
            WHEN 'percentage' THEN c.price * (c.commission_value / 100)
            ELSE 0
        END
    ";

    // ── Count for pagination ───────────────────────────────────
    $countParams = $params;
    $countSql    = "
        SELECT COUNT(DISTINCT c.id)
        FROM cars c
        JOIN dealers d    ON d.id = c.dealer_id
        LEFT JOIN addresses a ON a.id = d.address_id
        {$whereClause}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();

    // ── Fetch page ─────────────────────────────────────────────
    $rowParams = array_merge($params, [$currentSalesdeskId ?: 0, $perPage, $offset]);
    $sql = "
        SELECT
            c.id,
            c.uuid,
            c.slug,
            c.make,
            c.model,
            c.year,
            c.price,
            c.mileage,
            c.condition_type,
            c.body_type,
            c.colour,
            c.transmission,
            c.fuel_type,
            c.commission_type,
            c.commission_value,
            ({$commSortExpr}) AS commission_value_rand,
            c.image_urls,
            c.created_at,
            d.id            AS dealer_id,
            d.company_name  AS dealer_name,
            d.slug          AS dealer_slug,
            d.verification_status AS dealer_verification,
            a.city          AS dealer_city,
            a.province      AS dealer_province,
            (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.car_id = c.id) AS broker_count,
            CASE WHEN EXISTS (
                SELECT 1 FROM broker_inventory bi2
                WHERE bi2.car_id = c.id AND bi2.salesdesk_id = ?
            ) THEN 1 ELSE 0 END AS on_desk
        FROM cars c
        JOIN dealers d    ON d.id = c.dealer_id
        LEFT JOIN addresses a ON a.id = d.address_id
        {$whereClause}
        ORDER BY {$sortClause}
        LIMIT ? OFFSET ?
    ";

    $rowStmt = $pdo->prepare($sql);
    $rowStmt->execute($rowParams);
    $rows = $rowStmt->fetchAll();

    // ── Format output ──────────────────────────────────────────
    $cars = array_map(function (array $row): array {
        $commDisplay = $row['commission_type'] === 'fixed'
            ? 'R ' . number_format((float) $row['commission_value'], 0, '.', ',')
            : number_format((float) $row['commission_value'], 1) . '%';

        $commRand = $row['commission_type'] === 'fixed'
            ? (float) $row['commission_value']
            : round((float)$row['price'] * ((float)$row['commission_value'] / 100), 2);

        return [
            'id'                    => (int)   $row['id'],
            'uuid'                  =>          $row['uuid'],
            'slug'                  =>          $row['slug'],
            'make'                  =>          $row['make'],
            'model'                 =>          $row['model'],
            'year'                  => (int)   $row['year'],
            'price'                 => (float) $row['price'],
            'price_display'         => 'R ' . number_format((float)$row['price'], 0, '.', ','),
            'mileage'               => $row['mileage'] ? (int) $row['mileage'] : null,
            'condition_type'        =>          $row['condition_type'],
            'body_type'             =>          $row['body_type'],
            'colour'                =>          $row['colour'],
            'transmission'          =>          $row['transmission'],
            'fuel_type'             =>          $row['fuel_type'],
            'commission_type'       =>          $row['commission_type'],
            'commission_value'      => (float) $row['commission_value'],
            'commission_value_rand' =>          $commRand,
            'commission_display'    =>          $commDisplay,
            'image_urls'            => json_decode($row['image_urls'] ?? '[]', true) ?: [],
            'dealer_id'             => (int)   $row['dealer_id'],
            'dealer_name'           =>          $row['dealer_name'],
            'dealer_slug'           =>          $row['dealer_slug'],
            'dealer_verified'       =>          $row['dealer_verification'] === 'verified',
            'dealer_city'           =>          $row['dealer_city'],
            'dealer_province'       =>          $row['dealer_province'],
            'broker_count'          => (int)   $row['broker_count'],
            'on_desk'               =>          (bool)((int)$row['on_desk']),
        ];
    }, $rows);

    echo json_encode([
        'cars'     => $cars,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    error_log('[SalesDesk cars/search] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search temporarily unavailable.']);
}
