<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Database connection
try {
    $db = new PDO("sqlite:../pos.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Get period from request
$period = $_GET['period'] ?? 'all';

// Function to get date range based on period
function getDateRange($period) {
    $today = date('Y-m-d');
    
    switch ($period) {
        case 'all':
            // Return a very wide date range to include all records
            return ['start' => '1970-01-01', 'end' => '2099-12-31', 'no_filter' => true];
        case 'today':
            return ['start' => $today, 'end' => $today];
        case 'week':
            $startOfWeek = date('Y-m-d', strtotime('monday this week'));
            $endOfWeek = date('Y-m-d', strtotime('sunday this week'));
            return ['start' => $startOfWeek, 'end' => $endOfWeek];
        case 'month':
            $startOfMonth = date('Y-m-01');
            $endOfMonth = date('Y-m-t');
            return ['start' => $startOfMonth, 'end' => $endOfMonth];
        case 'year':
            $startOfYear = date('Y-01-01');
            $endOfYear = date('Y-12-31');
            return ['start' => $startOfYear, 'end' => $endOfYear];
        default:
            return ['start' => $today, 'end' => $today];
    }
}

// Function to get business day WHERE clause
function getBusinessDayWhereClause($dateField, $startDate, $endDate, $closingTime = '00:00', $isAfterMidnight = false) {
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
        
        return "(" . implode(") OR (", $whereClauses) . ")";
    }
}

try {
    $dateRange = getDateRange($period);
    $startDate = $dateRange['start'];
    $endDate = $dateRange['end'];
    $noFilter = isset($dateRange['no_filter']) && $dateRange['no_filter'] === true;
    
    // Get business closing time
    $closingTime = '00:00';
    $isAfterMidnight = false;
    try {
        $infoDb = new PDO("sqlite:../info.db");
        $businessInfo = $infoDb->query("SELECT closing_time FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($businessInfo) {
            $closingTime = $businessInfo['closing_time'] ?? '00:00';
            $closingHour = (int)substr($closingTime, 0, 2);
            $isAfterMidnight = $closingHour < 12;
        }
    } catch (PDOException $e) {
        // Use default values if info.db is not available
    }
    
    // Get all employees (cashiers)
    try {
        $employeesQuery = $db->query("SELECT DISTINCT cashier_id FROM (
            SELECT cashier_id FROM orders WHERE cashier_id IS NOT NULL AND cashier_id != ''
            UNION
            SELECT cashier_id FROM eft_payments WHERE cashier_id IS NOT NULL AND cashier_id != ''
            UNION
            SELECT cashier_id FROM credit_sales WHERE cashier_id IS NOT NULL AND cashier_id != ''
            UNION
            SELECT cashier_id FROM payments WHERE cashier_id IS NOT NULL AND cashier_id != ''
        ) ORDER BY cashier_id");
        $cashierIds = $employeesQuery->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // Fallback: try to get cashiers from each table individually
        $cashierIds = [];
        
        try {
            $result = $db->query("SELECT DISTINCT cashier_id FROM orders WHERE cashier_id IS NOT NULL AND cashier_id != ''");
            $cashierIds = array_merge($cashierIds, $result->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // Table might not exist or have cashier_id column
        }
        
        try {
            $result = $db->query("SELECT DISTINCT cashier_id FROM eft_payments WHERE cashier_id IS NOT NULL AND cashier_id != ''");
            $cashierIds = array_merge($cashierIds, $result->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // Table might not exist or have cashier_id column
        }
        
        try {
            $result = $db->query("SELECT DISTINCT cashier_id FROM credit_sales WHERE cashier_id IS NOT NULL AND cashier_id != ''");
            $cashierIds = array_merge($cashierIds, $result->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // Table might not exist or have cashier_id column
        }
        
        try {
            $result = $db->query("SELECT DISTINCT cashier_id FROM payments WHERE cashier_id IS NOT NULL AND cashier_id != ''");
            $cashierIds = array_merge($cashierIds, $result->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // Table might not exist or have cashier_id column
        }
        
        // Remove duplicates and empty values
        $cashierIds = array_unique(array_filter($cashierIds));
    }
    
    // Also get users from users table for additional info
    try {
        $usersQuery = $db->query("SELECT id, username, role, email FROM users WHERE role IN ('admin', 'manager', 'employee') ORDER BY username");
        $users = $usersQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $users = [];
    }
    
    $totalEmployees = count($cashierIds);
    $activeEmployees = 0;
    $employeeStats = [];
    $totalSales = 0;
    
    // If no cashiers found, return empty data
    if (empty($cashierIds)) {
        $dateRangeDisplay = $noFilter ? 'All Time' : "$startDate to $endDate";
        $response = [
            'success' => true,
            'period' => $period,
            'dateRange' => $dateRangeDisplay,
            'totalEmployees' => 0,
            'activeEmployees' => 0,
            'topPerformer' => null,
            'avgSalesPerEmployee' => 0,
            'totalSales' => 0,
            'employees' => []
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    foreach ($cashierIds as $cashierId) {
        if (empty($cashierId)) continue;
        
        // Get employee sales for the period using cashier_id
        // If noFilter is true (period = 'all'), don't filter by date
        if ($noFilter) {
            $whereClause = '1=1'; // Always true - no date filtering
            $paymentWhereClause = '1=1'; // Always true - no date filtering
        } else {
            $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
            $paymentWhereClause = getBusinessDayWhereClause('payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        }
        
        // Check if eft_payments table exists
        $eftTableExists = false;
        try {
            $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
        }
        
        // 1. Get CASH SALES (orders without EFT payments)
        $cashSalesData = ['cash_sales' => 0, 'cash_orders' => 0];
        try {
            if ($eftTableExists) {
                $cashSalesQuery = $db->prepare("
                    SELECT COALESCE(SUM(o.total), 0) as cash_sales, COUNT(o.id) as cash_orders
                    FROM orders o
                    LEFT JOIN eft_payments e ON o.id = e.order_id
                    WHERE o.cashier_id = :cashier_id AND e.order_id IS NULL AND ($whereClause)
                ");
            } else {
                $cashSalesQuery = $db->prepare("
                    SELECT COALESCE(SUM(total), 0) as cash_sales, COUNT(id) as cash_orders
                    FROM orders 
                    WHERE cashier_id = :cashier_id AND ($whereClause)
                ");
            }
            $cashSalesQuery->bindParam(':cashier_id', $cashierId);
            $cashSalesQuery->execute();
            $cashSalesData = $cashSalesQuery->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Use default values if query fails
        }
        
        // 2. Get EFT SALES
        $eftSales = 0;
        $eftOrders = 0;
        if ($eftTableExists) {
            try {
                $eftSalesQuery = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) as eft_sales, COUNT(id) as eft_orders
                    FROM eft_payments 
                    WHERE cashier_id = :cashier_id AND status = 'completed' AND ($paymentWhereClause)
                ");
                $eftSalesQuery->bindParam(':cashier_id', $cashierId);
                $eftSalesQuery->execute();
                $eftData = $eftSalesQuery->fetch(PDO::FETCH_ASSOC);
                $eftSales = floatval($eftData['eft_sales']);
                $eftOrders = intval($eftData['eft_orders']);
            } catch (PDOException $e) {
                // Use default values if query fails
            }
        }
        
        // 3. Get CREDIT SALES
        $creditSalesData = ['credit_sales' => 0, 'credit_orders' => 0];
        try {
            $creditSalesQuery = $db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as credit_sales, COUNT(id) as credit_orders
                FROM credit_sales 
                WHERE cashier_id = :cashier_id AND ($whereClause)
            ");
            $creditSalesQuery->bindParam(':cashier_id', $cashierId);
            $creditSalesQuery->execute();
            $creditSalesData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Use default values if query fails
        }
        
        // 4. Get CREDIT PAYMENTS (cash received from credit sales)
        $creditPaymentsData = ['credit_payments' => 0, 'payment_count' => 0];
        try {
            $creditPaymentsQuery = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as credit_payments, COUNT(id) as payment_count
                FROM payments 
                WHERE cashier_id = :cashier_id AND ($paymentWhereClause)
            ");
            $creditPaymentsQuery->bindParam(':cashier_id', $cashierId);
            $creditPaymentsQuery->execute();
            $creditPaymentsData = $creditPaymentsQuery->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Use default values if query fails
        }
        
        // Calculate totals
        $employeeCashSales = floatval($cashSalesData['cash_sales']);
        $employeeEftSales = $eftSales;
        $employeeCreditSales = floatval($creditSalesData['credit_sales']);
        $employeeCreditPayments = floatval($creditPaymentsData['credit_payments']);
        
        $employeeTotalSales = $employeeCashSales + $employeeEftSales + $employeeCreditSales + $employeeCreditPayments;
        $employeeTotalOrders = intval($cashSalesData['cash_orders']) + $eftOrders + intval($creditSalesData['credit_orders']) + intval($creditPaymentsData['payment_count']);
        $employeeAvgOrderValue = $employeeTotalOrders > 0 ? $employeeTotalSales / $employeeTotalOrders : 0;
        
        $totalSales += $employeeTotalSales;
        
        // Determine if employee is active (has made sales in the period)
        $isActive = $employeeTotalSales > 0;
        if ($isActive) {
            $activeEmployees++;
        }
        
        // Find user info if available
        $userInfo = null;
        foreach ($users as $user) {
            if ($user['username'] === $cashierId || $user['id'] == $cashierId) {
                $userInfo = $user;
                break;
            }
        }
        
        $employeeStats[] = [
            'id' => $cashierId,
            'name' => $cashierId,
            'email' => $userInfo ? $userInfo['email'] : '',
            'role' => $userInfo ? $userInfo['role'] : 'cashier',
            'totalSales' => $employeeTotalSales,
            'totalOrders' => $employeeTotalOrders,
            'avgOrderValue' => $employeeAvgOrderValue,
            'cashSales' => $employeeCashSales,
            'eftSales' => $employeeEftSales,
            'creditSales' => $employeeCreditSales,
            'creditPayments' => $employeeCreditPayments,
            'status' => $isActive ? 'active' : 'inactive'
        ];
    }
    
    // Sort employees by total sales (descending)
    usort($employeeStats, function($a, $b) {
        return $b['totalSales'] - $a['totalSales'];
    });
    
    // Get top performer
    $topPerformer = !empty($employeeStats) ? $employeeStats[0] : null;
    
    // Calculate average sales per employee
    $avgSalesPerEmployee = $totalEmployees > 0 ? $totalSales / $totalEmployees : 0;
    
    // Prepare response
    $dateRangeDisplay = $noFilter ? 'All Time' : "$startDate to $endDate";
    $response = [
        'success' => true,
        'period' => $period,
        'dateRange' => $dateRangeDisplay,
        'totalEmployees' => $totalEmployees,
        'activeEmployees' => $activeEmployees,
        'topPerformer' => $topPerformer,
        'avgSalesPerEmployee' => $avgSalesPerEmployee,
        'totalSales' => $totalSales,
        'employees' => $employeeStats
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?> 