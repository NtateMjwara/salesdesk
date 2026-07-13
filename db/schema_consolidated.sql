-- ============================================================
-- SalesDesk — Consolidated Schema (MySQL / MariaDB)
-- Stack: PHP + PDO + MySQL. Email via PHPMailer (SMTP).
--
-- This file merges the following migrations into a single,
-- dependency-ordered script. Where later migrations corrected
-- an earlier one, only the FINAL, corrected definition is kept
-- (the superseded version is not applied and then re-altered).
--
--   0002_initial_schema.sql        — base schema
--   0003_sales_executives.sql      — sales_executives, users.role, cars.uploaded_by_exec_id
--   0004/0009_car_features.sql     — car_features, car_feature_links (+ seed data)
--   0010_add_profile_car_limit.sql — profiles.car_limit
--   0010_car_import_fields.sql     — cars import/detail columns (superseded in part by 0011)
--   0011_vehicle_imports.sql       — vehicle_imports table, cars.import_id,
--                                     corrects 0010's VIN uniqueness (global -> per-dealer),
--                                     adds cars.source_image_urls
--
-- Net effect of the 0010 -> 0011 correction baked in below:
--   • vin uniqueness is scoped (dealer_id, vin) — NOT globally unique —
--     since the same physical car can legitimately be re-listed under
--     a different dealer over its lifetime.
--   • cars.source_image_urls exists alongside cars.image_urls.
--   • cars.import_id is a nullable FK -> vehicle_imports.
--
-- Run order: this is now the ONLY file you need to run, in place of
-- running 0002 through 0011 individually.
-- Command:   mysql -u salesdesk_user -p salesdesk_db < schema_consolidated.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- USERS
-- Core auth table. One row per registered account.
-- role: 'broker' | 'dealer' | 'sales_exec' | 'admin'   (sales_exec added by 0003)
-- status: 'pending' (unverified) | 'active' | 'suspended'
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36)        NOT NULL UNIQUE COMMENT 'UUID v4 — used in public URLs',
    email            VARCHAR(255)    NOT NULL UNIQUE,
    role             ENUM('broker','dealer','sales_exec','admin') NOT NULL DEFAULT 'broker',
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
--
-- car_limit (added by 0010_add_profile_car_limit.sql):
--   Per-broker max inventory size, editable by admin via the
--   "set_car_limit" action in app/admin/users.php.
--   NULL = use platform_config.broker_car_limit_default.
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
    car_limit            SMALLINT UNSIGNED DEFAULT NULL
                             COMMENT 'Per-broker max inventory size. NULL = use platform_config.broker_car_limit_default',
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
-- SALES_EXECUTIVES  (0003)
-- One row per sales exec ↔ dealership relationship. A user can only
-- belong to one dealership at a time (UNIQUE on user_id).
--
-- verification_status lifecycle (dealer-principal controlled):
--   pending → verified | rejected ; verified → suspended
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
-- VEHICLE_IMPORTS  (0011)
-- One row per sync() run of any InventoryImporterInterface
-- implementation (CsvImporter, future feed/API importers).
-- Must exist before `cars`, since cars.import_id references it.
-- ============================================================
CREATE TABLE IF NOT EXISTS vehicle_imports (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)      NOT NULL UNIQUE,
    dealer_id       INT UNSIGNED  NOT NULL,
    initiated_by    INT UNSIGNED  DEFAULT NULL
                        COMMENT 'FK -> users — the person who triggered this run, NULL if system/cron-initiated',
    source_platform VARCHAR(40)   NOT NULL
                        COMMENT 'Matches the importer''s getSourceName(), e.g. csv, cars_co_za, dealer_api — named to match cars.source_platform',
    source_ref      VARCHAR(255)  DEFAULT NULL
                        COMMENT 'The locator passed to crawlDealership() for this run — a file path for CsvImporter, a feed/dealer URL for future importers. Audit trail only; NOT the per-row dedup key (see cars.dealer_stock_no).',
    status          ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    total_rows      INT UNSIGNED  DEFAULT NULL,
    imported_count  INT UNSIGNED  NOT NULL DEFAULT 0,
    updated_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    skipped_count   INT UNSIGNED  NOT NULL DEFAULT 0,
    failed_count    INT UNSIGNED  NOT NULL DEFAULT 0,
    row_errors      JSON          DEFAULT NULL
                        COMMENT 'Array of {row, message} for rows that failed to parse/persist',
    error_message   TEXT          DEFAULT NULL
                        COMMENT 'Set only when the whole run aborts (e.g. unreadable source file) — see status=failed',
    started_at      DATETIME      NOT NULL,
    completed_at    DATETIME      DEFAULT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_vimport_dealer  FOREIGN KEY (dealer_id)    REFERENCES dealers(id) ON DELETE CASCADE,
    CONSTRAINT fk_vimport_actor   FOREIGN KEY (initiated_by) REFERENCES users(id)   ON DELETE SET NULL,

    INDEX idx_vimport_dealer (dealer_id),
    INDEX idx_vimport_status (status),
    INDEX idx_vimport_source (source_platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per inventory-import run (CSV upload, future API/feed syncs).';

-- ============================================================
-- CARS
-- Listed by dealers. Has a commission offer for brokers.
-- commission_type: 'fixed' (Rands) | 'percentage'
-- status: 'active' | 'paused' | 'sold'
--
-- Column groups added after the original (0002) schema:
--   • uploaded_by_exec_id                     — 0003
--   • variant, vin, mm_code                   — 0010 (vehicle identity)
--   • engine_capacity_cc … co2_emissions_gkm   — 0010 (engine/drivetrain)
--   • previous_owners … vat_inclusive          — 0010 (ownership/warranty/condition)
--   • source_platform, source_external_id,
--     dealer_stock_no, last_imported_at,
--     import_raw_payload                       — 0010 (import/sync metadata)
--   • import_id                                — 0011 (FK -> vehicle_imports)
--   • source_image_urls                        — 0011 (companion to image_urls)
--
-- Corrected in this consolidated version (per 0011):
--   • vin is unique per-dealer (uq_car_vin_dealer), NOT globally unique —
--     the same physical car may legitimately be re-listed under a
--     different dealer over its lifetime (trade-in, dealer transfer).
-- ============================================================
CREATE TABLE IF NOT EXISTS cars (
    id                       INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                     CHAR(36)      NOT NULL UNIQUE,
    dealer_id                INT UNSIGNED  NOT NULL,
    uploaded_by_exec_id      INT UNSIGNED  DEFAULT NULL
                                 COMMENT 'FK → sales_executives.id — NULL if uploaded by principal',
    slug                     VARCHAR(100)  NOT NULL,
    make                     VARCHAR(60)   NOT NULL,
    model                    VARCHAR(100)  NOT NULL,
    variant                  VARCHAR(150)  DEFAULT NULL
                                 COMMENT 'Trim/derivative, e.g. "1.4T Comfortline DSG" — distinguishes cars sharing make/model/year',
    vin                      VARCHAR(17)   DEFAULT NULL
                                 COMMENT '17-char Vehicle Identification Number — used for de-duplication and buyer verification',
    mm_code                  VARCHAR(20)   DEFAULT NULL
                                 COMMENT 'TransUnion/M&M vehicle code — SA industry-standard code used by finance houses, insurers, and listing platforms',
    year                     SMALLINT      NOT NULL,
    price                    DECIMAL(12,2) NOT NULL,
    mileage                  INT UNSIGNED  DEFAULT NULL,
    condition_type           ENUM('new','demo','used') NOT NULL DEFAULT 'used',
    body_type                VARCHAR(40)   DEFAULT NULL,
    colour                   VARCHAR(40)   DEFAULT NULL,
    transmission              VARCHAR(40)  DEFAULT NULL,
    fuel_type                VARCHAR(30)   DEFAULT NULL,
    drivetrain               VARCHAR(20)   DEFAULT NULL,
    engine_capacity_cc       SMALLINT UNSIGNED DEFAULT NULL
                                 COMMENT 'Engine displacement in cc, e.g. 1998 for "2.0L"',
    cylinders                TINYINT UNSIGNED DEFAULT NULL,
    induction                ENUM('na','turbo','twin_turbo','supercharged') DEFAULT NULL
                                 COMMENT 'Naturally aspirated vs forced induction type',
    power_kw                 SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Peak power in kW',
    torque_nm                SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Peak torque in Nm',
    gears                    TINYINT UNSIGNED DEFAULT NULL
                                 COMMENT 'Number of forward gears, e.g. 6, 7 (DSG), 8 (auto)',
    fuel_consumption_l100km  DECIMAL(4,1) UNSIGNED DEFAULT NULL
                                 COMMENT 'Combined-cycle claimed consumption, L/100km',
    co2_emissions_gkm        SMALLINT UNSIGNED DEFAULT NULL
                                 COMMENT 'CO2 emissions, g/km',
    previous_owners          TINYINT UNSIGNED DEFAULT NULL
                                 COMMENT 'Number of previous owners, if known',
    service_history          ENUM('full','partial','none','unknown') NOT NULL DEFAULT 'unknown',
    has_service_book         TINYINT(1)    NOT NULL DEFAULT 0,
    is_written_off            TINYINT(1)   NOT NULL DEFAULT 0
                                 COMMENT 'Insurance write-off / accident-damaged flag — legally significant disclosure in SA',
    interior_colour           VARCHAR(40)  DEFAULT NULL,
    doors                    TINYINT UNSIGNED DEFAULT NULL,
    seats                    TINYINT UNSIGNED DEFAULT NULL,
    warranty_type            ENUM('none','manufacturer','extended','dealer') NOT NULL DEFAULT 'none',
    warranty_expiry_date     DATE          DEFAULT NULL,
    warranty_expiry_km       INT UNSIGNED  DEFAULT NULL,
    service_plan_expiry_date DATE          DEFAULT NULL,
    service_plan_expiry_km   INT UNSIGNED  DEFAULT NULL,
    vat_inclusive             TINYINT(1)   NOT NULL DEFAULT 1
                                 COMMENT '1 = standard VAT scheme, 0 = sold under the second-hand goods / margin scheme',
    source_platform           VARCHAR(40)  NOT NULL DEFAULT 'manual'
                                 COMMENT 'e.g. cars_co_za, autotrader, manual — where this listing was imported from',
    source_external_id        VARCHAR(100) DEFAULT NULL
                                 COMMENT 'The unique listing ID on the source platform, used together with source_platform as the upsert key',
    import_id                 INT UNSIGNED DEFAULT NULL
                                 COMMENT 'FK -> vehicle_imports — the run that created or most recently updated this row via an importer',
    dealer_stock_no            VARCHAR(60) DEFAULT NULL
                                 COMMENT 'Dealer''s own internal stock reference — often the only stable join key on re-exported dealer CSVs',
    last_imported_at          DATETIME     DEFAULT NULL,
    import_raw_payload        JSON         DEFAULT NULL
                                 COMMENT 'Original CSV row as JSON — kept for debugging mapping issues after the fact',
    description               TEXT         DEFAULT NULL,
    commission_type           ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    commission_value          DECIMAL(10,2) NOT NULL COMMENT 'Rands if fixed; % if percentage',
    image_urls                TEXT         DEFAULT NULL COMMENT 'JSON array of image URLs',
    source_image_urls         JSON         DEFAULT NULL
                                 COMMENT 'Original image URLs from the import source, before re-hosting. Compared run-over-run to skip re-downloading unchanged images; also lets a failed re-host be retried from the original source without re-crawling the listing.',
    status                    ENUM('active','paused','sold') NOT NULL DEFAULT 'active',
    sold_at                    DATETIME    DEFAULT NULL,
    created_at                 DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_car_dealer_slug   (dealer_id, slug),
    UNIQUE KEY uq_car_vin_dealer    (dealer_id, vin)
        COMMENT 'Per-dealer VIN uniqueness (corrected in 0011 — was globally unique in 0010)',
    UNIQUE KEY uq_car_source        (source_platform, source_external_id)
        COMMENT 'Prevents the importer from ever double-inserting the same source-platform listing',
    UNIQUE KEY uq_car_dealer_stockno (dealer_id, dealer_stock_no)
        COMMENT 'Prevents duplicates when a dealer re-exports their own stock',

    CONSTRAINT fk_car_dealer FOREIGN KEY (dealer_id)           REFERENCES dealers(id)          ON DELETE RESTRICT,
    CONSTRAINT fk_car_exec   FOREIGN KEY (uploaded_by_exec_id) REFERENCES sales_executives(id) ON DELETE SET NULL,
    CONSTRAINT fk_car_import FOREIGN KEY (import_id)           REFERENCES vehicle_imports(id)  ON DELETE SET NULL,

    INDEX idx_car_dealer  (dealer_id),
    INDEX idx_car_status  (status),
    INDEX idx_car_uuid    (uuid),
    INDEX idx_car_exec    (uploaded_by_exec_id),
    INDEX idx_car_mmcode  (mm_code),
    INDEX idx_car_variant (variant),
    INDEX idx_car_import  (import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: uq_car_vin_dealer / uq_car_source / uq_car_dealer_stockno all allow
-- multiple NULLs (MySQL/MariaDB treat NULL as distinct in unique indexes),
-- so rows without a vin/stock_no/source_external_id are unaffected — these
-- only enforce uniqueness where the value IS set.

-- ============================================================
-- CAR_FEATURES  — master feature catalogue  (0004/0009)
-- ============================================================
CREATE TABLE IF NOT EXISTS car_features (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category    VARCHAR(60)   NOT NULL
                    COMMENT 'e.g. Safety, Infotainment & Connectivity',
    name        VARCHAR(120)  NOT NULL
                    COMMENT 'Display label e.g. Apple CarPlay',
    slug        VARCHAR(120)  NOT NULL UNIQUE
                    COMMENT 'Filter key e.g. apple-carplay — unique across all categories',
    is_popular  TINYINT(1)    NOT NULL DEFAULT 0
                    COMMENT '1 = show in top-level browse filter chips',
    sort_order  SMALLINT      NOT NULL DEFAULT 0
                    COMMENT 'Display order within category',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_feature_cat_name (category, name),
    INDEX idx_feature_category  (category),
    INDEX idx_feature_popular   (is_popular),
    INDEX idx_feature_slug      (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master catalogue of vehicle features. Controlled vocabulary — UI reads from sa-car-features.js.';

-- ============================================================
-- CAR_FEATURE_LINKS  — junction table (cars ↔ car_features)  (0004/0009)
-- ============================================================
CREATE TABLE IF NOT EXISTS car_feature_links (
    car_id      INT UNSIGNED NOT NULL COMMENT 'FK → cars.id',
    feature_id  INT UNSIGNED NOT NULL COMMENT 'FK → car_features.id',

    PRIMARY KEY (car_id, feature_id),

    CONSTRAINT fk_cfl_car     FOREIGN KEY (car_id)     REFERENCES cars(id)          ON DELETE CASCADE,
    CONSTRAINT fk_cfl_feature FOREIGN KEY (feature_id) REFERENCES car_features(id)  ON DELETE CASCADE,

    INDEX idx_link_feature (feature_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M:N junction — which features a car listing has.';

-- ============================================================
-- BROKER INVENTORY
-- A broker adds a car to their desk — generates the tracking link.
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
    -- Buyer info (POPIA-sensitive)
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
-- idempotency_key prevents double-pay.
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

-- Default config values (base + sales-exec additions from 0003)
INSERT INTO platform_config (config_key, config_value) VALUES
    ('platform_fee_percent',            '10'),
    ('lead_duplicate_window_days',      '30'),
    ('nudge_threshold_48h_hours',       '48'),
    ('nudge_threshold_5d_hours',        '120'),
    ('nudge_threshold_flag_hours',      '240'),
    ('broker_car_limit_default',        '10'),
    ('otp_expiry_seconds',              '600'),
    ('max_images_per_car',              '10'),
    ('max_execs_per_dealer',            '10'),
    ('exec_approval_notify_principal',  '1')
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

-- ============================================================
-- CAR_FEATURES SEED DATA  (0004/0009)
-- Matches sa-car-features.js exactly. Slugs are lowercase-hyphenated,
-- unique across all categories. is_popular = 1 on the features most
-- useful as browse chips. ON DUPLICATE KEY UPDATE is a no-op guard
-- for re-runs.
-- ============================================================

-- ── SAFETY ───────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Safety', 'Airbags (Front)',                         'airbags-front',                    0,  1),
('Safety', 'Side Airbags',                            'side-airbags',                     0,  2),
('Safety', 'Curtain Airbags',                         'curtain-airbags',                  0,  3),
('Safety', 'Knee Airbags',                             'knee-airbags',                     0,  4),
('Safety', 'Driver Airbag',                           'driver-airbag',                    0,  5),
('Safety', 'Passenger Airbag',                        'passenger-airbag',                 0,  6),
('Safety', 'Rear Side Airbags',                       'rear-side-airbags',                0,  7),
('Safety', 'ABS (Anti-lock Braking System)',          'abs',                              1,  8),
('Safety', 'EBD (Electronic Brakeforce Distribution)','ebd',                              0,  9),
('Safety', 'Brake Assist',                            'brake-assist',                     0, 10),
('Safety', 'Electronic Stability Control (ESC/ESP)',  'esc',                              1, 11),
('Safety', 'Traction Control',                        'traction-control',                 0, 12),
('Safety', 'Lane Departure Warning',                  'lane-departure-warning',           0, 13),
('Safety', 'Lane Keep Assist',                        'lane-keep-assist',                 0, 14),
('Safety', 'Blind Spot Monitoring',                   'blind-spot-monitoring',            1, 15),
('Safety', 'Rear Cross Traffic Alert',                'rear-cross-traffic-alert',         0, 16),
('Safety', 'Forward Collision Warning',               'forward-collision-warning',        0, 17),
('Safety', 'Automatic Emergency Braking (AEB)',       'aeb',                              1, 18),
('Safety', 'Adaptive Cruise Control',                 'adaptive-cruise-control',          1, 19),
('Safety', 'Driver Attention Monitoring',             'driver-attention-monitoring',      0, 20),
('Safety', 'Traffic Sign Recognition',                'traffic-sign-recognition',         0, 21),
('Safety', 'Lane Centering Assist',                   'lane-centering-assist',            0, 22),
('Safety', 'Traffic Jam Assist',                      'traffic-jam-assist',               0, 23),
('Safety', 'Evasive Steering Assist',                 'evasive-steering-assist',          0, 24),
('Safety', 'Night Vision Assist',                     'night-vision-assist',              0, 25),
('Safety', 'Pedestrian Detection',                    'pedestrian-detection',             0, 26),
('Safety', 'Cyclist Detection',                       'cyclist-detection',                0, 27),
('Safety', 'Tire Pressure Monitoring System (TPMS)', 'tpms',                              0, 28),
('Safety', 'ISOFIX Child Seat Anchors',               'isofix',                           0, 29),
('Safety', 'Emergency Brake Lights',                  'emergency-brake-lights',           0, 30),
('Safety', 'Hill Start Assist',                       'hill-start-assist',                0, 31),
('Safety', 'Hill Descent Control',                    'hill-descent-control',             0, 32),
('Safety', 'Roll Stability Control',                  'roll-stability-control',           0, 33),
('Safety', 'Surround View Camera',                    'surround-view-camera',             0, 34),
('Safety', '360° Camera',                             '360-camera',                       1, 35),
('Safety', 'Rear Parking Camera',                     'rear-parking-camera',              1, 36),
('Safety', 'Front Parking Sensors',                   'front-parking-sensors',            0, 37),
('Safety', 'Rear Parking Sensors',                    'rear-parking-sensors',             1, 38),
('Safety', 'Automatic Parking Assist',                'automatic-parking-assist',         0, 39),
('Safety', 'Self Parking System',                     'self-parking-system',              0, 40)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── SECURITY ─────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Security', 'Alarm System',              'alarm-system',              1,  1),
('Security', 'Immobilizer',               'immobilizer',               1,  2),
('Security', 'Central Locking',           'central-locking',           0,  3),
('Security', 'Remote Central Locking',    'remote-central-locking',    0,  4),
('Security', 'Keyless Entry',             'keyless-entry',             1,  5),
('Security', 'Keyless Start',             'keyless-start',             1,  6),
('Security', 'Smart Key',                 'smart-key',                 0,  7),
('Security', 'Vehicle Tracking System',   'vehicle-tracking',          1,  8),
('Security', 'Anti-Theft Locking Wheel Nuts', 'anti-theft-wheel-nuts', 0,  9),
('Security', 'Deadlocks',                 'deadlocks',                 0, 10),
('Security', 'Security Film',             'security-film',             0, 11),
('Security', 'Remote Vehicle Monitoring', 'remote-vehicle-monitoring', 0, 12),
('Security', 'PIN-to-Drive',              'pin-to-drive',              0, 13)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── COMFORT & CONVENIENCE ─────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Comfort & Convenience', 'Air Conditioning',             'air-conditioning',           1,  1),
('Comfort & Convenience', 'Automatic Climate Control',    'auto-climate-control',       1,  2),
('Comfort & Convenience', 'Dual-Zone Climate Control',    'dual-zone-climate',          0,  3),
('Comfort & Convenience', 'Tri-Zone Climate Control',     'tri-zone-climate',           0,  4),
('Comfort & Convenience', 'Four-Zone Climate Control',    'four-zone-climate',          0,  5),
('Comfort & Convenience', 'Rear Air Conditioning',        'rear-air-conditioning',      0,  6),
('Comfort & Convenience', 'Cruise Control',               'cruise-control',             1,  7),
('Comfort & Convenience', 'Power Steering',               'power-steering',             0,  8),
('Comfort & Convenience', 'Electric Windows',             'electric-windows',           0,  9),
('Comfort & Convenience', 'One-Touch Windows',            'one-touch-windows',          0, 10),
('Comfort & Convenience', 'Auto-Dimming Rearview Mirror', 'auto-dimming-mirror',        0, 11),
('Comfort & Convenience', 'Rain-Sensing Wipers',          'rain-sensing-wipers',        0, 12),
('Comfort & Convenience', 'Automatic Headlights',         'automatic-headlights',       0, 13),
('Comfort & Convenience', 'Follow-Me-Home Headlights',    'follow-me-home-headlights',  0, 14),
('Comfort & Convenience', 'Push Button Start',            'push-button-start',          1, 15),
('Comfort & Convenience', 'Remote Start',                 'remote-start',               0, 16),
('Comfort & Convenience', 'Paddle Shifters',              'paddle-shifters',            0, 17),
('Comfort & Convenience', 'Electric Handbrake',           'electric-handbrake',         0, 18),
('Comfort & Convenience', 'Auto Hold',                    'auto-hold',                  0, 19),
('Comfort & Convenience', 'Heated Steering Wheel',        'heated-steering-wheel',      0, 20),
('Comfort & Convenience', 'Adjustable Steering Wheel',    'adjustable-steering-wheel',  0, 21),
('Comfort & Convenience', 'Reach Adjustable Steering Wheel', 'reach-adj-steering',      0, 22),
('Comfort & Convenience', 'Tilt Adjustable Steering Wheel',  'tilt-adj-steering',       0, 23),
('Comfort & Convenience', 'Multi-Function Steering Wheel','multifunction-steering',     0, 24),
('Comfort & Convenience', 'Ambient Lighting',             'ambient-lighting',           0, 25),
('Comfort & Convenience', 'Wireless Phone Charging',      'wireless-phone-charging',    1, 26),
('Comfort & Convenience', 'Cooled Glovebox',              'cooled-glovebox',            0, 27),
('Comfort & Convenience', 'Rear Window Sunshade',         'rear-window-sunshade',       0, 28),
('Comfort & Convenience', 'Soft-Close Doors',             'soft-close-doors',           0, 29),
('Comfort & Convenience', 'Power Adjustable Pedals',      'power-adj-pedals',           0, 30),
('Comfort & Convenience', 'Power Folding Mirrors',        'power-folding-mirrors',      0, 31),
('Comfort & Convenience', 'Electric Mirrors',             'electric-mirrors',           0, 32),
('Comfort & Convenience', 'Heated Mirrors',               'heated-mirrors',             0, 33),
('Comfort & Convenience', 'Memory Mirrors',               'memory-mirrors',             0, 34),
('Comfort & Convenience', 'Hands-Free Tailgate',          'hands-free-tailgate',        0, 35),
('Comfort & Convenience', 'Power Tailgate',               'power-tailgate',             0, 36),
('Comfort & Convenience', 'Remote Tailgate Release',      'remote-tailgate-release',    0, 37),
('Comfort & Convenience', 'Digital Rearview Mirror',      'digital-rearview-mirror',    0, 38),
('Comfort & Convenience', 'Cabin Air Purifier',           'cabin-air-purifier',         0, 39)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── SEATING ───────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Seating', 'Leather Seats',           'leather-seats',            1,  1),
('Seating', 'Cloth Seats',             'cloth-seats',              0,  2),
('Seating', 'Partial Leather Seats',   'partial-leather-seats',    0,  3),
('Seating', 'Suede Seats',             'suede-seats',              0,  4),
('Seating', 'Sports Seats',            'sports-seats',             0,  5),
('Seating', 'Bucket Seats',            'bucket-seats',             0,  6),
('Seating', 'Heated Front Seats',      'heated-front-seats',       1,  7),
('Seating', 'Heated Rear Seats',       'heated-rear-seats',        0,  8),
('Seating', 'Ventilated Front Seats',  'ventilated-front-seats',   0,  9),
('Seating', 'Ventilated Rear Seats',   'ventilated-rear-seats',    0, 10),
('Seating', 'Electric Driver Seat',    'electric-driver-seat',     0, 11),
('Seating', 'Electric Passenger Seat', 'electric-passenger-seat',  0, 12),
('Seating', 'Memory Driver Seat',      'memory-driver-seat',       0, 13),
('Seating', 'Memory Passenger Seat',   'memory-passenger-seat',    0, 14),
('Seating', 'Massaging Seats',         'massaging-seats',          0, 15),
('Seating', 'Lumbar Support',          'lumbar-support',           0, 16),
('Seating', 'Adjustable Headrests',    'adjustable-headrests',     0, 17),
('Seating', 'Folding Rear Seats',      'folding-rear-seats',       0, 18),
('Seating', 'Split Folding Rear Seats','split-folding-rear-seats', 0, 19),
('Seating', 'Flat Folding Seats',      'flat-folding-seats',       0, 20),
('Seating', 'Third Row Seating',       'third-row-seating',        1, 21),
('Seating', 'Captain Seats',           'captain-seats',            0, 22),
('Seating', 'Center Armrest Front',    'center-armrest-front',     0, 23),
('Seating', 'Center Armrest Rear',     'center-armrest-rear',      0, 24)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── INFOTAINMENT & CONNECTIVITY ───────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Infotainment & Connectivity', 'Touchscreen Display',          'touchscreen-display',       1,  1),
('Infotainment & Connectivity', 'Navigation / GPS',             'navigation-gps',            1,  2),
('Infotainment & Connectivity', 'Apple CarPlay',                'apple-carplay',             1,  3),
('Infotainment & Connectivity', 'Android Auto',                 'android-auto',              1,  4),
('Infotainment & Connectivity', 'Wireless Apple CarPlay',       'wireless-apple-carplay',    0,  5),
('Infotainment & Connectivity', 'Wireless Android Auto',        'wireless-android-auto',     0,  6),
('Infotainment & Connectivity', 'Bluetooth',                    'bluetooth',                 1,  7),
('Infotainment & Connectivity', 'USB Ports',                    'usb-ports',                 0,  8),
('Infotainment & Connectivity', 'USB-C Ports',                  'usb-c-ports',               0,  9),
('Infotainment & Connectivity', 'AUX Input',                    'aux-input',                 0, 10),
('Infotainment & Connectivity', 'Wi-Fi Hotspot',                'wifi-hotspot',              0, 11),
('Infotainment & Connectivity', 'Premium Sound System',         'premium-sound-system',      1, 12),
('Infotainment & Connectivity', 'Subwoofer',                    'subwoofer',                 0, 13),
('Infotainment & Connectivity', 'Voice Control',                'voice-control',             0, 14),
('Infotainment & Connectivity', 'Digital Instrument Cluster',   'digital-instrument-cluster',0, 15),
('Infotainment & Connectivity', 'Head-Up Display',              'head-up-display',           0, 16),
('Infotainment & Connectivity', 'Satellite Radio',              'satellite-radio',           0, 17),
('Infotainment & Connectivity', 'DAB Radio',                    'dab-radio',                 0, 18),
('Infotainment & Connectivity', 'AM/FM Radio',                  'am-fm-radio',               0, 19),
('Infotainment & Connectivity', 'CD Player',                    'cd-player',                 0, 20),
('Infotainment & Connectivity', 'DVD Player',                   'dvd-player',                0, 21),
('Infotainment & Connectivity', 'Rear Entertainment Screens',   'rear-entertainment-screens',0, 22),
('Infotainment & Connectivity', 'Rear Seat Entertainment System','rear-seat-entertainment',  0, 23),
('Infotainment & Connectivity', 'Wireless Charging Pad',        'wireless-charging-pad',     0, 24),
('Infotainment & Connectivity', 'Smartphone Integration',       'smartphone-integration',    0, 25),
('Infotainment & Connectivity', 'Connected Vehicle Services',   'connected-vehicle-services',0, 26),
('Infotainment & Connectivity', 'Over-the-Air Updates',         'ota-updates',               0, 27),
('Infotainment & Connectivity', 'Built-In SIM Connectivity',    'built-in-sim',              0, 28),
('Infotainment & Connectivity', 'Internet Browser',             'internet-browser',          0, 29)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── EXTERIOR ─────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Exterior', 'Alloy Wheels',           'alloy-wheels',          1,  1),
('Exterior', 'Steel Wheels',           'steel-wheels',          0,  2),
('Exterior', 'Chrome Exterior Trim',   'chrome-exterior-trim',  0,  3),
('Exterior', 'Body-Coloured Bumpers',  'body-coloured-bumpers', 0,  4),
('Exterior', 'Fog Lights',             'fog-lights',            0,  5),
('Exterior', 'LED Fog Lights',         'led-fog-lights',        0,  6),
('Exterior', 'Headlight Washers',      'headlight-washers',     0,  7),
('Exterior', 'Sunroof',                'sunroof',               1,  8),
('Exterior', 'Panoramic Roof',         'panoramic-roof',        1,  9),
('Exterior', 'Moonroof',               'moonroof',              0, 10),
('Exterior', 'Convertible Roof',       'convertible-roof',      0, 11),
('Exterior', 'Removable Roof Panels',  'removable-roof-panels', 0, 12),
('Exterior', 'Roof Rails',             'roof-rails',            0, 13),
('Exterior', 'Roof Rack',              'roof-rack',             0, 14),
('Exterior', 'Tow Bar',                'tow-bar',               1, 15),
('Exterior', 'Tow Hitch',              'tow-hitch',             0, 16),
('Exterior', 'Running Boards / Side Steps', 'running-boards',   0, 17),
('Exterior', 'Rear Spoiler',           'rear-spoiler',          0, 18),
('Exterior', 'Front Spoiler',          'front-spoiler',         0, 19),
('Exterior', 'Rear Diffuser',          'rear-diffuser',         0, 20),
('Exterior', 'Tinted Windows',         'tinted-windows',        1, 21),
('Exterior', 'Privacy Glass',          'privacy-glass',         0, 22),
('Exterior', 'Heated Windscreen',      'heated-windscreen',     0, 23),
('Exterior', 'Heated Rear Window',     'heated-rear-window',    0, 24),
('Exterior', 'Rear Wiper',             'rear-wiper',            0, 25),
('Exterior', 'Power Sliding Doors',    'power-sliding-doors',   0, 26)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── PERFORMANCE & DRIVING ─────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Performance & Driving', 'Sport Mode',                  'sport-mode',                 1,  1),
('Performance & Driving', 'Eco Mode',                    'eco-mode',                   0,  2),
('Performance & Driving', 'Comfort Mode',                'comfort-mode',               0,  3),
('Performance & Driving', 'Custom Drive Modes',          'custom-drive-modes',         0,  4),
('Performance & Driving', 'Launch Control',              'launch-control',             0,  5),
('Performance & Driving', 'Active Suspension',           'active-suspension',          0,  6),
('Performance & Driving', 'Adaptive Suspension',         'adaptive-suspension',        0,  7),
('Performance & Driving', 'Air Suspension',              'air-suspension',             0,  8),
('Performance & Driving', 'Sport Suspension',            'sport-suspension',           0,  9),
('Performance & Driving', 'Electronic Differential Lock','electronic-diff-lock',       0, 10),
('Performance & Driving', 'Limited Slip Differential',   'limited-slip-diff',          0, 11),
('Performance & Driving', 'Performance Brakes',          'performance-brakes',         0, 12),
('Performance & Driving', 'Drive Mode Selector',         'drive-mode-selector',        0, 13),
('Performance & Driving', 'Start-Stop System',           'start-stop-system',          0, 14),
('Performance & Driving', 'Cylinder Deactivation',       'cylinder-deactivation',      0, 15)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── OFF-ROAD & UTILITY ────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Off-Road & Utility', 'Low Range Gearbox',           'low-range-gearbox',          1,  1),
('Off-Road & Utility', 'Terrain Response System',     'terrain-response',           1,  2),
('Off-Road & Utility', 'Off-Road Driving Modes',      'off-road-driving-modes',     1,  3),
('Off-Road & Utility', 'Underbody Protection',        'underbody-protection',       0,  4),
('Off-Road & Utility', 'Skid Plates',                 'skid-plates',                0,  5),
('Off-Road & Utility', 'Snorkel',                     'snorkel',                    0,  6),
('Off-Road & Utility', 'Winch',                       'winch',                      0,  7),
('Off-Road & Utility', 'Tow Package',                 'tow-package',                0,  8),
('Off-Road & Utility', 'Trailer Stability Assist',    'trailer-stability-assist',   0,  9),
('Off-Road & Utility', 'Trailer Brake Controller',    'trailer-brake-controller',   0, 10),
('Off-Road & Utility', 'Load Bed Liner',              'load-bed-liner',             0, 11),
('Off-Road & Utility', 'Roller Shutter',              'roller-shutter',             0, 12),
('Off-Road & Utility', 'Canopy',                      'canopy',                     0, 13),
('Off-Road & Utility', 'Cargo Divider',               'cargo-divider',              0, 14),
('Off-Road & Utility', 'Cargo Net',                   'cargo-net',                  0, 15),
('Off-Road & Utility', 'Tie-Down Points',             'tie-down-points',            0, 16),
('Off-Road & Utility', 'Bed Extender',                'bed-extender',               0, 17),
('Off-Road & Utility', 'Integrated Toolbox',          'integrated-toolbox',         0, 18)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── ELECTRIC & HYBRID ─────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Electric & Hybrid', 'Fast Charging',              'fast-charging',              1,  1),
('Electric & Hybrid', 'DC Fast Charging',           'dc-fast-charging',           0,  2),
('Electric & Hybrid', 'AC Charging',                'ac-charging',                0,  3),
('Electric & Hybrid', 'Battery Preconditioning',    'battery-preconditioning',    0,  4),
('Electric & Hybrid', 'Heat Pump',                  'heat-pump',                  0,  5),
('Electric & Hybrid', 'Vehicle-to-Load (V2L)',      'v2l',                        0,  6),
('Electric & Hybrid', 'Vehicle-to-Home (V2H)',      'v2h',                        0,  7),
('Electric & Hybrid', 'Vehicle-to-Grid (V2G)',      'v2g',                        0,  8),
('Electric & Hybrid', 'Charging Cable Included',    'charging-cable-included',    0,  9),
('Electric & Hybrid', 'Portable Charger Included',  'portable-charger-included',  0, 10),
('Electric & Hybrid', 'Battery Health Monitoring',  'battery-health-monitoring',  0, 11),
('Electric & Hybrid', 'Scheduled Charging',         'scheduled-charging',         0, 12),
('Electric & Hybrid', 'Remote Charging Control',    'remote-charging-control',    0, 13),
('Electric & Hybrid', 'Remote Climate Control',     'remote-climate-control',     0, 14)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── LIGHTING ─────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Lighting', 'LED Headlights',         'led-headlights',         1,  1),
('Lighting', 'Matrix LED Headlights',  'matrix-led-headlights',  0,  2),
('Lighting', 'Laser Headlights',       'laser-headlights',       0,  3),
('Lighting', 'Xenon Headlights',       'xenon-headlights',       0,  4),
('Lighting', 'Halogen Headlights',     'halogen-headlights',     0,  5),
('Lighting', 'LED Tail Lights',        'led-tail-lights',        0,  6),
('Lighting', 'Daytime Running Lights', 'daytime-running-lights', 0,  7),
('Lighting', 'Adaptive Headlights',    'adaptive-headlights',    0,  8),
('Lighting', 'Footwell Lighting',      'footwell-lighting',      0,  9),
('Lighting', 'Puddle Lights',          'puddle-lights',          0, 10)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── DRIVER ASSISTANCE (ADAS) ──────────────────────────────────
-- Note: several ADAS features also appear in Safety — that is intentional.
-- The Safety category captures what a car *has*; ADAS groups the active
-- assistance systems together for dealers who want to highlight the suite.
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Driver Assistance (ADAS)', 'Road Edge Detection',    'road-edge-detection',    0,  1),
('Driver Assistance (ADAS)', 'Autonomous Parking',     'autonomous-parking',     0,  2)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);
-- Remaining ADAS features share slugs with Safety — already seeded above.
-- Application layer should union both categories when building "ADAS" UI tab.

-- ── COMMERCIAL VEHICLE ────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Commercial Vehicle', 'Bulkhead',                    'bulkhead',                    0,  1),
('Commercial Vehicle', 'Cargo Partition',             'cargo-partition',             0,  2),
('Commercial Vehicle', 'Cargo Barrier',               'cargo-barrier',               0,  3),
('Commercial Vehicle', 'Refrigerated Cargo Area',     'refrigerated-cargo-area',     0,  4),
('Commercial Vehicle', 'Liftgate',                    'liftgate',                    0,  5),
('Commercial Vehicle', 'Hydraulic Tail Lift',         'hydraulic-tail-lift',         0,  6),
('Commercial Vehicle', 'Dual Rear Wheels',            'dual-rear-wheels',            0,  7),
('Commercial Vehicle', 'Shelving System',             'shelving-system',             0,  8),
('Commercial Vehicle', 'Tool Storage Compartments',   'tool-storage-compartments',   0,  9),
('Commercial Vehicle', 'Cargo Floor Protection',      'cargo-floor-protection',      0, 10),
('Commercial Vehicle', 'Load Area Lighting',          'load-area-lighting',          0, 11),
('Commercial Vehicle', 'Rear Step Bumper',            'rear-step-bumper',            0, 12)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── LUXURY ───────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Luxury', 'Leather Dashboard',          'leather-dashboard',          0,  1),
('Luxury', 'Wood Trim Interior',         'wood-trim-interior',         0,  2),
('Luxury', 'Aluminium Interior Trim',    'aluminium-interior-trim',    0,  3),
('Luxury', 'Premium Leather Upholstery', 'premium-leather-upholstery', 0,  4),
('Luxury', 'Fragrance Diffuser',         'fragrance-diffuser',         0,  5),
('Luxury', 'Power Rear Sunshades',       'power-rear-sunshades',       0,  6),
('Luxury', 'Executive Rear Seating',     'executive-rear-seating',     0,  7),
('Luxury', 'Rear Climate Controls',      'rear-climate-controls',      0,  8)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ── PRACTICAL ────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Practical', '12V Power Outlet',            '12v-power-outlet',         0,  1),
('Practical', '220V / 230V Power Outlet',    '230v-power-outlet',        0,  2),
('Practical', 'Household Power Socket',      'household-power-socket',   0,  3),
('Practical', 'Cup Holders',                 'cup-holders',              0,  4),
('Practical', 'Rear Cup Holders',            'rear-cup-holders',         0,  5),
('Practical', 'Cooled Storage Compartment',  'cooled-storage',           0,  6),
('Practical', 'Storage Bins',                'storage-bins',             0,  7),
('Practical', 'Sunglasses Holder',           'sunglasses-holder',        0,  8),
('Practical', 'Fold-Out Tables',             'fold-out-tables',          0,  9),
('Practical', 'Flat Load Floor',             'flat-load-floor',          0, 10),
('Practical', 'Luggage Net',                 'luggage-net',              0, 11),
('Practical', 'Trailer Hitch',               'trailer-hitch',            0, 12)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- Total features seeded: ~215 unique slugs across 14 categories.
-- is_popular = 1 on ~30 features — review and adjust via:
--   UPDATE car_features SET is_popular = 1 WHERE slug IN (...);

SET FOREIGN_KEY_CHECKS = 1;
