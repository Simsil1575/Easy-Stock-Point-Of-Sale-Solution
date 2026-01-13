<?php
// Start session to track conversation history
session_start();

// Initialize conversation history if it doesn't exist
if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}

// Connect to POS database
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to get total sales
function getCashSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(total), 0) 
                 FROM orders 
                 WHERE created_at BETWEEN :start_date AND :end_date)
                +
                (SELECT COALESCE(SUM(amount), 0) 
                 FROM payments 
                 WHERE payment_date BETWEEN :start_date AND :end_date)
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales
function getCreditSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0)
            FROM credit_sales 
            WHERE created_at BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCreditSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get cost of goods sold
function getCostOfGoodsSold($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(oi.quantity * p.buying_price), 0)
                 FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 JOIN products p ON oi.product_name = p.name
                 WHERE o.created_at BETWEEN :start_date AND :end_date)
                +
                (SELECT COALESCE(SUM(csi.quantity * p.buying_price), 0)
                 FROM credit_sale_items csi
                 JOIN credit_sales cs ON csi.sale_id = cs.id
                 JOIN products p ON csi.product_name = p.name
                 WHERE cs.created_at BETWEEN :start_date AND :end_date)
        ");
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
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

// Function to get total cash in
function getTotalCashIn($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in' AND created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashIn: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cash out
function getTotalCashOut($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
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

// Get all data from all tables
try {
    // Get list of all tables
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    
    $allData = [];
    foreach ($tables as $table) {
        $stmt = $db->query("SELECT * FROM $table");
        $allData[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Add daily sales report data
    $selectedDate = date('Y-m-d');
    
    // Get cash sales total
    $cashSalesTotal = getCashSales($db, $selectedDate, $selectedDate);
    $allData['daily_cash_sales'] = [['total' => $cashSalesTotal]];

    // Get credit sales data
    $creditSalesTotal = getCreditSales($db, $selectedDate, $selectedDate);
    $allData['daily_credit_sales'] = [['total_unpaid' => $creditSalesTotal]];

    // Calculate additional metrics
    $totalRevenue = getCashSales($db, date('Y-01-01'), date('Y-12-31')) + getCreditSales($db, date('Y-01-01'), date('Y-12-31'));
    $costOfGoodsSold = getCostOfGoodsSold($db, date('Y-01-01'), date('Y-12-31'));
    $grossProfit = calculateGrossProfit($totalRevenue, $costOfGoodsSold);
    $totalCashIn = getTotalCashIn($db, date('Y-01-01'), date('Y-12-31'));
    $totalCashOut = getTotalCashOut($db, date('Y-01-01'), date('Y-12-31'));
    $netProfit = calculateNetProfit($grossProfit, $totalCashIn, $totalCashOut);

    // Output the data being fed to the AI
    echo "<pre>";
    print_r($allData);
    echo "</pre>";

    // Output additional calculations
    echo "<h2>Calculated Metrics</h2>";
    echo "<pre>";
    echo "Total Revenue: N$" . number_format($totalRevenue, 2) . "\n";
    echo "Cost of Goods Sold: N$" . number_format($costOfGoodsSold, 2) . "\n";
    echo "Gross Profit: N$" . number_format($grossProfit, 2) . "\n";
    echo "Total Cash In: N$" . number_format($totalCashIn, 2) . "\n";
    echo "Total Cash Out: N$" . number_format($totalCashOut, 2) . "\n";
    echo "Net Profit: N$" . number_format($netProfit, 2) . "\n";
    echo "</pre>";

} catch (PDOException $e) {
    die("SQL query failed: " . $e->getMessage());
}
?>