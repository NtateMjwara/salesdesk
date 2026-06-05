<?php
/**
 * SalesDesk — 404 Not Found Page
 */
declare(strict_types=1);
require_once '../includes/security.php';
require_once '../includes/visitor.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

http_response_code(404);
applyCachePolicy('public');
$visitor = initVisitorSession();

$pageTitle    = 'Page Not Found | SalesDesk';
$layoutVariant = 'narrow';
ob_start();
?>
<div style="text-align:center;padding:5rem 1rem;">
  <div style="font-family:var(--font-d);font-size:96px;font-weight:800;
              color:var(--border);line-height:1;margin-bottom:1rem;">
    404
  </div>
  <div style="font-family:var(--font-d);font-size:24px;font-weight:700;
              color:var(--text);margin-bottom:8px;">
    Page not found
  </div>
  <p style="font-size:14px;color:var(--muted);margin-bottom:2rem;max-width:360px;
            margin-left:auto;margin-right:auto;line-height:1.7;">
    The page you're looking for has moved, been removed, or never existed.
  </p>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
    <a href="/c/" class="pub-btn pub-btn-primary" style="padding:11px 24px;font-size:14px;">
      <i class="fa-solid fa-car"></i> Browse cars
    </a>
    <a href="/" class="pub-btn pub-btn-ghost" style="padding:11px 20px;font-size:14px;">
      Go home
    </a>
  </div>
</div>
<?php
$pageContent = ob_get_clean();
require_once '../views/layout-public.php';
