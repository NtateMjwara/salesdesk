<?php
/**
 * SalesDesk — Sales executive verification guard.
 * T2 defines this interface contract. T3 calls it.
 *
 * USAGE — top of every app/exec/ page:
 *
 *   require_once '../../includes/security.php';
 *   require_once '../../includes/session.php';
 *   require_once '../../includes/functions.php';
 *   require_once '../../includes/exec_guard.php';
 *   applyCachePolicy('auth');
 *
 *   $exec = requireExecVerified(); // exits with status page if not verified
 *   // $exec['id']        — sales_executives.id
 *   // $exec['dealer_id'] — the exec's linked dealership
 *   // $exec['dealer_name'] — company name for display
 *   // $exec['job_title']
 *   // $exec['first_name'], $exec['last_name']
 *
 * CONTRACT (frozen — T2 owns, T3 calls):
 *   requireExecVerified(): array
 *     - Returns exec row array on success.
 *     - On failure: renders an appropriate HTML status page and exits.
 *       Never redirects silently — the user always sees an explanation.
 *     - Calls requireLogin() internally; no need to call it separately.
 *     - Gate is ALWAYS DB-level — never session-role-only.
 *
 * Status pages rendered on failure:
 *   pending   — "Awaiting approval" with dealer name
 *   rejected  — "Request declined" with reason + option to re-apply
 *   suspended — "Access suspended" with dealer contact prompt
 *   no record — "No dealership linked" with link back to dashboard
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

/**
 * Require the logged-in user to be a verified sales executive.
 *
 * Performs a single DB query joining sales_executives, dealers, profiles.
 * If not verified, renders an inline status page (with full HTML) and exits.
 *
 * @return array  Exec row with keys: id, dealer_id, dealer_name, job_title,
 *                first_name, last_name, verification_status, rejection_reason
 */
function requireExecVerified(): array
{
    requireLogin();

    if (($_SESSION['user_role'] ?? '') !== 'sales_exec') {
        http_response_code(403);
        exit('Access denied.');
    }

    $userId = (int) $_SESSION['user_id'];
    $pdo    = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT
            se.id,
            se.dealer_id,
            se.job_title,
            se.verification_status,
            se.rejection_reason,
            d.company_name   AS dealer_name,
            p.first_name,
            p.last_name
        FROM sales_executives se
        JOIN dealers d  ON d.id  = se.dealer_id
        LEFT JOIN profiles p ON p.user_id = se.user_id
        WHERE se.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $exec = $stmt->fetch();

    // No record at all — exec signed up but skipped dealership selection.
    if (!$exec) {
        _renderStatusPage(
            'no_record',
            '',
            '',
            ''
        );
    }

    if ($exec['verification_status'] === 'verified') {
        return $exec;
    }

    // Not verified — render status-appropriate page and exit.
    _renderStatusPage(
        $exec['verification_status'],
        $exec['dealer_name']     ?? '',
        $exec['first_name']      ?? '',
        $exec['rejection_reason'] ?? ''
    );
}


/**
 * Render a full HTML status page and exit.
 * Called when exec is pending, rejected, suspended, or has no dealership.
 *
 * @param string $status          'pending'|'rejected'|'suspended'|'no_record'
 * @param string $dealerName      Company name
 * @param string $firstName       Exec's first name (for greeting)
 * @param string $rejectionReason Populated for rejected/suspended
 */
function _renderStatusPage(
    string $status,
    string $dealerName,
    string $firstName,
    string $rejectionReason
): never {
    $greeting = $firstName ? htmlspecialchars($firstName) : 'there';
    $dealer   = $dealerName ? htmlspecialchars($dealerName) : 'your dealership';

    $config = match ($status) {
        'pending' => [
            'icon'    => 'fa-clock',
            'iconBg'  => 'background:var(--amb-bg);color:var(--amber)',
            'title'   => 'Awaiting approval',
            'message' => "Your request to join <strong>{$dealer}</strong> is pending. "
                       . "The dealer principal will review your request and you'll receive an email once approved.",
            'actions' => '<a href="/app/exec/dashboard.php" class="btn btn-ghost" style="text-decoration:none">Refresh status</a>',
        ],
        'rejected' => [
            'icon'    => 'fa-xmark-circle',
            'iconBg'  => 'background:var(--red-bg);color:var(--red)',
            'title'   => 'Request declined',
            'message' => "Your request to join <strong>{$dealer}</strong> was declined."
                       . ($rejectionReason
                            ? " Reason: <em>" . htmlspecialchars($rejectionReason) . "</em>"
                            : ''),
            'actions' => '<a href="/auth/register.php?reapply=1" class="btn btn-primary" style="text-decoration:none">'
                       . '<i class="fa-solid fa-rotate-right"></i> Apply to a different dealership</a>'
                       . '<a href="/auth/login.php?action=logout" class="btn btn-ghost" style="text-decoration:none;margin-left:8px">Sign out</a>',
        ],
        'suspended' => [
            'icon'    => 'fa-pause-circle',
            'iconBg'  => 'background:var(--bg);color:var(--faint)',
            'title'   => 'Access suspended',
            'message' => "Your access to <strong>{$dealer}</strong> has been temporarily suspended. "
                       . ($rejectionReason
                            ? "Reason: <em>" . htmlspecialchars($rejectionReason) . "</em>. "
                            : '')
                       . "Please contact your dealer principal for details.",
            'actions' => '<a href="/auth/login.php?action=logout" class="btn btn-ghost" style="text-decoration:none">Sign out</a>',
        ],
        default => [ // no_record
            'icon'    => 'fa-building-circle-xmark',
            'iconBg'  => 'background:var(--bg);color:var(--faint)',
            'title'   => 'No dealership linked',
            'message' => "Your account isn't linked to a dealership yet. "
                       . "Complete your onboarding to connect with a dealer.",
            'actions' => '<a href="/auth/register.php" class="btn btn-primary" style="text-decoration:none">'
                       . '<i class="fa-solid fa-arrow-right"></i> Complete setup</a>',
        ],
    };

    http_response_code($status === 'no_record' ? 200 : 403);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($config['title']) ?> | SalesDesk</title>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body {
      min-height: 100vh; display: flex;
      align-items: center; justify-content: center; padding: 24px 16px;
    }
    .status-card {
      background: var(--white); border: 1px solid var(--border);
      border-radius: var(--r-xl); padding: 2.5rem 2rem;
      width: 100%; max-width: 460px;
      box-shadow: var(--shadow-lg); text-align: center;
    }
    .status-icon {
      width: 64px; height: 64px; border-radius: 50%;
      margin: 0 auto 1.5rem;
      display: flex; align-items: center; justify-content: center;
      font-size: 26px;
    }
    .status-title { font-family: var(--serif); font-size: 1.5rem; font-weight: 300; margin-bottom: 10px; }
    .status-title em { font-style: italic; }
    .status-message { font-size: 14px; color: var(--muted); line-height: 1.7; margin-bottom: 1.75rem; }
    .brand { display: flex; align-items: center; gap: 8px; justify-content: center; margin-bottom: 2rem; }
    .brand-logo {
      width: 30px; height: 30px; background: var(--p); border-radius: 7px;
      display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px;
    }
    .brand-name { font-family: var(--serif); font-size: 1.05rem; font-weight: 500; color: var(--text); }
    .brand-name em { font-style: italic; color: var(--p); }
  </style>
</head>
<body>
<div class="status-card">
  <div class="brand">
    <div class="brand-logo"><i class="fa-solid fa-car-side"></i></div>
    <div class="brand-name">Sales<em>Desk</em></div>
  </div>
  <div class="status-icon" style="<?= $config['iconBg'] ?>">
    <i class="fa-solid <?= $config['icon'] ?>"></i>
  </div>
  <div class="status-title">Hi, <em><?= $greeting ?></em></div>
  <p class="status-message"><?= $config['message'] ?></p>
  <div><?= $config['actions'] ?></div>
</div>
</body>
</html>
    <?php
    exit;
}
