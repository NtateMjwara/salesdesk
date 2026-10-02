-- ============================================================
-- SalesDesk — 0014: Superadmin
--
-- Requires 0012. SAFE TO RE-RUN: the column check reads
-- information_schema first; the grant is a plain UPDATE.
-- Works on MySQL 5.7/8.x and MariaDB 10.3+, from the CLI, phpMyAdmin
-- or the in-app runner (Admin → Database → Migrations).
--
-- Model:
--   • Superadmin is a FLAG on an admin account (users.is_superadmin),
--     not a new role — every existing requireRole('admin') check keeps
--     working, and sensitive pages add requireSuperadmin() on top.
--   • Superadmins: manage admins (invite, suspend, remove, grant /
--     revoke superadmin), assign dealerships & desk orgs to admins,
--     see everything, act on payouts, and own Dashboard, Audit,
--     Database and Migrations.
--   • Regular admins: only the dealerships / orgs they manage, and the
--     users and payouts connected to them (payouts read-only).
--
-- Bootstrap: the first superadmin is user #13 (ntatemjwara@gmail.com).
-- The email is checked too, so this can't promote the wrong account
-- if ids ever differ between environments. That account becomes an
-- active admin if it isn't one already.
--
-- Until this migration runs, every admin keeps today's access (the app
-- treats all admins as superadmins while the column is missing), so the
-- migration can always be run from the admin panel.
--
-- Run:  mysql -u salesdesk_user -p salesdesk_db < db/0014_superadmin.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1. users.is_superadmin ──────────────────────────────────
SET @sd_sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_superadmin') = 0, 'ALTER TABLE `users` ADD COLUMN `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = superadmin (role must be admin)'' AFTER role', 'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

-- ── 2. First superadmin ─────────────────────────────────────
UPDATE users
SET role = 'admin', is_superadmin = 1, status = 'active', updated_at = NOW()
WHERE id = 13 AND email = 'ntatemjwara@gmail.com';

INSERT IGNORE INTO schema_migrations (name) VALUES ('0014_superadmin');

SELECT id, email, role, status, is_superadmin,
       IF(is_superadmin = 1, '0014 applied — superadmin set', '0014 applied — user 13 NOT found, set is_superadmin manually') AS result
FROM users WHERE id = 13
UNION ALL
SELECT NULL, NULL, NULL, NULL, NULL, '0014 applied — user 13 NOT found, set is_superadmin manually'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE id = 13);
