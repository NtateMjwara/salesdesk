<?php
/**
 * SalesDesk — VIP network perks, part 1  (plans Phase 4a, migration 0024)
 *
 * For businesses whose plan has vip_perks (Pro, Enterprise):
 *
 *   NETWORK-ONLY STOCK
 *     • the business marks cars network-only (cars.network_only). While it
 *       has VIP perks the mark is in force (cars.network_exclusive):
 *         – only its verified network brokers see the car in the
 *           Marketplace and can add it to a desk
 *         – it is never a platform listing: buyers reach it through a
 *           network broker's desk (public pages filter with
 *           sdPublicCarSql(); a car with no network desk is hidden)
 *         – desk rows of brokers outside the network are PARKED
 *           (broker_inventory_parked, reason 'network'), never deleted
 *     • parked rows come back by themselves when the car opens up, the
 *       business loses VIP perks, or the broker joins the network — as
 *       long as the broker's desk has room (otherwise they stay in the
 *       broker's "Paused cars" to bring back by hand)
 *
 *   NETWORK BONUS
 *     • an extra amount (R) or % of the car price on top of the car's
 *       commission, for leads brought by the business's verified network
 *       brokers, on any of its cars (organizations.network_bonus_*)
 *     • locked in when the lead comes in (leads.network_bonus_*), paid
 *       on close as part of the commission (commissions.network_bonus,
 *       included in gross_amount, so the platform fee applies as usual)
 *
 * Re-sync points:
 *   sdVipSyncOrg(orgId)      — plan / limit change (entitlements.php),
 *                              attach / detach (business.php), daily cron
 *   sdVipSyncCars(carIds)    — a dealer marks / unmarks cars
 *   sdVipSyncBroker(userId)  — a broker joins, leaves, is suspended,
 *                              reinstated or removed (org_membership.php)
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/plan_grace.php';

const SD_NETWORK_BONUS_MAX_PCT   = 10;       // % of the car price
const SD_NETWORK_BONUS_MAX_FIXED = 100000;   // rand


// ============================================================
// READINESS + SQL HELPERS
// ============================================================

/** 0024 has run (cars.network_exclusive exists). */
function sdVipReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) Database::getInstance()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'network_exclusive'"
            )->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Public pages: a network-only car shows only through a (network) desk.
 * Desk rows of non-members are parked, so any desk row is a member's.
 */
function sdPublicCarSql(string $c = 'c'): string
{
    if (!sdVipReady()) {
        return '1=1';
    }
    return "({$c}.network_exclusive = 0 OR EXISTS (SELECT 1 FROM broker_inventory bx_vip WHERE bx_vip.car_id = {$c}.id))";
}

/** Businesses the broker is a verified member of. */
function sdBrokerVerifiedOrgIds(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    try {
        $s = Database::getInstance()->prepare("SELECT organization_id FROM organization_members WHERE user_id = ?" . sdVerifiedMemberSql('organization_members'));
        $s->execute([$userId]);
        return $cache[$userId] = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return $cache[$userId] = [];
    }
}

/**
 * Marketplace: network-only cars only for members of their business.
 *
 * @return array{0:string,1:array} SQL fragment + params
 */
function sdMarketplaceNetworkSql(int $userId, string $c = 'c', string $d = 'd'): array
{
    if (!sdVipReady()) {
        return ['1=1', []];
    }
    $orgs = sdBrokerVerifiedOrgIds($userId);
    if (!$orgs) {
        return ["{$c}.network_exclusive = 0", []];
    }
    return ["({$c}.network_exclusive = 0 OR {$d}.organization_id IN (" . implode(',', array_fill(0, count($orgs), '?')) . '))', $orgs];
}

/** May this broker list this car (network-only rule only)? */
function sdBrokerMayListCar(int $userId, int $carId): bool
{
    if (!sdVipReady()) {
        return true;
    }
    $s = Database::getInstance()->prepare("SELECT c.network_exclusive, d.organization_id FROM cars c JOIN dealers d ON d.id = c.dealer_id WHERE c.id = ?");
    $s->execute([$carId]);
    $r = $s->fetch();
    if (!$r || !(int) $r['network_exclusive']) {
        return true;
    }
    return in_array((int) $r['organization_id'], sdBrokerVerifiedOrgIdsFresh($userId), true);
}

/** Same as sdBrokerVerifiedOrgIds but never cached (used by syncs). */
function sdBrokerVerifiedOrgIdsFresh(int $userId): array
{
    try {
        $s = Database::getInstance()->prepare("SELECT organization_id FROM organization_members WHERE user_id = ?" . sdVerifiedMemberSql('organization_members'));
        $s->execute([$userId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return [];
    }
}

/** Does the business have VIP perks on its plan? */
function sdOrgVipActive(int $orgId): bool
{
    return $orgId > 0 && sdPlansReady() && canDo('organization', $orgId, 'vip_perks');
}


// ============================================================
// NETWORK BONUS
// ============================================================

/** The business's bonus in force (null = none, or no VIP perks). */
function sdOrgBonus(int $orgId): ?array
{
    if (!sdVipReady() || !sdOrgVipActive($orgId)) {
        return null;
    }
    $s = Database::getInstance()->prepare("SELECT network_bonus_type, network_bonus_value FROM organizations WHERE id = ?");
    $s->execute([$orgId]);
    $r = $s->fetch();
    if (!$r || !$r['network_bonus_type'] || (float) $r['network_bonus_value'] <= 0) {
        return null;
    }
    return ['type' => (string) $r['network_bonus_type'], 'value' => (float) $r['network_bonus_value']];
}

/** Bonus in rand for a price. */
function sdBonusAmount(?string $type, $value, $price): float
{
    $value = (float) $value;
    if (!$type || $value <= 0) {
        return 0.0;
    }
    return $type === 'fixed' ? round($value, 2) : round((float) $price * $value / 100, 2);
}

/** "+R1,000" or "+0.5% of the price". */
function sdBonusText(?string $type, $value): string
{
    $value = (float) $value;
    if (!$type || $value <= 0) {
        return '';
    }
    return $type === 'fixed'
        ? '+R' . number_format($value, 0, '.', ',')
        : '+' . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '% of the price';
}

/** Bonus map for several businesses: orgId => ['type','value'] (VIP only). */
function sdOrgBonuses(array $orgIds): array
{
    $out = [];
    foreach (array_unique(array_map('intval', $orgIds)) as $id) {
        if ($b = sdOrgBonus($id)) {
            $out[$id] = $b;
        }
    }
    return $out;
}

/**
 * Save the business's bonus. $type null/'' switches it off.
 *
 * @return array{0:bool,1:string}
 */
function sdSetOrgBonus(int $orgId, ?string $type, $value, int $actorId): array
{
    if (!sdVipReady()) {
        return [false, 'Run 0024 first.'];
    }
    if (!sdOrgVipActive($orgId)) {
        return [false, 'The network bonus comes with the Pro and Enterprise business plans.'];
    }
    $pdo = Database::getInstance();
    $old = $pdo->prepare("SELECT network_bonus_type, network_bonus_value FROM organizations WHERE id = ?");
    $old->execute([$orgId]);
    $old = $old->fetch() ?: [];

    if ($type === null || $type === '' || $type === 'none') {
        $pdo->prepare("UPDATE organizations SET network_bonus_type = NULL, network_bonus_value = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([$orgId]);
        writeAuditLog('org.network_bonus_changed', 'organization', $orgId, $old, ['type' => null], $actorId);
        return [true, 'Network bonus switched off. Leads already in keep the bonus they came in with.'];
    }
    if (!in_array($type, ['fixed', 'percentage'], true)) {
        return [false, 'Choose a rand amount or a percentage.'];
    }
    $value = round((float) str_replace([',', ' '], ['.', ''], (string) $value), 2);
    if ($value <= 0) {
        return [false, 'Enter a bonus above zero, or switch the bonus off.'];
    }
    if ($type === 'percentage' && $value > SD_NETWORK_BONUS_MAX_PCT) {
        return [false, 'A percentage bonus can be at most ' . SD_NETWORK_BONUS_MAX_PCT . '% of the price.'];
    }
    if ($type === 'fixed' && $value > SD_NETWORK_BONUS_MAX_FIXED) {
        return [false, 'A rand bonus can be at most R' . number_format(SD_NETWORK_BONUS_MAX_FIXED, 0, '.', ',') . '.'];
    }
    $pdo->prepare("UPDATE organizations SET network_bonus_type = ?, network_bonus_value = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$type, $value, $orgId]);
    writeAuditLog('org.network_bonus_changed', 'organization', $orgId, $old, ['type' => $type, 'value' => $value], $actorId);
    return [true, 'Network bonus saved: ' . sdBonusText($type, $value) . ' per sale for your network brokers, on leads from now on.'];
}


// ============================================================
// NETWORK-ONLY STOCK
// ============================================================

/**
 * Mark / unmark a dealership's cars as network-only.
 *
 * @param int[] $onIds  car ids that should be network-only (others in
 *                      $scopeIds are unmarked)
 * @param int[] $scopeIds car ids the form showed (only these change)
 * @return array{0:bool,1:string}
 */
function sdSetCarsNetworkOnly(int $dealerId, array $scopeIds, array $onIds, int $actorId): array
{
    if (!sdVipReady()) {
        return [false, 'Run 0024 first.'];
    }
    $pdo = Database::getInstance();
    $org = (int) $pdo->query('SELECT COALESCE(organization_id, 0) FROM dealers WHERE id = ' . $dealerId)->fetchColumn();
    if (!sdOrgVipActive($org)) {
        return [false, 'Network-only stock comes with the Pro and Enterprise business plans.'];
    }
    $scopeIds = array_values(array_unique(array_filter(array_map('intval', $scopeIds))));
    if (!$scopeIds) {
        return [false, 'No cars to change.'];
    }
    $in  = implode(',', array_fill(0, count($scopeIds), '?'));
    $own = $pdo->prepare("SELECT id, network_only FROM cars WHERE dealer_id = ? AND id IN ({$in})");
    $own->execute(array_merge([$dealerId], $scopeIds));
    $cur = [];
    foreach ($own->fetchAll() as $r) {
        $cur[(int) $r['id']] = (int) $r['network_only'];
    }
    $want    = array_flip(array_map('intval', $onIds));
    $changed = [];
    $upd     = $pdo->prepare("UPDATE cars SET network_only = ?, updated_at = NOW() WHERE id = ?");
    foreach ($cur as $id => $was) {
        $now = isset($want[$id]) ? 1 : 0;
        if ($now !== $was) {
            $upd->execute([$now, $id]);
            $changed[$id] = $now;
        }
    }
    if (!$changed) {
        return [true, 'Nothing changed.'];
    }
    writeAuditLog('car.network_only_changed', 'dealer', $dealerId, null, ['cars' => $changed], $actorId);
    $res  = sdVipSyncCars(array_keys($changed));
    $on   = count(array_filter($changed));
    $off  = count($changed) - $on;
    $msg  = trim(($on ? "{$on} car" . ($on === 1 ? '' : 's') . ' now network-only. ' : '')
               . ($off ? "{$off} car" . ($off === 1 ? '' : 's') . ' open to every broker again. ' : ''));
    if ($res['parked']) {
        $msg .= " {$res['parked']} listing" . ($res['parked'] === 1 ? '' : 's') . ' by brokers outside your network paused (not deleted).';
    }
    return [true, $msg];
}

/** Recompute every car of a business (plan change, attach / detach, daily). */
function sdVipSyncOrg(int $orgId): array
{
    if (!sdVipReady() || $orgId <= 0) {
        return ['parked' => 0, 'restored' => 0];
    }
    $s = Database::getInstance()->prepare("
        SELECT c.id FROM cars c JOIN dealers d ON d.id = c.dealer_id
        WHERE d.organization_id = ? AND (c.network_only = 1 OR c.network_exclusive = 1
              OR EXISTS (SELECT 1 FROM broker_inventory_parked p WHERE p.car_id = c.id AND p.reason = 'network'))
    ");
    $s->execute([$orgId]);
    return sdVipSyncCars(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
}

/** A dealership's cars (after it moved business). */
function sdVipSyncDealer(int $dealerId): array
{
    if (!sdVipReady()) {
        return ['parked' => 0, 'restored' => 0];
    }
    $s = Database::getInstance()->prepare("SELECT id FROM cars WHERE dealer_id = ? AND (network_only = 1 OR network_exclusive = 1
        OR EXISTS (SELECT 1 FROM broker_inventory_parked p WHERE p.car_id = cars.id AND p.reason = 'network'))");
    $s->execute([$dealerId]);
    return sdVipSyncCars(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Bring cars' in-force flag up to date, then park desk rows of brokers who
 * may no longer list them and restore 'network' parked rows they may.
 *
 * @return array{parked:int, restored:int}
 */
function sdVipSyncCars(array $carIds): array
{
    $carIds = array_values(array_unique(array_filter(array_map('intval', $carIds))));
    $out    = ['parked' => 0, 'restored' => 0];
    if (!$carIds || !sdVipReady()) {
        return $out;
    }
    $pdo = Database::getInstance();
    $in  = implode(',', $carIds);

    // 1. In-force flag, per business.
    $rows = $pdo->query("SELECT c.id, c.network_only, c.network_exclusive, COALESCE(d.organization_id, 0) AS org_id
                         FROM cars c JOIN dealers d ON d.id = c.dealer_id WHERE c.id IN ({$in})")->fetchAll();
    $vip  = [];
    $set  = $pdo->prepare("UPDATE cars SET network_exclusive = ? WHERE id = ?");
    $carOrg = [];
    $excl   = [];
    foreach ($rows as $r) {
        $org = (int) $r['org_id'];
        $vip[$org] ??= sdOrgVipActive($org);
        $now = (int) $r['network_only'] && $vip[$org] ? 1 : 0;
        if ($now !== (int) $r['network_exclusive']) {
            $set->execute([$now, (int) $r['id']]);
        }
        $carOrg[(int) $r['id']] = $org;
        $excl[(int) $r['id']]   = $now;
    }

    // 2. Park rows of brokers outside the network.
    $members = [];
    $isMember = static function (int $userId, int $org) use (&$members): bool {
        $members[$userId] ??= sdBrokerVerifiedOrgIdsFresh($userId);
        return in_array($org, $members[$userId], true);
    };
    $exclIds = array_keys(array_filter($excl));
    $parkedBy = [];   // userId => [car titles]
    if ($exclIds) {
        $desk = $pdo->query("SELECT bi.id, bi.salesdesk_id, bi.car_id, sd.user_id,
                                    CONCAT(c.year, ' ', c.make, ' ', c.model) AS title, o.name AS org_name
                             FROM broker_inventory bi
                             JOIN salesdesks sd ON sd.id = bi.salesdesk_id
                             JOIN cars c ON c.id = bi.car_id
                             JOIN dealers d ON d.id = c.dealer_id
                             LEFT JOIN organizations o ON o.id = d.organization_id
                             WHERE bi.car_id IN (" . implode(',', $exclIds) . ")")->fetchAll();
        $byDesk = [];
        foreach ($desk as $r) {
            if (!$isMember((int) $r['user_id'], $carOrg[(int) $r['car_id']])) {
                $byDesk[(int) $r['salesdesk_id']][] = (int) $r['id'];
                $parkedBy[(int) $r['user_id']][] = [$r['title'], (string) $r['org_name']];
            }
        }
        foreach ($byDesk as $deskId => $ids) {
            $out['parked'] += sdParkDeskRows($deskId, $ids, 'network');
        }
    }
    foreach ($parkedBy as $uid => $cars) {
        $org = $cars[0][1] ?: 'A business';
        $n   = count($cars);
        sdGraceNotifyUser($uid,
            $n === 1 ? "{$cars[0][0]} is now network-only" : "{$n} cars on your desk are now network-only",
            "{$org} made " . ($n === 1 ? 'this car' : 'these cars') . ' available to its network brokers only, so '
            . ($n === 1 ? 'it’s' : 'they’re') . ' paused on your desk — nothing was deleted. Join its network to bring '
            . ($n === 1 ? 'it' : 'them') . ' back.',
            '/app/broker/networks');
    }

    // 3. Restore 'network' parked rows that are allowed again.
    $parked = $pdo->query("SELECT p.id, p.salesdesk_id, p.car_id, sd.user_id
                           FROM broker_inventory_parked p JOIN salesdesks sd ON sd.id = p.salesdesk_id
                           WHERE p.reason = 'network' AND p.car_id IN ({$in})
                           ORDER BY p.added_at DESC")->fetchAll();
    $byUser = [];
    foreach ($parked as $p) {
        $car = (int) $p['car_id'];
        if (!$excl[$car] || $isMember((int) $p['user_id'], $carOrg[$car])) {
            $byUser[(int) $p['user_id']][] = $p;
        }
    }
    foreach ($byUser as $uid => $list) {
        $out['restored'] += sdVipRestoreForBroker($uid, $list);
    }
    return $out;
}

/** Park / restore one broker's desk after a membership change. */
function sdVipSyncBroker(int $userId): array
{
    $out = ['parked' => 0, 'restored' => 0];
    if (!sdVipReady() || !($deskId = sdGraceDeskId($userId))) {
        return $out;
    }
    $pdo  = Database::getInstance();
    $orgs = sdBrokerVerifiedOrgIdsFresh($userId);

    $s = $pdo->prepare("SELECT bi.id, COALESCE(d.organization_id, 0) AS org_id FROM broker_inventory bi
                        JOIN cars c ON c.id = bi.car_id JOIN dealers d ON d.id = c.dealer_id
                        WHERE bi.salesdesk_id = ? AND c.network_exclusive = 1");
    $s->execute([$deskId]);
    $park = [];
    foreach ($s->fetchAll() as $r) {
        if (!in_array((int) $r['org_id'], $orgs, true)) {
            $park[] = (int) $r['id'];
        }
    }
    if ($park) {
        $out['parked'] = sdParkDeskRows($deskId, $park, 'network');
    }

    $p = $pdo->prepare("SELECT p.id, p.salesdesk_id, p.car_id, c.network_exclusive, COALESCE(d.organization_id, 0) AS org_id
                        FROM broker_inventory_parked p JOIN cars c ON c.id = p.car_id JOIN dealers d ON d.id = c.dealer_id
                        WHERE p.salesdesk_id = ? AND p.reason = 'network' ORDER BY p.added_at DESC");
    $p->execute([$deskId]);
    $back = array_values(array_filter($p->fetchAll(), static fn ($r) => !(int) $r['network_exclusive'] || in_array((int) $r['org_id'], $orgs, true)));
    if ($back) {
        $out['restored'] = sdVipRestoreForBroker($userId, $back);
    }
    return $out;
}

/**
 * Restore 'network' parked rows for one broker while the desk has room.
 * Rows that don't fit stay parked; they become plan-limit pauses the broker
 * brings back by hand (or that return on upgrade).
 */
function sdVipRestoreForBroker(int $userId, array $rows): int
{
    $deskId = sdGraceDeskId($userId);
    if (!$deskId) {
        return 0;
    }
    $state = sdBrokerDeskState($userId);
    $room  = $state['room'];   // null = unlimited
    $pdo   = Database::getInstance();
    $n     = 0;
    foreach ($rows as $r) {
        if ($room !== null && $n >= $room) {
            $pdo->prepare("UPDATE broker_inventory_parked SET reason = 'plan_limit' WHERE id = ? AND reason = 'network'")
                ->execute([(int) $r['id']]);
            continue;
        }
        if (sdRestoreParkedRow($deskId, (int) $r['id'])) {
            $n++;
        }
    }
    return $n;
}

/** Daily (cron/billing-daily.php): keep every business's flags in step. */
function sdVipDaily(): array
{
    if (!sdVipReady()) {
        return ['VIP: not ready (run 0024).'];
    }
    $orgs = Database::getInstance()->query("
        SELECT DISTINCT d.organization_id FROM cars c JOIN dealers d ON d.id = c.dealer_id
        WHERE d.organization_id IS NOT NULL
          AND (c.network_only = 1 OR c.network_exclusive = 1
               OR EXISTS (SELECT 1 FROM broker_inventory_parked p WHERE p.car_id = c.id AND p.reason = 'network'))
    ")->fetchAll(PDO::FETCH_COLUMN);
    $p = $r = 0;
    foreach ($orgs as $o) {
        $res = sdVipSyncOrg((int) $o);
        $p  += $res['parked'];
        $r  += $res['restored'];
    }
    return [($p || $r) ? "VIP: {$p} desk car(s) paused, {$r} brought back." : 'VIP: nothing to do.'];
}


// ============================================================
// SUMMARIES (dealer Perks tab, broker My networks, admin)
// ============================================================

/**
 * @return array{vip:bool, bonus:?array, network_only:int, in_force:int, raw_bonus:?array}
 */
function sdNetworkPerkSummary(int $orgId): array
{
    $out = ['vip' => sdOrgVipActive($orgId), 'bonus' => null, 'raw_bonus' => null, 'network_only' => 0, 'in_force' => 0];
    if (!sdVipReady() || $orgId <= 0) {
        return $out;
    }
    $pdo = Database::getInstance();
    $s   = $pdo->prepare("SELECT SUM(c.network_only = 1), SUM(c.network_exclusive = 1)
                          FROM cars c JOIN dealers d ON d.id = c.dealer_id
                          WHERE d.organization_id = ? AND c.status = 'active'");
    $s->execute([$orgId]);
    [$no, $nf] = $s->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $out['network_only'] = (int) $no;
    $out['in_force']     = (int) $nf;
    $b = $pdo->prepare("SELECT network_bonus_type, network_bonus_value FROM organizations WHERE id = ?");
    $b->execute([$orgId]);
    $b = $b->fetch();
    if ($b && $b['network_bonus_type']) {
        $out['raw_bonus'] = ['type' => (string) $b['network_bonus_type'], 'value' => (float) $b['network_bonus_value']];
    }
    $out['bonus'] = $out['vip'] ? $out['raw_bonus'] : null;
    return $out;
}

/** A dealership's cars for the Perks tab (active + paused, newest first). */
function sdDealerCarsForPerks(int $dealerId): array
{
    if (!sdVipReady()) {
        return [];
    }
    $s = Database::getInstance()->prepare("
        SELECT c.id, c.make, c.model, c.year, c.price, c.status, c.image_urls, c.network_only, c.network_exclusive,
               c.commission_type, c.commission_value,
               (SELECT COUNT(*) FROM broker_inventory bi WHERE bi.car_id = c.id) AS brokers
        FROM cars c WHERE c.dealer_id = ? AND c.status IN ('active', 'paused')
        ORDER BY c.network_only DESC, c.created_at DESC
    ");
    $s->execute([$dealerId]);
    return $s->fetchAll();
}
