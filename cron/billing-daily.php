<?php
/**
 * SalesDesk — Daily billing housekeeping  (plans Phase 3)
 *
 * Run once a day (cPanel → Cron Jobs), e.g. 02:15:
 *   15 2 * * * php /home/USER/public_html/cron/billing-daily.php >> /home/USER/logs/billing.log 2>&1
 *
 * What it does:
 *   • 0026 (includes/invoicing.php → sdInvoiceDaily()): invoiced businesses
 *     get next month's invoice a week before it starts (emailed with EFT
 *     details); overdue invoices mark the plan past due and remind the
 *     business and superadmins
 * Then (includes/billing.php → sdBillingLapse()):
 *   • subscriptions past their paid-through date → back to Free
 *     (row removed, audited as plan.changed, the broker gets a notice)
 *   • unfinished PayFast checkouts older than 2 days → expired
 *   • 3b (includes/plan_grace.php → sdGraceDaily()): accounts over a plan
 *     limit get a 7-day window; when it ends, extra desk cars are paused
 *     (parked, never deleted) and extra Enterprise dealerships stop adding
 *     new cars; parked cars come back as soon as there's room
 *   • 4a (includes/vip_network.php → sdVipDaily()): network-only cars are
 *     in force only while their business has VIP perks; desk rows of
 *     brokers outside the network are paused, and come back when allowed
 *
 * Plan access already stops at the paid-through date without this job
 * (sdSubscriptionGrants checks the date on every request); the job tidies
 * up and tells people. Safe to run more than once a day.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/plan_grace.php';
require_once $root . '/includes/vip_network.php';   // 4a
require_once $root . '/includes/invoicing.php';     // 0026

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('Billing daily: start');
try {
    foreach (sdInvoiceDaily() as $note) {   // 0026: issue next invoices, flag overdue ones (before the lapse)
        $log('  ' . $note);
    }
    foreach (sdBillingLapse() as $note) {
        $log('  ' . $note);
    }
    foreach (sdGraceDaily() as $note) {
        $log('  ' . $note);
    }
    foreach (sdVipDaily() as $note) {   // 4a: network-only cars follow each business's plan
        $log('  ' . $note);
    }
} catch (Throwable $e) {
    $log('  ERROR: ' . $e->getMessage());
    exit(1);
}
$log('Billing daily: done');
