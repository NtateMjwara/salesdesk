<?php
/**
 * SalesDesk — Dealer Leads Pipeline.
 * T3 owns this file.
 *
 * Task d4:
 *   - List: buyer name, car, broker desk, intent badge, timestamp, status
 *   - Lead detail panel: full buyer info, attribution block (locked/immutable), notes
 *   - Pipeline: new->contacted->test_drive->negotiation->closed/lost
 *   - Each transition writes audit_log
 *   - "Lost" prompts reason dropdown
 *   - Deal close triggers commission modal (d5)
 *
 * EMAIL NOTIFICATIONS  (D-03 follow-up):
 *   On deal close, the dealer principal already received a full
 *   commission invoice email (sendDealerCommissionInvoice). The broker
 *   and the uploading sales exec (when applicable) previously only got
 *   in-app notifications.php rows. They now also receive an email via
 *   sendDealClosedEmail() -- a lighter notice without banking details,
 *   since neither is the invoice payee. Each recipient gets their own
 *   email; this is not a literal CC -- see includes/mailer.php.
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/mailer.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// Dealer record
$dealerStmt = $pdo->prepare("
    SELECT d.id AS dealer_id, d.company_name
    FROM dealers d WHERE d.user_id = ? AND d.is_active = 1
");
$dealerStmt->execute([$userId]);
$dealer = $dealerStmt->fetch();
if (!$dealer) redirect('/app/dealer/dashboard.php');

$dealerId = (int) $dealer['dealer_id'];
$csrf     = generateCSRFToken();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $leadId = (int) ($_POST['lead_id'] ?? 0);

    if ($leadId > 0) {
        $leadCheck = $pdo->prepare("SELECT id, status, car_id, broker_id FROM leads WHERE id = ? AND dealer_id = ?");
        $leadCheck->execute([$leadId, $dealerId]);
        $leadRow = $leadCheck->fetch();

        if ($leadRow && $action === 'update_status') {
            $validStatuses = ['new','contacted','test_drive','negotiation','closed','lost'];
            $newStatus     = $_POST['new_status'] ?? '';
            $notes         = trim($_POST['dealer_notes'] ?? '');
            $lostReason    = trim($_POST['lost_reason'] ?? '');

            if (in_array($newStatus, $validStatuses, true) && $newStatus !== $leadRow['status']) {
                $pdo->prepare("
                    UPDATE leads
                    SET status = ?, dealer_notes = COALESCE(NULLIF(?, ''), dealer_notes),
                        status_updated_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$newStatus, $notes ?: null, $leadId]);

                writeAuditLog('lead.status_changed', 'lead', $leadId,
                    ['status' => $leadRow['status']],
                    ['status' => $newStatus, 'notes' => $notes ?: null]);

                // On close -- create commission record (d5)
                if ($newStatus === 'closed') {
                    // Get car + commission info, including broker contact
                    // details so we can email them on close (D-03 follow-up).
                    $carStmt = $pdo->prepare("
                        SELECT c.price, c.commission_type, c.commission_value,
                               c.make, c.model, c.year, c.uploaded_by_exec_id,
                               l.broker_id, l.salesdesk_id, l.organization_id,
                               bu.email      AS broker_email,
                               bp.first_name AS broker_first,
                               bp.last_name  AS broker_last
                        FROM leads l
                        JOIN cars c        ON c.id = l.car_id
                        JOIN users bu      ON bu.id = l.broker_id
                        LEFT JOIN profiles bp ON bp.user_id = l.broker_id
                        WHERE l.id = ?
                    ");
                    $carStmt->execute([$leadId]);
                    $carData = $carStmt->fetch();

                    if ($carData) {
                        $feePercent = getPlatformConfigInt('platform_fee_percent', 10);
                        $gross      = $carData['commission_type'] === 'fixed'
                            ? (float) $carData['commission_value']
                            : round($carData['price'] * $carData['commission_value'] / 100, 2);
                        $fee        = round($gross * $feePercent / 100, 2);
                        $net        = round($gross - $fee, 2);

                        if ($gross > 0 && $net > 0) {
                            // Check if commission already exists
                            $existsCheck = $pdo->prepare("SELECT id FROM commissions WHERE lead_id = ?");
                            $existsCheck->execute([$leadId]);
                            if (!$existsCheck->fetch()) {
                                $commUuid = generateUuidV4();
                                $pdo->prepare("
                                    INSERT INTO commissions
                                        (uuid, lead_id, broker_id, organization_id, dealer_id,
                                         gross_amount, platform_fee, net_amount, status, created_at, updated_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
                                ")->execute([
                                    $commUuid, $leadId,
                                    $carData['broker_id'], $carData['organization_id'], $dealerId,
                                    $gross, $fee, $net
                                ]);

                                $commId = (int) $pdo->lastInsertId();
                                writeAuditLog('commission.created', 'commission', $commId, null,
                                    ['status' => 'pending', 'gross' => $gross, 'net' => $net]);

                                // Notify broker (in-app)
                                $pdo->prepare("
                                    INSERT INTO notifications (user_id, type, title, body, meta, created_at)
                                    VALUES (?, 'deal_closed', ?, ?, ?, NOW())
                                ")->execute([
                                    $carData['broker_id'],
                                    'Deal closed — commission pending',
                                    "Your lead on {$carData['year']} {$carData['make']} {$carData['model']} has been closed.",
                                    json_encode(['lead_id' => $leadId, 'commission_id' => $commId]),
                                ]);

                                // Send invoice to dealer principal
                                $dealerEmailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                                $dealerEmailStmt->execute([$userId]);
                                $dealerEmail = $dealerEmailStmt->fetchColumn();

                                $leadForInvoice = $pdo->prepare("
                                    SELECT l.uuid, l.buyer_name, l.buyer_email,
                                           c.make AS car_make, c.model AS car_model,
                                           c.year AS car_year, c.price AS car_price
                                    FROM leads l JOIN cars c ON c.id = l.car_id WHERE l.id = ?
                                ");
                                $leadForInvoice->execute([$leadId]);
                                $invoiceLead = $leadForInvoice->fetch();

                                sendDealerCommissionInvoice(
                                    ['email' => $dealerEmail, 'company_name' => $dealer['company_name']],
                                    $invoiceLead,
                                    ['id' => $commId, 'gross_amount' => $gross,
                                     'platform_fee' => $fee, 'net_amount' => $net]
                                );

                                // Email broker -- was in-app only until now.
                                // Lighter-weight than the dealer invoice: no banking
                                // details, since the broker is not the invoice payee.
                                sendDealClosedEmail(
                                    $carData['broker_email'] ?? '',
                                    trim(($carData['broker_first'] ?? '') . ' ' . ($carData['broker_last'] ?? '')),
                                    'broker',
                                    [
                                        'buyer_name' => $invoiceLead['buyer_name'] ?? '',
                                        'car_make'   => $carData['make'],
                                        'car_model'  => $carData['model'],
                                        'car_year'   => $carData['year'],
                                        'net_amount' => $net,
                                    ]
                                );

                                // Email sales exec -- only if the car was uploaded by a
                                // verified exec. Scoped inside the commission-creation
                                // block so it only fires on a successful close, not on
                                // a duplicate commission attempt.
                                if (!empty($carData['uploaded_by_exec_id'])) {
                                    $execEmailStmt = $pdo->prepare("
                                        SELECT eu.email AS exec_email, ep.first_name AS exec_first
                                        FROM sales_executives se
                                        JOIN users eu ON eu.id = se.user_id
                                        LEFT JOIN profiles ep ON ep.user_id = se.user_id
                                        WHERE se.id = ? AND se.verification_status = 'verified'
                                        LIMIT 1
                                    ");
                                    $execEmailStmt->execute([$carData['uploaded_by_exec_id']]);
                                    $execContact = $execEmailStmt->fetch();

                                    if ($execContact && !empty($execContact['exec_email'])) {
                                        sendDealClosedEmail(
                                            $execContact['exec_email'],
                                            $execContact['exec_first'] ?? '',
                                            'sales_exec',
                                            [
                                                'buyer_name' => $invoiceLead['buyer_name'] ?? '',
                                                'car_make'   => $carData['make'],
                                                'car_model'  => $carData['model'],
                                                'car_year'   => $carData['year'],
                                                // net_amount intentionally omitted for exec:
                                                // sendDealClosedEmail() only renders the
                                                // commission figure when roleLabel === 'broker'.
                                            ]
                                        );
                                    }
                                }

                                $_SESSION['flash_ok'] = "Deal closed. Commission of R " . number_format($net, 0) . " created for the broker.";
                            }
                        }
                    }
                } elseif ($newStatus === 'lost' && $lostReason) {
                    $pdo->prepare("
                        UPDATE leads SET dealer_notes = ?, updated_at = NOW() WHERE id = ?
                    ")->execute([$lostReason, $leadId]);
                }

                if (!isset($_SESSION['flash_ok'])) {
                    $_SESSION['flash_ok'] = "Lead status updated to " . str_replace('_', ' ', $newStatus) . ".";
                }

                // In-app notification to exec on every status change
                // (separate from the close-specific email above).
                $execNotifStmt = $pdo->prepare("
                    SELECT se.user_id FROM sales_executives se
                    JOIN cars c ON c.uploaded_by_exec_id = se.id
                    JOIN leads l ON l.car_id = c.id
                    WHERE l.id = ? AND se.verification_status = 'verified'
                ");
                $execNotifStmt->execute([$leadId]);
                $execUser = $execNotifStmt->fetch();
                if ($execUser) {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
                        VALUES (?, 'lead_status_changed', ?, ?, ?, NOW())
                    ")->execute([
                        $execUser['user_id'],
                        'Lead status updated',
                        "A lead on your listing was updated to: " . str_replace('_', ' ', $newStatus),
                        json_encode(['lead_id' => $leadId]),
                    ]);
                }
            }
        }
    }
    redirect('/app/dealer/leads.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
}

// Filter state
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$detailId     = (int) ($_GET['id'] ?? 0);

// Status counts
$countsStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt FROM leads WHERE dealer_id = ? GROUP BY status
");
$countsStmt->execute([$dealerId]);
$statusCounts = array_fill_keys(['new','contacted','test_drive','negotiation','closed','lost'], 0);
foreach ($countsStmt->fetchAll() as $r) {
    $statusCounts[$r['status']] = (int) $r['cnt'];
}

// Load leads list
$where  = ['l.dealer_id = ?'];
$params = [$dealerId];
if ($filterStatus) { $where[] = 'l.status = ?'; $params[] = $filterStatus; }
if ($search) {
    $where[]  = '(l.buyer_name LIKE ? OR c.make LIKE ? OR c.model LIKE ?)';
    $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%";
}
$wc = implode(' AND ', $where);

$leadsStmt = $pdo->prepare("
    SELECT
        l.id, l.uuid, l.buyer_name, l.buyer_phone, l.buyer_email,
        l.buyer_intent, l.status, l.created_at, l.status_updated_at,
        l.dealer_notes,
        c.make, c.model, c.year, c.price, c.commission_type, c.commission_value,
        u.email AS broker_email,
        p.first_name AS broker_first, p.last_name AS broker_last,
        sd.display_name AS desk_name,
        cm.id AS commission_id, cm.status AS commission_status,
        cm.net_amount AS commission_net
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    JOIN users u ON u.id = l.broker_id
    LEFT JOIN profiles p ON p.user_id = l.broker_id
    LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
    LEFT JOIN commissions cm ON cm.lead_id = l.id
    WHERE {$wc}
    ORDER BY l.created_at DESC
    LIMIT 200
");
$leadsStmt->execute($params);
$leads = $leadsStmt->fetchAll();

// Load detail lead if requested
$detailLead = null;
if ($detailId > 0) {
    $detailStmt = $pdo->prepare("
        SELECT
            l.*,
            c.make, c.model, c.year, c.price, c.commission_type, c.commission_value,
            c.uploaded_by_exec_id,
            u.email AS broker_email,
            p.first_name AS broker_first, p.last_name AS broker_last,
            sd.display_name AS desk_name, sd.slug AS desk_slug,
            o.name AS org_name,
            cm.id AS commission_id, cm.status AS commission_status,
            cm.gross_amount, cm.net_amount,
            ep.first_name AS exec_first, ep.last_name AS exec_last
        FROM leads l
        JOIN cars c ON c.id = l.car_id
        JOIN users u ON u.id = l.broker_id
        LEFT JOIN profiles p ON p.user_id = l.broker_id
        LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
        LEFT JOIN organizations o ON o.id = l.organization_id
        LEFT JOIN commissions cm ON cm.lead_id = l.id
        LEFT JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
        LEFT JOIN profiles ep ON ep.user_id = se.user_id
        WHERE l.id = ? AND l.dealer_id = ?
    ");
    $detailStmt->execute([$detailId, $dealerId]);
    $detailLead = $detailStmt->fetch();
}

// Helpers
function intentBadge(string $intent): string {
    return match($intent) {
        'within_30d' => '<span class="badge" style="background:var(--gr-bg);color:var(--green);border-color:var(--gr-b);">&#x1F525; Hot</span>',
        'one_to_3mo' => '<span class="badge" style="background:var(--amb-bg);color:var(--amber);border-color:var(--amb-b);">Warm</span>',
        default      => '<span class="badge badge-suspended">Browsing</span>',
    };
}

function statusPill(string $status): string {
    $cfg = match($status) {
        'new'         => ['var(--p-light)',  'var(--p)',     'var(--p-b)',   'New'],
        'contacted'   => ['var(--amb-bg)',   'var(--amber)', 'var(--amb-b)', 'Contacted'],
        'test_drive'  => ['var(--teal-bg)',  'var(--teal)',  'var(--teal-b)','Test Drive'],
        'negotiation' => ['var(--pur-bg)',   'var(--purple)','var(--pur-b)', 'Negotiation'],
        'closed'      => ['var(--gr-bg)',    'var(--green)', 'var(--gr-b)',  'Closed'],
        'lost'        => ['var(--bg)',       'var(--faint)', 'var(--border)','Lost'],
        default       => ['var(--bg)',       'var(--muted)', 'var(--border)', $status],
    };
    return "<span style=\"display:inline-block;font-size:10px;font-family:var(--mono);font-weight:600;
        padding:3px 9px;border-radius:var(--r-full);letter-spacing:.03em;
        background:{$cfg[0]};color:{$cfg[1]};border:1px solid {$cfg[2]};\">{$cfg[3]}</span>";
}

$pipelineStages = ['new','contacted','test_drive','negotiation','closed','lost'];
$pageTitle = 'Leads';

ob_start();
?>

<div style="display:flex;align-items:flex-start;gap:1.5rem;">

  <!-- LEADS LIST PANEL -->
  <div style="flex:1;min-width:0;">

    <div style="display:flex;align-items:center;justify-content:space-between;
                margin-bottom:1.25rem;flex-wrap:wrap;gap:10px;">
      <h1 style="font-family:var(--serif);font-size:1.5rem;font-weight:300;">
        Lead <em style="font-style:italic;">pipeline</em>
      </h1>
    </div>

    <!-- Status filter tabs -->
    <div style="display:flex;gap:2px;margin-bottom:1rem;overflow-x:auto;padding-bottom:2px;">
      <?php
      $tabDefs = [
        ''            => 'All (' . array_sum($statusCounts) . ')',
        'new'         => 'New (' . $statusCounts['new'] . ')',
        'contacted'   => 'Contacted (' . $statusCounts['contacted'] . ')',
        'test_drive'  => 'Test Drive (' . $statusCounts['test_drive'] . ')',
        'negotiation' => 'Negotiation (' . $statusCounts['negotiation'] . ')',
        'closed'      => 'Closed (' . $statusCounts['closed'] . ')',
        'lost'        => 'Lost (' . $statusCounts['lost'] . ')',
      ];
      foreach ($tabDefs as $val => $label):
        $active = $filterStatus === $val;
      ?>
      <a href="?status=<?= urlencode($val) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
         style="white-space:nowrap;padding:6px 12px;border-radius:var(--r-sm);
                font-size:12px;font-weight:<?= $active ? '600' : '400' ?>;
                text-decoration:none;transition:all .15s;
                background:<?= $active ? 'var(--p)' : 'transparent' ?>;
                color:<?= $active ? '#fff' : 'var(--muted)' ?>;">
        <?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Search -->
    <form method="GET" style="margin-bottom:1rem;display:flex;gap:8px;">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <input class="finput" name="q" placeholder="Search buyer name, make, model&hellip;"
             value="<?= htmlspecialchars($search) ?>" style="max-width:260px;">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
      <?php if ($search): ?>
      <a href="?status=<?= urlencode($filterStatus) ?>" class="btn btn-ghost btn-sm"
         style="text-decoration:none;">Clear</a>
      <?php endif; ?>
    </form>

    <!-- Leads table -->
    <?php if (empty($leads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox"></i></span>
      No leads match your filters.
    </div>
    <?php else: ?>
    <div class="roster-wrap">
      <table class="roster">
        <thead>
          <tr>
            <th>Buyer</th>
            <th>Vehicle</th>
            <th>Broker</th>
            <th>Intent</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($leads as $lead):
          $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
          $ageLabel = $ageHours < 24
            ? round($ageHours) . 'h ago'
            : (round($ageHours / 24) . 'd ago');
          $isSelected = $detailId === (int)$lead['id'];
        ?>
        <tr style="cursor:pointer;<?= $isSelected ? 'background:var(--p-light);' : '' ?>"
            onclick="window.location='/app/dealer/leads.php?id=<?= $lead['id'] ?>&status=<?= urlencode($filterStatus) ?>'">
          <td>
            <div style="font-weight:500;color:var(--text);font-size:13px;">
              <?= htmlspecialchars($lead['buyer_name']) ?>
            </div>
            <div style="font-size:11px;color:var(--faint);">
              <?= htmlspecialchars($lead['buyer_phone']) ?>
            </div>
          </td>
          <td style="font-size:12px;">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
          </td>
          <td style="font-size:12px;color:var(--muted);">
            <?= htmlspecialchars($lead['desk_name'] ?? $lead['broker_email']) ?>
          </td>
          <td><?= intentBadge($lead['buyer_intent']) ?></td>
          <td><?= statusPill($lead['status']) ?></td>
          <td style="font-size:11px;color:var(--faint);"><?= $ageLabel ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- LEAD DETAIL PANEL -->
  <?php if ($detailLead): ?>
  <div style="width:360px;flex-shrink:0;" id="detailPanel">
    <div class="card" style="overflow:hidden;position:sticky;top:76px;">

      <!-- Header -->
      <div style="padding:16px 18px;border-bottom:1px solid var(--border);
                  background:var(--bg);display:flex;align-items:flex-start;
                  justify-content:space-between;gap:10px;">
        <div>
          <div style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:3px;">
            <?= htmlspecialchars($detailLead['buyer_name']) ?>
          </div>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <?= intentBadge($detailLead['buyer_intent']) ?>
            <?= statusPill($detailLead['status']) ?>
          </div>
        </div>
        <a href="/app/dealer/leads.php?status=<?= urlencode($filterStatus) ?>"
           style="color:var(--faint);text-decoration:none;font-size:18px;flex-shrink:0;">
          <i class="fa-solid fa-xmark"></i>
        </a>
      </div>

      <div style="padding:16px 18px;overflow-y:auto;max-height:calc(100vh - 200px);">

        <!-- Buyer info -->
        <div style="margin-bottom:1.25rem;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--faint);margin-bottom:8px;">
            Buyer details
          </div>
          <div style="font-size:13px;color:var(--text2);line-height:2;">
            <a href="tel:<?= htmlspecialchars($detailLead['buyer_phone']) ?>"
               style="color:var(--p);font-weight:600;text-decoration:none;">
              <i class="fa-solid fa-phone" style="font-size:11px;margin-right:5px;"></i>
              <?= htmlspecialchars($detailLead['buyer_phone']) ?>
            </a>
            <?php if ($detailLead['buyer_email']): ?>
            <br>
            <a href="mailto:<?= htmlspecialchars($detailLead['buyer_email']) ?>"
               style="color:var(--p);text-decoration:none;">
              <i class="fa-solid fa-envelope" style="font-size:11px;margin-right:5px;"></i>
              <?= htmlspecialchars($detailLead['buyer_email']) ?>
            </a>
            <?php endif; ?>
          </div>
          <?php if ($detailLead['buyer_message']): ?>
          <div style="margin-top:8px;font-size:12px;color:var(--muted);
                      background:var(--bg);border-radius:var(--r-sm);
                      padding:10px 12px;border:1px solid var(--border);
                      font-style:italic;line-height:1.6;">
            &ldquo;<?= htmlspecialchars($detailLead['buyer_message']) ?>&rdquo;
          </div>
          <?php endif; ?>
        </div>

        <!-- Attribution block — LOCKED/IMMUTABLE per architecture rule -->
        <div style="margin-bottom:1.25rem;background:var(--p-light);
                    border:1px solid var(--p-b);border-radius:var(--r-md);
                    padding:12px 14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--p);margin-bottom:8px;
                      display:flex;align-items:center;gap:5px;">
            <i class="fa-solid fa-lock" style="font-size:9px;"></i>
            Attribution (locked)
          </div>
          <div style="font-size:12px;color:var(--p-dark);line-height:1.9;">
            <strong>Broker:</strong>
            <?= htmlspecialchars(trim(($detailLead['broker_first'] ?? '') . ' ' . ($detailLead['broker_last'] ?? '')) ?: $detailLead['broker_email']) ?>
            <br>
            <strong>Desk:</strong>
            <?= htmlspecialchars($detailLead['desk_name'] ?? '&mdash;') ?>
            <?php if ($detailLead['org_name']): ?>
            <br><strong>Org:</strong> <?= htmlspecialchars($detailLead['org_name']) ?>
            <?php endif; ?>
            <?php if ($detailLead['exec_first'] ?? false): ?>
            <br><strong>Listed by:</strong>
            <?= htmlspecialchars(trim($detailLead['exec_first'] . ' ' . $detailLead['exec_last'])) ?> (exec)
            <?php endif; ?>
            <br><strong>Attributed:</strong>
            <?= date('d M Y H:i', strtotime($detailLead['attributed_at'])) ?>
          </div>
        </div>

        <!-- Car info -->
        <div style="margin-bottom:1.25rem;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--faint);margin-bottom:8px;">
            Vehicle
          </div>
          <div style="font-size:13px;font-weight:600;color:var(--text);">
            <?= htmlspecialchars("{$detailLead['year']} {$detailLead['make']} {$detailLead['model']}") ?>
          </div>
          <div style="font-size:12px;color:var(--muted);">
            R <?= number_format($detailLead['price'], 0) ?>
          </div>
        </div>

        <!-- Commission status (if deal closed) -->
        <?php if ($detailLead['commission_id']): ?>
        <div style="margin-bottom:1.25rem;background:var(--gr-bg);
                    border:1px solid var(--gr-b);border-radius:var(--r-md);
                    padding:12px 14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--green);margin-bottom:5px;">
            Commission
          </div>
          <div style="font-size:14px;font-weight:700;color:var(--green);">
            R <?= number_format($detailLead['net_amount'], 0) ?> net
          </div>
          <div style="font-size:11px;color:var(--muted);">
            Status: <?= htmlspecialchars($detailLead['commission_status']) ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Pipeline status update form -->
        <?php if (!in_array($detailLead['status'], ['closed','lost'])): ?>
        <form method="POST" id="statusForm">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="lead_id" value="<?= $detailLead['id'] ?>">

          <div style="margin-bottom:1rem;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                        letter-spacing:.06em;color:var(--faint);margin-bottom:8px;">
              Pipeline stage
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
              <?php
              $stages = [
                ['new',         'New'],
                ['contacted',   'Contacted'],
                ['test_drive',  'Test Drive'],
                ['negotiation', 'Negotiation'],
              ];
              foreach ($stages as [$sv, $sl]):
                $isCurrent = $detailLead['status'] === $sv;
              ?>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                             padding:8px 10px;border-radius:var(--r-md);
                             border:1px solid <?= $isCurrent ? 'var(--p-b)' : 'var(--border)' ?>;
                             background:<?= $isCurrent ? 'var(--p-light)' : 'var(--bg)' ?>;">
                <input type="radio" name="new_status" value="<?= $sv ?>"
                       <?= $isCurrent ? 'checked' : '' ?>
                       style="accent-color:var(--p);">
                <span style="font-size:12px;font-weight:<?= $isCurrent ? '600' : '400' ?>;
                             color:<?= $isCurrent ? 'var(--p)' : 'var(--text2)' ?>;">
                  <?= $sl ?>
                </span>
                <?php if ($isCurrent): ?>
                <span style="margin-left:auto;font-size:9px;font-family:var(--mono);
                             color:var(--p);font-weight:700;">CURRENT</span>
                <?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="fgroup">
            <label class="flabel" for="dealer_notes">
              Notes <span class="flabel-opt">(internal &mdash; not shown to broker or buyer)</span>
            </label>
            <textarea class="finput" id="dealer_notes" name="dealer_notes"
                      rows="3" maxlength="500"
                      placeholder="Call notes, test drive feedback&hellip;"><?= htmlspecialchars($detailLead['dealer_notes'] ?? '') ?></textarea>
          </div>

          <div style="display:flex;gap:6px;">
            <button class="btn btn-primary btn-sm" type="submit" style="flex:1;">
              Update status
            </button>
            <button class="btn btn-success btn-sm" type="button"
                    onclick="openCloseModal()">
              <i class="fa-solid fa-check"></i> Close deal
            </button>
          </div>
        </form>

        <!-- Mark as lost -->
        <button class="btn btn-ghost btn-sm"
                style="width:100%;margin-top:6px;color:var(--faint);"
                onclick="openLostModal()">
          Mark as lost
        </button>

        <?php else: ?>
        <!-- Already closed/lost -->
        <div style="text-align:center;padding:12px 0;">
          <?= statusPill($detailLead['status']) ?>
          <?php if ($detailLead['dealer_notes']): ?>
          <div style="font-size:12px;color:var(--muted);margin-top:8px;
                      font-style:italic;line-height:1.6;">
            <?= htmlspecialchars($detailLead['dealer_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div><!-- /scroll area -->
    </div>
  </div>

  <!-- Close Deal Modal -->
  <div class="modal-bg" id="closeModal">
    <div class="modal">
      <div class="modal-title" style="color:var(--green);">
        <i class="fa-solid fa-check-circle"></i> Close this deal
      </div>
      <p class="modal-sub">
        Closing this lead will generate a commission invoice for
        <strong><?= htmlspecialchars(trim(($detailLead['broker_first'] ?? '') . ' ' . ($detailLead['broker_last'] ?? '')) ?: $detailLead['broker_email']) ?></strong>
        and notify them by email.
      </p>
      <?php
        $feeP  = getPlatformConfigInt('platform_fee_percent', 10);
        $gross = $detailLead['commission_type'] === 'fixed'
          ? (float) $detailLead['commission_value']
          : round($detailLead['price'] * $detailLead['commission_value'] / 100, 2);
        $fee   = round($gross * $feeP / 100, 2);
        $net   = round($gross - $fee, 2);
      ?>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);
                  padding:14px;margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <span style="font-size:12px;color:var(--muted);">Gross commission</span>
          <span style="font-size:13px;font-family:var(--mono);">R <?= number_format($gross, 0) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
          <span style="font-size:12px;color:var(--muted);">Platform fee (<?= $feeP ?>%)</span>
          <span style="font-size:13px;font-family:var(--mono);color:var(--red);">- R <?= number_format($fee, 0) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding-top:8px;
                    border-top:1px solid var(--border);">
          <span style="font-size:13px;font-weight:600;">Net to broker</span>
          <span style="font-size:15px;font-weight:700;font-family:var(--mono);color:var(--green);">
            R <?= number_format($net, 0) ?>
          </span>
        </div>
      </div>
      <form method="POST" id="closeForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="lead_id" value="<?= $detailLead['id'] ?>">
        <input type="hidden" name="new_status" value="closed">
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="closeModals()">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="fa-solid fa-check"></i> Confirm &amp; close deal
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Lost Modal -->
  <div class="modal-bg" id="lostModal">
    <div class="modal">
      <div class="modal-title">Mark lead as lost</div>
      <p class="modal-sub">What was the reason this deal didn't go through?</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="lead_id" value="<?= $detailLead['id'] ?>">
        <input type="hidden" name="new_status" value="lost">
        <div class="fgroup">
          <label class="flabel">Reason</label>
          <select class="finput" name="lost_reason">
            <option value="Bought elsewhere">Bought a car elsewhere</option>
            <option value="Budget issues">Budget issues / no finance</option>
            <option value="No longer interested">No longer in the market</option>
            <option value="Price too high">Price was too high</option>
            <option value="Non-responsive">Buyer stopped responding</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="closeModals()">Cancel</button>
          <button type="submit" class="btn btn-danger">Mark as lost</button>
        </div>
      </form>
    </div>
  </div>

  <?php endif; // detailLead ?>

</div><!-- /flex container -->

<script>
function openCloseModal()  { document.getElementById('closeModal').classList.add('open'); }
function openLostModal()   { document.getElementById('lostModal').classList.add('open'); }
function closeModals() {
  document.querySelectorAll('.modal-bg').forEach(function(m){ m.classList.remove('open'); });
}
document.querySelectorAll('.modal-bg').forEach(function(bg) {
  bg.addEventListener('click', function(e) { if (e.target === bg) bg.classList.remove('open'); });
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
