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

// Database connection
$db = new PDO('sqlite:../pos.db');



// Get date filter and view type
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$viewType = isset($_GET['view']) ? $_GET['view'] : 'weekly'; // daily, weekly, monthly

// Calculate date range based on view type
if ($viewType === 'weekly') {
    // Get the Monday of the selected week
    $selectedDateTime = new DateTime($selectedDate);
    $dayOfWeek = $selectedDateTime->format('N'); // 1 (Monday) to 7 (Sunday)
    $daysToSubtract = $dayOfWeek - 1; // Days to go back to Monday
    
    $monday = clone $selectedDateTime;
    $monday->modify("-{$daysToSubtract} days");
    
    $sunday = clone $monday;
    $sunday->modify('+6 days');
    
    $startDate = $monday->format('Y-m-d');
    $endDate = $sunday->format('Y-m-d');
    $dateRange = $startDate . ' to ' . $endDate;
} else {
    $startDate = $selectedDate;
    $endDate = $selectedDate;
    $dateRange = $selectedDate;
}

// Function to get correct opening stock values for display (read-only)
function getCorrectOpeningStock($db, $productId, $date) {
    $stmt = $db->prepare("
        SELECT COALESCE(os.opening_quantity, 0) as opening_quantity
        FROM opening_stock os 
        WHERE os.product_id = ? 
        AND DATE(os.recorded_at) = ?
        ORDER BY os.recorded_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$productId, $date]);
    return $stmt->fetchColumn();
}

// Function to get correct sold quantities for display (read-only)
function getCorrectSoldQuantity($db, $productId, $date) {
    // Check if closing stock exists for this product and date
    $closingStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM closing_stock 
        WHERE product_id = ? AND DATE(recorded_at) = ?
    ");
    $closingStmt->execute([$productId, $date]);
    $hasClosingStock = $closingStmt->fetchColumn() > 0;
    
    // If closing stock exists, sold quantity should be 0 for future dates
    if ($hasClosingStock) {
        $currentDate = date('Y-m-d');
        if ($date > $currentDate) {
            return 0;
        }
    }
    
    // Get sold quantity from daily stock summary
    $stmt = $db->prepare("
        SELECT COALESCE(sold_quantity, 0) as sold_quantity
        FROM daily_stock_summary 
        WHERE product_id = ? AND date = ?
    ");
    $stmt->execute([$productId, $date]);
    return $stmt->fetchColumn();
}

// Fetch daily stock summary for the selected date range with correct opening stock, receiving, and sold quantities
$stmt = $db->prepare("
    SELECT 
        dss.date,
        dss.product_id,
        COALESCE(os.opening_quantity, 0) as opening_quantity,
        dss.closing_quantity,
        COALESCE(rc.received_quantity, 0) as received_quantity,
        dss.damaged_quantity,
        COALESCE(sc.sold_quantity, 0) as actual_sold_quantity,
        p.name as product_name,
        p.price,
        p.buying_price,
        (COALESCE(os.opening_quantity, 0) + COALESCE(rc.received_quantity, 0) - COALESCE(sc.sold_quantity, 0) - dss.damaged_quantity) as expected_closing,
        (COALESCE(sc.sold_quantity, 0) * p.price) as sales_revenue,
        (COALESCE(sc.sold_quantity, 0) * p.buying_price) as cost_of_goods,
        ((COALESCE(sc.sold_quantity, 0) * p.price) - (COALESCE(sc.sold_quantity, 0) * p.buying_price)) as profit_loss
    FROM daily_stock_summary dss
    JOIN products p ON dss.product_id = p.id
    LEFT JOIN (
        SELECT 
            product_id,
            DATE(recorded_at) as date,
            opening_quantity,
            ROW_NUMBER() OVER (PARTITION BY product_id, DATE(recorded_at) ORDER BY recorded_at DESC) as rn
        FROM opening_stock
    ) os ON os.product_id = dss.product_id AND os.date = dss.date AND os.rn = 1
    LEFT JOIN (
        SELECT 
            product_id,
            DATE(changed_at) as date,
            SUM(quantity_change) as received_quantity
        FROM stock_changes 
        WHERE action = 'Restock' 
        AND (is_stock_taken = 0 OR is_stock_taken IS NULL)
        GROUP BY product_id, DATE(changed_at)
    ) rc ON rc.product_id = dss.product_id AND rc.date = dss.date
    LEFT JOIN (
        SELECT 
            product_id,
            date,
            SUM(sold_quantity) as sold_quantity
        FROM (
            SELECT 
                p.id as product_id,
                DATE(o.created_at) as date,
                SUM(oi.quantity) as sold_quantity
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_name = p.name
            GROUP BY p.id, DATE(o.created_at)
            
            UNION ALL
            
            SELECT 
                p.id as product_id,
                DATE(cs.created_at) as date,
                SUM(csi.quantity) as sold_quantity
            FROM credit_sale_items csi
            JOIN credit_sales cs ON csi.sale_id = cs.id
            JOIN products p ON csi.product_name = p.name
            GROUP BY p.id, DATE(cs.created_at)
        )
        GROUP BY product_id, date
    ) sc ON sc.product_id = dss.product_id AND sc.date = dss.date
    WHERE dss.date BETWEEN ? AND ?
    ORDER BY COALESCE(sc.sold_quantity, 0) DESC, p.name ASC, dss.date ASC
");
$stmt->execute([$startDate, $endDate]);
$dailySummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Post-process the data to handle sold quantities that should be reset after closing stock
foreach ($dailySummary as &$item) {
    // Check if closing stock exists for this product and date
    $closingStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM closing_stock 
        WHERE product_id = ? AND DATE(recorded_at) = ?
    ");
    $closingStmt->execute([$item['product_id'], $item['date']]);
    $hasClosingStock = $closingStmt->fetchColumn() > 0;
    
    // If closing stock exists and this is a future date, sold quantity should be 0
    if ($hasClosingStock) {
        $currentDate = date('Y-m-d');
        if ($item['date'] > $currentDate) {
            $item['actual_sold_quantity'] = 0;
        }
    }
    
    // Also check if there's a closing stock record for a previous date that should reset future sold quantities
    $previousClosingStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM closing_stock 
        WHERE product_id = ? AND DATE(recorded_at) < ?
        ORDER BY recorded_at DESC
        LIMIT 1
    ");
    $previousClosingStmt->execute([$item['product_id'], $item['date']]);
    $hasPreviousClosingStock = $previousClosingStmt->fetchColumn() > 0;
    
    // If there's a previous closing stock and this date is after it, sold quantity should be 0
    if ($hasPreviousClosingStock) {
        $lastClosingStmt = $db->prepare("
            SELECT DATE(recorded_at) as closing_date
            FROM closing_stock 
            WHERE product_id = ? AND DATE(recorded_at) < ?
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $lastClosingStmt->execute([$item['product_id'], $item['date']]);
        $lastClosingDate = $lastClosingStmt->fetchColumn();
        
        if ($lastClosingDate && $item['date'] > $lastClosingDate) {
            $item['actual_sold_quantity'] = 0;
        }
    }
}

// Group by product for weekly view with daily breakdown
$groupedSummary = [];
$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

foreach ($dailySummary as $item) {
    $productId = $item['product_id'];
    $date = $item['date'];
    $dayOfWeek = date('N', strtotime($date)); // 1 (Monday) to 7 (Sunday)
    $dayName = $dayNames[$dayOfWeek - 1];
    
    if (!isset($groupedSummary[$productId])) {
        $groupedSummary[$productId] = [
            'product_name' => $item['product_name'],
            'price' => $item['price'],
            'buying_price' => $item['buying_price'],
            'opening_quantity' => 0,
            'received_quantity' => 0,
            'sold_quantity' => 0,
            'damaged_quantity' => 0,
            'closing_quantity' => 0,
            'expected_closing' => 0,
            'sales_revenue' => 0,
            'cost_of_goods' => 0,
            'profit_loss' => 0,
            'daily_records' => [],
            'daily_received' => [
                'Monday' => 0,
                'Tuesday' => 0,
                'Wednesday' => 0,
                'Thursday' => 0,
                'Friday' => 0,
                'Saturday' => 0,
                'Sunday' => 0
            ],
            'daily_sold' => [
                'Monday' => 0,
                'Tuesday' => 0,
                'Wednesday' => 0,
                'Thursday' => 0,
                'Friday' => 0,
                'Saturday' => 0,
                'Sunday' => 0
            ],
            'daily_damaged' => [
                'Monday' => 0,
                'Tuesday' => 0,
                'Wednesday' => 0,
                'Thursday' => 0,
                'Friday' => 0,
                'Saturday' => 0,
                'Sunday' => 0
            ]
        ];
    }
    
    // For weekly view, only add opening quantity once (from the first day)
    if (!isset($groupedSummary[$productId]['opening_quantity_set'])) {
        $groupedSummary[$productId]['opening_quantity'] = $item['opening_quantity'];
        $groupedSummary[$productId]['opening_quantity_set'] = true;
    }
    
    $groupedSummary[$productId]['received_quantity'] += $item['received_quantity'];
    $groupedSummary[$productId]['sold_quantity'] += $item['actual_sold_quantity'];
    $groupedSummary[$productId]['damaged_quantity'] += $item['damaged_quantity'];
    $groupedSummary[$productId]['closing_quantity'] = $item['closing_quantity']; // Use last day's closing
    
    // For weekly view, calculate expected closing based on first day's opening + total received - total sold - total damaged
    if (!isset($groupedSummary[$productId]['expected_closing_calculated'])) {
        $groupedSummary[$productId]['expected_closing'] = $groupedSummary[$productId]['opening_quantity'] + 
                                                         $groupedSummary[$productId]['received_quantity'] - 
                                                         $groupedSummary[$productId]['sold_quantity'] - 
                                                         $groupedSummary[$productId]['damaged_quantity'];
        $groupedSummary[$productId]['expected_closing_calculated'] = true;
    }
    
    // Recalculate sales revenue, cost of goods, and profit loss based on actual sold quantities
    $groupedSummary[$productId]['sales_revenue'] = $groupedSummary[$productId]['sold_quantity'] * $item['price'];
    $groupedSummary[$productId]['cost_of_goods'] = $groupedSummary[$productId]['sold_quantity'] * $item['buying_price'];
    $groupedSummary[$productId]['profit_loss'] = $groupedSummary[$productId]['sales_revenue'] - $groupedSummary[$productId]['cost_of_goods'];
    $groupedSummary[$productId]['daily_records'][] = $item;
    
    // Store daily values
    $groupedSummary[$productId]['daily_received'][$dayName] = $item['received_quantity'];
    $groupedSummary[$productId]['daily_sold'][$dayName] = $item['actual_sold_quantity'];
    $groupedSummary[$productId]['daily_damaged'][$dayName] = $item['damaged_quantity'];
}

// Sort grouped summary by sold quantity in descending order (highest sales first)
uasort($groupedSummary, function($a, $b) {
    return $b['sold_quantity'] - $a['sold_quantity'];
});

// Fetch opening stock records for the selected date range
try {
    $openingStmt = $db->prepare("
        SELECT 
            os.*,
            p.name as product_name,
            u.username as recorded_by_user
        FROM opening_stock os
        JOIN products p ON os.product_id = p.id
        LEFT JOIN users u ON os.recorded_by = u.id
        WHERE DATE(os.recorded_at) BETWEEN ? AND ?
        ORDER BY os.recorded_at ASC
    ");
    $openingStmt->execute([$startDate, $endDate]);
    $openingStock = $openingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if users table doesn't exist or has different structure
    $openingStmt = $db->prepare("
        SELECT 
            os.*,
            p.name as product_name,
            'User ' || os.recorded_by as recorded_by_user
        FROM opening_stock os
        JOIN products p ON os.product_id = p.id
        WHERE DATE(os.recorded_at) BETWEEN ? AND ?
        ORDER BY os.recorded_at ASC
    ");
    $openingStmt->execute([$startDate, $endDate]);
    $openingStock = $openingStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch closing stock records for the selected date range
try {
    $closingStmt = $db->prepare("
        SELECT 
            cs.*,
            p.name as product_name,
            u.username as recorded_by_user
        FROM closing_stock cs
        JOIN products p ON cs.product_id = p.id
        LEFT JOIN users u ON cs.recorded_by = u.id
        WHERE DATE(cs.recorded_at) BETWEEN ? AND ?
        ORDER BY cs.recorded_at ASC
    ");
    $closingStmt->execute([$startDate, $endDate]);
    $closingStock = $closingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if users table doesn't exist or has different structure
    $closingStmt = $db->prepare("
        SELECT 
            cs.*,
            p.name as product_name,
            'User ' || cs.recorded_by as recorded_by_user
        FROM closing_stock cs
        JOIN products p ON cs.product_id = p.id
        WHERE DATE(cs.recorded_at) BETWEEN ? AND ?
        ORDER BY cs.recorded_at ASC
    ");
    $closingStmt->execute([$startDate, $endDate]);
    $closingStock = $closingStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$totalOpening = 0;
$totalReceived = 0;
$totalSold = 0;
$totalDamaged = 0;
$totalClosing = 0;
$totalSalesRevenue = 0;
$totalCostOfGoods = 0;
$totalProfitLoss = 0;

if ($viewType === 'daily') {
    foreach ($dailySummary as $item) {
        $totalOpening += $item['opening_quantity'];
        $totalReceived += $item['received_quantity'];
        $totalSold += $item['actual_sold_quantity'];
        $totalDamaged += $item['damaged_quantity'];
        $totalClosing += $item['closing_quantity'];
        $totalSalesRevenue += $item['sales_revenue'];
        $totalCostOfGoods += $item['cost_of_goods'];
        $totalProfitLoss += $item['profit_loss'];
    }
} else {
    foreach ($groupedSummary as $item) {
        $totalOpening += $item['opening_quantity'];
        $totalReceived += $item['received_quantity'];
        $totalSold += $item['sold_quantity'];
        $totalDamaged += $item['damaged_quantity'];
        $totalClosing += $item['closing_quantity'];
        $totalSalesRevenue += $item['sales_revenue'];
        $totalCostOfGoods += $item['cost_of_goods'];
        $totalProfitLoss += $item['profit_loss'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ucfirst($viewType) ?> Stock Report</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js"></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
    <style>
        .profit-loss-positive {
            color: #059669;
            font-weight: 600;
        }
        .profit-loss-negative {
            color: #dc2626;
            font-weight: 600;
        }
        .profit-loss-zero {
            color: #6b7280;
        }
        .view-toggle {
            background-color: #f3f4f6;
            border-radius: 0.5rem;
            padding: 0.25rem;
        }
        .view-toggle button {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .view-toggle button.active {
            background-color: #3b82f6;
            color: white;
        }
        .view-toggle button:not(.active) {
            color: #6b7280;
        }
        .view-toggle button:not(.active):hover {
            color: #374151;
        }
        /* Weekly table styling */
        .weekly-table th:nth-child(9) {
            border-right: 2px solid #e5e7eb;
        }
        .weekly-table td:nth-child(9) {
            border-right: 2px solid #e5e7eb;
        }
        .day-group {
            background-color: #f9fafb;
        }
        .day-group th {
            background-color: #f3f4f6;
        }
        /* Container and table fit styling */
        .table-container {
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
        }
        .weekly-table {
            width: 100%;
            table-layout: fixed;
            font-size: 0.75rem;
        }
        .weekly-table th,
        .weekly-table td {
            padding: 0.5rem 0.25rem;
            font-size: 0.75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-right: 1px solid #e5e7eb;
        }
        .weekly-table th:first-child,
        .weekly-table td:first-child {
            width: 15%;
            min-width: 120px;
            max-width: 150px;
        }
        .weekly-table th:nth-child(2),
        .weekly-table td:nth-child(2) {
            width: 8%;
            min-width: 60px;
            max-width: 80px;
        }
        .weekly-table th:nth-child(n+3):nth-child(-n+9),
        .weekly-table td:nth-child(n+3):nth-child(-n+9) {
            width: 5%;
            min-width: 40px;
            max-width: 50px;
        }
        .weekly-table th:nth-child(10),
        .weekly-table td:nth-child(10),
        .weekly-table th:nth-child(11),
        .weekly-table td:nth-child(11) {
            width: 8%;
            min-width: 60px;
            max-width: 80px;
        }
        .weekly-table th:nth-child(12),
        .weekly-table td:nth-child(12),
        .weekly-table th:nth-child(13),
        .weekly-table td:nth-child(13) {
            width: 8%;
            min-width: 60px;
            max-width: 80px;
        }
        .weekly-table th:nth-child(14),
        .weekly-table td:nth-child(14) {
            width: 8%;
            min-width: 60px;
            max-width: 80px;
        }
        /* Ensure container doesn't overflow */
        .content {
            max-width: 100%;
            overflow-x: hidden;
        }
        .max-w-7xl {
            max-width: 100%;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        /* Summary Cards styling - matching home.php structure */
        .bg-white {
            background-color: white;
        }
        
        /* Responsive adjustments for summary cards */
        @media (max-width: 1280px) {
            .xl\:grid-cols-6 {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 1024px) {
            .lg\:grid-cols-3 {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 640px) {
            .sm\:grid-cols-2 {
                grid-template-columns: 1fr;
            }
            
            /* Adjust card content for mobile */
            .text-2xl {
                font-size: 1.5rem;
            }
            
            .p-6 {
                padding: 1rem;
            }
        }
        /* Additional responsive fixes */
        @media (max-width: 1024px) {
            .weekly-table th,
            .weekly-table td {
                font-size: 0.7rem;
                padding: 0.25rem 0.125rem;
            }
            .weekly-table th:first-child,
            .weekly-table td:first-child {
                min-width: 100px;
            }
            .weekly-table th:nth-child(n+3):nth-child(-n+9),
            .weekly-table td:nth-child(n+3):nth-child(-n+9) {
                min-width: 25px;
            }
        }
        @media (max-width: 768px) {
            .ml-64 {
                margin-left: 0;
            }
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 content">
            <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex justify-between items-center mb-8">
                    <div class="flex items-center gap-4">
                        <a href="inventory" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Go Back
                        </a>
                        <h1 class="text-3xl font-bold"><?= ucfirst($viewType) ?> Stock Report</h1>
                    </div>
                    
                    <div class="flex items-center gap-4">
                        <!-- View Type Toggle -->
                        <div class="view-toggle">
                            <button onclick="changeView('daily')" class="<?= $viewType === 'daily' ? 'active' : '' ?>">Daily</button>
                            <button onclick="changeView('weekly')" class="<?= $viewType === 'weekly' ? 'active' : '' ?>">Weekly</button>
                        </div>
                        
                        <input type="date" id="dateFilter" value="<?= $selectedDate ?>" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <button onclick="exportToPDF()" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md shadow-sm">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Export PDF
                        </button>
                    </div>
                </div>

                <!-- Date Range Display -->
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span class="text-blue-800 font-medium">
                            <?php if ($viewType === 'weekly'): ?>
                                Week of <?= date('F j, Y', strtotime($startDate)) ?> to <?= date('F j, Y', strtotime($endDate)) ?>
                            <?php else: ?>
                                <?= date('F j, Y', strtotime($selectedDate)) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-8">
                    <!-- Opening Stock Card -->
                    <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200" data-card="openingStock">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Opening Stock</p>
                                <h3 class="text-2xl font-bold text-blue-600">
                                    <?= number_format($totalOpening) ?>
                                </h3>
                            </div>
                            <div class="p-2 bg-blue-100 rounded-full">
                                <i class="fas fa-box text-blue-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Initial stock count</p>
                    </div>
                    
                    <!-- Sold Card -->
                    <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200" data-card="sold">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Sold</p>
                                <h3 class="text-2xl font-bold text-red-600">
                                    <?= number_format($totalSold) ?>
                                </h3>
                            </div>
                            <div class="p-2 bg-red-100 rounded-full">
                                <i class="fas fa-shopping-cart text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Items sold</p>
                    </div>
                    
                    <!-- Closing Stock Card -->
                    <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200" data-card="closingStock">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Closing Stock</p>
                                <h3 class="text-2xl font-bold text-purple-600">
                                    <?= number_format($totalClosing) ?>
                                </h3>
                            </div>
                            <div class="p-2 bg-purple-100 rounded-full">
                                <i class="fas fa-check-circle text-purple-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Final stock count</p>
                    </div>
                    
                    <!-- Profit/Loss Card -->
                    <div class="bg-white rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200" data-card="profitLoss">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Profit/Loss</p>
                                <h3 class="text-2xl font-bold <?= $totalProfitLoss > 0 ? 'text-teal-600' : ($totalProfitLoss < 0 ? 'text-red-600' : 'text-gray-900') ?>">
                                    <?= $totalProfitLoss > 0 ? '+' : '' ?>N$<?= number_format($totalProfitLoss, 2) ?>
                                </h3>
                            </div>
                            <div class="p-2 bg-<?= $totalProfitLoss > 0 ? 'teal' : ($totalProfitLoss < 0 ? 'red' : 'gray') ?>-100 rounded-full">
                                <i class="fas fa-chart-line text-<?= $totalProfitLoss > 0 ? 'teal' : ($totalProfitLoss < 0 ? 'red' : 'gray') ?>-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500">Total profit/loss</p>
                    </div>
                </div>

                <!-- Stock Summary Table -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-8">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="text-lg font-medium text-gray-900"><?= ucfirst($viewType) ?> Stock Summary</h3>
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <input type="text" id="searchInput" placeholder="Search products..." class="pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <svg class="w-5 h-5 absolute left-2 top-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="w-full divide-y divide-gray-200 <?= $viewType === 'weekly' ? 'weekly-table' : '' ?>">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(0)">
                                        <div class="flex items-center">
                                            Product
                                            <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(1, true)">
                                        <div class="flex items-center justify-center">
                                            Opening
                                            <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <?php if ($viewType === 'weekly'): ?>
                                        <!-- Daily breakdown columns for weekly view - only for Received -->
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Mon</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Tue</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Wed</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Thu</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Fri</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Sat</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Sun</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(9, true)">
                                            <div class="flex items-center justify-center">
                                                Sold
                                                <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(10, true)">
                                            <div class="flex items-center justify-center">
                                                Damage
                                                <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                    <?php else: ?>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(2, true)">
                                            <div class="flex items-center justify-center">
                                                Received
                                                <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(3, true)">
                                            <div class="flex items-center justify-center">
                                                Sold
                                                <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(4, true)">
                                            <div class="flex items-center justify-center">
                                                Damaged
                                                <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                    <?php endif; ?>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(<?= $viewType === 'weekly' ? 11 : 5 ?>, true)">
                                        <div class="flex items-center justify-center">
                                            Expected
                                            <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(<?= $viewType === 'weekly' ? 12 : 6 ?>, true)">
                                        <div class="flex items-center justify-center">
                                            Closing
                                            <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTable(<?= $viewType === 'weekly' ? 13 : 7 ?>, true)">
                                        <div class="flex items-center justify-center">
                                            Profit Loss
                                            <svg class="w-3 h-3 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="tableBody">
                                <?php if ($viewType === 'daily'): ?>
                                    <?php foreach ($dailySummary as $item): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($item['product_name']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['opening_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['received_quantity'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['received_quantity'] > 0 ? '+' . $item['received_quantity'] : $item['received_quantity'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['actual_sold_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['damaged_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['expected_closing'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['closing_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['profit_loss'] > 0 ? 'profit-loss-positive' : ($item['profit_loss'] < 0 ? 'profit-loss-negative' : 'profit-loss-zero') ?>">
                                                    <?= $item['profit_loss'] > 0 ? '+' : '' ?>N$<?= number_format($item['profit_loss'], 2) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($groupedSummary as $item): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($item['product_name']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['opening_quantity'] ?>
                                            </td>
                                            <!-- Daily Received columns -->
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Monday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Monday'] > 0 ? '+' . $item['daily_received']['Monday'] : $item['daily_received']['Monday'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Tuesday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Tuesday'] > 0 ? '+' . $item['daily_received']['Tuesday'] : $item['daily_received']['Tuesday'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Wednesday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Wednesday'] > 0 ? '+' . $item['daily_received']['Wednesday'] : $item['daily_received']['Wednesday'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Thursday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Thursday'] > 0 ? '+' . $item['daily_received']['Thursday'] : $item['daily_received']['Thursday'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Friday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Friday'] > 0 ? '+' . $item['daily_received']['Friday'] : $item['daily_received']['Friday'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Saturday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Saturday'] > 0 ? '+' . $item['daily_received']['Saturday'] : $item['daily_received']['Saturday'] ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['daily_received']['Sunday'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-500' ?>">
                                                    <?= $item['daily_received']['Sunday'] > 0 ? '+' . $item['daily_received']['Sunday'] : $item['daily_received']['Sunday'] ?>
                                                </span>
                                            </td>
                                            <!-- Total Sold and Damaged -->
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['sold_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['damaged_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['expected_closing'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                                <?= $item['closing_quantity'] ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <span class="<?= $item['profit_loss'] > 0 ? 'profit-loss-positive' : ($item['profit_loss'] < 0 ? 'profit-loss-negative' : 'profit-loss-zero') ?>">
                                                    <?= $item['profit_loss'] > 0 ? '+' : '' ?>N$<?= number_format($item['profit_loss'], 2) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls -->
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex justify-between items-center">
                            <div class="flex gap-2">
                                <button id="firstPage" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    <<
                                </button>
                                <button id="prevPage" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </button>
                            </div>
                            <div class="flex items-center gap-4">
                                <span id="pageNumber" class="text-sm text-gray-700">Page 1 of 1</span>
                                <div class="flex items-center gap-2">
                                    <input type="number" id="pageInput" min="1" class="w-20 px-2 py-1 border rounded text-sm" placeholder="Page">
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button id="nextPage" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </button>
                                <button id="lastPage" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    >>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        try {
            // Pagination and search functionality
            const rowsPerPage = 10;
            const tableBody = document.getElementById("tableBody");
            const pageNumber = document.getElementById("pageNumber");
            const searchInput = document.getElementById('searchInput');
            
            // Check if required elements exist before proceeding
            if (!tableBody || !pageNumber) {
                console.error('Required table elements not found');
            } else {
                let allRows = Array.from(tableBody.children);
                let rows = [...allRows];
                let sortDirection = {};
                let currentPage = 1;
                
                // Store current page in sessionStorage
                function saveCurrentPage() {
                    try {
                        sessionStorage.setItem('stockReportCurrentPage', currentPage);
                    } catch (e) {
                        console.warn('Could not save page to sessionStorage:', e);
                    }
                }
                
                // Retrieve current page from sessionStorage
                function loadCurrentPage() {
                    try {
                        const savedPage = sessionStorage.getItem('stockReportCurrentPage');
                        if (savedPage) {
                            currentPage = parseInt(savedPage);
                        }
                    } catch (e) {
                        console.warn('Could not load page from sessionStorage:', e);
                    }
                }
                
                // Load saved page on page initialization
                loadCurrentPage();

                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        filterRows(e.target.value);
                    });
                }

                function filterRows(searchTerm) {
                    rows = allRows.filter(row => {
                        const productName = row.children[0].textContent.toLowerCase();
                        const matchesSearch = productName.includes(searchTerm.toLowerCase());
                        return matchesSearch;
                    });
                    currentPage = 1;
                    showPage(currentPage);
                    saveCurrentPage();
                }

                function showPage(page) {
                    const start = (page - 1) * rowsPerPage;
                    const end = start + rowsPerPage;
                    const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                    
                    allRows.forEach(row => row.style.display = 'none');
                    rows.slice(start, end).forEach(row => row.style.display = 'table-row');
                    
                    pageNumber.textContent = `Page ${page} of ${maxPage}`;
                    const pageInput = document.getElementById('pageInput');
                    if (pageInput) {
                        pageInput.value = page;
                        pageInput.placeholder = `Page (1-${maxPage})`;
                    }
                    
                    saveCurrentPage();
                }

                function sortTable(columnIndex, isNumeric = false) {
                    // Reset all sort directions except the current column
                    Object.keys(sortDirection).forEach(key => {
                        if (parseInt(key) !== columnIndex) {
                            delete sortDirection[key];
                        }
                    });
                    
                    if (!sortDirection[columnIndex]) {
                        sortDirection[columnIndex] = 'asc';
                    } else {
                        sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
                    }

                    // Update visual feedback for sorting arrows
                    updateSortArrows(columnIndex, sortDirection[columnIndex]);

                    rows.sort((a, b) => {
                        let aValue = a.children[columnIndex].textContent.trim();
                        let bValue = b.children[columnIndex].textContent.trim();

                        if (isNumeric) {
                            // Remove any non-numeric characters except decimal points and minus signs
                            aValue = parseFloat(aValue.replace(/[^\d.-]/g, ''));
                            bValue = parseFloat(bValue.replace(/[^\d.-]/g, ''));
                            
                            // Handle NaN values
                            if (isNaN(aValue)) aValue = 0;
                            if (isNaN(bValue)) bValue = 0;
                        } else {
                            aValue = aValue.toLowerCase();
                            bValue = bValue.toLowerCase();
                        }

                        if (sortDirection[columnIndex] === 'asc') {
                            return aValue > bValue ? 1 : -1;
                        } else {
                            return aValue < bValue ? 1 : -1;
                        }
                    });

                    // Clear and re-append sorted rows
                    while (tableBody.firstChild) {
                        tableBody.removeChild(tableBody.firstChild);
                    }
                    rows.forEach(row => tableBody.appendChild(row));

                    showPage(currentPage);
                    saveCurrentPage();
                }

                function updateSortArrows(activeColumnIndex, direction) {
                    const headers = document.querySelectorAll('th[onclick]');
                    headers.forEach((header, index) => {
                        const svg = header.querySelector('svg');
                        if (svg) {
                            if (index === activeColumnIndex) {
                                // Show active sort direction
                                if (direction === 'asc') {
                                    svg.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>';
                                } else {
                                    svg.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>';
                                }
                                svg.classList.add('text-teal-600');
                            } else {
                                // Reset to default double arrow
                                svg.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>';
                                svg.classList.remove('text-teal-600');
                            }
                        }
                    });
                }

                // Add event listeners with error handling
                const prevPageBtn = document.getElementById("prevPage");
                if (prevPageBtn) {
                    prevPageBtn.addEventListener("click", () => {
                        if (currentPage > 1) {
                            currentPage--;
                            showPage(currentPage);
                            saveCurrentPage();
                        }
                    });
                }

                const nextPageBtn = document.getElementById("nextPage");
                if (nextPageBtn) {
                    nextPageBtn.addEventListener("click", () => {
                        if (currentPage * rowsPerPage < rows.length) {
                            currentPage++;
                            showPage(currentPage);
                            saveCurrentPage();
                        }
                    });
                }

                // Pagination controls
                const firstPageBtn = document.getElementById("firstPage");
                if (firstPageBtn) {
                    firstPageBtn.addEventListener("click", () => {
                        currentPage = 1;
                        showPage(currentPage);
                        saveCurrentPage();
                    });
                }

                const lastPageBtn = document.getElementById("lastPage");
                if (lastPageBtn) {
                    lastPageBtn.addEventListener("click", () => {
                        currentPage = Math.ceil(rows.length / rowsPerPage);
                        showPage(currentPage);
                        saveCurrentPage();
                    });
                }

                // Page input handler
                const pageInput = document.getElementById("pageInput");
                if (pageInput) {
                    pageInput.addEventListener("change", () => {
                        const desiredPage = parseInt(pageInput.value);
                        if (!isNaN(desiredPage)) {
                            const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                            currentPage = Math.min(Math.max(1, desiredPage), maxPage);
                            showPage(currentPage);
                            saveCurrentPage();
                        }
                    });
                }

                // Initialize the page display
                showPage(currentPage);
            }

            // Date filter functionality
            const dateFilter = document.getElementById('dateFilter');
            if (dateFilter) {
                dateFilter.addEventListener('change', function() {
                    const selectedDate = this.value;
                    const viewType = '<?= $viewType ?>';
                    window.location.href = `daily_stock_report.php?date=${selectedDate}&view=${viewType}`;
                });
            }

            // View type change functionality
            function changeView(viewType) {
                const dateFilter = document.getElementById('dateFilter');
                const selectedDate = dateFilter ? dateFilter.value : '<?= $selectedDate ?>';
                window.location.href = `daily_stock_report.php?date=${selectedDate}&view=${viewType}`;
            }

            // Export to PDF functionality
            function exportToPDF() {
                const dateFilter = document.getElementById('dateFilter');
                const selectedDate = dateFilter ? dateFilter.value : '<?= $selectedDate ?>';
                const viewType = '<?= $viewType ?>';
                window.open(`daily_stock_report.php?date=${selectedDate}&view=${viewType}&export_pdf=true`, '_blank');
            }

            // Make functions globally available
            window.changeView = changeView;
            window.exportToPDF = exportToPDF;
            window.sortTable = sortTable;
        } catch (error) {
            console.error('Error initializing stock report:', error);
        }
    </script>
</body>
</html> 