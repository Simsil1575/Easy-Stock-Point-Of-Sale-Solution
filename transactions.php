<?php
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
    $closingTime = $businessInfo['closing_time'] ?? '00:00'; // Default to 10:00 PM if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '00:00';
}

// Database connection
$db = new PDO('sqlite:pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
$isAfterMidnight = $closingHour < 12;

// Prepare date calculation snippet for SQL
$dateSql = "
    CASE 
        WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
        THEN date(datetime(created_at, '-1 day'))
        ELSE date(created_at)
    END AS business_date
";

// Fetch distinct dates where transactions occurred, considering business closing time
// MOVED THIS QUERY BEFORE IT'S REFERENCED IN THE HTML
$distinctDatesQuery = $db->prepare("
    SELECT DISTINCT business_date
    FROM (
        SELECT
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date
        FROM orders
        UNION ALL
        SELECT
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date
        FROM credit_sales
    )
    ORDER BY business_date DESC
");
$distinctDatesQuery->execute();
$distinctDates = $distinctDatesQuery->fetchAll(PDO::FETCH_COLUMN);

// Always add today's date if it's not already in the list
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

if (!in_array($today, $distinctDates)) {
    array_unshift($distinctDates, $today); // Add today at the beginning of the array
}

// Also add yesterday's date if it's not already in the list
if (!in_array($yesterday, $distinctDates)) {
    array_unshift($distinctDates, $yesterday); // Add yesterday at the beginning of the array
}

// Determine which date to show by default based on current time vs closing time
$currentTime = date('H:i');

// If current time is before closing time, show yesterday's data
// If current time is after closing time, show today's data
$defaultDate = ($currentTime < $closingTime) ? $yesterday : $today;

// Handle date selection
$selectedDate = isset($_POST['date']) ? $_POST['date'] : $defaultDate;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
    <script src="navigation.js" async></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link href="src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="src/jquery-3.6.0.min.js"></script>


    <style>
        :root {
            --table-row-height: 27px;
        }
        .sidebar {
            position: fixed;
            height: 100%;
            width: 250px; /* Ensure sidebar has a fixed width */
        }
        .content {
            margin-left: 250px; /* Adjust this value based on the width of your sidebar */
            width: calc(100vw - 250px); /* Ensure content width fits within the viewport */
            overflow-x: hidden; /* Prevent horizontal overflow */
        }
        .container {
            max-width: 100vw; /* Ensure container does not exceed viewport width */
            padding: 0 1rem; /* Add some padding for better spacing */
        }
        /* Compact Table Styles */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.75rem !important; /* 12px */
            table-layout: fixed; /* Ensure table does not exceed container width */
        }
        th, td {
            padding: 0.375rem 0.5rem !important; /* 6px 8px - very compact */
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            color: #374151;
            font-weight: 500;
            vertical-align: middle;
            overflow: hidden; /* Prevent content from overflowing */
            text-overflow: ellipsis; /* Add ellipsis for overflow text */
            white-space: nowrap; /* Prevent text from wrapping */
            height: var(--table-row-height) !important;
            line-height: 1.2 !important;
        }
        th {
            background-color: #f9fafb; 
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            font-size: 0.7rem !important;
            letter-spacing: 0.025em;
            height: calc(var(--table-row-height) + 0.5rem) !important;
        }
        td:nth-child(2),
        td:nth-child(3) {
            font-weight: 600;
            color: #111827;
        }
        tr {
            transition: all 0.2s ease;
            height: var(--table-row-height) !important;
        }
        tr:hover {
            background-color: #f3f4f6;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .table-container {
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            width: 100%; /* Ensure table container fits within the viewport */
        }
        /* Make status badges smaller */
        td span.inline-flex {
            font-size: 0.7rem !important;
            padding: 0.15rem 0.375rem !important;
            height: var(--table-row-height) !important;
            line-height: 1.2 !important;
            display: inline-flex !important;
            align-items: center !important;
            margin: 0 !important;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); /* Ensure grid items fit within the viewport */
            gap: 1rem;
        }
        .bg-header {
            background-color: #f3f4f6;
            border-bottom: 2px solid #e5e7eb;
        }
        .sort-icon {
            opacity: 0.5;
            transition: all 0.2s;
        }
        th:hover .sort-icon {
            opacity: 1;
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
                <!-- Header Row: Daily Report + Controls -->
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
                    <h1 class="text-3xl font-bold mb-0">Daily Report</h1>
                    <div class="flex flex-col md:flex-row md:items-end gap-4 w-full md:w-auto">
                        <form method="POST" class="flex flex-col md:flex-row md:items-end gap-4 w-full md:w-auto" id="dateForm">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 0 0 -2H6z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <select id="date" name="date" onchange="updateReport();" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 pr-10 p-2.5 shadow-sm transition-colors cursor-pointer">
                                    <?php foreach ($distinctDates as $date): ?>
                                        <option value="<?= htmlspecialchars($date) ?>" <?= $date == $selectedDate ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($date) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
    
                            </div>
                        </form>
                        <button id="printCashup"
                           class="h-[42px] flex items-center justify-center px-6 bg-gray-500 hover:bg-gray-600 text-white font-semibold rounded-lg shadow-sm transition duration-200 ease-in-out transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-opacity-50 mt-2 md:mt-0">
                            <i class="fas fa-print mr-2"></i>
                            Cash-up
                        </button>
                        <div class="flex items-center space-x-2 w-full md:w-auto">
                            <div class="relative flex-grow max-w-72">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                                    </svg>
                                </div>
                                <input type="text" id="search" onkeyup="filterSales()" placeholder="Search by any field..." 
                                       class="w-full pl-10 pr-4 py-2.5 border border-gray-400 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none focus:border-blue-500 shadow-sm transition duration-200">
                            </div>
                         
                        </div>
                    </div>
                </div>

                <?php
                // Calculate the next day date for queries
                $nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

                // Fetch cash sales total with business day logic
                $cashSalesQuery = $db->prepare("
                    SELECT SUM(total) FROM orders 
                    WHERE (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
                        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
                $cashSalesQuery->bindParam(':nextDay', $nextDay);
                $cashSalesQuery->execute();
                $cashSalesTotal = $cashSalesQuery->fetchColumn() ?: 0;

                // Get cumulative cash sales up to selected date
                $cumulativeCashSalesQuery = $db->prepare("
                    SELECT SUM(o.total) 
                    FROM orders o
                    LEFT JOIN eft_payments e ON o.id = e.order_id
                    WHERE e.order_id IS NULL AND DATE(o.created_at) <= :selectedDate
                ");
                $cumulativeCashSalesQuery->bindParam(':selectedDate', $selectedDate);
                $cumulativeCashSalesQuery->execute();
                $cumulativeCashSales = $cumulativeCashSalesQuery->fetchColumn() ?: 0;

                // Get today's cash in/out with business day logic
                $cashInQuery = $db->prepare("
                    SELECT SUM(amount) FROM cash_transactions 
                    WHERE type = 'cash-in' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
                        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $cashInQuery->bindParam(':selectedDate', $selectedDate);
                $cashInQuery->bindParam(':nextDay', $nextDay);
                $cashInQuery->execute();
                $totalCashIn = $cashInQuery->fetchColumn() ?: 0;

                $cashOutQuery = $db->prepare("
                    SELECT SUM(amount) FROM cash_transactions 
                    WHERE type = 'cash-out' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
                        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $cashOutQuery->bindParam(':selectedDate', $selectedDate);
                $cashOutQuery->bindParam(':nextDay', $nextDay);
                $cashOutQuery->execute();
                $totalCashOut = $cashOutQuery->fetchColumn() ?: 0;

                // Calculate EFT payments with business day logic
                $eftSalesQuery = $db->prepare("
                    SELECT SUM(e.amount) 
                    FROM eft_payments e 
                    JOIN orders o ON e.order_id = o.id 
                    WHERE (
                        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
                        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $eftSalesQuery->bindParam(':selectedDate', $selectedDate);
                $eftSalesQuery->bindParam(':nextDay', $nextDay);
                $eftSalesQuery->execute();
                $eftSalesTotal = $eftSalesQuery->fetchColumn() ?: 0;

                // Get credit sales with payment_status = 'eft' with business day logic
                $eftCreditSalesQuery = $db->prepare("
                    SELECT SUM(total_amount) 
                    FROM credit_sales 
                    WHERE payment_status = 'eft' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
                        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $eftCreditSalesQuery->bindParam(':selectedDate', $selectedDate);
                $eftCreditSalesQuery->bindParam(':nextDay', $nextDay);
                $eftCreditSalesQuery->execute();
                $eftCreditSalesTotal = $eftCreditSalesQuery->fetchColumn() ?: 0;

                // Total EFT payments including both regular EFT and credit sales with payment_status 'eft'
                $totalEftPayments = $eftSalesTotal + $eftCreditSalesTotal;

                // Fetch credit sales total and unpaid balances with business day logic
                $creditSalesQuery = $db->prepare("
                    SELECT 
                    SUM(total_amount) as total_issued,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount - paid_amount ELSE 0 END) as total_unpaid 
                    FROM credit_sales 
                    WHERE (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
                        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $creditSalesQuery->bindParam(':selectedDate', $selectedDate);
                $creditSalesQuery->bindParam(':nextDay', $nextDay);
                $creditSalesQuery->execute();
                $creditData = $creditSalesQuery->fetch(PDO::FETCH_ASSOC);
                $creditTotal = $creditData['total_issued'] ?: 0;
                $unpaidTotal = $creditData['total_unpaid'] ?: 0;

                // Use unpaid total for the selected day instead of all-time unpaid credit
                $totalUnpaidCredit = $unpaidTotal;

                // Get cumulative paid credit sales up to selected date
                $cumulativePaidCreditQuery = $db->prepare("
                    SELECT SUM(CASE 
                        WHEN payment_status = 'paid' THEN total_amount
                        WHEN payment_status = 'partial' THEN paid_amount
                        ELSE 0 
                    END) as paid_credit
                    FROM credit_sales 
                    WHERE DATE(created_at) <= :selectedDate
                ");
                $cumulativePaidCreditQuery->bindParam(':selectedDate', $selectedDate);
                $cumulativePaidCreditQuery->execute();
                $cumulativePaidCredit = $cumulativePaidCreditQuery->fetchColumn() ?: 0;

                // Get paid credit sales for the selected period (for daily reports)
                $paidCreditQuery = $db->prepare("
                    SELECT SUM(CASE 
                        WHEN payment_status = 'paid' THEN total_amount
                        WHEN payment_status = 'partial' THEN paid_amount
                        ELSE 0 
                    END) as paid_credit
                    FROM credit_sales 
                    WHERE (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= '$closingTime') OR
                        (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $paidCreditQuery->bindParam(':selectedDate', $selectedDate);
                $paidCreditQuery->bindParam(':nextDay', $nextDay);
                $paidCreditQuery->execute();
                $paidCreditAmount = $paidCreditQuery->fetchColumn() ?: 0;

                /*
                Cash available in till is calculated as:
                1. Today's cash deposits (cash-in transactions for selected business day)
                2. Plus today's cash sales (orders paid with cash for selected business day, excluding EFT)
                3. Plus today's paid credit sales (credit that has been paid for selected business day)
                4. Minus today's cash withdrawals (cash-out transactions for selected business day)
                5. EFT sales don't affect physical cash since they're electronic transfers
                */
                // Get today's cash sales (excluding EFT payments)
                $todayCashSalesQuery = $db->prepare("
                    SELECT COALESCE(SUM(o.total), 0)
                    FROM orders o
                    LEFT JOIN eft_payments e ON o.id = e.order_id
                    WHERE e.order_id IS NULL AND (
                        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
                        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                ");
                $todayCashSalesQuery->bindParam(':selectedDate', $selectedDate);
                $todayCashSalesQuery->bindParam(':nextDay', $nextDay);
                $todayCashSalesQuery->execute();
                $todayCashSalesOnly = $todayCashSalesQuery->fetchColumn() ?: 0;
                
                $cashAvailableInTill = $totalCashIn - $totalCashOut + $todayCashSalesOnly + $paidCreditAmount;

                // Total revenue includes all sales regardless of payment method (only for selected date)
                $totalCashOnHand = $cashSalesTotal + $creditTotal;

                // Fetch top selling products with business day logic
                $topProductsQuery = $db->prepare("
                    SELECT 
                        t.product_name, 
                        SUM(t.quantity) as total_qty, 
                        SUM(t.price * t.quantity) as historical_value,
                        p.price as current_price,
                        p.id as id
                    FROM (
                        SELECT 
                            product_name, 
                            quantity, 
                            price,
                            created_at 
                        FROM order_items
                        JOIN orders ON order_items.order_id = orders.id
                        
                        UNION ALL
                        
                        SELECT 
                            product_name, 
                            quantity, 
                            price,
                            created_at
                        FROM credit_sale_items
                        JOIN credit_sales ON credit_sale_items.sale_id = credit_sales.id
                    ) t
                    LEFT JOIN products p ON t.product_name = p.name
                    WHERE (
                        (DATE(t.created_at) = :selectedDate AND strftime('%H:%M', t.created_at) >= '$closingTime') OR
                        (DATE(t.created_at) = :nextDay AND strftime('%H:%M', t.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                    )
                    GROUP BY t.product_name
                    ORDER BY total_qty DESC
                ");
                $topProductsQuery->bindParam(':selectedDate', $selectedDate);
                $topProductsQuery->bindParam(':nextDay', $nextDay);
                $topProductsQuery->execute();
                $topProducts = $topProductsQuery->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-2 gap-6 mb-8">

                    <!-- Cash Sales Card -->
                    <div class="bg-blue-300 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-blue-800">Cash Sales</p>
                                <h3 class="text-2xl font-bold text-blue-800">N$<?= number_format($cashSalesTotal + $paidCreditAmount - $eftSalesTotal, 2) ?></h3>
                            </div>
                            <div class="p-2 bg-blue-300 rounded-full">
                                <i class="fas fa-dollar-sign text-blue-800 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-blue-800">Cash transactions + credit payments</p>
                    </div>

                    <!-- EFT Payments Card -->
                    <div class="bg-teal-200 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-teal-700">EFT Payments</p>
                                <h3 class="text-2xl font-bold text-teal-700">N$<?= number_format($totalEftPayments, 2) ?></h3>
                            </div>
                            <div class="p-2 bg-teal-200 rounded-full">
                                <i class="fas fa-credit-card text-teal-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-teal-600">Electronic Transfers</p>
                    </div>

                    <!-- Unpaid Credit Card -->
                    <div class="bg-yellow-400 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-yellow-800">Unpaid Credit</p>
                                <h3 class="text-2xl font-bold text-yellow-800">N$<?= number_format($totalUnpaidCredit, 2) ?></h3>
                            </div>
                            <div class="p-2 bg-yellow-400 rounded-full">
                                <i class="fas fa-hand-holding-usd text-yellow-800 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-yellow-800">Outstanding Balance</p>
                    </div>

                    <!-- Total Revenue Card -->
                    <div class="bg-teal-400 rounded-lg shadow-lg p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-teal-800">Total Revenue</p>
                                <h3 class="text-2xl font-bold text-teal-800">N$<?= number_format($totalCashOnHand, 2) ?></h3>
                            </div>
                            <div class="p-2 bg-teal-400 rounded-full">
                                <i class="fas fa-wallet text-teal-800 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-teal-800">Credit Sales + Cash Sales</p>
                    </div>
                </div>

                <!-- Cash Transactions Table -->
                <div class="bg-white shadow-lg rounded-xl overflow-hidden my-8">
                    <h2 class="text-xl font-bold p-3 bg-gray-300 text-gray-600 text-center"><i class="fas fa-chart-line mr-2"></i>Transactions</h2>
                    <div class="table-container">
                        <table class="min-w-full table-auto">
                                <thead class="sticky top-0">
                                    <tr class="bg-gray-100 border-b-2 border-gray-200 text-sm">
                                        <th class="py-2 px-3 text-center cursor-pointer" onclick="sortTable(0)">
                                            <div class="flex items-center justify-center">
                                                <span class="text-gray-700 text-center w-full">ID</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-center cursor-pointer" onclick="sortTable(1)">
                                            <div class="flex items-center justify-center">
                                                <span class="text-gray-700 text-center w-full">Type</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTable(2, true)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Total</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTable(3)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Products</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTable(4)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Date</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200" id="salesTableBody">
                                    <?php
                                    // Fetch sales data with business day logic
                                    $ordersQuery = $db->prepare("
                                        SELECT orders.id, orders.total, orders.created_at, 
                                        GROUP_CONCAT(order_items.product_name || ' (x' || order_items.quantity || ')', ', ') as products,
                                        CASE WHEN eft.order_id IS NOT NULL THEN 'eft' ELSE 'cash' END as sale_type,
                                        'paid' as payment_status,
                                        eft.wallet_provider as provider_name
                                        FROM orders
                                        JOIN order_items ON orders.id = order_items.order_id
                                        LEFT JOIN eft_payments eft ON orders.id = eft.order_id
                                        WHERE (
                                            (DATE(orders.created_at) = :selectedDate AND strftime('%H:%M', orders.created_at) >= '$closingTime') OR
                                            (DATE(orders.created_at) = :nextDay AND strftime('%H:%M', orders.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                                        )
                                        GROUP BY orders.id
                                        ORDER BY orders.created_at DESC
                                    ");
                                    
                                    $creditQuery = $db->prepare("
                                        SELECT credit_sales.id, credit_sales.total_amount as total, 
                                        credit_sales.created_at,
                                        GROUP_CONCAT(credit_sale_items.product_name || ' (x' || credit_sale_items.quantity || ')', ', ') as products,
                                        CASE 
                                            WHEN payment_status = 'paid' THEN 'paid' 
                                            WHEN payment_status = 'eft' THEN 'eft'
                                            WHEN payment_status = 'partial' THEN 'partial'
                                            ELSE 'credit' 
                                        END as sale_type,
                                        payment_status,
                                        NULL as provider_name
                                        FROM credit_sales
                                        JOIN credit_sale_items ON credit_sales.id = credit_sale_items.sale_id
                                        WHERE (
                                            (DATE(credit_sales.created_at) = :selectedDate AND strftime('%H:%M', credit_sales.created_at) >= '$closingTime') OR
                                            (DATE(credit_sales.created_at) = :nextDay AND strftime('%H:%M', credit_sales.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
                                        )
                                        GROUP BY credit_sales.id
                                        ORDER BY credit_sales.created_at DESC
                                    ");
                                    $ordersQuery->bindParam(':selectedDate', $selectedDate);
                                    $ordersQuery->bindParam(':nextDay', $nextDay);
                                    $ordersQuery->execute();
                                    $ordersResult = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    $creditQuery->bindParam(':selectedDate', $selectedDate);
                                    $creditQuery->bindParam(':nextDay', $nextDay);
                                    $creditQuery->execute();
                                    $creditResult = $creditQuery->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    // Combine results
                                    $result = array_merge($ordersResult, $creditResult);
                                    
                                    // Sort combined results by created_at in descending order (most recent first)
                                    usort($result, function($a, $b) {
                                        return strtotime($b['created_at']) - strtotime($a['created_at']);
                                    });
                                    
                                    if (count($result) > 0) {
                                        foreach($result as $row) {
                                    ?>
                                        <tr class="hover:bg-gray-50 transition-colors h-6">
                                            <td class="py-1 px-3 text-sm font-medium text-gray-800 text-center"><?= $row['id'] ?></td>
                                            <td class="py-1 px-3 text-center align-middle">
                                                <div class="flex justify-center items-center">
                                                    <?php if ($row['sale_type'] === 'credit' && $row['payment_status'] === 'unpaid'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-amber-100 text-amber-800 border border-amber-200 shadow-sm">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                                                        <span>Unpaid</span>
                                                    </span>
                                                    <?php elseif ($row['sale_type'] === 'partial'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800 border border-yellow-200 shadow-sm">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm0 14a6 6 0 110-12 6 6 0 010 12z"></path><path d="M10 5a1 1 0 011 1v3.586l2.707 2.707a1 1 0 01-1.414 1.414l-3-3A1 1 0 019 10V6a1 1 0 011-1z"></path></svg>
                                                        <span>Partial</span>
                                                    </span>
                                                    <?php elseif ($row['sale_type'] === 'eft'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800 border border-purple-200 shadow-sm">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"></path><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"></path></svg>
                                                        <span>EFT</span>
                                                    </span>
                                                    <?php elseif ($row['sale_type'] === 'cash' || $row['sale_type'] === 'paid'): ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800 border border-blue-200 shadow-sm">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"></path></svg>
                                                        <span>Cash</span>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800 border border-red-200 shadow-sm">
                                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                                        <span><?= ucfirst($row['sale_type']) ?></span>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-1 px-3 text-sm font-bold text-gray-900">N$<?= number_format($row['total'], 2) ?></td>
                                            <td class="py-1 px-3 text-sm text-gray-600 truncate max-w-xs" title="<?= htmlspecialchars($row['products']) ?>">
                                                <?= htmlspecialchars($row['products']) ?>
                                            </td>
                                            <td class="py-1 px-3 text-sm text-gray-500"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
                                    
                                        </tr>
                                    <?php
                                        }
                                    } else {
                                    ?>
                            
                                    <?php
                                    }
                                    ?>
                                </tbody>
                            </table>

                        <!-- Pagination Controls -->
                        <div class="px-6 py-2 bg-gray-50 border-t border-gray-200">
                            <div class="flex justify-between items-center">
                                <div class="flex gap-2">
                                    <button id="firstPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                                        </svg>
                                    </button>
                                    <button id="prevPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                        </svg>
                                        Prev
                                    </button>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span id="pageNumber" class="text-sm text-gray-700 font-medium">Page 1 of 1</span>
                                    <div class="flex items-center gap-2">
                                        <input type="number" id="pageInput" min="1" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-sm shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Page">
                                        <button class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 transition-colors shadow-sm">Go</button>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button id="nextPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        Next
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                    <button id="lastPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow-lg rounded-xl overflow-hidden my-8">
                    <h2 class="text-xl font-bold p-3 bg-gray-300 text-gray-600 text-center"><i class="fas fa-box-open mr-2"></i>Products</h2>
                    <div class="table-container">
                        <table class="min-w-full table-auto">
                                <thead>
                                    <tr class="bg-gray-100 border-b-2 border-gray-200 text-sm">
                                        <th class="py-2 px-3 text-center cursor-pointer" onclick="sortTopProductsTable(0, true)">
                                            <div class="flex items-center justify-center">
                                                <span class="text-gray-700">ID</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(1)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Product</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(2, true)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Quantity</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(3, true)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Price</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                        <th class="py-2 px-3 text-left cursor-pointer" onclick="sortTopProductsTable(4, true)">
                                            <div class="flex items-center">
                                                <span class="text-gray-700">Total Value</span>
                                                <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                                </svg>
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200" id="topProductsTableBody">
                                    <?php if (count($topProducts) > 0): ?>
                                        <?php foreach ($topProducts as $product): ?>
                                            <tr class="hover:bg-gray-50 transition-colors h-6">
                                                <td class="py-1 px-3 text-sm font-medium text-gray-500 text-center"><?= $product['id'] ?? 'N/A' ?></td>
                                                <td class="py-1 px-3 text-sm font-medium text-gray-800"><?= htmlspecialchars($product['product_name']) . ' (x' . $product['total_qty'] . ')' ?></td>
                                                <td class="py-1 px-3 text-sm font-semibold text-gray-900">
                                                    <?php
                                                    // Determine badge color based on quantity value
                                                    // Create a map of quantities to ensure same quantities have same color
                                                    static $quantityColorMap = [];
                                                    $qty = $product['total_qty'];
                                                    
                                                    if (!isset($quantityColorMap[$qty])) {
                                                        // First time seeing this quantity, assign a color
                                                        $count = count($quantityColorMap);
                                                        if ($count === 0) {
                                                            // Top seller - gold
                                                            $quantityColorMap[$qty] = "bg-amber-100 text-amber-800 border-amber-200";
                                                        } elseif ($count === 1) {
                                                            // Second best - silver
                                                            $quantityColorMap[$qty] = "bg-slate-100 text-slate-800 border-slate-200";
                                                        } elseif ($count === 2) {
                                                            // Third best - bronze
                                                            $quantityColorMap[$qty] = "bg-orange-100 text-orange-800 border-orange-200";
                                                        } elseif ($count < 5) {
                                                            // Top 5 - teal
                                                            $quantityColorMap[$qty] = "bg-teal-100 text-teal-800 border-teal-200";
                                                        } elseif ($count < 10) {
                                                            // Top 10 - blue
                                                            $quantityColorMap[$qty] = "bg-blue-100 text-blue-800 border-blue-200";
                                                        } else {
                                                            // Others - gray
                                                            $quantityColorMap[$qty] = "bg-gray-100 text-gray-800 border-gray-200";
                                                        }
                                                    }
                                                    
                                                    $badgeClass = $quantityColorMap[$qty];
                                                    ?>
                                                    <span class="inline-flex items-center justify-center px-3 py-1 rounded-full text-sm font-bold <?= $badgeClass ?> shadow-sm border" style="min-width:2.2em; min-height:2.2em;">
                                                        <?= $product['total_qty'] ?>
                                                    </span>
                                                </td>
                                                <td class="py-1 px-3 text-sm font-semibold text-gray-900">N$<?= number_format($product['current_price'], 2) ?></td>
                                                <td class="py-1 px-3 text-sm font-bold text-teal-700">N$<?= number_format($product['current_price'] * $product['total_qty'], 2) ?></td>
                                  
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                        <!-- Pagination Controls -->
                        <div class="px-6 py-2 bg-gray-50 border-t border-gray-200">
                            <div class="flex justify-between items-center">
                                <div class="flex gap-2">
                                    <button id="topProductsFirstPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                                        </svg>
                                    </button>
                                    <button id="topProductsPrevPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                        </svg>
                                        Prev
                                    </button>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span id="topProductsPageNumber" class="text-sm text-gray-700 font-medium">Page 1 of 1</span>
                                    <div class="flex items-center gap-2">
                                        <input type="number" id="topProductsPageInput" min="1" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-sm shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Page">
                                        <button class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 transition-colors shadow-sm">Go</button>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button id="topProductsNextPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        Next
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                    <button id="topProductsLastPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


            
                <!-- Top Selling Products Section -->



            </div>
            <?php $db = null; ?>
        </div>
    </div>

    <script>
    // Function to update report data via fetch
    function updateReport() {
        const selectedDate = document.getElementById('date').value;
        const formData = new FormData();
        formData.append('date', selectedDate);

        fetch('fetch_report_data.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error loading report data: ' + data.error);
                return;
            }
            // Update Stats Cards (using querySelector as IDs might not exist)
            const cashSalesHeader = document.querySelector('.bg-blue-300 h3');
            if (cashSalesHeader) cashSalesHeader.textContent = `N$${parseFloat(data.cashSalesTotal || 0).toFixed(2)}`;
            
            const eftHeader = document.querySelector('.bg-teal-200 h3');
            if (eftHeader) eftHeader.textContent = `N$${parseFloat(data.eftSalesTotal || 0).toFixed(2)}`;
            
            const creditHeader = document.querySelector('.bg-yellow-400 h3');
            if (creditHeader) creditHeader.textContent = `N$${parseFloat(data.totalUnpaidCredit || 0).toFixed(2)}`;
            
            const revenueHeader = document.querySelector('.bg-teal-400 h3');
            if (revenueHeader) revenueHeader.textContent = `N$${parseFloat(data.totalCashOnHand || 0).toFixed(2)}`;

            // Update the sales table
            const salesTableBody = document.getElementById('salesTableBody');
            if (salesTableBody) {
                salesTableBody.innerHTML = data.salesTableHtml;
                // Refresh pagination for sales table
                initializeSalesPaginationAndSorting();
            }

            // Update the daily breakdown table
            const dailyBreakdownTableBody = document.getElementById('dailyBreakdownTableBody');
            if (dailyBreakdownTableBody) {
                dailyBreakdownTableBody.innerHTML = data.dailyBreakdownTableHtml;
                 // Refresh pagination for daily breakdown table
                initializeDailyBreakdownPaginationAndSorting();
            }

            // Update the top products table - needs data from fetch response
             // Assuming fetch_report_data.php is updated to return topProductsHtml
            const topProductsTableBody = document.getElementById('topProductsTableBody');
             if (topProductsTableBody && data.topProductsTableHtml) { // Check if data includes top products html
                 topProductsTableBody.innerHTML = data.topProductsTableHtml;
                 // Refresh pagination for top products table
                 initializeTopProductsPaginationAndSorting();
             } else if (topProductsTableBody) {
                 // If no top products data in response, maybe clear or show message
                 console.warn("Top products HTML not found in fetch response.");
                 // topProductsTableBody.innerHTML = '<tr><td colspan="5">Data not loaded.</td></tr>';
                 initializeTopProductsPaginationAndSorting(); // Still initialize to handle empty state
             }
            
            // Update the download link after data is refreshed
            updateDownloadLink();

        })
        .catch(error => {
            console.error('Error fetching report data:', error);
            alert('An error occurred while updating the report.');
        });
    }

    // Function to filter tables (generic approach)
    function filterTable(inputId, tableBodyId) {
        const input = document.getElementById(inputId);
        const filter = input.value.toLowerCase();
        const tableBody = document.getElementById(tableBodyId);
        const rows = tableBody ? tableBody.querySelectorAll('tr') : [];

        rows.forEach(row => {
            const cells = row.getElementsByTagName('td');
            let showRow = false;
            // Skip if it's the "no data" row
            if (row.querySelector('td[colspan]')) {
                 showRow = true; // Always show the 'no data' row if it exists initially
            } else {
                Array.from(cells).forEach(cell => {
                    if (cell.textContent.toLowerCase().includes(filter)) {
                        showRow = true;
                    }
                });
            }
             row.style.display = showRow ? '' : 'none';
        });
         // Re-initialize pagination after filtering might be needed if filter changes the number of rows significantly
        // This example doesn't re-paginate on filter, but keeps the display style change.
        // For full re-pagination on filter, you'd need to update the `rows` array and call `showPage(1)`.
    }

    // Simplified filter function call for the main search bar
    function filterSales() {
        // Assuming the search bar filters multiple tables or just the main 'All Transactions' table
        filterTable('search', 'salesTableBody');
        filterTable('search', 'topProductsTableBody'); // If search should also filter products
        filterTable('search', 'dailyBreakdownTableBody'); // If search should also filter daily breakdown
    }


    // --- Generic Pagination and Sorting ---
    function initializePaginationAndSorting(config) {
        const tableBody = document.getElementById(config.tableBodyId);
        if (!tableBody) {
            console.warn(`Table body not found: ${config.tableBodyId}`);
            return; // Exit if table body doesn't exist
        }

        let allRows = Array.from(tableBody.children).filter(row => !row.querySelector('td[colspan]')); // Exclude 'no data' row from sorting/pagination logic
        let currentRows = [...allRows]; // Rows currently being displayed/sorted/paginated
        let sortDirection = {};
        let currentPage = 1;
        const rowsPerPage = config.rowsPerPage || 7;

        function showPage(page) {
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1; // Use currentRows.length
            
            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');
            
            // Display only the rows for the current page from the potentially sorted/filtered list
            currentRows.slice(start, end).forEach(row => row.style.display = '');

            // Update page indicator
            const pageNumberEl = document.getElementById(config.pageNumberId);
            if (pageNumberEl) {
                pageNumberEl.textContent = `Page ${page} of ${maxPage}`;
            }

            // Update page input field
            const pageInputEl = document.getElementById(config.pageInputId);
            if (pageInputEl) {
                pageInputEl.value = page;
                pageInputEl.max = maxPage;
                pageInputEl.placeholder = `Page (1-${maxPage})`;
            }

            // Update button states
            const firstBtn = document.getElementById(config.firstPageId);
            const prevBtn = document.getElementById(config.prevPageId);
            const nextBtn = document.getElementById(config.nextPageId);
            const lastBtn = document.getElementById(config.lastPageId);

            if (firstBtn) firstBtn.disabled = page === 1;
            if (prevBtn) prevBtn.disabled = page === 1;
            if (nextBtn) nextBtn.disabled = page >= maxPage;
            if (lastBtn) lastBtn.disabled = page >= maxPage;

            currentPage = page;
        }

        function sortTable(columnIndex, isNumeric = false) {
            // Update sort direction
            const currentSortDir = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            sortDirection = { [columnIndex]: currentSortDir }; // Reset other column sorts

            // Sort the rows
            currentRows.sort((a, b) => {
                let aValue = a.children[columnIndex]?.textContent.trim() || '';
                let bValue = b.children[columnIndex]?.textContent.trim() || '';

                if (isNumeric) {
                    // More robust parsing for currency etc.
                    aValue = parseFloat(aValue.replace(/[^0-9.-]+/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^0-9.-]+/g, '')) || 0;
                } else {
                    aValue = aValue.toLowerCase();
                    bValue = bValue.toLowerCase();
                }

                if (aValue < bValue) return currentSortDir === 'asc' ? -1 : 1;
                if (aValue > bValue) return currentSortDir === 'asc' ? 1 : -1;
                return 0;
            });

            // Re-append the sorted rows
            currentRows.forEach(row => tableBody.appendChild(row));

            // Go back to first page after sorting
            showPage(1);
        }

        // Set up event listeners for pagination controls
        const setupButton = (id, callback) => {
            const button = document.getElementById(id);
            if (button) {
                // Remove existing listeners to prevent duplicates
                const newButton = button.cloneNode(true);
                button.parentNode.replaceChild(newButton, button);
                newButton.addEventListener('click', callback);
            }
        };

        // Setup pagination buttons
        setupButton(config.firstPageId, () => showPage(1));
        setupButton(config.prevPageId, () => showPage(Math.max(1, currentPage - 1)));
        setupButton(config.nextPageId, () => {
            const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
            showPage(Math.min(maxPage, currentPage + 1));
        });
        setupButton(config.lastPageId, () => {
            const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
            showPage(maxPage);
        });

        // Setup page input
        const pageInput = document.getElementById(config.pageInputId);
        const pageGoBtn = pageInput?.nextElementSibling;
        
        if (pageInput) {
            // Remove existing listeners
            const newPageInput = pageInput.cloneNode(true);
            pageInput.parentNode.replaceChild(newPageInput, pageInput);
            
            newPageInput.addEventListener('change', function() {
                const desiredPage = parseInt(this.value);
                if (!isNaN(desiredPage)) {
                    const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
                    showPage(Math.min(Math.max(1, desiredPage), maxPage));
                }
            });
        }

        if (pageGoBtn) {
            // Remove existing listeners
            const newPageGoBtn = pageGoBtn.cloneNode(true);
            pageGoBtn.parentNode.replaceChild(newPageGoBtn, pageGoBtn);
            
            newPageGoBtn.addEventListener('click', function() {
                const desiredPage = parseInt(pageInput.value);
                if (!isNaN(desiredPage)) {
                    const maxPage = Math.ceil(currentRows.length / rowsPerPage) || 1;
                    showPage(Math.min(Math.max(1, desiredPage), maxPage));
                }
            });
        }

        // Setup sorting
        // Find all sortable headers in the thead (assuming thead is sibling of tbody)
        const headers = tableBody.parentElement.querySelector('thead')?.querySelectorAll('th');
        if (headers) {
            headers.forEach((th, index) => {
                // Check if this header has sorting functionality
                if (th.querySelector('.sort-icon')) {
                    // Remove old listeners and onclick attributes
                    const newTh = th.cloneNode(true);
                    th.parentNode.replaceChild(newTh, th);
                    newTh.removeAttribute('onclick');
                    
                    // Determine if this column contains numeric data
                    const isNum = newTh.textContent.trim().includes('Total') || 
                                  newTh.textContent.trim().includes('Price') || 
                                  newTh.textContent.trim().includes('Quantity') || 
                                  newTh.textContent.trim().includes('Sales');
                    
                    // Add new event listener
                    newTh.addEventListener('click', () => sortTable(index, isNum));
                }
            });
        }

        // Initial display
        showPage(1);
        
        // Return the sort function for external use if needed
        return { sort: sortTable };
    }

    // Table-specific initialization functions
    function initializeSalesPaginationAndSorting() {
        const result = initializePaginationAndSorting({
            tableBodyId: 'salesTableBody',
            rowsPerPage: 7,
            pageNumberId: 'pageNumber',
            pageInputId: 'pageInput',
            firstPageId: 'firstPage',
            prevPageId: 'prevPage',
            nextPageId: 'nextPage',
            lastPageId: 'lastPage'
        });
        window.sortTable = result && result.sort ? result.sort : function() {};
    }

    function initializeTopProductsPaginationAndSorting() {
        const result = initializePaginationAndSorting({
            tableBodyId: 'topProductsTableBody',
            rowsPerPage: 7,
            pageNumberId: 'topProductsPageNumber',
            pageInputId: 'topProductsPageInput',
            firstPageId: 'topProductsFirstPage',
            prevPageId: 'topProductsPrevPage',
            nextPageId: 'topProductsNextPage',
            lastPageId: 'topProductsLastPage'
        });
        window.sortTopProductsTable = result && result.sort ? result.sort : function() {};
    }

    function initializeDailyBreakdownPaginationAndSorting() {
        const result = initializePaginationAndSorting({
            tableBodyId: 'dailyBreakdownTableBody',
            rowsPerPage: 7,
            pageNumberId: 'dailyPageNumber',
            pageInputId: 'dailyPageInput',
            firstPageId: 'dailyFirstPage',
            prevPageId: 'dailyPrevPage',
            nextPageId: 'dailyNextPage',
            lastPageId: 'dailyLastPage'
        });
        window.sortDailyBreakdownTable = result && result.sort ? result.sort : function() {};
    }


    // --- Delete Functions ---
    function deleteRecord(type, id) {
        showConfirmationModal('Are you sure you want to delete this record?', 'This action cannot be undone.', () => {
            fetch('delete_record.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `type=${type}&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Success', 'Record deleted successfully.', 'success');
                    updateReport(); // Refresh data after deletion
                } else {
                    showNotification('Error', 'Error deleting record: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while deleting the record.', 'error');
            });
        });
    }

    function deleteDailyRecord(date) {
        showConfirmationModal(`Are you sure you want to delete all records for ${date}?`, 'This action cannot be undone.', () => {
            fetch('delete_record.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `type=daily&date=${date}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Success', 'Records deleted successfully.', 'success');
                    updateReport(); // Refresh data
                } else {
                    showNotification('Error', 'Error deleting records: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while deleting the records.', 'error');
            });
        });
    }

    function deleteProductRecord(productName) {
        showConfirmationModal(`Are you sure you want to delete all sales records for ${productName}?`, 'This action cannot be undone.', () => {
            fetch('delete_record.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `type=product&name=${encodeURIComponent(productName)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Success', 'Records deleted successfully.', 'success');
                    updateReport(); // Refresh data
                } else {
                    showNotification('Error', 'Error deleting records: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while deleting the records.', 'error');
            });
        });
    }

    // Notification Function
    function showNotification(title, message, type = 'info') {
        // Remove existing notification if present
        const existingNotification = document.getElementById('notification-toast');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // Create notification element
        const notification = document.createElement('div');
        notification.id = 'notification-toast';
        notification.className = 'fixed top-4 right-4 max-w-sm w-full shadow-lg rounded-lg overflow-hidden z-50 transform transition-all duration-300 ease-in-out translate-x-full opacity-0';
        
        // Define background and icon based on type
        let bgColor, icon;
        switch (type) {
            case 'success':
                bgColor = 'bg-teal-100 border-l-4 border-teal-500';
                icon = `<svg class="w-6 h-6 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>`;
                break;
            case 'error':
                bgColor = 'bg-red-100 border-l-4 border-red-500';
                icon = `<svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>`;
                break;
            case 'warning':
                bgColor = 'bg-yellow-50 border-l-4 border-yellow-500';
                icon = `<svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>`;
                break;
            default: // info
                bgColor = 'bg-blue-100 border-l-4 border-blue-500';
                icon = `<svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>`;
        }
        
        // Create notification content
        notification.innerHTML = `
            <div class="${bgColor}">
                <div class="flex items-center p-4">
                    <div class="flex-shrink-0">
                        ${icon}
                    </div>
                    <div class="ml-3 w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900">${title}</p>
                        <p class="mt-1 text-sm text-gray-500">${message}</p>
                    </div>
                    <div class="ml-4 flex-shrink-0 flex">
                        <button class="inline-flex text-gray-400 hover:text-gray-500 focus:outline-none" 
                                onclick="dismissNotification()">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Add to DOM
        document.body.appendChild(notification);
        
        // Trigger animation after a small delay (to ensure DOM is ready)
        setTimeout(() => {
            notification.classList.remove('translate-x-full', 'opacity-0');
        }, 10);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            dismissNotification();
        }, 5000);
    }
    
    function dismissNotification() {
        const toast = document.getElementById('notification-toast');
        if (toast) {
            toast.classList.add('translate-x-full', 'opacity-0');
            setTimeout(() => {
                if (document.body.contains(toast)) {
                    toast.remove();
                }
            }, 300);
        }
    }

    // Confirmation Modal Function
    function showConfirmationModal(title, message, onConfirm) {
        // Create modal backdrop
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-0 flex items-center justify-center z-50 transition-all duration-300';
        modal.id = 'confirmation-modal';
        
        // Create modal content
        modal.innerHTML = `
            <div class="bg-white rounded-lg shadow-lg p-6 max-w-md w-full transform transition-all duration-300 scale-90 opacity-0">
                <div class="flex items-center mb-4">
                    <div class="rounded-full bg-red-100 p-2 mr-3">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900">${title}</h3>
                </div>
                <p class="text-gray-600 mb-6">${message}</p>
                <div class="flex justify-end space-x-3">
                    <button 
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors"
                        onclick="dismissConfirmationModal()">
                        Cancel
                    </button>
                    <button 
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors"
                        id="confirm-delete-btn">
                        Delete
                    </button>
                </div>
            </div>
        `;
        
        // Add to DOM
        document.body.appendChild(modal);
        
        // Trigger animation after a small delay (to ensure DOM is ready)
        setTimeout(() => {
            modal.classList.add('bg-opacity-50');
            const modalContent = modal.querySelector('div');
            if (modalContent) {
                modalContent.classList.remove('scale-90', 'opacity-0');
            }
        }, 10);
        
        // Setup confirm button
        document.getElementById('confirm-delete-btn').addEventListener('click', () => {
            dismissConfirmationModal();
            setTimeout(() => {
                onConfirm();
            }, 300); // Wait for the animation to finish before executing the callback
        });
        
        // Close on background click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                dismissConfirmationModal();
            }
        });
        
        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('confirmation-modal')) {
                dismissConfirmationModal();
            }
        });
    }
    
    function dismissConfirmationModal() {
        const modal = document.getElementById('confirmation-modal');
        if (modal) {
            modal.classList.remove('bg-opacity-50');
            modal.classList.add('bg-opacity-0');
            
            const modalContent = modal.querySelector('div');
            if (modalContent) {
                modalContent.classList.add('scale-90', 'opacity-0');
            }
            
            setTimeout(() => {
                if (document.body.contains(modal)) {
                    modal.remove();
                }
            }, 300);
        }
    }

    // --- Initial Setup ---
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize pagination and sorting for tables present on initial load
        initializeSalesPaginationAndSorting();
        initializeDailyBreakdownPaginationAndSorting();
        initializeTopProductsPaginationAndSorting();

        // Add listener to the date select (already done in HTML via onchange, but ensure updateReport is globally available)
        // The 'onchange' in HTML should now work as updateReport is defined above.
        const dateSelect = document.getElementById('date');
        if (dateSelect) {
             // Optional: Remove HTML onchange and add listener here if preferred
             // dateSelect.onchange = null; // Remove inline handler
             // dateSelect.addEventListener('change', updateReport);
        } else {
            console.error("Date select element ('date') not found.");
        }
    });

    function updateDownloadLink() {
        var date = document.getElementById('date').value;
        var year = '';
        var month = '';
        // Expecting date in format YYYY-MM-DD
        if (date && /^\d{4}-\d{2}-\d{2}$/.test(date)) {
            var parts = date.split('-');
            year = parts[0];
            month = parts[1];
        } else {
            // fallback: use today's date
            var today = new Date();
            year = today.getFullYear();
            month = ("0" + (today.getMonth() + 1)).slice(-2);
        }
        var link = document.getElementById('downloadMonthlyReport');
        if (link) {
            link.href = "generate_monthly_report.php?month=" + month + "&year=" + year;
        }
    }

    document.getElementById('printCashup').addEventListener('click', function() {
        // Prepare data for the cash-up report
        const selectedDate = document.getElementById('date').value;
        // Connect to user database to get current user info
        const currentUser = "<?= isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown User' ?>";
        
        // First fetch cash available in till data from fetch_report_data.php
        const formData = new FormData();
        formData.append('date', selectedDate);

        fetch('fetch_report_data.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error loading report data: ' + data.error);
                return;
            }
            
            // Get values from the stats cards
            const cashSalesTotal = parseFloat(document.querySelector('.bg-blue-300 h3').textContent.replace('N$', '').replace(',', ''));
            const eftSalesTotal = parseFloat(document.querySelector('.bg-teal-200 h3').textContent.replace('N$', '').replace(',', ''));
            const unpaidCredit = parseFloat(document.querySelector('.bg-yellow-400 h3').textContent.replace('N$', '').replace(',', ''));
            const cashOnHand = parseFloat(document.querySelector('.bg-teal-400 h3').textContent.replace('N$', '').replace(',', ''));
            
            // Get cash available in till from fetch_report_data response
            const cashAvailableInTill = data.cashAvailableInTill || 0;
            
            // Prepare cashup data
            const cashupData = {
                is_cashup: 'true',
                date: selectedDate,
                total_cash_sales: cashSalesTotal,
                eft_sales_total: eftSalesTotal,
                unpaid_credit: unpaidCredit,
                cash_on_hand: cashOnHand,
                cash_available_in_till: cashAvailableInTill,
                cashier_username: currentUser
            };

            // Create a form to submit for PDF generation
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'cash-pdf.php';
            
            // Add all data as hidden fields
            for (const [key, value] of Object.entries(cashupData)) {
                const hiddenField = document.createElement('input');
                hiddenField.type = 'hidden';
                hiddenField.name = key;
                hiddenField.value = value;
                form.appendChild(hiddenField);
            }
            
            // Add form to body and submit
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        })
        .catch(error => {
            console.error('Error fetching report data:', error);
            alert('An error occurred while generating the Cash-up report.');
        });
    });

    // Ensure the link is correct on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize pagination and sorting for tables present on initial load
        initializeSalesPaginationAndSorting();
        initializeDailyBreakdownPaginationAndSorting();
        initializeTopProductsPaginationAndSorting();
    });

    function showTransactionDetails(date) {
        // Create modal to show detailed transactions for the specified date
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
        modal.id = 'transaction-modal';
        
        // Fetch transaction details for this date
        fetch('fetch_transaction_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `date=${date}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error loading transaction details: ' + data.error);
                document.body.removeChild(modal);
                return;
            }
            
            // Create modal content
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-lg max-w-3xl w-full max-h-[80vh] overflow-hidden">
                    <div class="p-4 bg-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-800">Transactions for ${date}</h3>
                        <button class="text-gray-600 hover:text-gray-900" onclick="document.getElementById('transaction-modal').remove()">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="p-4 overflow-y-auto max-h-[calc(80vh-8rem)]">
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700 mb-2">Income</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        ${data.income.map(item => `
                                            <tr>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900">${item.type}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-blue-600">N$${parseFloat(item.amount).toFixed(2)}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-600">${item.time}</td>
                                                <td class="px-3 py-2 text-sm text-gray-600 truncate max-w-xs" title="${item.details}">${item.details}</td>
                                            </tr>
                                        `).join('') || '<tr><td colspan="4" class="px-3 py-2 text-center text-sm text-gray-500">No income transactions</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-700 mb-2">Expenses</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        ${data.expenses.map(item => `
                                            <tr>
                                                <td class="px-3 py-2 text-sm font-medium text-gray-900">${item.description}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-red-600">N$${parseFloat(item.amount).toFixed(2)}</td>
                                                <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-600">${item.time}</td>
                                            </tr>
                                        `).join('') || '<tr><td colspan="3" class="px-3 py-2 text-center text-sm text-gray-500">No expense transactions</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-gray-700">Total Income:</span>
                                <span class="text-blue-600 font-bold">N$${parseFloat(data.totals.income).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between items-center mt-2">
                                <span class="font-bold text-gray-700">Total Expenses:</span>
                                <span class="text-red-600 font-bold">N$${parseFloat(data.totals.expenses).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between items-center mt-2 pt-2 border-t border-gray-200">
                                <span class="font-bold text-gray-900">Net (Profit/Loss):</span>
                                <span class="font-bold ${data.totals.net >= 0 ? 'text-teal-600' : 'text-red-600'}">
                                    N$${parseFloat(data.totals.net).toFixed(2)}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-gray-100 flex justify-end">
                        <button class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 font-medium" 
                                onclick="document.getElementById('transaction-modal').remove()">
                            Close
                        </button>
                    </div>
                </div>
            `;
        })
        .catch(error => {
            console.error('Error fetching transaction details:', error);
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-lg p-6 max-w-md w-full">
                    <h3 class="text-lg font-bold text-red-600 mb-4">Error</h3>
                    <p class="text-gray-700">Failed to load transaction details.</p>
                    <div class="mt-6 flex justify-end">
                        <button class="px-4 py-2 bg-gray-300 text-gray-800 rounded hover:bg-gray-400"
                                onclick="document.getElementById('transaction-modal').remove()">
                            Close
                        </button>
                    </div>
                </div>
            `;
        });
        
        document.body.appendChild(modal);
    }
    </script>
</body>
</html>