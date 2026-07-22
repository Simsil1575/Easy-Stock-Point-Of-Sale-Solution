<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Harare');
require_once __DIR__ . '/invoicing_lib.php';

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ./');
    exit;
}
if (!in_array(invCurrentRole(), ['admin', 'manager'], true)) {
    header('Location: ./');
    exit;
}

invBootstrap();
$db = invGetDb();
$settings = invGetDocumentSettings();

$id = (int) ($_GET['id'] ?? 0);
$bundle = invLoadQuotation($db, $id);
if (!$bundle) {
    http_response_code(404);
    exit('Quotation not found.');
}
$type = 'quotation';
include __DIR__ . '/includes/invoicing/document_print.php';
