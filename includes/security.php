<?php
/**
 * SalesDesk — Security headers.
 * Require at the very top of every public-facing script.
 */

// Prevent output before headers
if (headers_sent()) {
    return;
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com 'unsafe-inline'; "
     . "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com 'unsafe-inline'; "
     . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; "
     . "img-src 'self' data: https://images.unsplash.com;");