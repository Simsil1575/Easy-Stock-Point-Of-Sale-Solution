<?php

declare(strict_types=1);

/**
 * Shared bootstrap for Quotations & Invoicing role pages (admin/, manager/, cashier root).
 *
 * The including page must define $roleFolder ('admin' | 'manager' | 'cashier') BEFORE
 * including this file. It sets up the session, guards, activation check,
 * schema, and exposes: $db, $settings, $roleFolder, $backHref, $invBase.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Africa/Harare');

if (!isset($roleFolder) || !in_array($roleFolder, ['admin', 'manager', 'cashier'], true)) {
    $roleFolder = 'admin';
}

require_once __DIR__ . '/../../invoicing_lib.php';

invRequireInvoicingAccess();

// Relative path prefix for assets/API (root pages use '', role folders use '../').
$invBase = ($roleFolder === 'cashier') ? '' : '../';

// Activation guard (mirrors purchase_orders.php).
try {
    $activationDb = new PDO('sqlite:' . __DIR__ . '/../../active.db');
    if ((int) $activationDb->query('SELECT COUNT(*) FROM software_keys WHERE is_used = 1')->fetchColumn() === 0) {
        header('Location: ' . $invBase . 'settings');
        exit;
    }
} catch (Throwable $e) {
    // If activation table is unavailable, do not hard-block the module.
}

invBootstrap();
$db = invGetDb();
$settings = invGetDocumentSettings();
$backHref = ($roleFolder === 'cashier') ? 'cashier-center' : ($roleFolder . '-center');

// Keep statuses fresh (expire quotations, mark overdue invoices).
try {
    invRefreshStatuses($db);
} catch (Throwable $e) {
    // non-fatal
}
