<?php
/**
 * SalesDesk — How It Works: Dealers
 * Route: /how-it-works/dealers.php
 *
 * Informational page explaining the dealer value proposition,
 * commission-only model, broker network access, and sign-up flow.
 * No auth required.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');
$visitor = initVisitorSession();

$pageTitle     = 'How It Works for Dealers | SalesDesk';
$ogTitle       = 'List Your Inventory. Access Our Broker Network. Pay Commission Only. | SalesDesk';
$ogDescription = 'Join SalesDesk as a dealer. List cars with commission offers, get found by our broker network, and only pay when a deal closes. No upfront fees.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/how-it-works/dealers.php';
$layoutVariant  = 'narrow';
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

<!-- ═══════════════════════════════════════════════════════
     ROLE SWITCHER
     ═══════════════════════════════════════════════════════ -->
<div style="display:flex;gap:8px;justify-content:center;margin-bottom:2rem;flex-wrap:wrap;"
     class="pub-anim">
  <a href="/how-it-works/brokers.php"
     style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
            background:var(--white);color:var(--muted);border:1.5px solid var(--border);
            border-radius:var(--r-full);font-size:13px;font-weight:600;text-decoration:none;
            transition:all .18s;font-family:var(--font-d);"
     onmouseover="this.style.borderColor='var(--p)';this.style.color='var(--p)'"
     onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
    <i class="fa-solid fa-id-card"></i> Brokers
  </a>
  <a href="/how-it-works/dealers.php"
     style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
            background:var(--green);color:#fff;border-radius:var(--r-full);
            font-size:13px;font-weight:700;text-decoration:none;font-family:var(--font-d);">
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

  <div style="display:inline-flex;align-items:center;gap:8px;background:var(--gr-bg);
              border:1px solid var(--gr-b);border-radius:var(--r-full);
              padding:6px 18px;font-size:12px;font-weight:700;color:var(--green);
              margin-bottom:1.5rem;font-family:var(--font-d);">
    <i class="fa-solid fa-building-user"></i> For dealerships
  </div>

  <h1 style="font-family:var(--font-d);font-size:clamp(30px,6vw,46px);font-weight:800;
             line-height:1.08;letter-spacing:-.03em;color:var(--text);margin-bottom:1.25rem;">
    Your cars.<br>
    Our broker network.<br>
    <span style="color:var(--green);">Commission only.</span>
  </h1>

  <p style="font-size:16px;color:var(--muted);line-height:1.75;margin-bottom:2rem;">
    SalesDesk connects verified South African dealerships with a growing network
    of independent auto brokers. List your inventory once, set your commission,
    and let our brokers bring you qualified buyers — you only pay when a deal closes.
  </p>

  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:1.25rem;">
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:14px 36px;font-size:15px;font-family:var(--font-d);
              background:var(--green);">
      <i class="fa-solid fa-store"></i> List your inventory
    </a>
    <a href="/dealers.php" class="pub-btn pub-btn-ghost"
       style="padding:14px 24px;font-size:14px;">
      Learn more
    </a>
  </div>

  <div style="display:flex;gap:20px;justify-content:center;flex-wrap:wrap;">
    <?php foreach ([
      ['fa-circle-check', 'var(--green)', 'No upfront fees'],
      ['fa-circle-check', 'var(--green)', 'Pay on closed deals only'],
      ['fa-circle-check', 'var(--green)', 'CIPC verification badge'],
      ['fa-circle-check', 'var(--green)', 'Full lead pipeline'],
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
                margin-bottom:8px;">The flow</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      How deals reach your floor
    </h2>
  </div>

  <div style="background:linear-gradient(145deg,#f0fdf8,#ecfdf5);border:1px solid var(--gr-b);
              border-radius:var(--r-xl);padding:2.5rem 2rem;position:relative;overflow:hidden;">

    <div style="position:absolute;top:-30px;right:-30px;width:160px;height:160px;
                border-radius:50%;background:rgba(21,128,61,.05);pointer-events:none;"></div>

    <!-- Flow nodes -->
    <div style="display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto 1fr;
                gap:0;align-items:center;">

      <!-- Node: You (Dealer) -->
      <div style="text-align:center;">
        <div style="width:72px;height:72px;border-radius:50%;
                    background:linear-gradient(135deg,var(--gr-bg),#bbf7d0);
                    border:2px solid var(--gr-b);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 12px;box-shadow:0 4px 16px rgba(21,128,61,.15);
                    position:relative;">
          <i class="fa-solid fa-building-user" style="font-size:26px;color:var(--green);"></i>
          <div style="position:absolute;top:-6px;right:-6px;background:var(--green);
                      color:#fff;font-size:9px;font-weight:800;border-radius:var(--r-full);
                      padding:2px 6px;font-family:var(--font-d);">YOU</div>
        </div>
        <div style="font-family:var(--font-d);font-size:14px;font-weight:800;
                    color:var(--green);margin-bottom:4px;">Dealer</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;
                    max-width:110px;margin:0 auto;">
          List cars with commission offers
        </div>
      </div>

      <!-- Arrow -->
      <div style="padding:0 6px;text-align:center;">
        <div style="font-size:9px;color:var(--faint);margin-bottom:3px;white-space:nowrap;">
          marketplace
        </div>
        <div style="display:flex;align-items:center;gap:2px;">
          <div style="height:2px;width:28px;background:linear-gradient(90deg,var(--gr-b),var(--p-b));border-radius:1px;"></div>
          <i class="fa-solid fa-chevron-right" style="font-size:9px;color:var(--p);"></i>
        </div>
      </div>

      <!-- Node: Broker network -->
      <div style="text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;
                    background:var(--p-light);border:2px solid var(--p-b);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 12px;box-shadow:0 4px 12px rgba(15,76,158,.12);">
          <i class="fa-solid fa-id-card" style="font-size:22px;color:var(--p);"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                    color:var(--text);margin-bottom:4px;">Broker network</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;
                    max-width:100px;margin:0 auto;">
          Add your cars, share tracked links
        </div>
      </div>

      <!-- Arrow -->
      <div style="padding:0 6px;text-align:center;">
        <div style="font-size:9px;color:var(--faint);margin-bottom:3px;white-space:nowrap;">
          click
        </div>
        <div style="display:flex;align-items:center;gap:2px;">
          <div style="height:2px;width:28px;background:linear-gradient(90deg,var(--p-b),var(--amb-b));border-radius:1px;"></div>
          <i class="fa-solid fa-chevron-right" style="font-size:9px;color:var(--amber);"></i>
        </div>
      </div>

      <!-- Node: Buyer -->
      <div style="text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;
                    background:var(--amb-bg);border:2px solid var(--amb-b);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 12px;">
          <i class="fa-solid fa-user" style="font-size:22px;color:var(--amber);"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                    color:var(--text);margin-bottom:4px;">Buyer</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;
                    max-width:100px;margin:0 auto;">
          Submits a qualified enquiry
        </div>
      </div>

      <!-- Arrow -->
      <div style="padding:0 6px;text-align:center;">
        <div style="font-size:9px;color:var(--faint);margin-bottom:3px;white-space:nowrap;">
          lead
        </div>
        <div style="display:flex;align-items:center;gap:2px;">
          <div style="height:2px;width:28px;background:linear-gradient(90deg,var(--amb-b),var(--gr-b));border-radius:1px;"></div>
          <i class="fa-solid fa-chevron-right" style="font-size:9px;color:var(--green);"></i>
        </div>
      </div>

      <!-- Node: Deal closes -->
      <div style="text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;
                    background:var(--gr-bg);border:2px solid var(--gr-b);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 12px;">
          <i class="fa-solid fa-handshake" style="font-size:22px;color:var(--green);"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                    color:var(--text);margin-bottom:4px;">Deal closes</div>
        <div style="font-size:11px;color:var(--muted);line-height:1.5;
                    max-width:100px;margin:0 auto;">
          Commission paid only on closed deals
        </div>
      </div>
    </div>

    <!-- Key callout -->
    <div style="margin-top:2rem;padding:14px 18px;background:rgba(21,128,61,.08);
                border:1px solid rgba(21,128,61,.2);border-radius:var(--r-md);
                font-size:13px;color:var(--green);font-weight:600;text-align:center;
                display:flex;align-items:center;justify-content:center;gap:8px;">
      <i class="fa-solid fa-shield-halved"></i>
      You only pay commission when a deal closes — no lead fees, no subscriptions, no wasted spend.
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     3. THE COMMISSION-ONLY MODEL
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="background:linear-gradient(135deg,#14532d,var(--green));
              border-radius:var(--r-xl);padding:2.5rem;color:#fff;
              position:relative;overflow:hidden;">
    <div style="position:absolute;top:-20px;right:-20px;width:140px;height:140px;
                border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;"></div>

    <div style="position:relative;z-index:1;">
      <div style="display:inline-flex;align-items:center;gap:8px;
                  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
                  border-radius:var(--r-full);padding:5px 14px;
                  font-size:11px;font-weight:700;color:rgba(255,255,255,.8);
                  margin-bottom:1.25rem;letter-spacing:.05em;text-transform:uppercase;">
        <i class="fa-solid fa-bolt"></i> No lead fees. No subscriptions.
      </div>

      <h2 style="font-family:var(--font-d);font-size:26px;font-weight:800;
                 margin-bottom:10px;letter-spacing:-.02em;line-height:1.2;">
        The only cost is a commission<br>you've already agreed to pay.
      </h2>
      <p style="font-size:14px;color:rgba(255,255,255,.65);margin-bottom:2rem;
                line-height:1.7;max-width:540px;">
        Traditional lead platforms charge you whether the lead converts or not.
        SalesDesk is different — you set the commission when you list the car, and
        that's the only amount you'll ever pay on that deal.
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <?php foreach ([
          ['fa-ban', 'No monthly fees', 'Zero subscription cost to list on SalesDesk.'],
          ['fa-ban', 'No per-lead charges', 'You only pay when a deal is confirmed closed.'],
          ['fa-sliders', 'You control the rate', 'Set a fixed rand amount or a percentage per car.'],
        ] as [$icon, $title, $desc]): ?>
        <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
                    border-radius:var(--r-lg);padding:16px;text-align:center;">
          <i class="fa-solid <?= $icon ?>"
             style="font-size:20px;color:rgba(255,255,255,.5);margin-bottom:10px;display:block;"></i>
          <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                      margin-bottom:6px;"><?= $title ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,.55);line-height:1.6;">
            <?= $desc ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     4. HOW IT WORKS — STEPS
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2.5rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Step by step</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      From sign-up to your first broker lead
    </h2>
  </div>

  <?php
  $steps = [
    [
      'fa-store', '#15803d', 'var(--gr-bg)', 'var(--gr-b)',
      'Register and verify your dealership',
      'Sign up as a dealer principal. Complete your dealership profile with your company name, address, and brand focus. Submit your CIPC documentation for verification — verified dealers get a badge that brokers trust and prioritise when adding inventory.',
      'Verification typically takes 1–2 business days after your CIPC documents are submitted.'
    ],
    [
      'fa-car', 'var(--p)', 'var(--p-light)', 'var(--p-b)',
      'List your inventory with commission offers',
      'Upload cars individually or in bulk. For each listing, you set the commission type — a fixed rand amount (e.g. R 8 000) or a percentage of the sale price (e.g. 2.5%). Upload photos, add specs and a description. The listing is immediately visible in the broker marketplace.',
      null
    ],
    [
      'fa-network-wired', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
      'Brokers discover and share your cars',
      'Independent brokers browse the marketplace and add your cars to their personal SalesDesks. Each broker gets a unique tracking link for each car they add. They then share these links via WhatsApp, social media, or their own websites to qualified buyers.',
      null
    ],
    [
      'fa-inbox', '#7c3aed', 'var(--pur-bg)', 'var(--pur-b)',
      'Receive qualified leads in your pipeline',
      'When a buyer submits an enquiry through a broker\'s link, a lead arrives in your dealer dashboard. The lead is pre-attributed to the broker whose link generated it — you can see the buyer\'s name, phone, email, intent, and message, as well as which broker sourced them.',
      null
    ],
    [
      'fa-handshake', '#15803d', 'var(--gr-bg)', 'var(--gr-b)',
      'Close the deal and commission is paid',
      'Move the lead through your pipeline stages. When the deal closes, mark it as closed in your dashboard. A commission record is created automatically. Our admin team processes the EFT to the broker within 7 business days — you have no involvement in the payout process.',
      null
    ],
  ];
  foreach ($steps as $i => [$icon, $color, $bg, $border, $title, $body, $callout]):
  ?>
  <div style="display:flex;gap:20px;margin-bottom:2rem;align-items:flex-start;"
       class="pub-reveal">
    <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;">
      <div style="width:52px;height:52px;border-radius:50%;background:<?= $bg ?>;
                  border:2px solid <?= $border ?>;
                  display:flex;align-items:center;justify-content:center;
                  flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.07);">
        <i class="fa-solid <?= $icon ?>" style="font-size:20px;color:<?= $color ?>;"></i>
      </div>
      <?php if ($i < count($steps) - 1): ?>
      <div style="width:2px;height:40px;background:var(--border);margin-top:8px;border-radius:1px;"></div>
      <?php endif; ?>
    </div>
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
      <div style="background:var(--gr-bg);border:1px solid var(--gr-b);
                  border-radius:var(--r-md);padding:10px 14px;
                  font-size:12px;color:var(--green);display:flex;gap:8px;">
        <i class="fa-solid fa-circle-check" style="flex-shrink:0;margin-top:1px;"></i>
        <span><?= $callout ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     5. YOUR BROKER NETWORK
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:center;
              flex-wrap:wrap;">

    <!-- Stats mock -->
    <div style="background:var(--white);border:1px solid var(--border);
                border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-md);">
      <div style="background:var(--bg);border-bottom:1px solid var(--border);
                  padding:14px 18px;">
        <div style="font-family:var(--font-d);font-size:13px;font-weight:700;color:var(--text);">
          Active brokers right now
        </div>
      </div>
      <div style="padding:16px;">
        <?php
        $brokers = [
          ['TN', 'Thembi Nkosi', 'Johannesburg', '8 listings', 3],
          ['SR', 'Sipho Radebe', 'Pretoria', '5 listings', 1],
          ['KM', 'Keabetswe Molefe', 'Soweto', '12 listings', 7],
          ['LD', 'Lesego Dlamini', 'Sandton', '3 listings', 0],
        ];
        foreach ($brokers as [$init, $name, $city, $listings, $closed]):
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;
                    border-bottom:1px solid var(--border);">
          <div style="width:36px;height:36px;border-radius:50%;
                      background:linear-gradient(135deg,var(--p),#2563eb);
                      flex-shrink:0;display:flex;align-items:center;justify-content:center;
                      font-family:var(--font-d);font-size:12px;font-weight:700;color:#fff;">
            <?= $init ?>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;color:var(--text);"><?= $name ?></div>
            <div style="font-size:11px;color:var(--faint);"><?= $city ?> · <?= $listings ?></div>
          </div>
          <?php if ($closed > 0): ?>
          <span style="font-size:10px;font-weight:700;background:var(--gr-bg);
                       color:var(--green);border:1px solid var(--gr-b);
                       border-radius:var(--r-full);padding:2px 8px;white-space:nowrap;">
            <?= $closed ?> closed
          </span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div style="padding-top:10px;font-size:11px;color:var(--faint);text-align:center;">
          Illustrative — broker details shown are fictional
        </div>
      </div>
    </div>

    <div>
      <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                  letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                  margin-bottom:10px;">Broker network</div>
      <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
                 letter-spacing:-.02em;color:var(--text);margin-bottom:1rem;line-height:1.2;">
        A sales force that only costs you when they deliver
      </h2>
      <p style="font-size:14px;color:var(--muted);line-height:1.75;margin-bottom:1.25rem;">
        Every independent broker on SalesDesk is actively looking for inventory to add
        to their desk. The more compelling your commission offer, the more brokers will
        choose your cars over a competitor's.
      </p>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ([
          ['fa-magnifying-glass', 'Brokers filter by commission', 'Higher commission offers rank more prominently in broker search results.'],
          ['fa-share-nodes', 'Every share is tracked', 'Each broker\'s link carries their unique code — you always know who sourced each lead.'],
          ['fa-circle-check', 'Verified broker network', 'Brokers complete profile verification before they can add cars to their desks.'],
          ['fa-ban', 'No exclusivity required', 'Multiple brokers can list the same car — more reach, more qualified buyers.'],
        ] as [$icon, $title, $desc]): ?>
        <div style="display:flex;gap:12px;align-items:flex-start;">
          <div style="width:36px;height:36px;border-radius:var(--r-sm);background:var(--gr-bg);
                      display:flex;align-items:center;justify-content:center;
                      flex-shrink:0;color:var(--green);font-size:14px;">
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
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     6. SALES TEAM MANAGEMENT
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Your team</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      Bring your sales executives in
    </h2>
    <p style="font-size:14px;color:var(--muted);margin-top:8px;max-width:520px;
              margin-left:auto;margin-right:auto;line-height:1.7;">
      Your floor staff can have their own SalesDesk logins. They upload cars on the
      dealership's behalf, manage their own leads pipeline, and track their personal
      performance — while you maintain full visibility as the principal.
    </p>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
    <?php
    $teamFeatures = [
      ['fa-user-plus', 'var(--p)', 'var(--p-light)',  'Invite your team', 'Send invites or let execs request to join your dealership.'],
      ['fa-check-circle', 'var(--green)', 'var(--gr-bg)', 'You approve access', 'Every exec requires your approval before they can go live.'],
      ['fa-car-on', 'var(--amber)', 'var(--amb-bg)', 'They upload inventory', 'Execs add cars and manage listings on your behalf.'],
      ['fa-chart-line', 'var(--p)', 'var(--p-light)', 'Track performance', 'See leads, deals, and conversion per exec in your principal dashboard.'],
    ];
    foreach ($teamFeatures as [$icon, $color, $bg, $title, $desc]):
    ?>
    <div style="background:var(--white);border:1px solid var(--border);
                border-radius:var(--r-lg);padding:20px;box-shadow:var(--shadow-sm);">
      <div style="width:40px;height:40px;border-radius:var(--r-md);background:<?= $bg ?>;
                  display:flex;align-items:center;justify-content:center;
                  margin-bottom:12px;font-size:16px;color:<?= $color ?>;">
        <i class="fa-solid <?= $icon ?>"></i>
      </div>
      <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                  color:var(--text);margin-bottom:6px;"><?= $title ?></div>
      <div style="font-size:12px;color:var(--muted);line-height:1.6;"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:1.25rem;text-align:center;">
    <a href="/how-it-works/sales-exec.php"
       style="font-size:13px;color:var(--p);text-decoration:none;font-weight:600;">
      See how it works for sales executives →
    </a>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     7. LEAD PIPELINE WALKTHROUGH
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Your workflow</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      A lead pipeline built for car sales
    </h2>
  </div>

  <div style="background:var(--white);border:1px solid var(--border);
              border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-md);">
    <!-- Pipeline stages header -->
    <div style="display:grid;grid-template-columns:repeat(6,1fr);
                background:var(--bg);border-bottom:1px solid var(--border);">
      <?php
      $stages = [
        ['New lead',      'var(--p)',     'fa-inbox'],
        ['Contacted',     'var(--amber)', 'fa-phone'],
        ['Test drive',    '#7c3aed',      'fa-car-side'],
        ['Negotiating',   'var(--amber)', 'fa-comments'],
        ['Closed',        'var(--green)', 'fa-handshake'],
        ['Lost',          'var(--red)',   'fa-xmark'],
      ];
      foreach ($stages as $i => [$label, $color, $icon]):
      ?>
      <div style="padding:12px 8px;text-align:center;
                  <?= $i < count($stages) - 1 ? 'border-right:1px solid var(--border);' : '' ?>">
        <i class="fa-solid <?= $icon ?>"
           style="font-size:14px;color:<?= $color ?>;display:block;margin-bottom:5px;"></i>
        <div style="font-family:var(--font-d);font-size:10px;font-weight:700;
                    color:var(--text);"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Sample lead card -->
    <div style="padding:20px;">
      <div style="font-size:12px;color:var(--faint);margin-bottom:12px;">
        Example lead card in your pipeline:
      </div>
      <div style="background:var(--bg);border:1.5px solid var(--p-b);
                  border-radius:var(--r-lg);padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;
                    flex-wrap:wrap;gap:10px;">
          <div>
            <div style="font-family:var(--font-d);font-size:14px;font-weight:700;
                        color:var(--text);">Thabo Sithole</div>
            <div style="font-size:12px;color:var(--muted);margin-top:2px;">
              <i class="fa-solid fa-phone" style="font-size:10px;"></i> 082 000 0000
              &nbsp;·&nbsp;
              <i class="fa-solid fa-envelope" style="font-size:10px;"></i> thabo@example.com
            </div>
          </div>
          <div style="text-align:right;">
            <span style="font-size:10px;font-weight:700;background:var(--p-light);
                         color:var(--p);border:1px solid var(--p-b);border-radius:var(--r-full);
                         padding:2px 8px;">New lead</span>
            <div style="font-size:11px;color:var(--faint);margin-top:4px;">2 mins ago</div>
          </div>
        </div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);
                    display:flex;gap:16px;flex-wrap:wrap;">
          <div style="font-size:12px;color:var(--muted);">
            <strong style="color:var(--text);">Car:</strong> 2023 Toyota Corolla Cross
          </div>
          <div style="font-size:12px;color:var(--muted);">
            <strong style="color:var(--text);">Intent:</strong> Within 30 days 🔥
          </div>
          <div style="font-size:12px;color:var(--muted);">
            <strong style="color:var(--text);">Sourced by:</strong>
            <span style="color:var(--p);font-weight:600;">Thembi's AutoDesk</span>
          </div>
        </div>
      </div>

      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ([
          ['Mark contacted', 'var(--amb-bg)', 'var(--amb-b)', 'var(--amber)'],
          ['Schedule test drive', 'var(--pur-bg)', 'var(--pur-b)', '#7c3aed'],
          ['Mark closed', 'var(--gr-bg)', 'var(--gr-b)', 'var(--green)'],
          ['Mark lost', 'var(--red-bg)', 'var(--red-b)', 'var(--red)'],
        ] as [$label, $bg, $border, $color]): ?>
        <button style="padding:7px 14px;background:<?= $bg ?>;border:1px solid <?= $border ?>;
                       color:<?= $color ?>;border-radius:var(--r-md);font-size:12px;
                       font-weight:600;cursor:pointer;font-family:var(--sans);">
          <?= $label ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     8. VERIFICATION BADGE
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="background:var(--white);border:1px solid var(--border);
              border-radius:var(--r-xl);padding:2rem;box-shadow:var(--shadow-md);">
    <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">
      <div style="width:64px;height:64px;border-radius:50%;background:var(--gr-bg);
                  border:2px solid var(--gr-b);display:flex;align-items:center;
                  justify-content:center;flex-shrink:0;font-size:26px;color:var(--green);">
        <i class="fa-solid fa-circle-check"></i>
      </div>
      <div style="flex:1;min-width:240px;">
        <div style="font-family:var(--font-d);font-size:18px;font-weight:800;
                    color:var(--text);margin-bottom:6px;">
          Get verified. Get more leads.
        </div>
        <p style="font-size:13px;color:var(--muted);line-height:1.75;margin-bottom:1rem;">
          Verified dealers receive a green badge that appears on every car listing and
          on your dealer profile. Brokers actively filter for verified dealers — they're
          more likely to add your inventory because buyers trust the badge.
          Verification requires your CIPC registration documents and is reviewed by our team.
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:11px;font-weight:700;background:var(--gr-bg);
                         color:var(--green);border:1px solid var(--gr-b);
                         border-radius:var(--r-full);padding:3px 10px;
                         display:inline-flex;align-items:center;gap:4px;">
              <i class="fa-solid fa-circle-check" style="font-size:9px;"></i>
              Verified Dealer
            </span>
            <span style="font-size:12px;color:var(--muted);">appears on all your listings</span>
          </div>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0;">
        <a href="/auth/register.php"
           style="display:inline-flex;align-items:center;gap:8px;padding:12px 22px;
                  background:var(--green);color:#fff;border-radius:var(--r-md);
                  font-family:var(--font-d);font-size:13px;font-weight:700;
                  text-decoration:none;">
          <i class="fa-solid fa-file-certificate"></i> Get verified
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     9. FAQ
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Common questions</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      Dealer FAQ
    </h2>
  </div>

  <?php
  $faqs = [
    ['Can I control which brokers list my cars?',
     'Not by default — any verified broker can add any active car to their desk. This open model is intentional: more brokers listing your cars means more reach. However, if you have concerns about a specific broker\'s conduct, contact our admin team.'],
    ['What commission percentage should I offer?',
     'Commission rates vary by segment. For used cars, 1.5%–3% is common. For premium vehicles, a fixed amount (e.g. R 10 000–R 25 000) is often preferred by brokers as it\'s more predictable. Higher commission offers tend to attract more broker attention.'],
    ['What if a buyer contacts me directly after a broker shared my car?',
     'If the buyer submitted their initial enquiry through a broker\'s tracking link, that lead is attributed to the broker regardless of subsequent direct contact. Only enquiries submitted outside any broker link (e.g. from /c/ browse) are unattributed.'],
    ['What if I already have a sales team?',
     'Your existing sales staff can join as sales executives under your dealership account. They register, select your dealership, and you approve their access. They then upload cars and manage leads on your behalf with their own logins.'],
    ['How do I list a large inventory quickly?',
     'Individual listings are done via the dealer dashboard. For bulk uploads, contact our team — we can assist with CSV imports for large inventory sets. Each car requires at minimum: make, model, year, price, condition, and commission details.'],
    ['What happens if a deal falls through after I\'ve marked it closed?',
     'Contact our admin team as soon as possible. Commission records can be reversed before payment is processed. Once EFT is sent to the broker, the transaction is final — treat the commission as a cost of sale.'],
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
     10. CROSS-LINKS
     ═══════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:3rem;"
     class="pub-reveal">
  <?php foreach ([
    ['/how-it-works/brokers.php', 'fa-id-card', 'var(--p)', 'var(--p-light)', 'var(--p-b)',
     'How it works for brokers', 'Understand the broker network that drives your leads.'],
    ['/how-it-works/sales-exec.php', 'fa-user-tie', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
     'How it works for sales execs', 'See how your floor staff use their SalesDesk login.'],
  ] as [$href, $icon, $color, $bg, $border, $title, $desc]): ?>
  <a href="<?= $href ?>"
     style="display:flex;gap:14px;padding:18px;background:var(--white);
            border:1px solid <?= $border ?>;border-radius:var(--r-lg);
            text-decoration:none;box-shadow:var(--shadow-sm);transition:box-shadow .18s;"
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
     11. FINAL CTA
     ═══════════════════════════════════════════════════════ -->
<div style="text-align:center;background:linear-gradient(135deg,#14532d,var(--green));
            border-radius:var(--r-xl);padding:3rem 2rem;color:#fff;
            position:relative;overflow:hidden;" class="pub-reveal">
  <div style="position:absolute;top:-30px;right:-30px;width:180px;height:180px;
              border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;"></div>
  <div style="position:relative;z-index:1;">
    <div style="font-family:var(--font-d);font-size:28px;font-weight:800;
                margin-bottom:10px;letter-spacing:-.02em;line-height:1.15;">
      Ready to list your inventory?
    </div>
    <p style="font-size:15px;color:rgba(255,255,255,.65);margin-bottom:2rem;
              max-width:420px;margin-left:auto;margin-right:auto;line-height:1.7;">
      Join SalesDesk's growing dealer network. Set up your profile, upload your
      first car, and start receiving broker-sourced leads today.
    </p>
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:16px 48px;font-size:16px;font-family:var(--font-d);
              background:#fff;color:var(--green);display:inline-flex;">
      <i class="fa-solid fa-store"></i> List your inventory
    </a>
    <div style="margin-top:14px;font-size:12px;color:rgba(255,255,255,.45);">
      Already registered?
      <a href="/auth/login.php" style="color:rgba(255,255,255,.7);">Sign in to your dealer portal</a>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
