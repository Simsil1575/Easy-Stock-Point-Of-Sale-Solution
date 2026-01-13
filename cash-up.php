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

// Check activation status with expiration
require_once 'activation_helper.php';
$activationCheck = checkActivationStatus();
if ($activationCheck['status'] === 'not_activated' || $activationCheck['status'] === 'expired') {
    header('Location: settings');
    exit();
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00'; // Default to 00:00 if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '00:00';
}

// Database connection
$db = new PDO('sqlite:pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}

// Get selected date from GET parameter, default to today
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Calculate next day for business day logic
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch daily sales grouped by employee (cashier_id)
$employeeSalesQuery = $db->prepare("
    WITH order_totals AS (
        SELECT 
            o.cashier_id,
            o.id as order_id,
            o.total,
            o.created_at,
            COALESCE(SUM(ep.amount), 0) as eft_amount,
            (o.total - COALESCE(SUM(ep.amount), 0)) as cash_amount
        FROM orders o
        LEFT JOIN eft_payments ep ON o.id = ep.order_id
        WHERE (
            (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
        GROUP BY o.id, o.cashier_id, o.total, o.created_at
    ),
    employee_summary AS (
        SELECT 
            cashier_id,
            COUNT(DISTINCT order_id) as total_orders,
            SUM(cash_amount) as total_cash,
            SUM(eft_amount) as total_eft,
            SUM(total) as total_sales
        FROM order_totals
        GROUP BY cashier_id
    )
    SELECT 
        es.cashier_id,
        es.total_orders,
        es.total_cash,
        es.total_eft,
        es.total_sales
    FROM employee_summary es
    ORDER BY es.total_sales DESC
");

$employeeSalesQuery->bindParam(':selectedDate', $selectedDate);
$employeeSalesQuery->bindParam(':nextDay', $nextDay);
$employeeSalesQuery->bindParam(':closingTime', $closingTime);
$employeeSalesQuery->execute();
$employeeSales = $employeeSalesQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch items sold by each employee
$employeeItemsQuery = $db->prepare("
    SELECT 
        o.cashier_id,
        oi.product_name,
        SUM(oi.quantity) as total_quantity,
        oi.price,
        SUM(oi.quantity * oi.price) as total_value
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    GROUP BY o.cashier_id, oi.product_name, oi.price
    ORDER BY o.cashier_id, total_value DESC
");

$employeeItemsQuery->bindParam(':selectedDate', $selectedDate);
$employeeItemsQuery->bindParam(':nextDay', $nextDay);
$employeeItemsQuery->bindParam(':closingTime', $closingTime);
$employeeItemsQuery->execute();
$employeeItems = $employeeItemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Group items by employee
$itemsByEmployee = [];
foreach ($employeeItems as $item) {
    $cashierId = $item['cashier_id'] ?? 'Unknown';
    if (!isset($itemsByEmployee[$cashierId])) {
        $itemsByEmployee[$cashierId] = [];
    }
    $itemsByEmployee[$cashierId][] = $item;
}

// Calculate grand totals
$grandTotalCash = 0;
$grandTotalEft = 0;
$grandTotalSales = 0;
foreach ($employeeSales as $emp) {
    $grandTotalCash += $emp['total_cash'];
    $grandTotalEft += $emp['total_eft'];
    $grandTotalSales += $emp['total_sales'];
}

// Calculate credit sales (unpaid credit sales created on this date)
$creditSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(total_amount - paid_amount), 0) as total_credit
    FROM credit_sales
    WHERE payment_status = 'unpaid' AND (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
");
$creditSalesQuery->bindParam(':selectedDate', $selectedDate);
$creditSalesQuery->bindParam(':nextDay', $nextDay);
$creditSalesQuery->bindParam(':closingTime', $closingTime);
$creditSalesQuery->execute();
$totalCreditSales = $creditSalesQuery->fetchColumn();

// Calculate tab sales (unpaid tab items added on this date)
// This represents new credit sales via tabs during the business day
$tabSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(
        CASE 
            WHEN COALESCE(paid.total_paid, 0) >= (ti.price * ti.quantity) THEN 0
            ELSE (ti.price * ti.quantity) - COALESCE(paid.total_paid, 0)
        END
    ), 0) as total_tab_sales
    FROM tab_items ti
    JOIN tabs t ON ti.tab_id = t.id
    LEFT JOIN (
        SELECT tab_item_id, SUM(amount) as total_paid
        FROM tab_item_payments
        GROUP BY tab_item_id
    ) paid ON ti.id = paid.tab_item_id
    WHERE t.status = 'open' AND (
        (DATE(ti.added_at) = :selectedDate AND strftime('%H:%M', ti.added_at) >= :closingTime) OR
        (DATE(ti.added_at) = :nextDay AND strftime('%H:%M', ti.added_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
");
$tabSalesQuery->bindParam(':selectedDate', $selectedDate);
$tabSalesQuery->bindParam(':nextDay', $nextDay);
$tabSalesQuery->bindParam(':closingTime', $closingTime);
$tabSalesQuery->execute();
$totalTabSales = $tabSalesQuery->fetchColumn();

// Get distinct dates for date selector
$distinctDatesQuery = $db->query("
    SELECT DISTINCT DATE(created_at) as sale_date
    FROM orders
    ORDER BY sale_date DESC
    LIMIT 30
");
$availableDates = $distinctDatesQuery->fetchAll(PDO::FETCH_COLUMN);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Up - Daily Sales by Employee</title>
    <script src="navigation.js" async></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link href="src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="src/jquery-3.6.0.min.js"></script>

    <style>
        /* Main Layout Structure - Match reports.php */
        * {
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
        
        .employee-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .employee-info {
            flex: 1;
        }
        
        .employee-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.25rem;
        }
        
        .employee-meta {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .employee-totals {
            text-align: right;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .total-item {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .total-label {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .total-value {
            font-size: 1.125rem;
            font-weight: 700;
        }
        
        .total-value-large {
            font-size: 1.5rem;
        }
        
        /* Items Table */
        .items-section {
            margin-top: 1rem;
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }
        
        .items-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
        }
        
        .items-table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            background: white;
            table-layout: fixed;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .items-table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .items-table th:first-child {
            width: 45%;
        }
        
        .items-table th:nth-child(2) {
            width: 15%;
        }
        
        .items-table th:nth-child(3) {
            width: 20%;
        }
        
        .items-table th:nth-child(4) {
            width: 20%;
        }
        
        .items-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            color: #374151;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 0;
        }
        
        .items-table td:first-child {
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .items-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .total-row {
            font-weight: 700;
            background-color: #f3f4f6;
        }
        
        .total-row td {
            padding-top: 1rem;
            padding-bottom: 1rem;
            border-top: 2px solid #e5e7eb;
            border-bottom: none;
        }
        
        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
            .print-section {
                page-break-after: always;
            }
            .print-section:last-child {
                page-break-after: auto;
            }
            .employee-card {
                page-break-inside: avoid;
            }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header-content,
            .summary-section,
            .employees-section {
                padding: 0 1rem;
            }
            
            .header-top {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .employee-header {
                flex-direction: column;
                gap: 1rem;
            }
            
            .employee-totals {
                text-align: left;
                align-items: flex-start;
            }
            
            .items-table {
                font-size: 0.75rem;
                min-width: 100%;
            }
            
            .items-table th,
            .items-table td {
                padding: 0.5rem;
            }
            
            .items-table th:first-child {
                width: 40%;
            }
            
            .items-table th:nth-child(2) {
                width: 15%;
            }
            
            .items-table th:nth-child(3) {
                width: 22.5%;
            }
            
            .items-table th:nth-child(4) {
                width: 22.5%;
            }
            
            .summary-cards {
                grid-template-columns: 1fr;
            }
        }
        
    </style>
</head>
<body class="bg-gray-50" style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <!-- Header Row: Daily Cash Up Report + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm no-print">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Daily Cash Up Report</h1>
                    </div>
                    
                    <!-- Date Selection and Print Buttons -->
                    <div class="flex items-center gap-2">
                        <label for="dateSelect" class="text-sm font-medium text-gray-700">Date:</label>
                        <input 
                            type="date" 
                            id="dateSelect" 
                            value="<?php echo htmlspecialchars($selectedDate); ?>" 
                            class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                            onchange="window.location.href='cash-up?date=' + this.value"
                        >
                        <button 
                            onclick="printReceipt()" 
                            class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors flex items-center gap-2 shadow-sm"
                            id="printReceiptBtn"
                        >
                            <i class="fas fa-receipt"></i>
                            <span class="hidden sm:inline">Print Receipt</span>
                            <span class="sm:hidden">Receipt</span>
                        </button>
                        <button 
                            onclick="window.print()" 
                            class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors flex items-center gap-2 shadow-sm"
                        >
                            <i class="fas fa-print"></i>
                            <span class="hidden sm:inline">Print Page</span>
                            <span class="sm:hidden">Page</span>
                        </button>
                    </div>
                </div>

                <!-- Print Header (only visible when printing) -->
                <div class="print-section hidden print:block text-center mb-6 px-4">
                    <h1 class="text-2xl font-bold mb-2">Cash Up Report</h1>
                    <p class="text-lg">Date: <?php echo date('l, F j, Y', strtotime($selectedDate)); ?></p>
                    <p class="text-sm text-gray-600">Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>

                <!-- Summary Cards Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8 no-print">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Cash Sales</p>
                                <h3 id="summaryCashSales" class="text-2xl font-bold text-teal-600">N$<?php echo number_format($grandTotalCash, 2); ?></h3>
                            </div>
                            <div class="p-3 bg-teal-100 rounded-full">
                                <i class="fas fa-dollar-sign text-teal-600 text-lg"></i>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total EFT Sales</p>
                                <h3 id="summaryEftSales" class="text-2xl font-bold text-purple-600">N$<?php echo number_format($grandTotalEft, 2); ?></h3>
                            </div>
                            <div class="p-3 bg-purple-100 rounded-full">
                                <i class="fas fa-credit-card text-purple-600 text-lg"></i>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Grand Total</p>
                                <h3 id="summaryGrandTotal" class="text-2xl font-bold text-gray-800">N$<?php echo number_format($grandTotalSales, 2); ?></h3>
                            </div>
                            <div class="p-3 bg-gray-100 rounded-full">
                                <i class="fas fa-wallet text-gray-600 text-lg"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Employee Sales Section -->
                <?php if (empty($employeeSales)): ?>
                    <div class="bg-white shadow-lg rounded-xl overflow-hidden p-8 text-center">
                        <p class="text-gray-600 text-lg">No sales found for the selected date.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($employeeSales as $employee): ?>
                        <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-6 print-section">
                            <div class="p-6">
                            <div class="employee-header">
                                <div class="employee-info">
                                    <h2 class="employee-name">
                                        <?php echo htmlspecialchars($employee['cashier_id'] ?? 'Unknown Employee'); ?>
                                    </h2>
                                    <p class="employee-meta">Total Orders: <?php echo $employee['total_orders']; ?></p>
                                </div>
                                <div class="employee-totals">
                                    <div class="total-item">
                                        <span class="total-label">Cash Sales</span>
                                        <span class="total-value text-teal-600">N$ <?php echo number_format($employee['total_cash'], 2); ?></span>
                                    </div>
                                    <div class="total-item">
                                        <span class="total-label">EFT Sales</span>
                                        <span class="total-value text-purple-600">N$ <?php echo number_format($employee['total_eft'], 2); ?></span>
                                    </div>
                                    <div class="total-item">
                                        <span class="total-label">Total Sales</span>
                                        <span class="total-value total-value-large text-gray-800">N$ <?php echo number_format($employee['total_sales'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Items Sold Section -->
                            <?php 
                            $cashierId = $employee['cashier_id'] ?? 'Unknown';
                            $items = $itemsByEmployee[$cashierId] ?? [];
                            ?>
                            <?php if (!empty($items)): ?>
                                <div class="items-section">
                                    <h3 class="items-section-title">Items Sold</h3>
                                    <table class="items-table">
                                        <thead>
                                            <tr>
                                                <th>Product Name</th>
                                                <th class="text-right">Quantity</th>
                                                <th class="text-right">Unit Price</th>
                                                <th class="text-right">Total Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $employeeItemTotal = 0;
                                            foreach ($items as $item): 
                                                $employeeItemTotal += $item['total_value'];
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                    <td class="text-right"><?php echo $item['total_quantity']; ?></td>
                                                    <td class="text-right">N$ <?php echo number_format($item['price'], 2); ?></td>
                                                    <td class="text-right font-semibold">N$ <?php echo number_format($item['total_value'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td colspan="3" class="text-right font-bold">Subtotal:</td>
                                                <td class="text-right font-bold">N$ <?php echo number_format($employeeItemTotal, 2); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm mt-4">No items found for this employee.</p>
                            <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Grand Total Summary -->
                    <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-6 print-section bg-gray-50">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4 pb-4 border-b-2 border-gray-200">
                                <h2 class="text-xl font-bold text-gray-800">Grand Total Summary</h2>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-600 mb-2">Total Cash Sales</p>
                                    <p class="text-2xl font-bold text-teal-600">N$<?php echo number_format($grandTotalCash, 2); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-600 mb-2">Total EFT Sales</p>
                                    <p class="text-2xl font-bold text-purple-600">N$<?php echo number_format($grandTotalEft, 2); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-600 mb-2">Grand Total Sales</p>
                                    <p class="text-2xl font-bold text-gray-800">N$<?php echo number_format($grandTotalSales, 2); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

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

        // Helper function to send receipt to printer - uses Android native printing if available
        function sendToPrinter(receiptData) {
            var dataWithBusiness = Object.assign({}, receiptData, {
                business_name: receiptData.business_name || businessInfo.business_name,
                location: receiptData.location || businessInfo.location,
                phone: receiptData.phone || businessInfo.phone,
                footer_text: receiptData.footer_text || businessInfo.footer_text,
                vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                vat_rate: receiptData.vat_rate || businessInfo.vat_rate
            });
            
            var printer = window.AndroidPrinter || window.NativePrinter || null;
            
            if (printer && typeof printer.printReceipt === 'function') {
                console.log('[sendToPrinter] Using Android native printing');
                try {
                    printer.printReceipt(JSON.stringify(dataWithBusiness));
                    return Promise.resolve({ success: true, message: 'Printed via Android', printer_type: 'android_native' });
                } catch (e) {
                    console.error('[sendToPrinter] Android print error:', e.message);
                }
            }
            
            return fetch('receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dataWithBusiness)
            }).then(function(r) { return r.json(); });
        }

        // Fetch accurate amounts from admin/fetch_report_data.php on page load
        async function fetchAccurateAmounts() {
            try {
                const selectedDate = document.getElementById('dateSelect').value;
                const formData = new FormData();
                formData.append('date', selectedDate);
                
                const response = await fetch('admin/fetch_report_data.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error('Failed to fetch report data');
                }
                
                const data = await response.json();
                
                // Update summary cards with accurate amounts
                if (data.cashSalesTotal !== undefined) {
                    document.getElementById('summaryCashSales').textContent = 'N$' + parseFloat(data.cashSalesTotal).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                if (data.eftSalesTotal !== undefined) {
                    document.getElementById('summaryEftSales').textContent = 'N$' + parseFloat(data.eftSalesTotal).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                if (data.cashSalesTotal !== undefined && data.eftSalesTotal !== undefined) {
                    const grandTotal = parseFloat(data.cashSalesTotal) + parseFloat(data.eftSalesTotal);
                    document.getElementById('summaryGrandTotal').textContent = 'N$' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                
                // Update grand total summary section
                if (data.cashSalesTotal !== undefined) {
                    document.getElementById('grandTotalCash').textContent = 'N$' + parseFloat(data.cashSalesTotal).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                if (data.eftSalesTotal !== undefined) {
                    document.getElementById('grandTotalEft').textContent = 'N$' + parseFloat(data.eftSalesTotal).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                if (data.cashSalesTotal !== undefined && data.eftSalesTotal !== undefined) {
                    const grandTotal = parseFloat(data.cashSalesTotal) + parseFloat(data.eftSalesTotal);
                    document.getElementById('grandTotalSales').textContent = 'N$' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            } catch (error) {
                console.error('Error fetching accurate amounts:', error);
                // Keep the original values if fetch fails
            }
        }
        
        // Fetch accurate employee stats from admin/get_employee_stats.php
        async function fetchEmployeeStats() {
            try {
                const selectedDate = document.getElementById('dateSelect').value;
                const response = await fetch(`admin/get_employee_stats.php?date=${selectedDate}`);
                
                if (!response.ok) {
                    throw new Error('Failed to fetch employee stats');
                }
                
                const data = await response.json();
                
                if (data.success && data.employees && data.employees.length > 0) {
                    // Update employee cards with accurate data
                    const employeeCards = document.querySelectorAll('.print-section:not(.bg-gray-50)');
                    const employeeStatsMap = {};
                    
                    // Create a map of employee stats by cashier_id
                    data.employees.forEach(emp => {
                        employeeStatsMap[emp.id] = emp;
                    });
                    
                    employeeCards.forEach(card => {
                        // Skip the grand total summary card
                        if (card.querySelector('.text-xl.font-bold.text-gray-800')?.textContent.includes('Grand Total Summary')) {
                            return;
                        }
                        
                        // Get cashier ID from the employee name (it's displayed as cashier_id in cash-up.php)
                        const employeeName = card.querySelector('.employee-name')?.textContent.trim();
                        if (!employeeName) return;
                        
                        // Try to find matching employee by name or ID
                        let matchingEmp = null;
                        for (const [id, emp] of Object.entries(employeeStatsMap)) {
                            if (emp.name === employeeName || id === employeeName) {
                                matchingEmp = emp;
                                break;
                            }
                        }
                        
                        if (matchingEmp) {
                            // Update cash sales
                            const cashEl = card.querySelector('.total-value.text-teal-600');
                            if (cashEl) {
                                cashEl.textContent = 'N$ ' + parseFloat(matchingEmp.cashSales || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                            
                            // Update EFT sales
                            const eftEl = card.querySelector('.total-value.text-purple-600');
                            if (eftEl) {
                                eftEl.textContent = 'N$ ' + parseFloat(matchingEmp.eftSales || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                            
                            // Update total sales
                            const totalEl = card.querySelector('.total-value-large');
                            if (totalEl) {
                                const totalSales = parseFloat(matchingEmp.cashSales || 0) + parseFloat(matchingEmp.eftSales || 0);
                                totalEl.textContent = 'N$ ' + totalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                            
                            // Update total orders
                            const ordersMeta = card.querySelector('.employee-meta');
                            if (ordersMeta && ordersMeta.textContent.includes('Total Orders')) {
                                ordersMeta.textContent = 'Total Orders: ' + (matchingEmp.totalOrders || 0);
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error fetching employee stats:', error);
                // Keep the original values if fetch fails
            }
        }
        
        // Update date and fetch accurate amounts
        function updateDateAndFetch() {
            const date = document.getElementById('dateSelect').value;
            window.location.href = 'cash-up?date=' + date;
        }
        
        // Fetch accurate amounts and employee stats when page loads
        document.addEventListener('DOMContentLoaded', function() {
            fetchAccurateAmounts();
            // Delay employee stats fetch slightly to ensure DOM is ready
            setTimeout(fetchEmployeeStats, 200);
        });
        
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
        
        // Print receipt via receipt.php
        async function printReceipt() {
            const printBtn = document.getElementById('printReceiptBtn');
            const originalText = printBtn.innerHTML;
            
            try {
                printBtn.disabled = true;
                printBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Printing...';
                
                // Get the selected date
                const selectedDate = document.getElementById('dateSelect').value;
                
                // Collect employee data
                const employeeData = [];
                // Find all employee cards (divs with print-section class, excluding the grand total summary)
                const employeeCards = document.querySelectorAll('.print-section:not(.bg-gray-50)');
                
                employeeCards.forEach(card => {
                    // Skip the grand total summary card
                    if (card.querySelector('.text-xl.font-bold.text-gray-800')?.textContent.includes('Grand Total Summary')) {
                        return;
                    }
                    
                    const employeeName = card.querySelector('.employee-name')?.textContent.trim() || 'Unknown';
                    const totalOrders = card.querySelector('.employee-meta')?.textContent.match(/\d+/)?.[0] || '0';
                    const cashSalesText = card.querySelector('.total-value.text-teal-600')?.textContent || 'N$ 0.00';
                    const eftSalesText = card.querySelector('.total-value.text-purple-600')?.textContent || 'N$ 0.00';
                    const totalSalesText = card.querySelector('.total-value-large')?.textContent || 'N$ 0.00';
                    
                    // Extract numeric values
                    const cashSales = parseFloat(cashSalesText.replace(/[^0-9.]/g, '')) || 0;
                    const eftSales = parseFloat(eftSalesText.replace(/[^0-9.]/g, '')) || 0;
                    const totalSales = parseFloat(totalSalesText.replace(/[^0-9.]/g, '')) || 0;
                    
                    // Collect items
                    const items = [];
                    const itemRows = card.querySelectorAll('.items-table tbody tr:not(.total-row)');
                    itemRows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        if (cells.length >= 4) {
                            items.push({
                                name: cells[0].textContent.trim(),
                                quantity: parseInt(cells[1].textContent.trim()) || 0,
                                price: parseFloat(cells[2].textContent.replace(/[^0-9.]/g, '')) || 0
                            });
                        }
                    });
                    
                    employeeData.push({
                        name: employeeName,
                        total_orders: parseInt(totalOrders),
                        cash_sales: cashSales,
                        eft_sales: eftSales,
                        total_sales: totalSales,
                        items: items
                    });
                });
                
                // Get grand totals from summary cards (use IDs for accurate values)
                let grandTotalCash = 0;
                let grandTotalEft = 0;
                let grandTotal = 0;
                
                const cashEl = document.getElementById('summaryCashSales');
                const eftEl = document.getElementById('summaryEftSales');
                const totalEl = document.getElementById('summaryGrandTotal');
                
                if (cashEl) grandTotalCash = parseFloat(cashEl.textContent.replace(/[^0-9.]/g, '') || '0');
                if (eftEl) grandTotalEft = parseFloat(eftEl.textContent.replace(/[^0-9.]/g, '') || '0');
                if (totalEl) grandTotal = parseFloat(totalEl.textContent.replace(/[^0-9.]/g, '') || '0');
                
                // Fallback: if summary cards not found, try grand total summary section
                if ((grandTotalCash === 0 && grandTotalEft === 0 && grandTotal === 0)) {
                    const grandTotalSection = document.querySelector('.bg-gray-50.print-section');
                    if (grandTotalSection) {
                        const cashEl = grandTotalSection.querySelector('p.text-teal-600');
                        const eftEl = grandTotalSection.querySelector('p.text-purple-600');
                        const totalEl = grandTotalSection.querySelector('p.text-gray-800');
                        
                        if (cashEl) grandTotalCash = parseFloat(cashEl.textContent.replace(/[^0-9.]/g, '')) || 0;
                        if (eftEl) grandTotalEft = parseFloat(eftEl.textContent.replace(/[^0-9.]/g, '')) || 0;
                        if (totalEl) grandTotal = parseFloat(totalEl.textContent.replace(/[^0-9.]/g, '')) || 0;
                    }
                }
                
                // Prepare data for receipt.php
                const receiptData = {
                    is_cashup_report: true,
                    date: selectedDate,
                    cashier_username: '<?php echo $_SESSION['username'] ?? 'Cashier'; ?>',
                    employees: employeeData,
                    total_cash_sales: grandTotalCash,
                    total_eft_sales: grandTotalEft,
                    grand_total: grandTotal,
                    total_income: grandTotal,
                    cash_sales: grandTotalCash,
                    eft_sales: grandTotalEft,
                    credit_sales: <?php echo number_format($totalCreditSales, 2, '.', ''); ?>,
                    tab_sales: <?php echo number_format($totalTabSales, 2, '.', ''); ?>,
                    credit_unpaid: <?php echo number_format($totalCreditSales, 2, '.', ''); ?>,
                    credit_cash: 0,
                    credit_eft: 0,
                    total_expense: 0
                };
                
                // Send to printer (Android native or server)
                const result = await sendToPrinter(receiptData);
                
                if (result.success) {
                    alert('Cash-up receipt printed successfully!');
                } else {
                    alert('Error printing receipt: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Print error:', error);
                alert('Error printing receipt: ' + error.message);
            } finally {
                printBtn.disabled = false;
                printBtn.innerHTML = originalText;
            }
        }
    </script>
</body>
</html>
