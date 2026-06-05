<?php
/**
 * SalesDesk — How It Works: Auto Brokers
 * Route: /how-it-works/brokers.php
 *
 * Informational page explaining the broker value proposition,
 * attribution model, commission mechanics, and sign-up flow.
 * No auth required.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';


applyCachePolicy('public');
$visitor = initVisitorSession();

$pageTitle     = 'How It Works for Brokers | SalesDesk';
$ogTitle       = 'Earn Commission Selling Cars — No Stock Needed | SalesDesk';
$ogDescription = 'Create your free SalesDesk, share tracked car links, and earn commission on every deal you close. No inventory, no dealership, no monthly fees.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/how-it-works/brokers.php';
$layoutVariant  = 'narrow';
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

<!-- ═══════════════════════════════════════════════════════
     ROLE SWITCHER
     ═══════════════════════════════════════════════════════ -->
<div style="display:flex;gap:8px;justify-content:center;margin-bottom:2rem;flex-wrap:wrap;"
     class="pub-anim">
  <a href="/how-it-works/brokers.php"
     style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
            background:var(--p);color:#fff;border-radius:var(--r-full);
            font-size:13px;font-weight:700;text-decoration:none;font-family:var(--font-d);">
    <i class="fa-solid fa-id-card"></i> Brokers
  </a>
  <a href="/how-it-works/dealers.php"
     style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
            background:var(--white);color:var(--muted);border:1.5px solid var(--border);
            border-radius:var(--r-full);font-size:13px;font-weight:600;text-decoration:none;
            transition:all .18s;font-family:var(--font-d);"
     onmouseover="this.style.borderColor='var(--p)';this.style.color='var(--p)'"
     onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
    <i class="fa-solid fa-building-user"></i> Dealers
  </a>
  <a href="/how-it-works/sales-exec.php"
     style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
            background:var(--white);color:var(--muted);border:1.5px solid var(--border);
            border-radius:var(--r-full);font-size:13px;font-weight:600;text-decoration:none;
            transition:all .18s;font-family:var(--font-d);"
     onmouseover="this.style.borderColor='var(--p)';this.style.color='var(--p)'"
     onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
    <i class="fa-solid fa-user-tie"></i> Sales Executives
  </a>
</div>

<!-- ═══════════════════════════════════════════════════════
     1. HERO
     ═══════════════════════════════════════════════════════ -->
<div style="text-align:center;padding:1rem 0 3.5rem;max-width:620px;margin:0 auto;"
     class="pub-anim">

  <div style="display:inline-flex;align-items:center;gap:8px;background:var(--p-light);
              border:1px solid var(--p-b);border-radius:var(--r-full);
              padding:6px 18px;font-size:12px;font-weight:700;color:var(--p);
              margin-bottom:1.5rem;font-family:var(--font-d);">
    <i class="fa-solid fa-id-card"></i> For independent auto brokers
  </div>

  <h1 style="font-family:var(--font-d);font-size:clamp(32px,6vw,46px);font-weight:800;
             line-height:1.08;letter-spacing:-.03em;color:var(--text);margin-bottom:1.25rem;">
    Turn your car knowledge<br>
    into <span style="color:var(--p);">commission income.</span>
  </h1>

  <p style="font-size:16px;color:var(--muted);line-height:1.75;margin-bottom:2rem;">
    SalesDesk connects you with verified dealerships across South Africa.
    Add cars to your personal desk, share your tracked links, and earn
    a commission on every deal you close — without owning a single car.
  </p>

  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:1.25rem;">
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:14px 36px;font-size:15px;font-family:var(--font-d);">
      <i class="fa-solid fa-rocket"></i> Create your SalesDesk — free
    </a>
    <a href="/c/" class="pub-btn pub-btn-ghost"
       style="padding:14px 24px;font-size:14px;">
      Browse available cars
    </a>
  </div>

  <div style="display:flex;gap:20px;justify-content:center;flex-wrap:wrap;">
    <?php foreach ([
      ['fa-circle-check', 'var(--green)', 'Free to join'],
      ['fa-circle-check', 'var(--green)', 'No monthly fees'],
      ['fa-circle-check', 'var(--green)', 'Commission only'],
      ['fa-circle-check', 'var(--green)', 'POPIA compliant'],
    ] as [$icon, $col, $label]): ?>
    <span style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:5px;">
      <i class="fa-solid <?= $icon ?>" style="color:<?= $col ?>;font-size:11px;"></i>
      <?= $label ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     2. OVERVIEW DIAGRAM
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">

  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">How the platform works</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      The three-way marketplace
    </h2>
  </div>

  <!-- Diagram -->
  <div style="background:linear-gradient(145deg,#f8faff,#eef2ff);border:1px solid var(--border);
              border-radius:var(--r-xl);padding:2.5rem 2rem;position:relative;overflow:hidden;">

    <!-- Background decorative circles -->
    <div style="position:absolute;top:-40px;right:-40px;width:200px;height:200px;
                border-radius:50%;background:rgba(15,76,158,.04);pointer-events:none;"></div>
    <div style="position:absolute;bottom:-30px;left:-30px;width:150px;height:150px;
                border-radius:50%;background:rgba(15,76,158,.04);pointer-events:none;"></div>

    <!-- Three nodes -->
    <div style="display:grid;grid-template-columns:1fr auto 1fr auto 1fr;
                gap:0;align-items:center;position:relative;z-index:1;">

      <!-- Dealer node -->
      <div style="text-align:center;">
        <div style="width:72px;height:72px;border-radius:50%;
                    background:linear-gradient(135deg,#f0fdf4,#dcfce7);
                    border:2px solid var(--gr-b);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 12px;box-shadow:0 4px 16px rgba(21,128,61,.12);">
          <i class="fa-solid fa-building-user" style="font-size:26px;color:var(--green);"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:14px;font-weight:700;
                    color:var(--text);margin-bottom:4px;">Dealer</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;max-width:110px;margin:0 auto;">
          Lists cars with commission offer
        </div>
        <div style="margin-top:10px;">
          <span style="font-size:10px;font-weight:700;background:var(--gr-bg);
                       color:var(--green);border:1px solid var(--gr-b);
                       border-radius:var(--r-full);padding:2px 8px;">Sets commission</span>
        </div>
      </div>

      <!-- Arrow 1 -->
      <div style="text-align:center;padding:0 8px;">
        <div style="font-size:10px;color:var(--faint);margin-bottom:4px;">inventory</div>
        <div style="display:flex;align-items:center;gap:2px;">
          <div style="height:2px;width:40px;background:linear-gradient(90deg,var(--gr-b),var(--p-b));
                      border-radius:1px;"></div>
          <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--p);"></i>
        </div>
      </div>

      <!-- Broker node (YOU) — highlighted -->
      <div style="text-align:center;">
        <div style="position:relative;display:inline-block;margin-bottom:12px;">
          <div style="width:80px;height:80px;border-radius:50%;
                      background:linear-gradient(135deg,var(--p),#2563eb);
                      display:flex;align-items:center;justify-content:center;
                      margin:0 auto;box-shadow:0 8px 24px rgba(15,76,158,.3);">
            <i class="fa-solid fa-id-card" style="font-size:28px;color:#fff;"></i>
          </div>
          <div style="position:absolute;top:-8px;right:-8px;background:#f59e0b;
                      color:#fff;font-size:9px;font-weight:800;
                      border-radius:var(--r-full);padding:2px 6px;
                      font-family:var(--font-d);letter-spacing:.03em;">YOU</div>
        </div>
        <div style="font-family:var(--font-d);font-size:15px;font-weight:800;
                    color:var(--p);margin-bottom:4px;">Auto Broker</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;
                    max-width:120px;margin:0 auto;">
          Adds cars to desk, shares tracked links
        </div>
        <div style="margin-top:10px;">
          <span style="font-size:10px;font-weight:700;background:var(--p-light);
                       color:var(--p);border:1px solid var(--p-b);
                       border-radius:var(--r-full);padding:2px 8px;">Earns commission</span>
        </div>
      </div>

      <!-- Arrow 2 -->
      <div style="text-align:center;padding:0 8px;">
        <div style="font-size:10px;color:var(--faint);margin-bottom:4px;">enquiry</div>
        <div style="display:flex;align-items:center;gap:2px;">
          <div style="height:2px;width:40px;background:linear-gradient(90deg,var(--p-b),var(--amb-b));
                      border-radius:1px;"></div>
          <i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--amber);"></i>
        </div>
      </div>

      <!-- Buyer node -->
      <div style="text-align:center;">
        <div style="width:72px;height:72px;border-radius:50%;
                    background:linear-gradient(135deg,var(--amb-bg),#fef9c3);
                    border:2px solid var(--amb-b);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 12px;box-shadow:0 4px 16px rgba(180,83,9,.1);">
          <i class="fa-solid fa-user" style="font-size:26px;color:var(--amber);"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:14px;font-weight:700;
                    color:var(--text);margin-bottom:4px;">Buyer</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;
                    max-width:110px;margin:0 auto;">
          Clicks your link, submits enquiry
        </div>
        <div style="margin-top:10px;">
          <span style="font-size:10px;font-weight:700;background:var(--amb-bg);
                       color:var(--amber);border:1px solid var(--amb-b);
                       border-radius:var(--r-full);padding:2px 8px;">Lead generated</span>
        </div>
      </div>
    </div>

    <!-- Commission flow strip -->
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid rgba(15,76,158,.1);
                display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;">
      <span style="font-size:12px;color:var(--muted);">When the deal closes:</span>
      <?php foreach ([
        ['Deal closes', 'var(--text)', 'var(--border)', 'var(--bg)'],
        ['→', 'var(--faint)', 'transparent', 'transparent'],
        ['Commission set', 'var(--green)', 'var(--gr-b)', 'var(--gr-bg)'],
        ['→', 'var(--faint)', 'transparent', 'transparent'],
        ['Platform (' . $defaultFee . '%)', 'var(--amber)', 'var(--amb-b)', 'var(--amb-bg)'],
        ['→', 'var(--faint)', 'transparent', 'transparent'],
        ['You keep ' . (100 - $defaultFee) . '%', 'var(--p)', 'var(--p-b)', 'var(--p-light)'],
      ] as [$label, $color, $border, $bg]): ?>
      <span style="font-size:11px;font-weight:700;color:<?= $color ?>;
                   <?= $bg !== 'transparent' ? "background:{$bg};border:1px solid {$border};" : '' ?>
                   border-radius:var(--r-full);padding:3px 10px;">
        <?= $label ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     3. HOW IT WORKS — STEPS
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">

  <div style="text-align:center;margin-bottom:2.5rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Step by step</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      From sign-up to your first commission
    </h2>
  </div>

  <?php
  $steps = [
    [
      'fa-user-plus', 'var(--p)', 'var(--p-light)', 'var(--p-b)',
      'Create your free SalesDesk',
      'Register in under 5 minutes. Choose your unique broker slug — this becomes your personal URL at <strong>salesdesk.co.za/{your-name}</strong>. Add a profile photo, bio, and tagline to build buyer trust. No credit card required.',
      null
    ],
    [
      'fa-store', 'var(--green)', 'var(--gr-bg)', 'var(--gr-b)',
      'Browse the dealer marketplace',
      'Access our full inventory of cars listed by verified South African dealerships. Filter by make, province, body type, price, and commission rate. Each listing shows the commission you\'ll earn if the deal closes.',
      null
    ],
    [
      'fa-link', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
      'Add cars and get your tracked link',
      'Add up to 10 active cars to your desk at a time. Each car gets a unique 32-character tracking code tied to your SalesDesk. This code is cryptographically locked the moment a buyer submits an enquiry — it cannot be overwritten.',
      'Your attribution is locked at lead submission — even if the buyer calls the dealer directly weeks later.'
    ],
    [
      'fa-share-nodes', '#7c3aed', 'var(--pur-bg)', 'var(--pur-b)',
      'Share your link anywhere',
      'Post on WhatsApp, Facebook, Instagram, or your own website. Your tracking link carries your code everywhere it goes. Buyers who click it are attributed to you for 90 days via a secure visitor session.',
      null
    ],
    [
      'fa-sack-dollar', 'var(--green)', 'var(--gr-bg)', 'var(--gr-b)',
      'Earn commission when the deal closes',
      'When the dealer marks your lead as closed, a commission record is created. Our admin team approves and processes EFT payments to your registered bank account, typically within 7 business days. Track everything in your earnings dashboard.',
      null
    ],
  ];
  foreach ($steps as $i => [$icon, $color, $bg, $border, $title, $body, $callout]):
  ?>
  <div style="display:flex;gap:20px;margin-bottom:2rem;align-items:flex-start;"
       class="pub-reveal">

    <!-- Step number + connector line -->
    <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;">
      <div style="width:52px;height:52px;border-radius:50%;background:<?= $bg ?>;
                  border:2px solid <?= $border ?>;
                  display:flex;align-items:center;justify-content:center;
                  flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.07);">
        <i class="fa-solid <?= $icon ?>" style="font-size:20px;color:<?= $color ?>;"></i>
      </div>
      <?php if ($i < count($steps) - 1): ?>
      <div style="width:2px;height:40px;background:var(--border);margin-top:8px;
                  border-radius:1px;"></div>
      <?php endif; ?>
    </div>

    <!-- Content -->
    <div style="flex:1;padding-top:12px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <span style="font-family:var(--mono);font-size:11px;font-weight:700;
                     color:<?= $color ?>;background:<?= $bg ?>;border:1px solid <?= $border ?>;
                     border-radius:var(--r-full);padding:2px 8px;">0<?= $i + 1 ?></span>
        <h3 style="font-family:var(--font-d);font-size:16px;font-weight:700;
                   color:var(--text);margin:0;"><?= $title ?></h3>
      </div>
      <p style="font-size:13px;color:var(--muted);line-height:1.75;margin:0 0 8px;">
        <?= $body ?>
      </p>
      <?php if ($callout): ?>
      <div style="background:var(--p-light);border:1px solid var(--p-b);
                  border-radius:var(--r-md);padding:10px 14px;
                  font-size:12px;color:var(--p);display:flex;gap:8px;align-items:flex-start;">
        <i class="fa-solid fa-shield-halved" style="flex-shrink:0;margin-top:1px;"></i>
        <span><?= $callout ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     4. THE ATTRIBUTION PROMISE
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="background:linear-gradient(135deg,#08143c,var(--p));
              border-radius:var(--r-xl);padding:2.5rem;color:#fff;
              position:relative;overflow:hidden;">
    <div style="position:absolute;top:-30px;right:-30px;width:160px;height:160px;
                border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;"></div>
    <div style="position:absolute;bottom:-20px;left:40%;width:100px;height:100px;
                border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;"></div>

    <div style="position:relative;z-index:1;">
      <div style="display:inline-flex;align-items:center;gap:8px;
                  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
                  border-radius:var(--r-full);padding:5px 14px;
                  font-size:11px;font-weight:700;color:rgba(255,255,255,.8);
                  margin-bottom:1.25rem;letter-spacing:.05em;text-transform:uppercase;">
        <i class="fa-solid fa-shield-halved"></i> Attribution protection
      </div>

      <h2 style="font-family:var(--font-d);font-size:26px;font-weight:800;
                 margin-bottom:10px;letter-spacing:-.02em;line-height:1.15;">
        Your commission is protected<br>from the moment a buyer clicks.
      </h2>
      <p style="font-size:14px;color:rgba(255,255,255,.65);margin-bottom:2rem;
                line-height:1.7;max-width:520px;">
        The single biggest fear for any broker is putting in the work and not getting paid.
        SalesDesk's attribution model is designed from the ground up to protect you.
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <?php
        $protections = [
          ['fa-lock', '32-character tracking code', 'Every car on your desk gets a unique cryptographic hash tied specifically to your SalesDesk and that listing.'],
          ['fa-cookie-bite', '90-day visitor attribution', 'Once a buyer clicks your link, a secure session cookie tracks them for 90 days — even if they return directly to the site.'],
          ['fa-bolt', 'Locked at submission', 'The attribution record is immutable the moment an enquiry is submitted. No one can override or reassign it after the fact.'],
          ['fa-user-shield', 'No double-attribution', 'If two brokers share the same car, whoever generated the lead first gets the commission. First click, first credit.'],
        ];
        foreach ($protections as [$icon, $title, $desc]):
        ?>
        <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
                    border-radius:var(--r-lg);padding:16px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <i class="fa-solid <?= $icon ?>" style="color:rgba(255,255,255,.6);font-size:14px;"></i>
            <span style="font-family:var(--font-d);font-size:13px;font-weight:700;"><?= $title ?></span>
          </div>
          <p style="font-size:12px;color:rgba(255,255,255,.55);line-height:1.65;margin:0;">
            <?= $desc ?>
          </p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     5. COMMISSION CALCULATOR
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:1.75rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Your potential</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      See what you could earn
    </h2>
  </div>

  <div style="background:var(--white);border:1px solid var(--border);
              border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-md);">

    <!-- Calculator controls -->
    <div style="padding:2rem;border-bottom:1px solid var(--border);">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <div>
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;
                        letter-spacing:.06em;color:var(--faint);display:block;margin-bottom:8px;">
            Car sale price
          </label>
          <input type="range" id="calcPrice" min="50000" max="2000000" step="25000" value="350000"
                 style="width:100%;accent-color:var(--p);">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--faint);margin-top:4px;">
            <span>R 50k</span><span>R 2m</span>
          </div>
          <div id="calcPriceDisplay"
               style="font-family:var(--font-d);font-size:18px;font-weight:700;
                      color:var(--text);margin-top:8px;">R 350 000</div>
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;
                        letter-spacing:.06em;color:var(--faint);display:block;margin-bottom:8px;">
            Commission rate (%)
          </label>
          <input type="range" id="calcComm" min="0.5" max="5" step="0.5" value="2.5"
                 style="width:100%;accent-color:var(--green);">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--faint);margin-top:4px;">
            <span>0.5%</span><span>5%</span>
          </div>
          <div id="calcCommDisplay"
               style="font-family:var(--font-d);font-size:18px;font-weight:700;
                      color:var(--text);margin-top:8px;">2.5%</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;">
        <label style="display:flex;align-items:center;gap:8px;padding:12px 16px;
                      background:var(--bg);border-radius:var(--r-md);cursor:pointer;
                      font-size:13px;color:var(--text);">
          <input type="radio" name="calcType" value="single" checked
                 style="accent-color:var(--p);">
          Single deal calculation
        </label>
        <label style="display:flex;align-items:center;gap:8px;padding:12px 16px;
                      background:var(--bg);border-radius:var(--r-md);cursor:pointer;
                      font-size:13px;color:var(--text);">
          <input type="radio" name="calcType" value="monthly"
                 style="accent-color:var(--p);">
          <span>Monthly estimate (<span id="dealsPerMonth">3</span> deals)</span>
        </label>
      </div>

      <!-- Monthly deals slider (hidden by default) -->
      <div id="monthlySliderWrap" style="display:none;margin-top:12px;">
        <input type="range" id="calcDeals" min="1" max="20" step="1" value="3"
               style="width:100%;accent-color:var(--p);">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--faint);margin-top:4px;">
          <span>1 deal</span><span>20 deals</span>
        </div>
      </div>
    </div>

    <!-- Calculator result -->
    <div style="padding:2rem;background:linear-gradient(135deg,#f8faff,#eef2ff);">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
        <?php
        $resultCards = [
          ['Gross commission', 'calcGross', 'var(--text)'],
          ['Platform fee (' . $defaultFee . '%)', 'calcFee', 'var(--red)'],
          ['You receive', 'calcNet', 'var(--p)'],
        ];
        foreach ($resultCards as [$label, $id, $color]):
        ?>
        <div style="background:var(--white);border:1px solid var(--border);
                    border-radius:var(--r-lg);padding:16px;text-align:center;
                    box-shadow:var(--shadow-sm);">
          <div style="font-size:11px;color:var(--faint);margin-bottom:6px;"><?= $label ?></div>
          <div id="<?= $id ?>"
               style="font-family:var(--font-d);font-size:20px;font-weight:800;
                      color:<?= $color ?>;">—</div>
        </div>
        <?php endforeach; ?>
      </div>
      <p style="font-size:11px;color:var(--faint);text-align:center;margin:0;">
        Illustrative only. Actual commission is set by the dealer and varies per listing.
        EFT paid within 7 business days of deal confirmation.
      </p>
    </div>
  </div>
</div>

<script>
(function () {
  var priceEl    = document.getElementById('calcPrice');
  var commEl     = document.getElementById('calcComm');
  var dealsEl    = document.getElementById('calcDeals');
  var pDisp      = document.getElementById('calcPriceDisplay');
  var cDisp      = document.getElementById('calcCommDisplay');
  var dDisp      = document.getElementById('dealsPerMonth');
  var grossEl    = document.getElementById('calcGross');
  var feeEl      = document.getElementById('calcFee');
  var netEl      = document.getElementById('calcNet');
  var typeRadios = document.querySelectorAll('input[name="calcType"]');
  var monthlyWrap= document.getElementById('monthlySliderWrap');
  var fee        = <?= $defaultFee ?>;

  function fmt(n) {
    return 'R\u00a0' + Math.round(n).toLocaleString('en-ZA');
  }

  function calc() {
    var price  = parseFloat(priceEl.value);
    var comm   = parseFloat(commEl.value);
    var type   = document.querySelector('input[name="calcType"]:checked').value;
    var deals  = parseInt(dealsEl.value, 10);
    var mult   = type === 'monthly' ? deals : 1;

    var gross  = price * (comm / 100) * mult;
    var feeAmt = gross * (fee / 100);
    var net    = gross - feeAmt;

    pDisp.textContent  = 'R\u00a0' + Math.round(price).toLocaleString('en-ZA');
    cDisp.textContent  = comm.toFixed(1) + '%';
    dDisp.textContent  = deals;
    grossEl.textContent= fmt(gross);
    feeEl.textContent  = '−\u00a0' + fmt(feeAmt);
    netEl.textContent  = fmt(net);
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

  calc();
})();
</script>

<!-- ═══════════════════════════════════════════════════════
     6. YOUR PUBLIC STOREFRONT
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:center;
              flex-wrap:wrap;">
    <div>
      <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                  letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                  margin-bottom:10px;">Your brand</div>
      <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
                 letter-spacing:-.02em;color:var(--text);margin-bottom:1rem;line-height:1.2;">
        A personal storefront that belongs to you
      </h2>
      <p style="font-size:14px;color:var(--muted);line-height:1.75;margin-bottom:1.25rem;">
        Every broker gets a public SalesDesk page at
        <strong style="font-family:var(--mono);font-size:13px;">salesdesk.co.za/{your-name}</strong>.
        Share it on your WhatsApp profile, Instagram bio, or business card. Buyers see your
        listings, your stats, and your contact details — all in one place.
      </p>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ([
          ['fa-id-card', 'Your own URL', 'salesdesk.co.za/yourname — professional, shareable, memorable'],
          ['fa-chart-bar', 'Live analytics', 'See views, leads, and conversion rates per car'],
          ['fa-users', 'Build a team', 'Join a broker organisation or start your own'],
          ['fa-star', 'Social proof', 'Deals closed and total enquiries shown on your public page'],
        ] as [$icon, $title, $desc]): ?>
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <div style="width:36px;height:36px;border-radius:var(--r-sm);background:var(--p-light);
                      display:flex;align-items:center;justify-content:center;
                      flex-shrink:0;color:var(--p);font-size:14px;">
            <i class="fa-solid <?= $icon ?>"></i>
          </div>
          <div>
            <div style="font-size:13px;font-weight:700;color:var(--text);"><?= $title ?></div>
            <div style="font-size:12px;color:var(--muted);"><?= $desc ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Storefront mockup -->
    <div style="background:var(--white);border:1px solid var(--border);
                border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-lg);">
      <!-- Mock browser bar -->
      <div style="background:var(--bg);border-bottom:1px solid var(--border);
                  padding:10px 14px;display:flex;align-items:center;gap:8px;">
        <div style="display:flex;gap:5px;">
          <?php foreach (['#fc5c57','#fdbc40','#33c748'] as $c): ?>
          <div style="width:10px;height:10px;border-radius:50%;background:<?= $c ?>;"></div>
          <?php endforeach; ?>
        </div>
        <div style="flex:1;background:var(--white);border:1px solid var(--border);
                    border-radius:var(--r-sm);padding:4px 10px;font-size:10px;
                    color:var(--faint);font-family:var(--mono);">
          salesdesk.co.za/thembi
        </div>
      </div>
      <!-- Mock hero gradient -->
      <div style="background:linear-gradient(140deg,#08143c,var(--p));padding:20px;color:#fff;">
        <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.25);
                    display:flex;align-items:center;justify-content:center;
                    font-family:var(--font-d);font-size:16px;font-weight:700;margin-bottom:10px;">
          TN
        </div>
        <div style="font-family:var(--font-d);font-size:16px;font-weight:800;margin-bottom:3px;">
          Thembi's AutoDesk
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,.6);">Johannesburg · Gauteng</div>
        <div style="display:flex;gap:16px;margin-top:14px;">
          <?php foreach (['8 Active', '142 Views', '3 Closed'] as $stat): ?>
          <div style="text-align:center;">
            <div style="font-family:var(--font-d);font-size:16px;font-weight:700;">
              <?= explode(' ', $stat)[0] ?>
            </div>
            <div style="font-size:10px;color:rgba(255,255,255,.5);"><?= explode(' ', $stat)[1] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- Mock car card -->
      <div style="padding:14px;">
        <div style="font-family:var(--font-d);font-size:12px;font-weight:700;
                    margin-bottom:10px;color:var(--text);">Available cars (8)</div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--r-lg);
                    overflow:hidden;">
          <div style="height:80px;background:linear-gradient(135deg,#1e293b,#334155);
                      display:flex;align-items:center;justify-content:center;
                      color:rgba(255,255,255,.3);font-size:24px;">
            <i class="fa-solid fa-car-side"></i>
          </div>
          <div style="padding:10px;">
            <div style="font-family:var(--font-d);font-size:12px;font-weight:700;color:var(--text);">
              2022 Toyota RAV4
            </div>
            <div style="font-family:var(--font-d);font-size:14px;font-weight:800;
                        color:var(--p);margin-top:2px;">R 549 900</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     7. GETTING PAID
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Payouts</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      How and when you get paid
    </h2>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
    <?php
    $paySteps = [
      ['1', 'fa-handshake', 'var(--p)', 'var(--p-light)', 'Deal closes', 'The dealer marks your lead as closed in their pipeline dashboard.'],
      ['2', 'fa-file-invoice', 'var(--amber)', 'var(--amb-bg)', 'Record created', 'A commission record is generated automatically with your bank account details.'],
      ['3', 'fa-user-check', '#7c3aed', 'var(--pur-bg)', 'Admin approves', 'Our team verifies the deal and approves the commission payout, usually same day.'],
      ['4', 'fa-building-columns', 'var(--green)', 'var(--gr-bg)', 'EFT processed', 'Your commission is paid via EFT within 7 business days of approval.'],
    ];
    foreach ($paySteps as [$num, $icon, $color, $bg, $title, $desc]):
    ?>
    <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);
                padding:20px;box-shadow:var(--shadow-sm);position:relative;">
      <div style="width:36px;height:36px;border-radius:var(--r-md);background:<?= $bg ?>;
                  display:flex;align-items:center;justify-content:center;
                  margin-bottom:12px;font-size:16px;color:<?= $color ?>;">
        <i class="fa-solid <?= $icon ?>"></i>
      </div>
      <div style="position:absolute;top:14px;right:14px;font-family:var(--mono);
                  font-size:10px;font-weight:700;color:<?= $color ?>;background:<?= $bg ?>;
                  border-radius:var(--r-full);padding:2px 7px;">Step <?= $num ?></div>
      <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                  color:var(--text);margin-bottom:6px;"><?= $title ?></div>
      <div style="font-size:12px;color:var(--muted);line-height:1.6;"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:1.25rem;background:var(--p-light);border:1px solid var(--p-b);
              border-radius:var(--r-md);padding:14px 18px;display:flex;gap:10px;
              align-items:flex-start;font-size:13px;color:var(--p);">
    <i class="fa-solid fa-circle-info" style="flex-shrink:0;margin-top:1px;"></i>
    <span>Your bank account details are stored securely in your settings. Make sure they're
    correct before your first deal closes — incorrect details delay payment.</span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     8. FAQ
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Common questions</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      Broker FAQ
    </h2>
  </div>

  <?php
  $faqs = [
    ['What if a buyer contacts the dealer directly after clicking my link?',
     'Your attribution is locked at the moment the buyer submits their enquiry through your tracking link. If they later call the dealer directly, the lead is already recorded under your code. The dealer cannot reassign it — attribution is immutable once set.'],
    ['Do I need to be a licensed dealer or have any qualifications?',
     'No. SalesDesk is designed for independent brokers who act as lead generators, not sellers. You\'re connecting buyers with dealerships. The dealer handles the actual transaction, finance, and legal transfer.'],
    ['What if two brokers share the same car?',
     'Each broker has their own unique tracking code for each car. Attribution goes to whichever broker\'s link the buyer clicked first. If a buyer was already attributed to another broker, subsequent link clicks don\'t override that — first click wins.'],
    ['How many cars can I have on my desk at once?',
     'New brokers can have up to 10 active cars on their desk at any time. If you need a higher limit, contact your account manager or reach out to our admin team — increases are granted based on activity and account standing.'],
    ['Is there a monthly fee or subscription?',
     'SalesDesk is completely free for brokers. We only take our platform fee (' . $defaultFee . '%) when a deal closes and commission is paid. If you don\'t earn, we don\'t earn.'],
    ['Can I join a broker organisation or team?',
     'Yes. Broker organisations allow you to pool leads, share inventory, and build a branded team desk. You can join an existing verified organisation or apply to start your own.'],
    ['What happens if a car I\'ve added gets sold or pulled by the dealer?',
     'If a dealer marks a car as sold or removes it from the marketplace, it\'s automatically hidden from your desk and no new leads can be submitted. Any existing leads you generated before the car was closed are still attributed to you.'],
  ];
  foreach ($faqs as [$question, $answer]):
  ?>
  <details style="border:1px solid var(--border);border-radius:var(--r-lg);
                  margin-bottom:8px;background:var(--white);overflow:hidden;">
    <summary style="padding:16px 18px;font-size:14px;font-weight:600;color:var(--text);
                    cursor:pointer;list-style:none;display:flex;justify-content:space-between;
                    align-items:center;gap:12px;user-select:none;">
      <?= htmlspecialchars($question) ?>
      <i class="fa-solid fa-plus" style="font-size:11px;color:var(--faint);flex-shrink:0;"></i>
    </summary>
    <div style="padding:0 18px 16px;font-size:13px;color:var(--muted);
                line-height:1.75;border-top:1px solid var(--border);">
      <?= $answer ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     9. CROSS-LINKS
     ═══════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:3rem;"
     class="pub-reveal">
  <?php foreach ([
    ['/how-it-works/dealers.php', 'fa-building-user', 'var(--green)', 'var(--gr-bg)', 'var(--gr-b)',
     'How it works for dealers', 'List your inventory and tap into our broker network.'],
    ['/how-it-works/sales-exec.php', 'fa-user-tie', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
     'How it works for sales execs', 'Upload cars on behalf of your dealership and track your personal performance.'],
  ] as [$href, $icon, $color, $bg, $border, $title, $desc]): ?>
  <a href="<?= $href ?>"
     style="display:flex;gap:14px;padding:18px;background:var(--white);
            border:1px solid <?= $border ?>;border-radius:var(--r-lg);
            text-decoration:none;transition:box-shadow .18s;box-shadow:var(--shadow-sm);"
     onmouseover="this.style.boxShadow='var(--shadow-md)'"
     onmouseout="this.style.boxShadow='var(--shadow-sm)'">
    <div style="width:42px;height:42px;border-radius:var(--r-md);background:<?= $bg ?>;
                flex-shrink:0;display:flex;align-items:center;justify-content:center;
                color:<?= $color ?>;font-size:16px;">
      <i class="fa-solid <?= $icon ?>"></i>
    </div>
    <div>
      <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                  color:var(--text);margin-bottom:3px;"><?= $title ?></div>
      <div style="font-size:12px;color:var(--muted);line-height:1.5;"><?= $desc ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     10. FINAL CTA
     ═══════════════════════════════════════════════════════ -->
<div style="text-align:center;background:linear-gradient(135deg,#08143c,var(--p));
            border-radius:var(--r-xl);padding:3rem 2rem;color:#fff;
            position:relative;overflow:hidden;" class="pub-reveal">
  <div style="position:absolute;top:-30px;right:-30px;width:180px;height:180px;
              border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;"></div>
  <div style="position:relative;z-index:1;">
    <div style="font-family:var(--font-d);font-size:28px;font-weight:800;
                margin-bottom:10px;letter-spacing:-.02em;line-height:1.15;">
      Ready to start earning?
    </div>
    <p style="font-size:15px;color:rgba(255,255,255,.65);margin-bottom:2rem;
              max-width:420px;margin-left:auto;margin-right:auto;line-height:1.7;">
      Create your SalesDesk in under 5 minutes.
      No credit card. No monthly fee. Commission only.
    </p>
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:16px 48px;font-size:16px;font-family:var(--font-d);
              background:#fff;color:var(--p);display:inline-flex;">
      <i class="fa-solid fa-rocket"></i> Create your SalesDesk — free
    </a>
    <div style="margin-top:14px;font-size:12px;color:rgba(255,255,255,.45);">
      Already have an account?
      <a href="/auth/login.php" style="color:rgba(255,255,255,.7);">Sign in</a>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
