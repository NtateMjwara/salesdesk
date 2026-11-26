-- ============================================================
-- SalesDesk — 0019: Plans & entitlements (plans Phase 1)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
--
-- Two paying account types:
--   broker        — a person (users.id)            Free · Pro (R99/mo)
--   organization  — a business account (orgs.id)   Free · Pro (R3,000/mo) · Enterprise (R12,000/mo)
-- Sales execs inherit their dealership's plan. Prices exclude VAT.
--
-- Tables
--   plans                    the catalogue (price in cents, per month)
--   plan_limits              limit values per plan; NULL = unlimited,
--                            booleans are 0 / 1
--   subscriptions            one current row per account; no row = the
--                            audience's default (Free) plan
--   account_limit_overrides  superadmin custom limits per account
--
-- Limit keys
--   max_desk_cars        broker: cars on their desk
--   max_networks         broker: business networks they can join   (enforced from Phase 2)
--   max_dealerships      business: dealerships under the account    (Phase 2)
--   max_network_brokers  business: brokers in its network           (Phase 2)
--   can_invite_brokers   business: may send invitations (0/1)       (Phase 2)
--   vip_perks            business: network-only stock, bonus (0/1)  (Phase 4)
--
-- Plans are seeded with INSERT IGNORE: re-running never overwrites
-- prices or limits a superadmin has since edited in Admin → Plans.
--
-- Carry-over: today's per-broker car limit (profiles.car_limit) was an
-- admin raise over the old 10-car default. Free is now 150, so only
-- values ABOVE 150 are copied into account_limit_overrides; lower ones
-- are superseded by the new Free limit. profiles.car_limit is left in
-- place but no longer read.
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(40)  NOT NULL UNIQUE COMMENT 'broker_free, broker_pro, business_free, …',
    audience         ENUM('broker','organization') NOT NULL,
    name             VARCHAR(60)  NOT NULL,
    tagline          VARCHAR(160) NULL DEFAULT NULL,
    price_cents      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Per billing interval, excl. VAT',
    billing_interval ENUM('month','year') NOT NULL DEFAULT 'month',
    is_default       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Plan for accounts with no subscription (one per audience)',
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order       SMALLINT     NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_plans_audience (audience, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plan catalogue (plans Phase 1)';

CREATE TABLE IF NOT EXISTS plan_limits (
    plan_id     INT UNSIGNED NOT NULL,
    limit_key   VARCHAR(40)  NOT NULL,
    limit_value INT          NULL DEFAULT NULL COMMENT 'NULL = unlimited; booleans 0/1',
    PRIMARY KEY (plan_id, limit_key),
    CONSTRAINT fk_pl_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Limit values per plan';

CREATE TABLE IF NOT EXISTS subscriptions (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_type       ENUM('broker','organization') NOT NULL,
    account_id         INT UNSIGNED NOT NULL COMMENT 'users.id for brokers, organizations.id for businesses',
    plan_id            INT UNSIGNED NOT NULL,
    status             ENUM('active','comped','past_due','cancelled') NOT NULL DEFAULT 'active'
                           COMMENT 'active = paying (manually until billing ships), comped = free of charge',
    started_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    current_period_end DATETIME     NULL DEFAULT NULL COMMENT 'NULL = open-ended; after this the account falls back to Free',
    provider           VARCHAR(30)  NULL DEFAULT NULL COMMENT 'manual | payfast | paystack … (Phase 3)',
    provider_ref       VARCHAR(120) NULL DEFAULT NULL,
    note               VARCHAR(255) NULL DEFAULT NULL,
    updated_by         INT UNSIGNED NULL DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sub_account (account_type, account_id),
    CONSTRAINT fk_sub_plan    FOREIGN KEY (plan_id)    REFERENCES plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sub_plan (plan_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Current plan per account. History is in audit_logs (plan.*).';

CREATE TABLE IF NOT EXISTS account_limit_overrides (
    account_type ENUM('broker','organization') NOT NULL,
    account_id   INT UNSIGNED NOT NULL,
    limit_key    VARCHAR(40)  NOT NULL,
    limit_value  INT          NULL DEFAULT NULL COMMENT 'NULL = unlimited',
    reason       VARCHAR(255) NULL DEFAULT NULL,
    set_by       INT UNSIGNED NULL DEFAULT NULL,
    set_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_type, account_id, limit_key),
    CONSTRAINT fk_alo_setter FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Superadmin custom limits; beat the plan value';

-- ── Seed the catalogue (never overwrites later edits) ─────────
INSERT IGNORE INTO plans (code, audience, name, tagline, price_cents, is_default, sort_order) VALUES
    ('broker_free',         'broker',       'Free',       'Start selling with up to 150 cars on your desk.',  0,       1, 10),
    ('broker_pro',          'broker',       'Pro',        'Unlimited cars and networks.',                      9900,    0, 20),
    ('business_free',       'organization', 'Free',       'One dealership and a network of up to 5 brokers.', 0,       1, 10),
    ('business_pro',        'organization', 'Pro',        'Grow a network of up to 100 brokers.',              300000,  0, 20),
    ('business_enterprise', 'organization', 'Enterprise', 'Multiple dealerships and an unlimited network.',    1200000, 0, 30);

INSERT IGNORE INTO plan_limits (plan_id, limit_key, limit_value)
SELECT p.id, v.k, v.val
FROM plans p
JOIN (
              SELECT 'broker_free' AS code, 'max_desk_cars' AS k, 150 AS val
    UNION ALL SELECT 'broker_free',         'max_networks',        3
    UNION ALL SELECT 'broker_pro',          'max_desk_cars',       NULL
    UNION ALL SELECT 'broker_pro',          'max_networks',        NULL
    UNION ALL SELECT 'business_free',       'max_dealerships',     1
    UNION ALL SELECT 'business_free',       'max_network_brokers', 5
    UNION ALL SELECT 'business_free',       'can_invite_brokers',  0
    UNION ALL SELECT 'business_free',       'vip_perks',           0
    UNION ALL SELECT 'business_pro',        'max_dealerships',     1
    UNION ALL SELECT 'business_pro',        'max_network_brokers', 100
    UNION ALL SELECT 'business_pro',        'can_invite_brokers',  1
    UNION ALL SELECT 'business_pro',        'vip_perks',           1
    UNION ALL SELECT 'business_enterprise', 'max_dealerships',     NULL
    UNION ALL SELECT 'business_enterprise', 'max_network_brokers', NULL
    UNION ALL SELECT 'business_enterprise', 'can_invite_brokers',  1
    UNION ALL SELECT 'business_enterprise', 'vip_perks',           1
) v ON v.code = p.code;

-- ── Carry over per-broker car limits above the new Free limit ─
INSERT IGNORE INTO account_limit_overrides (account_type, account_id, limit_key, limit_value, reason, set_at)
SELECT 'broker', p.user_id, 'max_desk_cars', p.car_limit,
       'Carried over from the old per-broker car limit (0019)', NOW()
FROM profiles p
JOIN users u ON u.id = p.user_id AND u.role = 'broker'
WHERE p.car_limit IS NOT NULL AND p.car_limit > 150;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0019_plans');
SELECT CONCAT('0019 applied — ', (SELECT COUNT(*) FROM plans), ' plans, ',
              (SELECT COUNT(*) FROM account_limit_overrides), ' limit override(s) carried over') AS result;
