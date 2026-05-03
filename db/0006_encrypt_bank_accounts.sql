-- ============================================================
-- Migration 0006 — Bank Account Encryption Schema
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Run AFTER: 0005_addenda.sql
-- BEFORE running this migration:
--   1. Set BANK_ENCRYPTION_KEY in your environment (min 32 chars)
--   2. Run scripts/encrypt-bank-accounts.php to populate
--      account_number_encrypted for all existing rows
--   3. Verify encrypt script output shows 0 errors
--   4. THEN run this migration to drop the plaintext column
--
-- Command:
--   php scripts/encrypt-bank-accounts.php   # step 1: encrypt
--   mysql -u salesdesk_user -p salesdesk_db < db/0006_encrypt_bank_accounts.sql  # step 2: drop plaintext
--
-- CRITICAL: Do not run the DROP COLUMN step until the encrypt
-- script has completed and been verified. The migration is split
-- into two explicit steps below — comment out STEP 2 until ready.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- STEP 1 — Add encrypted storage column
-- Run this first; plaintext column stays until STEP 2.
-- The encrypted value is a base64-encoded AES-256-CBC ciphertext.
-- IV is prepended to the stored value: base64(iv + ciphertext)
-- ============================================================
ALTER TABLE bank_accounts
    ADD COLUMN IF NOT EXISTS account_number_encrypted TEXT DEFAULT NULL
        COMMENT 'AES-256-CBC encrypted. Format: base64(16-byte IV || ciphertext). Populated by scripts/encrypt-bank-accounts.php.'
        AFTER account_number;

ALTER TABLE bank_accounts
    ADD COLUMN IF NOT EXISTS encryption_version TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0 = plaintext (legacy), 1 = AES-256-CBC v1'
        AFTER account_number_encrypted;

-- Add platform_config key to record encryption status.
INSERT INTO platform_config (config_key, config_value)
VALUES ('bank_encryption_enabled', '0')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ============================================================
-- STEP 2 — Drop plaintext column
-- Only run after encrypt script has been verified.
-- Uncomment when ready to cut over.
--
-- ALTER TABLE bank_accounts
--     DROP COLUMN IF EXISTS account_number;
--
-- UPDATE platform_config
-- SET config_value = '1'
-- WHERE config_key = 'bank_encryption_enabled';
-- ============================================================

-- ============================================================
-- STEP 3 — Add key rotation audit table
-- Records when encryption keys were rotated, for compliance.
-- ============================================================
CREATE TABLE IF NOT EXISTS encryption_audit (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rotated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rotated_by      INT UNSIGNED DEFAULT NULL COMMENT 'FK → users (admin)',
    key_version_old TINYINT UNSIGNED NOT NULL DEFAULT 0,
    key_version_new TINYINT UNSIGNED NOT NULL,
    rows_affected   INT UNSIGNED NOT NULL DEFAULT 0,
    notes           TEXT         DEFAULT NULL,
    CONSTRAINT fk_encaudit_user FOREIGN KEY (rotated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks bank account encryption key rotation events for compliance.';

SET FOREIGN_KEY_CHECKS = 1;
