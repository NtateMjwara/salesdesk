-- ============================================================
-- SalesDesk — 0022: Online billing with PayFast (plans Phase 3)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
-- Needs 0019 (plans, subscriptions).
--
-- Brokers subscribe to Pro by card / debit through PayFast recurring
-- billing (subscription_type 1, monthly). Code: includes/billing.php.
--
--   1. billing_checkouts — one row per "Upgrade" click: who, which
--      plan, the amount we asked PayFast for, and the m_payment_id that
--      ties PayFast's notification (ITN) back to it.
--   2. billing_events    — every ITN PayFast posts, with the result of
--      each security check and what we did with it (audit + idempotency).
--   3. subscriptions     — amount_cents (price locked at sign-up, used to
--      check renewals), cancel_at_period_end, last_payment_at,
--      last_failed_at.
-- ============================================================

-- ── 1. Checkouts ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `billing_checkouts` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `m_payment_id`    CHAR(36)     NOT NULL COMMENT 'Our reference, sent to PayFast and echoed in the ITN',
  `account_type`    ENUM('broker','organization') NOT NULL,
  `account_id`      INT UNSIGNED NOT NULL,
  `plan_id`         INT UNSIGNED NOT NULL,
  `amount_cents`    INT UNSIGNED NOT NULL COMMENT 'First payment (charged at checkout)',
  `recurring_cents` INT UNSIGNED NOT NULL COMMENT 'Each monthly payment after that',
  `user_id`         INT UNSIGNED NULL     COMMENT 'Who clicked Upgrade',
  `status`          ENUM('started','paid','cancelled','failed','expired') NOT NULL DEFAULT 'started',
  `pf_payment_id`   VARCHAR(40)  NULL,
  `token`           VARCHAR(64)  NULL COMMENT 'PayFast subscription token (from the first ITN)',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_checkout_ref` (`m_payment_id`),
  KEY `idx_checkout_account` (`account_type`, `account_id`, `status`),
  KEY `idx_checkout_token` (`token`),
  CONSTRAINT `fk_checkout_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `fk_checkout_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plans Phase 3: each PayFast checkout started from SalesDesk';

-- ── 2. ITN log ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `billing_events` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`        VARCHAR(20)  NOT NULL DEFAULT 'payfast',
  `m_payment_id`    VARCHAR(64)  NULL,
  `pf_payment_id`   VARCHAR(40)  NULL,
  `token`           VARCHAR(64)  NULL,
  `payment_status`  VARCHAR(20)  NULL COMMENT 'COMPLETE | FAILED | PENDING | CANCELLED',
  `amount_gross_cents` INT       NULL,
  `amount_fee_cents`   INT       NULL,
  `amount_net_cents`   INT       NULL,
  `account_type`    ENUM('broker','organization') NULL,
  `account_id`      INT UNSIGNED NULL,
  `signature_ok`    TINYINT(1)   NOT NULL DEFAULT 0,
  `source_ok`       TINYINT(1)   NOT NULL DEFAULT 0,
  `amount_ok`       TINYINT(1)   NOT NULL DEFAULT 0,
  `server_ok`       TINYINT(1)   NOT NULL DEFAULT 0,
  `outcome`         VARCHAR(80)  NOT NULL COMMENT 'activated | renewed | cancelled | failed | duplicate | rejected: … | ignored: …',
  `remote_ip`       VARCHAR(45)  NULL,
  `payload`         TEXT         NULL COMMENT 'The posted fields as JSON',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bev_pf` (`pf_payment_id`, `payment_status`),
  KEY `idx_bev_token` (`token`),
  KEY `idx_bev_account` (`account_type`, `account_id`),
  KEY `idx_bev_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plans Phase 3: every PayFast notification and what was done with it';

-- ── 3. Subscriptions: billing columns ───────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'amount_cents') = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `amount_cents` INT UNSIGNED NULL COMMENT ''Recurring price locked at sign-up (online billing)'' AFTER `provider_ref`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'cancel_at_period_end') = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Cancelled at the provider; the plan runs until current_period_end'' AFTER `amount_cents`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'last_payment_at') = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `last_payment_at` DATETIME NULL AFTER `cancel_at_period_end`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'last_failed_at') = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `last_failed_at` DATETIME NULL AFTER `last_payment_at`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND INDEX_NAME = 'idx_sub_provider_ref') = 0,
    'ALTER TABLE `subscriptions` ADD INDEX `idx_sub_provider_ref` (`provider`, `provider_ref`)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0022_billing');
SELECT '0022 applied — online billing tables ready (add includes/payfast.config.php to switch PayFast on)' AS result;
