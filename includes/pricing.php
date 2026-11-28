<?php
/**
 * SalesDesk — Public pricing catalogue  (pricing page)
 *
 * One place that turns the live plan catalogue (plans + plan_limits, edited
 * in Admin → Plans) into what the public sees: /pricing, and the "what it
 * costs" copy on /how-it-works/brokers and /how-it-works/dealers.
 *
 * Nothing here is hard-coded per plan. Names, taglines, prices, limits and
 * "Offered" (is_active) all come from the database on every request, so an
 * edit in Admin → Plans shows on the public pages straight away:
 *
 *   • Plans with "Offered" unticked are left out.
 *   • Every limit key in SD_LIMIT_KEYS becomes a feature line and a row in
 *     the comparison table — a new key shows up without touching this page.
 *   • The platform fee comes from platform_config.platform_fee_percent.
 *   • Invoiced plans (SD_INVOICED_PLAN_CODES) say "Invoiced monthly · EFT"
 *     and get a "Talk to us" button instead of a sign-up.
 *
 * Before db/0019_plans.sql runs, sdPricingCatalogue()['ready'] is false and
 * callers fall back to "free to join" copy.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/billing.php';      // SD_INVOICED_PLAN_CODES, sdBillingAvailable()
require_once __DIR__ . '/plan_grace.php';   // SD_GRACE_DAYS
require_once __DIR__ . '/invoicing.php';    // SD_INVOICE_DUE_DAYS

/** Where the public "Talk to us" buttons send people. */
const SD_PRICING_SALES_EMAIL_DEFAULT = 'sales@salesdesk.co.za';

/** Audiences in the order they appear on /pricing, with their public names. */
const SD_PRICING_AUDIENCES = [
    'broker'       => ['label' => 'Brokers',       'anchor' => 'brokers'],
    'organization' => ['label' => 'Dealerships',   'anchor' => 'dealerships'],
];

/**
 * The public catalogue.
 *
 * @return array{
 *   ready: bool, fee_percent: int, online_billing: bool, sales_email: string,
 *   grace_days: int, invoice_due_days: int, billing_grace_days: int,
 *   audiences: array<string, array{plans: array<int, array>, free: ?array, from_paid: ?array}>
 * }
 */
function sdPricingCatalogue(): array
{
    $out = [
        'ready'              => false,
        'fee_percent'        => 10,
        'online_billing'     => false,
        'sales_email'        => SD_PRICING_SALES_EMAIL_DEFAULT,
        'grace_days'         => SD_GRACE_DAYS,
        'invoice_due_days'   => SD_INVOICE_DUE_DAYS,
        'billing_grace_days' => SD_BILLING_GRACE_DAYS,
        'audiences'          => [],
    ];
    try {
        $out['fee_percent'] = getPlatformConfigInt('platform_fee_percent', 10);
        $email = trim(getPlatformConfig('sales_contact_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out['sales_email'] = $email;
        }
        $out['online_billing'] = sdBillingAvailable();
    } catch (Throwable $e) {
        error_log('[SalesDesk] pricing: config read failed: ' . $e->getMessage());
    }

    if (!sdPlansReady()) {
        return $out;
    }

    foreach (array_keys(SD_PRICING_AUDIENCES) as $audience) {
        $plans = array_values(array_filter(
            sdPlans($audience),
            static fn ($p) => $p['is_active']
        ));
        usort($plans, static fn ($a, $b) => [$a['sort_order'], $a['price_cents'], $a['id']] <=> [$b['sort_order'], $b['price_cents'], $b['id']]);

        $free     = null;
        $fromPaid = null;
        foreach ($plans as $p) {
            if ($p['price_cents'] <= 0 && $free === null) {
                $free = $p;
            }
            if ($p['price_cents'] > 0 && ($fromPaid === null || $p['price_cents'] < $fromPaid['price_cents'])) {
                $fromPaid = $p;
            }
        }
        // Highlight the cheapest plan you can buy online (Pro); invoiced
        // plans are "talk to us", so they are never the highlighted one.
        $featuredId    = null;
        $featuredCents = PHP_INT_MAX;
        foreach ($plans as $p) {
            if ($p['price_cents'] > 0 && !sdPlanIsInvoiced($p) && $p['price_cents'] < $featuredCents) {
                $featuredId    = $p['id'];
                $featuredCents = $p['price_cents'];
            }
        }

        $out['audiences'][$audience] = [
            'plans'     => array_map(static fn ($p) => sdPricingDecorate($p, $p['id'] === $featuredId), $plans),
            'free'      => $free,
            'from_paid' => $fromPaid,
        ];
    }
    $out['ready'] = true;
    return $out;
}

/** Adds the display fields /pricing needs to one plan row. */
function sdPricingDecorate(array $plan, bool $featured): array
{
    $cents = (int) $plan['price_cents'];
    $plan['is_free']      = $cents <= 0;
    $plan['is_invoiced']  = sdPlanIsInvoiced($plan);
    $plan['featured']     = $featured;
    $plan['price_text']   = $cents <= 0 ? 'R0' : sdPriceText($cents);
    $plan['per_text']     = $plan['billing_interval'] === 'year' ? '/year' : '/month';
    $plan['price_amount'] = number_format($cents / 100, 2, '.', '');
    $plan['features']     = [];
    $plan['cells']        = [];
    foreach (SD_LIMIT_KEYS as $key => $meta) {
        if ($meta['audience'] !== $plan['audience']) {
            continue;
        }
        $value = sdPlanLimit($plan, $key);
        $plan['features'][] = sdPricingFeature($key, $value);
        $plan['cells'][$key] = [
            'text' => sdLimitText($value, $key),
            'on'   => $value === null || $value > 0,
        ];
    }
    return $plan;
}

/**
 * One feature line for a limit value.
 *
 * @return array{text: string, on: bool, icon: string, key: string}
 */
function sdPricingFeature(string $key, ?int $value): array
{
    $on = $value === null || $value > 0;
    $n  = $value === null ? '' : number_format($value, 0, '.', ' ');
    [$text, $icon] = match ($key) {
        'max_desk_cars'       => [$value === null ? 'Unlimited cars on your desk' : 'Up to ' . $n . ' cars on your desk', 'fa-car'],
        'max_networks'        => [$value === null ? 'Join unlimited dealer networks'
                                  : ($value === 0 ? 'No dealer networks' : 'Join up to ' . $n . ' dealer network' . ($value === 1 ? '' : 's')), 'fa-people-group'],
        'max_dealerships'     => [$value === null ? 'Unlimited dealerships' : ($value === 1 ? '1 dealership' : 'Up to ' . $n . ' dealerships'), 'fa-store'],
        'max_network_brokers' => [$value === null ? 'Unlimited brokers in your network'
                                  : ($value === 0 ? 'No broker network' : 'Up to ' . $n . ' broker' . ($value === 1 ? '' : 's') . ' in your network'), 'fa-people-group'],
        'can_invite_brokers'  => [$on ? 'Invite brokers to your network' : 'Brokers apply to join your network', 'fa-envelope-open-text'],
        'vip_perks'           => [$on ? 'VIP network perks' : 'No VIP network perks', 'fa-crown'],
        default               => [(SD_LIMIT_KEYS[$key]['label'] ?? $key) . ': ' . sdLimitText($value, $key), 'fa-check'],
    };
    // "Brokers apply to join" is how Free works, not a missing feature.
    if ($key === 'can_invite_brokers' && !$on) {
        $on = true;
        $icon = 'fa-user-check';
    }
    return ['text' => $text, 'on' => $on, 'icon' => $icon, 'key' => $key];
}

/**
 * Short public price line for one audience, for the how-it-works pages:
 * "Free plan, or Pro from R149/month". Falls back to "Free" before plans.
 */
function sdPricingSummaryLine(array $cat, string $audience): string
{
    $a = $cat['audiences'][$audience] ?? null;
    if (!$cat['ready'] || !$a) {
        return 'Free';
    }
    $paid = $a['from_paid'];
    if (!$paid) {
        return $a['free'] ? 'Free' : 'See pricing';
    }
    $price = sdPriceText((int) $paid['price_cents']) . ($paid['billing_interval'] === 'year' ? '/year' : '/month');
    return $a['free']
        ? 'Free plan, or ' . $paid['name'] . ' from ' . $price . ' (optional)'
        : $paid['name'] . ' from ' . $price;
}

/** The Free plan's value for a key in one audience (null = unlimited / no Free plan). */
function sdPricingFreeLimit(array $cat, string $audience, string $key): ?int
{
    $free = $cat['audiences'][$audience]['free'] ?? null;
    return $free ? sdPlanLimit($free, $key) : null;
}

/**
 * Where a plan's button goes for this visitor.
 *
 * @return array{href: string, label: string, note: string, mailto: bool}
 */
function sdPricingCta(array $plan, string $role, array $cat): array
{
    $audience = $plan['audience'];
    $page     = sdBillingPlanPage($audience);
    $mine     = ($audience === 'broker' && $role === 'broker')
             || ($audience === 'organization' && $role === 'dealer');

    if ($plan['is_invoiced']) {
        if ($mine) {
            return ['href' => $page, 'label' => 'Talk to us about ' . $plan['name'], 'note' => 'Ask from your Plan page — we’ll set it up.', 'mailto' => false];
        }
        $subject = rawurlencode('SalesDesk ' . $plan['name'] . ' plan');
        return ['href' => 'mailto:' . $cat['sales_email'] . '?subject=' . $subject, 'label' => 'Talk to us', 'note' => 'We’ll set it up and invoice you monthly.', 'mailto' => true];
    }
    if ($mine) {
        return $plan['is_free']
            ? ['href' => $page, 'label' => 'See your plan', 'note' => '', 'mailto' => false]
            : ['href' => $page, 'label' => 'Upgrade to ' . $plan['name'], 'note' => $cat['online_billing'] ? 'Pay monthly by card · cancel any time.' : 'Request it from your Plan page.', 'mailto' => false];
    }
    if ($role !== '') {
        // Signed in as someone this plan isn't for (e.g. a buyer or exec).
        return ['href' => '/auth/register', 'label' => $plan['is_free'] ? 'Get started' : 'Get ' . $plan['name'], 'note' => '', 'mailto' => false];
    }
    return $plan['is_free']
        ? ['href' => '/auth/register', 'label' => 'Join free', 'note' => 'No card needed.', 'mailto' => false]
        : ['href' => '/auth/register', 'label' => 'Start free, then upgrade', 'note' => 'Create your free account, then upgrade from your Plan page.', 'mailto' => false];
}

/**
 * schema.org Offers for /pricing (one Service per audience).
 *
 * @return array<int, array>
 */
function sdPricingSchema(array $cat, string $baseUrl): array
{
    $services = [];
    foreach (SD_PRICING_AUDIENCES as $audience => $meta) {
        $plans = $cat['audiences'][$audience]['plans'] ?? [];
        if (!$plans) {
            continue;
        }
        $offers = [];
        foreach ($plans as $p) {
            $offers[] = [
                '@type'              => 'Offer',
                'name'               => $p['name'],
                'description'        => (string) ($p['tagline'] ?? ''),
                'price'              => $p['price_amount'],
                'priceCurrency'      => 'ZAR',
                'url'                => $baseUrl . '/pricing#' . $meta['anchor'],
                'priceSpecification' => [
                    '@type'                 => 'UnitPriceSpecification',
                    'price'                 => $p['price_amount'],
                    'priceCurrency'         => 'ZAR',
                    'unitCode'              => $p['billing_interval'] === 'year' ? 'ANN' : 'MON',
                    'valueAddedTaxIncluded' => false,
                ],
            ];
        }
        $services[] = [
            '@context'   => 'https://schema.org',
            '@type'      => 'Service',
            'name'       => 'SalesDesk for ' . strtolower($meta['label']),
            'serviceType'=> 'Car sales platform',
            'areaServed' => ['@type' => 'Country', 'name' => 'South Africa'],
            'provider'   => ['@type' => 'Organization', 'name' => 'SalesDesk', 'url' => $baseUrl . '/'],
            'offers'     => $offers,
        ];
    }
    return $services;
}
