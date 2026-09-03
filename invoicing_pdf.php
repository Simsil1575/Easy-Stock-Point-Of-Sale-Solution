<?php

declare(strict_types=1);

/**
 * Shared A4 PDF generator for quotations and invoices.
 *
 * Usage: invoicing_pdf.php?type=quotation|invoice&id=123&dl=1
 *   dl=1 forces download, otherwise it is streamed inline (I).
 */

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/invoicing_lib.php';
require_once __DIR__ . '/invoicing_pdf_builder.php';

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ./');
    exit;
}
if (!in_array(invCurrentRole(), ['admin', 'manager', 'cashier'], true)) {
    header('Location: ./');
    exit;
}

invBootstrap();
$db = invGetDb();
$settings = invGetDocumentSettings();

$type = (string) ($_GET['type'] ?? 'invoice');
$type = $type === 'quotation' ? 'quotation' : 'invoice';
$id = (int) ($_GET['id'] ?? 0);
$disposition = !empty($_GET['dl']) ? 'D' : 'I';

try {
    $built = invBuildDocumentPdf($db, $type, $id, $settings);
} catch (Throwable $e) {
    http_response_code(404);
    exit($e->getMessage());
}

$built['pdf']->Output($disposition, $built['filename']);
exit;
