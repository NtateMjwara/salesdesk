<?php
/**
 * SalesDesk — retired: app/broker/org-settings.php  (0013)
 *
 * Desk organisations are now admin-run online dealerships. Brokers no
 * longer create or configure them; they apply to join one as agents from
 * /app/broker/desk-org. This stub keeps old links and bookmarks working.
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

unset($_SESSION['org_wz']);
header('Location: /app/broker/desk-org', true, 301);
exit;
