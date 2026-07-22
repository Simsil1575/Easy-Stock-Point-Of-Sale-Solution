<?php
/**
 * Shared view page runner. Expects: $type + context.php included.
 */
$isQuote = $type === 'quotation';
$id = (int) ($_GET['id'] ?? 0);
$bundle = $isQuote ? invLoadQuotation($db, $id) : invLoadInvoice($db, $id);
if (!$bundle) {
    header('Location: ' . ($isQuote ? 'quotations' : 'invoices'));
    exit;
}

$listPage = $isQuote ? 'quotations' : 'invoices';
$editPage = $isQuote ? 'quotation_edit' : 'invoice_edit';
$viewPage = $isQuote ? 'quotation_view' : 'invoice_view';
$doc0 = $isQuote ? $bundle['quotation'] : $bundle['invoice'];
$pageTitle = ($isQuote ? 'Quotation ' : 'Invoice ') . ($isQuote ? $doc0['quotation_number'] : $doc0['invoice_number']);
$mobileTitle = $isQuote ? 'Quotation' : 'Invoice';

include __DIR__ . '/layout_top.php';
include __DIR__ . '/view.php';
include __DIR__ . '/layout_bottom.php';
