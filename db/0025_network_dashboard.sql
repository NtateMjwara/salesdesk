-- ============================================================
-- SalesDesk — 0025: VIP network perks, part 2 (plans Phase 4b)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
-- Needs 0020 (business orgs). Code: includes/network_dashboard.php.
--
-- For Pro and Enterprise businesses (plan limit vip_perks):
--   • ANNOUNCEMENTS — the business posts short updates to its verified
--     network brokers (in-app notification, optional email). Brokers
--     read them on My networks.
--   • BUSINESS DASHBOARD — per-broker views, leads, sales and
--     commission on the business's cars. Read from existing tables
--     (car_views, leads, commissions); nothing new is stored.
--   • NETWORK BADGE — on the broker's public desk and on the network
--     page. Presentation only; nothing new is stored.
-- ============================================================

CREATE TABLE IF NOT EXISTS `network_announcements` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organization_id` INT UNSIGNED NOT NULL,
  `author_user_id`  INT UNSIGNED NULL,
  `title`           VARCHAR(120) NOT NULL,
  `body`            TEXT         NOT NULL,
  `recipients`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Verified brokers when posted',
  `emailed`         TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_org` (`organization_id`, `created_at`),
  CONSTRAINT `fk_ann_org`    FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ann_author` FOREIGN KEY (`author_user_id`)  REFERENCES `users` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Plans 4b: a business''s announcements to its broker network';

INSERT IGNORE INTO schema_migrations (name) VALUES ('0025_network_dashboard');
SELECT '0025 applied — network announcements, business dashboard and badges ready' AS result;
