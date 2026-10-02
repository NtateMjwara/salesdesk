-- ============================================================
-- SalesDesk — 0018: Unplaced sales execs (dealer workspace Phase 4)
--
-- Requires 0015. SAFE TO RE-RUN.
--
-- An "unplaced" sales exec is an active sales_exec account with no
-- dealership: they skipped choosing one at signup (no sales_executives
-- row) or their request was declined. Admins who can run a dealership
-- (Operate, or delegated by its principal) invite them; the exec accepts
-- and is verified at that dealership in one step, or declines.
--
-- exec_invitations.status:
--   pending    waiting for the exec (expires after 14 days — checked in code)
--   accepted   exec joined the dealership
--   declined   exec said no
--   cancelled  the inviter withdrew it, or the exec joined elsewhere
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0018_exec_invitations.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exec_invitations (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exec_user_id       INT UNSIGNED NOT NULL COMMENT 'The sales exec (users.id, role sales_exec)',
    dealer_id          INT UNSIGNED NOT NULL,
    invited_by_user_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Admin who sent it',
    message            VARCHAR(255) NULL DEFAULT NULL,
    status             ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
    expires_at         DATETIME     NOT NULL,
    responded_at       DATETIME     NULL DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_einv_exec    FOREIGN KEY (exec_user_id)       REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_einv_dealer  FOREIGN KEY (dealer_id)          REFERENCES dealers(id) ON DELETE CASCADE,
    CONSTRAINT fk_einv_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_einv_exec   (exec_user_id, status),
    INDEX idx_einv_dealer (dealer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Invitations from SalesDesk to unplaced sales execs to join a dealership';

INSERT IGNORE INTO schema_migrations (name) VALUES ('0018_exec_invitations');
SELECT '0018 applied — exec invitations' AS result;
