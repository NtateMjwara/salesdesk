-- ============================================================
-- Migration 0004 — Composite & Performance Indexes
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Run AFTER: 0002_initial_schema.sql + 0003_sales_executives.sql
-- Command:   mysql -u salesdesk_user -p salesdesk_db < 0004_indexes.sql
--
-- Rationale:
--   The initial schema created single-column FK indexes.
--   These composite indexes cover the specific query patterns
--   that will full-scan under production load:
--
--   1. leads(buyer_phone, car_id)
--      Duplicate-detection query on every lead submit.
--      Without this: full table scan on leads at peak.
--
--   2. otp_codes(user_id, purpose, used, expires_at)
--      verifyOTP() hits all four columns in one query.
--      The existing idx_otp_user(user_id) only helps partially.
--
--   3. broker_inventory(tracking_code)
--      Every link click resolves a tracking_code.
--      Already UNIQUE in schema but verify the index name here.
--
--   4. cars(status, dealer_id)
--      Dealer inventory list: WHERE status='active' AND dealer_id=?
--      Broker marketplace: WHERE status='active' ORDER BY...
--
--   5. sales_executives(dealer_id, verification_status)
--      Team management screen + exec feature-gating on every page load.
--
--   6. leads(status, dealer_id)
--      Dealer leads pipeline list — filtered by status.
--
--   7. leads(broker_id, status)
--      Broker's own leads screen.
--
--   8. commissions(status, dealer_id)
--      Admin payout dashboard + dealer commission view.
--
--   9. notifications(user_id, is_read)
--      Unread badge count on every authenticated page load.
--      (Already in schema as idx_notif_unread — confirmed here.)
--
--  10. audit_logs(entity_type, entity_id, created_at)
--      Admin audit trail queries always filter by entity + date range.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. Duplicate-lead detection ──────────────────────────────
-- Query: SELECT id FROM leads WHERE buyer_phone=? AND car_id=? AND created_at > ?
ALTER TABLE leads
    ADD INDEX IF NOT EXISTS idx_lead_dedup (buyer_phone, car_id, created_at);

-- ── 2. OTP verification ──────────────────────────────────────
-- Query: SELECT id, code_hash FROM otp_codes
--        WHERE user_id=? AND purpose=? AND used=0 AND expires_at > NOW()
-- DROP the narrow single-column index and replace with the composite.
-- (The FK index idx_otp_user stays; we add the covering composite.)
ALTER TABLE otp_codes
    ADD INDEX IF NOT EXISTS idx_otp_verify (user_id, purpose, used, expires_at);

-- ── 3. Tracking code resolution ──────────────────────────────
-- Already declared UNIQUE KEY in 0002 which creates an index.
-- Confirm with explicit name for clarity; IF NOT EXISTS guards duplicates.
ALTER TABLE broker_inventory
    ADD INDEX IF NOT EXISTS idx_binv_tracking_lookup (tracking_code);

-- ── 4a. Dealer inventory list ────────────────────────────────
-- Query: WHERE dealer_id=? AND status IN (...)  OR  WHERE dealer_id=? ORDER BY created_at
ALTER TABLE cars
    ADD INDEX IF NOT EXISTS idx_car_dealer_status (dealer_id, status);

-- ── 4b. Broker marketplace ───────────────────────────────────
-- Query: WHERE status='active' ORDER BY commission_value DESC, created_at DESC
ALTER TABLE cars
    ADD INDEX IF NOT EXISTS idx_car_active_commission (status, commission_value);

-- ── 5. Exec team management + feature gating ─────────────────
-- Query: WHERE dealer_id=? AND verification_status=?
--        (team screen, pending queue, verified roster)
ALTER TABLE sales_executives
    ADD INDEX IF NOT EXISTS idx_sexec_dealer_status (dealer_id, verification_status);

-- ── 6. Dealer leads pipeline ─────────────────────────────────
-- Query: WHERE dealer_id=? AND status=?  ORDER BY created_at DESC
ALTER TABLE leads
    ADD INDEX IF NOT EXISTS idx_lead_dealer_status (dealer_id, status, created_at);

-- ── 7. Broker leads screen ───────────────────────────────────
-- Query: WHERE broker_id=? ORDER BY created_at DESC
ALTER TABLE leads
    ADD INDEX IF NOT EXISTS idx_lead_broker_created (broker_id, created_at);

-- ── 8. Commission dashboard ──────────────────────────────────
-- Admin: WHERE status=?   Dealer: WHERE dealer_id=? AND status=?
ALTER TABLE commissions
    ADD INDEX IF NOT EXISTS idx_comm_dealer_status (dealer_id, status);

-- ── 9. Notification unread badge ─────────────────────────────
-- Already added in 0002 as idx_notif_unread(user_id, is_read).
-- Confirm; IF NOT EXISTS is a no-op if it exists.
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_user_unread (user_id, is_read, created_at);

-- ── 10. Audit log range queries ──────────────────────────────
-- Query: WHERE entity_type=? AND entity_id=? ORDER BY created_at DESC
ALTER TABLE audit_logs
    ADD INDEX IF NOT EXISTS idx_audit_entity_date (entity_type, entity_id, created_at);

-- ── 11. Payouts by status ────────────────────────────────────
-- Admin payout dashboard: WHERE status='scheduled' OR status='failed'
ALTER TABLE payouts
    ADD INDEX IF NOT EXISTS idx_payout_status_scheduled (status, scheduled_at);

-- ── 12. Nudge check cron ─────────────────────────────────────
-- Cron: JOIN leads ON ... WHERE l.status NOT IN ('closed','lost')
--       AND l.status_updated_at < (NOW() - threshold)
ALTER TABLE leads
    ADD INDEX IF NOT EXISTS idx_lead_status_updated (status, status_updated_at);

-- ── 13. POPIA scrub cron ─────────────────────────────────────
-- Cron: WHERE created_at < DATE_SUB(NOW(), INTERVAL N DAY)
--       AND buyer_name IS NOT NULL   (skip already-scrubbed rows)
ALTER TABLE leads
    ADD INDEX IF NOT EXISTS idx_lead_scrub (created_at, buyer_name(10));

-- ── 14. API rate limit lookups ───────────────────────────────
-- Requires the api_rate_limits table from 0005_addenda.sql.
-- Index defined there alongside the table.

SET FOREIGN_KEY_CHECKS = 1;
