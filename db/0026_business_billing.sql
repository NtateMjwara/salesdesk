-- ============================================================
-- SalesDesk — 0026: Business billing (hybrid)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
-- Needs 0019 (plans), 0020 (business orgs), 0022 (billing).
-- Code: includes/billing.php (PayFast, now for businesses too) and
--       includes/invoicing.php (monthly invoices).
--
--   • Business Pro pays online: the same PayFast monthly subscription as
--     brokers (billing_checkouts / billing_events already carry
--     account_type = 'organization'). No new table needed.
--   • Enterprise is invoiced: SalesDesk starts monthly invoicing for the
--     business (subscriptions.provider = 'invoice'); each month gets a
--     business_invoices row, emailed with EFT details, marked paid by a
--     superadmin. An unpaid invoice lets the plan lapse 7 days after it
--     was due (the usual lapse, then the 3b downgrade window).
--
-- Invoices are financial records: deleting a business keeps them
-- (organization_id → NULL; billed_to keeps the name).
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_invoices` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number`  VARCHAR(30)  NULL,
  `organization_id` INT UNSIGNED NULL,
  `billed_to`       VARCHAR(160) NOT NULL COMMENT 'Business name when issued',
  `plan_id`         INT UNSIGNED NULL,
  `plan_name`       VARCHAR(80)  NOT NULL,
  `period_start`    DATE         NOT NULL,
  `period_end`      DATE         NOT NULL COMMENT 'Exclusive: the next period starts here',
  `amount_cents`    INT UNSIGNED NOT NULL,
  `status`          ENUM('open','paid','void') NOT NULL DEFAULT 'open',
  `issued_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_at`          DATETIME     NOT NULL,
  `paid_at`         DATETIME     NULL,
  `paid_reference`  VARCHAR(80)  NULL COMMENT 'EFT reference or note',
  `marked_by`       INT UNSIGNED NULL,
  `emailed_at`      DATETIME     NULL,
  `reminded_at`     DATETIME     NULL,
  `void_reason`     VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_binv_number` (`invoice_number`),
  KEY `idx_binv_org` (`organization_id`, `period_start`),
  KEY `idx_binv_open` (`status`, `due_at`),
  CONSTRAINT `fk_binv_org`  FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_binv_plan` FOREIGN KEY (`plan_id`)         REFERENCES `plans` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_binv_by`   FOREIGN KEY (`marked_by`)       REFERENCES `users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business billing: monthly invoices (Enterprise, EFT)';

INSERT IGNORE INTO platform_config (config_key, config_value) VALUES ('business_invoice_prefix', 'SDB');

INSERT IGNORE INTO schema_migrations (name) VALUES ('0026_business_billing');
SELECT '0026 applied — Business Pro pays online (PayFast), Enterprise is invoiced monthly' AS result;
