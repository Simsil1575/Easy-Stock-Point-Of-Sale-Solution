<?php
// fetch_report_data.php - Fetch report data for reports.php and transactions.php

// Check for required parameter
if (!isset($_POST['date'])) {
    echo json_encode(['error' => 'Date parameter is required']);
    exit;
}

// Set error reporting for debugging
ini_set('display_errors', 0); // Don't display errors directly to client
error_reporting(E_ALL); // Report all errors for logging

// Validate date format
$selectedDate = $_POST['date'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    echo json_encode(['error' => 'Invalid date format. Required: YYYY-MM-DD']);
    exit;
}

// Get business closing time from business_info
$businessInfo = [];
$closingTime = '00:00'; // Default
try {
    $businessInfoDb = new PDO('sqlite:info.db');
    $businessInfoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($businessInfo && isset($businessInfo['closing_time'])) {
        $closingTime = $businessInfo['closing_time'];
    }
} catch (PDOException $e) {
    error_log('Business info DB error: ' . $e->getMessage());
    // Continue with default closing time
}

// Database connection
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
$isAfterMidnight = $closingHour < 12;

// Calculate the next day date for queries
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Get cash in/out with business day logic for the selected date only
try {
    $cashInQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions 
        WHERE type='cash-in' AND (
            (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
    $cashInQuery->bindParam(':selectedDate', $selectedDate);
    $cashInQuery->bindParam(':nextDay', $nextDay);
    $cashInQuery->bindParam(':closingTime', $closingTime);
    $cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    $cashInQuery->execute();
    $totalCashIn = $cashInQuery->fetchColumn();

    $cashOutQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions 
        WHERE type='cash-out' AND (
            (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
    $cashOutQuery->bindParam(':selectedDate', $selectedDate);
    $cashOutQuery->bindParam(':nextDay', $nextDay);
    $cashOutQuery->bindParam(':closingTime', $closingTime);
    $cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    $cashOutQuery->execute();
    $totalCashOut = $cashOutQuery->fetchColumn();
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error fetching cash transactions: ' . $e->getMessage()]);
    exit;
}

// Get cash sales for the selected date using business day logic
try {
    // Check if EFT payments table exists
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
                (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
        $cashSalesQuery->bindParam(':nextDay', $nextDay);
        $cashSalesQuery->bindParam(':closingTime', $closingTime);
        $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $cashSalesQuery->execute();
    } else {
        $cashSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(total), 0) 
            FROM orders 
            WHERE (
                (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
            )
        ");
        $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
        $cashSalesQuery->bindParam(':nextDay', $nextDay);
        $cashSalesQuery->bindParam(':closingTime', $closingTime);
        $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        $cashSalesQuery->execute();
    }
    $totalCashSales = $cashSalesQuery->fetchColumn();
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error fetching cash sales: ' . $e->getMessage()]);
    exit;
}

// Get credit payments received in cash for the selected date using business day logic
try {
    $creditPaymentsQuery = $db->prepare("
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.payment_status = 'paid' AND (
            (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
            (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
    $creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
    $creditPaymentsQuery->bindParam(':nextDay', $nextDay);
    $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
    $creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    $creditPaymentsQuery->execute();
    $totalCreditPayments = $creditPaymentsQuery->fetchColumn();
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error fetching credit payments: ' . $e->getMessage()]);
    exit;
}

/*
Cash available in till is calculated as:
1. Cash deposits for the selected date (cash-in transactions)
2. Plus cash sales for the selected date (orders paid with cash)
3. Plus credit payments received in cash for the selected date
4. Minus cash withdrawals for the selected date (cash-out transactions)
5. EFT sales don't affect physical cash since they're electronic transfers
*/
$cashAvailableInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;

// Ensure the value is a valid number
if (!is_numeric($cashAvailableInTill)) {
    error_log('Cash available calculation resulted in non-numeric value. Setting to 0.');
    $cashAvailableInTill = 0;
}

// Snapshot of system expected cash before any cash-up adjustment (for receipt after count)
$expectedCashAtCashup = floatval($cashAvailableInTill);

// Get the total cash ever received through the system
$totalSystemCash = $totalCashIn + $totalCashSales + $totalCreditPayments;

// Record cash discrepancy if actual cash amount is provided (difference computed server-side)
if (isset($_POST['actual_cash_in_till'])) {
    $actualAmount = floatval($_POST['actual_cash_in_till']);
    $difference = $actualAmount - $expectedCashAtCashup;
    
    if ($difference != 0) {
        try {
            // Use the selected date for the cash transaction record
            $cashUpDate = $selectedDate . ' 23:59:59'; // Set to end of selected date
            
            $stmt = $db->prepare("
                INSERT INTO cash_transactions (
                    type,
                    amount,
                    description,
                    created_at
                ) VALUES (
                    :type,
                    :amount,
                    :description,
                    :cash_up_date
                )
            ");
            
            $type = $difference > 0 ? 'cash-in' : 'cash-out';
            $amount = abs($difference);
            $description = $difference > 0 ? 
                'Cash surplus recorded during cash-up for ' . $selectedDate : 
                'Cash shortage recorded during cash-up for ' . $selectedDate;
            
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':amount', $amount);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':cash_up_date', $cashUpDate);
            $stmt->execute();
            
            // Update the cash available in till after recording the discrepancy
            if ($type === 'cash-in') {
                $cashAvailableInTill += $amount;
            } else {
                $cashAvailableInTill -= $amount;
            }
        } catch (PDOException $e) {
            error_log('Error recording cash discrepancy: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to record cash discrepancy: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Use the already calculated cash sales total with business day logic
$cashSalesTotal = $totalCashSales;

// Get EFT sales for the selected date
try {
    $eftSalesQuery = $db->prepare("
        SELECT SUM(e.amount) 
        FROM eft_payments e
        JOIN orders o ON e.order_id = o.id
        WHERE DATE(o.created_at) = :selectedDate
    ");
    $eftSalesQuery->bindParam(':selectedDate', $selectedDate);
    $eftSalesQuery->execute();
    $eftSalesTotal = $eftSalesQuery->fetchColumn();
    $eftSalesTotal = ($eftSalesTotal !== false && $eftSalesTotal !== null) ? (float)$eftSalesTotal : 0;
} catch (PDOException $e) {
    error_log('Error fetching EFT sales: ' . $e->getMessage());
    $eftSalesTotal = 0;
}

// Get unpaid credit for the selected date
try {
    $unpaidCreditQuery = $db->prepare("
        SELECT SUM(total_amount - COALESCE(paid_amount, 0)) as unpaid_credit
        FROM credit_sales 
        WHERE DATE(created_at) = :selectedDate
        AND payment_status IN ('pending', 'partial')
    ");
    $unpaidCreditQuery->bindParam(':selectedDate', $selectedDate);
    $unpaidCreditQuery->execute();
    $unpaidCredit = $unpaidCreditQuery->fetchColumn();
    $unpaidCredit = ($unpaidCredit !== false && $unpaidCredit !== null) ? (float)$unpaidCredit : 0;
} catch (PDOException $e) {
    error_log('Error fetching unpaid credit: ' . $e->getMessage());
    $unpaidCredit = 0;
}

// Add income/expense breakdown for cash-up receipt (same as in cash-pdf.php)
try {
    $dateSql = "CASE \
        WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "\n        THEN date(datetime(created_at, '-1 day'))\n        ELSE date(created_at)\n    END AS business_date";
    $sql = "\n        SELECT \n            t.business_date as sale_date,\n            SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'cash' THEN t.amount ELSE 0 END) as cash_sales,\n            SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_unpaid' THEN t.amount ELSE 0 END) as credit_unpaid,\n            SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_payment' THEN t.amount ELSE 0 END) as credit_cash,\n            SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_eft' THEN t.amount ELSE 0 END) as credit_eft,\n            SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'eft' THEN t.amount ELSE 0 END) as eft_sales,\n            SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE 0 END) as total_income,\n            SUM(CASE WHEN t.transaction_type = 'expense' THEN t.amount ELSE 0 END) as total_expense,\n            SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE -t.amount END) as net_amount\n        FROM (\n            -- Get all order transactions\n            SELECT \n                CASE \n                    WHEN strftime('%H:%M', o.created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "\n                    THEN date(datetime(o.created_at, '-1 day'))\n                    ELSE date(o.created_at)\n                END AS business_date, \n                o.total as amount,\n                CASE \n                    WHEN e.order_id IS NOT NULL THEN 'eft'\n                    ELSE 'cash'\n                END as source,\n                'income' as transaction_type\n            FROM orders o\n            LEFT JOIN eft_payments e ON o.id = e.order_id\n            \n            UNION ALL\n            \n            -- Include only unpaid/partial credit sales on their creation date\n            SELECT \n                CASE \n                    WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "\n                    THEN date(datetime(created_at, '-1 day'))\n                    ELSE date(created_at)\n                END AS business_date, \n                total_amount as amount, \n                CASE \n                    WHEN payment_status = 'unpaid' THEN 'credit_unpaid'\n                    WHEN payment_status = 'partial' THEN 'credit_unpaid'\n                    ELSE 'credit_unpaid'\n                END as source,\n                'income' as transaction_type\n            FROM credit_sales\n            WHERE payment_status IN ('unpaid', 'partial')\n            \n            UNION ALL\n            \n            -- Include credit payments on payment date based on payment type\n            SELECT \n                CASE \n                    WHEN strftime('%H:%M', p.payment_date) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "\n                    THEN date(datetime(p.payment_date, '-1 day'))\n                    ELSE date(p.payment_date)\n                END AS business_date,\n                p.amount as amount,\n                CASE\n                    WHEN cs.payment_status = 'eft' THEN 'credit_eft'\n                    ELSE 'credit_payment'\n                END as source,\n                'income' as transaction_type\n            FROM payments p\n            JOIN credit_sales cs ON p.sale_id = cs.id\n            \n            UNION ALL\n            \n            -- Include cash-out transactions as expenses\n            SELECT \n                CASE \n                    WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "\n                    THEN date(datetime(created_at, '-1 day'))\n                    ELSE date(created_at)\n                END AS business_date,\n                amount,\n                'cash-out' as source,\n                'expense' as transaction_type\n            FROM cash_transactions\n            WHERE type = 'cash-out'\n        ) t\n        WHERE business_date = :selectedDate\n        GROUP BY sale_date\n    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':selectedDate', $selectedDate);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cash_sales = $row['cash_sales'] ?? 0;
    $credit_unpaid = $row['credit_unpaid'] ?? 0;
    $credit_cash = $row['credit_cash'] ?? 0;
    $credit_eft = $row['credit_eft'] ?? 0;
    $eft_sales = $row['eft_sales'] ?? 0;
    $total_income = $row['total_income'] ?? 0;
    $total_expense = $row['total_expense'] ?? 0;
    $net_amount = $row['net_amount'] ?? 0;
} catch (PDOException $e) {
    error_log('Error fetching income/expense breakdown: ' . $e->getMessage());
    $cash_sales = 0;
    $credit_unpaid = 0;
    $credit_cash = 0;
    $credit_eft = 0;
    $eft_sales = 0;
    $total_income = 0;
    $total_expense = 0;
    $net_amount = 0;
}

// Prepare response with all necessary data
$response = [
    'cashAvailableInTill' => $cashAvailableInTill,
    'date' => $selectedDate,
    'totalCashIn' => $totalCashIn,
    'totalCashOut' => $totalCashOut,
    'totalCashSales' => $totalCashSales,
    'totalCreditPayments' => $totalCreditPayments,
    'cashSalesTotal' => $cashSalesTotal,
    'eftSalesTotal' => $eftSalesTotal,
    'unpaidCredit' => $unpaidCredit,
    'cashOnHand' => $cashAvailableInTill, // This is the same as cashAvailableInTill
    // Add the detailed breakdown fields
    'cash_sales' => $cash_sales,
    'credit_cash' => $credit_cash,
    'credit_eft' => $credit_eft,
    'eft_sales' => $eft_sales,
    'credit_unpaid' => $credit_unpaid,
    'total_income' => $total_income,
    'total_expense' => $total_expense,
    'net_amount' => $net_amount
];

// After cashier entered actual count: expose system expected on receipt only
if (isset($_POST['actual_cash_in_till'])) {
    $response['expected_cash_at_cashup'] = $expectedCashAtCashup;
    $response['cash_difference'] = floatval($_POST['actual_cash_in_till']) - $expectedCashAtCashup;
}

// During cash-up entry only: do not expose expected till amount (theft deterrent)
$cashUpHideExpected = !empty($_POST['cash_up_hide_expected']) && !isset($_POST['actual_cash_in_till']);
if ($cashUpHideExpected) {
    unset($response['cashAvailableInTill'], $response['cashOnHand']);
    $response['expected_cash_hidden'] = true;
}

// Return the data as JSON
header('Content-Type: application/json');
echo json_encode($response);
exit;