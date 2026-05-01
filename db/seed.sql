-- ============================================================
-- SalesDesk — Development Seed Data
-- Run AFTER: all migrations (0002 through 0005)
-- Command:   mysql -u salesdesk_user -p salesdesk_db < seed.sql
--
-- All accounts use password: Password1!
-- Password hash below is bcrypt of "Password1!"
--
-- Accounts created:
--   admin@salesdesk.co.za         role=admin
--   broker1@example.com           role=broker  (solo, org member)
--   broker2@example.com           role=broker  (solo)
--   dealer@cardealers.co.za       role=dealer  (dealer principal)
--   exec1@cardealers.co.za        role=sales_exec  (verified)
--   exec2@cardealers.co.za        role=sales_exec  (pending)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Truncate in dependency order ──────────────────────────────
TRUNCATE TABLE popia_audit;
TRUNCATE TABLE nudges;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE notifications;
TRUNCATE TABLE payouts;
TRUNCATE TABLE bank_accounts;
TRUNCATE TABLE commissions;
TRUNCATE TABLE leads;
TRUNCATE TABLE broker_inventory;
TRUNCATE TABLE cars;
TRUNCATE TABLE sales_executives;
TRUNCATE TABLE organization_members;
TRUNCATE TABLE organizations;
TRUNCATE TABLE salesdesks;
TRUNCATE TABLE dealers;
TRUNCATE TABLE otp_codes;
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE profiles;
TRUNCATE TABLE addresses;
TRUNCATE TABLE users;

-- ── Reset auto_increment ──────────────────────────────────────
ALTER TABLE users              AUTO_INCREMENT = 1;
ALTER TABLE profiles           AUTO_INCREMENT = 1;
ALTER TABLE addresses          AUTO_INCREMENT = 1;
ALTER TABLE salesdesks         AUTO_INCREMENT = 1;
ALTER TABLE dealers            AUTO_INCREMENT = 1;
ALTER TABLE organizations      AUTO_INCREMENT = 1;
ALTER TABLE organization_members AUTO_INCREMENT = 1;
ALTER TABLE sales_executives   AUTO_INCREMENT = 1;
ALTER TABLE cars               AUTO_INCREMENT = 1;
ALTER TABLE broker_inventory   AUTO_INCREMENT = 1;
ALTER TABLE leads              AUTO_INCREMENT = 1;
ALTER TABLE commissions        AUTO_INCREMENT = 1;
ALTER TABLE bank_accounts      AUTO_INCREMENT = 1;
ALTER TABLE notifications      AUTO_INCREMENT = 1;

-- ============================================================
-- ADDRESSES
-- ============================================================
INSERT INTO addresses (id, province, municipality, city, suburb, street_line1, postal_code) VALUES
(1, 'Gauteng', 'City of Johannesburg', 'Sandton', 'Morningside', '12 Rivonia Road', '2196'),
(2, 'Gauteng', 'City of Johannesburg', 'Randburg', 'Ferndale',   '88 Oxford Road',  '2194'),
(3, 'Western Cape', 'City of Cape Town', 'Cape Town', 'Green Point', '5 Somerset Road', '8005'),
(4, 'Gauteng', 'Ekurhuleni', 'Boksburg', 'Boksburg North', '20 Commissioner Street', '1459'),
(5, 'KwaZulu-Natal', 'eThekwini', 'Durban', 'Umhlanga', '33 Lighthouse Road', '4320');

-- ============================================================
-- USERS  (password hash = bcrypt of "Password1!")
-- ============================================================
INSERT INTO users (id, uuid, email, role, status, password_hash, email_verified, last_login, created_at, updated_at) VALUES
(1, '11111111-0000-4000-a000-000000000001', 'admin@salesdesk.co.za',   'admin',      'active', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NOW(), NOW(), NOW()),
(2, '22222222-0000-4000-a000-000000000002', 'broker1@example.com',     'broker',     'active', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NOW(), NOW(), NOW()),
(3, '33333333-0000-4000-a000-000000000003', 'broker2@example.com',     'broker',     'active', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NULL, NOW(), NOW()),
(4, '44444444-0000-4000-a000-000000000004', 'dealer@cardealers.co.za', 'dealer',     'active', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NOW(), NOW(), NOW()),
(5, '55555555-0000-4000-a000-000000000005', 'exec1@cardealers.co.za',  'sales_exec', 'active', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NOW(), NOW(), NOW()),
(6, '66666666-0000-4000-a000-000000000006', 'exec2@cardealers.co.za',  'sales_exec', 'active', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NULL, NOW(), NOW());

-- ============================================================
-- PROFILES
-- ============================================================
INSERT INTO profiles (id, user_id, first_name, last_name, phone, bio, address_id, car_limit, onboarding_step, onboarding_completed, created_at, updated_at) VALUES
(1, 1, 'Admin',   'SalesDesk',  '010 000 0001', 'Platform administrator.',            1, NULL, 99, 1, NOW(), NOW()),
(2, 2, 'Sipho',   'Dlamini',    '082 111 2222', 'Passionate auto broker, Sandton.',   1, NULL, 99, 1, NOW(), NOW()),
(3, 3, 'Naledi',  'Mokoena',    '083 333 4444', 'New broker, Cape Town area.',        3, 5,    99, 1, NOW(), NOW()),
(4, 4, 'Thabo',   'Nkosi',      '011 999 8888', 'Dealer principal, Cars & More.',     NULL, NULL, 99, 1, NOW(), NOW()),
(5, 5, 'Zanele',  'Khumalo',    '084 555 6666', 'Senior sales executive.',            2, NULL, 99, 1, NOW(), NOW()),
(6, 6, 'Lungelo', 'Zulu',       '085 777 8888', 'Sales executive, applying to join.', 2, NULL, 99, 1, NOW(), NOW());

-- Note: dealer profile has no address_id — dealer address is on dealers.address_id

-- ============================================================
-- SALESDESKS (brokers only)
-- ============================================================
INSERT INTO salesdesks (id, uuid, user_id, slug, display_name, tagline, primary_colour, is_active, created_at, updated_at) VALUES
(1, 'aaaa1111-0000-4000-a000-000000000001', 2, 'sipho-d',   'Sipho\'s SalesDesk',  'Your trusted Gauteng car broker',    '#0f4c9e', 1, NOW(), NOW()),
(2, 'aaaa2222-0000-4000-a000-000000000002', 3, 'naledi-m',  'Naledi\'s Auto Desk', 'Cape Town\'s best car deals',        '#0f4c9e', 1, NOW(), NOW());

-- ============================================================
-- DEALERS
-- ============================================================
INSERT INTO dealers (id, uuid, user_id, company_name, slug, address_id, brand_focus, verification_status, cipc_doc_url, verified_at, is_active, created_at, updated_at) VALUES
(1, 'bbbb1111-0000-4000-a000-000000000001', 4, 'Cars & More Boksburg', 'cars-and-more-boksburg', 4, '["Toyota","Ford","VW"]', 'verified', '/uploads/cipc/bbbb1111.pdf', DATE_SUB(NOW(), INTERVAL 30 DAY), 1, NOW(), NOW());

-- ============================================================
-- ORGANIZATIONS (broker team)
-- ============================================================
INSERT INTO organizations (id, uuid, name, slug, owner_user_id, address_id, verification_status, is_active, created_at, updated_at) VALUES
(1, 'cccc1111-0000-4000-a000-000000000001', 'Gauteng Auto Brokers', 'gauteng-auto-brokers', 2, 1, 'verified', 1, NOW(), NOW());

INSERT INTO organization_members (id, organization_id, user_id, role, joined_at) VALUES
(1, 1, 2, 'owner', NOW()),
(2, 1, 3, 'agent', NOW());

-- ============================================================
-- SALES EXECUTIVES
-- ============================================================
INSERT INTO sales_executives (id, user_id, dealer_id, job_title, verification_status, verified_by, verified_at, created_at, updated_at) VALUES
(1, 5, 1, 'Senior Sales Executive', 'verified', 4, DATE_SUB(NOW(), INTERVAL 14 DAY), NOW(), NOW()),
(2, 6, 1, 'Sales Executive',        'pending',  NULL, NULL, NOW(), NOW());

-- ============================================================
-- CARS  (dealer_id=1, mix of uploaded by principal and exec)
-- ============================================================
INSERT INTO cars (id, uuid, dealer_id, uploaded_by_exec_id, slug, make, model, year, price, mileage, condition_type, body_type, colour, transmission, fuel_type, commission_type, commission_value, image_urls, status, created_at, updated_at) VALUES
(1, 'dddd1111-0000-4000-a000-000000000001', 1, NULL, '2022-toyota-corolla-cross-white',   'Toyota', 'Corolla Cross', 2022, 349900.00, 28000, 'used',  'SUV',     'White',  'Automatic', 'Petrol', 'fixed',      8000.00, '[]', 'active', NOW(), NOW()),
(2, 'dddd2222-0000-4000-a000-000000000002', 1, NULL, '2021-ford-ranger-silver',            'Ford',   'Ranger',        2021, 459900.00, 45000, 'used',  'Bakkie',  'Silver', 'Manual',    'Diesel', 'percentage', 2.50,    '[]', 'active', NOW(), NOW()),
(3, 'dddd3333-0000-4000-a000-000000000003', 1, 1,    '2023-vw-polo-vivo-red',              'VW',     'Polo Vivo',     2023, 249900.00,  5000, 'demo',  'Hatch',   'Red',    'Manual',    'Petrol', 'fixed',      6000.00, '[]', 'active', NOW(), NOW()),
(4, 'dddd4444-0000-4000-a000-000000000004', 1, 1,    '2020-toyota-hilux-gun8-white',       'Toyota', 'Hilux GUN8',    2020, 529900.00, 72000, 'used',  'Bakkie',  'White',  'Automatic', 'Diesel', 'percentage', 2.00,    '[]', 'paused', NOW(), NOW()),
(5, 'dddd5555-0000-4000-a000-000000000005', 1, NULL, '2023-ford-ecosport-blue-demo',       'Ford',   'EcoSport',      2023, 299900.00,  8500, 'demo',  'SUV',     'Blue',   'Automatic', 'Petrol', 'fixed',      7500.00, '[]', 'sold',   DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY));

UPDATE cars SET sold_at = DATE_SUB(NOW(), INTERVAL 7 DAY) WHERE id = 5;

-- ============================================================
-- BROKER INVENTORY  (tracking links)
-- ============================================================
INSERT INTO broker_inventory (id, salesdesk_id, car_id, tracking_code, views, added_at) VALUES
(1, 1, 1, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 47, NOW()),
(2, 1, 2, 'b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7', 23, NOW()),
(3, 1, 3, 'c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8', 12, NOW()),
(4, 2, 1, 'd4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9', 8,  NOW()),
(5, 2, 2, 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0', 3,  NOW());

-- ============================================================
-- LEADS
-- Covers: new, contacted, closed, lost pipeline states.
-- Lead 3 is the closed one that will have a commission.
-- ============================================================
INSERT INTO leads (id, uuid, broker_id, salesdesk_id, organization_id, car_id, dealer_id, source_tracking_code, attribution_locked, attributed_at, buyer_name, buyer_phone, buyer_email, buyer_intent, buyer_message, consent_given, consent_at, status, dealer_notes, status_updated_at, created_at, updated_at) VALUES
(1, 'eeee1111-0000-4000-a000-000000000001', 2, 1, 1, 1, 1, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 1, NOW(), 'James Sithole',   '071 111 2222', 'james.s@email.com',  'within_30d', 'Very interested, saw the car on Sipho\'s link.', 1, NOW(), 'new',         NULL, NOW(), NOW(), NOW()),
(2, 'eeee2222-0000-4000-a000-000000000002', 2, 1, 1, 2, 1, 'b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7', 1, NOW(), 'Priya Pillay',    '082 222 3333', 'priya.p@email.com',  'one_to_3mo', 'Interested in the Ranger, need finance first.', 1, NOW(), 'contacted',   'Called client, finance in process.', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), NOW()),
(3, 'eeee3333-0000-4000-a000-000000000003', 2, 1, 1, 5, 1, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', 1, DATE_SUB(NOW(), INTERVAL 14 DAY), 'Michael Adams', '073 333 4444', 'michael.a@email.com', 'within_30d', 'Want to buy immediately, cash deal.', 1, DATE_SUB(NOW(), INTERVAL 14 DAY), 'closed', 'Cash deal done, signed papers 2025-04-08. Commission owed.', DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY)),
(4, 'eeee4444-0000-4000-a000-000000000004', 3, 2, 1, 1, 1, 'd4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9', 1, NOW(), 'Sarah Johnson',   '084 444 5555', 'sarah.j@email.com',  'browsing',   'Just browsing, will decide in a month.', 1, NOW(), 'new',         NULL, NOW(), NOW(), NOW()),
(5, 'eeee5555-0000-4000-a000-000000000005', 2, 1, 1, 3, 1, 'c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8', 1, DATE_SUB(NOW(), INTERVAL 20 DAY), 'Ayanda Nkosi', '085 555 6666', NULL, 'browsing', 'Saw Polo Vivo, asked for test drive.', 1, DATE_SUB(NOW(), INTERVAL 20 DAY), 'lost', 'Client went elsewhere — bought another brand.', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY));

-- ============================================================
-- COMMISSIONS (lead 3 = closed EcoSport deal)
-- gross = R7,500 fixed commission on EcoSport
-- platform fee = 10% = R750
-- net = R6,750
-- ============================================================
INSERT INTO commissions (id, uuid, lead_id, broker_id, organization_id, dealer_id, gross_amount, platform_fee, net_amount, status, approved_at, paid_at, created_at, updated_at) VALUES
(1, 'ffff1111-0000-4000-a000-000000000001', 3, 2, 1, 1, 7500.00, 750.00, 6750.00, 'paid', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY));

-- ============================================================
-- BANK ACCOUNTS
-- ============================================================
INSERT INTO bank_accounts (id, user_id, organization_id, account_holder, bank_name, account_number, branch_code, account_type, is_verified, is_primary, created_at) VALUES
(1, 2, 1, 'Gauteng Auto Brokers', 'FNB', '62123456789', '250655', 'cheque', 1, 1, NOW()),
(2, 4, NULL, 'Cars and More Boksburg Pty Ltd', 'Standard Bank', '01234567', '051001', 'cheque', 1, 1, NOW());

-- ============================================================
-- PAYOUTS (for the paid commission)
-- ============================================================
INSERT INTO payouts (id, uuid, commission_id, broker_id, organization_id, bank_account_id, amount, status, reference_number, idempotency_key, scheduled_at, processed_at, created_at) VALUES
(1, 'gggg1111-0000-4000-a000-000000000001', 1, 2, 1, 1, 6750.00, 'paid', 'EFT-2025-0001', 'idem-comm-1-broker-2-20250423', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY));

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
INSERT INTO notifications (id, user_id, type, title, body, is_read, created_at) VALUES
(1, 2, 'new_lead',          'New lead on Corolla Cross',      'James Sithole submitted a lead via your tracking link.', 0, NOW()),
(2, 4, 'new_lead',          'New lead received',              'James Sithole is interested in the 2022 Corolla Cross.',  0, NOW()),
(3, 2, 'payout_scheduled',  'Payout scheduled',               'Your commission of R6,750.00 has been scheduled.',        1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4, 2, 'payout_paid',       'Commission paid — R6,750.00',    'EFT reference EFT-2025-0001 processed successfully.',     1, DATE_SUB(NOW(), INTERVAL 4 DAY));

-- ============================================================
-- NUDGES (test the cron system)
-- Lead 2 was contacted 3 days ago — 48h nudge already fired.
-- ============================================================
INSERT INTO nudges (lead_id, dealer_id, nudge_type, sent_at) VALUES
(2, 1, '48h', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ============================================================
-- AUDIT LOGS (key events)
-- ============================================================
INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, after_data, ip_address, created_at) VALUES
(4, 'lead.status_changed',     'lead',        3, '{"status":"closed"}',     '127.0.0.1', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(4, 'commission.created',      'commission',  1, '{"status":"pending"}',    '127.0.0.1', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1, 'commission.approved',     'commission',  1, '{"status":"approved"}',   '127.0.0.1', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1, 'payout.paid',             'payout',      1, '{"status":"paid","ref":"EFT-2025-0001"}', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 'sales_exec.verified',     'sales_executive', 1, '{"verification_status":"verified"}', '127.0.0.1', DATE_SUB(NOW(), INTERVAL 14 DAY));

SET FOREIGN_KEY_CHECKS = 1;

-- Verification
SELECT 'Seed complete.' AS status;
SELECT role, COUNT(*) AS count FROM users GROUP BY role;
SELECT 'Cars' AS entity, COUNT(*) AS count FROM cars
UNION ALL SELECT 'Leads', COUNT(*) FROM leads
UNION ALL SELECT 'Commissions', COUNT(*) FROM commissions
UNION ALL SELECT 'Notifications', COUNT(*) FROM notifications;
