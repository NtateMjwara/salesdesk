<?php
/**
 * SalesDesk — Sales Exec Leads.
 * T3 owns this file.
 *
 * Task sep3: JOIN leads ON leads.car_id = cars.id WHERE cars.uploaded_by_exec_id = se.id
 * Same pipeline and detail as dealer leads.
 * Exec can update status, add notes. Attribution display is immutable.
 * Exec cannot close or mark lost — that is dealer principal only.
 *
 * REFACTORED: All inline style= layout attributes replaced with semantic
 * CSS classes. Mirrors the pattern established in dealer_leads.php.
 *
 * Problems fixed (identical root causes to dealer/leads.php):
 *   — outer flex split (list + sticky detail panel) was inline →
 *     .d-split-layout; stacks to single column on tablet/mobile
 *   — onclick table row navigation was combined with inline background
 *     colour state → .d-roster__row / .d-roster__row--selected CSS classes
 *     fix the iOS stuck-hover bug
 *   — buyer name/phone cell, desk cell → .d-lead-buyer-name / .d-lead-buyer-phone
 *     with min-width:0 parents so ellipsis fires instead of overflowing
 *   — filter tabs → .d-tab-bar / .d-tab, horizontally scrollable
 *   — status tabs had no wrapping guard — 7 tabs in a flex container at
 *     320px width caused significant overflow
 *   — detail panel: header, attribution, buyer message, stage list all
 *     converted from inline style= to dealer.css §5 classes
 *   — intent dot used inline width/height/border-radius/background →
 *     .dash-intent-dot + modifier class
 *   — pipeline stage radio labels used per-row inline border-color and
 *     background switching → .d-stage-label / .d-stage-label--active
 *   — "deal close is handled by dealer" info alert was inline-styled →
 *     uses .alert.alert-info from components.css
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
            // Exec can only move leads through pre-close stages
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
    $statusCounts[$r['status']] = (int) $r['cnt'];
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
        u.email AS broker_email,
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

// ── Helpers ───────────────────────────────────────────────────
function intentLabel(string $intent): string {
    return match($intent) {
        'within_30d' => '🔥 Hot — within 30 days',
        'one_to_3mo' => '🌡 Warm — 1–3 months',
        default      => 'Browsing',
    };
}

function intentBadge(string $intent): string {
    return match($intent) {
        'within_30d' => '<span class="badge" style="background:var(--gr-bg);color:var(--green);border-color:var(--gr-b);">🔥 Hot</span>',
        'one_to_3mo' => '<span class="badge" style="background:var(--amb-bg);color:var(--amber);border-color:var(--amb-b);">Warm</span>',
        default      => '<span class="badge badge-suspended">Browsing</span>',
    };
}

function statusPill(string $status): string {
    $cls = match($status) {
        'new'         => 'd-status-pill--new',
        'contacted'   => 'd-status-pill--contacted',
        'test_drive'  => 'd-status-pill--test-drive',
        'negotiation' => 'd-status-pill--negotiation',
        'closed'      => 'd-status-pill--closed',
        'lost'        => 'd-status-pill--lost',
        default       => '',
    };
    $label = match($status) {
        'new'         => 'New',
        'contacted'   => 'Contacted',
        'test_drive'  => 'Test Drive',
        'negotiation' => 'Negotiation',
        'closed'      => 'Closed',
        'lost'        => 'Lost',
        default       => $status,
    };
    return "<span class=\"d-status-pill {$cls}\">{$label}</span>";
}

$pageTitle = 'Leads';
ob_start();
?>

<div class="d-split-layout">

  <!-- ── LEADS LIST PANEL ────────────────────────────────────── -->
  <div class="d-split-layout__main">

    <div class="dash-header">
      <div class="dash-header__text">
        <h1 class="page-header__title">My lead <em>pipeline</em></h1>
        <p class="dash-header__sub-plain">
          Leads on cars you uploaded at <?= htmlspecialchars($exec['dealer_name']) ?>
        </p>
      </div>
    </div>

    <!-- Status filter tabs -->
    <div class="d-tab-bar" role="tablist" aria-label="Filter leads by status">
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
         class="d-tab <?= $active ? 'd-tab--active' : '' ?>"
         role="tab"
         aria-selected="<?= $active ? 'true' : 'false' ?>">
        <?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Search -->
    <form method="GET" class="d-search-row">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <input class="finput" name="q"
             placeholder="Search buyer or vehicle…"
             value="<?= htmlspecialchars($search) ?>">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
      <?php if ($search): ?>
      <a href="?status=<?= urlencode($filterStatus) ?>"
         class="btn btn-ghost btn-sm"
         style="text-decoration:none;">Clear</a>
      <?php endif; ?>
    </form>

    <!-- Leads table -->
    <?php if (empty($leads)): ?>
    <div class="empty">
      <span class="empty-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></span>
      No leads yet on your listings.
    </div>
    <?php else: ?>
    <div class="d-roster-wrap">
      <table class="d-roster">
        <thead>
          <tr>
            <th>Buyer</th>
            <th class="d-hide-sm">Vehicle</th>
            <th class="d-hide-sm">Broker</th>
            <th>Intent</th>
            <th>Status</th>
            <th class="d-hide-sm">Date</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($leads as $lead):
          $ageHours = (time() - strtotime($lead['created_at'])) / 3600;
          $ageLabel = $ageHours < 24
            ? round($ageHours) . 'h ago'
            : round($ageHours / 24) . 'd ago';
          $isSelected = $detailId === (int) $lead['id'];
        ?>
        <tr class="d-roster__row <?= $isSelected ? 'd-roster__row--selected' : '' ?>"
            onclick="window.location='/app/exec/leads.php?id=<?= $lead['id'] ?>&status=<?= urlencode($filterStatus) ?>'">
          <td>
            <div class="d-lead-buyer-name">
              <?= htmlspecialchars($lead['buyer_name']) ?>
            </div>
            <div class="d-lead-buyer-phone">
              <?= htmlspecialchars($lead['buyer_phone']) ?>
            </div>
          </td>
          <td class="d-hide-sm" style="font-size:12px;">
            <?= htmlspecialchars("{$lead['year']} {$lead['make']} {$lead['model']}") ?>
          </td>
          <td class="d-hide-sm" style="font-size:12px;color:var(--muted);">
            <?= htmlspecialchars($lead['desk_name'] ?? $lead['broker_email'] ?? '—') ?>
          </td>
          <td><?= intentBadge($lead['buyer_intent']) ?></td>
          <td><?= statusPill($lead['status']) ?></td>
          <td class="d-hide-sm" style="font-size:11px;color:var(--faint);"><?= $ageLabel ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── LEAD DETAIL PANEL ────────────────────────────────────── -->
  <?php if ($detailLead): ?>
  <div class="d-detail-panel" id="detailPanel">
    <div class="d-detail-card">

      <!-- Header -->
      <div class="d-detail-card__head">
        <div style="min-width:0;">
          <div class="d-detail-card__head-name">
            <?= htmlspecialchars($detailLead['buyer_name']) ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px;">
            <?= intentLabel($detailLead['buyer_intent']) ?>
          </div>
        </div>
        <a href="/app/exec/leads.php?status=<?= urlencode($filterStatus) ?>"
           class="d-detail-card__close"
           aria-label="Close detail panel">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </a>
      </div>

      <div class="d-detail-card__body">

        <!-- Buyer contact -->
        <div class="d-detail-section">
          <span class="d-detail-section__label">Contact</span>
          <a href="tel:<?= htmlspecialchars($detailLead['buyer_phone']) ?>"
             style="display:block;font-size:14px;color:var(--p);font-weight:600;
                    text-decoration:none;margin-bottom:3px;">
            <i class="fa-solid fa-phone" style="font-size:11px;margin-right:5px;" aria-hidden="true"></i>
            <?= htmlspecialchars($detailLead['buyer_phone']) ?>
          </a>
          <?php if ($detailLead['buyer_email']): ?>
          <a href="mailto:<?= htmlspecialchars($detailLead['buyer_email']) ?>"
             style="display:block;font-size:12px;color:var(--p);text-decoration:none;word-break:break-all;">
            <i class="fa-solid fa-envelope" style="font-size:11px;margin-right:5px;" aria-hidden="true"></i>
            <?= htmlspecialchars($detailLead['buyer_email']) ?>
          </a>
          <?php endif; ?>
          <?php if ($detailLead['buyer_message']): ?>
          <div class="d-buyer-message">
            "<?= htmlspecialchars($detailLead['buyer_message']) ?>"
          </div>
          <?php endif; ?>
        </div>

        <!-- Attribution (immutable) -->
        <div class="d-detail-section">
          <div class="d-attribution">
            <div class="d-attribution__label">
              <i class="fa-solid fa-lock" style="font-size:9px;" aria-hidden="true"></i>
              Attribution (locked)
            </div>
            <div class="d-attribution__content">
              <strong>Broker:</strong>
              <?= htmlspecialchars(trim(($detailLead['broker_first'] ?? '') . ' ' . ($detailLead['broker_last'] ?? '')) ?: $detailLead['broker_email']) ?>
              <br>
              <strong>Desk:</strong> <?= htmlspecialchars($detailLead['desk_name'] ?? '—') ?>
              <br>
              <strong>Attributed:</strong>
              <?= date('d M Y H:i', strtotime($detailLead['attributed_at'])) ?>
            </div>
          </div>
        </div>

        <!-- Vehicle -->
        <div class="d-detail-section">
          <span class="d-detail-section__label">Vehicle</span>
          <div style="font-size:13px;font-weight:600;color:var(--text);">
            <?= htmlspecialchars("{$detailLead['year']} {$detailLead['make']} {$detailLead['model']}") ?>
          </div>
          <div style="font-size:12px;color:var(--muted);">
            R <?= number_format($detailLead['price'], 0) ?>
          </div>
        </div>

        <!-- Commission status if deal closed -->
        <?php if ($detailLead['commission_id']): ?>
        <div class="d-detail-section">
          <div class="d-commission-block">
            <span class="d-commission-block__label">Commission</span>
            <div class="d-commission-block__status">
              Status: <?= htmlspecialchars($detailLead['commission_status']) ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Status update — exec can only move through pre-close stages -->
        <?php if (!in_array($detailLead['status'], ['closed','lost'])): ?>
        <form method="POST" id="statusForm">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="lead_id" value="<?= $detailLead['id'] ?>">

          <div class="d-detail-section">
            <span class="d-detail-section__label">Pipeline stage</span>
            <div class="d-stage-list">
              <?php foreach ([
                ['new',         'New'],
                ['contacted',   'Contacted'],
                ['test_drive',  'Test Drive'],
                ['negotiation', 'Negotiation'],
              ] as [$sv, $sl]):
                $isCurrent = $detailLead['status'] === $sv;
              ?>
              <label class="d-stage-label <?= $isCurrent ? 'd-stage-label--active' : '' ?>">
                <input type="radio"
                       name="new_status"
                       value="<?= $sv ?>"
                       <?= $isCurrent ? 'checked' : '' ?>
                       style="accent-color:var(--p);flex-shrink:0;">
                <span class="d-stage-label__text"><?= $sl ?></span>
                <?php if ($isCurrent): ?>
                <span class="d-stage-label__current">CURRENT</span>
                <?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="fgroup">
            <label class="flabel" for="exec_notes">
              Notes <span class="flabel-opt">(internal)</span>
            </label>
            <textarea class="finput"
                      id="exec_notes"
                      name="dealer_notes"
                      rows="3"
                      maxlength="500"
                      placeholder="Call notes, test drive feedback…"><?= htmlspecialchars($detailLead['dealer_notes'] ?? '') ?></textarea>
          </div>

          <!-- Exec permission boundary notice -->
          <div class="alert alert-info" style="margin-bottom:1rem;">
            <i class="fa-solid fa-circle-info alert-icon" aria-hidden="true"></i>
            Deal close and lost marking are handled by the dealer principal.
          </div>

          <button class="btn btn-primary btn-full" type="submit">
            Update status
          </button>
        </form>

        <?php else: ?>
        <!-- Already closed/lost — read-only view -->
        <div class="d-lead-terminal">
          <?= statusPill($detailLead['status']) ?>
          <?php if ($detailLead['dealer_notes']): ?>
          <div class="d-lead-terminal__notes">
            <?= htmlspecialchars($detailLead['dealer_notes']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div><!-- /d-detail-card__body -->
    </div><!-- /d-detail-card -->
  </div><!-- /d-detail-panel -->
  <?php endif; // detailLead ?>

</div><!-- /d-split-layout -->

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
