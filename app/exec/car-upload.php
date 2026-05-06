<?php
/**
 * SalesDesk — Exec Car Upload.
 * T3 owns this file.
 *
 * Task sep2: Reuses dealer car-upload wizard.
 * Sets uploaded_by_exec_id = se.id on INSERT.
 * dealer_id is pulled from sales_executives.dealer_id (DB, not editable).
 *
 * This file is a thin wrapper: it sets up the exec context
 * so car-upload.php (shared wizard) can detect the exec role
 * via $_SESSION['user_role'] === 'sales_exec'.
 *
 * The shared wizard in app/dealer/car-upload.php handles both paths:
 *   - role = 'dealer'     → uploaded_by_exec_id = NULL
 *   - role = 'sales_exec' → loads exec_guard, sets uploaded_by_exec_id = se.id
 *
 * This file simply includes the shared wizard with the correct path context.
 */

// Adjust include path for shared wizard located in app/dealer/
define('EXEC_CAR_UPLOAD', true);

// The shared car-upload wizard auto-detects role from session.
// It calls requireExecVerified() when role === 'sales_exec'.
// We just need to forward to it.
require_once dirname(__DIR__) . '/dealer/car-upload.php';
