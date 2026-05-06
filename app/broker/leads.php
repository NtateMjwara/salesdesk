<?php
/**
 * SalesDesk — Broker Leads Screen
 * T4 owns this file. Route: /app/broker/leads.php
 *
 * All leads from broker's desk. Filter by status.
 * Click row to see full buyer details (if status > new).
 * Attribution is shown as immutable/locked.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];

// ── Salesdesk ─────────────────────────────────────────────────
$deskStmt = $pdo->prepare("SELECT id, display_name FROM salesdesks WHERE user_id = ? AND is_active = 1 LIMIT 1");
$deskStmt->execute([$userId]);
$desk = $deskStmt->fetch();
if (!$desk) { redirect('/auth/register.php'); }
$salesdeskId = (int) $desk['id'];

// ── Filters ───────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['new', 'contacted', 'test_drive', 'negotiation', 'closed', 'lost'];
if ($statusFilter && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$selectedLead = (int) ($_GET['lead'] ?? 0);

// ── Lead counts by status ─────────────────────────────────────
$countStmt = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM leads
    WHERE salesdesk_id = ?
    GROUP BY status
");
$countStmt->execute([$salesdeskId]);
$statusCounts = [];
$totalLeads   = 0;
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
    $totalLeads += (int) $row['cnt'];
}

// ── Load leads ────────────────────────────────────────────────
$where  = ['l.salesdesk_id = ?'];
$params = [$salesdeskId];

if ($statusFilter) {
    $where[]  = 'l.status = ?';
    $params[] = $statusFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$leadsStmt = $pdo->prepare("
    SELECT
        l.id,
        l.uuid,
        l.buyer_name,
        l.buyer_phone,
        l.buyer_email,
        l.buyer_intent,
        l.buyer_message,
        l.status,
        l.dealer_notes,
        l.consent_given,
        l.consent_at,
        l.attribution_locked,
        l.attributed_at,
        l.created_at,
        l.updated_at,
        c.make, c.model, c.year, c.price, c.slug AS car_slug,
        d.company_name AS dealer_name,
        d.slug         AS dealer_slug,
        d.verification_status AS dealer_verification,
        -- Commission info
        cm.id          AS commission_id,
        cm.status      AS commission_status,
        cm.gross_amount,
        cm.net_amount
    FROM leads l
    JOIN cars c     ON c.id = l.car_id
    JOIN dealers d  ON d.id = l.dealer_id
    LEFT JOIN commissions cm ON cm.lead_id = l.id
    {$whereClause}
    ORDER BY l.created_at DESC
");
$leadsStmt->execute($params);
$leads = $leadsStmt->fetchAll();

// ── Load selected lead detail ─────────────────────────────────
$leadDetail = null;
if ($selectedLead) {
    foreach ($leads as $l) {
        if ((int)$l['id'] === $selectedLead) {
            $leadDetail = $l;
            break;
        }
    }
}

// ── Render ────────────────────────────────────────────────────
$pageTitle = 'My Leads | ' . $desk['display_name'];
ob_start();
?>

<div class="leads-layout">

  <!-- Left: list -->
  <div class="leads-list-col">
    <div class="leads-header">
      <h1 class="leads-title">My Leads</h1>
      <span class="section-count"><?= $totalLeads ?></span>
    </div>

    <!-- Status filter tabs -->
    <div class="status-tabs">
      <a href="?status=" class="status-tab <?= !$statusFilter ? 'active' : '' ?>">
        All <span class="tab-count"><?= $totalLeads ?></span>
      </a>
      <?php
      $tabDefs = [
        'new'         => ['🆕', 'New'],
        'contacted'   => ['📞', 'Contacted'],
        'test_drive'  => ['🚗', 'Test Drive'],
        'negotiation' => ['🤝', 'Negotiating'],
        'closed'      => ['✅', 'Closed'],
        'lost'        => ['❌', 'Lost'],
      ];
      foreach ($tabDefs as $s => [$icon, $label]):
        $cnt = $statusCounts[$s] ?? 0;
        if (!$cnt && !$statusFilter) continue;
      ?>
      <a href="?status=<?= $s ?>" class="status-tab <?= $statusFilter === $s ? 'active' : '' ?>">
        <?= $icon ?> <?= $label ?>
        <?php if ($cnt): ?>
        <span class="tab-count"><?= $cnt ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Leads list -->
    <?php if (empty($leads)): ?>
    <div class="empty" style="margin-top:1rem;">
      <span class="empty-icon"><i class="fa-solid fa-inbox"></i></span>
      No leads <?= $statusFilter ? "with status \"" . htmlspecialchars($statusFilter) . "\"" : 'yet' ?>.
    </div>
    <?php else: ?>
    <div class="leads-list">
      <?php foreach ($leads as $lead):
        $isSelected = (int)$lead['id'] === $selectedLead;
        $intentColor = match($lead['buyer_intent']) {
          'within_30d' => ['var(--red-bg)', 'var(--red)', 'var(--red-b)', '🔥'],
          'one_to_3mo' => ['var(--amb-bg)', 'var(--amber)', 'var(--amb-b)', '🗓️'],
          default      => ['var(--bg)', 'var(--faint)', 'var(--border)', '👀'],
        };
        $statusColor = match($lead['status']) {
          'new'         => 'var(--p)',
          'contacted'   => 'var(--amber)',
          'test_drive'  => 'var(--teal)',
          'negotiation' => 'var(--purple)',
          'closed'      => 'var(--green)',
          'lost'        => 'var(--faint)',
          default       => 'var(--muted)',
        };
      ?>
      <a href="?status=<?= urlencode($statusFilter) ?>&lead=<?= $lead['id'] ?>"
         class="lead-row <?= $isSelected ? 'selected' : '' ?>">
        <div class="lead-row-main">
          <span class="lead-buyer"><?= htmlspecialchars($lead['buyer_name']) ?></span>
          <span class="lead-status-dot" style="background:<?= $statusColor ?>"></span>
        </div>
        <div class="lead-row-car">
          <?= htmlspecialchars($lead['year'].' '.$lead['make'].' '.$lead['model']) ?>
        </div>
        <div class="lead-row-meta">
          <span style="background:<?= $intentColor[0] ?>;color:<?= $intentColor[1] ?>;border:1px solid <?= $intentColor[2] ?>;
                       font-size:10px;font-family:var(--mono);padding:1px 6px;border-radius:9999px;">
            <?= $intentColor[3] ?> <?= match($lead['buyer_intent']) {
              'within_30d' => '30d',
              'one_to_3mo' => '1-3mo',
              default      => 'browsing',
            } ?>
          </span>
          <span style="font-size:11px;color:var(--faint);">
            <?= date('d M', strtotime($lead['created_at'])) ?>
          </span>
          <?php if ($lead['commission_id']): ?>
          <span style="font-size:10px;color:var(--green);font-family:var(--mono);">
            💰 <?= formatZAR((float)$lead['net_amount']) ?>
          </span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: detail panel -->
  <div class="leads-detail-col">
    <?php if (!$leadDetail): ?>
    <div class="lead-detail-empty">
      <i class="fa-solid fa-hand-pointer" style="font-size:32px;color:var(--border);margin-bottom:12px;display:block"></i>
      Select a lead to view details
    </div>
    <?php else:
      $ld = $leadDetail;
      $canSeeContact = in_array($ld['status'], ['contacted','test_drive','negotiation','closed','lost'], true)
                       || $ld['status'] === 'new'; // brokers always see contact info on their own leads
    ?>
    <div class="lead-detail">
      <!-- Header -->
      <div class="ld-header">
        <div>
          <h2 class="ld-name"><?= htmlspecialchars($ld['buyer_name']) ?></h2>
          <p class="ld-car">
            <?= htmlspecialchars($ld['year'].' '.$ld['make'].' '.$ld['model']) ?>
            · R <?= number_format((float)$ld['price'], 0, '.', ',') ?>
          </p>
        </div>
        <?php
        $sColor = match($ld['status']) {
          'closed'      => ['badge-verified', 'Closed'],
          'lost'        => ['badge-suspended','Lost'],
          'new'         => ['badge-new',      'New'],
          'contacted'   => ['badge-pending',  'Contacted'],
          'test_drive'  => ['badge-pending',  'Test Drive'],
          'negotiation' => ['badge-pending',  'Negotiating'],
          default       => ['badge-suspended', ucfirst($ld['status'])],
        };
        ?>
        <span class="badge <?= $sColor[0] ?>"><?= $sColor[1] ?></span>
      </div>

      <!-- Contact info -->
      <div class="ld-section">
        <div class="ld-section-title">
          <i class="fa-solid fa-address-card"></i> Contact details
        </div>
        <div class="ld-contact-grid">
          <div class="ld-contact-row">
            <span class="ld-label">Phone</span>
            <a href="tel:<?= htmlspecialchars($ld['buyer_phone']) ?>"
               class="ld-value ld-link">
              <?= htmlspecialchars($ld['buyer_phone']) ?>
            </a>
          </div>
          <?php if ($ld['buyer_email']): ?>
          <div class="ld-contact-row">
            <span class="ld-label">Email</span>
            <a href="mailto:<?= htmlspecialchars($ld['buyer_email']) ?>"
               class="ld-value ld-link">
              <?= htmlspecialchars($ld['buyer_email']) ?>
            </a>
          </div>
          <?php endif; ?>
          <div class="ld-contact-row">
            <span class="ld-label">Intent</span>
            <span class="ld-value"><?= match($ld['buyer_intent']) {
              'within_30d' => '🔥 Within 30 days',
              'one_to_3mo' => '🗓️ 1–3 months',
              default      => '👀 Just browsing',
            } ?></span>
          </div>
          <?php if ($ld['buyer_message']): ?>
          <div class="ld-contact-row" style="flex-direction:column;gap:4px;">
            <span class="ld-label">Message</span>
            <span class="ld-value" style="font-style:italic;color:var(--text2);">
              "<?= htmlspecialchars($ld['buyer_message']) ?>"
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Attribution (immutable) -->
      <div class="ld-section">
        <div class="ld-section-title">
          <i class="fa-solid fa-lock"></i> Attribution
          <span style="font-size:9px;font-family:var(--mono);background:var(--gr-bg);color:var(--green);
                       border:1px solid var(--gr-b);border-radius:9999px;padding:1px 6px;margin-left:4px;">
            LOCKED
          </span>
        </div>
        <div class="ld-contact-grid">
          <div class="ld-contact-row">
            <span class="ld-label">Your desk</span>
            <span class="ld-value"><?= htmlspecialchars($desk['display_name']) ?></span>
          </div>
          <div class="ld-contact-row">
            <span class="ld-label">Dealer</span>
            <span class="ld-value"><?= htmlspecialchars($ld['dealer_name']) ?></span>
          </div>
          <div class="ld-contact-row">
            <span class="ld-label">Attributed</span>
            <span class="ld-value"><?= date('d M Y H:i', strtotime($ld['attributed_at'])) ?></span>
          </div>
          <div class="ld-contact-row">
            <span class="ld-label">Lead ref</span>
            <span class="ld-value" style="font-family:var(--mono);font-size:11px;">
              <?= strtoupper(substr($ld['uuid'], 0, 8)) ?>
            </span>
          </div>
        </div>
      </div>

      <!-- Commission -->
      <?php if ($ld['commission_id']): ?>
      <div class="ld-section">
        <div class="ld-section-title">
          <i class="fa-solid fa-sack-dollar"></i> Commission
        </div>
        <div class="ld-contact-grid">
          <div class="ld-contact-row">
            <span class="ld-label">Gross</span>
            <span class="ld-value"><?= formatZAR((float)$ld['gross_amount']) ?></span>
          </div>
          <div class="ld-contact-row">
            <span class="ld-label">Net to you</span>
            <span class="ld-value" style="font-weight:700;color:var(--green);">
              <?= formatZAR((float)$ld['net_amount']) ?>
            </span>
          </div>
          <div class="ld-contact-row">
            <span class="ld-label">Status</span>
            <span class="ld-value">
              <span class="badge badge-<?= in_array($ld['commission_status'], ['paid']) ? 'verified' : 'pending' ?>">
                <?= ucfirst($ld['commission_status']) ?>
              </span>
            </span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- WhatsApp quick action -->
      <?php if ($ld['buyer_phone']): ?>
      <div style="margin-top:1.25rem;">
        <a href="https://wa.me/<?= preg_replace('/\D/', '', $ld['buyer_phone']) ?>?text=<?= urlencode('Hi ' . $ld['buyer_name'] . ', thanks for your interest in the ' . $ld['year'] . ' ' . $ld['make'] . ' ' . $ld['model'] . '. I\'m following up on your enquiry.') ?>"
           target="_blank" rel="noopener"
           class="btn btn-sm" style="background:#25D366;color:#fff;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
          <i class="fa-brands fa-whatsapp"></i> WhatsApp buyer
        </a>
      </div>
      <?php endif; ?>

      <!-- Consent record -->
      <p style="font-size:10px;color:var(--faint);margin-top:1.25rem;line-height:1.5;">
        <i class="fa-solid fa-shield-halved" style="font-size:9px"></i>
        POPIA consent given <?= $ld['consent_at'] ? date('d M Y', strtotime($ld['consent_at'])) : '—' ?>.
        Data handled per our Privacy Policy.
      </p>

    </div>
    <?php endif; ?>
  </div>

</div>

<style>
.leads-layout { display:grid; grid-template-columns:340px 1fr; gap:16px; min-height:calc(100vh - 200px); }
.leads-list-col { display:flex; flex-direction:column; gap:0; }
.leads-detail-col { background:var(--white); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; }

.leads-header { display:flex; align-items:center; gap:10px; margin-bottom:.75rem; }
.leads-title  { font-family:var(--serif); font-size:1.4rem; font-weight:300; }

.status-tabs { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:.75rem; }
.status-tab {
  display:inline-flex; align-items:center; gap:5px;
  padding:5px 10px; border-radius:var(--r-full);
  font-size:12px; color:var(--muted);
  border:1px solid var(--border); background:var(--white);
  text-decoration:none; transition:all .15s; white-space:nowrap;
}
.status-tab:hover  { border-color:var(--p); color:var(--p); }
.status-tab.active { background:var(--p); color:#fff; border-color:var(--p); }
.tab-count { font-family:var(--mono); font-size:10px; }

.leads-list { display:flex; flex-direction:column; gap:0; }
.lead-row {
  display:flex; flex-direction:column; gap:4px;
  padding:12px 14px; border:1px solid var(--border);
  background:var(--white); border-radius:var(--r-md);
  margin-bottom:6px; text-decoration:none;
  transition:border-color .15s, box-shadow .15s;
}
.lead-row:hover   { border-color:var(--p-b); box-shadow:var(--shadow-sm); }
.lead-row.selected{ border-color:var(--p); background:var(--p-light); }
.lead-row-main { display:flex; align-items:center; justify-content:space-between; }
.lead-buyer   { font-size:13px; font-weight:600; color:var(--text); }
.lead-status-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.lead-row-car  { font-size:11px; color:var(--muted); }
.lead-row-meta { display:flex; align-items:center; gap:8px; }

/* Detail panel */
.lead-detail-empty {
  display:flex; flex-direction:column; align-items:center;
  justify-content:center; height:100%;
  font-size:13px; color:var(--faint); text-align:center; padding:2rem;
}
.lead-detail { padding:1.25rem; height:100%; overflow-y:auto; }
.ld-header   { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:1.25rem; }
.ld-name     { font-size:1.1rem; font-weight:700; color:var(--text); }
.ld-car      { font-size:12px; color:var(--muted); margin-top:2px; }
.ld-section  { background:var(--bg); border:1px solid var(--border); border-radius:var(--r-md); padding:12px 14px; margin-bottom:10px; }
.ld-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.ld-contact-grid  { display:flex; flex-direction:column; gap:8px; }
.ld-contact-row   { display:flex; align-items:flex-start; gap:12px; font-size:13px; }
.ld-label  { color:var(--faint); min-width:80px; flex-shrink:0; font-size:12px; }
.ld-value  { color:var(--text); font-weight:500; }
.ld-link   { color:var(--p); text-decoration:none; }
.ld-link:hover { text-decoration:underline; }

@media (max-width: 720px) {
  .leads-layout { grid-template-columns: 1fr; }
  .leads-detail-col { min-height:300px; }
}
</style>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
