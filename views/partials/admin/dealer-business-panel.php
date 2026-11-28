<?php
/**
 * SalesDesk — Admin partial: a dealership's business account  (0020, plans Phase 2a)
 * Included by app/admin/dealerships-view.php (tab=business).
 *
 * Every dealership belongs to a business org (its business account). The
 * org follows the dealership: name, logo, address, verification and the
 * principal as owner. This tab shows that account and its plan.
 * Managing admins can see it; only a superadmin can change the plan or
 * set custom limits (also enforced in includes/entitlements.php).
 *
 * 2b: the Broker network card (open/close, applications, members) is
 * available to every admin managing the dealership.
 *
 * Expects: $self, $adminId, $biz (see dealerships-view.php)
 */
$bizSuper = isSuperadmin($adminId);
?>
<?php if (!$biz['ready']): ?>
  <div class="alert alert-warn"><span class="alert-icon">!</span>
    <div>Run <strong>0020 Business orgs</strong> in <a href="/app/admin/migrations">Migrations</a> to give every dealership its business account.</div>
  </div>
<?php elseif (!$biz['org']): ?>
  <div class="alert alert-error"><span class="alert-icon">!</span>
    <div>This dealership’s business account couldn’t be loaded. Re-run <strong>0020 Business orgs</strong> in <a href="/app/admin/migrations">Migrations</a>.</div>
  </div>
<?php else: $org = $biz['org']; $acctPlan = $biz['plan'] ?? null; ?>

  <div class="card adm-card adm-card-wide">
    <div class="card-body">
      <h2 class="adm-h2">Business account</h2>
      <p class="adm-muted adm-gap">
        <?php if (count($biz['dealerships'] ?? []) > 1): ?>
        The business account for <?= count($biz['dealerships']) ?> dealerships: one plan and one broker network. It keeps its own name
        (set below) and its owner runs the network.
        <?php else: ?>
        The dealership’s account on SalesDesk. It takes its name, logo, address and verification from the
        dealership, and the principal owns it.
        <?php endif; ?>
        It never takes a cut of commission: brokers are paid in full to their own account.
      </p>
      <dl class="biz-facts">
        <div><dt>Name</dt><dd><?= htmlspecialchars($org['name']) ?></dd></div>
        <div><dt>Network page</dt><dd>
          <?php if ((int) $biz['brokers'] > 0): ?>
          <a class="adm-mono" href="/desks/<?= htmlspecialchars(rawurlencode($org['slug'])) ?>/" target="_blank" rel="noopener">/desks/<?= htmlspecialchars($org['slug']) ?>/ ↗</a>
          <?php else: ?>
          <span class="adm-mono">/desks/<?= htmlspecialchars($org['slug']) ?>/</span>
          <div class="adm-sub">Goes live once a verified broker joins the network.</div>
          <?php endif; ?></dd></div>
        <div><dt>Owner</dt><dd><?= $org['owner_email'] ? htmlspecialchars($org['owner_email']) : '<span class="adm-sub">No principal yet (admin-managed)</span>' ?></dd></div>
        <div><dt>Verification</dt><dd><?= adminStatusBadge($org['verification_status']) ?></dd></div>
        <div><dt>Dealerships</dt><dd><?= count($biz['dealers']) ?>
          <?php if (count($biz['dealers']) > 1): ?>
          <div class="adm-sub"><?= htmlspecialchars(implode(', ', array_column($biz['dealers'], 'company_name'))) ?></div>
          <?php endif; ?></dd></div>
        <div><dt>Brokers in network</dt><dd><?= (int) $biz['brokers'] ?></dd></div>
      </dl>
    </div>
  </div>

  <!-- 2d: Enterprise — dealerships in this business -->
  <?php $bizDealers = $biz['dealerships']; $bizMulti = count($bizDealers) > 1;
        $bizRoom = sdHasRoom($biz['dealer_limit'], count($bizDealers)); ?>
  <div class="card adm-card adm-card-wide" id="dealerships">
    <div class="card-body">
      <div class="biz-net-head">
        <h2 class="adm-h2">Dealerships</h2>
        <span class="section-count"><?= count($bizDealers) ?> of <?= htmlspecialchars(sdLimitText($biz['dealer_limit'], 'max_dealerships')) ?></span>
      </div>
      <?php if (!empty($biz['grace'])): ?>
      <div class="alert alert-warn adm-gap">
        <i class="fa-solid fa-hourglass-half alert-icon"></i>
        <div>
          <strong>Over its dealership limit.</strong> <?= (int) $biz['grace']['used_at_start'] ?> dealerships, the plan allows <?= (int) $biz['grace']['limit_value'] ?>.
          On <strong><?= htmlspecialchars(date('j M Y', strtotime((string) $biz['grace']['ends_at']))) ?></strong> the extra dealerships stop adding new cars
          (the owner's dealership, then the longest-standing, keep listing). Renew the plan, set a custom limit, or move a dealership out to clear this.
        </div>
      </div>
      <?php endif; ?>
      <p class="adm-muted adm-gap">
        <?= $bizMulti
            ? 'These dealerships share one plan and one broker network. The business owner can switch between all of them in the dealer portal; each dealership keeps its own principal.'
            : 'One dealership. An Enterprise business can group several dealerships under one plan and one broker network.' ?>
      </p>
      <div class="roster-wrap">
        <table class="roster">
          <thead><tr><th>Dealership</th><th>Principal</th><th>Status</th><?php if ($bizSuper && $bizMulti): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($bizDealers as $bd): ?>
            <tr>
              <td>
                <?php if ((int) $bd['id'] === (int) $dealerId): ?>
                  <span class="adm-strong"><?= htmlspecialchars($bd['company_name']) ?></span> <span class="adm-sub">(this page)</span>
                <?php else: ?>
                  <a class="adm-strong" href="/app/admin/dealerships-view?id=<?= (int) $bd['id'] ?>&amp;tab=business"><?= htmlspecialchars($bd['company_name']) ?></a>
                <?php endif; ?>
                <div class="adm-sub"><?= htmlspecialchars(implode(', ', array_filter([$bd['city'], $bd['province']])) ?: '—') ?></div>
              </td>
              <td>
                <?php if ($bd['principal_email']): ?>
                  <?= htmlspecialchars($bd['principal_name'] ?: $bd['principal_email']) ?>
                  <?php if ($bd['is_owner']): ?><span class="badge badge-verified">Business owner</span><?php endif; ?>
                  <div class="adm-sub"><?= htmlspecialchars($bd['principal_email']) ?></div>
                <?php else: ?><span class="adm-sub">Admin-managed</span><?php endif; ?>
              </td>
              <td><?= adminStatusBadge($bd['is_active'] ? 'active' : 'inactive') ?>
                <?php if (function_exists('sdDealerListingsPaused') && sdDealerListingsPaused((int) $bd['id'])): ?>
                <span class="badge badge-paused" title="Over the business's dealership limit — can't add new cars">Listings paused</span>
                <?php endif; ?>
              </td>
              <?php if ($bizSuper && $bizMulti): ?>
              <td>
                <?php if (!$bd['is_owner']): ?>
                <form method="POST" action="<?= $self ?>&amp;tab=business#dealerships"
                      data-confirm="Move <?= htmlspecialchars($bd['company_name'], ENT_QUOTES) ?> out of this business? It gets its own business account (Free). The broker network stays here.">
                  <?= csrf_hidden_field() ?>
                  <input type="hidden" name="action" value="biz_detach">
                  <input type="hidden" name="detach_dealer_id" value="<?= (int) $bd['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Move out</button>
                </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($bizSuper): ?>
      <div class="biz-ent-forms">
        <form method="POST" action="<?= $self ?>&amp;tab=business#dealerships" class="biz-ent-form"
              data-confirm="Add this dealership to <?= htmlspecialchars($org['name'], ENT_QUOTES) ?>? If its own business has no other dealership, that business is merged in: its brokers, leads and commissions move here.">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="biz_attach">
          <label class="flabel" for="attach_dealer_id">Add a dealership</label>
          <div class="biz-ent-row">
            <select class="finput" id="attach_dealer_id" name="attach_dealer_id" required <?= !$bizRoom ? 'disabled' : '' ?>>
              <option value="">Choose a dealership…</option>
              <?php foreach ($biz['attachable'] as $ad): ?>
              <option value="<?= (int) $ad['id'] ?>"><?= htmlspecialchars($ad['company_name']) ?><?= $ad['city'] ? ' · ' . htmlspecialchars($ad['city']) : '' ?><?= (int) $ad['org_dealers'] > 1 ? ' — in ' . htmlspecialchars($ad['org_name']) : '' ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit" <?= !$bizRoom ? 'disabled' : '' ?>>Add</button>
          </div>
          <?php if (!$bizRoom): ?>
            <p class="adm-sub">The <?= htmlspecialchars($acctPlan['name'] ?? 'current') ?> plan allows <?= htmlspecialchars(sdLimitText($biz['dealer_limit'], 'max_dealerships')) ?>. Move the business to Enterprise, or set a custom dealership limit, to add more.</p>
          <?php endif; ?>
        </form>

        <?php if ($bizMulti): ?>
        <form method="POST" action="<?= $self ?>&amp;tab=business#dealerships" class="biz-ent-form">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="biz_rename">
          <label class="flabel" for="business_name">Business name</label>
          <div class="biz-ent-row">
            <input class="finput" id="business_name" name="business_name" maxlength="120" required value="<?= htmlspecialchars($org['name']) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Rename</button>
          </div>
          <p class="adm-sub">The network page address stays <span class="adm-mono">/desks/<?= htmlspecialchars($org['slug']) ?>/</span>.</p>
        </form>

        <?php $ownerChoices = array_filter($bizDealers, static fn ($bd) => !empty($bd['user_id'])); ?>
        <?php if ($ownerChoices): ?>
        <form method="POST" action="<?= $self ?>&amp;tab=business#dealerships" class="biz-ent-form">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="biz_owner">
          <label class="flabel" for="owner_user_id">Business owner</label>
          <div class="biz-ent-row">
            <select class="finput" id="owner_user_id" name="owner_user_id">
              <?php foreach ($ownerChoices as $oc): ?>
              <option value="<?= (int) $oc['user_id'] ?>" <?= $oc['is_owner'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(($oc['principal_name'] ?: $oc['principal_email']) . ' — ' . $oc['company_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Save</button>
          </div>
          <p class="adm-sub">The owner runs the broker network and can switch between every dealership here.</p>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 2b: the business's broker network -->
  <div class="card adm-card adm-card-wide" id="network">
    <div class="card-body">
      <div class="biz-net-head">
        <h2 class="adm-h2">Broker network</h2>
        <span class="badge <?= $biz['accepting'] ? 'badge-verified' : 'badge-pending' ?>"><?= $biz['accepting'] ? 'Open to applications' : 'Closed' ?></span>
      </div>
      <p class="adm-muted adm-gap">
        Brokers who join carry the business’s name, and their leads on its cars count towards the network.
        They can still share any car on SalesDesk, and their commission is paid to them in full.
        <strong><?= (int) $biz['brokers'] ?></strong> of <strong><?= sdLimitText($biz['broker_limit'], 'max_network_brokers') ?></strong> places used on its plan.
      </p>
      <form method="POST" action="<?= $self ?>&amp;tab=business#network" class="adm-gap"
            data-confirm="<?= $biz['accepting'] ? 'Close the network to new applications? Current brokers stay in it.' : 'Open the network? Brokers will be able to find it on My networks and apply, and you’ll review each application.' ?>">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="<?= $biz['accepting'] ? 'net_close' : 'net_open' ?>">
        <button class="btn <?= $biz['accepting'] ? 'btn-ghost' : 'btn-primary' ?> btn-sm" type="submit">
          <?= $biz['accepting'] ? 'Close to new applications' : 'Open to broker applications' ?>
        </button>
      </form>

      <h3 class="adm-h3">Waiting for review <span class="section-count"><?= count($biz['pending']) ?></span></h3>
      <?php if (!$biz['pending']): ?>
        <p class="adm-sub">No applications waiting.</p>
      <?php else: ?>
        <?php $agentPending = $biz['pending']; $showOrg = false; include __DIR__ . '/agent-requests-table.php'; ?>
      <?php endif; ?>

      <h3 class="adm-h3 adm-section-gap">Brokers <span class="section-count"><?= count($biz['members']) ?></span></h3>
      <?php if (!$biz['members']): ?>
        <p class="adm-sub">No brokers in this network yet.</p>
      <?php else: ?>
      <div class="roster-wrap">
        <table class="roster">
          <thead><tr><th>Broker</th><th>SalesDesk</th><th>Status</th><th>Since</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($biz['members'] as $bm):
            $bmName = trim(($bm['first_name'] ?? '') . ' ' . ($bm['last_name'] ?? '')) ?: $bm['email']; ?>
            <tr>
              <td>
                <div class="adm-strong"><?= htmlspecialchars($bmName) ?></div>
                <div class="adm-sub"><?= htmlspecialchars($bm['email']) ?><?= $bm['phone'] ? ' · ' . htmlspecialchars($bm['phone']) : '' ?></div>
                <?php if ($bm['rejection_reason'] && $bm['status'] !== 'verified'): ?><div class="adm-sub">“<?= htmlspecialchars($bm['rejection_reason']) ?>”</div><?php endif; ?>
              </td>
              <td class="adm-muted">
                <?php if ($bm['desk_slug']): ?>
                  <a href="/<?= htmlspecialchars($bm['desk_slug']) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($bm['desk_name']) ?> ↗</a>
                  <div class="adm-sub"><?= (int) $bm['desk_cars'] ?> car<?= (int) $bm['desk_cars'] === 1 ? '' : 's' ?></div>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= adminStatusBadge($bm['status']) ?></td>
              <td class="adm-muted"><?= $bm['verified_at'] ? date('d M Y', strtotime($bm['verified_at'])) : ($bm['status'] === 'invited' ? 'Invited ' . date('d M Y', strtotime($bm['joined_at'])) : '—') ?></td>
              <td><div class="adm-actions">
                <?php if ($bm['status'] === 'verified'): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-reason-modal="suspend" data-reason-kind="agent"
                        data-exec-id="<?= (int) $bm['id'] ?>" data-exec-name="<?= htmlspecialchars($bmName) ?>">Suspend</button>
                <?php elseif ($bm['status'] === 'suspended'): ?>
                <form method="POST" action="<?= $self ?>&amp;tab=business#network">
                  <?= csrf_hidden_field() ?>
                  <input type="hidden" name="kind" value="agent">
                  <input type="hidden" name="member_id" value="<?= (int) $bm['id'] ?>">
                  <input type="hidden" name="action" value="reinstate">
                  <button class="btn btn-success btn-sm" type="submit">Reinstate</button>
                </form>
                <?php endif; ?>
                <?php $bmInvited = $bm['status'] === 'invited'; /* 2c */ ?>
                <form method="POST" action="<?= $self ?>&amp;tab=business#network"
                      data-confirm="<?= $bmInvited ? 'Withdraw the invitation to ' . htmlspecialchars($bmName, ENT_QUOTES) . '?' : 'Remove ' . htmlspecialchars($bmName, ENT_QUOTES) . ' from the network? Cars on their desk stay there.' ?>">
                  <?= csrf_hidden_field() ?>
                  <input type="hidden" name="kind" value="agent">
                  <input type="hidden" name="member_id" value="<?= (int) $bm['id'] ?>">
                  <input type="hidden" name="action" value="<?= $bmInvited ? 'cancel' : 'remove' ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= $bmInvited ? 'Withdraw invite' : 'Remove' ?></button>
                </form>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card adm-card adm-card-wide" id="plan">
    <div class="card-body">
      <h2 class="adm-h2">Business plan</h2>
      <?php if (!$biz['plans_ready']): ?>
      <div class="alert alert-warn"><span class="alert-icon">!</span>
        <div>Run <strong>0019 Plans</strong> in <a href="/app/admin/migrations">Migrations</a> to manage plans.</div>
      </div>
      <?php else: $acctPlan = $biz['plan']; $acctSub = $acctPlan['subscription'] ?? null; ?>
      <p class="adm-muted adm-gap">
        Currently on <strong><?= htmlspecialchars($acctPlan['name'] ?? 'Free') ?></strong>
        <?php if ($acctPlan && $acctPlan['source'] === 'subscription'): ?>
        — <?= htmlspecialchars(sdSubStatusLabel($acctSub)) ?><?= !empty($acctSub['current_period_end']) ? ' until ' . date('j M Y', strtotime($acctSub['current_period_end'])) : '' ?>
        <?= !empty($acctSub['note']) ? '· “' . htmlspecialchars($acctSub['note']) . '”' : '' ?>
        <?php endif; ?>.
        Business Pro can pay online from the dealer portal (Plan page); Enterprise is invoiced monthly (below).
        <em>Paid</em> set by hand means they pay outside SalesDesk billing, <em>Comped</em> means free of charge.
      </p>
      <?php if (!empty($biz['perks'])): $pk = $biz['perks']; /* 4a */ ?>
      <p class="adm-muted adm-gap">
        <strong>VIP perks</strong> <?= $pk['vip'] ? '(on)' : '(not on this plan)' ?> —
        network bonus: <?= $pk['raw_bonus'] ? htmlspecialchars(sdBonusText($pk['raw_bonus']['type'], $pk['raw_bonus']['value'])) . ($pk['vip'] ? '' : ' (saved, off)') : 'none' ?>;
        network-only cars: <?= (int) $pk['network_only'] ?><?= $pk['vip'] ? '' : ' marked (open to all until the plan has VIP perks)' ?>.
        The business manages these in the dealer portal (Network → VIP perks).
      </p>
      <?php endif; ?>
      <?php /* 0026: business billing — PayFast status + monthly invoices */
        $bSub     = $acctSub;
        $bOnline  = $bSub && ($bSub['provider'] ?? '') === 'payfast' && sdSubscriptionGrants($bSub);
        $bInvoice = $bSub && ($bSub['provider'] ?? '') === 'invoice' && sdSubscriptionGrants($bSub);
        $bMoney   = static fn (int $c): string => 'R ' . number_format($c / 100, 2, '.', ','); ?>
      <div class="biz-billing" id="billing">
        <?php if ($bOnline): ?>
        <div class="alert <?= (int) $bSub['cancel_at_period_end'] ? 'alert-info' : ($bSub['status'] === 'past_due' ? 'alert-warn' : 'alert-success') ?> adm-gap">
          <span class="alert-icon"><i class="fa-solid fa-credit-card"></i></span>
          <div>
            <strong>Pays online (PayFast)</strong> — <?= htmlspecialchars(sdPriceText((int) ($bSub['amount_cents'] ?? 0))) ?>/month,
            paid through <?= htmlspecialchars(date('j M Y', strtotime((string) $bSub['current_period_end'] . ' -' . SD_BILLING_GRACE_DAYS . ' days'))) ?>
            <?= (int) $bSub['cancel_at_period_end'] ? '· cancelled, runs to that date' : ($bSub['status'] === 'past_due' ? '· last payment failed' : '· renews monthly') ?>.
            <?php if ($bizSuper && !(int) $bSub['cancel_at_period_end']): ?>
            <form method="POST" action="<?= $self ?>&amp;tab=business#billing" class="adm-inline-form"
                  data-confirm="Cancel this business’s PayFast subscription? It won’t be charged again and keeps the plan until the paid-through date.">
              <?= csrf_hidden_field() ?>
              <input type="hidden" name="action" value="biz_billing_cancel">
              <button class="btn btn-ghost btn-sm" type="submit">Cancel online subscription</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($biz['inv_ready'])): ?>
        <h3 class="adm-h3">Invoices <span class="adm-sub">(monthly, paid by EFT)</span></h3>
        <?php if ($bInvoice): ?>
        <p class="adm-muted adm-gap">
          Invoiced monthly for <strong><?= htmlspecialchars($acctPlan['name'] ?? '') ?></strong>
          (<?= htmlspecialchars(sdPriceText((int) ($bSub['amount_cents'] ?? 0))) ?>) ·
          <?= (int) $bSub['cancel_at_period_end']
              ? 'invoicing stopped, plan ends ' . htmlspecialchars(date('j M Y', strtotime((string) $bSub['current_period_end'])))
              : 'plan in force until ' . htmlspecialchars(date('j M Y', strtotime((string) $bSub['current_period_end']))) . ' unless the next invoice is paid'
                . ($bSub['status'] === 'past_due' ? ' · <strong class="adm-text-red">overdue</strong>' : '') ?>.
        </p>
        <?php if ($bizSuper && !(int) $bSub['cancel_at_period_end']): ?>
        <form method="POST" action="<?= $self ?>&amp;tab=business#billing" class="adm-gap"
              data-confirm="Stop invoicing <?= htmlspecialchars($org['name'], ENT_QUOTES) ?>? No more invoices go out; the plan runs to its paid-through date, then Free.">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="inv_stop">
          <button class="btn btn-ghost btn-sm" type="submit">Stop invoicing</button>
        </form>
        <?php endif; ?>
        <?php elseif ($bizSuper && !$bOnline && $biz['bill_plans']): ?>
        <form method="POST" action="<?= $self ?>&amp;tab=business#billing" class="biz-inv-start adm-gap"
              data-confirm="Put <?= htmlspecialchars($org['name'], ENT_QUOTES) ?> on monthly invoicing? The first invoice is issued and emailed now.">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="inv_start">
          <label class="flabel" for="inv_plan">Invoice monthly for</label>
          <select class="finput" id="inv_plan" name="plan_code">
            <?php foreach ($biz['bill_plans'] as $bp): ?>
            <option value="<?= htmlspecialchars($bp['code']) ?>" <?= sdPlanIsInvoiced($bp) ? 'selected' : '' ?>>
              <?= htmlspecialchars($bp['name']) ?> — <?= htmlspecialchars(sdPlanPriceLabel($bp)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" type="submit">Start invoicing</button>
          <span class="adm-sub">Enterprise is always invoiced; Pro can be too if they prefer EFT. The first invoice is due in <?= SD_INVOICE_DUE_DAYS ?> days.</span>
        </form>
        <?php endif; ?>

        <?php if (!empty($biz['invoices'])): ?>
        <div class="roster-wrap">
          <table class="roster biz-inv-table">
            <thead><tr><th>Invoice</th><th>Period</th><th>Amount</th><th>Due</th><th>Status</th><?php if ($bizSuper): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($biz['invoices'] as $iv): $ist = sdInvoiceStatusLabel($iv); ?>
              <tr>
                <td><a class="adm-mono" href="/app/billing/invoice?id=<?= (int) $iv['id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($iv['invoice_number']) ?></a></td>
                <td class="adm-muted"><?= htmlspecialchars(date('j M', strtotime((string) $iv['period_start']))) ?> – <?= htmlspecialchars(date('j M Y', strtotime($iv['period_end'] . ' -1 day'))) ?></td>
                <td class="adm-mono"><?= htmlspecialchars($bMoney((int) $iv['amount_cents'])) ?></td>
                <td class="adm-muted"><?= htmlspecialchars(date('j M Y', strtotime((string) $iv['due_at']))) ?></td>
                <td><span class="badge <?= ['Paid' => 'badge-verified', 'Overdue' => 'badge-rejected', 'Open' => 'badge-pending'][$ist] ?? 'badge-inactive' ?>"><?= $ist ?></span>
                  <?php if ($iv['status'] === 'paid'): ?><div class="adm-sub"><?= htmlspecialchars(date('j M', strtotime((string) $iv['paid_at']))) ?> · <?= htmlspecialchars((string) $iv['paid_reference']) ?></div><?php endif; ?>
                  <?php if ($iv['status'] === 'void'): ?><div class="adm-sub"><?= htmlspecialchars((string) $iv['void_reason']) ?></div><?php endif; ?></td>
                <?php if ($bizSuper): ?>
                <td class="biz-inv-actions">
                  <?php if ($iv['status'] === 'open'): ?>
                  <form method="POST" action="<?= $self ?>&amp;tab=business#billing" class="biz-inv-paid">
                    <?= csrf_hidden_field() ?>
                    <input type="hidden" name="action" value="inv_paid">
                    <input type="hidden" name="inv_id" value="<?= (int) $iv['id'] ?>">
                    <input class="finput" name="reference" maxlength="80" placeholder="EFT reference" required aria-label="EFT reference for <?= htmlspecialchars($iv['invoice_number'], ENT_QUOTES) ?>">
                    <button class="btn btn-primary btn-sm" type="submit">Mark paid</button>
                  </form>
                  <form method="POST" action="<?= $self ?>&amp;tab=business#billing" class="adm-inline-form">
                    <?= csrf_hidden_field() ?>
                    <input type="hidden" name="action" value="inv_resend">
                    <input type="hidden" name="inv_id" value="<?= (int) $iv['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Resend</button>
                  </form>
                  <form method="POST" action="<?= $self ?>&amp;tab=business#billing" class="biz-inv-void">
                    <?= csrf_hidden_field() ?>
                    <input type="hidden" name="action" value="inv_void">
                    <input type="hidden" name="inv_id" value="<?= (int) $iv['id'] ?>">
                    <input class="finput" name="reason" maxlength="255" placeholder="Reason to void" required aria-label="Reason to void <?= htmlspecialchars($iv['invoice_number'], ENT_QUOTES) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Void</button>
                  </form>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php elseif (!$bInvoice): ?>
        <p class="adm-sub adm-gap">No invoices yet.</p>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if (!empty($biz['anns'])): /* 4b */ ?>
      <div class="adm-gap">
        <strong class="adm-muted">Latest announcements to its network</strong>
        <ul class="adm-ann-list">
          <?php foreach ($biz['anns'] as $an): ?>
          <li><span class="adm-mono"><?= htmlspecialchars(date('j M Y', strtotime((string) $an['created_at']))) ?></span>
            <strong><?= htmlspecialchars($an['title']) ?></strong> — <?= htmlspecialchars(mb_strimwidth((string) $an['body'], 0, 140, '…')) ?>
            <span class="adm-sub">(<?= (int) $an['recipients'] ?> brokers<?= (int) $an['emailed'] ? ', emailed' : '' ?>)</span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php $bLocked = ($bOnline || $bInvoice) && !(int) ($bSub['cancel_at_period_end'] ?? 0); /* 0026 */ ?>
      <?php if ($bizSuper && $bLocked): ?>
      <p class="adm-sub adm-gap"><?= $bOnline ? 'This business pays online — cancel the PayFast subscription above to change its plan by hand.'
                                               : 'This business is invoiced monthly — stop invoicing above to change its plan by hand.' ?></p>
      <?php elseif ($bizSuper): ?>
      <form method="POST" action="<?= $self ?>&amp;tab=business#plan" class="plan-assign"
            data-confirm="Change the business plan for <?= htmlspecialchars($org['name'], ENT_QUOTES) ?>?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="biz_set_plan">
        <div class="fgroup">
          <label class="flabel" for="biz_plan_code">Plan</label>
          <select class="finput" id="biz_plan_code" name="plan_code">
            <?php foreach ($biz['plans'] as $bp): ?>
            <option value="<?= htmlspecialchars($bp['code']) ?>" <?= ($acctPlan['code'] ?? '') === $bp['code'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($bp['name']) ?> — <?= htmlspecialchars(sdPlanPriceLabel($bp)) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <fieldset class="fgroup plan-status">
          <legend class="flabel">For paid plans</legend>
          <label><input type="radio" name="plan_status" value="active" <?= ($acctSub['status'] ?? 'active') !== 'comped' ? 'checked' : '' ?>> Paid</label>
          <label><input type="radio" name="plan_status" value="comped" <?= ($acctSub['status'] ?? '') === 'comped' ? 'checked' : '' ?>> Comped (free)</label>
        </fieldset>
        <div class="fgroup">
          <label class="flabel" for="biz_plan_until">Until <span class="flabel-opt">optional</span></label>
          <input class="finput" id="biz_plan_until" name="plan_until" type="date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                 value="<?= !empty($acctSub['current_period_end']) ? date('Y-m-d', strtotime($acctSub['current_period_end'])) : '' ?>">
        </div>
        <div class="fgroup plan-note">
          <label class="flabel" for="biz_plan_note">Note <span class="flabel-opt">optional</span></label>
          <input class="finput" id="biz_plan_note" name="plan_note" maxlength="255" placeholder="e.g. Enterprise deal — 4 dealerships"
                 value="<?= htmlspecialchars((string) ($acctSub['note'] ?? '')) ?>">
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Save plan</button>
      </form>
      <p class="adm-sub">After the end date the business drops back to Free automatically. Choosing Free removes the plan.</p>
      <?php else: ?>
      <p class="adm-sub">Only a superadmin can change the plan.</p>
      <?php endif; ?>

      <h3 class="adm-h3 adm-section-gap">Limits</h3>
      <p class="adm-sub">Each limit applies once its feature ships: broker networks in plans Phase 2b, invites in 2c, extra dealerships in 2d, VIP perks after billing. Setting one now is fine.</p>
      <div class="roster-wrap">
        <table class="roster plan-limits">
          <thead><tr><th>Limit</th><th>Plan</th><th>Custom</th><th>In force</th><?php if ($bizSuper): ?><th>Set a custom limit</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($biz['keys'] as $key => $def):
            $planVal = $acctPlan ? sdPlanLimit($acctPlan, $key) : $def['fallback'];
            $ov      = $biz['overrides'][$key] ?? null;
            $isBool  = $def['type'] === 'bool'; ?>
            <tr>
              <td><span class="adm-strong"><?= htmlspecialchars($def['label']) ?></span></td>
              <td class="adm-mono"><?= sdLimitText($planVal, $key) ?></td>
              <td>
                <?php if ($ov): ?>
                <span class="adm-mono"><?= sdLimitText($ov['limit_value'], $key) ?></span>
                <div class="adm-sub">“<?= htmlspecialchars((string) $ov['reason']) ?>”<?= $ov['set_by_name'] ? ' — ' . htmlspecialchars($ov['set_by_name']) : '' ?>, <?= date('j M Y', strtotime($ov['set_at'])) ?></div>
                <?php if ($bizSuper): ?>
                <form method="POST" action="<?= $self ?>&amp;tab=business#plan" class="adm-inline-form">
                  <?= csrf_hidden_field() ?>
                  <input type="hidden" name="action" value="biz_clear_limit">
                  <input type="hidden" name="limit_key" value="<?= $key ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                </form>
                <?php endif; ?>
                <?php else: ?><span class="adm-sub">—</span><?php endif; ?>
              </td>
              <td class="adm-mono adm-strong"><?= sdLimitText(entitlement('organization', (int) $org['id'], $key), $key) ?></td>
              <?php if ($bizSuper): ?>
              <td>
                <form method="POST" action="<?= $self ?>&amp;tab=business#plan" class="plan-limit-form">
                  <?= csrf_hidden_field() ?>
                  <input type="hidden" name="action" value="biz_set_limit">
                  <input type="hidden" name="limit_key" value="<?= $key ?>">
                  <?php if ($isBool): ?>
                  <?php $cur = $ov ? ($ov['limit_value'] === null || $ov['limit_value'] > 0) : ($planVal === null || $planVal > 0); ?>
                  <select class="finput ua-limit-input" name="limit_value" aria-label="<?= htmlspecialchars($def['label']) ?>">
                    <option value="1" <?= $cur ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= !$cur ? 'selected' : '' ?>>No</option>
                  </select>
                  <?php else: ?>
                  <input class="finput ua-limit-input" type="number" name="limit_value" min="0" max="100000"
                         value="<?= $ov && $ov['limit_value'] !== null ? (int) $ov['limit_value'] : ($planVal ?? '') ?>"
                         aria-label="<?= htmlspecialchars($def['label']) ?>">
                  <label class="plan-unl"><input type="checkbox" name="limit_unlimited" value="1" <?= $ov && $ov['limit_value'] === null ? 'checked' : '' ?>> Unlimited</label>
                  <?php endif; ?>
                  <input class="finput" name="limit_reason" maxlength="255" placeholder="Reason (required)" required
                         aria-label="Reason for <?= htmlspecialchars($def['label']) ?>">
                  <button class="btn btn-primary btn-sm" type="submit">Set</button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
