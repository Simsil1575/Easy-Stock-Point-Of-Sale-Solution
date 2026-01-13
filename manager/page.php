<?php
// Check activation status
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Database connection with error handling
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Get total revenue from orders table
$stmt = $db->query("SELECT COALESCE(SUM(total), 0) FROM orders");
$totalRevenue = $stmt->fetchColumn();

// Get credit sales from credit_book table
$stmt = $db->query("SELECT COALESCE(SUM(credit_amount), 0) FROM credit_book");
$creditSales = $stmt->fetchColumn();

// Calculate cash sales by subtracting credit sales from total revenue
$cashSales = $totalRevenue - $creditSales;

// Get cost of goods sold by joining order_items and products tables
$stmt = $db->query("
    SELECT COALESCE(SUM(oi.quantity * p.buying_price), 0) 
    FROM order_items oi
    JOIN products p ON oi.product_name = p.name
");
$costOfGoodsSold = $stmt->fetchColumn();

// Calculate gross profit
$grossProfit = $totalRevenue - $costOfGoodsSold;

// Get expenses from cash_transactions table
$stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out'");
$expenses = $stmt->fetchColumn();

// Get total cash in from cash_transactions table
$stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in'");
$totalCashIn = $stmt->fetchColumn();

// Calculate net profit
$netProfit = $grossProfit - $expenses + $totalCashIn;

// Get monthly sales data for all months
$stmt = $db->query("
    WITH RECURSIVE months(month) AS (
        SELECT date('now', 'start of year')
        UNION ALL
        SELECT date(month, '+1 month')
        FROM months
        WHERE month < date('now', 'start of year', '+11 months')
    )
    SELECT 
        strftime('%Y-%m', months.month) as month,
        COALESCE(SUM(orders.total), 0) as monthly_sales
    FROM months
    LEFT JOIN orders ON strftime('%Y-%m', orders.created_at) = strftime('%Y-%m', months.month)
    GROUP BY strftime('%Y-%m', months.month)
    ORDER BY month ASC
");
$chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$salesData = [];

foreach ($chartData as $data) {
    $labels[] = date('F Y', strtotime($data['month']));
    $salesData[] = $data['monthly_sales'];
}

// Function to get total cash in for date range
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

// Function to get total cash out for date range
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
</head>

<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="fixed top-0 left-0 h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        
        <div class="flex-1 ml-64 p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-8">Dashboard Overview</h1>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Revenue Card -->
                <div class="bg-blue-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm">Total Revenue</p>
                            <h3 class="text-2xl font-bold">N$<?php echo number_format($totalRevenue, 2); ?></h3>
                        </div>
                        <div class="bg-blue-500 rounded-full p-3">
                            <i class="fas fa-dollar-sign text-xl"></i>
                        </div>
                    </div>
                    <p class="text-sm mt-4">Total Revenue</p>
                </div>

                <!-- Cash Sales Card -->
                <div class="bg-teal-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white text-sm">Cash Sales</p>
                            <h3 class="text-white text-2xl font-bold"><?php echo number_format($cashSales, 2); ?></h3>
                        </div>
                        <div class="bg-teal-500 rounded-full p-3">
                            <i class="fas fa-shopping-cart text-white text-xl"></i>
                        </div>
                    </div>
                    <p class="text-white text-sm mt-4">Cash Sales</p>
                </div>

                <!-- Credit Sales Card -->
                <div class="bg-red-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white text-sm">Credit Sales</p>
                            <h3 class="text-white text-2xl font-bold"><?php echo number_format($creditSales, 2); ?></h3>
                        </div>
                        <div class="bg-purple-500 rounded-full p-3">
                            <i class="fas fa-box text-white text-xl"></i>
                        </div>
                    </div>
                    <p class="text-white text-sm mt-4">Credit Sales</p>
                </div>

                <!-- Net Profit Card -->
                <div class="bg-yellow-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white text-sm">Net Profit</p>
                            <h3 class="text-white text-2xl font-bold"><?php echo number_format($netProfit, 2); ?></h3>
                        </div>
                        <div class="bg-gray-500 rounded-full p-3">
                            <i class="fas fa-chart-line text-white text-xl"></i>
                        </div>
                    </div>
                    <p class="text-white text-sm mt-4">Net Profit</p>
                </div>
            </div>

            <!-- Graphs Section -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-4">Monthly Sales Overview</h2>
                <canvas id="salesChart" class="w-full h-64"></canvas>
            </div>

            <script>
                // Chart setup with real data
                const ctx = document.getElementById('salesChart').getContext('2d');
                const salesChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($labels); ?>,
                        datasets: [{
                            label: 'Sales (N$)',
                            data: <?php echo json_encode($salesData); ?>,
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 2,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            x: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Month'
                                }
                            },
                            y: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Sales Amount (N$)'
                                }
                            }
                        }
                    }
                });
            </script>
        </div>
    </div>
</body>
</html>