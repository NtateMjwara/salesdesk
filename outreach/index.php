<?php
/**
 * SalesDesk — Outreach Programme (public page)
 * Route: /outreach/  (via .htaccess → outreach/index.php, same
 *        pattern as /c/ → c/index.php)
 *
 * Replaces the old "Advertise" footer link — see views/layout-public.php.
 *
 * Dynamic pieces wired up (see includes/outreach.php + api/outreach/*):
 *   - Live "already on the waiting list" count.
 *   - Admin-togglable open/closed state (platform_config.outreach_registrations_open).
 *   - Registration form → POST /api/outreach/register.php (CSRF-protected).
 *   - Dealer/employer "Get Involved" cards → POST /api/outreach/partner-enquiry.php.
 *
 * Still static (see TODO-5 in the original prototype / project notes):
 *   pilot timeline stages, principles, approach copy. Promote these to
 *   platform_config or a dedicated table if they need to change without
 *   a deploy.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');

$pdo = Database::getInstance();

// ── CSRF token (same manual pattern as c/car-detail/index.php,
//    since layout-public.php does not render a csrf meta tag) ──
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[CSRF_TOKEN_NAME];

// ── Live, DB-backed state ───────────────────────────────────────
require_once '../includes/outreach.php';

$registrationsOpen = getPlatformConfig('outreach_registrations_open', '1') === '1';
$waitingListCount   = getOutreachRegistrationCount();

// ── Page meta ────────────────────────────────────────────────────
$pageTitle      = 'Our Outreach — Commerce Can Change Lives | SalesDesk';
$ogTitle        = 'Our Outreach — SalesDesk';
$ogDescription  = 'SalesDesk\'s Outreach Programme: building a sustainable employment ecosystem for South African youth, starting with a Driver Development Pilot.';
$canonicalUrl   = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/outreach/';
$shareUrl       = $canonicalUrl;
$shareTitle     = $ogTitle;
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [['Our Outreach', null]];

ob_start();
?>

<!-- ══════════════════════════════════════
     HERO
     ══════════════════════════════════════ -->
<header class="oc-hero">
  <div class="oc-hero__inner">
    <div class="oc-reveal is-in">
      <span class="eyebrow oc-hero__eyebrow">Our Outreach</span>
      <h1 class="oc-h1">Commerce can <em>change lives.</em></h1>
      <p>We believe every vehicle transaction has the potential to create opportunity. SalesDesk exists to connect buyers and sellers &mdash; but our purpose extends beyond the marketplace. Our vision is to build a sustainable employment ecosystem where commercial success creates opportunities for South African youth.</p>
      <div class="oc-hero__actions">
        <a href="#belief" class="oc-btn oc-btn--gold">Learn about the programme <i class="fa-solid fa-arrow-right"></i></a>
        <a href="#register" class="oc-btn oc-btn--ghost-light">Register your interest</a>
      </div>
      <?php if ($waitingListCount > 0): ?>
      <p style="margin-top:22px;color:rgba(255,255,255,.55);font-size:12.5px;">
        <i class="fa-solid fa-users" style="margin-right:6px;color:#f0c98a;"></i>
        <strong style="color:#fff;"><?= number_format($waitingListCount) ?></strong> people are already on the waiting list.
      </p>
      <?php endif; ?>
    </div>
    <div class="oc-hero__art">
      <svg viewBox="0 0 480 480" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Illustration of a road leading toward sunrise, with a delivery van">
        <defs>
          <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#12306e"/><stop offset="55%" stop-color="#3d5aa0"/><stop offset="100%" stop-color="#f3d5a3"/>
          </linearGradient>
          <linearGradient id="sun" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#ffe6b0"/><stop offset="100%" stop-color="#f0b25e"/>
          </linearGradient>
        </defs>
        <rect x="0" y="0" width="480" height="480" rx="28" fill="url(#sky)"/>
        <circle cx="240" cy="300" r="70" fill="url(#sun)" opacity="0.9"/>
        <path d="M0 420 L480 420 L480 480 L0 480 Z" fill="#0a1740"/>
        <path d="M40 420 L200 300 L280 300 L440 420 Z" fill="#132049"/>
        <path d="M170 420 L232 340 L248 340 L310 420 Z" fill="#1c2c5c"/>
        <line x1="238" y1="420" x2="234" y2="386" stroke="#f3d5a3" stroke-width="6" stroke-dasharray="14 12" stroke-linecap="round"/>
        <g transform="translate(150,352)">
          <rect x="0" y="18" width="120" height="46" rx="6" fill="#eef2fb"/>
          <rect x="0" y="0" width="70" height="34" rx="6" fill="#f7f9fd"/>
          <rect x="8" y="8" width="26" height="18" rx="3" fill="#9fc2ee"/>
          <circle cx="26" cy="66" r="12" fill="#08143c"/><circle cx="26" cy="66" r="5" fill="#cbd5e1"/>
          <circle cx="96" cy="66" r="12" fill="#08143c"/><circle cx="96" cy="66" r="5" fill="#cbd5e1"/>
          <rect x="0" y="30" width="120" height="6" fill="#b8792f"/>
        </g>
        <circle cx="90" cy="90" r="4" fill="#fff" opacity=".8"/>
        <circle cx="360" cy="60" r="3" fill="#fff" opacity=".6"/>
        <circle cx="410" cy="120" r="2.5" fill="#fff" opacity=".7"/>
      </svg>
    </div>
  </div>
  <div class="pub-page-wide" style="padding:0;">
    <div class="oc-hero__scroller-wrap">
      <div class="oc-hero__scroller"><i class="fa-solid fa-chevron-down"></i> Scroll to read our story</div>
    </div>
  </div>
</header>

<!-- ══════════════════════════════════════
     OUR BELIEF
     ══════════════════════════════════════ -->
<section class="oc-section" id="belief">
  <div class="oc-container">
    <div class="oc-belief__grid">
      <div class="oc-reveal">
        <span class="eyebrow">Our Belief</span>
        <h2 class="oc-h2">We believe business should create more than profit.</h2>
      </div>
      <div class="oc-reveal">
        <p>South Africa faces one of the highest youth unemployment rates in the world.</p>
        <p>Every day, capable young people are prevented from accessing employment because of barriers such as training costs, driver's licences, workplace experience and limited professional networks.</p>
        <p>We believe business has a role to play in changing this.</p>
        <p>Rather than relying solely on donations or grants, we are building a model where ordinary commercial activity contributes towards creating real employment opportunities.</p>
        <div class="oc-quote">Every vehicle bought or sold through SalesDesk helps strengthen that vision.</div>
      </div>
    </div>
  </div>
</section>

<div class="oc-divider-road"></div>

<!-- ══════════════════════════════════════
     OUR APPROACH
     ══════════════════════════════════════ -->
<section class="oc-section" id="approach">
  <div class="oc-container">
    <div class="oc-reveal" style="text-align:center;max-width:560px;margin:0 auto;">
      <span class="eyebrow" style="justify-content:center">Our Approach</span>
      <h2 class="oc-h2">A simple chain, from marketplace to livelihood.</h2>
    </div>
    <div class="oc-approach__row">
      <div class="oc-approach__card oc-reveal">
        <div class="oc-approach__icon"><i class="fa-solid fa-coins"></i></div>
        <h3>Sustainable Commerce</h3>
        <p>SalesDesk generates commercial revenue through its marketplace.</p>
      </div>
      <div class="oc-approach__card oc-reveal">
        <div class="oc-approach__icon"><i class="fa-solid fa-graduation-cap"></i></div>
        <h3>Skills Development</h3>
        <p>That revenue helps fund practical employment programmes &mdash; beginning with driver development.</p>
      </div>
      <div class="oc-approach__card oc-reveal">
        <div class="oc-approach__icon"><i class="fa-solid fa-handshake"></i></div>
        <h3>Employment</h3>
        <p>Participants are connected with employers through our Employment Bridge Model, creating pathways to meaningful work.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     WHY DRIVER DEVELOPMENT
     ══════════════════════════════════════ -->
<section class="oc-section oc-section--tint">
  <div class="oc-container">
    <div class="oc-why__grid">
      <div class="oc-why__badge oc-reveal">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Steering wheel icon">
          <circle cx="50" cy="50" r="34" fill="none" stroke="#f3d5a3" stroke-width="5"/>
          <circle cx="50" cy="50" r="8" fill="#f3d5a3"/>
          <line x1="50" y1="16" x2="50" y2="36" stroke="#f3d5a3" stroke-width="5" stroke-linecap="round"/>
          <line x1="24" y1="66" x2="40" y2="56" stroke="#f3d5a3" stroke-width="5" stroke-linecap="round"/>
          <line x1="76" y1="66" x2="60" y2="56" stroke="#f3d5a3" stroke-width="5" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="oc-reveal">
        <span class="eyebrow">Why Driver Development</span>
        <h2 class="oc-h2" style="margin-bottom:18px;">A practical barrier, and a practical place to start.</h2>
        <p>Many industries rely on licensed drivers.</p>
        <p>For thousands of young South Africans, obtaining a driver's licence or Professional Driving Permit (PrDP) is financially out of reach, despite being capable and ready to work.</p>
        <p>Driver development offers an opportunity to remove a practical barrier that prevents many young people from entering the workforce.</p>
        <p>We believe this is an ideal starting point for demonstrating a model that can later expand into additional industries and career pathways.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     PILOT — signature road timeline
     ══════════════════════════════════════ -->
<section class="oc-section" id="pilot">
  <div class="oc-container oc-container--narrow">
    <div class="oc-pilot__head oc-reveal">
      <span class="eyebrow" style="justify-content:center">How the Pilot Will Work</span>
      <h2 class="oc-h2">The road from mobilisation to long-term employment.</h2>
    </div>

    <div class="oc-route">
      <svg class="oc-route__svg" id="ocRouteSvg" preserveAspectRatio="none">
        <line class="oc-route__path" id="ocRoutePath" x1="1" y1="0" x2="1" y2="100%"></line>
      </svg>

      <div class="oc-stop"><div class="oc-stop__marker">01</div><div class="oc-stop__body"><h3>Community Mobilisation</h3><p>Reaching young people where they are, through community and partner networks.</p></div></div>
      <div class="oc-stop"><div class="oc-stop__marker">02</div><div class="oc-stop__body"><h3>Participant Registration</h3><p>Interested candidates register and share their details and readiness to work.</p></div></div>
      <div class="oc-stop"><div class="oc-stop__marker">03</div><div class="oc-stop__body"><h3>Selection Process</h3><p>A fair, transparent process identifies participants for the pilot cohort.</p></div></div>
      <div class="oc-stop"><div class="oc-stop__marker">04</div><div class="oc-stop__body"><h3>Driver Training</h3><p>Practical, supervised training builds the skills required to drive professionally.</p></div></div>
      <div class="oc-stop"><div class="oc-stop__marker">05</div><div class="oc-stop__body"><h3>Licence &amp; PrDP</h3><p>Participants work toward a driver's licence and Professional Driving Permit.</p></div></div>
      <div class="oc-stop"><div class="oc-stop__marker">06</div><div class="oc-stop__body"><h3>Employment Bridge</h3><p>Qualifying participants are introduced to employers through our bridge model.</p></div></div>
      <div class="oc-stop"><div class="oc-stop__marker"><i class="fa-solid fa-flag-checkered"></i></div><div class="oc-stop__body"><h3>Long-Term Employment</h3><p>The goal throughout: real, lasting work &mdash; not just a certificate.</p></div></div>
    </div>

    <div class="oc-pilot__note oc-reveal">
      <i class="fa-solid fa-circle-info"></i>
      <span>This pilot programme is currently in the partnership and planning phase. Participant selection will begin once implementation partners and funding milestones have been confirmed.</span>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     EMPLOYMENT BRIDGE
     ══════════════════════════════════════ -->
<section class="oc-section oc-section--tint">
  <div class="oc-container">
    <div class="oc-bridge">
      <div class="oc-reveal">
        <span class="eyebrow">The Employment Bridge</span>
        <h2 class="oc-h2" style="margin-bottom:18px;">Training is only half the journey.</h2>
        <p>Not every training programme leads to employment.</p>
        <p>We believe practical workplace experience is equally important. Our Employment Bridge Model is designed to support qualifying participants as they transition from training into meaningful employment through partnerships with industry.</p>
        <p>The objective is simple:</p>
        <p class="oc-bridge__goal">Reduce the risk for employers, while increasing opportunities for young South Africans.</p>
      </div>
      <div class="oc-bridge__visual oc-reveal">
        <svg viewBox="0 0 320 220" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Diagram of the employment bridge connecting training to employer">
          <rect x="10" y="150" width="90" height="50" rx="8" fill="#eff4ff" stroke="#bfdbfe"/>
          <text x="55" y="180" text-anchor="middle" font-family="Sora, sans-serif" font-size="11" font-weight="700" fill="#0f4c9e">Training</text>
          <rect x="220" y="150" width="90" height="50" rx="8" fill="#f7ecd9" stroke="#ecd8b3"/>
          <text x="265" y="180" text-anchor="middle" font-family="Sora, sans-serif" font-size="11" font-weight="700" fill="#8f5f22">Employer</text>
          <path d="M100 175 C150 175 130 90 160 90 C190 90 170 175 220 175" fill="none" stroke="#b8792f" stroke-width="3" stroke-dasharray="7 8" stroke-linecap="round"/>
          <circle cx="160" cy="90" r="9" fill="#b8792f"/>
          <text x="160" y="70" text-anchor="middle" font-family="Sora, sans-serif" font-size="10.5" font-weight="700" fill="#08143c">the bridge</text>
        </svg>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     WHY SALESDESK
     ══════════════════════════════════════ -->
<section class="oc-section" id="why-salesdesk">
  <div class="oc-container oc-container--narrow">
    <div class="oc-reveal" style="text-align:center;margin-bottom:8px;">
      <span class="eyebrow" style="justify-content:center">Why SalesDesk</span>
      <h2 class="oc-h2">Every transaction has purpose.</h2>
    </div>
    <p class="oc-lede oc-reveal" style="margin:0 auto 36px;text-align:center;max-width:600px;">Traditional fundraising relies on asking people to donate. Our model is different &mdash; instead of asking people to give more, we ask them to make choices that already align with what they're doing.</p>

    <div class="oc-reveal">
      <div class="oc-scenario">
        <div class="oc-scenario__icon"><i class="fa-solid fa-car"></i></div>
        <div class="oc-scenario__text">If you're <strong>buying</strong> a vehicle&nbsp;&hellip; use SalesDesk.</div>
        <div class="oc-scenario__arrow"><i class="fa-solid fa-arrow-right"></i></div>
      </div>
      <div class="oc-scenario">
        <div class="oc-scenario__icon"><i class="fa-solid fa-tag"></i></div>
        <div class="oc-scenario__text">If you're <strong>selling</strong> a vehicle&nbsp;&hellip; use SalesDesk.</div>
        <div class="oc-scenario__arrow"><i class="fa-solid fa-arrow-right"></i></div>
      </div>
      <div class="oc-scenario">
        <div class="oc-scenario__icon"><i class="fa-solid fa-share-nodes"></i></div>
        <div class="oc-scenario__text">If you're <strong>referring</strong> someone&nbsp;&hellip; refer them to SalesDesk.</div>
        <div class="oc-scenario__arrow"><i class="fa-solid fa-arrow-right"></i></div>
      </div>
    </div>

    <p class="oc-purpose-line oc-reveal">Commercial activity becomes social impact.</p>
    <p style="text-align:center;color:var(--muted);font-size:14.5px;" class="oc-reveal">The more the platform grows, the greater our ability to create employment opportunities.</p>
  </div>
</section>

<!-- ══════════════════════════════════════
     VISION
     ══════════════════════════════════════ -->
<section class="oc-section oc-section--navy">
  <div class="oc-container">
    <div class="oc-reveal" style="text-align:center;margin-bottom:12px;">
      <span class="eyebrow" style="justify-content:center;color:#f0c98a">Our Vision</span>
    </div>
    <p class="oc-vision__stmt oc-reveal">Our long-term vision is to build one of South Africa's largest commercially sustainable employment ecosystems &mdash; <em>where every successful transaction contributes to creating opportunities for the next generation.</em></p>
    <div class="oc-vision__row">
      <div class="oc-vision__card oc-reveal"><span class="tag">Today</span><p>Our focus is on launching a Driver Development Pilot Programme.</p></div>
      <div class="oc-vision__card oc-reveal"><span class="tag">Tomorrow</span><p>We hope to expand into additional career pathways, employer partnerships and industries.</p></div>
      <div class="oc-vision__card oc-reveal"><span class="tag">The Long Road</span><p>This journey will take time, collaboration and continuous learning &mdash; but every successful movement begins with a single step.</p></div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     PRINCIPLES
     ══════════════════════════════════════ -->
<section class="oc-section" id="principles">
  <div class="oc-container">
    <div class="oc-reveal" style="text-align:center;max-width:600px;margin:0 auto;">
      <span class="eyebrow" style="justify-content:center">Our Principles</span>
      <h2 class="oc-h2">The values that guide this, from the very beginning.</h2>
    </div>
    <div class="oc-principles__grid">
      <div class="oc-principle oc-reveal"><span class="oc-principle__num">I</span><h3>Opportunity over dependency</h3><p>We aim to remove barriers to employment, not create long-term reliance.</p></div>
      <div class="oc-principle oc-reveal"><span class="oc-principle__num">II</span><h3>Commerce with purpose</h3><p>Business success can and should create measurable social impact.</p></div>
      <div class="oc-principle oc-reveal"><span class="oc-principle__num">III</span><h3>Partnership over isolation</h3><p>Lasting solutions are built through collaboration between business, communities, and civil society.</p></div>
      <div class="oc-principle oc-reveal"><span class="oc-principle__num">IV</span><h3>Evidence before expansion</h3><p>We will prove the model through a pilot, learn from it, and scale responsibly.</p></div>
      <div class="oc-principle oc-reveal"><span class="oc-principle__num">V</span><h3>Dignity through work</h3><p>Employment is more than an income &mdash; it provides purpose, independence and hope.</p></div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     GET INVOLVED
     ══════════════════════════════════════ -->
<section class="oc-section oc-section--tint" id="get-involved">
  <div class="oc-container">
    <div class="oc-reveal" style="text-align:center;max-width:560px;margin:0 auto;">
      <span class="eyebrow" style="justify-content:center">Get Involved</span>
      <h2 class="oc-h2">There's a place for you in this, whoever you are.</h2>
    </div>
    <div class="oc-involved__grid">
      <div class="oc-involved__card oc-reveal">
        <div class="oc-involved__icon"><i class="fa-solid fa-user"></i></div>
        <h3>I'm looking for work</h3>
        <p>Register your interest to be considered for future programme opportunities.</p>
        <a href="#register" class="oc-btn oc-btn--outline oc-btn--block">Register</a>
      </div>
      <div class="oc-involved__card oc-reveal">
        <div class="oc-involved__icon"><i class="fa-solid fa-car-side"></i></div>
        <h3>I'm buying or selling a vehicle</h3>
        <p>Support the programme by using SalesDesk for your next vehicle transaction.</p>
        <a href="/c/" class="oc-btn oc-btn--outline oc-btn--block">Browse vehicles</a>
      </div>
      <div class="oc-involved__card oc-reveal">
        <div class="oc-involved__icon"><i class="fa-solid fa-building-user"></i></div>
        <h3>I'm a dealership</h3>
        <p>Partner with us to help create employment opportunities.</p>
        <button type="button" class="oc-btn oc-btn--outline oc-btn--block oc-open-partner" data-type="dealer">Become a dealer partner</button>
      </div>
      <div class="oc-involved__card oc-reveal">
        <div class="oc-involved__icon"><i class="fa-solid fa-briefcase"></i></div>
        <h3>I'm an employer</h3>
        <p>Help us create pathways to employment by offering internships or employment opportunities.</p>
        <button type="button" class="oc-btn oc-btn--outline oc-btn--block oc-open-partner" data-type="employer">Become a partner</button>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     REGISTER
     ══════════════════════════════════════ -->
<section class="oc-section oc-register" id="register">
  <div class="oc-container">
    <div class="oc-register__wrap oc-reveal">

      <?php if (!$registrationsOpen): ?>
      <div class="oc-closed-banner">
        <i class="fa-solid fa-circle-pause"></i>
        Registrations are temporarily paused while we confirm implementation partners. You're welcome to fill in your details below &mdash; we'll review submissions once recruitment reopens.
      </div>
      <?php endif; ?>

      <div id="ocFormPanel">
        <div class="oc-register__head">
          <span class="eyebrow" style="justify-content:center">Register Your Interest</span>
          <h2 class="oc-h2">Be among the first.</h2>
          <p style="color:var(--muted);margin-top:12px;font-size:14.5px;">We are currently establishing partnerships and preparing for the launch of our Driver Development Pilot Programme. If you would like to be considered once participant recruitment begins, register your interest below.</p>
        </div>

        <div id="ocGlobalError" class="oc-global-error" style="display:none;"></div>

        <form id="ocRegisterForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

          <div class="oc-f-grid">
            <div class="full">
              <label class="oc-flabel" for="ocName">Full name</label>
              <input class="oc-finput" type="text" id="ocName" name="full_name" required autocomplete="name">
              <div class="oc-ferr" data-for="full_name"></div>
            </div>

            <div>
              <label class="oc-flabel" for="ocId">ID number</label>
              <input class="oc-finput" type="text" id="ocId" name="id_number" inputmode="numeric" maxlength="13" required>
              <div class="oc-ferr" data-for="id_number"></div>
            </div>
            <div>
              <label class="oc-flabel" for="ocDob">Date of birth</label>
              <input class="oc-finput" type="date" id="ocDob" name="date_of_birth" required>
              <div class="oc-ferr" data-for="date_of_birth"></div>
            </div>

            <div>
              <label class="oc-flabel" for="ocPhone">Mobile number</label>
              <input class="oc-finput" type="tel" id="ocPhone" name="mobile" placeholder="082 000 0000" required autocomplete="tel">
              <div class="oc-ferr" data-for="mobile"></div>
            </div>
            <div>
              <label class="oc-flabel" for="ocEmail">Email <span style="font-weight:400;text-transform:none;color:var(--faint);">(optional)</span></label>
              <input class="oc-finput" type="email" id="ocEmail" name="email" autocomplete="email">
              <div class="oc-ferr" data-for="email"></div>
            </div>

            <div>
              <label class="oc-flabel" for="ocProvince">Province</label>
              <select class="oc-fselect" id="ocProvince" name="province" required>
                <option value="">Select province</option>
                <?php foreach (OUTREACH_PROVINCES as $prov): ?>
                <option value="<?= htmlspecialchars($prov) ?>"><?= htmlspecialchars($prov) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="oc-ferr" data-for="province"></div>
            </div>
            <div>
              <label class="oc-flabel" for="ocMunicipality">Municipality</label>
              <input class="oc-finput" type="text" id="ocMunicipality" name="municipality" required>
              <div class="oc-ferr" data-for="municipality"></div>
            </div>

            <div>
              <label class="oc-flabel" for="ocQualification">Highest qualification</label>
              <select class="oc-fselect" id="ocQualification" name="qualification" required>
                <option value="">Select</option>
                <?php foreach (OUTREACH_QUALIFICATIONS as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="oc-ferr" data-for="qualification"></div>
            </div>
            <div>
              <label class="oc-flabel" for="ocEmployment">Employment status</label>
              <select class="oc-fselect" id="ocEmployment" name="employment_status" required>
                <option value="">Select</option>
                <?php foreach (OUTREACH_EMPLOYMENT_STATUSES as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="oc-ferr" data-for="employment_status"></div>
            </div>

            <div>
              <label class="oc-flabel">Learner's licence?</label>
              <div class="oc-fradio-row">
                <label class="oc-fradio"><input type="radio" name="has_learners" value="yes" required> Yes</label>
                <label class="oc-fradio"><input type="radio" name="has_learners" value="no"> No</label>
              </div>
            </div>
            <div>
              <label class="oc-flabel">Driver's licence?</label>
              <div class="oc-fradio-row">
                <label class="oc-fradio"><input type="radio" name="has_drivers" value="yes" required> Yes</label>
                <label class="oc-fradio"><input type="radio" name="has_drivers" value="no"> No</label>
              </div>
            </div>

            <div class="full">
              <label class="oc-flabel" for="ocLicenceCode">Licence interest</label>
              <select class="oc-fselect" id="ocLicenceCode" name="licence_code" required>
                <option value="">Select code</option>
                <option value="8">Code 8 (light motor vehicle)</option>
                <option value="10">Code 10 (heavy motor vehicle)</option>
                <option value="14">Code 14 (extra heavy / articulated)</option>
              </select>
              <div class="oc-ferr" data-for="licence_code"></div>
            </div>

            <div class="full">
              <label class="oc-flabel" for="ocMotivation">Short motivation</label>
              <textarea class="oc-ftextarea" id="ocMotivation" name="motivation" placeholder="Tell us briefly why you'd like to take part…" required></textarea>
              <div class="oc-ferr" data-for="motivation"></div>
            </div>

            <div class="full">
              <label class="oc-fcheck">
                <input type="checkbox" name="consent_given" value="1" required>
                <span>I consent to SalesDesk collecting and processing the information above solely to consider me for this programme, in line with POPIA.</span>
              </label>
              <div class="oc-ferr" data-for="consent_given"></div>
            </div>
          </div>

          <div class="oc-register__submit">
            <button type="submit" class="oc-btn oc-btn--gold oc-btn--block" id="ocSubmitBtn">
              <span class="oc-btn-label">Join the waiting list</span>
            </button>
          </div>

          <div class="oc-register__disclaimer">
            <i class="fa-solid fa-shield-halved" style="color:var(--gold-dark);margin-top:1px;"></i>
            <span>Your ID number is encrypted before storage and is only ever used to confirm eligibility and avoid duplicate entries. See our <a href="/privacy" style="color:var(--gold-dark);text-decoration:underline;">Privacy Policy</a>.</span>
          </div>
        </form>
      </div>

      <div class="oc-register__success" id="ocSuccessPanel">
        <i class="fa-solid fa-circle-check"></i>
        <h3 id="ocSuccessTitle">You're on the list.</h3>
        <p id="ocSuccessMessage">Thank you for registering your interest. We'll be in touch once participant recruitment begins.</p>
      </div>

    </div>
  </div>
</section>

<!-- ══════════════════════════════════════
     PARTNER ENQUIRY — lightweight overlay
     shared by the dealer + employer cards
     ══════════════════════════════════════ -->
<div class="oc-overlay" id="ocPartnerOverlay" aria-hidden="true">
  <div class="oc-overlay__sheet">
    <button type="button" class="oc-overlay__close" id="ocPartnerClose" aria-label="Close">
      <i class="fa-solid fa-xmark"></i>
    </button>
    <h3 class="oc-h3" id="ocPartnerTitle" style="margin-bottom:6px;">Partner with us</h3>
    <p style="color:var(--muted);font-size:13.5px;margin-bottom:20px;">Tell us a little about you and we'll follow up.</p>

    <div id="ocPartnerGlobalError" class="oc-global-error" style="display:none;"></div>

    <form id="ocPartnerForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="enquiry_type" id="ocPartnerType" value="dealer">

      <label class="oc-flabel" for="ocPartnerName">Contact name</label>
      <input class="oc-finput" type="text" id="ocPartnerName" name="contact_name" required style="margin-bottom:14px;">

      <label class="oc-flabel" for="ocPartnerCompany">Company / organisation</label>
      <input class="oc-finput" type="text" id="ocPartnerCompany" name="company_name" style="margin-bottom:14px;">

      <label class="oc-flabel" for="ocPartnerEmail">Email</label>
      <input class="oc-finput" type="email" id="ocPartnerEmail" name="email" required style="margin-bottom:14px;">

      <label class="oc-flabel" for="ocPartnerPhone">Phone <span style="font-weight:400;text-transform:none;color:var(--faint);">(optional)</span></label>
      <input class="oc-finput" type="tel" id="ocPartnerPhone" name="phone" style="margin-bottom:14px;">

      <label class="oc-flabel" for="ocPartnerMessage">Message <span style="font-weight:400;text-transform:none;color:var(--faint);">(optional)</span></label>
      <textarea class="oc-ftextarea" id="ocPartnerMessage" name="message" style="margin-bottom:18px;"></textarea>

      <button type="submit" class="oc-btn oc-btn--gold oc-btn--block">Send enquiry</button>
    </form>

    <div class="oc-overlay__success" id="ocPartnerSuccess">
      <i class="fa-solid fa-circle-check"></i>
      <p>Thanks for reaching out &mdash; our outreach team will be in touch shortly.</p>
    </div>
  </div>
</div>

<style>
/* ══════════════════════════════════════
   OUTREACH PAGE — scoped styles
   Namespaced with .oc- to avoid colliding with public.css / browse.css.
   Reuses SalesDesk's existing tokens (--p, --text, --border, --font-d,
   --sans, --serif) from global.css and adds one accent (brass/gold)
   plus the "road" motif specific to this page.
   ══════════════════════════════════════ */
:root{
  --gold:#b8792f; --gold-dark:#8f5f22; --gold-soft:#f7ecd9; --gold-line:#d9ad72;
}
.oc-container{max-width:1180px;margin:0 auto;padding-inline:clamp(20px,5vw,48px)}
.oc-container--narrow{max-width:800px}

.eyebrow{
  display:inline-flex;align-items:center;gap:8px;font-family:var(--font-d);font-size:11.5px;
  font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--gold-dark);margin-bottom:14px;
}
.eyebrow::before{content:'';width:22px;height:1.5px;background:var(--gold);display:inline-block}

.oc-h1{font-family:var(--font-d);font-size:clamp(34px,5.4vw,60px);font-weight:800;line-height:1.04;letter-spacing:-.02em;color:#fff}
.oc-h2{font-family:var(--font-d);font-size:clamp(24px,3.2vw,36px);font-weight:700;line-height:1.18;color:var(--text)}
.oc-h3{font-family:var(--font-d);font-size:19px;font-weight:700;color:var(--text)}
.oc-lede{font-size:clamp(16px,1.6vw,19px);color:var(--text2);line-height:1.75}

.oc-quote{font-family:var(--serif);font-style:italic;font-weight:500;font-size:clamp(20px,2.2vw,26px);line-height:1.5;color:var(--p-dark);border-left:3px solid var(--gold);padding-left:22px;margin:26px 0}

.oc-btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;font-family:var(--font-d);font-weight:600;font-size:14px;padding:13px 26px;border-radius:999px;border:1.5px solid transparent;cursor:pointer;transition:transform .2s,box-shadow .2s,background .2s,color .2s,border-color .2s;white-space:nowrap}
.oc-btn--gold{background:var(--gold);color:#fff;box-shadow:0 10px 26px rgba(184,121,47,.28)}
.oc-btn--gold:hover{background:var(--gold-dark);transform:translateY(-2px)}
.oc-btn--ghost-light{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.35);color:#fff}
.oc-btn--ghost-light:hover{background:rgba(255,255,255,.16);transform:translateY(-2px)}
.oc-btn--outline{background:transparent;border-color:var(--border2);color:var(--text)}
.oc-btn--outline:hover{border-color:var(--p-dark);transform:translateY(-2px)}
.oc-btn--block{width:100%}
.oc-btn:disabled{opacity:.6;cursor:not-allowed;transform:none !important}

.oc-hero{position:relative;overflow:hidden;background:radial-gradient(120% 140% at 15% -10%,#0f2a63 0%,#08143c 46%,#050b24 100%);padding:clamp(48px,8vh,80px) 0 0}
.oc-hero__inner{max-width:1180px;margin:0 auto;padding-inline:clamp(20px,5vw,48px);display:grid;grid-template-columns:1.05fr .95fr;gap:40px;align-items:center;padding-bottom:clamp(32px,5vh,52px)}
.oc-hero__eyebrow{color:#f0c98a}.oc-hero__eyebrow::before{background:#f0c98a}
.oc-hero h1{margin-bottom:20px}
.oc-hero h1 em{font-family:var(--serif);font-style:italic;font-weight:500;color:#f3d5a3}
.oc-hero p{color:rgba(255,255,255,.72);font-size:clamp(15px,1.5vw,17px);line-height:1.75;max-width:520px;margin-bottom:28px}
.oc-hero__actions{display:flex;gap:14px;flex-wrap:wrap}
.oc-hero__art svg{width:100%;height:auto;filter:drop-shadow(0 24px 50px rgba(0,0,0,.35))}
.oc-hero__scroller-wrap{max-width:1180px;margin:0 auto;padding-inline:clamp(20px,5vw,48px)}
.oc-hero__scroller{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.45);font-size:11px;letter-spacing:.08em;text-transform:uppercase;font-weight:600;padding:16px 0 22px;border-top:1px solid rgba(255,255,255,.1)}
.oc-hero__scroller i{animation:ocBounce 1.8s ease-in-out infinite}
@keyframes ocBounce{0%,100%{transform:translateY(0)}50%{transform:translateY(4px)}}
@media (max-width:860px){.oc-hero__inner{grid-template-columns:1fr}.oc-hero__art{order:-1;max-width:320px}}

.oc-section{padding:clamp(48px,7vh,88px) 0}
.oc-section--tint{background:var(--bg2)}
.oc-section--navy{background:linear-gradient(155deg,#08143c 0%,#0d1f52 60%,#0a1740 100%);color:#fff}
.oc-divider-road{height:2px;max-width:120px;margin:0 auto;background-image:repeating-linear-gradient(90deg,var(--gold-line) 0 14px,transparent 14px 24px);opacity:.7}

.oc-reveal{opacity:0;transform:translateY(18px);transition:opacity .7s cubic-bezier(.2,.7,.2,1),transform .7s cubic-bezier(.2,.7,.2,1)}
.oc-reveal.is-in{opacity:1;transform:none}
@media (prefers-reduced-motion:reduce){.oc-reveal{opacity:1;transform:none;transition:none}}

.oc-belief__grid{display:grid;grid-template-columns:.9fr 1.1fr;gap:52px;align-items:start}
.oc-belief__grid p{color:var(--text2);margin-bottom:14px;font-size:15.5px}
@media (max-width:820px){.oc-belief__grid{grid-template-columns:1fr}}

.oc-approach__row{position:relative;display:grid;grid-template-columns:repeat(3,1fr);gap:26px;margin-top:40px}
.oc-approach__row::before{content:'';position:absolute;top:34px;left:calc(16.66% + 8px);right:calc(16.66% + 8px);height:2px;background-image:repeating-linear-gradient(90deg,var(--gold-line) 0 12px,transparent 12px 20px);opacity:.6;z-index:0}
.oc-approach__card{position:relative;z-index:1}
.oc-approach__icon{width:64px;height:64px;border-radius:50%;background:#fff;border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--gold-dark);box-shadow:0 1px 3px rgba(8,20,60,.06);margin-bottom:16px}
.oc-approach__card h3{font-family:var(--font-d);font-size:16.5px;font-weight:700;color:var(--text);margin-bottom:8px}
.oc-approach__card p{color:var(--muted);font-size:14px;line-height:1.7}
@media (max-width:820px){.oc-approach__row{grid-template-columns:1fr;gap:28px}.oc-approach__row::before{display:none}}

.oc-why__grid{display:grid;grid-template-columns:.85fr 1.15fr;gap:48px;align-items:center}
.oc-why__badge{aspect-ratio:1/1;border-radius:26px;background:linear-gradient(150deg,#08143c,#12306e);display:flex;align-items:center;justify-content:center;padding:14%;box-shadow:0 20px 46px rgba(8,20,60,.16);max-width:260px}
.oc-why__grid p{color:var(--text2);margin-bottom:12px;font-size:15.5px}
@media (max-width:820px){.oc-why__grid{grid-template-columns:1fr}.oc-why__badge{margin:0 auto}}

.oc-pilot__head{text-align:center;max-width:640px;margin:0 auto 16px}
.oc-route{position:relative;max-width:640px;margin:48px auto 0;padding-left:8px}
.oc-route__svg{position:absolute;top:6px;bottom:6px;left:23px;width:2px}
.oc-route__path{stroke:var(--gold-line);stroke-width:2;fill:none;stroke-dasharray:8 10;stroke-linecap:round}
.oc-stop{position:relative;display:flex;gap:22px;padding:0 0 40px 0;opacity:0;transform:translateX(-14px);transition:opacity .6s ease,transform .6s ease}
.oc-stop.is-in{opacity:1;transform:none}
.oc-stop:last-child{padding-bottom:0}
.oc-stop__marker{flex-shrink:0;width:46px;height:46px;border-radius:50%;background:#fff;border:2px solid var(--gold);color:var(--gold-dark);display:flex;align-items:center;justify-content:center;font-family:var(--font-d);font-weight:700;font-size:13px;box-shadow:0 0 0 6px #fff,0 1px 3px rgba(8,20,60,.06);position:relative;z-index:1}
.oc-stop:last-child .oc-stop__marker{background:var(--gold);border-color:var(--gold);color:#fff}
.oc-stop__body{padding-top:6px}
.oc-stop__body h3{font-family:var(--font-d);font-size:16px;font-weight:700;color:var(--text);margin-bottom:4px}
.oc-stop__body p{font-size:13px;color:var(--muted)}
.oc-pilot__note{margin-top:32px;background:var(--gold-soft);border:1px solid #ecd8b3;border-radius:14px;padding:18px 22px;font-size:13px;color:#7a5720;line-height:1.7;display:flex;gap:12px;max-width:640px;margin-inline:auto}
.oc-pilot__note i{margin-top:2px;color:var(--gold-dark)}

.oc-bridge{display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center}
.oc-bridge__visual{border-radius:20px;overflow:hidden;background:linear-gradient(160deg,#fff,#eef2fb);border:1px solid var(--border);box-shadow:0 8px 26px rgba(8,20,60,.08);padding:30px}
.oc-bridge__goal{margin-top:16px;padding-top:16px;border-top:1px dashed var(--border2);font-family:var(--font-d);font-weight:700;font-size:15.5px;color:var(--p-dark)}
.oc-bridge p{color:var(--text2);margin-bottom:12px;font-size:15.5px}
@media (max-width:820px){.oc-bridge{grid-template-columns:1fr}}

.oc-scenario{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:18px;padding:20px 24px;border:1px solid var(--border);border-radius:14px;background:#fff;transition:border-color .2s,box-shadow .2s,transform .2s}
.oc-scenario + .oc-scenario{margin-top:12px}
.oc-scenario:hover{border-color:var(--gold-line);box-shadow:0 1px 3px rgba(8,20,60,.06);transform:translateX(4px)}
.oc-scenario__icon{width:42px;height:42px;border-radius:50%;background:var(--p-light);color:var(--p);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.oc-scenario__text{font-size:14.5px;color:var(--text)}
.oc-scenario__text strong{color:var(--p-dark)}
.oc-scenario__arrow{color:var(--faint);font-size:12px}
@media (max-width:640px){.oc-scenario{grid-template-columns:auto 1fr}.oc-scenario__arrow{display:none}}
.oc-purpose-line{text-align:center;font-family:var(--serif);font-style:italic;font-weight:500;font-size:clamp(19px,2.4vw,25px);color:var(--p-dark);margin:36px 0 6px}

.oc-vision__stmt{font-family:var(--serif);font-weight:500;font-size:clamp(21px,3vw,30px);line-height:1.55;color:#fff;max-width:820px;margin:0 auto 40px;text-align:center}
.oc-vision__stmt em{font-style:italic;color:#f3d5a3}
.oc-vision__row{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;max-width:900px;margin:0 auto}
.oc-vision__card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:24px 20px}
.oc-vision__card .tag{font-family:var(--font-d);font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#f0c98a;margin-bottom:8px;display:block}
.oc-vision__card p{color:rgba(255,255,255,.75);font-size:13.5px;line-height:1.7}
@media (max-width:760px){.oc-vision__row{grid-template-columns:1fr}}

.oc-principles__grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:36px}
.oc-principle{background:#fff;border:1px solid var(--border);border-radius:14px;padding:24px 22px;transition:box-shadow .2s,transform .2s,border-color .2s}
.oc-principle:hover{box-shadow:0 8px 26px rgba(8,20,60,.08);transform:translateY(-3px);border-color:var(--gold-line)}
.oc-principle__num{font-family:var(--serif);font-style:italic;color:var(--gold);font-size:24px;margin-bottom:8px;display:block}
.oc-principle h3{font-family:var(--font-d);font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px}
.oc-principle p{font-size:13px;color:var(--muted);line-height:1.7}
@media (max-width:900px){.oc-principles__grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:600px){.oc-principles__grid{grid-template-columns:1fr}}

.oc-involved__grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:36px}
.oc-involved__card{display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-radius:14px;padding:24px 20px;box-shadow:0 1px 3px rgba(8,20,60,.06);transition:transform .2s,box-shadow .2s}
.oc-involved__card:hover{transform:translateY(-4px);box-shadow:0 8px 26px rgba(8,20,60,.08)}
.oc-involved__icon{width:44px;height:44px;border-radius:10px;background:var(--gold-soft);color:var(--gold-dark);display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:14px}
.oc-involved__card h3{font-family:var(--font-d);font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px}
.oc-involved__card p{font-size:12.5px;color:var(--muted);line-height:1.65;margin-bottom:18px;flex:1}
@media (max-width:980px){.oc-involved__grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:560px){.oc-involved__grid{grid-template-columns:1fr}}

.oc-closed-banner{display:flex;align-items:center;gap:10px;background:var(--amb-bg);border:1px solid var(--amb-b);color:var(--amber);border-radius:12px;padding:14px 18px;font-size:13px;margin-bottom:26px}

.oc-register__wrap{max-width:760px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:20px;box-shadow:0 20px 48px rgba(8,20,60,.14);padding:clamp(26px,4vw,44px)}
.oc-register__head{text-align:center;margin-bottom:28px}
.oc-f-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px}
.oc-f-grid .full{grid-column:1 / -1}
.oc-flabel{display:block;font-size:11.5px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:var(--text);margin-bottom:6px}
.oc-finput,.oc-fselect,.oc-ftextarea{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:13.5px;font-family:var(--sans);color:var(--text);background:var(--bg2);outline:none;transition:border-color .18s,background .18s}
.oc-finput:focus,.oc-fselect:focus,.oc-ftextarea:focus{border-color:var(--gold);background:#fff;box-shadow:0 0 0 3px rgba(184,121,47,.1)}
.oc-ftextarea{resize:vertical;min-height:84px}
.oc-fradio-row{display:flex;gap:16px;padding-top:6px}
.oc-fradio{display:flex;align-items:center;gap:7px;font-size:13.5px;color:var(--text2);cursor:pointer}
.oc-fradio input{accent-color:var(--gold)}
.oc-fcheck{display:flex;align-items:flex-start;gap:9px;font-size:12.5px;color:var(--text2);line-height:1.6;cursor:pointer}
.oc-fcheck input{margin-top:2px;accent-color:var(--gold)}
.oc-ferr{font-size:11.5px;color:var(--red);margin-top:5px;min-height:1px}
.oc-global-error{background:var(--red-bg);border:1px solid var(--red-b);color:var(--red);border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:18px}
.oc-register__submit{margin-top:22px}
.oc-register__disclaimer{display:flex;gap:10px;align-items:flex-start;margin-top:16px;font-size:12px;color:var(--faint);background:var(--bg);border-radius:10px;padding:12px 14px}
.oc-register__success{display:none;text-align:center;padding:30px 10px}
.oc-register__success.is-visible{display:block}
.oc-register__success i{font-size:36px;color:var(--green);margin-bottom:14px}
.oc-register__success h3{font-family:var(--font-d);font-weight:700;font-size:19px;color:var(--text);margin-bottom:8px}
.oc-register__success p{color:var(--muted);font-size:14px}
@media (max-width:640px){.oc-f-grid{grid-template-columns:1fr}}

.oc-overlay{display:none;position:fixed;inset:0;background:rgba(8,20,60,.55);z-index:400;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)}
.oc-overlay.is-open{display:flex}
.oc-overlay__sheet{position:relative;background:#fff;border-radius:18px;max-width:440px;width:100%;padding:30px;box-shadow:0 30px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto}
.oc-overlay__close{position:absolute;top:16px;right:16px;width:32px;height:32px;border-radius:50%;border:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted)}
.oc-overlay__close:hover{color:var(--text);border-color:var(--border2)}
.oc-overlay__success{display:none;text-align:center;padding:20px 6px}
.oc-overlay__success.is-visible{display:block}
.oc-overlay__success i{font-size:34px;color:var(--green);margin-bottom:12px}
.oc-overlay__success p{color:var(--muted);font-size:14px}
</style>

<script>
(function(){
  'use strict';

  /* ── Reveal on scroll ─────────────────────────── */
  var revealEls = document.querySelectorAll('.oc-reveal');
  if ('IntersectionObserver' in window) {
    var obs = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if (entry.isIntersecting) { entry.target.classList.add('is-in'); obs.unobserve(entry.target); }
      });
    }, { threshold: 0.12 });
    revealEls.forEach(function(el){ obs.observe(el); });
  } else {
    revealEls.forEach(function(el){ el.classList.add('is-in'); });
  }

  /* ── Pilot route line + staggered stops ──────────── */
  var route = document.querySelector('.oc-route');
  var stops = document.querySelectorAll('.oc-stop');
  var svg   = document.getElementById('ocRouteSvg');
  var path  = document.getElementById('ocRoutePath');

  function sizeRoute(){
    if (!route || !svg) return;
    var h = route.offsetHeight;
    svg.setAttribute('height', h);
    path.setAttribute('y2', h);
  }
  sizeRoute();
  window.addEventListener('resize', sizeRoute);

  if ('IntersectionObserver' in window) {
    var stopObs = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if (entry.isIntersecting) { entry.target.classList.add('is-in'); stopObs.unobserve(entry.target); }
      });
    }, { threshold: 0.3 });
    stops.forEach(function(el, i){ el.style.transitionDelay = (i * 60) + 'ms'; stopObs.observe(el); });
  } else {
    stops.forEach(function(el){ el.classList.add('is-in'); });
  }

  /* ══════════════════════════════════════════════════
     REGISTRATION FORM → POST /api/outreach/register.php
     ══════════════════════════════════════════════════ */
  var form       = document.getElementById('ocRegisterForm');
  var submitBtn  = document.getElementById('ocSubmitBtn');
  var globalErr  = document.getElementById('ocGlobalError');

  function clearFieldErrors(scope){
    scope.querySelectorAll('.oc-ferr').forEach(function(el){ el.textContent = ''; });
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    clearFieldErrors(form);
    globalErr.style.display = 'none';

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    submitBtn.disabled = true;
    var label = submitBtn.querySelector('.oc-btn-label');
    var originalLabel = label.textContent;
    label.textContent = 'Submitting…';

    fetch('/api/outreach/register.php', {
      method: 'POST',
      body: new FormData(form),
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
      submitBtn.disabled = false;
      label.textContent = originalLabel;

      if (res.success) {
        document.getElementById('ocSuccessTitle').textContent = res.duplicate ? 'Already on the list' : "You're on the list.";
        document.getElementById('ocSuccessMessage').textContent = res.message || '';
        document.getElementById('ocFormPanel').style.display = 'none';
        var successPanel = document.getElementById('ocSuccessPanel');
        successPanel.classList.add('is-visible');
        successPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }

      if (res.errors) {
        var firstField = null;
        Object.keys(res.errors).forEach(function(field){
          if (field === '_global') {
            globalErr.textContent = res.errors[field];
            globalErr.style.display = 'block';
            return;
          }
          var el = form.querySelector('.oc-ferr[data-for="' + field + '"]');
          if (el) el.textContent = res.errors[field];
          if (!firstField) firstField = field;
        });
        if (firstField) {
          var input = form.querySelector('[name="' + firstField + '"]');
          if (input) input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      } else if (res.message) {
        globalErr.textContent = res.message;
        globalErr.style.display = 'block';
      }
    })
    .catch(function(){
      submitBtn.disabled = false;
      label.textContent = originalLabel;
      globalErr.textContent = 'Connection error. Please check your internet and try again.';
      globalErr.style.display = 'block';
    });
  });

  /* ══════════════════════════════════════════════════
     PARTNER ENQUIRY OVERLAY → POST /api/outreach/partner-enquiry.php
     ══════════════════════════════════════════════════ */
  var overlay      = document.getElementById('ocPartnerOverlay');
  var partnerForm  = document.getElementById('ocPartnerForm');
  var partnerType  = document.getElementById('ocPartnerType');
  var partnerTitle = document.getElementById('ocPartnerTitle');
  var partnerErr   = document.getElementById('ocPartnerGlobalError');
  var partnerSuccess = document.getElementById('ocPartnerSuccess');

  document.querySelectorAll('.oc-open-partner').forEach(function(btn){
    btn.addEventListener('click', function(){
      var type = btn.dataset.type;
      partnerType.value = type;
      partnerTitle.textContent = type === 'dealer' ? 'Become a dealer partner' : 'Become an employer partner';
      partnerForm.style.display = 'block';
      partnerSuccess.classList.remove('is-visible');
      partnerErr.style.display = 'none';
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
    });
  });

  function closePartnerOverlay(){
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
  }
  document.getElementById('ocPartnerClose').addEventListener('click', closePartnerOverlay);
  overlay.addEventListener('click', function(e){ if (e.target === overlay) closePartnerOverlay(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closePartnerOverlay(); });

  partnerForm.addEventListener('submit', function(e){
    e.preventDefault();
    partnerErr.style.display = 'none';
    if (!partnerForm.checkValidity()) { partnerForm.reportValidity(); return; }

    var btn = partnerForm.querySelector('button[type="submit"]');
    btn.disabled = true;

    fetch('/api/outreach/partner-enquiry.php', {
      method: 'POST',
      body: new FormData(partnerForm),
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
      btn.disabled = false;
      if (res.success) {
        partnerForm.style.display = 'none';
        partnerSuccess.classList.add('is-visible');
      } else if (res.errors) {
        var msgs = Object.values(res.errors);
        partnerErr.textContent = msgs.join(' ');
        partnerErr.style.display = 'block';
      } else {
        partnerErr.textContent = res.message || 'Something went wrong. Please try again.';
        partnerErr.style.display = 'block';
      }
    })
    .catch(function(){
      btn.disabled = false;
      partnerErr.textContent = 'Connection error. Please check your internet and try again.';
      partnerErr.style.display = 'block';
    });
  });

})();
</script>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
