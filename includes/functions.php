<?php
/**
 * SalesDesk — Shared helper functions.
 *
 * FIXES APPLIED IN THIS FILE:
 *   FIX-05 (resolved): profiles.car_limit is added by migration
 *           0010_add_profile_car_limit.sql. p.car_limit is selected again
 *           in getUsersForAdmin() below so the admin table reflects the
 *           value set via the "set_car_limit" action in
 *           app/admin/users.php, instead of always falling back to the
 *           platform default.
 *
 * All other functions are unchanged.
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/response.php';

// ============================================================
// TOKEN / ID GENERATORS
// ============================================================

function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

function generateUuidV4(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

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

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $current = $_SERVER['REQUEST_URI'] ?? '';
        redirect('/auth/login.php?redirect=' . urlencode($current));
    }
}

function requireRole(string $role): void
{
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Access denied.');
    }
}


// ============================================================
// RATE LIMITING — LOGIN
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
// API RATE LIMITING
// ============================================================

function checkApiRateLimit(
    string $ip,
    string $endpoint,
    int $maxPerMinute = API_RATE_LIMIT_DEFAULT
): bool {
    $pdo = Database::getInstance();
    $windowStart = gmdate('Y-m-d H:i:00');

    $pdo->prepare("
        DELETE FROM api_rate_limits
        WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
    ")->execute();

    $pdo->prepare("
        INSERT INTO api_rate_limits
            (ip_address, endpoint, window_start, request_count, created_at)
        VALUES (?, ?, ?, 1, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            request_count = request_count + 1
    ")->execute([$ip, $endpoint, $windowStart]);

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
// DEALER MANAGEMENT
// ============================================================

function suspendDealer(int $dealerId, int $adminId): array
{
    $pdo = Database::getInstance();

    $dealer = $pdo->prepare("SELECT user_id, company_name FROM dealers WHERE id = ?");
    $dealer->execute([$dealerId]);
    $dealerRow = $dealer->fetch();

    if (!$dealerRow) {
        return ['cars_paused' => 0, 'commissions_frozen' => 0];
    }

    $dealerUserId = (int) $dealerRow['user_id'];

    $pdo->prepare("
        UPDATE users
        SET status = 'suspended', updated_at = NOW()
        WHERE id = ?
    ")->execute([$dealerUserId]);

    $pauseStmt = $pdo->prepare("
        UPDATE cars
        SET status = 'paused', updated_at = NOW()
        WHERE dealer_id = ? AND status = 'active'
    ");
    $pauseStmt->execute([$dealerId]);
    $carsPaused = $pauseStmt->rowCount();

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

    $logStmt = $pdo->prepare("
        INSERT INTO audit_logs
            (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

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

function reinstateDealer(int $dealerId, int $adminId): array
{
    $pdo = Database::getInstance();

    $dealerStmt = $pdo->prepare("
        SELECT d.user_id, d.company_name, u.status
        FROM dealers d
        JOIN users u ON u.id = d.user_id
        WHERE d.id = ?
    ");
    $dealerStmt->execute([$dealerId]);
    $dealerRow = $dealerStmt->fetch();

    if (!$dealerRow) {
        error_log("[SalesDesk] reinstateDealer: dealer #{$dealerId} not found.");
        return ['cars_reinstated' => 0, 'commissions_unfrozen' => 0];
    }

    if ($dealerRow['status'] !== 'suspended') {
        error_log("[SalesDesk] reinstateDealer: dealer #{$dealerId} is not suspended.");
        return ['cars_reinstated' => 0, 'commissions_unfrozen' => 0];
    }

    $dealerUserId = (int) $dealerRow['user_id'];

    $pdo->prepare("
        UPDATE users
        SET status = 'active', updated_at = NOW()
        WHERE id = ?
    ")->execute([$dealerUserId]);

    $reinstateStmt = $pdo->prepare("
        UPDATE cars
        SET status = 'active', updated_at = NOW()
        WHERE dealer_id = ? AND status = 'paused'
    ");
    $reinstateStmt->execute([$dealerId]);
    $carsReinstated = $reinstateStmt->rowCount();

    $unfreezeStmt = $pdo->prepare("
        UPDATE commissions
        SET dealer_notes = NULLIF(
              TRIM(
                REGEXP_REPLACE(
                  IFNULL(dealer_notes, ''),
                  '\\n?\\[dealer_suspended:[^\\]]*\\]',
                  ''
                )
              ),
              ''
            ),
            updated_at = NOW()
        WHERE dealer_id = ? AND status = 'pending'
          AND dealer_notes LIKE '%[dealer_suspended:%'
    ");
    $unfreezeStmt->execute([$dealerId]);
    $commissionsUnfrozen = $unfreezeStmt->rowCount();

    writeAuditLog(
        'dealer.reinstated',
        'dealer',
        $dealerId,
        ['status' => 'suspended'],
        [
            'status'               => 'active',
            'cars_reinstated'      => $carsReinstated,
            'commissions_unfrozen' => $commissionsUnfrozen,
        ],
        $adminId
    );

    return [
        'cars_reinstated'      => $carsReinstated,
        'commissions_unfrozen' => $commissionsUnfrozen,
    ];
}


// ============================================================
// ORGANISATION ROLE GATING
// ============================================================

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
// CACHE POLICY HELPER
// ============================================================

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

function getPlatformConfigInt(string $key, int $default = 0): int
{
    return (int) getPlatformConfig($key, (string) $default);
}


// ============================================================
// MISC
// ============================================================

function sanitizeInput(string $data): string
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function formatZAR(float $amount): string
{
    return 'R ' . number_format($amount, 2);
}

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


// ============================================================
// COMMISSION STATE MACHINE
// ============================================================

const COMMISSION_TRANSITIONS = [
    'pending'    => ['approved'],
    'approved'   => ['scheduled'],
    'scheduled'  => ['processing'],
    'processing' => ['paid', 'failed'],
    'failed'     => ['scheduled'],
    'paid'       => [],
];

function transitionCommissionStatus(
    int $commissionId,
    string $newStatus,
    int $actorId,
    ?string $notes = null
): bool {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT id, status, broker_id, dealer_id, organization_id,
               gross_amount, platform_fee, net_amount
        FROM commissions
        WHERE id = ?
        FOR UPDATE
    ");

    $pdo->beginTransaction();

    try {
        $stmt->execute([$commissionId]);
        $commission = $stmt->fetch();

        if (!$commission) {
            $pdo->rollBack();
            error_log("[SalesDesk] transitionCommissionStatus: commission #{$commissionId} not found.");
            return false;
        }

        $currentStatus = $commission['status'];
        $allowed       = COMMISSION_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            $pdo->rollBack();
            error_log("[SalesDesk] transitionCommissionStatus: invalid transition {$currentStatus} → {$newStatus}.");
            return false;
        }

        $timestampCol = match ($newStatus) {
            'approved' => ', approved_at = NOW()',
            'paid'     => ', paid_at = NOW()',
            default    => '',
        };

        $pdo->prepare("
            UPDATE commissions
            SET status = ?, updated_at = NOW() {$timestampCol}
            WHERE id = ?
        ")->execute([$newStatus, $commissionId]);

        $afterData = ['status' => $newStatus];
        if ($notes) {
            $afterData['note'] = $notes;
        }

        writeAuditLog(
            "commission.{$newStatus}",
            'commission',
            $commissionId,
            ['status' => $currentStatus],
            $afterData,
            $actorId
        );

        $pdo->commit();
        return true;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("[SalesDesk] transitionCommissionStatus exception: " . $e->getMessage());
        return false;
    }
}

function getCommissionById(int $commissionId): array|false
{
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            l.uuid          AS lead_uuid,
            l.buyer_name,
            l.buyer_email,
            l.car_id,
            cars.make       AS car_make,
            cars.model      AS car_model,
            cars.year       AS car_year,
            cars.price      AS car_price,
            bu.email        AS broker_email,
            bp.first_name   AS broker_first,
            bp.last_name    AS broker_last,
            du.email        AS dealer_email,
            d.company_name  AS dealer_company,
            o.name          AS org_name
        FROM commissions c
        JOIN leads       l    ON l.id = c.lead_id
        JOIN cars             ON cars.id = l.car_id
        JOIN users       bu   ON bu.id = c.broker_id
        JOIN profiles    bp   ON bp.user_id = c.broker_id
        JOIN dealers     d    ON d.id = c.dealer_id
        JOIN users       du   ON du.id = d.user_id
        LEFT JOIN organizations o ON o.id = c.organization_id
        WHERE c.id = ?
    ");
    $stmt->execute([$commissionId]);
    return $stmt->fetch();
}

function computeNetAmount(float $grossAmount, float $feePct): float
{
    $fee = round($grossAmount * ($feePct / 100), 2);
    return round($grossAmount - $fee, 2);
}


// ============================================================
// PAYOUT HELPERS
// ============================================================

function getBrokerBankAccount(int $brokerId, ?int $orgId = null): array|false
{
    $pdo = Database::getInstance();

    if ($orgId) {
        $stmt = $pdo->prepare("
            SELECT * FROM bank_accounts
            WHERE organization_id = ? AND is_primary = 1
            LIMIT 1
        ");
        $stmt->execute([$orgId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM bank_accounts
        WHERE user_id = ? AND organization_id IS NULL AND is_primary = 1
        LIMIT 1
    ");
    $stmt->execute([$brokerId]);
    return $stmt->fetch();
}

function createPayoutRecord(
    int $commissionId,
    int $brokerId,
    int $bankAccountId,
    float $amount,
    int $adminId,
    ?int $orgId = null
): int {
    $pdo            = Database::getInstance();
    $idempotencyKey = generateIdempotencyKey($commissionId, $brokerId, $orgId);
    $uuid           = generateUuidV4();

    $check = $pdo->prepare("SELECT id, status FROM payouts WHERE idempotency_key = ?");
    $check->execute([$idempotencyKey]);
    $existing = $check->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }

    $pdo->prepare("
        INSERT INTO payouts
            (uuid, commission_id, broker_id, organization_id, bank_account_id,
             amount, status, idempotency_key, scheduled_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW(), NOW())
    ")->execute([
        $uuid,
        $commissionId,
        $brokerId,
        $orgId,
        $bankAccountId,
        $amount,
        $idempotencyKey,
    ]);

    $payoutId = (int) $pdo->lastInsertId();

    writeAuditLog(
        'payout.created',
        'payout',
        $payoutId,
        null,
        ['status' => 'scheduled', 'amount' => $amount, 'commission_id' => $commissionId],
        $adminId
    );

    return $payoutId;
}

function generateIdempotencyKey(int $commissionId, int $brokerId, ?int $orgId): string
{
    return 'idem-comm-' . $commissionId
        . '-broker-' . $brokerId
        . ($orgId ? '-org-' . $orgId : '');
}


// ============================================================
// ADMIN DATA HELPERS
// ============================================================

function getPendingVerifications(): array
{
    $pdo = Database::getInstance();

    $dealerStmt = $pdo->prepare("
        SELECT
            d.id,
            d.company_name,
            d.cipc_doc_url,
            d.verification_status,
            d.created_at        AS submitted_at,
            u.email,
            a.city,
            a.province
        FROM dealers d
        JOIN users u ON u.id = d.user_id
        LEFT JOIN addresses a ON a.id = d.address_id
        WHERE d.verification_status = 'pending'
          AND d.cipc_doc_url IS NOT NULL
        ORDER BY d.created_at ASC
    ");
    $dealerStmt->execute();

    $orgStmt = $pdo->prepare("
        SELECT
            o.id,
            o.name,
            o.cipc_number,
            o.verification_status,
            o.created_at        AS submitted_at,
            u.email             AS owner_email,
            a.city,
            a.province
        FROM organizations o
        JOIN users u ON u.id = o.owner_user_id
        LEFT JOIN addresses a ON a.id = o.address_id
        WHERE o.verification_status = 'pending'
        ORDER BY o.created_at ASC
    ");
    $orgStmt->execute();

    return [
        'dealers' => $dealerStmt->fetchAll(),
        'orgs'    => $orgStmt->fetchAll(),
    ];
}

/**
 * FIX-05 (resolved): profiles.car_limit is added by migration
 * 0010_add_profile_car_limit.sql. p.car_limit is selected below so the
 * admin table reflects the value an admin actually set via the
 * "set_car_limit" action in app/admin/users.php, instead of always
 * falling back to the platform default (which is what happened while
 * the column was missing from both the schema and this SELECT).
 */
function getUsersForAdmin(
    ?string $role   = null,
    ?string $status = null,
    ?string $search = null,
    int $limit      = 50,
    int $offset     = 0
): array {
    $pdo    = Database::getInstance();
    $where  = ['1=1'];
    $params = [];

    if ($role) {
        $where[]  = 'u.role = ?';
        $params[] = $role;
    }
    if ($status) {
        $where[]  = 'u.status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where[]  = 'u.email LIKE ?';
        $params[] = '%' . $search . '%';
    }

    $whereClause = implode(' AND ', $where);
    $params[]    = $limit;
    $params[]    = $offset;

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.uuid,
            u.email,
            u.role,
            u.status,
            u.email_verified,
            u.last_login,
            u.created_at,
            p.first_name,
            p.last_name,
            p.onboarding_completed,
            p.car_limit,
            d.company_name        AS dealer_company,
            d.verification_status AS dealer_verification,
            (SELECT COUNT(*) FROM organization_members om WHERE om.user_id = u.id) AS org_count
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        LEFT JOIN dealers  d ON d.user_id = u.id
        WHERE {$whereClause}
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}
