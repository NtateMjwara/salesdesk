<?php
/**
 * SalesDesk — PayFast settings  (plans Phase 3)
 *
 * HOW TO USE
 *   1. Copy this file to   includes/payfast.config.php   on the server.
 *   2. Fill in the values below.
 *   3. Never commit payfast.config.php to GitHub. It holds your passphrase.
 *      (includes/ is blocked from the web by .htaccess, so it isn't downloadable.)
 *
 * Until payfast.config.php exists, SalesDesk keeps the old "Request Pro"
 * button and nothing is charged.
 *
 * WHERE TO FIND THE VALUES
 *   PayFast dashboard → Settings → Integration:
 *     Merchant ID, Merchant Key, and the passphrase you set for recurring
 *     billing. The passphrase must match EXACTLY (type it, don't paste trailing
 *     spaces). Recurring billing → Subscriptions must be enabled.
 *
 * SANDBOX FIRST
 *   The sandbox (https://sandbox.payfast.co.za) has its OWN merchant ID, key
 *   and passphrase: log in there, set a passphrase, and use those values with
 *   PAYFAST_MODE 'sandbox'. Switch to your live values and 'live' when the
 *   test payments look right in Admin → Plans → Billing.
 */

define('PAYFAST_MODE',         'live');        // 'sandbox' or 'live'
define('PAYFAST_MERCHANT_ID',  '38187115');               // e.g. '10000100'
define('PAYFAST_MERCHANT_KEY', '5wxwgegztdkqx');               // e.g. '46f0cd694581a'
define('PAYFAST_PASSPHRASE',   '6dd529de0689844227c1c59eeb0b2c74');               // the recurring-billing passphrase

// Only if your site sits behind a proxy / CDN (e.g. Cloudflare) so PHP sees
// the proxy's address instead of PayFast's: true makes the source check use
// the first X-Forwarded-For address. Leave false otherwise.
define('PAYFAST_TRUST_PROXY',  false);
