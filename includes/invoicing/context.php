<?php

declare(strict_types=1);

/**
 * Shared bootstrap for Quotations & Invoicing role pages (admin/ and manager/).
 *
 * The including page must define $roleFolder ('admin' | 'manager') BEFORE
 * including this file. It sets up the session, guards, activation check,
 * schema, and exposes: $db, $settings, $roleFolder, $backHref.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Harare');

if (!isset($roleFolder) || !in_array($roleFolder, ['admin', 'manager'], true)) {
    $roleFolder = 'admin';
}

require_once __DIR__ . '/../../invoicing_lib.php';

invRequireAdminOrManager();

// Activation guard (mirrors purchase_orders.php).
try {
    $activationDb = new PDO('sqlite:' . __DIR__ . '/../../active.db');
    if ((int) $activationDb->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
        header('Location: settings');
        exit;
    }
} catch (Throwable $e) {
    // If activation table is unavailable, do not hard-block the module.
}

invBootstrap();
$db = invGetDb();
$settings = invGetDocumentSettings();
$backHref = $roleFolder . '-center';

// Keep statuses fresh (expire quotations, mark overdue invoices).
try {
    invRefreshStatuses($db);
} catch (Throwable $e) {
    // non-fatal
}
