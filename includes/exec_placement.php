<?php
/**
 * SalesDesk — Unplaced sales execs  (0018, dealer workspace Phase 4)
 *
 * UNPLACED = an active, email-verified sales_exec account that isn't
 * working at any dealership:
 *   • no sales_executives row — skipped choosing a dealership at signup
 *   • or its request was declined (verification_status = 'rejected')
 * Pending applications and verified / suspended execs are NOT unplaced.
 *
 * FLOW
 *   1. An admin who can run a dealership — Operate on a SalesDesk-run
 *      dealership, or delegated by a principal (Phase 3) — invites an
 *      unplaced exec from Admin → Unplaced execs.
 *   2. The exec gets an email + in-app notification, and sees the
 *      invitation on /app/exec/invitations (also linked from the "no
 *      dealership" / "declined" status screens).
 *   3. Accept → verified at that dealership in one step (the inviting
 *      admin is recorded as invited_by / verified_by). Any other open
 *      invitations and a pending application elsewhere are cancelled.
 *      Decline → the inviter is told.
 *   Invitations expire after SD_EXEC_INVITE_DAYS days; the inviter can
 *   withdraw one while it's pending.
 *
 * The exec always decides — nobody is placed at a dealership without
 * saying yes. Everything is audited.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

const SD_EXEC_INVITE_DAYS = 14;

function sdExecPlacementReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) Database::getInstance()->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exec_invitations'
        ")->fetchColumn();
        if (!$ready) {
            error_log('[SalesDesk] Exec invitations are off: run db/0018_exec_invitations.sql');
        }
    }
    return $ready;
}

/** SQL condition: user alias u is an unplaced sales exec (se = LEFT JOIN sales_executives). */
function sdUnplacedWhere(): string
{
    return "u.role = 'sales_exec' AND u.status = 'active' AND u.email_verified = 1
            AND (se.id IS NULL OR se.verification_status = 'rejected')";
}

/**
 * Unplaced execs, newest first, with how they got here and any open invites.
 * @param array{q?:string,province?:string} $f
 */
function sdUnplacedExecs(array $f = []): array
{
    $where  = [sdUnplacedWhere()];
    $params = [];
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $where[] = '(u.email LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)';
        array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
    }
    if (($prov = trim((string) ($f['province'] ?? ''))) !== '') {
        $where[]  = 'a.province = ?';
        $params[] = $prov;
    }
    $inv = sdExecPlacementReady()
        ? "(SELECT COUNT(*) FROM exec_invitations ei WHERE ei.exec_user_id = u.id AND ei.status = 'pending' AND ei.expires_at > NOW())"
        : '0';

    $stmt = Database::getInstance()->prepare("
        SELECT u.id AS user_id, u.email, u.created_at, u.last_login,
               p.first_name, p.last_name, p.phone,
               a.city, a.province,
               se.verification_status, se.rejection_reason, se.job_title,
               dd.company_name AS declined_by,
               {$inv} AS open_invites
        FROM users u
        LEFT JOIN sales_executives se ON se.user_id = u.id
        LEFT JOIN dealers dd          ON dd.id = se.dealer_id AND se.verification_status = 'rejected'
        LEFT JOIN profiles p          ON p.user_id = u.id
        LEFT JOIN addresses a         ON a.id = p.address_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY u.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Dealerships this admin may invite execs into (can_write there). */
function sdInviteDealerships(int $adminId): array
{
    if (!function_exists('sdWorkspaceChoices')) {
        return [];
    }
    return array_values(array_filter(sdWorkspaceChoices($adminId),
        static fn(array $c): bool => in_array($c['mode'], ['operator', 'delegate'], true)));
}

/** Is this user currently an unplaced exec? */
function sdIsUnplacedExec(int $userId): bool
{
    $s = Database::getInstance()->prepare("
        SELECT 1 FROM users u LEFT JOIN sales_executives se ON se.user_id = u.id
        WHERE u.id = ? AND " . sdUnplacedWhere() . " LIMIT 1
    ");
    $s->execute([$userId]);
    return (bool) $s->fetchColumn();
}

/**
 * Invite an unplaced exec to a dealership the admin can run.
 * @return array{0:bool,1:string}
 */
function sdInviteExec(int $execUserId, int $dealerId, int $adminId, string $message): array
{
    if (!sdExecPlacementReady()) {
        return [false, 'Run migration 0018 first (Admin → Migrations).'];
    }
    $ctx = function_exists('sdResolveDealerAccess') ? sdResolveDealerAccess($adminId, 'admin', $dealerId) : null;
    if (!$ctx || !$ctx['can_write']) {
        return [false, 'You can only invite execs to dealerships you run (Operate) or that a principal has let SalesDesk help with.'];
    }
    if (!$ctx['is_active']) {
        return [false, $ctx['company_name'] . ' is offline.'];
    }
    if (!sdIsUnplacedExec($execUserId)) {
        return [false, 'That sales exec already has a dealership (or an application waiting).'];
    }

    $pdo = Database::getInstance();
    $dup = $pdo->prepare("
        SELECT 1 FROM exec_invitations
        WHERE exec_user_id = ? AND dealer_id = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1
    ");
    $dup->execute([$execUserId, $dealerId]);
    if ($dup->fetchColumn()) {
        return [false, 'They already have an open invitation to ' . $ctx['company_name'] . '.'];
    }

    $message = mb_substr(trim($message), 0, 255);
    $pdo->prepare("
        INSERT INTO exec_invitations (exec_user_id, dealer_id, invited_by_user_id, message, status, expires_at, created_at)
        VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? DAY), NOW())
    ")->execute([$execUserId, $dealerId, $adminId, $message !== '' ? $message : null, SD_EXEC_INVITE_DAYS]);
    $invId = (int) $pdo->lastInsertId();
    writeAuditLog('exec_invitation.sent', 'dealer', $dealerId, null,
        ['invitation_id' => $invId, 'exec_user_id' => $execUserId], $adminId);

    $exec = $pdo->prepare("SELECT u.email, p.first_name FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?");
    $exec->execute([$execUserId]);
    $e = $exec->fetch() ?: ['email' => '', 'first_name' => ''];

    if (function_exists('sdNotify')) {
        sdNotify($execUserId, 'exec_invitation', 'Invitation to join ' . $ctx['company_name'],
            $ctx['company_name'] . ' would like you on their sales team. Open your invitations to accept or decline.',
            ['invitation_id' => $invId, 'dealer_id' => $dealerId]);
    }
    if (function_exists('sendExecInvitation') && $e['email']) {
        sendExecInvitation($e['email'], (string) ($e['first_name'] ?? ''), $ctx['company_name'], $message, SD_EXEC_INVITE_DAYS);
    }
    return [true, 'Invitation sent to ' . ($e['first_name'] ?: $e['email']) . ' for ' . $ctx['company_name'] . '.'];
}

/** Open invitations for an exec (pending and not expired). */
function sdExecInvitations(int $execUserId): array
{
    if (!sdExecPlacementReady()) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT ei.*, d.company_name, d.brand_focus, a.city, a.province,
               ip.first_name AS inviter_first, ip.last_name AS inviter_last
        FROM exec_invitations ei
        JOIN dealers d        ON d.id = ei.dealer_id AND d.is_active = 1
        LEFT JOIN addresses a ON a.id = d.address_id
        LEFT JOIN profiles ip ON ip.user_id = ei.invited_by_user_id
        WHERE ei.exec_user_id = ? AND ei.status = 'pending' AND ei.expires_at > NOW()
        ORDER BY ei.id DESC
    ");
    $s->execute([$execUserId]);
    return $s->fetchAll();
}

/** Load one open invitation belonging to this exec. */
function sdOpenInvitation(int $invId, int $execUserId): ?array
{
    if (!sdExecPlacementReady()) {
        return null;
    }
    $s = Database::getInstance()->prepare("
        SELECT ei.*, d.company_name FROM exec_invitations ei
        JOIN dealers d ON d.id = ei.dealer_id AND d.is_active = 1
        WHERE ei.id = ? AND ei.exec_user_id = ? AND ei.status = 'pending' AND ei.expires_at > NOW()
        LIMIT 1
    ");
    $s->execute([$invId, $execUserId]);
    return $s->fetch() ?: null;
}

/**
 * Exec accepts: verified at that dealership now.
 * @return array{0:bool,1:string}
 */
function sdAcceptExecInvitation(int $invId, int $execUserId): array
{
    $inv = sdOpenInvitation($invId, $execUserId);
    if (!$inv) {
        return [false, 'That invitation has expired or was withdrawn.'];
    }
    $pdo = Database::getInstance();
    $cur = $pdo->prepare("SELECT verification_status FROM sales_executives WHERE user_id = ? LIMIT 1");
    $cur->execute([$execUserId]);
    $status = $cur->fetchColumn();
    if (in_array($status, ['verified', 'suspended'], true)) {
        return [false, 'You’re already linked to a dealership. Ask SalesDesk if you want to move.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO sales_executives
                (user_id, dealer_id, verification_status, invited_by, verified_by, verified_at, rejection_reason, created_at, updated_at)
            VALUES (?, ?, 'verified', ?, ?, NOW(), NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                dealer_id           = VALUES(dealer_id),
                verification_status = 'verified',
                invited_by          = VALUES(invited_by),
                verified_by         = VALUES(verified_by),
                verified_at         = NOW(),
                rejection_reason    = NULL,
                updated_at          = NOW()
        ")->execute([$execUserId, (int) $inv['dealer_id'], $inv['invited_by_user_id'], $inv['invited_by_user_id']]);
        $pdo->prepare("UPDATE exec_invitations SET status = 'accepted', responded_at = NOW() WHERE id = ?")
            ->execute([$invId]);
        $pdo->prepare("
            UPDATE exec_invitations SET status = 'cancelled', responded_at = NOW()
            WHERE exec_user_id = ? AND status = 'pending' AND id <> ?
        ")->execute([$execUserId, $invId]);
        writeAuditLog('exec_invitation.accepted', 'dealer', (int) $inv['dealer_id'],
            ['previous_status' => $status ?: null],
            ['invitation_id' => $invId, 'exec_user_id' => $execUserId, 'verification_status' => 'verified'], $execUserId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk sdAcceptExecInvitation] ' . $e->getMessage());
        return [false, 'Could not accept the invitation. Please try again.'];
    }

    // Tell the inviter and the dealership's contacts.
    $who = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(CONCAT(IFNULL(p.first_name,''),' ',IFNULL(p.last_name,''))),''), u.email)
                          FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?");
    $who->execute([$execUserId]);
    $name = (string) $who->fetchColumn();
    $tell = array_map('intval', array_column(getDealerContacts((int) $inv['dealer_id']), 'user_id'));
    if ($inv['invited_by_user_id']) {
        $tell[] = (int) $inv['invited_by_user_id'];
    }
    foreach (array_unique($tell) as $uid) {
        sdNotify($uid, 'exec_joined', $name . ' joined ' . $inv['company_name'],
            $name . ' accepted the invitation and is now a verified sales exec at ' . $inv['company_name'] . '.',
            ['dealer_id' => (int) $inv['dealer_id'], 'exec_user_id' => $execUserId]);
    }
    return [true, 'Welcome to ' . $inv['company_name'] . '! You can start listing cars now.'];
}

/** @return array{0:bool,1:string} */
function sdDeclineExecInvitation(int $invId, int $execUserId): array
{
    $inv = sdOpenInvitation($invId, $execUserId);
    if (!$inv) {
        return [false, 'That invitation has expired or was withdrawn.'];
    }
    Database::getInstance()->prepare("UPDATE exec_invitations SET status = 'declined', responded_at = NOW() WHERE id = ?")
        ->execute([$invId]);
    writeAuditLog('exec_invitation.declined', 'dealer', (int) $inv['dealer_id'], null,
        ['invitation_id' => $invId, 'exec_user_id' => $execUserId], $execUserId);
    if ($inv['invited_by_user_id']) {
        sdNotify((int) $inv['invited_by_user_id'], 'exec_declined', 'Invitation declined',
            'A sales exec declined your invitation to ' . $inv['company_name'] . '.', ['invitation_id' => $invId]);
    }
    return [true, 'Invitation declined.'];
}

/** Inviter (or a superadmin) withdraws a pending invitation. @return array{0:bool,1:string} */
function sdCancelExecInvitation(int $invId, int $adminId): array
{
    if (!sdExecPlacementReady()) {
        return [false, 'Nothing to withdraw.'];
    }
    $pdo = Database::getInstance();
    $s = $pdo->prepare("SELECT * FROM exec_invitations WHERE id = ? AND status = 'pending' LIMIT 1");
    $s->execute([$invId]);
    $inv = $s->fetch();
    if (!$inv) {
        return [false, 'That invitation isn’t open any more.'];
    }
    if ((int) $inv['invited_by_user_id'] !== $adminId && !isSuperadmin($adminId)) {
        return [false, 'Only the admin who sent it (or a superadmin) can withdraw it.'];
    }
    $pdo->prepare("UPDATE exec_invitations SET status = 'cancelled', responded_at = NOW() WHERE id = ?")->execute([$invId]);
    writeAuditLog('exec_invitation.cancelled', 'dealer', (int) $inv['dealer_id'], null, ['invitation_id' => $invId], $adminId);
    return [true, 'Invitation withdrawn.'];
}

/** Invitations this admin sent (superadmins: everyone's), newest first. */
function sdSentExecInvitations(int $adminId, int $limit = 50): array
{
    if (!sdExecPlacementReady()) {
        return [];
    }
    $mine = isSuperadmin($adminId) ? '' : 'WHERE ei.invited_by_user_id = ?';
    $s = Database::getInstance()->prepare("
        SELECT ei.*, d.company_name, u.email AS exec_email, p.first_name, p.last_name,
               ip.first_name AS inviter_first, ip.last_name AS inviter_last,
               IF(ei.status = 'pending' AND ei.expires_at <= NOW(), 1, 0) AS is_expired
        FROM exec_invitations ei
        JOIN dealers d        ON d.id = ei.dealer_id
        JOIN users u          ON u.id = ei.exec_user_id
        LEFT JOIN profiles p  ON p.user_id = ei.exec_user_id
        LEFT JOIN profiles ip ON ip.user_id = ei.invited_by_user_id
        {$mine}
        ORDER BY ei.id DESC
        LIMIT " . (int) $limit
    );
    $s->execute($mine ? [$adminId] : []);
    return $s->fetchAll();
}
