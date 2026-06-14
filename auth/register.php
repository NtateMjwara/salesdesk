<?php
/**
 * SalesDesk — Multi-step signup wizard.
 * T2 owns this file.
 *
 * FIXES APPLIED:
 *   FIX-08: The logged-in guard at the top used the hardcoded path
 *           '/app/dashboard.php', which does not exist. Every other redirect
 *           in the system (login.php, authentication.php handleLogin) uses a
 *           role-aware match expression. This guard now does the same.
 *
 *           Old behaviour:
 *             if (!empty($_SESSION['user_id'])) redirect('/app/dashboard.php');
 *             → 404 for every role.
 *
 *           New behaviour:
 *             role-aware match identical to login.php and handleLogin().
 *
 *           This guard fires in two legitimate scenarios:
 *             (a) A logged-in user manually navigates to /auth/register.php.
 *             (b) finaliseOnboarding() set $_SESSION['user_id'] then redirected
 *                 here (the pre-FIX-07 code path — now eliminated, but the
 *                 guard is still correct defensive code).
 *
 *   FIX-09: Infinite redirect loop for sales_exec who skipped dealership
 *           selection during onboarding.
 *
 *           Root cause:
 *             When an exec skips dealership selection, no row is inserted into
 *             sales_executives. After onboarding completes they land at
 *             /app/exec/dashboard.php. requireExecVerified() finds no
 *             sales_executives record and renders the "No dealership linked"
 *             status page, which points back to /auth/register.php. The
 *             FIX-08 guard sees a logged-in sales_exec and immediately
 *             redirects to /app/exec/dashboard.php → infinite loop.
 *
 *           Fix:
 *             Before the blanket redirect, query sales_executives for the
 *             logged-in user. If no row exists the exec needs to complete
 *             step 3 (dealership selection). Initialise a minimal wizard
 *             session with relink=true and their existing profile data,
 *             then fall through to render the wizard at step 3.
 *
 *             The relink flag is read by handleWizardDealership,
 *             handleWizardSkipDealership, and finaliseOnboarding in
 *             authentication.php so that after step 3 completes the exec
 *             goes straight to the pending-approval screen instead of
 *             re-running profile / password / address steps they already
 *             finished.
 *
 *             All other logged-in roles (broker, dealer, admin) are
 *             unaffected — they hit the role-aware redirect as before.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/csrf.php';

applyCachePolicy('auth');

// ── Logged-in guard ───────────────────────────────────────────
// FIX-08 / FIX-09: role-aware handling replaces the old hardcoded
// redirect('/app/dashboard.php').
if (!empty($_SESSION['user_id'])) {
    $loggedInRole = $_SESSION['user_role'] ?? '';

    // FIX-09: sales_exec with no sales_executives row must complete
    // step 3 (dealership selection) before they can use the app.
    // All other roles redirect straight to their dashboard.
    if ($loggedInRole === 'sales_exec') {
        require_once '../includes/database.php';

        $pdo        = Database::getInstance();
        $checkStmt  = $pdo->prepare("
            SELECT se.id, se.verification_status,
                   p.first_name, p.last_name, p.phone, p.bio,
                   u.email
            FROM users u
            LEFT JOIN profiles p ON p.user_id = u.id
            LEFT JOIN sales_executives se ON se.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $checkStmt->execute([(int) $_SESSION['user_id']]);
        $execRow = $checkStmt->fetch();

        $hasExecRecord = $execRow && !empty($execRow['id']);

        if ($hasExecRecord) {
            // Already has a sales_executives row — nothing to fix here.
            // Send them to the dashboard and let exec_guard handle the
            // pending / rejected / suspended states.
            redirect('/app/exec/dashboard.php');
        }

        // No sales_executives row — bootstrap a relink wizard session
        // so they can pick (or skip) a dealership without redoing
        // profile, password, or address.
        $_SESSION['wz'] = [
            'step'              => 3,
            'role'              => 'sales_exec',
            'email'             => $execRow['email']      ?? '',
            'user_id'           => (int) $_SESSION['user_id'],
            'first_name'        => $execRow['first_name'] ?? '',
            'last_name'         => $execRow['last_name']  ?? '',
            'phone'             => $execRow['phone']      ?? '',
            'bio'               => $execRow['bio']        ?? '',
            'province'          => '',
            'municipality'      => '',
            'city'              => '',
            'suburb'            => '',
            'desk_display_name' => '',
            'desk_slug'         => '',
            'company_name'      => '',
            'brand_focus'       => '',
            'cipc_doc_url'      => null,
            'dealer_id'         => null,
            'dealer_name'       => '',
            'job_title'         => '',
            // relink=true tells authentication.php to skip straight to the
            // pending-approval screen after dealership step, bypassing the
            // profile / password / address steps already completed.
            'relink'            => true,
        ];

        // Fall through — let the wizard render step 3 below.

    } else {
        // FIX-08: all other logged-in roles go to their dashboard.
        redirect(match($loggedInRole) {
            'dealer' => '/app/dealer/dashboard.php',
            'admin'  => '/app/admin/users.php',
            default  => '/app/broker/dashboard.php',
        });
    }
}

// Initialise wizard state for fresh (not logged-in) visitors.
if (empty($_SESSION['wz'])) {
    $_SESSION['wz'] = [
        'step'              => 1,
        'role'              => 'broker',
        'email'             => '',
        'user_id'           => null,
        'first_name'        => '',
        'last_name'         => '',
        'phone'             => '',
        'bio'               => '',
        'province'          => '',
        'municipality'      => '',
        'city'              => '',
        'suburb'            => '',
        'desk_display_name' => '',
        'desk_slug'         => '',
        'company_name'      => '',
        'brand_focus'       => '',
        'cipc_doc_url'      => null,
        'dealer_id'         => null,
        'dealer_name'       => '',
        'job_title'         => '',
    ];
}

$wz          = &$_SESSION['wz'];
$step        = (int) ($wz['step'] ?? 1);
$role        = $wz['role'] ?? 'broker';
$isSalesExec = ($role === 'sales_exec');
$isDealer    = ($role === 'dealer');
$isRelink    = !empty($wz['relink']); // FIX-09: relink mode flag for template hints
$csrf        = generateCSRFToken();

$error = $_GET['error'] ?? '';
$info  = $_GET['info']  ?? '';

// Progress labels by role.
if ($isSalesExec) {
    $stepLabels = ['account', 'verify', 'dealership', 'profile', 'security', 'location'];
} elseif ($isDealer) {
    $stepLabels = ['account', 'verify', 'company', 'security', 'location'];
} else {
    $stepLabels = ['account', 'verify', 'desk', 'profile', 'security', 'location'];
}

// layout-auth.php variables.
$pageTitle         = 'Create your SalesDesk';
$cardMaxWidth      = '520px';
$needsLocations    = (($step === 5 && $isDealer) || ($step === 6 && !$isDealer));
$needsDealerSearch = ($step === 3 && $isSalesExec);

ob_start();
?>

<!-- ── Progress bar (hidden on step 99 and during relink) ── -->
<?php if ($step < 99 && !$isRelink): ?>
<div class="progress-wrap">
  <div class="progress-pips">
    <?php foreach ($stepLabels as $i => $label):
      $n   = $i + 1;
      $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
      if ($i > 0): ?>
        <div class="connector <?= $n <= $step ? 'done' : '' ?>"></div>
      <?php endif; ?>
      <div class="pip <?= $cls ?>">
        <?php if ($n < $step): ?>
          <i class="fa-solid fa-check" style="font-size:10px"></i>
        <?php else: ?><?= $n ?><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="progress-labels">
    <?php foreach ($stepLabels as $i => $label): ?>
    <div class="pip-label <?= ($i + 1) === $step ? 'active' : '' ?>"><?= $label ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Banners ── -->
<?php if ($error): ?>
<div class="alert alert-error">
  <i class="fa-solid fa-circle-exclamation alert-icon"></i>
  <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>
<?php if ($info): ?>
<div class="alert alert-info">
  <i class="fa-solid fa-circle-info alert-icon"></i>
  <?= htmlspecialchars($info) ?>
</div>
<?php endif; ?>


<?php if ($step === 1): ?>
<!-- ══════════════════════════
     STEP 1 — Role + Email
     ══════════════════════════ -->
<div class="auth-heading">
  <h1>Create your <em>account</em></h1>
  <p>Choose how you'll use SalesDesk, then enter your email to get started.</p>
</div>

<form method="POST" action="/auth/authentication.php?action=wizard_email" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

  <div class="role-grid">
    <label class="role-label">
      <input type="radio" name="role" value="broker"
             <?= ($role !== 'dealer' && $role !== 'sales_exec') ? 'checked' : '' ?>>
      <div class="role-card">
        <span class="role-icon"><i class="fa-solid fa-id-card"></i></span>
        <span class="role-title">Auto Broker</span>
        <span class="role-sub">Share cars, earn commission</span>
      </div>
    </label>
    <label class="role-label">
      <input type="radio" name="role" value="dealer" <?= $isDealer ? 'checked' : '' ?>>
      <div class="role-card">
        <span class="role-icon"><i class="fa-solid fa-building"></i></span>
        <span class="role-title">Dealer</span>
        <span class="role-sub">List cars, receive leads</span>
      </div>
    </label>
    <label class="role-label">
      <input type="radio" name="role" value="sales_exec" <?= $isSalesExec ? 'checked' : '' ?>>
      <div class="role-card">
        <span class="role-icon"><i class="fa-solid fa-user-tie"></i></span>
        <span class="role-title">Sales Exec</span>
        <span class="role-sub">Upload cars for your dealership</span>
      </div>
    </label>
  </div>

  <div class="fgroup">
    <label class="flabel" for="email">Email address</label>
    <input class="finput" type="email" id="email" name="email"
           required maxlength="255" autocomplete="email"
           placeholder="you@example.com"
           value="<?= htmlspecialchars($wz['email']) ?>">
  </div>

  <button class="btn-auth" type="submit">
    Continue — send verification code <i class="fa-solid fa-arrow-right"></i>
  </button>
</form>

<p class="foot-link">Already have an account? <a href="/auth/login.php">Sign in</a></p>


<?php elseif ($step === 2): ?>
<!-- ══════════════════════════
     STEP 2 — OTP verification
     ══════════════════════════ -->
<div class="auth-heading">
  <h1>Check your <em>email</em></h1>
  <p>We sent a 6-digit code to:</p>
</div>

<div class="email-display">
  <span><?= htmlspecialchars($wz['email']) ?></span>
  <a class="email-change" href="/auth/authentication.php?action=wizard_reset">change</a>
</div>

<form method="POST" action="/auth/authentication.php?action=wizard_otp" id="otpForm" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="otp" id="otpHidden">
  <div class="otp-row">
    <?php for ($i = 0; $i < 6; $i++): ?>
    <input class="otp-d" type="text" inputmode="numeric" pattern="[0-9]"
           maxlength="1"
           autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>">
    <?php endfor; ?>
  </div>
  <button class="btn-auth" type="submit">
    <i class="fa-solid fa-shield-halved"></i> Verify email
  </button>
</form>

<form method="POST" action="/auth/authentication.php?action=wizard_resend">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <button class="btn-auth-ghost" type="submit">Resend code</button>
</form>


<?php elseif ($step === 3 && $isSalesExec): ?>
<!-- ══════════════════════════════════════
     STEP 3 (sales_exec) — Dealership selection
     Also renders for relink mode (FIX-09): logged-in exec who skipped
     dealership during onboarding and needs to link one now.
     ══════════════════════════════════════ -->
<div class="auth-heading">
  <?php if ($isRelink): ?>
  <h1>Link your <em>dealership</em></h1>
  <p>Your account is set up but isn't linked to a dealership yet. Find the dealership you work at to get started.</p>
  <?php else: ?>
  <h1>Find your <em>dealership</em></h1>
  <p>Search for the dealership you work at. Your dealer principal will verify you before your account goes live.</p>
  <?php endif; ?>
</div>

<div class="pending-notice">
  <div class="pending-notice-icon"><i class="fa-solid fa-clock"></i></div>
  <div>
    <div class="pending-notice-title">Approval required after this step</div>
    <div class="pending-notice-body">
      Your account will be <strong>pending approval</strong> until the dealer principal
      verifies you. You'll be notified by email once approved.
    </div>
  </div>
</div>

<form method="POST" action="/auth/authentication.php?action=wizard_dealership" id="dealershipForm" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="dealer_id"   id="selectedDealerId"   value="<?= (int)($wz['dealer_id'] ?? 0) ?: '' ?>">
  <input type="hidden" name="dealer_name" id="selectedDealerName" value="<?= htmlspecialchars($wz['dealer_name'] ?? '') ?>">

  <div class="fgroup">
    <label class="flabel">Dealership name</label>

    <div id="dealerChip" class="dealer-selected"
         style="display:<?= !empty($wz['dealer_id']) ? 'flex' : 'none' ?>">
      <i class="fa-solid fa-building-user" style="color:var(--green)"></i>
      <span class="dealer-selected-name" id="chipName"><?= htmlspecialchars($wz['dealer_name'] ?? '') ?></span>
      <button type="button" class="dealer-selected-clear" onclick="clearDealer()">Change</button>
    </div>

    <div id="dealerSearchWrap" style="display:<?= !empty($wz['dealer_id']) ? 'none' : 'block' ?>">
      <div class="dealer-search-wrap">
        <input class="finput" type="text" id="dealerSearch"
               placeholder="e.g. Joburg Motors, AutoNation Sandton…"
               autocomplete="off" spellcheck="false">
        <div class="dealer-results" id="dealerResults"></div>
      </div>
    </div>
  </div>

  <div class="fgroup">
    <label class="flabel" for="job_title">
      Job title <span class="flabel-opt">(optional)</span>
    </label>
    <input class="finput" type="text" id="job_title" name="job_title"
           maxlength="100" placeholder="e.g. Senior Sales Executive"
           value="<?= htmlspecialchars($wz['job_title'] ?? '') ?>">
  </div>

  <button class="btn-auth" type="submit" id="dealerSubmitBtn"
          <?= empty($wz['dealer_id']) ? 'disabled' : '' ?>>
    <?= $isRelink ? 'Link dealership' : 'Continue' ?> <i class="fa-solid fa-arrow-right"></i>
  </button>
</form>

<form method="POST" action="/auth/authentication.php?action=wizard_skip_dealership">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <button type="submit" class="btn-auth-ghost" style="margin-top:8px;">
    <?= $isRelink ? 'My dealership isn\'t registered yet — remind me later' : 'My dealership isn\'t registered yet — skip for now' ?>
  </button>
</form>


<?php elseif ($step === 3 && !$isSalesExec && !$isDealer): ?>
<!-- ══════════════════════════════════════
     STEP 3 (broker) — SalesDesk name
     ══════════════════════════════════════ -->
<div class="auth-heading">
  <h1>Name your <em>SalesDesk</em></h1>
  <p>This is the name buyers and dealers see when they visit your public car listings page.</p>
</div>

<span class="role-badge"><i class="fa-solid fa-id-card" style="font-size:10px"></i> Auto Broker</span>

<form method="POST" action="/auth/authentication.php?action=wizard_desk" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div class="fgroup">
    <label class="flabel" for="desk_display_name">Desk name</label>
    <input class="finput" type="text" id="desk_display_name" name="desk_display_name"
           required maxlength="120" autocomplete="off"
           placeholder="e.g. Sipho's SalesDesk, Cape Auto Deals…"
           value="<?= htmlspecialchars($wz['desk_display_name'] ?? '') ?>">
    <div class="slug-preview" id="slug-preview"
         style="display:<?= !empty($wz['desk_display_name']) ? 'block' : 'none' ?>">
      salesdesk.co.za/<span id="slug-val"><?= htmlspecialchars($wz['desk_slug'] ?? '') ?></span>
    </div>
  </div>
  <div class="info-box">
    <i class="fa-solid fa-circle-info" style="color:var(--p);margin-right:5px"></i>
    Your slug is automatically generated from the name you choose. You can rename it once after setup from your account settings.
  </div>
  <button class="btn-auth" type="submit" id="desk-submit-btn" disabled>Continue <i class="fa-solid fa-arrow-right"></i></button>
</form>


<?php elseif ($step === 3 && $isDealer): ?>
<!-- ══════════════════════════════════════
     STEP 3 (dealer) — Company profile
     ══════════════════════════════════════ -->
<div class="auth-heading">
  <h1>Your <em>dealership</em></h1>
  <p>Tell us about your business. Brokers will see this when choosing which cars to share.</p>
</div>

<span class="role-badge"><i class="fa-solid fa-building" style="font-size:10px"></i> Dealer Principal</span>

<form method="POST" action="/auth/authentication.php?action=wizard_company"
      enctype="multipart/form-data" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

  <div class="fgroup">
    <label class="flabel" for="company_name">Dealership name</label>
    <input class="finput" type="text" id="company_name" name="company_name"
           required maxlength="120" autocomplete="organization"
           placeholder="e.g. Cars &amp; More Boksburg"
           value="<?= htmlspecialchars($wz['company_name'] ?? '') ?>">
  </div>

  <div class="fgroup">
    <label class="flabel" for="brand_focus">
      Brands you sell <span class="flabel-opt">(optional, comma-separated)</span>
    </label>
    <input class="finput" type="text" id="brand_focus" name="brand_focus"
           maxlength="255" autocomplete="off" placeholder="e.g. Toyota, Ford, VW"
           value="<?= htmlspecialchars($wz['brand_focus'] ?? '') ?>">
  </div>

  <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0">
  <p style="font-size:12px;color:var(--muted);margin-bottom:.9rem;">
    <strong>Account holder</strong> — your name for the SalesDesk account.
  </p>

  <div class="frow">
    <div class="fgroup">
      <label class="flabel" for="first_name">First name</label>
      <input class="finput" type="text" id="first_name" name="first_name"
             required maxlength="60" autocomplete="given-name" placeholder="Thabo"
             value="<?= htmlspecialchars($wz['first_name'] ?? '') ?>">
    </div>
    <div class="fgroup">
      <label class="flabel" for="last_name">Last name</label>
      <input class="finput" type="text" id="last_name" name="last_name"
             required maxlength="60" autocomplete="family-name" placeholder="Nkosi"
             value="<?= htmlspecialchars($wz['last_name'] ?? '') ?>">
    </div>
  </div>

  <div class="fgroup">
    <label class="flabel" for="phone">
      Mobile number <span class="flabel-opt">(optional)</span>
    </label>
    <input class="finput" type="tel" id="phone" name="phone"
           maxlength="20" autocomplete="tel" placeholder="011 999 8888"
           value="<?= htmlspecialchars($wz['phone'] ?? '') ?>">
  </div>

  <div class="fgroup">
    <label class="flabel" for="cipc_doc">
      CIPC certificate <span class="flabel-opt">(optional — PDF, max 5 MB)</span>
    </label>
    <?php if (!empty($wz['cipc_doc_url'])): ?>
    <div class="dealer-selected">
      <i class="fa-solid fa-file-pdf" style="color:var(--green)"></i>
      <span class="dealer-selected-name">CIPC document uploaded</span>
    </div>
    <?php else: ?>
    <input class="finput" type="file" id="cipc_doc" name="cipc_doc"
           accept="application/pdf" style="padding:8px 13px;cursor:pointer">
    <p style="font-size:11px;color:var(--faint);margin-top:5px;line-height:1.5;">
      Verified dealers appear first in broker search. You can upload this later from your dashboard.
    </p>
    <?php endif; ?>
  </div>

  <button class="btn-auth" type="submit">Continue <i class="fa-solid fa-arrow-right"></i></button>
</form>


<?php elseif ($step === 4 && !$isDealer): ?>
<!-- ══════════════════════════════════════
     STEP 4 (broker / sales_exec) — Profile
     ══════════════════════════════════════ -->
<div class="auth-heading">
  <h1>Your <em>profile</em></h1>
  <p>Tell us about yourself — this is shown to <?= $isSalesExec ? 'your dealer principal and buyers' : 'dealers and buyers' ?>.</p>
</div>

<span class="role-badge">
  <i class="fa-solid fa-circle-check" style="font-size:10px"></i>
  <?= $isSalesExec ? 'Sales Executive' : 'Auto Broker' ?>
</span>

<?php if ($isSalesExec && !empty($wz['dealer_name'])): ?>
<div class="alert alert-info" style="margin-bottom:1rem;">
  <i class="fa-solid fa-building-user alert-icon"></i>
  Joining <strong><?= htmlspecialchars($wz['dealer_name']) ?></strong> — pending approval
</div>
<?php endif; ?>

<form method="POST" action="/auth/authentication.php?action=wizard_profile" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div class="frow">
    <div class="fgroup">
      <label class="flabel" for="first_name">First name</label>
      <input class="finput" type="text" id="first_name" name="first_name"
             required maxlength="60" autocomplete="given-name" placeholder="Sipho"
             value="<?= htmlspecialchars($wz['first_name']) ?>">
    </div>
    <div class="fgroup">
      <label class="flabel" for="last_name">Last name</label>
      <input class="finput" type="text" id="last_name" name="last_name"
             required maxlength="60" autocomplete="family-name" placeholder="Dlamini"
             value="<?= htmlspecialchars($wz['last_name']) ?>">
    </div>
  </div>
  <div class="fgroup">
    <label class="flabel" for="phone">
      Mobile number <span class="flabel-opt">(optional)</span>
    </label>
    <input class="finput" type="tel" id="phone" name="phone"
           maxlength="20" autocomplete="tel" placeholder="082 000 0000"
           value="<?= htmlspecialchars($wz['phone']) ?>">
  </div>
  <div class="fgroup">
    <label class="flabel" for="bio">
      Short bio <span class="flabel-opt">(optional)</span>
    </label>
    <textarea class="finput" id="bio" name="bio" maxlength="400"
              placeholder="e.g. Passionate about connecting buyers with their perfect car..."><?= htmlspecialchars($wz['bio']) ?></textarea>
  </div>
  <button class="btn-auth" type="submit">Continue <i class="fa-solid fa-arrow-right"></i></button>
</form>


<?php elseif (($step === 4 && $isDealer) || ($step === 5 && !$isDealer)): ?>
<!-- ══════════════════════════════════════
     STEP 4 (dealer) / STEP 5 (broker/exec) — Password
     ══════════════════════════════════════ -->
<div class="auth-heading">
  <h1>Secure your <em>account</em></h1>
  <p>Create a strong password. You'll use this every time you log in.</p>
</div>

<form method="POST" action="/auth/authentication.php?action=wizard_password" id="pwForm" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div class="fgroup">
    <label class="flabel" for="password">Password</label>
    <div class="pw-wrap">
      <input class="finput" type="password" id="password" name="password"
             required minlength="8" autocomplete="new-password">
      <button type="button" class="pw-toggle" onclick="togglePw('password',this)"
              aria-label="Show password">
        <i class="fa-regular fa-eye"></i>
      </button>
    </div>
    <ul class="pw-reqs">
      <li class="pw-req" id="r-len"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>At least 8 characters</li>
      <li class="pw-req" id="r-up"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Uppercase letter</li>
      <li class="pw-req" id="r-low"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Lowercase letter</li>
      <li class="pw-req" id="r-num"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Number</li>
      <li class="pw-req" id="r-spc"><span class="r-icon"><i class="fa-regular fa-circle"></i></span>Special character</li>
    </ul>
  </div>
  <div class="fgroup">
    <label class="flabel" for="confirm_password">Confirm password</label>
    <div class="pw-wrap">
      <input class="finput" type="password" id="confirm_password"
             name="confirm_password" required autocomplete="new-password">
      <button type="button" class="pw-toggle" onclick="togglePw('confirm_password',this)"
              aria-label="Show confirm password">
        <i class="fa-regular fa-eye"></i>
      </button>
    </div>
    <p class="match-hint" id="matchHint">
      <i class="fa-regular fa-circle" style="font-size:10px"></i> Passwords must match
    </p>
  </div>
  <button class="btn-auth" type="submit" id="pwBtn">
    Continue <i class="fa-solid fa-arrow-right"></i>
  </button>
</form>


<?php elseif (($step === 5 && $isDealer) || ($step === 6 && !$isDealer)): ?>
<!-- ══════════════════════════════════════
     STEP 5 (dealer) / STEP 6 (broker/exec) — Address
     ══════════════════════════════════════ -->
<div class="auth-heading">
  <h1><?= $isDealer ? 'Dealership <em>location</em>' : 'Your <em>location</em>' ?></h1>
  <p><?= $isDealer
    ? 'Helps brokers and buyers find your dealership. You can skip this and add it later.'
    : 'Helps match you with nearby dealers and buyers. You can skip this and add it later.' ?></p>
</div>

<div class="addr-hint">
  <i class="fa-solid fa-location-dot" style="margin-top:2px;flex-shrink:0"></i>
  <?= $isDealer
    ? 'We show your dealership city/area to brokers searching for local dealer partnerships.'
    : 'We only show your general area (city / suburb) — never your exact address.' ?>
</div>

<form method="POST" action="/auth/authentication.php?action=wizard_address" id="addrForm" novalidate>
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div class="fgroup">
    <label class="flabel" for="province">Province</label>
    <select class="finput" id="province" name="province" onchange="onProvince()">
      <option value="">— Select province —</option>
      <?php foreach (["Eastern Cape","Free State","Gauteng","KwaZulu-Natal","Limpopo","Mpumalanga","North West","Northern Cape","Western Cape"] as $p): ?>
      <option value="<?= htmlspecialchars($p) ?>" <?= $wz['province'] === $p ? 'selected' : '' ?>>
        <?= htmlspecialchars($p) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fgroup" id="muni-group" style="display:<?= $wz['province'] ? 'block' : 'none' ?>">
    <label class="flabel" for="municipality">Municipality</label>
    <select class="finput" id="municipality" name="municipality" onchange="onMuni()">
      <option value="">— Select municipality —</option>
    </select>
  </div>
  <div class="fgroup" id="city-group" style="display:<?= $wz['municipality'] ? 'block' : 'none' ?>">
    <label class="flabel" for="city">City / Town</label>
    <select class="finput" id="city" name="city" onchange="onCity()">
      <option value="">— Select city —</option>
    </select>
  </div>
  <div class="fgroup" id="suburb-group" style="display:<?= $wz['city'] ? 'block' : 'none' ?>">
    <label class="flabel" for="suburb">Suburb <span class="flabel-opt">(optional)</span></label>
    <select class="finput" id="suburb" name="suburb">
      <option value="">— Select suburb —</option>
    </select>
  </div>
  <button class="btn-auth" type="submit">
    <?= $isSalesExec ? 'Submit for approval' : 'Complete setup' ?>
    <i class="fa-solid fa-check"></i>
  </button>
</form>

<p class="skip-link">
  <a href="/auth/authentication.php?action=wizard_skip_address">Skip for now — I'll add this later</a>
</p>


<?php elseif ($step === 99): ?>
<!-- ══════════════════════════
     STEP 99 — Success / Pending
     NOTE: Only sales_exec reaches this screen now.
     Brokers and dealers are redirected directly to their dashboards
     in finaliseOnboarding() (FIX-07).
     ══════════════════════════ -->
<div class="success-wrap">

  <?php if ($isSalesExec && !empty($wz['dealer_id'])): ?>
    <div class="success-icon pending"><i class="fa-solid fa-clock"></i></div>
    <h1>Almost there, <em><?= htmlspecialchars($wz['first_name']) ?></em></h1>
    <p>Your account is set up. Your principal at <strong><?= htmlspecialchars($wz['dealer_name']) ?></strong>
    has been notified and will verify your membership. You'll receive an email once approved.</p>
    <div class="success-meta">
      <div class="success-meta-row"><span>Role</span><span>Sales Executive</span></div>
      <div class="success-meta-row"><span>Email</span><span><?= htmlspecialchars($wz['email']) ?></span></div>
      <div class="success-meta-row"><span>Name</span><span><?= htmlspecialchars(trim($wz['first_name'] . ' ' . $wz['last_name'])) ?></span></div>
      <div class="success-meta-row"><span>Dealership</span><span><?= htmlspecialchars($wz['dealer_name']) ?></span></div>
      <div class="success-meta-row"><span>Status</span><span style="color:var(--amber);">Pending approval</span></div>
    </div>
    <a href="/auth/login.php" class="btn-auth">Go to login <i class="fa-solid fa-arrow-right"></i></a>

  <?php else: ?>
    <!-- Fallback: sales_exec who skipped dealership selection -->
    <div class="success-icon"><i class="fa-solid fa-circle-check"></i></div>
    <h1>Account created, <em><?= htmlspecialchars($wz['first_name'] ?? 'there') ?></em></h1>
    <p>You can link a dealership from your dashboard when you're ready.</p>
    <a href="/auth/login.php" class="btn-auth">Go to login <i class="fa-solid fa-arrow-right"></i></a>
  <?php endif; ?>

</div>
<?php endif; ?>

<?php
$cardContent = ob_get_clean();

// ── Step-specific JS ──────────────────────────────────────────
ob_start();

if ($step === 2): ?>
<script>initOTPWidget('otpForm', 'otpHidden');</script>

<?php elseif ($step === 3 && $isSalesExec): ?>
<script>
(function () {
  var hiddenId   = document.getElementById('selectedDealerId');
  var hiddenName = document.getElementById('selectedDealerName');
  var chip       = document.getElementById('dealerChip');
  var chipName   = document.getElementById('chipName');
  var searchWrap = document.getElementById('dealerSearchWrap');
  var searchEl   = document.getElementById('dealerSearch');
  var resultsEl  = document.getElementById('dealerResults');
  var submitBtn  = document.getElementById('dealerSubmitBtn');
  var searchTimeout;

  function updateSubmit() { submitBtn.disabled = !hiddenId.value; }

  if (searchEl) {
    searchEl.addEventListener('input', function () {
      clearTimeout(searchTimeout);
      var q = searchEl.value.trim();
      if (q.length < 2) { resultsEl.innerHTML = ''; resultsEl.classList.remove('open'); return; }
      searchTimeout = setTimeout(function () { doSearch(q); }, 280);
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.dealer-search-wrap')) resultsEl.classList.remove('open');
  });

  async function doSearch(q) {
    resultsEl.innerHTML = '<div class="dealer-result-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Searching…</div>';
    resultsEl.classList.add('open');
    try {
      var res  = await fetch('/api/dealers/search.php?q=' + encodeURIComponent(q));
      var data = await res.json();
      if (!data.length) {
        resultsEl.innerHTML = '<div class="dealer-result-empty">No dealerships found.</div>';
        return;
      }
      resultsEl.innerHTML = data.map(function (d) {
        return '<div class="dealer-result-item" onclick="selectDealer(' + d.id + ',\'' + esc(d.company_name) + '\',\'' + esc(d.city || '') + '\',\'' + esc(d.verification_status || '') + '\')">'
          + '<div class="dealer-result-name">' + esc(d.company_name) + '</div>'
          + '<div class="dealer-result-meta">' + esc(d.city || '') + (d.verification_status === 'verified' ? ' &nbsp;·&nbsp; <span style="color:var(--green)">✓ Verified</span>' : '') + '</div>'
          + '</div>';
      }).join('');
    } catch (err) {
      resultsEl.innerHTML = '<div class="dealer-result-empty">Search unavailable — please try again.</div>';
    }
  }

  window.selectDealer = function (id, name) {
    hiddenId.value = id; hiddenName.value = name;
    chipName.textContent = name;
    chip.style.display       = 'flex';
    searchWrap.style.display = 'none';
    resultsEl.classList.remove('open');
    updateSubmit();
  };

  window.clearDealer = function () {
    hiddenId.value = ''; hiddenName.value = '';
    chip.style.display       = 'none';
    searchWrap.style.display = 'block';
    searchEl && searchEl.focus();
    updateSubmit();
  };

  function esc(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  updateSubmit();
})();
</script>

<?php elseif ($step === 3 && !$isSalesExec && !$isDealer): ?>
<script>
(function () {
  var input     = document.getElementById('desk_display_name');
  var preview   = document.getElementById('slug-preview');
  var slugVal   = document.getElementById('slug-val');
  var submitBtn = document.getElementById('desk-submit-btn');
  var hintEl    = null;

  function getHint() {
    if (!hintEl) {
      hintEl = document.createElement('p');
      hintEl.style.cssText = 'font-size:12px;margin-top:6px;display:flex;align-items:center;gap:5px;min-height:18px;';
      preview.parentNode.insertBefore(hintEl, preview.nextSibling);
    }
    return hintEl;
  }

  function toSlug(v) {
    return v.toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .substring(0, 60);
  }

  function showPreview(slug) {
    if (!slug) { preview.style.display = 'none'; return; }
    slugVal.textContent = slug;
    preview.style.display = 'block';
  }

  function setHint(html, color) {
    var h = getHint();
    h.innerHTML   = html || '';
    h.style.color = color || 'var(--faint)';
    h.style.display = html ? '' : 'none';
  }

  function setSubmit(enabled) { submitBtn.disabled = !enabled; }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  var debounceTimer = null;
  var lastChecked   = '';
  var lastResult    = null;

  function onInput() {
    var slug = toSlug(input.value);
    showPreview(slug);
    if (!slug) { setHint(''); setSubmit(false); lastChecked = ''; lastResult = null; return; }
    if (slug === lastChecked && lastResult !== null) { applyResult(slug, lastResult); return; }
    setHint('<i class="fa-solid fa-circle-notch fa-spin" style="font-size:10px"></i> Checking availability…', 'var(--faint)');
    setSubmit(false);
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () { checkSlug(slug); }, 320);
  }

  async function checkSlug(slug) {
    if (!slug) return;
    try {
      var res  = await fetch('/api/salesdesks/check-slug.php?slug=' + encodeURIComponent(slug));
      var data = await res.json();
      lastChecked = slug; lastResult = data.available === true;
      if (toSlug(input.value) === slug) applyResult(slug, lastResult);
    } catch (e) { setHint(''); setSubmit(true); }
  }

  function applyResult(slug, available) {
    if (available) {
      setHint('<i class="fa-solid fa-circle-check" style="font-size:10px;color:var(--green)"></i> <span style="color:var(--green)">' + esc('salesdesk.co.za/' + slug) + ' is available</span>', 'var(--green)');
      setSubmit(true);
    } else {
      setHint('<i class="fa-solid fa-circle-xmark" style="font-size:10px;color:var(--red)"></i> <span style="color:var(--red)">That slug is taken — try a different name</span>', 'var(--red)');
      setSubmit(false);
    }
  }

  input.addEventListener('input', onInput);
  if (input.value) { var slug = toSlug(input.value); showPreview(slug); if (slug) checkSlug(slug); }
  else { input.focus(); }
})();
</script>

<?php elseif (($step === 4 && $isDealer) || ($step === 5 && !$isDealer)): ?>
<script>
(function () {
  var pw      = document.getElementById('password');
  var confirm = document.getElementById('confirm_password');
  var hint    = document.getElementById('matchHint');
  var checks  = {
    'r-len': function (v) { return v.length >= 8; },
    'r-up':  function (v) { return /[A-Z]/.test(v); },
    'r-low': function (v) { return /[a-z]/.test(v); },
    'r-num': function (v) { return /[0-9]/.test(v); },
    'r-spc': function (v) { return /[^A-Za-z0-9]/.test(v); },
  };
  function evalPw() {
    var v = pw.value, ok = true;
    Object.keys(checks).forEach(function (id) {
      var el = document.getElementById(id), met = checks[id](v);
      el.classList.toggle('met', met);
      el.querySelector('.r-icon').innerHTML = met ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-regular fa-circle"></i>';
      if (!met) ok = false;
    });
    evalMatch(); return ok;
  }
  function evalMatch() {
    var ok = pw.value && pw.value === confirm.value;
    hint.classList.toggle('ok', ok);
    hint.innerHTML = ok ? '<i class="fa-solid fa-circle-check" style="font-size:10px"></i> Passwords match' : '<i class="fa-regular fa-circle" style="font-size:10px"></i> Passwords must match';
  }
  pw.addEventListener('input', evalPw);
  confirm.addEventListener('input', evalMatch);
  document.getElementById('pwForm').addEventListener('submit', function (e) {
    if (!evalPw()) { e.preventDefault(); pw.focus(); return; }
    if (pw.value !== confirm.value) { e.preventDefault(); confirm.focus(); }
  });
  pw.focus();
})();

function togglePw(id, btn) {
  var input = document.getElementById(id);
  var showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.innerHTML = showing ? '<i class="fa-regular fa-eye"></i>' : '<i class="fa-regular fa-eye-slash"></i>';
}
</script>

<?php elseif (($step === 5 && $isDealer) || ($step === 6 && !$isDealer)): ?>
<script>
var LOC = window.SA_LOCATIONS || {};
function populateSelect(el, items, selected) {
  var label = el.previousElementSibling ? el.previousElementSibling.textContent.trim().split(' ')[0] : 'Select';
  el.innerHTML = '<option value="">— ' + label + ' —</option>';
  items.forEach(function (v) {
    var o = document.createElement('option');
    o.value = v; o.textContent = v;
    if (v === selected) o.selected = true;
    el.appendChild(o);
  });
}
function onProvince() {
  var prov = document.getElementById('province').value;
  var munis = (LOC.municipalities || {})[prov] || [];
  var mg = document.getElementById('muni-group');
  var cg = document.getElementById('city-group');
  var sg = document.getElementById('suburb-group');
  cg.style.display = 'none'; sg.style.display = 'none';
  if (!munis.length) { mg.style.display = 'none'; return; }
  mg.style.display = 'block';
  populateSelect(document.getElementById('municipality'), munis, '');
}
function onMuni() {
  var muni   = document.getElementById('municipality').value;
  var cities = (LOC.cities || {})[muni] || [];
  var cg = document.getElementById('city-group');
  var sg = document.getElementById('suburb-group');
  sg.style.display = 'none';
  if (!cities.length) { cg.style.display = 'none'; return; }
  cg.style.display = 'block';
  populateSelect(document.getElementById('city'), cities, '');
}
function onCity() {
  var city    = document.getElementById('city').value;
  var suburbs = (LOC.suburbs || {})[city] || [];
  var sg = document.getElementById('suburb-group');
  if (!suburbs.length) { sg.style.display = 'none'; return; }
  sg.style.display = 'block';
  populateSelect(document.getElementById('suburb'), suburbs, '');
}
(function restoreSelects() {
  var sp = <?= json_encode($wz['province']) ?>;
  var sm = <?= json_encode($wz['municipality']) ?>;
  var sc = <?= json_encode($wz['city']) ?>;
  var ss = <?= json_encode($wz['suburb']) ?>;
  if (sp) {
    var munis = (LOC.municipalities || {})[sp] || [];
    if (munis.length) { populateSelect(document.getElementById('municipality'), munis, sm); document.getElementById('muni-group').style.display = 'block'; }
  }
  if (sm) {
    var cities = (LOC.cities || {})[sm] || [];
    if (cities.length) { populateSelect(document.getElementById('city'), cities, sc); document.getElementById('city-group').style.display = 'block'; }
  }
  if (sc) {
    var subs = (LOC.suburbs || {})[sc] || [];
    if (subs.length) { populateSelect(document.getElementById('suburb'), subs, ss); document.getElementById('suburb-group').style.display = 'block'; }
  }
})();
</script>
<?php endif; ?>

<?php
$extraJs     = ob_get_clean();
$cardContent .= $extraJs;

require_once '../views/layout-auth.php';
