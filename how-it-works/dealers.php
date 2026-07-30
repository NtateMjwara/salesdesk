<?php
/**
 * SalesDesk — How It Works: Dealers
 * Route: /how-it-works/dealers.php
 *
 * Repositioned pitch (v2): SalesDesk as distribution infrastructure —
 * one dealership, distributed across a network of independent promoter
 * storefronts — rather than a plain "referral platform" explainer.
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

$pageTitle     = 'How It Works for Dealers | SalesDesk';
$ogTitle       = 'Turn One Dealership Into Thousands of Vehicle Marketplaces | SalesDesk';
$ogDescription = 'SalesDesk turns your inventory into a network-distributed marketplace. Approved promoters market your vehicles through their own audiences — you only pay commission when a vehicle sells.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/how-it-works/dealers.php';
$layoutVariant  = 'wide';
$showBreadcrumb = true;
$breadcrumbs    = [
    ['How It Works', null],
    ['For Dealers',  null],
];

$defaultFee = 10;
try {
    require_once '../includes/database.php';
    require_once '../includes/functions.php';
    $defaultFee = getPlatformConfigInt('platform_fee_percent', 10);
} catch (Throwable) {}

ob_start();
?>

<div class="dpv2-page">

<!-- ═══════════════════════════════════════════════════════
     ROLE SWITCHER (sitewide pattern — unchanged colours)
     ═══════════════════════════════════════════════════════ --
<div class="dpv2-wrap" style="padding-top:1.6rem;">
  <div style="display:flex;gap:8px;justify-content:center;margin-bottom:0;flex-wrap:wrap;">
    <a href="/how-it-works/brokers.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
              background:#fff;color:#64748b;border:1.5px solid #e2e8f0;
              border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;
              transition:all .18s;font-family:'Space Grotesk',sans-serif;"
       onmouseover="this.style.borderColor='#0f4c9e';this.style.color='#0f4c9e'"
       onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#64748b'">
      <i class="fa-solid fa-id-card"></i> Brokers
    </a>
    <a href="/how-it-works/dealers.php"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
              background:#15803d;color:#fff;border-radius:999px;
              font-size:13px;font-weight:700;text-decoration:none;font-family:'Space Grotesk',sans-serif;">
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
     HERO — row on desktop (text left / network right),
     stacks to a column on tablet and mobile.
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-hero">
  <div class="dpv2-wrap">
    <div class="dpv2-hero-grid">

      <div class="dpv2-hero-copy">
        <div class="dpv2-eyebrow"><span class="dpv2-dot"></span>For dealerships</div>
        <h1>Turn one dealership into <em>thousands</em> of vehicle marketplaces.</h1>
        <p class="dpv2-hero-sub">
          Your inventory shouldn't live on one website. SalesDesk lets every approved promoter — brokers, agents, creators, and everyday people with real networks — turn your stock into their own digital showroom. You stay in control of every sale. You pay only when one closes.
        </p>

        <div class="dpv2-stat-strip">
          <span><b>1</b> dealership</span>
          <span class="dpv2-arrow">→</span>
          <span><b>5,000+</b> promoter storefronts</span>
          <span class="dpv2-arrow">→</span>
          <span><b>50,000+</b> social storefronts</span>
        </div>

        <div class="dpv2-hero-actions">
          <a href="/auth/register.php" class="dpv2-btn dpv2-btn-primary">
            <i class="fa-solid fa-store"></i> List your inventory
          </a>
          <a href="/dealers.php" class="dpv2-btn dpv2-btn-ghost-dark">
            Learn more
          </a>
        </div>
      </div>

      <div class="dpv2-hero-visual">
        <div class="dpv2-stage">
          <svg viewBox="0 0 620 620" xmlns="http://www.w3.org/2000/svg">
            <!-- faint scatter suggesting scale beyond the labeled nodes -->
            <g fill="#F2A93B" opacity="0.18">
              <circle cx="420" cy="140" r="2.5"/><circle cx="470" cy="90" r="2"/><circle cx="540" cy="150" r="2.5"/>
              <circle cx="565" cy="230" r="2"/><circle cx="580" cy="320" r="2.5"/><circle cx="560" cy="450" r="2"/>
              <circle cx="500" cy="500" r="2.5"/><circle cx="430" cy="470" r="2"/><circle cx="360" cy="430" r="2.5"/>
              <circle cx="330" cy="380" r="2"/><circle cx="470" cy="300" r="2"/><circle cx="500" cy="380" r="2.5"/>
              <circle cx="400" cy="200" r="2"/><circle cx="330" cy="220" r="2.5"/>
            </g>

            <!-- connecting lines from hub -->
            <g stroke="#F2A93B" stroke-width="1.3" opacity="0.4">
              <line x1="130" y1="310" x2="330" y2="70"/>
              <line x1="130" y1="310" x2="460" y2="40"/>
              <line x1="130" y1="310" x2="580" y2="110"/>
              <line x1="130" y1="310" x2="600" y2="260"/>
              <line x1="130" y1="310" x2="595" y2="400"/>
              <line x1="130" y1="310" x2="520" y2="530"/>
              <line x1="130" y1="310" x2="380" y2="580"/>
              <line x1="130" y1="310" x2="280" y2="520"/>
              <line x1="130" y1="310" x2="260" y2="340"/>
            </g>

            <!-- labeled promoter nodes -->
            <g font-family="IBM Plex Mono, monospace" font-size="12.5" fill="#EDEFF3">
              <circle cx="330" cy="70" r="5" fill="#F2A93B"/>
              <text x="342" y="66">Finance broker</text>

              <circle cx="460" cy="40" r="5" fill="#F2A93B"/>
              <text x="472" y="36">Property agent</text>

              <circle cx="580" cy="110" r="5" fill="#F2A93B"/>
              <text x="480" y="106">Fleet consultant</text>

              <circle cx="600" cy="260" r="5" fill="#F2A93B"/>
              <text x="480" y="264">TikTok creator</text>

              <circle cx="595" cy="400" r="5" fill="#F2A93B"/>
              <text x="460" y="416">WhatsApp community</text>

              <circle cx="520" cy="530" r="5" fill="#F2A93B"/>
              <text x="360" y="550">Facebook automotive page</text>

              <circle cx="380" cy="580" r="5" fill="#F2A93B"/>
              <text x="260" y="602">Insurance advisor</text>

              <circle cx="280" cy="520" r="5" fill="#F2A93B"/>
              <text x="150" y="540">Local influencer</text>

              <circle cx="260" cy="340" r="5" fill="#F2A93B"/>
              <text x="150" y="332">Everyday referrer</text>
            </g>

            <!-- hub -->
            <circle cx="130" cy="310" r="48" fill="#0B1220" stroke="#F2A93B" stroke-width="2"/>
            <circle cx="130" cy="310" r="48" fill="none" stroke="#F2A93B" stroke-width="1" opacity="0.35">
              <animate attributeName="r" values="48;66;48" dur="3.2s" repeatCount="indefinite"/>
              <animate attributeName="opacity" values="0.35;0;0.35" dur="3.2s" repeatCount="indefinite"/>
            </circle>
            <text x="130" y="304" text-anchor="middle" font-family="IBM Plex Mono, monospace" font-size="10.5" fill="#F2A93B" letter-spacing="0.5">YOUR</text>
            <text x="130" y="320" text-anchor="middle" font-family="IBM Plex Mono, monospace" font-size="10.5" fill="#F2A93B" letter-spacing="0.5">INVENTORY</text>
          </svg>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════
     THE LIMITATION
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-band">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker">The limitation every dealer already knows</div>
    <h2>You wait for buyers to come to you.</h2>
    <p>
      Every dealership has a website. Many advertise on paid platforms. Some spend thousands every month on Facebook and Google. All of it shares the same weakness — it's a single destination, competing against every other dealer on the same platform. When the budget stops, so does the visibility.
    </p>
    <div class="dpv2-channels">
      <span class="dpv2-strike">Your website</span>
      <span class="dpv2-strike">Paid Platform</span>
      <span class="dpv2-strike">Other Paid Platforms</span>
      <span class="dpv2-strike">Facebook ads</span>
      <span class="dpv2-strike">Google ads</span>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════
     COMPARISON
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">The difference</div>
    <h2 class="dpv2-title">One marketplace vs. thousands, running on the same stock.</h2>
    <div class="dpv2-compare-grid">
      <div class="dpv2-compare-card dpv2-trad">
        <span class="dpv2-compare-label">Traditional advertising</span>
        <h3>One marketplace</h3>
        <ul class="dpv2-compare-list">
          <li>You pay to place vehicles where buyers might be looking</li>
          <li>You compete with every other dealer on the platform</li>
          <li>Visibility stops the moment budget stops</li>
        </ul>
        <div class="dpv2-single-dot"><span></span></div>
      </div>
      <div class="dpv2-compare-card dpv2-sd">
        <span class="dpv2-compare-label">SalesDesk</span>
        <h3>Thousands of marketplaces</h3>
        <ul class="dpv2-compare-list">
          <li>Upload your inventory once, to your own dealership account</li>
          <li>Every approved promoter builds their own showroom from it</li>
          <li>You pay a commission only when a vehicle actually sells</li>
        </ul>
        <div class="dpv2-many-dots">
          <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
      </div>
    </div>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     IMAGINE THIS
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">Imagine this</div>
    <h2 class="dpv2-title">One vehicle, appearing everywhere your next buyer already is.</h2>
    <p class="dpv2-lead">Every promoter markets under their own name, to people who already trust them. Every one of them is another opportunity to sell the exact same vehicle.</p>
    <div class="dpv2-chip-field">
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>Finance broker's client network</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>Property agent's buyers</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>Fleet consultant's contacts</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>Insurance advisor's customers</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>TikTok creator's audience</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>Facebook automotive page</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>WhatsApp community</span>
      <span class="dpv2-chip"><span class="dpv2-chip-dot"></span>Local influencer's followers</span>
      <span class="dpv2-chip dpv2-ghost">+ hundreds of everyday referrers</span>
    </div>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     HOW IT WORKS
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">How it works</div>
    <h2 class="dpv2-title">Six steps. No extra work on your side.</h2>
    <div class="dpv2-flow-list">
      <div class="dpv2-flow-step">
        <div class="dpv2-step-num">1</div>
        <div class="dpv2-step-body"><strong>Upload your inventory</strong><span>List your vehicles exactly as you already do. No new process, no extra data entry.</span></div>
      </div>
      <div class="dpv2-flow-step">
        <div class="dpv2-step-num">2</div>
        <div class="dpv2-step-body"><strong>Set your commission</strong><span>A fixed rand amount or a percentage of the sale price — you decide what a successful sale is worth.</span></div>
      </div>
      <div class="dpv2-flow-step">
        <div class="dpv2-step-num">3</div>
        <div class="dpv2-step-body"><strong>SalesDesk activates the network</strong><span>Approved promoters browse participating dealership inventory and choose which vehicles to market.</span></div>
      </div>
      <div class="dpv2-flow-step">
        <div class="dpv2-step-num">4</div>
        <div class="dpv2-step-body"><strong>Promoters create real demand</strong><span>Shared through trusted networks — not random advertising. Real relationships, real communities, real audiences.</span></div>
      </div>
      <div class="dpv2-flow-step">
        <div class="dpv2-step-num">5</div>
        <div class="dpv2-step-body"><strong>You close the sale</strong><span>The customer buys directly from your dealership. Business continues exactly as it does today.</span></div>
      </div>
      <div class="dpv2-flow-step">
        <div class="dpv2-step-num">6</div>
        <div class="dpv2-step-body"><strong>Pay only when you sell</strong><span>No subscriptions, no advertising invoices, no monthly commitments — only a commission on a completed sale.</span></div>
      </div>
    </div>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     THREE PRODUCTS / LAYERS
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">Why this changes everything</div>
    <h2 class="dpv2-title">It isn't a referral platform. Referrals are the thinnest layer.</h2>
    <div class="dpv2-layer-stack">
      <div class="dpv2-layer dpv2-l1">
        <div class="dpv2-l-left">
          <span class="dpv2-l-tag dpv2-mono">LAYER 1</span>
          <span class="dpv2-l-name">Marketplace Infrastructure</span>
        </div>
        <span class="dpv2-l-desc">Publish your inventory once</span>
      </div>
      <div class="dpv2-layer dpv2-l2">
        <div class="dpv2-l-left">
          <span class="dpv2-l-tag dpv2-mono">LAYER 2</span>
          <span class="dpv2-l-name">Distribution Network</span>
        </div>
        <span class="dpv2-l-desc">Thousands of independent people distributing it</span>
      </div>
      <div class="dpv2-layer dpv2-l3">
        <div class="dpv2-l-left">
          <span class="dpv2-l-tag dpv2-mono">LAYER 3</span>
          <span class="dpv2-l-name">Performance Commerce</span>
        </div>
        <span class="dpv2-l-desc">Pay only when it sells</span>
      </div>
    </div>
    <p class="dpv2-layer-caption">Notice where the commission sits — it's the incentive layer that powers the network, not the product itself. The infrastructure and the distribution are the real value.</p>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     TRUST / PERFORMANCE
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section-tight">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">Built for performance</div>
    <h2 class="dpv2-title" style="font-size:21px;">Every enquiry attributed. Every sale accounted for.</h2>
    <div class="dpv2-trust-strip">
      <span class="dpv2-trust-pill"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Attribution locked at point of enquiry</span>
      <span class="dpv2-trust-pill"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>POPIA compliant</span>
      <span class="dpv2-trust-pill"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Full audit trail per lead</span>
      <span class="dpv2-trust-pill"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>CIPC-verified dealer badge</span>
    </div>
    <div class="dpv2-attr-note">
      Every referral is tracked from the first click and timestamped to the promoter who introduced the buyer. That attribution cannot be displaced — not by a walk-in, not by your own sales team, not by another promoter sharing the same vehicle — creating a clear, auditable record for every transaction and eliminating commission disputes before they start.
    </div>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     COST
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section-tight">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">What it will cost you</div>
    <table class="dpv2-cost-table">
      <tr><td class="dpv2-cost-label">Commission per vehicle</td><td class="dpv2-cost-val">You set this — typically R2,500–R8,000 fixed, or 1%–3% of sale price</td></tr>
      <tr><td class="dpv2-cost-label">Platform fee</td><td class="dpv2-cost-val"><?= $defaultFee ?>% deducted from the promoter's commission at payout — not charged to your dealership</td></tr>
      <tr><td class="dpv2-cost-label">Upfront advertising spend</td><td class="dpv2-cost-val dpv2-green">R0 — nothing until a vehicle sells</td></tr>
      <tr><td class="dpv2-cost-label">Setup and onboarding</td><td class="dpv2-cost-val dpv2-green">Free — less than 30 minutes to go live</td></tr>
    </table>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     FAQ
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section">
  <div class="dpv2-wrap">
    <div class="dpv2-kicker-light">Common questions</div>
    <h2 class="dpv2-title">Dealer FAQ</h2>

    <div class="dpv2-faq-list">
      <?php
      $faqs = [
        [
          'Can I control which promoters market my vehicles?',
          'Not individually — any approved, verified promoter can add any active listing to their own storefront. This open model is deliberate: it\'s what turns one dealership into thousands of independent marketplaces. If you have a concern about a specific promoter\'s conduct, our admin team can investigate and restrict their access.'
        ],
        [
          'What stops two promoters fighting over the same buyer?',
          'Attribution goes to whichever promoter\'s tracked link the buyer clicked first, and it locks the moment an enquiry is submitted. A second promoter sharing the same car afterwards does not, and cannot, override an existing attribution — first click, first credit, permanently.'
        ],
        [
          'What if the buyer already knew about my dealership before a promoter shared it?',
          'Attribution is based on the tracked link used to submit the enquiry, not prior awareness of your brand. If the enquiry came through a promoter\'s link, that referral belongs to them — regardless of whether the buyer had heard of your dealership before.'
        ],
        [
          'Does this replace my existing advertising on Cars.co.za, AutoTrader, or my own website?',
          'No — SalesDesk runs alongside whatever you already do. It adds a distribution layer on top of your existing channels rather than competing with them. Most dealers keep their other listings running and treat the promoter network as additional, commission-only reach.'
        ],
        [
          'What does "verified promoter" mean?',
          'Every promoter completes a profile verification step before they can add vehicles to a storefront. This confirms their identity and contact details and gives them a track record visible on their public page — enquiries, listings, and closed deals — which buyers and dealers can both see.'
        ],
        [
          'Who handles the test drive, financing, and paperwork?',
          'You do, exactly as you do today. Promoters generate and market the enquiry; your dealership handles the actual transaction — test drives, finance applications, trade-ins, delivery, and documentation. SalesDesk never inserts itself into the sale itself.'
        ],
        [
          'What happens if a deal falls through after I\'ve marked it closed?',
          'Contact our admin team as soon as possible. Commission records can be reversed before payment is processed. Once EFT is sent to the promoter, the transaction is treated as final — the commission is a cost of the sale.'
        ],
        [
          'Can my existing sales executives work alongside the promoter network?',
          'Yes. Your sales executives keep their own logins, upload inventory, and manage their own lead pipeline exactly as before. Promoter-driven leads and exec-driven leads sit in the same dashboard, each attributed to whoever actually sourced the enquiry.'
        ],
      ];
      foreach ($faqs as [$q, $a]):
      ?>
      <details class="dpv2-faq-item">
        <summary>
          <span><?= htmlspecialchars($q) ?></span>
          <i class="fa-solid fa-plus dpv2-faq-icon"></i>
        </summary>
        <div class="dpv2-faq-answer"><?= $a ?></div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<hr class="dpv2-divider">

<!-- ═══════════════════════════════════════════════════════
     CROSS-LINKS
     ═══════════════════════════════════════════════════════ -->
<section class="dpv2-section-tight">
  <div class="dpv2-wrap">
    <div class="dpv2-cross-grid">
      <a href="/how-it-works/brokers.php" class="dpv2-cross-card">
        <div class="dpv2-cross-icon"><i class="fa-solid fa-id-card"></i></div>
        <div>
          <div class="dpv2-cross-title">How it works for brokers</div>
          <div class="dpv2-cross-desc">Understand the promoter network that drives your leads.</div>
        </div>
      </a>
      <a href="/how-it-works/sales-exec.php" class="dpv2-cross-card">
        <div class="dpv2-cross-icon"><i class="fa-solid fa-user-tie"></i></div>
        <div>
          <div class="dpv2-cross-title">How it works for sales execs</div>
          <div class="dpv2-cross-desc">See how your floor staff use their SalesDesk login.</div>
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
<section class="dpv2-cta-band">
  <div class="dpv2-wrap">
    <div class="dpv2-cta-inner">
      <div class="dpv2-kicker-light dpv2-mono">Ready when you are</div>
      <div class="dpv2-cta-title">Turn your inventory into a national sales network.</div>
      <div class="dpv2-cta-body">
        Upload your inventory once. Activate thousands of potential promoters. There's no advertising spend, no subscription, and no risk — only a commission when a vehicle sells through SalesDesk.
      </div>
      <div class="dpv2-cta-actions">
        <a href="/auth/register.php" class="dpv2-btn dpv2-btn-primary">
          <i class="fa-solid fa-store"></i> List your inventory
        </a>
        <a href="/auth/login.php" class="dpv2-btn dpv2-btn-ghost">
          Sign in to your dealer portal
        </a>
      </div>
    </div>
  </div>
</section>

</div><!-- /dpv2-page -->

<style>
  .dpv2-page{
    --dpv2-ink:        #0B1220;
    --dpv2-ink-soft:   #16213A;
    --dpv2-ink-line:   rgba(255,255,255,0.10);
    --dpv2-paper:      #FFFFFF;
    --dpv2-surface:    #F4F5F8;
    --dpv2-line:       #E3E6EC;
    --dpv2-text-1:     #0F1620;
    --dpv2-text-2:     #59616D;
    --dpv2-text-3:     #93989F;
    --dpv2-amber:      #F2A93B;
    --dpv2-amber-deep: #D9800F;
    --dpv2-green:      #1E8E5A;
    --dpv2-green-tint: #EAF7EF;
    --dpv2-green-line: #C8ECD7;
    --dpv2-radius:     14px;

    font-family:'Inter',system-ui,sans-serif;
    color:var(--dpv2-text-1);
    background:var(--dpv2-paper);
    line-height:1.6;
    font-size:16px;
  }
  .dpv2-page *,.dpv2-page *::before,.dpv2-page *::after{box-sizing:border-box;}
  .dpv2-page h1,.dpv2-page h2,.dpv2-page h3{font-family:'Space Grotesk',sans-serif; letter-spacing:-0.01em; margin:0;}
  .dpv2-page p{margin:0;}
  .dpv2-page ul{list-style:none;}
  .dpv2-mono{font-family:'IBM Plex Mono',monospace; letter-spacing:0.02em;}
  .dpv2-page a{color:inherit;}

  .dpv2-wrap{max-width:1080px; margin:0 auto; padding:0 2rem;}
  .dpv2-page section{position:relative;}

  /* ───────── Hero ───────── */
  .dpv2-hero{background:var(--dpv2-ink); color:#fff; padding:2.4rem 0 3.2rem; overflow:hidden; margin-top:1.6rem;}
  .dpv2-hero-grid{display:grid; grid-template-columns:1.05fr 1fr; gap:48px; align-items:center;}
  .dpv2-eyebrow{display:inline-flex; align-items:center; gap:8px; font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.14em; text-transform:uppercase; color:var(--dpv2-amber); margin-bottom:1.3rem;}
  .dpv2-eyebrow .dpv2-dot{width:6px; height:6px; border-radius:50%; background:var(--dpv2-amber); display:inline-block;}
  .dpv2-hero-copy h1{font-size:38px; font-weight:600; line-height:1.14; letter-spacing:-0.02em; margin-bottom:1.2rem;}
  .dpv2-hero-copy h1 em{font-style:normal; color:var(--dpv2-amber);}
  .dpv2-hero-sub{font-size:15.5px; color:rgba(255,255,255,0.72); line-height:1.7; margin-bottom:1.8rem; max-width:520px;}
  .dpv2-stat-strip{display:flex; align-items:center; flex-wrap:wrap; gap:8px 0; font-family:'IBM Plex Mono',monospace; font-size:12px; color:rgba(255,255,255,0.55); margin-bottom:1.8rem;}
  .dpv2-stat-strip b{color:#fff; font-weight:600;}
  .dpv2-stat-strip .dpv2-arrow{color:var(--dpv2-amber); margin:0 10px; font-weight:600;}
  .dpv2-hero-actions{display:flex; gap:10px; flex-wrap:wrap;}

  .dpv2-hero-visual{width:100%;}
  .dpv2-stage svg{width:100%; height:auto; display:block; max-width:480px; margin:0 auto;}

  /* ───────── Buttons ───────── */
  .dpv2-btn{display:inline-flex; align-items:center; gap:8px; padding:11px 22px; border-radius:9px; font-size:13.5px; font-weight:600; font-family:'Inter',sans-serif; cursor:pointer; text-decoration:none; transition:opacity .15s, transform .15s;}
  .dpv2-btn:hover{opacity:0.9; transform:translateY(-1px); text-decoration:none;}
  .dpv2-btn-primary{background:var(--dpv2-amber); color:var(--dpv2-ink); border:none;}
  .dpv2-btn-ghost{background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.28);}
  .dpv2-btn-ghost-dark{background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.28);}

  /* ───────── Ink bands ───────── */
  .dpv2-band{background:var(--dpv2-ink-soft); color:#fff; padding:3.2rem 0;}
  .dpv2-kicker{font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.14em; text-transform:uppercase; color:rgba(255,255,255,0.45); margin-bottom:0.9rem;}
  .dpv2-band h2{font-size:25px; font-weight:600; color:#fff; max-width:560px; margin-bottom:1rem;}
  .dpv2-band p{font-size:15px; color:rgba(255,255,255,0.68); max-width:580px; line-height:1.75;}
  .dpv2-channels{display:flex; flex-wrap:wrap; gap:8px; margin-top:1.4rem;}
  .dpv2-channels span{font-size:12px; font-family:'IBM Plex Mono',monospace; color:rgba(255,255,255,0.5); border:1px solid var(--dpv2-ink-line); border-radius:20px; padding:5px 12px;}
  .dpv2-strike{text-decoration:line-through; text-decoration-color:rgba(255,255,255,0.3);}

  /* ───────── Generic sections ───────── */
  .dpv2-section{padding:3.4rem 0;}
  .dpv2-section-tight{padding:2.6rem 0;}
  .dpv2-kicker-light{font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.14em; text-transform:uppercase; color:var(--dpv2-text-3); margin-bottom:0.9rem;}
  .dpv2-title{font-size:25px; font-weight:600; color:var(--dpv2-ink); max-width:620px; margin-bottom:0.9rem;}
  .dpv2-lead{font-size:15px; color:var(--dpv2-text-2); max-width:640px; line-height:1.75; margin-bottom:1.6rem; display:block;}
  .dpv2-divider{border:none; border-top:1px solid var(--dpv2-line); max-width:1080px; margin:0 auto;}

  /* ───────── Comparison ───────── */
  .dpv2-compare-grid{display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:1.6rem;}
  .dpv2-compare-card{border-radius:var(--dpv2-radius); padding:1.5rem 1.4rem;}
  .dpv2-compare-card.dpv2-trad{background:var(--dpv2-surface); border:1px solid var(--dpv2-line);}
  .dpv2-compare-card.dpv2-sd{background:var(--dpv2-ink); color:#fff;}
  .dpv2-compare-label{font-family:'IBM Plex Mono',monospace; font-size:10.5px; letter-spacing:0.1em; text-transform:uppercase; margin-bottom:1rem; display:block;}
  .dpv2-trad .dpv2-compare-label{color:var(--dpv2-text-3);}
  .dpv2-sd .dpv2-compare-label{color:var(--dpv2-amber);}
  .dpv2-compare-card h3{font-size:17px; margin-bottom:0.9rem;}
  .dpv2-trad h3{color:var(--dpv2-ink);}
  .dpv2-sd h3{color:#fff;}
  .dpv2-compare-list{font-size:13.5px; line-height:1.65;}
  .dpv2-trad .dpv2-compare-list li{color:var(--dpv2-text-2); padding:5px 0;}
  .dpv2-sd .dpv2-compare-list li{color:rgba(255,255,255,0.78); padding:5px 0;}
  .dpv2-compare-list li::before{content:"—"; margin-right:8px; opacity:0.5;}
  .dpv2-single-dot,.dpv2-many-dots{margin-top:1.1rem; height:26px;}
  .dpv2-single-dot span{display:inline-block; width:10px; height:10px; border-radius:50%; background:var(--dpv2-text-3);}
  .dpv2-many-dots span{display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--dpv2-amber); margin-right:6px;}

  /* ───────── Chips ───────── */
  .dpv2-chip-field{display:flex; flex-wrap:wrap; gap:10px; margin-top:1.6rem;}
  .dpv2-chip{display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:500; color:var(--dpv2-ink); background:var(--dpv2-surface); border:1px solid var(--dpv2-line); border-radius:24px; padding:9px 15px;}
  .dpv2-chip .dpv2-chip-dot{width:6px; height:6px; border-radius:50%; background:var(--dpv2-amber-deep); flex-shrink:0;}
  .dpv2-chip.dpv2-ghost{color:var(--dpv2-text-3); border-style:dashed; background:transparent;}

  /* ───────── Steps ───────── */
  .dpv2-flow-list{display:flex; flex-direction:column; margin-top:1.6rem;}
  .dpv2-flow-step{display:flex; align-items:flex-start; gap:18px; padding:1.15rem 0; border-bottom:1px solid var(--dpv2-line);}
  .dpv2-flow-step:last-child{border-bottom:none;}
  .dpv2-step-num{font-family:'IBM Plex Mono',monospace; font-size:12px; font-weight:600; width:32px; height:32px; border-radius:50%; background:var(--dpv2-ink); color:var(--dpv2-amber); display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px;}
  .dpv2-step-body strong{display:block; font-size:14.5px; font-weight:600; color:var(--dpv2-ink); margin-bottom:3px;}
  .dpv2-step-body span{font-size:13.5px; color:var(--dpv2-text-2); line-height:1.65;}

  /* ───────── Layer diagram ───────── */
  .dpv2-layer-stack{margin-top:1.8rem; display:flex; flex-direction:column-reverse; gap:6px;}
  .dpv2-layer{border-radius:10px; padding:1rem 1.3rem; display:flex; align-items:center; justify-content:space-between; gap:16px;}
  .dpv2-l-left{display:flex; align-items:center; gap:12px;}
  .dpv2-l-tag{font-family:'IBM Plex Mono',monospace; font-size:10.5px; letter-spacing:0.08em; padding:3px 8px; border-radius:5px; flex-shrink:0;}
  .dpv2-l-name{font-size:14.5px; font-weight:600;}
  .dpv2-l-desc{font-size:12.5px; opacity:0.8;}
  .dpv2-l1{background:var(--dpv2-ink); color:#fff; width:100%;}
  .dpv2-l1 .dpv2-l-tag{background:rgba(255,255,255,0.12); color:#fff;}
  .dpv2-l2{background:var(--dpv2-ink-soft); color:#fff; width:86%; align-self:center;}
  .dpv2-l2 .dpv2-l-tag{background:rgba(255,255,255,0.14); color:#fff;}
  .dpv2-l3{background:var(--dpv2-amber); color:var(--dpv2-ink); width:60%; align-self:center;}
  .dpv2-l3 .dpv2-l-tag{background:rgba(11,18,32,0.15); color:var(--dpv2-ink);}
  .dpv2-layer-caption{font-size:12.5px; color:var(--dpv2-text-3); margin-top:1rem; max-width:480px;}

  /* ───────── Trust pills ───────── */
  .dpv2-trust-strip{display:flex; gap:8px; flex-wrap:wrap; margin-top:1.5rem; margin-bottom:1rem;}
  .dpv2-trust-pill{display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600; color:var(--dpv2-green); background:var(--dpv2-green-tint); border:1px solid var(--dpv2-green-line); border-radius:20px; padding:6px 12px;}
  .dpv2-trust-pill svg{width:12px; height:12px; stroke:var(--dpv2-green); fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0;}
  .dpv2-attr-note{font-size:13px; color:var(--dpv2-text-2); line-height:1.75; margin-top:1rem; background:var(--dpv2-surface); border:1px solid var(--dpv2-line); border-radius:10px; padding:1.1rem 1.3rem;}

  /* ───────── Cost table ───────── */
  .dpv2-cost-table{width:100%; border:1px solid var(--dpv2-line); border-radius:12px; overflow:hidden; margin-top:1.6rem; border-collapse:separate; border-spacing:0;}
  .dpv2-cost-table tr td{padding:13px 18px; font-size:13.5px; border-bottom:1px solid var(--dpv2-line);}
  .dpv2-cost-table tr:last-child td{border-bottom:none;}
  .dpv2-cost-table tr:nth-child(odd) td{background:var(--dpv2-paper);}
  .dpv2-cost-table tr:nth-child(even) td{background:var(--dpv2-surface);}
  .dpv2-cost-label{color:var(--dpv2-text-2); width:44%;}
  .dpv2-cost-val{color:var(--dpv2-ink); font-weight:600;}
  .dpv2-cost-val.dpv2-green{color:var(--dpv2-green);}

  /* ───────── FAQ ───────── */
  .dpv2-faq-list{margin-top:1.6rem;}
  .dpv2-faq-item{border:1px solid var(--dpv2-line); border-radius:12px; margin-bottom:8px; background:var(--dpv2-paper); overflow:hidden;}
  .dpv2-faq-item summary{padding:16px 18px; font-size:14px; font-weight:600; color:var(--dpv2-ink); cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; gap:12px; user-select:none;}
  .dpv2-faq-item summary::-webkit-details-marker{display:none;}
  .dpv2-faq-icon{font-size:11px; color:var(--dpv2-text-3); flex-shrink:0; transition:transform .2s ease;}
  .dpv2-faq-item[open] .dpv2-faq-icon{transform:rotate(45deg); color:var(--dpv2-amber-deep);}
  .dpv2-faq-item[open]{border-color:#F4D9A6;}
  .dpv2-faq-answer{padding:0 18px 16px; font-size:13px; color:var(--dpv2-text-2); line-height:1.75; border-top:1px solid var(--dpv2-line);}

  /* ───────── Cross-links ───────── */
  .dpv2-cross-grid{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
  .dpv2-cross-card{display:flex; gap:14px; padding:18px; background:var(--dpv2-paper); border:1px solid var(--dpv2-line); border-radius:14px; text-decoration:none; box-shadow:0 1px 2px rgba(11,18,32,.04); transition:box-shadow .18s;}
  .dpv2-cross-card:hover{box-shadow:0 8px 20px rgba(11,18,32,.08); text-decoration:none;}
  .dpv2-cross-icon{width:40px; height:40px; border-radius:10px; background:var(--dpv2-surface); display:flex; align-items:center; justify-content:center; color:var(--dpv2-amber-deep); font-size:15px; flex-shrink:0;}
  .dpv2-cross-title{font-size:13.5px; font-weight:700; color:var(--dpv2-ink); margin-bottom:3px; font-family:'Space Grotesk',sans-serif;}
  .dpv2-cross-desc{font-size:12px; color:var(--dpv2-text-2); line-height:1.5;}

  /* ───────── CTA ───────── */
  .dpv2-cta-band{background:var(--dpv2-ink); color:#fff; padding:3.4rem 0;}
  .dpv2-cta-inner{background:linear-gradient(135deg, var(--dpv2-ink-soft) 0%, var(--dpv2-ink) 100%); border:1px solid var(--dpv2-ink-line); border-radius:18px; padding:2.4rem;}
  .dpv2-cta-title{font-size:22px; font-weight:600; color:#fff; margin-bottom:0.7rem; letter-spacing:-0.01em; font-family:'Space Grotesk',sans-serif;}
  .dpv2-cta-body{font-size:14px; color:rgba(255,255,255,0.68); line-height:1.75; margin-bottom:1.6rem; max-width:520px;}
  .dpv2-cta-actions{display:flex; gap:10px; flex-wrap:wrap;}

  /* ───────── Responsive ───────── */
  @media (max-width:960px){
    .dpv2-hero-grid{grid-template-columns:1fr; gap:32px;}
    .dpv2-hero-visual{order:2;}
    .dpv2-stage svg{max-width:420px;}
  }
  @media (max-width:680px){
    .dpv2-wrap{padding:0 1.25rem;}
    .dpv2-hero-copy h1{font-size:28px;}
    .dpv2-compare-grid{grid-template-columns:1fr;}
    .dpv2-l2,.dpv2-l3{width:100%;}
    .dpv2-cta-inner{padding:1.8rem;}
    .dpv2-band h2,.dpv2-title{font-size:21px;}
    .dpv2-cross-grid{grid-template-columns:1fr;}
  }

  @media print{
    .dpv2-hero,.dpv2-band,.dpv2-cta-band{background:var(--dpv2-ink) !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;}
  }
</style>

<?php
$extraCss = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">';

$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
