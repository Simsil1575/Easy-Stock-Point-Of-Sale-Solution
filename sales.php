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
$pdo = new PDO('sqlite:active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}
?>
<?php
// Database connection with error handling
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Get business closing time from business_info
$businessInfo = [];
$closingTime = '00:00'; // Default
try {
    $businessInfoDb = new PDO('sqlite:info.db');
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

// Handle date selection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['selected_date'])) {
    $selectedDate = $_POST['selected_date'];
    $startDate = $selectedDate;
    $endDate = $selectedDate;
} else {
    // Determine which date to show by default based on current time vs closing time
    $currentTime = date('H:i');
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // If current time is before closing time, show yesterday's data
    // If current time is after closing time, show today's data
    $defaultDate = ($currentTime < $closingTime) ? $yesterday : $today;
    
    $selectedDate = $defaultDate;
    $startDate = $defaultDate;
    $endDate = $defaultDate;
}

// Function to get business day where clause for date range
function getBusinessDayWhereClause($dateField, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    if ($startDate === $endDate) {
        // Single day query
        $nextDay = date('Y-m-d', strtotime($startDate . ' +1 day'));
        return "((DATE($dateField) = '$startDate' AND strftime('%H:%M', $dateField) >= '$closingTime') OR 
                 (DATE($dateField) = '$nextDay' AND strftime('%H:%M', $dateField) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "))";
    } else {
        // Date range query - use business date calculation
        return "CASE 
                    WHEN strftime('%H:%M', $dateField) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                    THEN date(datetime($dateField, '-1 day'))
                    ELSE date($dateField)
                END BETWEEN '$startDate' AND '$endDate'";
    }
}

// Function to get total sales using business day logic
function getCashSales($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(total), 0) 
                 FROM orders o
                 LEFT JOIN eft_payments e ON o.id = e.order_id
                 WHERE e.order_id IS NULL AND ($whereClause))
                +
                (SELECT COALESCE(SUM(amount), 0) 
                 FROM payments p
                 JOIN credit_sales cs ON p.sale_id = cs.id
                 WHERE " . getBusinessDayWhereClause('p.payment_date', $startDate, $endDate, $closingTime, $isAfterMidnight) . ")
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getCashSales: " . $e->getMessage());
        return 0;
    }
}

// Function to get total credit sales using business day logic
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

// Function to get total cost of goods sold using business day logic
function getCostOfGoodsSold($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $orderWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
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

// Function to get top-selling products using business day logic
function getTopSellingProducts($db, $startDate, $endDate, $closingTime, $isAfterMidnight, $limit = 1000000000) {
    try {
        $orderWhereClause = getBusinessDayWhereClause('o.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $creditWhereClause = getBusinessDayWhereClause('cs.created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        
        $stmt = $db->prepare("
            SELECT 
                combined.product_name, 
                SUM(combined.quantity) as total_quantity,
                COALESCE(p.price, 0) as unit_price
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

// Function to get total cash in using business day logic
function getTotalCashIn($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-in' AND ($whereClause)");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in getTotalCashIn: " . $e->getMessage());
        return 0;
    }
}

// Function to get total cash out using business day logic
function getTotalCashOut($db, $startDate, $endDate, $closingTime, $isAfterMidnight) {
    try {
        $whereClause = getBusinessDayWhereClause('created_at', $startDate, $endDate, $closingTime, $isAfterMidnight);
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

// Retrieve values using business day logic
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

// Determine date display format
$dateDisplay = ($startDate === $endDate) ? $startDate : "$startDate to $endDate";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit and Loss Statement</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">    
    <script src="src/chart.js"></script>
    <link rel="icon" href="favicon.ico" type="image/png">

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
    </style>
</head>

<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="fixed top-0 left-0 h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content">
            <div class="container mx-auto p-6">
                <h1 class="text-3xl font-bold mb-6">Sales Overview</h1>
                <form method="POST" class="mb-8">
                    <div class="flex space-x-4">
                        <div>
                            <label for="selected_date" class="block mb-2">Select Date:</label>
                            <input type="date" id="selected_date" name="selected_date" value="<?php echo $startDate; ?>" class="border rounded px-2 py-1">
                        </div>
                        <div class="self-end">
                            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Check Date</button>
                        </div>
                    </div>
                </form>
                
                <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4" style="min-height: 550px;">
                    <h2 class="text-xl font-semibold mb-4">Top Selling Products</h2>
                    <div class="flex flex-container">
                        <div class="w-1/2 chart-section">
                            <div class="chart-container">
                                <div class="chart-wrapper">
                                    <canvas id="topProductsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="w-1/2 pl-2 table-section">
                            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                                <div class="flex justify-between items-center px-6 py-4 border-b">
                                    <h3 class="text-lg font-semibold text-gray-700">Product Sales</h3>
                                    <div class="flex items-center space-x-4">
                                        <div class="relative">
                                            <input type="text" id="searchInput" placeholder="Search products..." class="block appearance-none w-full bg-white border border-gray-300 text-gray-700 py-2 px-4 pr-8 rounded leading-tight focus:outline-none focus:border-blue-500">
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
                                                    Total Sales <span id="totalSalesSortArrow"></span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($topSellingProducts as $product): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $product['product_name']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format($product['total_quantity']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">N$<?php echo number_format($product['total_quantity'] * $product['unit_price'], 2); ?></td>
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
                type: 'pie',
                data: {
                    labels: productNames,
                    datasets: [{
                        data: productQuantities,
                        backgroundColor: [
                            '#2E86AB', '#F18F01', '#C73E1D', '#3A7D44', '#6B4E71',
                            '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFAD60',
                            '#9B4DCA', '#26A69A', '#EF5350', '#66BB6A', '#7E57C2'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Top 10 Selling Products',
                            font: {
                                size: 18
                            },
                            padding: {
                                top: 10,
                                bottom: 20
                            }
                        },
                        legend: {
                            position: 'top',
                            align: 'center',
                            labels: {
                                padding: 11,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    return `${label}: ${value}`;
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
                const totalSalesMatch = (product.total_quantity * product.unit_price).toFixed(2).includes(searchTerm);
                return nameMatch || quantityMatch || totalSalesMatch;
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
</body>
</html>