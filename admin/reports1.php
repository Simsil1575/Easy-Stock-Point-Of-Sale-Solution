<?php
// Check activation status
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
    <script src="../navigation.js" async></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        .sidebar {
            position: fixed;
            height: 100%;
        }
        .content {
            margin-left: 250px; /* Adjust this value based on the width of your sidebar */
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content">
            <div class="container mx-auto p-6">
                <h1 class="text-3xl font-bold mb-6">Daily Report</h1>

                <?php
                // Database connection
                $db = new PDO('sqlite:../pos.db');
                if ($db->errorCode()) {
                    die("Connection failed: " . $db->errorInfo()[2]);
                }

                // Handle date selection
                $selectedDate = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');

                // Calculate cash sales (remove credit payments from this calculation)
                $cashSalesQuery = $db->prepare("SELECT SUM(total) FROM orders WHERE DATE(created_at) = :selectedDate");
                $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
                $cashSalesQuery->execute();
                $cashSalesTotal = $cashSalesQuery->fetchColumn() ?: 0;

                $dailyCashIn = $cashSalesTotal;  // Removed credit payments addition

                // Get credit sales total and unpaid balances
                $creditSalesQuery = $db->prepare("SELECT 
                    SUM(total_amount) as total_issued,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END) as total_unpaid 
                    FROM credit_sales WHERE DATE(created_at) = :selectedDate");
                $creditSalesQuery->bindParam(':selectedDate', $selectedDate);
                $creditSalesQuery->execute();
                $creditData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
                $creditTotal = $creditData['total_issued'] ?: 0;
                $unpaidTotal = $creditData['total_unpaid'] ?: 0;
                ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                    <!-- Daily Cash In Card -->
                    <div class="bg-blue-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm">Cash sales</p>
                                <h3 class="text-2xl font-bold">N$<?= number_format($dailyCashIn, 2) ?></h3>
                            </div>
                            <div class="bg-blue-500 rounded-full p-3">
                                <i class="fas fa-cash-register text-xl"></i>
                            </div>
                        </div>
                        <p class="text-sm mt-4">Cash Sales</p>
                    </div>

                    <!-- Credit Sales Card -->
                    <div class="bg-red-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm">Credit Payments</p>
                                <h3 class="text-2xl font-bold">N$<?= number_format($creditTotal - $unpaidTotal, 2) ?></h3>
                            </div>
                            <div class="bg-red-500 rounded-full p-3">
                                <i class="fas fa-credit-card text-xl"></i>
                            </div>
                        </div>
                        <p class="text-sm mt-4">Total Credit Paid</p>
                    </div>

                    <!-- Unpaid Credit Card -->
                    <div class="bg-yellow-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm">Unpaid Credit</p>
                                <h3 class="text-2xl font-bold">N$<?= number_format($unpaidTotal, 2) ?></h3>
                            </div>
                            <div class="bg-yellow-500 rounded-full p-3">
                                <i class="fas fa-exclamation-circle text-xl"></i>
                            </div>
                        </div>
                        <p class="text-sm mt-4">Outstanding Balance</p>
                    </div>

                    <!-- Total Cash on Hand Card -->
                    <div class="bg-teal-500 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm">Total Cash on Hand</p>
                                <h3 class="text-2xl font-bold">N$<?= number_format($dailyCashIn + $creditTotal - $unpaidTotal, 2) ?></h3>
                            </div>
                            <div class="bg-teal-500 rounded-full p-3">
                                <i class="fas fa-wallet text-xl"></i>
                            </div>
                        </div>
                        <p class="text-sm mt-4">Cash On Hand</p>
                    </div>
                </div>

                <!-- Date Selection Form -->
                <form method="POST" class="mb-6">
                    <label for="date" class="block text-gray-700 text-sm font-bold mb-2">Select Date:</label>
                    <input type="date" id="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                    <button type="submit" class="mt-4 bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">Check Date</button>
                </form>

                <!-- Add Search Input -->
                <div class="mb-6 flex items-center space-x-2">
                    <div class="flex-grow">
                        <label for="search" class="block text-gray-700 text-sm font-bold mb-2">Search Sales:</label>
                        <input type="text" id="search" onkeyup="filterSales()" placeholder="Search by any field..." 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none transition duration-200">
                    </div>
                    <button onclick="filterSales()" class="mt-6 bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg transition duration-200 ease-in-out transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

                <!-- Sales Table -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden my-6">
                    <table class="min-w-full table-auto">
                        <thead class="sticky top-0">
                            <tr class="bg-gray-300 text-gray-800 uppercase text-sm leading-normal">
                                <th class="py-4 px-8 text-left">#</th>
                                <th class="py-4 px-8 text-left">Type</th>
                                <th class="py-4 px-8 text-left">Total</th>
                                <th class="py-4 px-8 text-left">Products</th>
                                <th class="py-4 px-8 text-left">Date</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-900 text-base font-medium">
                            <?php
                            // Fetch sales data (modified query)
                            $sql = "SELECT orders.id, orders.total, orders.created_at, 
                                    GROUP_CONCAT(order_items.product_name, ', ') as products,
                                    'cash' as sale_type,
                                    'paid' as payment_status
                                    FROM orders
                                    JOIN order_items ON orders.id = order_items.order_id
                                    WHERE DATE(orders.created_at) = :selectedDate
                                    GROUP BY orders.id
                                    
                                    UNION ALL
                                    
                                    SELECT credit_sales.id, credit_sales.total_amount as total, 
                                    credit_sales.created_at,
                                    GROUP_CONCAT(credit_sale_items.product_name, ', ') as products,
                                    'credit' as sale_type,
                                    payment_status
                                    FROM credit_sales
                                    JOIN credit_sale_items ON credit_sales.id = credit_sale_items.sale_id
                                    WHERE DATE(credit_sales.created_at) = :selectedDate
                                    GROUP BY credit_sales.id";
                            $stmt = $db->prepare($sql);
                            $stmt->bindParam(':selectedDate', $selectedDate);
                            $stmt->execute();
                            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            if (count($result) > 0) {
                                foreach($result as $row) {
                            ?>
                                <tr class="border-b border-gray-300 hover:bg-gray-200">
                                    <td class="py-4 px-8"><?= $row['id'] ?></td>
                                    <td class="py-4 px-8">
                                        <?php if ($row['sale_type'] === 'credit' && $row['payment_status'] === 'unpaid'): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-yellow-500 text-white">
                                            Unpaid
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold <?= $row['sale_type'] === 'cash' ? 'bg-blue-500 text-white' : 'bg-red-500 text-white' ?>">
                                            <span class="ml-1">
                                                <?= ucfirst($row['sale_type']) ?>
                                            </span>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-8">N$<?= number_format($row['total'], 2) ?></td>
                                    <td class="py-4 px-8"><?= htmlspecialchars($row['products']) ?></td>
                                    <td class="py-4 px-8"><?= $row['created_at'] ?></td>
                                </tr>
                            <?php
                                }
                            } else {
                            ?>
                                <tr>
                                    <td colspan="4" class="py-4 px-8 text-center">No sales data available for the selected date</td>
                                </tr>
                            <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php $db = null; ?>
        </div>
    </div>

    <script>
    function filterSales() {
        const input = document.getElementById('search');
        const filter = input.value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr');

        rows.forEach(row => {
            const cells = row.getElementsByTagName('td');
            let showRow = false;
            
            Array.from(cells).forEach(cell => {
                if (cell.textContent.toLowerCase().includes(filter)) {
                    showRow = true;
                }
            });
            
            row.style.display = showRow ? '' : 'none';
        });
    }
    </script>
</body>
</html>
