<?php
/**
 * SalesDesk — Principal-owned dealerships  (0017, dealer workspace Phase 3)
 *
 * The rule from Phase 1 stands: on a dealership with its own principal,
 * SalesDesk staff can only VIEW. Phase 3 adds the principal's controls and
 * SalesDesk's platform controls around that:
 *
 *   1. DELEGATION — the principal lets SalesDesk run the dealership for 7
 *      or 30 days (Settings → SalesDesk access). The dealership's managing
 *      admins and superadmins then work in it as "delegates": everything
 *      except company, verification and address settings. The principal
 *      can revoke at any time and sees every change SalesDesk makes.
 *
 *   2. DEAL CONFIRMATION — a deal closed by SalesDesk staff on such a
 *      dealership creates a commission marked 'pending' confirmation. No
 *      invoice is sent and it can't move towards payout until the principal
 *      confirms it. If they dispute it, the lead goes back to negotiation
 *      and the commission is removed (both audited, the closer is told).
 *
 *   3. ENFORCEMENT HOLDS — a superadmin can pull a listing for a platform
 *      reason (reason required, principal notified). The dealer can't
 *      resume it and imports can't reactivate it until it's released.
 *
 *   4. HANDOVER — a superadmin hands a SalesDesk-run dealership to its new
 *      principal (existing dealer account without a dealership, or a new
 *      invited one). Operators drop to View automatically (Phase 1 rule)
 *      and the principal gets a summary of what SalesDesk built.
 *
 * Before 0017 runs every helper degrades to "not available".
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

const SD_DELEGATION_DAYS = [7, 30];

function sdPhase3Ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $pdo   = Database::getInstance();
        $ready = (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealer_delegations'
        ")->fetchColumn() && (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'hold_reason'
        ")->fetchColumn() && (bool) $pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'dealer_confirmation'
        ")->fetchColumn();
        if (!$ready) {
            error_log('[SalesDesk] Delegation / deal confirmation / listing holds are off: run db/0017_principal_dealerships.sql');
        }
    }
    return $ready;
}

/** In-app notification (best effort — never breaks the caller). */
function sdNotify(int $userId, string $type, string $title, string $body, array $meta = []): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        Database::getInstance()->prepare("
            INSERT INTO notifications (user_id, type, title, body, meta, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$userId, $type, $title, $body, json_encode($meta)]);
    } catch (Throwable $e) {
        error_log('[SalesDesk sdNotify] ' . $e->getMessage());
    }
}

/** The dealership's principal user id (null when SalesDesk runs it). */
function sdDealerPrincipalId(int $dealerId): ?int
{
    $s = Database::getInstance()->prepare("SELECT user_id FROM dealers WHERE id = ? LIMIT 1");
    $s->execute([$dealerId]);
    $v = $s->fetchColumn();
    return $v ? (int) $v : null;
}

/** Active admins who manage a dealership. */
function sdDealerManagerIds(int $dealerId): array
{
    $s = Database::getInstance()->prepare("
        SELECT dm.admin_user_id FROM dealer_managers dm
        JOIN users u ON u.id = dm.admin_user_id AND u.role = 'admin' AND u.status = 'active'
        WHERE dm.dealer_id = ?
    ");
    $s->execute([$dealerId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}


// ============================================================
// 1. DELEGATION
// ============================================================

/** The active delegation for a dealership, or null. */
function sdActiveDelegation(int $dealerId): ?array
{
    if (!sdPhase3Ready()) {
        return null;
    }
    $s = Database::getInstance()->prepare("
        SELECT * FROM dealer_delegations
        WHERE dealer_id = ? AND revoked_at IS NULL AND starts_at <= NOW() AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $s->execute([$dealerId]);
    return $s->fetch() ?: null;
}

/**
 * Principal grants SalesDesk access for $days (7 or 30). Replaces any
 * active grant. @return array{0:bool,1:string}
 */
function sdGrantDelegation(int $dealerId, int $principalId, int $days, string $note): array
{
    if (!sdPhase3Ready()) {
        return [false, 'This isn’t switched on yet — please try again later.'];
    }
    if (!in_array($days, SD_DELEGATION_DAYS, true)) {
        return [false, 'Choose 7 or 30 days.'];
    }
    if (sdDealerPrincipalId($dealerId) !== $principalId) {
        return [false, 'Only the dealership’s principal can grant access.'];
    }
    $note = mb_substr(trim($note), 0, 255);
    $pdo  = Database::getInstance();

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE dealer_delegations SET revoked_at = NOW(), revoked_by_user_id = ?
            WHERE dealer_id = ? AND revoked_at IS NULL AND expires_at > NOW()
        ")->execute([$principalId, $dealerId]);
        $pdo->prepare("
            INSERT INTO dealer_delegations (dealer_id, granted_by_user_id, starts_at, expires_at, note, created_at)
            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?, NOW())
        ")->execute([$dealerId, $principalId, $days, $note !== '' ? $note : null]);
        $id = (int) $pdo->lastInsertId();
        writeAuditLog('delegation.granted', 'dealer', $dealerId, null,
            ['delegation_id' => $id, 'days' => $days, 'note' => $note ?: null], $principalId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk sdGrantDelegation] ' . $e->getMessage());
        return [false, 'Could not save that. Nothing was changed.'];
    }

    foreach (sdDealerManagerIds($dealerId) as $adminId) {
        sdNotify($adminId, 'delegation_granted', 'Dealership access granted',
            'A principal has let SalesDesk manage their dealership for ' . $days . ' days'
            . ($note !== '' ? ': “' . $note . '”' : '.'), ['dealer_id' => $dealerId]);
    }
    return [true, 'SalesDesk can now help run your dealership for ' . $days . ' days. You can switch this off at any time.'];
}

/** @return array{0:bool,1:string} */
function sdRevokeDelegation(int $dealerId, int $principalId): array
{
    if (!sdPhase3Ready()) {
        return [false, 'Nothing to switch off.'];
    }
    if (sdDealerPrincipalId($dealerId) !== $principalId) {
        return [false, 'Only the dealership’s principal can change access.'];
    }
    $s = Database::getInstance()->prepare("
        UPDATE dealer_delegations SET revoked_at = NOW(), revoked_by_user_id = ?
        WHERE dealer_id = ? AND revoked_at IS NULL AND expires_at > NOW()
    ");
    $s->execute([$principalId, $dealerId]);
    if ($s->rowCount() === 0) {
        return [true, 'SalesDesk didn’t have access.'];
    }
    writeAuditLog('delegation.revoked', 'dealer', $dealerId, null, null, $principalId);
    return [true, 'Done — SalesDesk can only view your dealership again.'];
}

/** Recent grants for the Settings page. */
function sdDelegationHistory(int $dealerId, int $limit = 5): array
{
    if (!sdPhase3Ready()) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT d.*, IF(d.revoked_at IS NULL AND d.expires_at > NOW(), 1, 0) AS is_active
        FROM dealer_delegations d WHERE d.dealer_id = ? ORDER BY d.id DESC LIMIT " . (int) $limit
    );
    $s->execute([$dealerId]);
    return $s->fetchAll();
}

/**
 * Everything SalesDesk staff did (or tried to do) inside this dealership,
 * newest first — from the workspace stamp on audit_logs (Phase 1).
 */
function sdStaffChangesFeed(int $dealerId, int $limit = 25): array
{
    try {
        $s = Database::getInstance()->prepare("
            SELECT al.action, al.entity_type, al.entity_id, al.created_at, al.after_data,
                   p.first_name, p.last_name, u.email
            FROM audit_logs al
            LEFT JOIN users u    ON u.id = al.actor_id
            LEFT JOIN profiles p ON p.user_id = al.actor_id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(al.after_data, '$._workspace.dealer_id')) = ?
               OR (al.entity_type = 'dealer' AND al.entity_id = ? AND al.action IN
                   ('workspace.entered', 'enforcement.listing_held', 'enforcement.listing_released', 'dealer.handed_over'))
            ORDER BY al.id DESC
            LIMIT " . (int) $limit
        );
        $s->execute([(string) $dealerId, $dealerId]);
        return $s->fetchAll();
    } catch (Throwable $e) {
        error_log('[SalesDesk sdStaffChangesFeed] ' . $e->getMessage());
        return [];
    }
}

/** Plain-language label for an audit action in the principal's feed. */
function sdActionLabel(string $action): string
{
    return match ($action) {
        'workspace.entered'            => 'Opened your dealership',
        'workspace.write_blocked'      => 'Tried to change something (blocked — view only)',
        'car.status_changed'           => 'Changed a listing’s status',
        'lead.status_changed'          => 'Updated a lead',
        'commission.created'           => 'Closed a deal',
        'enforcement.listing_held'     => 'Pulled a listing (platform hold)',
        'enforcement.listing_released' => 'Released a listing hold',
        'dealer.handed_over'           => 'Handed the dealership over to you',
        default                        => ucfirst(str_replace(['.', '_'], [' — ', ' '], $action)),
    };
}


// ============================================================
// 2. DEAL CONFIRMATION
// ============================================================

/**
 * Does a deal closed right now at $dealerId need the principal's
 * confirmation? Yes when SalesDesk staff close it on a principal-owned
 * dealership.
 */
function sdDealNeedsConfirmation(int $dealerId, string $closedVia): bool
{
    return sdPhase3Ready() && $closedVia === 'staff' && sdDealerPrincipalId($dealerId) !== null;
}

/** Null when commission $id may move forward; otherwise why not. */
function sdConfirmationBlock(int $commissionId, string $newStatus): ?string
{
    if (!sdPhase3Ready() || !in_array($newStatus, SD_FOUR_EYES_STATUSES, true)) {
        return null;
    }
    $s = Database::getInstance()->prepare("SELECT dealer_confirmation FROM commissions WHERE id = ? LIMIT 1");
    $s->execute([$commissionId]);
    return $s->fetchColumn() === 'pending'
        ? 'Waiting for the dealer principal to confirm this deal — SalesDesk staff closed it on their behalf.'
        : null;
}

/** Load a commission awaiting confirmation at a principal's dealership. */
function sdPendingConfirmation(int $commissionId, int $dealerId): ?array
{
    if (!sdPhase3Ready()) {
        return null;
    }
    $s = Database::getInstance()->prepare("
        SELECT c.*, l.status AS lead_status
        FROM commissions c JOIN leads l ON l.id = c.lead_id
        WHERE c.id = ? AND c.dealer_id = ? AND c.dealer_confirmation = 'pending' AND c.status = 'pending'
        LIMIT 1
    ");
    $s->execute([$commissionId, $dealerId]);
    return $s->fetch() ?: null;
}

/**
 * Principal confirms a staff-closed deal: invoice is sent now.
 * @return array{0:bool,1:string}
 */
function sdConfirmDeal(int $commissionId, int $dealerId, int $principalId): array
{
    if (sdDealerPrincipalId($dealerId) !== $principalId) {
        return [false, 'Only the dealership’s principal can confirm deals.'];
    }
    $c = sdPendingConfirmation($commissionId, $dealerId);
    if (!$c) {
        return [false, 'That deal isn’t waiting for confirmation.'];
    }
    $pdo = Database::getInstance();
    $pdo->prepare("UPDATE commissions SET dealer_confirmation = 'confirmed', dealer_confirmed_at = NOW(), updated_at = NOW() WHERE id = ?")
        ->execute([$commissionId]);
    writeAuditLog('commission.dealer_confirmed', 'commission', $commissionId,
        ['dealer_confirmation' => 'pending'], ['dealer_confirmation' => 'confirmed'], $principalId);

    // Invoice now — it was held back when staff closed the deal.
    $lead = $pdo->prepare("
        SELECT l.uuid, l.buyer_name, l.buyer_email, c.make AS car_make, c.model AS car_model,
               c.year AS car_year, c.price AS car_price, d.company_name
        FROM leads l JOIN cars c ON c.id = l.car_id JOIN dealers d ON d.id = l.dealer_id
        WHERE l.id = ?
    ");
    $lead->execute([(int) $c['lead_id']]);
    $invoiceLead = $lead->fetch();
    if ($invoiceLead && function_exists('sendDealerCommissionInvoice')) {
        foreach (array_unique(array_filter(array_column(getDealerContacts($dealerId), 'email'))) as $email) {
            sendDealerCommissionInvoice(
                ['email' => $email, 'company_name' => $invoiceLead['company_name']],
                $invoiceLead,
                ['id' => $commissionId, 'gross_amount' => $c['gross_amount'],
                 'platform_fee' => $c['platform_fee'], 'net_amount' => $c['net_amount']]
            );
        }
    }
    if (!empty($c['closed_by_user_id'])) {
        sdNotify((int) $c['closed_by_user_id'], 'deal_confirmed', 'Deal confirmed by the dealer',
            'The principal confirmed the deal you closed. It can now be approved for payout.',
            ['commission_id' => $commissionId]);
    }
    return [true, 'Thanks — deal confirmed. Your commission invoice is on its way.'];
}

/**
 * Principal disputes a staff-closed deal: the lead goes back to
 * negotiation and the commission is removed (nothing was invoiced or paid).
 * @return array{0:bool,1:string}
 */
function sdDisputeDeal(int $commissionId, int $dealerId, int $principalId, string $reason): array
{
    if (sdDealerPrincipalId($dealerId) !== $principalId) {
        return [false, 'Only the dealership’s principal can dispute deals.'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') {
        return [false, 'Please tell SalesDesk why — it helps them fix it.'];
    }
    $c = sdPendingConfirmation($commissionId, $dealerId);
    if (!$c) {
        return [false, 'That deal isn’t waiting for confirmation.'];
    }

    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM commissions WHERE id = ? AND dealer_confirmation = 'pending' AND status = 'pending'")
            ->execute([$commissionId]);
        $pdo->prepare("UPDATE leads SET status = 'negotiation', status_updated_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([(int) $c['lead_id']]);
        writeAuditLog('commission.dealer_disputed', 'commission', $commissionId,
            ['status' => 'pending', 'gross' => $c['gross_amount'], 'net' => $c['net_amount'],
             'closed_by' => $c['closed_by_user_id'], 'lead_id' => $c['lead_id']],
            ['removed' => true, 'reason' => $reason], $principalId);
        writeAuditLog('lead.status_changed', 'lead', (int) $c['lead_id'],
            ['status' => 'closed'], ['status' => 'negotiation', 'notes' => 'Deal disputed by principal: ' . $reason], $principalId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk sdDisputeDeal] ' . $e->getMessage());
        return [false, 'Could not record that. Nothing was changed.'];
    }

    if (!empty($c['broker_id'])) {
        sdNotify((int) $c['broker_id'], 'deal_reopened', 'Deal reopened by the dealer',
            'The dealer reopened a deal that was marked as closed, so its commission was withdrawn. The lead is back in negotiation.',
            ['lead_id' => (int) $c['lead_id']]);
    }
    if (!empty($c['closed_by_user_id'])) {
        sdNotify((int) $c['closed_by_user_id'], 'deal_disputed', 'Deal disputed by the dealer',
            'The principal disputed a deal you closed: “' . $reason . '”. The lead is back in negotiation.',
            ['lead_id' => (int) $c['lead_id']]);
    }
    return [true, 'Got it — the lead is back in negotiation and SalesDesk has been told why.'];
}


// ============================================================
// 3. ENFORCEMENT HOLDS
// ============================================================

/** @return array{0:bool,1:string} */
function sdHoldListing(int $carId, string $reason, int $actorId): array
{
    if (!sdPhase3Ready()) {
        return [false, 'Run migration 0017 first.'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if (mb_strlen($reason) < 5) {
        return [false, 'A clear reason is required — the dealer will see it.'];
    }
    $pdo = Database::getInstance();
    $s = $pdo->prepare("SELECT id, dealer_id, status, make, model, year, hold_reason FROM cars WHERE id = ? LIMIT 1");
    $s->execute([$carId]);
    $car = $s->fetch();
    if (!$car) {
        return [false, 'That listing was not found.'];
    }
    if ($car['status'] === 'sold') {
        return [false, 'That car is already sold.'];
    }
    $pdo->prepare("
        UPDATE cars SET status = 'paused', hold_reason = ?, hold_by_user_id = ?, hold_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([$reason, $actorId, $carId]);
    writeAuditLog('enforcement.listing_held', 'dealer', (int) $car['dealer_id'],
        ['car_id' => $carId, 'status' => $car['status']],
        ['car_id' => $carId, 'status' => 'paused', 'reason' => $reason], $actorId);

    $title = "{$car['year']} {$car['make']} {$car['model']}";
    foreach (array_column(getDealerContacts((int) $car['dealer_id']), 'user_id') as $uid) {
        sdNotify((int) $uid, 'listing_held', 'Listing paused by SalesDesk — ' . $title,
            'SalesDesk has paused this listing: “' . $reason . '”. Contact SalesDesk to have it released.',
            ['car_id' => $carId]);
    }
    return [true, $title . ' is on hold. The dealer has been told why.'];
}

/** @return array{0:bool,1:string} */
function sdReleaseHold(int $carId, int $actorId): array
{
    if (!sdPhase3Ready()) {
        return [false, 'Run migration 0017 first.'];
    }
    $pdo = Database::getInstance();
    $s = $pdo->prepare("SELECT id, dealer_id, make, model, year, hold_reason FROM cars WHERE id = ? AND hold_reason IS NOT NULL LIMIT 1");
    $s->execute([$carId]);
    $car = $s->fetch();
    if (!$car) {
        return [false, 'That listing isn’t on hold.'];
    }
    $pdo->prepare("UPDATE cars SET hold_reason = NULL, hold_by_user_id = NULL, hold_at = NULL, updated_at = NOW() WHERE id = ?")
        ->execute([$carId]);
    writeAuditLog('enforcement.listing_released', 'dealer', (int) $car['dealer_id'],
        ['car_id' => $carId, 'reason' => $car['hold_reason']], ['car_id' => $carId], $actorId);

    $title = "{$car['year']} {$car['make']} {$car['model']}";
    foreach (array_column(getDealerContacts((int) $car['dealer_id']), 'user_id') as $uid) {
        sdNotify((int) $uid, 'listing_released', 'Listing hold released — ' . $title,
            'SalesDesk has released its hold. The listing is still paused — resume it from your inventory when ready.',
            ['car_id' => $carId]);
    }
    return [true, 'Hold released. The listing stays paused until the dealer resumes it.'];
}

/** Hold reason for a car, or null. */
function sdCarHold(int $carId): ?string
{
    if (!sdPhase3Ready()) {
        return null;
    }
    $s = Database::getInstance()->prepare("SELECT hold_reason FROM cars WHERE id = ? LIMIT 1");
    $s->execute([$carId]);
    $v = $s->fetchColumn();
    return $v ?: null;
}


// ============================================================
// 4. HANDOVER TO A PRINCIPAL
// ============================================================

/**
 * Hand a SalesDesk-run dealership to its principal.
 *   • existing ACTIVE dealer account with no dealership → linked
 *   • no account with that email → a dealer account is created and
 *     invited (they set their own password)
 *   • anything else → refused
 * Operators drop to View automatically; their rows are set to 'view' too
 * so the record matches. @return array{0:bool,1:string}
 */
function saHandoverToPrincipal(int $dealerId, string $email, string $first, string $last, int $actorId): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Enter the principal’s email address.'];
    }
    $pdo = Database::getInstance();
    $d = $pdo->prepare("SELECT id, company_name, user_id FROM dealers WHERE id = ? LIMIT 1");
    $d->execute([$dealerId]);
    $dealer = $d->fetch();
    if (!$dealer) {
        return [false, 'That dealership was not found.'];
    }
    if ($dealer['user_id'] !== null) {
        return [false, $dealer['company_name'] . ' already has a principal.'];
    }

    $user    = getUserByEmail($email);
    $created = false;
    if ($user) {
        if ($user['role'] !== 'dealer') {
            return [false, $email . ' is a ' . str_replace('_', ' ', $user['role']) . ' account. A principal needs a dealer account.'];
        }
        if ($user['status'] !== 'active') {
            return [false, $email . ' isn’t active.'];
        }
        $has = $pdo->prepare("SELECT company_name FROM dealers WHERE user_id = ? LIMIT 1");
        $has->execute([(int) $user['id']]);
        if ($other = $has->fetchColumn()) {
            return [false, $email . ' is already the principal of ' . $other . '.'];
        }
    }
    if (!$user && trim($first) === '') {
        return [false, 'There’s no account for ' . $email . ' yet — add their first name and we’ll invite them.'];
    }

    $pdo->beginTransaction();
    try {
        if (!$user) {
            $pdo->prepare("
                INSERT INTO users (uuid, email, role, password_hash, status, email_verified, created_at, updated_at)
                VALUES (?, ?, 'dealer', ?, 'active', 1, NOW(), NOW())
            ")->execute([generateUuidV4(), $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_ALGO)]);
            $uid = (int) $pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO profiles (user_id, first_name, last_name, onboarding_step, onboarding_completed, created_at, updated_at)
                VALUES (?, ?, ?, 0, 1, NOW(), NOW())
            ")->execute([$uid, mb_substr(trim($first), 0, 60), mb_substr(trim($last), 0, 60) ?: null]);
            $created = true;
        } else {
            $uid = (int) $user['id'];
        }

        $pdo->prepare("UPDATE dealers SET user_id = ?, updated_at = NOW() WHERE id = ? AND user_id IS NULL")
            ->execute([$uid, $dealerId]);
        if (function_exists('sdWorkspaceSchemaReady') && sdWorkspaceSchemaReady()) {
            $pdo->prepare("UPDATE dealer_managers SET access = 'view' WHERE dealer_id = ?")->execute([$dealerId]);
        }
        writeAuditLog('dealer.handed_over', 'dealer', $dealerId, ['user_id' => null],
            ['user_id' => $uid, 'email' => $email, 'invited' => $created], $actorId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk saHandoverToPrincipal] ' . $e->getMessage());
        return [false, 'Could not hand it over. Nothing was changed.'];
    }

    // Handover summary: what SalesDesk built for them.
    $sum = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM cars WHERE dealer_id = ? AND status = 'active')                         AS live_cars,
            (SELECT COUNT(*) FROM leads WHERE dealer_id = ? AND status NOT IN ('closed','lost'))           AS open_leads,
            (SELECT COUNT(*) FROM sales_executives WHERE dealer_id = ? AND verification_status = 'verified') AS team,
            (SELECT COUNT(*) FROM commissions WHERE dealer_id = ? AND status IN ('pending','approved','scheduled')) AS open_commissions
    ");
    $sum->execute([$dealerId, $dealerId, $dealerId, $dealerId]);
    $s = $sum->fetch();
    $summary = sprintf('%d live car(s), %d open lead(s), %d sales exec(s) and %d commission(s) in progress.',
        $s['live_cars'], $s['open_leads'], $s['team'], $s['open_commissions']);

    sdNotify($uid, 'dealer_handover', 'Welcome to ' . $dealer['company_name'],
        'SalesDesk has handed ' . $dealer['company_name'] . ' over to you: ' . $summary
        . ' SalesDesk staff can now only view it — you can let them help from Settings → SalesDesk access.',
        ['dealer_id' => $dealerId]);
    if (function_exists('sendDealerHandover')) {
        sendDealerHandover($email, trim($first) ?: 'there', $dealer['company_name'], $summary, $created);
    }

    return [true, $dealer['company_name'] . ' now belongs to ' . $email . ($created ? ' (invited)' : '')
        . '. SalesDesk staff can only view it from now on. ' . $summary];
}
