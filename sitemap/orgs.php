<?php
/**
 * SalesDesk — Sitemap: Desk organisation hubs
 * Route: /sitemap-orgs.xml
 *
 * URL shape: /desks/{org-slug}/ and /desks/independent/ — the .htaccess
 * hub route (desks/org.php). Only hubs with at least one live desk are
 * listed: an empty hub is served noindex, so advertising it here would
 * only send crawlers to a page we've asked them not to index.
 */

declare(strict_types=1);

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/desk-directory.php';
require_once 'includes/sitemap-functions.php';

applyCachePolicy('public');

$xml = smCached('sitemap-orgs', SITEMAP_CACHE_TTL, function (): string {
    $pdo  = Database::getInstance();
    $base = smBaseUrl();
    $out  = smUrlsetOpen();

    try {
        $lastmodExpr = smLastmodExpr($pdo, 'organizations', 'o');
        $stmt = $pdo->query("
            SELECT o.slug, {$lastmodExpr} AS lastmod
            FROM organizations o
            WHERE o.is_active = 1
              AND EXISTS (
                  SELECT 1
                  FROM organization_members om
                  JOIN salesdesks sd ON sd.user_id = om.user_id AND sd.is_active = 1
                  JOIN users u       ON u.id = sd.user_id AND u.status = 'active'
                  WHERE om.organization_id = o.id" . sdVerifiedMemberSql('om') . "
              )
            ORDER BY o.id ASC
        ");
        $p = smPolicy('org_hub');
        while ($row = $stmt->fetch()) {
            $loc  = $base . '/desks/' . rawurlencode($row['slug']) . '/';
            $out .= smEmitUrl($loc, smW3cDate($row['lastmod']), $p['changefreq'], $p['priority']);
        }

        if (sdIndependentStats('')['desk_count'] > 0) {
            $out .= smEmitUrl($base . '/desks/' . SD_INDEPENDENT_SLUG . '/', smW3cDate(date('Y-m-d H:i:s')),
                              $p['changefreq'], $p['priority']);
        }
    } catch (Throwable) {
        // Fail soft — see cars.php for rationale.
    }

    $out .= smUrlsetClose();
    return $out;
});

smOutput($xml);
