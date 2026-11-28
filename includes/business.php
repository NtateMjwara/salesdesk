<?php
/**
 * SalesDesk — Business organisations  (0020, plans Phase 2a)
 *
 * The organisation IS the business account. Every dealership belongs to
 * exactly one org (dealers.organization_id); the org carries the business
 * plan and the broker network. Free/Pro businesses have one dealership,
 * Enterprise can have several (Phase 2d).
 *
 *   sdEnsureDealerOrganization($dealerId)  create the org if missing → org id
 *   sdSyncOrgFromDealer($dealerId)         keep a single-dealership org's
 *                                          name / logo / address / owner /
 *                                          verification in step
 *   sdLeadOrgSubquery('sd', 'd')           SQL: org a lead belongs to
 *   sdLegacyOrgSql('o')                    SQL: orgs that are NOT business orgs
 *   sdBackfillBusinessOrgs()               run once by the 0020 migration
 *
 * Call sites that create or change a dealership call ensure/sync:
 * auth/authentication.php (company step), app/admin/dealerships.php,
 * app/admin/dealerships-view.php, app/dealer/settings.php,
 * includes/principal_controls.php (handover).
 *
 * Before 0020 has run (sdBusinessReady() false) every function is a no-op
 * and the SQL helpers fall back to the old behaviour.
 *
 * Spec: project doc claude/plans-and-billing.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/** Placeholder name a dealer has before finishing the company step. */
const SD_DEALER_PLACEHOLDER_NAME = 'My Dealership';

/** Org slugs that are real /desks/ routes (see admin_scope.php adminUniqueSlug()). */
const SD_ORG_RESERVED_SLUGS = ['independent', 'org', 'index'];

/** True once db/0020_business_orgs.sql has run. */
function sdBusinessReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $s = Database::getInstance()->query("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND COLUMN_NAME = 'organization_id'
            ");
            $ready = (int) $s->fetchColumn() > 0;
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/** Unique org slug for /desks/{slug}/ hubs. */
function sdOrgSlug(string $name, ?int $ignoreId = null): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $base = trim(substr($base, 0, 56), '-') ?: 'business';
    $pdo  = Database::getInstance();
    $slug = $base;
    $n    = 2;
    while (true) {
        if (!in_array($slug, SD_ORG_RESERVED_SLUGS, true)) {
            $sql = "SELECT id FROM organizations WHERE slug = ?" . ($ignoreId ? " AND id <> ?" : '') . " LIMIT 1";
            $chk = $pdo->prepare($sql);
            $chk->execute($ignoreId ? [$slug, $ignoreId] : [$slug]);
            if (!$chk->fetch()) {
                return $slug;
            }
        }
        $slug = $base . '-' . $n++;
    }
}

/** The dealership row with what the org mirrors, or null. */
function sdDealerForOrg(int $dealerId): ?array
{
    $s = Database::getInstance()->prepare("
        SELECT id, user_id, organization_id, company_name, logo_url, address_id,
               verification_status, verified_at, created_by_admin_id
        FROM dealers WHERE id = ? LIMIT 1
    ");
    $s->execute([$dealerId]);
    return $s->fetch() ?: null;
}

/** Dealership's business org id (null before 0020 / if missing). */
function sdDealerOrgId(int $dealerId): ?int
{
    if (!sdBusinessReady()) {
        return null;
    }
    $s = Database::getInstance()->prepare("SELECT organization_id FROM dealers WHERE id = ? LIMIT 1");
    $s->execute([$dealerId]);
    $v = $s->fetchColumn();
    return $v ? (int) $v : null;
}

/** Dealerships under an org (Enterprise can have several). */
function sdOrgDealerCount(int $orgId): int
{
    $s = Database::getInstance()->prepare("SELECT COUNT(*) FROM dealers WHERE organization_id = ?");
    $s->execute([$orgId]);
    return (int) $s->fetchColumn();
}

/**
 * Make sure the dealership has its business org; create it if missing.
 * New orgs don't accept broker applications yet (network management
 * arrives in plans Phase 2c).
 *
 * @return int|null org id (null before 0020 or if the dealer is gone)
 */
function sdEnsureDealerOrganization(int $dealerId, ?int $actorId = null): ?int
{
    if (!sdBusinessReady()) {
        return null;
    }
    $d = sdDealerForOrg($dealerId);
    if (!$d) {
        return null;
    }
    $pdo = Database::getInstance();
    if (!empty($d['organization_id'])) {
        $exists = $pdo->prepare("SELECT id FROM organizations WHERE id = ? LIMIT 1");
        $exists->execute([(int) $d['organization_id']]);
        if ($exists->fetchColumn()) {
            return (int) $d['organization_id'];
        }
    }

    $name = trim((string) $d['company_name']) ?: SD_DEALER_PLACEHOLDER_NAME;
    $verification = in_array($d['verification_status'], ['unverified', 'pending', 'verified', 'rejected'], true)
        ? $d['verification_status'] : 'unverified';

    $pdo->prepare("
        INSERT INTO organizations
            (uuid, name, slug, accepting_applications, owner_user_id, address_id,
             verification_status, verified_at, logo_url, is_active, created_by_admin_id,
             created_at, updated_at)
        VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
    ")->execute([
        generateUuidV4(),
        mb_substr($name, 0, 120),
        sdOrgSlug($name),
        $d['user_id'] ? (int) $d['user_id'] : null,
        $d['address_id'] ? (int) $d['address_id'] : null,
        $verification,
        $verification === 'verified' ? ($d['verified_at'] ?: date('Y-m-d H:i:s')) : null,
        $d['logo_url'] ?: null,
        $d['created_by_admin_id'] ? (int) $d['created_by_admin_id'] : null,
    ]);
    $orgId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE dealers SET organization_id = ? WHERE id = ?")->execute([$orgId, $dealerId]);

    writeAuditLog('org.business_created', 'organization', $orgId, null,
        ['dealer_id' => $dealerId, 'name' => $name], $actorId);
    return $orgId;
}

/**
 * Keep a single-dealership org in step with its dealership: name, logo,
 * address, owner (principal) and verification. Enterprise orgs with
 * several dealerships keep their own name and logo; only the owner is
 * left alone there too (it's the business, not one rooftop).
 *
 * The slug only changes while the org still has a placeholder slug
 * ("my-dealership…"), so a live /desks/{slug}/ hub never moves.
 */
function sdSyncOrgFromDealer(int $dealerId): void
{
    if (!sdBusinessReady()) {
        return;
    }
    $orgId = sdEnsureDealerOrganization($dealerId);
    $d     = sdDealerForOrg($dealerId);
    if (!$orgId || !$d || sdOrgDealerCount($orgId) > 1) {
        return;
    }
    $pdo = Database::getInstance();
    $cur = $pdo->prepare("SELECT slug FROM organizations WHERE id = ?");
    $cur->execute([$orgId]);
    $slug = (string) $cur->fetchColumn();

    $name = trim((string) $d['company_name']) ?: SD_DEALER_PLACEHOLDER_NAME;
    $placeholderSlug = (bool) preg_match('/^my-dealership(-\d+)?$/', $slug);
    $newSlug = ($placeholderSlug && $name !== SD_DEALER_PLACEHOLDER_NAME) ? sdOrgSlug($name, $orgId) : $slug;

    $verification = in_array($d['verification_status'], ['unverified', 'pending', 'verified', 'rejected'], true)
        ? $d['verification_status'] : 'unverified';

    $pdo->prepare("
        UPDATE organizations
        SET name = ?, slug = ?, logo_url = ?, address_id = ?, owner_user_id = ?,
            verification_status = ?, verified_at = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([
        mb_substr($name, 0, 120),
        $newSlug,
        $d['logo_url'] ?: null,
        $d['address_id'] ? (int) $d['address_id'] : null,
        $d['user_id'] ? (int) $d['user_id'] : null,
        $verification,
        $verification === 'verified' ? ($d['verified_at'] ?: date('Y-m-d H:i:s')) : null,
        $orgId,
    ]);
}

/**
 * Create a business org for every dealership that has none.
 * Called by the migration runner right after db/0020_business_orgs.sql.
 *
 * @return string[] notes for the migration log
 */
function sdBackfillBusinessOrgs(int $actorId = 0): array
{
    if (!sdBusinessReady()) {
        return ['Business orgs not created: dealers.organization_id is missing.'];
    }
    $pdo = Database::getInstance();
    $ids = $pdo->query("
        SELECT d.id FROM dealers d
        LEFT JOIN organizations o ON o.id = d.organization_id
        WHERE o.id IS NULL
        ORDER BY d.id
    ")->fetchAll(PDO::FETCH_COLUMN);

    $made = 0;
    foreach ($ids as $id) {
        if (sdEnsureDealerOrganization((int) $id, $actorId ?: null)) {
            $made++;
        }
    }
    $total = (int) $pdo->query("SELECT COUNT(*) FROM dealers WHERE organization_id IS NOT NULL")->fetchColumn();
    return ["Business orgs: created {$made}; {$total} dealership(s) now belong to a business account."];
}


// ============================================================
// PLANS 2d — ENTERPRISE: several dealerships under one business
// ============================================================
//
// A superadmin attaches dealerships to a business (Admin → Dealerships →
// Business), up to the plan's max_dealerships (Free / Pro 1, Enterprise
// unlimited). One business = one network, one plan, one owner:
//
//   • the business OWNER (organizations.owner_user_id) is one of its
//     dealerships' principals; in the dealer portal they can switch to
//     every dealership in the business ('group' mode, dealer_context.php)
//   • each dealership keeps its own principal (dealers.user_id stays
//     UNIQUE — one principal per dealership)
//   • a multi-dealership business keeps its own name (sdSyncOrgFromDealer
//     only mirrors single-dealership businesses); the slug never changes
//
// Attaching a dealership whose own business has no other dealership
// MERGES that business in: its network members move across (a broker
// connected to both keeps one row, with the stronger status), its leads /
// commissions / payouts are re-tagged, and the empty business is
// deleted. A business on a paid or comped plan must be moved to Free
// first, so no subscription is silently dropped.

/** Dealerships in a business, owner's first. */
function sdOrgDealerships(int $orgId): array
{
    if (!sdBusinessReady()) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT d.id, d.company_name, d.slug, d.user_id, d.is_active, d.verification_status,
               u.email AS principal_email,
               TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS principal_name,
               a.city, a.province,
               (o.owner_user_id IS NOT NULL AND o.owner_user_id = d.user_id) AS is_owner
        FROM dealers d
        JOIN organizations o  ON o.id = d.organization_id
        LEFT JOIN users u     ON u.id = d.user_id
        LEFT JOIN profiles p  ON p.user_id = d.user_id
        LEFT JOIN addresses a ON a.id = d.address_id
        WHERE d.organization_id = ?
        ORDER BY is_owner DESC, d.company_name
    ");
    $s->execute([$orgId]);
    return $s->fetchAll();
}

/** Dealerships that could be attached to $orgId (any not already in it), with their current business. */
function sdAttachableDealerships(int $orgId): array
{
    if (!sdBusinessReady()) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT d.id, d.company_name, a.city,
               o.name AS org_name,
               (SELECT COUNT(*) FROM dealers d2 WHERE d2.organization_id = d.organization_id) AS org_dealers
        FROM dealers d
        LEFT JOIN organizations o ON o.id = d.organization_id
        LEFT JOIN addresses a     ON a.id = d.address_id
        WHERE d.organization_id IS NULL OR d.organization_id <> ?
        ORDER BY d.company_name
    ");
    $s->execute([$orgId]);
    return $s->fetchAll();
}

/** Effective max_dealerships for a business (null = unlimited). */
function sdOrgDealerLimit(int $orgId): ?int
{
    require_once __DIR__ . '/entitlements.php';
    return entitlement('organization', $orgId, 'max_dealerships');
}

/**
 * Move a dealership into a business (superadmin).
 *
 * @return array{0:bool,1:string}
 */
function sdAttachDealership(int $orgId, int $dealerId, int $actorId): array
{
    require_once __DIR__ . '/superadmin.php';
    require_once __DIR__ . '/entitlements.php';
    if (!sdBusinessReady()) {
        return [false, 'Run 0020 Business orgs in Admin → Migrations first.'];
    }
    if (!isSuperadmin($actorId)) {
        return [false, 'Only a superadmin can change which dealerships belong to a business.'];
    }
    $pdo = Database::getInstance();
    $org = $pdo->prepare("SELECT id, name, owner_user_id FROM organizations WHERE id = ? LIMIT 1");
    $org->execute([$orgId]);
    $org = $org->fetch();
    $d   = sdDealerForOrg($dealerId);
    if (!$org || !$d) {
        return [false, 'That business or dealership wasn’t found.'];
    }
    if ((int) $d['organization_id'] === $orgId) {
        return [false, "{$d['company_name']} is already part of {$org['name']}."];
    }
    $limit = sdOrgDealerLimit($orgId);
    $count = sdOrgDealerCount($orgId);
    if (!sdHasRoom($limit, $count)) {
        return [false, "{$org['name']} is on a plan with " . sdLimitText($limit, 'max_dealerships') . ' dealership'
            . ($limit === 1 ? '' : 's') . '. Move it to Enterprise (or set a custom dealership limit) first.'];
    }

    $fromId    = $d['organization_id'] ? (int) $d['organization_id'] : null;
    $mergeFrom = $fromId && sdOrgDealerCount($fromId) === 1 ? $fromId : null;
    if ($mergeFrom) {
        $sub = sdSubscription('organization', $mergeFrom);
        if ($sub && sdSubscriptionGrants($sub)) {
            $fromName = $pdo->query('SELECT name FROM organizations WHERE id = ' . $mergeFrom)->fetchColumn();
            return [false, "{$fromName} has its own business plan. Move it to Free on its Business tab first, so nothing is dropped silently."];
        }
    }

    $moved = $dupes = 0;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE dealers SET organization_id = ?, updated_at = NOW() WHERE id = ?")->execute([$orgId, $dealerId]);

        if ($mergeFrom) {
            // Network: a broker connected to both keeps ONE row, with the
            // stronger status (verified > suspended > pending > invited > rejected).
            $pdo->prepare("
                UPDATE organization_members t
                JOIN organization_members m ON m.user_id = t.user_id AND m.organization_id = ?
                SET t.status = m.status, t.verified_by = m.verified_by, t.verified_at = m.verified_at,
                    t.rejection_reason = m.rejection_reason, t.invited_by = m.invited_by
                WHERE t.organization_id = ?
                  AND FIELD(m.status, 'rejected', 'invited', 'pending', 'suspended', 'verified')
                    > FIELD(t.status, 'rejected', 'invited', 'pending', 'suspended', 'verified')
            ")->execute([$mergeFrom, $orgId]);
            $dupes = $pdo->prepare("
                DELETE m FROM organization_members m
                JOIN organization_members t ON t.user_id = m.user_id AND t.organization_id = ?
                WHERE m.organization_id = ?
            ");
            $dupes->execute([$orgId, $mergeFrom]);
            $dupes = $dupes->rowCount();
            $mv = $pdo->prepare("UPDATE organization_members SET organization_id = ? WHERE organization_id = ?");
            $mv->execute([$orgId, $mergeFrom]);
            $moved = $mv->rowCount();

            foreach (['leads', 'commissions', 'payouts'] as $table) {
                $pdo->prepare("UPDATE `{$table}` SET organization_id = ? WHERE organization_id = ?")->execute([$orgId, $mergeFrom]);
            }
            $pdo->prepare("INSERT IGNORE INTO organization_managers (organization_id, admin_user_id, added_by, created_at)
                           SELECT ?, admin_user_id, added_by, created_at FROM organization_managers WHERE organization_id = ?")
                ->execute([$orgId, $mergeFrom]);
            $pdo->prepare("DELETE FROM account_limit_overrides WHERE account_type = 'organization' AND account_id = ?")->execute([$mergeFrom]);
            $pdo->prepare("DELETE FROM subscriptions WHERE account_type = 'organization' AND account_id = ?")->execute([$mergeFrom]);
            $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$mergeFrom]);
            writeAuditLog('org.business_merged', 'organization', $orgId, ['merged_org_id' => $mergeFrom],
                ['dealer_id' => $dealerId, 'members_moved' => $moved, 'duplicates_dropped' => $dupes], $actorId);
        }
        writeAuditLog('org.dealership_attached', 'organization', $orgId, ['from_org_id' => $fromId],
            ['dealer_id' => $dealerId, 'company_name' => $d['company_name']], $actorId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[SalesDesk sdAttachDealership] ' . $e->getMessage());
        return [false, 'The dealership couldn’t be moved. Nothing was changed.'];
    }

    $notify = array_filter([(int) ($d['user_id'] ?? 0), (int) ($org['owner_user_id'] ?? 0)]);
    if ($notify) {
        $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, meta, created_at) VALUES (?, 'business_change', ?, ?, ?, NOW())");
        foreach (array_unique($notify) as $uid) {
            $ins->execute([$uid, "{$d['company_name']} is now part of {$org['name']}",
                'It shares the business’s plan and broker network. The business owner can switch to it in the dealer portal.',
                json_encode(['org_id' => $orgId, 'dealer_id' => $dealerId])]);
        }
    }

    require_once __DIR__ . '/plan_grace.php';
    sdGraceRecheck('organization', $orgId);   // 3b
    require_once __DIR__ . '/vip_network.php';
    sdVipSyncOrg($orgId);                     // 4a: its plan / members now cover the new dealership's cars

    return [true, "{$d['company_name']} is now part of {$org['name']}."
        . ($mergeFrom ? " Its network moved across ({$moved} broker" . ($moved === 1 ? '' : 's') . ($dupes ? ", {$dupes} already connected" : '') . ').' : '')
        . ($count === 1 ? ' Give the business its own name below.' : '')];
}

/**
 * Move a dealership out of a multi-dealership business (superadmin). It
 * gets a business of its own again (Free, closed network). The network
 * stays with the business it leaves.
 *
 * @return array{0:bool,1:string}
 */
function sdDetachDealership(int $dealerId, int $actorId): array
{
    require_once __DIR__ . '/superadmin.php';
    if (!sdBusinessReady() || !isSuperadmin($actorId)) {
        return [false, 'Only a superadmin can change which dealerships belong to a business.'];
    }
    $pdo = Database::getInstance();
    $d   = sdDealerForOrg($dealerId);
    if (!$d || !$d['organization_id']) {
        return [false, 'That dealership wasn’t found.'];
    }
    $orgId = (int) $d['organization_id'];
    $org   = $pdo->prepare("SELECT name, owner_user_id FROM organizations WHERE id = ?");
    $org->execute([$orgId]);
    $org = $org->fetch();
    if (sdOrgDealerCount($orgId) < 2) {
        return [false, "{$d['company_name']} is the only dealership in its business."];
    }
    if ($d['user_id'] && (int) $d['user_id'] === (int) $org['owner_user_id']) {
        return [false, "{$d['company_name']} belongs to the business owner. Choose another owner first."];
    }
    $pdo->prepare("UPDATE dealers SET organization_id = NULL, updated_at = NOW() WHERE id = ?")->execute([$dealerId]);
    $newOrg = sdEnsureDealerOrganization($dealerId, $actorId);
    writeAuditLog('org.dealership_detached', 'organization', $orgId, null,
        ['dealer_id' => $dealerId, 'new_org_id' => $newOrg], $actorId);
    if ($d['user_id']) {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, meta, created_at) VALUES (?, 'business_change', ?, ?, ?, NOW())")
            ->execute([(int) $d['user_id'], "{$d['company_name']} is its own business again",
                "It’s no longer part of {$org['name']}. It starts on the Free plan with its own broker network (closed).",
                json_encode(['dealer_id' => $dealerId])]);
    }
    require_once __DIR__ . '/plan_grace.php';
    sdGraceRecheck('organization', $orgId);   // 3b: back within the limit → pauses lifted
    if ($newOrg) {
        sdGraceRecheck('organization', (int) $newOrg);
    }
    require_once __DIR__ . '/vip_network.php';
    sdVipSyncDealer($dealerId);               // 4a: its cars follow the new business's plan (Free → open)
    return [true, "{$d['company_name']} moved out of {$org['name']}. It has its own business account again (Free)."];
}

/**
 * Name a multi-dealership business (single-dealership businesses take
 * the dealership's name). The slug — the network page URL — stays.
 *
 * @return array{0:bool,1:string}
 */
function sdRenameBusiness(int $orgId, string $name, int $actorId): array
{
    require_once __DIR__ . '/superadmin.php';
    if (!isSuperadmin($actorId)) {
        return [false, 'Only a superadmin can rename a business.'];
    }
    $name = mb_substr(trim($name), 0, 120);
    if (mb_strlen($name) < 2) {
        return [false, 'Enter the business name.'];
    }
    if (sdOrgDealerCount($orgId) < 2) {
        return [false, 'A single-dealership business takes its dealership’s name. Rename the dealership on its Details tab.'];
    }
    $pdo = Database::getInstance();
    $old = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $old->execute([$orgId]);
    $pdo->prepare("UPDATE organizations SET name = ?, updated_at = NOW() WHERE id = ?")->execute([$name, $orgId]);
    writeAuditLog('org.business_renamed', 'organization', $orgId, ['name' => $old->fetchColumn()], ['name' => $name], $actorId);
    return [true, "Business renamed to {$name}."];
}

/**
 * Choose the business owner: a principal of one of its dealerships. The
 * owner can switch between all the business's dealerships in the portal.
 *
 * @return array{0:bool,1:string}
 */
function sdSetBusinessOwner(int $orgId, int $userId, int $actorId): array
{
    require_once __DIR__ . '/superadmin.php';
    if (!isSuperadmin($actorId)) {
        return [false, 'Only a superadmin can change the business owner.'];
    }
    $pdo = Database::getInstance();
    $ok  = $pdo->prepare("SELECT d.company_name FROM dealers d JOIN users u ON u.id = d.user_id AND u.status = 'active'
                          WHERE d.organization_id = ? AND d.user_id = ? LIMIT 1");
    $ok->execute([$orgId, $userId]);
    $dealerName = $ok->fetchColumn();
    if (!$dealerName) {
        return [false, 'The owner must be the principal of one of the business’s dealerships.'];
    }
    $old = $pdo->prepare("SELECT owner_user_id FROM organizations WHERE id = ?");
    $old->execute([$orgId]);
    $pdo->prepare("UPDATE organizations SET owner_user_id = ?, updated_at = NOW() WHERE id = ?")->execute([$userId, $orgId]);
    writeAuditLog('org.owner_changed', 'organization', $orgId, ['owner_user_id' => $old->fetchColumn() ?: null],
        ['owner_user_id' => $userId], $actorId);
    return [true, "The principal of {$dealerName} now owns the business."];
}


// ============================================================
// SQL HELPERS
// ============================================================

/**
 * SQL that limits an organizations alias to NON-business orgs (none left
 * after 0020). Used so admin assignment lists don't show every business
 * org as an "unmanaged org" — business orgs are managed through their
 * dealership (dealer_managers).
 */
function sdLegacyOrgSql(string $alias = 'o'): string
{
    return sdBusinessReady()
        ? " AND NOT EXISTS (SELECT 1 FROM dealers sd_bd WHERE sd_bd.organization_id = {$alias}.id)"
        : '';
}

/**
 * Scalar subquery: the org a lead belongs to.
 *
 * After 0020: the business org that owns the car's dealership, but only
 * if the broker is a verified member of that network. Otherwise NULL
 * (independent). Before 0020: the broker's own approved desk org (old rule).
 *
 * @param string $deskAlias   salesdesks alias (sd.user_id = broker)
 * @param string $dealerAlias dealers alias of the car's dealership
 */
function sdLeadOrgSubquery(string $deskAlias = 'sd', string $dealerAlias = 'd'): string
{
    if (sdBusinessReady()) {
        return "(SELECT om.organization_id FROM organization_members om
                 WHERE om.user_id = {$deskAlias}.user_id
                   AND om.organization_id = {$dealerAlias}.organization_id" . sdVerifiedMemberSql('om') . "
                 LIMIT 1)";
    }
    return "(SELECT om.organization_id FROM organization_members om
             WHERE om.user_id = {$deskAlias}.user_id" . sdVerifiedMemberSql('om') . "
             LIMIT 1)";
}
