-- ============================================================
-- SalesDesk — 0015: Dealer workspace (Phase 1)
--
-- Requires 0012 (dealer_managers) and 0014 (superadmin).
-- SAFE TO RE-RUN: the column check reads information_schema first.
--
-- dealer_managers.access — what a managing admin may do inside the
-- dealer portal for that dealership:
--   'view'    read-only (DEFAULT — every existing assignment starts here,
--             so nothing changes until a superadmin grants Operate)
--   'operate' full principal powers (cars, imports, leads, team, settings)
--             — only honoured on dealerships with NO principal
--             (dealers.user_id IS NULL). Dealerships with their own
--             principal stay view-only for all staff (delegation by the
--             principal comes in Phase 3).
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0015_dealer_workspace.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealer_managers' AND COLUMN_NAME = 'access') = 0, 'ALTER TABLE `dealer_managers` ADD COLUMN `access` ENUM(''view'',''operate'') NOT NULL DEFAULT ''view'' COMMENT ''view = read-only dealer portal; operate = principal powers (no-principal dealerships only)'' AFTER admin_user_id', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0015_dealer_workspace');
SELECT '0015 applied — every dealership assignment is View until a superadmin grants Operate' AS result;
