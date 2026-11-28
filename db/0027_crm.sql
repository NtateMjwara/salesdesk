-- ============================================================
-- SalesDesk — 0027: CRM in the portal, v1
--
-- SAFE TO RE-RUN. Spec: docs/crm/spec.md. Code: includes/crm.php.
-- Run from Admin → Database updates. After the SQL, the runner calls
-- sdCrmBackfill() (SD_MIGRATION_POST), which builds each lead's timeline
-- from the old notes box and the audit log, and applies the default
-- assignment. Nothing here touches attribution, network bonus or
-- commission data.
--
--   1. leads: schema brought in line with the code
--        attribution_type            written by api/leads/submit.php,
--                                    never created by a migration
--        broker_id / salesdesk_id    NULL on platform leads
--        buyer_name / buyer_phone    NULL after the POPIA scrub
--   2. leads: CRM columns
--        assigned_user_id / _at / assigned_by_user_id
--        first_contact_at, last_activity_at
--   3. lead_activities   the timeline (immutable entries)
--   4. lead_tasks        follow-ups with due dates and reminders
--   5. lead_alerts       untouched / stale alerts, one per quiet period
--   6. platform_config   CRM thresholds and business hours
-- ============================================================

-- ── 1. leads: match the code ────────────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'attribution_type') = 0,
    'ALTER TABLE `leads` ADD COLUMN `attribution_type` ENUM(''broker'',''platform'') NOT NULL DEFAULT ''broker'' COMMENT ''broker = a desk got the lead; platform = no desk (platform car page)'' AFTER `source_tracking_code`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- Each MODIFY keeps the column's live type and comment, and only runs while it is still NOT NULL.
SET @sd_sql = COALESCE((SELECT IF(IS_NULLABLE = 'NO', CONCAT('ALTER TABLE `leads` MODIFY `broker_id` ', COLUMN_TYPE, ' NULL DEFAULT NULL', IF(COLUMN_COMMENT <> '', CONCAT(' COMMENT ', QUOTE(COLUMN_COMMENT)), '')), 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'broker_id'), 'DO 0');
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = COALESCE((SELECT IF(IS_NULLABLE = 'NO', CONCAT('ALTER TABLE `leads` MODIFY `salesdesk_id` ', COLUMN_TYPE, ' NULL DEFAULT NULL', IF(COLUMN_COMMENT <> '', CONCAT(' COMMENT ', QUOTE(COLUMN_COMMENT)), '')), 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'salesdesk_id'), 'DO 0');
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = COALESCE((SELECT IF(IS_NULLABLE = 'NO', CONCAT('ALTER TABLE `leads` MODIFY `buyer_name` ', COLUMN_TYPE, ' NULL DEFAULT NULL', IF(COLUMN_COMMENT <> '', CONCAT(' COMMENT ', QUOTE(COLUMN_COMMENT)), '')), 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'buyer_name'), 'DO 0');
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = COALESCE((SELECT IF(IS_NULLABLE = 'NO', CONCAT('ALTER TABLE `leads` MODIFY `buyer_phone` ', COLUMN_TYPE, ' NULL DEFAULT NULL', IF(COLUMN_COMMENT <> '', CONCAT(' COMMENT ', QUOTE(COLUMN_COMMENT)), '')), 'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'buyer_phone'), 'DO 0');
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- Platform leads that came in before the column existed (updated_at kept as it was).
UPDATE `leads` SET `attribution_type` = 'platform', `updated_at` = `updated_at`
WHERE `broker_id` IS NULL AND `attribution_type` <> 'platform';

-- ── 2. leads: CRM columns ───────────────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'assigned_user_id') = 0,
    'ALTER TABLE `leads` ADD COLUMN `assigned_user_id` INT UNSIGNED NULL COMMENT ''CRM 0027: who works the lead (a verified exec of the dealership, or the principal); NULL = Unassigned'' AFTER `status_updated_at`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'assigned_at') = 0,
    'ALTER TABLE `leads` ADD COLUMN `assigned_at` DATETIME NULL AFTER `assigned_user_id`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'assigned_by_user_id') = 0,
    'ALTER TABLE `leads` ADD COLUMN `assigned_by_user_id` INT UNSIGNED NULL COMMENT ''CRM 0027: NULL = assigned automatically'' AFTER `assigned_at`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'first_contact_at') = 0,
    'ALTER TABLE `leads` ADD COLUMN `first_contact_at` DATETIME NULL COMMENT ''CRM 0027: first logged contact with the buyer (speed-to-lead)'' AFTER `assigned_by_user_id`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'last_activity_at') = 0,
    'ALTER TABLE `leads` ADD COLUMN `last_activity_at` DATETIME NULL COMMENT ''CRM 0027: newest timeline entry (stale-lead alerts)'' AFTER `first_contact_at`', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_lead_assignee') = 0,
    'ALTER TABLE `leads` ADD KEY `idx_lead_assignee` (`assigned_user_id`, `status`)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_lead_crm_activity') = 0,
    'ALTER TABLE `leads` ADD KEY `idx_lead_crm_activity` (`dealer_id`, `status`, `last_activity_at`)', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND CONSTRAINT_NAME = 'fk_lead_assignee') = 0,
    'ALTER TABLE `leads` ADD CONSTRAINT `fk_lead_assignee` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND CONSTRAINT_NAME = 'fk_lead_assigned_by') = 0,
    'ALTER TABLE `leads` ADD CONSTRAINT `fk_lead_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 3. Timeline ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lead_activities (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lead_id       INT UNSIGNED NOT NULL,
    dealer_id     INT UNSIGNED NOT NULL,
    type          ENUM('note','call','whatsapp','email','test_drive','status_change','assignment','task','system') NOT NULL,
    outcome       VARCHAR(30)  DEFAULT NULL COMMENT 'call: reached | no_answer | voicemail | wrong_number; system: what happened',
    body          TEXT         DEFAULT NULL COMMENT 'POPIA: may hold personal information, scrubbed with the lead',
    meta          JSON         DEFAULT NULL COMMENT 'e.g. {from,to} on status_change, {to_user_id} on assignment',
    actor_user_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = SalesDesk (system or automatic)',
    actor_mode    VARCHAR(20)  DEFAULT NULL COMMENT 'dealer workspace mode of the actor (operator, delegate …)',
    source_ref    VARCHAR(40)  DEFAULT NULL COMMENT 'set only by imports, so the 0027 backfill is safe to re-run',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lact_lead   FOREIGN KEY (lead_id)       REFERENCES leads(id)   ON DELETE CASCADE,
    CONSTRAINT fk_lact_dealer FOREIGN KEY (dealer_id)     REFERENCES dealers(id) ON DELETE CASCADE,
    CONSTRAINT fk_lact_actor  FOREIGN KEY (actor_user_id) REFERENCES users(id)   ON DELETE SET NULL,
    UNIQUE KEY uq_lact_source (source_ref),
    INDEX idx_lact_lead   (lead_id, created_at),
    INDEX idx_lact_dealer (dealer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Follow-up tasks ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lead_tasks (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lead_id            INT UNSIGNED NOT NULL,
    dealer_id          INT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL COMMENT 'POPIA: scrubbed with the lead',
    type               ENUM('call','whatsapp','email','test_drive','other') NOT NULL DEFAULT 'call',
    due_at             DATETIME     NOT NULL,
    assignee_user_id   INT UNSIGNED DEFAULT NULL,
    created_by_user_id INT UNSIGNED DEFAULT NULL,
    done_at            DATETIME     DEFAULT NULL,
    done_by_user_id    INT UNSIGNED DEFAULT NULL,
    cancelled_at       DATETIME     DEFAULT NULL,
    reminded_at        DATETIME     DEFAULT NULL COMMENT 'due reminder sent; cleared on reschedule',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ltask_lead     FOREIGN KEY (lead_id)            REFERENCES leads(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ltask_dealer   FOREIGN KEY (dealer_id)          REFERENCES dealers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ltask_assignee FOREIGN KEY (assignee_user_id)   REFERENCES users(id)   ON DELETE SET NULL,
    CONSTRAINT fk_ltask_creator  FOREIGN KEY (created_by_user_id) REFERENCES users(id)   ON DELETE SET NULL,
    CONSTRAINT fk_ltask_doneby   FOREIGN KEY (done_by_user_id)    REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_ltask_assignee (assignee_user_id, done_at, due_at),
    INDEX idx_ltask_dealer   (dealer_id, done_at, due_at),
    INDEX idx_ltask_lead     (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Alerts sent (one per quiet period) ───────────────────────
CREATE TABLE IF NOT EXISTS lead_alerts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lead_id    INT UNSIGNED NOT NULL,
    alert_type ENUM('untouched','untouched_escalated','stale') NOT NULL,
    anchor_at  DATETIME     NOT NULL COMMENT 'the last_activity_at (or created_at) the alert was measured from',
    sent_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lalert_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    UNIQUE KEY uq_lalert (lead_id, alert_type, anchor_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Settings (editable without a deploy) ─────────────────────
-- South African public holidays are worked out in code; crm_extra_holidays
-- adds one-off dates (YYYY-MM-DD, comma-separated), e.g. a declared holiday.
-- crm_live_since: leads that went quiet before the CRM existed show their
-- badges and count in the digest, but don't each send an alert on day one.
INSERT IGNORE INTO platform_config (config_key, config_value) VALUES
    ('crm_untouched_business_hours', '2'),
    ('crm_untouched_escalate_hours', '4'),
    ('crm_stale_days',               '5'),
    ('crm_business_hours',           'mon-fri 08:00-17:00; sat 08:00-13:00'),
    ('crm_extra_holidays',           ''),
    ('crm_live_since',               DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));

INSERT IGNORE INTO schema_migrations (name) VALUES ('0027_crm');
SELECT '0027 applied — CRM tables ready (timeline, tasks, assignment, alerts)' AS result;
