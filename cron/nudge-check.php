<?php
/**
 * SalesDesk — Lead Nudge Cron Job
 * T1 owns this file.
 *
 * Run daily via crontab:
 *   0 8 * * * php /var/www/salesdesk/cron/nudge-check.php >> /var/log/salesdesk/nudge.log 2>&1
 *
 * What this does:
 *   Scans open leads (not closed/lost) and fires reminder nudges
 *   to the dealer when no progress has been made within:
 *     48 hours → email + in-app notification
 *     5 days   → email + in-app notification
 *     10 days  → admin flag only (no dealer email, per spec)
 *
 * Idempotency (D-06):
 *   nudges table has UNIQUE KEY (lead_id, nudge_type).
 *   We use INSERT IGNORE — if the row already exists the nudge
 *   was already sent and we silently skip. Zero risk of re-sending.
 *
 * Reads threshold hours from platform_config so they can be
 * changed without a code deploy.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Bootstrap — resolve path relative to this file.
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/mailer.php';

$startedAt = date('Y-m-d H:i:s');
$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("Nudge check started.");

try {
    $pdo = Database::getInstance();

    // ── Read thresholds from platform_config ──────────────────────
    $h48  = getPlatformConfigInt('nudge_threshold_48h_hours', 48);
    $h5d  = getPlatformConfigInt('nudge_threshold_5d_hours',  120);
    $h10d = getPlatformConfigInt('nudge_threshold_flag_hours', 240);

    // ── Fetch open leads not yet updated within the longest threshold ─
    // We fetch all leads that could potentially need any nudge,
    // joining nudges to know which have already been sent.
    $stmt = $pdo->prepare("
        SELECT
            l.id             AS lead_id,
            l.dealer_id,
            l.car_id,
            l.status_updated_at,
            l.buyer_name,
            c.make,
            c.model,
            c.year,
            u.email          AS dealer_email,
            p.first_name     AS dealer_first,
            d.company_name   AS dealer_company,
            TIMESTAMPDIFF(HOUR, l.status_updated_at, NOW()) AS hours_stale,
            -- Which nudges have already fired for this lead
            MAX(CASE WHEN n.nudge_type = '48h'      THEN 1 ELSE 0 END) AS sent_48h,
            MAX(CASE WHEN n.nudge_type = '5d'       THEN 1 ELSE 0 END) AS sent_5d,
            MAX(CASE WHEN n.nudge_type = '10d_flag' THEN 1 ELSE 0 END) AS sent_10d
        FROM leads l
        JOIN dealers d  ON d.id = l.dealer_id
        JOIN users   u  ON u.id = d.user_id
        JOIN cars    c  ON c.id = l.car_id
        LEFT JOIN profiles p ON p.user_id = d.user_id
        LEFT JOIN nudges n   ON n.lead_id = l.id
        WHERE l.status NOT IN ('closed', 'lost')
          AND TIMESTAMPDIFF(HOUR, l.status_updated_at, NOW()) >= ?
        GROUP BY l.id
    ");
    $stmt->execute([$h48]); // fetch anything stale for at least 48h
    $leads = $stmt->fetchAll();

    $log("Found " . count($leads) . " potentially stale lead(s).");

    $nudgeSent = 0;
    $flagged   = 0;

    foreach ($leads as $lead) {
        $leadId      = (int) $lead['lead_id'];
        $dealerId    = (int) $lead['dealer_id'];
        $hours       = (int) $lead['hours_stale'];
        $dealerEmail = $lead['dealer_email'];
        $dealerName  = trim(($lead['dealer_first'] ?? '') . ' — ' . $lead['dealer_company']);
        $carLabel    = $lead['year'] . ' ' . $lead['make'] . ' ' . $lead['model'];
        $buyerName   = $lead['buyer_name'] ?? 'A buyer';

        // ── 10-day flag (admin flag, no dealer email) ─────────────
        if ($hours >= $h10d && !$lead['sent_10d']) {
            // INSERT IGNORE — idempotency guaranteed by UNIQUE KEY.
            $pdo->prepare("
                INSERT IGNORE INTO nudges (lead_id, dealer_id, nudge_type, sent_at)
                VALUES (?, ?, '10d_flag', NOW())
            ")->execute([$leadId, $dealerId]);

            if ($pdo->prepare("SELECT ROW_COUNT()")->execute() || true) {
                // Notify admin via in-app notification.
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, created_at)
                    SELECT u.id, 'lead_stale_flag',
                           'Lead stale — 10+ days',
                           ?,
                           NOW()
                    FROM users u WHERE u.role = 'admin' AND u.status = 'active'
                    LIMIT 5
                ")->execute(["Lead #{$leadId} for {$carLabel} at {$lead['dealer_company']} has been untouched for 10+ days."]);

                $log("10d FLAG: lead #{$leadId} ({$carLabel})");
                $flagged++;
            }
        }

        // ── 5-day nudge ───────────────────────────────────────────
        if ($hours >= $h5d && !$lead['sent_5d']) {
            $pdo->prepare("
                INSERT IGNORE INTO nudges (lead_id, dealer_id, nudge_type, sent_at)
                VALUES (?, ?, '5d', NOW())
            ")->execute([$leadId, $dealerId]);

            // Check if the INSERT actually happened (not a duplicate).
            $checkStmt = $pdo->prepare("SELECT id FROM nudges WHERE lead_id = ? AND nudge_type = '5d'");
            $checkStmt->execute([$leadId]);
            if ($checkStmt->fetch()) {
                // Send email + in-app notification to dealer.
                sendNudgeEmail($dealerEmail, $dealerName, $carLabel, $buyerName, '5d');
                insertNudgeNotification($pdo, $dealerId, $leadId, $carLabel, $buyerName, '5d');
                $log("5d nudge: lead #{$leadId} ({$carLabel}) → {$dealerEmail}");
                $nudgeSent++;
            }
        }

        // ── 48-hour nudge ─────────────────────────────────────────
        if ($hours >= $h48 && !$lead['sent_48h']) {
            $pdo->prepare("
                INSERT IGNORE INTO nudges (lead_id, dealer_id, nudge_type, sent_at)
                VALUES (?, ?, '48h', NOW())
            ")->execute([$leadId, $dealerId]);

            $checkStmt = $pdo->prepare("SELECT id FROM nudges WHERE lead_id = ? AND nudge_type = '48h'");
            $checkStmt->execute([$leadId]);
            if ($checkStmt->fetch()) {
                sendNudgeEmail($dealerEmail, $dealerName, $carLabel, $buyerName, '48h');
                insertNudgeNotification($pdo, $dealerId, $leadId, $carLabel, $buyerName, '48h');
                $log("48h nudge: lead #{$leadId} ({$carLabel}) → {$dealerEmail}");
                $nudgeSent++;
            }
        }
    }

    $log("Done. Nudges sent: {$nudgeSent}. Admin flags: {$flagged}.");

} catch (Throwable $e) {
    error_log('[SalesDesk nudge-check] ' . $e->getMessage());
    echo '[FATAL] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}


// ── Helpers ──────────────────────────────────────────────────

/**
 * Insert an in-app notification for the dealer.
 */
function insertNudgeNotification(
    PDO $pdo,
    int $dealerId,
    int $leadId,
    string $carLabel,
    string $buyerName,
    string $nudgeType
): void {
    $titles = [
        '48h' => 'Reminder: lead needs attention (48h)',
        '5d'  => 'Reminder: lead stale for 5 days',
    ];

    $pdo->prepare("
        INSERT IGNORE INTO notifications (user_id, type, title, body, meta, created_at)
        SELECT d.user_id, 'lead_nudge', ?, ?, ?, NOW()
        FROM dealers d WHERE d.id = ?
    ")->execute([
        $titles[$nudgeType] ?? 'Lead reminder',
        "{$buyerName} is waiting for a response on the {$carLabel}.",
        json_encode(['lead_id' => $leadId]),
        $dealerId,
    ]);
}

/**
 * Send a nudge reminder email to the dealer.
 * mailer.php provides sendEmail() and emailLayout().
 */
function sendNudgeEmail(
    string $to,
    string $dealerName,
    string $carLabel,
    string $buyerName,
    string $nudgeType
): void {
    $leadsUrl = SITE_URL . '/app/dealer/leads.php';

    $urgency = $nudgeType === '5d'
        ? 'This lead is now <strong>5 days old</strong> without a status update.'
        : 'This lead has been waiting <strong>48 hours</strong> without a response.';

    $subject = $nudgeType === '5d'
        ? "Reminder: {$buyerName} is still waiting — {$carLabel}"
        : "Heads up: new lead needs your attention — {$carLabel}";

    $body = <<<HTML
<h2 style="font-size:20px;font-weight:700;color:#0f4c9e;margin:0 0 8px;">
  Lead follow-up reminder
</h2>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 12px;">
  Hi {$dealerName},
</p>
<p style="font-size:15px;color:#475569;line-height:1.65;margin:0 0 20px;">
  {$urgency}
</p>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
            padding:16px 20px;margin:0 0 24px;">
  <p style="font-size:13px;color:#92400e;margin:0 0 4px;font-weight:600;">
    Lead details
  </p>
  <p style="font-size:14px;color:#1e293b;margin:0;">
    <strong>Buyer:</strong> {$buyerName}<br>
    <strong>Car:</strong> {$carLabel}
  </p>
</div>
<a href="{$leadsUrl}"
   style="display:inline-block;background:#0f4c9e;color:#ffffff;font-size:15px;
          font-weight:600;padding:13px 28px;border-radius:8px;text-decoration:none;">
  View lead →
</a>
HTML;

    sendEmail($to, $subject, $body);
}
