<?php
// Check activation status
$pdo = new PDO('sqlite:active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

// Set the default timezone
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/business_day_helper.php';
require_once __DIR__ . '/invoice_transactions_helper.php';
require_once __DIR__ . '/cash_transactions_totals_helper.php';

$bdCtx = bdLoadBusinessHoursContext(__DIR__ . '/info.db');
$closingTime = $bdCtx['closing_time'];
$isAfterMidnight = $bdCtx['is_after_midnight'];

// New SQLite connection
$db = new PDO('sqlite:pos.db');

// Get selected date from request
$selectedDate = isset($_GET['date']) ? $_GET['date'] : bdDefaultSelectedDate($closingTime, $isAfterMidnight);
$nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$bdWhereCreated = bdSingleDayWhereSql('created_at', ':selectedDate', ':nextBusinessDay', $closingTime, $isAfterMidnight);
$bdWhereOCreated = bdSingleDayWhereSql('o.created_at', ':selectedDate', ':nextBusinessDay', $closingTime, $isAfterMidnight);
$bdWherePayment = bdSingleDayWhereSql('p.payment_date', ':selectedDate', ':nextBusinessDay', $closingTime, $isAfterMidnight);

// 1. Selected date's cash in transactions
$cashInQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-in' AND ($bdWhereCreated)
");
bdBindSingleDayParams($cashInQuery, $selectedDate, $nextBusinessDay);
$cashInQuery->execute();
$totalCashIn = $cashInQuery->fetchColumn();

// 2. Selected date's cash sales (excluding EFT payments)
$eftTableExists = false;
try {
    $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
    $eftTableExists = ($checkEftTable->fetchColumn() !== false);
} catch (PDOException $e) {
    $eftTableExists = false;
}

if ($eftTableExists) {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(o.total), 0)
        FROM orders o
        LEFT JOIN eft_payments e ON o.id = e.order_id
        WHERE e.order_id IS NULL AND ($bdWhereOCreated)
    ");
} else {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE ($bdWhereCreated)
    ");
}
bdBindSingleDayParams($cashSalesQuery, $selectedDate, $nextBusinessDay);
$cashSalesQuery->execute();
$totalCashSales = $cashSalesQuery->fetchColumn();

// 3. Selected date's credit payments
$creditPaymentsQuery = $db->prepare("
    SELECT COALESCE(SUM(p.amount), 0) 
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.payment_status = 'paid' AND ($bdWherePayment)
");
bdBindSingleDayParams($creditPaymentsQuery, $selectedDate, $nextBusinessDay);
$creditPaymentsQuery->execute();
$totalCreditPayments = $creditPaymentsQuery->fetchColumn();

// 4. Selected date's cash out (manual withdrawals and refunds)
$cashOutQuery = $db->prepare('
    SELECT ' . cashWithdrawalsSumSql() . '
    FROM cash_transactions 
    WHERE (' . $bdWhereCreated . ')
');
bdBindSingleDayParams($cashOutQuery, $selectedDate, $nextBusinessDay);
$cashOutQuery->execute();
$totalCashOut = $cashOutQuery->fetchColumn();

$invoiceCashPayments = sumInvoiceCashPayments($db, $selectedDate, $nextBusinessDay, $closingTime, (bool) $isAfterMidnight);

// Calculate final cash in till
$cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments + $invoiceCashPayments - $totalCashOut;

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'cashInTill' => $cashInTill,
    'totalWithdrawals' => $totalCashOut,
    'totalCashIn' => $totalCashIn,
    'totalCashSales' => $totalCashSales,
    'totalCreditPayments' => $totalCreditPayments
]);
