<?php

session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Activation check
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

// Handle month selection (format: YYYY-MM)
$selectedMonth = isset($_POST['month']) ? $_POST['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthParts = explode('-', $selectedMonth);
$year = (int)$monthParts[0];
$monthNum = (int)ltrim($monthParts[1], '0');
if ($monthNum < 1) $monthNum = 1;
if ($monthNum > 12) $monthNum = 12;

// Calculate month start and end
$monthStart = new DateTime();
$monthStart->setDate($year, $monthNum, 1);
$monthEnd = clone $monthStart;
$monthEnd->modify('last day of this month');

$monthStartStr = $monthStart->format('Y-m-d');
$monthEndStr = $monthEnd->format('Y-m-d');

// Get all months with transactions
$monthsQuery = $db->prepare("\n    SELECT DISTINCT strftime('%Y-%m', created_at) AS month_year\n    FROM (\n        SELECT created_at FROM orders\n        UNION ALL\n        SELECT created_at FROM credit_sales\n        UNION ALL\n        SELECT payment_date AS created_at FROM payments\n        UNION ALL\n        SELECT created_at FROM cash_transactions\n    )\n    ORDER BY month_year DESC\n");
$monthsQuery->execute();
$availableMonths = $monthsQuery->fetchAll(PDO::FETCH_COLUMN);

// Always add current month if not in list
$currentMonth = date('Y-m');
if (!in_array($currentMonth, $availableMonths)) {
    array_unshift($availableMonths, $currentMonth);
}

// Initialize monthly data
$monthlyData = [];

// Create an array of dates for each day of the month
$monthDates = [];
$daysInMonth = (int)$monthEnd->format('j');
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = new DateTime();
    $date->setDate($year, $monthNum, $d);
    $monthDates[] = $date->format('Y-m-d');
}

// Process each day
foreach ($monthDates as $dateStr) {
    $nextDay = new DateTime($dateStr);
    $nextDay->modify('+1 day');
    $nextDayStr = $nextDay->format('Y-m-d');

    // CASH SALES: Cash portion of orders (total - EFT amounts for mixed payments)
    $cashSalesQuery = $db->prepare("\n        SELECT COALESCE(SUM(\n            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)\n        ), 0) \n        FROM orders o\n        WHERE (\n            (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= '$closingTime') OR\n            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        )\n    ");
    $cashSalesQuery->bindParam(':date', $dateStr);
    $cashSalesQuery->bindParam(':nextDay', $nextDayStr);
    $cashSalesQuery->execute();
    $cashSales = $cashSalesQuery->fetchColumn() ?: 0;

    // EFT SALES: Regular orders paid via EFT (not credit sales)
    $eftSalesQuery = $db->prepare("\n        SELECT COALESCE(SUM(e.amount), 0) \n        FROM eft_payments e \n        JOIN orders o ON e.order_id = o.id \n        WHERE NOT EXISTS (SELECT 1 FROM credit_sales cs WHERE cs.id = o.id) AND (\n            (DATE(o.created_at) = :date AND strftime('%H:%M', o.created_at) >= '$closingTime') OR\n            (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        )\n    ");
    $eftSalesQuery->bindParam(':date', $dateStr);
    $eftSalesQuery->bindParam(':nextDay', $nextDayStr);
    $eftSalesQuery->execute();
    $eftSales = $eftSalesQuery->fetchColumn() ?: 0;

    // CREDIT ISSUED: Outstanding credit sales (amount still owed)
    $creditIssuedQuery = $db->prepare("\n        SELECT COALESCE(SUM(total_amount - COALESCE(paid_amount, 0)), 0) \n        FROM credit_sales \n        WHERE (\n            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR\n            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        ) AND payment_status IN ('unpaid', 'partial')\n    ");
    $creditIssuedQuery->bindParam(':date', $dateStr);
    $creditIssuedQuery->bindParam(':nextDay', $nextDayStr);
    $creditIssuedQuery->execute();
    $creditIssued = $creditIssuedQuery->fetchColumn() ?: 0;

    // CREDIT PAYMENTS: Cash payments received for credit sales (excluding EFT payments)
    $creditPaymentsQuery = $db->prepare("\n        SELECT COALESCE(SUM(p.amount), 0) \n        FROM payments p\n        JOIN credit_sales cs ON p.sale_id = cs.id\n        WHERE cs.payment_status IN ('paid', 'partial') AND (\n            (DATE(p.payment_date) = :date AND strftime('%H:%M', p.payment_date) >= '$closingTime') OR\n            (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        )\n    ");
    $creditPaymentsQuery->bindParam(':date', $dateStr);
    $creditPaymentsQuery->bindParam(':nextDay', $nextDayStr);
    $creditPaymentsQuery->execute();
    $creditPayments = $creditPaymentsQuery->fetchColumn() ?: 0;

    // EFT CREDIT PAYMENTS: EFT payments for credit sales based on payment date
    $eftCreditPaymentsQuery = $db->prepare("\n        SELECT COALESCE(SUM(p.amount), 0) \n        FROM payments p\n        JOIN credit_sales cs ON p.sale_id = cs.id\n        WHERE cs.payment_status = 'eft' AND (\n            (DATE(p.payment_date) = :date AND strftime('%H:%M', p.payment_date) >= '$closingTime') OR\n            (DATE(p.payment_date) = :nextDay AND strftime('%H:%M', p.payment_date) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        )\n    ");
    $eftCreditPaymentsQuery->bindParam(':date', $dateStr);
    $eftCreditPaymentsQuery->bindParam(':nextDay', $nextDayStr);
    $eftCreditPaymentsQuery->execute();
    $eftCreditPayments = $eftCreditPaymentsQuery->fetchColumn() ?: 0;

    // CASH IN
    $cashInQuery = $db->prepare("\n        SELECT COALESCE(SUM(amount), 0) \n        FROM cash_transactions\n        WHERE type = 'cash-in' AND (\n            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR\n            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        )\n    ");
    $cashInQuery->bindParam(':date', $dateStr);
    $cashInQuery->bindParam(':nextDay', $nextDayStr);
    $cashInQuery->execute();
    $cashIn = $cashInQuery->fetchColumn() ?: 0;

    // CASH OUT
    $cashOutQuery = $db->prepare("\n        SELECT COALESCE(SUM(amount), 0) \n        FROM cash_transactions\n        WHERE type = 'cash-out' AND (\n            (DATE(created_at) = :date AND strftime('%H:%M', created_at) >= '$closingTime') OR\n            (DATE(created_at) = :nextDay AND strftime('%H:%M', created_at) < '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")\n        )\n    ");
    $cashOutQuery->bindParam(':date', $dateStr);
    $cashOutQuery->bindParam(':nextDay', $nextDayStr);
    $cashOutQuery->execute();
    $cashOut = $cashOutQuery->fetchColumn() ?: 0;

    // Totals
    $cashReceived = $cashSales + $creditPayments; // cash orders + cash credit payments
    $eftReceived = $eftSales + $eftCreditPayments; // EFT orders + EFT credit payments
    $totalIncome = $cashReceived + $eftReceived + $cashIn;
    $totalExpenses = $cashOut;
    $netAmount = $totalIncome - $totalExpenses;

    $monthlyData[] = [
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

// Calculate running balance
$runningBalance = 0;
foreach ($monthlyData as $index => $day) {
    $runningBalance += $day['net_amount'];
    $monthlyData[$index]['running_balance'] = $runningBalance;
}

// Calculate monthly totals
$monthlyTotals = [
    'cash_sales' => array_sum(array_column($monthlyData, 'cash_sales')),
    'eft_sales' => array_sum(array_column($monthlyData, 'eft_sales')),
    'credit_issued' => array_sum(array_column($monthlyData, 'credit_issued')),
    'credit_payments' => array_sum(array_column($monthlyData, 'credit_payments')),
    'eft_credit_payments' => array_sum(array_column($monthlyData, 'eft_credit_payments')),
    'cash_in' => array_sum(array_column($monthlyData, 'cash_in')),
    'cash_out' => array_sum(array_column($monthlyData, 'cash_out')),
    'cash_received' => array_sum(array_column($monthlyData, 'cash_received')),
    'eft_received' => array_sum(array_column($monthlyData, 'eft_received')),
    'total_income' => array_sum(array_column($monthlyData, 'total_income')),
    'total_expenses' => array_sum(array_column($monthlyData, 'total_expenses')),
    'net_amount' => array_sum(array_column($monthlyData, 'net_amount'))
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Sales Report</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <style>
        body { overflow-x: hidden; }
        .sidebar { position: fixed; top: 0; left: 0; width: 16rem; height: 100vh; z-index: 40; }
        .content { margin-left: 16rem; min-height: 100vh; width: calc(100vw - 16rem); max-width: calc(100vw - 16rem); overflow-x: hidden; }
        .perfect-container { max-width: calc(100vw - 17rem); width: calc(100vw - 17rem); box-sizing: border-box; }
        .stats-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; width: 100%; }
        @media (min-width: 640px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
        .bg-card { background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 0.5rem; }
        .border-border { border-color: #e5e7eb; }
        .text-muted-foreground { color: #6b7280; }
        .table-wrapper { overflow-x: auto; max-width: 100%; width: 100%; border-radius: 0.5rem; }
        .table-wrapper table { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; }
        .chart-container { width: 100%; max-width: 100%; overflow: hidden; }
        .chart-container canvas { max-width: 100% !important; height: auto !important; }
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
                <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <a href="reports" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Go Back
                        </a>
                        <div>
                            <h1 class="text-2xl lg:text-3xl font-bold text-slate-900">Monthly Sales Report</h1>
                            <div class="flex items-center gap-2 text-sm text-slate-600 mt-1">
                                <i class="fas fa-calendar-alt"></i>
                                <span><?= $monthStart->format('M Y') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <form method="POST" action="" class="flex items-center gap-4">
                            <div class="relative">
                                <select name="month" onchange="this.form.submit()" class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-slate-500 focus:border-slate-500 block px-4 py-2.5 shadow-sm transition-colors cursor-pointer">
                                    <?php foreach ($availableMonths as $monthOption): ?>
                                        <?php
                                        $mParts = explode('-', $monthOption);
                                        $mYear = $mParts[0];
                                        $mNum = $mParts[1];
                                        $mDate = DateTime::createFromFormat('Y-m-d', $mYear . '-' . $mNum . '-01');
                                        ?>
                                        <option value="<?= $monthOption ?>" <?= $monthOption === $selectedMonth ? 'selected' : '' ?>>
                                            <?= $mDate ? $mDate->format('F Y') : ($mYear . '-' . $mNum) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <a href="generate_monthly_report.php?month=<?= $monthStart->format('m') ?>&year=<?= $monthStart->format('Y') ?>" 
                           class="inline-flex items-center px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg shadow-sm transition duration-200 ease-in-out transform hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-opacity-50 whitespace-nowrap">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Export PDF
                        </a>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="stats-grid mb-8">
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Cash Received</p>
                                <h3 class="text-2xl font-bold text-teal-600">N$<?= number_format($monthlyTotals['cash_received'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-teal-100 rounded-full">
                                <i class="fas fa-dollar-sign text-teal-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Cash orders + cash credit payments</p>
                    </div>

                    <div class="bg-card rounded-xl shadow-sm border border-border p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">EFT Payments</p>
                                <h3 class="text-2xl font-bold text-purple-600">N$<?= number_format($monthlyTotals['eft_received'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-purple-100 rounded-full">
                                <i class="fas fa-credit-card text-purple-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">EFT orders + EFT credit payments</p>
                    </div>
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Total Income</p>
                                <h3 class="text-2xl font-bold text-teal-600">N$<?= number_format($monthlyTotals['total_income'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-teal-100 rounded-full">
                                <i class="fas fa-arrow-up text-teal-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Cash + EFT + Credit Payments</p>
                    </div>
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Total Expenses</p>
                                <h3 class="text-2xl font-bold text-red-600">N$<?= number_format($monthlyTotals['total_expenses'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-red-100 rounded-full">
                                <i class="fas fa-arrow-down text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Cash Out Transactions</p>
                    </div>
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Net Profit</p>
                                <h3 class="text-2xl font-bold <?= $monthlyTotals['net_amount'] >= 0 ? 'text-teal-600' : 'text-red-600' ?>">N$<?= number_format($monthlyTotals['net_amount'], 2) ?></h3>
                            </div>
                            <div class="p-3 <?= $monthlyTotals['net_amount'] >= 0 ? 'bg-teal-100' : 'bg-red-100' ?> rounded-full">
                                <i class="fas fa-chart-line <?= $monthlyTotals['net_amount'] >= 0 ? 'text-teal-600' : 'text-red-600' ?> text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Income - Expenses</p>
                    </div>
                    <div class="bg-card rounded-xl shadow-sm border border-border p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Credit Outstanding</p>
                                <h3 class="text-2xl font-bold text-amber-600">N$<?= number_format($monthlyTotals['credit_issued'], 2) ?></h3>
                            </div>
                            <div class="p-3 bg-amber-100 rounded-full">
                                <i class="fas fa-hand-holding-usd text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">Unpaid Credit Sales</p>
                    </div>
                </div>

                <!-- Monthly Breakdown Table (one row per day) -->
                <div class="bg-card rounded-xl shadow-sm border border-border overflow-hidden">
                    <div class="flex items-center justify-between p-4 border-b border-border">
                        <h2 class="text-xl font-semibold text-slate-800"><i class="fas fa-table mr-2 text-slate-500"></i>Daily Breakdown</h2>
                        <div class="relative">
                            <input type="text" id="searchInput" placeholder="Search date..." class="w-full pl-10 pr-4 py-2 border border-border rounded-lg focus:ring-2 focus:ring-slate-500 focus:outline-none focus:border-slate-500 shadow-sm transition duration-200 text-sm bg-gray-50">
                            <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="min-w-full divide-y divide-border">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-slate-600 uppercase tracking-wider">Date</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Cash Sales</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">EFT Sales</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Credit Issued</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Credit Payments (Cash)</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">EFT Credit Payments</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Cash In</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Cash Out</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Net Amount</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 uppercase tracking-wider">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-border" id="tableBody">
                                <?php foreach ($monthlyData as $day): ?>
                                    <tr>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-slate-800"><?= date('D, M j', strtotime($day['date'])) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-teal-600">N$<?= number_format($day['cash_sales'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-blue-600">N$<?= number_format($day['eft_sales'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-amber-600">N$<?= number_format($day['credit_issued'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-teal-600">N$<?= number_format($day['credit_payments'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-purple-600">N$<?= number_format($day['eft_credit_payments'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-teal-600">N$<?= number_format($day['cash_in'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right text-red-600">N$<?= number_format($day['cash_out'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right font-semibold <?= $day['net_amount'] >= 0 ? 'text-teal-700' : 'text-red-700' ?>">N$<?= number_format($day['net_amount'], 2) ?></td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-right font-bold <?= $day['running_balance'] >= 0 ? 'text-teal-800' : 'text-red-800' ?>">N$<?= number_format($day['running_balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-slate-100 border-t-2 border-slate-300">
                                <tr class="font-bold">
                                    <td class="px-3 py-2 text-sm text-slate-900 uppercase tracking-wider">Monthly Totals</td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['cash_sales'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['eft_sales'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['credit_issued'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['credit_payments'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['eft_credit_payments'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['cash_in'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['cash_out'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">N$<?= number_format($monthlyTotals['net_amount'], 2) ?></td>
                                    <td class="px-3 py-2 text-right text-sm">&nbsp;</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Monthly Chart -->
                <div class="bg-card rounded-xl shadow-sm border border-border p-6 mt-8">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4"><i class="fas fa-chart-area mr-2 text-slate-500"></i>Monthly Performance Chart</h3>
                    <div class="chart-container h-80">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const tableBody = document.getElementById('tableBody');
        const allRows = Array.from(tableBody.children);
        searchInput.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            allRows.forEach(row => {
                const dateCell = row.children[0].textContent.toLowerCase();
                row.style.display = dateCell.includes(term) ? '' : 'none';
            });
        });

        // Monthly Chart
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($d) { return date('j', strtotime($d['date'])); }, $monthlyData)) ?>,
                datasets: [
                    {
                        label: 'Net Amount',
                        data: <?= json_encode(array_map(function($d) { return $d['net_amount']; }, $monthlyData)) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Running Balance',
                        data: <?= json_encode(array_map(function($d) { return $d['running_balance']; }, $monthlyData)) ?>,
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
                    legend: { position: 'top', labels: { usePointStyle: true, padding: 20, font: { size: 12 } } }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#e5e7eb' },
                        ticks: { callback: function(value) { return 'N$' + value.toLocaleString(); } }
                    },
                    x: { grid: { color: '#e5e7eb' } }
                },
                interaction: { intersect: false, mode: 'index' }
            }
        });
    </script>
</body>
</html>


