<?php
/**
 * SalesDesk — Admin: one user  (0019)
 * Route: /app/admin/users-view?id={user_id}[&tab=overview|edit|account|activity]
 *
 *   overview — identity, connections (dealership, employer, desk org,
 *              SalesDesk), role numbers, sign-in health.
 *   edit     — superadmin: name, phone, bio, address, broker car limit,
 *              sign-in email (step-up; old address is notified).
 *   account  — status (activate / suspend / reactivate, dealer cascade),
 *              email verification (verify / un-verify / resend code),
 *              password reset code, clear sign-in lockout, finish onboarding.
 *              Admins (non-super) only get suspend / reactivate here.
 *   activity — audit log entries about / by this user.
 *
 * Scope: admins only reach users connected to their dealerships / orgs
 * (adminCanActOnUser). Admin accounts redirect to /app/admin/admins-view.
 * Logic lives in includes/user_admin.php; all writes are audited.
 */

declare(strict_types=1);

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/user_admin.php';   // loads admin_scope.php + superadmin.php

applyCachePolicy('auth');
requireRole('admin');

$adminId      = (int) $_SESSION['user_id'];
$isSuperadmin = isSuperadmin($adminId);
$targetId     = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);
$tabs         = $isSuperadmin
    ? ['overview' => 'Overview', 'edit' => 'Edit', 'account' => 'Account', 'activity' => 'Activity']
    : ['overview' => 'Overview', 'account' => 'Account', 'activity' => 'Activity'];
$tab          = is_string($_GET['tab'] ?? null) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'overview';

$user = $targetId ? uaGetUser($targetId) : false;
if (!$user) {
    $isAdminAccount = $targetId && ($row = getUserById($targetId)) && $row['role'] === 'admin';
    if ($isAdminAccount && $isSuperadmin) {
        redirect('/app/admin/admins-view?id=' . $targetId);
    }
    $_SESSION['flash_error'] = 'That user was not found.';
    redirect('/app/admin/users');
}
if (!adminCanActOnUser($adminId, $targetId)) {
    $_SESSION['flash_error'] = 'That account isn’t connected to a dealership or organisation you manage.';
    redirect('/app/admin/users');
}
$self = '/app/admin/users-view?id=' . $targetId;

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action  = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $backTab = is_string($_POST['tab'] ?? null) && array_key_exists($_POST['tab'], $tabs) ? $_POST['tab'] : 'account';
    $back    = $self . '&tab=' . $backTab;

    $everyone  = ['suspend_user', 'reactivate_user'];
    $superOnly = ['activate', 'verify_email', 'unverify_email', 'resend_verification', 'send_reset',
                  'clear_lockout', 'complete_onboarding', 'update_profile', 'change_email', 'set_car_limit'];

    if (!in_array($action, $everyone, true) && !(in_array($action, $superOnly, true) && $isSuperadmin)) {
        $_SESSION['flash_error'] = 'Only a superadmin can do that.';
        redirect($back);
    }

    // Changing the sign-in email can hand over the account: confirm first.
    if ($action === 'change_email') {
        sdRequireStepUp($self . '&tab=edit', 'Changing a sign-in email needs a quick confirmation. Nothing was changed — enter the new email again afterwards.');
    }

    [$ok, $msg] = match ($action) {
        'suspend_user'        => uaSetStatus($targetId, 'suspended', $adminId),
        'reactivate_user'     => uaSetStatus($targetId, 'active', $adminId),
        'activate'            => uaActivate($targetId, $adminId, !empty($_POST['also_verify'])),
        'verify_email'        => uaSetEmailVerified($targetId, true, $adminId),
        'unverify_email'      => uaSetEmailVerified($targetId, false, $adminId),
        'resend_verification' => uaResendVerification($targetId, $adminId),
        'send_reset'          => uaSendPasswordReset($targetId, $adminId),
        'clear_lockout'       => uaClearLockout($targetId, $adminId),
        'complete_onboarding' => uaCompleteOnboarding($targetId, $adminId),
        'update_profile'      => uaUpdateProfile($targetId, $_POST, $adminId),
        'change_email'        => uaChangeEmail($targetId, (string) ($_POST['new_email'] ?? ''), !empty($_POST['keep_verified']), $adminId),
        'set_car_limit'       => uaSetCarLimit($targetId, (int) ($_POST['car_limit'] ?? 0), $adminId),
    };
    $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $msg;
    redirect($back);
}

// ── Load ──────────────────────────────────────────────────────
$name         = uaDisplayName($user) ?: $user['email'];
$person       = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$numbers      = uaUserNumbers($user);
$failedLogins = uaFailedLogins((string) $user['email']);
$activity     = $tab === 'activity' ? uaUserActivity($user) : [];
$isDealer     = $user['role'] === 'dealer';
$isPending    = $user['status'] === 'pending';
$isActive     = $user['status'] === 'active';
$isSuspended  = $user['status'] === 'suspended';
$verified     = (int) $user['email_verified'] === 1;
$hasPassword  = (int) $user['has_password'] === 1;
$onboarded    = (int) $user['onboarding_completed'] === 1;
$defaultLimit = getPlatformConfigInt('broker_car_limit_default', 10);
$fmt          = fn(?string $d, string $f = 'd M Y, H:i') => $d ? date($f, strtotime($d)) : '—';

// Problems worth flagging at the top of every tab.
$warnings = [];
if ($isPending)                $warnings[] = 'This account is pending — they can’t sign in until it’s active.';
if (!$hasPassword)             $warnings[] = 'No password set yet — send a password reset code so they can choose one.';
if (!$onboarded && !$isPending) $warnings[] = 'Signup isn’t finished — they’re sent back to the signup wizard when they sign in.';
if ($failedLogins >= RATE_LIMIT_ATTEMPTS) $warnings[] = 'Locked out by too many failed sign-ins.';

ob_start();
?>
<a class="adm-back" href="/app/admin/users"><i class="fa-solid fa-arrow-left"></i> All users</a>

<div class="section-head">
  <h1 class="section-title"><?= htmlspecialchars($name) ?></h1>
  <span class="badge <?= uaRoleBadgeClass($user['role']) ?>"><?= uaRoleLabel($user['role']) ?></span>
  <?= adminStatusBadge($user['status']) ?>
  <?php if ($verified): ?><span class="badge badge-verified"><i class="fa-solid fa-check"></i> Email verified</span>
  <?php else: ?><span class="badge badge-rejected">Email unverified</span><?php endif; ?>
  <span class="section-count"><?= htmlspecialchars($user['email']) ?> · #<?= (int) $user['id'] ?></span>
</div>

<?php if ($warnings && $tab !== 'activity'): ?>
<div class="alert alert-warn adm-gap">
  <span class="alert-icon">!</span>
  <div><?= implode('<br>', array_map('htmlspecialchars', $warnings)) ?>
    <?php if ($isSuperadmin && $tab !== 'account'): ?> <a href="<?= $self ?>&amp;tab=account">Fix on the Account tab →</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<nav class="adm-tabs" aria-label="User sections">
  <?php foreach ($tabs as $key => $label): ?>
  <a href="<?= $self ?>&amp;tab=<?= $key ?>" class="adm-tab<?= $tab === $key ? ' is-active' : '' ?>"
     <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>

  <?php if ($numbers): ?>
  <div class="adm-stats">
    <?php foreach ($numbers as $label => $value): ?>
    <div class="adm-stat"><span class="adm-stat-n"><?= htmlspecialchars((string) $value) ?></span><span class="adm-stat-l"><?= htmlspecialchars($label) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="ua-grid">
    <div class="card adm-card">
      <div class="card-body">
        <h2 class="adm-h2">Profile</h2>
        <dl class="ua-defs">
          <dt>Name</dt>      <dd><?= htmlspecialchars($person ?: '—') ?></dd>
          <dt>Email</dt>     <dd><?= htmlspecialchars($user['email']) ?></dd>
          <dt>Phone</dt>     <dd><?php if ($user['phone']): ?><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $user['phone'])) ?>"><?= htmlspecialchars($user['phone']) ?></a><?php else: ?>—<?php endif; ?></dd>
          <dt>Location</dt>  <dd><?= htmlspecialchars(implode(', ', array_filter([$user['suburb'], $user['city'], $user['province']])) ?: '—') ?></dd>
          <?php if ($user['role'] === 'broker'): ?>
          <dt>Car limit</dt> <dd><?= (int) ($user['car_limit'] ?? $defaultLimit) ?><?= $user['car_limit'] === null ? ' (platform default)' : '' ?></dd>
          <?php endif; ?>
          <?php if ($user['bio']): ?>
          <dt>Bio</dt>       <dd class="ua-pre"><?= htmlspecialchars($user['bio']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="card adm-card">
      <div class="card-body">
        <h2 class="adm-h2">Account</h2>
        <dl class="ua-defs">
          <dt>Joined</dt>          <dd><?= $fmt($user['created_at']) ?></dd>
          <dt>Last sign-in</dt>    <dd><?= $user['last_login'] ? $fmt($user['last_login']) : 'Never' ?></dd>
          <dt>Last changed</dt>    <dd><?= $fmt($user['updated_at']) ?></dd>
          <dt>Password</dt>        <dd><?= $hasPassword ? 'Set' : '<span class="adm-text-red">Not set</span>' ?></dd>
          <dt>Signup</dt>          <dd><?= $onboarded ? 'Complete' : 'Unfinished (step ' . (int) $user['onboarding_step'] . ')' ?></dd>
          <dt>Failed sign-ins</dt> <dd><?= $failedLogins ?> in the last <?= (int) (RATE_LIMIT_WINDOW / 60) ?> min</dd>
          <dt>UUID</dt>            <dd class="adm-mono"><?= htmlspecialchars($user['uuid']) ?></dd>
        </dl>
      </div>
    </div>

    <div class="card adm-card">
      <div class="card-body">
        <h2 class="adm-h2">Connections</h2>
        <ul class="adm-list">
          <?php if ($user['dealer_id']): ?>
          <li class="adm-list-row"><div>
            <span class="adm-sub">Dealer principal of</span><br>
            <a class="adm-strong" href="/app/admin/dealerships-view?id=<?= (int) $user['dealer_id'] ?>"><?= htmlspecialchars($user['dealer_company']) ?></a>
            <?= adminStatusBadge((string) $user['dealer_verification']) ?>
            <?= $user['dealer_active'] ? '' : adminStatusBadge('inactive') ?>
          </div></li>
          <?php endif; ?>
          <?php if ($user['exec_id']): ?>
          <li class="adm-list-row"><div>
            <span class="adm-sub">Sales exec<?= $user['exec_job_title'] ? ' (' . htmlspecialchars($user['exec_job_title']) . ')' : '' ?> at</span><br>
            <a class="adm-strong" href="/app/admin/dealerships-view?id=<?= (int) $user['exec_dealer_id'] ?>"><?= htmlspecialchars((string) $user['exec_dealer']) ?></a>
            <?= adminStatusBadge((string) $user['exec_status']) ?>
          </div></li>
          <?php elseif ($user['role'] === 'sales_exec'): ?>
          <li class="adm-list-row"><div>
            <span class="adm-muted">Not placed at a dealership.</span>
            <a href="/app/admin/unplaced-execs">Unplaced execs →</a>
          </div></li>
          <?php endif; ?>
          <?php if ($user['org_id']): ?>
          <li class="adm-list-row"><div>
            <span class="adm-sub">Agent of desk organisation</span><br>
            <a class="adm-strong" href="/app/admin/desk-orgs-view?id=<?= (int) $user['org_id'] ?>"><?= htmlspecialchars((string) $user['org_name']) ?></a>
            <?= adminStatusBadge((string) $user['org_status']) ?>
          </div></li>
          <?php elseif ($user['role'] === 'broker'): ?>
          <li class="adm-list-row"><div><span class="adm-muted">Independent broker (no desk organisation).</span></div></li>
          <?php endif; ?>
          <?php if ($user['desk_id']): ?>
          <li class="adm-list-row"><div>
            <span class="adm-sub">SalesDesk</span><br>
            <a class="adm-strong" href="/<?= htmlspecialchars($user['desk_slug']) ?>/" target="_blank" rel="noopener"><?= htmlspecialchars($user['desk_name']) ?> ↗</a>
            <?= $user['desk_active'] ? adminStatusBadge('active') : adminStatusBadge('inactive') ?>
          </div></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'edit'): /* superadmin only — tab isn't offered otherwise */ ?>

  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Profile</h2>
      <form method="POST" action="<?= $self ?>">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="tab" value="edit">
        <div class="adm-grid-2">
          <div class="fgroup">
            <label class="flabel" for="first_name">First name</label>
            <input class="finput" id="first_name" name="first_name" maxlength="60" value="<?= htmlspecialchars((string) $user['first_name']) ?>" <?= $isDealer ? '' : 'required' ?>>
          </div>
          <div class="fgroup">
            <label class="flabel" for="last_name">Last name</label>
            <input class="finput" id="last_name" name="last_name" maxlength="60" value="<?= htmlspecialchars((string) $user['last_name']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="phone">Phone</label>
            <input class="finput" id="phone" name="phone" type="tel" maxlength="30" value="<?= htmlspecialchars((string) $user['phone']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="province">Province</label>
            <select class="finput" id="province" name="province">
              <option value="">—</option>
              <?php foreach (SD_PROVINCES as $p): ?>
              <option value="<?= $p ?>" <?= $user['province'] === $p ? 'selected' : '' ?>><?= $p ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel" for="city">City</label>
            <input class="finput" id="city" name="city" maxlength="80" value="<?= htmlspecialchars((string) $user['city']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="suburb">Suburb</label>
            <input class="finput" id="suburb" name="suburb" maxlength="80" value="<?= htmlspecialchars((string) $user['suburb']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="street_line1">Street address</label>
            <input class="finput" id="street_line1" name="street_line1" maxlength="120" value="<?= htmlspecialchars((string) $user['street_line1']) ?>">
          </div>
          <div class="fgroup">
            <label class="flabel" for="postal_code">Postal code</label>
            <input class="finput" id="postal_code" name="postal_code" maxlength="10" value="<?= htmlspecialchars((string) $user['postal_code']) ?>">
          </div>
        </div>
        <div class="fgroup">
          <label class="flabel" for="bio">Bio</label>
          <textarea class="finput" id="bio" name="bio" rows="3" maxlength="2000"><?= htmlspecialchars((string) $user['bio']) ?></textarea>
        </div>
        <?php if ($isDealer): ?>
        <p class="adm-sub adm-gap">Dealership name, logo and brands are edited on the <a href="/app/admin/dealerships-view?id=<?= (int) $user['dealer_id'] ?>&amp;tab=details">dealership page</a>.</p>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" type="submit">Save profile</button>
      </form>
    </div>
  </div>

  <?php if ($user['role'] === 'broker'): ?>
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Car limit</h2>
      <p class="adm-muted adm-gap">How many cars this broker can keep on their desk. Platform default: <?= $defaultLimit ?>.</p>
      <form method="POST" action="<?= $self ?>" class="adm-inline">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="set_car_limit">
        <input type="hidden" name="tab" value="edit">
        <input class="finput ua-limit-input" type="number" name="car_limit" min="1" max="200"
               value="<?= (int) ($user['car_limit'] ?? $defaultLimit) ?>" aria-label="Car limit">
        <button class="btn btn-primary btn-sm" type="submit">Set limit</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Sign-in email</h2>
      <p class="adm-muted adm-gap">
        Their current address (<?= htmlspecialchars($user['email']) ?>) is told about the change. You’ll be asked
        to confirm it’s you with an emailed code first.
      </p>
      <form method="POST" action="<?= $self ?>" class="adm-inline-form"
            data-confirm="Change the sign-in email for <?= htmlspecialchars($name, ENT_QUOTES) ?>?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="change_email">
        <input type="hidden" name="tab" value="edit">
        <label class="flabel" for="new_email">New email</label>
        <div class="adm-inline">
          <input class="finput" id="new_email" name="new_email" type="email" maxlength="255" required autocomplete="off">
          <button class="btn btn-primary btn-sm" type="submit">Change email</button>
        </div>
        <div class="adm-checks">
          <label><input type="checkbox" name="keep_verified" value="1"> I’ve confirmed the new address belongs to them — don’t ask them to verify it</label>
        </div>
      </form>
    </div>
  </div>

<?php elseif ($tab === 'account'): ?>

  <!-- Status -->
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Status</h2>
      <?php if ($isPending): ?>
        <?php if ($isSuperadmin): ?>
        <p class="adm-muted adm-gap">Pending accounts can’t sign in. Activate to let them in<?= $verified ? '.' : ' — tick the box to skip email verification too.' ?></p>
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="activate">
          <input type="hidden" name="tab" value="account">
          <?php if (!$verified): ?>
          <div class="adm-checks"><label><input type="checkbox" name="also_verify" value="1" checked> Also mark their email verified</label></div>
          <?php endif; ?>
          <button class="btn btn-success btn-sm" type="submit">Activate account</button>
        </form>
        <?php else: ?>
        <p class="adm-muted">Pending — waiting for the user to verify their email. A superadmin can activate it.</p>
        <?php endif; ?>
      <?php elseif ($isActive): ?>
        <p class="adm-muted adm-gap">
          <?= $isDealer
              ? 'Suspending a dealer principal pauses all their live listings and freezes pending commissions. Broker attribution is never affected.'
              : 'Suspended users can’t sign in. Their current session ends within the hour.' ?>
        </p>
        <form method="POST" action="<?= $self ?>" data-confirm="Suspend <?= htmlspecialchars($name, ENT_QUOTES) ?>?">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="suspend_user">
          <input type="hidden" name="tab" value="account">
          <button class="btn btn-danger btn-sm" type="submit"><?= $isDealer ? 'Suspend dealer' : 'Suspend user' ?></button>
        </form>
      <?php else: ?>
        <p class="adm-muted adm-gap">
          <?= $isDealer ? 'Reinstating restores paused listings and unfreezes commissions.' : 'Reactivating lets them sign in again.' ?>
        </p>
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="reactivate_user">
          <input type="hidden" name="tab" value="account">
          <button class="btn btn-success btn-sm" type="submit"><?= $isDealer ? 'Reinstate dealer' : 'Reactivate user' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isSuperadmin): ?>
  <!-- Email verification -->
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Email verification</h2>
      <?php if ($verified): ?>
      <p class="adm-muted adm-gap">Verified. Un-verify if the address looks wrong — they’ll be asked for a code at their next sign-in.</p>
      <form method="POST" action="<?= $self ?>" data-confirm="Mark <?= htmlspecialchars($user['email'], ENT_QUOTES) ?> as unverified?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="unverify_email">
        <input type="hidden" name="tab" value="account">
        <button class="btn btn-ghost btn-sm" type="submit">Mark unverified</button>
      </form>
      <?php else: ?>
      <p class="adm-muted adm-gap">Not verified. Mark it verified if you’ve confirmed the address yourself<?= $isPending ? ' (this also activates the account)' : '' ?>, or resend their code.</p>
      <div class="adm-actions ua-actions-left">
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="verify_email">
          <input type="hidden" name="tab" value="account">
          <button class="btn btn-success btn-sm" type="submit">Mark verified</button>
        </form>
        <form method="POST" action="<?= $self ?>">
          <?= csrf_hidden_field() ?>
          <input type="hidden" name="action" value="resend_verification">
          <input type="hidden" name="tab" value="account">
          <button class="btn btn-ghost btn-sm" type="submit">Resend verification code</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Password -->
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Password</h2>
      <p class="adm-muted adm-gap">
        <?= $hasPassword ? 'You never see or set their password.' : 'They haven’t set a password yet.' ?>
        Send a reset code and they choose a new one themselves.
      </p>
      <?php if ($isActive): ?>
      <form method="POST" action="<?= $self ?>" data-confirm="Email a password reset code to <?= htmlspecialchars($user['email'], ENT_QUOTES) ?>?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="send_reset">
        <input type="hidden" name="tab" value="account">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-key"></i> Send password reset code</button>
      </form>
      <?php else: ?>
      <p class="adm-sub">Available once the account is active.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lockout -->
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Sign-in lockout</h2>
      <p class="adm-muted adm-gap">
        <?= $failedLogins ?> failed sign-in<?= $failedLogins === 1 ? '' : 's' ?> in the last <?= (int) (RATE_LIMIT_WINDOW / 60) ?> minutes
        (locked at <?= RATE_LIMIT_ATTEMPTS ?>).
      </p>
      <form method="POST" action="<?= $self ?>">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="clear_lockout">
        <input type="hidden" name="tab" value="account">
        <button class="btn btn-ghost btn-sm" type="submit" <?= $failedLogins ? '' : 'disabled' ?>>Clear failed sign-ins</button>
      </form>
    </div>
  </div>

  <!-- Onboarding -->
  <?php if (!$onboarded): ?>
  <div class="card adm-card">
    <div class="card-body">
      <h2 class="adm-h2">Signup wizard</h2>
      <p class="adm-muted adm-gap">
        Stuck at step <?= (int) $user['onboarding_step'] ?>. Marking it complete
        <?= $user['role'] === 'broker' ? 'switches their SalesDesk on' : ($isDealer ? 'switches their dealership on' : 'lets them into their dashboard') ?>
        without sending a welcome email.
      </p>
      <form method="POST" action="<?= $self ?>" data-confirm="Mark signup complete for <?= htmlspecialchars($name, ENT_QUOTES) ?>?">
        <?= csrf_hidden_field() ?>
        <input type="hidden" name="action" value="complete_onboarding">
        <input type="hidden" name="tab" value="account">
        <button class="btn btn-ghost btn-sm" type="submit">Mark signup complete</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; /* superadmin */ ?>

<?php else: /* activity */ ?>

  <?php if (!$activity): ?>
  <div class="empty"><span class="empty-icon">○</span>No recorded activity yet.</div>
  <?php else: ?>
  <div class="roster-wrap">
    <table class="roster">
      <thead><tr><th>When</th><th>Action</th><th>By</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($activity as $a):
        $details = $a['after_data'] ? json_decode((string) $a['after_data'], true) : null;
        unset($details['_workspace']);
        $byThem  = (int) $a['actor_id'] === (int) $user['id'];
      ?>
      <tr>
        <td class="ua-date"><?= $fmt($a['created_at']) ?></td>
        <td class="adm-mono"><?= htmlspecialchars($a['action']) ?></td>
        <td class="adm-muted"><?= $byThem ? 'This user' : htmlspecialchars($a['actor_email'] ?? 'System') ?></td>
        <td class="adm-sub ua-details"><?= $details ? htmlspecialchars(json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : '—' ?></td>
        <td class="adm-mono"><?= htmlspecialchars((string) ($a['ip_address'] ?? '—')) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($isSuperadmin): ?><p class="adm-sub ua-pager">Showing the latest 40. Full history: <a href="/app/admin/audit">Audit log</a>.</p><?php endif; ?>
  <?php endif; ?>

<?php endif; ?>
<?php
$pageContent = ob_get_clean();
$pageTitle   = $name . ' | Users';
$pageStyles  = ['/assets/css/admin.css'];
$pageScripts = ['/assets/js/admin.js'];
require_once '../../views/layout-app.php';
