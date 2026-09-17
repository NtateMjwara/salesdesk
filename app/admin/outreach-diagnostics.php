<?php
/**
 * SalesDesk — Admin: Outreach Encryption Diagnostics
 * Route: /app/admin/outreach-diagnostics.php
 *
 * One-page debug tool for "Registrations are temporarily unavailable."
 * Shows exactly which check in isOutreachIdEncryptionAvailable() is
 * failing — booleans and lengths only, NEVER the actual secret values.
 *
 * Delete this file (or gate it behind an extra check) once the
 * pilot's env config is confirmed stable — it's a diagnostic aid,
 * not something that needs to live in production forever.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

applyCachePolicy('auth');
requireRole('admin');

$diag = outreachIdEncryptionDiagnostics();

// Also surface which SAPI/process this actually ran under, since
// "set in my shell" vs "visible to Apache/PHP-FPM" is the #1 cause.
$sapi        = php_sapi_name();
$phpFpmPool  = getenv('FPM_POOL') ?: ($_SERVER['FPM_POOL'] ?? null);

ob_start();
?>
<div class="section-head" style="margin-bottom:1.5rem;">
  <h1 class="section-title">Outreach Encryption Diagnostics</h1>
</div>

<div class="alert alert-info" style="margin-bottom:1.5rem;">
  <span class="alert-icon">ℹ</span>
  <div>
    This page never displays your actual secrets — only whether each
    check passes, and (for the key/pepper) how many characters were
    read. If a value that you know you set shows up as "not present",
    the process serving this request cannot see it — see the notes
    below the table.
  </div>
</div>

<div class="card card-body" style="margin-bottom:1.5rem;">
  <table style="width:100%;font-size:13px;border-collapse:collapse;">
    <tbody>
      <tr>
        <td style="padding:8px 0;color:var(--faint);width:55%;">PHP SAPI running this request</td>
        <td style="font-family:var(--mono);"><?= htmlspecialchars($sapi) ?></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:var(--faint);">USE_OUTREACH_ID_ENCRYPTION defined in config.php?</td>
        <td><?= $diag['use_flag_defined'] ? '✅ yes' : '❌ no — add it to config.php' ?></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:var(--faint);">USE_OUTREACH_ID_ENCRYPTION is truthy?</td>
        <td><?= $diag['use_flag_true'] ? '✅ yes' : '❌ no — set it to true' ?></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:var(--faint);">OUTREACH_ID_ENCRYPTION_KEY visible to this process?</td>
        <td><?= $diag['key_present'] ? '✅ yes' : '❌ no' ?></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:var(--faint);">OUTREACH_ID_ENCRYPTION_KEY length</td>
        <td><?= (int)$diag['key_length'] ?> chars — <?= $diag['key_length_ok'] ? '✅ ≥ 32, OK' : '❌ needs to be ≥ 32' ?></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:var(--faint);">OUTREACH_ID_HASH_PEPPER visible to this process?</td>
        <td><?= $diag['pepper_present'] ? '✅ yes' : '❌ no' ?></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:var(--faint);">OUTREACH_ID_HASH_PEPPER length</td>
        <td><?= (int)$diag['pepper_length'] ?> chars — <?= $diag['pepper_length_ok'] ? '✅ ≥ 16, OK' : '❌ needs to be ≥ 16' ?></td>
      </tr>
      <tr style="border-top:1px solid var(--border);">
        <td style="padding:12px 0 0;font-weight:700;">Overall: isOutreachIdEncryptionAvailable()</td>
        <td style="padding:12px 0 0;font-weight:700;color:<?= $diag['available'] ? 'var(--green)' : 'var(--red)' ?>;">
          <?= $diag['available'] ? '✅ AVAILABLE — registrations should work' : '❌ NOT AVAILABLE — this is why registrations are blocked' ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<?php if (!$diag['available']): ?>
<div class="alert alert-warn">
  <span class="alert-icon">⚠</span>
  <div style="font-size:13px;line-height:1.7;">
    <strong>Most likely cause:</strong> environment variables set via a shell
    <code>export</code> (or a <code>.env</code> file only loaded for CLI
    scripts) are <strong>not automatically visible</strong> to your web
    server process. <code><?= htmlspecialchars($sapi) ?></code> is a
    separate, usually long-lived process that only sees the environment
    it was started with.<br><br>
    <strong>Apache + mod_php:</strong> set the vars where Apache itself
    reads its environment (e.g. <code>/etc/apache2/envvars</code>, or
    <code>SetEnv</code> in the vhost), then restart Apache.<br>
    <strong>PHP-FPM:</strong> add <code>env[OUTREACH_ID_ENCRYPTION_KEY] = ...</code>
    and <code>env[OUTREACH_ID_HASH_PEPPER] = ...</code> to your pool
    config (e.g. <code>www.conf</code>), then <strong>fully restart</strong>
    php-fpm — <code>env[]</code> is only read at master-process startup,
    a reload is not enough.<br>
    <strong>Docker:</strong> confirm the vars exist in the *running*
    container (<code>docker exec &lt;container&gt; printenv | grep OUTREACH</code>),
    not just in a <code>.env</code> file the compose file forgot to reference.<br>
    <strong>CLI works but web doesn't (or vice versa):</strong> that split
    is itself the diagnosis — the two run as different processes with
    different environments.
  </div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$pageTitle   = 'Outreach Encryption Diagnostics | Admin';
require_once '../../views/layout-app.php';
