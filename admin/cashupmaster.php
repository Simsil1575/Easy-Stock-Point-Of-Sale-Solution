<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Check if user has the correct role (admin only)
if (strtolower($_SESSION['role']) !== 'admin') {
    session_unset();
    session_destroy();
    header("Location: ../");
    exit();
}

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $activationDb = new PDO('sqlite:../active.db');
    $infoDb = new PDO('sqlite:../info.db');
    $userDb = new PDO('sqlite:../user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

// Function to get employee name from cashier_id (handles both username and ID)
function getEmployeeName($cashierId, $userDb) {
    if (empty($cashierId)) {
        return 'Unknown Employee';
    }
    
    // First try to find by username (if cashier_id is stored as username)
    try {
        $stmt = $userDb->prepare("SELECT username FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$cashierId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user['username'];
        }
    } catch (PDOException $e) {
        // Continue to try by ID
    }
    
    // Try to find by ID (if cashier_id is stored as ID)
    if (is_numeric($cashierId)) {
        try {
            $stmt = $userDb->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$cashierId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user['username'];
            }
        } catch (PDOException $e) {
            // Return cashier_id as fallback
        }
    }
    
    // Return cashier_id as fallback if no match found
    return $cashierId;
}

// Get all cashiers and waitresses for filter dropdown
$allEmployees = [];
try {
    $employeesQuery = $userDb->query("SELECT id, username, role FROM users WHERE role IN ('cashier', 'waitress', 'hubbly') ORDER BY username");
    $allEmployees = $employeesQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If query fails, leave empty
}

// Check activation status
$activationStatus = $activationDb->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Get business closing time
$businessInfo = [];
$closingTime = '22:00';
try {
    $businessInfo = $infoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '22:00';
} catch (PDOException $e) {
    $closingTime = '22:00';
}

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$isAfterMidnight = $closingHour < 12;

// Handle date selection and cashier filter (accept both GET and POST)
$selectedDate = isset($_POST['date']) ? $_POST['date'] : (isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'));
$nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Handle cashier filter (accept both GET and POST)
$selectedCashier = isset($_POST['cashier_id']) ? $_POST['cashier_id'] : (isset($_GET['cashier_id']) ? $_GET['cashier_id'] : 'all');
$selectedCashierName = 'All Staff';
$selectedCashierNumericId = null; // For tables that store numeric ID (like tabs)

if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
    $selectedCashierName = getEmployeeName($selectedCashier, $userDb);
    
    // Get the numeric user ID for tables that store it numerically (like tabs)
    try {
        // If selectedCashier is already numeric, use it
        if (is_numeric($selectedCashier)) {
            $selectedCashierNumericId = intval($selectedCashier);
        } else {
            // Look up the numeric ID from the username
            $idLookup = $userDb->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $idLookup->execute([$selectedCashier]);
            $idResult = $idLookup->fetch(PDO::FETCH_ASSOC);
            if ($idResult) {
                $selectedCashierNumericId = intval($idResult['id']);
            }
        }
    } catch (PDOException $e) {
        // If lookup fails, leave as null
    }
}

/**
 * Helper function to get business day WHERE clause
 * 
 * ALL queries in this file use business day logic to respect the business closing time.
 * Business day logic ensures that transactions occurring after closing time on one day
 * are counted as part of the next business day. This matches the logic used in admin/cash.php.
 * 
 * Business day definition:
 * - Transactions from closing time on selectedDate until closing time on nextBusinessDay
 * - If closing time is after midnight (e.g., 2:00 AM), transactions from midnight to closing
 *   time on nextBusinessDay are counted as part of selectedDate's business day
 */
function getBusinessDayWhere($dateField) {
    global $selectedDate, $nextBusinessDay, $closingTime, $isAfterMidnight;
    return "
        (DATE($dateField) = :selectedDate AND strftime('%H:%M', $dateField) >= :closingTime) OR
        (DATE($dateField) = :nextBusinessDay AND strftime('%H:%M', $dateField) < :closingTime AND :isAfterMidnight = 1)
    ";
}

/**
 * Helper function to get cashier filter WHERE clause
 * Use for tables that store cashier_id as username string
 */
function getCashierFilter($cashierIdField) {
    global $selectedCashier;
    if ($selectedCashier === 'all' || empty($selectedCashier)) {
        return "";
    }
    return " AND $cashierIdField = :cashierId";
}

/**
 * Helper function to bind cashier parameter (for username-based cashier_id)
 */
function bindCashierParam($stmt) {
    global $selectedCashier;
    if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
        $stmt->bindParam(':cashierId', $selectedCashier);
    }
}

/**
 * Helper function to get cashier filter WHERE clause for numeric ID fields
 * Use for tables that store cashier_id as numeric user ID (like tabs)
 */
function getCashierFilterNumeric($cashierIdField) {
    global $selectedCashierNumericId;
    if ($selectedCashierNumericId === null) {
        return "";
    }
    return " AND $cashierIdField = :cashierNumericId";
}

/**
 * Helper function to bind cashier parameter for numeric ID
 */
function bindCashierParamNumeric($stmt) {
    global $selectedCashierNumericId;
    if ($selectedCashierNumericId !== null) {
        $stmt->bindValue(':cashierNumericId', $selectedCashierNumericId, PDO::PARAM_INT);
    }
}

// Calculate system values for selected date
// 1. Cash Sales (Expected) - from orders excluding EFT payments
$eftTableExists = false;
try {
    $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
    $eftTableExists = ($checkEftTable->fetchColumn() !== false);
} catch (PDOException $e) {
    $eftTableExists = false;
}

if ($eftTableExists) {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(
            o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
        ), 0)
        FROM orders o
        WHERE (" . getBusinessDayWhere('o.created_at') . ")" . getCashierFilter('o.cashier_id') . "
    ");
} else {
    $cashSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0) 
        FROM orders 
        WHERE (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
}
$cashSalesQuery->bindParam(':selectedDate', $selectedDate);
$cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashSalesQuery->bindParam(':closingTime', $closingTime);
$cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($cashSalesQuery);
$cashSalesQuery->execute();
$totalCashSales = $cashSalesQuery->fetchColumn();

// Calculate Cash In Till (same as admin/cash.php)
// Get selected date's cash in transactions
$cashInQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-in' AND (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$cashInQuery->bindParam(':selectedDate', $selectedDate);
$cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashInQuery->bindParam(':closingTime', $closingTime);
$cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($cashInQuery);
$cashInQuery->execute();
$totalCashIn = $cashInQuery->fetchColumn();

// Get selected date's credit payments using payments table
$creditPaymentsQuery = $db->prepare("
    SELECT COALESCE(SUM(p.amount), 0) 
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.payment_status = 'paid' AND (" . getBusinessDayWhere('p.payment_date') . ")" . getCashierFilter('p.cashier_id') . "
");
$creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
$creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$creditPaymentsQuery->bindParam(':closingTime', $closingTime);
$creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($creditPaymentsQuery);
$creditPaymentsQuery->execute();
$totalCreditPayments = $creditPaymentsQuery->fetchColumn();

// Get selected date's cash out
$cashOutQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-out' AND (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$cashOutQuery->bindParam(':selectedDate', $selectedDate);
$cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashOutQuery->bindParam(':closingTime', $closingTime);
$cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($cashOutQuery);
$cashOutQuery->execute();
$totalCashOut = $cashOutQuery->fetchColumn();

// Calculate cash in till for selected date's business day (same formula as admin/cash.php)
$cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;

// Cash Sales (Expected) must balance with cash in till amount
$cashSalesExpected = $cashInTill;

// 2. Card Sales (Expected) - from eft_payments
$cardSalesExpected = 0;
if ($eftTableExists) {
    $cardSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(ep.amount), 0)
        FROM eft_payments ep
        JOIN orders o ON ep.order_id = o.id
        WHERE (" . getBusinessDayWhere('ep.payment_date') . ")" . getCashierFilter('ep.cashier_id') . "
    ");
    $cardSalesQuery->bindParam(':selectedDate', $selectedDate);
    $cardSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
    $cardSalesQuery->bindParam(':closingTime', $closingTime);
    $cardSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
    bindCashierParam($cardSalesQuery);
    $cardSalesQuery->execute();
    $cardSalesExpected = $cardSalesQuery->fetchColumn();
}

// 3. Unpaid Tabs (Credit) - includes unpaid credit sales AND open tabs
// Get unpaid credit sales for selected date
$unpaidCreditSalesQuery = $db->prepare("
    SELECT COALESCE(SUM(total_amount - paid_amount), 0)
    FROM credit_sales
    WHERE payment_status = 'unpaid' AND (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$unpaidCreditSalesQuery->bindParam(':selectedDate', $selectedDate);
$unpaidCreditSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$unpaidCreditSalesQuery->bindParam(':closingTime', $closingTime);
$unpaidCreditSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($unpaidCreditSalesQuery);
$unpaidCreditSalesQuery->execute();
$unpaidCreditSales = $unpaidCreditSalesQuery->fetchColumn();

// Get open tabs current balance for selected date
// Note: tabs table stores cashier_id as numeric user ID, not username
$openTabsQuery = $db->prepare("
    SELECT COALESCE(SUM(current_balance), 0)
    FROM tabs
    WHERE status = 'open' AND (" . getBusinessDayWhere('opened_at') . ")" . getCashierFilterNumeric('cashier_id') . "
");
$openTabsQuery->bindParam(':selectedDate', $selectedDate);
$openTabsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$openTabsQuery->bindParam(':closingTime', $closingTime);
$openTabsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParamNumeric($openTabsQuery);
$openTabsQuery->execute();
$openTabsBalance = $openTabsQuery->fetchColumn();

// Keep unpaid credit sales and open tabs balance separate
// Total unpaid tabs = unpaid credit sales + open tabs balance (for reference only)
$unpaidTabs = $unpaidCreditSales + $openTabsBalance;

// 4. Credit Returns - from credit_returns + credit payments
$creditReturnsQuery = $db->prepare("
    SELECT COALESCE(SUM(return_amount), 0)
    FROM credit_returns
    WHERE (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$creditReturnsQuery->bindParam(':selectedDate', $selectedDate);
$creditReturnsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$creditReturnsQuery->bindParam(':closingTime', $closingTime);
$creditReturnsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($creditReturnsQuery);
$creditReturnsQuery->execute();
$creditReturnsAmount = $creditReturnsQuery->fetchColumn();

// Credit Returns includes credit returns + credit payments
$creditReturns = $creditReturnsAmount + $totalCreditPayments;

// 5. Expenses - from cash_transactions where type = 'cash-out' (excluding tips and cash back)
$expensesQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM cash_transactions
    WHERE type = 'cash-out' 
    AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%')
    AND (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$expensesQuery->bindParam(':selectedDate', $selectedDate);
$expensesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$expensesQuery->bindParam(':closingTime', $closingTime);
$expensesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($expensesQuery);
$expensesQuery->execute();
$expenses = $expensesQuery->fetchColumn();

// 6. Cash Back - from cash_transactions where description contains 'Cash Back'
$cashBackQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM cash_transactions
    WHERE type = 'cash-out' 
    AND (description LIKE '%Cash Back%' OR description LIKE '%cash back%')
    AND (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$cashBackQuery->bindParam(':selectedDate', $selectedDate);
$cashBackQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$cashBackQuery->bindParam(':closingTime', $closingTime);
$cashBackQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($cashBackQuery);
$cashBackQuery->execute();
$cashBackSystem = $cashBackQuery->fetchColumn();

// 7. Tips — from tips table only (never cash-out / not expenses)
$tipsSystem = 0;
try {
    if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tips'")->fetchColumn()) {
        $tipsQuery = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM tips
            WHERE (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
        ");
        $tipsQuery->bindParam(':selectedDate', $selectedDate);
        $tipsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
        $tipsQuery->bindParam(':closingTime', $closingTime);
        $tipsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
        bindCashierParam($tipsQuery);
        $tipsQuery->execute();
        $tipsSystem = (float) $tipsQuery->fetchColumn();
    }
} catch (PDOException $e) {
    $tipsSystem = 0;
}

// 8. Voids - from void_transactions
$voidsQuery = $db->prepare("
    SELECT COALESCE(SUM(total), 0)
    FROM void_transactions
    WHERE (" . getBusinessDayWhere('voided_at') . ")" . getCashierFilter('cashier_id') . "
");
$voidsQuery->bindParam(':selectedDate', $selectedDate);
$voidsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$voidsQuery->bindParam(':closingTime', $closingTime);
$voidsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($voidsQuery);
$voidsQuery->execute();
$voids = $voidsQuery->fetchColumn();

// 9. Refunds - from refunds
$refundsQuery = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM refunds
    WHERE (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$refundsQuery->bindParam(':selectedDate', $selectedDate);
$refundsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$refundsQuery->bindParam(':closingTime', $closingTime);
$refundsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($refundsQuery);
$refundsQuery->execute();
$refunds = $refundsQuery->fetchColumn();

// 10. Total Value of Items Sold - sum of all order totals (cash + card + credit)
$totalItemsSoldQuery = $db->prepare("
    SELECT COALESCE(SUM(total), 0)
    FROM orders
    WHERE (" . getBusinessDayWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
$totalItemsSoldQuery->bindParam(':selectedDate', $selectedDate);
$totalItemsSoldQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$totalItemsSoldQuery->bindParam(':closingTime', $closingTime);
$totalItemsSoldQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
bindCashierParam($totalItemsSoldQuery);
$totalItemsSoldQuery->execute();
$totalItemsSold = $totalItemsSoldQuery->fetchColumn() ?? 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_cashup'])) {
    $cashOnHand = floatval($_POST['cash_on_hand'] ?? 0);
    $cashBack = floatval($_POST['cash_back'] ?? 0);
    $tips = floatval($_POST['tips'] ?? 0);
    $hubbly = floatval($_POST['hubbly'] ?? 0);
    $beerhouse = floatval($_POST['beerhouse'] ?? 0);
    
    // Calculate over/short
    $overShort = $cashOnHand - $cashSalesExpected;
    
    // Store in session for receipt printing
    $_SESSION['cashup_data'] = [
        'date' => $selectedDate,
        'cashier_id' => $selectedCashier,
        'cashier_name' => $selectedCashierName,
        'cash_sales_expected' => $cashSalesExpected,
        'cash_on_hand' => $cashOnHand,
        'over_short' => $overShort,
        'card_sales_expected' => $cardSalesExpected,
        'unpaid_credit_sales' => $unpaidCreditSales,
        'open_tabs_balance' => $openTabsBalance,
        'unpaid_tabs' => $unpaidTabs,
        'credit_returns' => $creditReturns,
        'expenses' => $expenses,
        'cash_back' => $cashBack,
        'tips' => $tips,
        'hubbly' => $hubbly,
        'beerhouse' => $beerhouse,
        'voids' => $voids,
        'refunds' => $refunds,
        'total_items_sold' => $totalItemsSold
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Up Master</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
            .print-section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
            <h1 class="text-3xl font-bold text-gray-800 mb-4">Cash Up Master</h1>
            
            <!-- Date & Cashier Selection -->
            <form method="POST" class="mb-4">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-2">
                        <label for="date" class="text-gray-700 font-medium">Select Date:</label>
                        <input type="date" 
                               id="date" 
                               name="date" 
                               value="<?= htmlspecialchars($selectedDate) ?>" 
                               class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="cashier_id" class="text-gray-700 font-medium">Cashier/Waitress:</label>
                        <select id="cashier_id" 
                                name="cashier_id" 
                                class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-w-[200px]">
                            <option value="all" <?= $selectedCashier === 'all' ? 'selected' : '' ?>>All Staff</option>
                            <?php foreach ($allEmployees as $employee): ?>
                            <option value="<?= htmlspecialchars($employee['username']) ?>" 
                                    <?= $selectedCashier === $employee['username'] || $selectedCashier == $employee['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($employee['username']) ?> (<?= ucfirst($employee['role']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" 
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        Load Data
                    </button>
                </div>
            </form>
            
            <?php if ($selectedCashier !== 'all'): ?>
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-blue-800 font-medium">
                    Showing cashout data for: <span class="font-bold"><?= htmlspecialchars($selectedCashierName) ?></span>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Cash Up Form -->
        <form method="POST" id="cashupForm" class="bg-white rounded-lg shadow-md p-6 print-section" onsubmit="return handleCashupSubmit(event)">
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
            <input type="hidden" name="cashier_id" value="<?= htmlspecialchars($selectedCashier) ?>">
            
            <?php if ($selectedCashier !== 'all'): ?>
            <div class="mb-6 p-4 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg shadow">
                <h2 class="text-xl font-bold">Cashout Report for: <?= htmlspecialchars($selectedCashierName) ?></h2>
                <p class="text-blue-100 text-sm">Date: <?= date('F d, Y', strtotime($selectedDate)) ?></p>
            </div>
            <?php endif; ?>
            
            <!-- CASH SECTION -->
            <div class="mb-8">
                <div class="border-b-2 border-gray-300 mb-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">CASH</h2>
                </div>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Cash Sales (Expected)</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($cashSalesExpected, 2) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label for="cash_on_hand" class="text-gray-700 font-medium">Cash on Hand</label>
                        <input type="number" 
                               id="cash_on_hand" 
                               name="cash_on_hand" 
                               step="0.01" 
                               min="0"
                               value="0"
                               class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-right font-semibold focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                    
                    <div class="flex justify-between items-center py-2 border-t border-gray-200">
                        <label class="text-gray-700 font-medium">Over / Short</label>
                        <span id="over_short" class="text-gray-900 font-semibold">N$ 0.00</span>
                    </div>
                </div>
            </div>

            <!-- CARD & CREDIT SECTION -->
            <div class="mb-8">
                <div class="border-b-2 border-gray-300 mb-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">CARD & CREDIT</h2>
                </div>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Card Sales (Expected)</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($cardSalesExpected, 2) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Unpaid Credit Sales</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($unpaidCreditSales, 2) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Open Tabs Balance</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($openTabsBalance, 2) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Credit Returns</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($creditReturns, 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- DEDUCTIONS SECTION -->
            <div class="mb-8">
                <div class="border-b-2 border-gray-300 mb-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">DEDUCTIONS</h2>
                </div>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Expenses</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($expenses, 2) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label for="cash_back" class="text-gray-700 font-medium">Cash Back</label>
                        <input type="number" 
                               id="cash_back" 
                               name="cash_back" 
                               step="0.01" 
                               min="0"
                               value="<?= number_format($cashBackSystem, 2, '.', '') ?>"
                               class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-right font-semibold focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label for="tips" class="text-gray-700 font-medium">Tips</label>
                        <input type="number" 
                               id="tips" 
                               name="tips" 
                               step="0.01" 
                               min="0"
                               value="<?= number_format($tipsSystem, 2, '.', '') ?>"
                               class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-right font-semibold focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                </div>
            </div>

            <!-- SALES SOURCES (INFO) SECTION -->
            <div class="mb-8">
                <div class="border-b-2 border-gray-300 mb-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">SALES SOURCES (INFO)</h2>
                </div>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center py-2">
                        <label for="hubbly" class="text-gray-700 font-medium">Hubbly</label>
                        <input type="number" 
                               id="hubbly" 
                               name="hubbly" 
                               step="0.01" 
                               min="0"
                               value="0"
                               class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-right font-semibold focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label for="beerhouse" class="text-gray-700 font-medium">Beerhouse</label>
                        <input type="number" 
                               id="beerhouse" 
                               name="beerhouse" 
                               step="0.01" 
                               min="0"
                               value="0"
                               class="w-32 px-3 py-2 border border-gray-300 rounded-lg text-right font-semibold focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               required>
                    </div>
                </div>
            </div>

            <!-- ADJUSTMENTS SECTION -->
            <div class="mb-8">
                <div class="border-b-2 border-gray-300 mb-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">ADJUSTMENTS</h2>
                </div>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Voids</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($voids, 2) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Refunds</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($refunds, 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- TOTAL VALUE OF ITEMS SOLD SECTION -->
            <div class="mb-8">
                <div class="border-b-2 border-gray-300 mb-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-2">TOTAL VALUE OF ITEMS SOLD</h2>
                </div>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center py-2">
                        <label class="text-gray-700 font-medium">Total Value of Items Sold</label>
                        <span class="text-gray-900 font-semibold">N$ <?= number_format($totalItemsSold, 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-4 justify-end mt-6 no-print">
                <button type="button" 
                        onclick="downloadCashupMasterPDF()"
                        class="bg-purple-600 text-white px-6 py-3 rounded-lg hover:bg-purple-700 transition-colors font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download PDF
                </button>
                <button type="button" 
                        onclick="printCashupMasterReceipt()"
                        class="bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 transition-colors font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    Print Receipt
                </button>
                <button type="button" 
                        onclick="window.print()"
                        class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-colors font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Print Page
                </button>
            </div>
        </form>

        <!-- Receipt Display -->
        <?php if (isset($_SESSION['cashup_data'])): ?>
        <div class="bg-white rounded-lg shadow-md p-6 mt-6 print-section" id="receipt">
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">CASH UP RECEIPT</h2>
                <p class="text-gray-600">Date: <?= date('F d, Y', strtotime($_SESSION['cashup_data']['date'])) ?></p>
                <?php if (isset($_SESSION['cashup_data']['cashier_name']) && $_SESSION['cashup_data']['cashier_id'] !== 'all'): ?>
                <p class="text-blue-600 font-semibold mt-1">Staff: <?= htmlspecialchars($_SESSION['cashup_data']['cashier_name']) ?></p>
                <?php endif; ?>
            </div>
            
            <div class="space-y-4">
                <!-- CASH -->
                <div>
                    <div class="border-b-2 border-gray-300 mb-2">
                        <h3 class="text-lg font-bold text-gray-800">CASH</h3>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Cash Sales (Expected)</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['cash_sales_expected'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Cash on Hand</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['cash_on_hand'], 2) ?></span>
                        </div>
                        <div class="flex justify-between border-t border-gray-200 pt-1">
                            <span>Over / Short</span>
                            <span class="font-semibold <?= $_SESSION['cashup_data']['over_short'] < 0 ? 'text-red-600' : 'text-green-600' ?>">
                                N$ <?= number_format($_SESSION['cashup_data']['over_short'], 2) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- CARD & CREDIT -->
                <div>
                    <div class="border-b-2 border-gray-300 mb-2">
                        <h3 class="text-lg font-bold text-gray-800">CARD & CREDIT</h3>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Card Sales (Expected)</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['card_sales_expected'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Unpaid Credit Sales</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['unpaid_credit_sales'] ?? 0, 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Open Tabs Balance</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['open_tabs_balance'] ?? 0, 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Credit Returns</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['credit_returns'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- DEDUCTIONS -->
                <div>
                    <div class="border-b-2 border-gray-300 mb-2">
                        <h3 class="text-lg font-bold text-gray-800">DEDUCTIONS</h3>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Expenses</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['expenses'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Cash Back</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['cash_back'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tips</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['tips'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- SALES SOURCES (INFO) -->
                <div>
                    <div class="border-b-2 border-gray-300 mb-2">
                        <h3 class="text-lg font-bold text-gray-800">SALES SOURCES (INFO)</h3>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Hubbly</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['hubbly'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Beerhouse</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['beerhouse'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- ADJUSTMENTS -->
                <div>
                    <div class="border-b-2 border-gray-300 mb-2">
                        <h3 class="text-lg font-bold text-gray-800">ADJUSTMENTS</h3>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Voids</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['voids'], 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Refunds</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['refunds'], 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- TOTAL VALUE OF ITEMS SOLD -->
                <div>
                    <div class="border-b-2 border-gray-300 mb-2">
                        <h3 class="text-lg font-bold text-gray-800">TOTAL VALUE OF ITEMS SOLD</h3>
                    </div>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <span>Total Value of Items Sold</span>
                            <span class="font-semibold">N$ <?= number_format($_SESSION['cashup_data']['total_items_sold'], 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Include sendToPrinter function from receipt.php -->
    <script src="../receipt.php?js=true"></script>
    <script>
        // Calculate Over/Short automatically
        const cashOnHandInput = document.getElementById('cash_on_hand');
        const overShortSpan = document.getElementById('over_short');
        const cashSalesExpected = <?= $cashSalesExpected ?>;

        function calculateOverShort() {
            const cashOnHand = parseFloat(cashOnHandInput.value) || 0;
            const overShort = cashOnHand - cashSalesExpected;
            overShortSpan.textContent = 'N$ ' + overShort.toFixed(2);
            
            // Color code: red for negative (short), green for positive (over)
            if (overShort < 0) {
                overShortSpan.classList.remove('text-green-600');
                overShortSpan.classList.add('text-red-600');
            } else {
                overShortSpan.classList.remove('text-red-600');
                overShortSpan.classList.add('text-green-600');
            }
        }

        cashOnHandInput.addEventListener('input', calculateOverShort);
        cashOnHandInput.addEventListener('change', calculateOverShort);
        
        // Initial calculation
        calculateOverShort();

        // Download Cash-up Master PDF
        function downloadCashupMasterPDF() {
            // Get form values
            const cashOnHand = parseFloat(document.getElementById('cash_on_hand').value) || 0;
            const cashBack = parseFloat(document.getElementById('cash_back').value) || 0;
            
            // Create form to submit for PDF generation
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'cashupmaster-pdf.php';
            
            // Add all data as hidden fields
            const pdfData = {
                date: '<?= $selectedDate ?>',
                cash_on_hand: cashOnHand,
                cash_back: cashBack
            };
            
            for (const [key, value] of Object.entries(pdfData)) {
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
        }
        
        // Print Cash-up Master Receipt
        function printCashupMasterReceipt() {
            // Get form values
            const cashOnHand = parseFloat(document.getElementById('cash_on_hand').value) || 0;
            const cashBack = parseFloat(document.getElementById('cash_back').value) || 0;
            const tips = parseFloat(document.getElementById('tips').value) || 0;
            const hubbly = parseFloat(document.getElementById('hubbly').value) || 0;
            const beerhouse = parseFloat(document.getElementById('beerhouse').value) || 0;
            
            // Calculate over/short
            const overShort = cashOnHand - cashSalesExpected;
            
            // Get system values from PHP
            const receiptData = {
                is_cashup_master_report: true,
                print_only: true,
                date: '<?= $selectedDate ?>',
                cashier_username: '<?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>',
                // Cashier filter info
                filter_cashier_id: '<?= htmlspecialchars($selectedCashier) ?>',
                filter_cashier_name: '<?= htmlspecialchars($selectedCashierName) ?>',
                is_individual_cashout: <?= $selectedCashier !== 'all' ? 'true' : 'false' ?>,
                // CASH section
                cash_sales_expected: <?= $cashSalesExpected ?>,
                cash_on_hand: cashOnHand,
                over_short: overShort,
                // CARD & CREDIT section
                card_sales_expected: <?= $cardSalesExpected ?>,
                unpaid_credit_sales: <?= $unpaidCreditSales ?>,
                open_tabs_balance: <?= $openTabsBalance ?>,
                unpaid_tabs: <?= $unpaidTabs ?>,
                credit_returns: <?= $creditReturns ?>,
                // DEDUCTIONS section
                expenses: <?= $expenses ?>,
                cash_back: cashBack,
                tips: tips,
                // SALES SOURCES (INFO) section
                hubbly: hubbly,
                beerhouse: beerhouse,
                // ADJUSTMENTS section
                voids: <?= $voids ?>,
                refunds: <?= $refunds ?>,
                // TOTAL VALUE OF ITEMS SOLD section
                total_items_sold: <?= $totalItemsSold ?>
            };
            
            console.log('[CashupMaster] Printing receipt with data:', receiptData);
            
            // Use sendToPrinter (routes to QZ Tray when enabled) or fallback to direct fetch
            const printFn = (typeof window.sendToPrinter === 'function')
                ? (data) => window.sendToPrinter(data)
                : (data) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => r.json());
            printFn(receiptData)
            .then(result => {
                console.log('[CashupMaster] Print result:', result);
                if (result && result.success) {
                    // Show success notification
                    showNotification('Cash-up receipt printed successfully!', 'success');
                } else {
                    const errorMsg = result?.message || result?.error || 'Unknown error';
                    showNotification('Print failed: ' + errorMsg, 'error');
                }
            })
            .catch(error => {
                console.error('[CashupMaster] Print error:', error);
                showNotification('Print error: ' + error.message, 'error');
            });
        }
        
        // Simple notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 ${type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
    </script>
</body>
</html>
