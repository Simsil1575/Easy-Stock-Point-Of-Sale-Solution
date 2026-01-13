<?php
// Database connection
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Set default date range to current month
$startDate = date('Y-m-01'); 
$endDate = date('Y-m-t');

// Function to get total sales with error checking
function getTotalSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT SUM(total) FROM orders WHERE created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        $result = $stmt->fetchColumn();
        return ($result !== false && $result !== null) ? $result : 0;
    } catch (PDOException $e) {
        error_log("Error in getTotalSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales with error checking
function getCreditSales($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("SELECT SUM(credit_amount) FROM credit_book WHERE created_at BETWEEN :start_date AND :end_date");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        $result = $stmt->fetchColumn();
        return ($result !== false && $result !== null) ? $result : 0;
    } catch (PDOException $e) {
        error_log("Error in getCreditSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cost of goods sold with error checking
function getCostOfGoodsSold($db, $startDate, $endDate) {
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(oi.quantity * p.buying_price), 0)
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_name = p.name
            WHERE o.created_at BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59']);
        $result = $stmt->fetchColumn();
        return ($result !== false && $result !== null) ? $result : 0;
    } catch (PDOException $e) {
        error_log("Error in getCostOfGoodsSold: " . $e->getMessage());
        return 0;
    }
}

// Gross profit calculation function
function calculateGrossProfit($totalSales, $costOfGoodsSold) {
    return floatval($totalSales) - floatval($costOfGoodsSold);
}

// Function to get top selling products with error checking
function getTopSellingProducts($db, $startDate, $endDate, $limit = 5) {
    try {
        $stmt = $db->prepare("
            SELECT oi.product_name, COALESCE(SUM(oi.quantity), 0) as total_quantity
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.created_at BETWEEN :start_date AND :end_date
            GROUP BY oi.product_name
            ORDER BY total_quantity DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':start_date', $startDate . ' 00:00:00');
        $stmt->bindValue(':end_date', $endDate . ' 23:59:59');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getTopSellingProducts: " . $e->getMessage());
        return [];
    }
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug output
echo "<!-- Debug Info:\n";
echo "Start Date: " . $startDate . "\n";
echo "End Date: " . $endDate . "\n";
echo "-->\n";

$totalSales = getTotalSales($db, $startDate, $endDate);
$creditSales = getCreditSales($db, $startDate, $endDate);
$costOfGoodsSold = getCostOfGoodsSold($db, $startDate, $endDate);
$grossProfit = calculateGrossProfit($totalSales, $costOfGoodsSold);
$topSellingProducts = getTopSellingProducts($db, $startDate, $endDate);

// Debug output
echo "<!-- Values:\n";
echo "Total Sales: " . $totalSales . "\n";
echo "Credit Sales: " . $creditSales . "\n";
echo "Cost of Goods: " . $costOfGoodsSold . "\n";
echo "Gross Profit: " . $grossProfit . "\n";
echo "-->\n";
?>

<h1 class="text-3xl font-bold mb-6">Profit and Loss Statement</h1>
<table class="min-w-full bg-white">
    <thead>
        <tr>
            <th class="py-2">Description</th>
            <th class="py-2 text-right">Amount (N$)</th>
        </tr>
    </thead>
    <tbody>
        <tr class="border-b">
            <td class="py-2">Total Sales</td>
            <td class="py-2 text-right"><?php echo number_format($totalSales, 2); ?></td>
        </tr>
        <tr class="border-b">
            <td class="py-2">Credit Sales</td>
            <td class="py-2 text-right"><?php echo number_format($creditSales, 2); ?></td>
        </tr>
        <tr class="border-b">
            <td class="py-2">Cost of Goods Sold</td>
            <td class="py-2 text-right"><?php echo number_format($costOfGoodsSold, 2); ?></td>
        </tr>
        <tr class="border-b">
            <td class="py-2 font-bold">Gross Profit</td>
            <td class="py-2 text-right font-bold"><?php echo number_format($grossProfit, 2); ?></td>
        </tr>
    </tbody>
</table>