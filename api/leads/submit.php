<?php
/**
 * SalesDesk — Lead Submission API
 * POST /api/leads/submit.php
 * T4 owns this file.
 *
 * Attribution engine — the trust foundation of the entire platform.
 * All attribution fields are written in a single atomic transaction.
 * Once written, they are never changed (attribution_locked = 1 always).
 *
 * Request: application/x-www-form-urlencoded or application/json
 *   tracking_code   string   required  — from ?ref= URL param
 *   buyer_name      string   required
 *   buyer_phone     string   required
 *   buyer_email     string   optional
 *   buyer_intent    string   required  within_30d|one_to_3mo|browsing
 *   buyer_message   string   optional
 *   consent_given   1        required  — POPIA consent
 *
 * Response JSON:
 *   { success: true, lead_id: int }           — new lead created
 *   { duplicate: true, dealer_name: string }  — duplicate detected
 *   { error: string, field?: string }         — validation error
 *
 * Security: rate limited, no auth required (public endpoint),
 *   CSRF via X-CSRF-Token header or hidden field.
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
if (!checkApiRateLimit($ip, 'leads_submit', 30)) { // 30/min — stricter for lead forms
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
// Public page — session may not exist. Start it gracefully.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$submittedToken = $_POST[CSRF_TOKEN_NAME]
    ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$storedToken    = $_SESSION[CSRF_TOKEN_NAME] ?? '';

// For public pages with no session, we allow the request through
// with rate limiting as the primary guard. If session exists, validate.
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
$buyerName    = trim($post['buyer_name']    ?? '');
$buyerPhone   = trim($post['buyer_phone']   ?? '');
$buyerEmail   = trim($post['buyer_email']   ?? '');
$buyerIntent  = $post['buyer_intent'] ?? 'browsing';
$buyerMessage = trim($post['buyer_message'] ?? '');
$consentGiven = !empty($post['consent_given']) ? 1 : 0;

// ── Validation ─────────────────────────────────────────────────
$errors = [];

if (!$trackingCode || !preg_match('/^[a-f0-9]{32}$/i', $trackingCode)) {
    $errors[] = ['error' => 'Invalid tracking link.'];
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

    // ── STEP 1: Resolve tracking code → attribution data ───────────
    // att1: resolve tracking code atomically — inside the transaction.
    $pdo->beginTransaction();

    $trackStmt = $pdo->prepare("
        SELECT
            bi.id           AS broker_inventory_id,
            bi.salesdesk_id,
            bi.car_id,
            sd.user_id      AS broker_id,
            sd.id           AS salesdesk_id_confirm,
            c.dealer_id,
            c.status        AS car_status,
            c.make,
            c.model,
            c.year,
            d.company_name  AS dealer_name,
            d.id            AS dealer_id_confirm,
            -- org membership: if broker is in an org context, capture org_id
            (SELECT om.organization_id
             FROM organization_members om
             WHERE om.user_id = sd.user_id
             LIMIT 1)       AS organization_id
        FROM broker_inventory bi
        JOIN salesdesks sd  ON sd.id  = bi.salesdesk_id
        JOIN cars c         ON c.id   = bi.car_id
        JOIN dealers d      ON d.id   = c.dealer_id
        WHERE bi.tracking_code = ?
        LIMIT 1
        FOR UPDATE
    ");
    $trackStmt->execute([$trackingCode]);
    $track = $trackStmt->fetch();

    if (!$track) {
        $pdo->rollBack();
        // att1: tracking code not found → 404 response
        http_response_code(200); // Return 200 so the page can show a friendly message
        echo json_encode(['not_found' => true]);
        exit;
    }

    // att1: car sold or paused → show stale state
    if ($track['car_status'] !== 'active') {
        $pdo->rollBack();
        echo json_encode([
            'stale'       => true,
            'car_status'  => $track['car_status'],
            'dealer_name' => $track['dealer_name'],
        ]);
        exit;
    }

    // ── STEP 2: Duplicate detection ─────────────────────────────────
    // att2: check for duplicate lead within dedup window
    $dedupDays = getPlatformConfigInt('lead_duplicate_window_days', 30);
    $dedupStmt = $pdo->prepare("
        SELECT id FROM leads
        WHERE buyer_phone = ?
          AND car_id      = ?
          AND created_at  > DATE_SUB(NOW(), INTERVAL ? DAY)
        LIMIT 1
    ");
    $dedupStmt->execute([
        preg_replace('/\D/', '', $buyerPhone), // normalise for comparison
        $track['car_id'],
        $dedupDays,
    ]);
    $existingLead = $dedupStmt->fetch();

    if ($existingLead) {
        $pdo->rollBack();
        // att3: duplicate — buyer sees friendly message, first-touch broker keeps attribution
        echo json_encode([
            'duplicate'   => true,
            'dealer_name' => $track['dealer_name'],
        ]);
        exit;
    }

    // ── STEP 3: Atomic INSERT with all attribution fields ────────────
    // att2: single INSERT — never split across two queries.
    $uuid = generateUuidV4();
    $insertStmt = $pdo->prepare("
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
    ");

    $insertStmt->execute([
        $uuid,
        $track['broker_id'],
        $track['salesdesk_id'],
        $track['organization_id'] ?? null,
        $track['car_id'],
        $track['dealer_id'],
        $trackingCode,
        $buyerName,
        // Normalise phone for storage and dedup — store digits only
        preg_replace('/\D/', '', $buyerPhone),
        $buyerEmail ?: null,
        $buyerIntent,
        $buyerMessage ?: null,
        $consentGiven,
    ]);

    $leadId = (int) $pdo->lastInsertId();

    // ── STEP 4: Increment view tracking for analytics ───────────────
    $pdo->prepare("
        UPDATE broker_inventory
        SET views = views + 1
        WHERE tracking_code = ?
    ")->execute([$trackingCode]);

    // ── STEP 5: Write audit log ─────────────────────────────────────
    $pdo->prepare("
        INSERT INTO audit_logs
            (actor_id, action, entity_type, entity_id, after_data, ip_address, created_at)
        VALUES (NULL, 'lead.created', 'lead', ?, ?, ?, NOW())
    ")->execute([
        $leadId,
        json_encode([
            'broker_id'  => $track['broker_id'],
            'car_id'     => $track['car_id'],
            'dealer_id'  => $track['dealer_id'],
            'intent'     => $buyerIntent,
            'has_email'  => !empty($buyerEmail),
        ]),
        $ip,
    ]);

    $pdo->commit();

    // ── STEP 6: Post-commit side effects (fire-and-forget) ──────────

    // Notify broker (in-app)
    $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'new_lead', ?, ?, ?, NOW())
    ")->execute([
        $track['broker_id'],
        'New lead: ' . $track['year'] . ' ' . $track['make'] . ' ' . $track['model'],
        $buyerName . ' is interested via your tracking link.',
        json_encode(['lead_id' => $leadId, 'car_id' => $track['car_id']]),
    ]);

    // Notify dealer (in-app)
    $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'new_lead', ?, ?, ?, NOW())
    ")->execute([
        // dealer's user_id — look it up
        (function() use ($pdo, $track): int {
            $s = $pdo->prepare("SELECT user_id FROM dealers WHERE id = ?");
            $s->execute([$track['dealer_id']]);
            return (int)($s->fetchColumn() ?: 0);
        })(),
        'New lead on ' . $track['year'] . ' ' . $track['make'] . ' ' . $track['model'],
        $buyerName . ' submitted an enquiry.',
        json_encode(['lead_id' => $leadId, 'car_id' => $track['car_id']]),
    ]);

    // D-03: sendBuyerConfirmation() — wrapper per architecture spec
    // Build lead array with joined car/dealer info for the mailer
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
    error_log('[SalesDesk leads/submit] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong. Please try again.']);
}
