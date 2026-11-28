<?php
/**
 * SalesDesk — Online billing with PayFast  (plans Phase 3, migration 0022)
 *
 * Brokers pay for Pro, and businesses for Business Pro, by card / debit
 * order through PayFast recurring billing (a monthly subscription).
 * Hybrid business billing (0026): plans in SD_INVOICED_PLAN_CODES
 * (Enterprise) are never sold online — SalesDesk invoices them monthly by
 * EFT (includes/invoicing.php).
 *
 * Who pays for a business: sdCanManageBilling() — the principal of a
 * single-dealership business, or the business owner. SalesDesk staff
 * working in the dealer portal can't pay; superadmins manage plans in
 * Admin.
 *
 * FLOW
 *   1. Upgrade   sdBillingStartCheckout()  → billing_checkouts row (m_payment_id)
 *   2. Pay       /app/billing/pay?ref=…    → signed form posted to PayFast /eng/process
 *                (subscription_type 1, frequency 3 = monthly, cycles 0 = until cancelled)
 *   3. ITN       /api/billing/payfast-notify → sdBillingProcessItn():
 *                four checks (signature + passphrase, PayFast source, amount,
 *                server confirmation at /eng/query/validate), every ITN logged
 *                in billing_events, then:
 *                  COMPLETE, first payment → subscription active (token stored)
 *                  COMPLETE, renewal       → paid-through date moves on a month
 *                  FAILED                  → past_due (plan kept until the paid-through date)
 *                  CANCELLED               → runs until the paid-through date, then Free
 *   4. Cancel    sdBillingCancel() → PayFast API PUT /subscriptions/{token}/cancel
 *   5. Lapse     cron/billing-daily.php → sdBillingLapse(): ended subscriptions
 *                back to Free, with a notice. (Access already stops at
 *                current_period_end without cron: sdSubscriptionGrants().)
 *                3b: an account left over a limit gets a 7-day window
 *                (includes/plan_grace.php).
 *
 * Paid-through date: each payment covers one month; current_period_end is
 * that date + SD_BILLING_GRACE_DAYS, so a renewal that lands a day or two
 * late never drops anyone to Free.
 *
 * Settings live in includes/payfast.config.php (not in Git; see
 * payfast.config.example.php). Without it, billing is off and the Plan page
 * keeps "Request Pro".
 *
 * Reference: PayFast developer docs + the official payfast-php-sdk
 * (signature field order, ITN checks, API signing).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/entitlements.php';

const SD_BILLING_GRACE_DAYS     = 3;
const SD_BILLING_CHECKOUT_HOURS = 2;    // a checkout link is usable this long
const SD_PAYFAST_FREQ_MONTHLY   = 3;
const SD_INVOICED_PLAN_CODES    = ['business_enterprise'];   // 0026: invoiced by SalesDesk, never sold online
const SD_PAYFAST_VALID_HOSTS    = ['www.payfast.co.za', 'sandbox.payfast.co.za', 'w1w.payfast.co.za', 'w2w.payfast.co.za'];

/** PayFast's signature order for the payment form (passphrase is appended last). */
const SD_PAYFAST_FORM_FIELDS = [
    'merchant_id', 'merchant_key', 'return_url', 'cancel_url', 'notify_url', 'notify_method',
    'name_first', 'name_last', 'email_address', 'cell_number',
    'm_payment_id', 'amount', 'item_name', 'item_description',
    'custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
    'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5',
    'email_confirmation', 'confirmation_address', 'currency', 'payment_method',
    'subscription_type', 'billing_date', 'recurring_amount', 'frequency', 'cycles',
    'subscription_notify_email', 'subscription_notify_webhook', 'subscription_notify_buyer',
];


// ============================================================
// SETTINGS + READINESS
// ============================================================

/** Load includes/payfast.config.php once, if it's there. */
function sdPayfastLoadConfig(): void
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $file   = __DIR__ . '/payfast.config.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}

/** Merchant ID, key and passphrase all set? */
function sdPayfastConfigured(): bool
{
    sdPayfastLoadConfig();
    return defined('PAYFAST_MERCHANT_ID') && trim((string) PAYFAST_MERCHANT_ID) !== ''
        && defined('PAYFAST_MERCHANT_KEY') && trim((string) PAYFAST_MERCHANT_KEY) !== ''
        && defined('PAYFAST_PASSPHRASE') && trim((string) PAYFAST_PASSPHRASE) !== '';
}

function sdPayfastSandbox(): bool
{
    sdPayfastLoadConfig();
    return !defined('PAYFAST_MODE') || PAYFAST_MODE !== 'live';
}

function sdPayfastBaseUrl(): string
{
    return sdPayfastSandbox() ? 'https://sandbox.payfast.co.za' : 'https://www.payfast.co.za';
}

function sdPayfastPassphrase(): string
{
    sdPayfastLoadConfig();
    return defined('PAYFAST_PASSPHRASE') ? trim((string) PAYFAST_PASSPHRASE) : '';
}

/** 0022 tables there? */
function sdBillingReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = sdPlansReady() && (bool) Database::getInstance()->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing_events'
            ")->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/** Can people pay online right now? */
function sdBillingAvailable(): bool
{
    return sdBillingReady() && sdPayfastConfigured();
}

/** URLs PayFast needs (also shown in Admin → Plans → Billing). */
function sdBillingUrls(string $type = 'broker'): array
{
    $page = sdBillingPlanPage($type);
    return [
        'notify' => SITE_URL . '/api/billing/payfast-notify',
        'return' => SITE_URL . $page . '?checkout=return',
        'cancel' => SITE_URL . $page . '?checkout=cancelled',
    ];
}

/** The account's Plan page: brokers /app/broker/plan, businesses /app/dealer/plan. */
function sdBillingPlanPage(string $type): string
{
    return $type === 'organization' ? '/app/dealer/plan' : '/app/broker/plan';
}

/** 0026: is this plan invoiced by SalesDesk (never sold online)? */
function sdPlanIsInvoiced(?array $plan): bool
{
    return $plan && in_array((string) $plan['code'], SD_INVOICED_PLAN_CODES, true);
}

/**
 * 0026: may this dealer-portal workspace pay for / cancel the business's
 * plan? The principal of a single-dealership business, or the business
 * owner (own dealership or 'group' mode). Never staff or viewers.
 */
function sdCanManageBilling(array $ws, int $orgId): bool
{
    if ($orgId <= 0 || empty($ws['can_write']) || !in_array($ws['mode'] ?? '', ['principal', 'group'], true)) {
        return false;
    }
    $pdo   = Database::getInstance();
    $owner = (int) $pdo->query('SELECT COALESCE(owner_user_id, 0) FROM organizations WHERE id = ' . $orgId)->fetchColumn();
    if ($owner && $owner === (int) $ws['actor_id']) {
        return true;
    }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM dealers WHERE organization_id = ' . $orgId)->fetchColumn();
    return $n < 2 && ($ws['mode'] ?? '') === 'principal';
}

/** 0026: the people who hear about a business's billing (owner, else its principals). */
function sdBillingOrgContacts(int $orgId): array
{
    $pdo   = Database::getInstance();
    $owner = (int) $pdo->query('SELECT COALESCE(owner_user_id, 0) FROM organizations WHERE id = ' . $orgId)->fetchColumn();
    if ($owner) {
        return [$owner];
    }
    $s = $pdo->prepare("SELECT DISTINCT user_id FROM dealers WHERE organization_id = ? AND user_id IS NOT NULL");
    $s->execute([$orgId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}


// ============================================================
// SIGNATURES
// ============================================================

/**
 * Signature for the payment form: fields in PayFast's documented order,
 * empty values left out, each value trimmed + urlencoded, then
 * &passphrase=… appended, MD5 of the lot.
 */
function sdPayfastFormSignature(array $fields, string $passphrase): string
{
    $parts = [];
    foreach (SD_PAYFAST_FORM_FIELDS as $key) {
        if (isset($fields[$key]) && trim((string) $fields[$key]) !== '') {
            $parts[] = $key . '=' . urlencode(trim((string) $fields[$key]));
        }
    }
    $string = implode('&', $parts);
    if ($passphrase !== '') {
        $string .= '&passphrase=' . urlencode($passphrase);
    }
    return md5($string);
}

/**
 * The ITN's own parameter string: every posted field in the order it was
 * posted, up to (not including) "signature", urlencoded. Used both for the
 * signature check and the server confirmation.
 */
function sdPayfastItnParamString(array $post): string
{
    $parts = [];
    foreach ($post as $key => $val) {
        if ($key === 'signature') {
            break;
        }
        $parts[] = $key . '=' . urlencode(stripslashes((string) $val));
    }
    return implode('&', $parts);
}

/** Signature for the REST API: headers + body, sorted by key, passphrase included. */
function sdPayfastApiSignature(array $data, string $passphrase): string
{
    if ($passphrase !== '') {
        $data['passphrase'] = $passphrase;
    }
    ksort($data);
    $parts = [];
    foreach ($data as $key => $val) {
        if ($key !== 'signature') {
            $parts[] = $key . '=' . urlencode((string) $val);
        }
    }
    return md5(implode('&', $parts));
}


// ============================================================
// CHECKOUT
// ============================================================

/** Rand amount for PayFast ("99.00"). */
function sdPayfastAmount(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/** Cents from a PayFast amount string. */
function sdPayfastCents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

/**
 * Start an upgrade. Brokers only for now (account = the broker).
 *
 * @return array{0:bool,1:string,2:?string}  [ok, message, m_payment_id]
 */
function sdBillingStartCheckout(string $type, int $accountId, string $planCode, int $userId): array
{
    if (!sdBillingAvailable()) {
        return [false, 'Online payment isn’t switched on yet.', null];
    }
    // Brokers pay for themselves; a business is paid for by its owner /
    // principal — the caller checks sdCanManageBilling() first.
    if (($type === 'broker' && $accountId !== $userId) || !in_array($type, ['broker', 'organization'], true)) {
        return [false, 'You can’t pay for this account.', null];
    }
    $plan = sdPlanByCode($planCode);
    if (!$plan || $plan['audience'] !== $type || !$plan['is_active'] || $plan['is_default'] || (int) $plan['price_cents'] <= 0) {
        return [false, 'That plan isn’t available.', null];
    }
    if (sdPlanIsInvoiced($plan)) {
        return [false, $plan['name'] . ' is arranged with SalesDesk and invoiced monthly. Ask us about it from your Plan page.', null];
    }
    $sub = sdSubscription($type, $accountId);
    if ($sub && sdSubscriptionGrants($sub)) {
        if (($sub['provider'] ?? '') === 'payfast' && !(int) ($sub['cancel_at_period_end'] ?? 0)) {
            return [false, 'You’re already subscribed. Your plan renews automatically.', null];
        }
        if (($sub['provider'] ?? '') === 'payfast') {
            return [false, 'Your plan runs until ' . date('j M Y', strtotime((string) $sub['current_period_end']))
                . '. You can subscribe again after that.', null];
        }
        return [false, 'You’re already on ' . (sdPlans()[(int) $sub['plan_id']]['name'] ?? 'a paid plan')
            . ', arranged by SalesDesk. Contact us to change it.', null];
    }

    $ref = generateUuidV4();
    Database::getInstance()->prepare("
        INSERT INTO billing_checkouts (m_payment_id, account_type, account_id, plan_id, amount_cents, recurring_cents, user_id, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'started', NOW())
    ")->execute([$ref, $type, $accountId, (int) $plan['id'], (int) $plan['price_cents'], (int) $plan['price_cents'], $userId]);
    writeAuditLog('billing.checkout_started', sdAuditEntity($type), $accountId, null, ['plan' => $plan['code'], 'ref' => $ref], $userId);
    return [true, '', $ref];
}

/**
 * The signed form fields for a checkout this user started (null if it's not
 * theirs, already used, or older than SD_BILLING_CHECKOUT_HOURS).
 */
function sdBillingCheckoutForm(string $ref, int $userId): ?array
{
    if (!sdBillingAvailable() || !preg_match('/^[0-9a-f\-]{36}$/', $ref)) {
        return null;
    }
    $pdo = Database::getInstance();
    $c   = $pdo->prepare("
        SELECT c.*, p.name AS plan_name, u.email, pr.first_name, pr.last_name
        FROM billing_checkouts c
        JOIN plans p          ON p.id = c.plan_id
        JOIN users u          ON u.id = c.user_id
        LEFT JOIN profiles pr ON pr.user_id = c.user_id
        WHERE c.m_payment_id = ? AND c.user_id = ? AND c.status = 'started'
          AND c.created_at > NOW() - INTERVAL " . SD_BILLING_CHECKOUT_HOURS . " HOUR
        LIMIT 1
    ");
    $c->execute([$ref, $userId]);
    $c = $c->fetch();
    if (!$c) {
        return null;
    }
    sdPayfastLoadConfig();
    $urls   = sdBillingUrls((string) $c['account_type']);
    $fields = [
        'merchant_id'      => trim((string) PAYFAST_MERCHANT_ID),
        'merchant_key'     => trim((string) PAYFAST_MERCHANT_KEY),
        'return_url'       => $urls['return'] . '&ref=' . $ref,
        'cancel_url'       => $urls['cancel'] . '&ref=' . $ref,
        'notify_url'       => $urls['notify'],
        'name_first'       => mb_substr(trim((string) $c['first_name']), 0, 100),
        'name_last'        => mb_substr(trim((string) $c['last_name']), 0, 100),
        'email_address'    => (string) $c['email'],
        'm_payment_id'     => $ref,
        'amount'           => sdPayfastAmount((int) $c['amount_cents']),
        'item_name'        => 'SalesDesk ' . $c['plan_name'] . ' (monthly)',
        'item_description' => ($c['account_type'] === 'organization' ? 'SalesDesk business plan' : 'SalesDesk broker plan')
                              . ', billed monthly until you cancel. Prices exclude VAT.',
        'custom_str1'      => $c['account_type'],
        'custom_int1'      => (string) (int) $c['account_id'],
        'subscription_type' => '1',
        'billing_date'     => date('Y-m-d'),
        'recurring_amount' => sdPayfastAmount((int) $c['recurring_cents']),
        'frequency'        => (string) SD_PAYFAST_FREQ_MONTHLY,
        'cycles'           => '0',
    ];
    // Keep only non-empty fields, in PayFast's order, then sign.
    $ordered = [];
    foreach (SD_PAYFAST_FORM_FIELDS as $key) {
        if (isset($fields[$key]) && trim((string) $fields[$key]) !== '') {
            $ordered[$key] = trim((string) $fields[$key]);
        }
    }
    $ordered['signature'] = sdPayfastFormSignature($ordered, sdPayfastPassphrase());
    return [
        'action' => sdPayfastBaseUrl() . '/eng/process',
        'fields' => $ordered,
        'plan'   => (string) $c['plan_name'],
        'amount' => (int) $c['amount_cents'],
        'type'   => (string) $c['account_type'],
    ];
}


// ============================================================
// ITN (PayFast → us)
// ============================================================

/** Is the request from PayFast? (source check — docs: compare against PayFast's hosts) */
function sdPayfastSourceOk(string $remoteIp): bool
{
    $ips = [];
    foreach (SD_PAYFAST_VALID_HOSTS as $host) {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            array_push($ips, ...$v4);
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
    }
    return $remoteIp !== '' && in_array($remoteIp, array_unique($ips), true);
}

/** The caller's address, honouring PAYFAST_TRUST_PROXY. */
function sdPayfastRemoteIp(): string
{
    sdPayfastLoadConfig();
    if (defined('PAYFAST_TRUST_PROXY') && PAYFAST_TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Ask PayFast to confirm the ITN ("VALID"). */
function sdPayfastServerConfirm(string $paramString): bool
{
    // Test harness only (never set in production): skip the network call in sandbox.
    if (sdPayfastSandbox() && defined('PAYFAST_TEST_SKIP_REMOTE') && PAYFAST_TEST_SKIP_REMOTE) {
        return true;
    }
    $ch = curl_init(sdPayfastBaseUrl() . '/eng/query/validate');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $paramString,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        error_log('[SalesDesk PayFast] server confirmation failed: ' . $err);
        return false;
    }
    return trim((string) $res) === 'VALID';
}

/**
 * Handle one ITN. Returns the outcome written to billing_events.
 * Never throws: PayFast only needs a 200, and every result is logged.
 */
function sdBillingProcessItn(array $post, string $remoteIp): string
{
    if (!sdBillingReady()) {
        return 'ignored: billing tables missing';
    }
    $pdo     = Database::getInstance();
    $status  = strtoupper(trim((string) ($post['payment_status'] ?? '')));
    $ref     = (string) ($post['m_payment_id'] ?? '');
    $pfId    = (string) ($post['pf_payment_id'] ?? '');
    $token   = trim((string) ($post['token'] ?? ''));
    $gross   = isset($post['amount_gross']) ? sdPayfastCents($post['amount_gross']) : null;
    $checks  = ['signature' => false, 'source' => false, 'amount' => false, 'server' => false];
    $account = [null, null];

    $log = function (string $outcome) use ($pdo, $post, $status, $ref, $pfId, $token, $gross, &$checks, &$account, $remoteIp): string {
        $payload = $post;
        unset($payload['signature']);
        try {
            $pdo->prepare("
                INSERT INTO billing_events
                    (provider, m_payment_id, pf_payment_id, token, payment_status, amount_gross_cents, amount_fee_cents,
                     amount_net_cents, account_type, account_id, signature_ok, source_ok, amount_ok, server_ok,
                     outcome, remote_ip, payload, created_at)
                VALUES ('payfast', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $ref !== '' ? mb_substr($ref, 0, 64) : null, $pfId !== '' ? mb_substr($pfId, 0, 40) : null,
                $token !== '' ? mb_substr($token, 0, 64) : null, $status !== '' ? mb_substr($status, 0, 20) : null,
                $gross,
                isset($post['amount_fee']) ? sdPayfastCents($post['amount_fee']) : null,
                isset($post['amount_net']) ? sdPayfastCents($post['amount_net']) : null,
                $account[0], $account[1],
                (int) $checks['signature'], (int) $checks['source'], (int) $checks['amount'], (int) $checks['server'],
                mb_substr($outcome, 0, 80), mb_substr($remoteIp, 0, 45),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            error_log('[SalesDesk PayFast] could not log ITN: ' . $e->getMessage());
        }
        return $outcome;
    };

    if (!sdPayfastConfigured()) {
        return $log('rejected: PayFast is not configured');
    }

    // 1. Signature (with passphrase)
    $param = sdPayfastItnParamString($post);
    $expected = md5($param . '&passphrase=' . urlencode(sdPayfastPassphrase()));
    $checks['signature'] = isset($post['signature']) && hash_equals($expected, (string) $post['signature']);
    if (!$checks['signature']) {
        return $log('rejected: bad signature');
    }
    // Merchant must be ours.
    sdPayfastLoadConfig();
    if (($post['merchant_id'] ?? '') !== trim((string) PAYFAST_MERCHANT_ID)) {
        return $log('rejected: wrong merchant');
    }

    // 2. Source
    $checks['source'] = (sdPayfastSandbox() && defined('PAYFAST_TEST_SKIP_REMOTE') && PAYFAST_TEST_SKIP_REMOTE)
        ? true : sdPayfastSourceOk($remoteIp);
    if (!$checks['source']) {
        return $log('rejected: not from PayFast (' . $remoteIp . ')');
    }

    // Find what this is about: the checkout (first payment) and/or the subscription (by token).
    $checkout = null;
    if ($ref !== '') {
        $s = $pdo->prepare("SELECT * FROM billing_checkouts WHERE m_payment_id = ? LIMIT 1");
        $s->execute([$ref]);
        $checkout = $s->fetch() ?: null;
    }
    $sub = null;
    if ($token !== '') {
        $s = $pdo->prepare("SELECT * FROM subscriptions WHERE provider = 'payfast' AND provider_ref = ? LIMIT 1");
        $s->execute([$token]);
        $sub = $s->fetch() ?: null;
        if (!$checkout) {
            $s = $pdo->prepare("SELECT * FROM billing_checkouts WHERE token = ? ORDER BY id DESC LIMIT 1");
            $s->execute([$token]);
            $checkout = $s->fetch() ?: null;
        }
    }
    if (!$checkout && !$sub) {
        return $log('ignored: unknown payment');
    }
    $account = $sub ? [$sub['account_type'], (int) $sub['account_id']] : [$checkout['account_type'], (int) $checkout['account_id']];

    // Already handled? (PayFast retries until it gets a 200.)
    if ($pfId !== '') {
        $d = $pdo->prepare("SELECT 1 FROM billing_events
                             WHERE pf_payment_id = ? AND payment_status = ?
                               AND outcome IN ('activated','renewed','cancelled','failed') LIMIT 1");
        $d->execute([$pfId, $status]);
        if ($d->fetchColumn()) {
            $checks['amount'] = $checks['server'] = true;
            return $log('duplicate');
        }
    }

    // 3. Amount (payments only)
    $isFirst = $checkout && $checkout['status'] === 'started';
    if ($status === 'COMPLETE') {
        $want = $isFirst ? (int) $checkout['amount_cents']
              : (int) ($sub['amount_cents'] ?? ($checkout['recurring_cents'] ?? 0));
        $checks['amount'] = $gross !== null && abs($gross - $want) <= 1;
        if (!$checks['amount']) {
            return $log('rejected: amount ' . ($gross ?? 'missing') . ' ≠ ' . $want);
        }
    } else {
        $checks['amount'] = true;
    }

    // 4. Server confirmation
    $checks['server'] = sdPayfastServerConfirm($param);
    if (!$checks['server']) {
        return $log('rejected: PayFast did not confirm');
    }

    try {
        return $log(match ($status) {
            'COMPLETE'  => $isFirst
                ? sdBillingActivate($checkout, $token, $pfId)
                : sdBillingRenew($sub, $checkout, $token),
            'FAILED'    => sdBillingMarkFailed($sub, $checkout),
            'CANCELLED' => sdBillingMarkCancelled($sub, $checkout),
            default     => 'ignored: status ' . ($status ?: 'missing'),
        });
    } catch (Throwable $e) {
        error_log('[SalesDesk PayFast] applying ITN failed: ' . $e->getMessage());
        return $log('error: ' . mb_substr($e->getMessage(), 0, 60));
    }
}

/** Paid-through date for a payment made now (one month + grace). */
function sdBillingPeriodEnd(?string $from = null): string
{
    $base = new DateTimeImmutable($from ?: 'now');
    return $base->modify('+1 month')->modify('+' . SD_BILLING_GRACE_DAYS . ' days')->format('Y-m-d 23:59:59');
}

/** First payment: the account is on the plan from now. */
function sdBillingActivate(array $checkout, string $token, string $pfId): string
{
    if ($token === '') {
        return 'rejected: no subscription token';
    }
    $pdo    = Database::getInstance();
    $type   = $checkout['account_type'];
    $id     = (int) $checkout['account_id'];
    $before = sdSubscription($type, $id);
    $end    = sdBillingPeriodEnd();

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO subscriptions
                (account_type, account_id, plan_id, status, started_at, current_period_end, provider, provider_ref,
                 amount_cents, cancel_at_period_end, last_payment_at, last_failed_at, note, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, 'active', NOW(), ?, 'payfast', ?, ?, 0, NOW(), NULL, 'Paid online (PayFast)', NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                plan_id = VALUES(plan_id), status = 'active', started_at = NOW(), current_period_end = VALUES(current_period_end),
                provider = 'payfast', provider_ref = VALUES(provider_ref), amount_cents = VALUES(amount_cents),
                cancel_at_period_end = 0, last_payment_at = NOW(), last_failed_at = NULL,
                note = VALUES(note), updated_by = NULL, updated_at = NOW()
        ")->execute([$type, $id, (int) $checkout['plan_id'], $end, $token, (int) $checkout['recurring_cents']]);
        $pdo->prepare("UPDATE billing_checkouts SET status = 'paid', token = ?, pf_payment_id = ?, completed_at = NOW() WHERE id = ?")
            ->execute([$token, $pfId ?: null, (int) $checkout['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $planCode = sdPlans(null, true)[(int) $checkout['plan_id']]['code'] ?? null;
    $oldCode  = $before ? (sdPlans()[(int) $before['plan_id']]['code'] ?? null) : (sdDefaultPlan($type)['code'] ?? null);
    writeAuditLog('plan.changed', sdAuditEntity($type), $id, ['plan' => $oldCode],
        ['plan' => $planCode, 'status' => 'active', 'until' => $end, 'note' => 'Paid online (PayFast)'], null);
    sdBillingNotify($type, $id, 'You’re on ' . (sdPlans()[(int) $checkout['plan_id']]['name'] ?? 'your new plan'),
        'Payment received. Your plan renews monthly until you cancel. Manage it on your Plan page.');
    sdPlanChangedRecheck($type, $id);   // 3b: paused cars come back, any downgrade window closes
    return 'activated';
}

/** Monthly renewal: move the paid-through date on. */
function sdBillingRenew(?array $sub, ?array $checkout, string $token): string
{
    $pdo = Database::getInstance();
    if (!$sub) {
        // Lapsed row was cleaned up but PayFast charged again (e.g. late retry): restore it.
        if ($checkout && $checkout['status'] === 'paid') {
            $checkout['status'] = 'started';
            return sdBillingActivate($checkout, $token, '') === 'activated' ? 'renewed' : 'rejected: could not restore';
        }
        return 'ignored: no subscription for token';
    }
    $pdo->prepare("
        UPDATE subscriptions
        SET status = 'active', current_period_end = ?, last_payment_at = NOW(), last_failed_at = NULL, updated_at = NOW()
        WHERE id = ?
    ")->execute([sdBillingPeriodEnd(), (int) $sub['id']]);
    if ($sub['status'] === 'past_due') {
        sdBillingNotify($sub['account_type'], (int) $sub['account_id'], 'Payment received', 'Thanks. Your plan is up to date again.');
    }
    return 'renewed';
}

/** A monthly payment failed: keep the plan until the paid-through date, flag it. */
function sdBillingMarkFailed(?array $sub, ?array $checkout): string
{
    if (!$sub) {
        if ($checkout && $checkout['status'] === 'started') {
            Database::getInstance()->prepare("UPDATE billing_checkouts SET status = 'failed', completed_at = NOW() WHERE id = ?")
                ->execute([(int) $checkout['id']]);
        }
        return 'failed';
    }
    Database::getInstance()->prepare("
        UPDATE subscriptions SET status = 'past_due', last_failed_at = NOW(), updated_at = NOW() WHERE id = ?
    ")->execute([(int) $sub['id']]);
    sdBillingNotify($sub['account_type'], (int) $sub['account_id'], 'Your payment didn’t go through',
        'PayFast couldn’t take this month’s payment. Your plan stays on until '
        . date('j M Y', strtotime((string) $sub['current_period_end'])) . '. Update your card on your Plan page.');
    return 'failed';
}

/** Cancelled at PayFast (by us, by the buyer, or after failed payments). */
function sdBillingMarkCancelled(?array $sub, ?array $checkout): string
{
    if (!$sub) {
        if ($checkout && $checkout['status'] === 'started') {
            Database::getInstance()->prepare("UPDATE billing_checkouts SET status = 'cancelled', completed_at = NOW() WHERE id = ?")
                ->execute([(int) $checkout['id']]);
        }
        return 'cancelled';
    }
    if (!(int) $sub['cancel_at_period_end']) {
        Database::getInstance()->prepare("UPDATE subscriptions SET cancel_at_period_end = 1, updated_at = NOW() WHERE id = ?")
            ->execute([(int) $sub['id']]);
        writeAuditLog('plan.cancelled', sdAuditEntity($sub['account_type']), (int) $sub['account_id'], null,
            ['until' => $sub['current_period_end'], 'via' => 'payfast'], null);
        sdBillingNotify($sub['account_type'], (int) $sub['account_id'], 'Subscription cancelled',
            'You won’t be charged again. Your plan stays on until ' . date('j M Y', strtotime((string) $sub['current_period_end'])) . ', then you’re on Free.');
    }
    return 'cancelled';
}

/** In-app notice to the account holder (brokers: the user; businesses: owner / principals). */
function sdBillingNotify(string $type, int $accountId, string $title, string $body): void
{
    try {
        $to  = $type === 'organization' ? sdBillingOrgContacts($accountId) : [$accountId];
        $ins = Database::getInstance()->prepare("
            INSERT INTO notifications (user_id, type, title, body, meta, created_at)
            VALUES (?, 'billing', ?, ?, ?, NOW())
        ");
        foreach ($to as $uid) {
            $ins->execute([$uid, $title, $body, json_encode(['link' => sdBillingPlanPage($type)])]);
        }
    } catch (Throwable) {
        // notifications are best-effort
    }
}


// ============================================================
// PAYFAST API (us → PayFast)
// ============================================================

/**
 * Call the PayFast REST API (api.payfast.co.za, version v1, signed headers).
 *
 * @return array{0:bool,1:array|string}  [ok, decoded body or error text]
 */
function sdPayfastApi(string $method, string $path, array $body = []): array
{
    if (!sdPayfastConfigured()) {
        return [false, 'PayFast is not configured.'];
    }
    sdPayfastLoadConfig();
    $headers = [
        'merchant-id' => trim((string) PAYFAST_MERCHANT_ID),
        'version'     => 'v1',
        'timestamp'   => date('Y-m-d\TH:i:sO'),
    ];
    $sig  = sdPayfastApiSignature(array_merge($headers, $body), sdPayfastPassphrase());
    $url  = 'https://api.payfast.co.za/' . ltrim($path, '/') . (sdPayfastSandbox() ? '?testing=true' : '');
    $http = [];
    foreach ($headers + ['signature' => $sig] as $k => $v) {
        $http[] = $k . ': ' . $v;
    }
    $http[] = 'Content-Type: application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $http,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => $body ? json_encode($body) : '',
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        return [false, 'Couldn’t reach PayFast (' . $err . ').'];
    }
    $data = json_decode((string) $res, true);
    if ($code >= 200 && $code < 300 && is_array($data) && ($data['status'] ?? 'success') === 'success') {
        return [true, $data];
    }
    $msg = is_array($data) ? (string) ($data['data']['message'] ?? $data['message'] ?? $data['status'] ?? 'error') : trim((string) $res);
    error_log("[SalesDesk PayFast] API {$method} {$path} → {$code}: " . mb_substr($msg, 0, 300));
    return [false, 'PayFast said: ' . mb_substr($msg, 0, 160)];
}

/**
 * Cancel an online subscription (the broker, or a superadmin for them).
 * The plan stays on until the paid-through date.
 *
 * @return array{0:bool,1:string}
 */
function sdBillingCancel(string $type, int $accountId, int $actorId): array
{
    $sub = sdSubscription($type, $accountId);
    if (!$sub || ($sub['provider'] ?? '') !== 'payfast' || !$sub['provider_ref']) {
        return [false, 'There’s no online subscription to cancel.'];
    }
    if ((int) $sub['cancel_at_period_end']) {
        return [true, 'Already cancelled. The plan stays on until ' . date('j M Y', strtotime((string) $sub['current_period_end'])) . '.'];
    }
    [$ok, $res] = sdPayfastApi('PUT', 'subscriptions/' . rawurlencode((string) $sub['provider_ref']) . '/cancel');
    if (!$ok) {
        return [false, 'The subscription couldn’t be cancelled right now. ' . $res . ' Please try again in a few minutes.'];
    }
    Database::getInstance()->prepare("UPDATE subscriptions SET cancel_at_period_end = 1, updated_at = NOW() WHERE id = ?")
        ->execute([(int) $sub['id']]);
    $self = $type === 'broker' ? $actorId === $accountId : in_array($actorId, sdBillingOrgContacts($accountId), true);
    writeAuditLog('plan.cancelled', sdAuditEntity($type), $accountId, null,
        ['until' => $sub['current_period_end'], 'via' => $self ? 'self' : 'admin'], $actorId);
    return [true, 'Cancelled. You won’t be charged again, and your plan stays on until '
        . date('j M Y', strtotime((string) $sub['current_period_end'])) . '.'];
}

/** PayFast's "update card" page for a subscription (live only — not in sandbox). */
function sdPayfastUpdateCardUrl(string $token, string $returnUrl): ?string
{
    if ($token === '' || sdPayfastSandbox()) {
        return null;
    }
    return 'https://www.payfast.co.za/eng/recurring/update/' . rawurlencode($token) . '?return=' . rawurlencode($returnUrl);
}


// ============================================================
// LAPSE (cron) + REPORTING
// ============================================================

/**
 * Daily: subscriptions past their paid-through date go back to Free
 * (row removed, audited, account told), and stale checkouts expire.
 *
 * @return string[] notes for the cron log
 */
function sdBillingLapse(): array
{
    if (!sdPlansReady()) {
        return ['Plans not ready.'];
    }
    $pdo   = Database::getInstance();
    $notes = [];
    $ended = $pdo->query("
        SELECT * FROM subscriptions
        WHERE current_period_end IS NOT NULL AND current_period_end < NOW()
    ")->fetchAll();
    foreach ($ended as $s) {
        $pdo->prepare("DELETE FROM subscriptions WHERE id = ?")->execute([(int) $s['id']]);
        $planCode = sdPlans()[(int) $s['plan_id']]['code'] ?? null;
        writeAuditLog('plan.changed', sdAuditEntity($s['account_type']), (int) $s['account_id'],
            ['plan' => $planCode, 'status' => $s['status'], 'until' => $s['current_period_end']],
            ['plan' => sdDefaultPlan($s['account_type'])['code'] ?? null, 'note' => 'Ended on ' . date('j M Y', strtotime((string) $s['current_period_end']))], null);
        sdBillingNotify($s['account_type'], (int) $s['account_id'], 'You’re on the Free plan now',
            (in_array($s['provider'], ['payfast', 'invoice'], true) && !(int) ($s['cancel_at_period_end'] ?? 0)
                ? ($s['provider'] === 'invoice'
                    ? 'We didn’t receive payment for your invoice, so your paid plan has ended. '
                    : 'We didn’t receive this month’s payment, so your paid plan has ended. ')
                : 'Your paid plan has ended. ')
            . ($s['account_type'] === 'broker'
                ? 'If your desk has more cars than the Free plan allows, you have ' . (defined('SD_GRACE_DAYS') ? SD_GRACE_DAYS : 7) . ' days to choose which stay live — the rest are paused, never deleted. '
                : 'If your business has more dealerships than the Free plan allows, the extra dealerships stop adding new cars after ' . (defined('SD_GRACE_DAYS') ? SD_GRACE_DAYS : 7) . ' days. ')
            . 'Subscribe again any time.');
        $notes[] = "{$s['account_type']} #{$s['account_id']}: {$planCode} ended → Free";
        sdPlanChangedRecheck($s['account_type'], (int) $s['account_id']);   // 3b: over the Free limit → 7-day window
    }
    if (sdBillingReady()) {
        $n = $pdo->exec("UPDATE billing_checkouts SET status = 'expired' WHERE status = 'started' AND created_at < NOW() - INTERVAL 2 DAY");
        if ($n) {
            $notes[] = "{$n} unfinished checkout(s) expired";
        }
    }
    return $notes ?: ['Nothing to do.'];
}

/** Latest ITNs for Admin → Plans → Billing. */
function sdBillingRecentEvents(int $limit = 20): array
{
    if (!sdBillingReady()) {
        return [];
    }
    $stmt = Database::getInstance()->prepare("
        SELECT e.*, u.email, o.name AS org_name,
               (SELECT MIN(d.id) FROM dealers d WHERE d.organization_id = e.account_id) AS org_dealer_id
        FROM billing_events e
        LEFT JOIN users u ON e.account_type = 'broker' AND u.id = e.account_id
        LEFT JOIN organizations o ON e.account_type = 'organization' AND o.id = e.account_id
        ORDER BY e.id DESC
        LIMIT " . max(1, min(100, $limit))
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/** How many accounts pay online, and the online MRR. */
function sdBillingSummary(): array
{
    $out = ['online' => 0, 'cancelling' => 0, 'past_due' => 0, 'mrr_cents' => 0, 'received_30d_cents' => 0];
    if (!sdBillingReady()) {
        return $out;
    }
    $pdo = Database::getInstance();
    foreach ($pdo->query("SELECT status, cancel_at_period_end, amount_cents, current_period_end FROM subscriptions WHERE provider = 'payfast'") as $s) {
        if (!sdSubscriptionGrants($s)) {
            continue;
        }
        $out['online']++;
        if ((int) $s['cancel_at_period_end']) {
            $out['cancelling']++;
        } else {
            $out['mrr_cents'] += (int) $s['amount_cents'];
        }
        if ($s['status'] === 'past_due') {
            $out['past_due']++;
        }
    }
    $out['received_30d_cents'] = (int) $pdo->query("
        SELECT COALESCE(SUM(amount_gross_cents), 0) FROM billing_events
        WHERE payment_status = 'COMPLETE' AND outcome IN ('activated', 'renewed') AND created_at > NOW() - INTERVAL 30 DAY
    ")->fetchColumn();
    return $out;
}
