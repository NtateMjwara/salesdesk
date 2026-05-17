-- ============================================================
-- Migration 0004 — Public Visitor Tracking
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Adds anonymous visitor tracking for the public-facing pages.
-- No buyer sign-up required — a secure random token in an
-- HttpOnly cookie identifies the session.
--
-- New tables:
--   visitor_sessions  — one row per browser session (cookie token)
--   car_views         — one row per car detail page view
--   visitor_wishlist  — heart/save on any car (no account needed)
--
-- Also adds api_rate_limits table (referenced by functions.php
-- checkApiRateLimit() but never previously migrated).
--
-- Run after:  0003_sales_executives.sql
-- Command:    mysql -u salesdesk_user -p salesdesk_db < 0004_public_visitor_tracking.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- API RATE LIMITS
-- Used by checkApiRateLimit() in functions.php.
-- One row per (ip, endpoint, minute-window).
-- Cleaned up automatically by the rate-limit function.
-- ============================================================
CREATE TABLE IF NOT EXISTS api_rate_limits (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45)   NOT NULL,
    endpoint      VARCHAR(80)   NOT NULL,
    window_start  DATETIME      NOT NULL COMMENT 'Truncated to the minute',
    request_count INT UNSIGNED  NOT NULL DEFAULT 1,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate (ip_address, endpoint, window_start),
    INDEX idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-IP per-endpoint per-minute request counter for API rate limiting.';

-- ============================================================
-- VISITOR SESSIONS
-- One row per unique browser visiting the public site.
-- Token is hex(random_bytes(32)) — 64 hex chars, set in an
-- HttpOnly Secure SameSite=Lax cookie named sd_vid.
--
-- ip_address     — stored for fraud / abuse detection only.
-- user_agent_hash— sha256 of raw UA, never displayed, analytics only.
-- user_id        — populated if the visitor later logs in as any role,
--                  allowing cross-session attribution stitching.
-- last_tracking_code — most recent ?ref= value seen, used for
--                  attribution persistence across page loads.
-- ============================================================
CREATE TABLE IF NOT EXISTS visitor_sessions (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token               CHAR(64)      NOT NULL UNIQUE
                            COMMENT 'hex(random_bytes(32)) — stored in sd_vid cookie',
    ip_address          VARCHAR(45)   NOT NULL,
    user_agent_hash     CHAR(64)      DEFAULT NULL
                            COMMENT 'sha256(user_agent) for analytics — never displayed',
    last_tracking_code  VARCHAR(32)   DEFAULT NULL
                            COMMENT 'Most recent ?ref= code seen — for attribution persistence',
    user_id             INT UNSIGNED  DEFAULT NULL
                            COMMENT 'FK → users — populated if visitor logs in',
    first_seen_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vs_token    (token),
    INDEX idx_vs_ip       (ip_address),
    INDEX idx_vs_user     (user_id),
    INDEX idx_vs_seen     (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anonymous visitor sessions for public-facing pages. No PII stored.';

-- ============================================================
-- CAR VIEWS
-- One row per car detail page impression.
-- tracking_code is the ?ref= value present on the URL at view time.
-- If no ?ref= was present and the visitor has a stored tracking
-- code in their session, the application layer uses that instead —
-- that value is still recorded here as tracking_code.
--
-- This table powers:
--   - The "views" counter on broker_inventory (supplemental to
--     the existing broker_inventory.views integer, which is
--     incremented only on lead submit; car_views captures all
--     page views including bounces).
--   - Dealer analytics: see which broker's link drives the most
--     views even before a lead is submitted.
-- ============================================================
CREATE TABLE IF NOT EXISTS car_views (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    car_id              INT UNSIGNED  NOT NULL,
    visitor_session_id  INT UNSIGNED  DEFAULT NULL,
    tracking_code       VARCHAR(32)   DEFAULT NULL
                            COMMENT '?ref= value at view time — NULL if organic / direct',
    broker_inventory_id INT UNSIGNED  DEFAULT NULL
                            COMMENT 'Resolved from tracking_code — NULL if organic',
    ip_address          VARCHAR(45)   NOT NULL,
    referrer            VARCHAR(512)  DEFAULT NULL,
    viewed_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cv_car     FOREIGN KEY (car_id)              REFERENCES cars(id)             ON DELETE CASCADE,
    CONSTRAINT fk_cv_session FOREIGN KEY (visitor_session_id)  REFERENCES visitor_sessions(id) ON DELETE SET NULL,
    CONSTRAINT fk_cv_bi      FOREIGN KEY (broker_inventory_id) REFERENCES broker_inventory(id) ON DELETE SET NULL,
    INDEX idx_cv_car      (car_id),
    INDEX idx_cv_session  (visitor_session_id),
    INDEX idx_cv_tracking (tracking_code),
    INDEX idx_cv_viewed   (viewed_at),
    INDEX idx_cv_bi       (broker_inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual car detail page view events, linked to visitor sessions and broker tracking codes.';

-- ============================================================
-- VISITOR WISHLIST
-- Heart / save button on public car pages — no account needed.
-- Persists for the life of the sd_vid cookie (90 days).
-- If the visitor later creates an account, their wishlist can be
-- migrated by matching visitor_sessions.user_id (future feature).
-- ============================================================
CREATE TABLE IF NOT EXISTS visitor_wishlist (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visitor_session_id  INT UNSIGNED  NOT NULL,
    car_id              INT UNSIGNED  NOT NULL,
    tracking_code       VARCHAR(32)   DEFAULT NULL
                            COMMENT 'Tracking code active when the car was saved',
    added_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vw (visitor_session_id, car_id),
    CONSTRAINT fk_vw_session FOREIGN KEY (visitor_session_id) REFERENCES visitor_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_vw_car     FOREIGN KEY (car_id)             REFERENCES cars(id)             ON DELETE CASCADE,
    INDEX idx_vw_session  (visitor_session_id),
    INDEX idx_vw_car      (car_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anonymous visitor wishlist — saved cars without requiring an account.';

-- ============================================================
-- platform_config defaults for public layer
-- ============================================================
INSERT INTO platform_config (config_key, config_value) VALUES
    ('public_visitor_cookie_days',   '90'),
    ('finance_deposit_percent',      '20'),
    ('finance_interest_rate_annual', '13.25'),
    ('finance_term_months',          '60'),
    ('browse_cars_per_page',         '24')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

SET FOREIGN_KEY_CHECKS = 1;
