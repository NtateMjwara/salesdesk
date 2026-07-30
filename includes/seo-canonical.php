<?php
/**
 * SalesDesk — Faceted-navigation SEO policy for /cars-for-sale/
 *
 * The browse page has ~10 independent filter dimensions (make,
 * condition, body_type[], fuel_type[], transmission[], drivetrain[],
 * province, desk, price/mileage/year ranges, sort, page). Left alone,
 * that's a near-infinite set of crawlable URLs — the textbook faceted-
 * navigation duplicate-content problem.
 *
 * Policy used here (deliberately NOT a robots.txt Disallow — see the
 * note at the bottom for why that's the wrong tool):
 *
 *   1. No filters at all              → self-canonical, indexable
 *   2. Exactly ONE filter, and it's a  → self-canonical, indexable
 *      "curated" single-value facet      (these are real landing pages —
 *      (condition, a single make,        your homepage links to them
 *      or a single body_type)             directly)
 *   3. Anything else (2+ facets        → canonical → base /cars-for-sale/,
 *      combined, price/mileage/year      AND meta robots "noindex,follow"
 *      ranges, desk filter, search q,     (crawlable so link equity still
 *      non-default sort, page > 1)        flows, just not indexed)
 *
 * "noindex,follow" (not robots.txt Disallow) is used for case 3
 * specifically so Google can still CRAWL the page, see the canonical
 * hint, and pass link equity back to the base listing. A robots.txt
 * Disallow would block the crawl entirely — Google would never see
 * the canonical tag, and could still show the bare URL in results
 * with no snippet ("blocked by robots.txt"), which is worse.
 */

declare(strict_types=1);

/**
 * Facets allowed to stand alone as their own indexable landing page.
 * Keep this list intentional and small — every entry here is a page
 * you're actively promising Google is unique, useful content, not just
 * "the same list of cars, filtered." Only add a value here if it's
 * also linked to from somewhere in the site (homepage pills, nav,
 * etc.) — an indexable page with no internal links pointing to it is
 * a dead end for both users and crawlers.
 */
function seoCuratedBrowseFacets(): array
{
    return [
        'condition' => ['new', 'demo', 'used'],
        'body_type' => ['Bakkie', 'Hatchback', 'Sedan', 'SUV'],
        // Makes are open-ended (DB-driven) rather than a fixed list —
        // any single `make` value is allowed alone, handled specially
        // below rather than enumerated here.
    ];
}

/**
 * Decides canonical URL + whether to noindex, given the current
 * request's filters. Call this from cars-for-sale/index.php AFTER all
 * the existing $make/$condition/$bodyTypesSelected/etc. variables are
 * parsed, and BEFORE the page-meta block that currently sets
 * $pageTitle/$ogTitle/etc.
 *
 * Returns ['canonical' => string, 'noindex' => bool].
 */
function seoResolveBrowseCanonical(
    string $baseUrl,
    string $q,
    string $make,
    string $condition,
    string $province,
    string $deskSlug,
    array  $bodyTypesSelected,
    array  $fuelTypes,
    array  $transmissions,
    array  $drivetrains,
    ?int   $priceMin,
    ?int   $priceMax,
    ?int   $mileageMin,
    ?int   $mileageMax,
    ?int   $yearMin,
    ?int   $yearMax,
    string $sort,
    int    $page
): array {
    $basePath = $baseUrl . '/cars-for-sale/';

    // Count every active facet, regardless of whether it's curated.
    $activeFacets = array_filter([
        'q'            => $q,
        'province'     => $province,
        'desk'         => $deskSlug,
        'fuel_type'    => $fuelTypes,
        'transmission' => $transmissions,
        'drivetrain'   => $drivetrains,
        'price'        => ($priceMin !== null || $priceMax !== null) ? '1' : '',
        'mileage'      => ($mileageMin !== null || $mileageMax !== null) ? '1' : '',
        'year'         => ($yearMin !== null || $yearMax !== null) ? '1' : '',
    ], fn($v) => !empty($v));

    $hasMake      = $make !== '';
    $hasCondition = $condition !== '';
    $bodyTypeCount = count($bodyTypesSelected);
    $curatedBodyTypes = seoCuratedBrowseFacets()['body_type'];
    $hasCuratedBodyType = $bodyTypeCount === 1 && in_array($bodyTypesSelected[0], $curatedBodyTypes, true);

    $curatedFacetCount = ($hasMake ? 1 : 0) + ($hasCondition ? 1 : 0) + ($hasCuratedBodyType ? 1 : 0);
    $totalFacetCount    = count($activeFacets) + ($hasMake ? 1 : 0) + ($hasCondition ? 1 : 0) + $bodyTypeCount;

    $sortIsDefault = ($sort === 'newest' || $sort === '');
    $isPageOne     = ($page <= 1);

    // Case 1 & 2: no filters, or exactly one CURATED filter, default
    // sort, first page → this is a real landing page.
    $isCuratedLanding = $totalFacetCount === 0
        || ($totalFacetCount === 1 && $curatedFacetCount === 1);

    if ($isCuratedLanding && $sortIsDefault) {
        $canonical = $basePath;
        if ($hasCondition)       $canonical .= '?condition=' . rawurlencode($condition);
        elseif ($hasMake)        $canonical .= '?make=' . rawurlencode($make);
        elseif ($hasCuratedBodyType) $canonical .= '?body_type%5B%5D=' . rawurlencode($bodyTypesSelected[0]);

        // Page 2+ of even a curated landing page is still worth its
        // own self-canonical (real distinct content), just append page.
        if (!$isPageOne) {
            $canonical .= (str_contains($canonical, '?') ? '&' : '?') . 'page=' . $page;
        }

        return ['canonical' => $canonical, 'noindex' => false];
    }

    // Case 3: everything else — canonical rolls up to the base listing,
    // noindex so the thin/duplicate combination itself doesn't compete
    // in search results, but 'follow' so link equity + crawl still
    // reaches every car detail page linked from it.
    return ['canonical' => $basePath, 'noindex' => true];
}

/**
 * Renders the <link rel="canonical"> and, when needed, <meta
 * name="robots"> tags. Echo the return value inside layout-public.php's
 * <head> alongside where $canonicalUrl is currently used — see the
 * integration guide for the exact line.
 */
function seoRenderCanonicalTags(string $canonicalUrl, bool $noindex = false): string
{
    $out = '<link rel="canonical" href="' . htmlspecialchars($canonicalUrl, ENT_QUOTES) . '">' . "\n";
    if ($noindex) {
        $out .= '<meta name="robots" content="noindex,follow">' . "\n";
    }
    return $out;
}
