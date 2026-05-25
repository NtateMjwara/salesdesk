<?php
/**
 * SalesDesk — Configuration template.
 *
 * SETUP INSTRUCTIONS:
 *   1. Copy this file to config.php  (one level above web root in production)
 *   2. Fill in every value marked  <-- CHANGE
 *   3. Never commit config.php to version control
 *
 * In production, place config.php OUTSIDE public_html:
 *   /var/www/salesdesk/config.php          ← live config
 *   /var/www/salesdesk/public_html/        ← web root
 *
 * All includes/ files use:  require_once __DIR__ . '/config.php';
 * Make sure BASE_PATH resolves correctly for your layout.
 */

// ============================================================
// DATABASE — MySQL / MariaDB via PDO
// ============================================================
define('DB_HOST',    'localhost');                    // <-- CHANGE if remote
define('DB_NAME',    'salesdesk_db');                // <-- CHANGE
define('DB_USER',    'salesdesk_user');              // <-- CHANGE
define('DB_PASS',    'STRONG_DB_PASSWORD_HERE');     // <-- CHANGE
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// SITE
// ============================================================
define('SITE_URL',  'https://salesdesk.co.za');   // No trailing slash  <-- CHANGE
define('APP_NAME',  'SalesDesk');
define('CURRENCY',  'ZAR');

// ============================================================
// SECURITY
// ============================================================
define('PASSWORD_ALGO',       PASSWORD_ARGON2ID);
define('CSRF_TOKEN_NAME',     'csrf_token');
define('SESSION_TIMEOUT',     3600);               // seconds — 60 min
define('RATE_LIMIT_ATTEMPTS', 10);                 // max login failures
define('RATE_LIMIT_WINDOW',   900);                // seconds — 15 min window
define('LOCKOUT_DURATION',    900);                // seconds — 15 min lockout
define('OTP_EXPIRY_SECONDS',  600);                // seconds — 10 min
define('OTP_LENGTH',          6);                  // digits

// ============================================================
// EMAIL — PHPMailer / SMTP
// ============================================================
define('SMTP_HOST',      'smtp.example.com');      // <-- CHANGE
define('SMTP_PORT',      587);                     // 587 for STARTTLS, 465 for SSL
define('SMTP_USER',      'noreply@salesdesk.co.za'); // <-- CHANGE
define('SMTP_PASS',      'SMTP_PASSWORD_HERE');    // <-- CHANGE
define('SMTP_FROM',      'noreply@salesdesk.co.za'); // <-- CHANGE
define('SMTP_FROM_NAME', 'SalesDesk');
define('SMTP_SECURE',    'tls');                   // 'tls' (port 587) or 'ssl' (port 465)

// ============================================================
// PATHS
// ============================================================
define('BASE_PATH',      dirname(__DIR__));          // project root (contains includes/, db/, etc.)
define('INCLUDES_PATH',  BASE_PATH . '/includes');
define('UPLOADS_PATH',   BASE_PATH . '/uploads');    // outside public root in production
define('LOG_PATH',       BASE_PATH . '/../logs');    // outside public root

// ============================================================
// PLATFORM DEFAULTS (override via platform_config table at runtime)
// ============================================================
define('DEFAULT_PLATFORM_FEE_PERCENT',    10);
define('DEFAULT_BROKER_CAR_LIMIT',        10);
define('DEFAULT_LEAD_DEDUP_WINDOW_DAYS',  30);
define('DEFAULT_POPIA_RETENTION_DAYS',   365);
define('MAX_CAR_IMAGES',                  10);
define('MAX_IMAGE_SIZE_BYTES',     2097152);   // 2MB per image
define('MAX_PDF_SIZE_BYTES',       5242880);   // 5MB for CIPC docs

// ============================================================
// API RATE LIMITING
// ============================================================
define('API_RATE_LIMIT_DEFAULT', 60);              // requests per minute
define('API_RATE_WINDOW_SECONDS', 60);

// ============================================================
// REDIS SESSIONS (set to true once Redis is configured)
// ============================================================
// When enabled, configure php.ini or call session_save_handler:
//   session.save_handler = redis
//   session.save_path    = "tcp://127.0.0.1:6379?prefix=salesdesk_sess_"
define('USE_REDIS_SESSIONS', false);               // <-- set true in production
define('REDIS_SESSION_PREFIX', 'salesdesk_sess_');
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);

// ============================================================
// Define $baseUrl 
// ============================================================

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
// or for an absolute URL:
$baseUrl = 'https://salesdesk.co.za/';
// ============================================================
// ERROR REPORTING
// ============================================================
define('APP_DEBUG', false);    // <-- set true in local dev only, never in production

ini_set('display_errors', APP_DEBUG ? 1 : 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
