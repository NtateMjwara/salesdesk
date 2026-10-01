<?php
/**
 * Admin partial — database is behind the code.  (0012/0013)
 * Rendered by adminRequireSchema() (includes/admin_scope.php).
 * Expects: array $byMigration  migration file => list of missing items
 */
?>
<div class="section-head">
  <h1 class="section-title"><?= htmlspecialchars($pageTitle) ?></h1>
</div>
<div class="alert alert-error adm-gap">
  <span class="alert-icon">!</span>
  <div>
    <strong>The database needs updating before this page can work.</strong>
    Click below to run the update<?= count($byMigration) > 1 ? 's' : '' ?> from the server — it's safe to run again
    if an earlier attempt stopped part-way. (Pasting the SQL into a web database tool is often blocked by the
    host's firewall with “Forbidden”.)
  </div>
</div>
<form method="POST" action="/app/admin/migrations" class="adm-gap">
  <?= csrf_hidden_field() ?>
  <input type="hidden" name="action" value="run_all">
  <button class="btn btn-primary" type="submit">Run database updates now</button>
  <a class="btn btn-ghost" href="/app/admin/migrations">Details</a>
</form>
<?php foreach ($byMigration as $file => $missing): ?>
<div class="card adm-card">
  <div class="card-body">
    <h2 class="adm-h2"><span class="adm-mono">db/<?= htmlspecialchars($file) ?></span></h2>
    <p class="adm-muted">Missing: <?= htmlspecialchars(implode(', ', $missing)) ?></p>
    <p class="adm-sub">Or from a terminal: <span class="adm-mono">mysql -u salesdesk_user -p salesdesk_db &lt; db/<?= htmlspecialchars($file) ?></span></p>
  </div>
</div>
<?php endforeach; ?>
