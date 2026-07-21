-- ============================================================
-- SalesDesk — Cookie Consent Log
-- Migration: 00XX_cookie_consents.sql
--   (rename to match your next sequential migration number,
--    e.g. if your last migration is 0008_saved_searches.sql,
--    this becomes 0009_cookie_consents.sql)
--
-- POPIA (s11) and GDPR (Art. 7(1)) both require the data controller
-- to be able to DEMONSTRATE that consent was given — not just to have
-- obtained it. A cookie on the visitor's own browser is not, by
-- itself, proof you can produce later; this table is that proof.
--
-- One row per consent EVENT (not one row per visitor) — every time
-- someone accepts, rejects, or updates their preferences, a new row
-- is inserted. This gives you a full history per visitor rather than
-- an overwritten "current state" you can't audit later.
-- ============================================================

CREATE TABLE IF NOT EXISTS cookie_consents (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Ties back to the same visitor_session_id used by car_views,
    -- saved_searches, visitor_wishlist etc. (see includes/visitor.php).
    -- Nullable because a consent choice can be made before a visitor
    -- session row necessarily exists yet on some entry points.
    visitor_session_id  VARCHAR(64)      NULL,

    -- Snapshot of what was actually agreed to. Kept as individual
    -- boolean columns (not just a JSON blob) so you can report on
    -- consent rates per category without parsing JSON in queries.
    necessary           TINYINT(1)       NOT NULL DEFAULT 1, -- always 1; not a real choice
    functional          TINYINT(1)       NOT NULL DEFAULT 0,
    analytics           TINYINT(1)       NOT NULL DEFAULT 0,
    marketing           TINYINT(1)       NOT NULL DEFAULT 0,

    -- Which banner/policy version was shown. Bump
    -- COOKIE_CONSENT_POLICY_VERSION in includes/cookie-consent.php
    -- whenever the categories or their purposes change materially —
    -- that forces every visitor to be re-prompted, and lets you prove
    -- exactly what wording someone consented to at the time.
    policy_version      VARCHAR(20)      NOT NULL,

    -- How the consent was captured, for audit clarity.
    action              ENUM('accept_all', 'reject_all', 'custom') NOT NULL,

    -- Minimal request context — enough to investigate a dispute,
    -- not enough to be its own privacy problem. IP is stored as a
    -- SHA-256 hash, never in the clear (same spirit as the outreach
    -- programme's "ID number is encrypted before storage" pattern
    -- already used elsewhere in this codebase).
    ip_hash             CHAR(64)         NULL,
    user_agent          VARCHAR(255)     NULL,

    created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_visitor_session (visitor_session_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
