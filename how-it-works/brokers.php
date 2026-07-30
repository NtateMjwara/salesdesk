<?php
/**
 * SalesDesk — How It Works: Auto Brokers / Promoters
 * Route: /how-it-works/brokers.php
 *
 * Repositioned pitch (v2): SalesDesk as your own vehicle marketplace —
 * "own a marketplace without owning a vehicle" — rather than a plain
 * commission/affiliate explainer.
 *
 * No auth required. Wired into layout-public.php (nav + real site footer).
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');
$visitor = initVisitorSession();

$pageTitle     = 'How It Works for Brokers | SalesDesk';
$ogTitle       = 'Own a Vehicle Marketplace — Without Owning a Vehicle | SalesDesk';
$ogDescription = 'Build your own automotive business using inventory from verified dealerships across South Africa. No stock, no premises, no employees, no capital — just your network.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/how-it-works/brokers.php';
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [
    ['How It Works', null],
    ['For Brokers',  null],
];

$defaultFee = 10;
try {
    require_once '../includes/database.php';
    require_once '../includes/functions.php';
    $defaultFee = getPlatformConfigInt('platform_fee_percent', 10);
} catch (Throwable) {}

ob_start();
?>

<div class="bpv2-page">

<!-- ═══════════════════════════════════════════════════════
     ROLE SWITCHER (sitewide pattern — unchanged colours)
     ═══════════════════════════════════════════════════════ --
<div class="bpv2-wrap" style="padding-top:1.6rem;">
  <div style="display:flex;gap:8px;justify-content:center;margin-bottom:0;flex-wrap:wrap;">
    <a href="/how-it-works/brokers.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
              background:#0f4c9e;color:#fff;border-radius:999px;
              font-size:13px;font-weight:700;text-decoration:none;font-family:'Space Grotesk',sans-serif;">
      <i class="fa-solid fa-id-card"></i> Brokers
    </a>
    <a href="/how-it-works/dealers.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
              background:#fff;color:#64748b;border:1.5px solid #e2e8f0;
              border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;
              transition:all .18s;font-family:'Space Grotesk',sans-serif;"
       onmouseover="this.style.borderColor='#0f4c9e';this.style.color='#0f4c9e'"
       onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#64748b'">
      <i class="fa-solid fa-building-user"></i> Dealers
    </a>
    <a href="/how-it-works/sales-exec.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
              background:#fff;color:#64748b;border:1.5px solid #e2e8f0;
              border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;
              transition:all .18s;font-family:'Space Grotesk',sans-serif;"
       onmouseover="this.style.borderColor='#0f4c9e';this.style.color='#0f4c9e'"
       onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#64748b'">
      <i class="fa-solid fa-user-tie"></i> Sales Executives
    </a>
  </div>
</div>-->

<!-- ═══════════════════════════════════════════════════════
     HERO — row on desktop (text left / storefront right),
     stacks to a column on tablet and mobile.
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-hero">
  <div class="bpv2-wrap">
    <div class="bpv2-hero-grid">

      <div class="bpv2-hero-copy">
        <div class="bpv2-eyebrow"><span class="bpv2-dot"></span>Build your own automotive business</div>
        <h1>Own a vehicle marketplace. Without owning <em>a single vehicle.</em></h1>
        <p class="bpv2-hero-sub">
          Build your own automotive business using inventory from verified dealerships across South Africa. You build the audience, the brand, and the reputation. We provide the inventory and the technology behind it.
        </p>

        <div class="bpv2-build-strip">
          <span>No <b>stock</b></span>
          <span>No <b>premises</b></span>
          <span>No <b>employees</b></span>
          <span>No <b>capital</b></span>
        </div>

        <div class="bpv2-hero-actions">
          <a href="/auth/register.php" class="bpv2-btn bpv2-btn-primary">
            <i class="fa-solid fa-rocket"></i> Create my free SalesDesk
          </a>
          <a href="/c/" class="bpv2-btn bpv2-btn-ghost">
            Browse available cars
          </a>
        </div>
      </div>

      <div class="bpv2-hero-visual">
        <div class="bpv2-stage">
          <svg viewBox="0 0 880 500" xmlns="http://www.w3.org/2000/svg">
            <!-- source dealership nodes on the left -->
            <g font-family="IBM Plex Mono, monospace" font-size="12" fill="rgba(255,255,255,0.55)">
              <rect x="20" y="80" width="150" height="42" rx="8" fill="rgba(255,255,255,0.05)" stroke="rgba(255,255,255,0.14)"/>
              <text x="38" y="105">Dealership A</text>

              <rect x="20" y="175" width="150" height="42" rx="8" fill="rgba(255,255,255,0.05)" stroke="rgba(255,255,255,0.14)"/>
              <text x="38" y="200">Dealership B</text>

              <rect x="20" y="270" width="150" height="42" rx="8" fill="rgba(255,255,255,0.05)" stroke="rgba(255,255,255,0.14)"/>
              <text x="38" y="295">Dealership C</text>

              <rect x="20" y="365" width="150" height="42" rx="8" fill="rgba(255,255,255,0.05)" stroke="rgba(255,255,255,0.14)"/>
              <text x="38" y="390">+ hundreds more</text>
            </g>

            <!-- feed lines converging -->
            <g stroke="#F2A93B" stroke-width="1.3" opacity="0.4" fill="none">
              <path d="M170,101 C 260,101 260,220 330,228"/>
              <path d="M170,196 C 260,196 260,224 330,228"/>
              <path d="M170,291 C 260,291 260,232 330,228"/>
              <path d="M170,386 C 260,386 260,238 330,228"/>
            </g>

            <!-- browser storefront -->
            <g>
              <rect x="330" y="70" width="530" height="345" rx="14" fill="#141F33" stroke="#F2A93B" stroke-width="1.5"/>
              <rect x="330" y="70" width="530" height="36" rx="14" fill="#0B1220"/>
              <circle cx="352" cy="88" r="4.5" fill="#EF5B5B"/>
              <circle cx="368" cy="88" r="4.5" fill="#F2A93B"/>
              <circle cx="384" cy="88" r="4.5" fill="#39C56A"/>
              <rect x="410" y="80" width="290" height="16" rx="7" fill="rgba(255,255,255,0.08)"/>
              <text x="420" y="92.5" font-family="IBM Plex Mono, monospace" font-size="11" fill="rgba(255,255,255,0.55)">yourname.salesdesk.co.za</text>

              <text x="352" y="138" font-family="Space Grotesk, sans-serif" font-size="18" font-weight="600" fill="#FFFFFF">Your Motors Marketplace</text>
              <text x="352" y="159" font-family="Inter, sans-serif" font-size="12" fill="rgba(255,255,255,0.5)">142 vehicles &middot; browse, filter, enquire</text>

              <!-- vehicle grid -->
              <g>
                <rect x="352" y="178" width="120" height="80" rx="8" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.1)"/>
                <rect x="363" y="189" width="98" height="38" rx="4" fill="#F2A93B" opacity="0.55"/>
                <rect x="363" y="235" width="76" height="8" rx="3" fill="rgba(255,255,255,0.4)"/>
                <rect x="363" y="247" width="48" height="7" rx="3" fill="rgba(255,255,255,0.25)"/>

                <rect x="484" y="178" width="120" height="80" rx="8" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.1)"/>
                <rect x="495" y="189" width="98" height="38" rx="4" fill="#F2A93B" opacity="0.4"/>
                <rect x="495" y="235" width="76" height="8" rx="3" fill="rgba(255,255,255,0.4)"/>
                <rect x="495" y="247" width="48" height="7" rx="3" fill="rgba(255,255,255,0.25)"/>

                <rect x="616" y="178" width="120" height="80" rx="8" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.1)"/>
                <rect x="627" y="189" width="98" height="38" rx="4" fill="#F2A93B" opacity="0.7"/>
                <rect x="627" y="235" width="76" height="8" rx="3" fill="rgba(255,255,255,0.4)"/>
                <rect x="627" y="247" width="48" height="7" rx="3" fill="rgba(255,255,255,0.25)"/>

                <rect x="352" y="272" width="120" height="80" rx="8" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.1)"/>
                <rect x="363" y="283" width="98" height="38" rx="4" fill="#F2A93B" opacity="0.5"/>
                <rect x="363" y="329" width="76" height="8" rx="3" fill="rgba(255,255,255,0.4)"/>
                <rect x="363" y="341" width="48" height="7" rx="3" fill="rgba(255,255,255,0.25)"/>

                <rect x="484" y="272" width="120" height="80" rx="8" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.1)"/>
                <rect x="495" y="283" width="98" height="38" rx="4" fill="#F2A93B" opacity="0.65"/>
                <rect x="495" y="329" width="76" height="8" rx="3" fill="rgba(255,255,255,0.4)"/>
                <rect x="495" y="341" width="48" height="7" rx="3" fill="rgba(255,255,255,0.25)"/>

                <rect x="616" y="272" width="120" height="80" rx="8" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.1)"/>
                <rect x="627" y="283" width="98" height="38" rx="4" fill="#F2A93B" opacity="0.45"/>
                <rect x="627" y="329" width="76" height="8" rx="3" fill="rgba(255,255,255,0.4)"/>
                <rect x="627" y="341" width="48" height="7" rx="3" fill="rgba(255,255,255,0.25)"/>
              </g>
            </g>
          </svg>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════
     MORE THAN COMMISSION
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-band">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker">More than commission</div>
    <h2>You're not becoming an affiliate. You're building a business.</h2>
    <p>Traditional vehicle sales require capital, premises, stock, and employees — millions to even get started. SalesDesk removes every one of those barriers, so you invest in relationships instead of inventory.</p>
    <div class="bpv2-barrier-grid">
      <div class="bpv2-barrier"><div class="bpv2-old">Invest in vehicles</div><div class="bpv2-new">Invest in relationships</div></div>
      <div class="bpv2-barrier"><div class="bpv2-old">Manage inventory</div><div class="bpv2-new">Choose which vehicles to feature</div></div>
      <div class="bpv2-barrier"><div class="bpv2-old">Pay overheads</div><div class="bpv2-new">Grow your audience</div></div>
      <div class="bpv2-barrier"><div class="bpv2-old">Chase a salary</div><div class="bpv2-new">Earn a commission per sale</div></div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════
     YOUR MARKETPLACE, YOUR BRAND
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-split-grid">

      <div>
        <div class="bpv2-kicker-light">Your marketplace, your brand</div>
        <h2 class="bpv2-title">Not a referral link. A complete vehicle marketplace.</h2>
        <p class="bpv2-lead">To your customers, they're visiting your marketplace — not clicking an affiliate link. Every enquiry it generates belongs to you and is automatically attributed to your account.</p>
        <div class="bpv2-feature-grid">
          <div class="bpv2-feature"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg><span>Browse hundreds of vehicles</span></div>
          <div class="bpv2-feature"><svg viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg><span>Filter by make, model, price, location</span></div>
          <div class="bpv2-feature"><svg viewBox="0 0 24 24"><path d="M9 3v18M15 3v18"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg><span>Compare listings side by side</span></div>
          <div class="bpv2-feature"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg><span>View full specifications and images</span></div>
          <div class="bpv2-feature"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>Submit enquiries directly to dealerships</span></div>
          <div class="bpv2-feature"><svg viewBox="0 0 24 24"><path d="M12 2l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L4 9l6-1z"/></svg><span>Every lead attributed to you, automatically</span></div>
        </div>
      </div>

      <!-- Your brand illustration — a live storefront mockup, not a link -->
      <div class="bpv2-split-visual">
        <div class="bpv2-storefront-mock">
          <div class="bpv2-sf-browserbar">
            <span class="bpv2-sf-dot bpv2-sf-dot-r"></span>
            <span class="bpv2-sf-dot bpv2-sf-dot-a"></span>
            <span class="bpv2-sf-dot bpv2-sf-dot-g"></span>
            <span class="bpv2-sf-url">salesdesk.co.za/thembi</span>
          </div>
          <div class="bpv2-sf-hero">
            <div class="bpv2-sf-avatar">TN</div>
            <div class="bpv2-sf-name">Thembi's AutoDesk</div>
            <div class="bpv2-sf-loc">Johannesburg &middot; Gauteng</div>
            <div class="bpv2-sf-stats">
              <div><strong>8</strong><span>Active</span></div>
              <div><strong>142</strong><span>Views</span></div>
              <div><strong>3</strong><span>Closed</span></div>
            </div>
          </div>
          <div class="bpv2-sf-body">
            <div class="bpv2-sf-label">Available cars (8)</div>
            <div class="bpv2-sf-card">
              <div class="bpv2-sf-card-img"><i class="fa-solid fa-car-side"></i></div>
              <div class="bpv2-sf-card-body">
                <div class="bpv2-sf-card-name">2022 Toyota RAV4</div>
                <div class="bpv2-sf-card-price">R 549 900</div>
              </div>
            </div>
          </div>
        </div>
        <p class="bpv2-visual-caption">Illustrative — every SalesDesk gets its own branded URL, stats, and listings.</p>
      </div>

    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     BUILD IT YOUR WAY
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Build your marketplace your way</div>
    <h2 class="bpv2-title">There's no single way to grow a SalesDesk.</h2>
    <p class="bpv2-lead">Some members build large social audiences. Others rely on professional relationships built over many years. SalesDesk doesn't dictate how you grow — it simply gives you the platform.</p>
    <div class="bpv2-chip-field">
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>Facebook</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>TikTok</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>Instagram</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>WhatsApp</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>LinkedIn</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>Your own website</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>Email newsletters</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>Existing clients</span>
      <span class="bpv2-chip"><span class="bpv2-chip-dot"></span>Community organisations</span>
      <span class="bpv2-chip bpv2-ghost">+ word of mouth</span>
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     HOW IT WORKS
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">How it works</div>
    <h2 class="bpv2-title">Six steps from sign-up to payout.</h2>
    <div class="bpv2-flow-list">
      <div class="bpv2-flow-step"><div class="bpv2-step-num">1</div><div class="bpv2-step-body"><strong>Create your free SalesDesk</strong><span>Your personal vehicle marketplace is live in minutes. No subscription, no setup cost, no inventory to purchase.</span></div></div>
      <div class="bpv2-flow-step"><div class="bpv2-step-num">2</div><div class="bpv2-step-body"><strong>Choose your inventory</strong><span>Browse vehicles from verified dealerships and select the listings that suit your audience. Commission is shown upfront on every listing.</span></div></div>
      <div class="bpv2-flow-step"><div class="bpv2-step-num">3</div><div class="bpv2-step-body"><strong>Build your marketplace</strong><span>Create collections, share vehicles, publish content, and grow your reputation as a trusted automotive marketplace.</span></div></div>
      <div class="bpv2-flow-step"><div class="bpv2-step-num">4</div><div class="bpv2-step-body"><strong>Generate enquiries</strong><span>When buyers enquire through your SalesDesk, every lead is securely attributed to your account from the first click.</span></div></div>
      <div class="bpv2-flow-step"><div class="bpv2-step-num">5</div><div class="bpv2-step-body"><strong>Dealership completes the sale</strong><span>They handle test drives, finance, trade-ins, delivery, and documentation. You focus on generating customers.</span></div></div>
      <div class="bpv2-flow-step"><div class="bpv2-step-num">6</div><div class="bpv2-step-body"><strong>Get paid</strong><span>Once the vehicle sells, your commission is processed. No chasing dealerships, no invoicing — everything is tracked within SalesDesk.</span></div></div>
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     WHY DIFFERENT
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Why SalesDesk is different</div>
    <h2 class="bpv2-title">Every traditional barrier, removed.</h2>
    <div class="bpv2-nb-grid">
      <div class="bpv2-nb-card"><span class="bpv2-nb-tag">No inventory</span><div class="bpv2-nb-title">Sell vehicles without owning them</div></div>
      <div class="bpv2-nb-card"><span class="bpv2-nb-tag">No dealership</span><div class="bpv2-nb-title">Operate entirely online, from anywhere</div></div>
      <div class="bpv2-nb-card"><span class="bpv2-nb-tag">No employees</span><div class="bpv2-nb-title">Build at your own pace</div></div>
      <div class="bpv2-nb-card"><span class="bpv2-nb-tag">No capital</span><div class="bpv2-nb-title">Start with your network and ambition</div></div>
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     SCALE BEYOND YOURSELF
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Scale beyond yourself</div>
    <h2 class="bpv2-title">Build more than a marketplace. Build an organisation.</h2>
    <p class="bpv2-lead">As your SalesDesk grows, invite other promoters to join your organisation. Create your own sales team, monitor performance, and share opportunities — whether you stay a solo entrepreneur or build a nationwide network.</p>
    <div class="bpv2-team-diagram">
      <svg viewBox="0 0 520 160" xmlns="http://www.w3.org/2000/svg">
        <circle cx="60" cy="80" r="26" fill="#0B1220"/>
        <text x="60" y="84" text-anchor="middle" font-family="IBM Plex Mono, monospace" font-size="10" fill="#F2A93B">YOU</text>
        <g stroke="#F2A93B" stroke-width="1.3" opacity="0.45">
          <line x1="86" y1="80" x2="220" y2="30"/>
          <line x1="86" y1="80" x2="220" y2="80"/>
          <line x1="86" y1="80" x2="220" y2="130"/>
        </g>
        <g font-family="IBM Plex Mono, monospace" font-size="10.5" fill="#59616D">
          <circle cx="240" cy="30" r="16" fill="#F4F5F8" stroke="#E3E6EC"/>
          <text x="264" y="34">Promoter</text>
          <circle cx="240" cy="80" r="16" fill="#F4F5F8" stroke="#E3E6EC"/>
          <text x="264" y="84">Promoter</text>
          <circle cx="240" cy="130" r="16" fill="#F4F5F8" stroke="#E3E6EC"/>
          <text x="264" y="134">Promoter</text>
        </g>
        <g stroke="#F2A93B" stroke-width="1" opacity="0.3">
          <line x1="256" y1="30" x2="360" y2="15"/>
          <line x1="256" y1="30" x2="360" y2="45"/>
          <line x1="256" y1="130" x2="360" y2="115"/>
          <line x1="256" y1="130" x2="360" y2="145"/>
        </g>
        <g fill="#F2A93B" opacity="0.55">
          <circle cx="365" cy="15" r="4"/><circle cx="365" cy="45" r="4"/>
          <circle cx="365" cy="115" r="4"/><circle cx="365" cy="145" r="4"/>
          <circle cx="400" cy="80" r="4"/><circle cx="420" cy="60" r="3"/><circle cx="430" cy="100" r="3"/>
        </g>
      </svg>
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     WHAT YOU CAN EARN
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section-tight">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Your potential return</div>
    <h2 class="bpv2-title" style="font-size:21px;">See what you could earn.</h2>
    <p class="bpv2-lead" style="margin-bottom:1.4rem;">Move the sliders to match a real listing — the numbers update as you go.</p>

    <div class="bpv2-calc-card">
      <div class="bpv2-calc-controls">
        <div class="bpv2-calc-field">
          <label>Car sale price</label>
          <input type="range" id="bpv2CalcPrice" min="50000" max="2000000" step="25000" value="350000">
          <div class="bpv2-calc-range-labels"><span>R50k</span><span>R2m</span></div>
          <div class="bpv2-calc-value" id="bpv2CalcPriceDisplay">R&nbsp;350&nbsp;000</div>
        </div>
        <div class="bpv2-calc-field">
          <label>Commission rate</label>
          <input type="range" id="bpv2CalcComm" min="0.5" max="5" step="0.5" value="2.5">
          <div class="bpv2-calc-range-labels"><span>0.5%</span><span>5%</span></div>
          <div class="bpv2-calc-value" id="bpv2CalcCommDisplay">2.5%</div>
        </div>
      </div>

      <div class="bpv2-calc-toggle">
        <label class="bpv2-calc-radio">
          <input type="radio" name="bpv2CalcType" value="single" checked> Single deal
        </label>
        <label class="bpv2-calc-radio">
          <input type="radio" name="bpv2CalcType" value="monthly"> Monthly (<span id="bpv2DealsPerMonth">3</span> deals)
        </label>
      </div>

      <div class="bpv2-calc-deals-wrap" id="bpv2MonthlySliderWrap">
        <input type="range" id="bpv2CalcDeals" min="1" max="20" step="1" value="3">
        <div class="bpv2-calc-range-labels"><span>1 deal</span><span>20 deals</span></div>
      </div>

      <div class="bpv2-calc-results">
        <div class="bpv2-calc-result">
          <span>Gross commission</span>
          <strong id="bpv2CalcGross">—</strong>
        </div>
        <div class="bpv2-calc-result">
          <span>Platform fee (<?= $defaultFee ?>%)</span>
          <strong id="bpv2CalcFee" class="bpv2-calc-neg">—</strong>
        </div>
        <div class="bpv2-calc-result bpv2-calc-highlight">
          <span>You receive</span>
          <strong id="bpv2CalcNet">—</strong>
        </div>
      </div>
      <p class="bpv2-calc-note">Illustrative only. Actual commission is set by the dealer and varies per listing. EFT paid within 7 business days of deal confirmation.</p>
    </div>
  </div>
</section>

<script>
(function () {
  var priceEl     = document.getElementById('bpv2CalcPrice');
  var commEl      = document.getElementById('bpv2CalcComm');
  var dealsEl     = document.getElementById('bpv2CalcDeals');
  var pDisp       = document.getElementById('bpv2CalcPriceDisplay');
  var cDisp       = document.getElementById('bpv2CalcCommDisplay');
  var dDisp       = document.getElementById('bpv2DealsPerMonth');
  var grossEl     = document.getElementById('bpv2CalcGross');
  var feeEl       = document.getElementById('bpv2CalcFee');
  var netEl       = document.getElementById('bpv2CalcNet');
  var typeRadios  = document.querySelectorAll('input[name="bpv2CalcType"]');
  var monthlyWrap = document.getElementById('bpv2MonthlySliderWrap');
  var fee         = <?= $defaultFee ?>;

  function fmt(n) {
    return 'R\u00a0' + Math.round(n).toLocaleString('en-ZA');
  }

  function calc() {
    var price = parseFloat(priceEl.value);
    var comm  = parseFloat(commEl.value);
    var type  = document.querySelector('input[name="bpv2CalcType"]:checked').value;
    var deals = parseInt(dealsEl.value, 10);
    var mult  = type === 'monthly' ? deals : 1;

    var gross  = price * (comm / 100) * mult;
    var feeAmt = gross * (fee / 100);
    var net    = gross - feeAmt;

    pDisp.textContent   = 'R\u00a0' + Math.round(price).toLocaleString('en-ZA');
    cDisp.textContent   = comm.toFixed(1) + '%';
    dDisp.textContent   = deals;
    grossEl.textContent = fmt(gross);
    feeEl.textContent   = '\u2212\u00a0' + fmt(feeAmt);
    netEl.textContent   = fmt(net);
  }

  [priceEl, commEl, dealsEl].forEach(function (el) {
    el.addEventListener('input', calc);
  });

  typeRadios.forEach(function (r) {
    r.addEventListener('change', function () {
      monthlyWrap.style.display = r.value === 'monthly' ? 'block' : 'none';
      calc();
    });
  });

  monthlyWrap.style.display = 'none';
  calc();
})();
</script>

<!-- ═══════════════════════════════════════════════════════
     WHAT IT COSTS
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section-tight">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">What it costs you</div>
    <table class="bpv2-cost-table">
      <tr><td class="bpv2-cost-label">Setup and registration</td><td class="bpv2-cost-val bpv2-green">Free</td></tr>
      <tr><td class="bpv2-cost-label">Monthly subscription</td><td class="bpv2-cost-val bpv2-green">None</td></tr>
      <tr><td class="bpv2-cost-label">Stock or capital required</td><td class="bpv2-cost-val bpv2-green">None — you never own the vehicles</td></tr>
      <tr><td class="bpv2-cost-label">Platform fee</td><td class="bpv2-cost-val"><?= $defaultFee ?>% deducted from your commission at payout</td></tr>
      <tr><td class="bpv2-cost-label">Cost per lead that doesn't close</td><td class="bpv2-cost-val bpv2-green">R0 — you only earn on concluded sales</td></tr>
    </table>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     WHO IT'S FOR
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Who SalesDesk is for</div>
    <h2 class="bpv2-title">If people trust your recommendations, you already qualify.</h2>
    <div class="bpv2-who-grid">
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Insurance brokers</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Finance professionals</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Estate agents</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Fleet consultants</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Business owners</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Ex sales executives</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Influencers &amp; creators</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Community leaders</div></div>
      <div class="bpv2-who-chip"><div class="bpv2-w-name">Entrepreneurs</div></div>
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     PROTECTIONS
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section-tight">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Your commission is protected</div>
    <h2 class="bpv2-title" style="font-size:21px;">Recognised from first enquiry to final payout.</h2>
    <div class="bpv2-trust-strip">
      <span class="bpv2-trust-pill"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Attribution locked at first enquiry</span>
      <span class="bpv2-trust-pill"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>POPIA compliant</span>
      <span class="bpv2-trust-pill"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Full commission audit trail</span>
      <span class="bpv2-trust-pill"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Dispute resolution built in</span>
    </div>
    <div class="bpv2-attr-note">
      The moment a buyer enquires through your tracked link, that referral is yours — permanently. The dealer cannot reassign it, claim it was a walk-in they already knew, or dispute the attribution. Every lead carries a timestamped, tamper-proof record that protects your commission all the way through to payout.
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     FAQ
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section">
  <div class="bpv2-wrap">
    <div class="bpv2-kicker-light">Common questions</div>
    <h2 class="bpv2-title">Broker FAQ</h2>

    <div class="bpv2-faq-list">
      <?php
      $faqs = [
        [
          'Do I need a dealer license or any qualifications to do this?',
          'No. SalesDesk is designed for independent promoters who act as lead generators, not sellers. You\'re connecting buyers with dealerships — the dealer handles the actual transaction, finance, and legal transfer. No licence, certification, or automotive background is required.'
        ],
        [
          'What if a buyer contacts the dealer directly after clicking my link?',
          'Your attribution is locked the moment the buyer submits an enquiry through your tracked link. If they later call the dealer directly, the lead is already recorded under your code. The dealer cannot reassign it — attribution is immutable once set.'
        ],
        [
          'What if two promoters share the same car?',
          'Each promoter gets their own unique tracking code for every car they add. Attribution goes to whichever promoter\'s link the buyer clicked first. If a buyer was already attributed to someone else, a later click from another promoter\'s link doesn\'t override that — first click wins.'
        ],
        [
          'How many vehicles can I have on my SalesDesk at once?',
          'New promoters can feature up to 10 active vehicles at any time. If you need a higher limit, reach out to our admin team — increases are granted based on activity and account standing.'
        ],
        [
          'Is there a monthly fee or subscription?',
          'SalesDesk is completely free to join and run. We only take our platform fee (' . $defaultFee . '%) when a deal closes and commission is paid out. If you don\'t earn, we don\'t earn.'
        ],
        [
          'How and when do I actually get paid?',
          'Once a dealer marks your lead as closed, a commission record is generated automatically. Our admin team reviews and approves the payout — usually the same day — and processes the EFT to your registered bank account within 7 business days.'
        ],
        [
          'Can I build a team, or invite other promoters to join me?',
          'Yes. As your SalesDesk grows you can create an organisation, invite other promoters, share opportunities, and monitor performance across your whole team from one dashboard — whether you stay solo or build a nationwide network.'
        ],
        [
          'What happens if a car I\'ve added gets sold or pulled by the dealer?',
          'If a dealer marks a car as sold or removes it from the marketplace, it\'s automatically hidden from your SalesDesk and no new enquiries can be submitted on it. Any leads you already generated before that point remain attributed to you.'
        ],
      ];
      foreach ($faqs as [$q, $a]):
      ?>
      <details class="bpv2-faq-item">
        <summary>
          <span><?= htmlspecialchars($q) ?></span>
          <i class="fa-solid fa-plus bpv2-faq-icon"></i>
        </summary>
        <div class="bpv2-faq-answer"><?= $a ?></div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<hr class="bpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     CROSS-LINKS
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-section-tight">
  <div class="bpv2-wrap">
    <div class="bpv2-cross-grid">
      <a href="/how-it-works/dealers.php" class="bpv2-cross-card">
        <div class="bpv2-cross-icon"><i class="fa-solid fa-building-user"></i></div>
        <div>
          <div class="bpv2-cross-title">How it works for dealers</div>
          <div class="bpv2-cross-desc">List your inventory and tap into the promoter network.</div>
        </div>
      </a>
      <a href="/how-it-works/sales-exec.php" class="bpv2-cross-card">
        <div class="bpv2-cross-icon"><i class="fa-solid fa-user-tie"></i></div>
        <div>
          <div class="bpv2-cross-title">How it works for sales execs</div>
          <div class="bpv2-cross-desc">Upload cars on behalf of a dealership and track your own leads.</div>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════
     FINAL CTA
     (Real site footer from layout-public.php follows this —
     no standalone pitch-doc footer needed on this page.)
     ═══════════════════════════════════════════════════════ -->
<section class="bpv2-cta-band">
  <div class="bpv2-wrap">
    <div class="bpv2-cta-inner">
      <div class="bpv2-kicker-light bpv2-mono">Ready when you are</div>
      <div class="bpv2-cta-title">Launch your own vehicle marketplace today.</div>
      <div class="bpv2-cta-body">
        Every successful automotive business starts with a single customer. No stock, no premises, no employees, no capital investment — just your ambition, your network, and a marketplace that's entirely your own.
      </div>
      <div class="bpv2-cta-actions">
        <a href="/auth/register.php" class="bpv2-btn bpv2-btn-primary">
          <i class="fa-solid fa-rocket"></i> Create my free SalesDesk
        </a>
        <a href="/auth/login.php" class="bpv2-btn bpv2-btn-ghost">
          Sign in
        </a>
      </div>
    </div>
  </div>
</section>

</div><!-- /bpv2-page -->

<style>
  .bpv2-page{
    --bpv2-ink:        #0B1220;
    --bpv2-ink-soft:   #16213A;
    --bpv2-ink-line:   rgba(255,255,255,0.10);
    --bpv2-paper:      #FFFFFF;
    --bpv2-surface:    #F4F5F8;
    --bpv2-line:       #E3E6EC;
    --bpv2-text-1:     #0F1620;
    --bpv2-text-2:     #59616D;
    --bpv2-text-3:     #93989F;
    --bpv2-amber:      #F2A93B;
    --bpv2-amber-deep: #D9800F;
    --bpv2-amber-tint: #FFF3DE;
    --bpv2-green:      #1E8E5A;
    --bpv2-green-tint: #EAF7EF;
    --bpv2-green-line: #C8ECD7;
    --bpv2-radius:     14px;

    font-family:'Inter',system-ui,sans-serif;
    color:var(--bpv2-text-1);
    background:var(--bpv2-paper);
    line-height:1.6;
    font-size:16px;
  }
  .bpv2-page *,.bpv2-page *::before,.bpv2-page *::after{box-sizing:border-box;}
  .bpv2-page h1,.bpv2-page h2,.bpv2-page h3{font-family:'Space Grotesk',sans-serif; letter-spacing:-0.01em; margin:0;}
  .bpv2-page p{margin:0;}
  .bpv2-page ul{list-style:none;}
  .bpv2-mono{font-family:'IBM Plex Mono',monospace; letter-spacing:0.02em;}
  .bpv2-page a{color:inherit;}

  .bpv2-wrap{max-width:1080px; margin:0 auto; padding:0 2rem;}
  .bpv2-page section{position:relative;}

  /* ───────── Hero ───────── */
  .bpv2-hero{background:var(--bpv2-ink); color:#fff; padding:2.4rem 0 3.2rem; overflow:hidden; margin-top:1.6rem;}
  .bpv2-hero-grid{display:grid; grid-template-columns:1.05fr 1fr; gap:48px; align-items:center;}
  .bpv2-eyebrow{display:inline-flex; align-items:center; gap:8px; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.14em; text-transform:uppercase; color:var(--bpv2-amber); margin-bottom:1.3rem;}
  .bpv2-eyebrow .bpv2-dot{width:6px; height:6px; border-radius:50%; background:var(--bpv2-amber); display:inline-block;}
  .bpv2-hero-copy h1{font-size:36px; font-weight:600; line-height:1.16; letter-spacing:-0.02em; margin-bottom:1.2rem;}
  .bpv2-hero-copy h1 em{font-style:normal; color:var(--bpv2-amber);}
  .bpv2-hero-sub{font-size:15.5px; color:rgba(255,255,255,0.72); line-height:1.7; margin-bottom:1.8rem; max-width:520px;}
  .bpv2-build-strip{display:flex; flex-wrap:wrap; gap:8px 20px; font-family:'IBM Plex Mono',monospace; font-size:12px; color:rgba(255,255,255,0.55); margin-bottom:1.8rem;}
  .bpv2-build-strip b{color:var(--bpv2-amber); font-weight:600;}
  .bpv2-hero-actions{display:flex; gap:10px; flex-wrap:wrap;}

  .bpv2-hero-visual{width:100%;}
  .bpv2-stage svg{width:100%; height:auto; display:block; max-width:520px; margin:0 auto;}

  /* ───────── Buttons ───────── */
  .bpv2-btn{display:inline-flex; align-items:center; gap:8px; padding:11px 22px; border-radius:9px; font-size:13.5px; font-weight:600; font-family:'Inter',sans-serif; cursor:pointer; text-decoration:none; transition:opacity .15s, transform .15s;}
  .bpv2-btn:hover{opacity:0.9; transform:translateY(-1px); text-decoration:none;}
  .bpv2-btn-primary{background:var(--bpv2-amber); color:var(--bpv2-ink); border:none;}
  .bpv2-btn-ghost{background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.28);}

  /* ───────── Ink bands ───────── */
  .bpv2-band{background:var(--bpv2-ink-soft); color:#fff; padding:3.2rem 0;}
  .bpv2-kicker{font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.14em; text-transform:uppercase; color:rgba(255,255,255,0.45); margin-bottom:0.9rem;}
  .bpv2-band h2{font-size:25px; font-weight:600; color:#fff; max-width:560px; margin-bottom:1rem;}
  .bpv2-band p{font-size:15px; color:rgba(255,255,255,0.68); max-width:580px; line-height:1.75;}
  .bpv2-barrier-grid{display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:1.8rem;}
  .bpv2-barrier{background:rgba(255,255,255,0.04); border:1px solid var(--bpv2-ink-line); border-radius:12px; padding:1.1rem 1.2rem;}
  .bpv2-old{font-size:13px; color:rgba(255,255,255,0.45); text-decoration:line-through; text-decoration-color:rgba(255,255,255,0.3); margin-bottom:4px;}
  .bpv2-new{font-size:14px; font-weight:600; color:var(--bpv2-amber);}

  /* ───────── Generic sections ───────── */
  .bpv2-section{padding:3.4rem 0;}
  .bpv2-section-tight{padding:2.6rem 0;}
  .bpv2-kicker-light{font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.14em; text-transform:uppercase; color:var(--bpv2-text-3); margin-bottom:0.9rem;}
  .bpv2-title{font-size:25px; font-weight:600; color:var(--bpv2-ink); max-width:620px; margin-bottom:0.9rem;}
  .bpv2-lead{font-size:15px; color:var(--bpv2-text-2); max-width:640px; line-height:1.75; margin-bottom:1.6rem; display:block;}
  .bpv2-divider{border:none; border-top:1px solid var(--bpv2-line); max-width:1080px; margin:0 auto;}

  /* ───────── Feature grid ───────── */
  .bpv2-feature-grid{display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:1.6rem;}
  .bpv2-feature{display:flex; align-items:flex-start; gap:10px; background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:10px; padding:0.9rem 1rem;}
  .bpv2-feature svg{width:16px; height:16px; stroke:var(--bpv2-amber-deep); fill:none; stroke-width:2.2; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; margin-top:2px;}
  .bpv2-feature span{font-size:13.5px; color:var(--bpv2-text-1); font-weight:500;}

  /* ───────── Chips ───────── */
  .bpv2-chip-field{display:flex; flex-wrap:wrap; gap:10px; margin-top:1.6rem;}
  .bpv2-chip{display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:500; color:var(--bpv2-ink); background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:24px; padding:9px 15px;}
  .bpv2-chip .bpv2-chip-dot{width:6px; height:6px; border-radius:50%; background:var(--bpv2-amber-deep); flex-shrink:0;}
  .bpv2-chip.bpv2-ghost{color:var(--bpv2-text-3); border-style:dashed; background:transparent;}

  /* ───────── Steps ───────── */
  .bpv2-flow-list{display:flex; flex-direction:column; margin-top:1.6rem;}
  .bpv2-flow-step{display:flex; align-items:flex-start; gap:18px; padding:1.15rem 0; border-bottom:1px solid var(--bpv2-line);}
  .bpv2-flow-step:last-child{border-bottom:none;}
  .bpv2-step-num{font-family:'IBM Plex Mono',monospace; font-size:12px; font-weight:600; width:32px; height:32px; border-radius:50%; background:var(--bpv2-ink); color:var(--bpv2-amber); display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px;}
  .bpv2-step-body strong{display:block; font-size:14.5px; font-weight:600; color:var(--bpv2-ink); margin-bottom:3px;}
  .bpv2-step-body span{font-size:13.5px; color:var(--bpv2-text-2); line-height:1.65;}

  /* ───────── Why different cards ───────── */
  .bpv2-nb-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-top:1.6rem;}
  .bpv2-nb-card{background:var(--bpv2-ink); color:#fff; border-radius:12px; padding:1.2rem 1.3rem;}
  .bpv2-nb-tag{font-family:'IBM Plex Mono',monospace; font-size:10.5px; color:var(--bpv2-amber); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:6px; display:block;}
  .bpv2-nb-title{font-size:15px; font-weight:600;}

  /* ───────── Team diagram ───────── */
  .bpv2-team-diagram{display:flex; align-items:center; justify-content:center; margin-top:1.8rem; padding:1.6rem; background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:14px;}
  .bpv2-team-diagram svg{width:100%; max-width:520px; height:auto;}

  /* ───────── Split grid (feature + storefront illustration) ───────── */
  .bpv2-split-grid{display:grid; grid-template-columns:1.1fr 0.9fr; gap:44px; align-items:center;}
  .bpv2-split-visual{display:flex; flex-direction:column; align-items:center; gap:12px;}
  .bpv2-visual-caption{font-size:11.5px; color:var(--bpv2-text-3); text-align:center; max-width:300px;}

  /* ───────── Storefront illustration ───────── */
  .bpv2-storefront-mock{width:100%; max-width:320px; background:var(--bpv2-paper); border:1px solid var(--bpv2-line); border-radius:16px; overflow:hidden; box-shadow:0 16px 40px rgba(11,18,32,.12);}
  .bpv2-sf-browserbar{background:var(--bpv2-surface); border-bottom:1px solid var(--bpv2-line); padding:10px 14px; display:flex; align-items:center; gap:8px;}
  .bpv2-sf-dot{width:9px; height:9px; border-radius:50%; flex-shrink:0;}
  .bpv2-sf-dot-r{background:#FC5C57;}
  .bpv2-sf-dot-a{background:#FDBC40;}
  .bpv2-sf-dot-g{background:#33C748;}
  .bpv2-sf-url{flex:1; background:var(--bpv2-paper); border:1px solid var(--bpv2-line); border-radius:6px; padding:3px 10px; font-family:'IBM Plex Mono',monospace; font-size:10px; color:var(--bpv2-text-3);}
  .bpv2-sf-hero{background:linear-gradient(140deg, var(--bpv2-ink) 0%, var(--bpv2-ink-soft) 100%); padding:20px; color:#fff;}
  .bpv2-sf-avatar{width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#1d4ed8); display:flex; align-items:center; justify-content:center; font-family:'Space Grotesk',sans-serif; font-size:15px; font-weight:700; margin-bottom:10px;}
  .bpv2-sf-name{font-family:'Space Grotesk',sans-serif; font-size:15px; font-weight:700; margin-bottom:2px;}
  .bpv2-sf-loc{font-size:11px; color:rgba(255,255,255,0.55);}
  .bpv2-sf-stats{display:flex; gap:18px; margin-top:14px;}
  .bpv2-sf-stats div{text-align:center;}
  .bpv2-sf-stats strong{display:block; font-family:'Space Grotesk',sans-serif; font-size:16px; font-weight:700;}
  .bpv2-sf-stats span{font-size:9.5px; color:rgba(255,255,255,0.5);}
  .bpv2-sf-body{padding:16px;}
  .bpv2-sf-label{font-family:'Space Grotesk',sans-serif; font-size:11.5px; font-weight:700; color:var(--bpv2-text-1); margin-bottom:10px;}
  .bpv2-sf-card{background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:10px; overflow:hidden;}
  .bpv2-sf-card-img{height:74px; background:linear-gradient(135deg,#1e293b,#334155); display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,0.3); font-size:22px;}
  .bpv2-sf-card-body{padding:10px;}
  .bpv2-sf-card-name{font-family:'Space Grotesk',sans-serif; font-size:12px; font-weight:700; color:var(--bpv2-text-1);}
  .bpv2-sf-card-price{font-family:'Space Grotesk',sans-serif; font-size:14px; font-weight:800; color:var(--bpv2-amber-deep); margin-top:2px;}

  /* ───────── Commission calculator ───────── */
  .bpv2-calc-card{background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:16px; padding:1.8rem; margin-top:0.4rem;}
  .bpv2-calc-controls{display:grid; grid-template-columns:1fr 1fr; gap:28px; margin-bottom:1.4rem;}
  .bpv2-calc-field label{display:block; font-family:'IBM Plex Mono',monospace; font-size:10.5px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:var(--bpv2-text-3); margin-bottom:10px;}
  .bpv2-calc-field input[type="range"]{width:100%; accent-color:var(--bpv2-amber-deep);}
  .bpv2-calc-range-labels{display:flex; justify-content:space-between; font-size:11px; color:var(--bpv2-text-3); margin-top:4px;}
  .bpv2-calc-value{font-family:'Space Grotesk',sans-serif; font-size:19px; font-weight:700; color:var(--bpv2-ink); margin-top:8px;}
  .bpv2-calc-toggle{display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:1rem;}
  .bpv2-calc-radio{display:flex; align-items:center; gap:8px; padding:11px 15px; background:var(--bpv2-paper); border:1px solid var(--bpv2-line); border-radius:9px; cursor:pointer; font-size:13px; color:var(--bpv2-text-1);}
  .bpv2-calc-radio input{accent-color:var(--bpv2-amber-deep);}
  .bpv2-calc-deals-wrap{margin-bottom:1.4rem;}
  .bpv2-calc-deals-wrap input[type="range"]{width:100%; accent-color:var(--bpv2-amber-deep);}
  .bpv2-calc-results{display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:0.9rem;}
  .bpv2-calc-result{background:var(--bpv2-paper); border:1px solid var(--bpv2-line); border-radius:12px; padding:14px; text-align:center;}
  .bpv2-calc-result span{display:block; font-size:11px; color:var(--bpv2-text-3); margin-bottom:6px;}
  .bpv2-calc-result strong{font-family:'Space Grotesk',sans-serif; font-size:18px; font-weight:700; color:var(--bpv2-ink);}
  .bpv2-calc-result strong.bpv2-calc-neg{color:#C0392B;}
  .bpv2-calc-result.bpv2-calc-highlight{background:var(--bpv2-ink); border-color:var(--bpv2-ink);}
  .bpv2-calc-result.bpv2-calc-highlight span{color:rgba(255,255,255,0.55);}
  .bpv2-calc-result.bpv2-calc-highlight strong{color:var(--bpv2-amber);}
  .bpv2-calc-note{font-size:11px; color:var(--bpv2-text-3); text-align:center;}

  /* ───────── Cost table ───────── */
  .bpv2-cost-table{width:100%; border:1px solid var(--bpv2-line); border-radius:12px; overflow:hidden; margin-top:1.6rem; border-collapse:separate; border-spacing:0;}
  .bpv2-cost-table tr td{padding:13px 18px; font-size:13.5px; border-bottom:1px solid var(--bpv2-line);}
  .bpv2-cost-table tr:last-child td{border-bottom:none;}
  .bpv2-cost-table tr:nth-child(odd) td{background:var(--bpv2-paper);}
  .bpv2-cost-table tr:nth-child(even) td{background:var(--bpv2-surface);}
  .bpv2-cost-label{color:var(--bpv2-text-2); width:48%;}
  .bpv2-cost-val{color:var(--bpv2-ink); font-weight:600;}
  .bpv2-cost-val.bpv2-green{color:var(--bpv2-green);}

  /* ───────── Who grid ───────── */
  .bpv2-who-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:1.6rem;}
  .bpv2-who-chip{background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:10px; padding:0.9rem 1rem; text-align:center;}
  .bpv2-w-name{font-size:13px; font-weight:600; color:var(--bpv2-ink);}

  /* ───────── Trust pills ───────── */
  .bpv2-trust-strip{display:flex; gap:8px; flex-wrap:wrap; margin-top:1.5rem; margin-bottom:1rem;}
  .bpv2-trust-pill{display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600; color:var(--bpv2-green); background:var(--bpv2-green-tint); border:1px solid var(--bpv2-green-line); border-radius:20px; padding:6px 12px;}
  .bpv2-trust-pill svg{width:12px; height:12px; stroke:var(--bpv2-green); fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0;}
  .bpv2-attr-note{font-size:13px; color:var(--bpv2-text-2); line-height:1.75; margin-top:1rem; background:var(--bpv2-surface); border:1px solid var(--bpv2-line); border-radius:10px; padding:1.1rem 1.3rem;}

  /* ───────── FAQ ───────── */
  .bpv2-faq-list{margin-top:1.6rem;}
  .bpv2-faq-item{border:1px solid var(--bpv2-line); border-radius:12px; margin-bottom:8px; background:var(--bpv2-paper); overflow:hidden;}
  .bpv2-faq-item summary{padding:16px 18px; font-size:14px; font-weight:600; color:var(--bpv2-ink); cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; gap:12px; user-select:none;}
  .bpv2-faq-item summary::-webkit-details-marker{display:none;}
  .bpv2-faq-icon{font-size:11px; color:var(--bpv2-text-3); flex-shrink:0; transition:transform .2s ease;}
  .bpv2-faq-item[open] .bpv2-faq-icon{transform:rotate(45deg); color:var(--bpv2-amber-deep);}
  .bpv2-faq-item[open]{border-color:#F4D9A6;}
  .bpv2-faq-answer{padding:0 18px 16px; font-size:13px; color:var(--bpv2-text-2); line-height:1.75; border-top:1px solid var(--bpv2-line);}

  /* ───────── Cross-links ───────── */
  .bpv2-cross-grid{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
  .bpv2-cross-card{display:flex; gap:14px; padding:18px; background:var(--bpv2-paper); border:1px solid var(--bpv2-line); border-radius:14px; text-decoration:none; box-shadow:0 1px 2px rgba(11,18,32,.04); transition:box-shadow .18s;}
  .bpv2-cross-card:hover{box-shadow:0 8px 20px rgba(11,18,32,.08); text-decoration:none;}
  .bpv2-cross-icon{width:40px; height:40px; border-radius:10px; background:var(--bpv2-surface); display:flex; align-items:center; justify-content:center; color:var(--bpv2-amber-deep); font-size:15px; flex-shrink:0;}
  .bpv2-cross-title{font-size:13.5px; font-weight:700; color:var(--bpv2-ink); margin-bottom:3px; font-family:'Space Grotesk',sans-serif;}
  .bpv2-cross-desc{font-size:12px; color:var(--bpv2-text-2); line-height:1.5;}

  /* ───────── CTA ───────── */
  .bpv2-cta-band{background:var(--bpv2-ink); color:#fff; padding:3.4rem 0;}
  .bpv2-cta-inner{background:linear-gradient(135deg, var(--bpv2-ink-soft) 0%, var(--bpv2-ink) 100%); border:1px solid var(--bpv2-ink-line); border-radius:18px; padding:2.4rem;}
  .bpv2-cta-title{font-size:22px; font-weight:600; color:#fff; margin-bottom:0.7rem; letter-spacing:-0.01em; font-family:'Space Grotesk',sans-serif;}
  .bpv2-cta-body{font-size:14px; color:rgba(255,255,255,0.68); line-height:1.75; margin-bottom:1.6rem; max-width:520px;}
  .bpv2-cta-actions{display:flex; gap:10px; flex-wrap:wrap;}

  /* ───────── Responsive ───────── */
  @media (max-width:960px){
    .bpv2-hero-grid{grid-template-columns:1fr; gap:32px;}
    .bpv2-hero-visual{order:2;}
    .bpv2-stage svg{max-width:460px;}
    .bpv2-split-grid{grid-template-columns:1fr; gap:32px;}
    .bpv2-split-visual{order:2;}
  }
  @media (max-width:680px){
    .bpv2-wrap{padding:0 1.25rem;}
    .bpv2-hero-copy h1{font-size:27px;}
    .bpv2-barrier-grid{grid-template-columns:1fr;}
    .bpv2-feature-grid{grid-template-columns:1fr;}
    .bpv2-nb-grid{grid-template-columns:1fr;}
    .bpv2-who-grid{grid-template-columns:1fr 1fr;}
    .bpv2-cta-inner{padding:1.8rem;}
    .bpv2-band h2,.bpv2-title{font-size:21px;}
    .bpv2-cross-grid{grid-template-columns:1fr;}
    .bpv2-calc-controls{grid-template-columns:1fr; gap:18px;}
    .bpv2-calc-toggle{grid-template-columns:1fr;}
    .bpv2-calc-results{grid-template-columns:1fr;}
  }

  @media print{
    .bpv2-hero,.bpv2-band,.bpv2-cta-band{background:var(--bpv2-ink) !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;}
  }
</style>

<?php
$extraCss = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">';

$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
