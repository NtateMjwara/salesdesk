-- ============================================================
-- SalesDesk — 0020: Business organisations (plans Phase 2a)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
--
-- The organisation IS the business account: every dealership belongs to
-- one org (its broker network + plan). Brokers never own orgs, and
-- SalesDesk has no "platform" orgs.
--
--   1. dealers.organization_id (+ index, FK ON DELETE SET NULL)
--   2. organizations.owner_user_id becomes nullable (admin-managed
--      businesses have no principal), FK → ON DELETE SET NULL
--   3. Old desk orgs (any org no dealership points at) are DELETED —
--      decided 26 Nov 2026 ("start over"):
--        • their bank accounts are first demoted (is_primary = 0), so a
--          broker is never left with two primary "personal" accounts
--          once organization_id is nulled; rows are kept
--        • leads / commissions / payouts / bank_accounts keep their rows
--          (organization_id → NULL, FK ON DELETE SET NULL)
--        • organization_members / organization_managers rows go (CASCADE);
--          those brokers become independent
--   4. organization_members: drop UNIQUE (user_id) — brokers may join
--      several business networks (limit: plan max_networks)
--
-- After the SQL, the runner calls sdBackfillBusinessOrgs()
-- (includes/business.php): one business org per dealership, named after
-- it, not accepting applications until plans Phase 2c.
--
-- Columns kept but no longer used (drop in a later cleanup):
--   organizations.brands, organizations.agent_car_limit,
--   organization_managers (business orgs are managed via dealer_managers).
--
-- 0013 is guarded so re-running it never undoes this migration.
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1. dealers.organization_id ─────────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND COLUMN_NAME = 'organization_id') = 0, 'ALTER TABLE `dealers` ADD COLUMN `organization_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Business account this dealership belongs to (0020)'' AFTER user_id', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND INDEX_NAME = 'idx_dealer_org') = 0, 'ALTER TABLE `dealers` ADD INDEX idx_dealer_org (organization_id)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND CONSTRAINT_NAME = 'fk_dealer_org') = 0, 'ALTER TABLE `dealers` ADD CONSTRAINT fk_dealer_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 2. organizations.owner_user_id nullable, FK → SET NULL ─────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND CONSTRAINT_NAME = 'fk_org_owner' AND DELETE_RULE <> 'SET NULL') = 1, 'ALTER TABLE `organizations` DROP FOREIGN KEY fk_org_owner', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

ALTER TABLE organizations
    MODIFY owner_user_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Principal of the business (= the dealership''s dealers.user_id); NULL = admin-managed (0020)';

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND CONSTRAINT_NAME = 'fk_org_owner') = 0, 'ALTER TABLE `organizations` ADD CONSTRAINT fk_org_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 3. Remove the old desk orgs ────────────────────────────────
SET @sd_old_orgs = (SELECT COUNT(*) FROM organizations o
                    WHERE NOT EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = o.id));
SET @sd_old_members = (SELECT COUNT(*) FROM organization_members om
                       WHERE NOT EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = om.organization_id));
SET @sd_old_banks = (SELECT COUNT(*) FROM bank_accounts b
                     WHERE b.organization_id IS NOT NULL AND b.is_primary = 1
                       AND NOT EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = b.organization_id));

UPDATE bank_accounts b
SET b.is_primary = 0
WHERE b.organization_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = b.organization_id);

DELETE o FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM dealers d WHERE d.organization_id = o.id);

-- ── 4. Brokers may join several networks ───────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members' AND INDEX_NAME = 'uq_orgmem_one_org') > 0, 'ALTER TABLE `organization_members` DROP INDEX uq_orgmem_one_org', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0020_business_orgs');
SELECT CONCAT('0020 applied — removed ', @sd_old_orgs, ' old desk org(s) and ', @sd_old_members,
              ' membership(s); ', @sd_old_banks, ' org bank account(s) set to non-primary') AS result;
