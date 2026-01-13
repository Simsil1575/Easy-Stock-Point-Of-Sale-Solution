<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit and Loss Statement</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" href="favicon.ico" type="image/png">
</head>

<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="fixed top-0 left-0 h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content">
            <div class="container mx-auto p-6">

            <?php
                $db = new PDO('sqlite:pos.db');

                // Function to fetch data
                function fetchData($db, $query, $params = []) {
                    $stmt = $db->prepare($query);
                    $stmt->execute($params);
                    return $stmt->fetchColumn() ?: 0;
                }

                $startDate = date('Y-m-01');
                $endDate = date('Y-m-t');

                $totalSales = fetchData($db, "SELECT SUM(total) FROM orders WHERE created_at BETWEEN :start_date AND :end_date", [':start_date' => $startDate, ':end_date' => $endDate]);
                $creditCosts = fetchData($db, "SELECT SUM(credit_amount) FROM credit_book WHERE created_at BETWEEN :start_date AND :end_date", [':start_date' => $startDate, ':end_date' => $endDate]);
                $costOfGoodsSold = fetchData($db, "
                    SELECT SUM(oi.quantity * p.buying_price) 
                    FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    JOIN products p ON oi.product_name = p.name
                    WHERE o.created_at BETWEEN :start_date AND :end_date", [':start_date' => $startDate, ':end_date' => $endDate]);

                $grossProfit = $totalSales - $costOfGoodsSold;

                // Fetch top products
                $stmt = $db->prepare("
                    SELECT oi.product_name, SUM(oi.quantity) as total_quantity
                    FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    WHERE o.created_at BETWEEN :start_date AND :end_date
                    GROUP BY oi.product_name
                    ORDER BY total_quantity DESC
                    LIMIT 5
                ");
                $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
                $topSellingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <h1 class="text-3xl font-bold mb-6">Profit and Loss Statement</h1>
            
            <form method="POST" class="mb-8">
                <div class="flex space-x-4">
                    <div>
                        <label for="start_date" class="block mb-2">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo $startDate; ?>" class="border rounded px-2 py-1">
                    </div>
                    <div>
                        <label for="end_date" class="block mb-2">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo $endDate; ?>" class="border rounded px-2 py-1">
                    </div>
                    <div class="self-end">
                        <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Generate Report</button>
                    </div>
                </div>
            </form>

            <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                <h2 class="text-xl font-semibold mb-4">Top Selling Products</h2>
                <div class="flex">
                    <div class="w-1/2">
                        <canvas id="topProductsChart"></canvas>
                    </div>
                    <div class="w-1/2">
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="text-left">Product</th>
                                    <th class="text-right">Quantity Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSellingProducts as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                    <td class="text-right"><?php echo $product['total_quantity']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                <h2 class="text-xl font-semibold mb-4">Income Statement (<?php echo $startDate; ?> to <?php echo $endDate; ?>)</h2>
                <table class="w-full">
                    <tr class="border-b">
                        <td class="py-2">Revenue</td>
                        <td class="py-2 text-right">N$<?php echo number_format($totalSales, 2); ?></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 pl-8">Cash Sales</td>
                        <td class="py-2 text-right">N$<?php echo number_format($totalSales - $creditCosts, 2); ?></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 pl-8">Credit Sales</td>
                        <td class="py-2 text-right">N$<?php echo number_format($creditCosts, 2); ?></td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2">Cost of Revenue</td>
                        <td class="py-2 text-right">N$<?php echo number_format($costOfGoodsSold, 2); ?></td>
                    </tr>
                    <tr class="font-bold">
                        <td class="py-2">Gross Profit</td>
                        <td class="py-2 text-right">N$<?php echo number_format($grossProfit, 2); ?></td>
                    </tr>
                </table>
            </div>

            <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                <h2 class="text-xl font-semibold mb-4">Delete All Records</h2>
                <form method="POST" action="">
                    <button type="submit" name="delete_all" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                        Delete All Records
                    </button>
                </form>
            </div>

            <?php
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_all'])) {
                try {
                    $db->exec('PRAGMA foreign_keys = OFF');
                    $db->exec("DELETE FROM orders");
                    $db->exec("DELETE FROM order_items"); 
                    $db->exec('PRAGMA foreign_keys = ON');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1');
                    exit();
                } catch(PDOException $e) {
                    die("Connection failed: " . $e->getMessage());
                }
            }
            ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const productNames = <?php echo json_encode(array_column($topSellingProducts, 'product_name')); ?>;
            const productQuantities = <?php echo json_encode(array_column($topSellingProducts, 'total_quantity')); ?>;

            if (typeof Chart !== 'undefined') {
                new Chart(document.getElementById('topProductsChart').getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: productNames,
                        datasets: [{
                            data: productQuantities,
                            backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
                        }]
                    },
                    options: {
                        responsive: true,
                        title: {
                            display: true,
                            text: 'Top Selling Products'
                        },
                        animation: {
                            duration: 2000
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
