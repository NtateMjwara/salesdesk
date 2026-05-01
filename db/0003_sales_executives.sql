-- ============================================================
-- Migration 0003 — Sales Executives
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Rationale:
--   A dealership (dealer) is created and owned by a dealer principal.
--   Sales executives are employees of that dealership who need their
--   own user accounts. They sign up independently, select their
--   dealership, and wait for the principal to verify them before
--   they can upload cars or access dealer-side features.
--
-- Changes:
--   1. users.role ENUM extended → adds 'sales_exec'
--   2. sales_executives table — links user ↔ dealer with
--      verification lifecycle (pending / verified / rejected / suspended)
--   3. cars.uploaded_by_exec_id — nullable FK so we know which exec
--      uploaded a given car (or NULL if the principal uploaded it)
--   4. platform_config — default max_execs_per_dealer added
--
-- Run after:  0002_initial_schema.sql (or 0002_indexes.sql if present)
-- Command:    mysql -u salesdesk_user -p salesdesk_db < 0003_sales_executives.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- 1. Extend users.role to include 'sales_exec'
--    MySQL requires ALTER COLUMN to redefine the ENUM.
-- ============================================================
ALTER TABLE users
    MODIFY COLUMN role ENUM('broker','dealer','sales_exec','admin')
        NOT NULL DEFAULT 'broker';

-- ============================================================
-- 2. SALES_EXECUTIVES
--    One row per sales exec ↔ dealership relationship.
--    A user can only belong to one dealership at a time (UNIQUE
--    on user_id). If they leave and join another, the row is
--    updated — the audit_log captures the history.
--
--    verification_status lifecycle (dealer-principal controlled):
--      pending   → exec submitted join request, awaiting approval
--      verified  → principal approved, exec has full dealer-side access
--      rejected  → principal declined the request
--      suspended → was verified, later suspended by principal
--
--    job_title: optional free-text e.g. "Senior Sales Executive"
--    invited_by: if the principal pre-invited them by email this
--                stores the principal's user_id; NULL = self-registered
-- ============================================================
CREATE TABLE IF NOT EXISTS sales_executives (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL UNIQUE
                            COMMENT 'FK → users — one dealer per exec at a time',
    dealer_id           INT UNSIGNED NOT NULL
                            COMMENT 'FK → dealers — the dealership they belong to',
    job_title           VARCHAR(100) DEFAULT NULL
                            COMMENT 'e.g. Sales Executive / Senior Sales Consultant',
    verification_status ENUM('pending','verified','rejected','suspended')
                            NOT NULL DEFAULT 'pending',
    invited_by          INT UNSIGNED DEFAULT NULL
                            COMMENT 'FK → users (principal) — NULL if self-registered',
    verified_by         INT UNSIGNED DEFAULT NULL
                            COMMENT 'FK → users (principal/admin) who approved/rejected',
    verified_at         DATETIME     DEFAULT NULL,
    rejection_reason    VARCHAR(255) DEFAULT NULL
                            COMMENT 'Filled when status = rejected or suspended',
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_sexec_user      FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_sexec_dealer    FOREIGN KEY (dealer_id)  REFERENCES dealers(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sexec_invited   FOREIGN KEY (invited_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_sexec_verified  FOREIGN KEY (verified_by) REFERENCES users(id)   ON DELETE SET NULL,

    INDEX idx_sexec_user    (user_id),
    INDEX idx_sexec_dealer  (dealer_id),
    INDEX idx_sexec_status  (verification_status),
    INDEX idx_sexec_invited (invited_by)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sales executives linked to a dealership, verified by the dealer principal.';

-- ============================================================
-- 3. cars — track which sales exec uploaded the listing
--    NULL = uploaded by the dealer principal themselves.
-- ============================================================
ALTER TABLE cars
    ADD COLUMN uploaded_by_exec_id INT UNSIGNED DEFAULT NULL
        COMMENT 'FK → sales_executives.id — NULL if uploaded by principal'
        AFTER dealer_id,
    ADD CONSTRAINT fk_car_exec
        FOREIGN KEY (uploaded_by_exec_id)
        REFERENCES sales_executives(id)
        ON DELETE SET NULL,
    ADD INDEX idx_car_exec (uploaded_by_exec_id);

-- ============================================================
-- 4. platform_config — new defaults for exec management
-- ============================================================
INSERT INTO platform_config (config_key, config_value) VALUES
    ('max_execs_per_dealer',           '20'),
    ('exec_approval_notify_principal', '1')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

SET FOREIGN_KEY_CHECKS = 1;
