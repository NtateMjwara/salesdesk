<?php
/**
 * SalesDesk — Public Visitor Session Helper.
 * T1 owns this file.
 *
 * Manages the anonymous sd_vid cookie and visitor_sessions table
 * for all public-facing pages. No PII is stored — the cookie value
 * is a random 64-char hex token with no relationship to any user data.
 *
 * POPIA note: This is operational session tracking, not advertising
 * profiling. No consent banner is required under POPIA for this use.
 * The privacy policy must mention this cookie.
 *
 * CONSENT GATE (this pass): getActiveTrackingCode() persists the
 * broker attribution code into visitor_sessions.last_tracking_code so
 * it survives across the visitor's next 90 days of visits. That
 * persistence is exactly the kind of cross-visit tracking POPIA/GDPR
 * require consent for — see includes/cookie-consent.php's
 * INTEGRATION NOTE. It's now gated behind hasConsent('functional').
 * The CURRENT page view is still attributed regardless of consent
 * (the ?ref= is read and returned either way) — only the persistence
 * of that code into future requests is gated. See getActiveTrackingCode()
 * below.
 *
 * USAGE — top of every public page (before any output):
 *
 *   require_once '../../includes/visitor.php';
 *
 *   // Returns the resolved VisitorSession array:
 *   $visitor = initVisitorSession();
 *   // $visitor['id']                  — visitor_sessions.id
 *   // $visitor['token']               — the cookie value
 *   // $visitor['last_tracking_code']  — most recent ?ref= seen
 *
 *   // Resolve and record a car page view:
 *   recordCarView($visitor, $carId, $trackingCode);
 *
 *   // Check wishlist state:
 *   $wishlisted = isCarWishlisted($visitor['id'], $carId);
 *
 * ATTRIBUTION RESOLUTION ORDER (applied by getActiveTrackingCode()):
 *   1. ?ref= present on current URL → use it, update session
 *      (persistence to last_tracking_code requires functional consent —
 *      the current-request value is still returned regardless)
 *   2. No ?ref= but session has last_tracking_code → use stored value
 *   3. Neither → null (organic / platform view, no broker credit)
 *
 * Cookie spec:
 *   Name:     sd_vid
 *   Value:    hex(random_bytes(32))   — 64 hex chars
 *   MaxAge:   7776000 (90 days)
 *   Path:     /
 *   Secure:   true (production)
 *   HttpOnly: true
 *   SameSite: Lax
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/cookie-consent.php';

define('VISITOR_COOKIE_NAME', 'sd_vid');
define('VISITOR_COOKIE_DAYS', 90);

// ── Public API ────────────────────────────────────────────────

/**
 * Initialise or resume the visitor session for the current request.
 *
 * - Reads sd_vid cookie.
 * - If valid token exists in DB: updates last_seen_at, returns row.
 * - If no cookie or unknown token: creates new session, sets cookie.
 * - Optionally links to a logged-in user if $_SESSION['user_id'] is set.
 *
 * @return array  visitor_sessions row with keys:
 *                id, token, last_tracking_code, user_id,
 *                first_seen_at, last_seen_at
 */
function initVisitorSession(): array
{
    $pdo  = Database::getInstance();
    $ip   = _visitorIp();
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $uaHash = hash('sha256', $ua);

    // Try to read existing cookie.
    $token = $_COOKIE[VISITOR_COOKIE_NAME] ?? '';

    if ($token && preg_match('/^[a-f0-9]{64}$/', $token)) {
        // Look up existing session.
        $stmt = $pdo->prepare("
            SELECT id, token, last_tracking_code, user_id, first_seen_at, last_seen_at
            FROM visitor_sessions
            WHERE token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if ($row) {
            // Refresh last_seen_at and optionally link to logged-in user.
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $pdo->prepare("
                UPDATE visitor_sessions
                SET last_seen_at    = NOW(),
                    ip_address      = ?,
                    user_agent_hash = ?,
                    user_id         = COALESCE(user_id, ?)
                WHERE id = ?
            ")->execute([$ip, $uaHash, $userId, $row['id']]);

            // Re-set cookie to keep it fresh (rolling expiry).
            _setVisitorCookie($token);
            return $row;
        }
    }

    // No valid session — create one.
    $token  = bin2hex(random_bytes(32));
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

    $pdo->prepare("
        INSERT INTO visitor_sessions
            (token, ip_address, user_agent_hash, user_id, first_seen_at, last_seen_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ")->execute([$token, $ip, $uaHash, $userId]);

    $newId = (int) $pdo->lastInsertId();
    _setVisitorCookie($token);

    return [
        'id'                 => $newId,
        'token'              => $token,
        'last_tracking_code' => null,
        'user_id'            => $userId,
        'first_seen_at'      => date('Y-m-d H:i:s'),
        'last_seen_at'       => date('Y-m-d H:i:s'),
    ];
}

/**
 * Resolve the active tracking code for the current page load.
 *
 * Priority:
 *   1. ?ref= query param (present → use it for THIS request always;
 *      persist it to the session only if the visitor has consented
 *      to "functional" cookies — see CONSENT GATE note at the top of
 *      this file)
 *   2. Session's last_tracking_code (no ?ref= → fall back)
 *   3. null (organic visit)
 *
 * @param array $visitor   Row from initVisitorSession()
 * @return string|null     Active tracking code, or null
 */
function getActiveTrackingCode(array $visitor): ?string
{
    $ref = trim($_GET['ref'] ?? '');

    if ($ref && preg_match('/^[a-f0-9]{32}$/i', $ref)) {
        // Persist new tracking code to session — but only once the
        // visitor has consented to "functional" cookies. Persisting
        // this is what carries attribution forward across the next
        // 90 days of visits (see includes/cookie-consent.php's
        // INTEGRATION NOTE); it is not "strictly necessary" under
        // POPIA/GDPR, so it requires an affirmative consent decision.
        //
        // The current page view is still attributed regardless: we
        // return $ref unconditionally below. We only skip *carrying
        // it forward* into visitor_sessions.last_tracking_code when
        // consent hasn't (yet) been given for "functional".
        if ($ref !== $visitor['last_tracking_code'] && hasConsent('functional')) {
            $pdo = Database::getInstance();
            $pdo->prepare("
                UPDATE visitor_sessions
                SET last_tracking_code = ?
                WHERE id = ?
            ")->execute([$ref, $visitor['id']]);
        }
        return $ref;
    }

    // Fall back to stored code.
    return $visitor['last_tracking_code'] ?: null;
}

/**
 * Record a car detail page view.
 * Resolves the tracking code → broker_inventory row for analytics.
 * Silently swallows exceptions — never let view logging crash a public page.
 *
 * @param array    $visitor      Row from initVisitorSession()
 * @param int      $carId        cars.id
 * @param string|null $trackingCode  From getActiveTrackingCode()
 */
function recordCarView(array $visitor, int $carId, ?string $trackingCode): void
{
    try {
        $pdo = Database::getInstance();
        $ip  = _visitorIp();
        $ref = $_SERVER['HTTP_REFERER'] ?? null;

        // Resolve broker_inventory_id from tracking_code.
        $biId = null;
        if ($trackingCode) {
            $biStmt = $pdo->prepare("
                SELECT id FROM broker_inventory WHERE tracking_code = ? LIMIT 1
            ");
            $biStmt->execute([$trackingCode]);
            $biRow = $biStmt->fetch();
            $biId  = $biRow ? (int) $biRow['id'] : null;
        }

        $pdo->prepare("
            INSERT INTO car_views
                (car_id, visitor_session_id, tracking_code,
                 broker_inventory_id, ip_address, referrer, viewed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $carId,
            $visitor['id'],
            $trackingCode,
            $biId,
            $ip,
            $ref ? mb_substr($ref, 0, 512) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[SalesDesk visitor] recordCarView failed: ' . $e->getMessage());
    }
}

/**
 * Check whether a car is in the visitor's wishlist.
 *
 * @param int $visitorSessionId
 * @param int $carId
 * @return bool
 */
function isCarWishlisted(int $visitorSessionId, int $carId): bool
{
    try {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT 1 FROM visitor_wishlist
            WHERE visitor_session_id = ? AND car_id = ?
            LIMIT 1
        ");
        $stmt->execute([$visitorSessionId, $carId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Toggle a car in the visitor's wishlist.
 * Returns new state: true = now wishlisted, false = now removed.
 *
 * @param int         $visitorSessionId
 * @param int         $carId
 * @param string|null $trackingCode
 * @return bool
 */
function toggleWishlist(int $visitorSessionId, int $carId, ?string $trackingCode): bool
{
    $pdo = Database::getInstance();

    $check = $pdo->prepare("
        SELECT id FROM visitor_wishlist WHERE visitor_session_id = ? AND car_id = ? LIMIT 1
    ");
    $check->execute([$visitorSessionId, $carId]);
    $existing = $check->fetch();

    if ($existing) {
        $pdo->prepare("
            DELETE FROM visitor_wishlist WHERE id = ?
        ")->execute([$existing['id']]);
        return false;
    }

    $pdo->prepare("
        INSERT INTO visitor_wishlist (visitor_session_id, car_id, tracking_code, added_at)
        VALUES (?, ?, ?, NOW())
    ")->execute([$visitorSessionId, $carId, $trackingCode]);

    return true;
}

/**
 * Get all car IDs in a visitor's wishlist.
 *
 * @param int $visitorSessionId
 * @return int[]
 */
function getWishlistCarIds(int $visitorSessionId): array
{
    try {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT car_id FROM visitor_wishlist
            WHERE visitor_session_id = ?
            ORDER BY added_at DESC
        ");
        $stmt->execute([$visitorSessionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Compute a simple monthly finance estimate.
 * Uses config values: finance_deposit_percent, finance_interest_rate_annual, finance_term_months.
 *
 * @param float $price  Vehicle asking price in ZAR
 * @return float        Estimated monthly instalment
 */
function estimateMonthlyPayment(float $price): float
{
    static $deposit  = null;
    static $rate     = null;
    static $term     = null;

    if ($deposit === null) {
        $pdo     = Database::getInstance();
        $fetch   = static function (string $key, string $default) use ($pdo): float {
            $s = $pdo->prepare("SELECT config_value FROM platform_config WHERE config_key = ?");
            $s->execute([$key]);
            $r = $s->fetch();
            return (float) ($r ? $r['config_value'] : $default);
        };
        $deposit = $fetch('finance_deposit_percent',      '20');
        $rate    = $fetch('finance_interest_rate_annual', '13.25');
        $term    = $fetch('finance_term_months',          '60');
    }

    $loanAmount   = $price * (1 - ($deposit / 100));
    $monthlyRate  = ($rate / 100) / 12;

    if ($monthlyRate <= 0) {
        return $loanAmount / $term;
    }

    // Standard annuity formula
    return round(
        $loanAmount * ($monthlyRate * pow(1 + $monthlyRate, $term))
                    / (pow(1 + $monthlyRate, $term) - 1),
        0
    );
}

// ── Internal helpers ──────────────────────────────────────────

/**
 * Set or refresh the sd_vid cookie.
 * Respects HTTPS detection for the Secure flag.
 */
function _setVisitorCookie(string $token): void
{
    $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $maxAge   = VISITOR_COOKIE_DAYS * 86400;
    $expires  = time() + $maxAge;

    // PHP 7.3+ cookie options array — supports SameSite cleanly.
    setcookie(VISITOR_COOKIE_NAME, $token, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Get the real visitor IP, respecting common proxy headers.
 * Prefer REMOTE_ADDR as the ground truth; only use forwarded headers
 * if behind a known trusted proxy (Cloudflare / Nginx).
 */
function _visitorIp(): string
{
    // In production behind Cloudflare:
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        return filter_var(trim($first), FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
