-- ============================================================
-- SalesDesk — 0017: Principal-owned dealerships (dealer workspace Phase 3)
--
-- Requires 0015 + 0016. SAFE TO RE-RUN.
--
-- 1. dealer_delegations — a principal lets SalesDesk run their dealership
--    for a fixed period (7 or 30 days). While active, the dealership's
--    managing admins (and superadmins) may make changes — except company,
--    verification and address settings, which stay principal-only.
--    Revocable at any time; expiry needs no job (checked on every request).
--
-- 2. commissions.dealer_confirmation — deals closed by SalesDesk staff on
--    a dealership that has its own principal need the principal's
--    confirmation before an invoice is issued or the commission can move
--    towards payout:
--      not_required  closed by the principal, or on a SalesDesk-run dealership
--      pending       waiting for the principal
--      confirmed     principal confirmed → invoice sent, payout may proceed
--    (A disputed deal is reopened and its commission removed — see code.)
--
-- 3. cars.hold_* — a superadmin enforcement hold: a listing pulled for a
--    platform reason (reason required, principal notified). The dealer
--    can't resume it and imports can't reactivate it until it's released.
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0017_principal_dealerships.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1. Delegations ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dealer_delegations (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dealer_id          INT UNSIGNED NOT NULL,
    granted_by_user_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'The principal who granted it',
    starts_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at         DATETIME     NOT NULL,
    revoked_at         DATETIME     NULL DEFAULT NULL,
    revoked_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    note               VARCHAR(255) NULL DEFAULT NULL COMMENT 'What the principal asked SalesDesk to do',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_deleg_dealer  FOREIGN KEY (dealer_id)          REFERENCES dealers(id) ON DELETE CASCADE,
    CONSTRAINT fk_deleg_granted FOREIGN KEY (granted_by_user_id) REFERENCES users(id)   ON DELETE SET NULL,
    CONSTRAINT fk_deleg_revoked FOREIGN KEY (revoked_by_user_id) REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_deleg_active (dealer_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Principal-granted, time-boxed SalesDesk access to a dealership';

-- ── 2. Dealer confirmation of staff-closed deals ───────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'dealer_confirmation') = 0, 'ALTER TABLE `commissions` ADD COLUMN `dealer_confirmation` ENUM(''not_required'',''pending'',''confirmed'') NOT NULL DEFAULT ''not_required'' COMMENT ''Principal confirmation of a staff-closed deal'' AFTER closed_via', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'dealer_confirmed_at') = 0, 'ALTER TABLE `commissions` ADD COLUMN `dealer_confirmed_at` DATETIME NULL DEFAULT NULL AFTER dealer_confirmation', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 3. Enforcement holds on listings ────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'hold_reason') = 0, 'ALTER TABLE `cars` ADD COLUMN `hold_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT ''Superadmin enforcement hold — dealer cannot resume while set''', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'hold_by_user_id') = 0, 'ALTER TABLE `cars` ADD COLUMN `hold_by_user_id` INT UNSIGNED NULL DEFAULT NULL AFTER hold_reason', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'hold_at') = 0, 'ALTER TABLE `cars` ADD COLUMN `hold_at` DATETIME NULL DEFAULT NULL AFTER hold_by_user_id', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0017_principal_dealerships');
SELECT '0017 applied — delegations, dealer confirmation of staff-closed deals, listing holds' AS result;
