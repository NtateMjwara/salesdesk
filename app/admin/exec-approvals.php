<?php
/**
 * SalesDesk — retired: /app/admin/exec-approvals  (0013)
 * Sales exec and desk-org agent requests now share one queue at
 * /app/admin/approvals. Kept so links in already-sent emails still work.
 */
header('Location: /app/admin/approvals?tab=execs', true, 301);
exit;
