<?php
/**
 * SalesDesk — Dealer portal: switch dealership  (plans Phase 2d)
 * Route: POST /app/dealer/switch
 *
 * A principal who owns a multi-dealership (Enterprise) business can run
 * every dealership in it. This stores their choice in the session
 * (SD_PRINCIPAL_ACTIVE_KEY); requireDealerWorkspace() re-checks access on
 * every request, so a dealership moved out of the business drops back to
 * the principal's own dealership straight away.
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/csrf.php';
require_once '../../includes/dealer_context.php';

applyCachePolicy('auth');
requireLogin();
requireRole('dealer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/app/dealer/dashboard.php');
}
validateCSRF();

$userId   = (int) $_SESSION['user_id'];
$targetId = (int) ($_POST['dealer_id'] ?? 0);
$choices  = array_column(sdPrincipalDealerships($userId), null, 'id');

if (!isset($choices[$targetId])) {
    $_SESSION['flash_error'] = 'You can’t open that dealership.';
    redirect('/app/dealer/dashboard.php');
}

if ((int) $choices[$targetId]['is_own']) {
    unset($_SESSION[SD_PRINCIPAL_ACTIVE_KEY]);
} else {
    $_SESSION[SD_PRINCIPAL_ACTIVE_KEY] = $targetId;
    writeAuditLog('workspace.group_entered', 'dealer', $targetId, null, ['mode' => 'group'], $userId);
}
$_SESSION['flash_ok'] = 'Now working in ' . $choices[$targetId]['company_name'] . '.';

// Stay on the same section where it makes sense.
$back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
$path = parse_url($back, PHP_URL_PATH) ?: '';
redirect(preg_match('#^/app/dealer/[a-z0-9\-]+(\.php)?$#', $path) && $path !== '/app/dealer/switch' ? $path : '/app/dealer/dashboard.php');
