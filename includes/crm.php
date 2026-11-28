<?php
/**
 * SalesDesk — CRM in the portal (migration 0027). Spec: docs/crm/spec.md.
 * Never writes attribution, network bonus or commission data.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

const SD_LEAD_STATUSES = ['new', 'contacted', 'test_drive', 'negotiation', 'closed', 'lost'];

function sdCrmReady(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) Database::getInstance()->query("
                SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME IN ('lead_activities', 'lead_tasks', 'lead_alerts')) = 3
                   AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'last_activity_at') = 1
            ")->fetchColumn();
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * 0027 post-step: give every existing lead a timeline and an owner.
 * Safe to re-run: imported rows carry a unique source_ref, and leads that
 * already have a value are left alone.
 *
 * @return string[] notes for the migration log
 */
function sdCrmBackfill(int $adminId): array
{
    $pdo = Database::getInstance();
    @set_time_limit(600);

    // 1. Stage history (and the notes typed with each change) from the audit log.
    $insert = $pdo->prepare("
        INSERT IGNORE INTO lead_activities
            (lead_id, dealer_id, type, body, meta, actor_user_id, actor_mode, source_ref, created_at)
        VALUES (?, ?, 'status_change', ?, ?, ?, ?, ?, ?)
    ");
    $batch = $pdo->prepare("
        SELECT a.id, a.entity_id, a.actor_id, a.before_data, a.after_data, a.created_at, l.dealer_id
        FROM audit_logs a
        JOIN leads l ON l.id = a.entity_id
        WHERE a.action = 'lead.status_changed' AND a.entity_type = 'lead' AND a.id > ?
        ORDER BY a.id
        LIMIT 500
    ");
    $history = 0;
    $lastId  = 0;
    do {
        $batch->execute([$lastId]);
        $rows = $batch->fetchAll();
        foreach ($rows as $r) {
            $lastId = (int) $r['id'];
            $before = json_decode((string) $r['before_data'], true) ?: [];
            $after  = json_decode((string) $r['after_data'], true) ?: [];
            $from   = in_array($before['status'] ?? null, SD_LEAD_STATUSES, true) ? $before['status'] : null;
            $to     = in_array($after['status'] ?? null, SD_LEAD_STATUSES, true) ? $after['status'] : null;
            if ($to === null) {
                continue;
            }
            $note = trim((string) ($after['notes'] ?? ''));
            $insert->execute([
                (int) $r['entity_id'],
                (int) $r['dealer_id'],
                $note !== '' ? $note : null,
                json_encode(['from' => $from, 'to' => $to]),
                $r['actor_id'] !== null ? (int) $r['actor_id'] : null,
                $after['_workspace']['mode'] ?? null,
                'audit:' . $r['id'],
                $r['created_at'],
            ]);
            $history += $insert->rowCount();
        }
    } while (count($rows) === 500);

    // 2. The old notes box, unless that exact text already came in with the history.
    $leadsWithNotes = $pdo->query("
        SELECT id, dealer_id, dealer_notes, status_updated_at
        FROM leads
        WHERE dealer_notes IS NOT NULL AND TRIM(dealer_notes) <> ''
    ")->fetchAll();
    $alreadyThere = $pdo->prepare("SELECT 1 FROM lead_activities WHERE lead_id = ? AND body = ? LIMIT 1");
    $carry = $pdo->prepare("
        INSERT IGNORE INTO lead_activities (lead_id, dealer_id, type, outcome, body, source_ref, created_at)
        VALUES (?, ?, 'system', 'notes_carried_over', ?, ?, ?)
    ");
    $carried = 0;
    foreach ($leadsWithNotes as $l) {
        $text = trim((string) $l['dealer_notes']);
        $alreadyThere->execute([(int) $l['id'], $text]);
        if ($alreadyThere->fetchColumn()) {
            continue;
        }
        $carry->execute([(int) $l['id'], (int) $l['dealer_id'], $text, 'notes:' . $l['id'], $l['status_updated_at']]);
        $carried += $carry->rowCount();
    }

    // 3. Default owner: the verified, active exec who uploaded the car. Leads that
    //    were ever assigned (or unassigned) by hand are left alone.
    $candidates = $pdo->query("
        SELECT l.id, l.dealer_id, l.created_at, se.user_id
        FROM leads l
        JOIN cars c              ON c.id = l.car_id
        JOIN sales_executives se ON se.id = c.uploaded_by_exec_id
                                AND se.dealer_id = l.dealer_id
                                AND se.verification_status = 'verified'
        JOIN users u             ON u.id = se.user_id AND u.status = 'active'
        WHERE l.assigned_user_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM lead_activities a WHERE a.lead_id = l.id AND a.type = 'assignment')
    ")->fetchAll();
    $assignLog = $pdo->prepare("
        INSERT IGNORE INTO lead_activities (lead_id, dealer_id, type, outcome, meta, source_ref, created_at)
        VALUES (?, ?, 'assignment', 'auto', ?, ?, ?)
    ");
    $assign = $pdo->prepare("
        UPDATE leads
        SET assigned_user_id = ?, assigned_at = created_at, assigned_by_user_id = NULL, updated_at = updated_at
        WHERE id = ? AND assigned_user_id IS NULL
    ");
    $assigned = 0;
    foreach ($candidates as $c) {
        $pdo->beginTransaction();
        try {
            $assign->execute([(int) $c['user_id'], (int) $c['id']]);
            if ($assign->rowCount()) {
                $assignLog->execute([
                    (int) $c['id'], (int) $c['dealer_id'],
                    json_encode(['to_user_id' => (int) $c['user_id']]),
                    'assign:' . $c['id'], $c['created_at'],
                ]);
                $assigned++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // 4. Speed-to-lead and staleness clocks. updated_at = updated_at keeps the
    //    lead's own "last updated" date untouched.
    $firstContact = $pdo->exec("
        UPDATE leads l
        SET l.first_contact_at = COALESCE(
                (SELECT MIN(a.created_at) FROM lead_activities a WHERE a.lead_id = l.id AND a.type = 'status_change'),
                l.status_updated_at),
            l.updated_at = l.updated_at
        WHERE l.first_contact_at IS NULL AND l.status <> 'new'
    ");
    $lastActivity = $pdo->exec("
        UPDATE leads l
        SET l.last_activity_at = GREATEST(
                COALESCE((SELECT MAX(a.created_at) FROM lead_activities a WHERE a.lead_id = l.id), l.created_at),
                l.status_updated_at,
                l.created_at),
            l.updated_at = l.updated_at
        WHERE l.last_activity_at IS NULL
    ");

    return [
        "Timeline: {$history} stage change(s) imported from the audit log.",
        "Old notes box: {$carried} note(s) carried over (the rest were already in the history).",
        "Assigned automatically to the exec who uploaded the car: {$assigned} lead(s).",
        "Clocks set: first contact on {$firstContact} lead(s), last activity on {$lastActivity} lead(s).",
    ];
}
