<?php
/**
 * SalesDesk — Plans & entitlements  (0019, plans Phase 1)
 * T1 owns this file.
 *
 * THE ONE GATE for every plan limit. Pages never hardcode a limit: they
 * ask entitlement() / canDo() and show sdLimitText().
 *
 *   entitlement('broker', $userId, 'max_desk_cars')   → ?int  (null = unlimited)
 *   canDo('organization', $orgId, 'can_invite_brokers') → bool
 *
 * Resolution order for a limit:
 *   1. account_limit_overrides  (superadmin custom limit for that account)
 *   2. the account's plan       (subscriptions row that is current, else
 *                                the audience's default Free plan)
 *   3. SD_LIMIT_KEYS fallback   (only if a plan is missing the key)
 *
 * Account types
 *   broker        account_id = users.id (role broker)
 *   organization  account_id = organizations.id (= the business account,
 *                 from plans Phase 2). Sales execs inherit their dealership.
 *
 * Before db/0019_plans.sql has run, sdPlansReady() is false and brokers
 * keep the legacy limit (profiles.car_limit → platform default), so this
 * file can be deployed ahead of the migration.
 *
 * Spec: project doc claude/plans-and-billing.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Every limit key: who it applies to, how to show it, and the value used
 * only if a plan row is missing it.
 */
const SD_LIMIT_KEYS = [
    'max_desk_cars'       => ['audience' => 'broker',       'label' => 'Desk cars',               'type' => 'count', 'fallback' => 150],
    'max_networks'        => ['audience' => 'broker',       'label' => 'Networks they can join',  'type' => 'count', 'fallback' => 3],
    'max_dealerships'     => ['audience' => 'organization', 'label' => 'Dealerships',             'type' => 'count', 'fallback' => 1],
    'max_network_brokers' => ['audience' => 'organization', 'label' => 'Brokers in network',      'type' => 'count', 'fallback' => 5],
    'can_invite_brokers'  => ['audience' => 'organization', 'label' => 'Invite brokers',          'type' => 'bool',  'fallback' => 0],
    'vip_perks'           => ['audience' => 'organization', 'label' => 'VIP network perks',       'type' => 'bool',  'fallback' => 0],
];

const SD_ACCOUNT_TYPES = ['broker', 'organization'];

/** Subscription statuses that grant the plan (past_due = grace until billing decides). */
const SD_SUB_GRANTING = ['active', 'comped', 'past_due'];


// ============================================================
// READINESS + CATALOGUE
// ============================================================

/** True once db/0019_plans.sql has run. */
function sdPlansReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            Database::getInstance()->query("SELECT 1 FROM plans LIMIT 1");
            Database::getInstance()->query("SELECT 1 FROM account_limit_overrides LIMIT 1");
            $ready = true;
        } catch (Throwable) {
            $ready = false;
            error_log('[SalesDesk] Plans are off (legacy car limits in use): run db/0019_plans.sql');
        }
    }
    return $ready;
}

/**
 * The plan catalogue, each plan with a 'limits' map (key => ?int).
 *
 * @param string|null $audience 'broker' | 'organization' | null for all
 * @param bool        $fresh    bypass the per-request cache (after an edit)
 * @return array<int, array>  keyed by plan id, ordered by audience + sort_order
 */
function sdPlans(?string $audience = null, bool $fresh = false): array
{
    static $all = null;
    if (!sdPlansReady()) {
        return [];
    }
    if ($all === null || $fresh) {
        $pdo  = Database::getInstance();
        $rows = $pdo->query("
            SELECT id, code, audience, name, tagline, price_cents, billing_interval,
                   is_default, is_active, sort_order
            FROM plans
            ORDER BY audience, sort_order, id
        ")->fetchAll();
        $all = [];
        foreach ($rows as $r) {
            $r['id']          = (int) $r['id'];
            $r['price_cents'] = (int) $r['price_cents'];
            $r['is_default']  = (int) $r['is_default'] === 1;
            $r['is_active']   = (int) $r['is_active'] === 1;
            $r['limits']      = [];
            $all[$r['id']]    = $r;
        }
        foreach ($pdo->query("SELECT plan_id, limit_key, limit_value FROM plan_limits")->fetchAll() as $l) {
            $pid = (int) $l['plan_id'];
            if (isset($all[$pid])) {
                $all[$pid]['limits'][$l['limit_key']] = $l['limit_value'] === null ? null : (int) $l['limit_value'];
            }
        }
    }
    return $audience === null
        ? $all
        : array_filter($all, static fn ($p) => $p['audience'] === $audience);
}

function sdPlanByCode(string $code): ?array
{
    foreach (sdPlans() as $p) {
        if ($p['code'] === $code) {
            return $p;
        }
    }
    return null;
}

/** The plan an account without a subscription is on (Free). */
function sdDefaultPlan(string $audience): ?array
{
    $plans = sdPlans($audience);
    foreach ($plans as $p) {
        if ($p['is_default']) {
            return $p;
        }
    }
    return $plans ? reset($plans) : null;
}


// ============================================================
// ACCOUNT → PLAN → LIMIT
// ============================================================

/** The raw subscriptions row (any status) or null. */
function sdSubscription(string $type, int $accountId): ?array
{
    if (!sdPlansReady() || !in_array($type, SD_ACCOUNT_TYPES, true) || $accountId <= 0) {
        return null;
    }
    $stmt = Database::getInstance()->prepare("
        SELECT * FROM subscriptions WHERE account_type = ? AND account_id = ? LIMIT 1
    ");
    $stmt->execute([$type, $accountId]);
    return $stmt->fetch() ?: null;
}

/** Does this subscription row currently grant its plan? */
function sdSubscriptionGrants(?array $sub): bool
{
    if (!$sub || !in_array($sub['status'], SD_SUB_GRANTING, true)) {
        return false;
    }
    return empty($sub['current_period_end']) || strtotime((string) $sub['current_period_end']) > time();
}

/**
 * The plan in force for an account.
 *
 * Cached per request; $fresh re-reads it (3b: after a plan change in the
 * same request, e.g. the billing cron lapsing a subscription).
 *
 * @return array|null  plan row + 'subscription' (row|null) + 'source' ('subscription'|'default')
 */
function sdAccountPlan(string $type, int $accountId, bool $fresh = false): ?array
{
    static $cache = [];
    $k = $type . ':' . $accountId;
    if (!$fresh && array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $sub  = sdSubscription($type, $accountId);
    $plan = null;
    if (sdSubscriptionGrants($sub)) {
        $plan = sdPlans()[(int) $sub['plan_id']] ?? null;
    }
    $source = $plan ? 'subscription' : 'default';
    $plan ??= sdDefaultPlan($type);
    if ($plan) {
        $plan['subscription'] = $sub;
        $plan['source']       = $source;
    }
    return $cache[$k] = $plan;
}

/** Superadmin overrides for an account: key => row (limit_value ?int, reason, set_by, set_at). */
function sdLimitOverrides(string $type, int $accountId): array
{
    if (!sdPlansReady()) {
        return [];
    }
    $stmt = Database::getInstance()->prepare("
        SELECT o.limit_key, o.limit_value, o.reason, o.set_by, o.set_at,
               TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS set_by_name
        FROM account_limit_overrides o
        LEFT JOIN profiles p ON p.user_id = o.set_by
        WHERE o.account_type = ? AND o.account_id = ?
    ");
    $stmt->execute([$type, $accountId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['limit_value'] = $r['limit_value'] === null ? null : (int) $r['limit_value'];
        $out[$r['limit_key']] = $r;
    }
    return $out;
}

/**
 * Effective limit for an account. null = unlimited.
 * Unknown keys return 0 (deny) so a typo can never unlock something.
 */
function entitlement(string $type, int $accountId, string $key): ?int
{
    if (!isset(SD_LIMIT_KEYS[$key])) {
        error_log("[SalesDesk entitlements] unknown limit key '{$key}'");
        return 0;
    }
    if (!sdPlansReady()) {
        return SD_LIMIT_KEYS[$key]['fallback'];
    }
    $overrides = sdLimitOverrides($type, $accountId);
    if (array_key_exists($key, $overrides)) {
        return $overrides[$key]['limit_value'];
    }
    $plan = sdAccountPlan($type, $accountId);
    if ($plan && array_key_exists($key, $plan['limits'])) {
        return $plan['limits'][$key];
    }
    return SD_LIMIT_KEYS[$key]['fallback'];
}

/** Boolean keys (and "any room at all" for counts): null or > 0 = allowed. */
function canDo(string $type, int $accountId, string $key): bool
{
    $v = entitlement($type, $accountId, $key);
    return $v === null || $v > 0;
}

/** A plan's value for a key, respecting NULL = unlimited (never use ?? on limits). */
function sdPlanLimit(array $plan, string $key): ?int
{
    return array_key_exists($key, $plan['limits'] ?? [])
        ? $plan['limits'][$key]
        : (SD_LIMIT_KEYS[$key]['fallback'] ?? 0);
}

/** Is there room for one more? (count keys) */
function sdHasRoom(?int $limit, int $used): bool
{
    return $limit === null || $used < $limit;
}


// ============================================================
// BROKER HELPERS
// ============================================================

/**
 * Cars a broker may keep on their desk. null = unlimited.
 *
 * $orgCap: the desk organisation's agent car limit from
 * brokerCatalogueRules() — still honoured as an extra cap until plans
 * Phase 2 retires agent mode.
 */
function brokerDeskCarLimit(int $userId, ?int $orgCap = null): ?int
{
    if (!sdPlansReady()) {
        // Legacy (before 0019): per-broker override → org cap → platform default.
        $stmt = Database::getInstance()->prepare("SELECT car_limit FROM profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $own = $stmt->fetchColumn();
        if ($own !== false && $own !== null) {
            return (int) $own;
        }
        return $orgCap ?? getPlatformConfigInt('broker_car_limit_default', 10);
    }
    $limit = entitlement('broker', $userId, 'max_desk_cars');
    if ($orgCap !== null) {
        $limit = $limit === null ? $orgCap : min($limit, $orgCap);
    }
    return $limit;
}

/** Live (active) cars on a desk — what counts towards the limit. */
function brokerDeskCarCount(int $salesdeskId): int
{
    $stmt = Database::getInstance()->prepare("
        SELECT COUNT(*) FROM broker_inventory bi
        JOIN cars c ON c.id = bi.car_id
        WHERE bi.salesdesk_id = ? AND c.status = 'active'
    ");
    $stmt->execute([$salesdeskId]);
    return (int) $stmt->fetchColumn();
}


// ============================================================
// DISPLAY
// ============================================================

/** "150", "Unlimited", "Yes" / "No". */
function sdLimitText(?int $value, string $key = 'max_desk_cars'): string
{
    if ((SD_LIMIT_KEYS[$key]['type'] ?? 'count') === 'bool') {
        return ($value === null || $value > 0) ? 'Yes' : 'No';
    }
    return $value === null ? 'Unlimited' : number_format($value, 0, '.', ' ');
}

/** "Free", "R99", "R3 000" — prices exclude VAT. */
function sdPriceText(int $cents): string
{
    if ($cents <= 0) {
        return 'Free';
    }
    $rands = $cents / 100;
    return 'R' . number_format($rands, fmod($rands, 1.0) > 0 ? 2 : 0, '.', ' ');
}

/** "R99/month" or "Free". */
function sdPlanPriceLabel(array $plan): string
{
    $p = sdPriceText((int) $plan['price_cents']);
    return $p === 'Free' ? $p : $p . '/' . ($plan['billing_interval'] === 'year' ? 'year' : 'month');
}

function sdSubStatusLabel(?array $sub): string
{
    if (!$sub) {
        return '';
    }
    if (!sdSubscriptionGrants($sub)) {
        return $sub['status'] === 'cancelled' ? 'Cancelled' : 'Expired';
    }
    return match ($sub['status']) {
        'comped'   => 'Comped (free of charge)',
        'past_due' => 'Payment overdue',
        default    => 'Paid',
    };
}


// ============================================================
// SUPERADMIN WRITES (all audited)
// ============================================================

function sdEntGuard(int $actorId): ?string
{
    require_once __DIR__ . '/superadmin.php';
    if (!sdPlansReady()) {
        return 'Run 0019 Plans in Admin → Migrations first.';
    }
    return isSuperadmin($actorId) ? null : 'Only a superadmin can change plans and limits.';
}

/** Does the account exist with the right shape? */
function sdAccountExists(string $type, int $accountId): bool
{
    $pdo = Database::getInstance();
    if ($type === 'broker') {
        $s = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'broker' LIMIT 1");
    } elseif ($type === 'organization') {
        $s = $pdo->prepare("SELECT 1 FROM organizations WHERE id = ? LIMIT 1");
    } else {
        return false;
    }
    $s->execute([$accountId]);
    return (bool) $s->fetchColumn();
}

function sdAuditEntity(string $type): string
{
    return $type === 'broker' ? 'user' : 'organization';
}

/**
 * 2d: refuse a dealership limit below what the business already has.
 * Returns an error message, or null when the limit fits.
 */
function sdDealershipLimitGuard(int $orgId, ?int $newLimit): ?string
{
    require_once __DIR__ . '/business.php';
    if ($newLimit === null || !sdBusinessReady()) {
        return null;
    }
    $count = sdOrgDealerCount($orgId);
    return $count > $newLimit
        ? "This business has {$count} dealerships, more than {$newLimit}. Move dealerships out on the Business tab first."
        : null;
}

/**
 * Put an account on a plan.
 *   status 'active' = paying (manually, until billing ships), 'comped' = free of charge
 *   $until  'Y-m-d' or '' — after that date the account falls back to Free
 * Choosing the default (Free) plan removes the subscription.
 *
 * @return array{0:bool,1:string}
 */
function sdSetAccountPlan(string $type, int $accountId, string $planCode, string $status,
                          string $until, string $note, int $actorId): array
{
    if ($err = sdEntGuard($actorId)) {
        return [false, $err];
    }
    if (!in_array($type, SD_ACCOUNT_TYPES, true) || !sdAccountExists($type, $accountId)) {
        return [false, 'That account was not found.'];
    }
    $plan = sdPlanByCode($planCode);
    if (!$plan || $plan['audience'] !== $type) {
        return [false, 'Choose a plan for this kind of account.'];
    }
    if (!in_array($status, ['active', 'comped'], true)) {
        return [false, 'Choose Paid or Comped.'];
    }
    // Phase 3: an online (PayFast) subscription is changed by the payer or
    // cancelled first — a manual change would leave their card being charged.
    $current = sdSubscription($type, $accountId);
    if ($current && ($current['provider'] ?? '') === 'payfast' && sdSubscriptionGrants($current)
        && !(int) ($current['cancel_at_period_end'] ?? 0)) {
        return [false, 'This account pays online through PayFast. Cancel the online subscription first (button below), then change the plan.'];
    }
    // 0026: an invoiced business is changed by stopping invoicing first —
    // otherwise invoices would keep being issued for the old plan.
    if ($current && ($current['provider'] ?? '') === 'invoice' && sdSubscriptionGrants($current)
        && !(int) ($current['cancel_at_period_end'] ?? 0)) {
        return [false, 'This business is invoiced monthly. Stop invoicing first (Invoices, below), then change the plan.'];
    }
    // 2d: a business can't move to a plan with fewer dealerships than it has
    // (a custom dealership limit, if set, still wins over the plan).
    if ($type === 'organization' && !array_key_exists('max_dealerships', sdLimitOverrides($type, $accountId))
        && ($err = sdDealershipLimitGuard($accountId, sdPlanLimit($plan, 'max_dealerships')))) {
        return [false, $err];
    }
    $periodEnd = null;
    if (trim($until) !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($until));
        if (!$d || $d->format('Y-m-d') !== trim($until)) {
            return [false, 'The end date must be a valid date.'];
        }
        if ($d->getTimestamp() <= strtotime('today')) {
            return [false, 'The end date must be in the future.'];
        }
        $periodEnd = $d->format('Y-m-d 23:59:59');
    }
    $note = mb_substr(trim($note), 0, 255);

    $pdo    = Database::getInstance();
    $before = sdSubscription($type, $accountId);
    $old    = $before ? (sdPlans()[(int) $before['plan_id']]['code'] ?? null) : null;

    if ($plan['is_default']) {
        $periodEnd = null;
        if (!$before) {
            return [true, 'Already on the ' . $plan['name'] . ' plan.'];
        }
        $pdo->prepare("DELETE FROM subscriptions WHERE account_type = ? AND account_id = ?")
            ->execute([$type, $accountId]);
    } elseif ($before) {
        $planChanged = (int) $before['plan_id'] !== $plan['id'];
        $pdo->prepare("
            UPDATE subscriptions
            SET plan_id = ?, status = ?, current_period_end = ?, note = ?, provider = 'manual',
                updated_by = ?, updated_at = NOW()" . ($planChanged ? ", started_at = NOW()" : '') . "
            WHERE account_type = ? AND account_id = ?
        ")->execute([$plan['id'], $status, $periodEnd, $note ?: null, $actorId, $type, $accountId]);
    } else {
        $pdo->prepare("
            INSERT INTO subscriptions
                (account_type, account_id, plan_id, status, started_at, current_period_end,
                 provider, note, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), ?, 'manual', ?, ?, NOW(), NOW())
        ")->execute([$type, $accountId, $plan['id'], $status, $periodEnd, $note ?: null, $actorId]);
    }

    writeAuditLog('plan.changed', sdAuditEntity($type), $accountId,
        $before ? ['plan' => $old, 'status' => $before['status'], 'until' => $before['current_period_end']] : ['plan' => sdDefaultPlan($type)['code'] ?? null],
        ['plan' => $plan['code'], 'status' => $plan['is_default'] ? null : $status, 'until' => $periodEnd, 'note' => $note ?: null],
        $actorId);

    $label = $plan['is_default']
        ? 'Moved to the ' . $plan['name'] . ' plan.'
        : 'Now on ' . $plan['name'] . ($status === 'comped' ? ' (comped)' : ' (paid)')
          . ($periodEnd ? ' until ' . date('j M Y', strtotime($periodEnd)) : '') . '.';
    sdPlanChangedRecheck($type, $accountId);   // 3b: open / close a downgrade window
    return [true, $label];
}

/** 3b: after any plan or limit change, re-check the account's downgrade window. */
function sdPlanChangedRecheck(string $type, int $accountId): void
{
    require_once __DIR__ . '/plan_grace.php';
    sdAccountPlan($type, $accountId, true);   // drop the cached (old) plan first
    sdGraceRecheck($type, $accountId);
    if ($type === 'organization') {           // 4a: VIP perks on / off → network-only cars in force or open
        require_once __DIR__ . '/vip_network.php';
        try {
            sdVipSyncOrg($accountId);
        } catch (Throwable $e) {
            error_log('[SalesDesk vip] sync failed: ' . $e->getMessage());
        }
    }
}

/**
 * Custom limit for one account. $value null = unlimited.
 *
 * @return array{0:bool,1:string}
 */
function sdSetLimitOverride(string $type, int $accountId, string $key, ?int $value, string $reason, int $actorId): array
{
    if ($err = sdEntGuard($actorId)) {
        return [false, $err];
    }
    $def = SD_LIMIT_KEYS[$key] ?? null;
    if (!$def || $def['audience'] !== $type) {
        return [false, 'That limit doesn’t apply to this account.'];
    }
    if (!sdAccountExists($type, $accountId)) {
        return [false, 'That account was not found.'];
    }
    if ($value !== null) {
        if ($def['type'] === 'bool') {
            $value = $value > 0 ? 1 : 0;
        } elseif ($value < 0 || $value > 100000) {
            return [false, 'Enter a number from 0 to 100 000, or choose Unlimited.'];
        }
    }
    if ($key === 'max_dealerships' && ($err = sdDealershipLimitGuard($accountId, $value))) {   // 2d
        return [false, $err];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') {
        return [false, 'Add a short reason — it’s kept with the limit.'];
    }

    $pdo    = Database::getInstance();
    $before = sdLimitOverrides($type, $accountId)[$key] ?? null;
    if ($before) {
        $pdo->prepare("
            UPDATE account_limit_overrides
            SET limit_value = ?, reason = ?, set_by = ?, set_at = NOW()
            WHERE account_type = ? AND account_id = ? AND limit_key = ?
        ")->execute([$value, $reason, $actorId, $type, $accountId, $key]);
    } else {
        $pdo->prepare("
            INSERT INTO account_limit_overrides (account_type, account_id, limit_key, limit_value, reason, set_by, set_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$type, $accountId, $key, $value, $reason, $actorId]);
    }
    writeAuditLog('plan.limit_override_set', sdAuditEntity($type), $accountId,
        $before ? ['key' => $key, 'value' => $before['limit_value'], 'reason' => $before['reason']] : null,
        ['key' => $key, 'value' => $value, 'reason' => $reason], $actorId);
    sdPlanChangedRecheck($type, $accountId);   // 3b

    return [true, $def['label'] . ' set to ' . sdLimitText($value, $key) . ' for this account.'];
}

/** @return array{0:bool,1:string} */
function sdClearLimitOverride(string $type, int $accountId, string $key, int $actorId): array
{
    if ($err = sdEntGuard($actorId)) {
        return [false, $err];
    }
    $before = sdLimitOverrides($type, $accountId)[$key] ?? null;
    if (!$before) {
        return [true, 'There was no custom limit to remove.'];
    }
    if ($type === 'organization' && $key === 'max_dealerships') {   // 2d: falling back to the plan mustn't strand dealerships
        $plan = sdAccountPlan($type, $accountId);
        if ($err = sdDealershipLimitGuard($accountId, $plan ? sdPlanLimit($plan, $key) : SD_LIMIT_KEYS[$key]['fallback'])) {
            return [false, $err];
        }
    }
    Database::getInstance()->prepare("
        DELETE FROM account_limit_overrides WHERE account_type = ? AND account_id = ? AND limit_key = ?
    ")->execute([$type, $accountId, $key]);
    writeAuditLog('plan.limit_override_cleared', sdAuditEntity($type), $accountId,
        ['key' => $key, 'value' => $before['limit_value'], 'reason' => $before['reason']], null, $actorId);
    $label = SD_LIMIT_KEYS[$key]['label'] ?? $key;
    sdPlanChangedRecheck($type, $accountId);   // 3b
    return [true, "Custom limit removed — {$label} follows the plan again."];
}

/**
 * Edit a plan in the catalogue: name, tagline, price, limits, active.
 * $in: name, tagline, price (rands), is_active, limit[key] (number),
 *      unlimited[key] (checkbox)
 *
 * @return array{0:bool,1:string}
 */
function sdUpdatePlan(int $planId, array $in, int $actorId): array
{
    if ($err = sdEntGuard($actorId)) {
        return [false, $err];
    }
    $plan = sdPlans()[$planId] ?? null;
    if (!$plan) {
        return [false, 'That plan was not found.'];
    }
    $name    = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    $tagline = mb_substr(trim((string) ($in['tagline'] ?? '')), 0, 160);
    $priceIn = str_replace([' ', ','], ['', '.'], trim((string) ($in['price'] ?? '0')));
    if ($name === '') {
        return [false, 'A plan needs a name.'];
    }
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $priceIn)) {
        return [false, 'Enter the price in rands, e.g. 99 or 3000.'];
    }
    $priceCents = (int) round(((float) $priceIn) * 100);
    $active     = !empty($in['is_active']);
    if ($plan['is_default'] && (!$active || $priceCents !== 0)) {
        return [false, 'The default (Free) plan must stay active and free — accounts without a plan land on it.'];
    }

    $limits = [];
    foreach (SD_LIMIT_KEYS as $key => $def) {
        if ($def['audience'] !== $plan['audience']) {
            continue;
        }
        if ($def['type'] === 'bool') {
            $limits[$key] = !empty($in['limit'][$key]) ? 1 : 0;
        } elseif (!empty($in['unlimited'][$key])) {
            $limits[$key] = null;
        } else {
            $raw = trim((string) ($in['limit'][$key] ?? ''));
            if (!preg_match('/^\d{1,6}$/', $raw)) {
                return [false, $def['label'] . ': enter a whole number or tick Unlimited.'];
            }
            $limits[$key] = (int) $raw;
        }
    }

    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE plans SET name = ?, tagline = ?, price_cents = ?, is_active = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$name, $tagline ?: null, $priceCents, $active ? 1 : 0, $planId]);
        $del = $pdo->prepare("DELETE FROM plan_limits WHERE plan_id = ? AND limit_key = ?");
        $ins = $pdo->prepare("INSERT INTO plan_limits (plan_id, limit_key, limit_value) VALUES (?, ?, ?)");
        foreach ($limits as $key => $value) {
            $del->execute([$planId, $key]);
            $ins->execute([$planId, $key, $value]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk entitlements] plan update failed: ' . $e->getMessage());
        return [false, 'The plan couldn’t be saved. Nothing was changed.'];
    }

    writeAuditLog('plan.catalogue_updated', 'plan', $planId,
        ['name' => $plan['name'], 'price_cents' => $plan['price_cents'], 'is_active' => $plan['is_active'], 'limits' => $plan['limits']],
        ['name' => $name, 'price_cents' => $priceCents, 'is_active' => $active, 'limits' => $limits],
        $actorId);
    sdPlans(null, true);
    return [true, ucfirst($plan['audience'] === 'broker' ? 'Broker' : 'Business') . " {$name} plan saved."];
}


// ============================================================
// STATS (Admin → Plans)
// ============================================================

/**
 * Accounts per plan + monthly recurring revenue.
 *
 * @return array{plans: array<int, array{accounts:int, paid:int, comped:int, mrr_cents:int}>,
 *               mrr_cents:int, brokers_total:int, businesses_total:int|null}
 *         businesses_total is null before 0020 (no business orgs yet).
 */
function sdPlanStats(): array
{
    $out = ['plans' => [], 'mrr_cents' => 0, 'brokers_total' => 0, 'businesses_total' => null];
    if (!sdPlansReady()) {
        return $out;
    }
    $pdo   = Database::getInstance();
    $plans = sdPlans();
    foreach ($plans as $id => $p) {
        $out['plans'][$id] = ['accounts' => 0, 'paid' => 0, 'comped' => 0, 'mrr_cents' => 0];
    }

    $subs = $pdo->query("SELECT account_type, plan_id, status, current_period_end FROM subscriptions")->fetchAll();
    $current = ['broker' => 0, 'organization' => 0];
    foreach ($subs as $s) {
        if (!sdSubscriptionGrants($s) || !isset($plans[(int) $s['plan_id']])) {
            continue;
        }
        $pid = (int) $s['plan_id'];
        $out['plans'][$pid]['accounts']++;
        $current[$s['account_type']] = ($current[$s['account_type']] ?? 0) + 1;
        if ($s['status'] === 'comped') {
            $out['plans'][$pid]['comped']++;
        } else {
            $out['plans'][$pid]['paid']++;
            $monthly = $plans[$pid]['billing_interval'] === 'year'
                ? intdiv($plans[$pid]['price_cents'], 12)
                : $plans[$pid]['price_cents'];
            $out['plans'][$pid]['mrr_cents'] += $monthly;
            $out['mrr_cents'] += $monthly;
        }
    }

    // Everyone without a current subscription is on the default plan.
    $brokers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'broker' AND status <> 'suspended'")->fetchColumn();
    $out['brokers_total'] = $brokers;
    if ($free = sdDefaultPlan('broker')) {
        $out['plans'][$free['id']]['accounts'] += max(0, $brokers - $current['broker']);
    }

    // 0020: business accounts = orgs that own at least one dealership.
    require_once __DIR__ . '/business.php';
    if (sdBusinessReady()) {
        $biz = (int) $pdo->query("
            SELECT COUNT(*) FROM organizations o
            WHERE o.is_active = 1 AND EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = o.id)
        ")->fetchColumn();
        $out['businesses_total'] = $biz;
        if ($bfree = sdDefaultPlan('organization')) {
            $out['plans'][$bfree['id']]['accounts'] += max(0, $biz - $current['organization']);
        }
    }
    return $out;
}

/** Recent plan.* audit entries for the Plans page. */
function sdRecentPlanChanges(int $limit = 15): array
{
    $stmt = Database::getInstance()->prepare("
        SELECT a.action, a.entity_type, a.entity_id, a.before_data, a.after_data, a.created_at,
               u.email AS actor_email,
               TRIM(CONCAT(COALESCE(ap.first_name, ''), ' ', COALESCE(ap.last_name, ''))) AS actor_name,
               tu.email AS target_email,
               org.name AS target_org   -- 0020: business accounts
        FROM audit_logs a
        LEFT JOIN users u     ON u.id = a.actor_id
        LEFT JOIN profiles ap ON ap.user_id = a.actor_id
        LEFT JOIN users tu    ON a.entity_type = 'user' AND tu.id = a.entity_id
        LEFT JOIN organizations org ON a.entity_type = 'organization' AND org.id = a.entity_id
        WHERE a.action LIKE 'plan.%'
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT " . max(1, min(100, $limit))
    );
    $stmt->execute();
    return $stmt->fetchAll();
}


/**
 * Plan + desk car limit for many brokers in two queries (Users list).
 * Ignores the desk-org agent cap (shown on the user's own page).
 *
 * @param int[] $userIds
 * @return array<int, array{plan:string, plan_code:string, limit:?int, custom:bool, comped:bool}>
 */
function sdBrokerPlanSummaries(array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids || !sdPlansReady()) {
        return [];
    }
    $pdo   = Database::getInstance();
    $in    = implode(',', array_fill(0, count($ids), '?'));
    $plans = sdPlans();
    $free  = sdDefaultPlan('broker');

    $subs = [];
    $s = $pdo->prepare("SELECT * FROM subscriptions WHERE account_type = 'broker' AND account_id IN ({$in})");
    $s->execute($ids);
    foreach ($s->fetchAll() as $row) {
        $subs[(int) $row['account_id']] = $row;
    }
    $over = [];
    $o = $pdo->prepare("SELECT account_id, limit_value FROM account_limit_overrides
                        WHERE account_type = 'broker' AND limit_key = 'max_desk_cars' AND account_id IN ({$in})");
    $o->execute($ids);
    foreach ($o->fetchAll() as $row) {
        $over[(int) $row['account_id']] = $row['limit_value'] === null ? null : (int) $row['limit_value'];
    }

    $out = [];
    foreach ($ids as $id) {
        $sub  = $subs[$id] ?? null;
        $plan = (sdSubscriptionGrants($sub) ? ($plans[(int) $sub['plan_id']] ?? null) : null) ?? $free;
        if (!$plan) {
            continue;
        }
        $custom   = array_key_exists($id, $over);
        $out[$id] = [
            'plan'      => $plan['name'],
            'plan_code' => $plan['code'],
            'limit'     => $custom ? $over[$id] : sdPlanLimit($plan, 'max_desk_cars'),
            'custom'    => $custom,
            'comped'    => $sub && sdSubscriptionGrants($sub) && $sub['status'] === 'comped',
        ];
    }
    return $out;
}


// ============================================================
// BROKER: REQUEST AN UPGRADE (until billing ships in Phase 3)
// ============================================================

/** Network memberships a broker holds (pending or verified) — counts towards max_networks. */
function brokerNetworkCount(int $userId): int
{
    try {
        $s = Database::getInstance()->prepare("
            SELECT COUNT(*) FROM organization_members
            WHERE user_id = ? AND status IN ('pending', 'verified')
        ");
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/** When this broker last asked for an upgrade (Y-m-d H:i:s) or null. */
function sdLastUpgradeRequest(int $userId): ?string
{
    $s = Database::getInstance()->prepare("
        SELECT MAX(created_at) FROM audit_logs
        WHERE action = 'plan.upgrade_requested' AND entity_type = 'user' AND entity_id = ?
    ");
    $s->execute([$userId]);
    $v = $s->fetchColumn();
    return $v ?: null;
}

/**
 * A broker asks to move to a paid plan. Every active superadmin gets an
 * in-app notification linking to the broker's Plan & limits card.
 * At most one request per 7 days per broker.
 *
 * @return array{0:bool,1:string}
 */
function sdRequestUpgrade(int $userId, string $planCode): array
{
    if (!sdPlansReady()) {
        return [false, 'Plans aren’t available yet.'];
    }
    $plan    = sdPlanByCode($planCode);
    $current = sdAccountPlan('broker', $userId);
    if (!$plan || $plan['audience'] !== 'broker' || !$plan['is_active'] || $plan['is_default']) {
        return [false, 'That plan isn’t available.'];
    }
    if ($current && $current['code'] === $plan['code']) {
        return [false, 'You’re already on ' . $plan['name'] . '.'];
    }
    $last = sdLastUpgradeRequest($userId);
    if ($last && strtotime($last) > strtotime('-7 days')) {
        return [true, 'We already have your request from ' . date('j M', strtotime($last)) . ' — we’ll be in touch.'];
    }

    $pdo = Database::getInstance();
    $u   = $pdo->prepare("
        SELECT u.email, TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS name
        FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'broker' LIMIT 1
    ");
    $u->execute([$userId]);
    $who = $u->fetch();
    if (!$who) {
        return [false, 'Only brokers can request this plan.'];
    }

    require_once __DIR__ . '/superadmin.php';
    $col    = sdSuperadminSchemaReady() ? 'AND is_superadmin = 1' : '';
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' {$col}")->fetchAll(PDO::FETCH_COLUMN);
    $label  = ($who['name'] ?: $who['email']);
    $ins    = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, body, meta, created_at)
        VALUES (?, 'plan_request', ?, ?, ?, NOW())
    ");
    foreach ($admins as $adminId) {
        try {
            $ins->execute([(int) $adminId,
                $label . ' wants ' . $plan['name'],
                $label . ' (' . $who['email'] . ') asked to move to the broker ' . $plan['name'] . ' plan ('
                    . sdPlanPriceLabel($plan) . '). Set it up under Users → Edit → Plan & limits.',
                json_encode(['url' => '/app/admin/users-view?id=' . $userId . '&tab=edit#plan', 'plan' => $plan['code']])]);
        } catch (Throwable $e) {
            error_log('[SalesDesk plan request] notify failed: ' . $e->getMessage());
        }
    }
    writeAuditLog('plan.upgrade_requested', 'user', $userId,
        ['plan' => $current['code'] ?? null], ['plan' => $plan['code']], $userId);

    return [true, 'Thanks — your request for ' . $plan['name'] . ' is in. We’ll contact you to set it up.'];
}

/** 0026: when this business last asked for an upgrade (Y-m-d H:i:s) or null. */
function sdLastBusinessUpgradeRequest(int $orgId): ?string
{
    $s = Database::getInstance()->prepare("
        SELECT MAX(created_at) FROM audit_logs
        WHERE action = 'plan.upgrade_requested' AND entity_type = 'organization' AND entity_id = ?
    ");
    $s->execute([$orgId]);
    $v = $s->fetchColumn();
    return $v ?: null;
}

/**
 * 0026: a business asks SalesDesk for a plan (Enterprise, which is
 * invoiced, or Pro while online payment is off). Superadmins get an
 * in-app notification linking to the business's Business tab.
 * At most one request per 7 days per business.
 *
 * @return array{0:bool,1:string}
 */
function sdRequestBusinessUpgrade(int $orgId, string $planCode, int $userId): array
{
    if (!sdPlansReady()) {
        return [false, 'Plans aren’t available yet.'];
    }
    $plan    = sdPlanByCode($planCode);
    $current = sdAccountPlan('organization', $orgId);
    if (!$plan || $plan['audience'] !== 'organization' || !$plan['is_active'] || $plan['is_default']) {
        return [false, 'That plan isn’t available.'];
    }
    if ($current && $current['code'] === $plan['code']) {
        return [false, 'Your business is already on ' . $plan['name'] . '.'];
    }
    $last = sdLastBusinessUpgradeRequest($orgId);
    if ($last && strtotime($last) > strtotime('-7 days')) {
        return [true, 'We already have your request from ' . date('j M', strtotime($last)) . ' — we’ll be in touch.'];
    }
    $pdo = Database::getInstance();
    $org = $pdo->prepare("SELECT o.name, (SELECT MIN(d.id) FROM dealers d WHERE d.organization_id = o.id) AS dealer_id FROM organizations o WHERE o.id = ?");
    $org->execute([$orgId]);
    $org = $org->fetch();
    if (!$org) {
        return [false, 'That business wasn’t found.'];
    }
    $u = $pdo->prepare("SELECT u.email, TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS name
                        FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?");
    $u->execute([$userId]);
    $who = $u->fetch() ?: ['email' => '', 'name' => ''];

    require_once __DIR__ . '/superadmin.php';
    $col    = sdSuperadminSchemaReady() ? 'AND is_superadmin = 1' : '';
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' {$col}")->fetchAll(PDO::FETCH_COLUMN);
    $ins    = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, meta, created_at) VALUES (?, 'plan_request', ?, ?, ?, NOW())");
    foreach ($admins as $adminId) {
        try {
            $ins->execute([(int) $adminId,
                $org['name'] . ' wants ' . $plan['name'],
                ($who['name'] ?: $who['email']) . ' (' . $who['email'] . ') asked to move ' . $org['name'] . ' to the '
                    . $plan['name'] . ' business plan (' . sdPlanPriceLabel($plan) . '). Set it up on the Business tab.',
                json_encode(['link' => '/app/admin/dealerships-view?id=' . (int) $org['dealer_id'] . '&tab=business#plan', 'plan' => $plan['code']])]);
        } catch (Throwable $e) {
            error_log('[SalesDesk plan request] notify failed: ' . $e->getMessage());
        }
    }
    writeAuditLog('plan.upgrade_requested', 'organization', $orgId,
        ['plan' => $current['code'] ?? null], ['plan' => $plan['code'], 'by' => $userId], $userId);
    return [true, 'Thanks — your request for ' . $plan['name'] . ' is in. We’ll contact you to set it up.'];
}
