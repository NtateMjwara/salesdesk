<?php
/**
 * SalesDesk — Shared helper functions.
 *
 * T1 owns this file. Any addition requires a SHARED_CHANGELOG.md entry
 * and Slack notification to all team leads before merging.
 *
 * Functions defined here:
 *   TOKEN / ID GENERATORS
 *     generateToken()
 *     generateUuidV4()
 *     generateAndStoreOTP()
 *     verifyOTP()
 *
 *   USER QUERIES
 *     getUserByEmail()
 *     getUserById()
 *
 *   SESSION / AUTH GUARDS
 *     isLoggedIn()
 *     requireLogin()
 *     requireRole()
 *
 *   RATE LIMITING (login)
 *     checkRateLimit()
 *     recordFailedAttempt()
 *     clearFailedAttempts()
 *
 *   API RATE LIMITING  (BUG-05 — T1-04 / inf9)
 *     checkApiRateLimit()
 *
 *   DEALER / EXEC MANAGEMENT  (D-09)
 *     suspendDealer()
 *
 *   ORGANISATION ROLE GATING  (o4)
 *     requireOrgRole()
 *     getActiveMemberRole()
 *
 *   CACHE POLICY  (inf10)
 *     applyCachePolicy()
 *
 *   PLATFORM CONFIG
 *     getPlatformConfig()
 *     getPlatformConfigInt()
 *
 *   MISC
 *     sanitizeInput()
 *     formatZAR()
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/response.php';

// ============================================================
// TOKEN / ID GENERATORS
// ============================================================

/** 64-char hex verification / reset token */
function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

/** UUID v4 */
function generateUuidV4(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a numeric OTP and store it (hashed) in otp_codes.
 * Any previous unused OTPs for this user + purpose are invalidated first.
 *
 * @param int    $userId
 * @param string $purpose  'email_verify' | 'password_reset' | 'login_2fa'
 * @return string  Plain OTP to send to the user
 */
function generateAndStoreOTP(int $userId, string $purpose): string
{
    $pdo  = Database::getInstance();
    $code = str_pad(
        (string) random_int(0, 10 ** OTP_LENGTH - 1),
        OTP_LENGTH,
        '0',
        STR_PAD_LEFT
    );
    $hash = password_hash($code, PASSWORD_BCRYPT);

    // Invalidate previous OTPs for same user + purpose.
    $pdo->prepare("
        UPDATE otp_codes
        SET used = 1
        WHERE user_id = ? AND purpose = ? AND used = 0
    ")->execute([$userId, $purpose]);

    $pdo->prepare("
        INSERT INTO otp_codes (user_id, purpose, code_hash, expires_at, created_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
    ")->execute([$userId, $purpose, $hash, OTP_EXPIRY_SECONDS]);

    return $code;
}

/**
 * Verify a submitted OTP.
 * Returns true and marks the code used on success.
 * Returns false if not found, expired, used, or wrong code.
 *
 * @param int    $userId
 * @param string $purpose
 * @param string $submittedCode  Plain code from the user
 */
function verifyOTP(int $userId, string $purpose, string $submittedCode): bool
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT id, code_hash
        FROM otp_codes
        WHERE user_id    = ?
          AND purpose    = ?
          AND used       = 0
          AND expires_at > NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $purpose]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($submittedCode, $row['code_hash'])) {
        return false;
    }

    $pdo->prepare("UPDATE otp_codes SET used = 1 WHERE id = ?")
        ->execute([$row['id']]);

    return true;
}


// ============================================================
// USER QUERIES
// ============================================================

function getUserByEmail(string $email): array|false
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function getUserById(int $id): array|false
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}


// ============================================================
// SESSION / AUTH GUARDS
// ============================================================

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Require the user to be logged in.
 * Redirects to login with ?redirect= so they return after authenticating.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $current = $_SERVER['REQUEST_URI'] ?? '';
        redirect('/auth/login.php?redirect=' . urlencode($current));
    }
}

/**
 * Require a specific user role. Call after requireLogin().
 *
 * @param string $role  'broker' | 'dealer' | 'sales_exec' | 'admin'
 */
function requireRole(string $role): void
{
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Access denied.');
    }
}


// ============================================================
// RATE LIMITING — LOGIN  (DB-backed, shared-hosting compatible)
// ============================================================

function checkRateLimit(string $identifier, string $ip): bool
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS attempts
        FROM login_attempts
        WHERE (identifier = ? OR ip_address = ?)
          AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$identifier, $ip, RATE_LIMIT_WINDOW]);
    $row = $stmt->fetch();
    return (int)($row['attempts'] ?? 0) < RATE_LIMIT_ATTEMPTS;
}

function recordFailedAttempt(string $identifier, string $ip): void
{
    $pdo = Database::getInstance();
    $pdo->prepare("
        INSERT INTO login_attempts (identifier, ip_address, attempted_at)
        VALUES (?, ?, NOW())
    ")->execute([$identifier, $ip]);
}

function clearFailedAttempts(string $identifier): void
{
    $pdo = Database::getInstance();
    $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")
        ->execute([$identifier]);
}


// ============================================================
// API RATE LIMITING  (BUG-05, inf9)
//
// Call at the top of every /api/ file BEFORE any other logic:
//
//   $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
//   if (!checkApiRateLimit($ip, 'dealers_search')) {
//       rateLimitResponse(); // exits
//   }
//
// Uses the api_rate_limits table (created in 0005_addenda.sql).
// Window = current UTC minute (truncated to the minute boundary).
// ============================================================

/**
 * Check and record an API request for rate limiting purposes.
 *
 * @param string $ip            Client IP address
 * @param string $endpoint      Short identifier e.g. 'dealers_search'
 * @param int    $maxPerMinute  Default from config constant
 * @return bool  True = request allowed; False = rate limit exceeded
 */
function checkApiRateLimit(
    string $ip,
    string $endpoint,
    int $maxPerMinute = API_RATE_LIMIT_DEFAULT
): bool {
    $pdo = Database::getInstance();

    // Truncate to the current UTC minute — all requests in the same minute
    // share the same window_start, which is the UNIQUE key component.
    $windowStart = gmdate('Y-m-d H:i:00');

    // Clean up rows older than 2 minutes (keep table lean).
    $pdo->prepare("
        DELETE FROM api_rate_limits
        WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
    ")->execute();

    // Atomic upsert — insert or increment the counter for this window.
    $pdo->prepare("
        INSERT INTO api_rate_limits
            (ip_address, endpoint, window_start, request_count, created_at)
        VALUES (?, ?, ?, 1, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            request_count = request_count + 1
    ")->execute([$ip, $endpoint, $windowStart]);

    // Read back the current count.
    $stmt = $pdo->prepare("
        SELECT request_count
        FROM api_rate_limits
        WHERE ip_address   = ?
          AND endpoint     = ?
          AND window_start = ?
        LIMIT 1
    ");
    $stmt->execute([$ip, $endpoint, $windowStart]);
    $row = $stmt->fetch();

    return (int)($row['request_count'] ?? 1) <= $maxPerMinute;
}


// ============================================================
// DEALER MANAGEMENT  (D-09)
//
// suspendDealer() implements the full cascade:
//   1. Set users.status = 'suspended'
//   2. Pause all the dealer's active car listings
//   3. Freeze pending commissions (add note)
//   4. Write to audit_logs
//   5. Return counts for the admin confirmation modal
// ============================================================

/**
 * Suspend a dealer account with full cascade.
 *
 * Cascade:
 *   - users.status → 'suspended'
 *   - cars.status  → 'paused' for all active listings
 *   - commissions  → dealer_notes updated (status stays 'pending')
 *   - audit_log    → one entry per significant change
 *
 * @param int $dealerId  dealers.id
 * @param int $adminId   users.id of the admin performing the action
 * @return array{cars_paused: int, commissions_frozen: int}
 */
function suspendDealer(int $dealerId, int $adminId): array
{
    $pdo = Database::getInstance();

    // 1. Get dealer's user_id for users table update.
    $dealer = $pdo->prepare("SELECT user_id, company_name FROM dealers WHERE id = ?");
    $dealer->execute([$dealerId]);
    $dealerRow = $dealer->fetch();

    if (!$dealerRow) {
        return ['cars_paused' => 0, 'commissions_frozen' => 0];
    }

    $dealerUserId  = (int) $dealerRow['user_id'];
    $dealerName    = $dealerRow['company_name'];

    // 2. Suspend the user account.
    $pdo->prepare("
        UPDATE users
        SET status = 'suspended', updated_at = NOW()
        WHERE id = ?
    ")->execute([$dealerUserId]);

    // 3. Pause all active car listings for this dealer.
    $pauseStmt = $pdo->prepare("
        UPDATE cars
        SET status = 'paused', updated_at = NOW()
        WHERE dealer_id = ? AND status = 'active'
    ");
    $pauseStmt->execute([$dealerId]);
    $carsPaused = $pauseStmt->rowCount();

    // 4. Freeze pending commissions — add a note but don't change status.
    $freezeStmt = $pdo->prepare("
        UPDATE commissions
        SET dealer_notes = CONCAT(
                IFNULL(dealer_notes, ''),
                IF(dealer_notes IS NOT NULL AND dealer_notes != '', '\n', ''),
                '[dealer_suspended: account suspended by admin on ',
                DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'),
                ']'
            ),
            updated_at = NOW()
        WHERE dealer_id = ? AND status = 'pending'
    ");
    $freezeStmt->execute([$dealerId]);
    $commissionsFrozen = $freezeStmt->rowCount();

    // 5. Write to audit_log.
    $logStmt = $pdo->prepare("
        INSERT INTO audit_logs
            (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Dealer suspension event.
    $logStmt->execute([
        $adminId,
        'dealer.suspended',
        'dealer',
        $dealerId,
        json_encode(['status' => 'active']),
        json_encode([
            'status'             => 'suspended',
            'cars_paused'        => $carsPaused,
            'commissions_frozen' => $commissionsFrozen,
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    return [
        'cars_paused'        => $carsPaused,
        'commissions_frozen' => $commissionsFrozen,
    ];
}


// ============================================================
// ORGANISATION ROLE GATING  (o4)
//
// requireOrgRole() enforces org membership at the DB level.
// Session org_context holds the active org_id.
// ============================================================

/**
 * Get the current user's role in the active organisation context.
 * Returns null if the user is not a member or context is not set.
 *
 * @param int $userId
 * @param int $orgId
 * @return string|null  'owner' | 'admin' | 'agent' | null
 */
function getActiveMemberRole(int $userId, int $orgId): ?string
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT role
        FROM organization_members
        WHERE organization_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orgId, $userId]);
    $row = $stmt->fetch();
    return $row ? $row['role'] : null;
}

/**
 * Require the logged-in user to hold a minimum org role.
 * Exits with 403 if the requirement is not met.
 *
 * Role hierarchy: owner > admin > agent
 *
 * @param string $minimumRole  'owner' | 'admin' | 'agent'
 */
function requireOrgRole(string $minimumRole): void
{
    requireLogin();

    $orgId = (int) ($_SESSION['org_context'] ?? 0);
    if (!$orgId) {
        http_response_code(403);
        exit('No organisation context active.');
    }

    $userId      = (int) $_SESSION['user_id'];
    $actualRole  = getActiveMemberRole($userId, $orgId);

    $hierarchy = ['agent' => 1, 'admin' => 2, 'owner' => 3];
    $required  = $hierarchy[$minimumRole] ?? 1;
    $actual    = $hierarchy[$actualRole ?? ''] ?? 0;

    if ($actual < $required) {
        http_response_code(403);
        exit('Insufficient organisation role.');
    }
}


// ============================================================
// CACHE POLICY HELPER  (inf10)
//
// Call at the top of every page — never set Cache-Control manually.
//   applyCachePolicy('auth');    — authenticated app pages
//   applyCachePolicy('public');  — buyer-facing car link pages
//   applyCachePolicy('api');     — JSON API endpoints
// ============================================================

/**
 * Apply the correct Cache-Control headers for the page type.
 *
 * @param string $type  'auth' | 'public' | 'api'
 */
function applyCachePolicy(string $type): void
{
    match ($type) {
        'public' => header('Cache-Control: public, max-age=300, stale-while-revalidate=60'),
        'api'    => header('Cache-Control: no-store'),
        default  => header('Cache-Control: no-store, no-cache, must-revalidate, private'),
    };
}


// ============================================================
// PLATFORM CONFIG
// ============================================================

/**
 * Read a single platform_config value.
 * Returns the string value or $default if the key does not exist.
 */
function getPlatformConfig(string $key, string $default = ''): string
{
    static $cache = [];

    if (!isset($cache[$key])) {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT config_value FROM platform_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $row         = $stmt->fetch();
        $cache[$key] = $row ? $row['config_value'] : null;
    }

    return $cache[$key] ?? $default;
}

/**
 * Read a platform_config value as an integer.
 */
function getPlatformConfigInt(string $key, int $default = 0): int
{
    return (int) getPlatformConfig($key, (string) $default);
}


// ============================================================
// MISC
// ============================================================

/**
 * Escape a string for safe HTML output.
 */
function sanitizeInput(string $data): string
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format a number as South African Rand.
 */
function formatZAR(float $amount): string
{
    return 'R ' . number_format($amount, 2);
}

/**
 * Write an entry to the audit_logs table.
 * actor_id = null means system-generated action.
 *
 * @param string     $action      e.g. 'lead.created', 'commission.approved'
 * @param string     $entityType  e.g. 'lead', 'commission', 'sales_executive'
 * @param int        $entityId
 * @param array|null $beforeData
 * @param array|null $afterData
 * @param int|null   $actorId     Defaults to $_SESSION['user_id'] if set
 */
function writeAuditLog(
    string $action,
    string $entityType,
    int $entityId,
    ?array $beforeData = null,
    ?array $afterData  = null,
    ?int $actorId      = null
): void {
    $pdo    = Database::getInstance();
    $actor  = $actorId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $pdo->prepare("
        INSERT INTO audit_logs
            (actor_id, action, entity_type, entity_id,
             before_data, after_data, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $actor,
        $action,
        $entityType,
        $entityId,
        $beforeData !== null ? json_encode($beforeData) : null,
        $afterData  !== null ? json_encode($afterData)  : null,
        $ip,
        $ua ? mb_substr($ua, 0, 255) : null,
    ]);
}
