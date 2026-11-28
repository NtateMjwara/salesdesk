<?php
/**
 * SalesDesk — Business invoice (printable)  (hybrid business billing, 0026)
 * Route: /app/billing/invoice?id={invoice id}
 *
 * A clean, print-ready page for one monthly business invoice. Seen by
 * superadmins, and by the principal / owner of a dealership in the
 * invoiced business (sdInvoiceViewable). Prices exclude VAT.
 *
 * Styles: assets/css/invoice.css. Script: assets/js/invoice.js (Print).
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/assets.php';
require_once '../../includes/invoicing.php';

applyCachePolicy('auth');
requireLogin();

$userId = (int) $_SESSION['user_id'];
$role   = (string) ($_SESSION['user_role'] ?? '');
$inv    = sdInvoice((int) ($_GET['id'] ?? 0));
if (!$inv || !sdInvoiceViewable($inv, $userId, $role)) {
    http_response_code(404);
    exit('Invoice not found.');
}

$e       = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$money   = static fn (int $c): string => 'R ' . number_format($c / 100, 2, '.', ',');
$status  = sdInvoiceStatusLabel($inv);
$back    = $role === 'admin' ? '/app/admin/plans#invoices' : '/app/dealer/plan';
$company = getPlatformConfig('salesdesk_bank_holder', 'SalesDesk (Pty) Ltd');
?><!DOCTYPE html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Invoice <?= $e($inv['invoice_number']) ?> | SalesDesk</title>
  <link rel="icon" href="/assets/img/brand/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= sdAsset('/assets/css/invoice.css') ?>">
</head>
<body>
  <nav class="inv-bar" aria-label="Invoice actions">
    <a class="inv-bar__back" href="<?= $e($back) ?>">← Back</a>
    <button class="inv-bar__print" type="button" data-print>Print or save as PDF</button>
  </nav>

  <main class="inv-page">
    <header class="inv-head">
      <img class="inv-logo" src="/assets/img/brand/salesdesk-logo-ink-transparent.png" alt="SalesDesk" width="180">
      <div class="inv-title">
        <h1>Invoice</h1>
        <p class="inv-num"><?= $e($inv['invoice_number']) ?></p>
        <span class="inv-status inv-status--<?= strtolower($e($status)) ?>"><?= $e($status) ?></span>
      </div>
    </header>

    <section class="inv-parties">
      <div>
        <h2>From</h2>
        <p><strong><?= $e($company) ?></strong><br>salesdesk.co.za</p>
      </div>
      <div>
        <h2>Billed to</h2>
        <p><strong><?= $e($inv['billed_to']) ?></strong></p>
      </div>
      <div>
        <h2>Dates</h2>
        <p>Issued <?= $e(date('j M Y', strtotime((string) $inv['issued_at']))) ?><br>
           Due <?= $e(date('j M Y', strtotime((string) $inv['due_at']))) ?>
           <?php if ($inv['status'] === 'paid' && $inv['paid_at']): ?><br>Paid <?= $e(date('j M Y', strtotime((string) $inv['paid_at']))) ?><?php endif; ?></p>
      </div>
    </section>

    <table class="inv-lines">
      <thead><tr><th scope="col">Description</th><th scope="col">Period</th><th scope="col" class="num">Amount</th></tr></thead>
      <tbody>
        <tr>
          <td>SalesDesk <?= $e($inv['plan_name']) ?> business plan (monthly)</td>
          <td><?= $e(date('j M Y', strtotime((string) $inv['period_start']))) ?> – <?= $e(date('j M Y', strtotime($inv['period_end'] . ' -1 day'))) ?></td>
          <td class="num"><?= $e($money((int) $inv['amount_cents'])) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr><th scope="row" colspan="2">Total due</th><td class="num"><?= $e($money($inv['status'] === 'open' ? (int) $inv['amount_cents'] : 0)) ?></td></tr>
      </tfoot>
    </table>
    <p class="inv-vat">Prices exclude VAT. SalesDesk is not registered for VAT.</p>

    <?php if ($inv['status'] === 'open'): ?>
    <section class="inv-pay">
      <h2>Pay by EFT</h2>
      <dl>
        <div><dt>Bank</dt><dd><?= $e(getPlatformConfig('salesdesk_bank_name', 'FNB')) ?></dd></div>
        <div><dt>Account holder</dt><dd><?= $e($company) ?></dd></div>
        <div><dt>Account number</dt><dd><?= $e(getPlatformConfig('salesdesk_bank_account', '62000000000')) ?></dd></div>
        <div><dt>Branch code</dt><dd><?= $e(getPlatformConfig('salesdesk_bank_branch', '250655')) ?></dd></div>
        <div><dt>Reference</dt><dd><strong><?= $e($inv['invoice_number']) ?></strong></dd></div>
      </dl>
    </section>
    <?php elseif ($inv['status'] === 'paid'): ?>
    <p class="inv-note">Paid — thank you.<?= $inv['paid_reference'] ? ' Reference: ' . $e($inv['paid_reference']) . '.' : '' ?></p>
    <?php else: ?>
    <p class="inv-note">This invoice was cancelled<?= $inv['void_reason'] ? ': ' . $e($inv['void_reason']) : '' ?>. Nothing is owed on it.</p>
    <?php endif; ?>
  </main>
  <script src="<?= sdAsset('/assets/js/invoice.js') ?>" defer></script>
</body>
</html>
