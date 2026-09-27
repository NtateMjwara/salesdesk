-- ============================================================
-- SalesDesk — 0013: Desk organisations as online dealerships
--
-- Requires 0012. SAFE TO RE-RUN: schema changes check information_schema
-- first, and one-off data steps are recorded in schema_migrations so they
-- never run twice. If an earlier attempt stopped half-way (e.g. "Duplicate
-- column name 'description'"), just run this file again.
-- Works on MySQL 5.7/8.x (incl. managed MySQL with GTID or
-- sql_require_primary_key) and MariaDB 10.3+, from the CLI or phpMyAdmin.
--
-- New model:
--   • A desk organisation is an online dealership run by admins
--     (organization_managers). It sells 1–3 brands.
--   • Brokers join one as agents: they choose it at signup (or later),
--     land as 'pending', and an admin approves or declines — exactly
--     like sales execs joining a dealership.
--   • Approved agents only see / add cars of the org's brands.
--   • Independent brokers (no membership) keep the full marketplace.
--   • The org takes no cut: commission still goes to the agent.
--
-- 1. organizations: brands (JSON array, 1–3), description,
--    agent_car_limit, accepting_applications.
-- 2. organization_members: approval columns mirroring sales_executives,
--    and ONE org per broker (UNIQUE user_id). Existing members become
--    verified agents (first run only). Memberships are backed up to
--    organization_members_pre0013 first; duplicates keep the earliest.
-- 3. Existing orgs with no manager are converted to admin-run orgs:
--    every active admin becomes a manager (remove extras in the org's
--    Managers tab). Brands start empty — set them in the org's Details.
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0013_desk_orgs_online_dealerships.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1. Organisation catalogue rules ─────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'brands') = 0, 'ALTER TABLE `organizations` ADD COLUMN `brands` TEXT NULL DEFAULT NULL COMMENT ''JSON array of 1-3 car makes, e.g. ["Volkswagen"]. NULL = unrestricted (legacy)'' AFTER slug', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'description') = 0, 'ALTER TABLE `organizations` ADD COLUMN `description` TEXT NULL DEFAULT NULL AFTER brands', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'agent_car_limit') = 0, 'ALTER TABLE `organizations` ADD COLUMN `agent_car_limit` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT ''Max desk cars per agent. NULL = platform default'' AFTER description', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'accepting_applications') = 0, 'ALTER TABLE `organizations` ADD COLUMN `accepting_applications` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = hidden from signup / apply lists'' AFTER agent_car_limit', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 1b. Force the exact column definitions ──────────────────
-- If any of these columns already existed with a different definition
-- (e.g. a hand-added `description VARCHAR(255) NOT NULL`), saving an
-- organisation fails ("Column 'description' cannot be null"). MODIFY is
-- idempotent and keeps existing values.
UPDATE organizations SET accepting_applications = 1 WHERE accepting_applications IS NULL;
ALTER TABLE organizations
    MODIFY brands TEXT NULL DEFAULT NULL
        COMMENT 'JSON array of 1-3 car makes, e.g. ["Volkswagen"]. NULL = unrestricted (legacy)',
    MODIFY description TEXT NULL DEFAULT NULL,
    MODIFY agent_car_limit SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Max desk cars per agent. NULL = platform default',
    MODIFY accepting_applications TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = hidden from signup / apply lists';

-- Was the approval column already there (an earlier 0013 got that far)?
-- If so, the one-off data steps below already ran and must not repeat —
-- otherwise brokers who applied since would be auto-approved.
SET @sd_had_status = (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'status');

-- ── 2a. Back up memberships (first run only) ────────────────
CREATE TABLE IF NOT EXISTS organization_members_pre0013 LIKE organization_members;
INSERT IGNORE INTO organization_members_pre0013 (id, organization_id, user_id, role, invited_by, joined_at)
SELECT id, organization_id, user_id, role, invited_by, joined_at
FROM organization_members
WHERE @sd_had_status = 0
  AND (SELECT COUNT(*) FROM schema_migrations WHERE name = '0013_backup') = 0;
INSERT IGNORE INTO schema_migrations (name) VALUES ('0013_backup');

-- ── 2b. Agent approval columns ──────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'status') = 0, 'ALTER TABLE `organization_members` ADD COLUMN `status` ENUM(''pending'',''verified'',''rejected'',''suspended'') NOT NULL DEFAULT ''pending'' COMMENT ''Agent approval lifecycle, mirrors sales_executives'' AFTER role', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'verified_by') = 0, 'ALTER TABLE `organization_members` ADD COLUMN `verified_by` INT UNSIGNED NULL DEFAULT NULL COMMENT ''FK users (admin) who approved / declined'' AFTER status', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'verified_at') = 0, 'ALTER TABLE `organization_members` ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL AFTER verified_by', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'rejection_reason') = 0, 'ALTER TABLE `organization_members` ADD COLUMN `rejection_reason` VARCHAR(255) NULL DEFAULT NULL AFTER verified_at', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND COLUMN_NAME = 'updated_at') = 0, 'ALTER TABLE `organization_members` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER joined_at', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND CONSTRAINT_NAME = 'fk_orgmem_verified_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0, 'ALTER TABLE `organization_members` ADD CONSTRAINT `fk_orgmem_verified_by` FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND INDEX_NAME = 'idx_orgmem_status') = 0, 'ALTER TABLE `organization_members` ADD INDEX idx_orgmem_status (status)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- Force exact definitions (see 1b).
UPDATE organization_members SET updated_at = COALESCE(updated_at, joined_at, NOW()) WHERE updated_at IS NULL;
ALTER TABLE organization_members
    MODIFY status ENUM('pending','verified','rejected','suspended') NOT NULL DEFAULT 'pending'
        COMMENT 'Agent approval lifecycle, mirrors sales_executives',
    MODIFY verified_by INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK users (admin) who approved / declined',
    MODIFY verified_at DATETIME NULL DEFAULT NULL,
    MODIFY rejection_reason VARCHAR(255) NULL DEFAULT NULL,
    MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Everyone who was already in an org becomes an approved agent (first run
-- only — never touches applications made after this migration).
UPDATE organization_members
SET status = 'verified', verified_at = COALESCE(verified_at, joined_at), role = 'agent'
WHERE @sd_had_status = 0
  AND (SELECT COUNT(*) FROM schema_migrations WHERE name = '0013_members_verified') = 0;
INSERT IGNORE INTO schema_migrations (name) VALUES ('0013_members_verified');

-- ── 2c. One org per broker: keep the earliest membership ────
DELETE om FROM organization_members om
JOIN organization_members keep_row
  ON keep_row.user_id = om.user_id
 AND (keep_row.joined_at < om.joined_at OR (keep_row.joined_at = om.joined_at AND keep_row.id < om.id));

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND INDEX_NAME = 'uq_orgmem_one_org') = 0, 'ALTER TABLE `organization_members` ADD UNIQUE KEY uq_orgmem_one_org (user_id)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 3. Convert orgs with no manager to admin-run orgs ───────
INSERT IGNORE INTO organization_managers (organization_id, admin_user_id, added_by, created_at)
SELECT o.id, u.id, NULL, NOW()
FROM organizations o
JOIN users u ON u.role = 'admin' AND u.status = 'active'
WHERE NOT EXISTS (SELECT 1 FROM organization_managers m WHERE m.organization_id = o.id);

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0013_desk_orgs_online_dealerships');
SELECT '0013 applied' AS result;
