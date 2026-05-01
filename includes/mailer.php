<?php
/**
 * SalesDesk — PHPMailer wrapper & all transactional email helpers.
 * T1 owns this file entirely — one file, no exceptions.
 *
 * CONTRACT: Function signatures below are frozen once shipped.
 * Adding a parameter, changing a return type, or renaming requires
 * a PR touching all callers simultaneously and T1 approval.
 *
 * Functions defined:
 *
 *   CORE
 *     sendEmail(to, subject, body, replyTo?)
 *     emailLayout(subject, content)
 *
 *   AUTH
 *     sendVerificationOTP(to, otp)
 *     sendPasswordResetOTP(to, otp)
 *     sendPasswordChangedNotice(to)
 *     sendWelcomeEmail(to, displayName, role)
 *
 *   SALES EXEC
 *     sendSalesExecJoinRequest(principalEmail, dealerName, execEmail)
 *     sendSalesExecApproved(execEmail, execName, dealerName)
 *     sendSalesExecRejected(execEmail, execName, dealerName, reason)
 *
 *   DEALER VERIFICATION  (D-11)
 *     sendDealerVerified(dealer)
 *     sendDealerVerificationRejected(dealer, reason)
 *
 *   LEAD / BUYER  (D-03)
 *     sendBuyerConfirmation(lead, channel)   — wrapper; v2 adds SMS/WA
 *
 *   COMMISSIONS  (D-08)
 *     sendDealerCommissionInvoice(dealer, lead, commission)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';


// ============================================================
// CORE SENDER
// ============================================================

/**
 * Send a single HTML email via PHPMailer / SMTP.
 *
 * @param string      $to
 * @param string      $subject
 * @param string      $body     Full HTML body (inner content, wrapped by emailLayout)
 * @param string|null $replyTo
 */
function sendEmail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (SMTP_SECURE === 'ssl')
            ? PHPMailer::ENCRYPTION_SMIME
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = emailLayout($subject, $body);
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (MailerException $e) {
        error_log('[SalesDesk Mailer] ' . $mail->ErrorInfo);
        return false;
    }
}


// ============================================================
// EMAIL LAYOUT WRAPPER
// ============================================================

function emailLayout(string $subject, string $content): string
{
    $siteName = APP_NAME;
    $siteUrl  = SITE_URL;
    $year     = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f8;font-family:'DM Sans',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f8;padding:32px 16px;">
    <tr><td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:540px;">

        <tr>
          <td style="background:#0f4c9e;border-radius:12px 12px 0 0;padding:22px 30px;">
            <a href="{$siteUrl}" style="text-decoration:none;">
              <span style="font-size:22px;font-weight:800;color:#ffffff;font-family:Georgia,serif;">
                Sales<span style="color:#93c5fd;">Desk</span>
              </span>
              <span style="font-size:10px;font-weight:700;color:rgba(255,255,255,.5);
                           margin-left:8px;letter-spacing:.08em;">ZA</span>
            </a>
          </td>
        </tr>

        <tr>
          <td style="background:#ffffff;padding:30px 30px 26px;border:1px solid #e2e8f0;border-top:none;">
            {$content}
          </td>
        </tr>

        <tr>
          <td style="background:#f8faff;border:1px solid #e2e8f0;border-top:none;
                     border-radius:0 0 12px 12px;padding:16px 30px;text-align:center;">
            <p style="font-size:11px;color:#94a3b8;margin:0;line-height:1.6;">
              &copy; {$year} {$siteName} (Pty) Ltd &middot; South Africa<br>
              You received this email because an action was taken on your SalesDesk account.<br>
              If you did not request this, you can safely ignore this message.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}


// ============================================================
// AUTH EMAIL HELPERS
// ============================================================

function sendVerificationOTP(string $to, string $otp): bool
{
    $expMin = (int)(OTP_EXPIRY_SECONDS / 60);
    $body   = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Verify your email address
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 24px;">
  Thanks for signing up to SalesDesk. Enter the code below to activate your account.
</p>
<div style="background:#eff4ff;border:1px solid #dbeafe;border-radius:12px;
            padding:24px;text-align:center;margin:0 0 24px;">
  <p style="font-size:11px;font-weight:700;letter-spacing:.1em;color:#64748b;
            text-transform:uppercase;margin:0 0 8px;">Verification code</p>
  <p style="font-size:40px;font-weight:800;letter-spacing:.25em;color:#0f4c9e;
            font-family:monospace;margin:0;">{$otp}</p>
  <p style="font-size:12px;color:#94a3b8;margin:8px 0 0;">Expires in {$expMin} minutes</p>
</div>
<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
  If you did not create a SalesDesk account, you can safely ignore this email.
</p>
HTML;
    return sendEmail($to, 'Your SalesDesk verification code: ' . $otp, $body);
}

function sendPasswordResetOTP(string $to, string $otp): bool
{
    $expMin = (int)(OTP_EXPIRY_SECONDS / 60);
    $body   = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Reset your password
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 24px;">
  We received a request to reset your SalesDesk password. Use the code below.
</p>
<div style="background:#eff4ff;border:1px solid #dbeafe;border-radius:12px;
            padding:24px;text-align:center;margin:0 0 24px;">
  <p style="font-size:11px;font-weight:700;letter-spacing:.1em;color:#64748b;
            text-transform:uppercase;margin:0 0 8px;">Reset code</p>
  <p style="font-size:40px;font-weight:800;letter-spacing:.25em;color:#0f4c9e;
            font-family:monospace;margin:0;">{$otp}</p>
  <p style="font-size:12px;color:#94a3b8;margin:8px 0 0;">Expires in {$expMin} minutes</p>
</div>
<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
  If you did not request a password reset, your account is safe — no changes were made.
</p>
HTML;
    return sendEmail($to, 'Your SalesDesk password reset code: ' . $otp, $body);
}

function sendPasswordChangedNotice(string $to): bool
{
    $loginUrl = SITE_URL . '/auth/login.php';
    $body     = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">Password changed</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  Your SalesDesk password was successfully changed.
</p>
<a href="{$loginUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Log in →
</a>
<p style="font-size:13px;color:#94a3b8;margin:20px 0 0;">
  If you did not make this change, contact
  <a href="mailto:support@salesdesk.co.za" style="color:#0f4c9e;">support@salesdesk.co.za</a>
  immediately.
</p>
HTML;
    return sendEmail($to, 'Your SalesDesk password has been changed', $body);
}

/**
 * Welcome email. Copy differs by role.
 * For dealer role, $displayName should be the company_name (BUG-03 fix).
 */
function sendWelcomeEmail(string $to, string $displayName, string $role = 'broker'): bool
{
    $onboardUrl = SITE_URL . '/app/onboarding.php';
    $loginUrl   = SITE_URL . '/auth/login.php';

    if ($role === 'sales_exec') {
        $subject = 'Welcome to SalesDesk — your account is under review';
        $body    = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Welcome to SalesDesk, {$displayName}!
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 16px;">
  Your account has been created and your join request has been sent to the dealer principal.
  You will receive another email once they approve you.
</p>
<a href="{$loginUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Check your status →
</a>
HTML;
    } elseif ($role === 'dealer') {
        // $displayName = company_name for dealers (BUG-03)
        $subject = 'Welcome to SalesDesk — set up your dealership';
        $body    = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Welcome to SalesDesk!
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 8px;">
  <strong>{$displayName}</strong> is now registered on SalesDesk.
</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  Complete your dealership profile to start listing cars and receiving leads from our broker network.
</p>
<a href="{$onboardUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Set up my dealership →
</a>
HTML;
    } else {
        $subject = 'Welcome to SalesDesk — finish setting up your desk';
        $body    = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Welcome to SalesDesk, {$displayName}!
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 16px;">
  Your email has been verified. Complete your profile to start sharing cars and earning commission.
</p>
<a href="{$onboardUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Set up my SalesDesk →
</a>
HTML;
    }

    return sendEmail($to, $subject, $body);
}


// ============================================================
// SALES EXECUTIVE EMAIL HELPERS
// ============================================================

function sendSalesExecJoinRequest(
    string $principalEmail,
    string $dealerName,
    string $execEmail
): bool {
    $teamUrl = SITE_URL . '/app/dealer/team.php';
    $body    = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  New team join request
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 8px;">
  A sales executive has requested to join <strong>{$dealerName}</strong>:
</p>
<div style="background:#f8faff;border:1px solid #e2e8f0;border-radius:10px;
            padding:14px 18px;margin:0 0 20px;">
  <p style="font-size:13px;color:#64748b;margin:0 0 3px;">Email address</p>
  <p style="font-size:15px;font-weight:600;color:#1e293b;font-family:monospace;margin:0;">
    {$execEmail}
  </p>
</div>
<a href="{$teamUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Review request →
</a>
HTML;
    return sendEmail($principalEmail, 'New team join request — ' . $dealerName, $body);
}

function sendSalesExecApproved(string $execEmail, string $execName, string $dealerName): bool
{
    $dashUrl = SITE_URL . '/app/exec/dashboard.php';
    $body    = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#15803d;margin:0 0 8px;">You've been approved!</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 8px;">Hi {$execName},</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  Your request to join <strong>{$dealerName}</strong> has been
  <strong style="color:#15803d;">approved</strong>. You can now upload cars and manage leads.
</p>
<a href="{$dashUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Go to my dashboard →
</a>
HTML;
    return sendEmail($execEmail, "You're approved — welcome to {$dealerName}", $body);
}

function sendSalesExecRejected(
    string $execEmail,
    string $execName,
    string $dealerName,
    string $reason = ''
): bool {
    $reasonBlock = $reason
        ? "<p style=\"font-size:13px;color:#7f1d1d;background:#fef2f2;border:1px solid #fecaca;"
          . "border-radius:8px;padding:12px 14px;margin:0 0 18px;\"><strong>Reason:</strong> "
          . htmlspecialchars($reason) . "</p>"
        : '';
    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#dc2626;margin:0 0 8px;">
  Join request declined
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 12px;">Hi {$execName},</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 16px;">
  Your request to join <strong>{$dealerName}</strong> has been declined.
</p>
{$reasonBlock}
<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
  If you believe this is an error, contact your manager directly or reach us at
  <a href="mailto:support@salesdesk.co.za" style="color:#0f4c9e;">support@salesdesk.co.za</a>.
</p>
HTML;
    return sendEmail($execEmail, "Your join request for {$dealerName} was declined", $body);
}


// ============================================================
// DEALER VERIFICATION HELPERS  (D-11)
// Called by admin panel when approving/rejecting CIPC verification.
//
// $dealer array must contain: email, company_name
// ============================================================

/**
 * Notify a dealer their CIPC verification was approved.
 *
 * @param array $dealer  Must have keys: email, company_name
 */
function sendDealerVerified(array $dealer): bool
{
    $to          = $dealer['email'];
    $companyName = htmlspecialchars($dealer['company_name'] ?? 'your dealership');
    $dashUrl     = SITE_URL . '/app/dealer/dashboard.php';

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#15803d;margin:0 0 8px;">
  ✓ Dealership verified
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 12px;">
  <strong>{$companyName}</strong> has been verified on SalesDesk.
</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  Your dealership now displays a verified badge in broker search results and will be
  ranked higher in the marketplace.
</p>
<a href="{$dashUrl}" style="display:inline-block;background:#0f4c9e;color:#fff;
   font-size:15px;font-weight:600;padding:12px 26px;border-radius:8px;text-decoration:none;">
  Go to dashboard →
</a>
HTML;

    return sendEmail($to, "{$companyName} is now verified on SalesDesk", $body);
}

/**
 * Notify a dealer their CIPC verification was rejected.
 *
 * @param array  $dealer  Must have keys: email, company_name
 * @param string $reason  Rejection reason from admin
 */
function sendDealerVerificationRejected(array $dealer, string $reason): bool
{
    $to          = $dealer['email'];
    $companyName = htmlspecialchars($dealer['company_name'] ?? 'your dealership');
    $supportUrl  = 'mailto:support@salesdesk.co.za';

    $reasonBlock = $reason
        ? "<p style=\"font-size:13px;color:#7f1d1d;background:#fef2f2;border:1px solid #fecaca;"
          . "border-radius:8px;padding:12px 14px;margin:0 0 18px;\"><strong>Reason:</strong> "
          . htmlspecialchars($reason) . "</p>"
        : '';

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#dc2626;margin:0 0 8px;">
  Verification update — action required
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 12px;">
  We reviewed the CIPC documentation submitted for <strong>{$companyName}</strong>
  and were unable to complete verification at this time.
</p>
{$reasonBlock}
<p style="font-size:14px;color:#475569;line-height:1.65;margin:0 0 20px;">
  <strong>Next steps:</strong> Please re-upload a clear copy of your CIPC certificate
  from your dealer dashboard. If you need assistance, reply to this email or contact us at
  <a href="{$supportUrl}" style="color:#0f4c9e;">support@salesdesk.co.za</a>.
</p>
HTML;

    return sendEmail($to, "Verification update for {$companyName} — action required", $body);
}


// ============================================================
// BUYER CONFIRMATION  (D-03)
//
// MVP: channel is always 'email'.
// v2 upgrade: swap implementation to add 'sms' / 'whatsapp'
// without touching lead submit logic.
//
// Contract: T4 calls this — never calls sendEmail() directly
// from the lead submit handler.
// ============================================================

/**
 * Send buyer a confirmation after lead submission.
 *
 * @param array  $lead    Must have: buyer_name, buyer_email, dealer_name (from join),
 *                        car_make, car_model, car_year (from join)
 * @param string $channel 'email' (MVP) | 'sms' | 'whatsapp' (v2)
 */
function sendBuyerConfirmation(array $lead, string $channel = 'email'): bool
{
    // v2 hook: check channel and delegate to appropriate sender.
    if ($channel !== 'email') {
        // Placeholder: future SMS/WhatsApp dispatch.
        // TODO v2: integrate Twilio or local SA provider here.
        error_log("[SalesDesk mailer] sendBuyerConfirmation: channel '{$channel}' not yet implemented. Falling back to email.");
    }

    // MVP: email only.
    $to         = $lead['buyer_email'] ?? '';
    $buyerName  = htmlspecialchars($lead['buyer_name']  ?? 'there');
    $dealerName = htmlspecialchars($lead['dealer_name'] ?? 'the dealer');
    $carLabel   = htmlspecialchars(
        ($lead['car_year']  ?? '') . ' ' .
        ($lead['car_make']  ?? '') . ' ' .
        ($lead['car_model'] ?? '')
    );

    // No email address = nothing to send (buyer_email is optional).
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return true; // Silently succeed — phone-only buyers don't get email.
    }

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  We received your enquiry!
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 8px;">
  Hi {$buyerName},
</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  <strong>{$dealerName}</strong> has received your enquiry about the <strong>{$carLabel}</strong>
  and will be in touch with you shortly.
</p>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;
            padding:14px 18px;margin:0 0 20px;">
  <p style="font-size:13px;color:#166534;margin:0;">
    ✓ Your details have been securely submitted. The dealer's response usually arrives within
    24 hours during business days.
  </p>
</div>
<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
  Your information will not be shared with third parties. If you have any concerns,
  contact us at
  <a href="mailto:support@salesdesk.co.za" style="color:#0f4c9e;">support@salesdesk.co.za</a>.
</p>
HTML;

    return sendEmail($to, "Enquiry received — {$carLabel}", $body);
}


// ============================================================
// COMMISSION INVOICE  (D-08)
//
// Sends invoice to dealer when a deal is closed.
// Invoice format: sequential INV-{YYYY}-{zero-padded commission id}
// No PDF for MVP — HTML email only.
//
// $dealer     — must have: email, company_name
// $lead       — must have: uuid, buyer_name, car_make, car_model, car_year, car_price
// $commission — must have: id, gross_amount, platform_fee, net_amount
// ============================================================

/**
 * Send a commission invoice to the dealer on deal close.
 *
 * @param array $dealer
 * @param array $lead
 * @param array $commission
 */
function sendDealerCommissionInvoice(
    array $dealer,
    array $lead,
    array $commission
): bool {
    $to          = $dealer['email'];
    $companyName = htmlspecialchars($dealer['company_name'] ?? 'Your Dealership');

    $prefix      = getPlatformConfig('invoice_number_prefix', 'INV');
    $invoiceNum  = $prefix . '-' . date('Y') . '-' . str_pad((string)($commission['id'] ?? 0), 5, '0', STR_PAD_LEFT);

    $carLabel    = htmlspecialchars(
        ($lead['car_year']  ?? '') . ' ' .
        ($lead['car_make']  ?? '') . ' ' .
        ($lead['car_model'] ?? '')
    );
    $buyerName   = htmlspecialchars($lead['buyer_name'] ?? '—');
    $leadRef     = $lead['uuid']        ?? '—';
    $gross       = 'R ' . number_format((float)($commission['gross_amount']  ?? 0), 2);
    $fee         = 'R ' . number_format((float)($commission['platform_fee']  ?? 0), 2);
    $net         = 'R ' . number_format((float)($commission['net_amount']    ?? 0), 2);
    $salePrice   = isset($lead['car_price'])
        ? 'R ' . number_format((float)$lead['car_price'], 2)
        : '—';

    $today       = date('d F Y');

    // EFT banking details.
    $bankName    = htmlspecialchars(defined('SALESDESK_BANK_NAME')   ? SALESDESK_BANK_NAME   : 'FNB');
    $bankAccount = htmlspecialchars(defined('SALESDESK_BANK_ACC')    ? SALESDESK_BANK_ACC    : '62000000000');
    $bankBranch  = htmlspecialchars(defined('SALESDESK_BANK_BRANCH') ? SALESDESK_BANK_BRANCH : '250655');

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 4px;">
  Commission Invoice
</h2>
<p style="font-size:13px;color:#94a3b8;margin:0 0 20px;font-family:monospace;">
  {$invoiceNum}
</p>

<!-- Invoice meta -->
<table cellpadding="0" cellspacing="0" width="100%"
       style="font-size:13px;border-collapse:collapse;margin:0 0 20px;">
  <tr>
    <td style="color:#64748b;padding:4px 0;width:40%;">Invoice date</td>
    <td style="color:#1e293b;font-weight:600;">{$today}</td>
  </tr>
  <tr>
    <td style="color:#64748b;padding:4px 0;">Billed to</td>
    <td style="color:#1e293b;font-weight:600;">{$companyName}</td>
  </tr>
  <tr>
    <td style="color:#64748b;padding:4px 0;">Lead reference</td>
    <td style="color:#1e293b;font-family:monospace;font-size:11px;">{$leadRef}</td>
  </tr>
  <tr>
    <td style="color:#64748b;padding:4px 0;">Vehicle</td>
    <td style="color:#1e293b;font-weight:600;">{$carLabel}</td>
  </tr>
  <tr>
    <td style="color:#64748b;padding:4px 0;">Buyer</td>
    <td style="color:#1e293b;">{$buyerName}</td>
  </tr>
  <tr>
    <td style="color:#64748b;padding:4px 0;">Sale price</td>
    <td style="color:#1e293b;">{$salePrice}</td>
  </tr>
</table>

<!-- Commission breakdown -->
<table cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse;background:#f8faff;border:1px solid #e2e8f0;
              border-radius:10px;overflow:hidden;margin:0 0 20px;">
  <tr style="background:#eff4ff;border-bottom:1px solid #bfdbfe;">
    <td style="padding:10px 16px;font-size:11px;font-weight:700;letter-spacing:.06em;
               text-transform:uppercase;color:#64748b;" colspan="2">
      Commission breakdown
    </td>
  </tr>
  <tr style="border-bottom:1px solid #e2e8f0;">
    <td style="padding:10px 16px;font-size:13px;color:#475569;">Broker commission (gross)</td>
    <td style="padding:10px 16px;font-size:13px;font-family:monospace;text-align:right;
               color:#1e293b;">{$gross}</td>
  </tr>
  <tr style="border-bottom:1px solid #e2e8f0;">
    <td style="padding:10px 16px;font-size:13px;color:#475569;">SalesDesk platform fee</td>
    <td style="padding:10px 16px;font-size:13px;font-family:monospace;text-align:right;
               color:#dc2626;">&minus; {$fee}</td>
  </tr>
  <tr style="background:#f0fdf4;">
    <td style="padding:12px 16px;font-size:14px;font-weight:700;color:#1e293b;">
      Amount due to SalesDesk
    </td>
    <td style="padding:12px 16px;font-size:16px;font-family:monospace;font-weight:800;
               text-align:right;color:#0f4c9e;">{$gross}</td>
  </tr>
</table>

<!-- EFT payment details -->
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
            padding:14px 18px;margin:0 0 20px;">
  <p style="font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;
            letter-spacing:.05em;margin:0 0 8px;">EFT Payment Details</p>
  <p style="font-size:13px;color:#78350f;margin:0;line-height:1.8;">
    <strong>Bank:</strong> {$bankName}<br>
    <strong>Account number:</strong> {$bankAccount}<br>
    <strong>Branch code:</strong> {$bankBranch}<br>
    <strong>Reference:</strong> {$invoiceNum}
  </p>
</div>

<p style="font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
  Payment is due within 7 days of this invoice. Questions?
  <a href="mailto:accounts@salesdesk.co.za" style="color:#0f4c9e;">accounts@salesdesk.co.za</a>
</p>
HTML;

    return sendEmail(
        $to,
        "Commission Invoice {$invoiceNum} — {$carLabel}",
        $body
    );
}

// ── Internal helper used by mailer helpers ─────────────────
// (Avoids requiring functions.php in mailer to prevent circular deps)
if (!function_exists('getPlatformConfig')) {
    function getPlatformConfig(string $key, string $default = ''): string
    {
        static $cache = [];
        if (!isset($cache[$key])) {
            try {
                $pdo  = Database::getInstance();
                $stmt = $pdo->prepare("SELECT config_value FROM platform_config WHERE config_key = ?");
                $stmt->execute([$key]);
                $row         = $stmt->fetch();
                $cache[$key] = $row ? $row['config_value'] : null;
            } catch (Throwable) {
                $cache[$key] = null;
            }
        }
        return $cache[$key] ?? $default;
    }
}
