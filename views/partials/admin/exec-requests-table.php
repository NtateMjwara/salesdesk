<?php
/**
 * Admin partial — pending sales exec requests table.  (0012)
 *
 * Expects:
 *   array $pending     rows from adminPendingExecApplications()
 *   bool  $showDealer  show the Dealership column (cross-dealership queue)
 *
 * Approve posts directly; Decline opens #execReasonModal
 * (views/partials/admin/exec-reason-modal.php) via assets/js/admin.js.
 */
$showDealer = $showDealer ?? false;
?>
<div class="roster-wrap adm-gap">
  <table class="roster">
    <thead>
      <tr>
        <th>Applicant</th>
        <?php if ($showDealer): ?><th>Dealership</th><?php endif; ?>
        <th>Job title</th>
        <th>Email verified</th>
        <th>Applied</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pending as $app):
      $appName = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')) ?: $app['email']; ?>
      <tr>
        <td>
          <div class="adm-strong"><?= htmlspecialchars($appName) ?></div>
          <div class="adm-sub"><?= htmlspecialchars($app['email']) ?><?= $app['phone'] ? ' · ' . htmlspecialchars($app['phone']) : '' ?></div>
        </td>
        <?php if ($showDealer): ?>
        <td>
          <a href="/app/admin/dealerships-view?id=<?= (int) $app['dealer_id'] ?>"><?= htmlspecialchars($app['dealer_name']) ?></a>
        </td>
        <?php endif; ?>
        <td class="adm-muted"><?= htmlspecialchars($app['job_title'] ?? '—') ?></td>
        <td class="adm-muted"><?= $app['email_verified'] ? '✓ Yes' : '— Not yet' ?></td>
        <td class="adm-muted"><?= date('d M Y', strtotime($app['created_at'])) ?></td>
        <td><div class="adm-actions">
          <form method="POST" data-confirm="Approve <?= htmlspecialchars($appName) ?> for <?= htmlspecialchars($app['dealer_name']) ?>? They'll be emailed and can start uploading cars.">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="exec_id" value="<?= (int) $app['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-success btn-sm" type="submit">Approve</button>
          </form>
          <button type="button" class="btn btn-danger btn-sm"
                  data-reason-modal="reject" data-exec-id="<?= (int) $app['id'] ?>"
                  data-exec-name="<?= htmlspecialchars($appName) ?>">Decline</button>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
