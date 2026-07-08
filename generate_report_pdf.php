<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ");
    exit();
}

// Get parameters
$reportType = $_GET['report_type'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$cashierId = $_GET['cashier_id'] ?? '';
$creditorId = $_GET['creditor_id'] ?? '';
$category = $_GET['category'] ?? '';

// Database connections
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $userDb = new PDO('sqlite:user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $infoDb = new PDO('sqlite:info.db');
    $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get business info
$businessInfo = [];
try {
    $businessInfo = $infoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $businessInfo = ['name' => 'POS System', 'address' => '', 'phone' => ''];
}

$businessName = $businessInfo['name'] ?? 'POS System';
$businessAddress = $businessInfo['address'] ?? '';
$businessPhone = $businessInfo['phone'] ?? '';

// Get closing time for business day calculations
$closingTime = $businessInfo['closing_time'] ?? '00:00';
$closingHour = (int)substr($closingTime, 0, 2);
$isAfterMidnight = $closingHour < 12;

// Business day WHERE clause generator
function getBusinessDayWhereClause($dateField, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    // Safety check - if dates are invalid, use simple date range
    if (empty($startDate) || empty($endDate)) {
        return "1=1";
    }
    
    // For date ranges longer than 7 days, use simple date filtering to avoid performance issues
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $daysDiff = $start->diff($end)->days;
    
    if ($daysDiff > 7) {
        // Simple date range for longer periods
        $endPlusOne = date('Y-m-d', strtotime($endDate . ' +1 day'));
        return "DATE($dateField) >= '$startDate' AND DATE($dateField) <= '$endPlusOne'";
    }
    
    if ($startDate === $endDate) {
        $nextDay = date('Y-m-d', strtotime($startDate . ' +1 day'));
        return "
            (DATE($dateField) = '$startDate' AND strftime('%H:%M', $dateField) >= '$closingTime') OR
            (DATE($dateField) = '$nextDay' AND strftime('%H:%M', $dateField) < '$closingTime' AND " . ($isAfterMidnight ? "1" : "0") . " = 1)
        ";
    } else {
        $whereClauses = [];
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        
        while ($currentDate <= $endDateTime) {
            $dateStr = $currentDate->format('Y-m-d');
            $nextDay = clone $currentDate;
            $nextDay->modify('+1 day');
            $nextDayStr = $nextDay->format('Y-m-d');
            
            $whereClauses[] = "
                (DATE($dateField) = '$dateStr' AND strftime('%H:%M', $dateField) >= '$closingTime') OR
                (DATE($dateField) = '$nextDayStr' AND strftime('%H:%M', $dateField) < '$closingTime' AND " . ($isAfterMidnight ? "1" : "0") . " = 1)
            ";
            
            $currentDate->modify('+1 day');
        }
        
        // Safety check - ensure we have at least one clause
        if (empty($whereClauses)) {
            return "DATE($dateField) >= '$startDate' AND DATE($dateField) <= '$endDate'";
        }
        
        return "(" . implode(") OR (", $whereClauses) . ")";
    }
}

// Format currency
function formatCurrency($amount) {
    return number_format((float)$amount, 2);
}

// Get cash data for one business day (includes all fields from admin/get_cashup_data.php)
function getCashDataForDate($db, $selectedDate, $closingTime, $isAfterMidnight) {
    $nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
    $eftTableExists = false;
    try {
        $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn() !== false;
    } catch (PDOException $e) {
    }
    $bdWhere = "(
        (DATE(:dateField) = :d AND strftime('%H:%M', :dateField) >= :ct) OR
        (DATE(:dateField) = :nbd AND strftime('%H:%M', :dateField) < :ct2 AND :iam = 1)
    )";
    $params = [':d' => $selectedDate, ':nbd' => $nextBusinessDay, ':ct' => $closingTime, ':ct2' => $closingTime, ':iam' => (int)$isAfterMidnight];
    
    // 1. Cash in (deposits)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
        WHERE type='cash-in' AND (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $totalCashIn = (float)$stmt->fetchColumn();
    
    // 2. Cash sales (orders with no EFT, or sum(total - eft) if mixed)
    if ($eftTableExists) {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
            ), 0) FROM orders o
            WHERE (
                (DATE(o.created_at) = :d AND strftime('%H:%M', o.created_at) >= :ct) OR
                (DATE(o.created_at) = :nbd AND strftime('%H:%M', o.created_at) < :ct2 AND :iam = 1)
            )
        ");
    } else {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) FROM orders
            WHERE (
                (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
                (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
            )
        ");
    }
    $stmt->execute($params);
    $totalCashSales = (float)$stmt->fetchColumn();
    
    // 3. Credit payments (cash received against credit sales)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(p.amount), 0) FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.payment_status = 'paid' AND (
            (DATE(p.payment_date) = :d AND strftime('%H:%M', p.payment_date) >= :ct) OR
            (DATE(p.payment_date) = :nbd AND strftime('%H:%M', p.payment_date) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $totalCreditPayments = (float)$stmt->fetchColumn();
    
    // 4. Cash out (withdrawals)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
        WHERE type='cash-out' AND (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $totalCashOut = (float)$stmt->fetchColumn();
    
    // 5. Card Sales Expected (EFT payments)
    $cardSalesExpected = 0;
    if ($eftTableExists) {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(ep.amount), 0) FROM eft_payments ep
            JOIN orders o ON ep.order_id = o.id
            WHERE (
                (DATE(ep.payment_date) = :d AND strftime('%H:%M', ep.payment_date) >= :ct) OR
                (DATE(ep.payment_date) = :nbd AND strftime('%H:%M', ep.payment_date) < :ct2 AND :iam = 1)
            )
        ");
        $stmt->execute($params);
        $cardSalesExpected = (float)$stmt->fetchColumn();
    }
    
    // 6. Unpaid Credit Sales
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM credit_sales
        WHERE payment_status = 'unpaid' AND (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $unpaidCreditSales = (float)$stmt->fetchColumn();
    
    // 7. Open Tabs Balance
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(current_balance), 0) FROM tabs
        WHERE status = 'open' AND (
            (DATE(opened_at) = :d AND strftime('%H:%M', opened_at) >= :ct) OR
            (DATE(opened_at) = :nbd AND strftime('%H:%M', opened_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $openTabsBalance = (float)$stmt->fetchColumn();
    $unpaidTabs = $unpaidCreditSales + $openTabsBalance;
    
    // 8. Credit Returns
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(return_amount), 0) FROM credit_returns
        WHERE (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $creditReturnsAmount = (float)$stmt->fetchColumn();
    $creditReturns = $creditReturnsAmount + $totalCreditPayments;
    
    // 9. Expenses (excluding tips and cash back)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
        WHERE type = 'cash-out' 
        AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%')
        AND (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $expenses = (float)$stmt->fetchColumn();
    
    // 10. Cash Back (system value)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
        WHERE type = 'cash-out' 
        AND (description LIKE '%Cash Back%' OR description LIKE '%cash back%')
        AND (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $cashBackSystem = (float)$stmt->fetchColumn();
    
    // 11. Tips (system value)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
        WHERE type = 'cash-out' 
        AND (description LIKE '%Tips%' OR description LIKE '%tip%')
        AND (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $tipsSystem = (float)$stmt->fetchColumn();
    
    // 12. Voids
    $voids = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) FROM void_transactions
            WHERE (
                (DATE(voided_at) = :d AND strftime('%H:%M', voided_at) >= :ct) OR
                (DATE(voided_at) = :nbd AND strftime('%H:%M', voided_at) < :ct2 AND :iam = 1)
            )
        ");
        $stmt->execute($params);
        $voids = (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // 13. Refunds
    $refunds = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount), 0) FROM refunds
            WHERE (
                (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
                (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
            )
        ");
        $stmt->execute($params);
        $refunds = (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // 14. Total Items Sold
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) FROM orders
        WHERE (
            (DATE(created_at) = :d AND strftime('%H:%M', created_at) >= :ct) OR
            (DATE(created_at) = :nbd AND strftime('%H:%M', created_at) < :ct2 AND :iam = 1)
        )
    ");
    $stmt->execute($params);
    $totalItemsSold = (float)$stmt->fetchColumn();
    
    $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
    
    return [
        'totalCashIn' => $totalCashIn,
        'totalCashSales' => $totalCashSales,
        'totalCreditPayments' => $totalCreditPayments,
        'totalCashOut' => $totalCashOut,
        'cashInTill' => $cashInTill,
        'totalCashReceived' => $totalCashIn + $totalCashSales + $totalCreditPayments,
        'cardSalesExpected' => $cardSalesExpected,
        'unpaidCreditSales' => $unpaidCreditSales,
        'openTabsBalance' => $openTabsBalance,
        'unpaidTabs' => $unpaidTabs,
        'creditReturns' => $creditReturns,
        'expenses' => $expenses,
        'cashBackSystem' => $cashBackSystem,
        'tipsSystem' => $tipsSystem,
        'voids' => $voids,
        'refunds' => $refunds,
        'totalItemsSold' => $totalItemsSold
    ];
}

// Get report title
function getReportTitle($type) {
    $titles = [
        'sales' => 'Sales Report',
        'daily_sales' => 'Daily Sales Report',
        'monthly_sales' => 'Monthly Sales Report',
        'plu' => 'PLU Report',
        'item_sales' => 'Item Sales Report',
        'cash_sales' => 'Cash Sales Report',
        'card_sales' => 'Card Sales Report',
        'payment_summary' => 'Payment Summary Report',
        'cashup' => 'Cash-Up Report',
        'credit_sales' => 'Credit Sales Report',
        'outstanding_credit' => 'Outstanding Credit Report',
        'tabs' => 'Tabs Report',
        'expenses' => 'Expenses Report',
        'current_stock' => 'Current Stock Report',
        'stock_movement' => 'Stock Movement Report',
        'low_stock' => 'Low Stock Report',
        'stock_variance' => 'Stock Variance Report',
        'refunds' => 'Refunds Report',
        'voids' => 'Voids Report',
        'cashier_sales' => 'Cashier Sales Report',
        'shift' => 'Shift Report',
        'profit_loss' => 'Profit & Loss Report',
        'audit_log' => 'Audit Log Report'
    ];
    return $titles[$type] ?? 'Report';
}

// Generate report data based on type
$reportData = [];
$reportTitle = getReportTitle($reportType);
$dateRange = ($startDate === $endDate) ? date('F j, Y', strtotime($startDate)) : date('M j, Y', strtotime($startDate)) . ' - ' . date('M j, Y', strtotime($endDate));

$whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);

switch ($reportType) {
    case 'sales':
    case 'daily_sales':
    case 'monthly_sales':
        // Get orders
        $ordersWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $ordersQuery = $db->prepare("
            SELECT o.id, o.total, o.cash_received, o.created_at, o.cashier_id,
                   COALESCE(o.cashier_id, 'Unknown') as cashier_name
            FROM orders o
            WHERE ($ordersWhereClause)
            ORDER BY o.created_at DESC
        ");
        $ordersQuery->execute();
        $orders = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get totals
        $cashTotal = 0;
        $cardTotal = 0;
        
        foreach ($orders as &$order) {
            // Get EFT amount for this order
            $eftQuery = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM eft_payments WHERE order_id = ?");
            $eftQuery->execute([$order['id']]);
            $eftAmount = $eftQuery->fetchColumn();
            
            $order['eft_amount'] = $eftAmount;
            $order['cash_amount'] = $order['total'] - $eftAmount;
            
            $cashTotal += $order['cash_amount'];
            $cardTotal += $eftAmount;
            
            // Get order items
            $itemsQuery = $db->prepare("SELECT product_name, quantity, price FROM order_items WHERE order_id = ?");
            $itemsQuery->execute([$order['id']]);
            $order['items'] = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get credit sales
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditQuery = $db->prepare("
            SELECT cs.*, c.name as creditor_name
            FROM credit_sales cs
            LEFT JOIN creditors c ON cs.creditor_id = c.id
            WHERE ($creditWhereClause)
            ORDER BY cs.created_at DESC
        ");
        $creditQuery->execute();
        $creditSales = $creditQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $creditTotal = array_sum(array_column($creditSales, 'total_amount'));
        $creditPaid = array_sum(array_column($creditSales, 'paid_amount'));
        
        $reportData = [
            'orders' => $orders,
            'credit_sales' => $creditSales,
            'summary' => [
                'total_orders' => count($orders),
                'total_credit_sales' => count($creditSales),
                'cash_total' => $cashTotal,
                'card_total' => $cardTotal,
                'credit_total' => $creditTotal,
                'credit_paid' => $creditPaid,
                'credit_outstanding' => $creditTotal - $creditPaid,
                'grand_total' => $cashTotal + $cardTotal + $creditTotal
            ]
        ];
        break;
        
    case 'plu':
        // Get all products with their PLU/barcode
        $categoryCondition = $category ? " AND category = " . $db->quote($category) : "";
        $productsQuery = $db->query("
            SELECT id, name, barcode, price, buying_price, quantity, category, restock_level
            FROM products
            WHERE 1=1 $categoryCondition
            ORDER BY name ASC
        ");
        $reportData = [
            'products' => $productsQuery->fetchAll(PDO::FETCH_ASSOC)
        ];
        break;
        
    case 'item_sales':
        // Get item sales
        // Note: order_items.price and credit_sale_items.price store LINE TOTAL (already multiplied by qty)
        // So we should NOT multiply by quantity again
        $categoryCondition = $category ? " AND p.category = " . $db->quote($category) : "";
        $ordersWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $itemsQuery = $db->prepare("
            SELECT 
                combined.product_name,
                SUM(combined.quantity) as total_quantity,
                SUM(combined.total_value) as total_value,
                COALESCE(p.price, 0) as unit_price,
                COALESCE(p.buying_price, 0) as unit_cost,
                COALESCE(p.category, 'Uncategorized') as category
            FROM (
                SELECT oi.product_name, oi.quantity, oi.price as total_value
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE ($ordersWhereClause)
                UNION ALL
                SELECT csi.product_name, csi.quantity, csi.price as total_value
                FROM credit_sale_items csi
                JOIN credit_sales cs ON csi.sale_id = cs.id
                WHERE ($creditWhereClause)
            ) combined
            LEFT JOIN products p ON combined.product_name = p.name
            WHERE 1=1 $categoryCondition
            GROUP BY combined.product_name
            ORDER BY total_quantity DESC
        ");
        $itemsQuery->execute();
        $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalQty = array_sum(array_column($items, 'total_quantity'));
        $totalValue = array_sum(array_column($items, 'total_value'));
        
        $reportData = [
            'items' => $items,
            'summary' => [
                'total_items' => count($items),
                'total_quantity' => $totalQty,
                'total_value' => $totalValue
            ]
        ];
        break;
        
    case 'cash_sales':
        // Get cash sales only
        $ordersWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $ordersQuery = $db->prepare("
            SELECT o.id, o.total, o.cash_received, o.created_at, o.cashier_id,
                   COALESCE((SELECT SUM(amount) FROM eft_payments WHERE order_id = o.id), 0) as eft_amount
            FROM orders o
            WHERE ($ordersWhereClause)
            ORDER BY o.created_at DESC
        ");
        $ordersQuery->execute();
        $orders = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter to cash only and calculate cash amount
        $cashOrders = [];
        $totalCash = 0;
        foreach ($orders as $order) {
            $cashAmount = $order['total'] - $order['eft_amount'];
            if ($cashAmount > 0) {
                $order['cash_amount'] = $cashAmount;
                $cashOrders[] = $order;
                $totalCash += $cashAmount;
            }
        }
        
        $reportData = [
            'orders' => $cashOrders,
            'summary' => [
                'total_transactions' => count($cashOrders),
                'total_cash' => $totalCash
            ]
        ];
        break;
        
    case 'card_sales':
        // Get card/EFT sales only
        $whereClause = getBusinessDayWhereClause('ep.payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $eftQuery = $db->prepare("
            SELECT ep.*, o.total as order_total, o.created_at as order_date
            FROM eft_payments ep
            JOIN orders o ON ep.order_id = o.id
            WHERE ($whereClause)
            ORDER BY ep.payment_date DESC
        ");
        $eftQuery->execute();
        $eftPayments = $eftQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalEft = array_sum(array_column($eftPayments, 'amount'));
        
        $reportData = [
            'payments' => $eftPayments,
            'summary' => [
                'total_transactions' => count($eftPayments),
                'total_eft' => $totalEft
            ]
        ];
        break;
        
    case 'payment_summary':
        // Get payment summary by method
        $ordersWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        // Cash sales
        $cashQuery = $db->prepare("
            SELECT COALESCE(SUM(o.total - COALESCE((SELECT SUM(amount) FROM eft_payments WHERE order_id = o.id), 0)), 0) as total
            FROM orders o
            WHERE ($ordersWhereClause)
        ");
        $cashQuery->execute();
        $cashTotal = $cashQuery->fetchColumn();
        
        // EFT sales
        $eftWhereClause = getBusinessDayWhereClause('ep.payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $eftQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total FROM eft_payments ep WHERE ($eftWhereClause)
        ");
        $eftQuery->execute();
        $eftTotal = $eftQuery->fetchColumn();
        
        // Credit sales
        $creditWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditQuery = $db->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(paid_amount), 0) as paid
            FROM credit_sales WHERE ($creditWhereClause)
        ");
        $creditQuery->execute();
        $creditRow = $creditQuery->fetch(PDO::FETCH_ASSOC);
        
        // Tab payments
        $tabWhereClause = getBusinessDayWhereClause('payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $tabCashQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM tab_payments WHERE payment_method = 'cash' AND ($tabWhereClause)
        ");
        $tabCashQuery->execute();
        $tabCash = $tabCashQuery->fetchColumn();
        
        $tabEftQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM tab_payments WHERE payment_method = 'eft' AND ($tabWhereClause)
        ");
        $tabEftQuery->execute();
        $tabEft = $tabEftQuery->fetchColumn();
        
        $reportData = [
            'summary' => [
                'cash_sales' => $cashTotal,
                'eft_sales' => $eftTotal,
                'credit_sales_total' => $creditRow['total'],
                'credit_sales_paid' => $creditRow['paid'],
                'credit_outstanding' => $creditRow['total'] - $creditRow['paid'],
                'tab_cash_payments' => $tabCash,
                'tab_eft_payments' => $tabEft,
                'grand_total' => $cashTotal + $eftTotal + $creditRow['total']
            ]
        ];
        break;
        
    case 'cashup':
        // Daily cash data (includes all fields from admin/get_cashup_data.php) for each day in range
        $dailyCash = [];
        $totals = [
            'totalCashIn' => 0, 'totalCashSales' => 0, 'totalCreditPayments' => 0, 'totalCashOut' => 0,
            'cashInTill' => 0, 'totalCashReceived' => 0, 'cardSalesExpected' => 0, 'unpaidCreditSales' => 0,
            'openTabsBalance' => 0, 'unpaidTabs' => 0, 'creditReturns' => 0, 'expenses' => 0,
            'cashBackSystem' => 0, 'tipsSystem' => 0, 'voids' => 0, 'refunds' => 0, 'totalItemsSold' => 0
        ];
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($current <= $end) {
            $d = $current->format('Y-m-d');
            $dayData = getCashDataForDate($db, $d, $closingTime, $isAfterMidnight);
            $dayData['date'] = $d;
            $dailyCash[] = $dayData;
            foreach (array_keys($totals) as $k) {
                if (isset($dayData[$k])) {
                    $totals[$k] += $dayData[$k];
                }
            }
            $current->modify('+1 day');
        }
        // Get all cash-out transactions (withdrawals) for the period
        $withdrawalsWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $withdrawalsCashierCondition = $cashierId ? " AND cashier_id = " . $db->quote($cashierId) : "";
        $withdrawalsQuery = $db->prepare("
            SELECT * FROM cash_transactions
            WHERE type = 'cash-out' AND ($withdrawalsWhereClause) $withdrawalsCashierCondition
            ORDER BY created_at DESC
        ");
        $withdrawalsQuery->execute();
        $withdrawals = $withdrawalsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Also get cashup_records (recorded cash-ups) for the period
        $cashierCondition = $cashierId ? " AND cashier_id = " . $db->quote($cashierId) : "";
        $cashupQuery = $db->prepare("
            SELECT * FROM cashup_records
            WHERE cashup_date BETWEEN :start AND :end $cashierCondition
            ORDER BY cashup_date DESC, created_at DESC
        ");
        $cashupQuery->execute([':start' => $startDate, ':end' => $endDate]);
        $cashups = $cashupQuery->fetchAll(PDO::FETCH_ASSOC);
        $totalExpected = array_sum(array_column($cashups, 'cash_sales_expected'));
        $totalOnHand = array_sum(array_column($cashups, 'cash_on_hand'));
        $totalVariance = array_sum(array_column($cashups, 'over_short'));
        $reportData = [
            'cashups' => $cashups,
            'daily' => $dailyCash,
            'totals' => $totals,
            'withdrawals' => $withdrawals,
            'summary' => [
                'total_records' => count($cashups),
                'total_expected' => $totalExpected,
                'total_on_hand' => $totalOnHand,
                'total_variance' => $totalVariance
            ]
        ];
        break;
        
    case 'credit_sales':
        // Get credit sales
        $creditorCondition = $creditorId ? " AND cs.creditor_id = " . $db->quote($creditorId) : "";
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditQuery = $db->prepare("
            SELECT cs.*, c.name as creditor_name, c.phone as creditor_phone
            FROM credit_sales cs
            LEFT JOIN creditors c ON cs.creditor_id = c.id
            WHERE ($creditWhereClause) $creditorCondition
            ORDER BY cs.created_at DESC
        ");
        $creditQuery->execute();
        $creditSales = $creditQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get items for each sale
        foreach ($creditSales as &$sale) {
            $itemsQuery = $db->prepare("SELECT product_name, quantity, price FROM credit_sale_items WHERE sale_id = ?");
            $itemsQuery->execute([$sale['id']]);
            $sale['items'] = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $totalAmount = array_sum(array_column($creditSales, 'total_amount'));
        $totalPaid = array_sum(array_column($creditSales, 'paid_amount'));
        
        $reportData = [
            'sales' => $creditSales,
            'summary' => [
                'total_sales' => count($creditSales),
                'total_amount' => $totalAmount,
                'total_paid' => $totalPaid,
                'total_outstanding' => $totalAmount - $totalPaid
            ]
        ];
        break;
        
    case 'outstanding_credit':
        // Get outstanding credit
        $creditorCondition = $creditorId ? " AND cs.creditor_id = " . $db->quote($creditorId) : "";
        $creditQuery = $db->prepare("
            SELECT cs.*, c.name as creditor_name, c.phone as creditor_phone,
                   (cs.total_amount - cs.paid_amount) as outstanding
            FROM credit_sales cs
            LEFT JOIN creditors c ON cs.creditor_id = c.id
            WHERE cs.payment_status != 'paid' $creditorCondition
            ORDER BY outstanding DESC
        ");
        $creditQuery->execute();
        $outstanding = $creditQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalOutstanding = array_sum(array_column($outstanding, 'outstanding'));
        
        $reportData = [
            'outstanding' => $outstanding,
            'summary' => [
                'total_accounts' => count($outstanding),
                'total_outstanding' => $totalOutstanding
            ]
        ];
        break;
        
    case 'tabs':
        // Get tabs
        $creditorCondition = $creditorId ? " AND t.creditor_id = " . $db->quote($creditorId) : "";
        $tabsWhereClause = getBusinessDayWhereClause('t.opened_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $tabsQuery = $db->prepare("
            SELECT t.*, c.name as creditor_name
            FROM tabs t
            LEFT JOIN creditors c ON t.creditor_id = c.id
            WHERE ($tabsWhereClause) $creditorCondition
            ORDER BY t.opened_at DESC
        ");
        $tabsQuery->execute();
        $tabs = $tabsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get items and payments for each tab
        foreach ($tabs as &$tab) {
            $itemsQuery = $db->prepare("SELECT product_name, quantity, price FROM tab_items WHERE tab_id = ?");
            $itemsQuery->execute([$tab['id']]);
            $tab['items'] = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
            
            $paymentsQuery = $db->prepare("SELECT amount, payment_method, payment_date FROM tab_payments WHERE tab_id = ?");
            $paymentsQuery->execute([$tab['id']]);
            $tab['payments'] = $paymentsQuery->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $openTabs = array_filter($tabs, fn($t) => $t['status'] === 'open');
        $closedTabs = array_filter($tabs, fn($t) => $t['status'] === 'closed');
        $totalBalance = array_sum(array_column($tabs, 'current_balance'));
        
        $reportData = [
            'tabs' => $tabs,
            'summary' => [
                'total_tabs' => count($tabs),
                'open_tabs' => count($openTabs),
                'closed_tabs' => count($closedTabs),
                'total_balance' => $totalBalance
            ]
        ];
        break;
        
    case 'expenses':
        // Get expenses (cash-out transactions)
        $expWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $expensesQuery = $db->prepare("
            SELECT * FROM cash_transactions
            WHERE type = 'cash-out' AND ($expWhereClause)
            ORDER BY created_at DESC
        ");
        $expensesQuery->execute();
        $expenses = $expensesQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        
        $reportData = [
            'expenses' => $expenses,
            'summary' => [
                'total_transactions' => count($expenses),
                'total_amount' => $totalExpenses
            ]
        ];
        break;
        
    case 'current_stock':
        // Get current stock
        $categoryCondition = $category ? " WHERE category = " . $db->quote($category) : "";
        $stockQuery = $db->query("
            SELECT id, name, quantity, price, buying_price, restock_level, category, barcode
            FROM products $categoryCondition
            ORDER BY name ASC
        ");
        $products = $stockQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalItems = count($products);
        $totalQuantity = array_sum(array_column($products, 'quantity'));
        $totalValue = 0;
        $totalCost = 0;
        
        foreach ($products as $product) {
            $totalValue += $product['quantity'] * $product['price'];
            $totalCost += $product['quantity'] * ($product['buying_price'] ?? 0);
        }
        
        $reportData = [
            'products' => $products,
            'summary' => [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'total_cost' => $totalCost
            ]
        ];
        break;
        
    case 'stock_movement':
        // Get stock movements
        $movementQuery = $db->prepare("
            SELECT sc.*, p.name as product_name
            FROM stock_changes sc
            JOIN products p ON sc.product_id = p.id
            WHERE DATE(sc.changed_at) BETWEEN :start AND :end
            ORDER BY sc.changed_at DESC
        ");
        $movementQuery->execute([':start' => $startDate, ':end' => $endDate]);
        $movements = $movementQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalIn = 0;
        $totalOut = 0;
        foreach ($movements as $m) {
            if ($m['quantity_change'] > 0) {
                $totalIn += $m['quantity_change'];
            } else {
                $totalOut += abs($m['quantity_change']);
            }
        }
        
        $reportData = [
            'movements' => $movements,
            'summary' => [
                'total_movements' => count($movements),
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'net_change' => $totalIn - $totalOut
            ]
        ];
        break;
        
    case 'low_stock':
        // Get low stock items
        $categoryCondition = $category ? " AND category = " . $db->quote($category) : "";
        $lowStockQuery = $db->query("
            SELECT id, name, quantity, restock_level, price, category,
                   (restock_level - quantity) as shortage
            FROM products
            WHERE quantity <= restock_level AND restock_level > 0 $categoryCondition
            ORDER BY shortage DESC
        ");
        $lowStock = $lowStockQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $reportData = [
            'products' => $lowStock,
            'summary' => [
                'total_low_stock' => count($lowStock)
            ]
        ];
        break;
        
    case 'stock_variance':
        // Get stock variance from daily stock summary
        $varianceQuery = $db->prepare("
            SELECT dss.*, p.name as product_name
            FROM daily_stock_summary dss
            JOIN products p ON dss.product_id = p.id
            WHERE dss.date BETWEEN :start AND :end
            ORDER BY dss.date DESC, p.name ASC
        ");
        $varianceQuery->execute([':start' => $startDate, ':end' => $endDate]);
        $variance = $varianceQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $reportData = [
            'variance' => $variance,
            'summary' => [
                'total_records' => count($variance)
            ]
        ];
        break;
        
    case 'refunds':
        // Get refunds - cashier_id now stores username directly
        $refundsWhereClause = getBusinessDayWhereClause('r.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $refundsQuery = $db->prepare("
            SELECT r.*, COALESCE(r.cashier_id, 'Unknown') as cashier_name
            FROM refunds r
            WHERE ($refundsWhereClause)
            ORDER BY r.created_at DESC
        ");
        $refundsQuery->execute();
        $refunds = $refundsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get items for each refund
        foreach ($refunds as &$refund) {
            $itemsQuery = $db->prepare("SELECT product_name, quantity, price FROM refund_items WHERE refund_id = ?");
            $itemsQuery->execute([$refund['id']]);
            $refund['items'] = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $totalRefunds = array_sum(array_column($refunds, 'total_amount'));
        
        $reportData = [
            'refunds' => $refunds,
            'summary' => [
                'total_refunds' => count($refunds),
                'total_amount' => $totalRefunds
            ]
        ];
        break;
        
    case 'voids':
        // Get void transactions
        $voidsWhereClause = getBusinessDayWhereClause('voided_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $voidsQuery = $db->prepare("
            SELECT * FROM void_transactions
            WHERE ($voidsWhereClause)
            ORDER BY voided_at DESC
        ");
        $voidsQuery->execute();
        $voids = $voidsQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $totalVoids = array_sum(array_column($voids, 'total'));
        
        $reportData = [
            'voids' => $voids,
            'summary' => [
                'total_voids' => count($voids),
                'total_amount' => $totalVoids
            ]
        ];
        break;
        
    case 'cashier_sales':
        // Get cashier sales - cashier_id now stores username directly
        $cashierCondition = $cashierId ? " AND o.cashier_id = " . $db->quote($cashierId) : "";
        $ordersWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        // Get all cashiers from user.db
        $cashiersQuery = $userDb->query("SELECT id, username, role FROM users");
        $allCashiers = $cashiersQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $cashierSales = [];
        foreach ($allCashiers as $cashier) {
            // Filter by username if cashierId is provided (now contains username)
            if ($cashierId && $cashier['username'] != $cashierId) continue;
            
            // Get order count and total - match by username
            $salesQuery = $db->prepare("
                SELECT COUNT(*) as order_count, COALESCE(SUM(total), 0) as total_sales
                FROM orders o
                WHERE (o.cashier_id = ? OR CAST(o.cashier_id AS TEXT) = ?) AND ($ordersWhereClause)
            ");
            $salesQuery->execute([$cashier['username'], $cashier['id']]);
            $salesData = $salesQuery->fetch(PDO::FETCH_ASSOC);
            
            // Get credit sales - match by username
            $creditWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
            $creditQuery = $db->prepare("
                SELECT COUNT(*) as credit_count, COALESCE(SUM(total_amount), 0) as credit_total
                FROM credit_sales
                WHERE (cashier_id = ? OR CAST(cashier_id AS TEXT) = ?) AND ($creditWhereClause)
            ");
            $creditQuery->execute([$cashier['username'], $cashier['id']]);
            $creditData = $creditQuery->fetch(PDO::FETCH_ASSOC);
            
            $cashierSales[] = [
                'cashier_id' => $cashier['username'],
                'cashier_name' => $cashier['username'],
                'role' => $cashier['role'],
                'order_count' => $salesData['order_count'],
                'total_sales' => $salesData['total_sales'],
                'credit_count' => $creditData['credit_count'],
                'credit_total' => $creditData['credit_total'],
                'grand_total' => $salesData['total_sales'] + $creditData['credit_total']
            ];
        }
        
        // Sort by total sales
        usort($cashierSales, fn($a, $b) => $b['grand_total'] <=> $a['grand_total']);
        
        $totalSales = array_sum(array_column($cashierSales, 'grand_total'));
        
        $reportData = [
            'cashiers' => $cashierSales,
            'summary' => [
                'total_cashiers' => count($cashierSales),
                'total_sales' => $totalSales
            ]
        ];
        break;
        
    case 'shift':
        // Get shift/login data
        $cashierCondition = $cashierId ? " AND ul.user_id = " . $db->quote($cashierId) : "";
        $shiftQuery = $db->prepare("
            SELECT ul.*, u.username
            FROM user_log ul
            LEFT JOIN users u ON ul.user_id = u.id
            WHERE DATE(ul.action_time) BETWEEN :start AND :end $cashierCondition
            ORDER BY ul.action_time DESC
        ");
        try {
            $shiftQuery->execute([':start' => $startDate, ':end' => $endDate]);
            $shifts = $shiftQuery->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // If user_log table doesn't exist or query fails
            $shifts = [];
        }
        
        $logins = array_filter($shifts, fn($s) => $s['action_type'] === 'login');
        $logouts = array_filter($shifts, fn($s) => $s['action_type'] === 'logout');
        
        $reportData = [
            'shifts' => $shifts,
            'summary' => [
                'total_logins' => count($logins),
                'total_logouts' => count($logouts)
            ]
        ];
        break;
        
    case 'profit_loss':
        // Get profit and loss data
        $ordersWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        // Revenue from orders
        $revenueQuery = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM orders o WHERE ($ordersWhereClause)");
        $revenueQuery->execute();
        $orderRevenue = $revenueQuery->fetchColumn();
        
        // Revenue from credit sales
        $creditRevenueQuery = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM credit_sales cs WHERE ($creditWhereClause)");
        $creditRevenueQuery->execute();
        $creditRevenue = $creditRevenueQuery->fetchColumn();
        
        // Cost of goods sold
        $cogsQuery = $db->prepare("
            SELECT COALESCE(SUM(oi.quantity * COALESCE(oi.buying_price, p.buying_price, 0)), 0)
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            LEFT JOIN products p ON oi.product_name = p.name
            WHERE ($ordersWhereClause)
        ");
        $cogsQuery->execute();
        $cogs = $cogsQuery->fetchColumn();
        
        // Credit sale COGS
        $creditCogsQuery = $db->prepare("
            SELECT COALESCE(SUM(csi.quantity * COALESCE(csi.buying_price, p.buying_price, 0)), 0)
            FROM credit_sale_items csi
            JOIN credit_sales cs ON csi.sale_id = cs.id
            LEFT JOIN products p ON csi.product_name = p.name
            WHERE ($creditWhereClause)
        ");
        $creditCogsQuery->execute();
        $creditCogs = $creditCogsQuery->fetchColumn();
        
        // Expenses
        $expWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $expQuery = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND ($expWhereClause)");
        $expQuery->execute();
        $expenses = $expQuery->fetchColumn();
        
        // Refunds
        $refundWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $refundQuery = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM refunds WHERE ($refundWhereClause)");
        $refundQuery->execute();
        $refunds = $refundQuery->fetchColumn();
        
        $totalRevenue = $orderRevenue + $creditRevenue;
        $totalCogs = $cogs + $creditCogs;
        $grossProfit = $totalRevenue - $totalCogs;
        $netProfit = $grossProfit - $expenses - $refunds;
        $grossMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
        $netMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;
        
        $reportData = [
            'summary' => [
                'order_revenue' => $orderRevenue,
                'credit_revenue' => $creditRevenue,
                'total_revenue' => $totalRevenue,
                'cost_of_goods_sold' => $totalCogs,
                'gross_profit' => $grossProfit,
                'expenses' => $expenses,
                'refunds' => $refunds,
                'net_profit' => $netProfit,
                'gross_margin' => $grossMargin,
                'net_margin' => $netMargin
            ]
        ];
        break;
        
    case 'audit_log':
        // Get user log/audit data
        $logQuery = $db->prepare("
            SELECT ul.*, u.username
            FROM user_log ul
            LEFT JOIN users u ON ul.user_id = u.id
            WHERE DATE(ul.action_time) BETWEEN :start AND :end
            ORDER BY ul.action_time DESC
            LIMIT 500
        ");
        try {
            $logQuery->execute([':start' => $startDate, ':end' => $endDate]);
            $logs = $logQuery->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $logs = [];
        }
        
        $reportData = [
            'logs' => $logs,
            'summary' => [
                'total_entries' => count($logs)
            ]
        ];
        break;
        
    default:
        $reportData = ['error' => 'Unknown report type'];
        break;
}

// Generate PDF HTML
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($reportTitle) ?> - <?= htmlspecialchars($businessName) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            background: #fff;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #14b8a6;
        }
        
        .header h1 {
            font-size: 24px;
            color: #14b8a6;
            margin-bottom: 5px;
        }
        
        .header .business-name {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .header .business-info {
            font-size: 11px;
            color: #666;
        }
        
        .header .date-range {
            font-size: 14px;
            color: #555;
            margin-top: 10px;
            font-weight: 500;
        }
        
        .header .generated {
            font-size: 10px;
            color: #888;
            margin-top: 5px;
        }
        
        .summary-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .summary-card {
            flex: 1;
            min-width: 150px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
        }
        
        .summary-card .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .summary-card .value {
            font-size: 20px;
            font-weight: bold;
            color: #1e293b;
            margin-top: 5px;
        }
        
        .summary-card.positive .value {
            color: #10b981;
        }
        
        .summary-card.negative .value {
            color: #ef4444;
        }
        
        .summary-card .hint {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        th {
            background: #14b8a6;
            color: white;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tr:nth-child(even) {
            background: #f9fafb;
        }
        
        tr:hover {
            background: #f3f4f6;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .font-bold {
            font-weight: bold;
        }
        
        .text-green {
            color: #10b981;
        }
        
        .text-red {
            color: #ef4444;
        }
        
        .text-gray {
            color: #6b7280;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #1e293b;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .total-row {
            background: #f1f5f9 !important;
            font-weight: bold;
        }
        
        .total-row td {
            border-top: 2px solid #14b8a6;
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #14b8a6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
        }
        
        .print-btn:hover {
            background: #0d9488;
        }
        
        @media print {
            .print-btn {
                display: none;
            }
            
            body {
                padding: 0;
            }
            
            .header {
                page-break-after: avoid;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .items-list {
            font-size: 10px;
            color: #666;
            max-width: 300px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 9V2h12v7"></path>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
            <path d="M6 14h12v8H6z"></path>
        </svg>
        Print Report
    </button>
    
    <div class="header">
        <h1><?= htmlspecialchars($reportTitle) ?></h1>
        <div class="business-name"><?= htmlspecialchars($businessName) ?></div>
        <?php if ($businessAddress): ?>
            <div class="business-info"><?= htmlspecialchars($businessAddress) ?></div>
        <?php endif; ?>
        <?php if ($businessPhone): ?>
            <div class="business-info">Tel: <?= htmlspecialchars($businessPhone) ?></div>
        <?php endif; ?>
        <div class="date-range"><?= htmlspecialchars($dateRange) ?></div>
        <div class="generated">Generated on <?= date('F j, Y \a\t H:i') ?> by <?= htmlspecialchars($_SESSION['username']) ?></div>
    </div>
    
    <?php
    // Render report based on type
    switch ($reportType):
        case 'sales':
        case 'daily_sales':
        case 'monthly_sales':
    ?>
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Orders</div>
                <div class="value"><?= $reportData['summary']['total_orders'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Cash Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['cash_total']) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Card Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['card_total']) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Credit Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['credit_total']) ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Grand Total</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['grand_total']) ?></div>
            </div>
        </div>
        
        <h3 class="section-title">Cash & Card Orders</h3>
        <?php if (!empty($reportData['orders'])): ?>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date/Time</th>
                    <th>Cashier</th>
                    <th class="text-right">Cash</th>
                    <th class="text-right">Card</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['orders'] as $order): ?>
                <tr>
                    <td>#<?= $order['id'] ?></td>
                    <td><?= date('M j, H:i', strtotime($order['created_at'])) ?></td>
                    <td><?= htmlspecialchars($order['cashier_name'] ?? 'Unknown') ?></td>
                    <td class="text-right">N$<?= formatCurrency($order['cash_amount']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($order['eft_amount']) ?></td>
                    <td class="text-right font-bold">N$<?= formatCurrency($order['total']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3">Total</td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['cash_total']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['card_total']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['cash_total'] + $reportData['summary']['card_total']) ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-data">No orders found for this period</div>
        <?php endif; ?>
        
        <?php if (!empty($reportData['credit_sales'])): ?>
        <h3 class="section-title">Credit Sales</h3>
        <table>
            <thead>
                <tr>
                    <th>Sale #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Outstanding</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['credit_sales'] as $sale): ?>
                <tr>
                    <td>#<?= $sale['id'] ?></td>
                    <td><?= date('M j, H:i', strtotime($sale['created_at'])) ?></td>
                    <td><?= htmlspecialchars($sale['creditor_name'] ?? 'Unknown') ?></td>
                    <td class="text-right">N$<?= formatCurrency($sale['total_amount']) ?></td>
                    <td class="text-right text-green">N$<?= formatCurrency($sale['paid_amount']) ?></td>
                    <td class="text-right <?= ($sale['total_amount'] - $sale['paid_amount']) > 0 ? 'text-red' : '' ?>">N$<?= formatCurrency($sale['total_amount'] - $sale['paid_amount']) ?></td>
                    <td>
                        <span class="badge <?= $sale['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
                            <?= ucfirst($sale['payment_status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3">Total Credit Sales</td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['credit_total']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['credit_paid']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['credit_outstanding']) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
    <?php break; case 'plu': ?>
        <table>
            <thead>
                <tr>
                    <th>PLU/Barcode</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Cost</th>
                    <th class="text-right">Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['products'])): ?>
                    <?php foreach ($reportData['products'] as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['barcode'] ?: $product['id']) ?></td>
                        <td><?= htmlspecialchars($product['name']) ?></td>
                        <td><?= htmlspecialchars($product['category'] ?: '-') ?></td>
                        <td class="text-right">N$<?= formatCurrency($product['price']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($product['buying_price'] ?? 0) ?></td>
                        <td class="text-right"><?= $product['quantity'] ?? 0 ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No products found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'item_sales': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Items</div>
                <div class="value"><?= $reportData['summary']['total_items'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Quantity</div>
                <div class="value"><?= $reportData['summary']['total_quantity'] ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Total Value</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_value']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th class="text-right">Qty Sold</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Total Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['items'])): ?>
                    <?php foreach ($reportData['items'] as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['category'] ?? '-') ?></td>
                        <td class="text-right"><?= $item['total_quantity'] ?></td>
                        <td class="text-right">N$<?= formatCurrency($item['unit_price']) ?></td>
                        <td class="text-right font-bold">N$<?= formatCurrency($item['total_value']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2">Total</td>
                        <td class="text-right"><?= $reportData['summary']['total_quantity'] ?></td>
                        <td></td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_value']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="5" class="no-data">No item sales found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'cash_sales': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Transactions</div>
                <div class="value"><?= $reportData['summary']['total_transactions'] ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Total Cash</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_cash']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date/Time</th>
                    <th class="text-right">Cash Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['orders'])): ?>
                    <?php foreach ($reportData['orders'] as $order): ?>
                    <tr>
                        <td>#<?= $order['id'] ?></td>
                        <td><?= date('M j, Y H:i', strtotime($order['created_at'])) ?></td>
                        <td class="text-right font-bold">N$<?= formatCurrency($order['cash_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2">Total</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_cash']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="3" class="no-data">No cash sales found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'card_sales': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Transactions</div>
                <div class="value"><?= $reportData['summary']['total_transactions'] ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Total EFT/Card</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_eft']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date/Time</th>
                    <th>Reference</th>
                    <th>Provider</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['payments'])): ?>
                    <?php foreach ($reportData['payments'] as $payment): ?>
                    <tr>
                        <td>#<?= $payment['order_id'] ?></td>
                        <td><?= date('M j, Y H:i', strtotime($payment['payment_date'])) ?></td>
                        <td><?= htmlspecialchars($payment['transaction_ref'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($payment['wallet_provider'] ?? '-') ?></td>
                        <td class="text-right font-bold">N$<?= formatCurrency($payment['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4">Total</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_eft']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="5" class="no-data">No card sales found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'payment_summary': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Cash Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['cash_sales']) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">EFT/Card Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['eft_sales']) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Credit Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['credit_sales_total']) ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Grand Total</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['grand_total']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grandTotal = $reportData['summary']['grand_total'];
                $methods = [
                    'Cash Sales' => $reportData['summary']['cash_sales'],
                    'Card/EFT Sales' => $reportData['summary']['eft_sales'],
                    'Credit Sales (Total)' => $reportData['summary']['credit_sales_total'],
                    'Credit Sales (Paid)' => $reportData['summary']['credit_sales_paid'],
                    'Credit Outstanding' => $reportData['summary']['credit_outstanding'],
                    'Tab Payments (Cash)' => $reportData['summary']['tab_cash_payments'],
                    'Tab Payments (EFT)' => $reportData['summary']['tab_eft_payments'],
                ];
                foreach ($methods as $method => $amount):
                    $pct = $grandTotal > 0 ? ($amount / $grandTotal) * 100 : 0;
                ?>
                <tr>
                    <td><?= $method ?></td>
                    <td class="text-right">N$<?= formatCurrency($amount) ?></td>
                    <td class="text-right"><?= number_format($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>Grand Total</td>
                    <td class="text-right">N$<?= formatCurrency($grandTotal) ?></td>
                    <td class="text-right">100%</td>
                </tr>
            </tbody>
        </table>
        
    <?php break; case 'cashup':
        $t = $reportData['totals'];
        ?>
        <h3 class="section-title">Cash Reconciliation (Complete Data from admin/get_cashup_data.php)</h3>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Cash In Till</div>
                <div class="value">N$<?= formatCurrency($t['cashInTill']) ?></div>
                <div class="hint">Available cash balance</div>
            </div>
            <div class="summary-card">
                <div class="label">Total Cash Received</div>
                <div class="value">N$<?= formatCurrency($t['totalCashReceived']) ?></div>
                <div class="hint">Deposits + cash sales + credit payments</div>
            </div>
            <div class="summary-card">
                <div class="label">Card Sales</div>
                <div class="value">N$<?= formatCurrency($t['cardSalesExpected']) ?></div>
                <div class="hint">EFT/Card transactions</div>
            </div>
            <div class="summary-card negative">
                <div class="label">Cash Withdrawals</div>
                <div class="value">N$<?= formatCurrency($t['totalCashOut']) ?></div>
                <div class="hint">Total cash out</div>
            </div>
        </div>
        
        <!-- CASH Section -->
        <h3 class="section-title">CASH Section</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Cash In</th>
                    <th class="text-right">Cash Sales</th>
                    <th class="text-right">Credit Payments</th>
                    <th class="text-right">Cash Out</th>
                    <th class="text-right">Cash In Till</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['daily'])): ?>
                    <?php foreach ($reportData['daily'] as $row): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['totalCashIn']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['totalCashSales']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['totalCreditPayments']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['totalCashOut']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['cashInTill']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right">N$<?= formatCurrency($t['totalCashIn']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['totalCashSales']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['totalCreditPayments']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['totalCashOut']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['cashInTill']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No daily cash data for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- CARD & CREDIT Section -->
        <h3 class="section-title">CARD & CREDIT Section</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Card Sales</th>
                    <th class="text-right">Unpaid Credit</th>
                    <th class="text-right">Open Tabs</th>
                    <th class="text-right">Unpaid Tabs</th>
                    <th class="text-right">Credit Returns</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['daily'])): ?>
                    <?php foreach ($reportData['daily'] as $row): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['cardSalesExpected']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['unpaidCreditSales']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['openTabsBalance']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['unpaidTabs']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['creditReturns']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right">N$<?= formatCurrency($t['cardSalesExpected']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['unpaidCreditSales']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['openTabsBalance']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['unpaidTabs']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['creditReturns']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No daily data for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- DEDUCTIONS Section -->
        <h3 class="section-title">DEDUCTIONS Section</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Expenses</th>
                    <th class="text-right">Cash Back</th>
                    <th class="text-right">Tips</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['daily'])): ?>
                    <?php foreach ($reportData['daily'] as $row): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['expenses']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['cashBackSystem']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['tipsSystem']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right">N$<?= formatCurrency($t['expenses']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['cashBackSystem']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['tipsSystem']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="4" class="no-data">No daily data for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- ADJUSTMENTS Section -->
        <h3 class="section-title">ADJUSTMENTS Section</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Voids</th>
                    <th class="text-right">Refunds</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['daily'])): ?>
                    <?php foreach ($reportData['daily'] as $row): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['voids']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['refunds']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right">N$<?= formatCurrency($t['voids']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($t['refunds']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="3" class="no-data">No daily data for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- WITHDRAWALS Section -->
        <h3 class="section-title">WITHDRAWALS (Cash-Out Transactions)</h3>
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Description</th>
                    <th>Cashier</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['withdrawals'])): ?>
                    <?php 
                    $withdrawalsTotal = 0;
                    foreach ($reportData['withdrawals'] as $withdrawal): 
                        $withdrawalsTotal += $withdrawal['amount'];
                    ?>
                    <tr>
                        <td><?= date('M j, Y H:i', strtotime($withdrawal['created_at'])) ?></td>
                        <td><?= htmlspecialchars($withdrawal['description'] ?? 'Withdrawal') ?></td>
                        <td><?= htmlspecialchars($withdrawal['cashier_id'] ?? '-') ?></td>
                        <td class="text-right">N$<?= formatCurrency($withdrawal['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3"><strong>Total Withdrawals</strong></td>
                        <td class="text-right">N$<?= formatCurrency($withdrawalsTotal) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="4" class="no-data">No withdrawals found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- TOTAL Section -->
        <h3 class="section-title">TOTAL Section</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-right">Total Items Sold</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['daily'])): ?>
                    <?php foreach ($reportData['daily'] as $row): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                        <td class="text-right">N$<?= formatCurrency($row['totalItemsSold']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right">N$<?= formatCurrency($t['totalItemsSold']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="2" class="no-data">No daily data for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($reportData['cashups'])): ?>
        <h3 class="section-title">Recorded Cash-Ups (Manual Entries)</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cashier</th>
                    <th class="text-right">Expected</th>
                    <th class="text-right">On Hand</th>
                    <th class="text-right">Variance</th>
                    <th class="text-right">Tips</th>
                    <th class="text-right">Hubbly</th>
                    <th class="text-right">Beerhouse</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $recordedTotals = [
                    'expected' => 0, 'onHand' => 0, 'variance' => 0, 
                    'tips' => 0, 'hubbly' => 0, 'beerhouse' => 0
                ];
                foreach ($reportData['cashups'] as $cashup):
                    $recordedTotals['expected'] += $cashup['cash_sales_expected'] ?? 0;
                    $recordedTotals['onHand'] += $cashup['cash_on_hand'] ?? 0;
                    $recordedTotals['variance'] += $cashup['over_short'] ?? 0;
                    $recordedTotals['tips'] += $cashup['tips'] ?? 0;
                    $recordedTotals['hubbly'] += $cashup['hubbly'] ?? 0;
                    $recordedTotals['beerhouse'] += $cashup['beerhouse'] ?? 0;
                ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($cashup['cashup_date'])) ?></td>
                    <td><?= htmlspecialchars($cashup['cashier_name']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($cashup['cash_sales_expected'] ?? 0) ?></td>
                    <td class="text-right">N$<?= formatCurrency($cashup['cash_on_hand'] ?? 0) ?></td>
                    <td class="text-right <?= ($cashup['over_short'] ?? 0) >= 0 ? 'text-green' : 'text-red' ?>">
                        N$<?= formatCurrency($cashup['over_short'] ?? 0) ?>
                    </td>
                    <td class="text-right">N$<?= formatCurrency($cashup['tips'] ?? 0) ?></td>
                    <td class="text-right">N$<?= formatCurrency($cashup['hubbly'] ?? 0) ?></td>
                    <td class="text-right">N$<?= formatCurrency($cashup['beerhouse'] ?? 0) ?></td>
                    <td>
                        <?php $overShort = $cashup['over_short'] ?? 0; ?>
                        <?php if ($overShort == 0): ?>
                            <span class="badge badge-success">Balanced</span>
                        <?php elseif ($overShort > 0): ?>
                            <span class="badge badge-info">Over</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Short</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2"><strong>Total</strong></td>
                    <td class="text-right">N$<?= formatCurrency($recordedTotals['expected']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($recordedTotals['onHand']) ?></td>
                    <td class="text-right <?= $recordedTotals['variance'] >= 0 ? 'text-green' : 'text-red' ?>">
                        N$<?= formatCurrency($recordedTotals['variance']) ?>
                    </td>
                    <td class="text-right">N$<?= formatCurrency($recordedTotals['tips']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($recordedTotals['hubbly']) ?></td>
                    <td class="text-right">N$<?= formatCurrency($recordedTotals['beerhouse']) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
    <?php break; case 'credit_sales': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Sales</div>
                <div class="value"><?= $reportData['summary']['total_sales'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Amount</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Total Paid</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_paid']) ?></div>
            </div>
            <div class="summary-card negative">
                <div class="label">Outstanding</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_outstanding']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Sale #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['sales'])): ?>
                    <?php foreach ($reportData['sales'] as $sale): ?>
                    <tr>
                        <td>#<?= $sale['id'] ?></td>
                        <td><?= date('M j, Y', strtotime($sale['created_at'])) ?></td>
                        <td><?= htmlspecialchars($sale['creditor_name'] ?? 'Unknown') ?></td>
                        <td><?= htmlspecialchars($sale['creditor_phone'] ?? '-') ?></td>
                        <td class="text-right">N$<?= formatCurrency($sale['total_amount']) ?></td>
                        <td class="text-right text-green">N$<?= formatCurrency($sale['paid_amount']) ?></td>
                        <td class="text-right text-red">N$<?= formatCurrency($sale['total_amount'] - $sale['paid_amount']) ?></td>
                        <td>
                            <span class="badge <?= $sale['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
                                <?= ucfirst($sale['payment_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="no-data">No credit sales found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'outstanding_credit': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Accounts</div>
                <div class="value"><?= $reportData['summary']['total_accounts'] ?></div>
            </div>
            <div class="summary-card negative">
                <div class="label">Total Outstanding</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_outstanding']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Sale Date</th>
                    <th>Due Date</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['outstanding'])): ?>
                    <?php foreach ($reportData['outstanding'] as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['creditor_name'] ?? 'Unknown') ?></td>
                        <td><?= htmlspecialchars($item['creditor_phone'] ?? '-') ?></td>
                        <td><?= date('M j, Y', strtotime($item['created_at'])) ?></td>
                        <td><?= $item['due_date'] ? date('M j, Y', strtotime($item['due_date'])) : '-' ?></td>
                        <td class="text-right">N$<?= formatCurrency($item['total_amount']) ?></td>
                        <td class="text-right text-green">N$<?= formatCurrency($item['paid_amount']) ?></td>
                        <td class="text-right text-red font-bold">N$<?= formatCurrency($item['outstanding']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="6">Total Outstanding</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_outstanding']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="7" class="no-data">No outstanding credit found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'tabs': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Tabs</div>
                <div class="value"><?= $reportData['summary']['total_tabs'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Open Tabs</div>
                <div class="value"><?= $reportData['summary']['open_tabs'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Closed Tabs</div>
                <div class="value"><?= $reportData['summary']['closed_tabs'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Balance</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_balance']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Tab #</th>
                    <th>Name</th>
                    <th>Customer</th>
                    <th>Opened</th>
                    <th class="text-right">Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['tabs'])): ?>
                    <?php foreach ($reportData['tabs'] as $tab): ?>
                    <tr>
                        <td>#<?= $tab['id'] ?></td>
                        <td><?= htmlspecialchars($tab['tab_name']) ?></td>
                        <td><?= htmlspecialchars($tab['creditor_name'] ?? '-') ?></td>
                        <td><?= date('M j, Y H:i', strtotime($tab['opened_at'])) ?></td>
                        <td class="text-right font-bold">N$<?= formatCurrency($tab['current_balance']) ?></td>
                        <td>
                            <span class="badge <?= $tab['status'] === 'open' ? 'badge-warning' : 'badge-success' ?>">
                                <?= ucfirst($tab['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No tabs found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'expenses': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Transactions</div>
                <div class="value"><?= $reportData['summary']['total_transactions'] ?></div>
            </div>
            <div class="summary-card negative">
                <div class="label">Total Expenses</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Description</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['expenses'])): ?>
                    <?php foreach ($reportData['expenses'] as $expense): ?>
                    <tr>
                        <td><?= date('M j, Y H:i', strtotime($expense['created_at'])) ?></td>
                        <td><?= htmlspecialchars($expense['description'] ?? '-') ?></td>
                        <td class="text-right text-red font-bold">N$<?= formatCurrency($expense['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2">Total Expenses</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="3" class="no-data">No expenses found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'current_stock': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Items</div>
                <div class="value"><?= $reportData['summary']['total_items'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Quantity</div>
                <div class="value"><?= $reportData['summary']['total_quantity'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Stock Value (Retail)</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_value']) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Stock Value (Cost)</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_cost']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Barcode</th>
                    <th class="text-right">Stock</th>
                    <th class="text-right">Restock Level</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['products'])): ?>
                    <?php foreach ($reportData['products'] as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['name']) ?></td>
                        <td><?= htmlspecialchars($product['category'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($product['barcode'] ?? '-') ?></td>
                        <td class="text-right <?= ($product['quantity'] <= ($product['restock_level'] ?? 0) && ($product['restock_level'] ?? 0) > 0) ? 'text-red' : '' ?>">
                            <?= $product['quantity'] ?? 0 ?>
                        </td>
                        <td class="text-right"><?= $product['restock_level'] ?? 0 ?></td>
                        <td class="text-right">N$<?= formatCurrency($product['price']) ?></td>
                        <td class="text-right font-bold">N$<?= formatCurrency($product['quantity'] * $product['price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3">Total</td>
                        <td class="text-right"><?= $reportData['summary']['total_quantity'] ?></td>
                        <td></td>
                        <td></td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_value']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="7" class="no-data">No products found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'stock_movement': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Movements</div>
                <div class="value"><?= $reportData['summary']['total_movements'] ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Stock In</div>
                <div class="value">+<?= $reportData['summary']['total_in'] ?></div>
            </div>
            <div class="summary-card negative">
                <div class="label">Stock Out</div>
                <div class="value">-<?= $reportData['summary']['total_out'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Net Change</div>
                <div class="value"><?= $reportData['summary']['net_change'] >= 0 ? '+' : '' ?><?= $reportData['summary']['net_change'] ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Product</th>
                    <th>Action</th>
                    <th class="text-right">Change</th>
                    <th class="text-right">Old Qty</th>
                    <th class="text-right">New Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['movements'])): ?>
                    <?php foreach ($reportData['movements'] as $movement): ?>
                    <tr>
                        <td><?= date('M j, Y H:i', strtotime($movement['changed_at'])) ?></td>
                        <td><?= htmlspecialchars($movement['product_name']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($movement['action'])) ?></td>
                        <td class="text-right <?= $movement['quantity_change'] >= 0 ? 'text-green' : 'text-red' ?>">
                            <?= $movement['quantity_change'] >= 0 ? '+' : '' ?><?= $movement['quantity_change'] ?>
                        </td>
                        <td class="text-right"><?= $movement['old_quantity'] ?></td>
                        <td class="text-right"><?= $movement['new_quantity'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No stock movements found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'low_stock': ?>
        <div class="summary-cards">
            <div class="summary-card negative">
                <div class="label">Low Stock Items</div>
                <div class="value"><?= $reportData['summary']['total_low_stock'] ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th class="text-right">Current Stock</th>
                    <th class="text-right">Restock Level</th>
                    <th class="text-right">Shortage</th>
                    <th class="text-right">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['products'])): ?>
                    <?php foreach ($reportData['products'] as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['name']) ?></td>
                        <td><?= htmlspecialchars($product['category'] ?? '-') ?></td>
                        <td class="text-right text-red"><?= $product['quantity'] ?></td>
                        <td class="text-right"><?= $product['restock_level'] ?></td>
                        <td class="text-right text-red font-bold"><?= $product['shortage'] ?></td>
                        <td class="text-right">N$<?= formatCurrency($product['price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No low stock items found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'stock_variance': ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th class="text-right">Opening</th>
                    <th class="text-right">Received</th>
                    <th class="text-right">Sold</th>
                    <th class="text-right">Damaged</th>
                    <th class="text-right">Closing</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['variance'])): ?>
                    <?php foreach ($reportData['variance'] as $item): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($item['date'])) ?></td>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="text-right"><?= $item['opening_quantity'] ?></td>
                        <td class="text-right text-green">+<?= $item['received_quantity'] ?></td>
                        <td class="text-right text-red">-<?= $item['sold_quantity'] ?></td>
                        <td class="text-right text-red">-<?= $item['damaged_quantity'] ?></td>
                        <td class="text-right font-bold"><?= $item['closing_quantity'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="no-data">No stock variance data found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'refunds': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Refunds</div>
                <div class="value"><?= $reportData['summary']['total_refunds'] ?></div>
            </div>
            <div class="summary-card negative">
                <div class="label">Total Amount</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Refund #</th>
                    <th>Order #</th>
                    <th>Date/Time</th>
                    <th>Cashier</th>
                    <th>Reason</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['refunds'])): ?>
                    <?php foreach ($reportData['refunds'] as $refund): ?>
                    <tr>
                        <td>#<?= $refund['id'] ?></td>
                        <td>#<?= $refund['order_id'] ?></td>
                        <td><?= date('M j, Y H:i', strtotime($refund['created_at'])) ?></td>
                        <td><?= htmlspecialchars($refund['cashier_name'] ?? 'Unknown') ?></td>
                        <td><?= htmlspecialchars($refund['reason'] ?? '-') ?></td>
                        <td class="text-right text-red font-bold">N$<?= formatCurrency($refund['total_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">Total</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No refunds found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'voids': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Voids</div>
                <div class="value"><?= $reportData['summary']['total_voids'] ?></div>
            </div>
            <div class="summary-card negative">
                <div class="label">Total Amount</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Void #</th>
                    <th>Order #</th>
                    <th>Date/Time</th>
                    <th>Payment Method</th>
                    <th>Items</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['voids'])): ?>
                    <?php foreach ($reportData['voids'] as $void): ?>
                    <tr>
                        <td>#<?= $void['id'] ?></td>
                        <td>#<?= $void['order_id'] ?? '-' ?></td>
                        <td><?= date('M j, Y H:i', strtotime($void['voided_at'])) ?></td>
                        <td><?= htmlspecialchars($void['payment_method'] ?? '-') ?></td>
                        <td class="items-list"><?= htmlspecialchars(substr($void['items'] ?? '-', 0, 50)) ?>...</td>
                        <td class="text-right text-red font-bold">N$<?= formatCurrency($void['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">Total</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_amount']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No voids found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'cashier_sales': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Staff</div>
                <div class="value"><?= $reportData['summary']['total_cashiers'] ?></div>
            </div>
            <div class="summary-card positive">
                <div class="label">Total Sales</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['total_sales']) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Cashier</th>
                    <th>Role</th>
                    <th class="text-right">Orders</th>
                    <th class="text-right">Order Sales</th>
                    <th class="text-right">Credit Sales</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['cashiers'])): ?>
                    <?php foreach ($reportData['cashiers'] as $cashier): ?>
                    <?php if ($cashier['grand_total'] > 0): ?>
                    <tr>
                        <td><?= htmlspecialchars($cashier['cashier_name']) ?></td>
                        <td><?= ucfirst($cashier['role']) ?></td>
                        <td class="text-right"><?= $cashier['order_count'] ?></td>
                        <td class="text-right">N$<?= formatCurrency($cashier['total_sales']) ?></td>
                        <td class="text-right">N$<?= formatCurrency($cashier['credit_total']) ?></td>
                        <td class="text-right font-bold">N$<?= formatCurrency($cashier['grand_total']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">Total</td>
                        <td class="text-right">N$<?= formatCurrency($reportData['summary']['total_sales']) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" class="no-data">No cashier sales found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'shift': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Logins</div>
                <div class="value"><?= $reportData['summary']['total_logins'] ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Logouts</div>
                <div class="value"><?= $reportData['summary']['total_logouts'] ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['shifts'])): ?>
                    <?php foreach ($reportData['shifts'] as $shift): ?>
                    <tr>
                        <td><?= date('M j, Y H:i', strtotime($shift['action_time'])) ?></td>
                        <td><?= htmlspecialchars($shift['username'] ?? 'Unknown') ?></td>
                        <td>
                            <span class="badge <?= $shift['action_type'] === 'login' ? 'badge-success' : 'badge-warning' ?>">
                                <?= ucfirst($shift['action_type']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="no-data">No shift data found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; case 'profit_loss': ?>
        <h3 class="section-title">Revenue</h3>
        <table>
            <tbody>
                <tr>
                    <td>Cash & Card Order Sales</td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['order_revenue']) ?></td>
                </tr>
                <tr>
                    <td>Credit Sales</td>
                    <td class="text-right">N$<?= formatCurrency($reportData['summary']['credit_revenue']) ?></td>
                </tr>
                <tr class="total-row">
                    <td><strong>Total Revenue</strong></td>
                    <td class="text-right text-green"><strong>N$<?= formatCurrency($reportData['summary']['total_revenue']) ?></strong></td>
                </tr>
            </tbody>
        </table>
        
        <h3 class="section-title">Cost of Goods Sold</h3>
        <table>
            <tbody>
                <tr>
                    <td>Cost of Products Sold</td>
                    <td class="text-right text-red">N$<?= formatCurrency($reportData['summary']['cost_of_goods_sold']) ?></td>
                </tr>
            </tbody>
        </table>
        
        <h3 class="section-title">Gross Profit</h3>
        <table>
            <tbody>
                <tr class="total-row">
                    <td><strong>Gross Profit</strong></td>
                    <td class="text-right <?= $reportData['summary']['gross_profit'] >= 0 ? 'text-green' : 'text-red' ?>">
                        <strong>N$<?= formatCurrency($reportData['summary']['gross_profit']) ?></strong>
                    </td>
                </tr>
                <tr>
                    <td>Gross Margin</td>
                    <td class="text-right"><?= number_format($reportData['summary']['gross_margin'], 1) ?>%</td>
                </tr>
            </tbody>
        </table>
        
        <h3 class="section-title">Expenses</h3>
        <table>
            <tbody>
                <tr>
                    <td>Operating Expenses</td>
                    <td class="text-right text-red">N$<?= formatCurrency($reportData['summary']['expenses']) ?></td>
                </tr>
                <tr>
                    <td>Refunds</td>
                    <td class="text-right text-red">N$<?= formatCurrency($reportData['summary']['refunds']) ?></td>
                </tr>
            </tbody>
        </table>
        
        <h3 class="section-title">Net Profit</h3>
        <div class="summary-cards">
            <div class="summary-card <?= $reportData['summary']['net_profit'] >= 0 ? 'positive' : 'negative' ?>">
                <div class="label">Net Profit</div>
                <div class="value">N$<?= formatCurrency($reportData['summary']['net_profit']) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Net Margin</div>
                <div class="value"><?= number_format($reportData['summary']['net_margin'], 1) ?>%</div>
            </div>
        </div>
        
    <?php break; case 'audit_log': ?>
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Entries</div>
                <div class="value"><?= $reportData['summary']['total_entries'] ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reportData['logs'])): ?>
                    <?php foreach ($reportData['logs'] as $log): ?>
                    <tr>
                        <td><?= date('M j, Y H:i:s', strtotime($log['action_time'])) ?></td>
                        <td><?= htmlspecialchars($log['username'] ?? 'Unknown') ?></td>
                        <td>
                            <span class="badge <?= $log['action_type'] === 'login' ? 'badge-success' : 'badge-info' ?>">
                                <?= ucfirst($log['action_type']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="no-data">No audit log entries found for this period</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php break; default: ?>
        <div class="no-data">Unknown report type: <?= htmlspecialchars($reportType) ?></div>
    <?php endswitch; ?>
    
</body>
</html>
