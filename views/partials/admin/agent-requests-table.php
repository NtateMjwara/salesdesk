<?php
/**
 * Admin partial — pending desk organisation agent applications.  (0013)
 *
 * Expects:
 *   array $agentPending  rows from adminPendingAgentApplications()
 *   bool  $showOrg       show the Organisation column (cross-org queue)
 *
 * Approve posts kind=agent, action=approve, member_id. Decline opens
 * #execReasonModal with data-reason-kind="agent".
 */
$showOrg = $showOrg ?? false;
?>
<div class="roster-wrap adm-gap">
  <table class="roster">
    <thead>
      <tr>
        <th>Broker</th>
        <?php if ($showOrg): ?><th>Organisation</th><?php endif; ?>
        <th>SalesDesk</th>
        <th>Track record</th>
        <th>Applied</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($agentPending as $app):
      $appName = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')) ?: $app['email']; ?>
      <tr>
        <td>
          <div class="adm-strong"><?= htmlspecialchars($appName) ?></div>
          <div class="adm-sub"><?= htmlspecialchars($app['email']) ?><?= $app['phone'] ? ' · ' . htmlspecialchars($app['phone']) : '' ?></div>
        </td>
        <?php if ($showOrg): ?>
        <td><a href="/app/admin/desk-orgs-view?id=<?= (int) $app['org_id'] ?>"><?= htmlspecialchars($app['org_name']) ?></a></td>
        <?php endif; ?>
        <td class="adm-muted">
          <?php if ($app['desk_slug']): ?>
            <a href="/<?= htmlspecialchars($app['desk_slug']) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($app['desk_name']) ?> ↗</a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="adm-muted"><?= (int) $app['desk_cars'] ?> car<?= (int) $app['desk_cars'] === 1 ? '' : 's' ?> · <?= (int) $app['total_leads'] ?> lead<?= (int) $app['total_leads'] === 1 ? '' : 's' ?></td>
        <td class="adm-muted"><?= date('d M Y', strtotime($app['applied_at'])) ?></td>
        <td><div class="adm-actions">
          <form method="POST" data-confirm="Approve <?= htmlspecialchars($appName) ?> as an agent at <?= htmlspecialchars($app['org_name']) ?>? They'll be emailed.">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="kind" value="agent">
            <input type="hidden" name="member_id" value="<?= (int) $app['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-success btn-sm" type="submit">Approve</button>
          </form>
          <button type="button" class="btn btn-danger btn-sm"
                  data-reason-modal="reject" data-reason-kind="agent" data-exec-id="<?= (int) $app['id'] ?>"
                  data-exec-name="<?= htmlspecialchars($appName) ?>">Decline</button>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
