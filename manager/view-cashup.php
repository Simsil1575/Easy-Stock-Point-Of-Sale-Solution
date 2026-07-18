<?php

session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Check activation status with expiration
require_once '../activation_helper.php';
$activationCheck = checkActivationStatus();
if ($activationCheck['status'] === 'not_activated' || $activationCheck['status'] === 'expired') {
    header('Location: settings');
    exit();
}

// Get cashier ID and date from parameters
$cashierId = isset($_GET['cashier']) ? $_GET['cashier'] : null;
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$printMode = isset($_GET['print']) && $_GET['print'] == '1';

if (!$cashierId) {
    header("Location: cash-up.php");
    exit();
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00';
    $businessName = $businessInfo['name'] ?? 'Business';
} catch (PDOException $e) {
    $closingTime = '00:00';
    $businessName = 'Business';
}

// Database connection
$db = new PDO('sqlite:../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Connect to user database to get employee names
$userDb = new PDO('sqlite:../user.db');
$userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Function to get employee name from cashier_id
function getEmployeeName($cashierId, $userDb) {
    if (empty($cashierId)) {
        return 'Unknown Employee';
    }
    
    try {
        $stmt = $userDb->prepare("SELECT username FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$cashierId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user['username'];
        }
    } catch (PDOException $e) {
        // Continue
    }
    
    if (is_numeric($cashierId)) {
        try {
            $stmt = $userDb->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$cashierId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user['username'];
            }
        } catch (PDOException $e) {
            // Continue
        }
    }
    
    return $cashierId;
}

$employeeName = getEmployeeName($cashierId, $userDb);

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$isAfterMidnight = $closingHour < 12;
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch cashier sales summary - using same logic as reports.php
$summaryQuery = $db->prepare("
    SELECT 
        ROUND(SUM(o.total - COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 2) as total_cash,
        ROUND(SUM(COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 2) as total_eft,
        ROUND(SUM(o.total), 2) as total_sales
    FROM orders o
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    AND o.cashier_id = :cashierId
");

$summaryQuery->bindParam(':selectedDate', $selectedDate);
$summaryQuery->bindParam(':nextDay', $nextDay);
$summaryQuery->bindParam(':closingTime', $closingTime);
$summaryQuery->bindParam(':cashierId', $cashierId);
$summaryQuery->execute();
$summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);

$totalCash = floatval($summary['total_cash'] ?? 0);
$totalEft = floatval($summary['total_eft'] ?? 0);
$totalSales = floatval($summary['total_sales'] ?? 0);

// Fetch all orders for the cashier - using same logic as reports.php
$ordersQuery = $db->prepare("
    SELECT 
        o.id,
        o.total,
        o.created_at,
        COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) as eft_total
    FROM orders o
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    AND o.cashier_id = :cashierId
    ORDER BY o.created_at DESC
");

$ordersQuery->bindParam(':selectedDate', $selectedDate);
$ordersQuery->bindParam(':nextDay', $nextDay);
$ordersQuery->bindParam(':closingTime', $closingTime);
$ordersQuery->bindParam(':cashierId', $cashierId);
$ordersQuery->execute();
$orders = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);

// Process orders to calculate payment type
foreach ($orders as &$order) {
    $order['cash_amount'] = $order['total'] - $order['eft_total'];
    $order['eft_amount'] = $order['eft_total'];
    
    if ($order['eft_total'] > 0 && $order['cash_amount'] > 0) {
        $order['payment_type'] = 'Mixed';
    } elseif ($order['eft_total'] > 0) {
        $order['payment_type'] = 'EFT';
    } else {
        $order['payment_type'] = 'Cash';
    }
}
unset($order);

// Fetch items sold by the cashier
// Note: oi.price stores the line total (unit_price * quantity), not the unit price
$itemsQuery = $db->prepare("
    SELECT 
        oi.product_name,
        SUM(oi.quantity) as total_quantity,
        ROUND(SUM(oi.price) / SUM(oi.quantity), 2) as unit_price,
        SUM(oi.price) as total_value
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    AND o.cashier_id = :cashierId
    GROUP BY oi.product_name
    ORDER BY total_value DESC
");

$itemsQuery->bindParam(':selectedDate', $selectedDate);
$itemsQuery->bindParam(':nextDay', $nextDay);
$itemsQuery->bindParam(':closingTime', $closingTime);
$itemsQuery->bindParam(':cashierId', $cashierId);
$itemsQuery->execute();
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Up - <?php echo htmlspecialchars($employeeName); ?></title>
    <script src="../navigation.js" async></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <script src="../sweetalert2@11.js"></script>
    <script src="../lucide.js"></script>
    <!-- Load sendToPrinter function from receipt.php -->
    <script src="../receipt.php?js=true"></script>

    <style>
        * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            box-sizing: border-box;
        }
        
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            overflow-x: hidden;
            max-width: 100vw;
        }
        
        .container {
            max-width: 100vw;
            padding: 0 1rem;
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000;
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
            z-index: 80;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
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

        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .print-section {
                page-break-inside: avoid;
            }
            
            .container {
                max-width: 100% !important;
                padding: 0 !important;
            }
            
            .bg-white {
                box-shadow: none !important;
            }
        }

        /* Table styles */
        .order-row:hover {
            background-color: #f9fafb;
        }

        /* Tab navigation */
        .tab-button {
            transition: all 0.2s;
        }
        
        .tab-button.active {
            border-color: #14b8a6;
            color: #14b8a6;
            background-color: #f0fdfa;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body class="bg-gray-50" style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">
    <div class="flex">
        <?php if (!$printMode): ?>
        <?php include 'sidebar.php'; ?>
        <?php endif; ?>
        <div class="content flex-1 lg:ml-64">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="w-full p-4 lg:p-6">
                <!-- Header Row -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm no-print">
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <a href="cash-up.php?date=<?php echo htmlspecialchars($selectedDate); ?>" class="text-gray-500 hover:text-gray-700">
                                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                                </a>
                                <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">
                                    Account: <?php echo htmlspecialchars($employeeName); ?>
                                </h1>
                            </div>
                            <p class="text-sm text-gray-500 mt-1"><?php echo date('l, F j, Y', strtotime($selectedDate)); ?></p>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <input 
                            type="date" 
                            id="dateSelect" 
                            value="<?php echo htmlspecialchars($selectedDate); ?>" 
                            class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                            onchange="updateDate()"
                        >
                        <button 
                            onclick="printReceipt()" 
                            id="printReceiptBtn"
                            class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors flex items-center gap-2 shadow-sm"
                        >
                            <i class="fas fa-receipt"></i>
                            <span class="hidden sm:inline">Print Receipt</span>
                        </button>
                    </div>
                </div>

                <!-- Print Header (only visible when printing) -->
                <div class="print-section hidden print:block text-center mb-6">
                    <h1 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($businessName); ?></h1>
                    <h2 class="text-xl mb-2">Cash Up Report</h2>
                    <p class="text-lg">Employee: <?php echo htmlspecialchars($employeeName); ?></p>
                    <p class="text-lg">Date: <?php echo date('l, F j, Y', strtotime($selectedDate)); ?></p>
                    <p class="text-sm text-gray-600 mt-2">Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
                    <hr class="my-4">
                </div>

        
                <!-- Tab Navigation -->
                <div class="border-b border-gray-200 mb-6 no-print">
                    <nav class="-mb-px flex space-x-4" aria-label="Tabs">
                        <button type="button" onclick="switchTab('orders')" class="tab-button active whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm rounded-t-lg" data-tab="orders">
                            <i data-lucide="list" class="w-4 h-4 inline-block mr-2"></i>
                            Orders
                        </button>
                        <button type="button" onclick="switchTab('items')" class="tab-button whitespace-nowrap py-3 px-4 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300 rounded-t-lg" data-tab="items">
                            <i data-lucide="package" class="w-4 h-4 inline-block mr-2"></i>
                            Items Sold
                        </button>
                    </nav>
                </div>

                <!-- Orders Tab Content -->
                <div id="orders-tab" class="tab-content active">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden print-section">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">
                                <i data-lucide="list" class="w-5 h-5 inline-block mr-2 text-gray-400"></i>
                                Orders List
                            </h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Order ID</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Time</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Payment Type</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Cash</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">EFT</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($orders)): ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-12 text-center">
                                                <i data-lucide="inbox" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                <p class="text-gray-500 text-lg">No orders found for this employee.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($orders as $order): ?>
                                            <tr class="order-row hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    #<?= htmlspecialchars($order['id']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                    <?= date('H:i:s', strtotime($order['created_at'])) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php
                                                    $badgeClass = 'bg-teal-100 text-teal-800';
                                                    if ($order['payment_type'] === 'EFT') {
                                                        $badgeClass = 'bg-purple-100 text-purple-800';
                                                    } elseif ($order['payment_type'] === 'Mixed') {
                                                        $badgeClass = 'bg-blue-100 text-blue-800';
                                                    }
                                                    ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $badgeClass ?>">
                                                        <?= $order['payment_type'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm <?= $order['cash_amount'] > 0 ? 'text-teal-600 font-semibold' : 'text-gray-400' ?>">
                                                    N$<?= number_format($order['cash_amount'], 2) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm <?= $order['eft_amount'] > 0 ? 'text-purple-600 font-semibold' : 'text-gray-400' ?>">
                                                    N$<?= number_format($order['eft_amount'], 2) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                                    N$<?= number_format($order['total'], 2) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($orders)): ?>
                                <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                                    <tr>
                                        <td colspan="3" class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">Totals</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-teal-600">N$<?= number_format($totalCash, 2) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-purple-600">N$<?= number_format($totalEft, 2) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">N$<?= number_format($totalSales, 2) ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Items Tab Content -->
                <div id="items-tab" class="tab-content">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden print-section">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">
                                <i data-lucide="package" class="w-5 h-5 inline-block mr-2 text-gray-400"></i>
                                Items Sold
                            </h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Product</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                                        <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-12 text-center">
                                                <i data-lucide="package" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                <p class="text-gray-500 text-lg">No items found for this employee.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $totalItemsValue = 0;
                                        $totalQuantity = 0;
                                        foreach($items as $item): 
                                            $totalItemsValue += $item['total_value'];
                                            $totalQuantity += $item['total_quantity'];
                                        ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($item['product_name']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                    <?= $item['total_quantity'] ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                    N$<?= number_format($item['unit_price'], 2) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                                    N$<?= number_format($item['total_value'], 2) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($items)): ?>
                                <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">Total</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900"><?= $totalQuantity ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">-</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">N$<?= number_format($totalItemsValue, 2) ?></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Print-only Items Section (always visible in print) -->
                <div class="hidden print:block print-section mt-6">
                    <div class="bg-white border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">Items Sold</h3>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Product</th>
                                    <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Qty</th>
                                    <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Price</th>
                                    <th class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php 
                                $printTotalValue = 0;
                                foreach($items as $item): 
                                    $printTotalValue += $item['total_value'];
                                ?>
                                    <tr>
                                        <td class="px-6 py-2 text-sm text-gray-900"><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td class="px-6 py-2 text-sm text-gray-600"><?= $item['total_quantity'] ?></td>
                                        <td class="px-6 py-2 text-sm text-gray-600">N$<?= number_format($item['unit_price'], 2) ?></td>
                                        <td class="px-6 py-2 text-sm font-semibold text-gray-900">N$<?= number_format($item['total_value'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="3" class="px-6 py-3 text-sm font-bold text-gray-900">Total</td>
                                    <td class="px-6 py-3 text-sm font-bold text-gray-900">N$<?= number_format($printTotalValue, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <?php
    // Fetch business info for Android printing
    $dbInfo = new PDO('sqlite:../info.db');
    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => '',
            'phone' => '',
            'footer_text' => 'Thank you!',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    ?>

    <script>
        // Business info for Android printing
        var businessInfo = {
            business_name: <?= json_encode($businessInfo['name'] ?? 'POS SOLUTION') ?>,
            location: <?= json_encode($businessInfo['location'] ?? '') ?>,
            phone: <?= json_encode($businessInfo['phone'] ?? '') ?>,
            footer_text: <?= json_encode($businessInfo['footer_text'] ?? 'Thank you!') ?>,
            vat_inclusive: <?= json_encode($businessInfo['vat_inclusive'] ?? 'exclusive') ?>,
            vat_rate: <?= json_encode(floatval($businessInfo['vat_rate'] ?? 15.0)) ?>
        };

        // sendToPrinter function is now loaded from ../receipt.php?js=true
        // The function is defined in receipt.php and automatically handles Android printing
        // The Android interceptor in MainActivity.java only listens to receipt.php calls
        if (typeof sendToPrinter === 'undefined') {
            console.warn('[admin/view-cashup.php] sendToPrinter not loaded from receipt.php, using fallback');
            function sendToPrinter(receiptData) {
                // Ensure print_only flag is set for regular receipts
                if (!receiptData.print_only && !receiptData.is_cashup_report && !receiptData.is_balance_receipt && !receiptData.is_tab_balance_receipt && !receiptData.is_payment_receipt) {
                    receiptData.print_only = true;
                }
                
                // Add business info to receipt data
                var dataWithBusiness = Object.assign({}, receiptData, {
                    business_name: receiptData.business_name || businessInfo.business_name,
                    location: receiptData.location || businessInfo.location,
                    phone: receiptData.phone || businessInfo.phone,
                    footer_text: receiptData.footer_text || businessInfo.footer_text,
                    vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                    vat_rate: receiptData.vat_rate || businessInfo.vat_rate
                });
                
                // Use fetch to receipt.php - the interceptor will catch this
                return fetch('../receipt.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dataWithBusiness)
                }).then(function(r) { 
                    return r.json();
                });
            }
        }

        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            <?php if ($printMode): ?>
            // Auto print receipt if print parameter is set
            setTimeout(function() {
                printReceipt();
            }, 500);
            <?php endif; ?>
        });

        // Print receipt via thermal printer
        async function printReceipt() {
            const printBtn = document.getElementById('printReceiptBtn');
            const originalText = printBtn.innerHTML;
            
            try {
                printBtn.disabled = true;
                printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Printing...';
                
                // Prepare receipt data for the cashier
                const receiptData = {
                    is_cashup_report: true,
                    date: '<?php echo htmlspecialchars($selectedDate); ?>',
                    cashier_username: '<?php echo htmlspecialchars($employeeName, ENT_QUOTES); ?>',
                    employees: [{
                        name: '<?php echo htmlspecialchars($employeeName, ENT_QUOTES); ?>',
                        cash_sales: <?php echo number_format($totalCash, 2, '.', ''); ?>,
                        eft_sales: <?php echo number_format($totalEft, 2, '.', ''); ?>,
                        total_sales: <?php echo number_format($totalSales, 2, '.', ''); ?>
                    }],
                    total_cash_sales: <?php echo number_format($totalCash, 2, '.', ''); ?>,
                    total_eft_sales: <?php echo number_format($totalEft, 2, '.', ''); ?>,
                    grand_total: <?php echo number_format($totalSales, 2, '.', ''); ?>,
                    cash_sales: <?php echo number_format($totalCash, 2, '.', ''); ?>,
                    eft_sales: <?php echo number_format($totalEft, 2, '.', ''); ?>
                };
                
                // Send to printer (Android native or server)
                const result = await sendToPrinter(receiptData);
                
                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Receipt Printed',
                        text: 'Employee cash-up receipt printed successfully!',
                        timer: 2000,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Print Error',
                        text: result.message || 'Failed to print receipt'
                    });
                }
            } catch (error) {
                console.error('Print error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Print Error',
                    text: 'Error printing receipt: ' + error.message
                });
            } finally {
                printBtn.disabled = false;
                printBtn.innerHTML = originalText;
            }
        }

        // Tab switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to selected tab button
            const activeBtn = document.querySelector(`.tab-button[data-tab="${tabName}"]`);
            if (activeBtn) {
                activeBtn.classList.add('active');
                activeBtn.classList.remove('border-transparent', 'text-gray-500');
            }
        }

        // Update date and reload
        function updateDate() {
            const date = document.getElementById('dateSelect').value;
            const cashierId = '<?php echo htmlspecialchars($cashierId); ?>';
            window.location.href = `view-cashup.php?cashier=${encodeURIComponent(cashierId)}&date=${date}`;
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
