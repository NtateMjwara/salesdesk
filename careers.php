<?php
/**
 * SalesDesk — Careers
 * Route: /careers  (add `careers` to the reserved-paths RewriteRule in
 * .htaccess alongside brokers|dealers|privacy|terms|sitemap so the
 * extensionless URL resolves — footer already links to "/careers").
 *
 * No `jobs` table exists in the current schema, so openings are a small
 * static array below. Swap $openings for a DB query later — the render
 * loop doesn't care where the array comes from. Filtering (department /
 * location / type) is done client-side since the dataset is tiny; no
 * extra request round-trip needed for a page like this.
 */

declare(strict_types=1);

require_once 'includes/security.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/session.php';

applyCachePolicy('public');

// ── Open positions (edit this array to update listings) ────────
$openings = [
    [
        'title'      => 'Senior Backend Engineer (PHP)',
        'dept'       => 'Engineering',
        'location'   => 'Johannesburg, GP',
        'type'       => 'Full-time',
        'remote'     => 'Hybrid',
        'slug'       => 'senior-backend-engineer',
        'blurb'      => 'Own core marketplace systems — leads, commissions, payouts — at a scale where correctness actually matters.',
    ],
    [
        'title'      => 'Product Designer',
        'dept'       => 'Design',
        'location'   => 'Cape Town, WC',
        'type'       => 'Full-time',
        'remote'     => 'Remote',
        'slug'       => 'product-designer',
        'blurb'      => 'Shape the buyer and broker experience end-to-end, from wireframe to shipped component.',
    ],
    [
        'title'      => 'Dealer Success Manager',
        'dept'       => 'Operations',
        'location'   => 'Johannesburg, GP',
        'type'       => 'Full-time',
        'remote'     => 'On-site',
        'slug'       => 'dealer-success-manager',
        'blurb'      => 'Be the face of SalesDesk for our dealer network — onboarding, verification, and retention.',
    ],
    [
        'title'      => 'Growth Marketer',
        'dept'       => 'Marketing',
        'location'   => 'Remote (ZA)',
        'type'       => 'Full-time',
        'remote'     => 'Remote',
        'slug'       => 'growth-marketer',
        'blurb'      => 'Run acquisition across SEO, paid, and lifecycle for both the buyer marketplace and broker network.',
    ],
    [
        'title'      => 'Data Analyst (Marketplace)',
        'dept'       => 'Engineering',
        'location'   => 'Johannesburg, GP',
        'type'       => 'Contract',
        'remote'     => 'Hybrid',
        'slug'       => 'data-analyst-marketplace',
        'blurb'      => 'Turn leads, listings, and payout data into the dashboards that run the business.',
    ],
    [
        'title'      => 'Customer Support Agent',
        'dept'       => 'Operations',
        'location'   => 'Durban, KZN',
        'type'       => 'Full-time',
        'remote'     => 'On-site',
        'slug'       => 'customer-support-agent',
        'blurb'      => 'Front line for buyers and brokers — enquiries, disputes, and the occasional saved deal.',
    ],
];

$departments = array_values(array_unique(array_column($openings, 'dept')));
sort($departments);
$locations = array_values(array_unique(array_column($openings, 'location')));
sort($locations);

// ── Page meta ────────────────────────────────────────────────
$pageTitle     = 'Careers | SalesDesk';
$ogTitle       = 'Careers at SalesDesk — Build South Africa\'s Car Marketplace';
$ogDescription = 'Join SalesDesk. Open roles in engineering, design, operations, and marketing — remote-friendly, South Africa based.';
$canonicalUrl  = (defined('SITE_URL') ? SITE_URL : '') . '/careers';
$shareUrl      = $canonicalUrl;
$shareTitle    = $ogTitle;
$layoutVariant = 'wide';
$showBreadcrumb = false;

ob_start();
?>

<!-- ══════════════════════════════════════════════════
     HERO
     ══════════════════════════════════════════════════ -->
<section class="cr-hero">
  <div class="cr-container cr-hero__inner">
    <span class="cr-eyebrow">We're hiring</span>
    <h1 class="cr-hero__title">Help build South Africa&rsquo;s independent car marketplace</h1>
    <p class="cr-hero__sub">
      SalesDesk connects brokers, dealers, and buyers across the country. We're a small
      team shipping fast — if that sounds good, we'd like to meet you.
    </p>
    <div class="cr-hero__stats">
      <div class="cr-hero__stat">
        <div class="cr-hero__stat-num"><?= count($openings) ?></div>
        <div class="cr-hero__stat-lbl">Open roles</div>
      </div>
      <div class="cr-hero__stat">
        <div class="cr-hero__stat-num">9</div>
        <div class="cr-hero__stat-lbl">Provinces reached</div>
      </div>
      <div class="cr-hero__stat">
        <div class="cr-hero__stat-num">Remote-friendly</div>
        <div class="cr-hero__stat-lbl">Where the role allows</div>
      </div>
    </div>
    <a href="#openings" class="cr-btn cr-btn-primary">
      View open positions <i class="fa-solid fa-arrow-down"></i>
    </a>
  </div>
</section>

<!-- ══════════════════════════════════════════════════
     VALUES
     ══════════════════════════════════════════════════ -->
<section class="cr-section">
  <div class="cr-container">
    <div class="cr-sec-head">
      <span class="cr-eyebrow">How we work</span>
      <h2 class="cr-sec-title">What it&rsquo;s actually like here</h2>
    </div>
    <div class="cr-values-grid">
      <div class="cr-value-card">
        <div class="cr-value-icon"><i class="fa-solid fa-bolt"></i></div>
        <h3 class="cr-value-title">Ship, then refine</h3>
        <p class="cr-value-desc">We'd rather learn from something real in front of users than polish something in a doc for a month.</p>
      </div>
      <div class="cr-value-card">
        <div class="cr-value-icon"><i class="fa-solid fa-scale-balanced"></i></div>
        <h3 class="cr-value-title">Ownership, not oversight</h3>
        <p class="cr-value-desc">Every function has a named owner. You'll know exactly what's yours, and you won't need permission to fix it.</p>
      </div>
      <div class="cr-value-card">
        <div class="cr-value-icon"><i class="fa-solid fa-house-laptop"></i></div>
        <h3 class="cr-value-title">Flexible by default</h3>
        <p class="cr-value-desc">Remote, hybrid, or in-office — we hire for the role's needs, not a blanket policy.</p>
      </div>
      <div class="cr-value-card">
        <div class="cr-value-icon"><i class="fa-solid fa-chart-line"></i></div>
        <h3 class="cr-value-title">Real equity in the outcome</h3>
        <p class="cr-value-desc">Early team members get meaningful stake — we want people who'd want SalesDesk to win regardless.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════
     BENEFITS STRIP
     ══════════════════════════════════════════════════ -->
<section class="cr-benefits">
  <div class="cr-container cr-benefits__grid">
    <div class="cr-benefit">
      <i class="fa-solid fa-briefcase-medical"></i>
      <span>Medical aid contribution</span>
    </div>
    <div class="cr-benefit">
      <i class="fa-solid fa-umbrella-beach"></i>
      <span>Uncapped annual leave</span>
    </div>
    <div class="cr-benefit">
      <i class="fa-solid fa-laptop"></i>
      <span>Home office setup budget</span>
    </div>
    <div class="cr-benefit">
      <i class="fa-solid fa-graduation-cap"></i>
      <span>Learning &amp; conference budget</span>
    </div>
    <div class="cr-benefit">
      <i class="fa-solid fa-car-side"></i>
      <span>Staff vehicle discounts</span>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════
     OPEN POSITIONS
     ══════════════════════════════════════════════════ -->
<section class="cr-section" id="openings">
  <div class="cr-container">
    <div class="cr-sec-head">
      <span class="cr-eyebrow">Join the team</span>
      <h2 class="cr-sec-title">Open positions</h2>
    </div>

    <!-- Filters -->
    <div class="cr-filters" role="group" aria-label="Filter open positions">
      <div class="cr-filter-group">
        <label class="cr-filter-label" for="crDept">Department</label>
        <select class="cr-filter-select" id="crDept">
          <option value="">All departments</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cr-filter-group">
        <label class="cr-filter-label" for="crLoc">Location</label>
        <select class="cr-filter-select" id="crLoc">
          <option value="">All locations</option>
          <?php foreach ($locations as $l): ?>
          <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cr-filter-group cr-filter-group--search">
        <label class="cr-filter-label" for="crSearch">Search</label>
        <div class="cr-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="crSearch" class="cr-filter-input" placeholder="Search roles…" autocomplete="off">
        </div>
      </div>
    </div>

    <!-- Listings -->
    <div class="cr-job-list" id="crJobList">
      <?php foreach ($openings as $job): ?>
      <article class="cr-job-card"
                data-dept="<?= htmlspecialchars($job['dept']) ?>"
                data-loc="<?= htmlspecialchars($job['location']) ?>"
                data-search="<?= htmlspecialchars(strtolower($job['title'] . ' ' . $job['dept'] . ' ' . $job['blurb'])) ?>">
        <div class="cr-job-card__main">
          <div class="cr-job-card__top">
            <h3 class="cr-job-card__title"><?= htmlspecialchars($job['title']) ?></h3>
            <span class="cr-job-card__dept-badge"><?= htmlspecialchars($job['dept']) ?></span>
          </div>
          <p class="cr-job-card__blurb"><?= htmlspecialchars($job['blurb']) ?></p>
          <div class="cr-job-card__meta">
            <span><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($job['location']) ?></span>
            <span><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($job['type']) ?></span>
            <span><i class="fa-solid fa-house-signal"></i> <?= htmlspecialchars($job['remote']) ?></span>
          </div>
        </div>
        <a href="/careers/apply.php?role=<?= urlencode($job['slug']) ?>" class="cr-job-card__cta">
          Apply <i class="fa-solid fa-arrow-right"></i>
        </a>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="cr-empty" id="crEmpty" style="display:none;">
      <div class="cr-empty__icon"><i class="fa-regular fa-folder-open"></i></div>
      <div class="cr-empty__title">No roles match those filters</div>
      <div class="cr-empty__sub">Try clearing a filter, or send us a speculative application below.</div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════
     CTA — SPECULATIVE / GENERAL
     ══════════════════════════════════════════════════ -->
<section class="cr-cta">
  <div class="cr-container cr-cta__inner">
    <div class="cr-cta__text">
      <h2 class="cr-cta__title">Don&rsquo;t see the right role?</h2>
      <p class="cr-cta__sub">
        We're growing quickly and always keen to hear from strong people, even without an open req.
      </p>
    </div>
    <a href="mailto:careers@salesdesk.co.za" class="cr-btn cr-btn-light">
      <i class="fa-solid fa-paper-plane"></i> Send a speculative application
    </a>
  </div>
</section>

<style>
/* ══════════════════════════════════════════════════════════
   CAREERS — self-contained (mirrors news-* container pattern,
   doesn't depend on home.css or any other page's classes)
   ══════════════════════════════════════════════════════════ */
.cr-container {
  max-width: 1120px;
  margin-inline: auto;
  padding-inline: clamp(20px, 4vw, 48px);
}

.cr-eyebrow {
  display: inline-block;
  font-family: var(--font-d);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--p);
  margin-bottom: 10px;
}

.cr-btn {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  font-family: var(--sans);
  font-weight: 600;
  font-size: 14px;
  padding: 13px 26px;
  border-radius: var(--r-md);
  text-decoration: none;
  border: none;
  cursor: pointer;
  transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.cr-btn-primary { background: var(--p); color: #fff; }
.cr-btn-primary:hover { background: var(--p-dark); text-decoration: none; transform: translateY(-1px); }
.cr-btn-light { background: #fff; color: #08143c; }
.cr-btn-light:hover { text-decoration: none; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(0,0,0,.18); }

/* ══════════════════════════════════════════════════════════
   HERO
   ══════════════════════════════════════════════════════════ */
.cr-hero {
  background:
    linear-gradient(135deg, rgba(8,20,60,.92) 0%, rgba(15,76,158,.85) 100%),
    url('https://images.unsplash.com/photo-1521737604893-d14cc237f11d?q=80&w=1600&auto=format&fit=crop');
  background-size: cover;
  background-position: center;
  padding: clamp(64px, 10vw, 108px) 0 clamp(56px, 8vw, 84px);
  position: relative;
  overflow: hidden;
}
.cr-hero::after {
  content: '';
  position: absolute;
  top: -60px; right: -60px;
  width: 260px; height: 260px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
  pointer-events: none;
}
.cr-hero__inner { position: relative; z-index: 1; max-width: 760px; }
.cr-hero .cr-eyebrow { color: #93c5fd; }
.cr-hero__title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: clamp(30px, 4.6vw, 48px);
  line-height: 1.12;
  letter-spacing: -.015em;
  color: #fff;
  margin-bottom: 18px;
}
.cr-hero__sub {
  font-size: clamp(14px, 1.6vw, 16.5px);
  line-height: 1.7;
  color: rgba(255,255,255,.78);
  max-width: 620px;
  margin-bottom: clamp(28px, 4vw, 40px);
}
.cr-hero__stats {
  display: flex;
  gap: clamp(24px, 4vw, 48px);
  margin-bottom: clamp(28px, 4vw, 40px);
  flex-wrap: wrap;
}
.cr-hero__stat-num {
  font-family: var(--font-d);
  font-size: clamp(20px, 2.4vw, 26px);
  font-weight: 800;
  color: #fff;
  letter-spacing: -.01em;
}
.cr-hero__stat-lbl {
  font-size: 11.5px;
  color: rgba(255,255,255,.55);
  margin-top: 2px;
}

/* ══════════════════════════════════════════════════════════
   SECTIONS — shared head
   ══════════════════════════════════════════════════════════ */
.cr-section { padding: clamp(48px, 7vw, 76px) 0; }
.cr-sec-head { margin-bottom: clamp(28px, 3.5vw, 36px); }
.cr-sec-title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: clamp(24px, 3vw, 32px);
  color: #08143c;
  letter-spacing: -.01em;
}

/* ══════════════════════════════════════════════════════════
   VALUES
   ══════════════════════════════════════════════════════════ */
.cr-values-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 18px;
}
.cr-value-card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 24px 22px;
  box-shadow: var(--shadow-sm);
  transition: transform .3s cubic-bezier(.16,1,.3,1), box-shadow .3s ease;
}
.cr-value-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 16px 32px rgba(15,76,158,.1);
}
.cr-value-icon {
  width: 44px; height: 44px;
  border-radius: var(--r-md);
  background: var(--p-light);
  color: var(--p);
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
  margin-bottom: 16px;
}
.cr-value-title {
  font-family: var(--font-d);
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}
.cr-value-desc {
  font-size: 13px;
  line-height: 1.65;
  color: var(--muted);
}

/* ══════════════════════════════════════════════════════════
   BENEFITS STRIP
   ══════════════════════════════════════════════════════════ */
.cr-benefits {
  background: var(--p-light);
  border-top: 1px solid var(--p-b);
  border-bottom: 1px solid var(--p-b);
  padding: 22px 0;
}
.cr-benefits__grid {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: clamp(16px, 3vw, 40px);
}
.cr-benefit {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 13px;
  font-weight: 600;
  color: var(--p-dark);
  white-space: nowrap;
}
.cr-benefit i { color: var(--p); font-size: 15px; }

/* ══════════════════════════════════════════════════════════
   FILTERS
   ══════════════════════════════════════════════════════════ */
.cr-filters {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  align-items: flex-end;
  margin-bottom: 28px;
  padding: 18px;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-sm);
}
.cr-filter-group { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
.cr-filter-group--search { flex: 1; min-width: 220px; }
.cr-filter-label {
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--faint);
}
.cr-filter-select, .cr-filter-input {
  height: 40px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  padding: 0 12px;
  font-size: 13px;
  font-family: var(--sans);
  color: var(--text);
  background: #fff;
  outline: none;
  transition: border-color .18s;
  width: 100%;
}
.cr-filter-select:focus, .cr-filter-input:focus { border-color: var(--p); }
.cr-search-wrap { position: relative; }
.cr-search-wrap i {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: var(--faint); font-size: 12px; pointer-events: none;
}
.cr-search-wrap .cr-filter-input { padding-left: 32px; }

/* ══════════════════════════════════════════════════════════
   JOB LIST
   ══════════════════════════════════════════════════════════ */
.cr-job-list { display: flex; flex-direction: column; gap: 12px; }

.cr-job-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 20px 24px;
  box-shadow: var(--shadow-sm);
  transition: transform .22s cubic-bezier(.16,1,.3,1), box-shadow .22s ease, border-color .2s ease;
}
.cr-job-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 14px 30px rgba(15,76,158,.1);
  border-color: #c7d6f5;
}
.cr-job-card__main { flex: 1; min-width: 0; }
.cr-job-card__top {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.cr-job-card__title {
  font-family: var(--font-d);
  font-size: 16px;
  font-weight: 700;
  color: var(--text);
}
.cr-job-card__dept-badge {
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  background: var(--p-light);
  color: var(--p);
  border: 1px solid var(--p-b);
  padding: 3px 9px;
  border-radius: var(--r-full);
}
.cr-job-card__blurb {
  font-size: 13px;
  color: var(--muted);
  line-height: 1.6;
  margin-bottom: 10px;
  max-width: 640px;
}
.cr-job-card__meta {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  font-size: 12px;
  color: var(--faint);
}
.cr-job-card__meta span { display: inline-flex; align-items: center; gap: 6px; }
.cr-job-card__meta i { color: var(--p); font-size: 11px; }

.cr-job-card__cta {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
  background: var(--p);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  font-family: var(--sans);
  padding: 10px 18px;
  border-radius: var(--r-md);
  text-decoration: none;
  transition: background .18s, gap .18s;
}
.cr-job-card__cta:hover { background: var(--p-dark); text-decoration: none; gap: 11px; }

/* ── empty state ── */
.cr-empty {
  text-align: center;
  padding: 56px 24px;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
}
.cr-empty__icon { font-size: 32px; color: var(--faint); margin-bottom: 12px; }
.cr-empty__title { font-family: var(--font-d); font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.cr-empty__sub { font-size: 13px; color: var(--faint); }

/* ══════════════════════════════════════════════════════════
   CTA
   ══════════════════════════════════════════════════════════ */
.cr-cta {
  background: linear-gradient(135deg, #08143c 0%, var(--p) 100%);
  padding: clamp(40px, 6vw, 64px) 0;
}
.cr-cta__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  flex-wrap: wrap;
}
.cr-cta__title {
  font-family: var(--serif);
  font-weight: 500;
  font-size: clamp(20px, 2.6vw, 26px);
  color: #fff;
  margin-bottom: 6px;
}
.cr-cta__sub {
  font-size: 13.5px;
  color: rgba(255,255,255,.7);
  max-width: 440px;
}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════════════════════ */
@media (max-width: 960px) {
  .cr-values-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 760px) {
  .cr-job-card { flex-direction: column; align-items: stretch; }
  .cr-job-card__cta { justify-content: center; }
  .cr-hero__stats { gap: 22px; }
}

@media (max-width: 640px) {
  .cr-values-grid { grid-template-columns: 1fr; }
  .cr-filters { flex-direction: column; align-items: stretch; }
  .cr-filter-group { min-width: 0; }
  .cr-cta__inner { flex-direction: column; align-items: flex-start; }
  .cr-cta .cr-btn { width: 100%; justify-content: center; }
}

@media (prefers-reduced-motion: reduce) {
  .cr-job-card, .cr-value-card, .cr-btn { transition: none !important; }
}
</style>

<script>
(function () {
  'use strict';

  var deptSel   = document.getElementById('crDept');
  var locSel    = document.getElementById('crLoc');
  var searchInp = document.getElementById('crSearch');
  var cards     = Array.from(document.querySelectorAll('.cr-job-card'));
  var emptyEl   = document.getElementById('crEmpty');
  var listEl    = document.getElementById('crJobList');

  function applyFilters() {
    var dept = deptSel.value;
    var loc  = locSel.value;
    var q    = searchInp.value.trim().toLowerCase();
    var visibleCount = 0;

    cards.forEach(function (card) {
      var matchesDept = !dept || card.dataset.dept === dept;
      var matchesLoc  = !loc  || card.dataset.loc  === loc;
      var matchesQ    = !q    || card.dataset.search.indexOf(q) !== -1;
      var show = matchesDept && matchesLoc && matchesQ;
      card.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });

    emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
    listEl.style.display  = visibleCount === 0 ? 'none'  : 'flex';
  }

  [deptSel, locSel].forEach(function (el) {
    el.addEventListener('change', applyFilters);
  });
  searchInp.addEventListener('input', applyFilters);
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once 'views/layout-public.php';
