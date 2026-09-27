-- ============================================================
-- SalesDesk — 0012: Admin-managed dealerships & desk organisations
--
-- SAFE TO RE-RUN. Every change checks information_schema first, so this
-- works on a database where part of it was already applied, or where a
-- column already exists. Runs on MySQL 5.7/8.x and MariaDB 10.3+, from the
-- mysql CLI or phpMyAdmin, with no special privileges (no stored
-- procedures). Each guarded step is:  SET @sd_sql = IF(missing, ddl, 'DO 0')
-- then PREPARE/EXECUTE.
--
-- 1. dealers.user_id becomes NULLable. user_id IS NULL = "admin-managed"
--    dealership: live immediately; exec join requests, lead emails and
--    nudges go to its managing admins. A principal can be linked later.
-- 2. dealer_managers        — which admins manage which dealership.
-- 3. organization_managers  — which admins manage which desk organisation.
-- 4. created_by_admin_id on dealers + organizations (audit trail).
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0012_admin_managed_dealers_orgs.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1. Principal-less dealerships ───────────────────────────
-- MODIFY is naturally idempotent.
ALTER TABLE dealers
    MODIFY user_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK users (dealer principal). NULL = admin-managed, no principal yet';

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND COLUMN_NAME = 'created_by_admin_id') = 0, 'ALTER TABLE `dealers` ADD COLUMN `created_by_admin_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Admin who created this dealership; NULL = self-registered'' AFTER is_active', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND CONSTRAINT_NAME = 'fk_dealer_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0, 'ALTER TABLE `dealers` ADD CONSTRAINT `fk_dealer_created_by` FOREIGN KEY (created_by_admin_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 2. Dealer managers ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS dealer_managers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dealer_id     INT UNSIGNED NOT NULL,
    admin_user_id INT UNSIGNED NOT NULL,
    added_by      INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dealer_manager (dealer_id, admin_user_id),
    CONSTRAINT fk_dm_dealer FOREIGN KEY (dealer_id)     REFERENCES dealers(id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_admin  FOREIGN KEY (admin_user_id) REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_dm_added  FOREIGN KEY (added_by)      REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_dm_admin (admin_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admins who manage a dealership (create/edit, approve sales execs).';

-- ── 3. Organisation managers ────────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'created_by_admin_id') = 0, 'ALTER TABLE `organizations` ADD COLUMN `created_by_admin_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Admin who created this org; NULL = broker-created'' AFTER is_active', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND CONSTRAINT_NAME = 'fk_org_created_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0, 'ALTER TABLE `organizations` ADD CONSTRAINT `fk_org_created_by` FOREIGN KEY (created_by_admin_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

CREATE TABLE IF NOT EXISTS organization_managers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    admin_user_id   INT UNSIGNED NOT NULL,
    added_by        INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_manager (organization_id, admin_user_id),
    CONSTRAINT fk_om_org   FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_om_admin FOREIGN KEY (admin_user_id)   REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_om_added FOREIGN KEY (added_by)        REFERENCES users(id)         ON DELETE SET NULL,
    INDEX idx_orgmgr_admin (admin_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admins who manage a desk organisation (members, roles, details).';

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0012_admin_managed_dealers_orgs');
SELECT '0012 applied' AS result;
