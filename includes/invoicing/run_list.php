<?php
/**
 * Shared list page runner. Expects: $type, plus context.php already included
 * (provides $db, $settings, $backHref, $roleFolder).
 */
require_once __DIR__ . '/../../invoicing_notifications.php';

$isQuote = $type === 'quotation';
$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'customer_id' => (int) ($_GET['customer_id'] ?? 0) ?: '',
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
if (!$isQuote) {
    $filters['payment'] = trim((string) ($_GET['payment'] ?? ''));
}
$sort = (string) ($_GET['sort'] ?? 'date_desc');
$pageNum = max(1, (int) ($_GET['page'] ?? 1));

$result = $isQuote
    ? invListQuotations($db, $filters, $pageNum, 15, $sort)
    : invListInvoices($db, $filters, $pageNum, 15, $sort);

$customers = invListCustomers($db);
$notifications = invNotificationCards($db, $settings, $isQuote ? 'quotation' : 'invoice');

$listPage = $isQuote ? 'quotations' : 'invoices';
$editPage = $isQuote ? 'quotation_edit' : 'invoice_edit';
$viewPage = $isQuote ? 'quotation_view' : 'invoice_view';
$printPage = $isQuote ? 'quotation_print' : 'invoice_print';
$pageTitle = $isQuote ? 'Quotations' : 'Invoices';
$mobileTitle = $pageTitle;

include __DIR__ . '/layout_top.php';
include __DIR__ . '/list.php';
include __DIR__ . '/layout_bottom.php';
