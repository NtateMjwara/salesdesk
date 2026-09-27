<?php
/**
 * SalesDesk — Admin: Database updates  (0012/0013)
 * Route: /app/admin/migrations
 *
 * Runs the bundled migrations (db/0012…, db/0013…) from the server's own
 * copy of the files. Use this instead of pasting the SQL into a web SQL
 * tool: hosting firewalls block that with "403 Forbidden" (see
 * includes/migrations.php). Both migrations are safe to run repeatedly.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/admin_scope.php';
require_once '../../includes/migrations.php';

applyCachePolicy('auth');
requireRole('admin');

$adminId = (int) $_SESSION['user_id'];
$runLog     = $_SESSION['migration_log'] ?? null;
$resetLog   = $_SESSION['reinstall_log'] ?? null;
$testResult = $_SESSION['test_save'] ?? null;
unset($_SESSION['migration_log'], $_SESSION['reinstall_log'], $_SESSION['test_save']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    // Reset & reinstall 0013 (data-preserving — see includes/migrations.php)
    if ($action === 'reinstall') {
        if (empty($_POST['confirm'])) {
            $_SESSION['flash_error'] = 'Tick the confirmation box first.';
        } else {
            $res = sdReinstall0013($adminId);
            $_SESSION[$res['ok'] ? 'flash_ok' : 'flash_error'] = $res['ok']
                ? 'Reset complete — 0012 and 0013 were re-installed and your settings restored.'
                : 'The reset stopped — details below.';
            $_SESSION['reinstall_log'] = $res;
        }
        redirect('/app/admin/migrations');
    }

    // Dry-run the organisation Details save and report the real error.
    if ($action === 'test_save') {
        $stmt = Database::getInstance()->prepare("
            SELECT o.*, a.province, a.city, a.suburb FROM organizations o
            LEFT JOIN addresses a ON a.id = o.address_id WHERE o.id = ?");
        $stmt->execute([(int) ($_POST['org_id'] ?? 0)]);
        $org = $stmt->fetch();
        if ($org) {
            $brands = orgBrands($org['brands'] ?? null) ?: ['Toyota'];
            [$ok, $msg] = adminSaveOrgDetails($org, [
                'name'                   => $org['name'],
                'brands'                 => $brands,
                'description'            => $org['description'] ?? '',
                'agent_car_limit'        => (string) ($org['agent_car_limit'] ?? ''),
                'cipc_number'            => $org['cipc_number'] ?? '',
                'province'               => $org['province'] ?? '',
                'city'                   => $org['city'] ?? '',
                'suburb'                 => $org['suburb'] ?? '',
                'logo_url'               => $org['logo_url'] ?? '',
                'accepting_applications' => ($org['accepting_applications'] ?? 1) ? '1' : '',
                'verification_status'    => $org['verification_status'],
            ], $adminId, true);
            $_SESSION['test_save'] = ['ok' => $ok, 'msg' => $msg, 'org' => $org['name'], 'brands' => $brands];
        }
        redirect('/app/admin/migrations');
    }
    $files  = $action === 'run_all'
        ? array_keys(SD_MIGRATIONS)
        : (array_key_exists($_POST['file'] ?? '', SD_MIGRATIONS) ? [$_POST['file']] : []);

    $log = [];
    foreach ($files as $file) {
        $res   = sdRunMigration($file, $adminId);
        $log[] = ['file' => $file] + $res;
        if (!$res['ok']) {
            break;   // later migrations depend on earlier ones
        }
    }
    if ($log) {
        $failed = array_filter($log, fn($l) => !$l['ok']);
        $_SESSION[$failed ? 'flash_error' : 'flash_ok'] = $failed
            ? 'A database update stopped — details below.'
            : 'Database is up to date.';
        $_SESSION['migration_log'] = $log;
    }
    redirect('/app/admin/migrations');
}

$allOrgs = [];
try {
    $allOrgs = Database::getInstance()->query("SELECT id, name FROM organizations ORDER BY name")->fetchAll();
} catch (Throwable) {}

// Status: what's still missing, per migration.
$problems  = adminSchemaProblems();
$missingBy = [];
foreach ($problems as $p) {
    $missingBy[$p['migration']][] = $p['missing'];
}
$applied = [];
try {
    foreach (Database::getInstance()->query("SELECT name, applied_at FROM schema_migrations") as $r) {
        $applied[$r['name']] = $r['applied_at'];
    }
} catch (Throwable) {
    // schema_migrations is created by 0012 — absent before the first run.
}

ob_start();
?>
<div class="section-head">
  <h1 class="section-title">Database updates</h1>
  <span class="section-count"><?= $problems ? 'Update needed' : 'Up to date' ?></span>
  <form method="POST" class="adm-head-action">
    <?= csrf_hidden_field() ?>
    <input type="hidden" name="action" value="run_all">
    <button class="btn btn-primary btn-sm" type="submit"><?= $problems ? 'Run all updates' : 'Run again' ?></button>
  </form>
</div>

<p class="adm-muted adm-gap">
  Runs the update files that shipped with this release, straight from the server.
  Use this rather than pasting the SQL into a database tool — web firewalls often block that with “Forbidden”.
  Each update checks what's already there, so running it again is harmless.
</p>

<?php if ($runLog): ?>
<div class="card adm-card">
  <div class="card-body">
    <h2 class="adm-h2">Last run</h2>
    <?php foreach ($runLog as $l): ?>
      <div class="adm-list-row">
        <div>
          <div class="adm-strong adm-mono">db/<?= htmlspecialchars($l['file']) ?></div>
          <div class="adm-sub">
            <?= (int) $l['statements'] ?> statement<?= (int) $l['statements'] === 1 ? '' : 's' ?> run
            <?= $l['notes'] ? ' · ' . htmlspecialchars(implode(' · ', $l['notes'])) : '' ?>
          </div>
          <?php if (!$l['ok']): ?>
            <div class="alert alert-error adm-migration-error">
              <span class="alert-icon">!</span>
              <div>
                <strong><?= htmlspecialchars($l['error']) ?></strong>
                <?php if ($l['failed_sql']): ?><pre class="adm-pre"><?= htmlspecialchars($l['failed_sql']) ?></pre><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?= $l['ok'] ? '<span class="badge badge-verified">Done</span>' : '<span class="badge badge-rejected">Failed</span>' ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="roster-wrap">
  <table class="roster">
    <thead><tr><th>Update</th><th>Status</th><th>Still missing</th><th></th></tr></thead>
    <tbody>
    <?php foreach (SD_MIGRATIONS as $file => $label):
      $missing = $missingBy[$file] ?? [];
      $stem    = substr($file, 0, -4); ?>
      <tr>
        <td>
          <div class="adm-strong adm-mono">db/<?= htmlspecialchars($file) ?></div>
          <div class="adm-sub"><?= htmlspecialchars($label) ?></div>
        </td>
        <td>
          <?= $missing ? '<span class="badge badge-pending">Needed</span>' : '<span class="badge badge-verified">Applied</span>' ?>
          <?php if (isset($applied[$stem])): ?><div class="adm-sub">Applied <?= date('d M Y H:i', strtotime($applied[$stem])) ?></div><?php endif; ?>
        </td>
        <td class="adm-muted"><?= $missing ? htmlspecialchars(implode(', ', $missing)) : '—' ?></td>
        <td>
          <form method="POST">
            <?= csrf_hidden_field() ?>
            <input type="hidden" name="action" value="run_one">
            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Run</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 class="adm-h2 adm-section-gap">Troubleshooting</h2>

<div class="adm-tools">
  <div class="card adm-card">
    <div class="card-body">
      <h3 class="adm-h3">Test saving an organisation</h3>
      <p class="adm-muted">Runs the exact “Save details” an admin does on the organisation page, then undoes it.
         Shows the real database error if it fails.</p>
      <?php if ($testResult): ?>
        <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-error' ?> adm-gap">
          <span class="alert-icon"><?= $testResult['ok'] ? '✓' : '!' ?></span>
          <div><strong><?= htmlspecialchars($testResult['org']) ?></strong>
               (brands <?= htmlspecialchars(implode(', ', $testResult['brands'])) ?>):
               <?= htmlspecialchars($testResult['msg']) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($allOrgs): ?>
      <form method="POST" class="adm-inline">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="test_save">
        <select class="finput" name="org_id" aria-label="Organisation">
          <?php foreach ($allOrgs as $o): ?>
          <option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">Test save</button>
      </form>
      <?php else: ?>
        <p class="adm-sub">No organisations yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card adm-card adm-danger">
    <div class="card-body">
      <h3 class="adm-h3">Reset &amp; reinstall the desk organisation update</h3>
      <p class="adm-muted">
        Removes everything <span class="adm-mono">0013</span> added (brands, description, car limit, application
        settings and the agent-approval columns), runs <span class="adm-mono">0012</span> and
        <span class="adm-mono">0013</span> again from scratch, then puts your saved values back.
        Use this if earlier attempts left the database in an odd state.
      </p>
      <p class="adm-sub">
        Kept: every organisation, member, dealership, manager link and car. Values are saved to the
        <span class="adm-mono">sd_reinstall_snapshot</span> table first, so an interrupted reset can simply be run again.
      </p>
      <?php if ($resetLog): ?>
        <ol class="adm-steps">
          <?php foreach ($resetLog['steps'] as $st): ?><li><?= htmlspecialchars($st) ?></li><?php endforeach; ?>
        </ol>
        <?php if (!$resetLog['ok']): ?>
          <pre class="adm-pre"><?= htmlspecialchars($resetLog['error']) ?></pre>
        <?php endif; ?>
      <?php endif; ?>
      <form method="POST" data-confirm="Reset and reinstall the desk organisation update now?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="reinstall">
        <label class="adm-checks"><span><input type="checkbox" name="confirm" value="1"> I understand this changes the database structure</span></label>
        <button class="btn btn-danger btn-sm" type="submit">Reset &amp; reinstall</button>
      </form>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Database updates | Admin';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
