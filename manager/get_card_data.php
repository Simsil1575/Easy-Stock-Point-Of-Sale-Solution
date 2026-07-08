<?php
// Database connection with error handling
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Connection failed: ' . $e->getMessage()]);
    exit;
}

// Get business closing time from business_info
$businessInfo = [];
$closingTime = '00:00';
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00';
} catch (PDOException $e) {
    $closingTime = '00:00';
}

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Function to get date range based on view (using business days)
function getDateRange($view, $closingTime, $isAfterMidnight) {
    $today = date('Y-m-d');
    
    switch($view) {
        case 'weekly':
            // Get Monday to Sunday of current week
            $dayOfWeek = date('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            
            if ($dayOfWeek == 0) {
                // If today is Sunday, use this week (Sunday to Saturday)
                $startDate = date('Y-m-d', strtotime('monday last week'));
                $endDate = date('Y-m-d'); // Today (Sunday)
            } else {
                // Monday to Sunday of this week
                $startDate = date('Y-m-d', strtotime('monday this week'));
                $endDate = date('Y-m-d', strtotime('sunday this week'));
            }
            break;
        case 'monthly':
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
            break;
        case 'daily':
        default:
            // Always return today's date for daily view
            $startDate = $today;
            $endDate = $today;
            break;
    }
    
    return [$startDate, $endDate];
}

// Function to build business day WHERE clause
function getBusinessDayWhereClause($dateField, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    // Simplified business day logic - if business closes after midnight, include early morning hours of next day
    if ($isAfterMidnight && $closingTime !== '00:00') {
        // Business day spans midnight
        if ($startDate === $endDate) {
            // Single day
            $nextDay = date('Y-m-d', strtotime($startDate . ' +1 day'));
            return "(DATE($dateField) = '$startDate' AND strftime('%H:%M', $dateField) >= '$closingTime') OR (DATE($dateField) = '$nextDay' AND strftime('%H:%M', $dateField) < '$closingTime')";
        } else {
            // Multiple days
            $endDatePlusOne = date('Y-m-d', strtotime($endDate . ' +1 day'));
            return "(DATE($dateField) >= '$startDate' AND DATE($dateField) <= '$endDate') OR (DATE($dateField) = '$endDatePlusOne' AND strftime('%H:%M', $dateField) < '$closingTime')";
        }
    } else {
        // Normal business day (closes before midnight or at midnight)
        return "DATE($dateField) >= '$startDate' AND DATE($dateField) <= '$endDate'";
    }
}

// Function to get total cash in for date range (using business days)
function getTotalCashIn($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
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

// Function to get total cash out for date range (using business days)
function getTotalCashOut($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
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

// Function to get total cash sales using business days
// This matches sales.php getCashSalesAjax exactly - includes ALL orders (not excluding EFT) + payments
function getCashSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $paymentWhereClause = getBusinessDayWhereClause('payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
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

// Function to get cash sales from orders only (excluding EFT and payments)
// Used for cashInTill calculation to avoid double-counting with credit payments
function getCashSalesFromOrders($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        // Check if eft_payments table exists
        $eftTableExists = false;
        try {
            $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
        }
        
        $orderWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        if ($eftTableExists) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(
                    o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
                ), 0)
                FROM orders o
                WHERE ($orderWhereClause)
            ");
        } else {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total), 0) 
                FROM orders o
                WHERE ($orderWhereClause)
            ");
        }
        
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSalesFromOrders: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales using business days
function getCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
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

// Function to get cost of goods sold using business days
function getCostOfGoodsSold($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
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

// Function to get unpaid credit sales using business days
function getUnpaidCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
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

// Function to get total EFT payments using business days (includes both direct EFT and credit EFT)
function getTotalEftPayments($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        // Check if eft_payments table exists
        $eftTableExists = false;
        try {
            $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
            $eftTableExists = ($checkEftTable->fetchColumn() !== false);
        } catch (PDOException $e) {
            $eftTableExists = false;
        }
        
        $eftDirectTotal = 0;
        $eftCreditTotal = 0;
        
        // Get direct EFT payments from orders
        if ($eftTableExists) {
            $orderWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(e.amount), 0)
                FROM eft_payments e
                JOIN orders o ON e.order_id = o.id
                WHERE ($orderWhereClause) AND e.status = 'completed'
            ");
            $stmt->execute();
            $eftDirectTotal = $stmt->fetchColumn();
        }
        
        // Get credit EFT payments (from payments table where payment_status is 'eft')
        $paymentWhereClause = getBusinessDayWhereClause('p.payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0)
            FROM payments p
            JOIN credit_sales cs ON p.sale_id = cs.id
            WHERE cs.payment_status = 'eft' AND ($paymentWhereClause)
        ");
        $stmt->execute();
        $eftCreditTotal = $stmt->fetchColumn();
        
        return ($eftDirectTotal ?: 0) + ($eftCreditTotal ?: 0);
    } catch (PDOException $e) {
        error_log("Error in getTotalEftPayments: " . $e->getMessage());
        return 0;
    }
}

// Function to get credit payments using business days (only paid credit sales)
function getCreditPayments($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('p.payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) 
            FROM payments p
            JOIN credit_sales cs ON p.sale_id = cs.id
            WHERE cs.payment_status = 'paid' AND ($whereClause)
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCreditPayments: " . $e->getMessage());
        return 0;
    }
}

// Function to get total inventory value (using buying price)
function getTotalInventoryValue($db) {
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(CAST(quantity AS DECIMAL(10,2)) * CAST(price AS DECIMAL(10,2))), 0) as total_value
            FROM products
            WHERE quantity > 0 AND price IS NOT NULL
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalInventoryValue: " . $e->getMessage());
        return 0;
    }
}

// Function to get total products count
function getTotalProducts($db) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as total_products FROM products");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalProducts: " . $e->getMessage());
        return 0;
    }
}

// Function to get products in stock count
function getProductsInStock($db) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as products_in_stock FROM products WHERE quantity > 0");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getProductsInStock: " . $e->getMessage());
        return 0;
    }
}

// Handle AJAX request
if (isset($_GET['view'])) {
    $view = $_GET['view'];
    
    try {
        list($startDate, $endDate) = getDateRange($view, $closingTime, $isAfterMidnight);
        
        // Get period-specific data for most cards (using business day logic)
        $totalCashIn = getTotalCashIn($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $totalCashOut = getTotalCashOut($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $cashSales = getCashSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $cashSalesFromOrders = getCashSalesFromOrders($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditSales = getCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $costOfGoodsSold = getCostOfGoodsSold($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $unpaidCreditSales = getUnpaidCreditSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $totalEftPayments = getTotalEftPayments($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        $totalCreditPayments = getCreditPayments($db, $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        // Get inventory metrics (these are current state, not period-specific)
        $totalInventoryValue = getTotalInventoryValue($db);
        $totalProducts = getTotalProducts($db);
        $productsInStock = getProductsInStock($db);
        
        // Calculate derived values (matching sales.php exactly)
        // Note: sales.php does NOT include EFT in totalRevenue, only cashSales + creditSales
        $totalRevenue = $cashSales + $creditSales;
        $grossProfit = $totalRevenue - $costOfGoodsSold;
        // Net Revenue should match Net Profit in sales.php: Gross Profit + Cash In - Cash Out
        $netRevenue = $grossProfit + $totalCashIn - $totalCashOut;
        
        // Cash in till calculation using period-specific data with businessClosingTime (matching cash.php)
        // Uses cashSalesFromOrders (orders minus EFT, no payments) to avoid double-counting with creditPayments
        // All components (totalCashIn, cashSalesFromOrders, totalCreditPayments, totalCashOut) use business day logic
        $cashInTill = $totalCashIn + $cashSalesFromOrders + $totalCreditPayments - $totalCashOut;
        
        // Total deposits calculation using period-specific data (matching home.php)
        $totalDeposits = $totalCashIn + $cashSalesFromOrders + $totalCreditPayments;
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'cashInTill' => round($cashInTill, 2),
            'totalDeposits' => round($totalDeposits, 2),
            'netRevenue' => round($netRevenue, 2),
            'unpaidCreditSales' => round($unpaidCreditSales, 2),
            'totalEftPayments' => round($totalEftPayments, 2),
            'totalCashOut' => round($totalCashOut, 2),
            'totalRevenue' => round($totalRevenue, 2),
            'grossProfit' => round($grossProfit, 2),
            'cashSales' => round($cashSales, 2),
            'creditSales' => round($creditSales, 2),
            'costOfGoodsSold' => round($costOfGoodsSold, 2),
            'totalInventoryValue' => round($totalInventoryValue, 2),
            'totalProducts' => intval($totalProducts),
            'productsInStock' => intval($productsInStock),
            'dateRange' => $startDate === $endDate ? $startDate : "$startDate to $endDate",
            'period' => $view,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'businessClosingTime' => $closingTime,
            'isAfterMidnight' => $isAfterMidnight
        ]);
    } catch (Exception $e) {
        // Return error response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Error calculating data: ' . $e->getMessage(),
            'view' => $view
        ]);
    }
} else {
    // Return error if no view parameter
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'No view parameter provided'
    ]);
}
?> 