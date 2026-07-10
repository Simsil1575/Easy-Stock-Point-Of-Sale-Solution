<?php
// Start session and handle authentication (moved from sidebar.php to avoid header issues)
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00'; // Default to 00:00 if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '00:00';
}

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}
require_once __DIR__ . '/../ensure_laybye_schema.php';
ensureLaybyeSchema($db);

// Ensure tab tips column exists (ignore if already added)
try {
    $db->exec("ALTER TABLE tab_payments ADD COLUMN tip_amount DECIMAL(10,2) NOT NULL DEFAULT 0");
} catch (PDOException $e) {
    // Column may already exist
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
$isAfterMidnight = $closingHour < 12;

// Prepare date calculation snippet for SQL
$dateSql = "
    CASE 
        WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
        THEN date(datetime(created_at, '-1 day'))
        ELSE date(created_at)
    END AS business_date
";

// Fetch distinct dates where transactions occurred, considering business closing time
$distinctDatesQuery = $db->prepare("
    SELECT DISTINCT business_date
    FROM (
        SELECT
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date
        FROM orders
        UNION ALL
        SELECT
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date
        FROM credit_sales
        UNION ALL
        SELECT
            CASE 
                WHEN strftime('%H:%M', payment_date) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(payment_date, '-1 day'))
                ELSE date(payment_date)
            END AS business_date
        FROM payments
    )
    ORDER BY business_date DESC
");
$distinctDatesQuery->execute();
$distinctDates = $distinctDatesQuery->fetchAll(PDO::FETCH_COLUMN);

// Always add today's date if it's not already in the list
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

if (!in_array($today, $distinctDates)) {
    array_unshift($distinctDates, $today); // Add today at the beginning of the array
}

// Also add yesterday's date if it's not already in the list
if (!in_array($yesterday, $distinctDates)) {
    array_unshift($distinctDates, $yesterday); // Add yesterday at the beginning of the array
}

// Determine which date to show by default based on current time vs closing time
$currentTime = date('H:i');

// If current time is before closing time, show yesterday's data
// If current time is after closing time, show today's data
$defaultDate = ($currentTime < $closingTime) ? $yesterday : $today;

// Handle date selection
$selectedDate = isset($_POST['date']) ? $_POST['date'] : $defaultDate;

// Calculate the next day date for queries
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch cash sales total with business day logic
$cashSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(
        o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
    ), 0) 
    FROM orders o
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
");
$cashSalesQuery->bindParam(':selectedDate', $selectedDate);
$cashSalesQuery->bindParam(':nextDay', $nextDay);
$cashSalesQuery->execute();
$cashSalesTotal = $cashSalesQuery->fetchColumn() ?: 0;

// Get cumulative cash sales up to selected date
$cumulativeCashSalesQuery = $db->prepare("
    SELECT SUM(total) FROM (
        SELECT 
            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) as total, 
        CASE 
            WHEN strftime('%H:%M', o.created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
            THEN date(datetime(o.created_at, '-1 day'))
            ELSE date(o.created_at)
        END AS business_date
        FROM orders o
    ) 
    WHERE business_date <= :selectedDate
");
$cumulativeCashSalesQuery->bindParam(':selectedDate', $selectedDate);
$cumulativeCashSalesQuery->execute();
$cumulativeCashSales = $cumulativeCashSalesQuery->fetchColumn() ?: 0;

// Get cash in/out with business day logic - for ALL TIME, not just selected date
$cashInQuery = $db->prepare("
    SELECT SUM(amount) FROM cash_transactions 
    WHERE type = 'cash-in'
");
$cashInQuery->execute();
$totalCashIn = $cashInQuery->fetchColumn() ?: 0;

$cashOutQuery = $db->prepare("
    SELECT SUM(amount) FROM cash_transactions 
    WHERE type = 'cash-out'
");
$cashOutQuery->execute();
$totalCashOut = $cashOutQuery->fetchColumn() ?: 0;

// Calculate EFT payments with business day logic
$eftSalesQuery = $db->prepare("
    SELECT SUM(e.amount) 
    FROM eft_payments e 
    JOIN orders o ON e.order_id = o.id 
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
");
$eftSalesQuery->bindParam(':selectedDate', $selectedDate);
$eftSalesQuery->bindParam(':nextDay', $nextDay);
$eftSalesQuery->execute();
$eftSalesTotal = $eftSalesQuery->fetchColumn() ?: 0;

// Get EFT amounts received for credit sales on payment dates (includes mixed-settled credit sales)
$eftCreditSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM eft_payments e
    JOIN credit_sales cs ON e.order_id = cs.id
    WHERE (
        (DATE(e.payment_date) = :selectedDate AND strftime('%H:%M', e.payment_date) >= '$closingTime') OR
        (DATE(e.payment_date) = :nextDay AND strftime('%H:%M', e.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
");
$eftCreditSalesQuery->bindParam(':selectedDate', $selectedDate);
$eftCreditSalesQuery->bindParam(':nextDay', $nextDay);
$eftCreditSalesQuery->execute();
$eftCreditSalesTotal = $eftCreditSalesQuery->fetchColumn() ?: 0;

// Total EFT payments including both regular EFT and credit sales with payment_status 'eft'
$totalEftPayments = $eftSalesTotal + $eftCreditSalesTotal;

// Fetch credit sales total and unpaid balances with business day logic
$creditSalesQuery = $db->prepare("
    SELECT 
    SUM(CASE 
        WHEN payment_status IN ('unpaid', 'partial') THEN total_amount 
        ELSE 0 
    END) as total_issued,
    SUM(CASE 
        WHEN payment_status = 'unpaid' THEN total_amount - paid_amount 
            WHEN payment_status = 'partial' THEN total_amount - paid_amount 
            ELSE 0 
    END) as total_unpaid,
    SUM(CASE 
        WHEN payment_status = 'partial' THEN paid_amount 
        ELSE 0 
    END) as total_partial_paid
    FROM credit_sales 
    WHERE (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
");
$creditSalesQuery->bindParam(':selectedDate', $selectedDate);
$creditSalesQuery->bindParam(':nextDay', $nextDay);
$creditSalesQuery->execute();
$creditData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
$creditTotal = $creditData['total_issued'] ?: 0;
$unpaidTotal = $creditData['total_unpaid'] ?: 0;
$partialPaidTotal = $creditData['total_partial_paid'] ?: 0;

// Use unpaid total for the selected day instead of all-time unpaid credit
$totalUnpaidCredit = $unpaidTotal;

// Get cumulative paid credit sales up to selected date
$cumulativePaidCreditQuery = $db->prepare("
    SELECT SUM(p.amount) as paid_credit
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE DATE(p.payment_date) <= :selectedDate
");
$cumulativePaidCreditQuery->bindParam(':selectedDate', $selectedDate);
$cumulativePaidCreditQuery->execute();
$cumulativePaidCredit = $cumulativePaidCreditQuery->fetchColumn() ?: 0;

// Get paid credit sales for the selected period (for daily reports)
$paidCreditQuery = $db->prepare("
    SELECT 
        SUM(p.amount) as paid_credit,
        COUNT(DISTINCT cs.id) as total_transactions
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE (
        (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= '$closingTime') OR
        (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    ) AND cs.payment_status IN ('paid', 'partial', 'eft', 'paid_mixed')
");
$paidCreditQuery->bindParam(':selectedDate', $selectedDate);
$paidCreditQuery->bindParam(':nextDay', $nextDay);
$paidCreditQuery->execute();
$paidCreditData = $paidCreditQuery->fetch(PDO::FETCH_ASSOC);
$paidCreditAmount = $paidCreditData['paid_credit'] ?: 0;
$totalTransactions = $paidCreditData['total_transactions'] ?: 0;

// Update cash sales display total to include partial payments
$cashSalesDisplayTotal = $cashSalesTotal + $paidCreditAmount - $eftCreditSalesTotal ;

// Total revenue includes all sales regardless of payment method (only for selected date)
$totalCashOnHand = $cashSalesTotal + $creditTotal + $paidCreditAmount + $totalEftPayments -$partialPaidTotal - $eftCreditSalesTotal;

// Fetch top selling products with business day logic
$topProductsQuery = $db->prepare("
    SELECT 
        t.product_name, 
        SUM(CASE WHEN t.payment_method = 'cash' THEN t.quantity ELSE 0 END) as cash_qty,
        SUM(CASE WHEN t.payment_method = 'eft' THEN t.quantity ELSE 0 END) as eft_qty,
        SUM(CASE WHEN t.payment_method = 'credit' THEN t.quantity ELSE 0 END) as credit_qty,
        SUM(t.quantity) as total_qty, 
        SUM(t.price * t.quantity) as historical_value,
        COALESCE(p.price, t.price) as current_price,
        COALESCE(p.id, 'Deleted') as id,
        GROUP_CONCAT(DISTINCT t.payment_method) as payment_methods
    FROM (
        SELECT 
            product_name, 
            quantity, 
            price,
            created_at,
            CASE 
                WHEN e.order_id IS NOT NULL THEN 'eft'
                ELSE 'cash'
            END as payment_method
        FROM order_items
        JOIN orders ON order_items.order_id = orders.id
        LEFT JOIN eft_payments e ON orders.id = e.order_id
        
        UNION ALL
        
        SELECT 
            product_name, 
            quantity, 
            price,
            created_at,
            CASE 
                WHEN cs.payment_status = 'eft' THEN 'eft'
                WHEN cs.payment_status = 'paid_mixed' THEN 'credit'
                WHEN cs.payment_status = 'paid' THEN 'cash'
                ELSE 'credit'
            END as payment_method
        FROM credit_sale_items
        JOIN credit_sales cs ON credit_sale_items.sale_id = cs.id
    ) t
    LEFT JOIN products p ON t.product_name = p.name
    WHERE (
        (DATE(t.created_at) = :selectedDate AND strftime('%H:%M', t.created_at) >= '$closingTime') OR
        (DATE(t.created_at) = :nextDay AND strftime('%H:%M', t.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    GROUP BY t.product_name
    ORDER BY total_qty DESC
");
$topProductsQuery->bindParam(':selectedDate', $selectedDate);
$topProductsQuery->bindParam(':nextDay', $nextDay);
$topProductsQuery->execute();
$topProducts = $topProductsQuery->fetchAll(PDO::FETCH_ASSOC);

// Damaged stock for selected business day (same window as sales)
$damagedStockRowsQuery = $db->prepare("
    SELECT dg.id,
           dg.product_id,
           p.name AS product_name,
           dg.quantity,
           dg.reason,
           dg.date AS damaged_at,
           (CAST(dg.quantity AS REAL) * COALESCE(p.price, 0)) AS line_value
    FROM damaged_goods dg
    INNER JOIN products p ON p.id = dg.product_id
    WHERE (
        (DATE(dg.date) = :selectedDateDamaged AND strftime('%H:%M', dg.date) >= '$closingTime') OR
        (DATE(dg.date) = :nextDayDamaged AND strftime('%H:%M', dg.date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    ORDER BY dg.date DESC
");
$damagedStockRowsQuery->bindParam(':selectedDateDamaged', $selectedDate);
$damagedStockRowsQuery->bindParam(':nextDayDamaged', $nextDay);
$damagedStockRowsQuery->execute();
$damagedStockRows = $damagedStockRowsQuery->fetchAll(PDO::FETCH_ASSOC);
$damagedStockTotalQty = 0.0;
$damagedStockTotalValue = 0.0;
foreach ($damagedStockRows as $dr) {
    $damagedStockTotalQty += (float) $dr['quantity'];
    $damagedStockTotalValue += (float) $dr['line_value'];
}

// Fetch sales data with business day logic
$ordersQuery = $db->prepare("
    WITH order_sums AS (
        SELECT order_id, SUM(amount) AS eft_sum, MIN(wallet_provider) AS provider_name
        FROM eft_payments
        GROUP BY order_id
    ), order_products AS (
        SELECT oi.order_id, GROUP_CONCAT(oi.product_name || ' (x' || oi.quantity || ')', ', ') AS products
        FROM order_items oi
        GROUP BY oi.order_id
    ), order_tab_info AS (
        SELECT
            tp.order_id,
            t.tab_name,
            MIN(tp.cashier_id) as tab_cashier_id,
            COALESCE(SUM(tp.tip_amount), 0) as tips
        FROM tab_payments tp
        JOIN tabs t ON tp.tab_id = t.id
        GROUP BY tp.order_id, t.tab_name
    ), order_laybye AS (
        SELECT lp.order_id, lp.laybye_id, lp.payment_kind AS laybye_payment_kind,
               la.reference AS laybye_reference, cr.name AS laybye_creditor_name
        FROM laybye_payments lp
        INNER JOIN laybye_accounts la ON la.id = lp.laybye_id
        LEFT JOIN creditors cr ON cr.id = la.creditor_id
    ), order_splits AS (
        SELECT o.id, o.total, o.created_at, o.cashier_id, op.products, os.eft_sum, os.provider_name,
               MAX(o.total - COALESCE(os.eft_sum,0), 0) AS cash_amount,
               MAX(COALESCE(os.eft_sum,0), 0) AS eft_amount,
               oti.tab_name, oti.tab_cashier_id, COALESCE(oti.tips, 0) as tips,
               MAX(olb.laybye_id) AS laybye_id, MAX(olb.laybye_reference) AS laybye_reference,
               MAX(olb.laybye_payment_kind) AS laybye_payment_kind, MAX(olb.laybye_creditor_name) AS laybye_creditor_name
        FROM orders o
        LEFT JOIN order_sums os ON os.order_id = o.id
        LEFT JOIN order_products op ON op.order_id = o.id
        LEFT JOIN order_tab_info oti ON oti.order_id = o.id
        LEFT JOIN order_laybye olb ON olb.order_id = o.id
        WHERE (
            (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
        GROUP BY o.id
    )
    SELECT id, cash_amount AS total, tips, created_at, products, 'cash' AS sale_type, 'paid' AS payment_status, NULL AS provider_name, NULL AS creditor_name, cashier_id, tab_name, tab_cashier_id,
           laybye_id, laybye_reference, laybye_payment_kind, laybye_creditor_name
    FROM order_splits
    WHERE cash_amount > 0
    UNION ALL
    SELECT id, eft_amount AS total, tips, created_at, products, 'eft' AS sale_type, 'paid' AS payment_status, provider_name, NULL AS creditor_name, cashier_id, tab_name, tab_cashier_id,
           laybye_id, laybye_reference, laybye_payment_kind, laybye_creditor_name
    FROM order_splits
    WHERE eft_amount > 0
    ORDER BY created_at DESC
");

$creditQuery = $db->prepare("
    SELECT 
        cs.id, 
        cs.total_amount as total, 
        0 as tips,
        cs.created_at,
        GROUP_CONCAT(csi.product_name || ' (x' || csi.quantity || ')', ', ') as products,
        CASE 
            WHEN cs.payment_status = 'paid' THEN 'paid' 
            WHEN cs.payment_status = 'eft' THEN 'eft'
            WHEN cs.payment_status = 'paid_mixed' THEN 'mixed'
            WHEN cs.payment_status = 'partial' THEN 'partial'
            ELSE 'credit' 
        END as sale_type,
        cs.payment_status,
        NULL as provider_name,
        cr.name as creditor_name,
        (SELECT MAX(payment_date) FROM payments WHERE sale_id = cs.id) as payment_date,
        cs.paid_amount as paid_amount,
        COALESCE((SELECT cashier_id FROM payments WHERE sale_id = cs.id ORDER BY payment_date DESC LIMIT 1), cs.cashier_id) as cashier_id,
        NULL as tab_name,
        NULL as tab_cashier_id,
        NULL as laybye_id,
        NULL as laybye_reference,
        NULL as laybye_payment_kind,
        NULL as laybye_creditor_name
    FROM credit_sales cs
    JOIN credit_sale_items csi ON cs.id = csi.sale_id
    LEFT JOIN creditors cr ON cs.creditor_id = cr.id
    WHERE (
        -- Show unpaid/partial credit sales on their original creation date
        (
            (DATE(cs.created_at) = :selectedDate AND strftime('%H:%M', cs.created_at) >= '$closingTime') OR
            (DATE(cs.created_at) = :nextDay AND strftime('%H:%M', cs.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        ) AND cs.payment_status IN ('unpaid', 'partial')
    )
    OR (
        -- Show paid/eft credit sales only on their payment date
        cs.payment_status IN ('paid', 'eft', 'partial', 'paid_mixed') AND cs.id IN (
            SELECT sale_id FROM payments 
            WHERE (
                (DATE(payment_date) = :selectedDate AND strftime('%H:%M', payment_date) >= '$closingTime') OR
                (DATE(payment_date) = :nextDay AND strftime('%H:%M', payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
            )
        )
    )
    GROUP BY cs.id
    ORDER BY cs.created_at DESC
");
$ordersQuery->bindParam(':selectedDate', $selectedDate);
$ordersQuery->bindParam(':nextDay', $nextDay);
$ordersQuery->execute();
$ordersResult = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);

$creditQuery->bindParam(':selectedDate', $selectedDate);
$creditQuery->bindParam(':nextDay', $nextDay);
$creditQuery->execute();
$creditResult = $creditQuery->fetchAll(PDO::FETCH_ASSOC);

// Combine results
$salesData = array_merge($ordersResult, $creditResult);

// Sort combined results by created_at in descending order (most recent first)
usort($salesData, function($a, $b) {
    $aDate = isset($a['payment_date']) && $a['payment_date'] ? strtotime($a['payment_date']) : strtotime($a['created_at']);
    $bDate = isset($b['payment_date']) && $b['payment_date'] ? strtotime($b['payment_date']) : strtotime($b['created_at']);
    return $bDate - $aDate;
});

// Fetch daily breakdown data for the date with business day logic
$dailyBreakdownQuery = $db->prepare("
    SELECT 
        t.business_date as sale_date,
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'cash' THEN t.amount ELSE 0 END) as cash_sales,
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_unpaid' THEN t.amount ELSE 0 END) as credit_unpaid,
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_payment' THEN t.amount ELSE 0 END) as credit_cash,
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'credit_eft' THEN t.amount ELSE 0 END) as credit_eft,
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'eft' THEN t.amount ELSE 0 END) as eft_sales,
        SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE 0 END) as total_sales,
        SUM(CASE WHEN t.transaction_type = 'expense' THEN t.amount ELSE 0 END) as total_expense,
        SUM(CASE WHEN t.transaction_type = 'income' THEN t.amount ELSE -t.amount END) as net_amount
    FROM (
        -- Get all order transactions split into cash and EFT components
        SELECT 
            CASE 
                WHEN strftime('%H:%M', o.created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(o.created_at, '-1 day'))
                ELSE date(o.created_at)
            END AS business_date, 
            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) as amount,
            'cash' as source,
            'income' as transaction_type
        FROM orders o
        WHERE o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) > 0
        
        UNION ALL
        
        SELECT 
            CASE 
                WHEN strftime('%H:%M', o.created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(o.created_at, '-1 day'))
                ELSE date(o.created_at)
            END AS business_date, 
            COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) as amount,
            'eft' as source,
            'income' as transaction_type
        FROM orders o
        WHERE COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) > 0
        
        UNION ALL
        
        -- Include only unpaid/partial credit sales on their creation date
        SELECT 
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date, 
            total_amount as amount, 
            CASE 
                WHEN payment_status = 'unpaid' THEN 'credit_unpaid'
                WHEN payment_status = 'partial' THEN 'credit_unpaid'
                ELSE 'credit_unpaid'
            END as source,
            'income' as transaction_type
        FROM credit_sales
        WHERE payment_status IN ('unpaid', 'partial')
        
        UNION ALL
        
        -- Include credit payments on payment date based on payment type
        SELECT 
            CASE 
                WHEN strftime('%H:%M', p.payment_date) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(p.payment_date, '-1 day'))
                ELSE date(p.payment_date)
            END AS business_date,
            p.amount as amount,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM eft_payments ep
                    WHERE ep.order_id = p.sale_id AND ABS(CAST(ep.amount AS REAL) - CAST(p.amount AS REAL)) < 0.021
                    AND date(ep.payment_date) = date(p.payment_date)
                    AND strftime('%H:%M', ep.payment_date) = strftime('%H:%M', p.payment_date)
                ) THEN 'credit_eft'
                ELSE 'credit_payment'
            END as source,
            'income' as transaction_type
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        
        UNION ALL
        
        -- Include cash-out transactions as expenses
        SELECT 
            date(created_at) as business_date,
            amount,
            'cash-out' as source,
            'expense' as transaction_type
        FROM cash_transactions
        WHERE type = 'cash-out'
    ) t
    WHERE business_date = :selectedDate
    GROUP BY sale_date
");
$dailyBreakdownQuery->bindParam(':selectedDate', $selectedDate);
$dailyBreakdownQuery->execute();
$dailyBreakdown = $dailyBreakdownQuery->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
    <script src="../navigation.js" async></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../src/jquery-3.6.0.min.js"></script>


    <style>
        :root {
            --table-row-height: 27px;
        }
        /* Disable text selection for the entire UI */
 
        .sidebar {
            position: fixed;
            height: 100%;
            width: 250px; /* Ensure sidebar has a fixed width */
        }
        .content {
            margin-left: 250px; /* Adjust this value based on the width of your sidebar */
            width: calc(100vw - 250px); /* Ensure content width fits within the viewport */
            overflow-x: hidden; /* Prevent horizontal overflow */
        }
        .container {
            max-width: 100vw; /* Ensure container does not exceed viewport width */
            padding: 0 1rem; /* Add some padding for better spacing */
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000;
        }
        
        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        
        .hamburger span:nth-child(1) {
            top: 0px;
        }
        
        .hamburger span:nth-child(2) {
            top: 10px;
        }
        
        .hamburger span:nth-child(3) {
            top: 20px;
        }
        
        .hamburger.open span:nth-child(1) {
            top: 10px;
            transform: rotate(135deg);
        }
        
        .hamburger.open span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }
        
        .hamburger.open span:nth-child(3) {
            top: 10px;
            transform: rotate(-135deg);
        }
        
        /* Mobile sidebar overlay */
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
            }
            
            .container {
                padding: 1rem;
            }
        }
        
        /* Premium shadcn grey theme */
        .bg-card {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
        }
        
        .bg-muted {
            background-color: #f9fafb;
        }
        
        .border-border {
            border-color: #e5e7eb;
        }
        
        .text-muted-foreground {
            color: #6b7280;
        }
        
        .text-card-foreground {
            color: #111827;
        }
        
        .hover\:bg-accent:hover {
            background-color: #f3f4f6;
        }
        
        .hover\:bg-accent\/50:hover {
            background-color: rgba(243, 244, 246, 0.5);
        }
        
        /* Compact Table Styles */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.75rem !important; /* 12px */
            table-layout: fixed; /* Ensure table does not exceed container width */
        }
        th, td {
            padding: 0.375rem 0.5rem !important; /* 6px 8px - very compact */
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            color: #374151;
            font-weight: 500;
            vertical-align: middle;
            overflow: hidden; /* Prevent content from overflowing */
            text-overflow: ellipsis; /* Add ellipsis for overflow text */
            white-space: nowrap; /* Prevent text from wrapping */
            height: var(--table-row-height) !important;
            line-height: 1.2 !important;
        }
        th {
            background-color: #f9fafb; 
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            font-size: 0.7rem !important;
            letter-spacing: 0.025em;
            height: calc(var(--table-row-height) + 0.5rem) !important;
        }
        td:nth-child(2),
        td:nth-child(3) {
            font-weight: 600;
            color: #111827;
        }
        tr {
            transition: all 0.2s ease;
            height: var(--table-row-height) !important;
        }
        tr:hover {
            background-color: #f3f4f6;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .table-container {
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            width: 100%; /* Ensure table container fits within the viewport */
        }
        /* Make status badges smaller */
        td span.inline-flex {
            font-size: 0.7rem !important;
            padding: 0.15rem 0.375rem !important;
            height: var(--table-row-height) !important;
            line-height: 1.2 !important;
            display: inline-flex !important;
            align-items: center !important;
            margin: 0 !important;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); /* Ensure grid items fit within the viewport */
            gap: 1rem;
        }
        .bg-header {
            background-color: #f3f4f6;
            border-bottom: 2px solid #e5e7eb;
        }
        .sort-icon {
            opacity: 0.5;
            transition: all 0.2s;
        }
        th:hover .sort-icon {
            opacity: 1;
        }
        
        /* Allow products column to wrap text */
        .products-cell {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: unset !important;
            vertical-align: top !important;
            height: auto !important;
            min-height: var(--table-row-height) !important;
            line-height: 1.4 !important;
            padding: 0.5rem 0.5rem !important;
        }
        
        /* Ensure parent row can expand */
        .expandable-row {
            height: auto !important;
        }
        
        /* Mobile Vertical Table Structure */
        @media (max-width: 768px) {
            .table-container {
                overflow: visible;
                background: #f3f4f6;
            }
            
            /* Hide table headers on mobile */
            table thead {
                display: none;
            }
            
            /* Convert table rows to compact cards */
            table tbody tr {
                display: block;
                width: 100%;
                margin-bottom: 0.75rem;
                background: white;
                border: 2px solid #d1d5db;
                border-radius: 0.375rem;
                padding: 0.5rem;
                box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
                height: auto !important;
                position: relative;
            }
            
            /* Convert table cells to compact inline blocks */
            table tbody td {
                display: flex;
                align-items: center;
                width: 100% !important;
                padding: 0.375rem 0.25rem !important;
                text-align: left !important;
                border: none !important;
                border-bottom: 1px solid #f3f4f6 !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: unset !important;
                height: auto !important;
                line-height: 1.3 !important;
                gap: 0.5rem;
                font-size: 0.8rem !important;
                color: #111827;
            }
            
            /* Remove border from last visible cell in each row */
            table tbody td:last-child:not([data-label="Action"]) {
                border-bottom: none !important;
            }
            
            /* If Action is the last child, remove border from the cell before it */
            table tbody tr td[data-label="Action"]:last-child {
                border-bottom: none !important;
            }
            
            table tbody tr td[data-label="Action"]:last-child + td,
            table tbody tr td:nth-last-child(2):not([data-label="Action"]) {
                border-bottom: none !important;
            }
            
            /* Add labels inline with data using CSS */
            table tbody td::before {
                content: attr(data-label) ":";
                display: inline-block;
                font-weight: 600;
                font-size: 0.7rem;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.025em;
                min-width: 4rem;
                flex-shrink: 0;
            }
            
            /* Center align specific cells */
            table tbody td[data-label="ID"] {
                justify-content: flex-start;
            }
            
            /* Hide Action cell from normal flow but keep button visible */
            table tbody td[data-label="Action"] {
                position: absolute;
                top: 0.5rem;
                right: 0.5rem;
                width: auto !important;
                padding: 0 !important;
                border: none !important;
                justify-content: flex-end;
            }
            
            table tbody td[data-label="Action"]::before {
                display: none; /* Hide label for Action column */
            }
            
            /* Products cell special handling */
            table tbody td.products-cell {
                padding: 0.375rem 0.25rem !important;
                align-items: flex-start;
            }
            
            /* Action button styling on mobile - positioned in top right */
            table tbody td[data-label="Action"] button {
                width: auto;
                height: 2rem;
                min-height: 2rem;
                padding: 0.375rem 0.625rem;
                justify-content: center;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.15);
                border: 1px solid #9ca3af;
            }
            
            /* Ensure content inside cells wraps properly and takes remaining space */
            table tbody td > div {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 0.25rem;
                flex: 1;
                min-width: 0;
                justify-content: flex-start !important;
            }
            
            table tbody td > span:not(::before),
            table tbody td > button {
                flex: 1;
                min-width: 0;
            }
            
            /* Ensure badges are aligned to the left */
            table tbody td > div span.inline-flex {
                flex: 0 0 auto;
                margin-left: 0;
            }
            
            /* Remove hover effect on mobile cards */
            table tbody tr:hover {
                background: white;
            }
            
            /* Ensure badges and buttons display properly */
            table tbody td span.inline-flex {
                display: inline-flex !important;
                width: auto !important;
                font-size: 0.7rem !important;
                padding: 0.2rem 0.4rem !important;
            }
            
            /* Compact badge styling */
            table tbody td span.inline-flex svg {
                width: 0.75rem !important;
                height: 0.75rem !important;
            }
            
            /* Mobile Pagination - Fit in one row */
            .bg-gray-50.border-t {
                padding: 0.5rem 0.375rem !important;
                overflow-x: visible !important;
            }
            
            .bg-gray-50.border-t > div {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                align-items: center !important;
                width: 100% !important;
                min-width: 0 !important;
                overflow: visible !important;
            }
            
            /* Ensure parent containers don't restrict pagination */
            .bg-white.shadow-lg {
                overflow-x: visible !important;
            }
            
            /* Compact button groups */
            .bg-gray-50.border-t > div > div {
                display: flex !important;
                gap: 0.25rem !important;
                flex-shrink: 0;
            }
            
            /* Left button group - first and prev */
            .bg-gray-50.border-t > div > div:first-child {
                flex-shrink: 0;
            }
            
            /* Right button group - next and last */
            .bg-gray-50.border-t > div > div:last-child {
                flex-shrink: 0;
            }
            
            /* First/Last buttons - icon only, smaller */
            .bg-gray-50.border-t button#firstPage,
            .bg-gray-50.border-t button#lastPage,
            .bg-gray-50.border-t button#topProductsFirstPage,
            .bg-gray-50.border-t button#topProductsLastPage {
                padding: 0.375rem !important;
                min-width: 2rem !important;
                width: 2rem !important;
            }
            
            .bg-gray-50.border-t button#firstPage svg,
            .bg-gray-50.border-t button#lastPage svg,
            .bg-gray-50.border-t button#topProductsFirstPage svg,
            .bg-gray-50.border-t button#topProductsLastPage svg {
                width: 1rem !important;
                height: 1rem !important;
                margin: 0 !important;
            }
            
            /* Prev/Next buttons - compact text */
            .bg-gray-50.border-t button#prevPage,
            .bg-gray-50.border-t button#nextPage,
            .bg-gray-50.border-t button#topProductsPrevPage,
            .bg-gray-50.border-t button#topProductsNextPage {
                padding: 0.375rem 0.4rem !important;
                font-size: 0.65rem !important;
                min-width: auto !important;
                white-space: nowrap;
            }
            
            .bg-gray-50.border-t button#prevPage svg,
            .bg-gray-50.border-t button#nextPage svg,
            .bg-gray-50.border-t button#topProductsPrevPage svg,
            .bg-gray-50.border-t button#topProductsNextPage svg {
                width: 0.875rem !important;
                height: 0.875rem !important;
            }
            
            /* Center section - compact and flexible */
            .bg-gray-50.border-t > div > div:nth-child(2) {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                flex-shrink: 1;
                min-width: 0;
                max-width: 100%;
                overflow: hidden;
            }
            
            /* Page number text - smaller and compact */
            .bg-gray-50.border-t span[id*="PageNumber"] {
                font-size: 0.65rem !important;
                white-space: nowrap;
                flex-shrink: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 5rem;
            }
            
            /* Page input - compact */
            .bg-gray-50.border-t input[type="number"] {
                width: 2.5rem !important;
                padding: 0.375rem 0.375rem !important;
                font-size: 0.65rem !important;
                min-width: 2.5rem;
                max-width: 2.5rem;
            }
            
            /* Go button - compact */
            .bg-gray-50.border-t input[type="number"] + button {
                padding: 0.375rem 0.5rem !important;
                font-size: 0.65rem !important;
                white-space: nowrap;
            }
            
            /* All pagination buttons - consistent height */
            .bg-gray-50.border-t button {
                height: 2rem !important;
                min-height: 2rem !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))" class="overflow-x-hidden">

    <div class="flex min-h-screen">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <!-- Fixed Header -->
            <div class="fixed top-0 left-0 lg:left-64 right-0 z-50 bg-gray-50 py-3 lg:py-4 flex items-center px-3 sm:px-4 lg:px-8 shadow-sm">
                <div class="w-full flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 sm:gap-4">
                    <div class="flex items-center gap-2 sm:gap-3 lg:gap-4 flex-shrink-0 min-w-0">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 rounded flex-shrink-0" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-lg sm:text-xl lg:text-2xl font-bold whitespace-nowrap flex-shrink-0">Daily Report</h1>
                        <a href="weekly_sales.php" class="inline-flex items-center px-2 sm:px-3 lg:px-4 py-1.5 sm:py-2 text-xs sm:text-sm border border-gray-300 rounded-md shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out whitespace-nowrap flex-shrink-0">
                            <i class="fas fa-calendar-week mr-1 sm:mr-2"></i>
                            <span class="hidden sm:inline">Weekly Sales</span>
                            <span class="sm:hidden">Weekly</span>
                        </a>
                        <a href="monthly_sales.php" class="inline-flex items-center px-2 sm:px-3 lg:px-4 py-1.5 sm:py-2 text-xs sm:text-sm border border-gray-300 rounded-md shadow-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out whitespace-nowrap flex-shrink-0">
                            <i class="fas fa-calendar-alt mr-1 sm:mr-2"></i>
                            <span class="hidden sm:inline">Monthly Sales</span>
                            <span class="sm:hidden">Monthly</span>
                        </a>
                    </div>
                    
                    <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0 min-w-0">
                        <form method="POST" action="" class="flex items-center gap-2 flex-shrink-0" id="dateForm" style="margin-bottom:0;">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-2 sm:pl-3 pointer-events-none">
                                    <!-- calendar icon -->
                                    <svg class="w-3 h-3 sm:w-4 sm:h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <select id="date" name="date" onchange="updateReport();" class="bg-gray-50 border border-gray-300 text-gray-900 text-xs sm:text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block pl-7 sm:pl-8 pr-7 sm:pr-8 py-1.5 sm:py-2 shadow-sm transition-colors cursor-pointer whitespace-nowrap">
                                    <?php foreach ($distinctDates as $date): ?>
                                        <option value="<?= htmlspecialchars($date) ?>" <?= $date == $selectedDate ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($date) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <a id="downloadMonthlyReport"
                           href="generate_monthly_report.php?month=<?= date('m', strtotime($selectedDate)) ?>&year=<?= date('Y', strtotime($selectedDate)) ?>" 
                           class="inline-flex items-center justify-center px-3 sm:px-4 lg:px-6 py-1.5 sm:py-2 text-xs sm:text-sm bg-gray-500 hover:bg-gray-600 text-white font-semibold rounded-lg shadow-sm transition duration-200 ease-in-out transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-opacity-50 whitespace-nowrap flex-shrink-0">
                            <i class="fas fa-file-pdf mr-1 sm:mr-2"></i>
                            <span class="hidden sm:inline">Download Monthly Report</span>
                            <span class="sm:hidden">PDF</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Spacer for fixed header -->
            <div class="h-16 sm:h-20 mb-4"></div>
            
            <div class="container mx-auto p-6">
                <!-- Stats Cards -->
                <?php if (count($salesData) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

<!-- Cash Sales Card -->
<div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-filter-type="cash" onclick="filterByCard('cash')">
    <div class="flex items-center justify-between mb-4">
        <div>
            <p class="text-sm font-medium text-muted-foreground">Cash Sales</p>
            <h3 class="text-2xl font-bold text-teal-600">N$<?= number_format($cashSalesDisplayTotal, 2) ?></h3>
        </div>
        <div class="p-3 bg-teal-100 rounded-full">
            <i class="fas fa-dollar-sign text-teal-600 text-lg"></i>
        </div>
    </div>
    <p class="text-sm text-muted-foreground">Cash transactions + cash credit payments</p>
</div>

<!-- EFT Payments Card -->
<div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-filter-type="eft" onclick="filterByCard('eft')">
    <div class="flex items-center justify-between mb-4">
        <div>
            <p class="text-sm font-medium text-muted-foreground">EFT Payments</p>
            <h3 class="text-2xl font-bold text-purple-600">N$<?= number_format($totalEftPayments, 2) ?></h3>
        </div>
        <div class="p-3 bg-purple-100 rounded-full">
            <i class="fas fa-credit-card text-purple-600 text-lg"></i>
        </div>
    </div>
    <p class="text-sm text-muted-foreground">Direct and credit EFT payments</p>
</div>

<!-- Unpaid Credit Card -->
<div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-filter-type="unpaid" onclick="filterByCard('unpaid')">
    <div class="flex items-center justify-between mb-4">
        <div>
            <p class="text-sm font-medium text-muted-foreground">Unpaid Credit</p>
            <h3 class="text-2xl font-bold text-amber-600">N$<?= number_format($totalUnpaidCredit, 2) ?></h3>
        </div>
        <div class="p-3 bg-amber-100 rounded-full">
            <i class="fas fa-hand-holding-usd text-amber-600 text-lg"></i>
        </div>
    </div>
    <p class="text-sm text-muted-foreground">Outstanding Balance</p>
</div>

<!-- Total Revenue Card -->
<div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-filter-type="all" onclick="filterByCard('all')">
    <div class="flex items-center justify-between mb-4">
        <div>
            <p class="text-sm font-medium text-muted-foreground">Total Revenue</p>
            <h3 class="text-2xl font-bold text-gray-600">N$<?= number_format($totalCashOnHand, 2) ?></h3>
        </div>
        <div class="p-3 bg-gray-100 rounded-full">
            <i class="fas fa-wallet text-gray-600 text-lg"></i>
        </div>
    </div>
    <p class="text-sm text-muted-foreground">All sales (Cash, EFT, Credit)</p>
</div>
</div>

<!-- Cash Transactions Table -->
<div class="bg-white shadow-lg rounded-xl overflow-hidden my-8">
    <div class="flex items-center justify-between p-2 bg-gray-300">
        <h2 class="text-xl font-bold p-1 text-gray-500 pr-4">
            <span class="inline-flex items-center">
                <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M7 20l5-5 5 5"></path>
                    <path d="M7 4l5 5 5-5"></path>
                </svg>
                Transactions
            </span>
        </h2>
        <div class="relative max-w-xs">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <svg class="w-3 h-3 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                </svg>
            </div>
            <input type="text" id="search" onkeyup="filterSales()" placeholder="Search by any field..." 
                   class="w-full pl-8 pr-3 py-1.5 border border-gray-400 rounded-lg focus:ring-2 focus:ring-gray-500 focus:outline-none focus:border-gray-500 shadow-sm transition duration-200 text-sm">
        </div>
    </div>
    <div class="table-container">
        <table class="min-w-full table-auto">
                <thead class="sticky top-0 select-none">
                    <tr class="bg-gray-100 border-b-2 border-gray-200 text-sm">
                        <th class="py-2 px-2 text-center cursor-pointer w-16" onclick="sortTable(0)">
                            <div class="flex items-center justify-center">
                                <span class="text-gray-700 text-center w-full">ID</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                        <th class="py-2 px-2 text-center cursor-pointer w-20" onclick="sortTable(1)">
                            <div class="flex items-center justify-center">
                                <span class="text-gray-700 text-center w-full">Type</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                        <th class="py-2 px-2 text-left cursor-pointer w-24" onclick="sortTable(2, true)">
                            <div class="flex items-center">
                                <span class="text-gray-700">Total</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                        <th class="py-2 px-2 text-left cursor-pointer" onclick="sortTable(3)">
                            <div class="flex items-center">
                                <span class="text-gray-700">Products</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                        <th class="py-2 px-2 text-left cursor-pointer w-32" onclick="sortTable(4)">
                            <div class="flex items-center">
                                <span class="text-gray-700">Date</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                        <th class="py-2 px-2 text-center cursor-pointer w-24" onclick="sortTable(5)">
                            <div class="flex items-center justify-center">
                                <span class="text-gray-700 text-center w-full">Cashier</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                        <th class="py-2 px-2 text-left cursor-pointer w-16" onclick="sortTable(6)">
                            <div class="flex items-center">
                                <span class="text-gray-700">Action</span>
                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                </svg>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200" id="salesTableBody">
                    <?php
                    if (count($salesData) > 0) {
                        foreach($salesData as $row) {
                    ?>
                        <tr class="hover:bg-gray-50 transition-colors expandable-row">
                            <td class="py-1 px-2 text-sm font-medium text-gray-500 text-center" data-label="ID"><?= $row['id'] ?></td>
                            <td class="py-1 px-2 text-sm font-medium text-gray-500 text-center" data-label="Type">
                                <div class="flex justify-center items-center">
                                    <?php if ($row['sale_type'] === 'credit' && $row['payment_status'] === 'unpaid'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-amber-100 text-amber-800 border border-amber-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                                        <span>Unpaid Credit</span>
                                    </span>
                                    <?php elseif ($row['payment_status'] === 'partial'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800 border border-yellow-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm0 14a6 6 0 110-12 6 6 0 010 12z"></path><path d="M10 5a1 1 0 011 1v3.586l2.707 2.707a1 1 0 01-1.414 1.414l-3-3A1 1 0 019 10V6a1 1 0 011-1z"></path></svg>
                                        <span>Partial Payment (N$<?= number_format($row['paid_amount'], 2) ?>)</span>
                                    </span>
                                    <?php elseif ($row['payment_status'] === 'paid_mixed' || ($row['sale_type'] ?? '') === 'mixed'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-violet-100 text-violet-800 border border-violet-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"></path></svg>
                                        <span>Cash + EFT Credit</span>
                                    </span>
                                    <?php elseif ($row['payment_status'] === 'eft'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800 border border-purple-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"></path><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"></path></svg>
                                        <span>EFT Credit Payment</span>
                                    </span>
                                    <?php elseif ($row['sale_type'] === 'eft'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800 border border-purple-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"></path><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"></path></svg>
                                        <span>EFT</span>
                                    </span>
                                    <?php elseif ($row['sale_type'] === 'cash'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800 border border-teal-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"></path></svg>
                                        <span>Cash Sales</span>
                                    </span>
                                    <?php elseif ($row['payment_status'] === 'paid' && $row['sale_type'] === 'credit'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800 border border-teal-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"></path></svg>
                                        <span>Credit (Cash)</span>
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800 border border-teal-200 shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                        <span>Credit Payment</span>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-1 px-2 text-sm font-bold text-gray-900" data-label="Total">N$<?= number_format($row['total'], 2) ?></td>
                            <td class="products-cell text-sm text-gray-600" data-label="Products">
                                <span class="font-medium <?= $row['sale_type'] === 'credit' ? 'text-gray-600' : 'text-gray-600' ?>"><?= htmlspecialchars($row['products']) ?></span>
                                <?php if (isset($row['creditor_name']) && $row['creditor_name']): ?>
                                <span class="text-xs text-orange-500 font-medium">(<?= htmlspecialchars($row['creditor_name']) ?>)</span>
                                <?php endif; ?>
                                <?php if (floatval($row['tips'] ?? 0) > 0): ?>
                                <span class="text-xs text-emerald-600 font-medium">(Tip: N$<?= number_format(floatval($row['tips']), 2) ?>)</span>
                                <?php endif; ?>
                                <?php if (isset($row['tab_name']) && $row['tab_name']): ?>
                                <span class="text-xs text-blue-500 font-medium">[Tab: <?= htmlspecialchars($row['tab_name']) ?>]</span>
                                <?php if (isset($row['tab_cashier_id']) && $row['tab_cashier_id']): ?>
                                <span class="text-xs text-blue-400 font-medium">by <?= htmlspecialchars($row['tab_cashier_id']) ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (isset($row['provider_name']) && $row['provider_name']): ?>
                                <span class="text-xs text-purple-500 font-medium">via <?= htmlspecialchars($row['provider_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($row['laybye_reference'])): ?>
                                <span class="block text-xs text-amber-800 font-medium mt-0.5">Lay-bye <?= htmlspecialchars($row['laybye_reference']) ?><?= !empty($row['laybye_payment_kind']) ? ' · ' . htmlspecialchars($row['laybye_payment_kind']) : '' ?><?= !empty($row['laybye_creditor_name']) ? ' — ' . htmlspecialchars($row['laybye_creditor_name']) : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-1 px-2 text-sm text-gray-500" data-label="Date">
                                <?php
                                // Use payment_date for paid/eft credits, otherwise use created_at
                                $displayDate = isset($row['payment_date']) && $row['payment_date'] && 
                                               ($row['payment_status'] === 'paid' || $row['payment_status'] === 'eft' || $row['payment_status'] === 'paid_mixed') ? 
                                               $row['payment_date'] : $row['created_at'];
                                echo date('d M Y H:i', strtotime($displayDate));
                                ?>
                            </td>
                            <td class="py-1 px-2 text-sm text-gray-500 text-center" data-label="Cashier">
                                <?= htmlspecialchars($row['cashier_id'] ?? '-') ?>
                            </td>
                            <td class="py-4 px-6" data-label="Action">
                                <div class="flex flex-wrap items-center gap-2 justify-center">
                                <?php
                                $ps = $row['payment_status'] ?? '';
                                $st = $row['sale_type'] ?? '';
                                ?>
                                <?php
                                $reprintSaleType = (($st === 'cash' || $st === 'eft') && empty($row['creditor_name'])) ? $st : 'credit';
                                ?>
                                <button type="button" onclick="reprintReceipt('<?= htmlspecialchars((string) $row['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($reprintSaleType, ENT_QUOTES) ?>', '<?= htmlspecialchars((string) $ps, ENT_QUOTES) ?>')" class="text-gray-600 hover:text-gray-900 transition-colors" title="Reprint receipt">
                                    <i class="fas fa-print"></i>
                                </button>
                                <?php if (!empty($row['laybye_id'])): ?>
                                <button type="button" onclick="reprintLaybyeBalance(<?= (int) $row['laybye_id'] ?>)" class="text-amber-700 hover:text-amber-900 transition-colors" title="Lay-bye statement">
                                    <i class="fas fa-file-invoice"></i>
                                </button>
                                <?php endif; ?>
                                <?php
                                if (
                                    ($row['sale_type'] === 'credit' && ($row['payment_status'] === 'paid' || $row['payment_status'] === 'eft' || $row['payment_status'] === 'paid_mixed'))
                                ) : ?>
                                    <button onclick="deleteRecord('credit', '<?= $row['id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors" title="Reset Paid/EFT Credit Sale">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php elseif ($row['sale_type'] === 'cash') : ?>
                                    <button onclick="deleteRecord('sales', '<?= $row['id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors" title="Delete Cash Sale">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                <?php elseif ($row['sale_type'] === 'eft') : ?>
                                    <?php if ($row['creditor_name']) : ?>
                                        <button onclick="deleteRecord('credit', '<?= $row['id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors" title="Reset EFT Credit Sale">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php else : ?>
                                        <button onclick="deleteRecord('sales', '<?= $row['id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors" title="Delete EFT Sale">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php elseif ($row['sale_type'] === 'paid') : ?>
                                    <button onclick="deleteRecord('credit', '<?= $row['id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors" title="Reset Paid Credit Sale">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php elseif ($row['sale_type'] === 'credit' && ($row['payment_status'] === 'unpaid' || $row['payment_status'] === 'partial')) : ?>
                                    <button onclick="deleteRecord('credit', '<?= $row['id'] ?>')" class="text-red-600 hover:text-red-800 transition-colors" title="Delete Unpaid/Partial Credit Sale">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                <?php endif; ?>
                                </div>
                            </td>



                            
                        </tr>
                    <?php
                        }
                    } else {
                    ?>
            
                    <?php
                    }
                    ?>
                </tbody>
            </table>

        <!-- Pagination Controls -->
        <div class="px-6 py-2 bg-gray-50 border-t border-gray-200">
            <div class="flex justify-between items-center">
                <div class="flex gap-2">
                    <button id="firstPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                        </svg>
                    </button>
                    <button id="prevPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Prev
                    </button>
                </div>
                <div class="flex items-center gap-4">
                    <span id="pageNumber" class="text-sm text-gray-700 font-medium">Page 1 of 1</span>
                    <div class="flex items-center gap-2">
                        <input type="number" id="pageInput" min="1" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-sm shadow-sm focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors" placeholder="Page">
                        <button class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-gray-500 hover:bg-gray-600 transition-colors shadow-sm">Go</button>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button id="nextPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                        Next
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                    <button id="lastPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="bg-white shadow-lg rounded-xl overflow-hidden my-8">
    <div class="flex items-center justify-between p-3 bg-gray-300">
        <h2 class="text-xl font-bold text-gray-600"><i class="fas fa-box-open mr-2"></i>Products</h2>
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700">Payment Method:</label>
                <select id="paymentMethodFilter" onchange="filterProducts()" class="bg-white border border-gray-300 text-gray-700 text-sm rounded-lg focus:ring-gray-500 focus:border-gray-500 block p-2.5">
                    <option value="all">All Methods</option>
                    <option value="cash">Cash Only</option>
                    <option value="eft">EFT Only</option>
                    <option value="credit">Credit Only</option>
                </select>
            </div>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <svg class="w-3 h-3 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                    </svg>
                </div>
                <input type="text" id="productSearch" onkeyup="filterProducts()" placeholder="Search products..." 
                       class="w-full pl-8 pr-3 py-1.5 border border-gray-400 rounded-lg focus:ring-2 focus:ring-gray-500 focus:outline-none focus:border-gray-500 shadow-sm transition duration-200 text-sm">
            </div>
        </div>
    </div>
    <div class="table-container">
        <table class="min-w-full table-auto">
            <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200 text-sm">
                    <th class="py-2 px-3 text-center cursor-pointer" onclick="sortTopProductsTable(0, true)">
                        <div class="flex items-center justify-center">
                            <span class="text-gray-700">ID</span>
                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                            </svg>
                        </div>
                    </th>
                    <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(1)">
                        <div class="flex items-center">
                            <span class="text-gray-700">Product</span>
                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                            </svg>
                        </div>
                    </th>
                    <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(2, true)">
                        <div class="flex items-center">
                            <span class="text-gray-700">Quantity</span>
                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                            </svg>
                        </div>
                    </th>
                    <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(3, true)">
                        <div class="flex items-center">
                            <span class="text-gray-700">Price</span>
                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                            </svg>
                        </div>
                    </th>
                    <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(4, true)">
                        <div class="flex items-center">
                            <span class="text-gray-700">Total Value</span>
                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                            </svg>
                        </div>
                    </th>
                    <th class="py-2 px-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200" id="topProductsTableBody">
                <?php if (count($topProducts) > 0): ?>
                    <?php foreach ($topProducts as $product): ?>
                        <tr class="hover:bg-gray-50 transition-colors h-6" 
                            data-payment-methods="<?= htmlspecialchars($product['payment_methods']) ?>"
                            data-cash-qty="<?= $product['cash_qty'] ?>"
                            data-eft-qty="<?= $product['eft_qty'] ?>"
                            data-credit-qty="<?= $product['credit_qty'] ?>"
                            data-total-qty="<?= $product['total_qty'] ?>">
                            <td class="py-1 px-3 text-sm font-medium text-gray-500 text-center"><?= $product['id'] ?? 'N/A' ?></td>
                            <td class="py-1 px-3 text-sm font-medium text-gray-800"><?= htmlspecialchars($product['product_name']) ?></td>
                            <td class="py-1 px-3 text-sm font-semibold text-gray-900">
                                <?php
                                // Determine badge color based on quantity value
                                // Create a map of quantities to ensure same quantities have same color
                                static $quantityColorMap = [];
                                $qty = $product['total_qty'];
                                
                                if (!isset($quantityColorMap[$qty])) {
                                    // First time seeing this quantity, assign a color
                                    $count = count($quantityColorMap);
                                    if ($count === 0) {
                                        // Top seller - gold
                                        $quantityColorMap[$qty] = "bg-amber-100 text-amber-800 border-amber-200";
                                    } elseif ($count === 1) {
                                        // Second best - silver
                                        $quantityColorMap[$qty] = "bg-slate-100 text-slate-800 border-slate-200";
                                    } elseif ($count === 2) {
                                        // Third best - bronze
                                        $quantityColorMap[$qty] = "bg-orange-100 text-orange-800 border-orange-200";
                                    } elseif ($count < 5) {
                                        // Top 5 - teal
                                        $quantityColorMap[$qty] = "bg-teal-100 text-teal-800 border-teal-200";
                                    } elseif ($count < 10) {
                                        // Top 10 - gray
                                        $quantityColorMap[$qty] = "bg-gray-100 text-gray-800 border-gray-200";
                                    } else {
                                        // Others - gray
                                        $quantityColorMap[$qty] = "bg-gray-100 text-gray-800 border-gray-200";
                                    }
                                }
                                
                                $badgeClass = $quantityColorMap[$qty];
                                ?>
                                <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-sm font-bold <?= $badgeClass ?> shadow-sm border quantity-badge" style="min-width:2.2em; min-height:2.2em;">
                                    <?= $product['total_qty'] ?>
                                </span>
                            </td>
                            <td class="py-1 px-3 text-sm font-semibold text-gray-900" data-label="Price">N$<?= number_format($product['current_price'], 2) ?></td>
                            <td class="py-1 px-3 text-sm font-bold text-teal-700 total-value" data-label="Total Value">N$<?= number_format($product['current_price'] * $product['total_qty'], 2) ?></td>
                            <td class="py-1 px-3" data-label="Actions">
                                <button onclick="deleteProductRecord('<?= htmlspecialchars($product['product_name']) ?>')" class="text-red-600 hover:text-red-800 transition-colors">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="py-6 px-6 text-center text-gray-500">No products sold on this date</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Products Table Pagination Controls -->
    <div class="px-6 py-2 bg-gray-50 border-t border-gray-200">
        <div class="flex justify-between items-center">
            <div class="flex gap-2">
                <button id="topProductsFirstPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                    </svg>
                </button>
                <button id="topProductsPrevPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Prev
                </button>
            </div>
            <div class="flex items-center gap-4">
                <span id="topProductsPageNumber" class="text-sm text-gray-700 font-medium">Page 1 of 1</span>
                <div class="flex items-center gap-2">
                    <input type="number" id="topProductsPageInput" min="1" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-sm shadow-sm focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors" placeholder="Page">
                    <button class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-gray-500 hover:bg-gray-600 transition-colors shadow-sm">Go</button>
                </div>
            </div>
            <div class="flex gap-2">
                <button id="topProductsNextPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                    Next
                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
                <button id="topProductsLastPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="bg-white shadow-lg rounded-xl overflow-hidden my-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-3 bg-rose-100 border-b border-rose-200">
        <h2 class="text-xl font-bold text-rose-900">
            <i class="fas fa-exclamation-triangle mr-2" aria-hidden="true"></i>Damaged stock
        </h2>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-rose-800">
                <span class="font-semibold"><?= count($damagedStockRows) ?></span> record<?= count($damagedStockRows) === 1 ? '' : 's' ?>
                · <span class="font-semibold"><?= rtrim(rtrim(number_format($damagedStockTotalQty, 4, '.', ''), '0'), '.') ?></span> units
                · <span class="font-semibold">N$<?= number_format($damagedStockTotalValue, 2) ?></span> at retail
            </span>
            <a href="damaged_goods.php" class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium text-white bg-rose-600 hover:bg-rose-700 shadow-sm transition-colors">
                <i class="fas fa-plus mr-1.5" aria-hidden="true"></i> Record damage
            </a>
        </div>
    </div>
    <div class="table-container overflow-x-auto">
        <table class="min-w-full table-auto">
            <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200 text-sm">
                    <th class="py-2 px-3 text-left text-gray-700">Product</th>
                    <th class="py-2 px-3 text-right text-gray-700">Qty</th>
                    <th class="py-2 px-3 text-left text-gray-700">Reason</th>
                    <th class="py-2 px-3 text-left text-gray-700">Recorded</th>
                    <th class="py-2 px-3 text-right text-gray-700">Retail value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php if (count($damagedStockRows) > 0): ?>
                    <?php foreach ($damagedStockRows as $drow): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-2 px-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($drow['product_name']) ?></td>
                            <td class="py-2 px-3 text-sm text-right text-gray-800"><?= htmlspecialchars((string) $drow['quantity']) ?></td>
                            <td class="py-2 px-3 text-sm text-gray-600"><?= htmlspecialchars((string) ($drow['reason'] ?? '')) ?: '—' ?></td>
                            <td class="py-2 px-3 text-sm text-gray-600"><?= htmlspecialchars(date('d M Y H:i', strtotime($drow['damaged_at']))) ?></td>
                            <td class="py-2 px-3 text-sm text-right font-medium text-rose-800">N$<?= number_format((float) $drow['line_value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="py-8 px-4 text-center text-gray-500">No damaged stock for this business day.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-t border-gray-200">Uses the same business-day window as transactions (closing time from business settings).</p>
</div>

<script>
function filterProducts() {
    const searchText = document.getElementById('productSearch').value.toLowerCase();
    const paymentMethod = document.getElementById('paymentMethodFilter').value;
    
    // Use pagination manager if available
    if (topProductsPaginationManager) {
        const allProductRows = topProductsPaginationManager.getAllRows();
        const filteredProductRows = allProductRows.filter(row => {
            // Skip "no data" rows
            if (row.querySelector('td[colspan]')) {
                return true;
            }
            
            const productNameCell = row.querySelector('td:nth-child(2)');
            if (!productNameCell) return false;
            
            const productName = productNameCell.textContent.toLowerCase();
            const paymentMethods = row.getAttribute('data-payment-methods')?.split(',') || [];
            const quantityBadge = row.querySelector('.quantity-badge');
            const totalValue = row.querySelector('.total-value');
            const priceCell = row.querySelector('td:nth-child(4)');
            
            if (!quantityBadge || !totalValue || !priceCell) return false;
            
            const price = parseFloat(priceCell.textContent.replace('N$', '').replace(',', ''));
            
            let quantity = 0;
            if (paymentMethod === 'all') {
                quantity = parseInt(row.getAttribute('data-total-qty')) || 0;
            } else if (paymentMethod === 'cash') {
                quantity = parseInt(row.getAttribute('data-cash-qty')) || 0;
            } else if (paymentMethod === 'eft') {
                quantity = parseInt(row.getAttribute('data-eft-qty')) || 0;
            } else if (paymentMethod === 'credit') {
                quantity = parseInt(row.getAttribute('data-credit-qty')) || 0;
            }
            
            // Update quantity badge
            quantityBadge.textContent = quantity;
            
            // Update total value
            totalValue.textContent = 'N$' + (price * quantity).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            // Check if row matches search and payment method filters
            const matchesSearch = productName.includes(searchText);
            const matchesPaymentMethod = paymentMethod === 'all' || paymentMethods.includes(paymentMethod);
            
            return matchesSearch && matchesPaymentMethod && quantity > 0; // Only show products with quantity > 0
        });
        
        // Update the pagination manager with filtered rows
        topProductsPaginationManager.updateCurrentRows(filteredProductRows);
    } else {
        // Fallback to old method if pagination manager not available
        const rows = document.querySelectorAll('#topProductsTableBody tr');
        
        rows.forEach(row => {
            // Skip "no data" rows
            if (row.querySelector('td[colspan]')) {
                row.style.display = '';
                return;
            }
            
            const productNameCell = row.querySelector('td:nth-child(2)');
            if (!productNameCell) {
                row.style.display = 'none';
                return;
            }
            
            const productName = productNameCell.textContent.toLowerCase();
            const paymentMethods = row.getAttribute('data-payment-methods')?.split(',') || [];
            const quantityBadge = row.querySelector('.quantity-badge');
            const totalValue = row.querySelector('.total-value');
            const priceCell = row.querySelector('td:nth-child(4)');
            
            if (!quantityBadge || !totalValue || !priceCell) {
                row.style.display = 'none';
                return;
            }
            
            const price = parseFloat(priceCell.textContent.replace('N$', '').replace(',', ''));
            
            let quantity = 0;
            if (paymentMethod === 'all') {
                quantity = parseInt(row.getAttribute('data-total-qty')) || 0;
            } else if (paymentMethod === 'cash') {
                quantity = parseInt(row.getAttribute('data-cash-qty')) || 0;
            } else if (paymentMethod === 'eft') {
                quantity = parseInt(row.getAttribute('data-eft-qty')) || 0;
            } else if (paymentMethod === 'credit') {
                quantity = parseInt(row.getAttribute('data-credit-qty')) || 0;
            }
            
            // Update quantity badge
            quantityBadge.textContent = quantity;
            
            // Update total value
            totalValue.textContent = 'N$' + (price * quantity).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            // Show/hide row based on search and payment method
            const matchesSearch = productName.includes(searchText);
            const matchesPaymentMethod = paymentMethod === 'all' || paymentMethods.includes(paymentMethod);
            
            row.style.display = (matchesSearch && matchesPaymentMethod && quantity > 0) ? '' : 'none';
        });
    }
}

// Add data attributes to each row
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('#topProductsTableBody tr');
    rows.forEach(row => {
        const productNameCell = row.querySelector('td:nth-child(2)');
        if (!productNameCell) return; // Skip this row if it doesn't have a second cell
        const productName = productNameCell.textContent;
        const product = <?= json_encode($topProducts) ?>.find(p => p.product_name === productName);
        if (product) {
            row.setAttribute('data-payment-methods', product.payment_methods);
            row.setAttribute('data-cash-qty', product.cash_qty);
            row.setAttribute('data-eft-qty', product.eft_qty);
            row.setAttribute('data-credit-qty', product.credit_qty);
        }
    });
});

function filterByCard(type) {
    // Filter Sales Table using pagination manager
    if (salesPaginationManager) {
        const allSalesRows = salesPaginationManager.getAllRows();
        const filteredSalesRows = allSalesRows.filter(row => {
            const typeCell = row.querySelector('td:nth-child(2) span');
            if (!typeCell) {
                return true; // Show rows without type cell
            }
            const typeText = typeCell.textContent.toLowerCase();
            let show = false;
            if (type === 'cash') {
                // Include Cash Sales and Credit (Cash) payments, but exclude EFT credit payments
                show = typeText.includes('cash sales') || 
                       ((typeText.includes('credit (cash)') || typeText.includes('credit payment')) && 
                        !typeText.includes('eft'));
            } else if (type === 'eft') {
                // Include EFT and EFT Credit Payment
                show = typeText.includes('eft');
            } else if (type === 'unpaid') {
                show = typeText.includes('unpaid credit') || typeText.includes('partial payment');
            } else if (type === 'all') {
                show = true;
            }
            return show;
        });
        
        // Update the pagination manager with filtered rows
        salesPaginationManager.updateCurrentRows(filteredSalesRows);
        salesPaginationManager.showPage(1); // Reset to first page after filtering
    }
    
    // Update Top Products Table Filter
    const paymentMethodFilter = document.getElementById('paymentMethodFilter');
    if (paymentMethodFilter) {
        let filterValue = 'all';
        if (type === 'cash') {
            filterValue = 'cash';
        } else if (type === 'eft') {
            filterValue = 'eft';
        } else if (type === 'unpaid') {
            filterValue = 'credit';
        } else if (type === 'all') {
            filterValue = 'all';
        }
        
        // Update the dropdown value
        paymentMethodFilter.value = filterValue;
        
        // Call the existing filterProducts function to apply the filter
        filterProducts();
    }
    
    // Highlight the active card with matching colors
    document.querySelectorAll('[data-filter-type]').forEach(card => {
        // Remove all possible ring colors
        card.classList.remove('ring-2', 'ring-gray-400', 'ring-teal-500', 'ring-purple-500', 'ring-amber-500', 'ring-gray-500');
    });
    const activeCard = document.querySelector(`[data-filter-type="${type}"]`);
    if (activeCard) {
        // Add ring with matching color for each card type
        let ringColor = 'ring-gray-500'; // default
        if (type === 'cash') {
            ringColor = 'ring-teal-500';
        } else if (type === 'eft') {
            ringColor = 'ring-purple-500';
        } else if (type === 'unpaid') {
            ringColor = 'ring-amber-500';
        } else if (type === 'all') {
            ringColor = 'ring-gray-500';
        }
        activeCard.classList.add('ring-2', ringColor);
    }
    // Optionally, highlight the Show All button
    const showAllBtn = document.getElementById('showAllBtn');
    if (showAllBtn) {
        if (type === 'all') {
            showAllBtn.classList.add('bg-gray-200');
        } else {
            showAllBtn.classList.remove('bg-gray-200');
        }
    }
}
// Show all on page load
window.addEventListener('DOMContentLoaded', function() {
    filterByCard('all');
});
</script>

<?php else: ?>
<!-- No Transactions Alert -->
<div class="bg-gray-50 border border-gray-200 rounded-xl shadow-lg p-8 h-full">
    <div class="flex flex-col items-center text-center">
        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-6">
            <svg class="w-10 h-10 text-teal-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        </div>
        <h3 class="text-xl font-semibold text-gray-800 mb-2">No Transactions Today</h3>
        <p class="text-gray-600 mb-4">There are no transactions recorded for the selected date (<?= htmlspecialchars($selectedDate) ?>).</p>
        <p class="text-gray-500 text-sm">Please select a different date or create a new transaction.</p>
    </div>
</div>
<?php endif; ?>


            
                <!-- Top Selling Products Section -->



            </div>
            <?php $db = null; ?>
        </div>
    </div>

    <script>
    <?php
    $mgrPrintInfo = [
        'name' => 'POS SOLUTION', 'location' => '', 'phone' => '', 'footer_text' => 'Thank you!',
        'vat_inclusive' => 'exclusive', 'vat_rate' => 15.0,
    ];
    try {
        $biDbM = new PDO('sqlite:' . __DIR__ . '/../info.db');
        $rM = $biDbM->query('SELECT * FROM business_info LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($rM) {
            $mgrPrintInfo = array_merge($mgrPrintInfo, $rM);
        }
    } catch (Exception $e) {
    }
    ?>
    var businessInfo = {
        business_name: <?= json_encode($mgrPrintInfo['name'] ?? 'POS SOLUTION') ?>,
        location: <?= json_encode($mgrPrintInfo['location'] ?? '') ?>,
        phone: <?= json_encode($mgrPrintInfo['phone'] ?? '') ?>,
        footer_text: <?= json_encode($mgrPrintInfo['footer_text'] ?? 'Thank you!') ?>,
        vat_inclusive: <?= json_encode($mgrPrintInfo['vat_inclusive'] ?? 'exclusive') ?>,
        vat_rate: <?= json_encode(floatval($mgrPrintInfo['vat_rate'] ?? 15.0)) ?>
    };
    if (typeof sendToPrinter === 'undefined') {
        function sendToPrinter(receiptData) {
            if (!receiptData.print_only && !receiptData.is_cashup_report && !receiptData.is_balance_receipt && !receiptData.is_tab_balance_receipt && !receiptData.is_payment_receipt && !receiptData.is_laybye_balance_receipt) {
                receiptData.print_only = true;
            }
            var dataWithBusiness = Object.assign({}, receiptData, {
                business_name: receiptData.business_name || businessInfo.business_name,
                location: receiptData.location || businessInfo.location,
                phone: receiptData.phone || businessInfo.phone,
                footer_text: receiptData.footer_text || businessInfo.footer_text,
                vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                vat_rate: receiptData.vat_rate || businessInfo.vat_rate,
                items: receiptData.items || []
            });
            return fetch('../receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dataWithBusiness)
            }).then(function(r) { return r.json(); });
        }
    }
    function reprintReceipt(transactionId, saleType, paymentStatus) {
        if (typeof showNotification === 'function') {
            showNotification('Processing', 'Fetching receipt data...', 'info');
        }
        fetch('../reprint_receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                transaction_id: transactionId,
                sale_type: saleType,
                payment_status: paymentStatus || ''
            })
        })
        .then(async (response) => {
            const text = await response.text();
            let data = null;
            try { data = text ? JSON.parse(text) : null; } catch (e) { /* non-JSON */ }
            if (!response.ok) {
                throw new Error((data && data.message) ? data.message : (text || 'Server error'));
            }
            if (!data || !data.success) {
                throw new Error((data && data.message) ? data.message : 'Unknown error');
            }
            if (data.receipt_data) {
                return sendToPrinter(data.receipt_data);
            }
            return Promise.resolve({ success: true });
        })
        .then(result => {
            if (result && result.success && typeof showNotification === 'function') {
                showNotification('Success', 'Receipt sent to printer.', 'success');
            } else if (!result || !result.success) {
                throw new Error(result?.message || 'Printing failed');
            }
        })
        .catch(error => {
            console.error(error);
            if (typeof showNotification === 'function') {
                showNotification('Error', 'Reprint: ' + (error.message || 'Unknown error'), 'error');
            }
        });
    }
    function reprintLaybyeBalance(laybyeId) {
        if (typeof showNotification === 'function') {
            showNotification('Processing', 'Preparing lay-bye statement...', 'info');
        }
        fetch('../reprint_laybye_balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ laybye_id: String(laybyeId) })
        })
        .then(async (response) => {
            const text = await response.text();
            let data = null;
            try { data = text ? JSON.parse(text) : null; } catch (e) { /* non-JSON */ }
            if (!response.ok) {
                throw new Error((data && data.message) ? data.message : (text || 'Server error'));
            }
            if (!data || !data.success) {
                throw new Error((data && data.message) ? data.message : 'Unknown error');
            }
            if (data.receipt_data) {
                return sendToPrinter(data.receipt_data);
            }
            return Promise.resolve({ success: true });
        })
        .then(result => {
            if (result && result.success && typeof showNotification === 'function') {
                showNotification('Success', 'Lay-bye statement sent to printer.', 'success');
            } else if (!result || !result.success) {
                throw new Error(result?.message || 'Printing failed');
            }
        })
        .catch(error => {
            console.error(error);
            if (typeof showNotification === 'function') {
                showNotification('Error', 'Lay-bye statement: ' + (error.message || 'Unknown error'), 'error');
            }
        });
    }
    // Function to update report data via form submission
    function updateReport() {
        // Simply submit the form to reload the page with the new date
        document.getElementById('dateForm').submit();
    }

    // Function to filter tables (generic approach)
    function filterTable(inputId, tableBodyId) {
        const input = document.getElementById(inputId);
        const filter = input.value.toLowerCase();
        const tableBody = document.getElementById(tableBodyId);
        const rows = tableBody ? tableBody.querySelectorAll('tr') : [];

        rows.forEach(row => {
            const cells = row.getElementsByTagName('td');
            let showRow = false;
            // Skip if it's the "no data" row
            if (row.querySelector('td[colspan]')) {
                 showRow = true; // Always show the 'no data' row if it exists initially
            } else {
                Array.from(cells).forEach(cell => {
                    if (cell.textContent.toLowerCase().includes(filter)) {
                        showRow = true;
                    }
                });
            }
             row.style.display = showRow ? '' : 'none';
        });
         // Re-initialize pagination after filtering might be needed if filter changes the number of rows significantly
        // This example doesn't re-paginate on filter, but keeps the display style change.
        // For full re-pagination on filter, you'd need to update the `rows` array and call `showPage(1)`.
    }

    // Simplified filter function call for the main search bar
    function filterSales() {
        // Assuming the search bar filters multiple tables or just the main 'All Transactions' table
        filterTable('search', 'salesTableBody');
        filterTable('search', 'topProductsTableBody'); // If search should also filter products
        if (document.getElementById('dailyBreakdownTableBody')) {
            filterTable('search', 'dailyBreakdownTableBody');
        }
    }

    // --- Global Pagination Managers ---
    let salesPaginationManager = null;
    let topProductsPaginationManager = null;

    // --- Generic Pagination and Sorting ---
    function initializePaginationAndSorting(config) {
        const tableBody = document.getElementById(config.tableBodyId);
        if (!tableBody) {
            console.warn(`Table body not found: ${config.tableBodyId}`);
            return; // Exit if table body doesn't exist
        }

        let allRows = Array.from(tableBody.children).filter(row => !row.querySelector('td[colspan]')); // Exclude 'no data' row from sorting/pagination logic
        let currentRows = [...allRows]; // Rows currently being displayed/sorted/paginated
        let sortDirection = {};
        let currentPage = 1;
        const rowsPerPage = config.rowsPerPage || 10;

        function updateCurrentRows(filteredRows) {
            currentRows = filteredRows || [...allRows];
            showPage(1); // Reset to first page when filtering
        }

        function showPage(page) {
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1; // Use currentRows.length
            
            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');
            
            // Display only the rows for the current page from the potentially sorted/filtered list
            currentRows.slice(start, end).forEach(row => row.style.display = '');

            // Update page indicator
            const pageNumberEl = document.getElementById(config.pageNumberId);
            if (pageNumberEl) {
                pageNumberEl.textContent = `Page ${page} of ${maxPage}`;
            }

            // Update page input field
            const pageInputEl = document.getElementById(config.pageInputId);
            if (pageInputEl) {
                pageInputEl.value = page;
                pageInputEl.max = maxPage;
                pageInputEl.placeholder = `Page (1-${maxPage})`;
            }

            // Update button states
            const firstBtn = document.getElementById(config.firstPageId);
            const prevBtn = document.getElementById(config.prevPageId);
            const nextBtn = document.getElementById(config.nextPageId);
            const lastBtn = document.getElementById(config.lastPageId);

            if (firstBtn) firstBtn.disabled = page === 1;
            if (prevBtn) prevBtn.disabled = page === 1;
            if (nextBtn) nextBtn.disabled = page >= maxPage;
            if (lastBtn) lastBtn.disabled = page >= maxPage;

            currentPage = page;
        }

        function sortTable(columnIndex, isNumeric = false) {
            // Update sort direction
            const currentSortDir = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            sortDirection = { [columnIndex]: currentSortDir }; // Reset other column sorts

            // Sort the rows
            currentRows.sort((a, b) => {
                let aValue = a.children[columnIndex]?.textContent.trim() || '';
                let bValue = b.children[columnIndex]?.textContent.trim() || '';

                if (isNumeric) {
                    // More robust parsing for currency etc.
                    aValue = parseFloat(aValue.replace(/[^0-9.-]+/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^0-9.-]+/g, '')) || 0;
                } else {
                    aValue = aValue.toLowerCase();
                    bValue = bValue.toLowerCase();
                }

                if (aValue < bValue) return currentSortDir === 'asc' ? -1 : 1;
                if (aValue > bValue) return currentSortDir === 'asc' ? 1 : -1;
                return 0;
            });

            // Re-append the sorted rows to maintain DOM order
            currentRows.forEach(row => tableBody.appendChild(row));

            // Show current page after sorting
            showPage(currentPage);
        }

        // Set up event listeners for pagination controls
        const setupButton = (id, callback) => {
            const button = document.getElementById(id);
            if (button) {
                // Remove existing listeners to prevent duplicates
                const newButton = button.cloneNode(true);
                button.parentNode.replaceChild(newButton, button);
                newButton.addEventListener('click', callback);
            }
        };

        // Setup pagination buttons
        setupButton(config.firstPageId, () => showPage(1));
        setupButton(config.prevPageId, () => showPage(Math.max(1, currentPage - 1)));
        setupButton(config.nextPageId, () => {
            const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
            showPage(Math.min(maxPage, currentPage + 1));
        });
        setupButton(config.lastPageId, () => {
            const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
            showPage(maxPage);
        });

        // Setup page input
        const pageInput = document.getElementById(config.pageInputId);
        const pageGoBtn = pageInput?.nextElementSibling;
        
        if (pageInput) {
            // Remove existing listeners
            const newPageInput = pageInput.cloneNode(true);
            pageInput.parentNode.replaceChild(newPageInput, pageInput);
            
            newPageInput.addEventListener('change', function() {
                const desiredPage = parseInt(this.value);
                if (!isNaN(desiredPage)) {
                    const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
                    showPage(Math.min(Math.max(1, desiredPage), maxPage));
                }
            });
        }

        if (pageGoBtn) {
            // Remove existing listeners
            const newPageGoBtn = pageGoBtn.cloneNode(true);
            pageGoBtn.parentNode.replaceChild(newPageGoBtn, pageGoBtn);
            
            newPageGoBtn.addEventListener('click', function() {
                const desiredPage = parseInt(newPageInput.value);
                if (!isNaN(desiredPage)) {
                    const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
                    showPage(Math.min(Math.max(1, desiredPage), maxPage));
                }
            });
        }

        // Setup sorting
        // Find all sortable headers in the thead (assuming thead is sibling of tbody)
        const headers = tableBody.parentElement.querySelector('thead')?.querySelectorAll('th');
        if (headers) {
            headers.forEach((th, index) => {
                // Check if this header has sorting functionality
                if (th.querySelector('.sort-icon')) {
                    // Remove old listeners and onclick attributes
                    const newTh = th.cloneNode(true);
                    th.parentNode.replaceChild(newTh, th);
                    newTh.removeAttribute('onclick');
                    
                    // Determine if this column contains numeric data
                    const isNum = newTh.textContent.trim().includes('Total') || 
                                  newTh.textContent.trim().includes('Price') || 
                                  newTh.textContent.trim().includes('Quantity') || 
                                  newTh.textContent.trim().includes('Sales');
                    
                    // Add new event listener
                    newTh.addEventListener('click', () => sortTable(index, isNum));
                }
            });
        }

        // Initial display
        showPage(1);
        
        // Return the pagination manager object
        return { 
            sort: sortTable, 
            updateCurrentRows: updateCurrentRows,
            showPage: showPage,
            getAllRows: () => allRows,
            getCurrentRows: () => currentRows
        };
    }

    // Table-specific initialization functions
    function initializeSalesPaginationAndSorting() {
        salesPaginationManager = initializePaginationAndSorting({
            tableBodyId: 'salesTableBody',
            rowsPerPage: 10,
            pageNumberId: 'pageNumber',
            pageInputId: 'pageInput',
            firstPageId: 'firstPage',
            prevPageId: 'prevPage',
            nextPageId: 'nextPage',
            lastPageId: 'lastPage'
        });
        window.sortTable = salesPaginationManager && salesPaginationManager.sort ? salesPaginationManager.sort : function() {};
    }

    function initializeTopProductsPaginationAndSorting() {
        topProductsPaginationManager = initializePaginationAndSorting({
            tableBodyId: 'topProductsTableBody',
            rowsPerPage: 10,
            pageNumberId: 'topProductsPageNumber',
            pageInputId: 'topProductsPageInput',
            firstPageId: 'topProductsFirstPage',
            prevPageId: 'topProductsPrevPage',
            nextPageId: 'topProductsNextPage',
            lastPageId: 'topProductsLastPage'
        });
        window.sortTopProductsTable = topProductsPaginationManager && topProductsPaginationManager.sort ? topProductsPaginationManager.sort : function() {};
    }

    function initializeDailyBreakdownPaginationAndSorting() {
        const result = initializePaginationAndSorting({
            tableBodyId: 'dailyBreakdownTableBody',
            rowsPerPage: 10,
            pageNumberId: 'dailyPageNumber',
            pageInputId: 'dailyPageInput',
            firstPageId: 'dailyFirstPage',
            prevPageId: 'dailyPrevPage',
            nextPageId: 'dailyNextPage',
            lastPageId: 'dailyLastPage'
        });
        window.sortDailyBreakdownTable = result && result.sort ? result.sort : function() {};
    }


    // --- Manager PIN modal (void / delete sales or credit) ---
    function openManagerPinModal(onPin) {
        const overlay = document.createElement('div');
        overlay.id = 'manager-void-pin-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.5);padding:1rem;box-sizing:border-box;overflow:auto;';
        overlay.innerHTML = `
            <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm w-full" style="margin:auto;max-height:min(90vh,100%);overflow:auto">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Manager PIN</h3>
                <p class="text-sm text-gray-600 mb-4">Enter the manager PIN to void this transaction.</p>
                <input type="password" id="manager-void-pin-input" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-2 focus:ring-gray-500 focus:border-gray-500 mb-4" autocomplete="off" inputmode="numeric" placeholder="PIN">
                <div class="flex justify-end gap-2">
                    <button type="button" id="manager-void-pin-cancel" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50">Cancel</button>
                    <button type="button" id="manager-void-pin-ok" class="px-4 py-2 rounded-md text-white bg-gray-800 hover:bg-gray-900">Void</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const input = overlay.querySelector('#manager-void-pin-input');
        const close = () => { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
        overlay.querySelector('#manager-void-pin-cancel').onclick = close;
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        overlay.querySelector('#manager-void-pin-ok').onclick = () => {
            const pin = input.value || '';
            close();
            onPin(pin);
        };
        setTimeout(() => input.focus(), 0);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                overlay.querySelector('#manager-void-pin-ok').click();
            }
        });
    }

    // --- Delete Functions ---
    function deleteRecord(type, id) {
        const needsPin = (type === 'sales' || type === 'credit');
        const runDelete = (managerPin) => {
            let body = `type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;
            if (needsPin) {
                body += `&manager_pin=${encodeURIComponent(managerPin)}`;
            }
            fetch('delete_record.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Success', 'Record deleted successfully.', 'success');
                    location.reload();
                } else {
                    showNotification('Error', 'Error deleting record: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while deleting the record.', 'error');
            });
        };
        if (needsPin) {
            openManagerPinModal((pin) => runDelete(pin));
            return;
        }
        showConfirmationModal('Are you sure you want to delete this record?', 'This action cannot be undone.', () => {
            runDelete();
        });
    }

    function deleteDailyRecord(date) {
        showConfirmationModal(`Are you sure you want to delete all records for ${date}?`, 'This action cannot be undone.', () => {
            fetch('delete_record.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `type=daily&date=${date}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Success', 'Records deleted successfully.', 'success');
                    updateReport(); // Refresh data
                } else {
                    showNotification('Error', 'Error deleting records: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while deleting the records.', 'error');
            });
        });
    }

    function deleteProductRecord(productName) {
        showConfirmationModal(`Are you sure you want to delete all sales records for ${productName}?`, 'This action cannot be undone.', () => {
            fetch('delete_record.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `type=product&name=${encodeURIComponent(productName)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Success', 'Records deleted successfully.', 'success');
                    // Reload the page after deletion
                    location.reload();
                } else {
                    showNotification('Error', 'Error deleting records: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while deleting the records.', 'error');
            });
        });
    }

    // Notification Function
    function showNotification(title, message, type = 'info') {
        // Remove existing notification if present
        const existingNotification = document.getElementById('notification-toast');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // Create notification element
        const notification = document.createElement('div');
        notification.id = 'notification-toast';
        notification.className = 'fixed top-4 right-4 max-w-sm w-full shadow-lg rounded-lg overflow-hidden z-50 transform transition-all duration-300 ease-in-out translate-x-full opacity-0';
        
        // Define background and icon based on type
        let bgColor, icon;
        switch (type) {
            case 'success':
                bgColor = 'bg-teal-100 border-l-4 border-teal-500';
                icon = `<svg class="w-6 h-6 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>`;
                break;
            case 'error':
                bgColor = 'bg-red-100 border-l-4 border-red-500';
                icon = `<svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>`;
                break;
            case 'warning':
                bgColor = 'bg-yellow-50 border-l-4 border-yellow-500';
                icon = `<svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>`;
                break;
            default: // info
                bgColor = 'bg-gray-100 border-l-4 border-gray-500';
                icon = `<svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>`;
        }
        
        // Create notification content
        notification.innerHTML = `
            <div class="${bgColor}">
                <div class="flex items-center p-4">
                    <div class="flex-shrink-0">
                        ${icon}
                    </div>
                    <div class="ml-3 w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900">${title}</p>
                        <p class="mt-1 text-sm text-gray-500">${message}</p>
                    </div>
                    <div class="ml-4 flex-shrink-0 flex">
                        <button class="inline-flex text-gray-400 hover:text-gray-500 focus:outline-none" 
                                onclick="dismissNotification()">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Add to DOM
        document.body.appendChild(notification);
        
        // Trigger animation after a small delay (to ensure DOM is ready)
        setTimeout(() => {
            notification.classList.remove('translate-x-full', 'opacity-0');
        }, 10);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            dismissNotification();
        }, 5000);
    }
    
    function dismissNotification() {
        const toast = document.getElementById('notification-toast');
        if (toast) {
            toast.classList.add('translate-x-full', 'opacity-0');
            setTimeout(() => {
                if (document.body.contains(toast)) {
                    toast.remove();
                }
            }, 300);
        }
    }

    // Confirmation Modal Function
    function showConfirmationModal(title, message, onConfirm) {
        // Create modal backdrop
        const modal = document.createElement('div');
        modal.id = 'confirmation-modal';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px); transition: all 0.3s;';
        
        // Create modal content
        modal.innerHTML = `
            <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-md w-full transform transition-all duration-300 scale-90 opacity-0" style="margin: auto;">
                <div class="flex items-center mb-4">
                    <div class="rounded-full bg-red-100 p-2 mr-3">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900">${title}</h3>
                </div>
                <p class="text-gray-600 mb-6">${message}</p>
                <div class="flex justify-end space-x-3">
                    <button 
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors"
                        onclick="dismissConfirmationModal()">
                        Cancel
                    </button>
                    <button 
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors"
                        id="confirm-delete-btn">
                        Delete
                    </button>
                </div>
            </div>
        `;
        
        // Add to DOM
        document.body.appendChild(modal);
        
        // Trigger animation after a small delay (to ensure DOM is ready)
        setTimeout(() => {
            const modalContent = modal.querySelector('div');
            if (modalContent) {
                modalContent.classList.remove('scale-90', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }
        }, 10);
        
        // Setup confirm button
        document.getElementById('confirm-delete-btn').addEventListener('click', () => {
            dismissConfirmationModal();
            setTimeout(() => {
                onConfirm();
            }, 300); // Wait for the animation to finish before executing the callback
        });
        
        // Close on background click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                dismissConfirmationModal();
            }
        });
        
        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('confirmation-modal')) {
                dismissConfirmationModal();
            }
        });
    }
    
    function dismissConfirmationModal() {
        const modal = document.getElementById('confirmation-modal');
        if (modal) {
            modal.style.opacity = '0';
            
            const modalContent = modal.querySelector('div');
            if (modalContent) {
                modalContent.classList.remove('scale-100', 'opacity-100');
                modalContent.classList.add('scale-90', 'opacity-0');
            }
            
            setTimeout(() => {
                if (document.body.contains(modal)) {
                    modal.remove();
                }
            }, 300);
        }
    }

    // --- Initial Setup ---
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize pagination and sorting for tables present on initial load
        initializeSalesPaginationAndSorting();
        initializeTopProductsPaginationAndSorting();
        if (document.getElementById('dailyBreakdownTableBody')) {
            initializeDailyBreakdownPaginationAndSorting();
        }

        // Update download link on page load
        updateDownloadLink();
        
        // Add change event listener to the date select to update the download link
        const dateSelect = document.getElementById('date');
        if (dateSelect) {
            dateSelect.addEventListener('change', function() {
                updateDownloadLink();
            });
        } else {
            console.error("Date select element ('date') not found.");
        }
        
        // Initialize card filtering - show all by default
        filterByCard('all');
    });

    function updateDownloadLink() {
        var date = document.getElementById('date').value;
        var year = '';
        var month = '';
        // Expecting date in format YYYY-MM-DD
        if (date && /^\d{4}-\d{2}-\d{2}$/.test(date)) {
            var parts = date.split('-');
            year = parts[0];
            month = parts[1];
        } else {
            // fallback: use today's date
            var today = new Date();
            year = today.getFullYear();
            month = ("0" + (today.getMonth() + 1)).slice(-2);
        }
        var link = document.getElementById('downloadMonthlyReport');
        if (link) {
            link.href = "generate_monthly_report.php?month=" + month + "&year=" + year;
        }
    }

    function showTransactionDetails(date) {
        // Create modal to show detailed transactions for the specified date
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.id = 'transaction-modal';
        
        // Fetch transaction details for this date
        fetch('../fetch_transaction_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `date=${date}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error loading transaction details: ' + data.error);
                document.body.removeChild(modal);
                return;
            }
            
            // Create modal content
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-lg max-w-3xl w-full max-h-[80vh] overflow-hidden">
                    <div class="p-4 bg-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-800">Transactions for ${date}</h3>
                        <button class="text-gray-600 hover:text-gray-900" onclick="document.getElementById('transaction-modal').remove()">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="p-4 overflow-y-auto max-h-[calc(80vh-8rem)]">
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700 mb-2">Income</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        ${data.income.map(item => `
                                            <tr>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900">${item.type}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-600">N$${parseFloat(item.amount).toFixed(2)}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-600">${item.time}</td>
                                                <td class="px-3 py-2 text-sm text-gray-600 truncate max-w-xs" title="${item.details}">${item.details}</td>
                                            </tr>
                                        `).join('') || '<tr><td colspan="4" class="px-3 py-2 text-center text-sm text-gray-500">No income transactions</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700 mb-2">Expenses</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        ${data.expenses.map(item => `
                                            <tr>
                                                <td class="px-3 py-2 text-sm font-medium text-gray-900">${item.description}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-red-600">N$${parseFloat(item.amount).toFixed(2)}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-600">${item.time}</td>
                                            </tr>
                                        `).join('') || '<tr><td colspan="3" class="px-3 py-2 text-center text-sm text-gray-500">No expense transactions</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-gray-700">Total Income:</span>
                                <span class="text-gray-600 font-bold">N$${parseFloat(data.totals.income).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between items-center mt-2">
                                <span class="font-bold text-gray-700">Total Expenses:</span>
                                <span class="text-red-600 font-bold">N$${parseFloat(data.totals.expenses).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between items-center mt-2 pt-2 border-t border-gray-200">
                                <span class="font-bold text-gray-900">Net (Profit/Loss):</span>
                                <span class="font-bold ${data.totals.net >= 0 ? 'text-teal-600' : 'text-red-600'}">
                                    N$${parseFloat(data.totals.net).toFixed(2)}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-gray-100 flex justify-end">
                        <button class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 font-medium" 
                                onclick="document.getElementById('transaction-modal').remove()">
                            Close
                        </button>
                    </div>
                </div>
            `;
        })
        .catch(error => {
            console.error('Error fetching transaction details:', error);
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-lg p-6 max-w-md w-full">
                    <h3 class="text-lg font-bold text-red-600 mb-4">Error</h3>
                    <p class="text-gray-700">Failed to load transaction details.</p>
                    <div class="mt-6 flex justify-end">
                        <button class="px-4 py-2 bg-gray-300 text-gray-800 rounded hover:bg-gray-400"
                                onclick="document.getElementById('transaction-modal').remove()">
                            Close
                        </button>
                    </div>
                </div>
            `;
        });
        
        document.body.appendChild(modal);
    }
    
    // Mobile sidebar functions
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');
        const hamburger = document.querySelector('.hamburger');
        
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        hamburger.classList.toggle('open');
    }
    
    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');
        const hamburger = document.querySelector('.hamburger');
        
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        hamburger.classList.remove('open');
    }
    </script>
</body>
</html>