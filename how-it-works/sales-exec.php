<?php
/**
 * SalesDesk — How It Works: Sales Executives
 * Route: /how-it-works/sales-exec.php
 *
 * Informational page explaining the sales executive role,
 * the dealer approval flow, personal performance tracking,
 * and how execs fit into the broader platform.
 * No auth required.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/functions.php';
require_once '../includes/session.php';

applyCachePolicy('public');
$visitor = initVisitorSession();

$pageTitle     = 'How It Works for Sales Executives | SalesDesk';
$ogTitle       = 'Upload Cars, Manage Leads, Build Your Track Record | SalesDesk';
$ogDescription = 'Sales executives on SalesDesk upload inventory on behalf of their dealership, manage their own lead pipeline, and build a personal performance record that belongs to them.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : 'https://salesdesk.co.za') . '/how-it-works/sales-exec.php';
$layoutVariant  = 'narrow';
$showBreadcrumb = true;
$breadcrumbs    = [
    ['How It Works', null],
    ['For Sales Executives', null],
];

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
            background:var(--white);color:var(--muted);border:1.5px solid var(--border);
            border-radius:var(--r-full);font-size:13px;font-weight:600;text-decoration:none;
            transition:all .18s;font-family:var(--font-d);"
     onmouseover="this.style.borderColor='var(--p)';this.style.color='var(--p)'"
     onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--muted)'">
    <i class="fa-solid fa-building-user"></i> Dealers
  </a>
  <a href="/how-it-works/sales-exec.php"
     style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;
            background:var(--amber);color:#fff;border-radius:var(--r-full);
            font-size:13px;font-weight:700;text-decoration:none;font-family:var(--font-d);">
    <i class="fa-solid fa-user-tie"></i> Sales Executives
  </a>
</div>

<!-- ═══════════════════════════════════════════════════════
     1. HERO
     ═══════════════════════════════════════════════════════ -->
<div style="text-align:center;padding:1rem 0 3.5rem;max-width:620px;margin:0 auto;"
     class="pub-anim">

  <div style="display:inline-flex;align-items:center;gap:8px;background:var(--amb-bg);
              border:1px solid var(--amb-b);border-radius:var(--r-full);
              padding:6px 18px;font-size:12px;font-weight:700;color:var(--amber);
              margin-bottom:1.5rem;font-family:var(--font-d);">
    <i class="fa-solid fa-user-tie"></i> For dealership sales staff
  </div>

  <h1 style="font-family:var(--font-d);font-size:clamp(30px,6vw,46px);font-weight:800;
             line-height:1.08;letter-spacing:-.03em;color:var(--text);margin-bottom:1.25rem;">
    Sell smarter.<br>
    <span style="color:var(--amber);">Build your own record.</span>
  </h1>

  <p style="font-size:16px;color:var(--muted);line-height:1.75;margin-bottom:2rem;">
    SalesDesk gives dealership sales executives their own login, their own lead
    pipeline, and their own performance analytics — attributed specifically to them,
    not just to the dealership. Upload cars, manage leads, and build a track record
    that proves your value.
  </p>

  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:1.25rem;">
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:14px 36px;font-size:15px;font-family:var(--font-d);
              background:var(--amber);">
      <i class="fa-solid fa-user-tie"></i> Join your dealership
    </a>
    <a href="/how-it-works/dealers.php" class="pub-btn pub-btn-ghost"
       style="padding:14px 24px;font-size:14px;">
      I'm a dealer principal
    </a>
  </div>

  <div style="display:flex;gap:20px;justify-content:center;flex-wrap:wrap;">
    <?php foreach ([
      ['fa-circle-check', 'var(--green)', 'Free to register'],
      ['fa-circle-check', 'var(--green)', 'Personal analytics'],
      ['fa-circle-check', 'var(--green)', 'Your own lead pipeline'],
      ['fa-circle-check', 'var(--green)', 'Approval required'],
    ] as [$icon, $col, $label]): ?>
    <span style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:5px;">
      <i class="fa-solid <?= $icon ?>" style="color:<?= $col ?>;font-size:11px;"></i>
      <?= $label ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     2. OVERVIEW DIAGRAM — WHERE YOU FIT
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Your place in the platform</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      Where sales executives fit
    </h2>
  </div>

  <div style="background:linear-gradient(145deg,#fffbeb,#fef9c3);border:1px solid var(--amb-b);
              border-radius:var(--r-xl);padding:2.5rem 2rem;position:relative;overflow:hidden;">

    <div style="position:absolute;top:-30px;right:-30px;width:160px;height:160px;
                border-radius:50%;background:rgba(180,83,9,.05);pointer-events:none;"></div>

    <!-- Top row: Dealer Principal above exec -->
    <div style="text-align:center;margin-bottom:1.5rem;">
      <div style="display:inline-flex;flex-direction:column;align-items:center;gap:8px;">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--gr-bg);
                    border:2px solid var(--gr-b);display:flex;align-items:center;
                    justify-content:center;box-shadow:0 4px 12px rgba(21,128,61,.12);">
          <i class="fa-solid fa-building-user" style="font-size:20px;color:var(--green);"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:13px;font-weight:700;color:var(--text);">
          Dealer Principal
        </div>
        <div style="font-size:11px;color:var(--muted);">Owns the dealership account · Approves access</div>
      </div>
    </div>

    <!-- Connector line down -->
    <div style="display:flex;justify-content:center;margin-bottom:1.5rem;">
      <div style="display:flex;flex-direction:column;align-items:center;gap:0;">
        <div style="width:2px;height:20px;background:var(--amb-b);"></div>
        <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--amber);"></i>
      </div>
    </div>

    <!-- Middle row: YOU (exec) -->
    <div style="text-align:center;margin-bottom:1.5rem;">
      <div style="display:inline-flex;flex-direction:column;align-items:center;gap:8px;
                  position:relative;">
        <div style="width:72px;height:72px;border-radius:50%;
                    background:linear-gradient(135deg,var(--amber),#d97706);
                    display:flex;align-items:center;justify-content:center;
                    box-shadow:0 8px 24px rgba(180,83,9,.25);">
          <i class="fa-solid fa-user-tie" style="font-size:26px;color:#fff;"></i>
        </div>
        <div style="position:absolute;top:-8px;right:-28px;background:var(--p);
                    color:#fff;font-size:9px;font-weight:800;border-radius:var(--r-full);
                    padding:2px 6px;font-family:var(--font-d);">YOU</div>
        <div style="font-family:var(--font-d);font-size:15px;font-weight:800;
                    color:var(--amber);">Sales Executive</div>
        <div style="font-size:11px;color:var(--muted);">
          Upload cars · Manage leads · Track your performance
        </div>
      </div>
    </div>

    <!-- Bottom row: two outputs -->
    <div style="display:flex;justify-content:center;gap:32px;margin-bottom:0;">
      <!-- Connector lines -->
      <div style="display:flex;flex-direction:column;align-items:center;">
        <div style="width:2px;height:20px;background:var(--p-b);"></div>
        <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--p);"></i>
        <div style="margin-top:8px;background:var(--p-light);border:1.5px solid var(--p-b);
                    border-radius:var(--r-lg);padding:12px 16px;text-align:center;width:140px;">
          <i class="fa-solid fa-car" style="font-size:18px;color:var(--p);display:block;margin-bottom:6px;"></i>
          <div style="font-family:var(--font-d);font-size:12px;font-weight:700;color:var(--text);">
            Car listings
          </div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px;">
            Attributed to you
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;align-items:center;">
        <div style="width:2px;height:20px;background:var(--gr-b);"></div>
        <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--green);"></i>
        <div style="margin-top:8px;background:var(--gr-bg);border:1.5px solid var(--gr-b);
                    border-radius:var(--r-lg);padding:12px 16px;text-align:center;width:140px;">
          <i class="fa-solid fa-inbox" style="font-size:18px;color:var(--green);display:block;margin-bottom:6px;"></i>
          <div style="font-family:var(--font-d);font-size:12px;font-weight:700;color:var(--text);">
            Lead pipeline
          </div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px;">
            Your leads, your results
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     3. THE APPROVAL PROCESS — TRANSPARENT
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Before you go live</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      The approval process, explained
    </h2>
    <p style="font-size:14px;color:var(--muted);margin-top:8px;max-width:500px;
              margin-left:auto;margin-right:auto;line-height:1.7;">
      When you register as a sales executive, your account enters a short pending
      state while your dealer principal verifies you. Here's exactly what happens.
    </p>
  </div>

  <!-- Timeline -->
  <div style="position:relative;">
    <!-- Vertical line -->
    <div style="position:absolute;left:25px;top:26px;bottom:26px;width:2px;
                background:var(--border);border-radius:1px;"></div>

    <?php
    $approvalSteps = [
      [
        'fa-user-plus', 'var(--p)', 'var(--p-light)', 'var(--p-b)',
        'You register',
        'Create your account and select your role as Sales Executive. Search for your dealership by name — it must already be registered on SalesDesk. Fill in your profile details.',
        false,
        null
      ],
      [
        'fa-building-user', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
        'Request sent to dealer principal',
        'Your registration triggers a notification to the dealer principal. They can see your name, profile, and request in their team management dashboard. Your account status is set to <strong>Pending</strong>.',
        false,
        'pending'
      ],
      [
        'fa-clock', 'var(--faint)', 'var(--bg)', 'var(--border)',
        'Wait for approval — usually within 24 hours',
        'During this time your account exists but you cannot upload cars or manage leads. You\'ll receive an email the moment your principal approves or rejects your request. If you haven\'t heard back in 48 hours, contact your principal directly.',
        true,
        null
      ],
      [
        'fa-circle-check', 'var(--green)', 'var(--gr-bg)', 'var(--gr-b)',
        'Principal approves — you\'re live',
        'Once approved, your account status changes to <strong>Verified</strong>. You can immediately start uploading cars and managing leads. Your personal dashboard and analytics are ready to use.',
        false,
        'active'
      ],
    ];
    foreach ($approvalSteps as $i => [$icon, $color, $bg, $border, $title, $body, $isWait, $badge]):
    ?>
    <div style="display:flex;gap:20px;margin-bottom:<?= $i < count($approvalSteps) - 1 ? '28px' : '0' ?>;
                position:relative;">
      <!-- Icon node -->
      <div style="width:52px;height:52px;border-radius:50%;background:<?= $bg ?>;
                  border:2px solid <?= $border ?>;flex-shrink:0;
                  display:flex;align-items:center;justify-content:center;
                  position:relative;z-index:1;box-shadow:0 2px 8px rgba(0,0,0,.07);
                  <?= $isWait ? 'opacity:.7;' : '' ?>">
        <i class="fa-solid <?= $icon ?>" style="font-size:18px;color:<?= $color ?>;
           <?= $isWait ? 'animation:spin 2s linear infinite;' : '' ?>"></i>
      </div>
      <!-- Content -->
      <div style="flex:1;padding-top:12px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
          <h3 style="font-family:var(--font-d);font-size:15px;font-weight:700;
                     color:var(--text);margin:0;"><?= $title ?></h3>
          <?php if ($badge === 'pending'): ?>
          <span style="font-size:10px;font-weight:700;background:var(--amb-bg);
                       color:var(--amber);border:1px solid var(--amb-b);
                       border-radius:var(--r-full);padding:2px 8px;font-family:var(--mono);">
            PENDING
          </span>
          <?php elseif ($badge === 'active'): ?>
          <span style="font-size:10px;font-weight:700;background:var(--gr-bg);
                       color:var(--green);border:1px solid var(--gr-b);
                       border-radius:var(--r-full);padding:2px 8px;font-family:var(--mono);">
            VERIFIED
          </span>
          <?php endif; ?>
        </div>
        <p style="font-size:13px;color:var(--muted);line-height:1.75;margin:0;">
          <?= $body ?>
        </p>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  </style>

  <div style="margin-top:1.5rem;background:var(--amb-bg);border:1px solid var(--amb-b);
              border-radius:var(--r-md);padding:14px 18px;display:flex;gap:10px;
              align-items:flex-start;font-size:13px;color:var(--amber);">
    <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;margin-top:1px;"></i>
    <span><strong>Is your dealership not on SalesDesk yet?</strong> Ask your principal to register
    first at <a href="/auth/register.php" style="color:var(--amber);">salesdesk.co.za/auth/register.php</a>.
    You can only link to an existing dealership account.</span>
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
      From registration to your first lead
    </h2>
  </div>

  <?php
  $steps = [
    [
      'fa-user-plus', 'var(--p)', 'var(--p-light)', 'var(--p-b)',
      'Register and link to your dealership',
      'Create your SalesDesk account and select Sales Executive as your role. Search for your dealership by name — your principal will receive an approval request. Fill in your profile with a photo and contact details to build buyer confidence.',
      null
    ],
    [
      'fa-clock', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
      'Receive principal approval',
      'Your account is in a pending state until your dealer principal approves you. This usually takes less than 24 hours. You\'ll receive an email notification the moment your status changes. No action is needed from you during this window.',
      'You can still log in and explore your dashboard during the pending period — you just can\'t publish listings yet.'
    ],
    [
      'fa-car', 'var(--p)', 'var(--p-light)', 'var(--p-b)',
      'Upload and manage car listings',
      'Once approved, you can add cars to the dealership\'s inventory. Each listing you create is linked to your exec account — the principal sees who uploaded it, and your listings feed into the broker marketplace for lead generation.',
      null
    ],
    [
      'fa-inbox', 'var(--green)', 'var(--gr-bg)', 'var(--gr-b)',
      'Manage your lead pipeline',
      'Leads generated from cars you uploaded arrive in your personal pipeline. You can move them through stages — contacted, test drive, negotiating — and flag them for the principal when a deal is ready to close. Notes and activity history are tracked per lead.',
      null
    ],
    [
      'fa-chart-bar', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)',
      'Track your personal performance',
      'Your analytics dashboard shows views, leads, conversion rates, and deal history — all filtered to your own activity. This data is yours: it travels with your account, not with the dealership. If you move to a new employer, your track record moves with you.',
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
      <div style="background:var(--p-light);border:1px solid var(--p-b);
                  border-radius:var(--r-md);padding:10px 14px;
                  font-size:12px;color:var(--p);display:flex;gap:8px;align-items:flex-start;">
        <i class="fa-solid fa-circle-info" style="flex-shrink:0;margin-top:1px;"></i>
        <span><?= $callout ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     5. SCOPE CARD — WHAT YOU CAN / CAN'T DO
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:2rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Your role, clearly defined</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      What you can do as a sales executive
    </h2>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

    <!-- Can do -->
    <div style="background:var(--white);border:1.5px solid var(--gr-b);
                border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-sm);">
      <div style="background:var(--gr-bg);padding:14px 18px;border-bottom:1px solid var(--gr-b);
                  display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-circle-check" style="color:var(--green);font-size:14px;"></i>
        <div style="font-family:var(--font-d);font-size:13px;font-weight:800;color:var(--green);">
          What you can do
        </div>
      </div>
      <div style="padding:16px;">
        <?php foreach ([
          'Upload car listings on behalf of the dealership',
          'Edit and pause your own listings',
          'View and manage leads on your uploaded cars',
          'Move leads through pipeline stages',
          'Add notes and activity to lead records',
          'View your personal analytics dashboard',
          'Update your profile, photo, and contact details',
          'Link to a new dealership if you change employers',
        ] as $item): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:7px 0;
                    border-bottom:1px solid var(--border);">
          <i class="fa-solid fa-check" style="color:var(--green);font-size:11px;
             flex-shrink:0;margin-top:3px;"></i>
          <span style="font-size:13px;color:var(--text);"><?= $item ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Can't do -->
    <div style="background:var(--white);border:1.5px solid var(--border);
                border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-sm);">
      <div style="background:var(--bg);padding:14px 18px;border-bottom:1px solid var(--border);
                  display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-building-user" style="color:var(--faint);font-size:14px;"></i>
        <div style="font-family:var(--font-d);font-size:13px;font-weight:800;color:var(--muted);">
          Principal handles
        </div>
      </div>
      <div style="padding:16px;">
        <?php foreach ([
          'Confirm a deal as closed (triggers commission)',
          'Approve or reject other sales exec requests',
          'Manage the dealership\'s commission offers',
          'View analytics for other team members',
          'Delete or unpublish listings uploaded by others',
          'Manage the dealership\'s billing or account settings',
          'Submit CIPC documents for verification',
          'Set the dealership\'s brand and contact details',
        ] as $item): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:7px 0;
                    border-bottom:1px solid var(--border);">
          <i class="fa-solid fa-building-user" style="color:var(--faint);font-size:10px;
             flex-shrink:0;margin-top:3px;"></i>
          <span style="font-size:13px;color:var(--muted);"><?= $item ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div style="margin-top:1rem;background:var(--p-light);border:1px solid var(--p-b);
              border-radius:var(--r-md);padding:14px 18px;display:flex;gap:10px;
              align-items:flex-start;font-size:13px;color:var(--p);">
    <i class="fa-solid fa-circle-info" style="flex-shrink:0;margin-top:1px;"></i>
    <span>This division is intentional — it keeps commission and billing decisions
    in the hands of the account owner, while giving you full ownership over
    your listings and lead pipeline.</span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     6. YOUR PERFORMANCE RECORD
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="background:linear-gradient(135deg,#78350f,var(--amber));
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
        <i class="fa-solid fa-chart-bar"></i> Your data, your career
      </div>
      <h2 style="font-family:var(--font-d);font-size:26px;font-weight:800;
                 margin-bottom:10px;letter-spacing:-.02em;line-height:1.2;">
        A track record that belongs<br>to you — not the dealership.
      </h2>
      <p style="font-size:14px;color:rgba(255,255,255,.65);margin-bottom:2rem;
                line-height:1.7;max-width:520px;">
        Most dealership systems track performance at the floor level. SalesDesk
        attributes every listing, every lead, and every deal to the individual exec
        who drove it. That data is yours. It lives on your account, not the employer's.
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">
        <?php foreach ([
          ['fa-car', 'Cars uploaded', 'Every listing you publish is tagged to your account.'],
          ['fa-inbox', 'Leads generated', 'Enquiries on your cars, tracked over time.'],
          ['fa-handshake', 'Deals influenced', 'Pipeline milestones you moved a lead through.'],
          ['fa-chart-line', 'Conversion rate', 'Your personal lead-to-deal conversion over time.'],
        ] as [$icon, $title, $desc]): ?>
        <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
                    border-radius:var(--r-lg);padding:16px;">
          <i class="fa-solid <?= $icon ?>"
             style="font-size:20px;color:rgba(255,255,255,.5);margin-bottom:10px;display:block;"></i>
          <div style="font-family:var(--font-d);font-size:13px;font-weight:700;
                      margin-bottom:4px;"><?= $title ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,.5);line-height:1.55;">
            <?= $desc ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     7. ANALYTICS DASHBOARD MOCKUP
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="text-align:center;margin-bottom:1.75rem;">
    <div style="font-family:var(--font-d);font-size:11px;font-weight:700;
                letter-spacing:.1em;text-transform:uppercase;color:var(--faint);
                margin-bottom:8px;">Your dashboard</div>
    <h2 style="font-family:var(--font-d);font-size:24px;font-weight:800;
               letter-spacing:-.02em;color:var(--text);">
      See your performance at a glance
    </h2>
  </div>

  <div style="background:var(--white);border:1px solid var(--border);
              border-radius:var(--r-xl);overflow:hidden;box-shadow:var(--shadow-md);">
    <!-- Mock dashboard header -->
    <div style="background:var(--bg);border-bottom:1px solid var(--border);
                padding:16px 20px;display:flex;align-items:center;
                justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div>
        <div style="font-family:var(--font-d);font-size:15px;font-weight:700;color:var(--text);">
          My Performance
        </div>
        <div style="font-size:12px;color:var(--faint);margin-top:1px;">
          Last 30 days · Zanele Mokoena · Capitol Motors
        </div>
      </div>
      <span style="font-size:10px;font-weight:700;background:var(--gr-bg);
                   color:var(--green);border:1px solid var(--gr-b);
                   border-radius:var(--r-full);padding:3px 10px;
                   display:inline-flex;align-items:center;gap:4px;">
        <i class="fa-solid fa-circle-check" style="font-size:8px;"></i> Verified exec
      </span>
    </div>

    <!-- Stat tiles -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);
                border-bottom:1px solid var(--border);">
      <?php
      $stats = [
        ['12', 'Active listings', 'fa-car', 'var(--p)', 'var(--p-light)'],
        ['34', 'Total leads', 'fa-inbox', 'var(--green)', 'var(--gr-bg)'],
        ['8.8%', 'Conversion rate', 'fa-chart-line', 'var(--amber)', 'var(--amb-bg)'],
        ['3', 'Deals influenced', 'fa-handshake', '#7c3aed', 'var(--pur-bg)'],
      ];
      foreach ($stats as $i => [$val, $label, $icon, $color, $bg]):
      ?>
      <div style="padding:20px 16px;text-align:center;
                  <?= $i < count($stats) - 1 ? 'border-right:1px solid var(--border);' : '' ?>">
        <div style="width:36px;height:36px;border-radius:var(--r-sm);background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 10px;font-size:14px;color:<?= $color ?>;">
          <i class="fa-solid <?= $icon ?>"></i>
        </div>
        <div style="font-family:var(--font-d);font-size:22px;font-weight:800;
                    color:var(--text);"><?= $val ?></div>
        <div style="font-size:11px;color:var(--faint);margin-top:2px;"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Recent leads list -->
    <div style="padding:16px 20px;">
      <div style="font-family:var(--font-d);font-size:12px;font-weight:700;
                  color:var(--text);margin-bottom:12px;">Recent leads on your listings</div>
      <?php
      $leads = [
        ['TM', 'Thabo Molefe', '2023 VW Polo', 'New lead', 'var(--p)', 'var(--p-light)', 'var(--p-b)'],
        ['KD', 'Kgomotso Dlamini', '2022 Toyota RAV4', 'Contacted', 'var(--amber)', 'var(--amb-bg)', 'var(--amb-b)'],
        ['NM', 'Nosipho Mthembu', '2021 Ford Ranger', 'Test drive', '#7c3aed', 'var(--pur-bg)', 'var(--pur-b)'],
      ];
      foreach ($leads as [$init, $name, $car, $stage, $color, $bg, $border]):
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;
                  border-bottom:1px solid var(--border);">
        <div style="width:34px;height:34px;border-radius:50%;background:<?= $bg ?>;
                    border:1px solid <?= $border ?>;flex-shrink:0;
                    display:flex;align-items:center;justify-content:center;
                    font-family:var(--font-d);font-size:11px;font-weight:700;
                    color:<?= $color ?>;"><?= $init ?></div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:600;color:var(--text);"><?= $name ?></div>
          <div style="font-size:11px;color:var(--faint);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $car ?></div>
        </div>
        <span style="font-size:10px;font-weight:700;background:<?= $bg ?>;
                     color:<?= $color ?>;border:1px solid <?= $border ?>;
                     border-radius:var(--r-full);padding:2px 8px;white-space:nowrap;">
          <?= $stage ?>
        </span>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:8px;font-size:11px;color:var(--faint);text-align:center;">
        Illustrative — data shown is fictional
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     8. CAN I BECOME A BROKER?
     ═══════════════════════════════════════════════════════ -->
<div style="margin-bottom:4rem;" class="pub-reveal">
  <div style="background:var(--white);border:1px solid var(--border);
              border-radius:var(--r-xl);padding:2rem;box-shadow:var(--shadow-md);">
    <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">
      <div style="width:56px;height:56px;border-radius:var(--r-lg);
                  background:var(--p-light);border:1.5px solid var(--p-b);
                  display:flex;align-items:center;justify-content:center;
                  flex-shrink:0;font-size:22px;color:var(--p);">
        <i class="fa-solid fa-id-card"></i>
      </div>
      <div style="flex:1;min-width:240px;">
        <div style="font-family:var(--font-d);font-size:17px;font-weight:800;
                    color:var(--text);margin-bottom:6px;">
          Thinking about going independent?
        </div>
        <p style="font-size:13px;color:var(--muted);line-height:1.75;margin-bottom:1rem;">
          If you ever decide to operate as an independent auto broker — whether alongside
          your current role or after leaving dealership employment — you can register a
          separate SalesDesk broker account. Your exec history and broker history are
          tracked separately and don't interfere with each other.
        </p>
        <a href="/how-it-works/brokers.php"
           style="font-size:13px;color:var(--p);font-weight:600;text-decoration:none;">
          See how it works for brokers →
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
      Sales executive FAQ
    </h2>
  </div>

  <?php
  $faqs = [
    ['How long does approval take?',
     'It depends on how responsive your dealer principal is. Most approvals happen within a few hours during business hours. If you haven\'t been approved within 48 hours, contact your principal directly — they may not have seen the notification. Our team can also follow up on your behalf if needed.'],
    ['What if my dealership isn\'t registered on SalesDesk yet?',
     'You can only link to a dealership that already has an active SalesDesk account. Ask your principal to register first. Once they\'re set up, you can submit your request to join their team. Registration is free for dealers.'],
    ['Can I be linked to more than one dealership?',
     'No — a sales executive account is linked to a single dealership at a time. If you change employers, you can update your dealership link, but you\'ll need approval from the new principal before you go live again.'],
    ['Is my personal performance data visible to the dealer principal?',
     'Yes — your principal can see aggregated performance metrics for all their execs in their team dashboard. They cannot see personal notes you\'ve added to leads, but lead volume, stages, and deal history are visible at the dealership level.'],
    ['What happens to my data if I leave the dealership?',
     'Your account stays with you. Historical performance data (listings you uploaded, leads generated, stages moved) remains on your profile. When you link to a new dealership, new activity is attributed to that new account. Your record is cumulative and portable.'],
    ['Can I upload cars that the dealership didn\'t explicitly assign to me?',
     'Yes — any approved exec can upload any car on behalf of the dealership. There\'s no "assignment" system — all active inventory belongs to the dealership, and any verified exec can manage it. The distinction is that leads on cars you uploaded are attributed to your account.'],
    ['Do I earn commission when a deal closes on my listing?',
     'No — the commission model is between the dealer and the broker. As a sales executive, you\'re a dealership employee uploading inventory on their behalf. Your remuneration structure (salary, incentives, bonuses) is set by your employer, not by SalesDesk.'],
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
     'How it works for brokers', 'Earn commission independently — no stock required.'],
    ['/how-it-works/dealers.php', 'fa-building-user', 'var(--green)', 'var(--gr-bg)', 'var(--gr-b)',
     'How it works for dealers', 'Show your principal how the dealer side of SalesDesk works.'],
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
<div style="text-align:center;background:linear-gradient(135deg,#78350f,var(--amber));
            border-radius:var(--r-xl);padding:3rem 2rem;color:#fff;
            position:relative;overflow:hidden;" class="pub-reveal">
  <div style="position:absolute;top:-30px;right:-30px;width:180px;height:180px;
              border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;"></div>
  <div style="position:relative;z-index:1;">
    <div style="font-family:var(--font-d);font-size:28px;font-weight:800;
                margin-bottom:10px;letter-spacing:-.02em;line-height:1.15;">
      Ready to build your record?
    </div>
    <p style="font-size:15px;color:rgba(255,255,255,.65);margin-bottom:2rem;
              max-width:420px;margin-left:auto;margin-right:auto;line-height:1.7;">
      Register your sales executive account, link to your dealership,
      and start building a performance record that's entirely yours.
    </p>
    <a href="/auth/register.php" class="pub-btn pub-btn-primary"
       style="padding:16px 48px;font-size:16px;font-family:var(--font-d);
              background:#fff;color:var(--amber);display:inline-flex;">
      <i class="fa-solid fa-user-tie"></i> Join your dealership
    </a>
    <div style="margin-top:14px;font-size:12px;color:rgba(255,255,255,.45);">
      Already registered?
      <a href="/auth/login.php" style="color:rgba(255,255,255,.7);">Sign in to your exec portal</a>
    </div>
    <div style="margin-top:8px;font-size:12px;color:rgba(255,255,255,.35);">
      Your dealership needs to be on SalesDesk first.
      <a href="/how-it-works/dealers.php" style="color:rgba(255,255,255,.6);">
        Share this with your principal →
      </a>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
