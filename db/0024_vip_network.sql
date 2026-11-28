-- ============================================================
-- SalesDesk — 0024: VIP network perks, part 1 (plans Phase 4a)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
-- Needs 0019 (plans), 0020 (business orgs) and 0023 (parked desk cars).
-- Code: includes/vip_network.php.
--
-- Two perks for Pro and Enterprise businesses (plan limit vip_perks):
--
--   1. NETWORK-ONLY STOCK — the business marks cars that only its
--      verified network brokers can list and share.
--        cars.network_only       the dealer's choice (kept on downgrade)
--        cars.network_exclusive  in force = network_only AND the business
--                                has VIP perks. Every public and broker
--                                query filters on this one column.
--      A network-only car is never a platform listing: buyers reach it
--      through a network broker's desk. Desk rows of brokers outside the
--      network are parked (broker_inventory_parked, reason 'network'),
--      never deleted, and come back when the car opens up again or the
--      broker joins the network.
--
--   2. NETWORK BONUS — an extra amount (R) or % of the price, on top of
--      the car's commission, for leads brought by network brokers on
--      any of the business's cars.
--        organizations.network_bonus_type / _value   the setting
--        leads.network_bonus_type / _value           locked in when the
--                                                    lead comes in
--        commissions.network_bonus                   the amount (part of
--                                                    gross_amount)
-- ============================================================

-- ── 1. Network-only stock ───────────────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'network_only') = 0,
    'ALTER TABLE `cars` ADD COLUMN `network_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Plans 4a: the dealer marked it network-only'' AFTER `commission_value`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND COLUMN_NAME = 'network_exclusive') = 0,
    'ALTER TABLE `cars` ADD COLUMN `network_exclusive` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Plans 4a: in force (network_only + the business has VIP perks)'' AFTER `network_only`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cars' AND INDEX_NAME = 'idx_cars_network') = 0,
    'ALTER TABLE `cars` ADD KEY `idx_cars_network` (`network_exclusive`, `status`)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 2. Network bonus: the business's setting ────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'network_bonus_type') = 0,
    'ALTER TABLE `organizations` ADD COLUMN `network_bonus_type` ENUM(''fixed'',''percentage'') NULL COMMENT ''Plans 4a: NULL = no bonus'' AFTER `accepting_applications`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'network_bonus_value') = 0,
    'ALTER TABLE `organizations` ADD COLUMN `network_bonus_value` DECIMAL(10,2) NULL COMMENT ''Plans 4a: rand amount, or % of the car price'' AFTER `network_bonus_type`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 3. Network bonus: locked in on the lead ─────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'network_bonus_type') = 0,
    'ALTER TABLE `leads` ADD COLUMN `network_bonus_type` ENUM(''fixed'',''percentage'') NULL COMMENT ''Plans 4a: bonus terms when the lead came in'' AFTER `organization_id`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'network_bonus_value') = 0,
    'ALTER TABLE `leads` ADD COLUMN `network_bonus_value` DECIMAL(10,2) NULL AFTER `network_bonus_type`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 4. Network bonus: on the commission ─────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commissions' AND COLUMN_NAME = 'network_bonus') = 0,
    'ALTER TABLE `commissions` ADD COLUMN `network_bonus` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT ''Plans 4a: part of gross_amount'' AFTER `gross_amount`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0024_vip_network');
SELECT '0024 applied — network-only stock and network bonus ready' AS result;
