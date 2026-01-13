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

// Check activation status
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Get business closing time from business_info
$businessInfo = [];
$closingTime = '00:00'; // Default
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($businessInfo && isset($businessInfo['closing_time'])) {
        $closingTime = $businessInfo['closing_time'];
    }
} catch (PDOException $e) {
    error_log('Business info DB error: ' . $e->getMessage());
    // Continue with default closing time
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
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

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    try {
        $db = new PDO('sqlite:../pos.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get parameters
        $view = $_GET['view'] ?? 'daily';
        $startDate = $_GET['start_date'] ?? date('Y-m-d');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        // Use business closing time from global scope
        global $closingTime, $isAfterMidnight;
        
        // Function definitions with business closing time logic
        function getCashSalesAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
            try {
                $orderWhereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                $paymentWhereClause = BusinessDayCache::getWhereClause('payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
                
                $stmt = $db->prepare("
                    SELECT 
                        (SELECT COALESCE(SUM(total), 0) 
                         FROM orders 
                         WHERE ($orderWhereClause))
                        +
                        (SELECT COALESCE(SUM(amount), 0) 
                         FROM payments 
                         WHERE ($paymentWhereClause))
                ");
                $stmt->execute();
                return $stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Error in getCashSalesAjax: " . $e->getMessage());
                return 0;
            }
        }
        
        function getCreditSalesAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
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
                error_log("Error in getCreditSalesAjax: " . $e->getMessage());
                return 0;
            }
        }
        
        function getCostOfGoodsSoldAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
            try {
                $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                
                $stmt = $db->prepare("
                    SELECT 
                        (SELECT COALESCE(SUM(oi.quantity * p.buying_price), 0)
                         FROM order_items oi
                         JOIN orders o ON oi.order_id = o.id
                         JOIN products p ON oi.product_name = p.name
                         WHERE ($orderWhereClause))
                        +
                        (SELECT COALESCE(SUM(csi.quantity * p.buying_price), 0)
                         FROM credit_sale_items csi
                         JOIN credit_sales cs ON csi.sale_id = cs.id
                         JOIN products p ON csi.product_name = p.name
                         WHERE ($creditWhereClause))
                ");
                $stmt->execute();
                return $stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Error in getCostOfGoodsSoldAjax: " . $e->getMessage());
                return 0;
            }
        }
        
        function getTopSellingProductsAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight, $limit = 1000000000) {
            try {
                $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                
                $stmt = $db->prepare("
                    SELECT 
                        combined.product_name, 
                        SUM(combined.quantity) as total_quantity,
                        COALESCE(p.price, 0) as unit_price,
                        COALESCE(p.buying_price, 0) as unit_cost
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
                    LEFT JOIN products p ON combined.product_name = p.name
                    GROUP BY combined.product_name
                    ORDER BY total_quantity DESC
                    LIMIT $limit
                ");
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error in getTopSellingProductsAjax: " . $e->getMessage());
                return [];
            }
        }
        
        function getTotalCashInAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
            try {
                $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in' AND ($whereClause)");
                $stmt->execute();
                return $stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Error in getTotalCashInAjax: " . $e->getMessage());
                return 0;
            }
        }
        
        function getTotalCashOutAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
            try {
                $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
                $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND ($whereClause)");
                $stmt->execute();
                return $stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Error in getTotalCashOutAjax: " . $e->getMessage());
                return 0;
            }
        }
        
        $cashSales = getCashSalesAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditSales = getCreditSalesAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $costOfGoodsSold = getCostOfGoodsSoldAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $totalRevenue = $cashSales + $creditSales;
        $grossProfit = $totalRevenue - $costOfGoodsSold;
        $topSellingProducts = getTopSellingProductsAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $topProductsForChart = getTopSellingProductsAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight, 10);
        $totalCashIn = getTotalCashInAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $totalCashOut = getTotalCashOutAjax($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $netProfit = $grossProfit + $totalCashIn - $totalCashOut;
        
        // Date display
        switch ($view) {
            case 'daily':
                $dateDisplay = date('l, F j, Y', strtotime($startDate));
                break;
            case 'weekly':
                $dateDisplay = date('F j', strtotime($startDate)) . ' - ' . date('F j, Y', strtotime($endDate));
                break;
            case 'monthly':
                $dateDisplay = date('F Y', strtotime($startDate));
                break;
            default:
                $dateDisplay = ($startDate === $endDate) ? $startDate : "$startDate to $endDate";
        }
        
        $grossMarginPct = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
        $netMarginPct = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;
        
        // Get inventory data
        $openingInventory = 0;
        $purchases = 0;
        $closingInventory = 0;
        try {
            $stmt = $db->prepare("SELECT SUM(opening_quantity) as opening, SUM(received_quantity) as purchases, SUM(closing_quantity) as closing FROM daily_stock_summary WHERE date BETWEEN :start AND :end");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $openingInventory = $row['opening'] ?? 0;
            $purchases = $row['purchases'] ?? 0;
            $closingInventory = $row['closing'] ?? 0;
        } catch (Exception $e) {
            // Leave as 0
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'totalRevenue' => $totalRevenue,
                'grossProfit' => $grossProfit,
                'netProfit' => $netProfit,
                'grossMarginPct' => $grossMarginPct,
                'netMarginPct' => $netMarginPct,
                'dateDisplay' => $dateDisplay,
                'topProducts' => $topSellingProducts,
                'topProductsForChart' => $topProductsForChart,
                'costOfGoodsSold' => $costOfGoodsSold,
                'openingInventory' => $openingInventory,
                'purchases' => $purchases,
                'closingInventory' => $closingInventory,
                'totalCashOut' => $totalCashOut
            ]
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
            <?php
// Database connection with error handling
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Function to get all dates with data
function getDatesWithData($db) {
    try {
        $stmt = $db->query("
            SELECT DISTINCT DATE(created_at) as data_date 
            FROM (
                SELECT created_at FROM orders
                UNION ALL
                SELECT created_at FROM credit_sales
                UNION ALL
                SELECT payment_date as created_at FROM payments
                UNION ALL
                SELECT created_at FROM cash_transactions
            )
            ORDER BY data_date DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error in getDatesWithData: " . $e->getMessage());
        return [date('Y-m-d')];
    }
}

// Function to get all weeks with data
function getWeeksWithData($db) {
    try {
        $stmt = $db->query("
            SELECT DISTINCT strftime('%Y-W%W', created_at) as week_key,
                   strftime('%Y', created_at) as year,
                   strftime('%W', created_at) as week
            FROM (
                SELECT created_at FROM orders
                UNION ALL
                SELECT created_at FROM credit_sales
                UNION ALL
                SELECT payment_date as created_at FROM payments
                UNION ALL
                SELECT created_at FROM cash_transactions
            )
            ORDER BY week_key DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getWeeksWithData: " . $e->getMessage());
        $dt = new DateTime();
        return [['week_key' => $dt->format('o-\WW'), 'year' => $dt->format('Y'), 'week' => $dt->format('W')]];
    }
}

// Function to get all months with data
function getMonthsWithData($db) {
    try {
        $stmt = $db->query("
            SELECT DISTINCT strftime('%Y-%m', created_at) as month_key
            FROM (
                SELECT created_at FROM orders
                UNION ALL
                SELECT created_at FROM credit_sales
                UNION ALL
                SELECT payment_date as created_at FROM payments
                UNION ALL
                SELECT created_at FROM cash_transactions
            )
            ORDER BY month_key DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error in getMonthsWithData: " . $e->getMessage());
        return [date('Y-m')];
    }
}

// Get available dates, weeks, and months
$availableDates = getDatesWithData($db);
$availableWeeks = getWeeksWithData($db);
$availableMonths = getMonthsWithData($db);

// Handle view selection (daily, weekly, monthly) and period picking
$view = isset($_GET['view']) ? $_GET['view'] : 'daily';

// Default period based on current time (or first available data)
switch ($view) {
    case 'weekly':
        if (!empty($availableWeeks)) {
            $firstWeek = $availableWeeks[0];
            $dt = new DateTime();
            $dt->setISODate($firstWeek['year'], $firstWeek['week']);
            $startDate = $dt->format('Y-m-d');
            $dt->modify('+6 days');
            $endDate = $dt->format('Y-m-d');
        } else {
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate = date('Y-m-d', strtotime('sunday this week'));
        }
        break;
    case 'monthly':
        if (!empty($availableMonths)) {
            $ts = strtotime($availableMonths[0] . '-01');
            $startDate = date('Y-m-01', $ts);
            $endDate = date('Y-m-t', $ts);
        } else {
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
        }
        break;
    case 'daily':
    default:
        if (!empty($availableDates)) {
            $startDate = $availableDates[0];
            $endDate = $availableDates[0];
        } else {
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d');
        }
        break;
}

// Override defaults if a specific period is posted
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Check if view type is explicitly set
    if (!empty($_POST['view'])) {
        $view = $_POST['view'];
    }
    
    if (!empty($_POST['selected_date'])) {
        $selectedDate = $_POST['selected_date'];
        $startDate = $selectedDate;
        $endDate = $selectedDate;
        $view = 'daily';
    }
    if (!empty($_POST['selected_week'])) {
        $selectedWeek = $_POST['selected_week']; // format YYYY-W##
        $year = 0; $week = 0;
        if (sscanf($selectedWeek, "%d-W%d", $year, $week) === 2) {
            $dt = new DateTime();
            $dt->setISODate($year, $week);
            $startDate = $dt->format('Y-m-d');
            $dt->modify('+6 days');
            $endDate = $dt->format('Y-m-d');
            $view = 'weekly';
        }
    }
    if (!empty($_POST['weekly_start_date'])) {
        // Convert selected date to week (Monday to Sunday)
        $selectedDate = $_POST['weekly_start_date'];
        $dt = new DateTime($selectedDate);
        // Get Monday of the week
        $dayOfWeek = $dt->format('w'); // 0 (Sunday) to 6 (Saturday)
        $daysToMonday = ($dayOfWeek == 0) ? 6 : $dayOfWeek - 1; // Convert Sunday (0) to 6 days back
        $dt->modify('-' . $daysToMonday . ' days');
        $startDate = $dt->format('Y-m-d');
        $dt->modify('+6 days');
        $endDate = $dt->format('Y-m-d');
        $view = 'weekly';
    }
    if (!empty($_POST['selected_month'])) {
        $selectedMonth = $_POST['selected_month']; // format YYYY-MM
        $ts = strtotime($selectedMonth . '-01');
        if ($ts) {
            $startDate = date('Y-m-01', $ts);
            $endDate = date('Y-m-t', $ts);
            $view = 'monthly';
        }
    }
}

// Provide default input values
$selectedDate = isset($selectedDate) ? $selectedDate : $startDate;
$selectedMonthValue = date('Y-m', strtotime($startDate));
$dtForWeek = new DateTime($startDate);
$selectedWeekValue = $dtForWeek->format('o-\WW');

// Function to get total sales (with business closing time logic)
function getCashSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $paymentWhereClause = BusinessDayCache::getWhereClause('payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(total), 0) 
                 FROM orders 
                 WHERE ($orderWhereClause))
                +
                (SELECT COALESCE(SUM(amount), 0) 
                 FROM payments 
                 WHERE ($paymentWhereClause))
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales (with business closing time logic)
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

// Function to get total cost of goods sold (with business closing time logic)
function getCostOfGoodsSold($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(oi.quantity * p.buying_price), 0)
                 FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 JOIN products p ON oi.product_name = p.name
                 WHERE ($orderWhereClause))
                +
                (SELECT COALESCE(SUM(csi.quantity * p.buying_price), 0)
                 FROM credit_sale_items csi
                 JOIN credit_sales cs ON csi.sale_id = cs.id
                 JOIN products p ON csi.product_name = p.name
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

// Function to get top-selling products (with business closing time logic)
function getTopSellingProducts($db, $startDate, $endDate, $closingTime, $isAfterMidnight, $limit = 1000000000) {
    try {
        $orderWhereClause = BusinessDayCache::getWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = BusinessDayCache::getWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                combined.product_name, 
                SUM(combined.quantity) as total_quantity,
                COALESCE(p.price, 0) as unit_price,
                COALESCE(p.buying_price, 0) as unit_cost
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
            LEFT JOIN products p ON combined.product_name = p.name
            GROUP BY combined.product_name
            ORDER BY total_quantity DESC
            LIMIT $limit
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getTopSellingProducts: " . $e->getMessage());
        return [];
    }
}

// Function to get total cash in (with business closing time logic)
function getTotalCashIn($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in' AND ($whereClause)");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashIn: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cash out (with business closing time logic)
function getTotalCashOut($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = BusinessDayCache::getWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND ($whereClause)");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashOut: " . $e->getMessage());
        return 0;
    }
}

// Function to calculate net profit
function calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut) {
    return $grossProfit + $totalCashIn - $totalCashOut;
}

// Retrieve values (using business closing time logic)
$cashSales = getCashSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
$creditSales = getCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
$costOfGoodsSold = getCostOfGoodsSold($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
$totalRevenue = $cashSales + $creditSales;
$grossProfit = $totalRevenue - $costOfGoodsSold;
$topSellingProducts = getTopSellingProducts($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
$topProductsForChart = getTopSellingProducts($db, $startDate, $endDate, $closingTime, $isAfterMidnight, 10);
$totalCashIn = getTotalCashIn($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
$totalCashOut = getTotalCashOut($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
$netProfit = calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut);

// Determine date display format based on view
switch ($view) {
    case 'daily':
        $dateDisplay = date('l, F j, Y', strtotime($startDate));
        break;
    case 'weekly':
        $dateDisplay = date('F j', strtotime($startDate)) . ' - ' . date('F j, Y', strtotime($endDate));
        break;
    case 'monthly':
        $dateDisplay = date('F Y', strtotime($startDate));
        break;
    default:
        $dateDisplay = ($startDate === $endDate) ? $startDate : "$startDate to $endDate";
        break;
}

// --- Fetch Opening, Purchases, and Closing Inventory for the period ---
$openingInventory = 0;
$purchases = 0;
$closingInventory = 0;

try {
    $stmt = $db->prepare("SELECT SUM(opening_quantity) as opening, SUM(received_quantity) as purchases, SUM(closing_quantity) as closing FROM daily_stock_summary WHERE date BETWEEN :start AND :end");
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $openingInventory = $row['opening'] ?? 0;
    $purchases = $row['purchases'] ?? 0;
    $closingInventory = $row['closing'] ?? 0;
} catch (Exception $e) {
    // Leave as 0 if error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit and Loss Statement</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">    
    <script src="../src/chart.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">

    <style>
        /* Table styles */
        .table-fixed {
            table-layout: fixed;
            width: 100%;
        }

        .table-fixed th,
        .table-fixed td {
            white-space: nowrap;
            overflow: hidden;
        }

        .table-fixed th:first-child,
        .table-fixed td:first-child {
            width: 40%;
        }

        .table-fixed th:nth-child(2),
        .table-fixed td:nth-child(2) {
            width: 30%;
        }

        .table-fixed th:nth-child(3),
        .table-fixed td:nth-child(3) {
            width: 30%;
        }

        /* Chart container styles */
        .chart-container {
            position: relative;
            width: 100%;
            height: 600px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            max-width: 600px;
            max-height: 600px;
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .chart-container {
                height: 500px;
            }
            .chart-wrapper {
                max-width: 500px;
                max-height: 500px;
            }
        }

        @media (max-width: 768px) {
            .chart-container {
                height: 400px;
            }
            .chart-wrapper {
                max-width: 400px;
                max-height: 400px;
            }
            .flex-container {
                flex-direction: column !important;
            }
            .chart-section {
                width: 100% !important;
                margin-bottom: 2rem;
            }
            .table-section {
                width: 100% !important;
                padding-left: 0 !important;
            }
        }

        @media (max-width: 640px) {
            .chart-container {
                height: 300px;
            }
            .chart-wrapper {
                max-width: 300px;
                max-height: 300px;
            }
        }

        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000; /* Highest - always accessible */
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
            z-index: 80; /* Below sidebar (10000) and hamburger (10000) */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Sidebar and content styles */
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
        
        /* Container styles */
        .container {
            max-width: 100vw; /* Ensure container does not exceed viewport width */
            padding: 0 1rem; /* Add some padding for better spacing */
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
    </style>
</head>

<body class="bg-gray-100 overflow-x-hidden">
    <div class="flex min-h-screen">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <!-- Fixed Header Row -->
            <div class="fixed top-0 left-0 lg:left-64 right-0 z-50 bg-gray-50 py-4 flex items-center justify-between gap-4 px-4 lg:px-8 shadow-sm">
                <div class="w-full max-w-7xl mx-auto flex items-center justify-between gap-4 px-4 lg:px-8">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 rounded" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold">Sales Overview</h1>
                    </div>
                </div>
            </div>
            <!-- Spacer for fixed header -->
            <div class="h-20 mb-4"></div>
            
            <div class="container mx-auto p-4 md:p-6">
                <!-- Date Selection Card -->

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 lg:gap-6 mb-8">
                    <?php 
                        $grossMarginPct = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0; 
                        $netMarginPct = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0; 
                    ?>
                    <div id="revenue-card" class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Revenue</p>
                                <h3 id="revenue-value" class="text-2xl font-bold text-gray-600">N$<?php echo number_format($totalRevenue, 2); ?></h3>
                            </div>
                            <div class="p-2 bg-gray-100 rounded-full">
                                <i class="fas fa-chart-line text-gray-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Total sales revenue</p>
                    </div>
                    <div id="gross-profit-card" class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Gross Profit</p>
                                <h3 id="gross-profit-value" class="text-2xl font-bold text-gray-600">N$<?php echo number_format($grossProfit, 2); ?></h3>
                                <div id="gross-margin-value" class="mt-1 inline-flex items-center text-xs font-medium text-gray-700 bg-gray-50 px-2 py-0.5 rounded-full">Margin <?php echo number_format($grossMarginPct, 1); ?>%</div>
                            </div>
                            <div class="p-2 bg-gray-100 rounded-full">
                                <i class="fas fa-arrow-up text-gray-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Profit before expenses</p>
                    </div>
                    <div id="net-profit-card" class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Net Profit</p>
                                <h3 id="net-profit-value" class="text-2xl font-bold <?php echo $netProfit >= 0 ? 'text-gray-600' : 'text-red-600'; ?>">N$<?php echo number_format($netProfit, 2); ?></h3>
                                <div id="net-margin-value" class="mt-1 inline-flex items-center text-xs font-medium text-gray-700 bg-gray-50 px-2 py-0.5 rounded-full">Margin <?php echo number_format($netMarginPct, 1); ?>%</div>
                            </div>
                            <div class="p-2 bg-gray-100 rounded-full">
                                <i class="fas fa-chart-line text-gray-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Profit after expenses</p>
                    </div>
                    <div id="period-card" class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 cursor-pointer">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-600">Period</p>
                                <h3 id="period-value" class="text-2xl font-bold text-gray-600 truncate"><?php echo htmlspecialchars($dateDisplay); ?></h3>
                            </div>
                            <div class="p-2 bg-gray-100 rounded-full flex-shrink-0 ml-2">
                                <i class="fas fa-calendar text-gray-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Selected time period</p>
                    </div>
                </div>


                <div class="bg-white rounded-lg shadow-lg p-4 lg:p-6 mb-8">
                    <form method="POST" id="dateSelectionForm">
                        <input type="hidden" name="view" id="view_type_input" value="<?php echo htmlspecialchars($view); ?>">
                        <!-- View Type Tabs -->
                        <div class="mb-6">
                            <div class="flex flex-wrap gap-2 mb-4">
                                <button type="button" onclick="switchView('daily')" id="tab-daily" class="view-tab px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 <?php echo $view === 'daily' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                    <i class="fas fa-calendar-day mr-2"></i>Daily
                                </button>
                                <button type="button" onclick="switchView('weekly')" id="tab-weekly" class="view-tab px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 <?php echo $view === 'weekly' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                    <i class="fas fa-calendar-week mr-2"></i>Weekly
                                </button>
                                <button type="button" onclick="switchView('monthly')" id="tab-monthly" class="view-tab px-3 py-2 text-sm font-medium rounded-md transition-all duration-200 <?php echo $view === 'monthly' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                    <i class="fas fa-calendar-alt mr-2"></i>Monthly
                                </button>
                            </div>
                        </div>

                        <!-- Daily View - Badge Grid -->
                        <div id="daily-view" class="view-content <?php echo $view === 'daily' ? '' : 'hidden'; ?>">
                            <div class="mb-4">
                                
                                
                                <?php
                                // Group dates by month/year
                                $datesByMonth = [];
                                foreach ($availableDates as $date) {
                                    $monthKey = date('Y-m', strtotime($date));
                                    if (!isset($datesByMonth[$monthKey])) {
                                        $datesByMonth[$monthKey] = [];
                                    }
                                    $datesByMonth[$monthKey][] = $date;
                                }
                                krsort($datesByMonth); // Most recent first
                                
                                $currentDateValue = $selectedDate;
                                $currentMonthKey = date('Y-m', strtotime($startDate));
                                ?>
                                
                                <!-- Month/Year Selector -->
                                <div class="mb-4 flex flex-wrap gap-2">
                                    <?php foreach ($datesByMonth as $monthKey => $dates): 
                                        $monthName = date('F Y', strtotime($monthKey . '-01'));
                                        $isCurrentMonth = ($monthKey === $currentMonthKey);
                                    ?>
                                        <button type="button" onclick="filterDailyByMonth('<?php echo $monthKey; ?>')" 
                                                class="daily-month-badge px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 <?php echo $isCurrentMonth ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"
                                                data-month="<?php echo $monthKey; ?>">
                                            <?php echo $monthName; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Date Badge Grid for Selected Month -->
                                <div class="mb-4">
                                    <div class="grid grid-cols-7 sm:grid-cols-10 md:grid-cols-14 lg:grid-cols-31 gap-1.5 md:gap-2" id="daily-dates-grid">
                                        <?php
                                        // Show dates for current month
                                        $datesToShow = isset($datesByMonth[$currentMonthKey]) ? $datesByMonth[$currentMonthKey] : [];
                                        rsort($datesToShow); // Most recent first
                                        
                                        foreach ($datesToShow as $date):
                                            $isSelected = ($date === $currentDateValue);
                                            $dayNumber = date('j', strtotime($date));
                                            $dayName = date('D', strtotime($date));
                                        ?>
                                            <button type="button" 
                                                    onclick="selectDate('<?php echo $date; ?>')" 
                                                    class="date-badge px-2 py-2 rounded-md text-xs font-medium transition-all duration-200 flex flex-col items-center justify-center gap-0.5 min-h-[50px] border border-gray-300 <?php 
                                                        echo $isSelected ? 'bg-gray-100 text-gray-700 shadow-md transform scale-105' : 
                                                           'bg-gray-100 text-gray-700 hover:bg-gray-50 hover:text-gray-700 hover:shadow-md'; 
                                                    ?>"
                                                    data-date="<?php echo $date; ?>"
                                                    data-month="<?php echo date('Y-m', strtotime($date)); ?>">
                                                <span class="text-[10px] opacity-75"><?php echo $dayName; ?></span>
                                                <span class="font-semibold text-sm"><?php echo $dayNumber; ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <!-- Store all dates data for JavaScript -->
                                <script>
                                    window.allDatesByMonth = <?php echo json_encode($datesByMonth); ?>;
                                    window.currentSelectedDate = '<?php echo $currentDateValue; ?>';
                                </script>
                                
                                <input type="hidden" name="selected_date" id="selected_date_value" value="<?php echo htmlspecialchars($currentDateValue); ?>">
                                <p class="mt-2 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>Click on a date badge to view sales data for that day
                                </p>
                            </div>
                        </div>

                        <!-- Weekly View - Badge Grid -->
                        <div id="weekly-view" class="view-content <?php echo $view === 'weekly' ? '' : 'hidden'; ?>">
                            <div class="mb-4">
                                
                                
                                <?php
                                // Calculate "this week" (current week)
                                $today = new DateTime();
                                $dayOfWeek = $today->format('w');
                                $daysToMonday = ($dayOfWeek == 0) ? 6 : $dayOfWeek - 1;
                                $thisWeekMonday = clone $today;
                                $thisWeekMonday->modify('-' . $daysToMonday . ' days');
                                $thisWeekYear = $thisWeekMonday->format('Y');
                                $thisWeekNumber = $thisWeekMonday->format('W');
                                $thisWeekValue = $thisWeekYear . '-W' . str_pad($thisWeekNumber, 2, '0', STR_PAD_LEFT);
                                
                                // Calculate current selected week value
                                $dtForCurrentWeek = new DateTime($startDate);
                                $dayOfWeek = $dtForCurrentWeek->format('w');
                                $daysToMonday = ($dayOfWeek == 0) ? 6 : $dayOfWeek - 1;
                                $dtForCurrentWeek->modify('-' . $daysToMonday . ' days');
                                $currentWeekStart = $dtForCurrentWeek->format('Y-m-d');
                                $year = $dtForCurrentWeek->format('Y');
                                $week = $dtForCurrentWeek->format('W');
                                $currentWeekValue = $year . '-W' . str_pad($week, 2, '0', STR_PAD_LEFT);
                                
                                // Function to get week label
                                function getWeekLabel($weekValue, $thisWeekValue, $thisWeekMonday) {
                                    if ($weekValue === $thisWeekValue) {
                                        return 'This Week';
                                    }
                                    
                                    // Parse the week value
                                    list($year, $weekNum) = explode('-W', $weekValue);
                                    $weekNum = intval($weekNum);
                                    
                                    // Create DateTime for the week's Monday
                                    $weekDate = new DateTime();
                                    $weekDate->setISODate(intval($year), $weekNum);
                                    
                                    // Calculate difference in weeks
                                    $diff = $thisWeekMonday->diff($weekDate);
                                    $daysDiff = $diff->days;
                                    
                                    // Determine if it's in the past or future
                                    if ($weekDate > $thisWeekMonday) {
                                        $daysDiff = -$daysDiff; // Negative for future
                                    }
                                    
                                    $weeksDiff = floor($daysDiff / 7);
                                    
                                    if ($weeksDiff == 0) {
                                        return 'This Week';
                                    } elseif ($weeksDiff == 1) {
                                        return 'Last Week';
                                    } elseif ($weeksDiff > 1) {
                                        return $weeksDiff . ' Weeks Ago';
                                    } elseif ($weeksDiff == -1) {
                                        return 'Next Week';
                                    } else {
                                        return abs($weeksDiff) . ' Weeks Ahead';
                                    }
                                }
                                
                                // Sort weeks by most recent first
                                usort($availableWeeks, function($a, $b) {
                                    if ($a['year'] != $b['year']) {
                                        return $b['year'] - $a['year'];
                                    }
                                    return $b['week'] - $a['week'];
                                });
                                ?>
                                
                                <!-- Week Badge Grid -->
                                <div class="mb-4">
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2 md:gap-3" id="weekly-weeks-grid">
                                        <?php
                                        // Show most recent 20 weeks
                                        $weeksToShow = array_slice($availableWeeks, 0, 20);
                                        
                                        foreach ($weeksToShow as $week):
                                            $weekValue = $week['week_key'];
                                            $dt = new DateTime();
                                            $dt->setISODate($week['year'], $week['week']);
                                            $weekStart = $dt->format('M j');
                                            $dt->modify('+6 days');
                                            $weekEnd = $dt->format('M j, Y');
                                            
                                            $isSelected = ($weekValue === $currentWeekValue);
                                            $weekLabel = getWeekLabel($weekValue, $thisWeekValue, $thisWeekMonday);
                                        ?>
                                            <button type="button" 
                                                    onclick="selectWeek('<?php echo $weekValue; ?>', '<?php echo $week['year']; ?>', '<?php echo $week['week']; ?>')" 
                                                    class="week-badge px-3 py-3 rounded-md text-xs md:text-sm font-medium transition-all duration-200 flex flex-col items-center justify-center gap-1 min-h-[70px] border border-gray-300 <?php 
                                                        echo $isSelected ? 'bg-gray-100 text-gray-700 shadow-md transform scale-105' : 
                                                           'bg-gray-100 text-gray-700 hover:bg-gray-50 hover:text-gray-700 hover:shadow-md'; 
                                                    ?>"
                                                    data-week="<?php echo $weekValue; ?>">
                                                <span class="font-semibold"><?php echo $weekLabel; ?></span>
                                                <span class="text-xs opacity-75"><?php echo $weekStart; ?> - <?php echo $weekEnd; ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="selected_week" id="selected_week_value" value="<?php echo htmlspecialchars($currentWeekValue); ?>">
                                <p class="mt-2 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>Click on a week badge to view sales data for that week
                                </p>
                            </div>
                        </div>

                        <!-- Monthly View - Badge Grid -->
                        <div id="monthly-view" class="view-content <?php echo $view === 'monthly' ? '' : 'hidden'; ?>">
                            <div class="mb-4">
                                
                                
                                <!-- Year Selector -->
                                <?php
                                // Get unique years from available months
                                $availableYears = [];
                                foreach ($availableMonths as $month) {
                                    $year = substr($month, 0, 4);
                                    if (!in_array($year, $availableYears)) {
                                        $availableYears[] = $year;
                                    }
                                }
                                rsort($availableYears); // Most recent first
                                $currentYear = date('Y', strtotime($startDate));
                                ?>
                                <div class="mb-4 flex flex-wrap gap-2">
                                    <?php foreach ($availableYears as $year): ?>
                                        <button type="button" onclick="filterByYear('<?php echo $year; ?>')" 
                                                class="year-badge px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 <?php echo $year == $currentYear ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"
                                                data-year="<?php echo $year; ?>">
                                            <?php echo $year; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Month Badge Grid -->
                                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-12 gap-2 md:gap-3">
                                    <?php
                                    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    $currentMonthValue = date('Y-m', strtotime($startDate));
                                    
                                    // Filter months by selected year
                                    $monthsForYear = array_filter($availableMonths, function($month) use ($currentYear) {
                                        return substr($month, 0, 4) == $currentYear;
                                    });
                                    
                                    // Show all 12 months, but disable ones without data
                                    for ($i = 1; $i <= 12; $i++):
                                        $monthKey = $currentYear . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
                                        $hasData = in_array($monthKey, $monthsForYear);
                                        $isSelected = ($monthKey === $currentMonthValue);
                                        $monthName = $monthNames[$i - 1];
                                    ?>
                                        <button type="button" 
                                                onclick="selectMonth('<?php echo $monthKey; ?>')" 
                                                class="month-badge px-3 py-2.5 rounded-md text-xs md:text-sm font-medium transition-all duration-200 flex flex-col items-center justify-center gap-1 min-h-[60px] border border-gray-300 <?php 
                                                    echo $isSelected ? 'bg-gray-100 text-gray-700 shadow-md transform scale-105' : 
                                                       ($hasData ? 'bg-gray-100 text-gray-700 hover:bg-gray-50 hover:text-gray-700 hover:shadow-md' : 'bg-gray-50 text-gray-400 cursor-not-allowed opacity-50'); 
                                                ?>"
                                                data-month="<?php echo $monthKey; ?>"
                                                <?php echo !$hasData ? 'disabled' : ''; ?>>
                                            <span class="font-semibold"><?php echo substr($monthName, 0, 3); ?></span>
                                            <span class="text-xs opacity-75"><?php echo $i; ?></span>
                                        </button>
                                    <?php endfor; ?>
                                </div>
                                
                                <input type="hidden" name="selected_month" id="selected_month_value" value="<?php echo htmlspecialchars($currentMonthValue); ?>">
                                <p class="mt-3 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>Click on a month badge to view sales data for that month
                                </p>
                            </div>
                        </div>

                        <!-- Action Buttons Row -->
                        <div class="mt-6 pt-6 border-t border-gray-200 flex flex-wrap gap-3 justify-between items-center">
                            <div class="flex flex-wrap gap-3">
                             
                            </div>
                            <button type="button" onclick="downloadPDF()" class="px-4 py-2 bg-gray-600 text-white rounded-md shadow-md hover:bg-gray-700 transition-all duration-200 font-medium flex items-center gap-2 text-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                Download PDF
                            </button>
                        </div>
                    </form>
                </div>
                
                
                <div class="bg-white rounded-lg shadow-lg p-4 lg:p-6 mb-8" style="min-height: 550px;">
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-4 gap-4">
                        <h2 class="text-xl lg:text-2xl font-bold text-gray-800">Top Selling Products</h2>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="updateView('daily', event); return false;" class="px-3 py-2 text-sm font-medium rounded-md <?php echo $view === 'daily' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700'; ?> hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" id="dailyBtn">Daily</button>
                            <button type="button" onclick="updateView('weekly', event); return false;" class="px-3 py-2 text-sm font-medium rounded-md <?php echo $view === 'weekly' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700'; ?> hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" id="weeklyBtn">Weekly</button>
                            <button type="button" onclick="updateView('monthly', event); return false;" class="px-3 py-2 text-sm font-medium rounded-md <?php echo $view === 'monthly' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-700'; ?> hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" id="monthlyBtn">Monthly</button>
                        </div>
                    </div>
                    <div class="flex flex-container">
                        <div class="w-1/2 chart-section">
                            <div class="chart-container">
                                <div class="chart-wrapper">
                                    <canvas id="topProductsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="w-1/2 pl-2 table-section">
                            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                                <div class="flex justify-between items-center px-6 py-4 border-b">
                                    <h3 class="text-lg font-semibold text-gray-800">Product Sales</h3>
                                    <div class="flex items-center space-x-4">
                                        <div class="relative">
                                            <input type="text" id="searchInput" placeholder="Search products..." class="block appearance-none w-full bg-white border border-gray-300 text-gray-700 py-2 px-4 pr-8 rounded leading-tight focus:outline-none focus:border-gray-500">
                                        </div>
                                    </div>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 table-fixed">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 sortable" data-sort="name" onclick="sortTable('name')">
                                                    Product <span id="nameSortArrow"></span>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 sortable" data-sort="quantity" onclick="sortTable('quantity')">
                                                    Quantity Sold <span id="quantitySortArrow"></span>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 sortable" data-sort="total_sales" onclick="sortTable('total_sales')">
                                                    Revenue <span id="totalSalesSortArrow"></span>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 sortable" data-sort="profit" onclick="sortTable('profit')">
                                                    Profit
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 sortable" data-sort="margin" onclick="sortTable('margin')">
                                                    Margin %
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($topSellingProducts as $product): 
                                                $revenue = ($product['total_quantity'] ?? 0) * ($product['unit_price'] ?? 0);
                                                $cost = ($product['total_quantity'] ?? 0) * ($product['unit_cost'] ?? 0);
                                                $profit = $revenue - $cost;
                                                $marginPct = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
                                            ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $product['product_name']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($product['total_quantity']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">N$<?php echo number_format($revenue, 2); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">N$<?php echo number_format($profit, 2); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($marginPct, 1); ?>%</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="px-6 py-4 border-t flex items-center justify-between">
                                    <div class="text-sm text-gray-500">
                                        Showing <span id="currentPage">1</span> of <span id="totalPages"><?php echo ceil(count($topSellingProducts) / 10); ?></span>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button id="prevPage" class="px-3 py-1 text-sm text-gray-700 bg-gray-100 rounded hover:bg-gray-200 disabled:opacity-50" disabled>
                                            Previous
                                        </button>
                                        <button id="nextPage" class="px-3 py-1 text-sm text-gray-700 bg-gray-100 rounded hover:bg-gray-200">
                                            Next
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-lg p-4 lg:p-6 mb-8">
                    <h2 class="text-xl lg:text-2xl font-bold text-gray-800 mb-4">Income Statement (<?php echo $dateDisplay; ?>)</h2>
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm border border-gray-200 rounded-lg bg-white">
                        <thead>
                            <tr><th colspan="2" class="text-lg text-left py-2 px-4 bg-gray-100 border-b font-bold">Income Statement</th></tr>
                        </thead>
                        <tbody>
                            <tr class="border-b">
                                <td class="py-2 px-4 font-medium">Revenue</td>
                                <td id="is-revenue" class="py-2 px-4 text-right font-semibold">N$<?php echo number_format($totalRevenue, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Less: Cost of Goods Sold</td>
                                <td id="is-cogs" class="py-2 px-4 text-right text-gray-600">N$<?php echo number_format($costOfGoodsSold, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Opening Inventory</td>
                                <td id="is-opening-inv" class="py-2 px-4 text-right text-gray-600">N$<?php echo number_format($openingInventory, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Add Purchases</td>
                                <td id="is-purchases" class="py-2 px-4 text-right text-gray-600">N$<?php echo number_format($purchases, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600 font-semibold">Subtotal</td>
                                <td id="is-subtotal" class="py-2 px-4 text-right text-gray-600 font-semibold">N$<?php echo number_format($openingInventory + $purchases, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Less: Closing Inventory</td>
                                <td id="is-closing-inv" class="py-2 px-4 text-right text-gray-600">N$<?php echo number_format($closingInventory, 2); ?></td>
                            </tr>
                            <tr class="border-b bg-gray-50 font-bold">
                                <td class="py-2 px-4">Gross Profit</td>
                                <td id="is-gross-profit" class="py-2 px-4 text-right">N$<?php echo number_format($grossProfit, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Gross Margin %</td>
                                <td id="is-gross-margin" class="py-2 px-4 text-right text-gray-600"><?php echo number_format($grossMarginPct, 1); ?>%</td>
                            </tr>
                            <tr><td colspan="2" class="py-2"></td></tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 font-medium">Less: Expenses</td>
                                <td class="py-2 px-4"></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Total Cash Out (Expenses)</td>
                                <td id="is-cash-out" class="py-2 px-4 text-right text-gray-600">N$<?php echo number_format($totalCashOut, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Wages</td>
                                <td class="py-2 px-4 text-right text-gray-600">-</td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Rent Expenses</td>
                                <td class="py-2 px-4 text-right text-gray-600">-</td>
                            </tr>
                            <tr class="border-b bg-gray-50 font-bold">
                                <td class="py-2 px-4">Net Profit</td>
                                <td id="is-net-profit" class="py-2 px-4 text-right">N$<?php echo number_format($netProfit, 2); ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 px-4 pl-8 text-gray-600">Net Margin %</td>
                                <td id="is-net-margin" class="py-2 px-4 text-right text-gray-600"><?php echo number_format($netMarginPct, 1); ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>

    <script>
        let chartInstance = null;

        function initializeChart() {
            var productNames = <?php echo json_encode(array_column($topProductsForChart, 'product_name')); ?>;
            var productQuantities = <?php echo json_encode(array_column($topProductsForChart, 'total_quantity')); ?>;
            
            // Destroy existing chart if it exists
            if (chartInstance) {
                chartInstance.destroy();
            }
            
            var ctx = document.getElementById('topProductsChart').getContext('2d');
            
            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: productNames,
                    datasets: [{
                        label: 'Quantity Sold',
                        data: productQuantities,
                        backgroundColor: [
                            '#2E86AB', '#F18F01', '#C73E1D', '#3A7D44', '#6B4E71',
                            '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFAD60',
                            '#9B4DCA', '#26A69A', '#EF5350', '#66BB6A', '#7E57C2'
                        ],
                        borderColor: [
                            '#1B5F7A', '#D17A01', '#A62E1A', '#2A5D35', '#5A3E5E',
                            '#E55A5A', '#3EBDB4', '#34A7C1', '#86BEA4', '#E59D50',
                            '#8B3DB7', '#1F958A', '#DF4240', '#56AB60', '#6E47B2'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Top 10 Selling Products (<?php echo addslashes($dateDisplay); ?>)',
                            font: {
                                size: 18
                            },
                            padding: {
                                top: 10,
                                bottom: 20
                            }
                        },
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.dataset.label || '';
                                    const value = context.parsed.y || 0;
                                    return `${label}: ${value.toLocaleString()}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Products'
                            },
                            ticks: {
                                maxRotation: 0,
                                minRotation: 0
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Quantity Sold'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        onComplete: function() {
                            console.log('Chart animation completed');
                        }
                    },
                    layout: {
                        padding: {
                            top: 10,
                            bottom: 10,
                            left: 10,
                            right: 10
                        }
                    }
                }
            });
        }

        function initializePageScripts() {
            setTimeout(function() {
                if (typeof Chart !== 'undefined') {
                    initializeChart();
                } else {
                    console.error('Chart.js is not loaded');
                }
            }, 50);
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            if (chartInstance) {
                chartInstance.resize();
            }
        });

        // Call initializePageScripts when the page loads
        document.addEventListener('DOMContentLoaded', initializePageScripts);

        // Reinitialize scripts after loading new content
        if (typeof reinitializeScripts === 'function') {
            reinitializeScripts();
        }

        // Function to update view (daily, weekly, monthly)
        function updateView(view, event) {
            // Prevent page scroll
            if (event) {
                event.preventDefault();
            }
            
            // Update button styles
            document.querySelectorAll('#dailyBtn, #weeklyBtn, #monthlyBtn').forEach(btn => {
                btn.classList.remove('bg-gray-100', 'text-gray-700');
                btn.classList.add('bg-gray-100', 'text-gray-700');
            });
            document.getElementById(view + 'Btn').classList.remove('bg-gray-100', 'text-gray-700');
            document.getElementById(view + 'Btn').classList.add('bg-gray-100', 'text-gray-700');

            // Redirect to the same page with the new view parameter
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('view', view);
            window.location.href = currentUrl.toString();
        }

        // Function to download PDF
        function downloadPDF() {
            const currentUrl = new URL(window.location);
            const view = currentUrl.searchParams.get('view') || 'daily';
            const startDate = '<?php echo $startDate; ?>';
            const endDate = '<?php echo $endDate; ?>';
            const dateDisplay = '<?php echo addslashes($dateDisplay); ?>';
            
            // Create download URL with parameters
            const downloadUrl = `net_profit_pdf.php?view=${view}&start_date=${startDate}&end_date=${endDate}&date_display=${encodeURIComponent(dateDisplay)}`;
            
            // Create a temporary link and trigger download
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = `Net_Profit_Report_${startDate}_to_${endDate}.pdf`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>

    <script>
        console.log("Product Names: ", <?php echo json_encode(array_column($topSellingProducts, 'product_name')); ?>);
        console.log("Product Quantities: ", <?php echo json_encode(array_column($topSellingProducts, 'total_quantity')); ?>);
    </script>

    <script>
        // Sorting and Search Logic
        let currentSort = { column: 'name', direction: 'asc' };
        const sortArrows = {
            asc: '↑',
            desc: '↓'
        };

        function sortTable(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }
            updateSortArrows();
            sortData(`${column}_${currentSort.direction}`);
        }

        function updateSortArrows() {
            document.getElementById('nameSortArrow').textContent = currentSort.column === 'name' ? sortArrows[currentSort.direction] : '';
            document.getElementById('quantitySortArrow').textContent = currentSort.column === 'quantity' ? sortArrows[currentSort.direction] : '';
            document.getElementById('totalSalesSortArrow').textContent = currentSort.column === 'total_sales' ? sortArrows[currentSort.direction] : '';
        }

        function filterTable(searchTerm) {
            if (!searchTerm) {
                sortedData = [...<?php echo json_encode($topSellingProducts); ?>];
                currentPage = 1;
                updateTable();
                return;
            }

            const filteredData = <?php echo json_encode($topSellingProducts); ?>.filter(product => {
                const nameMatch = product.product_name.toLowerCase().includes(searchTerm.toLowerCase());
                const quantityMatch = product.total_quantity.toString().includes(searchTerm);
                const revenue = (product.total_quantity * product.unit_price);
                const profit = (product.total_quantity * (product.unit_price - (product.unit_cost || 0)));
                const margin = revenue > 0 ? (profit / revenue) * 100 : 0;
                const revenueMatch = revenue.toFixed(2).includes(searchTerm);
                const profitMatch = profit.toFixed(2).includes(searchTerm);
                const marginMatch = margin.toFixed(1).includes(searchTerm);
                return nameMatch || quantityMatch || revenueMatch || profitMatch || marginMatch;
            });
            
            sortedData = filteredData;
            currentPage = 1;
            updateTable();
        }

        // Pagination and Sorting Logic
        const itemsPerPage = 5;
        let currentPage = 1;
        let sortedData = [...<?php echo json_encode($topSellingProducts); ?>];

        function updateTable() {
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageData = sortedData.slice(start, end);
            
            const tbody = document.querySelector('tbody');
            tbody.innerHTML = pageData.map(product => `
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${product.product_name}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">${Number(product.total_quantity).toLocaleString()}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">N$${(product.total_quantity * product.unit_price).toFixed(2)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">N$${(product.total_quantity * (product.unit_price - (product.unit_cost || 0))).toFixed(2)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">${((product.total_quantity * (product.unit_price - (product.unit_cost || 0))) / Math.max(product.total_quantity * product.unit_price, 1) * 100).toFixed(1)}%</td>
                </tr>
            `).join('');

            document.getElementById('currentPage').textContent = currentPage;
            document.getElementById('totalPages').textContent = Math.ceil(sortedData.length / itemsPerPage);
            document.getElementById('prevPage').disabled = currentPage === 1;
            document.getElementById('nextPage').disabled = currentPage === Math.ceil(sortedData.length / itemsPerPage);
        }

        function sortData(sortBy) {
            switch(sortBy) {
                case 'name_asc':
                    sortedData.sort((a, b) => a.product_name.localeCompare(b.product_name));
                    break;
                case 'name_desc':
                    sortedData.sort((a, b) => b.product_name.localeCompare(a.product_name));
                    break;
                case 'quantity_asc':
                    sortedData.sort((a, b) => a.total_quantity - b.total_quantity);
                    break;
                case 'quantity_desc':
                    sortedData.sort((a, b) => b.total_quantity - a.total_quantity);
                    break;
                case 'total_sales_asc':
                    sortedData.sort((a, b) => (a.total_quantity * a.unit_price) - (b.total_quantity * b.unit_price));
                    break;
                case 'total_sales_desc':
                    sortedData.sort((a, b) => (b.total_quantity * b.unit_price) - (a.total_quantity * a.unit_price));
                    break;
                case 'profit_asc':
                    sortedData.sort((a, b) => (a.total_quantity * (a.unit_price - (a.unit_cost || 0))) - (b.total_quantity * (b.unit_price - (b.unit_cost || 0))));
                    break;
                case 'profit_desc':
                    sortedData.sort((a, b) => (b.total_quantity * (b.unit_price - (b.unit_cost || 0))) - (a.total_quantity * (a.unit_price - (a.unit_cost || 0))));
                    break;
                case 'margin_asc':
                    sortedData.sort((a, b) => {
                        const aRev = a.total_quantity * a.unit_price;
                        const bRev = b.total_quantity * b.unit_price;
                        const aProfit = a.total_quantity * (a.unit_price - (a.unit_cost || 0));
                        const bProfit = b.total_quantity * (b.unit_price - (b.unit_cost || 0));
                        const aMargin = aRev > 0 ? (aProfit / aRev) : 0;
                        const bMargin = bRev > 0 ? (bProfit / bRev) : 0;
                        return aMargin - bMargin;
                    });
                    break;
                case 'margin_desc':
                    sortedData.sort((a, b) => {
                        const aRev = a.total_quantity * a.unit_price;
                        const bRev = b.total_quantity * b.unit_price;
                        const aProfit = a.total_quantity * (a.unit_price - (a.unit_cost || 0));
                        const bProfit = b.total_quantity * (b.unit_price - (b.unit_cost || 0));
                        const aMargin = aRev > 0 ? (aProfit / aRev) : 0;
                        const bMargin = bRev > 0 ? (bProfit / bRev) : 0;
                        return bMargin - aMargin;
                    });
                    break;
            }
            currentPage = 1;
            updateTable();
        }

        // Event Listeners
        document.getElementById('searchInput').addEventListener('input', (e) => {
            filterTable(e.target.value.trim());
        });

        document.getElementById('prevPage').addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                updateTable();
            }
        });

        document.getElementById('nextPage').addEventListener('click', () => {
            if (currentPage < Math.ceil(sortedData.length / itemsPerPage)) {
                currentPage++;
                updateTable();
            }
        });

        // Initial setup
        updateSortArrows();
        updateTable();
    </script>

    <script>
        // View switching functions
        function switchView(viewType) {
            // Update tab buttons
            document.querySelectorAll('.view-tab').forEach(tab => {
                tab.classList.remove('bg-gray-100', 'text-gray-700');
                tab.classList.add('bg-gray-100', 'text-gray-700');
            });
            
            const activeTab = document.getElementById('tab-' + viewType);
            if (activeTab) {
                activeTab.classList.remove('bg-gray-100', 'text-gray-700');
                activeTab.classList.add('bg-gray-100', 'text-gray-700');
            }
            
            // Hide all views
            document.querySelectorAll('.view-content').forEach(view => {
                view.classList.add('hidden');
            });
            
            // Show selected view
            const selectedView = document.getElementById(viewType + '-view');
            if (selectedView) {
                selectedView.classList.remove('hidden');
            }
            
            // Update hidden input to ensure view type is submitted
            const viewInput = document.getElementById('view_type_input');
            if (viewInput) {
                viewInput.value = viewType;
            }
        }

        // Month badge selection (with toggle/deselect support)
        function selectMonth(monthKey) {
            // Ensure we're on monthly view
            switchView('monthly');
            
            // Check if this month is already selected
            const currentMonthValue = document.getElementById('selected_month_value').value;
            const isCurrentlySelected = currentMonthValue === monthKey;
            
            if (isCurrentlySelected) {
                // Deselect - clear selection
                document.getElementById('selected_month_value').value = '';
                
                // Update badge styles - remove selection from all
                document.querySelectorAll('.month-badge').forEach(badge => {
                    if (!badge.disabled) {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                        badge.classList.add('bg-gray-100', 'text-gray-700');
                    }
                });
                
                // Don't update data when deselecting - keep current view
            } else {
                // Select new month
                document.getElementById('selected_month_value').value = monthKey;
                
                // Update badge styles
                document.querySelectorAll('.month-badge').forEach(badge => {
                    const badgeMonth = badge.getAttribute('data-month');
                    if (badgeMonth === monthKey) {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'bg-gray-50', 'text-gray-700');
                        badge.classList.add('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                    } else {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                        if (!badge.disabled) {
                            badge.classList.add('bg-gray-100', 'text-gray-700');
                        }
                    }
                });
                
                // Calculate start and end dates for the month
                const startDate = monthKey + '-01';
                const endDate = new Date(new Date(startDate).getFullYear(), new Date(startDate).getMonth() + 1, 0).toISOString().split('T')[0];
                
                // Update data via AJAX
                updateSalesData('monthly', startDate, endDate);
            }
        }

        // Year filter function
        function filterByYear(year) {
            // Update year badge styles
            document.querySelectorAll('.year-badge').forEach(badge => {
                const badgeYear = badge.getAttribute('data-year');
                if (badgeYear === year) {
                    badge.classList.remove('bg-gray-100', 'text-gray-700');
                    badge.classList.add('bg-gray-100', 'text-gray-700');
                } else {
                    badge.classList.remove('bg-gray-100', 'text-gray-700');
                    badge.classList.add('bg-gray-100', 'text-gray-700');
                }
            });
            
            // Update to first month of that year via AJAX
            const firstMonthOfYear = year + '-01';
            document.getElementById('selected_month_value').value = firstMonthOfYear;
            const startDate = firstMonthOfYear + '-01';
            const endDate = new Date(new Date(startDate).getFullYear(), new Date(startDate).getMonth() + 1, 0).toISOString().split('T')[0];
            updateSalesData('monthly', startDate, endDate);
        }

        // AJAX function to update data without page reload
        async function updateSalesData(view, startDate, endDate) {
            try {
                // Show loading state
                const cards = document.querySelectorAll('#revenue-card, #gross-profit-card, #net-profit-card, #period-card');
                cards.forEach(card => {
                    if (card) {
                        card.style.opacity = '0.6';
                        card.style.pointerEvents = 'none';
                    }
                });
                
                const url = `sales.php?ajax=1&view=${encodeURIComponent(view)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                const response = await fetch(url);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    
                    // Update cards
                    updateCard('revenue', data.totalRevenue);
                    updateCard('gross-profit', data.grossProfit, data.grossMarginPct);
                    updateCard('net-profit', data.netProfit, data.netMarginPct);
                    updateCard('period', data.dateDisplay);
                    
                    // Update income statement table
                    updateIncomeStatement(data);
                    
                    // Update products table and chart
                    updateProductsTable(data.topProducts);
                    updateChart(data.topProductsForChart, data.dateDisplay);
                    
                    // Update URL without reload
                    const newUrl = `sales.php?view=${encodeURIComponent(view)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                    window.history.pushState({view, startDate, endDate}, '', newUrl);
                } else {
                    throw new Error(result.error || 'Failed to load data');
                }
                
                // Remove loading state
                cards.forEach(card => {
                    if (card) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    }
                });
            } catch (error) {
                console.error('Error updating data:', error);
                // Remove loading state on error
                const cards = document.querySelectorAll('#revenue-card, #gross-profit-card, #net-profit-card, #period-card');
                cards.forEach(card => {
                    if (card) {
                        card.style.opacity = '1';
                        card.style.pointerEvents = 'auto';
                    }
                });
                // Show error message
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error Loading Data',
                        text: 'Failed to update sales data. Please try again.',
                        confirmButtonColor: '#3B82F6',
                    });
                } else {
                    alert('Error loading data. Please refresh the page.');
                }
            }
        }
        
        function updateCard(type, value, margin = null) {
            if (type === 'revenue') {
                const el = document.getElementById('revenue-value');
                if (el) el.textContent = `N$${Number(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            } else if (type === 'gross-profit') {
                const el = document.getElementById('gross-profit-value');
                if (el) el.textContent = `N$${Number(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                if (margin !== null) {
                    const marginEl = document.getElementById('gross-margin-value');
                    if (marginEl) marginEl.textContent = `Margin ${Number(margin).toFixed(1)}%`;
                }
            } else if (type === 'net-profit') {
                const el = document.getElementById('net-profit-value');
                if (el) el.textContent = `N$${Number(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                if (margin !== null) {
                    const marginEl = document.getElementById('net-margin-value');
                    if (marginEl) marginEl.textContent = `Margin ${Number(margin).toFixed(1)}%`;
                }
            } else if (type === 'period') {
                const el = document.getElementById('period-value');
                if (el) el.textContent = value;
            }
        }
        
        function updateIncomeStatement(data) {
            // Update income statement table values using IDs
            const formatCurrency = (value) => `N$${Number(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            
            const el = (id) => document.getElementById(id);
            
            if (el('is-revenue')) el('is-revenue').textContent = formatCurrency(data.totalRevenue);
            if (el('is-cogs')) el('is-cogs').textContent = formatCurrency(data.costOfGoodsSold);
            if (el('is-opening-inv')) el('is-opening-inv').textContent = formatCurrency(data.openingInventory);
            if (el('is-purchases')) el('is-purchases').textContent = formatCurrency(data.purchases);
            if (el('is-subtotal')) el('is-subtotal').textContent = formatCurrency(data.openingInventory + data.purchases);
            if (el('is-closing-inv')) el('is-closing-inv').textContent = formatCurrency(data.closingInventory);
            if (el('is-gross-profit')) el('is-gross-profit').textContent = formatCurrency(data.grossProfit);
            if (el('is-gross-margin')) el('is-gross-margin').textContent = `${Number(data.grossMarginPct).toFixed(1)}%`;
            if (el('is-cash-out')) el('is-cash-out').textContent = formatCurrency(data.totalCashOut);
            if (el('is-net-profit')) el('is-net-profit').textContent = formatCurrency(data.netProfit);
            if (el('is-net-margin')) el('is-net-margin').textContent = `${Number(data.netMarginPct).toFixed(1)}%`;
            
            // Update income statement title
            const titleEl = document.querySelector('h2.text-xl.font-semibold.mb-4');
            if (titleEl && titleEl.textContent.includes('Income Statement')) {
                titleEl.textContent = `Income Statement (${data.dateDisplay})`;
            }
        }
        
        function updateProductsTable(products) {
            sortedData = products;
            currentPage = 1;
            updateTable();
        }
        
        function updateChart(products, dateDisplay) {
            if (chartInstance) {
                chartInstance.destroy();
            }
            
            const productNames = products.map(p => p.product_name);
            const productQuantities = products.map(p => p.total_quantity);
            
            const ctx = document.getElementById('topProductsChart').getContext('2d');
            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: productNames,
                    datasets: [{
                        label: 'Quantity Sold',
                        data: productQuantities,
                        backgroundColor: [
                            '#2E86AB', '#F18F01', '#C73E1D', '#3A7D44', '#6B4E71',
                            '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFAD60'
                        ],
                        borderColor: [
                            '#1B5F7A', '#D17A01', '#A62E1A', '#2A5D35', '#5A3E5E',
                            '#E55A5A', '#3EBDB4', '#34A7C1', '#86BEA4', '#E59D50'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: `Top 10 Selling Products (${dateDisplay})`,
                            font: { size: 18 },
                            padding: { top: 10, bottom: 20 }
                        },
                        legend: { display: false }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Products' } },
                        y: { beginAtZero: true, title: { display: true, text: 'Quantity Sold' } }
                    }
                }
            });
        }
        
        // Daily badge selection (with toggle/deselect support)
        function selectDate(date) {
            // Ensure we're on daily view
            switchView('daily');
            
            // Check if this date is already selected
            const isCurrentlySelected = window.currentSelectedDate === date;
            
            if (isCurrentlySelected) {
                // Deselect - clear selection
                window.currentSelectedDate = '';
                document.getElementById('selected_date_value').value = '';
                
                // Update badge styles - remove selection from all
                document.querySelectorAll('.date-badge').forEach(badge => {
                    badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                    badge.classList.add('bg-gray-100', 'text-gray-700');
                });
                
                // Don't update data when deselecting - keep current view
            } else {
                // Select new date
                window.currentSelectedDate = date;
                document.getElementById('selected_date_value').value = date;
                
                // Update badge styles
                document.querySelectorAll('.date-badge').forEach(badge => {
                    const badgeDate = badge.getAttribute('data-date');
                    if (badgeDate === date) {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'bg-gray-50', 'text-gray-700');
                        badge.classList.add('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                    } else {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                        badge.classList.add('bg-gray-100', 'text-gray-700');
                    }
                });
                
                // Update data via AJAX
                updateSalesData('daily', date, date);
            }
        }

        // Filter daily dates by month
        function filterDailyByMonth(monthKey) {
            // Update month badge styles
            document.querySelectorAll('.daily-month-badge').forEach(badge => {
                const badgeMonth = badge.getAttribute('data-month');
                if (badgeMonth === monthKey) {
                    badge.classList.remove('bg-gray-100', 'text-gray-700');
                    badge.classList.add('bg-gray-100', 'text-gray-700');
                } else {
                    badge.classList.remove('bg-gray-100', 'text-gray-700');
                    badge.classList.add('bg-gray-100', 'text-gray-700');
                }
            });
            
            // Get dates for this month
            const datesForMonth = window.allDatesByMonth[monthKey] || [];
            
            // Update the date badge grid
            const datesGrid = document.getElementById('daily-dates-grid');
            if (datesGrid && datesForMonth.length > 0) {
                // Sort dates (most recent first)
                const sortedDates = [...datesForMonth].sort((a, b) => new Date(b) - new Date(a));
                
                // Clear existing badges
                datesGrid.innerHTML = '';
                
                // Create badges for all dates in this month
                sortedDates.forEach(date => {
                    const dateObj = new Date(date);
                    const dayNumber = dateObj.getDate();
                    const dayName = dateObj.toLocaleDateString('en-US', { weekday: 'short' });
                    const isSelected = (date === window.currentSelectedDate);
                    
                    const badge = document.createElement('button');
                    badge.type = 'button';
                    badge.onclick = () => selectDate(date);
                    badge.className = `date-badge px-2 py-2 rounded-md text-xs font-medium transition-all duration-200 flex flex-col items-center justify-center gap-0.5 min-h-[50px] border border-gray-300 ${
                        isSelected ? 'bg-gray-100 text-gray-700 shadow-md transform scale-105' : 
                        'bg-gray-100 text-gray-700 hover:bg-gray-50 hover:text-gray-700 hover:shadow-md'
                    }`;
                    badge.setAttribute('data-date', date);
                    badge.setAttribute('data-month', monthKey);
                    
                    badge.innerHTML = `
                        <span class="text-[10px] opacity-75">${dayName}</span>
                        <span class="font-semibold text-sm">${dayNumber}</span>
                    `;
                    
                    datesGrid.appendChild(badge);
                });
            }
            
            // Calculate start and end dates for the entire month
            const startDate = monthKey + '-01';
            const endDate = new Date(new Date(startDate).getFullYear(), new Date(startDate).getMonth() + 1, 0).toISOString().split('T')[0];
            
            // Clear individual date selection since we're showing month totals
            window.currentSelectedDate = '';
            document.getElementById('selected_date_value').value = '';
            
            // Remove selection from all date badges
            document.querySelectorAll('.date-badge').forEach(badge => {
                badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                badge.classList.add('bg-gray-100', 'text-gray-700');
            });
            
            // Update data via AJAX for the entire month
            updateSalesData('daily', startDate, endDate);
        }

        // Weekly badge selection (with toggle/deselect support)
        function selectWeek(weekValue, year, week) {
            // Ensure we're on weekly view
            switchView('weekly');
            
            // Check if this week is already selected
            const currentWeekValue = document.getElementById('selected_week_value').value;
            const isCurrentlySelected = currentWeekValue === weekValue;
            
            if (isCurrentlySelected) {
                // Deselect - clear selection
                document.getElementById('selected_week_value').value = '';
                
                // Update badge styles - remove selection from all
                document.querySelectorAll('.week-badge').forEach(badge => {
                    badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                    badge.classList.add('bg-gray-100', 'text-gray-700');
                });
                
                // Don't update data when deselecting - keep current view
            } else {
                // Select new week
                document.getElementById('selected_week_value').value = weekValue;
                
                // Update badge styles
                document.querySelectorAll('.week-badge').forEach(badge => {
                    const badgeWeek = badge.getAttribute('data-week');
                    if (badgeWeek === weekValue) {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'bg-gray-50', 'text-gray-700');
                        badge.classList.add('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                    } else {
                        badge.classList.remove('bg-gray-100', 'text-gray-700', 'shadow-md', 'scale-105');
                        badge.classList.add('bg-gray-100', 'text-gray-700');
                    }
                });
                
                // Calculate start and end dates for the week using ISO week
                const weekParts = weekValue.split('-W');
                const weekYear = parseInt(weekParts[0]);
                const weekNum = parseInt(weekParts[1]);
                
                // Get first day of year
                const jan4 = new Date(weekYear, 0, 4);
                const jan4Day = jan4.getDay() || 7; // Convert Sunday (0) to 7
                const daysToMonday = jan4Day - 1;
                const firstMonday = new Date(jan4);
                firstMonday.setDate(jan4.getDate() - daysToMonday);
                
                // Calculate the Monday of the target week
                const weekStartDate = new Date(firstMonday);
                weekStartDate.setDate(firstMonday.getDate() + (weekNum - 1) * 7);
                
                const startDate = weekStartDate.toISOString().split('T')[0];
                const endDate = new Date(weekStartDate.getTime() + 6 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                
                // Update data via AJAX
                updateSalesData('weekly', startDate, endDate);
            }
        }


        // Quick date selection functions
        function setToday() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('selected_date_value').value = today;
            switchView('daily');
            // Auto-submit to show today
            setTimeout(() => {
                document.getElementById('dateSelectionForm').submit();
            }, 100);
        }

        function setThisWeek() {
            const today = new Date();
            const dayOfWeek = today.getDay();
            const daysToMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
            const monday = new Date(today);
            monday.setDate(today.getDate() - daysToMonday);
            
            // Calculate week number
            const year = monday.getFullYear();
            const startOfYear = new Date(year, 0, 1);
            const days = Math.floor((monday - startOfYear) / (24 * 60 * 60 * 1000));
            const weekNumber = Math.ceil((days + startOfYear.getDay() + 1) / 7);
            const weekValue = year + '-W' + String(weekNumber).padStart(2, '0');
            
            document.getElementById('selected_week_value').value = weekValue;
            switchView('weekly');
            // Auto-submit to show this week
            setTimeout(() => {
                document.getElementById('dateSelectionForm').submit();
            }, 100);
        }

        function setThisMonth() {
            const today = new Date();
            const monthStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
            document.getElementById('selected_month_value').value = monthStr;
            switchView('monthly');
            // Auto-submit to show current month
            setTimeout(() => {
                document.getElementById('dateSelectionForm').submit();
            }, 100);
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