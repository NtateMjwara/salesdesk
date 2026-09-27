<?php
/**
 * SalesDesk — In-app migration runner  (0012/0013)
 *
 * WHY: pasting a migration into a web SQL tool (app/admin/database.php,
 * or phpMyAdmin on some hosts) sends the SQL in the request body. Hosting
 * firewalls (ModSecurity / Imunify360) read words like information_schema,
 * PREPARE or SELECT IF(...) as an SQL-injection attack and answer
 * "403 Forbidden" before PHP ever runs. This runner reads the migration
 * files from db/ on the server instead — the browser only sends the
 * migration's name — so there is nothing for the firewall to block.
 *
 * Only the files in SD_MIGRATIONS can be run. Both are idempotent.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

const SD_MIGRATIONS = [
    '0012_admin_managed_dealers_orgs.sql'   => 'Admin-managed dealerships, dealer & organisation managers',
    '0013_desk_orgs_online_dealerships.sql' => 'Desk organisations as online dealerships (brands, agent approvals)',
];

/**
 * Split a .sql file into statements: honours '…', "…", `…` quoting,
 * -- / # line comments and block comments, and drops empty statements.
 */
function sdSplitSql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $len; $i++) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $quote !== '`') {          // escaped char inside a string
                $buf .= $next;
                $i++;
            } elseif ($c === $quote) {
                if ($next === $quote) {                     // doubled quote ('')
                    $buf .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
            $end = strpos($sql, "\n", $i);
            $i   = $end === false ? $len : $end;
            $buf .= "\n";
            continue;
        }
        if ($c === '#') {
            $end = strpos($sql, "\n", $i);
            $i   = $end === false ? $len : $end;
            $buf .= "\n";
            continue;
        }
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = $end === false ? $len : $end + 1;
            $buf .= ' ';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf  .= $c;
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

/**
 * Run one bundled migration.
 *
 * @return array{ok:bool, statements:int, notes:array, error:string, failed_sql:string}
 */
function sdRunMigration(string $file, int $adminId): array
{
    if (!array_key_exists($file, SD_MIGRATIONS)) {
        return ['ok' => false, 'statements' => 0, 'notes' => [], 'error' => 'Unknown migration.', 'failed_sql' => ''];
    }
    $path = dirname(__DIR__) . '/db/' . $file;
    if (!is_readable($path)) {
        return ['ok' => false, 'statements' => 0, 'notes' => [],
                'error' => "db/{$file} is missing on the server — upload it with the other files.", 'failed_sql' => ''];
    }

    $pdo   = Database::getInstance();
    $notes = [];
    $count = 0;
    @set_time_limit(300);

    foreach (sdSplitSql((string) file_get_contents($path)) as $stmt) {
        try {
            if (preg_match('/^(SELECT|SHOW)\b/i', $stmt)) {
                foreach ($pdo->query($stmt)->fetchAll(PDO::FETCH_NUM) as $row) {
                    $notes[] = implode(' ', $row);
                }
            } else {
                $pdo->exec($stmt);
            }
            $count++;
        } catch (Throwable $e) {
            error_log("[SalesDesk migration {$file}] " . $e->getMessage() . ' — ' . mb_substr($stmt, 0, 300));
            writeAuditLog('db.migration_failed', 'database', 0, null,
                ['file' => $file, 'error' => $e->getMessage(), 'statement' => mb_substr($stmt, 0, 500)], $adminId);
            return ['ok' => false, 'statements' => $count, 'notes' => $notes,
                    'error' => $e->getMessage(), 'failed_sql' => mb_substr($stmt, 0, 600)];
        }
    }

    writeAuditLog('db.migration_run', 'database', 0, null, ['file' => $file, 'statements' => $count], $adminId);
    return ['ok' => true, 'statements' => $count, 'notes' => $notes, 'error' => '', 'failed_sql' => ''];
}


// ============================================================
// RESET & REINSTALL 0013  (keeps your data)
//
// Removes everything 0013 added — the organisation catalogue columns and
// the agent-approval columns/indexes — then runs 0012 and 0013 again from
// scratch and puts the saved values back. Use it when a database was left
// in an odd state by earlier attempts (hand-added columns, half-run SQL).
//
// Values are snapshotted into sd_reinstall_snapshot BEFORE anything is
// dropped. If a reinstall is interrupted, the next one reuses that
// snapshot instead of taking a new one from the half-dropped tables.
// ============================================================

function sdColumnExists(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (bool) $s->fetchColumn();
}

/**
 * @return array{ok:bool, steps:array, error:string}
 */
function sdReinstall0013(int $adminId): array
{
    $pdo   = Database::getInstance();
    $steps = [];
    $step  = function (string $label, callable $fn) use (&$steps) {
        $fn();
        $steps[] = $label;
    };

    try {
        // ── 1. Snapshot (or reuse an unfinished one) ─────────────
        $pdo->exec("CREATE TABLE IF NOT EXISTS sd_reinstall_snapshot (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payload     LONGTEXT     NOT NULL,
            finished    TINYINT(1)   NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pending = $pdo->query("SELECT id, payload FROM sd_reinstall_snapshot WHERE finished = 0 ORDER BY id DESC LIMIT 1")->fetch();
        if ($pending) {
            $snapId = (int) $pending['id'];
            $snap   = json_decode($pending['payload'], true);
            $steps[] = 'Re-using the snapshot from an earlier, unfinished reset.';
        } else {
            $orgCols = array_values(array_filter(['brands', 'description', 'agent_car_limit', 'accepting_applications'],
                fn($c) => sdColumnExists($pdo, 'organizations', $c)));
            $memCols = array_values(array_filter(['status', 'verified_by', 'verified_at', 'rejection_reason'],
                fn($c) => sdColumnExists($pdo, 'organization_members', $c)));
            $snap = [
                'orgs'    => $pdo->query('SELECT id' . ($orgCols ? ', ' . implode(', ', $orgCols) : '') . ' FROM organizations')->fetchAll(),
                'members' => $pdo->query('SELECT id' . ($memCols ? ', ' . implode(', ', $memCols) : '') . ' FROM organization_members')->fetchAll(),
            ];
            $pdo->prepare("INSERT INTO sd_reinstall_snapshot (payload) VALUES (?)")
                ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE)]);
            $snapId  = (int) $pdo->lastInsertId();
            $steps[] = 'Saved ' . count($snap['orgs']) . ' organisation(s) and ' . count($snap['members']) . ' membership(s).';
        }

        // ── 2. Remove what 0013 added ────────────────────────────
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // Any foreign key on the approval columns (whatever it's called).
        $fks = $pdo->query("
            SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members'
              AND COLUMN_NAME IN ('status','verified_by','verified_at','rejection_reason','updated_at')
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fks as $fk) {
            $step("Dropped foreign key {$fk}", fn() => $pdo->exec("ALTER TABLE organization_members DROP FOREIGN KEY `{$fk}`"));
        }
        foreach (['uq_orgmem_one_org', 'idx_orgmem_status'] as $idx) {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = 'organization_members' AND INDEX_NAME = '{$idx}'")->fetchColumn();
            if ($has) {
                $step("Dropped index {$idx}", fn() => $pdo->exec("ALTER TABLE organization_members DROP INDEX `{$idx}`"));
            }
        }
        foreach (['status', 'verified_by', 'verified_at', 'rejection_reason', 'updated_at'] as $c) {
            if (sdColumnExists($pdo, 'organization_members', $c)) {
                $step("Dropped organization_members.{$c}", fn() => $pdo->exec("ALTER TABLE organization_members DROP COLUMN `{$c}`"));
            }
        }
        foreach (['brands', 'description', 'agent_car_limit', 'accepting_applications'] as $c) {
            if (sdColumnExists($pdo, 'organizations', $c)) {
                $step("Dropped organizations.{$c}", fn() => $pdo->exec("ALTER TABLE organizations DROP COLUMN `{$c}`"));
            }
        }
        if ($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'")->fetchColumn()) {
            $pdo->exec("DELETE FROM schema_migrations WHERE name LIKE '0013%'");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // ── 3. Run 0012 and 0013 again ───────────────────────────
        foreach (['0012_admin_managed_dealers_orgs.sql', '0013_desk_orgs_online_dealerships.sql'] as $file) {
            $res = sdRunMigration($file, $adminId);
            if (!$res['ok']) {
                return ['ok' => false, 'steps' => $steps,
                        'error' => "db/{$file} failed: {$res['error']}" . ($res['failed_sql'] ? "\n\n" . $res['failed_sql'] : '')
                                 . "\n\nYour data is safe in sd_reinstall_snapshot — fix the error and run the reset again."];
            }
            $steps[] = "Ran db/{$file} ({$res['statements']} statements).";
        }

        // ── 4. Put the values back ───────────────────────────────
        $updOrg = $pdo->prepare("
            UPDATE organizations
            SET brands = ?, description = ?, agent_car_limit = ?, accepting_applications = ?
            WHERE id = ?
        ");
        foreach ($snap['orgs'] as $o) {
            $brands = isset($o['brands']) && is_array(json_decode((string) $o['brands'], true)) ? $o['brands'] : null;
            $limit  = isset($o['agent_car_limit']) && $o['agent_car_limit'] !== '' && $o['agent_car_limit'] !== null
                ? max(1, min(500, (int) $o['agent_car_limit'])) : null;
            $desc   = isset($o['description']) && trim((string) $o['description']) !== '' ? (string) $o['description'] : null;
            $accept = array_key_exists('accepting_applications', $o) && $o['accepting_applications'] !== null
                ? ((int) $o['accepting_applications'] ? 1 : 0) : 1;
            $updOrg->execute([$brands, $desc, $limit, $accept, (int) $o['id']]);
        }
        $steps[] = 'Restored brands, descriptions, car limits and application settings.';

        $updMem = $pdo->prepare("
            UPDATE organization_members
            SET status = ?, verified_by = ?, verified_at = ?, rejection_reason = ?
            WHERE id = ?
        ");
        $restoredMembers = 0;
        foreach ($snap['members'] as $m) {
            if (!array_key_exists('status', $m)) {
                continue; // approval columns didn't exist before — 0013 just made everyone an approved agent
            }
            $status = in_array($m['status'], ['pending', 'verified', 'rejected', 'suspended'], true) ? $m['status'] : 'verified';
            $updMem->execute([
                $status,
                !empty($m['verified_by']) ? (int) $m['verified_by'] : null,
                $m['verified_at'] ?? null,
                $m['rejection_reason'] ?? null,
                (int) $m['id'],
            ]);
            $restoredMembers++;
        }
        $steps[] = $restoredMembers
            ? "Restored approval status for {$restoredMembers} membership(s)."
            : 'Existing members are approved agents.';

        $pdo->prepare("UPDATE sd_reinstall_snapshot SET finished = 1 WHERE id = ?")->execute([$snapId]);
        writeAuditLog('db.reinstall_0013', 'database', 0, null, ['steps' => $steps], $adminId);
        return ['ok' => true, 'steps' => $steps, 'error' => ''];

    } catch (Throwable $e) {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable) {}
        error_log('[SalesDesk reinstall 0013] ' . $e->getMessage());
        return ['ok' => false, 'steps' => $steps,
                'error' => $e->getMessage() . "\n\nYour data is safe in sd_reinstall_snapshot — run the reset again once the error is fixed."];
    }
}
