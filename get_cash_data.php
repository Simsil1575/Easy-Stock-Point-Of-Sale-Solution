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

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '22:00';
} catch (PDOException $e) {
    $closingTime = '22:00';
}

// New SQLite connection
$db = new PDO('sqlite:pos.db');

// Get selected date from request
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Calculate next business day
$nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// 1. Selected date's cash in transactions
$cashInQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-in' AND (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
    )
");
$cashInQuery->bindParam(':selectedDate', $selectedDate);
$cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashInQuery->bindParam(':closingTime', $closingTime);
$cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
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
        WHERE e.order_id IS NULL AND (
            (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
            (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
} else {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE (
            (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
            (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
}
$cashSalesQuery->bindParam(':selectedDate', $selectedDate);
$cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashSalesQuery->bindParam(':closingTime', $closingTime);
$cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashSalesQuery->execute();
$totalCashSales = $cashSalesQuery->fetchColumn();

// 3. Selected date's credit payments
$creditPaymentsQuery = $db->prepare("
    SELECT COALESCE(SUM(p.amount), 0) 
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.payment_status = 'paid' AND (
        (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
        (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
    )
");
$creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
$creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$creditPaymentsQuery->bindParam(':closingTime', $closingTime);
$creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$creditPaymentsQuery->execute();
$totalCreditPayments = $creditPaymentsQuery->fetchColumn();

// 4. Selected date's cash out (withdrawals)
$cashOutQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-out' AND (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
    )
");
$cashOutQuery->bindParam(':selectedDate', $selectedDate);
$cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashOutQuery->bindParam(':closingTime', $closingTime);
$cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashOutQuery->execute();
$totalCashOut = $cashOutQuery->fetchColumn();

// Calculate final cash in till
$cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'cashInTill' => $cashInTill,
    'totalWithdrawals' => $totalCashOut,
    'totalCashIn' => $totalCashIn,
    'totalCashSales' => $totalCashSales,
    'totalCreditPayments' => $totalCreditPayments
]);
?> 