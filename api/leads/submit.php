<?php
/**
 * SalesDesk — Lead Submission API  (v2)
 * POST /api/leads/submit.php
 * T4 owns this file.
 *
 * Attribution model v2:
 *   The desk slug is now embedded in every car detail URL, so every
 *   submitted lead has a known desk even without a ?ref= tracking code.
 *
 *   Resolution order:
 *     1. tracking_code present + valid for this car → use it (honours
 *        external share links; the sharing broker may differ from the
 *        URL-path desk)
 *     2. tracking_code absent/invalid + desk_slug posted → look up the
 *        desk's own broker_inventory row for this car and use that
 *        tracking code (desk-in-URL attribution)
 *     3. Neither resolves → reject with not_found
 *
 * Request: application/x-www-form-urlencoded or application/json
 *   tracking_code   string   required (may be empty if desk_slug provided)
 *   desk_slug       string   required — always posted by the detail page form
 *   car_slug        string   required — used for fallback resolution
 *   buyer_name      string   required
 *   buyer_phone     string   required
 *   buyer_email     string   optional
 *   buyer_intent    string   required  within_30d|one_to_3mo|browsing
 *   buyer_message   string   optional
 *   consent_given   1        required
 *
 * Response JSON:
 *   { success: true, lead_id: int }
 *   { duplicate: true, dealer_name: string }
 *   { not_found: true }
 *   { stale: true, car_status: string }
 *   { error: string, field?: string }
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';
require_once '../../includes/response.php';

applyCachePolicy('api');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Rate limit ─────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkApiRateLimit($ip, 'leads_submit', 30)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many submissions. Please wait before trying again.']);
    exit;
}

// ── Method guard ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

// ── CSRF validation ────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$submittedToken = $_POST[CSRF_TOKEN_NAME]
    ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$storedToken    = $_SESSION[CSRF_TOKEN_NAME] ?? '';

if ($storedToken !== '' && ($submittedToken === '' || !hash_equals($storedToken, $submittedToken))) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request. Please reload the page and try again.']);
    exit;
}

// ── Parse input ────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $post = $body;
} else {
    $post = $_POST;
}

$trackingCode = trim($post['tracking_code'] ?? '');
$deskSlug     = trim($post['desk_slug']     ?? '');
$carSlugPost  = trim($post['car_slug']      ?? '');
$buyerName    = trim($post['buyer_name']    ?? '');
$buyerPhone   = trim($post['buyer_phone']   ?? '');
$buyerEmail   = trim($post['buyer_email']   ?? '');
$buyerIntent  = $post['buyer_intent'] ?? 'browsing';
$buyerMessage = trim($post['buyer_message'] ?? '');
$consentGiven = !empty($post['consent_given']) ? 1 : 0;

// Sanitise slug inputs
$deskSlug    = preg_replace('/[^a-z0-9\-]/', '', strtolower($deskSlug));
$carSlugPost = preg_replace('/[^a-z0-9\-]/', '', strtolower($carSlugPost));

// Validate tracking code format if provided
if ($trackingCode && !preg_match('/^[a-f0-9]{32}$/i', $trackingCode)) {
    $trackingCode = '';
}

// ── Validation ─────────────────────────────────────────────────
$errors = [];

// Must have either a valid tracking code OR a desk+car slug pair
if (!$trackingCode && (!$deskSlug || !$carSlugPost)) {
    $errors[] = ['error' => 'Invalid listing reference. Please reload the page.'];
}
if (!$buyerName) {
    $errors[] = ['error' => 'Please enter your name.', 'field' => 'buyer_name'];
}
if (!$buyerPhone || !preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $buyerPhone)) {
    $errors[] = ['error' => 'Please enter a valid phone number.', 'field' => 'buyer_phone'];
}
if ($buyerEmail && !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = ['error' => 'Please enter a valid email address.', 'field' => 'buyer_email'];
}
if (!in_array($buyerIntent, ['within_30d', 'one_to_3mo', 'browsing'], true)) {
    $buyerIntent = 'browsing';
}
if (!$consentGiven) {
    $errors[] = ['error' => 'You must consent to your details being shared with the dealer.', 'field' => 'consent'];
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode($errors[0]);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    // ── STEP 1: Resolve attribution ────────────────────────────
    // Try tracking_code first (ref= attribution — sharing broker gets credit).
    // Fall back to desk_slug + car_slug path attribution.

    $track = null;

    if ($trackingCode) {
        $trackStmt = $pdo->prepare("
            SELECT
                bi.id               AS broker_inventory_id,
                bi.salesdesk_id,
                bi.car_id,
                bi.tracking_code,
                sd.user_id          AS broker_id,
                c.dealer_id,
                c.status            AS car_status,
                c.make, c.model, c.year,
                d.company_name      AS dealer_name,
                (SELECT om.organization_id FROM organization_members om
                 WHERE om.user_id = sd.user_id LIMIT 1) AS organization_id
            FROM broker_inventory bi
            JOIN salesdesks sd ON sd.id  = bi.salesdesk_id
            JOIN cars c        ON c.id   = bi.car_id
            JOIN dealers d     ON d.id   = c.dealer_id
            WHERE bi.tracking_code = ?
            LIMIT 1
            FOR UPDATE
        ");
        $trackStmt->execute([$trackingCode]);
        $track = $trackStmt->fetch() ?: null;
    }

    // Fallback: resolve via desk_slug + car_slug (v2 path attribution)
    if (!$track && $deskSlug && $carSlugPost) {
        $fallbackStmt = $pdo->prepare("
            SELECT
                bi.id               AS broker_inventory_id,
                bi.salesdesk_id,
                bi.car_id,
                bi.tracking_code,
                sd.user_id          AS broker_id,
                c.dealer_id,
                c.status            AS car_status,
                c.make, c.model, c.year,
                d.company_name      AS dealer_name,
                (SELECT om.organization_id FROM organization_members om
                 WHERE om.user_id = sd.user_id LIMIT 1) AS organization_id
            FROM salesdesks sd
            JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
            JOIN cars c              ON c.id   = bi.car_id
            JOIN dealers d           ON d.id   = c.dealer_id
            WHERE sd.slug  = ?
              AND c.slug   = ?
              AND sd.is_active = 1
            LIMIT 1
            FOR UPDATE
        ");
        $fallbackStmt->execute([$deskSlug, $carSlugPost]);
        $track = $fallbackStmt->fetch() ?: null;
    }

    if (!$track) {
        $pdo->rollBack();
        echo json_encode(['not_found' => true]);
        exit;
    }

    // ── STEP 2: Car status check ───────────────────────────────
    if ($track['car_status'] !== 'active') {
        $pdo->rollBack();
        echo json_encode([
            'stale'      => true,
            'car_status' => $track['car_status'],
            'dealer_name'=> $track['dealer_name'],
        ]);
        exit;
    }

    // ── STEP 3: Duplicate detection ────────────────────────────
    $dedupDays = getPlatformConfigInt('lead_duplicate_window_days', 30);
    $dedupStmt = $pdo->prepare("
        SELECT id FROM leads
        WHERE buyer_phone = ?
          AND car_id      = ?
          AND created_at  > DATE_SUB(NOW(), INTERVAL ? DAY)
        LIMIT 1
    ");
    $dedupStmt->execute([
        preg_replace('/\D/', '', $buyerPhone),
        $track['car_id'],
        $dedupDays,
    ]);
    if ($dedupStmt->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'duplicate'   => true,
            'dealer_name' => $track['dealer_name'],
        ]);
        exit;
    }

    // ── STEP 4: Atomic INSERT — all attribution fields at once ─
    $uuid = generateUuidV4();
    $pdo->prepare("
        INSERT INTO leads (
            uuid,
            broker_id,
            salesdesk_id,
            organization_id,
            car_id,
            dealer_id,
            source_tracking_code,
            attribution_locked,
            attributed_at,
            buyer_name,
            buyer_phone,
            buyer_email,
            buyer_intent,
            buyer_message,
            consent_given,
            consent_at,
            status,
            status_updated_at,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 1, NOW(),
            ?, ?, ?, ?, ?,
            ?, NOW(),
            'new', NOW(), NOW(), NOW()
        )
    ")->execute([
        $uuid,
        $track['broker_id'],
        $track['salesdesk_id'],
        $track['organization_id'] ?? null,
        $track['car_id'],
        $track['dealer_id'],
        $track['tracking_code'],  // always store the resolved tracking code
        $buyerName,
        preg_replace('/\D/', '', $buyerPhone),
        $buyerEmail ?: null,
        $buyerIntent,
        $buyerMessage ?: null,
        $consentGiven,
    ]);

    $leadId = (int) $pdo->lastInsertId();

    // ── STEP 5: Increment broker_inventory view counter ────────
    $pdo->prepare("
        UPDATE broker_inventory SET views = views + 1
        WHERE id = ?
    ")->execute([$track['broker_inventory_id']]);

    // ── STEP 6: Audit log ──────────────────────────────────────
    $pdo->prepare("
        INSERT INTO audit_logs
            (actor_id, action, entity_type, entity_id, after_data, ip_address, created_at)
        VALUES (NULL, 'lead.created', 'lead', ?, ?, ?, NOW())
    ")->execute([
        $leadId,
        json_encode([
            'broker_id'      => $track['broker_id'],
            'salesdesk_id'   => $track['salesdesk_id'],
            'car_id'         => $track['car_id'],
            'dealer_id'      => $track['dealer_id'],
            'tracking_code'  => $track['tracking_code'],
            'desk_slug'      => $deskSlug,
            'ref_used'       => !empty($trackingCode),  // was ?ref= tracking used?
            'intent'         => $buyerIntent,
            'has_email'      => !empty($buyerEmail),
        ]),
        $ip,
    ]);

    $pdo->commit();

    // ── STEP 7: Post-commit notifications (fire-and-forget) ────

    // Notify broker (in-app)
    $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'new_lead', ?, ?, ?, NOW())
    ")->execute([
        $track['broker_id'],
        'New lead: ' . $track['year'] . ' ' . $track['make'] . ' ' . $track['model'],
        $buyerName . ' is interested via your SalesDesk.',
        json_encode(['lead_id' => $leadId, 'car_id' => $track['car_id']]),
    ]);

    // Notify dealer (in-app)
    $dealerUserStmt = $pdo->prepare("SELECT user_id FROM dealers WHERE id = ? LIMIT 1");
    $dealerUserStmt->execute([$track['dealer_id']]);
    $dealerUserId = (int) ($dealerUserStmt->fetchColumn() ?: 0);

    if ($dealerUserId) {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, body, meta, created_at)
            VALUES (?, 'new_lead', ?, ?, ?, NOW())
        ")->execute([
            $dealerUserId,
            'New lead on ' . $track['year'] . ' ' . $track['make'] . ' ' . $track['model'],
            $buyerName . ' submitted an enquiry.',
            json_encode(['lead_id' => $leadId, 'car_id' => $track['car_id']]),
        ]);
    }

    // Buyer confirmation email
    $leadForMailer = [
        'buyer_name'  => $buyerName,
        'buyer_email' => $buyerEmail,
        'dealer_name' => $track['dealer_name'],
        'car_make'    => $track['make'],
        'car_model'   => $track['model'],
        'car_year'    => $track['year'],
    ];
    sendBuyerConfirmation($leadForMailer, 'email');

    echo json_encode([
        'success' => true,
        'lead_id' => $leadId,
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[SalesDesk leads/submit v2] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong. Please try again.']);
}
