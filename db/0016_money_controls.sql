-- ============================================================
-- SalesDesk — 0016: Money controls (dealer workspace, Phase 2)
--
-- Requires 0015. SAFE TO RE-RUN: schema changes check
-- information_schema first; the backfill only touches NULL rows.
--
-- commissions.closed_by_user_id — who closed the deal that created the
--   commission (the person who clicked "closed" on the lead).
-- commissions.closed_via — 'principal' (the dealer's own principal) or
--   'staff' (a SalesDesk admin operating the dealership in a workspace).
--
-- Four-eyes rule (enforced in code): the person who closed a deal can
-- never approve, schedule, mark paid or retry its commission — not even
-- a superadmin. Recording a bounced EFT (→ failed) is still allowed.
--
-- Backfill: existing commissions take their closer from the audit log
-- ('commission.created' actor); admins → 'staff', everyone else →
-- 'principal'. Rows with no audit entry stay NULL ("unknown").
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0016_money_controls.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'closed_by_user_id') = 0, 'ALTER TABLE `commissions` ADD COLUMN `closed_by_user_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Who closed the deal (four-eyes: may not approve/pay it)'' AFTER dealer_id', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'closed_via') = 0, 'ALTER TABLE `commissions` ADD COLUMN `closed_via` ENUM(''principal'',''staff'') NULL DEFAULT NULL COMMENT ''staff = closed by a SalesDesk admin in a dealer workspace'' AFTER closed_by_user_id', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND INDEX_NAME = 'idx_comm_closed_by') = 0, 'ALTER TABLE `commissions` ADD INDEX idx_comm_closed_by (closed_by_user_id)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND CONSTRAINT_NAME = 'fk_comm_closed_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0, 'ALTER TABLE `commissions` ADD CONSTRAINT `fk_comm_closed_by` FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── Backfill from the audit log ─────────────────────────────
UPDATE commissions c
JOIN (
    SELECT al.entity_id, MIN(al.id) AS first_id
    FROM audit_logs al
    WHERE al.action = 'commission.created' AND al.entity_type = 'commission' AND al.actor_id IS NOT NULL
    GROUP BY al.entity_id
) f ON f.entity_id = c.id
JOIN audit_logs al ON al.id = f.first_id
JOIN users u ON u.id = al.actor_id
SET c.closed_by_user_id = al.actor_id,
    c.closed_via        = IF(u.role = 'admin', 'staff', 'principal')
WHERE c.closed_by_user_id IS NULL;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0016_money_controls');
SELECT CONCAT('0016 applied — ', COUNT(*), ' commission(s), ',
              SUM(closed_by_user_id IS NOT NULL), ' with a known closer') AS result
FROM commissions;
