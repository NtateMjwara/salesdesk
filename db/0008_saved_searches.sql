-- ============================================================
-- Migration 0008 — Saved Searches
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Powers the "Saved Searches" tab in the "Pick up where you left
-- off" section on the homepage (index.php).
--
-- No buyer sign-up required — keyed to visitor_sessions, same
-- pattern as visitor_wishlist (see 0007_public_visitor_tracking.sql).
--
-- query_string stores the raw query string from /c/ (everything
-- after the '?'), e.g. "condition=used&body_type[]=SUV%2F4x4&province=Gauteng"
-- label is a short user-facing description, either user-supplied
-- or auto-generated from the active filters at save time.
--
-- Run after:  0007_public_visitor_tracking.sql
-- Command:    mysql -u salesdesk_user -p salesdesk_db < 0008_saved_searches.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS saved_searches (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_session_id  INT UNSIGNED  NOT NULL,
    label               VARCHAR(120)  NOT NULL
                            COMMENT 'User-facing description, e.g. "SUVs in Gauteng under R400k"',
    query_string        VARCHAR(512)  NOT NULL
                            COMMENT 'Raw /c/ query string (without leading ?), urlencoded',
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ss_session FOREIGN KEY (visitor_session_id)
        REFERENCES visitor_sessions(id) ON DELETE CASCADE,
    INDEX idx_ss_session (visitor_session_id),
    INDEX idx_ss_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anonymous visitor saved searches — surfaced on the homepage activity tabs.';

SET FOREIGN_KEY_CHECKS = 1;
