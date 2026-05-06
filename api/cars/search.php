<?php
/**
 * SalesDesk — Cars Search API
 * GET /api/cars/search.php
 * T4 owns this file.
 *
 * Used by the broker marketplace to browse active dealer inventory.
 * Rate-limited per architecture rules.
 *
 * Query params:
 *   q         string   optional  — search by make/model
 *   make      string   optional  — filter by make
 *   province  string   optional  — filter by dealer province
 *   comm_type string   optional  fixed|percentage
 *   sort      string   optional  commission_desc (default)|newest|fewest_brokers
 *   exclude_desk int   optional  — salesdesk_id to exclude already-added cars
 *   page      int      optional  default 1
 *   per_page  int      optional  max 24
 *
 * Response JSON:
 *   { cars: [...], total: int, page: int, per_page: int }
 *
 * Car shape:
 *   id, uuid, slug, make, model, year, price, mileage,
 *   condition_type, body_type, colour, transmission, fuel_type,
 *   commission_type, commission_value, commission_display,
 *   image_urls, dealer_id, dealer_name, dealer_slug,
 *   dealer_verification, dealer_city, dealer_province,
 *   broker_count, on_desk
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
$q           = trim($_GET['q']       ?? '');
$make        = trim($_GET['make']    ?? '');
$province    = trim($_GET['province']   ?? '');
$commType    = trim($_GET['comm_type']  ?? '');
$sort        = trim($_GET['sort']       ?? 'commission_desc');
$excludeDesk = (int) ($_GET['exclude_desk'] ?? 0);
$page        = max(1, (int) ($_GET['page']     ?? 1));
$perPage     = min(24, max(1, (int) ($_GET['per_page'] ?? 12)));
$offset      = ($page - 1) * $perPage;

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

    if ($q) {
        $where[]  = "(c.make LIKE ? OR c.model LIKE ? OR CONCAT(c.year, ' ', c.make, ' ', c.model) LIKE ?)";
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($make) {
        $where[]  = "c.make = ?";
        $params[] = $make;
    }
    if ($province) {
        $where[]  = "a.province = ?";
        $params[] = $province;
    }
    if ($commType && in_array($commType, ['fixed', 'percentage'])) {
        $where[]  = "c.commission_type = ?";
        $params[] = $commType;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $sortClause = match($sort) {
        'newest'          => 'c.created_at DESC',
        'fewest_brokers'  => 'broker_count ASC, c.created_at DESC',
        'price_asc'       => 'c.price ASC',
        'price_desc'      => 'c.price DESC',
        default           => 'commission_value_rand DESC, c.created_at DESC',
    };

    // Build the commission value in Rands for sorting.
    // For percentage: commission_value_rand = price * (commission_value / 100)
    // For fixed: commission_value_rand = commission_value
    $commSortExpr = "
        CASE c.commission_type
            WHEN 'fixed'      THEN c.commission_value
            WHEN 'percentage' THEN c.price * (c.commission_value / 100)
            ELSE 0
        END
    ";

    // Count total for pagination.
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

    // Fetch page.
    $rowParams   = array_merge($params, [$currentSalesdeskId ?: 0, $perPage, $offset]);
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
            -- Count brokers who have this car on their desk
            (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.car_id = c.id) AS broker_count,
            -- Is this car already on the current broker's desk?
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

    // Format for output.
    $cars = array_map(function (array $row): array {
        // Compute commission display string.
        $commDisplay = $row['commission_type'] === 'fixed'
            ? 'R ' . number_format((float) $row['commission_value'], 0, '.', ',')
            : number_format((float) $row['commission_value'], 1) . '%';

        $commRand = $row['commission_type'] === 'fixed'
            ? (float) $row['commission_value']
            : round((float)$row['price'] * ((float)$row['commission_value'] / 100), 2);

        return [
            'id'                   => (int)   $row['id'],
            'uuid'                 =>          $row['uuid'],
            'slug'                 =>          $row['slug'],
            'make'                 =>          $row['make'],
            'model'                =>          $row['model'],
            'year'                 => (int)   $row['year'],
            'price'                => (float) $row['price'],
            'price_display'        => 'R ' . number_format((float)$row['price'], 0, '.', ','),
            'mileage'              => $row['mileage'] ? (int) $row['mileage'] : null,
            'condition_type'       =>          $row['condition_type'],
            'body_type'            =>          $row['body_type'],
            'colour'               =>          $row['colour'],
            'transmission'         =>          $row['transmission'],
            'fuel_type'            =>          $row['fuel_type'],
            'commission_type'      =>          $row['commission_type'],
            'commission_value'     => (float) $row['commission_value'],
            'commission_value_rand'=> $commRand,
            'commission_display'   =>          $commDisplay,
            'image_urls'           => json_decode($row['image_urls'] ?? '[]', true) ?: [],
            'dealer_id'            => (int)   $row['dealer_id'],
            'dealer_name'          =>          $row['dealer_name'],
            'dealer_slug'          =>          $row['dealer_slug'],
            'dealer_verified'      =>          $row['dealer_verification'] === 'verified',
            'dealer_city'          =>          $row['dealer_city'],
            'dealer_province'      =>          $row['dealer_province'],
            'broker_count'         => (int)   $row['broker_count'],
            'on_desk'              =>          (bool)((int)$row['on_desk']),
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
