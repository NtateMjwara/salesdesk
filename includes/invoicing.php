<?php
/**
 * SalesDesk — Business invoicing  (hybrid business billing, migration 0026)
 *
 * Enterprise (and any business plan SalesDesk agrees to invoice) is paid
 * monthly by EFT against an invoice. Business Pro pays online instead
 * (includes/billing.php).
 *
 *   START   superadmin → sdStartInvoicing(): subscription provider 'invoice',
 *           first invoice for [today, +1 month), due in SD_INVOICE_DUE_DAYS
 *   EACH MONTH  cron (sdInvoiceDaily): the next invoice is issued
 *           SD_INVOICE_LEAD_DAYS before the current period ends, due on the
 *           day the new period starts; emailed to the business with EFT details
 *   PAID    superadmin → sdMarkInvoicePaid(reference): the plan is paid
 *           through that period (+ SD_INVOICE_GRACE_DAYS)
 *   LATE    unpaid after the due date → subscription past_due, reminder to
 *           the business and superadmins; still unpaid SD_INVOICE_GRACE_DAYS
 *           after the due date → current_period_end passes → the daily lapse
 *           moves the business to Free (and 3b's 7-day dealership window)
 *   STOP    superadmin → sdStopInvoicing(): no more invoices; the plan runs
 *           to its paid-through date
 *
 * subscriptions.current_period_end for invoiced businesses = the end of the
 * last paid period + grace (before the first payment: first due date + grace).
 *
 * Prices exclude VAT (SalesDesk isn't VAT-registered yet), so invoices carry
 * no VAT line.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/billing.php';

const SD_INVOICE_DUE_DAYS   = 7;   // first invoice: pay within a week
const SD_INVOICE_LEAD_DAYS  = 7;   // monthly invoices go out a week before the period starts
const SD_INVOICE_GRACE_DAYS = 7;   // unpaid this long after the due date → the plan lapses


// ============================================================
// READINESS + LOOKUPS
// ============================================================

function sdInvoicingReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) Database::getInstance()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_invoices'"
            )->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

function sdInvoiceNumber(int $id, ?string $issuedAt = null): string
{
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) getPlatformConfig('business_invoice_prefix', 'SDB'))) ?: 'SDB';
    return $prefix . '-' . date('Y', $issuedAt ? strtotime($issuedAt) : time()) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function sdInvoice(int $id): ?array
{
    if (!sdInvoicingReady()) {
        return null;
    }
    $s = Database::getInstance()->prepare("SELECT * FROM business_invoices WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** A business's invoices, newest first. */
function sdOrgInvoices(int $orgId, int $limit = 24): array
{
    if (!sdInvoicingReady() || $orgId <= 0) {
        return [];
    }
    $s = Database::getInstance()->prepare("SELECT * FROM business_invoices WHERE organization_id = ?
                                           ORDER BY period_start DESC, id DESC LIMIT " . max(1, min(120, $limit)));
    $s->execute([$orgId]);
    return $s->fetchAll();
}

/** Is the business invoiced right now (an invoice subscription in force)? */
function sdOrgInvoiced(int $orgId): ?array
{
    $sub = sdSubscription('organization', $orgId);
    return $sub && ($sub['provider'] ?? '') === 'invoice' && sdSubscriptionGrants($sub) ? $sub : null;
}

/** Overdue = open and past its due date. */
function sdInvoiceOverdue(array $inv): bool
{
    return $inv['status'] === 'open' && strtotime((string) $inv['due_at']) < time();
}

/** "Open", "Overdue", "Paid", "Void". */
function sdInvoiceStatusLabel(array $inv): string
{
    return $inv['status'] === 'open' ? (sdInvoiceOverdue($inv) ? 'Overdue' : 'Open') : ucfirst((string) $inv['status']);
}

function sdInvoiceGuard(int $actorId): ?string
{
    require_once __DIR__ . '/superadmin.php';
    if (!sdInvoicingReady()) {
        return 'Run 0026 Business billing in Admin → Migrations first.';
    }
    return isSuperadmin($actorId) ? null : 'Only a superadmin can manage business invoices.';
}


// ============================================================
// START / STOP
// ============================================================

/**
 * Put a business on monthly invoicing for a paid business plan.
 *
 * @return array{0:bool,1:string}
 */
function sdStartInvoicing(int $orgId, string $planCode, int $actorId): array
{
    if ($err = sdInvoiceGuard($actorId)) {
        return [false, $err];
    }
    $plan = sdPlanByCode($planCode);
    if (!$plan || $plan['audience'] !== 'organization' || $plan['is_default'] || (int) $plan['price_cents'] <= 0) {
        return [false, 'Choose a paid business plan.'];
    }
    $cur = sdSubscription('organization', $orgId);
    if ($cur && sdSubscriptionGrants($cur) && ($cur['provider'] ?? '') === 'payfast' && !(int) $cur['cancel_at_period_end']) {
        return [false, 'This business pays online through PayFast. Cancel that subscription first.'];
    }
    if ($cur && ($cur['provider'] ?? '') === 'invoice' && sdSubscriptionGrants($cur) && !(int) $cur['cancel_at_period_end']) {
        return [false, 'This business is already invoiced monthly.'];
    }
    if (!array_key_exists('max_dealerships', sdLimitOverrides('organization', $orgId))
        && ($e = sdDealershipLimitGuard($orgId, sdPlanLimit($plan, 'max_dealerships')))) {
        return [false, $e];
    }
    $pdo  = Database::getInstance();
    $org  = $pdo->prepare("SELECT id, name FROM organizations WHERE id = ? AND is_active = 1");
    $org->execute([$orgId]);
    $org  = $org->fetch();
    if (!$org) {
        return [false, 'That business wasn’t found.'];
    }
    $start = date('Y-m-d');
    $due   = date('Y-m-d 23:59:59', strtotime('+' . SD_INVOICE_DUE_DAYS . ' days'));
    $until = date('Y-m-d 23:59:59', strtotime($due . ' +' . SD_INVOICE_GRACE_DAYS . ' days'));
    $oldCode = $cur ? (sdPlans()[(int) $cur['plan_id']]['code'] ?? null) : (sdDefaultPlan('organization')['code'] ?? null);

    $pdo->prepare("
        INSERT INTO subscriptions
            (account_type, account_id, plan_id, status, started_at, current_period_end, provider, provider_ref,
             amount_cents, cancel_at_period_end, last_payment_at, last_failed_at, note, updated_by, created_at, updated_at)
        VALUES ('organization', ?, ?, 'active', NOW(), ?, 'invoice', NULL, ?, 0, NULL, NULL, 'Invoiced monthly (EFT)', ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            plan_id = VALUES(plan_id), status = 'active', started_at = NOW(), current_period_end = VALUES(current_period_end),
            provider = 'invoice', provider_ref = NULL, amount_cents = VALUES(amount_cents), cancel_at_period_end = 0,
            last_payment_at = NULL, last_failed_at = NULL, note = VALUES(note), updated_by = VALUES(updated_by), updated_at = NOW()
    ")->execute([$orgId, (int) $plan['id'], $until, (int) $plan['price_cents'], $actorId]);

    writeAuditLog('plan.changed', 'organization', $orgId, ['plan' => $oldCode],
        ['plan' => $plan['code'], 'status' => 'active', 'until' => $until, 'note' => 'Invoiced monthly (EFT)'], $actorId);
    $invId = sdIssueInvoice($orgId, $plan, $start, $due, $actorId);
    sdBillingNotify('organization', $orgId, 'You’re on ' . $plan['name'],
        'SalesDesk will invoice you monthly. Your first invoice is on your Plan page — please pay by EFT within '
        . SD_INVOICE_DUE_DAYS . ' days.');
    sdPlanChangedRecheck('organization', $orgId);
    return [true, "{$org['name']} is now on {$plan['name']}, invoiced monthly. Invoice " . sdInvoiceNumber($invId) . ' was issued and emailed.'];
}

/**
 * Stop invoicing: no more invoices; the plan runs until its paid-through
 * date (current_period_end), then Free. Open invoices stay open.
 */
function sdStopInvoicing(int $orgId, int $actorId): array
{
    if ($err = sdInvoiceGuard($actorId)) {
        return [false, $err];
    }
    $sub = sdOrgInvoiced($orgId);
    if (!$sub) {
        return [false, 'This business isn’t invoiced.'];
    }
    if ((int) $sub['cancel_at_period_end']) {
        return [true, 'Invoicing is already stopped.'];
    }
    // Paid through = last paid period's end; before any payment, the plan ends now.
    $s = Database::getInstance()->prepare("SELECT MAX(period_end) FROM business_invoices WHERE organization_id = ? AND status = 'paid'");
    $s->execute([$orgId]);
    $paidThrough = $s->fetchColumn();
    $end = $paidThrough ? date('Y-m-d 23:59:59', strtotime($paidThrough . ' -1 day')) : date('Y-m-d H:i:s');
    Database::getInstance()->prepare("UPDATE subscriptions SET cancel_at_period_end = 1, current_period_end = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$end, $actorId, (int) $sub['id']]);
    writeAuditLog('plan.cancelled', 'organization', $orgId, null, ['until' => $end, 'via' => 'invoicing_stopped'], $actorId);
    sdBillingNotify('organization', $orgId, 'Invoicing stopped',
        'SalesDesk has stopped invoicing your plan. It stays on until ' . date('j M Y', strtotime($end)) . ', then you’re on Free.');
    return [true, 'Invoicing stopped. The plan stays on until ' . date('j M Y', strtotime($end)) . '.'];
}


// ============================================================
// ISSUE / EMAIL
// ============================================================

/** Create an invoice for one month from $periodStart and email it. Returns its id. */
function sdIssueInvoice(int $orgId, array $plan, string $periodStart, string $dueAt, ?int $actorId): int
{
    $pdo  = Database::getInstance();
    $name = (string) $pdo->query('SELECT name FROM organizations WHERE id = ' . $orgId)->fetchColumn();
    $end  = date('Y-m-d', strtotime($periodStart . ' +1 month'));
    $pdo->prepare("
        INSERT INTO business_invoices (organization_id, billed_to, plan_id, plan_name, period_start, period_end, amount_cents, status, issued_at, due_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW(), ?)
    ")->execute([$orgId, $name, (int) $plan['id'], (string) $plan['name'], $periodStart, $end, (int) $plan['price_cents'], $dueAt]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE business_invoices SET invoice_number = ? WHERE id = ?")->execute([sdInvoiceNumber($id), $id]);
    writeAuditLog('invoice.issued', 'organization', $orgId, null,
        ['invoice' => sdInvoiceNumber($id), 'period' => $periodStart . '–' . $end, 'amount_cents' => (int) $plan['price_cents']], $actorId);
    sdEmailInvoice($id);
    return $id;
}

/** Email an invoice to the business's billing contacts. Returns how many got it. */
function sdEmailInvoice(int $invId): int
{
    $inv = sdInvoice($invId);
    if (!$inv || !$inv['organization_id']) {
        return 0;
    }
    require_once __DIR__ . '/mailer.php';
    $pdo  = Database::getInstance();
    $ids  = sdBillingOrgContacts((int) $inv['organization_id']);
    $sent = 0;
    if ($ids) {
        $s = $pdo->query('SELECT email FROM users WHERE status = \'active\' AND id IN (' . implode(',', array_map('intval', $ids)) . ')');
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $email) {
            if (sendBusinessInvoice((string) $email, $inv)) {
                $sent++;
            }
        }
    }
    $pdo->prepare("UPDATE business_invoices SET emailed_at = NOW() WHERE id = ?")->execute([$invId]);
    sdBillingNotify('organization', (int) $inv['organization_id'], 'Invoice ' . $inv['invoice_number'],
        'R' . number_format($inv['amount_cents'] / 100, 2, '.', ',') . ' for ' . $inv['plan_name'] . ', '
        . date('j M', strtotime((string) $inv['period_start'])) . ' – ' . date('j M Y', strtotime($inv['period_end'] . ' -1 day'))
        . '. Please pay by EFT by ' . date('j M Y', strtotime((string) $inv['due_at'])) . ' using the invoice number as the reference.');
    return $sent;
}


// ============================================================
// PAID / VOID
// ============================================================

/**
 * A superadmin records an EFT payment. The plan is then paid through the
 * invoice's period (+ grace). A late payment after a lapse puts the
 * business back on the plan.
 *
 * @return array{0:bool,1:string}
 */
function sdMarkInvoicePaid(int $invId, string $reference, int $actorId): array
{
    if ($err = sdInvoiceGuard($actorId)) {
        return [false, $err];
    }
    $inv = sdInvoice($invId);
    if (!$inv || $inv['status'] !== 'open') {
        return [false, 'That invoice isn’t open.'];
    }
    $reference = mb_substr(trim($reference), 0, 80);
    if ($reference === '') {
        return [false, 'Enter the EFT reference (or a short note) so the payment can be traced.'];
    }
    $pdo = Database::getInstance();
    $pdo->prepare("UPDATE business_invoices SET status = 'paid', paid_at = NOW(), paid_reference = ?, marked_by = ? WHERE id = ?")
        ->execute([$reference, $actorId, $invId]);
    $orgId = (int) $inv['organization_id'];
    if ($orgId) {
        $until = date('Y-m-d 23:59:59', strtotime($inv['period_end'] . ' +' . SD_INVOICE_GRACE_DAYS . ' days'));
        $sub   = sdSubscription('organization', $orgId);
        if ($sub && ($sub['provider'] ?? '') === 'invoice') {
            $newEnd = max(strtotime((string) $sub['current_period_end']), strtotime($until));
            // Stopped invoicing: paid through the period, no grace on top.
            if ((int) $sub['cancel_at_period_end']) {
                $newEnd = max(strtotime((string) $sub['current_period_end']), strtotime($inv['period_end'] . ' -1 day 23:59:59'));
            }
            $pdo->prepare("UPDATE subscriptions SET status = 'active', current_period_end = ?, last_payment_at = NOW(), last_failed_at = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', $newEnd), (int) $sub['id']]);
        } elseif (!$sub && $inv['plan_id'] && strtotime($until) > time()) {
            // Lapsed for non-payment, then paid: back on the plan, invoicing continues.
            $pdo->prepare("
                INSERT INTO subscriptions (account_type, account_id, plan_id, status, started_at, current_period_end, provider,
                                           amount_cents, cancel_at_period_end, last_payment_at, note, updated_by, created_at, updated_at)
                VALUES ('organization', ?, ?, 'active', NOW(), ?, 'invoice', ?, 0, NOW(), 'Invoiced monthly (EFT)', ?, NOW(), NOW())
            ")->execute([$orgId, (int) $inv['plan_id'], $until, (int) $inv['amount_cents'], $actorId]);
            writeAuditLog('plan.changed', 'organization', $orgId, ['plan' => sdDefaultPlan('organization')['code'] ?? null],
                ['plan' => sdPlans(null, true)[(int) $inv['plan_id']]['code'] ?? null, 'status' => 'active', 'until' => $until, 'note' => 'Restored: invoice paid'], $actorId);
        }
        sdBillingNotify('organization', $orgId, 'Payment received — thank you',
            'Invoice ' . $inv['invoice_number'] . ' is paid. Your plan is paid up to ' . date('j M Y', strtotime($inv['period_end'] . ' -1 day')) . '.');
        sdPlanChangedRecheck('organization', $orgId);
    }
    writeAuditLog('invoice.paid', 'organization', $orgId, ['status' => 'open'],
        ['invoice' => $inv['invoice_number'], 'reference' => $reference], $actorId);
    return [true, 'Invoice ' . $inv['invoice_number'] . ' marked paid.'];
}

/** Cancel an invoice issued in error (it no longer counts as owed). */
function sdVoidInvoice(int $invId, string $reason, int $actorId): array
{
    if ($err = sdInvoiceGuard($actorId)) {
        return [false, $err];
    }
    $inv = sdInvoice($invId);
    if (!$inv || $inv['status'] !== 'open') {
        return [false, 'Only an open invoice can be voided.'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') {
        return [false, 'Say why the invoice is being voided.'];
    }
    Database::getInstance()->prepare("UPDATE business_invoices SET status = 'void', void_reason = ?, marked_by = ? WHERE id = ?")
        ->execute([$reason, $actorId, $invId]);
    writeAuditLog('invoice.voided', 'organization', (int) $inv['organization_id'],
        ['status' => 'open'], ['invoice' => $inv['invoice_number'], 'reason' => $reason], $actorId);
    return [true, 'Invoice ' . $inv['invoice_number'] . ' voided.'];
}

/** Superadmin: email an invoice again. */
function sdResendInvoice(int $invId, int $actorId): array
{
    if ($err = sdInvoiceGuard($actorId)) {
        return [false, $err];
    }
    $inv = sdInvoice($invId);
    if (!$inv || $inv['status'] === 'void') {
        return [false, 'That invoice can’t be sent.'];
    }
    $n  = sdEmailInvoice($invId);
    $to = $inv['organization_id'] ? count(sdBillingOrgContacts((int) $inv['organization_id'])) : 0;
    return [true, 'Invoice ' . $inv['invoice_number'] . ($n
        ? " emailed again to {$n} contact" . ($n === 1 ? '' : 's') . '.'
        : ($to ? ' could not be emailed just now (check the mail settings) — it’s on their Plan page and in their notifications.'
               : ' has no one to email (the business has no principal) — it’s on their Plan page.'))];
}


// ============================================================
// DAILY (cron/billing-daily.php)
// ============================================================

/**
 * Issue next month's invoices, flag overdue ones. (The lapse itself is
 * sdBillingLapse(): current_period_end passes.)
 *
 * @return string[] notes for the cron log
 */
function sdInvoiceDaily(): array
{
    if (!sdInvoicingReady() || !sdPlansReady()) {
        return ['Invoices: not ready (run 0026).'];
    }
    $pdo   = Database::getInstance();
    $notes = [];

    // 1. Next invoice, SD_INVOICE_LEAD_DAYS before the current period ends.
    $subs = $pdo->query("SELECT * FROM subscriptions WHERE account_type = 'organization' AND provider = 'invoice' AND cancel_at_period_end = 0")->fetchAll();
    foreach ($subs as $sub) {
        if (!sdSubscriptionGrants($sub)) {
            continue;
        }
        $orgId = (int) $sub['account_id'];
        $last  = $pdo->prepare("SELECT MAX(period_end) FROM business_invoices WHERE organization_id = ? AND status <> 'void'");
        $last->execute([$orgId]);
        $lastEnd = $last->fetchColumn();
        if (!$lastEnd) {
            continue;   // started by sdStartInvoicing, which issues the first one
        }
        $guard = 0;
        while (strtotime((string) $lastEnd) <= strtotime('+' . SD_INVOICE_LEAD_DAYS . ' days') && $guard++ < 3) {
            $plan = sdPlans(null, true)[(int) $sub['plan_id']] ?? null;
            if (!$plan) {
                break;
            }
            $due = date('Y-m-d 23:59:59', max(strtotime((string) $lastEnd), strtotime('+' . SD_INVOICE_DUE_DAYS . ' days')));
            $id  = sdIssueInvoice($orgId, $plan, (string) $lastEnd, $due, null);
            $notes[] = 'invoice ' . sdInvoiceNumber($id) . " issued to business #{$orgId}";
            $lastEnd = date('Y-m-d', strtotime($lastEnd . ' +1 month'));
        }
    }

    // 2. Overdue: past_due + one reminder (to the business and superadmins).
    $over = $pdo->query("SELECT * FROM business_invoices WHERE status = 'open' AND due_at < NOW() AND reminded_at IS NULL AND organization_id IS NOT NULL")->fetchAll();
    foreach ($over as $inv) {
        $orgId = (int) $inv['organization_id'];
        $pdo->prepare("UPDATE subscriptions SET status = 'past_due', last_failed_at = NOW(), updated_at = NOW()
                       WHERE account_type = 'organization' AND account_id = ? AND provider = 'invoice' AND status = 'active'")
            ->execute([$orgId]);
        $lapse = date('j M Y', strtotime($inv['due_at'] . ' +' . SD_INVOICE_GRACE_DAYS . ' days'));
        sdBillingNotify('organization', $orgId, 'Invoice ' . $inv['invoice_number'] . ' is overdue',
            'We haven’t received payment yet. Please pay by EFT by ' . $lapse . ' to keep your plan — after that the business moves to Free.');
        require_once __DIR__ . '/plan_grace.php';
        foreach (sdGraceSuperadminIds() as $sa) {
            sdGraceNotifyUser($sa, $inv['billed_to'] . ': invoice ' . $inv['invoice_number'] . ' overdue',
                'R' . number_format($inv['amount_cents'] / 100, 2, '.', ',') . ' was due ' . date('j M', strtotime((string) $inv['due_at']))
                . '. The plan lapses on ' . $lapse . ' unless it’s marked paid.',
                '/app/admin/plans#invoices');
        }
        $pdo->prepare("UPDATE business_invoices SET reminded_at = NOW() WHERE id = ?")->execute([(int) $inv['id']]);
        $notes[] = 'invoice ' . $inv['invoice_number'] . ' overdue — reminders sent';
    }
    return $notes ?: ['Invoices: nothing to do.'];
}


// ============================================================
// REPORTING (Admin → Plans)
// ============================================================

/** Invoiced MRR, open and overdue totals, and the open invoices list. */
function sdInvoiceSummary(): array
{
    $out = ['invoiced' => 0, 'mrr_cents' => 0, 'open' => 0, 'open_cents' => 0, 'overdue' => 0, 'paid_30d_cents' => 0, 'rows' => []];
    if (!sdInvoicingReady()) {
        return $out;
    }
    $pdo = Database::getInstance();
    foreach ($pdo->query("SELECT * FROM subscriptions WHERE provider = 'invoice'") as $s) {
        if (sdSubscriptionGrants($s)) {
            $out['invoiced']++;
            if (!(int) $s['cancel_at_period_end']) {
                $out['mrr_cents'] += (int) $s['amount_cents'];
            }
        }
    }
    $out['rows'] = $pdo->query("
        SELECT i.*, (SELECT MIN(d.id) FROM dealers d WHERE d.organization_id = i.organization_id) AS dealer_id
        FROM business_invoices i WHERE i.status = 'open' ORDER BY i.due_at ASC LIMIT 100
    ")->fetchAll();
    foreach ($out['rows'] as $r) {
        $out['open']++;
        $out['open_cents'] += (int) $r['amount_cents'];
        if (sdInvoiceOverdue($r)) {
            $out['overdue']++;
        }
    }
    $out['paid_30d_cents'] = (int) $pdo->query("SELECT COALESCE(SUM(amount_cents), 0) FROM business_invoices WHERE status = 'paid' AND paid_at > NOW() - INTERVAL 30 DAY")->fetchColumn();
    return $out;
}

/**
 * May this signed-in user see this invoice? Superadmins, or the principal
 * / owner of a dealership in the invoiced business.
 */
function sdInvoiceViewable(array $inv, int $userId, string $role): bool
{
    require_once __DIR__ . '/superadmin.php';
    if ($role === 'admin') {
        return isSuperadmin($userId);
    }
    if ($role !== 'dealer' || !$inv['organization_id']) {
        return false;
    }
    $s = Database::getInstance()->prepare("
        SELECT 1 FROM organizations o
        WHERE o.id = ? AND (o.owner_user_id = ? OR EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = o.id AND d.user_id = ?))
    ");
    $s->execute([(int) $inv['organization_id'], $userId, $userId]);
    return (bool) $s->fetchColumn();
}
