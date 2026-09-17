<?php
/**
 * SalesDesk — LEGACY route shim: /c/  →  /cars-for-sale/   (301)
 *
 * The browse page moved from c/ to cars-for-sale/ (ROUTE-1). This file
 * used to be a full, drifting copy of the old browse page. It is now a
 * permanent redirect only, so old bookmarks, shared links and indexed
 * URLs pass their ranking signal to the canonical page.
 *
 * WHY A PHP FILE IS NEEDED AT ALL:
 *   .htaccess serves any existing directory directly ("-d → [L]") BEFORE
 *   its LEGACY /c/ redirect rules run. While the c/ directory exists,
 *   a request for /c/ lands here and never reaches that rewrite rule.
 *   (/c/index.php is first 301'd to /c/ by the "remove trailing
 *   index.php" rule, then lands here.)
 *
 * Behaviour:
 *   /c/                         → /cars-for-sale/
 *   /c/?make=Toyota&page=2      → /cars-for-sale/?make=Toyota&page=2
 *   Array filters (body_type[] etc.) are preserved. The query string is
 *   rebuilt with http_build_query(), never echoed raw, so no header
 *   injection is possible.
 *
 * Deliberately loads nothing from includes/ — no DB, session or visitor
 * tracking for a request that only exists to redirect.
 */

declare(strict_types=1);

$target = '/cars-for-sale/';

$query = http_build_query($_GET);
// body_type[0]=SUV → body_type[]=SUV, matching the site's own filter links.
$query = preg_replace('/%5B\d+%5D=/', '%5B%5D=', $query);
if ($query !== '') {
    $target .= '?' . $query;
}

header('Cache-Control: public, max-age=86400');
header('Location: ' . $target, true, 301);
exit;
