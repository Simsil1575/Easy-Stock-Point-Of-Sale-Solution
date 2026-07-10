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


$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00';
} catch (PDOException $e) {
    $closingTime = '00:00';
}

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Handle week selection
$selectedWeek = isset($_POST['week']) ? $_POST['week'] : date('Y-W');
$weekParts = explode('-', $selectedWeek);
$year = (int)$weekParts[0];
$week = (int)ltrim($weekParts[1], '0');
if ($week < 1) $week = 1;
if ($week > 53) $week = 53;

// Calculate week start (Monday) and end (Sunday)
$weekStart = new DateTime();
$weekStart->setISODate($year, $week, 1); // Monday
$weekEnd = clone $weekStart;
$weekEnd->modify('+6 days'); // Sunday

$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr = $weekEnd->format('Y-m-d');

// Get all weeks with transactions
$weeksQuery = $db->prepare("
    SELECT DISTINCT strftime('%Y-%W', created_at) as week_year
    FROM (
        SELECT created_at FROM orders
        UNION ALL
        SELECT created_at FROM credit_sales
        UNION ALL
        SELECT payment_date as created_at FROM payments
    )
    ORDER BY week_year DESC
");
$weeksQuery->execute();
$availableWeeks = $weeksQuery->fetchAll(PDO::FETCH_COLUMN);

// Always add current week if not in list
$currentWeek = date('Y-W');
if (!in_array($currentWeek, $availableWeeks)) {
    array_unshift($availableWeeks, $currentWeek);
}

// Initialize weekly data array
$weeklyData = [];
$weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Create an array of dates for each day of the week
$weekDates = [];
for ($i = 0; $i < 7; $i++) {
    $date = new DateTime();
    $date->setISODate($year, $week, $i + 1); // 1=Monday, 2=Tuesday, etc.
    $weekDates[] = $date->format('Y-m-d');
}

// Process each day
for ($i = 0; $i < 7; $i++) {
    $dateStr = $weekDates[$i];
    $nextDay = new DateTime($dateStr);
    $nextDay->modify('+1 day');
    $nextDayStr = $nextDay->format('Y-m-d');
    
    // CASH SALES: Cash portion of orders (total - EFT amounts for mixed payments)
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(
            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
        ), 0) 
        FROM orders o
        WHERE (
            (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $cashSalesQuery->bindParam(':date', $dateStr);
    $cashSalesQuery->bindParam(':nextDay', $nextDayStr);
    $cashSalesQuery->execute();
    $cashSales = $cashSalesQuery->fetchColumn() ?: 0;

    // EFT SALES: Regular orders paid via EFT (not credit sales)
    $eftSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(e.amount), 0) 
        FROM eft_payments e 
        JOIN orders o ON e.order_id = o.id 
        WHERE NOT EXISTS (SELECT 1 FROM credit_sales cs WHERE cs.id = o.id) AND (
            (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= '$closingTime') OR
            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $eftSalesQuery->bindParam(':date', $dateStr);
    $eftSalesQuery->bindParam(':nextDay', $nextDayStr);
    $eftSalesQuery->execute();
    $eftSales = $eftSalesQuery->fetchColumn() ?: 0;

    // CREDIT ISSUED: Outstanding credit sales (amount still owed)
    $creditIssuedQuery = $db->prepare("
        SELECT COALESCE(SUM(total_amount - COALESCE(paid_amount, 0)), 0) 
        FROM credit_sales 
        WHERE (
            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR
            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        ) AND payment_status IN ('unpaid', 'partial')
    ");
    $creditIssuedQuery->bindParam(':date', $dateStr);
    $creditIssuedQuery->bindParam(':nextDay', $nextDayStr);
    $creditIssuedQuery->execute();
    $creditIssued = $creditIssuedQuery->fetchColumn() ?: 0;

    // CREDIT PAYMENTS: Cash installments on credit sales (payments not mirrored as EFT rows)
    $creditPaymentsQuery = $db->prepare("
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE NOT EXISTS (
            SELECT 1 FROM eft_payments ep
            WHERE ep.order_id = p.sale_id
            AND ABS(CAST(ep.amount AS REAL) - CAST(p.amount AS REAL)) < 0.021
            AND date(ep.payment_date) = date(p.payment_date)
            AND strftime('%H:%M', ep.payment_date) = strftime('%H:%M', p.payment_date)
        ) AND (
            (DATE(p.payment_date) = :date AND strftime('%H:%M', p.payment_date) >= '$closingTime') OR
            (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $creditPaymentsQuery->bindParam(':date', $dateStr);
    $creditPaymentsQuery->bindParam(':nextDay', $nextDayStr);
    $creditPaymentsQuery->execute();
    $creditPayments = $creditPaymentsQuery->fetchColumn() ?: 0;

    // EFT CREDIT PAYMENTS: Amounts from eft_payments for credit sales
    $eftCreditPaymentsQuery = $db->prepare("
        SELECT COALESCE(SUM(e.amount), 0) 
        FROM eft_payments e
        JOIN credit_sales cs ON e.order_id = cs.id
        WHERE (
            (DATE(e.payment_date) = :date AND strftime('%H:%M', e.payment_date) >= '$closingTime') OR
            (DATE(e.payment_date) = :nextDay AND strftime('%H:%M', e.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $eftCreditPaymentsQuery->bindParam(':date', $dateStr);
    $eftCreditPaymentsQuery->bindParam(':nextDay', $nextDayStr);
    $eftCreditPaymentsQuery->execute();
    $eftCreditPayments = $eftCreditPaymentsQuery->fetchColumn() ?: 0;

    // CASH IN: Cash deposits made to the business
    $cashInQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions
        WHERE type = 'cash-in' AND (
            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR
            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $cashInQuery->bindParam(':date', $dateStr);
    $cashInQuery->bindParam(':nextDay', $nextDayStr);
    $cashInQuery->execute();
    $cashIn = $cashInQuery->fetchColumn() ?: 0;

    // CASH OUT: Money taken out of the business
    $cashOutQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions
        WHERE type = 'cash-out' AND (
            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR
            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
        )
    ");
    $cashOutQuery->bindParam(':date', $dateStr);
    $cashOutQuery->bindParam(':nextDay', $nextDayStr);
    $cashOutQuery->execute();
    $cashOut = $cashOutQuery->fetchColumn() ?: 0;

    // CALCULATE TOTALS
    $cashReceived = $cashSales + $creditPayments; // cash orders + cash credit payments
    $eftReceived = $eftSales + $eftCreditPayments; // EFT orders + EFT credit payments
    $totalIncome = $cashReceived + $eftReceived + $cashIn;
    $totalExpenses = $cashOut;
    $netAmount = $totalIncome - $totalExpenses;

    $weeklyData[] = [
        'day' => $weekDays[$i],
        'date' => $dateStr,
        'cash_sales' => $cashSales,
        'eft_sales' => $eftSales,
        'credit_issued' => $creditIssued,
        'credit_payments' => $creditPayments,
        'eft_credit_payments' => $eftCreditPayments,
        'cash_in' => $cashIn,
        'cash_out' => $cashOut,
        'cash_received' => $cashReceived,
        'eft_received' => $eftReceived,
        'total_income' => $totalIncome,
        'total_expenses' => $totalExpenses,
        'net_amount' => $netAmount
    ];
}

// Calculate weekly totals
$weeklyTotals = [
    'cash_sales' => array_sum(array_column($weeklyData, 'cash_sales')),
    'eft_sales' => array_sum(array_column($weeklyData, 'eft_sales')),
    'credit_issued' => array_sum(array_column($weeklyData, 'credit_issued')),
    'credit_payments' => array_sum(array_column($weeklyData, 'credit_payments')),
    'eft_credit_payments' => array_sum(array_column($weeklyData, 'eft_credit_payments')),
    'cash_in' => array_sum(array_column($weeklyData, 'cash_in')),
    'cash_out' => array_sum(array_column($weeklyData, 'cash_out')),
    'cash_received' => array_sum(array_column($weeklyData, 'cash_received')),
    'eft_received' => array_sum(array_column($weeklyData, 'eft_received')),
    'total_income' => array_sum(array_column($weeklyData, 'total_income')),
    'total_expenses' => array_sum(array_column($weeklyData, 'total_expenses')),
    'net_amount' => array_sum(array_column($weeklyData, 'net_amount'))
];

// Calculate running balance (ledger style)
$runningBalance = 0;
foreach ($weeklyData as &$day) {
    $runningBalance += $day['net_amount'];
    $day['running_balance'] = $runningBalance;
}

// Calculate unique days
$uniqueDays = [];
$processedDates = [];
foreach ($weekDates as $i => $date) {
    if (!in_array($date, $processedDates)) {
        $uniqueDays[] = [
            'day' => $weekDays[$i],
            'date' => $date,
            'index' => $i
        ];
        $processedDates[] = $date;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Sales Report</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
    <style>
        .toast-notification {
            transition: opacity 0.5s, transform 0.5s;
            transform: translateX(100%);
            opacity: 0;
            animation: slideIn 0.5s forwards;
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* Premium shadcn grey theme */
        .bg-card {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
        }
        
        .bg-muted {
            background-color: #f9fafb;
        }
        
        .border-border {
            border-color: #e5e7eb;
        }
        
        .text-muted-foreground {
            color: #6b7280;
        }
        
        .text-card-foreground {
            color: #111827;
        }
        
        .hover\:bg-accent:hover {
            background-color: #f3f4f6;
        }
        
        .hover\:bg-accent\/50:hover {
            background-color: rgba(243, 244, 246, 0.5);
        }
        
        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* Layout fixes for proper containment */
        body {
            overflow-x: hidden;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 16rem; /* 256px */
            height: 100vh;
            z-index: 40;
        }
        
        .content {
            margin-left: 16rem; /* 256px */
            min-height: 100vh;
            width: calc(100vw - 16rem);
            max-width: calc(100vw - 16rem);
            overflow-x: hidden;
        }
        
        /* Container constraints */
        .container-wrapper {
            max-width: 100%;
            padding: 0 1rem;
            box-sizing: border-box;
        }
        
        /* Header responsive fixes */
        .header-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        @media (min-width: 768px) {
            .header-container {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        
        /* Title section responsive */
        .title-section {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        @media (min-width: 640px) {
            .title-section {
                flex-direction: row;
                align-items: center;
                gap: 1.5rem;
            }
        }
        
        /* Controls section responsive */
        .controls-section {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            width: 100%;
        }
        
        @media (min-width: 768px) {
            .controls-section {
                flex-direction: row;
                align-items: center;
                width: auto;
            }
        }
        
        /* Table responsive handling */
        .table-wrapper {
            overflow-x: auto;
            max-width: 100%;
            width: 100%;
            border-radius: 0.5rem;
        }
        
        .table-wrapper table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        /* Perfect fit table column sizing */
        .table-wrapper th:first-child,
        .table-wrapper td:first-child {
            width: 180px;
            min-width: 180px;
            max-width: 180px;
        }
        
        .table-wrapper th:not(:first-child),
        .table-wrapper td:not(:first-child) {
            width: calc((100% - 180px) / 7);
            min-width: 100px;
        }
        
        /* Ensure text fits within cells */
        .table-wrapper th,
        .table-wrapper td {
            padding: 0.75rem 0.5rem;
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
        }
        
        /* Mobile responsive table adjustments */
        @media (max-width: 1024px) {
            .table-wrapper {
                overflow-x: scroll;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-wrapper table {
                min-width: 800px;
                table-layout: auto;
            }
            
            .table-wrapper th:first-child,
            .table-wrapper td:first-child {
                width: 160px;
                min-width: 160px;
                position: sticky;
                left: 0;
                background: inherit;
                z-index: 1;
            }
            
            .table-wrapper thead th:first-child {
                background: #f9fafb;
            }
            
            .table-wrapper tbody td:first-child {
                background: #ffffff;
            }
        }
        
        /* Container perfect fit */
        .perfect-container {
            max-width: calc(100vw - 17rem);
            width: calc(100vw - 17rem);
            box-sizing: border-box;
        }
        
        /* Chart container fit */
        .chart-container {
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }
        
        .chart-container canvas {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* Card grid perfect fit */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            width: 100%;
        }
        
        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        /* Ensure all cards fit perfectly */
        .stats-grid > * {
            min-width: 0;
            word-wrap: break-word;
        }
        
        /* Form controls responsive */
        .form-select {
            width: 100%;
            min-width: 200px;
        }
        
        @media (min-width: 640px) {
            .form-select {
                width: auto;
            }
        }
        
        /* Table header responsive */
        .table-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1.5rem;
        }
        
        @media (min-width: 640px) {
            .table-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        
        /* Search input responsive */
        .search-wrapper {
            width: 100%;
        }
        
        @media (min-width: 640px) {
            .search-wrapper {
                width: auto;
                min-width: 250px;
            }
        }
    </style>
</head>
<body class="bg-slate-50 overflow-x-hidden">
    <div class="flex min-h-screen">
        <div class="sidebar fixed top-0 left-0 w-64 h-full z-40">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 ml-64 min-h-screen">
            <div class="perfect-container px-2 sm:px-4 lg:px-6 py-6">
                <!-- Header -->
                <div class="header-container mb-8">
                    <div class="title-section">
                        <a href="reports" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Go Back
                        </a>
                        <div>
                            <h1 class="text-2xl lg:text-3xl font-bold text-slate-900">Weekly Sales Report</h1>
                            <div class="flex items-center gap-2 text-sm text-slate-600 mt-1">
                                <i class="fas fa-calendar-week"></i>
                                <span>Week of <?= $weekStart->format('M j') ?> - <?= $weekEnd->format('M j, Y') ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="controls-section">
                        <form method="POST" action="" class="flex items-center gap-4">
                            <div class="relative">
                                <select name="week" onchange="this.form.submit()" class="form-select bg-card border border-border text-card-foreground text-sm rounded-lg focus:ring-2 focus:ring-slate-500 focus:border-slate-500 block px-4 py-2.5 shadow-sm transition-colors cursor-pointer">
                                    <?php foreach ($availableWeeks as $weekOption): ?>
                                        <?php
                                        $weekParts = explode('-', $weekOption);
                                        $weekYear = $weekParts[0];
                                        $weekNum = $weekParts[1];
                                        $weekStartDate = new DateTime();
                                        $weekStartDate->setISODate($weekYear, $weekNum, 1);
                                        $weekEndDate = clone $weekStartDate;
                                        $weekEndDate->modify('+6 days');
                                        ?>
                                        <option value="<?= $weekOption ?>" <?= $weekOption === $selectedWeek ? 'selected' : '' ?>>
                                            Week <?= $weekNum ?>, <?= $weekYear ?> (<?= $weekStartDate->format('M j') ?> - <?= $weekEndDate->format('M j') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        
                        <a href="generate_weekly_report.php?week=<?= $selectedWeek ?>" 
                           class="inline-flex items-center px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg shadow-sm transition duration-200 ease-in-out transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-opacity-50 whitespace-nowrap">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Export PDF
                        </a>
                    </div>
                </div>

                <!-- Weekly Summary Cards -->
                <div class="stats-grid mb-8">
                    <!-- Cash Received -->
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Cash Received</p>
                                <h3 class="text-2xl font-bold text-teal-600">N$<?= number_format($weeklyTotals['cash_received'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-teal-100 rounded-full">
                                <i class="fas fa-dollar-sign text-teal-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Cash orders + cash credit payments</p>
                    </div>

                    <!-- EFT Payments -->
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">EFT Payments</p>
                                <h3 class="text-2xl font-bold text-purple-600">N$<?= number_format($weeklyTotals['eft_received'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-purple-100 rounded-full">
                                <i class="fas fa-credit-card text-purple-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">EFT orders + EFT credit payments</p>
                    </div>
                    <!-- Total Income -->
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Total Income</p>
                                <h3 class="text-2xl font-bold text-teal-600">N$<?= number_format($weeklyTotals['total_income'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-teal-100 rounded-full">
                                <i class="fas fa-arrow-up text-teal-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Cash + EFT + Credit Payments</p>
                    </div>

                    <!-- Total Expenses -->
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Total Expenses</p>
                                <h3 class="text-2xl font-bold text-red-600">N$<?= number_format($weeklyTotals['total_expenses'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-red-100 rounded-full">
                                <i class="fas fa-arrow-down text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Cash Out Transactions</p>
                    </div>

                    <!-- Net Profit -->
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Net Profit</p>
                                <h3 class="text-2xl font-bold <?= $weeklyTotals['net_amount'] >= 0 ? 'text-teal-600' : 'text-red-600' ?>">
                                    N$<?= number_format($weeklyTotals['net_amount'], 2) ?>
                                </h3>
                            </div>
                            <div class="p-3 <?= $weeklyTotals['net_amount'] >= 0 ? 'bg-teal-100' : 'bg-red-100' ?> rounded-full">
                                <i class="fas fa-chart-line <?= $weeklyTotals['net_amount'] >= 0 ? 'text-teal-600' : 'text-red-600' ?> text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Income - Expenses</p>
                    </div>

                    <!-- Credit Outstanding -->
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6 transform hover:scale-105 transition-transform duration-200">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Credit Outstanding</p>
                                <h3 class="text-2xl font-bold text-amber-600">N$<?= number_format($weeklyTotals['credit_issued'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-amber-100 rounded-full">
                                <i class="fas fa-hand-holding-usd text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Unpaid Credit Sales</p>
                    </div>
                </div>

                <!-- Weekly Sales Table -->
                <div class="bg-card rounded-xl shadow-sm border border-border overflow-hidden">
                    <div class="table-header border-b border-border">
                        <h2 class="text-xl font-semibold text-card-foreground">
                            <i class="fas fa-table mr-2 text-slate-500"></i>
                            Daily Breakdown
                        </h2>
                        <div class="search-wrapper">
                            <div class="relative">
                                <input type="text" id="searchInput" placeholder="Search metric..." 
                                       class="w-full pl-10 pr-4 py-2 border border-border rounded-lg focus:ring-2 focus:ring-slate-500 focus:outline-none focus:border-slate-500 shadow-sm transition duration-200 text-sm bg-muted">
                                <svg class="w-4 h-4 absolute left-3 top-2.5 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0118 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="min-w-full divide-y divide-border">
                            <thead class="bg-muted">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Metric</th>
                                    <?php
                                    // Render table headers for each unique day
                                    foreach ($uniqueDays as $dayInfo): 
                                    ?>
                                        <th class="px-3 py-2 text-center text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                            <?= $dayInfo['day'] ?><br>
                                            <span class="text-xs text-muted-foreground"><?= date('M j', strtotime($dayInfo['date'])) ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-card divide-y divide-border" id="tableBody">
                                <?php
                                $metrics = [
                                    'cash_sales' => ['label' => 'Cash Sales', 'class' => 'text-teal-600'],
                                    'eft_sales' => ['label' => 'EFT Sales', 'class' => 'text-blue-600'],
                                    'credit_issued' => ['label' => 'Credit Issued', 'class' => 'text-amber-600'],
                                    'credit_payments' => ['label' => 'Credit Payments (Cash)', 'class' => 'text-teal-600'],
                                    'eft_credit_payments' => ['label' => 'EFT Credit Payments', 'class' => 'text-purple-600'],
                                    'cash_in' => ['label' => 'Cash In', 'class' => 'text-teal-600'],
                                    'cash_out' => ['label' => 'Cash Out', 'class' => 'text-red-600'],
                                    'net_amount' => ['label' => 'Net Amount', 'class' => 'font-bold'],
                                    'running_balance' => ['label' => 'Running Balance', 'class' => 'font-bold'],
                                ];
                                foreach ($metrics as $key => $meta):
                                ?>
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-card-foreground metric-label">
                                        <?= $meta['label'] ?>
                                    </td>
                                    <?php foreach ($uniqueDays as $dayInfo): 
                                        $dayIndex = $dayInfo['index'];
                                        $dayData = $weeklyData[$dayIndex];
                                    ?>
                                        <td class="px-2 py-2 whitespace-nowrap text-center text-sm <?= $meta['class'] ?>">
                                            N$<?= number_format($dayData[$key], 2) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-100 border-t-2 border-slate-300">
                                <tr class="font-bold">
                                    <td class="px-3 py-2 text-sm text-slate-900 uppercase tracking-wider">WEEKLY TOTALS</td>
                                    <?php foreach ($uniqueDays as $dayInfo): ?>
                                        <td class="px-2 py-2 text-center text-xs text-slate-700 font-bold"></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Weekly Chart -->
                <div class="bg-card rounded-xl shadow-sm border border-border p-6 mt-8">
                    <h3 class="text-lg font-semibold text-card-foreground mb-4">
                        <i class="fas fa-chart-area mr-2 text-slate-500"></i>
                        Weekly Performance Chart
                    </h3>
                    <div class="chart-container h-80">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search/filter for metrics (row-based)
        const searchInput = document.getElementById('searchInput');
        const tableBody = document.getElementById('tableBody');
        const allRows = Array.from(tableBody.children);
        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            allRows.forEach(row => {
                const label = row.querySelector('.metric-label').textContent.toLowerCase();
                row.style.display = label.includes(searchTerm) ? '' : 'none';
            });
        });
        // No sorting or pagination needed for transposed table

        // Weekly Chart
        const ctx = document.getElementById('weeklyChart').getContext('2d');
        const weeklyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($dayInfo) {
                    return $dayInfo['day'];
                }, $uniqueDays)) ?>,
                datasets: [
                    {
                        label: 'Net Amount',
                        data: <?= json_encode(array_map(function($dayInfo) use ($weeklyData) {
                            return $weeklyData[$dayInfo['index']]['net_amount'];
                        }, $uniqueDays)) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Running Balance',
                        data: <?= json_encode(array_map(function($dayInfo) use ($weeklyData) {
                            return $weeklyData[$dayInfo['index']]['running_balance'];
                        }, $uniqueDays)) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e5e7eb'
                        },
                        ticks: {
                            callback: function(value) {
                                return 'N$' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: '#e5e7eb'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });

        // Toast notification function
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.toast-notification');
            existingToasts.forEach(toast => toast.remove());

            const icons = {
                success: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`,
                error: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>`,
                info: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`
            };

            const toast = document.createElement('div');
            toast.className = `toast-notification fixed top-4 right-4 px-4 py-3 rounded-md text-white shadow-lg z-50 flex items-center gap-2 ${
                type === 'success' ? 'bg-teal-500' : 
                type === 'error' ? 'bg-rose-600' : 
                'bg-sky-500'
            }`;
            
            toast.innerHTML = `
                ${icons[type]}
                <span>${message}</span>
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-full');
                setTimeout(() => {
                    toast.remove();
                }, 500);
            }, 3000);
        }
    </script>
</body>
</html> 