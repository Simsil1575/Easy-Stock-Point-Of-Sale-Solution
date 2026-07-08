<?php


session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

header('Content-Type: application/json');

// IMPORTANT: 
// Cash in till calculation matches cash.php exactly:
// 1. Cash-in, cash-out, cash sales, and credit payments are calculated for the SELECTED DATE ONLY
// 2. Uses business day logic (considers closing time) to determine which transactions belong to the selected date
// 3. Other financial data (daily sales, credit totals) are specific to the selected date only
// This ensures the cash available in till matches the calculation in cash.php

if (!isset($_POST['date']) || !DateTime::createFromFormat('Y-m-d', $_POST['date'])) {
    die(json_encode(['error' => 'Invalid date format']));
}

$selectedDate = $_POST['date'];

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    die(json_encode(['error' => 'Database connection failed']));
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00'; // Default to 10:00 PM if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '00:00';
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
$isAfterMidnight = $closingHour < 12;

// Calculate the next day date for queries
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$nextBusinessDay = $nextDay; // Alias for consistency with cash.php

// Pre-calculate business day start and end timestamps for efficient querying
// Business day starts at closing time of the previous day and ends at closing time of current day
if ($isAfterMidnight) {
    // Business day for $selectedDate is from closing time yesterday to closing time today
    $businessDayStart = date('Y-m-d H:i:s', strtotime($selectedDate . ' -1 day ' . $closingTime));
    $businessDayEnd = date('Y-m-d H:i:s', strtotime($selectedDate . ' ' . $closingTime));
} else {
    // Normal business day: from closing time of current day to end of day
    $businessDayStart = date('Y-m-d H:i:s', strtotime($selectedDate . ' ' . $closingTime));
    $businessDayEnd = date('Y-m-d H:i:s', strtotime($nextDay . ' ' . $closingTime));
}

// Fetch cash sales total for selected date (matching cash.php logic exactly)
$eftTableExists = false;
try {
    $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
    $eftTableExists = ($checkEftTable->fetchColumn() !== false);
} catch (PDOException $e) {
    $eftTableExists = false;
}

if ($eftTableExists) {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(
            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
        ), 0)
        FROM orders o
        WHERE (
            (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
            (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
    $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
    $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
    $cashSalesQuery->bindParam(':closingTime', $closingTime);
    $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    $cashSalesQuery->execute();
} else {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE (
            (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
            (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
        )
    ");
    $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
    $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
    $cashSalesQuery->bindParam(':closingTime', $closingTime);
    $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    $cashSalesQuery->execute();
}
$cashSalesTotal = (float)$cashSalesQuery->fetchColumn();

// Get cumulative cash sales up to end of selected business day
$cumulativeCashSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(o.total), 0)
    FROM orders o
    LEFT JOIN eft_payments e ON o.id = e.order_id
    WHERE e.order_id IS NULL
    AND o.created_at < :businessDayEnd
");
$cumulativeCashSalesQuery->bindParam(':businessDayEnd', $businessDayEnd);
$cumulativeCashSalesQuery->execute();
$cumulativeCashSales = (float)$cumulativeCashSalesQuery->fetchColumn();

// Get cash in/out for selected date only (matching cash.php logic exactly)
// This ensures cash in till calculation matches cash.php
$cashInQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type = 'cash-in' AND (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
    )
");
$cashInQuery->bindParam(':selectedDate', $selectedDate);
$cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashInQuery->bindParam(':closingTime', $closingTime);
$cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashInQuery->execute();
$totalCashIn = (float)$cashInQuery->fetchColumn();

$cashOutQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type = 'cash-out' AND (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
    )
");
$cashOutQuery->bindParam(':selectedDate', $selectedDate);
$cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashOutQuery->bindParam(':closingTime', $closingTime);
$cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$cashOutQuery->execute();
$totalCashOut = (float)$cashOutQuery->fetchColumn();

// Calculate EFT payments with business day logic (optimized)
$eftSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM eft_payments e 
    JOIN orders o ON e.order_id = o.id 
    WHERE o.created_at >= :businessDayStart
    AND o.created_at < :businessDayEnd
");
$eftSalesQuery->bindParam(':businessDayStart', $businessDayStart);
$eftSalesQuery->bindParam(':businessDayEnd', $businessDayEnd);
$eftSalesQuery->execute();
$eftSalesTotal = (float)$eftSalesQuery->fetchColumn();

// Get credit sales with payment_status = 'eft' with business day logic (optimized)
$eftCreditSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM credit_sales 
    WHERE payment_status = 'eft' 
    AND created_at >= :businessDayStart
    AND created_at < :businessDayEnd
");
$eftCreditSalesQuery->bindParam(':businessDayStart', $businessDayStart);
$eftCreditSalesQuery->bindParam(':businessDayEnd', $businessDayEnd);
$eftCreditSalesQuery->execute();
$eftCreditSalesTotal = (float)$eftCreditSalesQuery->fetchColumn();

// Total EFT payments including both regular EFT and credit sales with payment_status 'eft'
$totalEftPayments = $eftSalesTotal + $eftCreditSalesTotal;

// Fetch credit sales total and unpaid balances with business day logic (optimized)
$creditSalesQuery = $db->prepare("
    SELECT 
    COALESCE(SUM(total_amount), 0) as total_issued,
    COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END), 0) as total_unpaid 
    FROM credit_sales 
    WHERE created_at >= :businessDayStart
    AND created_at < :businessDayEnd
");
$creditSalesQuery->bindParam(':businessDayStart', $businessDayStart);
$creditSalesQuery->bindParam(':businessDayEnd', $businessDayEnd);
$creditSalesQuery->execute();
$creditData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
$creditTotal = (float)$creditData['total_issued'];
$unpaidTotal = (float)$creditData['total_unpaid'];

// Calculate unpaid credit (cumulative up to end of selected day)
$totalUnpaidCreditQuery = $db->prepare("
    SELECT COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END), 0) as total_unpaid 
    FROM credit_sales
    WHERE created_at < :businessDayEnd
");
$totalUnpaidCreditQuery->bindParam(':businessDayEnd', $businessDayEnd);
$totalUnpaidCreditQuery->execute();
$totalUnpaidCredit = (float)$totalUnpaidCreditQuery->fetchColumn();

// Get cumulative paid credit sales up to end of selected business day
$cumulativePaidCreditQuery = $db->prepare("
    SELECT COALESCE(SUM(p.amount), 0) as paid_credit
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE p.payment_date < :businessDayEnd
");
$cumulativePaidCreditQuery->bindParam(':businessDayEnd', $businessDayEnd);
$cumulativePaidCreditQuery->execute();
$cumulativePaidCredit = (float)$cumulativePaidCreditQuery->fetchColumn();

// Get paid credit sales for the selected period (matching cash.php logic exactly)
$paidCreditQuery = $db->prepare("
    SELECT COALESCE(SUM(p.amount), 0) 
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.payment_status = 'paid' AND (
        (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
        (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
    )
");
$paidCreditQuery->bindParam(':selectedDate', $selectedDate);
$paidCreditQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$paidCreditQuery->bindParam(':closingTime', $closingTime);
$paidCreditQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$paidCreditQuery->execute();
$paidCreditAmount = (float)$paidCreditQuery->fetchColumn();

// Calculate cash available in till (matching cash.php calculation exactly)
// Uses selected date's transactions only, not cumulative
// NOTE: EFT payments are explicitly excluded from cash available calculations
// Final calculation matching cash.php: $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
$cashAvailableInTill = $totalCashIn + $cashSalesTotal + $paidCreditAmount - $totalCashOut;

// Total revenue includes all sales regardless of payment method (only for selected date)
$totalCashOnHand = $cashSalesTotal + $creditTotal + $eftSalesTotal;

// Update cashSalesTotal to include paid credit amounts (matching the Cash Sales card in reports.php)
$cashSalesDisplayTotal = $cashSalesTotal + $paidCreditAmount;

// Fetch sales data for the table (optimized with timestamp-based filtering)
$sql = "SELECT id, total, created_at, products, sale_type, payment_status, provider_name, creditor_name, payment_date FROM (
    SELECT orders.id, orders.total, orders.created_at, 
        GROUP_CONCAT(order_items.product_name || ' (x' || order_items.quantity || ')', ', ') as products,
        CASE WHEN eft.order_id IS NOT NULL THEN 'eft' ELSE 'cash' END as sale_type,
        'paid' as payment_status,
        eft.wallet_provider as provider_name,
        NULL as creditor_name,
        orders.created_at as payment_date
    FROM orders
    JOIN order_items ON orders.id = order_items.order_id
    LEFT JOIN eft_payments eft ON orders.id = eft.order_id
    WHERE orders.created_at >= :businessDayStart
    AND orders.created_at < :businessDayEnd
    GROUP BY orders.id
    
    UNION ALL
    
    SELECT credit_sales.id, credit_sales.total_amount as total, 
        credit_sales.created_at,
        GROUP_CONCAT(credit_sale_items.product_name || ' (x' || credit_sale_items.quantity || ')', ', ') as products,
        CASE 
            WHEN payment_status = 'paid' THEN 'credit' 
            WHEN payment_status = 'eft' THEN 'eft'
            WHEN payment_status = 'partial' THEN 'partial'
            ELSE 'credit' 
        END as sale_type,
        payment_status,
        NULL as provider_name,
        creditors.name as creditor_name,
        CASE 
            WHEN payment_status = 'unpaid' THEN credit_sales.created_at
            ELSE (SELECT MAX(payment_date) FROM payments WHERE sale_id = credit_sales.id)
        END as payment_date
    FROM credit_sales
    JOIN credit_sale_items ON credit_sales.id = credit_sale_items.sale_id
    LEFT JOIN creditors ON credit_sales.creditor_id = creditors.id
    WHERE (credit_sales.created_at >= :businessDayStart AND credit_sales.created_at < :businessDayEnd)
    OR credit_sales.id IN (
        SELECT sale_id FROM payments 
        WHERE payment_date >= :businessDayStart AND payment_date < :businessDayEnd
    )
    GROUP BY credit_sales.id
)
ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->bindParam(':businessDayStart', $businessDayStart);
$stmt->bindParam(':businessDayEnd', $businessDayEnd);
$stmt->execute();
$salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate HTML for the sales table
$salesTableHtml = '';
if (count($salesData) > 0) {
    foreach($salesData as $row) {
        $salesTableHtml .= "<tr class='hover:bg-gray-50 transition-colors h-6'>
            <td class='py-1 px-3 text-xs font-medium text-gray-800 text-center'>{$row['id']}</td>
            <td class='py-1 px-3 text-center align-middle'>";
        if ($row['sale_type'] === 'credit' && $row['payment_status'] === 'unpaid') {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path fill-rule='evenodd' d='M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z' clip-rule='evenodd'></path></svg>
                <span>Unpaid Credit</span>
            </span>";
        } elseif ($row['sale_type'] === 'partial') {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 border border-yellow-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path d='M10 2a8 8 0 100 16 8 8 0 000-16zm0 14a6 6 0 110-12 6 6 0 010 12z'></path><path d='M10 5a1 1 0 011 1v3.586l2.707 2.707a1 1 0 01-1.414 1.414l-3-3A1 1 0 019 10V6a1 1 0 011-1z'></path></svg>
                <span>Partial Credit (Cash)</span>
            </span>";
        } elseif ($row['payment_status'] === 'eft') {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path d='M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z'></path><path fill-rule='evenodd' d='M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z' clip-rule='evenodd'></path></svg>
                <span>Credit (EFT)</span>
            </span>";
        } elseif ($row['sale_type'] === 'eft') {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 border border-purple-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path d='M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z'></path><path fill-rule='evenodd' d='M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z' clip-rule='evenodd'></path></svg>
                <span>EFT</span>
            </span>";
        } elseif ($row['sale_type'] === 'cash') {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path d='M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z'></path><path fill-rule='evenodd' d='M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z' clip-rule='evenodd'></path></svg>
                <span>Cash</span>
            </span>";
        } elseif ($row['payment_status'] === 'paid' && $row['sale_type'] === 'credit') {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-teal-100 text-teal-800 border border-teal-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path fill-rule='evenodd' d='M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z' clip-rule='evenodd'></path></svg>
                <span>Credit (Cash)</span>
            </span>";
        } else {
            $salesTableHtml .= "<span class='inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 border border-gray-200 shadow-sm'>
                <svg class='w-3 h-3' fill='currentColor' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'><path fill-rule='evenodd' d='M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586l-1.293-1.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 10.586V7z' clip-rule='evenodd'></path></svg>
                <span>" . ucfirst($row['sale_type']) . "</span>
            </span>";
        }
        $salesTableHtml .= "</td>
            <td class='py-1 px-3 text-sm font-bold text-gray-900'>N$" . number_format($row['total'], 2) . "</td>
            <td class='py-1 px-3 text-sm text-gray-600 truncate max-w-xs' title='" . htmlspecialchars($row['products']) . ($row['creditor_name'] ? ' ( ' . htmlspecialchars($row['creditor_name']) . ')' : '') . "'>
                " . htmlspecialchars($row['products']);
                
        if ($row['creditor_name']) {
            $salesTableHtml .= " <span class='italic text-xs text-gray-500'>(" . htmlspecialchars($row['creditor_name']) . ")</span>";
        }
        
        $salesTableHtml .= "</td>
            <td class='py-1 px-3 text-sm text-gray-500'>" . date('d M Y H:i', strtotime($row['created_at'])) . "</td>
            <td class='py-1 px-3'>
                <button onclick='deleteRecord(\"" . ($row['sale_type'] === 'credit' ? 'credit' : 'sales') . "\", \"" . $row['id'] . "\")' class='text-red-600 hover:text-red-800 transition-colors'>
                    <i class='fas fa-trash-alt'></i>
                </button>
            </td>
        </tr>";
    }
} else {
    // No results found
}

// Fetch daily breakdown data for selected date (optimized with timestamp filtering)
$dailyBreakdownQuery = $db->prepare("
    SELECT 
        :selectedDate as sale_date,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'cash' THEN t.amount ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_unpaid' THEN t.amount ELSE 0 END), 0) as credit_unpaid,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_cash' THEN t.amount ELSE 0 END), 0) as credit_cash,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_eft' THEN t.amount ELSE 0 END), 0) as credit_eft,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'eft' THEN t.amount ELSE 0 END), 0) as eft_sales,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE 0 END), 0) as total_sales,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'expense' THEN t.amount ELSE 0 END), 0) as total_expense,
        COALESCE(SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE -t.amount END), 0) as net_amount
    FROM (
        -- Get all order transactions
        SELECT 
            o.total as amount,
            CASE 
                WHEN e.order_id IS NOT NULL THEN 'eft'
                ELSE 'cash'
            END as source,
            'income' as transaction_type
        FROM orders o
        LEFT JOIN eft_payments e ON o.id = e.order_id
        WHERE o.created_at >= :businessDayStart
        AND o.created_at < :businessDayEnd
        
        UNION ALL
        
        -- Include credit sales with their payment types
        SELECT 
            total_amount as amount, 
            CASE 
                WHEN payment_status = 'unpaid' THEN 'credit_unpaid'
                WHEN payment_status = 'eft' THEN 'credit_eft'
                WHEN payment_status = 'paid' THEN 'credit_cash'
                WHEN payment_status = 'partial' THEN 'credit_cash'
                ELSE 'credit_unpaid'
            END as source,
            'income' as transaction_type
        FROM credit_sales
        WHERE created_at >= :businessDayStart
        AND created_at < :businessDayEnd
        
        UNION ALL
        
        -- Include credit payments separately based on payment date
        SELECT 
            p.amount as amount,
            'credit_cash' as source,
            'income' as transaction_type
        FROM payments p
        WHERE p.payment_date >= :businessDayStart
        AND p.payment_date < :businessDayEnd
        
        UNION ALL
        
        -- Include cash-out transactions as expenses
        SELECT 
            amount,
            'cash-out' as source,
            'expense' as transaction_type
        FROM cash_transactions
        WHERE type = 'cash-out'
        AND created_at >= :businessDayStart
        AND created_at < :businessDayEnd
    ) t
");
$dailyBreakdownQuery->bindParam(':selectedDate', $selectedDate);
$dailyBreakdownQuery->bindParam(':businessDayStart', $businessDayStart);
$dailyBreakdownQuery->bindParam(':businessDayEnd', $businessDayEnd);
$dailyBreakdownQuery->execute();
$dailyBreakdownData = $dailyBreakdownQuery->fetchAll(PDO::FETCH_ASSOC);

// Generate HTML for the daily breakdown table with the new income and expense format
$dailyBreakdownTableHtml = '';
if (count($dailyBreakdownData) > 0) {
    foreach($dailyBreakdownData as $day) {
        $netClass = $day['net_amount'] >= 0 ? 'text-teal-700' : 'text-red-700';
        
        // Prepare tooltip content with detailed breakdown
        $tooltipContent = 
            "Cash: N$" . number_format((float)$day['cash_sales'], 2) . 
            "\nEFT: N$" . number_format((float)$day['eft_sales'], 2) .
            "\nCredit (Unpaid): N$" . number_format((float)$day['credit_unpaid'], 2) .
            "\nCredit (Cash): N$" . number_format((float)$day['credit_cash'], 2) .
            "\nCredit (EFT): N$" . number_format((float)$day['credit_eft'], 2);
        
        $dailyBreakdownTableHtml .= "<tr class='hover:bg-gray-50 transition-colors'>
            <td class='py-4 px-6 text-sm font-medium text-gray-800'>" . date('Y-m-d (D)', strtotime($day['sale_date'])) . "</td>
            <td class='py-4 px-6 text-sm font-bold text-blue-700' title='" . htmlspecialchars($tooltipContent) . "'>
                N$" . number_format((float)$day['total_sales'], 2) . "
                <div class='text-xs font-normal text-gray-500 mt-1'>
                    <div>Cash: N$" . number_format((float)$day['cash_sales'], 2) . "</div>
                    <div>EFT: N$" . number_format((float)$day['eft_sales'], 2) . "</div>
                    <div>Credit: N$" . number_format((float)($day['credit_unpaid'] + $day['credit_cash'] + $day['credit_eft']), 2) . "</div>
                </div>
            </td>
            <td class='py-4 px-6 text-sm font-bold text-red-700'>N$" . number_format((float)$day['total_expense'], 2) . "</td>
            <td class='py-4 px-6 text-sm font-bold {$netClass}'>N$" . number_format((float)$day['net_amount'], 2) . "</td>
            <td class='py-4 px-6'>
                <button onclick=\"showTransactionDetails('{$day['sale_date']}')\" class='text-blue-500 hover:text-blue-700'>
                    <i class='fas fa-eye'></i>
                </button>
                <button onclick='deleteDailyRecord(\"" . $day['sale_date'] . "\")' class='text-red-600 hover:text-red-800 transition-colors ml-2'>
                    <i class='fas fa-trash-alt'></i>
                </button>
            </td>
        </tr>";
    }
} else {
    $dailyBreakdownTableHtml = "<tr><td colspan='5' class='py-6 px-6 text-center text-gray-500 '>No income/expense data available for the selected date</td></tr>";
}

// Fetch top selling products for the selected date (optimized)
$topProductsQuery = $db->prepare("
    SELECT 
        t.product_name, 
        SUM(t.quantity) as total_qty, 
        SUM(t.price * t.quantity) as historical_value,
        p.price as current_price,
        p.id as product_id
    FROM (
        SELECT 
            oi.product_name, 
            oi.quantity, 
            oi.price,
            o.created_at 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.created_at >= :businessDayStart
        AND o.created_at < :businessDayEnd
        
        UNION ALL
        
        SELECT 
            csi.product_name, 
            csi.quantity, 
            csi.price,
            cs.created_at
        FROM credit_sale_items csi
        JOIN credit_sales cs ON csi.sale_id = cs.id
        WHERE cs.created_at >= :businessDayStart
        AND cs.created_at < :businessDayEnd
    ) t
    LEFT JOIN products p ON t.product_name = p.name
    GROUP BY t.product_name
    ORDER BY total_qty DESC
");
$topProductsQuery->bindParam(':businessDayStart', $businessDayStart);
$topProductsQuery->bindParam(':businessDayEnd', $businessDayEnd);
$topProductsQuery->execute();
$topProducts = $topProductsQuery->fetchAll(PDO::FETCH_ASSOC);

// Generate HTML for the top products table
$topProductsTableHtml = '';
if (count($topProducts) > 0) {
    foreach ($topProducts as $product) {
        $currentPrice = $product['current_price'] ?? 0; // Handle null price
        $totalValue = $currentPrice * $product['total_qty'];
        $productId = $product['product_id'] ?? 'N/A';
        $productNameWithQty = htmlspecialchars($product['product_name']) . ' (x' . $product['total_qty'] . ')';
        
        $topProductsTableHtml .= "<tr class='hover:bg-gray-50 transition-colors'>
            <td class='py-4 px-6 text-sm font-medium text-gray-500 text-center'>" . $productId . "</td>
            <td class='py-4 px-6 text-sm font-medium text-gray-800'>" . $productNameWithQty . "</td>
            <td class='py-4 px-6 text-sm font-semibold text-gray-900'>
                <span class='inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-bold bg-gray-500 text-gray-200 shadow-sm border border-gray-300' style='min-width:2.2em; min-height:2.2em;'>
                    " . $product['total_qty'] . "
                </span>
            </td>
            <td class='py-4 px-6 text-sm font-semibold text-gray-900'>N$" . number_format($currentPrice, 2) . "</td>
            <td class='py-4 px-6 text-sm font-bold text-teal-700'>N$" . number_format($totalValue, 2) . "</td>
            <td class='py-4 px-6'>
                <button onclick='deleteProductRecord(\"" . htmlspecialchars(addslashes($product['product_name'])) . "\")' class='text-red-600 hover:text-red-800 transition-colors'>
                    <i class='fas fa-trash-alt'></i>
                </button>
            </td>
        </tr>";
    }
} else {
    $topProductsTableHtml .= "<tr></tr>";
}

// Prepare the response
$response = [
    'creditTotal' => $creditTotal,
    'unpaidTotal' => $unpaidTotal,
    'totalUnpaidCredit' => $unpaidTotal,
    'cashSalesTotal' => $cashSalesDisplayTotal,
    'cashSalesRaw' => $cashSalesTotal,
    'eftSalesTotal' => $totalEftPayments,
    'eftOrdersTotal' => $eftSalesTotal,
    'eftCreditTotal' => $eftCreditSalesTotal,
    'totalCashIn' => $totalCashIn,
    'totalCashOut' => $totalCashOut,
    'cashAvailableInTill' => $cashAvailableInTill,
    'totalCashOnHand' => $totalCashOnHand,
    'paidCreditAmount' => $paidCreditAmount,
    'salesTableHtml' => $salesTableHtml,
    'dailyBreakdownTableHtml' => $dailyBreakdownTableHtml,
    'topProductsTableHtml' => $topProductsTableHtml,
    // Add income and expense data with credit payment breakdowns
    'incomeExpenseData' => count($dailyBreakdownData) > 0 ? [
        'sale_date' => $dailyBreakdownData[0]['sale_date'] ?? null,
        'cash_sales' => (float)($dailyBreakdownData[0]['cash_sales'] ?? 0),
        'credit_unpaid' => (float)($dailyBreakdownData[0]['credit_unpaid'] ?? 0),
        'credit_cash' => (float)($dailyBreakdownData[0]['credit_cash'] ?? 0),
        'credit_eft' => (float)($dailyBreakdownData[0]['credit_eft'] ?? 0),
        'eft_sales' => (float)($dailyBreakdownData[0]['eft_sales'] ?? 0),
        'total_sales' => (float)($dailyBreakdownData[0]['total_sales'] ?? 0),
        'total_expense' => (float)($dailyBreakdownData[0]['total_expense'] ?? 0),
        'net_amount' => (float)($dailyBreakdownData[0]['net_amount'] ?? 0)
    ] : null
];

// Return the response as JSON
echo json_encode($response);

$db = null; // Close the database connection