<?php
/**
 * SalesDesk — retired: app/admin/desk-orgs.php  (0012/0013, retired in 0020)
 *
 * Desk organisations were admin-run online dealerships. Since 0020 every
 * organisation is a dealership's business account, created and kept in
 * step with the dealership, so there is nothing to manage here. Plans and
 * networks are handled on the dealership's page.
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$_SESSION['flash_ok'] = 'Desk orgs have moved: each dealership is now its own business account. Manage it from Dealerships.';
header('Location: /app/admin/dealerships', true, 302);
exit;
