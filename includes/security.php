<?php
/**
 * SalesDesk — Security headers.
 * T1 owns this file.
 *
 * Require this at the very top of every public-facing script,
 * BEFORE any output, session start, or other includes.
 *
 * ── CANONICAL REQUIRE ORDER ──────────────────────────────────
 * Every authenticated page must load includes in this exact order:
 *
 *   require_once '../includes/security.php';   ← always first (sets headers)
 *   require_once '../includes/session.php';    ← needs config.php (via security.php)
 *   require_once '../includes/database.php';   ← PDO singleton
 *   require_once '../includes/functions.php';  ← helpers, guards, applyCachePolicy()
 *   require_once '../includes/csrf.php';       ← needs session started
 *   require_once '../includes/mailer.php';     ← only on pages that send email
 *   require_once '../includes/response.php';   ← redirect() / jsonResponse()
 *   require_once '../includes/encryption.php'; ← only on pages handling bank data
 *
 * Note: security.php itself requires config.php so that session.php
 * and all subsequent files can safely use defined constants.
 * Do NOT require config.php separately — security.php does it.
 *
 * ── applyCachePolicy() ────────────────────────────────────────
 * Call applyCachePolicy() (defined in functions.php) immediately
 * after the require block on every page:
 *
 *   applyCachePolicy('auth');    — authenticated app pages (no-store)
 *   applyCachePolicy('public');  — buyer-facing pages (max-age=300)
 *   applyCachePolicy('api');     — JSON API endpoints (no-store)
 *
 * Never set Cache-Control manually. The function is the single
 * source of truth for caching policy.
 *
 * ── Why this order matters ────────────────────────────────────
 * security.php sets headers — must run before ANY output.
 * session.php calls session_start() — must run before any read
 *   of $_SESSION or CSRF operations.
 * functions.php provides requireLogin() and applyCachePolicy() —
 *   must be loaded before guards are called.
 * csrf.php reads $_SESSION[CSRF_TOKEN_NAME] — needs session open.
 */

require_once __DIR__ . '/config.php';

// Prevent output before headers.
if (headers_sent()) {
    return;
}

// ── Security headers — applied on every page load ─────────────

// Prevent clickjacking.
header('X-Frame-Options: DENY');

// Prevent MIME-type sniffing.
header('X-Content-Type-Options: nosniff');

// Legacy XSS filter (belt-and-suspenders for older browsers).
header('X-XSS-Protection: 1; mode=block');

// Limit referrer information sent to third parties.
header('Referrer-Policy: strict-origin-when-cross-origin');

// Content Security Policy.
// script-src: allow self + Google Fonts (for @import in CSS) + CDN + inline
//   (inline is required for the small page-specific JS snippets in auth pages).
// style-src:  allow self + Google Fonts + CDN + inline (same reason).
// img-src:    allow self + data URIs + Unsplash (car images in dev).
// font-src:   allow self + Google Fonts + CDN + data URIs.
//
// Review before production — tighten script-src 'unsafe-inline' once
// all page-specific JS has been extracted to static files.
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; " .
    "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com 'unsafe-inline'; " .
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; " .
    "img-src 'self' data: https://images.unsplash.com; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none';"
);
