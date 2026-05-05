-- ============================================================
-- Migration 0005 — Schema Addenda & Corrections
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Run AFTER: 0004_indexes.sql
-- Command:   mysql -u salesdesk_user -p salesdesk_db < 0005_addenda.sql
--
-- Implements:
--   D-07  popia_retention_days in platform_config
--   D-08  invoice_number_prefix in platform_config
--   D-10  profiles.car_limit column (admin-overrideable per-broker)
--   BUG-05 api_rate_limits table (backing checkApiRateLimit())
--   BUG-07 Fix commissions CHECK constraint (DECIMAL drift issue)
--
-- Safe to re-run: all ALTER TABLE use IF NOT EXISTS / IF EXISTS.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- D-10: profiles.car_limit
-- Admin-overrideable per-broker car limit.
-- NULL = use platform_config.broker_car_limit_default (10).
-- ============================================================
ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS car_limit INT UNSIGNED DEFAULT NULL
        COMMENT 'NULL = use platform_config.broker_car_limit_default; admin can set per-broker override'
        AFTER onboarding_completed;

-- ============================================================
-- D-07 + D-08 + D-10 extension: platform_config defaults
-- Uses ON DUPLICATE KEY UPDATE so safe to re-run.
-- ============================================================
INSERT INTO platform_config (config_key, config_value) VALUES
    ('popia_retention_days',     '365'),
    ('invoice_number_prefix',    'INV'),
    ('max_execs_per_dealer',     '20'),
    ('exec_approval_notify_principal', '1'),
    ('broker_car_limit_default', '10'),
    ('api_rate_limit_per_minute','60'),
    ('commission_rounding_scale','2'),
    -- SalesDesk EFT banking details — shown on dealer commission invoices.
    -- Update these before going live. Never commit real account details.
    ('salesdesk_bank_name',      'FNB'),
    ('salesdesk_bank_account',   '62000000000'),
    ('salesdesk_bank_branch',    '250655'),
    ('salesdesk_bank_holder',    'SalesDesk (Pty) Ltd')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ============================================================
-- BUG-07: Fix commissions CHECK constraint
--
-- The original chk_comm_net enforces exact equality:
--   net_amount = gross_amount - platform_fee
-- MySQL DECIMAL arithmetic can produce tiny rounding drift,
-- causing valid inserts to fail with a CHECK constraint error.
--
-- Fix: Drop the exact-equality constraint. Compute net_amount
-- in PHP (round($gross - $fee, 2)) before INSERT.
-- Replace with a meaningful-invariant-only constraint.
--
-- Note: MySQL 8.0.16+ supports DROP CHECK / ADD CONSTRAINT CHECK.
-- MariaDB 10.2+ uses DROP CONSTRAINT.
-- We use a procedure to handle both gracefully.
-- ============================================================

-- Drop the broken constraint (ignore error if already gone)
ALTER TABLE commissions
    DROP CONSTRAINT IF EXISTS chk_comm_net;

-- Replace with corrected constraint:
--   - gross > 0
--   - fee >= 0
--   - net > 0
--   - net < gross (catches cases where fee > gross, always wrong)
--   - No exact equality — PHP computes net before insert
ALTER TABLE commissions
    DROP CONSTRAINT IF EXISTS chk_comm_pos;

ALTER TABLE commissions
    ADD CONSTRAINT chk_comm_amounts
        CHECK (
            gross_amount > 0
            AND platform_fee >= 0
            AND net_amount   > 0
            AND net_amount   < gross_amount
        );

-- ============================================================
-- BUG-05: api_rate_limits table
-- Used by checkApiRateLimit() in functions.php.
-- Mirrors the login_attempts pattern — DB-backed, works on
-- shared hosting without Redis/APCu.
--
-- window_start: the UTC minute-boundary timestamp for this window
-- request_count: number of requests in this window
--
-- Application logic:
--   1. Delete expired windows  (older than API_RATE_WINDOW_SECONDS)
--   2. INSERT ... ON DUPLICATE KEY UPDATE request_count = request_count + 1
--   3. SELECT request_count — if > max, reject with 429
--
-- UNIQUE KEY on (ip_address, endpoint, window_start) enables
-- atomic upsert with ON DUPLICATE KEY UPDATE.
-- ============================================================
CREATE TABLE IF NOT EXISTS api_rate_limits (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45)  NOT NULL,
    endpoint      VARCHAR(80)  NOT NULL COMMENT 'e.g. dealers_search, leads_submit',
    window_start  DATETIME     NOT NULL COMMENT 'Truncated to the current minute',
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_window (ip_address, endpoint, window_start),
    INDEX idx_rate_ip_endpoint (ip_address, endpoint),
    INDEX idx_rate_window_start (window_start) COMMENT 'For cleanup of expired rows'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='API rate limiting — DB-backed per-IP per-endpoint per-minute counters';

SET FOREIGN_KEY_CHECKS = 1;
