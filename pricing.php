<?php
/**
 * SalesDesk — Pricing
 * Route: /pricing   (top-level .php → extensionless via .htaccess §6;
 *                    `pricing` is also reserved in §7 and in
 *                    SD_RESERVED_DESK_SLUGS so no desk can take the URL)
 *
 * Fully driven by the live plan catalogue — edit a plan in
 * Admin → Plans (name, tagline, price, limits, "Offered") and this page
 * shows the change on the next load. Nothing per plan is hard-coded here:
 *
 *   • includes/pricing.php  sdPricingCatalogue()  — active plans per audience,
 *     feature lines and comparison cells from SD_LIMIT_KEYS, the highlighted
 *     plan (cheapest plan sold online), platform fee, billing facts
 *   • Buttons depend on who is looking (guest / broker / dealer) via
 *     sdPricingCta(), so the page is sent with Cache-Control: private.
 *   • JSON-LD: one schema.org Service per audience with an Offer per plan,
 *     plus FAQPage (same answers as the visible FAQ).
 *
 * Styles: assets/css/pricing.css · Script: assets/js/pricing.js (audience
 * switch; without JS both audiences show, one under the other).
 */

declare(strict_types=1);

require_once 'includes/security.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/session.php';
require_once 'includes/pricing.php';
require_once 'includes/structured-data.php';
require_once 'includes/assets.php';   // sdAsset() for the stylesheet link

// Prices change from Admin → Plans and the buttons depend on the visitor:
// never let a browser or proxy serve a stale or someone else's copy.
header('Cache-Control: private, no-cache, max-age=0');

$cat  = sdPricingCatalogue();
$role = !empty($_SESSION['user_id']) ? (string) ($_SESSION['user_role'] ?? '') : '';
$fee  = $cat['fee_percent'];

// The broker's own plan, for the "Your plan" tag.
$myPlanId = null;
if ($role === 'broker' && $cat['ready']) {
    try {
        $mine     = sdAccountPlan('broker', (int) $_SESSION['user_id']);
        $myPlanId = $mine ? (int) $mine['id'] : null;
    } catch (Throwable) {}
}

// Which audience opens first: ?for=dealerships, or a signed-in dealer.
$for = (string) ($_GET['for'] ?? '');
$initial = in_array($for, ['brokers', 'dealerships'], true)
    ? $for
    : ($role === 'dealer' ? 'dealerships' : 'brokers');

$brokerFreeCars = sdPricingFreeLimit($cat, 'broker', 'max_desk_cars');
$bizPlans       = $cat['audiences']['organization']['plans'] ?? [];
$hasVip         = (bool) array_filter($bizPlans, static fn ($p) => $p['cells']['vip_perks']['on'] ?? false);
$hasInvoiced    = (bool) array_filter($bizPlans, static fn ($p) => $p['is_invoiced']);
$hasOnline      = (bool) array_filter(
    array_merge($cat['audiences']['broker']['plans'] ?? [], $bizPlans),
    static fn ($p) => !$p['is_free'] && !$p['is_invoiced']
);

// ── What every plan includes (product facts, not plan limits) ─────
$includes = [
    'broker' => [
        ['fa-id-card',          'Your own SalesDesk page to share'],
        ['fa-car-side',         'Cars from verified dealerships, commission shown upfront'],
        ['fa-link',             'Every lead attributed to you from the first click'],
        ['fa-money-bill-wave',  'Commission tracked and paid out by EFT'],
    ],
    'organization' => [
        ['fa-cloud-arrow-up',   'Upload cars or import your stock'],
        ['fa-percent',          'You set the commission on every car'],
        ['fa-people-group',     'Brokers across South Africa sell your cars'],
        ['fa-handshake',        'Pay commission only when a car sells'],
    ],
];

$vipPerks = [
    ['fa-lock',            'Network-only stock',  'Keep chosen cars off the public marketplace — only your network’s brokers can sell them.'],
    ['fa-gift',            'Network bonus',       'Add a bonus on top of commission for brokers in your network.'],
    ['fa-certificate',     'VIP network badge',   'Your brokers’ desks show they sell for your network.'],
    ['fa-chart-column',    'Network dashboard',   'Sales, leads and commission by broker, with CSV export.'],
    ['fa-bullhorn',        'Announcements',       'Send news and specials to every broker in your network.'],
];

// ── FAQ (answers use the live values) ────────────────────────────
$faqs = [];
$faqs[] = ['Is SalesDesk free to use?',
    $cat['ready'] && ($cat['audiences']['broker']['free'] ?? null)
        ? 'Yes. Brokers and dealerships can join and use SalesDesk on the Free plan with no card and no contract. Paid plans are optional and only add room to grow.'
        : 'Yes. Brokers and dealerships can join SalesDesk free of charge, with no card and no contract.'];
$faqs[] = ['How does SalesDesk make money?',
    'A platform fee of ' . $fee . '% is deducted from the broker’s commission when a sale pays out. Dealerships are not charged the fee. Optional paid plans add more cars, networks and network tools.'];
$faqs[] = ['Do prices include VAT?', 'No. All prices are shown excluding VAT.'];
if ($hasOnline) {
    $faqs[] = ['How do I pay for a paid plan?',
        'Upgrade from the Plan page in your account. Plans sold online are billed monthly by card through PayFast, and you can cancel any time — you keep the plan until the end of the month you’ve paid for.'];
}
if ($hasInvoiced) {
    $names = implode(' and ', array_map(static fn ($p) => $p['name'], array_filter($bizPlans, static fn ($p) => $p['is_invoiced'])));
    $faqs[] = ['How does ' . $names . ' billing work?',
        $names . ' is set up with our team and invoiced monthly. You pay by EFT within ' . $cat['invoice_due_days'] . ' days, using the invoice number as the reference.'];
}
$faqs[] = ['What happens if I go back to Free?',
    'Nothing is deleted. If you have more than the Free plan allows, you get ' . $cat['grace_days'] . ' days to choose what stays live. Anything over the limit is paused, not removed, and comes back when you upgrade again.'];
if ($cat['ready']) {
    $faqs[] = ['Can I change plans later?',
        'Yes. Upgrade whenever you need more room — the new limits apply as soon as your payment goes through.'];
}
if ($hasVip) {
    $faqs[] = ['What are VIP network perks?',
        'Tools for dealerships that run their own broker network: network-only stock, a network bonus on top of commission, a VIP badge on your brokers’ desks, a network dashboard and announcements.'];
}

// ── Page meta ────────────────────────────────────────────────────
$pageTitle      = 'Pricing | SalesDesk';
$ogTitle        = 'SalesDesk pricing — free to start for brokers and dealerships';
$ogDescription  = 'Start free on SalesDesk. Brokers earn commission on every sale; dealerships pay commission only when a car sells. Paid plans add more cars, networks and VIP network tools.';
$canonicalUrl   = (defined('SITE_URL') ? SITE_URL : '') . '/pricing';
$shareUrl       = $canonicalUrl;
$shareTitle     = $ogTitle;
$layoutVariant  = 'wide';
$showBreadcrumb = false;
$includeBrowseCss     = false;
$includeHowItWorksCss = false;
$bodyClass      = 'pr-page';
$extraCss       = '<link rel="stylesheet" href="' . htmlspecialchars(sdAsset('/assets/css/pricing.css')) . '">' . "\n";
$extraJs        = ['/assets/js/pricing.js'];

$schemaBase = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

ob_start();
?>
<section class="pr-hero">
  <div class="sd-container pr-hero__inner">
    <span class="pr-eyebrow">Pricing</span>
    <h1 class="pr-hero__title">Start free. Upgrade when you need more room.</h1>
    <p class="pr-hero__sub">
      Brokers earn commission on every car they sell. Dealerships pay commission only when a car sells.
      Paid plans are optional.
    </p>

    <?php if ($cat['ready'] && count($cat['audiences']) > 1): ?>
    <div class="pr-switch" role="group" aria-label="Show pricing for" data-pr-switch data-initial="<?= htmlspecialchars($initial) ?>">
      <?php foreach (SD_PRICING_AUDIENCES as $aud => $meta): if (empty($cat['audiences'][$aud]['plans'])) continue; ?>
      <a class="pr-switch__btn" href="?for=<?= $meta['anchor'] ?>#<?= $meta['anchor'] ?>" data-pr-target="<?= $meta['anchor'] ?>"
         aria-pressed="<?= $initial === $meta['anchor'] ? 'true' : 'false' ?>">For <?= strtolower($meta['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!$cat['ready']): ?>
<section class="pr-section">
  <div class="sd-container pr-fallback">
    <h2 class="pr-sec-title">Free to join</h2>
    <p>Brokers and dealerships join SalesDesk free of charge. We take a <?= $fee ?>% platform fee from the broker’s commission when a sale pays out.</p>
    <a class="pub-btn pub-btn-primary" href="/auth/register">Join free</a>
  </div>
</section>
<?php else: ?>

<?php foreach (SD_PRICING_AUDIENCES as $aud => $meta):
    $plans = $cat['audiences'][$aud]['plans'] ?? [];
    if (!$plans) continue;
    $isBroker = $aud === 'broker';
    $cols = min(count($plans), 3); ?>
<section class="pr-section pr-panel" id="<?= $meta['anchor'] ?>" data-pr-panel="<?= $meta['anchor'] ?>" aria-labelledby="pr-h-<?= $meta['anchor'] ?>">
  <div class="sd-container">
    <div class="pr-sec-head">
      <span class="pr-eyebrow pr-eyebrow--soft">For <?= strtolower($meta['label']) ?></span>
      <h2 class="pr-sec-title" id="pr-h-<?= $meta['anchor'] ?>">
        <?= $isBroker ? 'Sell cars without owning stock' : 'Get a national sales force for your stock' ?>
      </h2>
      <p class="pr-sec-sub">
        <?= $isBroker
            ? 'Add cars from verified dealerships to your desk, share them and earn commission when they sell.'
            : 'List your cars once. Brokers across the country bring you buyers, and you pay commission only on a sale.' ?>
      </p>
    </div>

    <div class="pr-cards pr-cards--<?= $cols ?>">
      <?php foreach ($plans as $p):
        $cta  = sdPricingCta($p, $role, $cat);
        $isMy = $myPlanId !== null && $myPlanId === $p['id']; ?>
      <article class="pr-card<?= $p['featured'] ? ' is-featured' : '' ?><?= $isMy ? ' is-mine' : '' ?>" id="plan-<?= htmlspecialchars($p['code']) ?>">
        <?php if ($isMy): ?>
        <span class="pr-card__flag pr-card__flag--mine">Your plan</span>
        <?php elseif ($p['featured']): ?>
        <span class="pr-card__flag">Most popular</span>
        <?php endif; ?>
        <h3 class="pr-card__name"><?= htmlspecialchars($p['name']) ?></h3>
        <?php if ($p['tagline']): ?><p class="pr-card__tagline"><?= htmlspecialchars($p['tagline']) ?></p><?php endif; ?>

        <div class="pr-card__price">
          <?php if ($p['is_free']): ?>
          <strong>Free</strong><span class="pr-card__per">forever</span>
          <?php else: ?>
          <strong><?= htmlspecialchars($p['price_text']) ?></strong><span class="pr-card__per"><?= $p['per_text'] ?></span>
          <?php endif; ?>
        </div>
        <p class="pr-card__billing">
          <?php if ($p['is_free']): ?>No card, no contract
          <?php elseif ($p['is_invoiced']): ?>Excl. VAT · invoiced monthly, pay by EFT
          <?php else: ?>Excl. VAT · billed monthly by card · cancel any time
          <?php endif; ?>
        </p>

        <ul class="pr-card__list">
          <?php foreach ($p['features'] as $f): ?>
          <li class="<?= $f['on'] ? '' : 'is-off' ?>">
            <i class="fa-solid <?= $f['on'] ? htmlspecialchars($f['icon']) : 'fa-xmark' ?>" aria-hidden="true"></i>
            <span><?= htmlspecialchars($f['text']) ?></span>
            <?php if (!$f['on']): ?><span class="sr-only">(not included)</span><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>

        <div class="pr-card__cta">
          <a class="pub-btn <?= $p['featured'] ? 'pub-btn-primary' : 'pub-btn-ghost' ?> pub-btn-full" href="<?= htmlspecialchars($cta['href']) ?>"><?= htmlspecialchars($cta['label']) ?></a>
          <?php if ($cta['note'] !== ''): ?><p class="pr-card__note"><?= htmlspecialchars($cta['note']) ?></p><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="pr-includes">
      <h3 class="pr-includes__title">Every plan includes</h3>
      <ul class="pr-includes__list">
        <?php foreach ($includes[$aud] as [$icon, $text]): ?>
        <li><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i> <?= htmlspecialchars($text) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if (!$isBroker && $hasVip): ?>
    <div class="pr-vip" id="vip-perks">
      <div class="pr-vip__head">
        <span class="pr-vip__icon"><i class="fa-solid fa-crown" aria-hidden="true"></i></span>
        <div>
          <h3 class="pr-vip__title">VIP network perks</h3>
          <p class="pr-vip__sub">Included with <?= htmlspecialchars(implode(' and ', array_map(static fn ($p) => $p['name'], array_filter($plans, static fn ($p) => $p['cells']['vip_perks']['on'] ?? false)))) ?>. For dealerships that run their own broker network.</p>
        </div>
      </div>
      <ul class="pr-vip__grid">
        <?php foreach ($vipPerks as [$icon, $title, $desc]): ?>
        <li class="pr-vip__item">
          <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i>
          <strong><?= htmlspecialchars($title) ?></strong>
          <span><?= htmlspecialchars($desc) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (count($plans) > 1): ?>
    <div class="pr-compare">
      <h3 class="pr-compare__title">Compare plans for <?= strtolower($meta['label']) ?></h3>
      <div class="pr-compare__scroll" tabindex="0" role="region" aria-label="Compare plans for <?= strtolower($meta['label']) ?>">
        <table class="pr-table">
          <thead>
            <tr>
              <th scope="col"><span class="sr-only">Feature</span></th>
              <?php foreach ($plans as $p): ?>
              <th scope="col"<?= $p['featured'] ? ' class="is-featured"' : '' ?>>
                <span class="pr-table__plan"><?= htmlspecialchars($p['name']) ?></span>
                <span class="pr-table__price"><?= htmlspecialchars($p['price_text'] . $p['per_text']) ?></span>
              </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach (SD_LIMIT_KEYS as $key => $lk): if ($lk['audience'] !== $aud) continue; ?>
            <tr>
              <th scope="row"><?= htmlspecialchars($lk['label']) ?></th>
              <?php foreach ($plans as $p): $c = $p['cells'][$key]; ?>
              <td<?= $p['featured'] ? ' class="is-featured"' : '' ?>>
                <?php if ($lk['type'] === 'bool'): ?>
                <i class="fa-solid <?= $c['on'] ? 'fa-check pr-yes' : 'fa-minus pr-no' ?>" aria-hidden="true"></i><span class="sr-only"><?= $c['on'] ? 'Included' : 'Not included' ?></span>
                <?php else: ?>
                <?= htmlspecialchars($c['text']) ?>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <tr>
              <th scope="row">Platform fee</th>
              <?php foreach ($plans as $p): ?>
              <td<?= $p['featured'] ? ' class="is-featured"' : '' ?>><?= $isBroker ? $fee . '% of commission' : 'None' ?></td>
              <?php endforeach; ?>
            </tr>
            <tr>
              <th scope="row">Billing</th>
              <?php foreach ($plans as $p): ?>
              <td<?= $p['featured'] ? ' class="is-featured"' : '' ?>><?= $p['is_free'] ? 'No monthly fee' : ($p['is_invoiced'] ? 'Invoice · EFT' : 'Card · monthly') ?></td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php endif; ?>

<section class="pr-fee">
  <div class="sd-container pr-fee__inner">
    <div class="pr-fee__stat">
      <strong><?= $fee ?>%</strong>
      <span>platform fee</span>
    </div>
    <div class="pr-fee__text">
      <h2 class="pr-fee__title">We earn when you earn</h2>
      <p>
        On every plan, SalesDesk takes <?= $fee ?>% of the broker’s commission when a sale pays out.
        Dealerships aren’t charged the fee, there’s no cost per lead, and no one pays anything for a car that doesn’t sell.
      </p>
    </div>
  </div>
</section>

<section class="pr-section pr-faq-wrap" id="faq">
  <div class="sd-container pr-faq">
    <h2 class="pr-sec-title">Questions about pricing</h2>
    <div class="pr-faq__list">
      <?php foreach ($faqs as $i => [$q, $a]): ?>
      <details class="pr-faq__item"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= htmlspecialchars($q) ?><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
        <p><?= htmlspecialchars($a) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="pr-faq__more">
      More detail: <a href="/how-it-works/brokers">how it works for brokers</a> ·
      <a href="/how-it-works/dealers">how it works for dealerships</a> ·
      <a href="mailto:<?= htmlspecialchars($cat['sales_email']) ?>"><?= htmlspecialchars($cat['sales_email']) ?></a>
    </p>
  </div>
</section>

<section class="pr-cta">
  <div class="sd-container pr-cta__inner">
    <h2 class="pr-cta__title">Ready to start?</h2>
    <p class="pr-cta__sub">
      <?= $brokerFreeCars !== null
          ? 'Your Free desk holds up to ' . number_format($brokerFreeCars, 0, '.', ' ') . ' cars — plenty to get going.'
          : 'Create your free account in a few minutes.' ?>
    </p>
    <div class="pr-cta__actions">
      <?php if ($role === ''): ?>
      <a class="pub-btn pub-btn-accent pub-btn-lg" href="/auth/register">Join free</a>
      <a class="pub-btn pub-btn-on-ink pub-btn-lg" href="/auth/login">Sign in</a>
      <?php elseif ($role === 'broker' || $role === 'dealer'): ?>
      <a class="pub-btn pub-btn-accent pub-btn-lg" href="<?= sdBillingPlanPage($role === 'dealer' ? 'organization' : 'broker') ?>">Go to your plan</a>
      <?php else: ?>
      <a class="pub-btn pub-btn-accent pub-btn-lg" href="/how-it-works/brokers">See how it works</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php
if ($cat['ready']) {
    foreach (sdPricingSchema($cat, $schemaBase) as $svc) {
        echo sdJsonLd($svc);
    }
}
echo renderFaqSchema($faqs);

$pageContent = ob_get_clean();
require_once 'views/layout-public.php';
