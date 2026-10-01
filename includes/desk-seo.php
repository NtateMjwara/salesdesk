<?php
/**
 * SalesDesk — Desk & organisation SEO helpers
 *
 * Presentation only. Nothing in this file is read by lead submission,
 * tracking codes or commission — attribution still keys off the desk
 * slug in the URL (or a valid ?ref=), exactly as before. The org shows
 * up in titles, descriptions, breadcrumbs and JSON-LD; it never
 * appears in a desk or car URL, so a broker who changes org (or goes
 * independent) keeps every URL and every shared link.
 *
 * Title pattern agreed for desk pages:
 *     {Desk name} | {Org name} | SalesDesk
 *     {Desk name} | Independent Car Broker | SalesDesk
 * Suffixes are dropped from the right when the title would run past
 * what Google shows (~60 chars): "| SalesDesk" goes first — the site
 * name is shown above the result anyway via WebSite schema.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/** Hub slug for brokers who are not agents of any organisation. */
const SD_INDEPENDENT_SLUG  = 'independent';
const SD_INDEPENDENT_LABEL = 'Independent brokers';

/** Org slugs that can never be issued (they're real /desks/ routes). */
function sdReservedOrgSlugs(): array
{
    return [SD_INDEPENDENT_SLUG, 'org', 'index'];   // keep in sync with adminUniqueSlug()
}

/**
 * Build a <title> from a primary part plus optional suffixes, adding
 * each suffix only while the whole thing still fits in $max chars.
 * The primary part is always kept, however long.
 */
function sdSeoTitle(string $primary, array $suffixes, int $max = 60): string
{
    $title = trim($primary);
    foreach ($suffixes as $suffix) {
        $suffix = trim((string) $suffix);
        if ($suffix === '') {
            continue;
        }
        $candidate = $title . ' | ' . $suffix;
        if (mb_strlen($candidate) <= $max) {
            $title = $candidate;
        }
    }
    return $title;
}

/**
 * False for the onboarding default ("My SalesDesk") and other names
 * that would give hundreds of identical titles. Desks without a real
 * name are served with noindex until the broker renames them.
 */
function sdDeskHasRealName(?string $name): bool
{
    $n = mb_strtolower(trim((string) $name));
    return $n !== '' && !in_array($n, ['my salesdesk', 'my sales desk', 'salesdesk', 'my desk'], true);
}

/**
 * The organisation a broker is an APPROVED agent of (active org only),
 * or null for an independent broker. Cached per request.
 *
 * @return array{id:int,name:string,slug:string,verification_status:string,logo_url:?string,brand_list:array}|null
 */
function sdDeskOrg(int $brokerUserId): ?array
{
    static $cache = [];
    if (array_key_exists($brokerUserId, $cache)) {
        return $cache[$brokerUserId];
    }

    $brandCol = sdOrgSchemaReady() ? 'o.brands' : 'NULL AS brands';
    $stmt = Database::getInstance()->prepare("
        SELECT o.id, o.name, o.slug, o.verification_status, o.logo_url, {$brandCol}
        FROM organization_members om
        JOIN organizations o ON o.id = om.organization_id
        WHERE om.user_id = ? AND o.is_active = 1" . sdVerifiedMemberSql('om') . "
        LIMIT 1
    ");
    $stmt->execute([$brokerUserId]);
    $row = $stmt->fetch() ?: null;

    if ($row) {
        $row['id']         = (int) $row['id'];
        $row['brand_list'] = sdOrgBrandList($row['brands'] ?? null);
    }
    return $cache[$brokerUserId] = $row;
}

/** organizations.brands JSON → clean list (same rules as orgBrands()). */
function sdOrgBrandList(?string $json): array
{
    $arr = $json ? json_decode($json, true) : null;
    return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
}

/** ["Volkswagen","Audi","Skoda"] → "Volkswagen, Audi & Skoda". */
function sdBrandPhrase(array $brands): string
{
    $brands = array_values(array_filter(array_map('trim', $brands)));
    if (count($brands) <= 1) {
        return $brands[0] ?? '';
    }
    $last = array_pop($brands);
    return implode(', ', $brands) . ' & ' . $last;
}

/** Public URL path of an org hub (or the independent hub for null). */
function sdOrgHubPath(?array $org): string
{
    return '/desks/' . rawurlencode($org['slug'] ?? SD_INDEPENDENT_SLUG) . '/';
}

/** Breadcrumb entry for the org level: [label, href]. */
function sdOrgCrumb(?array $org): array
{
    return [$org['name'] ?? SD_INDEPENDENT_LABEL, sdOrgHubPath($org)];
}

/** Second title segment for a desk: the org name, or "Independent Car Broker". */
function sdOrgTitleSegment(?array $org): string
{
    return $org['name'] ?? 'Independent Car Broker';
}

/**
 * Meta description for a desk storefront, ≤160 chars.
 *   "Thabo's Desk: Volkswagen car broker in Sandton, Gauteng · VW South
 *    Africa agent · 34 cars in stock. {tagline}"
 */
function sdDeskMetaDescription(string $deskName, ?array $org, string $location, int $carCount, ?string $tagline): string
{
    $brands = $org ? sdBrandPhrase($org['brand_list'] ?? []) : '';
    $what   = trim(($brands !== '' ? $brands . ' ' : '') . 'car broker');
    $parts  = [$deskName . ': ' . $what . ($location !== '' ? ' in ' . $location : '')];
    $parts[] = $org ? $org['name'] . ' agent' : 'independent SalesDesk broker';
    $parts[] = $carCount . ' car' . ($carCount === 1 ? '' : 's') . ' in stock';

    $out = implode(' · ', $parts) . '.';
    if ($tagline) {
        $out .= ' ' . trim($tagline);
    }
    return mb_strimwidth($out, 0, 160, '…');
}
