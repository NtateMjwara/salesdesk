-- ============================================================
-- SalesDesk — 0023: Downgrade grace (plans Phase 3b)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
-- Needs 0019 (plans) and 0020 (business orgs). Code: includes/plan_grace.php.
--
-- Nothing is ever deleted when an account ends up over a plan limit
-- (e.g. Pro ends with 170 cars on a desk; Free allows 150):
--
--   1. plan_grace — a 7-day window per account + limit. The broker can
--      choose which cars stay live (keep_ids). When the window ends the
--      extras are PARKED. Getting back under the limit closes the
--      window; upgrading (or a higher limit) also brings parked cars
--      back as room allows.
--   2. broker_inventory_parked — a paused desk car. The row moves here
--      with its id, tracking code, views and added date, so every page
--      that reads broker_inventory ignores it automatically (public
--      desk, search, sitemaps, lead attribution) and restoring puts it
--      back exactly as it was.
--   3. dealers.listings_paused_at — an Enterprise business whose paid
--      plan ended with more dealerships than its plan allows: after the
--      window the extra dealerships can't add new cars (existing cars
--      stay live) until dealerships are moved out or the plan is renewed.
-- ============================================================

-- ── 1. Grace windows ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `plan_grace` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_type`  ENUM('broker','organization') NOT NULL,
  `account_id`    INT UNSIGNED NOT NULL,
  `limit_key`     VARCHAR(40)  NOT NULL COMMENT 'max_desk_cars | max_dealerships',
  `limit_value`   INT UNSIGNED NULL COMMENT 'The limit when the window opened',
  `used_at_start` INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at`       DATETIME     NOT NULL,
  `keep_ids`      TEXT         NULL COMMENT 'JSON list the account chose to keep live (desk inventory ids)',
  `resolved_at`   DATETIME     NULL,
  `resolution`    VARCHAR(30)  NULL COMMENT 'under_limit | applied | enforced',
  PRIMARY KEY (`id`),
  KEY `idx_grace_account` (`account_type`, `account_id`, `limit_key`, `resolved_at`),
  KEY `idx_grace_open` (`resolved_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plans Phase 3b: 7-day windows when an account is over a plan limit';

-- ── 2. Parked (paused) desk cars ────────────────────────────────
CREATE TABLE IF NOT EXISTS `broker_inventory_parked` (
  `id`            INT UNSIGNED NOT NULL COMMENT 'The broker_inventory id it had (restored with it when free)',
  `salesdesk_id`  INT UNSIGNED NOT NULL,
  `car_id`        INT UNSIGNED NOT NULL,
  `tracking_code` VARCHAR(32)  NOT NULL,
  `views`         INT UNSIGNED NOT NULL DEFAULT 0,
  `added_at`      DATETIME     NOT NULL,
  `parked_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason`        VARCHAR(30)  NOT NULL DEFAULT 'plan_limit',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parked_desk_car` (`salesdesk_id`, `car_id`),
  UNIQUE KEY `uq_parked_tracking` (`tracking_code`),
  KEY `idx_parked_car` (`car_id`),
  CONSTRAINT `fk_parked_car`  FOREIGN KEY (`car_id`)       REFERENCES `cars` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_parked_desk` FOREIGN KEY (`salesdesk_id`) REFERENCES `salesdesks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plans Phase 3b: desk cars paused because the desk is over its plan limit';

-- ── 3. Dealership new-listing pause ─────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND COLUMN_NAME = 'listings_paused_at') = 0,
    'ALTER TABLE `dealers` ADD COLUMN `listings_paused_at` DATETIME NULL COMMENT ''Plans 3b: over the business plan dealership limit — no new cars until resolved'' AFTER `organization_id`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0023_plan_grace');
SELECT '0023 applied — downgrade grace ready (runs from cron/billing-daily.php)' AS result;
