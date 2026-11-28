<?php
/**
 * SalesDesk — retired: app/broker/org-context.php  (T4, retired again in 0020)
 *
 * Since 0020 an organisation is a dealership's business account; brokers
 * never own or run one. They join business networks from My networks
 * (plans Phase 2b), so this route sends them there and old links and
 * bookmarks keep working.
 */
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

unset($_SESSION['org_wz'], $_SESSION['org_context']);
header('Location: /app/broker/networks', true, 302);
exit;
