<?php
/**
 * Shared create/edit page runner. Expects: $type + context.php included.
 */
$isQuote = $type === 'quotation';
$id = (int) ($_GET['id'] ?? 0);
$bundle = null;
if ($id > 0) {
    $bundle = $isQuote ? invLoadQuotation($db, $id) : invLoadInvoice($db, $id);
    if (!$bundle) {
        header('Location: ' . ($isQuote ? 'quotations' : 'invoices'));
        exit;
    }
}

$customers = invListCustomers($db);
$products = invListProducts($db, '', 2000);

$listPage = $isQuote ? 'quotations' : 'invoices';
$editPage = $isQuote ? 'quotation_edit' : 'invoice_edit';
$viewPage = $isQuote ? 'quotation_view' : 'invoice_view';
$pageTitle = ($id ? 'Edit ' : 'New ') . ($isQuote ? 'Quotation' : 'Invoice');
$mobileTitle = $pageTitle;

include __DIR__ . '/layout_top.php';
include __DIR__ . '/editor.php';
include __DIR__ . '/layout_bottom.php';
