<?php
/**
 * SalesDesk — Desk directory queries  (shared by /desks/ and the org hubs)
 *
 *   /desks/                      organisations + all brokers   desks/index.php
 *   /desks/{org-slug}/           one organisation's desks       desks/org.php
 *   /desks/independent/          brokers with no organisation   desks/org.php
 *
 * "Belongs to an org" means exactly what sdDeskOrg() means: an APPROVED
 * agent (organization_members.status = 'verified' after 0013) of an
 * ACTIVE organisation. Everyone else is independent. Both helpers use
 * the same rule so a desk is never listed under two hubs, or none.
 *
 * Ordering is deliberately neutral and merit-based — hub position
 * passes internal-link weight, so it must be something every desk can
 * earn: live cars first, then views, then oldest desk id (stable).
 * Signup date and admin choice never decide it.
 *
 * Presentation only — nothing here touches attribution.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/desk-seo.php';

/** Sort keys accepted by every directory page. */
function sdDeskSorts(): array
{
    return [
        'active'  => 'Most cars listed',
        'popular' => 'Most viewed',
        'newest'  => 'Newest desks',
        'name'    => 'Name (A–Z)',
    ];
}

/**
 * SQL condition (alias u = users) + params limiting desks to a scope.
 *   null           → every desk
 *   'independent'  → desks whose broker is not an approved agent of an active org
 *   int            → desks of approved agents of that org id
 */
function sdDeskScopeSql(int|string|null $scope): array
{
    if ($scope === null) {
        return ['', []];
    }
    $member = "SELECT 1 FROM organization_members om2
               JOIN organizations o2 ON o2.id = om2.organization_id AND o2.is_active = 1
               WHERE om2.user_id = u.id" . sdVerifiedMemberSql('om2');

    if ($scope === SD_INDEPENDENT_SLUG) {
        return ["NOT EXISTS ({$member})", []];
    }
    return ["EXISTS ({$member} AND om2.organization_id = ?)", [(int) $scope]];
}

/**
 * One page of desks for a directory.
 *
 * @param array{scope?:int|string|null,q?:string,province?:string,sort?:string,page?:int,per_page?:int} $o
 * @return array{desks:array,total:int,page:int,total_pages:int,previews:array}
 */
function sdDeskListing(array $o): array
{
    $pdo      = Database::getInstance();
    $q        = trim((string) ($o['q'] ?? ''));
    $province = trim((string) ($o['province'] ?? ''));
    $sort     = array_key_exists($o['sort'] ?? '', sdDeskSorts()) ? $o['sort'] : 'active';
    $perPage  = max(1, (int) ($o['per_page'] ?? 12));
    $page     = max(1, (int) ($o['page'] ?? 1));

    $where  = ['sd.is_active = 1', "u.status = 'active'"];
    $params = [];

    [$scopeSql, $scopeParams] = sdDeskScopeSql($o['scope'] ?? null);
    if ($scopeSql !== '') {
        $where[] = $scopeSql;
        array_push($params, ...$scopeParams);
    }
    if ($q !== '') {
        $where[] = '(sd.display_name LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)';
        $like    = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($province !== '') {
        $where[]  = 'a.province = ?';
        $params[] = $province;
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM salesdesks sd
        JOIN users u          ON u.id = sd.user_id
        LEFT JOIN profiles p  ON p.user_id = u.id
        LEFT JOIN addresses a ON a.id = p.address_id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $total      = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $orderBy = match ($sort) {
        'popular' => 'total_views DESC, cars_count DESC, sd.id ASC',
        'newest'  => 'sd.created_at DESC, sd.id DESC',
        'name'    => 'sd.display_name ASC, sd.id ASC',
        default   => 'cars_count DESC, total_views DESC, sd.id ASC',
    };

    // Counts are correlated subqueries so views and closed deals can't
    // multiply each other (joining inventory AND leads in one GROUP BY
    // inflated SUM(bi.views) by the lead count).
    $stmt = $pdo->prepare("
        SELECT
            sd.id, sd.uuid, sd.slug, sd.display_name, sd.tagline,
            sd.logo_url, sd.primary_colour, sd.created_at,
            p.first_name, p.last_name, p.avatar_url,
            a.city, a.province, a.suburb,
            org.org_name, org.org_slug, org.org_verification,
            (SELECT COUNT(*) FROM broker_inventory bi
               JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
              WHERE bi.salesdesk_id = sd.id)                          AS cars_count,
            (SELECT COALESCE(SUM(bi.views), 0) FROM broker_inventory bi
              WHERE bi.salesdesk_id = sd.id)                          AS total_views,
            (SELECT COUNT(*) FROM leads l
              WHERE l.salesdesk_id = sd.id AND l.status = 'closed')   AS deals_closed
        FROM salesdesks sd
        JOIN users u          ON u.id = sd.user_id
        LEFT JOIN profiles p  ON p.user_id = u.id
        LEFT JOIN addresses a ON a.id = p.address_id
        LEFT JOIN (
            SELECT om.user_id,
                   MIN(o.name)                AS org_name,
                   MIN(o.slug)                AS org_slug,
                   MIN(o.verification_status) AS org_verification
            FROM organization_members om
            JOIN organizations o ON o.id = om.organization_id AND o.is_active = 1
            WHERE 1 = 1" . sdVerifiedMemberSql('om') . "
            GROUP BY om.user_id
        ) org ON org.user_id = u.id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $desks = $stmt->fetchAll();

    return [
        'desks'       => $desks,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
        'previews'    => sdDeskPreviews(array_map(static fn($d) => (int) $d['id'], $desks)),
    ];
}

/**
 * Newest 3 car photos per desk — ONE query for the whole page.
 * @return array<int, list<array{src:string,alt:string}>>
 */
function sdDeskPreviews(array $deskIds): array
{
    if (!$deskIds) {
        return [];
    }
    $ph  = implode(',', array_fill(0, count($deskIds), '?'));
    $out = [];
    try {
        $stmt = Database::getInstance()->prepare("
            SELECT salesdesk_id, image_urls, make, model
            FROM (
                SELECT bi.salesdesk_id, c.image_urls, c.make, c.model,
                       ROW_NUMBER() OVER (PARTITION BY bi.salesdesk_id ORDER BY bi.added_at DESC) AS rn
                FROM broker_inventory bi
                JOIN cars c ON c.id = bi.car_id AND c.status = 'active'
                WHERE bi.salesdesk_id IN ({$ph})
            ) ranked
            WHERE rn <= 3
        ");
        $stmt->execute($deskIds);
        foreach ($stmt->fetchAll() as $row) {
            $imgs = json_decode($row['image_urls'] ?? '[]', true) ?: [];
            if (!empty($imgs[0])) {
                $out[(int) $row['salesdesk_id']][] = [
                    'src' => $imgs[0],
                    'alt' => trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')),
                ];
            }
        }
    } catch (Throwable) {
        return [];   // window functions unavailable — cards render without previews
    }
    return $out;
}

/**
 * Organisations that have at least one live desk, for the /desks/
 * directory. Optional province narrows to orgs with desks there (and
 * counts only those desks).
 *
 * Car counts are DISTINCT cars — the same car on 12 agents' desks is
 * one car in the org's stock, not twelve.
 */
function sdOrgDirectory(string $province = ''): array
{
    if (!sdOrgSchemaReady()) {
        return [];
    }
    $pdo    = Database::getInstance();
    $params = [];
    $provSql = '';
    if ($province !== '') {
        $provSql  = ' AND pa.province = ?';
        $params[] = $province;
    }

    $rows = $pdo->prepare("
        SELECT
            o.id, o.name, o.slug, o.brands, o.logo_url, o.verification_status, o.description,
            oa.city, oa.province,
            COUNT(DISTINCT sd.id)                                        AS desk_count,
            COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN c.id END)     AS car_count,
            COUNT(DISTINCT pa.province)                                  AS province_count
        FROM organizations o
        JOIN organization_members om ON om.organization_id = o.id" . sdVerifiedMemberSql('om') . "
        JOIN salesdesks sd           ON sd.user_id = om.user_id AND sd.is_active = 1
        JOIN users u                 ON u.id = sd.user_id AND u.status = 'active'
        LEFT JOIN profiles p         ON p.user_id = u.id
        LEFT JOIN addresses pa       ON pa.id = p.address_id
        LEFT JOIN addresses oa       ON oa.id = o.address_id
        LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
        LEFT JOIN cars c             ON c.id = bi.car_id AND c.status = 'active'
        WHERE o.is_active = 1{$provSql}
        GROUP BY o.id
        ORDER BY car_count DESC, desk_count DESC, o.name ASC
    ");
    $rows->execute($params);
    $orgs = $rows->fetchAll();

    foreach ($orgs as &$org) {
        $org['brand_list'] = sdOrgBrandList($org['brands'] ?? null);
    }
    unset($org);
    return $orgs;
}

/**
 * Newest 3 car photos per org (across all its agents' desks, each car
 * once). One query.
 * @return array<int, list<array{src:string,alt:string}>>
 */
function sdOrgPreviews(array $orgIds): array
{
    if (!$orgIds || !sdOrgSchemaReady()) {
        return [];
    }
    $ph  = implode(',', array_fill(0, count($orgIds), '?'));
    $out = [];
    try {
        $stmt = Database::getInstance()->prepare("
            SELECT organization_id, image_urls, make, model
            FROM (
                SELECT om.organization_id, c.image_urls, c.make, c.model,
                       ROW_NUMBER() OVER (PARTITION BY om.organization_id ORDER BY MAX(bi.added_at) DESC) AS rn
                FROM organization_members om
                JOIN salesdesks sd       ON sd.user_id = om.user_id AND sd.is_active = 1
                JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
                JOIN cars c              ON c.id = bi.car_id AND c.status = 'active'
                WHERE om.organization_id IN ({$ph})" . sdVerifiedMemberSql('om') . "
                GROUP BY om.organization_id, c.id, c.image_urls, c.make, c.model
            ) ranked
            WHERE rn <= 3
        ");
        $stmt->execute($orgIds);
        foreach ($stmt->fetchAll() as $row) {
            $imgs = json_decode($row['image_urls'] ?? '[]', true) ?: [];
            if (!empty($imgs[0])) {
                $out[(int) $row['organization_id']][] = [
                    'src' => $imgs[0],
                    'alt' => trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')),
                ];
            }
        }
    } catch (Throwable) {
        return [];
    }
    return $out;
}

/**
 * Totals for the independent-brokers tile / hub hero.
 * @return array{desk_count:int,car_count:int}
 */
function sdIndependentStats(string $province = ''): array
{
    [$scopeSql] = sdDeskScopeSql(SD_INDEPENDENT_SLUG);
    $params  = [];
    $provSql = '';
    if ($province !== '') {
        $provSql  = ' AND a.province = ?';
        $params[] = $province;
    }
    $stmt = Database::getInstance()->prepare("
        SELECT COUNT(DISTINCT sd.id) AS desk_count,
               COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN c.id END) AS car_count
        FROM salesdesks sd
        JOIN users u             ON u.id = sd.user_id AND u.status = 'active'
        LEFT JOIN profiles p     ON p.user_id = u.id
        LEFT JOIN addresses a    ON a.id = p.address_id
        LEFT JOIN broker_inventory bi ON bi.salesdesk_id = sd.id
        LEFT JOIN cars c         ON c.id = bi.car_id AND c.status = 'active'
        WHERE sd.is_active = 1 AND {$scopeSql}{$provSql}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return ['desk_count' => (int) ($row['desk_count'] ?? 0), 'car_count' => (int) ($row['car_count'] ?? 0)];
}

/**
 * Provinces that have live desks in a scope, with desk counts —
 * drives the province chips / select on every directory page.
 * @return array<string,int>
 */
function sdDeskProvinceCounts(int|string|null $scope = null): array
{
    [$scopeSql, $scopeParams] = sdDeskScopeSql($scope);
    $stmt = Database::getInstance()->prepare("
        SELECT a.province, COUNT(DISTINCT sd.id) AS desk_count
        FROM salesdesks sd
        JOIN users u     ON u.id = sd.user_id AND u.status = 'active'
        JOIN profiles p  ON p.user_id = u.id
        JOIN addresses a ON a.id = p.address_id
        WHERE sd.is_active = 1 AND a.province IS NOT NULL AND a.province != ''"
        . ($scopeSql !== '' ? " AND {$scopeSql}" : '') . "
        GROUP BY a.province
        ORDER BY a.province ASC
    ");
    $stmt->execute($scopeParams);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Fallback province list when the DB has none yet. */
function sdAllProvinces(): array
{
    return [
        'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
        'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
    ];
}

/** Compact number for stat rows: 1 200 → 1.2k */
function sdCompactNumber(int $n): string
{
    return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : (string) $n;
}
