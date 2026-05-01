-- ============================================================
-- Migration 0001 — SalesDesk Initial Schema (MySQL / MariaDB)
-- Stack: PHP + PDO + MySQL. Email via PHPMailer (SMTP).
-- Payouts wired manually for MVP.
--
-- Changes from draft:
--   • addresses table added (normalized, FK'd to profiles/dealers/orgs)
--   • profiles.full_name → first_name + last_name
--   • profiles.address_id FK → addresses
--   • dealers.address_id FK → addresses (removed inline location)
--   • organizations.address_id FK → addresses
--   • All FK columns indexed in this file (companion 0002_indexes.sql
--     adds composite + search indexes on top)
--
-- Run order: this file FIRST, then 0002_indexes.sql.
-- Command:   mysql -u salesdesk_user -p salesdesk_db < 0001_initial_schema.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- USERS
-- Core auth table. One row per registered account.
-- role: 'broker' | 'dealer' | 'admin'
-- status: 'pending' (unverified) | 'active' | 'suspended'
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36)        NOT NULL UNIQUE COMMENT 'UUID v4 — used in public URLs',
    email            VARCHAR(255)    NOT NULL UNIQUE,
    role             ENUM('broker','dealer','admin') NOT NULL DEFAULT 'broker',
    status           ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
    password_hash    VARCHAR(255)    NOT NULL,
    email_verified   TINYINT(1)      NOT NULL DEFAULT 0,
    last_login       DATETIME        DEFAULT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- OTP CODES
-- Stores hashed OTPs for email verification, password reset, etc.
-- purpose: 'email_verify' | 'password_reset' | 'login_2fa'
-- ============================================================
CREATE TABLE IF NOT EXISTS otp_codes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    purpose     VARCHAR(30)  NOT NULL,
    code_hash   VARCHAR(255) NOT NULL COMMENT 'bcrypt hash of the plain OTP',
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    expires_at  DATETIME     NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_otp_user (user_id),
    INDEX idx_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LOGIN ATTEMPTS  (rate limiting, DB-backed)
-- ============================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(255) NOT NULL COMMENT 'email address',
    ip_address   VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_identifier (identifier),
    INDEX idx_login_ip (ip_address),
    INDEX idx_login_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ADDRESSES
-- Normalized address table. Reused by profiles, dealers, and
-- organizations via FK. Mirrors the SA_LOCATIONS cascade:
--   province → municipality → city → suburb
--
-- Optional lat/lng for future map features.
-- All location fields are nullable — collected progressively
-- during onboarding wizards.
-- ============================================================
CREATE TABLE IF NOT EXISTS addresses (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    province     VARCHAR(60)  DEFAULT NULL COMMENT 'e.g. Gauteng',
    municipality VARCHAR(80)  DEFAULT NULL COMMENT 'e.g. City of Johannesburg',
    city         VARCHAR(80)  DEFAULT NULL COMMENT 'e.g. Sandton',
    suburb       VARCHAR(80)  DEFAULT NULL COMMENT 'e.g. Morningside',
    street_line1 VARCHAR(120) DEFAULT NULL,
    street_line2 VARCHAR(120) DEFAULT NULL,
    postal_code  VARCHAR(10)  DEFAULT NULL,
    latitude     DECIMAL(9,6) DEFAULT NULL,
    longitude    DECIMAL(9,6) DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_addr_province (province),
    INDEX idx_addr_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PROFILES
-- Extended identity for both brokers and dealers.
-- first_name + last_name (not full_name — split for sorting,
-- display personalisation, and API contract consistency).
-- address_id FK → addresses (nullable until onboarding step 3).
-- onboarding_step: 0 = not started, 1/2/3 = in progress, 99 = complete.
-- ============================================================
CREATE TABLE IF NOT EXISTS profiles (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id              INT UNSIGNED  NOT NULL UNIQUE,
    first_name           VARCHAR(60)   DEFAULT NULL,
    last_name            VARCHAR(60)   DEFAULT NULL,
    avatar_url           VARCHAR(512)  DEFAULT NULL,
    phone                VARCHAR(30)   DEFAULT NULL,
    bio                  TEXT          DEFAULT NULL,
    address_id           INT UNSIGNED  DEFAULT NULL COMMENT 'FK → addresses',
    onboarding_step      TINYINT       NOT NULL DEFAULT 0,
    onboarding_completed TINYINT(1)    NOT NULL DEFAULT 0,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_user    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_profile_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL,
    INDEX idx_profile_user    (user_id),
    INDEX idx_profile_address (address_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SALESDESKS
-- One personal SalesDesk per broker. Created on signup.
-- is_active = 0 until onboarding is complete.
-- ============================================================
CREATE TABLE IF NOT EXISTS salesdesks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36)     NOT NULL UNIQUE,
    user_id        INT UNSIGNED NOT NULL UNIQUE COMMENT 'One desk per broker',
    slug           VARCHAR(60)  NOT NULL UNIQUE COMMENT 'URL-safe, set during onboarding',
    display_name   VARCHAR(120) NOT NULL DEFAULT 'My SalesDesk',
    tagline        VARCHAR(255) DEFAULT NULL,
    logo_url       VARCHAR(512) DEFAULT NULL,
    primary_colour VARCHAR(7)   NOT NULL DEFAULT '#0f4c9e',
    is_active      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_desk_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_desk_slug   CHECK (slug REGEXP '^[a-z0-9-]+$'),
    CONSTRAINT chk_desk_colour CHECK (primary_colour REGEXP '^#[0-9A-Fa-f]{6}$'),
    INDEX idx_desk_user (user_id),
    INDEX idx_desk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ORGANIZATIONS
-- CIPC-registered broker teams. Optional layer on top of desks.
-- address_id FK → addresses.
-- ============================================================
CREATE TABLE IF NOT EXISTS organizations (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)     NOT NULL UNIQUE,
    name                VARCHAR(120) NOT NULL,
    slug                VARCHAR(60)  NOT NULL UNIQUE,
    cipc_number         VARCHAR(30)  DEFAULT NULL,
    owner_user_id       INT UNSIGNED NOT NULL,
    address_id          INT UNSIGNED DEFAULT NULL COMMENT 'FK → addresses',
    verification_status ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
    verified_at         DATETIME     DEFAULT NULL,
    logo_url            VARCHAR(512) DEFAULT NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_org_owner   FOREIGN KEY (owner_user_id) REFERENCES users(id)     ON DELETE RESTRICT,
    CONSTRAINT fk_org_address FOREIGN KEY (address_id)    REFERENCES addresses(id) ON DELETE SET NULL,
    INDEX idx_org_owner   (owner_user_id),
    INDEX idx_org_address (address_id),
    INDEX idx_org_slug    (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ORGANIZATION MEMBERS
-- Links brokers to an org. Brokers keep their personal desk.
-- role: 'owner' | 'admin' | 'agent'
-- ============================================================
CREATE TABLE IF NOT EXISTS organization_members (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    role            ENUM('owner','admin','agent') NOT NULL DEFAULT 'agent',
    invited_by      INT UNSIGNED DEFAULT NULL,
    joined_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_member (organization_id, user_id),
    CONSTRAINT fk_orgmem_org  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_orgmem_user FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
    CONSTRAINT fk_orgmem_inv  FOREIGN KEY (invited_by)      REFERENCES users(id)         ON DELETE SET NULL,
    INDEX idx_orgmem_org  (organization_id),
    INDEX idx_orgmem_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEALERS
-- One dealer record per dealer user. Linked via user_id.
-- address_id FK → addresses (dealership physical location).
-- ============================================================
CREATE TABLE IF NOT EXISTS dealers (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)     NOT NULL UNIQUE,
    user_id             INT UNSIGNED NOT NULL UNIQUE,
    company_name        VARCHAR(120) NOT NULL DEFAULT 'My Dealership',
    slug                VARCHAR(80)  NOT NULL UNIQUE,
    logo_url            VARCHAR(512) DEFAULT NULL,
    address_id          INT UNSIGNED DEFAULT NULL COMMENT 'FK → addresses (dealership location)',
    brand_focus         TEXT         DEFAULT NULL COMMENT 'JSON array e.g. ["Toyota","Ford"]',
    verification_status ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
    cipc_doc_url        VARCHAR(512) DEFAULT NULL,
    verified_at         DATETIME     DEFAULT NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dealer_user    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_dealer_address FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL,
    INDEX idx_dealer_user    (user_id),
    INDEX idx_dealer_address (address_id),
    INDEX idx_dealer_slug    (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CARS
-- Listed by dealers. Has a commission offer for brokers.
-- commission_type: 'fixed' (Rands) | 'percentage'
-- status: 'active' | 'paused' | 'sold'
-- ============================================================
CREATE TABLE IF NOT EXISTS cars (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36)      NOT NULL UNIQUE,
    dealer_id        INT UNSIGNED  NOT NULL,
    slug             VARCHAR(100)  NOT NULL,
    make             VARCHAR(60)   NOT NULL,
    model            VARCHAR(100)  NOT NULL,
    year             SMALLINT      NOT NULL,
    price            DECIMAL(12,2) NOT NULL,
    mileage          INT UNSIGNED  DEFAULT NULL,
    condition_type   ENUM('new','demo','used') NOT NULL DEFAULT 'used',
    body_type        VARCHAR(40)   DEFAULT NULL,
    colour           VARCHAR(40)   DEFAULT NULL,
    transmission     VARCHAR(40)   DEFAULT NULL,
    fuel_type        VARCHAR(30)   DEFAULT NULL,
    drivetrain       VARCHAR(20)   DEFAULT NULL,
    description      TEXT          DEFAULT NULL,
    commission_type  ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    commission_value DECIMAL(10,2) NOT NULL COMMENT 'Rands if fixed; % if percentage',
    image_urls       TEXT          DEFAULT NULL COMMENT 'JSON array of image URLs',
    status           ENUM('active','paused','sold') NOT NULL DEFAULT 'active',
    sold_at          DATETIME      DEFAULT NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_car_dealer_slug (dealer_id, slug),
    CONSTRAINT fk_car_dealer FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE RESTRICT,
    INDEX idx_car_dealer (dealer_id),
    INDEX idx_car_status (status),
    INDEX idx_car_uuid   (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BROKER INVENTORY
-- A broker adds a car to their desk — generates the tracking link.
-- tracking_code is the ?ref= parameter (opaque, 16 hex chars).
-- ============================================================
CREATE TABLE IF NOT EXISTS broker_inventory (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    salesdesk_id  INT UNSIGNED NOT NULL,
    car_id        INT UNSIGNED NOT NULL,
    tracking_code VARCHAR(32)  NOT NULL UNIQUE COMMENT '16 random bytes → hex',
    views         INT UNSIGNED NOT NULL DEFAULT 0,
    added_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_desk_car (salesdesk_id, car_id),
    CONSTRAINT fk_binv_desk FOREIGN KEY (salesdesk_id) REFERENCES salesdesks(id) ON DELETE CASCADE,
    CONSTRAINT fk_binv_car  FOREIGN KEY (car_id)       REFERENCES cars(id)       ON DELETE CASCADE,
    INDEX idx_binv_desk     (salesdesk_id),
    INDEX idx_binv_car      (car_id),
    INDEX idx_binv_tracking (tracking_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LEADS
-- CRITICAL: all attribution fields written atomically on insert.
-- attribution_locked is always 1 — enforced by application logic.
-- ============================================================
CREATE TABLE IF NOT EXISTS leads (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                 CHAR(36)     NOT NULL UNIQUE,
    -- Attribution (immutable after insert)
    broker_id            INT UNSIGNED NOT NULL,
    salesdesk_id         INT UNSIGNED NOT NULL,
    organization_id      INT UNSIGNED DEFAULT NULL,
    car_id               INT UNSIGNED NOT NULL,
    dealer_id            INT UNSIGNED NOT NULL,
    source_tracking_code VARCHAR(32)  NOT NULL,
    attribution_locked   TINYINT(1)   NOT NULL DEFAULT 1,
    attributed_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Buyer info (POPIA-sensitive — see migration 0006)
    buyer_name           VARCHAR(120) NOT NULL,
    buyer_phone          VARCHAR(30)  NOT NULL,
    buyer_email          VARCHAR(255) DEFAULT NULL,
    buyer_intent         ENUM('within_30d','one_to_3mo','browsing') NOT NULL DEFAULT 'browsing',
    buyer_message        TEXT         DEFAULT NULL,
    consent_given        TINYINT(1)   NOT NULL DEFAULT 0,
    consent_at           DATETIME     DEFAULT NULL,
    -- Pipeline
    status               ENUM('new','contacted','test_drive','negotiation','closed','lost') NOT NULL DEFAULT 'new',
    dealer_notes         TEXT         DEFAULT NULL,
    status_updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Timestamps
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lead_broker  FOREIGN KEY (broker_id)       REFERENCES users(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_lead_desk    FOREIGN KEY (salesdesk_id)    REFERENCES salesdesks(id)    ON DELETE RESTRICT,
    CONSTRAINT fk_lead_org     FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_lead_car     FOREIGN KEY (car_id)          REFERENCES cars(id)          ON DELETE RESTRICT,
    CONSTRAINT fk_lead_dealer  FOREIGN KEY (dealer_id)       REFERENCES dealers(id)       ON DELETE RESTRICT,
    INDEX idx_lead_broker  (broker_id),
    INDEX idx_lead_desk    (salesdesk_id),
    INDEX idx_lead_org     (organization_id),
    INDEX idx_lead_car     (car_id),
    INDEX idx_lead_dealer  (dealer_id),
    INDEX idx_lead_status  (status),
    INDEX idx_lead_phone   (buyer_phone) COMMENT 'Duplicate detection query',
    INDEX idx_lead_uuid    (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- COMMISSIONS
-- Created when a dealer marks a lead as Closed.
-- status flow: pending → approved → scheduled → processing → paid / failed
-- ============================================================
CREATE TABLE IF NOT EXISTS commissions (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)      NOT NULL UNIQUE,
    lead_id         INT UNSIGNED  NOT NULL UNIQUE,
    broker_id       INT UNSIGNED  NOT NULL,
    organization_id INT UNSIGNED  DEFAULT NULL,
    dealer_id       INT UNSIGNED  NOT NULL,
    gross_amount    DECIMAL(12,2) NOT NULL,
    platform_fee    DECIMAL(12,2) NOT NULL,
    net_amount      DECIMAL(12,2) NOT NULL COMMENT 'gross_amount - platform_fee',
    status          ENUM('pending','approved','scheduled','processing','paid','failed') NOT NULL DEFAULT 'pending',
    approved_at     DATETIME      DEFAULT NULL,
    paid_at         DATETIME      DEFAULT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_comm_lead   FOREIGN KEY (lead_id)         REFERENCES leads(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_comm_broker FOREIGN KEY (broker_id)       REFERENCES users(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_comm_org    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_comm_dealer FOREIGN KEY (dealer_id)       REFERENCES dealers(id)       ON DELETE RESTRICT,
    CONSTRAINT chk_comm_net   CHECK (net_amount = gross_amount - platform_fee),
    CONSTRAINT chk_comm_pos   CHECK (gross_amount > 0 AND platform_fee >= 0 AND net_amount > 0),
    INDEX idx_comm_lead   (lead_id),
    INDEX idx_comm_broker (broker_id),
    INDEX idx_comm_org    (organization_id),
    INDEX idx_comm_dealer (dealer_id),
    INDEX idx_comm_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BANK ACCOUNTS
-- Used by brokers and orgs for commission payouts.
-- MVP: manual EFT. Future: Peach Payments or similar.
-- ============================================================
CREATE TABLE IF NOT EXISTS bank_accounts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED DEFAULT NULL,
    account_holder  VARCHAR(120) NOT NULL,
    bank_name       VARCHAR(80)  NOT NULL,
    account_number  VARCHAR(30)  NOT NULL COMMENT 'Store encrypted in production',
    branch_code     VARCHAR(10)  NOT NULL,
    account_type    ENUM('cheque','savings','transmission') NOT NULL DEFAULT 'cheque',
    is_verified     TINYINT(1)   NOT NULL DEFAULT 0,
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bank_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bank_org  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    INDEX idx_bank_user (user_id),
    INDEX idx_bank_org  (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PAYOUTS
-- One row per payout execution attempt.
-- MVP: manual EFT reference. idempotency_key prevents double-pay.
-- ============================================================
CREATE TABLE IF NOT EXISTS payouts (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36)      NOT NULL UNIQUE,
    commission_id    INT UNSIGNED  NOT NULL,
    broker_id        INT UNSIGNED  NOT NULL,
    organization_id  INT UNSIGNED  DEFAULT NULL,
    bank_account_id  INT UNSIGNED  NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    status           ENUM('scheduled','processing','paid','failed') NOT NULL DEFAULT 'scheduled',
    reference_number VARCHAR(80)   DEFAULT NULL COMMENT 'EFT/payment reference',
    error_message    TEXT          DEFAULT NULL,
    retry_count      TINYINT       NOT NULL DEFAULT 0,
    idempotency_key  VARCHAR(80)   NOT NULL UNIQUE,
    scheduled_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at     DATETIME      DEFAULT NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payout_comm   FOREIGN KEY (commission_id)    REFERENCES commissions(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_payout_broker FOREIGN KEY (broker_id)        REFERENCES users(id)         ON DELETE RESTRICT,
    CONSTRAINT fk_payout_org    FOREIGN KEY (organization_id)  REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_payout_bank   FOREIGN KEY (bank_account_id)  REFERENCES bank_accounts(id) ON DELETE RESTRICT,
    INDEX idx_payout_comm   (commission_id),
    INDEX idx_payout_broker (broker_id),
    INDEX idx_payout_org    (organization_id),
    INDEX idx_payout_bank   (bank_account_id),
    INDEX idx_payout_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTIFICATIONS
-- In-app notification inbox. Email delivery handled by PHPMailer.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(40)  NOT NULL COMMENT 'new_lead | deal_closed | payout_scheduled | etc.',
    title      VARCHAR(150) NOT NULL,
    body       TEXT         DEFAULT NULL,
    meta       JSON         DEFAULT NULL COMMENT 'Extra data for deep-linking',
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    read_at    DATETIME     DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user   (user_id),
    INDEX idx_notif_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NUDGES
-- Tracks which stale-lead reminders have been sent to dealers.
-- Prevents re-sending the same nudge type for the same lead.
-- ============================================================
CREATE TABLE IF NOT EXISTS nudges (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lead_id     INT UNSIGNED NOT NULL,
    dealer_id   INT UNSIGNED NOT NULL,
    nudge_type  ENUM('48h','5d','10d_flag') NOT NULL,
    sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nudge (lead_id, nudge_type),
    CONSTRAINT fk_nudge_lead   FOREIGN KEY (lead_id)   REFERENCES leads(id)   ON DELETE CASCADE,
    CONSTRAINT fk_nudge_dealer FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    INDEX idx_nudge_lead   (lead_id),
    INDEX idx_nudge_dealer (dealer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT LOGS
-- Append-only. Application MUST NOT UPDATE or DELETE rows here.
-- actor_id NULL = system-generated action.
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(80)  NOT NULL COMMENT 'e.g. lead.created | commission.approved',
    entity_type VARCHAR(40)  NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    before_data JSON         DEFAULT NULL,
    after_data  JSON         DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_actor      (actor_id),
    INDEX idx_audit_entity     (entity_type, entity_id),
    INDEX idx_audit_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PLATFORM CONFIG
-- Admin-editable key/value pairs. App reads at runtime.
-- ============================================================
CREATE TABLE IF NOT EXISTS platform_config (
    config_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
    config_value VARCHAR(255) NOT NULL,
    updated_by   INT UNSIGNED DEFAULT NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default config values
INSERT INTO platform_config (config_key, config_value) VALUES
    ('platform_fee_percent',          '10'),
    ('lead_duplicate_window_days',    '30'),
    ('nudge_threshold_48h_hours',     '48'),
    ('nudge_threshold_5d_hours',      '120'),
    ('nudge_threshold_flag_hours',    '240'),
    ('broker_car_limit_default',      '10'),
    ('otp_expiry_seconds',            '600'),
    ('max_images_per_car',            '10')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ============================================================
-- POPIA AUDIT  (data retention compliance — South Africa)
-- ============================================================
CREATE TABLE IF NOT EXISTS popia_audit (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    records_scrubbed INT UNSIGNED NOT NULL DEFAULT 0,
    operator         VARCHAR(120) NOT NULL DEFAULT 'system',
    notes            TEXT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;