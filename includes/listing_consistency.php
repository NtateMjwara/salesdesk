<?php
/**
 * SalesDesk — Listing consistency monitor (admin-facing).
 *
 * WHY THIS EXISTS — the actual bug class this guards against:
 *
 *   cars.slug is only unique PER DEALER — uq_car_dealer_slug(dealer_id, slug)
 *   in db/schema_consolidated.sql. There is no global uniqueness constraint.
 *
 *   cars-for-sale/index.php (the browse grid) links each card to
 *   /cars-for-sale/{desk-slug}/{car-slug}/ (or /cars-for-sale/car/{car-slug}/
 *   for platform-attributed cars).
 *
 *   cars-for-sale/car-detail/index.php then loads the car for that page with:
 *       SELECT ... FROM cars c JOIN dealers d ON d.id = c.dealer_id
 *       WHERE c.slug = ?
 *       LIMIT 1
 *   — no dealer_id in that WHERE clause at all. If two different dealers
 *   each have a car whose slug happens to be the same string, this query
 *   returns whichever row MySQL hands back first, which may well NOT be
 *   the car the card actually linked to. The buyer clicks dealer A's card
 *   and can land on dealer B's vehicle.
 *
 *   Separately (not something this file tries to fix, just documenting so
 *   it isn't mistaken for a bug this check should catch): car-detail's
 *   existence checks never filter on c.status, while the browse card's
 *   WHERE requires c.status = 'active'. A sold/paused car therefore stays
 *   reachable at its own URL after being pulled from the browse grid.
 *   Orthogonal issue, out of scope here.
 *
 * WHAT THIS CHECK DOES:
 *   For every car that currently qualifies for the browse grid (mirrors
 *   cars-for-sale/index.php's real WHERE: c.status='active' AND
 *   d.is_active=1), independently re-resolve that car's own slug the exact
 *   way car-detail/index.php's $carStmt does (WHERE c.slug = ? LIMIT 1,
 *   no dealer_id filter) and compare make, model, year, variant, and
 *   dealer company name between the two.
 *
 *   - If the resolved row's id differs from the card's own id → the slug
 *     is ambiguous across dealers and the detail page for this car can
 *     resolve to a different vehicle. Flagged as a mismatch regardless of
 *     whether the field values happen to look similar.
 *   - If the id matches but any compared field differs → flagged too
 *     (would only happen from a genuine data anomaly, since both queries
 *     read the same row when ids agree).
 *
 *   Any flagged car is marked 'sold' immediately (pulling it from the
 *   browse grid) and an audit_logs row is written with the exact reason,
 *   so an admin can see what happened instead of a car just vanishing.
 *   Note: this only ever touches the "losing" side of a slug collision —
 *   the car whose card and detail page actually disagree. The other
 *   dealer's car (the one the ambiguous slug resolves TO) is left alone
 *   since its own card/detail pair still agrees.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Run the consistency check.
 *
 * @param int|null $dealerId  Pass null (default) to check every active
 *                            dealer's cars platform-wide (admin use).
 *                            Pass a dealer id to scope to just that dealer.
 *
 * @return array{
 *     checked: int,
 *     mismatches: array<int, array>,
 *     auto_sold: array<int, int>
 * }
 */
function checkListingConsistency(PDO $pdo, ?int $dealerId = null): array
{
    $results = [
        'checked'    => 0,
        'mismatches' => [],
        'auto_sold'  => [],
    ];

    // ── "Card view" — mirrors cars-for-sale/index.php's real $wc ───────
    // (c.status='active' AND d.is_active=1 — the only two conditions that
    // are unconditional there; all other filters on that page are
    // user-driven search params and irrelevant to "is this car on the
    // grid at all"). variant is pulled here too even though the real
    // browse card doesn't display it — it's still part of the identity
    // this check is asked to verify for the car behind that card's link.
    $where  = ["c.status = 'active'", "d.is_active = 1"];
    $params = [];
    if ($dealerId !== null) {
        $where[]  = 'c.dealer_id = ?';
        $params[] = $dealerId;
    }
    $wc = 'WHERE ' . implode(' AND ', $where);

    $cardStmt = $pdo->prepare("
        SELECT
            c.id, c.slug, c.dealer_id, c.make, c.model, c.year, c.variant,
            d.company_name AS dealer_name
        FROM cars c
        JOIN dealers d ON d.id = c.dealer_id
        {$wc}
    ");
    $cardStmt->execute($params);
    $cardRows = $cardStmt->fetchAll();

    // ── "Detail view" — mirrors car-detail/index.php's $carStmt exactly:
    // WHERE c.slug = ? LIMIT 1, no dealer_id filter. This is the actual
    // query responsible for the slug-collision bug described above.
    $detailStmt = $pdo->prepare("
        SELECT
            c.id, c.slug, c.dealer_id, c.make, c.model, c.year, c.variant,
            d.company_name AS dealer_name
        FROM cars c
        JOIN dealers d ON d.id = c.dealer_id
        WHERE c.slug = ?
        LIMIT 1
    ");

    foreach ($cardRows as $card) {
        $results['checked']++;

        $detailStmt->execute([$card['slug']]);
        $detail = $detailStmt->fetch();

        if (!$detail) {
            // Shouldn't happen in practice — car-detail's existence check
            // is a superset of the card's WHERE (see docblock) — but guard
            // for it anyway rather than assume.
            $results['mismatches'][] = [
                'car_id' => (int) $card['id'],
                'slug'   => $card['slug'],
                'reason' => 'detail_not_found',
                'diffs'  => null,
                'card'   => $card,
                'detail' => null,
            ];
            markCarSoldForInconsistency($pdo, (int) $card['id'], 'detail page 404s for this slug');
            $results['auto_sold'][] = (int) $card['id'];
            continue;
        }

        $diffs = [];

        // The actual bug this check exists for: slug resolved to a
        // DIFFERENT car — possibly a different dealer's vehicle entirely.
        if ((int) $detail['id'] !== (int) $card['id']) {
            $diffs['car_id']    = ['card' => (int) $card['id'],        'detail' => (int) $detail['id']];
            $diffs['dealer_id'] = ['card' => (int) $card['dealer_id'], 'detail' => (int) $detail['dealer_id']];
        }

        foreach (['make', 'model', 'year', 'variant', 'dealer_name'] as $field) {
            $cardVal   = $card[$field]   ?? null;
            $detailVal = $detail[$field] ?? null;
            if ((string) $cardVal !== (string) $detailVal) {
                $diffs[$field] = ['card' => $cardVal, 'detail' => $detailVal];
            }
        }

        if (!empty($diffs)) {
            $results['mismatches'][] = [
                'car_id' => (int) $card['id'],
                'slug'   => $card['slug'],
                'reason' => 'field_mismatch',
                'diffs'  => $diffs,
                'card'   => $card,
                'detail' => $detail,
            ];
            markCarSoldForInconsistency(
                $pdo,
                (int) $card['id'],
                'card/detail disagree: ' . implode(', ', array_keys($diffs))
            );
            $results['auto_sold'][] = (int) $card['id'];
        }
    }

    return $results;
}

/**
 * Mark a car 'sold' because the card/detail consistency check failed for
 * it, and write an audit trail entry explaining why. Idempotent — a car
 * already 'sold' is left untouched (no duplicate sold_at stamp).
 */
function markCarSoldForInconsistency(PDO $pdo, int $carId, string $reason): void
{
    $beforeStmt = $pdo->prepare("SELECT status FROM cars WHERE id = ?");
    $beforeStmt->execute([$carId]);
    $beforeStatus = $beforeStmt->fetchColumn();

    if ($beforeStatus === false || $beforeStatus === 'sold') {
        return; // already gone, or nothing to update
    }

    $pdo->prepare("
        UPDATE cars
        SET status = 'sold', sold_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ")->execute([$carId]);

    writeAuditLog(
        'car.auto_sold_inconsistency',
        'car',
        $carId,
        ['status' => $beforeStatus],
        ['status' => 'sold', 'reason' => $reason]
    );
}
