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

// Optimized database connections with connection pooling
class DatabaseManager {
    private static $connections = [];
    
    public static function getConnection($dbPath) {
        if (!isset(self::$connections[$dbPath])) {
            try {
                $pdo = new PDO("sqlite:$dbPath");
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);
                self::$connections[$dbPath] = $pdo;
            } catch (PDOException $e) {
                error_log("Database connection failed for $dbPath: " . $e->getMessage());
                throw $e;
            }
        }
        return self::$connections[$dbPath];
    }
}

// Get database connections
try {
    $db = DatabaseManager::getConnection('../pos.db');
    $activationDb = DatabaseManager::getConnection('../active.db');
    $infoDb = DatabaseManager::getConnection('../info.db');
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Check activation status
$activationStatus = $activationDb->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Get business closing time from business_info with caching
$businessInfo = [];
$closingTime = '00:00';
try {
    $businessInfo = $infoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00';
} catch (PDOException $e) {
    $closingTime = '00:00';
}

// Calculate business day boundaries (cached calculation)
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Optimized business day WHERE clause with caching
class BusinessDayCache {
    private static $cache = [];
    
    public static function getWhereClause($dateField, $startDate, $endDate, $closingTime, $isAfterMidnight) {
        $cacheKey = "$dateField-$startDate-$endDate-$closingTime-$isAfterMidnight";
        
        if (!isset(self::$cache[$cacheKey])) {
    if ($startDate === $endDate) {
        // Single day - use business day logic
        $nextDay = date('Y-m-d', strtotime($startDate . ' +1 day'));
                self::$cache[$cacheKey] = "
            (DATE($dateField) = '$startDate' AND strftime('%H:%M', $dateField) >= '$closingTime') OR
            (DATE($dateField) = '$nextDay' AND strftime('%H:%M', $dateField) < '$closingTime' AND " . ($isAfterMidnight ? "1" : "0") . " = 1)
        ";
    } else {
        // Multiple days - need to handle each day's business hours
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
        
                self::$cache[$cacheKey] = "(" . implode(") OR (", $whereClauses) . ")";
            }
        }
        
        return self::$cache[$cacheKey];
    }
    
    public static function clearCache() {
        self::$cache = [];
    }
}

// Optimized function to get total cash in for date range (using business days)
function getTotalCashIn($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type = 'cash-in' AND ($whereClause)
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashIn: " . $e->getMessage());
        return 0;
    }
}

// Optimized function to get total cash out for date range (using business days)
function getTotalCashOut($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM cash_transactions 
            WHERE type = 'cash-out' AND ($whereClause)
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashOut: " . $e->getMessage());
        return 0;
    }
}
?>       
            <?php
// Handle date selection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['selected_date'])) {
    $selectedDate = $_POST['selected_date'];
    $startDate = $selectedDate;
    $endDate = $selectedDate;
} else {
    // Default to current year if no date selected
    $selectedDate = date('Y-m-d');
    $startDate = date('Y-01-01');
    $endDate = date('Y-12-31');
}

// Optimized function to get total sales (using business days with caching)
function getCashSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        // Check if eft_payments table exists (cached check)
        static $eftTableExists = null;
        if ($eftTableExists === null) {
        try {
            $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
            }
        }
        
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $paymentWhereClause = BusinessDayCache::getWhereClause('p.payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        if ($eftTableExists) {
            $stmt = $db->prepare("
                SELECT 
                    (SELECT COALESCE(SUM(o.total), 0) 
                     FROM orders o
                     LEFT JOIN eft_payments e ON o.id = e.order_id
                     WHERE e.order_id IS NULL AND ($orderWhereClause))
                    +
                    (SELECT COALESCE(SUM(p.amount), 0) 
                     FROM payments p 
                     WHERE ($paymentWhereClause))
            ");
        } else {
            $stmt = $db->prepare("
                SELECT 
                    (SELECT COALESCE(SUM(total), 0) 
                     FROM orders o
                     WHERE ($orderWhereClause))
                    +
                    (SELECT COALESCE(SUM(amount), 0) 
                     FROM payments p
                     WHERE ($paymentWhereClause))
            ");
        }
        
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales (using business days)
function getCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0)
            FROM credit_sales 
            WHERE ($whereClause)
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCreditSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cost of goods sold (using business days)
function getCostOfGoodsSold($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(oi.quantity * COALESCE(oi.buying_price, 0)), 0)
                 FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 WHERE ($orderWhereClause))
                +
                (SELECT COALESCE(SUM(csi.quantity * COALESCE(csi.buying_price, 0)), 0)
                 FROM credit_sale_items csi
                 JOIN credit_sales cs ON csi.sale_id = cs.id
                 WHERE ($creditWhereClause))
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCostOfGoodsSold: " . $e->getMessage());
        return 0;
    }
}

// Function to calculate gross profit
function calculateGrossProfit($totalSales, $costOfGoodsSold) {
    return floatval($totalSales) - floatval($costOfGoodsSold);
}

// Function to get top-selling products (using business days)
function getTopSellingProducts($db, $startDate, $endDate, $closingTime, $isAfterMidnight, $limit = 10) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT product_name, SUM(quantity) as total_quantity
            FROM (
                SELECT oi.product_name, oi.quantity
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE ($orderWhereClause)
                UNION ALL
                SELECT csi.product_name, csi.quantity 
                FROM credit_sale_items csi
                JOIN credit_sales cs ON csi.sale_id = cs.id
                WHERE ($creditWhereClause)
            ) combined
            GROUP BY product_name
            ORDER BY total_quantity DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getTopSellingProducts: " . $e->getMessage());
        return [];
    }
}

// Function to get unpaid credit sales (using business days)
function getUnpaidCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0) as unpaid_amount
            FROM credit_sales
            WHERE ($whereClause) AND payment_status != 'paid'
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getUnpaidCreditSales: " . $e->getMessage());
        return 0;
    }
}

// Optimized function to get total EFT payments (using business days with caching)
function getTotalEftPayments($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        // Check if eft_payments table exists (cached check)
        static $eftTableExists = null;
        if ($eftTableExists === null) {
        try {
            $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
            }
        }
        
        if (!$eftTableExists) {
            return 0;
        }
        
        $whereClause = BusinessDayCache::getWhereClause('payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as eft_total
            FROM eft_payments
            WHERE ($whereClause) AND status = 'completed'
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalEftPayments: " . $e->getMessage());
        return 0;
    }
}

// Function to calculate net profit
function calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut) {
    return $grossProfit ;
}

// Initialize with default values to avoid slow initial load
$cashSales = 0;
$creditSales = 0;
$costOfGoodsSold = 0;
$totalRevenue = 0;
$grossProfit = 0;
$topSellingProducts = [];
$totalCashIn = 0;
$totalCashOut = 0;
$netProfit = 0;
$unpaidCreditSales = 0;
$totalEftPayments = 0;
$netRevenue = 0;
$totalCreditPayments = 0;

// Determine date display format
$dateDisplay = ($startDate === $endDate) ? $startDate : "$startDate to $endDate";

// Get sales data based on selected view (using business days)
function getSalesData($db, $view = 'daily', $closingTime = '00:00', $isAfterMidnight = false) {
    $query = "";
    switch($view) {
        case 'monthly':
            // For monthly view, we'll use business day logic for each month
            $query = "
                WITH RECURSIVE months(month) AS (
                    SELECT date('now', 'start of year')
                    UNION ALL
                    SELECT date(month, '+1 month')
                    FROM months
                    WHERE month < date('now', 'start of year', '+11 months')
                ),
                order_totals AS (
                    SELECT 
                        strftime('%Y-%m', created_at) as period,
                        COALESCE(SUM(total), 0) as order_total
                    FROM orders
                    GROUP BY strftime('%Y-%m', created_at)
                ),
                credit_totals AS (
                    SELECT 
                        strftime('%Y-%m', created_at) as period,
                        COALESCE(SUM(total_amount), 0) as credit_total
                    FROM credit_sales
                    GROUP BY strftime('%Y-%m', created_at)
                )
                SELECT 
                    strftime('%Y-%m', months.month) as period,
                    (COALESCE(order_totals.order_total, 0) + COALESCE(credit_totals.credit_total, 0)) as total_sales
                FROM months
                LEFT JOIN order_totals ON strftime('%Y-%m', months.month) = order_totals.period
                LEFT JOIN credit_totals ON strftime('%Y-%m', months.month) = credit_totals.period
                ORDER BY months.month ASC";
            break;
            
        case 'weekly':
            $query = "
                WITH RECURSIVE weeks(week_num) AS (
                    SELECT 1
                    UNION ALL
                    SELECT week_num + 1
                    FROM weeks
                    WHERE week_num < 4
                ),
                order_totals AS (
                    SELECT 
                        CASE 
                            WHEN date(created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+21 days') AND date('now', 'start of month', '+1 month', '-1 day') THEN 4
                        END as week_num,
                        COALESCE(SUM(total), 0) as order_total
                    FROM orders
                    WHERE date(created_at) >= date('now', 'start of month')
                    AND date(created_at) <= date('now', 'start of month', '+1 month', '-1 day')
                    GROUP BY week_num
                ),
                credit_totals AS (
                    SELECT 
                        CASE 
                            WHEN date(created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+21 days') AND date('now', 'start of month', '+1 month', '-1 day') THEN 4
                        END as week_num,
                        COALESCE(SUM(total_amount), 0) as credit_total
                    FROM credit_sales
                    WHERE date(created_at) >= date('now', 'start of month')
                    AND date(created_at) <= date('now', 'start of month', '+1 month', '-1 day')
                    GROUP BY week_num
                )
                SELECT 
                    weeks.week_num as period,
                    (COALESCE(order_totals.order_total, 0) + COALESCE(credit_totals.credit_total, 0)) as total_sales
                FROM weeks
                LEFT JOIN order_totals ON weeks.week_num = order_totals.week_num
                LEFT JOIN credit_totals ON weeks.week_num = credit_totals.week_num
                ORDER BY weeks.week_num ASC";
            break;
            
        case 'daily':
        default:
            // For daily view, we'll use business day logic for the last 7 days
            $query = "
                WITH RECURSIVE dates(date) AS (
                    SELECT date('now', '-6 days')
                    UNION ALL
                    SELECT date(date, '+1 day')
                    FROM dates
                    WHERE date < date('now')
                ),
                order_totals AS (
                    SELECT 
                        date(created_at) as period,
                        COALESCE(SUM(total), 0) as order_total
                    FROM orders
                    WHERE date(created_at) >= date('now', '-6 days')
                    AND date(created_at) <= date('now')
                    GROUP BY date(created_at)
                ),
                credit_totals AS (
                    SELECT 
                        date(created_at) as period,
                        COALESCE(SUM(total_amount), 0) as credit_total
                    FROM credit_sales
                    WHERE date(created_at) >= date('now', '-6 days')
                    AND date(created_at) <= date('now')
                    GROUP BY date(created_at)
                )
                SELECT 
                    dates.date as period,
                    (COALESCE(order_totals.order_total, 0) + COALESCE(credit_totals.credit_total, 0)) as total_sales
                FROM dates
                LEFT JOIN order_totals ON dates.date = order_totals.period
                LEFT JOIN credit_totals ON dates.date = credit_totals.period
                ORDER BY dates.date ASC";
            break;
    }
    
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Initialize chart data with empty arrays for fast loading
$view = 'daily';
$labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$salesData = [0, 0, 0, 0, 0, 0, 0];
?> 

<?php
// Initialize with empty arrays for fast loading - data will be loaded via AJAX
$products = [];
$lowStock = [];
$outOfStock = [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/chart.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        /* Ensure no horizontal overflow */
        * {
            box-sizing: border-box;
        }
        
        /* Custom scrollbar for notifications */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Ensure chart container doesn't overflow */
        canvas {
            max-width: 100% !important;
            /* Remove height: auto to maintain aspect ratio */
        }
        
        /* Chart container improvements */
        .chart-container {
            position: relative;
            width: 100%;
            height: 500px;
        }
        
        /* Ensure chart canvases maintain their dimensions */
        canvas[id*="Chart"] {
            display: block !important;
            box-sizing: border-box !important;
        }
        
        /* Mobile hamburger menu styles - matches credit-tabs.php */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000; /* Highest - always accessible, matches credit-tabs.php */
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
        
        /* Mobile sidebar overlay - matches credit-tabs.php */
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80; /* Below sidebar (10000) and hamburger (10000) - matches credit-tabs.php */
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
            /* Hide sidebar on mobile by default - sidebar.php handles its own styling */
            /* Remove left margin on mobile */
            .ml-64 {
                margin-left: 0 !important;
            }
            
            /* Ensure content takes full width on mobile */
            .flex-1 {
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden;
            }
        }
        
        /* Responsive adjustments for very small screens */
        @media (max-width: 640px) {
            /* Ensure charts don't get too small on mobile */
            .chart-container {
                min-height: 400px !important;
                height: 400px !important;
            }
            
            canvas[id*="Chart"] {
                min-height: 400px !important;
            }
        }
        
        /* Card hover effects */
        [data-card] {
            transition: all 0.3s ease;
        }
        
        [data-card]:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }
        
        [data-card]:active {
            transform: scale(0.98);
        }
        
        /* Click indicator animation */
        [data-card] .fa-arrow-right {
            transition: transform 0.2s ease;
        }
        
        [data-card]:hover .fa-arrow-right {
            transform: translateX(3px);
        }
        
        /* Stock report button animation */
        .stock-report-btn {
            transition: all 0.3s ease;
        }
        
        .stock-report-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }
        
        .stock-report-btn:active {
            transform: scale(0.95);
        }
    </style>
<script src="3.4.16"></script>
</head>

<body class="bg-gray-100 overflow-x-hidden">
    <div class="flex min-h-screen">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="p-4 lg:p-8 w-full min-w-0">
                <!-- Fixed Header Row -->
                <div class="fixed top-0 left-0 lg:left-64 right-0 z-50 bg-gray-50 py-4 flex items-center gap-4 px-4 lg:px-8 shadow-sm">
                    <div class="w-full max-w-full mx-auto flex items-center gap-4 px-4 lg:px-8">
                        <!-- Mobile Controls Row -->
                        <div class="flex items-center gap-3">
                            <!-- Mobile Hamburger Menu Button -->
                            <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold">Admin Overview</h1>
                            
                            <!-- Action Buttons next to title -->
                            <div class="flex items-center gap-3 ml-2">
                                <button type="button" onclick="window.location.href='chat'" class="p-2 bg-gradient-to-br from-teal-200 to-teal-50 rounded-full hover:shadow-md transition-all duration-200">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                </button>
                                <div class="relative cursor-pointer">
                                    <svg onclick="toggleNotifications()" class="h-8 w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                    <span id="notificationCount" class="absolute top-1 right-0 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center pointer-events-none transform -translate-y-1/4 translate-x-1/4 hidden">
                                        0
                                    </span>
                                    <!-- Notifications Dropdown -->
                                    <div id="notificationsDropdown" class="hidden absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar max-w-[90vw]">
                                        <div id="notificationsContent">
                                            <div class="p-6 text-center">
                                                <div class="mx-auto w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                                    </svg>
                                                </div>
                                                <p class="text-gray-500 font-medium">Loading notifications...</p>
                                                <p class="text-gray-400 text-sm mt-1">Please wait</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Stock Report Download Button -->
                                <div class="relative">
                                    <button onclick="downloadStockReport()" class="p-2 bg-gradient-to-br from-orange-200 to-orange-50 rounded-full hover:shadow-md transition-all duration-200 group stock-report-btn" title="Download Stock Alert Report">
                                        <svg class="w-6 h-6 text-orange-600 group-hover:text-orange-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </button>
                                    <span id="stockReportCount" class="absolute top-1 right-0 bg-orange-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center pointer-events-none transform -translate-y-1/4 translate-x-1/4 <?php echo (count($lowStock) + count($outOfStock)) > 0 ? '' : 'hidden'; ?>">
                                        <?php echo count($lowStock) + count($outOfStock); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Spacer for fixed header -->
                <div class="h-20 mb-4"></div>
                <p class="text-sm text-gray-500 period-indicator mb-4">Today</p>


            <!-- Cash Transaction Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6 mb-8">
                <!-- Cash In Till Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="cashInTill" onclick="navigateToCard('cashInTill')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Cash In Till</p>
                            <h3 class="text-2xl font-bold <?php 
                                // Get today's date
                                $today = date('Y-m-d');
                                $nextBusinessDay = date('Y-m-d', strtotime($today . ' +1 day'));
                                
                                // Use the actual business closing time from database
                                $closingTime = $closingTime; // This is already set from business_info
                                $isAfterMidnight = $isAfterMidnight; // This is already calculated from business_info
                                
                                // Check if current time is after midnight but before closing time
                                $currentTime = date('H:i');
                                $currentIsAfterMidnight = 0;
                                if ($isAfterMidnight && $currentTime < $closingTime) {
                                    $currentIsAfterMidnight = 1;
                                }
                                
                                // 1. Today's cash in (deposits)
                                $cashInQuery = $db->prepare("
                                    SELECT COALESCE(SUM(amount), 0) 
                                    FROM cash_transactions 
                                    WHERE type='cash-in' AND (
                                        (DATE(created_at) = :today AND strftime('%H:%M', created_at) >= :closingTime) OR
                                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                                    )
                                ");
                                $cashInQuery->bindParam(':today', $today);
                                $cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                $cashInQuery->bindParam(':closingTime', $closingTime);
                                $cashInQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                $cashInQuery->execute();
                                $totalCashIn = $cashInQuery->fetchColumn();
                                
                                // 2. Today's cash sales (from orders)
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
                                            (DATE(o.created_at) = :today AND strftime('%H:%M', o.created_at) >= :closingTime) OR
                                            (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
                                        )
                                    ");
                                    $cashSalesQuery->bindParam(':today', $today);
                                    $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                    $cashSalesQuery->bindParam(':closingTime', $closingTime);
                                    $cashSalesQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                    $cashSalesQuery->execute();
                                } else {
                                    $cashSalesQuery = $db->prepare("
                                        SELECT COALESCE(SUM(total), 0) 
                                        FROM orders 
                                        WHERE (
                                            (DATE(created_at) = :today AND strftime('%H:%M', created_at) >= :closingTime) OR
                                            (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                                        )
                                    ");
                                    $cashSalesQuery->bindParam(':today', $today);
                                    $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                    $cashSalesQuery->bindParam(':closingTime', $closingTime);
                                    $cashSalesQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                    $cashSalesQuery->execute();
                                }
                                $totalCashSales = $cashSalesQuery->fetchColumn();
                                
                                // 3. Today's cash received from credit sales payments
                                $creditPaymentsQuery = $db->prepare("
                                    SELECT COALESCE(SUM(p.amount), 0) 
                                    FROM payments p
                                    JOIN credit_sales cs ON p.sale_id = cs.id
                                    WHERE cs.payment_status = 'paid' AND (
                                        (DATE(p.payment_date) = :today AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
                                        (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
                                    )
                                ");
                                $creditPaymentsQuery->bindParam(':today', $today);
                                $creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
                                $creditPaymentsQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                $creditPaymentsQuery->execute();
                                $totalCreditPayments = $creditPaymentsQuery->fetchColumn();
                                
                                // 3b. Today's EFT direct sales from orders (EFT portion of mixed + full EFT)
                                $eftDirectQuery = $db->prepare("
                                    SELECT COALESCE(SUM(e.amount), 0)
                                    FROM eft_payments e
                                    JOIN orders o ON e.order_id = o.id
                                    WHERE (
                                        (DATE(o.created_at) = :today AND strftime('%H:%M', o.created_at) >= :closingTime) OR
                                        (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
                                    )
                                ");
                                $eftDirectQuery->bindParam(':today', $today);
                                $eftDirectQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                $eftDirectQuery->bindParam(':closingTime', $closingTime);
                                $eftDirectQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                $eftDirectQuery->execute();
                                $eftDirectTotal = $eftDirectQuery->fetchColumn();

                                // 3c. Today's EFT credit payments (payments table where status is EFT)
                                $eftCreditQuery = $db->prepare("
                                    SELECT COALESCE(SUM(p.amount), 0)
                                    FROM payments p
                                    JOIN credit_sales cs ON p.sale_id = cs.id
                                    WHERE cs.payment_status = 'eft' AND (
                                        (DATE(p.payment_date) = :today AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
                                        (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
                                    )
                                ");
                                $eftCreditQuery->bindParam(':today', $today);
                                $eftCreditQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                $eftCreditQuery->bindParam(':closingTime', $closingTime);
                                $eftCreditQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                $eftCreditQuery->execute();
                                $eftCreditTotal = $eftCreditQuery->fetchColumn();

                                // 4. Today's cash out (withdrawals)
                                $cashOutQuery = $db->prepare("
                                    SELECT COALESCE(SUM(amount), 0) 
                                    FROM cash_transactions 
                                    WHERE type='cash-out' AND (
                                        (DATE(created_at) = :today AND strftime('%H:%M', created_at) >= :closingTime) OR
                                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                                    )
                                ");
                                $cashOutQuery->bindParam(':today', $today);
                                $cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                                $cashOutQuery->bindParam(':closingTime', $closingTime);
                                $cashOutQuery->bindParam(':isAfterMidnight', $currentIsAfterMidnight, PDO::PARAM_INT);
                                $cashOutQuery->execute();
                                $totalCashOut = $cashOutQuery->fetchColumn();
                                
                                // Total EFT payments (direct + credit EFT)
                                $totalEftPayments = ($eftDirectTotal ?: 0) + ($eftCreditTotal ?: 0);

                                // Calculate cash in till for today's business day
                                $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
                                echo $cashInTill >= 0 ? 'text-blue-600' : 'text-red-600'; 
                            ?>">
                                N$<?php
                                echo number_format($cashInTill, 2);
                                ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-blue-100 rounded-full">
                            <i class="fas fa-wallet text-blue-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Available cash balance</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- Cash In Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="totalDeposits" onclick="navigateToCard('totalDeposits')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Cash Received</p>
                            <h3 class="text-2xl font-bold text-teal-600">
                                N$<?php 
                                // Calculate total receipts including cash and EFT portions
                                $totalDeposits = $totalCashIn + $totalCashSales + $totalCreditPayments ;
                                echo number_format($totalDeposits, 2); 
                                ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-teal-100 rounded-full">
                            <i class="fas fa-arrow-circle-down text-teal-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Total cash deposits + sales + payments</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- Net Revenue Card (Moved here) -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="netRevenue" onclick="navigateToCard('netRevenue')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Net Revenue</p>
                            <h3 class="text-2xl font-bold <?php echo $netRevenue >= 0 ? 'text-teal-600' : 'text-red-600'; ?>">
                                N$<?php echo number_format($netRevenue, 2); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-teal-100 rounded-full">
                            <i class="fas fa-chart-line text-teal-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Total profit after expenses</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
            </div>

            <!-- Additional Financial Cards - Initially Hidden -->
            <div id="additionalCards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6 mb-8 hidden opacity-0 transition-all duration-500 transform -translate-y-4">
                <!-- Unpaid Credit Sales Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="unpaidCredit" onclick="navigateToCard('unpaidCredit')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Unpaid Credit Sales</p>
                            <h3 class="text-2xl font-bold text-purple-600">
                                N$<?php echo number_format($unpaidCreditSales, 2); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-purple-100 rounded-full">
                            <i class="fas fa-credit-card text-purple-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Outstanding credit to be collected</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- EFT Payments Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="eftPayments" onclick="navigateToCard('eftPayments')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">EFT Payments</p>
                            <h3 class="text-2xl font-bold text-teal-600">
                                N$<?php echo number_format($totalEftPayments, 2); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-teal-100 rounded-full">
                            <i class="fas fa-mobile-alt text-teal-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Total electronic payments</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- Cash Out Card (Moved here) -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="cashOut" onclick="navigateToCard('cashOut')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Cash Withdrawals</p>
                            <h3 class="text-2xl font-bold text-red-600">
                                N$<?php echo number_format($totalCashOut, 2); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-red-100 rounded-full">
                            <i class="fas fa-arrow-circle-up text-red-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Total cash withdrawals</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- Total Inventory Value Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="totalInventoryValue" onclick="navigateToCard('totalInventoryValue')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Inventory Value</p>
                            <h3 class="text-2xl font-bold text-teal-600">
                                N$<?php echo number_format(0, 2); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-teal-100 rounded-full">
                            <i class="fas fa-boxes text-teal-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Current stock value at cost</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- Total Products Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="totalProducts" onclick="navigateToCard('totalProducts')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Products</p>
                            <h3 class="text-2xl font-bold text-cyan-600">
                                <?php echo number_format(0); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-cyan-100 rounded-full">
                            <i class="fas fa-tags text-cyan-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Total products in system</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
                
                <!-- Products In Stock Card -->
                <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer" data-card="productsInStock" onclick="navigateToCard('productsInStock')">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Products In Stock</p>
                            <h3 class="text-2xl font-bold text-teal-600">
                                <?php echo number_format(0); ?>
                            </h3>
                        </div>
                        <div class="p-2 bg-teal-100 rounded-full">
                            <i class="fas fa-check-circle text-teal-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Products with stock available</p>
                    <div class="mt-2 flex items-center text-xs text-gray-400">
                        <i class="fas fa-arrow-right mr-1"></i>
                        <span>Click for details</span>
                    </div>
                </div>
            </div>

            <!-- Expand/Collapse Button -->
            <div class="flex justify-center my-4">
                <button type="button" id="toggleCardsBtn" class="text-teal-600 hover:text-purple-600 transition-all duration-300">
                    <i id="toggleIcon" class="fas fa-chevron-down text-2xl text-gray-500 transition-transform duration-300"></i>
                </button>
            </div>

            <!-- Graphs Section -->
            <div class="bg-white rounded-lg shadow-lg p-4 lg:p-6 w-full mb-8">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-4 gap-4">
                    <h2 class="text-xl lg:text-2xl font-bold text-gray-800">Revenue Overview (Sales + Cash-ins)</h2>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="updateChart('daily', event); return false;" class="px-3 py-2 text-sm font-medium rounded-md bg-teal-100 text-teal-700 hover:bg-teal-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500" id="dailyBtn">Daily</button>
                        <button type="button" onclick="updateChart('weekly', event); return false;" class="px-3 py-2 text-sm font-medium rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" id="weeklyBtn">Weekly</button>
                        <button type="button" onclick="updateChart('monthly', event); return false;" class="px-3 py-2 text-sm font-medium rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" id="monthlyBtn">Monthly</button>
                    </div>
                </div>
                <div class="w-full overflow-hidden chart-container" style="height: 500px;">
                    <canvas id="salesChart" class="w-full h-full max-w-full"></canvas>
                </div>
            </div>

            <!-- Employee Statistics and Creditor Analytics Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-8">
                <!-- Employee Statistics Section -->
                <div class="bg-white rounded-lg shadow-lg p-4 lg:p-6 w-full">
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-2">
                        <h2 class="text-lg lg:text-xl font-bold text-gray-800">Employee Statistics</h2>
                        <div class="flex flex-wrap gap-1">
                            <button type="button" onclick="updateEmployeePeriod('all', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-teal-100 text-teal-700 hover:bg-teal-200 focus:outline-none focus:ring-1 focus:ring-teal-500" id="employeeAllBtn">Total</button>
                            <button type="button" onclick="updateEmployeePeriod('today', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="employeeTodayBtn">Today</button>
                            <button type="button" onclick="updateEmployeePeriod('week', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="employeeWeekBtn">Week</button>
                            <button type="button" onclick="updateEmployeePeriod('month', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="employeeMonthBtn">Month</button>
                            <button type="button" onclick="updateEmployeePeriod('year', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="employeeYearBtn">Year</button>
                        </div>
                    </div>
                    
                    <!-- Employee Performance Chart -->
                    <div class="mb-6">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-4 gap-2">
                            <h3 class="text-base font-semibold text-gray-800">Employee Sales Performance</h3>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" onclick="updateEmployeeChartView('sales'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-teal-100 text-teal-700 hover:bg-teal-200 focus:outline-none focus:ring-1 focus:ring-teal-500" id="salesBtn">Sales</button>
                                <button type="button" onclick="updateEmployeeChartView('credit'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditBtn">Credit</button>
                                <button type="button" onclick="updateEmployeeChartView('eft'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="eftBtn">EFT</button>
                                <button type="button" onclick="updateEmployeeChartView('cash'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="cashBtn">Cash</button>
                                <button type="button" onclick="updateEmployeeChartView('orders'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="ordersBtn">Orders</button>
                                <button type="button" onclick="updateEmployeeChartView('avg'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="avgBtn">Avg</button>
                            </div>
                        </div>
                        <div class="w-full overflow-hidden chart-container" style="height: 400px;">
                            <canvas id="employeeChart" class="w-full h-full max-w-full"></canvas>
                        </div>
                    </div>

                    <!-- Employee Performance Table -->
                    <div>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sales</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Orders</th>
                                </tr>
                            </thead>
                            <tbody id="employeeTableBody" class="bg-white divide-y divide-gray-200">
                                <tr>
                                    <td colspan="3" class="px-4 py-4 text-center text-gray-500">
                                        <i class="fas fa-spinner fa-spin mr-2"></i>Loading employee data...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <!-- Pagination Controls -->
                        <div id="employeePagination" class="mt-4 flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6">
                            <div class="flex flex-1 justify-between sm:hidden">
                                <button id="employeePrevBtn" onclick="changeEmployeePage(-1)" class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">Previous</button>
                                <button id="employeeNextBtn" onclick="changeEmployeePage(1)" class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">Next</button>
                            </div>
                            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Showing <span id="employeePageInfo" class="font-medium">0-0</span> of <span id="employeeTotalItems" class="font-medium">0</span> results
                                    </p>
                                </div>
                                <div>
                                    <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                        <button id="employeePrevBtnDesktop" onclick="changeEmployeePage(-1)" class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </button>
                                        <button id="employeeNextBtnDesktop" onclick="changeEmployeePage(1)" class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span class="sr-only">Next</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Creditor Analytics Section -->
                <div class="bg-white rounded-lg shadow-lg p-4 lg:p-6 w-full">
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-2">
                        <h2 class="text-lg lg:text-xl font-bold text-gray-800">Creditor Analytics</h2>
                        <div class="flex flex-wrap gap-1">
                            <button type="button" onclick="updateCreditorPeriod('all', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-teal-100 text-teal-700 hover:bg-teal-200 focus:outline-none focus:ring-1 focus:ring-teal-500" id="creditorAllBtn">Total</button>
                            <button type="button" onclick="updateCreditorPeriod('today', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorTodayBtn">Today</button>
                            <button type="button" onclick="updateCreditorPeriod('week', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorWeekBtn">Week</button>
                            <button type="button" onclick="updateCreditorPeriod('month', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorMonthBtn">Month</button>
                            <button type="button" onclick="updateCreditorPeriod('year', event); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorYearBtn">Year</button>
                        </div>
                    </div>
                    
                    <!-- Creditor Analytics Chart -->
                    <div class="mb-6">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-4 gap-2">
                            <h3 class="text-base font-semibold text-gray-800">Creditor Performance</h3>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" onclick="updateCreditorChartView('sales'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-teal-100 text-teal-700 hover:bg-teal-200 focus:outline-none focus:ring-1 focus:ring-teal-500" id="creditorSalesBtn">Sales</button>
                                <button type="button" onclick="updateCreditorChartView('outstanding'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorOutstandingBtn">Outstanding</button>
                                <button type="button" onclick="updateCreditorChartView('paid'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorPaidBtn">Paid</button>
                                <button type="button" onclick="updateCreditorChartView('transactions'); return false;" class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-gray-500" id="creditorTransactionsBtn">Txns</button>
                            </div>
                        </div>
                        <div class="w-full overflow-hidden chart-container" style="height: 400px;">
                            <canvas id="creditorChart" class="w-full h-full max-w-full"></canvas>
                        </div>
                    </div>

                    <!-- Creditor Summary Table -->
                    <div>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Creditor</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paid</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody id="creditorTableBody" class="bg-white divide-y divide-gray-200">
                                <tr>
                                    <td colspan="3" class="px-4 py-4 text-center text-gray-500">
                                        <i class="fas fa-spinner fa-spin mr-2"></i>Loading creditor data...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <!-- Pagination Controls -->
                        <div id="creditorPagination" class="mt-4 flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6">
                            <div class="flex flex-1 justify-between sm:hidden">
                                <button id="creditorPrevBtn" onclick="changeCreditorPage(-1)" class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">Previous</button>
                                <button id="creditorNextBtn" onclick="changeCreditorPage(1)" class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">Next</button>
                            </div>
                            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Showing <span id="creditorPageInfo" class="font-medium">0-0</span> of <span id="creditorTotalItems" class="font-medium">0</span> results
                                    </p>
                                </div>
                                <div>
                                    <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                                        <button id="creditorPrevBtnDesktop" onclick="changeCreditorPage(-1)" class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span class="sr-only">Previous</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </button>
                                        <button id="creditorNextBtnDesktop" onclick="changeCreditorPage(1)" class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span class="sr-only">Next</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let salesChart;
                let employeeChart;
                let creditorChart;
                let requestCache = new Map();
                let debounceTimer;
                let creditorChartData = [];
                
                // Pagination state
                let employeePage = 1;
                let employeeData = [];
                let creditorPage = 1;
                let creditorData = [];
                const itemsPerPage = 4;
                
                // Optimized chart setup with loading state
                const ctx = document.getElementById('salesChart').getContext('2d');
                salesChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Loading...'],
                        datasets: [{
                            label: 'Total Revenue (Sales + Cash-ins) (N$)',
                            data: [0],
                            borderColor: 'rgba(75, 192, 192, 1)',
                            backgroundColor: 'rgba(75, 192, 192, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 800,
                            easing: 'easeOutQuart'
                        },
                        scales: {
                            x: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Day'
                                }
                            },
                            y: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Revenue Amount (N$)'
                                },
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        }
                    }
                });

                // Optimized initialization with debouncing
                document.addEventListener('DOMContentLoaded', function() {
                    // Load data asynchronously to avoid blocking page load
                    setTimeout(() => {
                        loadTodaysData();
                        loadNotifications();
                        loadInitialChartData();
                        initializeCreditorChart();
                        // Initialize creditor analytics with 'all' period (Total view - all creditors)
                        updateCreditorPeriod('all');
                    }, 100);
                });
                
                // Handle window resize to ensure charts maintain proper dimensions
                window.addEventListener('resize', debounce(function() {
                    if (salesChart) {
                        salesChart.resize();
                    }
                    if (employeeChart) {
                        employeeChart.resize();
                    }
                    if (creditorChart) {
                        creditorChart.resize();
                    }
                }, 250));

                // Optimized fetch function with caching
                async function cachedFetch(url, cacheTime = 30000) { // 30 second cache
                    const cacheKey = url;
                    const cached = requestCache.get(cacheKey);
                    
                    if (cached && (Date.now() - cached.timestamp) < cacheTime) {
                        return cached.data;
                    }
                    
                    const response = await fetch(url);
                    const data = await response.json();
                    
                    requestCache.set(cacheKey, {
                        data: data,
                        timestamp: Date.now()
                    });
                    
                    return data;
                }

                // Debounced function to prevent rapid API calls
                function debounce(func, wait) {
                    return function executedFunction(...args) {
                        const later = () => {
                            clearTimeout(debounceTimer);
                            func(...args);
                        };
                        clearTimeout(debounceTimer);
                        debounceTimer = setTimeout(later, wait);
                    };
                }

                async function loadTodaysData() {
                    // Show loading state while fetching today's data
                    showLoadingState();
                    
                    // Set the period indicator to show "Today"
                    const periodIndicators = domCache.getAll('.period-indicator');
                    periodIndicators.forEach(indicator => {
                        indicator.textContent = 'Loading today\'s data...';
                    });

                    try {
                        // Fetch today's card data with caching
                        const data = await cachedFetch(`get_card_data.php?view=daily`);
                        
                            // Update all cards with today's data
                            updateCardValues(data);
                            
                            // Update period indicator to show "Today"
                            periodIndicators.forEach(indicator => {
                                indicator.textContent = 'Today';
                            });
                    } catch (error) {
                            console.error('Error fetching today\'s data:', error);
                            // Show error message
                            periodIndicators.forEach(indicator => {
                                indicator.textContent = 'Error loading today\'s data';
                            });
                    }
                }

                // Memory cleanup function
                function cleanupMemory() {
                    // Clear request cache older than 5 minutes
                    const fiveMinutesAgo = Date.now() - (5 * 60 * 1000);
                    for (const [key, value] of requestCache.entries()) {
                        if (value.timestamp < fiveMinutesAgo) {
                            requestCache.delete(key);
                        }
                    }
                    
                    // Clear DOM cache if page is hidden (tab switching)
                    if (document.hidden) {
                        domCache.clear();
                    }
                }

                // Set up periodic cleanup
                setInterval(cleanupMemory, 60000); // Run every minute
                
                // Cleanup when page becomes hidden
                document.addEventListener('visibilitychange', cleanupMemory);

                // Employee Statistics Functions
                async function loadEmployeeStatistics(period = 'all') {
                    try {
                        const response = await fetch(`get_employee_stats.php?period=${period}`);
                        const data = await response.json();
                        
                        if (data.success) {
                            updateEmployeeCards(data);
                            updateEmployeeTable(data.employees);
                            
                            // Update chart data
                            employeeChartData = data.employees || [];
                            updateEmployeeChart();
                        } else {
                            console.error('Error loading employee statistics:', data.error);
                            showEmployeeError();
                        }
                    } catch (error) {
                        console.error('Error loading employee statistics:', error);
                        showEmployeeError();
                    }
                }

                function updateEmployeeCards(data) {
                    // Update total employees
                    const totalEmployeesElement = document.getElementById('totalEmployees');
                    if (totalEmployeesElement) {
                        totalEmployeesElement.textContent = data.totalEmployees || 0;
                    }

                    // Update top credit sales
                    const topCreditSalesElement = document.getElementById('topCreditSales');
                    const topCreditSalesAmountElement = document.getElementById('topCreditSalesAmount');
                    if (topCreditSalesElement && topCreditSalesAmountElement) {
                        // Find employee with highest credit sales
                        const topCreditEmployee = data.employees ? data.employees.reduce((top, current) => {
                            return (current.creditSales || 0) > (top.creditSales || 0) ? current : top;
                        }, { name: '-', creditSales: 0 }) : { name: '-', creditSales: 0 };
                        
                        topCreditSalesElement.textContent = topCreditEmployee.name;
                        topCreditSalesAmountElement.textContent = `N$${parseFloat(topCreditEmployee.creditSales || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    }

                    // Update top performer
                    const topPerformerElement = document.getElementById('topPerformer');
                    const topPerformerSalesElement = document.getElementById('topPerformerSales');
                    if (topPerformerElement && topPerformerSalesElement) {
                        if (data.topPerformer) {
                            topPerformerElement.textContent = data.topPerformer.name;
                            topPerformerSalesElement.textContent = `N$${parseFloat(data.topPerformer.totalSales || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                        } else {
                            topPerformerElement.textContent = '-';
                            topPerformerSalesElement.textContent = 'N$0.00';
                        }
                    }

                    // Update average sales per employee
                    const avgSalesElement = document.getElementById('avgSalesPerEmployee');
                    if (avgSalesElement) {
                        avgSalesElement.textContent = `N$${parseFloat(data.avgSalesPerEmployee || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                    }
                }

                function updateEmployeeTable(employees) {
                    const tableBody = document.getElementById('employeeTableBody');
                    if (!tableBody) return;

                    // Store full data for pagination
                    employeeData = employees || [];
                    employeePage = 1; // Reset to first page when data changes

                    if (!employeeData || employeeData.length === 0) {
                        tableBody.innerHTML = `
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-center text-gray-500">
                                    No employee data available
                                </td>
                            </tr>
                        `;
                        updateEmployeePagination();
                        return;
                    }

                    renderEmployeePage();
                }

                function renderEmployeePage() {
                    const tableBody = document.getElementById('employeeTableBody');
                    if (!tableBody) return;

                    const startIndex = (employeePage - 1) * itemsPerPage;
                    const endIndex = startIndex + itemsPerPage;
                    const pageData = employeeData.slice(startIndex, endIndex);

                    const rows = pageData.map(employee => `
                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8">
                                        <div class="h-8 w-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center">
                                            <span class="text-xs font-medium text-white">${employee.name.charAt(0).toUpperCase()}</span>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900">${employee.name}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                N$${parseFloat(employee.totalSales || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                ${employee.totalOrders || 0}
                            </td>
                        </tr>
                    `).join('');

                    tableBody.innerHTML = rows;
                    updateEmployeePagination();
                }

                function updateEmployeePagination() {
                    const totalItems = employeeData.length;
                    const totalPages = Math.ceil(totalItems / itemsPerPage);
                    const startItem = totalItems === 0 ? 0 : (employeePage - 1) * itemsPerPage + 1;
                    const endItem = Math.min(employeePage * itemsPerPage, totalItems);

                    // Update page info
                    document.getElementById('employeePageInfo').textContent = `${startItem}-${endItem}`;
                    document.getElementById('employeeTotalItems').textContent = totalItems;

                    // Update button states
                    const prevDisabled = employeePage === 1;
                    const nextDisabled = employeePage >= totalPages;

                    document.getElementById('employeePrevBtn').disabled = prevDisabled;
                    document.getElementById('employeeNextBtn').disabled = nextDisabled;
                    document.getElementById('employeePrevBtnDesktop').disabled = prevDisabled;
                    document.getElementById('employeeNextBtnDesktop').disabled = nextDisabled;
                }

                function changeEmployeePage(direction) {
                    const totalPages = Math.ceil(employeeData.length / itemsPerPage);
                    const newPage = employeePage + direction;
                    
                    if (newPage >= 1 && newPage <= totalPages) {
                        employeePage = newPage;
                        renderEmployeePage();
                    }
                }

                function showEmployeeError() {
                    const tableBody = document.getElementById('employeeTableBody');
                    if (tableBody) {
                        tableBody.innerHTML = `
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-center text-red-500">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Error loading employee data
                                </td>
                            </tr>
                        `;
                        updateEmployeePagination();
                    }
                }

                // Employee chart data storage
                let employeeChartData = [];

                // Initialize employee chart
                function initializeEmployeeChart() {
                    const ctx = document.getElementById('employeeChart');
                    if (!ctx) return;
                    
                    employeeChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: ['Loading...'],
                            datasets: [{
                                label: 'Employee Performance',
                                data: [0],
                                borderColor: 'rgba(75, 192, 192, 1)',
                                backgroundColor: 'rgba(75, 192, 192, 0.8)',
                                borderWidth: 2,
                                fill: false,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: {
                                duration: 800,
                                easing: 'easeOutQuart'
                            },
                            scales: {
                                x: {
                                    display: true,
                                    title: {
                                        display: true,
                                        text: 'Employees'
                                    }
                                },
                                y: {
                                    display: true,
                                    title: {
                                        display: true,
                                        text: 'Sales Amount (N$)'
                                    },
                                    beginAtZero: true
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const chartView = document.getElementById('chartView')?.value || 'sales';
                                            if (chartView === 'sales') {
                                                return `Total Sales: N$${parseFloat(context.parsed.y).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                                            } else if (chartView === 'credit') {
                                                return `Credit Sales: N$${parseFloat(context.parsed.y).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                                            } else if (chartView === 'eft') {
                                                return `EFT Sales: N$${parseFloat(context.parsed.y).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                                            } else if (chartView === 'cash') {
                                                return `Cash Sales: N$${parseFloat(context.parsed.y).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                                            } else if (chartView === 'orders') {
                                                return `Orders: ${context.parsed.y}`;
                                            } else {
                                                return `Avg: N$${parseFloat(context.parsed.y).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Employee color palette - translucent colors
                const employeeColors = [
                    { bg: 'rgba(75, 192, 192, 0.3)', border: 'rgba(75, 192, 192, 0.8)' },   // Teal
                    { bg: 'rgba(255, 99, 132, 0.3)', border: 'rgba(255, 99, 132, 0.8)' },   // Red
                    { bg: 'rgba(54, 162, 235, 0.3)', border: 'rgba(54, 162, 235, 0.8)' },   // Blue
                    { bg: 'rgba(255, 205, 86, 0.3)', border: 'rgba(255, 205, 86, 0.8)' },   // Yellow
                    { bg: 'rgba(153, 102, 255, 0.3)', border: 'rgba(153, 102, 255, 0.8)' }, // Purple
                    { bg: 'rgba(255, 159, 64, 0.3)', border: 'rgba(255, 159, 64, 0.8)' },   // Orange
                    { bg: 'rgba(199, 199, 199, 0.3)', border: 'rgba(199, 199, 199, 0.8)' }, // Gray
                    { bg: 'rgba(83, 102, 255, 0.3)', border: 'rgba(83, 102, 255, 0.8)' },   // teal
                    { bg: 'rgba(255, 99, 132, 0.3)', border: 'rgba(255, 99, 132, 0.8)' },   // teal
                    { bg: 'rgba(34, 197, 94, 0.3)', border: 'rgba(34, 197, 94, 0.8)' }      // teal
                ];

                // Update employee chart view (button click handler)
                function updateEmployeeChartView(view) {
                    // Update button styles
                    document.querySelectorAll('#salesBtn, #creditBtn, #eftBtn, #cashBtn, #ordersBtn, #avgBtn').forEach(btn => {
                        btn.classList.remove('bg-teal-100', 'text-teal-700');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    document.getElementById(view + 'Btn').classList.remove('bg-gray-100', 'text-gray-700');
                    document.getElementById(view + 'Btn').classList.add('bg-teal-100', 'text-teal-700');
                    
                    // Update chart with new view
                    updateEmployeeChart(view);
                }

                // Update employee chart
                function updateEmployeeChart(chartView = 'sales') {
                    if (!employeeChart || !employeeChartData.length) return;
                    let labels = [];
                    let data = [];
                    let yAxisLabel = '';
                    let chartLabel = '';
                    
                    // Sort employees by the selected metric
                    const sortedEmployees = [...employeeChartData].sort((a, b) => {
                        if (chartView === 'sales') {
                            return b.totalSales - a.totalSales;
                        } else if (chartView === 'credit') {
                            return b.creditSales - a.creditSales;
                        } else if (chartView === 'eft') {
                            return b.eftSales - a.eftSales;
                        } else if (chartView === 'cash') {
                            return b.cashSales - a.cashSales;
                        } else if (chartView === 'orders') {
                            return b.totalOrders - a.totalOrders;
                        } else {
                            return b.avgOrderValue - a.avgOrderValue;
                        }
                    });
                    
                    // Take top 10 employees for better visualization
                    const topEmployees = sortedEmployees.slice(0, 10);
                    
                    topEmployees.forEach(employee => {
                        labels.push(employee.name);
                        
                        if (chartView === 'sales') {
                            data.push(employee.totalSales);
                            yAxisLabel = 'Total Sales (N$)';
                            chartLabel = 'Total Sales';
                        } else if (chartView === 'credit') {
                            data.push(employee.creditSales);
                            yAxisLabel = 'Credit Sales (N$)';
                            chartLabel = 'Credit Sales';
                        } else if (chartView === 'eft') {
                            data.push(employee.eftSales);
                            yAxisLabel = 'EFT Sales (N$)';
                            chartLabel = 'EFT Sales';
                        } else if (chartView === 'cash') {
                            data.push(employee.cashSales);
                            yAxisLabel = 'Cash Sales (N$)';
                            chartLabel = 'Cash Sales';
                        } else if (chartView === 'orders') {
                            data.push(employee.totalOrders);
                            yAxisLabel = 'Number of Orders';
                            chartLabel = 'Number of Orders';
                        } else {
                            data.push(employee.avgOrderValue);
                            yAxisLabel = 'Average Order Value (N$)';
                            chartLabel = 'Average Order Value';
                        }
                    });
                    
                    // Update chart
                    employeeChart.data.labels = labels;
                    employeeChart.data.datasets[0].data = data;
                    employeeChart.data.datasets[0].label = chartLabel;
                    employeeChart.options.scales.y.title.text = yAxisLabel;
                    
                    // Assign different colors to each employee
                    const backgroundColors = [];
                    const borderColors = [];
                    
                    topEmployees.forEach((employee, index) => {
                        const colorIndex = index % employeeColors.length;
                        backgroundColors.push(employeeColors[colorIndex].bg);
                        borderColors.push(employeeColors[colorIndex].border);
                    });
                    
                    employeeChart.data.datasets[0].backgroundColor = backgroundColors;
                    employeeChart.data.datasets[0].borderColor = borderColors;
                    employeeChart.data.datasets[0].borderWidth = 2;
                    employeeChart.data.datasets[0].fill = false; // Keep translucent bars without fill
                    
                    employeeChart.update();
                }

                // Initialize employee statistics
                document.addEventListener('DOMContentLoaded', function() {
                    // Initialize employee chart
                    initializeEmployeeChart();
                    
                    // Load initial employee data with 'all' period (Total view - all employees)
                    setTimeout(() => {
                        updateEmployeePeriod('all');
                    }, 200);
                    
                    // Handle chart view change - now using buttons instead of select
                    // The updateEmployeeChartView function will handle button clicks
                });

                // Function to update employee period (button click handler)
                function updateEmployeePeriod(period, event) {
                    // Prevent page scroll
                    if (event) {
                        event.preventDefault();
                    }
                    
                    // Update button styles
                    document.querySelectorAll('#employeeAllBtn, #employeeTodayBtn, #employeeWeekBtn, #employeeMonthBtn, #employeeYearBtn').forEach(btn => {
                        btn.classList.remove('bg-teal-100', 'text-teal-700');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    
                    // Handle 'all' period button ID
                    const buttonId = period === 'all' ? 'employeeAllBtn' : 'employee' + period.charAt(0).toUpperCase() + period.slice(1) + 'Btn';
                    const button = document.getElementById(buttonId);
                    if (button) {
                        button.classList.remove('bg-gray-100', 'text-gray-700');
                        button.classList.add('bg-teal-100', 'text-teal-700');
                    }
                    
                    // Load employee statistics for the selected period
                    loadEmployeeStatistics(period);
                }

                // Creditor Analytics Functions
                function initializeCreditorChart() {
                    const ctx = document.getElementById('creditorChart');
                    if (!ctx) return;
                    
                    creditorChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: ['Loading...'],
                            datasets: [{
                                label: 'Creditor Performance',
                                data: [0],
                                backgroundColor: [
                                    'rgba(153, 102, 255, 0.8)',
                                    'rgba(255, 99, 132, 0.8)',
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(255, 205, 86, 0.8)',
                                    'rgba(75, 192, 192, 0.8)',
                                    'rgba(255, 159, 64, 0.8)',
                                    'rgba(199, 199, 199, 0.8)',
                                    'rgba(83, 102, 255, 0.8)',
                                    'rgba(34, 197, 94, 0.8)',
                                    'rgba(239, 68, 68, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(153, 102, 255, 1)',
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 205, 86, 1)',
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(255, 159, 64, 1)',
                                    'rgba(199, 199, 199, 1)',
                                    'rgba(83, 102, 255, 1)',
                                    'rgba(34, 197, 94, 1)',
                                    'rgba(239, 68, 68, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: {
                                duration: 800,
                                easing: 'easeOutQuart'
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'right'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                            return `${label}: N$${parseFloat(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Creditor color palette
                const creditorColors = [
                    { bg: 'rgba(153, 102, 255, 0.3)', border: 'rgba(153, 102, 255, 0.8)' }, // Purple
                    { bg: 'rgba(255, 99, 132, 0.3)', border: 'rgba(255, 99, 132, 0.8)' }, // Red
                    { bg: 'rgba(54, 162, 235, 0.3)', border: 'rgba(54, 162, 235, 0.8)' }, // Blue
                    { bg: 'rgba(255, 205, 86, 0.3)', border: 'rgba(255, 205, 86, 0.8)' }, // Yellow
                    { bg: 'rgba(75, 192, 192, 0.3)', border: 'rgba(75, 192, 192, 0.8)' }, // Teal
                    { bg: 'rgba(255, 159, 64, 0.3)', border: 'rgba(255, 159, 64, 0.8)' }, // Orange
                    { bg: 'rgba(199, 199, 199, 0.3)', border: 'rgba(199, 199, 199, 0.8)' }, // Gray
                    { bg: 'rgba(83, 102, 255, 0.3)', border: 'rgba(83, 102, 255, 0.8)' }, // teal
                    { bg: 'rgba(34, 197, 94, 0.3)', border: 'rgba(34, 197, 94, 0.8)' }, // teal
                    { bg: 'rgba(239, 68, 68, 0.3)', border: 'rgba(239, 68, 68, 0.8)' }  // Red
                ];

                async function loadCreditorAnalytics(period = 'all') {
                    try {
                        const response = await fetch(`get_creditor_analytics.php?period=${period}`);
                        const data = await response.json();
                        
                        if (data.success) {
                            creditorChartData = data.creditors || [];
                            updateCreditorTable(data.creditors);
                            updateCreditorChart('sales');
                        } else {
                            console.error('Error loading creditor analytics:', data.error);
                            showCreditorError();
                        }
                    } catch (error) {
                        console.error('Error loading creditor analytics:', error);
                        showCreditorError();
                    }
                }

                function updateCreditorTable(creditors) {
                    const tableBody = document.getElementById('creditorTableBody');
                    if (!tableBody) return;

                    // Store full data for pagination
                    creditorData = creditors || [];
                    creditorPage = 1; // Reset to first page when data changes

                    if (!creditorData || creditorData.length === 0) {
                        tableBody.innerHTML = `
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-center text-gray-500">
                                    No creditor data available
                                </td>
                            </tr>
                        `;
                        updateCreditorPagination();
                        return;
                    }

                    renderCreditorPage();
                }

                function renderCreditorPage() {
                    const tableBody = document.getElementById('creditorTableBody');
                    if (!tableBody) return;

                    const startIndex = (creditorPage - 1) * itemsPerPage;
                    const endIndex = startIndex + itemsPerPage;
                    const pageData = creditorData.slice(startIndex, endIndex);

                    const rows = pageData.map(creditor => `
                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">${creditor.name}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                N$${parseFloat(creditor.total_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm ${parseFloat(creditor.outstanding_balance || 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-900'}">
                                N$${parseFloat(creditor.outstanding_balance || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </td>
                        </tr>
                    `).join('');

                    tableBody.innerHTML = rows;
                    updateCreditorPagination();
                }

                function updateCreditorPagination() {
                    const totalItems = creditorData.length;
                    const totalPages = Math.ceil(totalItems / itemsPerPage);
                    const startItem = totalItems === 0 ? 0 : (creditorPage - 1) * itemsPerPage + 1;
                    const endItem = Math.min(creditorPage * itemsPerPage, totalItems);

                    // Update page info
                    document.getElementById('creditorPageInfo').textContent = `${startItem}-${endItem}`;
                    document.getElementById('creditorTotalItems').textContent = totalItems;

                    // Update button states
                    const prevDisabled = creditorPage === 1;
                    const nextDisabled = creditorPage >= totalPages;

                    document.getElementById('creditorPrevBtn').disabled = prevDisabled;
                    document.getElementById('creditorNextBtn').disabled = nextDisabled;
                    document.getElementById('creditorPrevBtnDesktop').disabled = prevDisabled;
                    document.getElementById('creditorNextBtnDesktop').disabled = nextDisabled;
                }

                function changeCreditorPage(direction) {
                    const totalPages = Math.ceil(creditorData.length / itemsPerPage);
                    const newPage = creditorPage + direction;
                    
                    if (newPage >= 1 && newPage <= totalPages) {
                        creditorPage = newPage;
                        renderCreditorPage();
                    }
                }

                function showCreditorError() {
                    const tableBody = document.getElementById('creditorTableBody');
                    if (tableBody) {
                        tableBody.innerHTML = `
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-center text-red-500">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Error loading creditor data
                                </td>
                            </tr>
                        `;
                        updateCreditorPagination();
                    }
                }

                function updateCreditorChartView(view) {
                    // Update button styles
                    document.querySelectorAll('#creditorSalesBtn, #creditorOutstandingBtn, #creditorPaidBtn, #creditorTransactionsBtn').forEach(btn => {
                        btn.classList.remove('bg-teal-100', 'text-teal-700');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    document.getElementById('creditor' + view.charAt(0).toUpperCase() + view.slice(1) + 'Btn').classList.remove('bg-gray-100', 'text-gray-700');
                    document.getElementById('creditor' + view.charAt(0).toUpperCase() + view.slice(1) + 'Btn').classList.add('bg-teal-100', 'text-teal-700');
                    
                    // Update chart with new view
                    updateCreditorChart(view);
                }

                function updateCreditorChart(chartView = 'sales') {
                    if (!creditorChart || !creditorChartData.length) return;
                    
                    let labels = [];
                    let data = [];
                    let chartLabel = '';
                    
                    // Sort creditors by the selected metric
                    const sortedCreditors = [...creditorChartData].sort((a, b) => {
                        if (chartView === 'sales') {
                            return b.total_sales - a.total_sales;
                        } else if (chartView === 'outstanding') {
                            return b.outstanding_balance - a.outstanding_balance;
                        } else if (chartView === 'paid') {
                            return b.total_paid - a.total_paid;
                        } else {
                            return b.total_transactions - a.total_transactions;
                        }
                    });
                    
                    // Take top 10 creditors for better visualization
                    const topCreditors = sortedCreditors.slice(0, 10);
                    
                    topCreditors.forEach(creditor => {
                        labels.push(creditor.name);
                        
                        if (chartView === 'sales') {
                            data.push(creditor.total_sales);
                            chartLabel = 'Total Sales';
                        } else if (chartView === 'outstanding') {
                            data.push(creditor.outstanding_balance);
                            chartLabel = 'Outstanding Balance';
                        } else if (chartView === 'paid') {
                            data.push(creditor.total_paid);
                            chartLabel = 'Paid Amount';
                        } else {
                            data.push(creditor.total_transactions);
                            chartLabel = 'Number of Transactions';
                        }
                    });
                    
                    // Pie chart color arrays
                    const pieColors = [
                        'rgba(153, 102, 255, 0.8)',  // Purple
                        'rgba(255, 99, 132, 0.8)',   // Red
                        'rgba(54, 162, 235, 0.8)',   // Blue
                        'rgba(255, 205, 86, 0.8)',   // Yellow
                        'rgba(75, 192, 192, 0.8)',   // Teal
                        'rgba(255, 159, 64, 0.8)',   // Orange
                        'rgba(199, 199, 199, 0.8)',  // Gray
                        'rgba(83, 102, 255, 0.8)',   // teal
                        'rgba(34, 197, 94, 0.8)',    // teal
                        'rgba(239, 68, 68, 0.8)'     // Red
                    ];
                    
                    const pieBorderColors = [
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 205, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 159, 64, 1)',
                        'rgba(199, 199, 199, 1)',
                        'rgba(83, 102, 255, 1)',
                        'rgba(34, 197, 94, 1)',
                        'rgba(239, 68, 68, 1)'
                    ];
                    
                    // Assign colors to each creditor
                    const backgroundColors = [];
                    const borderColors = [];
                    
                    topCreditors.forEach((creditor, index) => {
                        const colorIndex = index % pieColors.length;
                        backgroundColors.push(pieColors[colorIndex]);
                        borderColors.push(pieBorderColors[colorIndex]);
                    });
                    
                    // Update chart
                    creditorChart.data.labels = labels;
                    creditorChart.data.datasets[0].data = data;
                    creditorChart.data.datasets[0].label = chartLabel;
                    creditorChart.data.datasets[0].backgroundColor = backgroundColors;
                    creditorChart.data.datasets[0].borderColor = borderColors;
                    creditorChart.data.datasets[0].borderWidth = 2;
                    
                    creditorChart.update();
                }

                // Function to update creditor period (button click handler)
                function updateCreditorPeriod(period, event) {
                    // Prevent page scroll
                    if (event) {
                        event.preventDefault();
                    }
                    
                    // Update button styles
                    document.querySelectorAll('#creditorAllBtn, #creditorTodayBtn, #creditorWeekBtn, #creditorMonthBtn, #creditorYearBtn').forEach(btn => {
                        btn.classList.remove('bg-teal-100', 'text-teal-700');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    
                    // Handle 'all' period button ID
                    const buttonId = period === 'all' ? 'creditorAllBtn' : 'creditor' + period.charAt(0).toUpperCase() + period.slice(1) + 'Btn';
                    const button = document.getElementById(buttonId);
                    if (button) {
                        button.classList.remove('bg-gray-100', 'text-gray-700');
                        button.classList.add('bg-teal-100', 'text-teal-700');
                    }
                    
                    // Load creditor analytics for the selected period
                    loadCreditorAnalytics(period);
                }

                // Load notifications asynchronously
                async function loadNotifications() {
                    try {
                        const response = await fetch('get_notifications.php');
                        const data = await response.json();
                        
                        const notificationCount = data.outOfStock.length + data.lowStock.length;
                        const notificationElement = document.getElementById('notificationCount');
                        const stockReportCountElement = document.getElementById('stockReportCount');
                        
                        if (notificationCount > 0) {
                            notificationElement.textContent = notificationCount < 100 ? notificationCount : '99+';
                            notificationElement.classList.remove('hidden');
                            
                            // Update stock report count
                            stockReportCountElement.textContent = notificationCount < 100 ? notificationCount : '99+';
                            stockReportCountElement.classList.remove('hidden');
                        } else {
                            notificationElement.classList.add('hidden');
                            stockReportCountElement.classList.add('hidden');
                        }
                        
                        // Update notifications content
                        const notificationsContent = document.getElementById('notificationsContent');
                        if (notificationCount === 0) {
                            notificationsContent.innerHTML = `
                                <div class="p-6 text-center">
                                    <div class="mx-auto w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                        </svg>
                                    </div>
                                    <p class="text-gray-500 font-medium">No notifications</p>
                                    <p class="text-gray-400 text-sm mt-1">You're all caught up!</p>
                                </div>
                            `;
                        } else {
                            let html = '';
                            
                            if (data.outOfStock.length > 0) {
                                html += `
                                    <div class="p-4 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-200">
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0">
                                                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                                                    <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                </div>
                                            </div>
                                            <div class="ml-4 flex-1">
                                                <div class="flex items-center justify-between">
                                                    <h3 class="text-sm font-semibold text-gray-900">Out of Stock Products</h3>
                                                    <span class="text-xs font-medium text-red-500 bg-red-50 px-2 py-1 rounded-full">${data.outOfStock.length}</span>
                                                </div>
                                                <div class="mt-2 space-y-2">
                                                    ${data.outOfStock.map(product => `
                                                        <div class="flex items-center text-sm">
                                                            <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                            <a href="edit.php?id=${product.id}" class="text-gray-700 hover:text-teal-600 transition-colors">
                                                                ${product.name}
                                                            </a>
                                                        </div>
                                                    `).join('')}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }
                            
                            if (data.lowStock.length > 0) {
                                html += `
                                    <div class="p-4 hover:bg-gray-50 transition-colors duration-200">
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0">
                                                <div class="w-10 h-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                                    <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                </div>
                                            </div>
                                            <div class="ml-4 flex-1">
                                                <div class="flex items-center justify-between">
                                                    <h3 class="text-sm font-semibold text-gray-900">Low Stock Alert</h3>
                                                    <span class="text-xs font-medium text-yellow-500 bg-yellow-50 px-2 py-1 rounded-full">${data.lowStock.length}</span>
                                                </div>
                                                <div class="mt-2 space-y-2">
                                                    ${data.lowStock.map(product => `
                                                        <div class="flex items-center justify-between text-sm">
                                                            <div class="flex items-center">
                                                                <svg class="w-4 h-4 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                                </svg>
                                                                <a href="edit.php?id=${product.id}" class="text-gray-700 hover:text-teal-600 transition-colors">
                                                                    ${product.name}
                                                                </a>
                                                            </div>
                                                            <span class="text-yellow-600 font-medium">${product.quantity} left</span>
                                                        </div>
                                                    `).join('')}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            }
                            
                            notificationsContent.innerHTML = html;
                        }
                    } catch (error) {
                        console.error('Error loading notifications:', error);
                    }
                }

                // Load initial chart data
                async function loadInitialChartData() {
                    try {
                        const chartData = await cachedFetch(`get_sales_data.php?view=daily`);
                        
                        if (chartData.success) {
                            // Destroy existing chart to prevent animation glitches
                            if (salesChart) {
                                salesChart.destroy();
                            }
                            
                            // Create a new chart instance with real data
                            salesChart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: chartData.labels,
                                    datasets: [{
                                        label: 'Daily Sales (N$)',
                                        data: chartData.sales,
                                        borderColor: 'rgba(75, 192, 192, 1)',
                                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                        borderWidth: 2,
                                        fill: true,
                                        tension: 0.1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    animation: {
                                        duration: 800,
                                        easing: 'easeOutQuart'
                                    },
                                    scales: {
                                        x: {
                                            display: true,
                                            title: {
                                                display: true,
                                                text: 'Day'
                                            }
                                        },
                                        y: {
                                            display: true,
                                            title: {
                                                display: true,
                                                text: 'Sales Amount (N$)'
                                            },
                                            beginAtZero: true
                                        }
                                    },
                                    plugins: {
                                        legend: {
                                            display: true,
                                            position: 'top'
                                        }
                                    }
                                }
                            });
                        }
                    } catch (error) {
                        console.error('Error loading initial chart data:', error);
                    }
                }

                // Optimized chart update function with debouncing and caching
                const debouncedUpdateChart = debounce(async function updateChart(view, event) {
                    // Prevent page scroll
                    if (event) {
                        event.preventDefault();
                    }
                    
                    // Update button styles
                    document.querySelectorAll('button').forEach(btn => {
                        btn.classList.remove('bg-teal-100', 'text-teal-700');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    document.getElementById(view + 'Btn').classList.remove('bg-gray-100', 'text-gray-700');
                    document.getElementById(view + 'Btn').classList.add('bg-teal-100', 'text-teal-700');

                    // Show loading state for cards
                    showLoadingState();

                    // If daily is selected, ensure we're getting today's data specifically
                    const viewParam = view === 'daily' ? 'daily' : view;

                    try {
                        // Fetch chart data and card data simultaneously with caching
                        const [chartData, cardData] = await Promise.all([
                            cachedFetch(`get_sales_data.php?view=${viewParam}`),
                            cachedFetch(`get_card_data.php?view=${viewParam}`)
                        ]);
                        
                        // Check for errors in responses
                        if (!chartData.success) {
                            throw new Error('Chart data error: ' + (chartData.error || 'Unknown error'));
                        }
                        if (!cardData.success) {
                            throw new Error('Card data error: ' + (cardData.error || 'Unknown error'));
                        }
                        
                        // Destroy existing chart to prevent animation glitches
                        if (salesChart) {
                            salesChart.destroy();
                        }
                        
                        // Use the labels from the response (no need to override for weekly)
                        let labels = chartData.labels;
                        
                        // Create a new chart instance
                        salesChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: `${view.charAt(0).toUpperCase() + view.slice(1)} Sales (N$)`,
                                    data: chartData.sales,
                                    borderColor: 'rgba(75, 192, 192, 1)',
                                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                    borderWidth: 2,
                                    fill: true,
                                    tension: 0.1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: {
                                    duration: 800,
                                    easing: 'easeOutQuart'
                                },
                                scales: {
                                    x: {
                                        display: true,
                                        title: {
                                            display: true,
                                            text: view === 'weekly' ? 'Weeks' : (view === 'monthly' ? 'Months' : 'Days')
                                        }
                                    },
                                    y: {
                                        display: true,
                                        title: {
                                            display: true,
                                            text: 'Sales Amount (N$)'
                                        },
                                        beginAtZero: true
                                    }
                                },
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'top'
                                    }
                                }
                            }
                        });

                        // Update card values
                        updateCardValues(cardData);
                    } catch (error) {
                        console.error('Error fetching data:', error);
                        // Show error message to user
                        const periodIndicators = document.querySelectorAll('.period-indicator');
                        periodIndicators.forEach(indicator => {
                            indicator.textContent = 'Error loading data';
                            indicator.style.color = 'red';
                        });
                        
                        // Optionally show a toast notification
                        if (window.showToast) {
                            window.showToast('Error loading data: ' + error.message, 'error');
                        }
                }
                }, 300); // 300ms debounce

                // Expose the debounced function globally for onclick handlers
                window.updateChart = debouncedUpdateChart;

                function showLoadingState() {
                    // Show loading state for all cards except cash in till (which should remain constant)
                    const cards = domCache.getAll('[data-card]:not([data-card="cashInTill"])');
                    cards.forEach(card => {
                        const h3Element = card.querySelector('h3');
                        if (h3Element) {
                            h3Element.innerHTML = '<i class="fas fa-spinner fa-spin text-gray-400"></i>';
                        }
                    });
                }

                // Cache DOM elements for better performance
                const domCache = {
                    elements: new Map(),
                    get(selector) {
                        if (!this.elements.has(selector)) {
                            this.elements.set(selector, document.querySelector(selector));
                        }
                        return this.elements.get(selector);
                    },
                    getAll(selector) {
                        const cacheKey = selector + '_all';
                        if (!this.elements.has(cacheKey)) {
                            this.elements.set(cacheKey, document.querySelectorAll(selector));
                        }
                        return this.elements.get(cacheKey);
                    },
                    clear() {
                        this.elements.clear();
                    }
                };

                function updateCardValues(data) {
                    // Helper function to format currency
                    const formatCurrency = (amount) => {
                        return 'N$' + parseFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    };

                    // Helper function to determine color class
                    const getColorClass = (amount) => {
                        return amount >= 0 ? 'text-blue-600' : 'text-red-600';
                    };

                    // DON'T UPDATE CASH IN TILL - it should remain constant as it represents current physical cash
                    // The cash in till card will only be updated on page load with current amount
                    
                    // Update Total Cash Received Card
                    const totalDepositsElement = domCache.get('[data-card="totalDeposits"] h3');
                    if (totalDepositsElement) {
                        totalDepositsElement.textContent = formatCurrency(data.totalDeposits);
                        totalDepositsElement.className = 'text-2xl font-bold text-teal-600';
                    }

                    // Update Net Revenue Card
                    const netRevenueElement = domCache.get('[data-card="netRevenue"] h3');
                    if (netRevenueElement) {
                        netRevenueElement.textContent = formatCurrency(data.netRevenue);
                        netRevenueElement.className = `text-2xl font-bold ${data.netRevenue >= 0 ? 'text-teal-600' : 'text-red-600'}`;
                    }

                    // Update additional cards (if visible)
                    const unpaidCreditElement = domCache.get('[data-card="unpaidCredit"] h3');
                    if (unpaidCreditElement) {
                        unpaidCreditElement.textContent = formatCurrency(data.unpaidCreditSales);
                        unpaidCreditElement.className = 'text-2xl font-bold text-purple-600';
                    }

                    const eftPaymentsElement = domCache.get('[data-card="eftPayments"] h3');
                    if (eftPaymentsElement) {
                        eftPaymentsElement.textContent = formatCurrency(data.totalEftPayments);
                        eftPaymentsElement.className = 'text-2xl font-bold text-teal-600';
                    }

                    const cashOutElement = domCache.get('[data-card="cashOut"] h3');
                    if (cashOutElement) {
                        cashOutElement.textContent = formatCurrency(data.totalCashOut);
                        cashOutElement.className = 'text-2xl font-bold text-red-600';
                    }

                    // Update inventory cards
                    const totalInventoryValueElement = domCache.get('[data-card="totalInventoryValue"] h3');
                    if (totalInventoryValueElement) {
                        totalInventoryValueElement.textContent = formatCurrency(data.totalInventoryValue);
                        totalInventoryValueElement.className = 'text-2xl font-bold text-teal-600';
                    }

                    const totalProductsElement = domCache.get('[data-card="totalProducts"] h3');
                    if (totalProductsElement) {
                        totalProductsElement.textContent = data.totalProducts.toLocaleString();
                        totalProductsElement.className = 'text-2xl font-bold text-cyan-600';
                    }

                    const productsInStockElement = domCache.get('[data-card="productsInStock"] h3');
                    if (productsInStockElement) {
                        productsInStockElement.textContent = data.productsInStock.toLocaleString();
                        productsInStockElement.className = 'text-2xl font-bold text-teal-600';
                    }

                    // Update period indicators if they exist
                    const periodIndicators = domCache.getAll('.period-indicator');
                    periodIndicators.forEach(indicator => {
                        let displayText = data.dateRange;
                        if (data.period === 'daily') {
                            displayText = data.dateRange === new Date().toISOString().split('T')[0] ? 'Today' : data.dateRange;
                        } else if (data.period === 'weekly') {
                            displayText = 'This Week (' + data.dateRange + ')';
                        } else if (data.period === 'monthly') {
                            displayText = 'This Month (' + data.dateRange + ')';
                        }
                        indicator.textContent = displayText;
                    });
                }

                // Card navigation function
                function navigateToCard(cardType) {
                    // Add visual feedback
                    const card = document.querySelector(`[data-card="${cardType}"]`);
                    if (card) {
                        card.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            card.style.transform = '';
                        }, 150);
                    }

                    // Navigate based on card type
                    switch(cardType) {
                        case 'cashInTill':
                            // Navigate to cash transactions page
                            window.location.href = 'cash.php';
                            break;
                        case 'totalDeposits':
                            // Navigate to cash transactions page with deposit filter
                            window.location.href = 'weekly_sales.php?type=cash-in';
                            break;
                        case 'netRevenue':
                            // Navigate to financial reports page
                            window.location.href = 'weekly_sales.php?type=financial';
                            break;
                        case 'unpaidCredit':
                            // Navigate to credit sales page with unpaid filter
                            window.location.href = 'credit-book.php?status=unpaid';
                            break;
                        case 'eftPayments':
                            // Navigate to EFT payments page
                            window.location.href = 'reports.php';
                            break;
                        case 'cashOut':
                            // Navigate to cash transactions page with withdrawal filter
                            window.location.href = 'cash.php?type=cash-out';
                            break;
                        case 'totalInventoryValue':
                        case 'totalProducts':
                        case 'productsInStock':
                            // Navigate to products/inventory page
                            window.location.href = 'inventory.php';
                            break;
                        default:
                            // Default fallback
                            console.log('Navigation not implemented for card type:', cardType);
                            break;
                    }
                }

                // Download stock report function
                function downloadStockReport() {
                    // Add visual feedback
                    const button = event.target.closest('button');
                    if (button) {
                        button.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            button.style.transform = '';
                        }, 150);
                    }

                    // Show loading state
                    const originalContent = button.innerHTML;
                    button.innerHTML = '<svg class="w-6 h-6 text-orange-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>';
                    
                    // Download the PDF
                    const link = document.createElement('a');
                    link.href = 'generate_stock_report.php';
                    link.download = 'stock_alert_report_' + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.pdf';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // Restore button content after a short delay
                    setTimeout(() => {
                        button.innerHTML = originalContent;
                    }, 1000);
                }
            </script>

            <script>
                // Toggle additional cards
                document.addEventListener('DOMContentLoaded', function() {
                    const toggleBtn = document.getElementById('toggleCardsBtn');
                    const toggleIcon = document.getElementById('toggleIcon');
                    const additionalCards = document.getElementById('additionalCards');
                    
                    toggleBtn.addEventListener('click', function() {
                        // Toggle visibility
                        additionalCards.classList.toggle('hidden');
                        
                        // After a small delay to allow for the display property to change
                        setTimeout(() => {
                            // Toggle opacity and transform for smooth animation
                            additionalCards.classList.toggle('opacity-0');
                            additionalCards.classList.toggle('-translate-y-4');
                            
                            // Rotate icon
                            toggleIcon.classList.toggle('rotate-180');
                            
                            // Change button text based on state
                            const buttonText = toggleBtn.querySelector('span');
                            if (additionalCards.classList.contains('hidden')) {
                                buttonText.textContent = 'Show Additional Metrics & Inventory';
                            } else {
                                buttonText.textContent = 'Hide Additional Metrics & Inventory';
                            }
                        }, 10);
                    });
                });
            </script>
            </div>
        </div>
    </div>
</body>
</html>

<script>
    function toggleNotifications() {
        const dropdown = document.getElementById('notificationsDropdown');
        dropdown.classList.toggle('hidden');
        if (!dropdown.classList.contains('hidden')) {
            dropdown.classList.remove('opacity-0', 'scale-95');
            dropdown.classList.add('opacity-100', 'scale-100');
        } else {
            dropdown.classList.add('opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notificationsDropdown');
        const notificationIcon = event.target.closest('svg');
        if (!dropdown.contains(event.target) && !notificationIcon) {
            dropdown.classList.add('hidden', 'opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    });

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

    // Function to show order details in a modal with print option
    function showOrderDetails(orderId) {
        // Fetch order details
        fetch('fetch_order_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `order_id=${orderId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error loading order details: ' + data.error);
                return;
            }
            
            // Create modal
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.id = 'orderDetailsModal';
            modal.onclick = function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            };
            
            // Determine payment method
            let paymentMethod = 'cash';
            let paymentInfo = '';
            if (data.eft_payment) {
                paymentMethod = 'e-wallet';
                paymentInfo = `<p class="text-sm text-gray-600"><strong>Provider:</strong> ${data.eft_payment.wallet_provider || 'N/A'}</p>
                               <p class="text-sm text-gray-600"><strong>Transaction Ref:</strong> ${data.eft_payment.transaction_ref || 'N/A'}</p>`;
            } else if (data.mixed_payment) {
                paymentMethod = 'mixed';
                paymentInfo = `<p class="text-sm text-gray-600"><strong>Cash:</strong> N$${parseFloat(data.mixed_payment.cash_amount || 0).toFixed(2)}</p>
                               <p class="text-sm text-gray-600"><strong>EFT:</strong> N$${parseFloat(data.mixed_payment.eft_amount || 0).toFixed(2)}</p>
                               <p class="text-sm text-gray-600"><strong>Provider:</strong> ${data.mixed_payment.eft_wallet_provider || 'N/A'}</p>`;
            } else {
                paymentInfo = `<p class="text-sm text-gray-600"><strong>Cash Received:</strong> N$${parseFloat(data.order.cash_received || 0).toFixed(2)}</p>`;
            }
            
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-hidden" onclick="event.stopPropagation()">
                    <div class="flex items-center justify-between p-6 border-b border-gray-200 bg-gray-50">
                        <h3 class="text-xl font-bold text-gray-800">Order Details #${orderId}</h3>
                        <button onclick="document.getElementById('orderDetailsModal').remove()" class="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="p-6 overflow-y-auto max-h-[calc(90vh-12rem)]">
                        <div class="mb-6">
                            <h4 class="text-lg font-semibold text-gray-700 mb-4">Order Information</h4>
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <p class="text-sm text-gray-500">Order ID</p>
                                    <p class="text-base font-medium text-gray-800">#${orderId}</p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Date</p>
                                    <p class="text-base font-medium text-gray-800">${new Date(data.order.created_at).toLocaleString()}</p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Cashier</p>
                                    <p class="text-base font-medium text-gray-800">${data.order.cashier_id || 'Unknown'}</p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Total</p>
                                    <p class="text-base font-bold text-teal-600">N$${parseFloat(data.order.total || 0).toFixed(2)}</p>
                                </div>
                            </div>
                            <div class="border-t border-gray-200 pt-4">
                                <h5 class="text-sm font-semibold text-gray-700 mb-2">Payment Method</h5>
                                <p class="text-sm font-medium text-gray-800 capitalize">${paymentMethod}</p>
                                ${paymentInfo}
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <h4 class="text-lg font-semibold text-gray-700 mb-4">Items</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        ${data.items.map(item => `
                                            <tr>
                                                <td class="px-4 py-3 text-sm text-gray-800">${item.name}</td>
                                                <td class="px-4 py-3 text-sm text-gray-600 text-right">${item.quantity}</td>
                                                <td class="px-4 py-3 text-sm text-gray-600 text-right">N$${parseFloat(item.price / item.quantity).toFixed(2)}</td>
                                                <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">N$${parseFloat(item.price).toFixed(2)}</td>
                                            </tr>
                                        `).join('')}
                                        <tr class="bg-gray-50">
                                            <td colspan="3" class="px-4 py-3 text-right text-sm font-bold text-gray-800">Total:</td>
                                            <td class="px-4 py-3 text-right text-sm font-bold text-teal-600">N$${parseFloat(data.order.total || 0).toFixed(2)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 p-6 border-t border-gray-200 bg-gray-50">
                        <button onclick="document.getElementById('orderDetailsModal').remove()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                            Close
                        </button>
                        <button onclick="printOrderReceipt(${orderId})" class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors flex items-center gap-2">
                            <i class="fas fa-print"></i>
                            Print Receipt
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
        })
        .catch(error => {
            console.error('Error fetching order details:', error);
            alert('Error loading order details: ' + error.message);
        });
    }

    // Function to print order receipt
    function printOrderReceipt(orderId) {
        // Show loading state
        const printBtn = event.target;
        const originalText = printBtn.innerHTML;
        printBtn.disabled = true;
        printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Printing...';

        // First fetch order details
        fetch('fetch_order_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `order_id=${orderId}`
        })
        .then(response => response.json())
        .then(orderData => {
            if (orderData.error) {
                throw new Error(orderData.error);
            }

            // Prepare receipt data
            const receiptData = {
                order_id: orderId,
                items: orderData.items.map(item => ({
                    name: item.name,
                    quantity: parseInt(item.quantity),
                    price: parseFloat(item.price)
                })),
                cashier_username: orderData.order.cashier_id || '<?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES); ?>',
                total: parseFloat(orderData.order.total || 0),
                cash_received: parseFloat(orderData.order.cash_received || 0),
                created_at: orderData.order.created_at,
                print_only: true
            };

            // Determine payment method and add relevant data
            if (orderData.eft_payment) {
                receiptData.payment_method = 'e-wallet';
                receiptData.transaction_ref = orderData.eft_payment.transaction_ref || '';
                receiptData.wallet_provider = orderData.eft_payment.wallet_provider || '';
            } else if (orderData.mixed_payment) {
                receiptData.payment_method = 'mixed';
                receiptData.cash_amount = parseFloat(orderData.mixed_payment.cash_amount || 0);
                receiptData.eft_amount = parseFloat(orderData.mixed_payment.eft_amount || 0);
                receiptData.eft_transaction_ref = orderData.mixed_payment.eft_transaction_ref || '';
                receiptData.eft_wallet_provider = orderData.mixed_payment.eft_wallet_provider || '';
            } else {
                receiptData.payment_method = 'cash';
            }

            // Send to receipt.php
            return fetch('../receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(receiptData)
            });
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                if (!text || text.trim() === '') {
                    throw new Error('Empty response from server');
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid JSON response from server');
                }
            });
        })
        .then(result => {
            if (result.success) {
                alert('Receipt printed successfully!');
            } else {
                alert('Error printing receipt: ' + (result.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Print error:', error);
            alert('Error printing receipt: ' + error.message);
        })
        .finally(() => {
            printBtn.disabled = false;
            printBtn.innerHTML = originalText;
        });
    }
</script>

