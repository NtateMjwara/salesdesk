<?php
/**
 * SalesDesk — Sales Exec Leads.
 * T3 owns this file.
 *
 * Task sep3: JOIN leads ON leads.car_id = cars.id WHERE cars.uploaded_by_exec_id = se.id
 * Same pipeline and detail as dealer leads.
 * Exec can update status, add notes. Attribution display is immutable.
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/exec_guard.php';

applyCachePolicy('auth');

$exec     = requireExecVerified();
$execId   = (int) $exec['id'];
$dealerId = (int) $exec['dealer_id'];
$pdo      = Database::getInstance();
$csrf     = generateCSRFToken();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';
    $leadId = (int) ($_POST['lead_id'] ?? 0);

    if ($leadId > 0 && $action === 'update_status') {
        // Verify this lead is on an exec-uploaded car
        $check = $pdo->prepare("
            SELECT l.id, l.status FROM leads l
            JOIN cars c ON c.id = l.car_id
            WHERE l.id = ? AND c.uploaded_by_exec_id = ?
        ");
        $check->execute([$leadId, $execId]);
        $leadRow = $check->fetch();

        if ($leadRow) {
            $validStatuses = ['new','contacted','test_drive','negotiation'];
            $newStatus     = $_POST['new_status'] ?? '';
            $notes         = trim($_POST['dealer_notes'] ?? '');

            if (in_array($newStatus, $validStatuses, true) && $newStatus !== $leadRow['status']) {
                $pdo->prepare("
                    UPDATE leads
                    SET status = ?,
                        dealer_notes = COALESCE(NULLIF(?, ''), dealer_notes),
                        status_updated_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$newStatus, $notes ?: null, $leadId]);

                writeAuditLog('lead.status_changed', 'lead', $leadId,
                    ['status' => $leadRow['status']],
                    ['status' => $newStatus, 'updated_by_exec' => $execId]);

                $_SESSION['flash_ok'] = "Lead updated to " . str_replace('_', ' ', $newStatus) . ".";
            }
        }
    }
    redirect('/app/exec/leads.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
}

// ── Filter state ──────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$detailId     = (int) ($_GET['id'] ?? 0);

// ── Status counts — exec-scoped ───────────────────────────────
$countsStmt = $pdo->prepare("
    SELECT l.status, COUNT(*) AS cnt
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    WHERE c.uploaded_by_exec_id = ?
    GROUP BY l.status
");
$countsStmt->execute([$execId]);
$statusCounts = array_fill_keys(['new','contacted','test_drive','negotiation','closed','lost'], 0);
foreach ($countsStmt->fetchAll() as $r) {
    $statusCounts[$r['status']] = (int)$r['cnt'];
}

// ── Load leads — exec-scoped ──────────────────────────────────
$where  = ['c.uploaded_by_exec_id = ?'];
$params = [$execId];
if ($filterStatus) { $where[] = 'l.status = ?'; $params[] = $filterStatus; }
if ($search) {
    $where[] = '(l.buyer_name LIKE ? OR c.make LIKE ? OR c.model LIKE ?)';
    $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%";
}
$wc = implode(' AND ', $where);

$leadsStmt = $pdo->prepare("
    SELECT
        l.id, l.buyer_name, l.buyer_phone, l.buyer_intent,
        l.status, l.created_at, l.dealer_notes,
        c.make, c.model, c.year, c.price,
        p.first_name AS broker_first, p.last_name AS broker_last,
        sd.display_name AS desk_name
    FROM leads l
    JOIN cars c ON c.id = l.car_id
    JOIN users u ON u.id = l.broker_id
    LEFT JOIN profiles p ON p.user_id = l.broker_id
    LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
    WHERE {$wc}
    ORDER BY l.created_at DESC
    LIMIT 200
");
$leadsStmt->execute($params);
$leads = $leadsStmt->fetchAll();

// ── Detail lead ───────────────────────────────────────────────
$detailLead = null;
if ($detailId > 0) {
    $detailStmt = $pdo->prepare("
        SELECT
            l.id, l.uuid, l.buyer_name, l.buyer_phone, l.buyer_email,
            l.buyer_intent, l.buyer_message, l.status, l.created_at,
            l.attributed_at, l.dealer_notes, l.consent_given,
            c.make, c.model, c.year, c.price,
            p.first_name AS broker_first, p.last_name AS broker_last,
            u.email AS broker_email,
            sd.display_name AS desk_name,
            cm.id AS commission_id, cm.status AS commission_status
        FROM leads l
        JOIN cars c ON c.id = l.car_id
        JOIN users u ON u.id = l.broker_id
        LEFT JOIN profiles p ON p.user_id = l.broker_id
        LEFT JOIN salesdesks sd ON sd.id = l.salesdesk_id
        LEFT JOIN commissions cm ON cm.lead_id = l.id
        WHERE l.id = ? AND c.uploaded_by_exec_id = ?
    ");
    $detailStmt->execute([$detailId, $execId]);
    $detailLead = $detailStmt->fetch();
}

function intentLabel(string $intent): string {
    return match($intent) {
        'within_30d' => '🔥 Hot — within 30 days',
        'one_to_3mo' => '🌡 Warm — 1–3 months',
        default      => 'Browsing',
    };
}

$pageTitle = 'Leads';
ob_start();
?>

<div style="display:flex;align-items:flex-start;gap:1.5rem;">

  <!-- ── LEADS LIST ─────────────────────────────────────────── -->
  <div style="flex:1;min-width:0;">
    <div style="margin-bottom:1.25rem;">
      <h1 style="font-family:var(--serif);font-size:1.5rem;font-weight:300;">
        My lead <em style="font-style:italic;">pipeline</em>
      </h1>
      <p style="font-size:13px;color:var(--muted);margin-top:2px;">
        Leads on cars you uploaded at <?= htmlspecialchars($exec['dealer_name']) ?>
      </p>
    </div>

    <!-- Status tabs -->
    <div style="display:flex;gap:2px;margin-bottom:1rem;overflow-x:auto;">
      <?php
      $tabs = [
        ''            => 'All (' . array_sum($statusCounts) . ')',
        'new'         => 'New (' . $statusCounts['new'] . ')',
        'contacted'   => 'Contacted (' . $statusCounts['contacted'] . ')',
        'test_drive'  => 'Test Drive (' . $statusCounts['test_drive'] . ')',
        'negotiation' => 'Negotiation (' . $statusCounts['negotiation'] . ')',
        'closed'      => 'Closed (' . $statusCounts['closed'] . ')',
        'lost'        => 'Lost (' . $statusCounts['lost'] . ')',
      ];
      foreach ($tabs as $val => $lbl):
        $active = $filterStatus === $val;
      ?>
      <a href="?status=<?= urlencode($val) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
         style="white-space:nowrap;padding:6px 12px;border-radius:var(--r-sm);
                font-size:12px;font-weight:<?= $active ? '600' : '400' ?>;
                text-decoration:none;background:<?= $active ? 'var(--p)' : 'transparent' ?>;
                color:<?= $active ? '#fff' : 'var(--muted)' ?>;">
        <?= $lbl ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Search -->
    <form method="GET" style="display:flex;gap:8px;margin-bottom:1rem;">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <input class="finput" name="q" placeholder="Search buyer or vehicle…"
             value="<?= htmlspecialchars($search) ?>" style="max-width:240px;">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
      <?php if ($search): ?>
      <a href="?status=<?= urlencode($filterStatus) ?>" class="btn btn-ghost btn-sm"
         style="text-decoration:none;">Clear</a>
      <?php endif; ?>
    </form>

    <?php if (empty($leads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox"></i></span>
      No leads yet on your listings.
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
          $ageLabel = $ageHours < 24 ? round($ageHours) . 'h ago' : round($ageHours / 24) . 'd ago';
          $isSelected = $detailId === (int)$lead['id'];
          $intentColour = match($lead['buyer_intent']) {
            'within_30d' => 'var(--green)', 'one_to_3mo' => 'var(--amber)', default => 'var(--faint)'
          };
        ?>
        <tr style="cursor:pointer;<?= $isSelected ? 'background:var(--p-light)' : '' ?>"
            onclick="window.location='/app/exec/leads.php?id=<?= $lead['id'] ?>&status=<?= urlencode($filterStatus) ?>'">
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
            <?= htmlspecialchars($lead['desk_name'] ?? $lead['broker_email'] ?? '—') ?>
          </td>
          <td>
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                         background:<?= $intentColour ?>;margin-right:4px;"></span>
            <span style="font-size:11px;color:var(--muted);">
              <?= match($lead['buyer_intent']) {
                'within_30d' => 'Hot', 'one_to_3mo' => 'Warm', default => 'Browsing'
              } ?>
            </span>
          </td>
          <td>
            <span style="font-size:10px;font-family:var(--mono);font-weight:600;
                         text-transform:uppercase;color:var(--p);">
              <?= str_replace('_', ' ', $lead['status']) ?>
            </span>
          </td>
          <td style="font-size:11px;color:var(--faint);"><?= $ageLabel ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── DETAIL PANEL ──────────────────────────────────────── -->
  <?php if ($detailLead): ?>
  <div style="width:340px;flex-shrink:0;">
    <div class="card" style="overflow:hidden;position:sticky;top:76px;">

      <div style="padding:16px 18px;border-bottom:1px solid var(--border);background:var(--bg);
                  display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
        <div>
          <div style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:3px;">
            <?= htmlspecialchars($detailLead['buyer_name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);"><?= intentLabel($detailLead['buyer_intent']) ?></div>
        </div>
        <a href="/app/exec/leads.php?status=<?= urlencode($filterStatus) ?>"
           style="color:var(--faint);text-decoration:none;font-size:18px;">
          <i class="fa-solid fa-xmark"></i>
        </a>
      </div>

      <div style="padding:16px 18px;overflow-y:auto;max-height:calc(100vh - 200px);">

        <!-- Buyer contact -->
        <div style="margin-bottom:1.25rem;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--faint);margin-bottom:6px;">Contact</div>
          <a href="tel:<?= htmlspecialchars($detailLead['buyer_phone']) ?>"
             style="display:block;font-size:14px;color:var(--p);font-weight:600;
                    text-decoration:none;margin-bottom:3px;">
            <i class="fa-solid fa-phone" style="font-size:11px;margin-right:5px;"></i>
            <?= htmlspecialchars($detailLead['buyer_phone']) ?>
          </a>
          <?php if ($detailLead['buyer_email']): ?>
          <a href="mailto:<?= htmlspecialchars($detailLead['buyer_email']) ?>"
             style="display:block;font-size:12px;color:var(--p);text-decoration:none;">
            <i class="fa-solid fa-envelope" style="font-size:11px;margin-right:5px;"></i>
            <?= htmlspecialchars($detailLead['buyer_email']) ?>
          </a>
          <?php endif; ?>
          <?php if ($detailLead['buyer_message']): ?>
          <div style="margin-top:8px;font-size:12px;color:var(--muted);
                      background:var(--bg);border:1px solid var(--border);
                      border-radius:var(--r-sm);padding:9px 11px;
                      font-style:italic;line-height:1.6;">
            "<?= htmlspecialchars($detailLead['buyer_message']) ?>"
          </div>
          <?php endif; ?>
        </div>

        <!-- Attribution (immutable) -->
        <div style="margin-bottom:1.25rem;background:var(--p-light);
                    border:1px solid var(--p-b);border-radius:var(--r-md);padding:12px 14px;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--p);margin-bottom:6px;">
            <i class="fa-solid fa-lock" style="font-size:9px;margin-right:3px;"></i>
            Attribution (locked)
          </div>
          <div style="font-size:12px;color:var(--p-dark);line-height:1.9;">
            <strong>Broker:</strong>
            <?= htmlspecialchars(trim(($detailLead['broker_first'] ?? '') . ' ' . ($detailLead['broker_last'] ?? '')) ?: $detailLead['broker_email']) ?>
            <br><strong>Desk:</strong> <?= htmlspecialchars($detailLead['desk_name'] ?? '—') ?>
            <br><strong>At:</strong> <?= date('d M Y H:i', strtotime($detailLead['attributed_at'])) ?>
          </div>
        </div>

        <!-- Car -->
        <div style="margin-bottom:1.25rem;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                      letter-spacing:.06em;color:var(--faint);margin-bottom:5px;">Vehicle</div>
          <div style="font-size:13px;font-weight:600;color:var(--text);">
            <?= htmlspecialchars("{$detailLead['year']} {$detailLead['make']} {$detailLead['model']}") ?>
          </div>
          <div style="font-size:12px;color:var(--muted);">R <?= number_format($detailLead['price'], 0) ?></div>
        </div>

        <!-- Status update — exec can update pre-close stages only -->
        <?php if (!in_array($detailLead['status'], ['closed','lost'])): ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="lead_id" value="<?= $detailLead['id'] ?>">

          <div style="margin-bottom:1rem;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                        letter-spacing:.06em;color:var(--faint);margin-bottom:7px;">
              Pipeline stage
            </div>
            <?php foreach ([['new','New'],['contacted','Contacted'],['test_drive','Test Drive'],['negotiation','Negotiation']] as [$sv,$sl]): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;
                           border-radius:var(--r-md);margin-bottom:4px;cursor:pointer;
                           border:1px solid <?= $detailLead['status'] === $sv ? 'var(--p-b)' : 'var(--border)' ?>;
                           background:<?= $detailLead['status'] === $sv ? 'var(--p-light)' : 'var(--bg)' ?>;">
              <input type="radio" name="new_status" value="<?= $sv ?>"
                     <?= $detailLead['status'] === $sv ? 'checked' : '' ?>
                     style="accent-color:var(--p);">
              <span style="font-size:12px;font-weight:<?= $detailLead['status'] === $sv ? '600' : '400' ?>;
                           color:<?= $detailLead['status'] === $sv ? 'var(--p)' : 'var(--text2)' ?>;">
                <?= $sl ?>
              </span>
            </label>
            <?php endforeach; ?>
          </div>

          <div class="fgroup">
            <label class="flabel" for="exec_notes">Notes</label>
            <textarea class="finput" id="exec_notes" name="dealer_notes" rows="3"
                      maxlength="500"
                      placeholder="Call notes, test drive feedback…"><?= htmlspecialchars($detailLead['dealer_notes'] ?? '') ?></textarea>
          </div>

          <div class="alert alert-info" style="font-size:11px;padding:8px 12px;">
            <i class="fa-solid fa-circle-info alert-icon"></i>
            Deal close is handled by the dealer principal.
          </div>

          <button class="btn btn-primary btn-full" type="submit" style="margin-top:8px;">
            Update status
          </button>
        </form>
        <?php else: ?>
        <div style="text-align:center;padding:10px 0;">
          <span style="font-size:13px;font-weight:600;
                       color:<?= $detailLead['status'] === 'closed' ? 'var(--green)' : 'var(--faint)' ?>;">
            <?= ucfirst($detailLead['status']) ?>
          </span>
          <?php if ($detailLead['dealer_notes']): ?>
          <div style="font-size:12px;color:var(--muted);margin-top:6px;font-style:italic;">
            <?= htmlspecialchars($detailLead['dealer_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
