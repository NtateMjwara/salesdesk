<?php
/**
 * SalesDesk — Money controls  (0016, dealer workspace Phase 2)
 *
 *   1. Every commission records WHO closed the deal that created it
 *      (commissions.closed_by_user_id) and HOW (closed_via):
 *        principal — the dealer's own principal
 *        staff     — a SalesDesk admin operating the dealership
 *   2. FOUR-EYES: whoever closed a deal can never move its commission
 *      towards money leaving the platform — approved, scheduled,
 *      processing or paid — not even a superadmin. Recording a bounced
 *      EFT (→ failed) stays allowed; it moves no money.
 *      Enforced twice: payouts.php refuses up front with a clear
 *      message, and transitionCommissionStatus() refuses regardless of
 *      caller (defence in depth).
 *   3. Staff-closed deals are labelled "Closed by SalesDesk" wherever
 *      commissions are shown to admins and to the dealership.
 *
 * Before 0016 has run, nothing is recorded and the rule can't be
 * checked — sdMoneySchemaReady() is false and every helper is a no-op.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/** Commission statuses the closer may never move a commission INTO. */
const SD_FOUR_EYES_STATUSES = ['approved', 'scheduled', 'processing', 'paid'];

function sdMoneySchemaReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) Database::getInstance()->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'closed_by_user_id'
        ")->fetchColumn();
        if (!$ready) {
            error_log('[SalesDesk] Four-eyes payouts are off: run db/0016_money_controls.sql');
        }
    }
    return $ready;
}

/**
 * How the current request is closing a deal: the actor and whether it is
 * the principal or SalesDesk staff working in a dealer workspace.
 *
 * @return array{0:int,1:string} [closed_by_user_id, closed_via]
 */
function sdCurrentCloser(): array
{
    $actor = (int) ($_SESSION['user_id'] ?? 0);
    $staff = function_exists('sdInStaffWorkspace') && sdInStaffWorkspace();
    return [$actor, $staff ? 'staff' : 'principal'];
}

/**
 * Who closed the deal behind a commission.
 * @return array{closed_by_user_id:?int, closed_via:?string, closer_name:string}|null
 */
function sdCommissionCloser(int $commissionId): ?array
{
    if (!sdMoneySchemaReady()) {
        return null;
    }
    $stmt = Database::getInstance()->prepare("
        SELECT c.closed_by_user_id, c.closed_via, u.email, p.first_name, p.last_name
        FROM commissions c
        LEFT JOIN users u    ON u.id = c.closed_by_user_id
        LEFT JOIN profiles p ON p.user_id = c.closed_by_user_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$commissionId]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: (string) ($r['email'] ?? '');
    return [
        'closed_by_user_id' => $r['closed_by_user_id'] !== null ? (int) $r['closed_by_user_id'] : null,
        'closed_via'        => $r['closed_via'],
        'closer_name'       => $name,
    ];
}

/**
 * Null when $actorId may move commission $commissionId to $newStatus,
 * otherwise a human-readable reason.
 */
function sdFourEyesViolation(int $commissionId, int $actorId, string $newStatus = 'approved'): ?string
{
    if (!in_array($newStatus, SD_FOUR_EYES_STATUSES, true) || $actorId <= 0) {
        return null;
    }
    $closer = sdCommissionCloser($commissionId);
    if (!$closer || $closer['closed_by_user_id'] === null || $closer['closed_by_user_id'] !== $actorId) {
        return null;
    }
    return 'You closed this deal, so another superadmin has to approve and pay out its commission (four-eyes rule).';
}

/**
 * SQL fragments to add closer info to a commissions query (alias c).
 * @return array{0:string,1:string} [select columns, joins]
 */
function sdCloserSql(string $alias = 'c'): array
{
    if (!sdMoneySchemaReady()) {
        return ["NULL AS closed_by_user_id, NULL AS closed_via, NULL AS closer_first, NULL AS closer_last, NULL AS closer_email", ''];
    }
    return [
        "{$alias}.closed_by_user_id, {$alias}.closed_via, sd_cp.first_name AS closer_first, sd_cp.last_name AS closer_last, sd_cu.email AS closer_email",
        "LEFT JOIN users sd_cu ON sd_cu.id = {$alias}.closed_by_user_id
         LEFT JOIN profiles sd_cp ON sd_cp.user_id = {$alias}.closed_by_user_id",
    ];
}

/**
 * Small label for a commission row (needs the columns from sdCloserSql()).
 * $viewerId: when it's the closer, say "you" and flag the four-eyes rule.
 */
function sdClosedByBadge(array $row, int $viewerId = 0): string
{
    if (($row['closed_via'] ?? null) === null) {
        return '';
    }
    $name  = trim(($row['closer_first'] ?? '') . ' ' . ($row['closer_last'] ?? '')) ?: (string) ($row['closer_email'] ?? '');
    $isYou = $viewerId > 0 && (int) ($row['closed_by_user_id'] ?? 0) === $viewerId;
    $who   = $isYou ? 'you' : $name;

    if ($row['closed_via'] === 'staff') {
        return '<span class="badge badge-closed-staff" title="Closed by SalesDesk staff in a dealer workspace">'
             . '<i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Closed by SalesDesk'
             . ($who !== '' ? ' · ' . htmlspecialchars($who) : '') . '</span>';
    }
    return $isYou
        ? '<span class="badge badge-closed-principal">Closed by you</span>'
        : '<span class="adm-sub">Closed by ' . htmlspecialchars($who !== '' ? $who : 'dealer') . '</span>';
}
