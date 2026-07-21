<?php
/**
 * SalesDesk — Cookie Consent (server-side helpers)
 * Include this from any file that needs to know what a visitor has
 * consented to before setting a non-essential cookie or rendering a
 * script tag — e.g. includes/visitor.php should call
 * hasConsent('functional') before setting the 90-day broker
 * attribution cookie (see INTEGRATION NOTE at the bottom of this file).
 *
 * Nothing in here talks to the browser directly except reading
 * $_COOKIE — setting the cookie itself is done client-side in
 * assets/js/cookie-consent.js so the banner can react instantly
 * without a page reload. This file exists so *server-rendered* PHP
 * (which can't wait for JS) can still ask "am I allowed to do X?"
 * on the very same request the banner is shown on.
 */

declare(strict_types=1);

// Bump this whenever the categories, their purposes, or the wording
// shown to visitors changes materially. Changing it re-prompts every
// visitor, regardless of their stored cookie, and is what lets you
// prove in cookie_consents.policy_version exactly which version of
// the notice someone agreed to.
const COOKIE_CONSENT_POLICY_VERSION = '2026-07-19.1';

const COOKIE_CONSENT_COOKIE_NAME = 'sd_cookie_consent';

/**
 * The categories shown in the banner, in display order. This is the
 * single source of truth — the banner partial and the save endpoint
 * both read from this instead of hardcoding category names, so adding
 * a category only ever means editing this one array.
 *
 * 'locked' categories can't be turned off by the visitor (POPIA/GDPR
 * both exempt strictly-necessary cookies from requiring consent).
 */
function cookieConsentCategories(): array
{
    return [
        'necessary' => [
            'label'  => 'Strictly necessary',
            'locked' => true,
            'desc'   => 'Keeps you logged in, remembers items in a submitted enquiry, '
                      . 'and protects forms against fraud. The site cannot function without these.',
        ],
        'functional' => [
            'label'  => 'Functional',
            'locked' => false,
            'desc'   => 'Remembers your recently viewed cars, wishlist, and saved searches. '
                      . 'Also credits the broker whose link you arrived through, for up to 90 days, '
                      . 'so their commission is protected if you later submit an enquiry.',
        ],
        'analytics' => [
            'label'  => 'Analytics',
            'locked' => false,
            'desc'   => 'Helps us understand which cars and pages are popular, so we can improve '
                      . 'the site. Data is aggregated and does not identify you personally.',
        ],
        'marketing' => [
            'label'  => 'Marketing',
            'locked' => false,
            'desc'   => 'Used to show you more relevant vehicle recommendations and measure the '
                      . 'performance of our own ads. We do not sell this data to third parties.',
        ],
    ];
}

/**
 * Reads and validates the consent cookie. Returns null if there's no
 * cookie, it's malformed, or it was recorded under an older policy
 * version — all three of which mean "treat this visitor as not yet
 * having made a choice" and the banner should show again.
 */
function getCookieConsent(): ?array
{
    $raw = $_COOKIE[COOKIE_CONSENT_COOKIE_NAME] ?? null;
    if (!$raw) return null;

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return null;
    if (($decoded['v'] ?? null) !== COOKIE_CONSENT_POLICY_VERSION) return null;

    foreach (array_keys(cookieConsentCategories()) as $cat) {
        if (!array_key_exists($cat, $decoded)) return null;
    }

    return $decoded;
}

/** Has the visitor made ANY choice yet under the current policy version? */
function hasCookieConsentDecision(): bool
{
    return getCookieConsent() !== null;
}

/**
 * Has the visitor consented to a specific category? 'necessary' is
 * always true. Categories with no recorded decision yet default to
 * false — never assume consent that wasn't explicitly given.
 */
function hasConsent(string $category): bool
{
    if ($category === 'necessary') return true;

    $consent = getCookieConsent();
    if ($consent === null) return false;

    return (bool) ($consent[$category] ?? false);
}

/**
 * Hashes an IP for the audit-log table — never store IPs in the
 * clear. Same pattern this codebase already uses for outreach ID
 * numbers ("encrypted before storage").
 */
function hashIpForAudit(string $ip): string
{
    // Pepper with policy version so hashes aren't stable across a
    // notice change — not a security requirement, just avoids being
    // able to correlate identical hashes across unrelated deployments.
    return hash('sha256', $ip . '|' . COOKIE_CONSENT_POLICY_VERSION);
}

/**
 * Inserts one row into cookie_consents. Called from
 * api/consent/save.php. Kept here (rather than only in the endpoint)
 * so any other place that ever needs to log a consent event
 * programmatically — e.g. a future account-settings "cookie
 * preferences" page — can reuse the exact same logic.
 */
function recordCookieConsent(
    PDO $pdo,
    ?string $visitorSessionId,
    array $decision,
    string $action,
    ?string $ip,
    ?string $userAgent
): void {
    $stmt = $pdo->prepare("
        INSERT INTO cookie_consents
            (visitor_session_id, necessary, functional, analytics, marketing,
             policy_version, action, ip_hash, user_agent, created_at)
        VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $visitorSessionId,
        !empty($decision['functional']) ? 1 : 0,
        !empty($decision['analytics'])  ? 1 : 0,
        !empty($decision['marketing'])  ? 1 : 0,
        COOKIE_CONSENT_POLICY_VERSION,
        $action,
        $ip ? hashIpForAudit($ip) : null,
        $userAgent ? substr($userAgent, 0, 255) : null,
    ]);
}

/*
 * ============================================================
 * INTEGRATION NOTE — includes/visitor.php
 * ============================================================
 * Your existing broker-attribution flow (see cars-for-sale/index.php:
 * `$ref = trim($_GET['ref'] ?? $visitor['last_tracking_code'] ?? '')`)
 * relies on a cookie that persists a tracking code for 90 days. That
 * cookie falls under the "functional" category above and legally
 * requires consent before it's set — it identifies an individual
 * visitor's browsing/referral behavior over time, which is exactly
 * the kind of cookie POPIA/GDPR carve-outs for "strictly necessary"
 * do NOT cover.
 *
 * Wherever includes/visitor.php currently does something like:
 *
 *   setcookie('sd_tracking_code', $ref, time() + 90*86400, '/');
 *
 * wrap it with:
 *
 *   require_once __DIR__ . '/cookie-consent.php';
 *   if (hasConsent('functional')) {
 *       setcookie('sd_tracking_code', $ref, time() + 90*86400, '/');
 *   }
 *
 * If a visitor hasn't yet made a cookie choice (or said no to
 * "Functional"), the ?ref= code should still be honored for that
 * single page view/request (read it straight from $_GET, don't
 * silently drop the referral) — you're only skipping the *persistent
 * cookie*, not the current request's attribution. Once they accept
 * "Functional" (even later in the same session), the next page they
 * land on with a ?ref= will start persisting normally.
 * ============================================================
 */
