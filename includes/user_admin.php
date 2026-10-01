<?php
/**
 * SalesDesk — User management helpers  (0019)
 *
 * Used by:
 *   app/admin/users.php       — list, filter, bulk actions, CSV export
 *   app/admin/users-view.php  — one user: overview, edit, account, activity
 *
 * WHO CAN DO WHAT
 *   Admin       — only users connected to their dealerships / orgs
 *                 (adminCanActOnUser): view, suspend / reactivate,
 *                 broker car limit. Same as before 0019.
 *   Superadmin  — every non-admin user, plus everything in this file:
 *                 activate, verify / un-verify email, edit profile and
 *                 address, change email (step-up), password reset code,
 *                 resend verification code, clear login lockout, finish
 *                 onboarding, bulk activate / verify, CSV export (step-up).
 *
 * Admin accounts are never touched here — see app/admin/admins-view.
 * Every state change is written to audit_logs. No migration needed:
 * everything runs on existing columns.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/admin_scope.php';   // also loads superadmin.php

const UA_ROLES      = ['broker', 'dealer', 'sales_exec'];
const UA_STATUSES   = ['pending', 'active', 'suspended'];
const UA_SORTS      = [
    'newest'     => 'u.created_at DESC',
    'oldest'     => 'u.created_at ASC',
    'last_login' => 'u.last_login IS NULL, u.last_login DESC',
    'email'      => 'u.email ASC',
];
const UA_PAGE_SIZE  = 50;
const UA_BULK_MAX   = 100;
const UA_EXPORT_MAX = 5000;


// ============================================================
// FILTERS + LIST
// ============================================================

/** Normalise the list filters from a query string. */
function uaFiltersFromRequest(array $in): array
{
    $s = static fn(string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';
    return [
        'q'          => mb_substr(trim($s('q')), 0, 120),
        'role'       => in_array($s('role'), UA_ROLES, true) ? $s('role') : '',
        'status'     => in_array($s('status'), UA_STATUSES, true) ? $s('status') : '',
        'verified'   => in_array($s('verified'), ['yes', 'no'], true) ? $s('verified') : '',
        'onboarding' => in_array($s('onboarding'), ['complete', 'incomplete'], true) ? $s('onboarding') : '',
        'sort'       => array_key_exists($s('sort'), UA_SORTS) ? $s('sort') : 'newest',
    ];
}

/** Query-string for the current filters (used by pagination / export links). */
function uaFilterQuery(array $f, array $extra = []): string
{
    $q = array_filter(array_merge($f, $extra), fn($v) => $v !== '' && $v !== null);
    if (($q['sort'] ?? '') === 'newest') {
        unset($q['sort']);
    }
    // RFC 3986 (%20, not +) so the result passes sdSafeAdminPath() as a return_to.
    return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/** @return array{0:string,1:array} WHERE clause + params for the list. */
function uaWhere(int $adminId, array $f): array
{
    [$scope, $params] = adminUserScopeSql($adminId, 'u');
    $where = [$scope];

    if ($f['role'] !== '') {
        $where[]  = 'u.role = ?';
        $params[] = $f['role'];
    }
    if ($f['status'] !== '') {
        $where[]  = 'u.status = ?';
        $params[] = $f['status'];
    }
    if ($f['verified'] !== '') {
        $where[]  = 'u.email_verified = ?';
        $params[] = $f['verified'] === 'yes' ? 1 : 0;
    }
    if ($f['onboarding'] === 'complete') {
        $where[] = 'p.onboarding_completed = 1';
    } elseif ($f['onboarding'] === 'incomplete') {
        $where[] = '(p.onboarding_completed IS NULL OR p.onboarding_completed = 0)';
    }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        if (ctype_digit($f['q'])) {
            $where[]  = '(u.id = ? OR u.email LIKE ? OR p.phone LIKE ?)';
            array_push($params, (int) $f['q'], $like, $like);
        } else {
            $where[] = "(u.email LIKE ? OR p.phone LIKE ? OR d.company_name LIKE ?
                      OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }
    }
    return [implode(' AND ', $where), $params];
}

const UA_LIST_FROM = "
    FROM users u
    LEFT JOIN profiles p          ON p.user_id = u.id
    LEFT JOIN dealers  d          ON d.user_id = u.id
    LEFT JOIN sales_executives se ON se.user_id = u.id
    LEFT JOIN dealers  sed        ON sed.id = se.dealer_id
    LEFT JOIN organization_members om ON om.user_id = u.id
    LEFT JOIN organizations o     ON o.id = om.organization_id
";

function uaCountUsers(int $adminId, array $f): int
{
    [$where, $params] = uaWhere($adminId, $f);
    $stmt = Database::getInstance()->prepare("SELECT COUNT(*) " . UA_LIST_FROM . " WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function uaListUsers(int $adminId, array $f, int $limit = UA_PAGE_SIZE, int $offset = 0): array
{
    [$where, $params] = uaWhere($adminId, $f);
    $order = UA_SORTS[$f['sort']] ?? UA_SORTS['newest'];
    $stmt  = Database::getInstance()->prepare("
        SELECT u.id, u.email, u.role, u.status, u.email_verified, u.last_login, u.created_at,
               (u.password_hash <> '') AS has_password,
               p.first_name, p.last_name, p.phone, p.onboarding_completed, p.car_limit,
               d.id AS dealer_id, d.company_name AS dealer_company, d.verification_status AS dealer_verification,
               sed.company_name AS exec_dealer, se.verification_status AS exec_status,
               o.name AS org_name, om.status AS org_status
        " . UA_LIST_FROM . "
        WHERE {$where}
        ORDER BY {$order}, u.id DESC
        LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset)
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Headline counts for the stat strip (scoped like the list). */
function uaStats(int $adminId): array
{
    [$scope, $params] = adminUserScopeSql($adminId, 'u');
    $stmt = Database::getInstance()->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(u.status = 'pending'), 0)   AS pending,
               COALESCE(SUM(u.status = 'suspended'), 0) AS suspended,
               COALESCE(SUM(u.email_verified = 0), 0)   AS unverified,
               COALESCE(SUM(u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)), 0) AS new_week
        FROM users u
        WHERE {$scope}
    ");
    $stmt->execute($params);
    return array_map('intval', $stmt->fetch() ?: []);
}

/** Display name: dealership, else person, else ''. */
function uaDisplayName(array $u): string
{
    if (!empty($u['dealer_company'])) {
        return (string) $u['dealer_company'];
    }
    return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
}

function uaRoleLabel(string $role): string
{
    return match ($role) {
        'sales_exec' => 'Sales exec',
        default      => ucfirst($role),
    };
}

function uaRoleBadgeClass(string $role): string
{
    return match ($role) {
        'broker'     => 'badge-new',
        'dealer'     => 'badge-pending',
        'sales_exec' => 'badge-role-exec',
        default      => '',
    };
}


// ============================================================
// ONE USER
// ============================================================

/** Full record for the view page (never admins), or false. */
function uaGetUser(int $userId): array|false
{
    $stmt = Database::getInstance()->prepare("
        SELECT u.id, u.uuid, u.email, u.role, u.status, u.email_verified, u.last_login,
               u.created_at, u.updated_at, (u.password_hash <> '') AS has_password,
               p.first_name, p.last_name, p.phone, p.bio, p.avatar_url, p.address_id,
               p.onboarding_step, p.onboarding_completed, p.car_limit,
               a.province, a.city, a.suburb, a.street_line1, a.postal_code,
               d.id AS dealer_id, d.company_name AS dealer_company, d.slug AS dealer_slug,
               d.verification_status AS dealer_verification, d.is_active AS dealer_active,
               se.id AS exec_id, se.dealer_id AS exec_dealer_id, se.job_title AS exec_job_title,
               se.verification_status AS exec_status, sed.company_name AS exec_dealer,
               om.organization_id AS org_id, om.status AS org_status, o.name AS org_name,
               sd.id AS desk_id, sd.display_name AS desk_name, sd.slug AS desk_slug, sd.is_active AS desk_active
        FROM users u
        LEFT JOIN profiles p          ON p.user_id = u.id
        LEFT JOIN addresses a         ON a.id = p.address_id
        LEFT JOIN dealers  d          ON d.user_id = u.id
        LEFT JOIN sales_executives se ON se.user_id = u.id
        LEFT JOIN dealers  sed        ON sed.id = se.dealer_id
        LEFT JOIN organization_members om ON om.user_id = u.id
        LEFT JOIN organizations o     ON o.id = om.organization_id
        LEFT JOIN salesdesks sd       ON sd.user_id = u.id
        WHERE u.id = ? AND u.role <> 'admin'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/** Role-specific activity numbers for the overview tab. */
function uaUserNumbers(array $u): array
{
    $pdo = Database::getInstance();
    $one = function (string $sql, array $p) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn();
    };
    $id = (int) $u['id'];
    $n  = [];

    if ($u['role'] === 'broker') {
        $n['Cars on desk']     = (int) $one("SELECT COUNT(*) FROM broker_inventory bi JOIN salesdesks s ON s.id = bi.salesdesk_id WHERE s.user_id = ?", [$id]);
        $n['Leads']            = (int) $one("SELECT COUNT(*) FROM leads WHERE broker_id = ?", [$id]);
        $n['Deals closed']     = (int) $one("SELECT COUNT(*) FROM leads WHERE broker_id = ? AND status = 'closed'", [$id]);
        $n['Commission paid']  = formatZAR((float) $one("SELECT COALESCE(SUM(net_amount),0) FROM commissions WHERE broker_id = ? AND status = 'paid'", [$id]));
        $n['Commission owed']  = formatZAR((float) $one("SELECT COALESCE(SUM(net_amount),0) FROM commissions WHERE broker_id = ? AND status IN ('pending','approved','scheduled','processing')", [$id]));
    } elseif ($u['role'] === 'dealer' && $u['dealer_id']) {
        $did = (int) $u['dealer_id'];
        $n['Live cars']        = (int) $one("SELECT COUNT(*) FROM cars WHERE dealer_id = ? AND status = 'active'", [$did]);
        $n['Paused cars']      = (int) $one("SELECT COUNT(*) FROM cars WHERE dealer_id = ? AND status = 'paused'", [$did]);
        $n['Sales execs']      = (int) $one("SELECT COUNT(*) FROM sales_executives WHERE dealer_id = ? AND verification_status = 'verified'", [$did]);
        $n['Leads']            = (int) $one("SELECT COUNT(*) FROM leads WHERE dealer_id = ?", [$did]);
        $n['Commission owed']  = formatZAR((float) $one("SELECT COALESCE(SUM(gross_amount),0) FROM commissions WHERE dealer_id = ? AND status IN ('pending','approved')", [$did]));
    } elseif ($u['role'] === 'sales_exec' && $u['exec_id']) {
        $n['Cars uploaded']    = (int) $one("SELECT COUNT(*) FROM cars WHERE uploaded_by_exec_id = ?", [(int) $u['exec_id']]);
        $n['Live cars']        = (int) $one("SELECT COUNT(*) FROM cars WHERE uploaded_by_exec_id = ? AND status = 'active'", [(int) $u['exec_id']]);
    }
    return $n;
}

/** Audit entries about this user (or their dealership) and by them. */
function uaUserActivity(array $u, int $limit = 40): array
{
    $params = [(int) $u['id'], (int) $u['id']];
    $dealer = '';
    if (!empty($u['dealer_id'])) {
        $dealer   = " OR (al.entity_type = 'dealer' AND al.entity_id = ?)";
        $params[] = (int) $u['dealer_id'];
    }
    $stmt = Database::getInstance()->prepare("
        SELECT al.id, al.action, al.entity_type, al.entity_id, al.after_data, al.ip_address, al.created_at,
               al.actor_id, act.email AS actor_email
        FROM audit_logs al
        LEFT JOIN users act ON act.id = al.actor_id
        WHERE (al.entity_type = 'user' AND al.entity_id = ?) OR al.actor_id = ?{$dealer}
        ORDER BY al.id DESC
        LIMIT " . max(1, $limit)
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Failed sign-ins in the current rate-limit window (for "clear lockout"). */
function uaFailedLogins(string $email): int
{
    $stmt = Database::getInstance()->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE identifier = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$email, RATE_LIMIT_WINDOW]);
    return (int) $stmt->fetchColumn();
}


// ============================================================
// ACTIONS  (each returns [ok, message]; all audited)
// ============================================================

/** pending → active. Leaves suspended users to the suspend/reactivate flow. */
function uaActivate(int $userId, int $actorId, bool $alsoVerify = false): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    if ($u['status'] === 'suspended') {
        return [false, $u['email'] . ' is suspended — use Reactivate instead.'];
    }
    $verify = $alsoVerify && !(int) $u['email_verified'];
    if ($u['status'] === 'active' && !$verify) {
        return [true, $u['email'] . ' is already active.'];
    }
    Database::getInstance()->prepare("
        UPDATE users SET status = 'active'" . ($verify ? ', email_verified = 1' : '') . ", updated_at = NOW() WHERE id = ?
    ")->execute([$userId]);
    writeAuditLog('user.activated', 'user', $userId,
        ['status' => $u['status'], 'email_verified' => (int) $u['email_verified']],
        ['status' => 'active', 'email_verified' => $verify ? 1 : (int) $u['email_verified']], $actorId);

    $note = (int) $u['has_password'] ? '' : ' They have no password yet — send them a password reset code.';
    return [true, $u['email'] . ' is active' . ($verify ? ' and verified.' : '.') . $note];
}

/** Mark the email verified (also activates a pending account) or un-verified. */
function uaSetEmailVerified(int $userId, bool $verified, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    if ((int) $u['email_verified'] === ($verified ? 1 : 0)) {
        return [true, $u['email'] . ($verified ? ' is already verified.' : ' is already unverified.')];
    }
    // Verifying a pending signup is what the OTP step does: verified + active.
    $activate = $verified && $u['status'] === 'pending';
    Database::getInstance()->prepare("
        UPDATE users SET email_verified = ?" . ($activate ? ", status = 'active'" : '') . ", updated_at = NOW() WHERE id = ?
    ")->execute([$verified ? 1 : 0, $userId]);
    writeAuditLog($verified ? 'user.email_verified_by_admin' : 'user.email_unverified_by_admin', 'user', $userId,
        ['email_verified' => (int) $u['email_verified'], 'status' => $u['status']],
        ['email_verified' => $verified ? 1 : 0, 'status' => $activate ? 'active' : $u['status']], $actorId);

    return [true, $verified
        ? $u['email'] . ' is verified' . ($activate ? ' and active.' : '.')
        : $u['email'] . ' is unverified — they’ll be asked for a code at their next sign-in.'];
}

/** Suspend / reactivate, with the dealer cascade for principals. */
function uaSetStatus(int $userId, string $status, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    if ($userId === $actorId) {
        return [false, 'You can’t change your own account here.'];
    }
    if ($status === 'suspended') {
        if ($u['status'] === 'suspended') {
            return [true, $u['email'] . ' is already suspended.'];
        }
        if ($u['role'] === 'dealer' && $u['dealer_id']) {
            $r = suspendDealer((int) $u['dealer_id'], $actorId);
            return [true, "Dealer suspended. {$r['cars_paused']} listing(s) paused, {$r['commissions_frozen']} commission(s) frozen."];
        }
        Database::getInstance()->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?")->execute([$userId]);
        writeAuditLog('user.suspended', 'user', $userId, ['status' => $u['status']], ['status' => 'suspended'], $actorId);
        return [true, $u['email'] . ' is suspended. They’re signed out when their current session ends.'];
    }

    if ($u['status'] !== 'suspended') {
        return [true, $u['email'] . ' isn’t suspended.'];
    }
    if ($u['role'] === 'dealer' && $u['dealer_id']) {
        $r = reinstateDealer((int) $u['dealer_id'], $actorId);
        return [true, "Dealer reinstated. {$r['cars_reinstated']} listing(s) restored, {$r['commissions_unfrozen']} commission(s) unfrozen."];
    }
    Database::getInstance()->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$userId]);
    writeAuditLog('user.reactivated', 'user', $userId, ['status' => 'suspended'], ['status' => 'active'], $actorId);
    return [true, $u['email'] . ' is reactivated.'];
}

function uaSetCarLimit(int $userId, int $limit, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u || $u['role'] !== 'broker') {
        return [false, 'Car limits only apply to brokers.'];
    }
    if ($limit < 1 || $limit > 200) {
        return [false, 'Car limit must be between 1 and 200.'];
    }
    Database::getInstance()->prepare("UPDATE profiles SET car_limit = ?, updated_at = NOW() WHERE user_id = ?")
        ->execute([$limit, $userId]);
    writeAuditLog('user.car_limit_set', 'user', $userId, ['car_limit' => $u['car_limit']], ['car_limit' => $limit], $actorId);
    return [true, "Car limit updated to {$limit}."];
}

/** Superadmin: edit name, phone, bio, address. */
function uaUpdateProfile(int $userId, array $in, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    $first = mb_substr(trim((string) ($in['first_name'] ?? '')), 0, 60);
    $last  = mb_substr(trim((string) ($in['last_name'] ?? '')), 0, 60);
    $phone = mb_substr(preg_replace('/[^0-9+ ()\-]/', '', (string) ($in['phone'] ?? '')), 0, 30);
    $bio   = mb_substr(trim((string) ($in['bio'] ?? '')), 0, 2000);
    if ($u['role'] !== 'dealer' && $first === '') {
        return [false, 'First name can’t be empty.'];
    }

    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    try {
        $addressId = adminSaveAddress($u['address_id'] ? (int) $u['address_id'] : null, $in);
        $pdo->prepare("
            INSERT INTO profiles (user_id, first_name, last_name, phone, bio, address_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name),
                phone = VALUES(phone), bio = VALUES(bio), address_id = VALUES(address_id), updated_at = NOW()
        ")->execute([$userId, $first ?: null, $last ?: null, $phone ?: null, $bio ?: null, $addressId]);

        writeAuditLog('user.profile_edited_by_admin', 'user', $userId,
            ['first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'phone' => $u['phone'],
             'city' => $u['city'], 'province' => $u['province']],
            ['first_name' => $first, 'last_name' => $last, 'phone' => $phone,
             'city' => trim((string) ($in['city'] ?? '')), 'province' => (string) ($in['province'] ?? '')], $actorId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk uaUpdateProfile] ' . $e->getMessage());
        return [false, 'Could not save the profile. Please try again.'];
    }
    return [true, 'Profile saved.'];
}

/**
 * Superadmin (step-up): change the sign-in email. The old address is
 * told about it. Unless the superadmin confirms the new address is
 * theirs, it's marked unverified and they confirm it with a code at
 * their next sign-in.
 */
function uaChangeEmail(int $userId, string $newEmail, bool $keepVerified, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    $newEmail = strtolower(trim($newEmail));
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 255) {
        return [false, 'Please enter a valid email address.'];
    }
    if ($newEmail === strtolower((string) $u['email'])) {
        return [true, 'That’s already their email address.'];
    }
    $taken = getUserByEmail($newEmail);
    if ($taken) {
        return [false, $newEmail . ' already belongs to another account.'];
    }

    Database::getInstance()->prepare("
        UPDATE users SET email = ?, email_verified = ?, updated_at = NOW() WHERE id = ?
    ")->execute([$newEmail, $keepVerified ? (int) $u['email_verified'] : 0, $userId]);
    writeAuditLog('user.email_changed_by_admin', 'user', $userId,
        ['email' => $u['email'], 'email_verified' => (int) $u['email_verified']],
        ['email' => $newEmail, 'email_verified' => $keepVerified ? (int) $u['email_verified'] : 0], $actorId);

    $old  = htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8');
    $new  = htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8');
    sendEmail((string) $u['email'], 'Your SalesDesk sign-in email was changed', <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">Your sign-in email changed</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 16px;">
  A SalesDesk administrator changed the email on your account from <strong>{$old}</strong> to <strong>{$new}</strong>.
  From now on, sign in with the new address.
</p>
<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
  If you didn't ask for this, reply to this email straight away.
</p>
HTML);

    return [true, 'Email changed to ' . $newEmail . ($keepVerified ? '.' : ' — they’ll confirm it with a code at their next sign-in.')];
}

/**
 * Superadmin: email the user a password-reset code with a link straight
 * to the code-entry step. Works for accounts that never set a password.
 */
function uaSendPasswordReset(int $userId, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    if ($u['status'] !== 'active') {
        return [false, 'Activate the account first — password reset only works for active accounts.'];
    }
    $code   = generateAndStoreOTP($userId, 'password_reset');
    $link   = SITE_URL . '/auth/reset_password.php?mode=verify&uid=' . $userId . '&email=' . urlencode((string) $u['email']);
    $expMin = (int) (OTP_EXPIRY_SECONDS / 60);
    $safe   = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $sent   = sendEmail((string) $u['email'], 'Set your SalesDesk password: ' . $code, <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">Set a new password</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 24px;">
  The SalesDesk team sent you this so you can set a new password. Open the link and enter the code.
</p>
<div style="background:#eff4ff;border:1px solid #dbeafe;border-radius:12px;padding:24px;text-align:center;margin:0 0 24px;">
  <p style="font-size:40px;font-weight:800;letter-spacing:.25em;color:#0f4c9e;font-family:monospace;margin:0;">{$code}</p>
  <p style="font-size:12px;color:#94a3b8;margin:8px 0 0;">Expires in {$expMin} minutes — the page can send you a new one</p>
</div>
<a href="{$safe}" style="display:inline-block;background:#0f4c9e;color:#fff;font-size:14px;font-weight:600;
   padding:12px 24px;border-radius:8px;text-decoration:none;">Set my password</a>
<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:24px 0 0;">
  If you weren't expecting this, you can ignore it — your password hasn't changed.
</p>
HTML);
    writeAuditLog('user.password_reset_sent_by_admin', 'user', $userId, null, ['sent' => $sent], $actorId);
    return $sent
        ? [true, 'Password reset code emailed to ' . $u['email'] . '.']
        : [false, 'The email could not be sent. Check the SMTP settings in config.php.'];
}

/** Superadmin: resend the signup verification code. */
function uaResendVerification(int $userId, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    if ((int) $u['email_verified']) {
        return [true, $u['email'] . ' is already verified.'];
    }
    $sent = sendVerificationOTP((string) $u['email'], generateAndStoreOTP($userId, 'email_verify'));
    writeAuditLog('user.verification_resent_by_admin', 'user', $userId, null, ['sent' => $sent], $actorId);
    return $sent
        ? [true, 'Verification code emailed to ' . $u['email'] . '. They enter it by signing in or continuing signup.']
        : [false, 'The email could not be sent. Check the SMTP settings in config.php.'];
}

/** Superadmin: clear failed sign-in attempts for this email. */
function uaClearLockout(int $userId, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    $n = uaFailedLogins((string) $u['email']);
    clearFailedAttempts((string) $u['email']);
    writeAuditLog('user.lockout_cleared', 'user', $userId, ['failed_attempts' => $n], ['failed_attempts' => 0], $actorId);
    return [true, "Cleared {$n} failed sign-in attempt(s). Note: attempts from the same IP address still count."];
}

/** Superadmin: finish onboarding for someone stuck in the signup wizard. */
function uaCompleteOnboarding(int $userId, int $actorId): array
{
    $u = uaGetUser($userId);
    if (!$u) {
        return [false, 'User not found.'];
    }
    if ((int) $u['onboarding_completed']) {
        return [true, 'Onboarding is already complete.'];
    }
    if ($u['role'] === 'broker' && !$u['desk_id']) {
        return [false, 'This broker hasn’t created their SalesDesk yet — they need to finish that step themselves.'];
    }
    if ($u['role'] === 'dealer' && !$u['dealer_id']) {
        return [false, 'This dealer has no dealership record yet.'];
    }

    $pdo = Database::getInstance();
    $pdo->prepare("
        INSERT INTO profiles (user_id, onboarding_step, onboarding_completed, created_at, updated_at)
        VALUES (?, 99, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE onboarding_step = 99, onboarding_completed = 1, updated_at = NOW()
    ")->execute([$userId]);
    if ($u['role'] === 'broker') {
        $pdo->prepare("UPDATE salesdesks SET is_active = 1, updated_at = NOW() WHERE user_id = ?")->execute([$userId]);
    } elseif ($u['role'] === 'dealer') {
        $pdo->prepare("UPDATE dealers SET is_active = 1, updated_at = NOW() WHERE user_id = ?")->execute([$userId]);
    }
    writeAuditLog('user.onboarding_completed_by_admin', 'user', $userId,
        ['onboarding_step' => $u['onboarding_step']], ['onboarding_step' => 99, 'onboarding_completed' => 1], $actorId);

    return [true, 'Onboarding marked complete' . ((int) $u['has_password'] ? '.' : ' — they still need a password, so send a reset code.')];
}


// ============================================================
// BULK + EXPORT  (superadmin)
// ============================================================

/** @return array{0:bool,1:string} */
function uaBulk(string $action, array $ids, int $actorId): array
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0))), 0, UA_BULK_MAX);
    if (!$ids) {
        return [false, 'Tick at least one user first.'];
    }
    $done = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        if (!adminCanActOnUser($actorId, $id)) {
            $skipped++;
            continue;
        }
        [$ok] = match ($action) {
            'activate'        => uaActivate($id, $actorId),
            'activate_verify' => uaActivate($id, $actorId, true),
            'verify_email'    => uaSetEmailVerified($id, true, $actorId),
            default           => [false],
        };
        $ok ? $done++ : $skipped++;
    }
    return [$done > 0, "{$done} user(s) updated" . ($skipped ? ", {$skipped} skipped (suspended or out of scope)." : '.')];
}

/** Stream the filtered list as CSV. Caller has already checked step-up. */
function uaExportCsv(int $adminId, array $f): never
{
    $rows = uaListUsers($adminId, $f, UA_EXPORT_MAX, 0);
    writeAuditLog('users.exported', 'user', $adminId, null, ['rows' => count($rows), 'filters' => $f], $adminId);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salesdesk-users-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Email', 'Name', 'Phone', 'Role', 'Status', 'Email verified', 'Onboarded',
                   'Dealership / employer', 'Desk org', 'Joined', 'Last sign-in']);
    foreach ($rows as $r) {
        $cells = [
            $r['id'], $r['email'], uaDisplayName($r), $r['phone'], $r['role'], $r['status'],
            $r['email_verified'] ? 'yes' : 'no', $r['onboarding_completed'] ? 'yes' : 'no',
            $r['dealer_company'] ?: $r['exec_dealer'], $r['org_name'], $r['created_at'], $r['last_login'],
        ];
        // Neutralise spreadsheet formula injection.
        $cells = array_map(fn($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) ? "'" . $c : $c, $cells);
        fputcsv($out, $cells);
    }
    fclose($out);
    exit;
}
