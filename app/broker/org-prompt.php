<?php
/**
 * SalesDesk — Organisation Mode Prompt.
 * T4 owns this file. Route: /app/broker/org-prompt.php
 *
 * Task o1: Shown to a broker on their first login after completing
 * onboarding, if they have no organisation membership.
 *
 * Options:
 *   "I'm a solo broker"  → dismisses prompt, never shown again (sets profile flag)
 *   "Create a team"      → redirects to create-org.php wizard
 *
 * NOT shown during signup — broker completes personal setup first,
 * then sees this prompt on their next login.
 *
 * The broker dashboard checks:
 *   if (onboarding_completed && no_org_membership && !org_prompt_dismissed)
 *     redirect here
 */
declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';

applyCachePolicy('auth');
requireLogin();
requireRole('broker');

$pdo    = Database::getInstance();
$userId = (int) $_SESSION['user_id'];
$csrf   = generateCSRFToken();

// If broker already has an org, redirect to dashboard
$orgCheck = $pdo->prepare("
    SELECT om.organization_id FROM organization_members om
    JOIN organizations o ON o.id = om.organization_id
    WHERE om.user_id = ? AND o.is_active = 1
    LIMIT 1
");
$orgCheck->execute([$userId]);
if ($orgCheck->fetch()) {
    redirect('/app/broker/dashboard.php');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $choice = $_POST['choice'] ?? '';

    if ($choice === 'solo') {
        // Dismiss prompt permanently — store in platform via a simple profiles flag
        // We re-use the profiles.bio field is NOT appropriate.
        // Instead we add a JSON meta approach via a notifications record as a marker,
        // OR simply redirect — the dashboard can check session flag.
        // Cleanest MVP: set a session flag + store in a platform_config-style user pref.
        // For MVP we use a session flag + a notifications "dismissed" marker.
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, body, is_read, read_at, created_at)
            VALUES (?, 'org_prompt_dismissed', 'Solo broker confirmed', NULL, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ")->execute([$userId]);

        redirect('/app/broker/dashboard.php');
    }

    if ($choice === 'create') {
        redirect('/app/broker/create-org.php');
    }
}

// Load broker profile for greeting
$profileStmt = $pdo->prepare("
    SELECT p.first_name, sd.display_name
    FROM profiles p
    LEFT JOIN salesdesks sd ON sd.user_id = p.user_id
    WHERE p.user_id = ?
");
$profileStmt->execute([$userId]);
$profile    = $profileStmt->fetch();
$firstName  = $profile['first_name'] ?? '';
$deskName   = $profile['display_name'] ?? 'your SalesDesk';

$pageTitle = 'Work solo or as a team?';
ob_start();
?>

<div style="max-width:620px;margin:3rem auto;padding:0 1rem;">

  <!-- Header -->
  <div style="text-align:center;margin-bottom:2.5rem;">
    <div style="width:64px;height:64px;background:var(--p-light);border-radius:50%;
                margin:0 auto 1.25rem;display:flex;align-items:center;
                justify-content:center;font-size:26px;color:var(--p);">
      <i class="fa-solid fa-users"></i>
    </div>
    <h1 style="font-family:var(--serif);font-size:1.75rem;font-weight:300;margin-bottom:8px;">
      <?= $firstName ? 'Hi ' . htmlspecialchars($firstName) . ',' : 'Welcome,' ?>
      are you working <em style="font-style:italic;">solo or with a team?</em>
    </h1>
    <p style="font-size:14px;color:var(--muted);line-height:1.7;max-width:480px;margin:0 auto;">
      You can work on your own with <strong><?= htmlspecialchars($deskName) ?></strong>,
      or create a team organisation to share leads, pool commissions, and track
      performance together.
    </p>
  </div>

  <!-- Choice cards -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:2rem;">

    <!-- Solo option -->
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="choice" value="solo">
      <button type="submit"
              style="width:100%;background:var(--white);border:1.5px solid var(--border);
                     border-radius:var(--r-xl);padding:2rem 1.5rem;text-align:left;
                     cursor:pointer;font-family:var(--sans);transition:border-color .15s,box-shadow .15s;"
              onmouseover="this.style.borderColor='var(--border2)';this.style.boxShadow='var(--shadow-md)'"
              onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
        <div style="font-size:28px;margin-bottom:14px;color:var(--p);">
          <i class="fa-solid fa-id-card"></i>
        </div>
        <div style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:6px;">
          I'm a solo broker
        </div>
        <div style="font-size:13px;color:var(--muted);line-height:1.6;">
          Work independently with your personal SalesDesk. All leads and commissions
          belong to you alone.
        </div>
        <div style="margin-top:1.25rem;font-size:12px;color:var(--faint);">
          You can always create a team organisation later from your dashboard.
        </div>
      </button>
    </form>

    <!-- Team / org option -->
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="choice" value="create">
      <button type="submit"
              style="width:100%;background:var(--p);border:1.5px solid var(--p);
                     border-radius:var(--r-xl);padding:2rem 1.5rem;text-align:left;
                     cursor:pointer;font-family:var(--sans);transition:background .15s,box-shadow .15s;"
              onmouseover="this.style.background='var(--p-dark)';this.style.boxShadow='var(--shadow-md)'"
              onmouseout="this.style.background='var(--p)';this.style.boxShadow='none'">
        <div style="font-size:28px;margin-bottom:14px;color:#93c5fd;">
          <i class="fa-solid fa-building"></i>
        </div>
        <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:6px;">
          Create a team organisation
        </div>
        <div style="font-size:13px;color:rgba(255,255,255,.8);line-height:1.6;">
          Build a brokerage team — invite colleagues, share leads, track everyone's
          performance, and pool commissions under one org.
        </div>
        <div style="margin-top:1.25rem;font-size:12px;color:rgba(255,255,255,.5);">
          Takes about 2 minutes to set up.
        </div>
      </button>
    </form>

  </div>

  <!-- Feature comparison -->
  <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);
              overflow:hidden;margin-bottom:2rem;">
    <div style="padding:12px 18px;background:var(--bg);border-bottom:1px solid var(--border);">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;
                   letter-spacing:.08em;color:var(--faint);">
        Feature comparison
      </span>
    </div>
    <?php
    $features = [
      ['Personal SalesDesk public page',     true,  true],
      ['Add cars to your desk',               true,  true],
      ['Receive leads & commissions',         true,  true],
      ['Track your own analytics',            true,  true],
      ['Shared team lead pipeline',           false, true],
      ['Organisation commission pooling',     false, true],
      ['Per-agent performance tracking',      false, true],
      ['Organisation CIPC verification',      false, true],
      ['Invite & manage team members',        false, true],
    ];
    foreach ($features as $i => [$label, $solo, $org]):
    ?>
    <div style="display:flex;align-items:center;padding:10px 18px;
                border-bottom:<?= $i < count($features)-1 ? '1px solid var(--border)' : 'none' ?>;
                font-size:13px;color:var(--text2);">
      <span style="flex:1;"><?= $label ?></span>
      <span style="width:80px;text-align:center;color:<?= $solo ? 'var(--green)' : 'var(--faint)' ?>;">
        <?= $solo ? '<i class="fa-solid fa-check"></i>' : '—' ?>
      </span>
      <span style="width:80px;text-align:center;color:var(--green);">
        <i class="fa-solid fa-check"></i>
      </span>
    </div>
    <?php endforeach; ?>
    <div style="display:flex;padding:10px 18px;background:var(--bg);">
      <span style="flex:1;font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Feature</span>
      <span style="width:80px;text-align:center;font-size:11px;color:var(--muted);font-weight:600;">Solo</span>
      <span style="width:80px;text-align:center;font-size:11px;color:var(--p);font-weight:600;">Team org</span>
    </div>
  </div>

  <!-- Skip link -->
  <div style="text-align:center;">
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="choice" value="solo">
      <button type="submit"
              style="background:none;border:none;color:var(--faint);font-size:13px;
                     cursor:pointer;font-family:var(--sans);text-decoration:underline;">
        Skip for now — I'll decide later
      </button>
    </form>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../../views/layout-app.php';
