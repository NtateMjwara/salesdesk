<?php
/**
 * SalesDesk — Brokers Marketing Page
 * Route: /brokers.php  (or /brokers/)
 *
 * Explains the broker value proposition, how commission works,
 * and drives sign-ups. No auth or visitor tracking required on
 * this page — it is purely informational.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/session.php';

applyCachePolicy('public');

$visitor = initVisitorSession();

$pageTitle     = 'Earn Commission Selling Cars | SalesDesk Brokers';
$ogTitle       = 'Become a SalesDesk Broker — Earn Commission on Every Sale';
$ogDescription = 'Join South Africa\'s broker car sales network. Add cars to your personal SalesDesk, share your links, and earn commission on every deal you close. No stock. No dealership. Just results.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/brokers.php';
$layoutVariant  = 'narrow';
$showBreadcrumb = false;

// Commission rate from platform config.
$defaultFee = 10; // fallback
try {
    require_once '../includes/database.php';
    require_once '../includes/functions.php';
    $defaultFee = getPlatformConfigInt('platform_fee_percent', 10);
} catch (Throwable) {}

ob_start();
?>

<!-- ── Hero ──────────────────────────────────────────────────── -->
<div style="text-align:center;padding:2rem 0 3rem;max-width:640px;margin:0 auto;"
     class="pub-anim">
  <div style="display:inline-flex;align-items:center;gap:8px;background:var(--p-light);
              border:1px solid var(--p-b);border-radius:var(--r-full);
              padding:6px 16px;font-size:12px;font-weight:600;color:var(--p);
              margin-bottom:1.5rem;">
    <i class="fa-solid fa-star"></i> For independent auto brokers
  </div>
  <h1 style="font-family:var(--font-d);font-size:36px;font-weight:800;
             line-height:1.1;letter-spacing:-.03em;color:var(--text);margin-bottom:1rem;">
    Sell cars.<br>
    <span style="color:var(--p);">Earn commission.</span><br>
    No stock needed.
  </h1>
  <p style="font-size:16px;color:var(--muted);line-height:1.7;margin-bottom:2rem;">
    SalesDesk connects independent brokers with verified dealerships across South Africa.
    Build your personal car desk, share your links, and earn a commission on every deal
    you close — all without owning a single car.
  </p>
  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:14px 32px;font-size:15px;">
      <i class="fa-solid fa-arrow-right"></i> Create your SalesDesk — free
    </a>
    <a href="/c/" class="pub-btn pub-btn-ghost"
       style="padding:14px 24px;font-size:15px;">
      Browse cars
    </a>
  </div>
  <p style="font-size:12px;color:var(--faint);margin-top:12px;">
    Free to join. No monthly fees. Commission only.
  </p>
</div>

<!-- ── How it works ──────────────────────────────────────────── -->
<div style="margin-bottom:3rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:22px;font-weight:800;
                letter-spacing:-.02em;">How it works</div>
    <div style="font-size:14px;color:var(--muted);margin-top:6px;">
      From sign-up to commission in 4 steps
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
    <?php
    $steps = [
      ['fa-user-plus',      '1. Sign up',        'Create your free SalesDesk account. Set your broker slug — e.g. salesdesk.co.za/sipho'],
      ['fa-car',            '2. Pick cars',       'Browse the dealer marketplace. Add cars to your personal desk — up to 10 at a time.'],
      ['fa-share-nodes',    '3. Share your links','Every car gets a unique tracked link. Share via WhatsApp, Facebook, or your website.'],
      ['fa-sack-dollar',    '4. Earn commission', 'When a buyer you referred closes a deal, you earn a commission — straight to your bank.'],
    ];
    foreach ($steps as [$icon, $title, $body]):
    ?>
    <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);
                padding:20px;box-shadow:var(--shadow-sm);">
      <div style="width:44px;height:44px;border-radius:var(--r-md);background:var(--p-light);
                  color:var(--p);display:flex;align-items:center;justify-content:center;
                  font-size:18px;margin-bottom:14px;">
        <i class="fa-solid <?= $icon ?>"></i>
      </div>
      <div style="font-family:var(--font-d);font-size:14px;font-weight:700;margin-bottom:6px;">
        <?= $title ?>
      </div>
      <div style="font-size:13px;color:var(--muted);line-height:1.65;">
        <?= $body ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Commission explainer ──────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#08143c,var(--p));border-radius:var(--r-xl);
            padding:2.5rem;margin-bottom:3rem;color:#fff;"
     class="pub-reveal">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;align-items:center;
              flex-wrap:wrap;">
    <div>
      <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
                  color:rgba(255,255,255,.5);margin-bottom:8px;">Your earnings</div>
      <div style="font-family:var(--font-d);font-size:30px;font-weight:800;
                  margin-bottom:6px;letter-spacing:-.02em;">
        Keep <?= 100 - $defaultFee ?>% of every commission
      </div>
      <div style="font-size:14px;color:rgba(255,255,255,.65);line-height:1.65;">
        Dealers set a fixed rand amount or a percentage of the sale price.
        SalesDesk takes <?= $defaultFee ?>%. The rest is yours — paid via EFT.
      </div>
    </div>
    <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);
                border-radius:var(--r-lg);padding:20px;">
      <div style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:12px;font-weight:600;">
        Example deal
      </div>
      <?php
      $examplePrice = 350000;
      $exampleCommPct = 2.5;
      $exampleGross = round($examplePrice * $exampleCommPct / 100);
      $exampleFee   = round($exampleGross * $defaultFee / 100);
      $exampleNet   = $exampleGross - $exampleFee;
      $rows = [
        ['Sale price',         'R ' . number_format($examplePrice),   false],
        ['Commission (2.5%)',  'R ' . number_format($exampleGross),   false],
        ['Platform fee (' . $defaultFee . '%)', '- R ' . number_format($exampleFee), false],
        ['You receive',        'R ' . number_format($exampleNet),     true],
      ];
      foreach ($rows as [$label, $value, $highlight]):
      ?>
      <div style="display:flex;justify-content:space-between;
                  padding:8px 0;border-bottom:1px solid rgba(255,255,255,.08);">
        <span style="font-size:13px;color:rgba(255,255,255,.6);"><?= $label ?></span>
        <span style="font-family:var(--font-d);font-size:<?= $highlight ? '18px' : '13px' ?>;
                     font-weight:<?= $highlight ? '800' : '500' ?>;
                     color:<?= $highlight ? '#4ade80' : '#fff' ?>;">
          <?= $value ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Features ──────────────────────────────────────────────── -->
<div style="margin-bottom:3rem;" class="pub-reveal">
  <div style="font-family:var(--font-d);font-size:22px;font-weight:800;
              text-align:center;margin-bottom:2rem;letter-spacing:-.02em;">
    Everything you need to sell
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <?php
    $features = [
      ['fa-id-card',         'Personal SalesDesk URL',    'Your own branded page at salesdesk.co.za/{your-name} — share it anywhere.'],
      ['fa-link',            'Tracked share links',       'Every car gets a unique link. We know exactly which sale you sourced.'],
      ['fa-lock',            'Protected attribution',     'Your commission is cryptographically locked at lead submission. No disputes.'],
      ['fa-chart-line',      'Live analytics',            'See views, leads, and conversion rates for every car on your desk.'],
      ['fa-building',        'Organisation support',      'Join a broker organisation and share leads with your team.'],
      ['fa-mobile-screen',   'Mobile-first design',       'Share a link from your phone in seconds. Works perfectly on any device.'],
    ];
    foreach ($features as [$icon, $title, $desc]):
    ?>
    <div style="background:var(--white);border:1px solid var(--border);border-radius:var(--r-lg);
                padding:16px;display:flex;gap:12px;align-items:flex-start;
                box-shadow:var(--shadow-sm);">
      <div style="width:36px;height:36px;border-radius:var(--r-sm);background:var(--p-light);
                  color:var(--p);display:flex;align-items:center;justify-content:center;
                  font-size:14px;flex-shrink:0;">
        <i class="fa-solid <?= $icon ?>"></i>
      </div>
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px;">
          <?= $title ?>
        </div>
        <div style="font-size:12px;color:var(--muted);line-height:1.6;"><?= $desc ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── FAQ ───────────────────────────────────────────────────── -->
<div style="margin-bottom:3rem;" class="pub-reveal">
  <div style="font-family:var(--font-d);font-size:22px;font-weight:800;
              text-align:center;margin-bottom:1.5rem;letter-spacing:-.02em;">
    Common questions
  </div>
  <?php
  $faqs = [
    ['Do I need to be a licensed dealer?',
     'No. SalesDesk is designed for independent brokers who refer buyers to dealerships. You act as a lead generator — the dealer handles the actual sale.'],
    ['How do I get paid?',
     'Once a dealer marks a lead as closed, a commission record is created. Our admin team approves and processes EFT payments to your registered bank account, typically within 7 business days.'],
    ['What if a buyer contacts the dealer directly after I shared the link?',
     'Your tracking link is cryptographically locked at the moment the buyer submits their enquiry. If they use your link first, the attribution is yours — even if they call the dealer later.'],
    ['How many cars can I list on my desk?',
     'New brokers can list up to 10 active cars. Speak to your account manager or admin to increase your limit.'],
    ['Is there a monthly fee?',
     'No. SalesDesk is completely free to use as a broker. We only take our platform fee when a deal closes and commission is paid.'],
  ];
  foreach ($faqs as $i => [$question, $answer]):
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
      <?= htmlspecialchars($answer) ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<!-- ── Final CTA ─────────────────────────────────────────────── -->
<div style="text-align:center;padding:2.5rem;background:var(--white);
            border:1px solid var(--border);border-radius:var(--r-xl);
            box-shadow:var(--shadow-md);" class="pub-reveal">
  <div style="font-family:var(--font-d);font-size:24px;font-weight:800;
              margin-bottom:8px;letter-spacing:-.02em;">
    Ready to start earning?
  </div>
  <p style="font-size:14px;color:var(--muted);margin-bottom:1.5rem;max-width:400px;
            margin-left:auto;margin-right:auto;">
    Create your SalesDesk in under 5 minutes. No credit card required.
  </p>
  <a href="/auth/register.php" class="pub-btn pub-btn-primary"
     style="padding:14px 40px;font-size:15px;display:inline-flex;">
    <i class="fa-solid fa-rocket"></i> Get started free
  </a>
  <div style="margin-top:12px;font-size:12px;color:var(--faint);">
    Already have an account?
    <a href="/auth/login.php" style="color:var(--p);">Sign in</a>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
