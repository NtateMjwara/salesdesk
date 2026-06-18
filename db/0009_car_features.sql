-- ============================================================
-- Migration 0004 — Car Features
-- Stack: PHP + PDO + MySQL / MariaDB
--
-- Rationale:
--   Features (ABS, Apple CarPlay, Sunroof, etc.) are a controlled
--   vocabulary that buyers will filter on. Storing them as a JSON
--   array on cars (like image_urls) would make WHERE-clause filtering
--   slow and unindexable. A proper catalogue + junction table gives us:
--     • Indexed, fast filtering on the browse page
--     • Feature counts across inventory (e.g. "42 cars have Sunroof")
--     • A path to mark popular/promoted filter chips without touching
--       car records
--     • Clean data — no free-text drift or duplicate spellings
--
--   The JS file (sa-car-features.js) is the UI layer for the upload
--   wizard. This migration is the source of truth — the JS is seeded
--   from these INSERT statements and should be kept in sync.
--
-- Tables added:
--   1. car_features       — master feature catalogue
--   2. car_feature_links  — junction: cars ↔ features (M:N)
--
-- No changes to existing tables.
--
-- Run after:  0003_sales_executives.sql
-- Command:    mysql -u salesdesk_user -p salesdesk_db < 0004_car_features.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- 1. CAR_FEATURES  — master feature catalogue
--
--    category:    matches SA_CAR_FEATURES.categories in sa-car-features.js
--    name:        the display label, unique within its category
--    slug:        URL/filter-safe key — generated from name on insert
--                 e.g. "Apple CarPlay" → "apple-carplay"
--                 Used as the ?features[]= query param on the browse page.
--    is_popular:  flag for surface-level filter chips (top ~12 features
--                 shown without expanding the full list). Admin-editable.
--    sort_order:  controls display order within a category. Defaults to
--                 insertion order (0) — admin can reorder without re-seeding.
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
-- 2. CAR_FEATURE_LINKS  — junction table (cars ↔ car_features)
--
--    Lean join table — no extra columns needed beyond the FK pair.
--    ON DELETE CASCADE on both sides: removing a car or retiring a
--    feature cleans up links automatically.
--
--    The PRIMARY KEY is the composite (car_id, feature_id) — queries
--    that ask "which features does car X have?" hit it directly.
--    The reverse index idx_link_feature covers "which cars have
--    feature Y?" for browse-page filtering.
-- ============================================================
CREATE TABLE IF NOT EXISTS car_feature_links (
    car_id      INT UNSIGNED NOT NULL
                    COMMENT 'FK → cars.id',
    feature_id  INT UNSIGNED NOT NULL
                    COMMENT 'FK → car_features.id',

    PRIMARY KEY (car_id, feature_id),

    CONSTRAINT fk_cfl_car     FOREIGN KEY (car_id)     REFERENCES cars(id)          ON DELETE CASCADE,
    CONSTRAINT fk_cfl_feature FOREIGN KEY (feature_id) REFERENCES car_features(id)  ON DELETE CASCADE,

    INDEX idx_link_feature (feature_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='M:N junction — which features a car listing has.';


-- ============================================================
-- 3. SEED  — feature catalogue
--    Matches sa-car-features.js exactly.
--    Slugs are lowercase-hyphenated, unique across all categories.
--    is_popular = 1 on the ~16 features most useful as browse chips.
--    ON DUPLICATE KEY UPDATE is a no-op guard for re-runs.
-- ============================================================

-- ── SAFETY ───────────────────────────────────────────────────
INSERT INTO car_features (category, name, slug, is_popular, sort_order) VALUES
('Safety', 'Airbags (Front)',                         'airbags-front',                    0,  1),
('Safety', 'Side Airbags',                            'side-airbags',                     0,  2),
('Safety', 'Curtain Airbags',                         'curtain-airbags',                  0,  3),
('Safety', 'Knee Airbags',                            'knee-airbags',                     0,  4),
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
('Safety', 'Tire Pressure Monitoring System (TPMS)', 'tpms',                             0, 28),
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

-- ============================================================
-- Done.
-- Total features seeded: ~215 unique slugs across 14 categories.
-- is_popular = 1 on ~30 features — review and adjust via:
--   UPDATE car_features SET is_popular = 1 WHERE slug IN (...);
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;
