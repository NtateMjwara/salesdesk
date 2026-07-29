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
 *
 * EMAIL NOTIFICATIONS  (D-03 follow-up):
 *   Previously, only the buyer received an email (sendBuyerConfirmation);
 *   the broker and dealer principal got in-app notifications.php rows only,
 *   and the sales exec (when the car was exec-uploaded) got nothing at all.
 *   The $track query below now also resolves dealer/broker/exec contact
 *   details so STEP 7 can email all three supply-side parties via the new
 *   sendNewLeadEmail() helper in mailer.php. Each recipient gets their own
 *   email (not a literal CC) — see mailer.php for rationale.
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

// MIGRATION 0012: a bare car_slug (no desk_slug) is now valid — it's
// how the platform-attributed detail page (/cars-for-sale/car/{slug}/)
// posts. Must have EITHER a tracking code, OR a car_slug (desk_slug
// optional alongside it).
if (!$trackingCode && !$carSlugPost) {
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
    //
    // Both queries below also resolve dealer principal, broker, and
    // (where applicable) sales exec contact details in one pass, so
    // STEP 7 can email all supply-side parties without extra round trips.

    $track = null;

    if ($trackingCode) {
        $trackStmt = $pdo->prepare("
            SELECT
                bi.id                  AS broker_inventory_id,
                bi.salesdesk_id,
                bi.car_id,
                bi.tracking_code,
                sd.user_id             AS broker_id,
                sd.display_name        AS desk_name,
                c.dealer_id,
                c.status               AS car_status,
                c.make, c.model, c.year,
                c.uploaded_by_exec_id,
                d.company_name         AS dealer_name,
                du.id                  AS dealer_user_id,
                du.email               AS dealer_email,
                dp.first_name          AS dealer_first,
                bu.email                AS broker_email,
                bp.first_name           AS broker_first,
                bp.last_name            AS broker_last,
                se.user_id              AS exec_user_id,
                se.verification_status  AS exec_verification,
                eu.email                AS exec_email,
                ep.first_name           AS exec_first,
                (SELECT om.organization_id FROM organization_members om
                 WHERE om.user_id = sd.user_id LIMIT 1) AS organization_id
            FROM broker_inventory bi
            JOIN salesdesks sd        ON sd.id  = bi.salesdesk_id
            JOIN cars c               ON c.id   = bi.car_id
            JOIN dealers d            ON d.id   = c.dealer_id
            JOIN users du             ON du.id  = d.user_id
            LEFT JOIN profiles dp     ON dp.user_id = du.id
            JOIN users bu             ON bu.id  = sd.user_id
            LEFT JOIN profiles bp     ON bp.user_id = bu.id
            LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
            LEFT JOIN users eu        ON eu.id  = se.user_id
            LEFT JOIN profiles ep     ON ep.user_id = se.user_id
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
                bi.id                  AS broker_inventory_id,
                bi.salesdesk_id,
                bi.car_id,
                bi.tracking_code,
                sd.user_id             AS broker_id,
                sd.display_name        AS desk_name,
                c.dealer_id,
                c.status               AS car_status,
                c.make, c.model, c.year,
                c.uploaded_by_exec_id,
                d.company_name         AS dealer_name,
                du.id                  AS dealer_user_id,
                du.email               AS dealer_email,
                dp.first_name          AS dealer_first,
                bu.email                AS broker_email,
                bp.first_name           AS broker_first,
                bp.last_name            AS broker_last,
                se.user_id              AS exec_user_id,
                se.verification_status  AS exec_verification,
                eu.email                AS exec_email,
                ep.first_name           AS exec_first,
                (SELECT om.organization_id FROM organization_members om
                 WHERE om.user_id = sd.user_id LIMIT 1) AS organization_id
            FROM salesdesks sd
            JOIN broker_inventory bi  ON bi.salesdesk_id = sd.id
            JOIN cars c               ON c.id   = bi.car_id
            JOIN dealers d            ON d.id   = c.dealer_id
            JOIN users du             ON du.id  = d.user_id
            LEFT JOIN profiles dp     ON dp.user_id = du.id
            JOIN users bu             ON bu.id  = sd.user_id
            LEFT JOIN profiles bp     ON bp.user_id = bu.id
            LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
            LEFT JOIN users eu        ON eu.id  = se.user_id
            LEFT JOIN profiles ep     ON ep.user_id = se.user_id
            WHERE sd.slug  = ?
              AND c.slug   = ?
              AND sd.is_active = 1
            LIMIT 1
            FOR UPDATE
        ");
        $fallbackStmt->execute([$deskSlug, $carSlugPost]);
        $track = $fallbackStmt->fetch() ?: null;
    }

    // ── MIGRATION 0012: platform attribution branch ────────────
    // No tracking_code, no (resolved) desk_slug — car_slug alone
    // against dealer inventory directly. broker_id / salesdesk_id /
    // tracking_code are real NULL here (migration 0012 made all
    // three columns nullable) — there is no broker on this lead, not
    // a stand-in one. Shaped to return the same column set as the
    // two branches above so STEP 2 onward needs no further branching;
    // the broker/org fields are simply NULL.
    $isPlatformAttribution = false;
    if (!$track && $carSlugPost) {
        $platformStmt = $pdo->prepare("
            SELECT
                NULL                    AS broker_inventory_id,
                NULL                    AS salesdesk_id,
                c.id                    AS car_id,
                NULL                    AS tracking_code,
                NULL                    AS broker_id,
                NULL                    AS desk_name,
                c.dealer_id,
                c.status                AS car_status,
                c.make, c.model, c.year,
                c.uploaded_by_exec_id,
                d.company_name          AS dealer_name,
                du.id                   AS dealer_user_id,
                du.email                AS dealer_email,
                dp.first_name           AS dealer_first,
                NULL                    AS broker_email,
                NULL                    AS broker_first,
                NULL                    AS broker_last,
                se.user_id              AS exec_user_id,
                se.verification_status  AS exec_verification,
                eu.email                AS exec_email,
                ep.first_name           AS exec_first,
                NULL                    AS organization_id
            FROM cars c
            JOIN dealers d             ON d.id  = c.dealer_id
            JOIN users du              ON du.id = d.user_id
            LEFT JOIN profiles dp      ON dp.user_id = du.id
            LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
            LEFT JOIN users eu         ON eu.id  = se.user_id
            LEFT JOIN profiles ep      ON ep.user_id = se.user_id
            WHERE c.slug = ?
              AND d.is_active = 1
            LIMIT 1
            FOR UPDATE
        ");
        $platformStmt->execute([$carSlugPost]);
        $track = $platformStmt->fetch() ?: null;

        if ($track) {
            $isPlatformAttribution = true;
        }
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
            attribution_type,
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
            ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(),
            ?, ?, ?, ?, ?,
            ?, NOW(),
            'new', NOW(), NOW(), NOW()
        )
    ")->execute([
        $uuid,
        $track['broker_id'],           // NULL on platform branch — column now nullable
        $track['salesdesk_id'],        // NULL on platform branch
        $track['organization_id'] ?? null,
        $track['car_id'],
        $track['dealer_id'],
        $track['tracking_code'],       // NULL on platform branch — column now nullable
        $isPlatformAttribution ? 'platform' : 'broker',
        $buyerName,
        preg_replace('/\D/', '', $buyerPhone),
        $buyerEmail ?: null,
        $buyerIntent,
        $buyerMessage ?: null,
        $consentGiven,
    ]);

    $leadId = (int) $pdo->lastInsertId();

    // ── STEP 5: Increment broker_inventory view counter ────────
    // Platform-attributed leads have no broker_inventory row.
    if (!$isPlatformAttribution && $track['broker_inventory_id']) {
        $pdo->prepare("
            UPDATE broker_inventory SET views = views + 1
            WHERE id = ?
        ")->execute([$track['broker_inventory_id']]);
    }

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
            'attribution_type' => $isPlatformAttribution ? 'platform' : 'broker',
            'intent'         => $buyerIntent,
            'has_email'      => !empty($buyerEmail),
        ]),
        $ip,
    ]);

    $pdo->commit();

    // ── STEP 7: Post-commit notifications (fire-and-forget) ────

    // Notify broker (in-app) — skipped for platform leads, there is
    // no user_id to attach the notification to.
    if (!$isPlatformAttribution) {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, body, meta, created_at)
            VALUES (?, 'new_lead', ?, ?, ?, NOW())
        ")->execute([
            $track['broker_id'],
            'New lead: ' . $track['year'] . ' ' . $track['make'] . ' ' . $track['model'],
            $buyerName . ' is interested via your SalesDesk.',
            json_encode(['lead_id' => $leadId, 'car_id' => $track['car_id']]),
        ]);
    }

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

    // ── Email notifications — supply side (D-03 follow-up) ─────
    // Each recipient gets their own email via sendNewLeadEmail();
    // this is intentionally not a single CC'd email — see mailer.php.
    $leadEmailContext = [
        'buyer_name'   => $buyerName,
        'buyer_phone'  => preg_replace('/\D/', '', $buyerPhone),
        'buyer_intent' => $buyerIntent,
        'car_make'     => $track['make'],
        'car_model'    => $track['model'],
        'car_year'     => $track['year'],
        'desk_name'    => $track['desk_name'],
    ];

    // Broker — was in-app only until now. Skipped entirely for
    // platform leads (no broker exists); SalesDesk's own sales inbox
    // is emailed instead, in addition to the dealer.
    if (!$isPlatformAttribution) {
        sendNewLeadEmail(
            $track['broker_email'] ?? '',
            trim(($track['broker_first'] ?? '') . ' ' . ($track['broker_last'] ?? '')),
            'broker',
            $leadEmailContext
        );
    } else {
        sendNewLeadEmail(
            'sales@salesdesk.co.za',
            'SalesDesk Team',
            'platform',
            $leadEmailContext
        );
    }

    // Dealer principal — was in-app only until now.
    sendNewLeadEmail(
        $track['dealer_email'] ?? '',
        $track['dealer_first'] ?? '',
        'dealer',
        $leadEmailContext
    );

    // Sales exec — only when the car was uploaded by a verified exec.
    // Mirrors the verification gate used elsewhere (e.g. exec_guard.php),
    // so an unverified/suspended/rejected exec never gets emailed leads
    // for a car still attributed to them.
    if (
        !empty($track['uploaded_by_exec_id'])
        && ($track['exec_verification'] ?? null) === 'verified'
        && !empty($track['exec_email'])
    ) {
        sendNewLeadEmail(
            $track['exec_email'],
            $track['exec_first'] ?? '',
            'sales_exec',
            $leadEmailContext
        );
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
