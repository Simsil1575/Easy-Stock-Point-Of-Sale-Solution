<?php
/**
 * Get Cash Up Data API
 * Returns calculated values for cash up modal based on selected date and cashier
 * Uses the same business day logic as cashupmaster.php
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$today = date('Y-m-d');
$startDate = $input['start_date'] ?? $input['date'] ?? $today;
$startTime = $input['start_time'] ?? '00:00';
$endDate = $input['end_date'] ?? $input['date'] ?? $today;
$endTime = $input['end_time'] ?? '23:59';
$selectedCashier = $input['cashier_id'] ?? 'all';

// Build datetime range (inclusive start and end)
$startDatetime = $startDate . ' ' . (strlen($startTime) === 5 ? $startTime : substr($startTime, 0, 5));
$endDatetime = $endDate . ' ' . (strlen($endTime) === 5 ? $endTime : substr($endTime, 0, 5));

// Database connection
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $infoDb = new PDO('sqlite:info.db');
    $userDb = new PDO('sqlite:user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

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
        // Continue to try by ID
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
            // Return cashier_id as fallback
        }
    }
    
    return $cashierId;
}

// Get selected cashier info
$selectedCashierName = 'All Staff';
$selectedCashierNumericId = null;

if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
    $selectedCashierName = getEmployeeName($selectedCashier, $userDb);
    
    try {
        if (is_numeric($selectedCashier)) {
            $selectedCashierNumericId = intval($selectedCashier);
        } else {
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

// Helper function for datetime range WHERE clause (inclusive start and end)
function getRangeWhere($dateField) {
    return " ($dateField >= :startDatetime AND $dateField <= :endDatetime) ";
}

// Helper function for cashier filter (username-based)
function getCashierFilter($cashierIdField) {
    global $selectedCashier;
    if ($selectedCashier === 'all' || empty($selectedCashier)) {
        return "";
    }
    return " AND $cashierIdField = :cashierId";
}

// Helper function for cashier filter (numeric ID-based)
function getCashierFilterNumeric($cashierIdField) {
    global $selectedCashierNumericId;
    if ($selectedCashierNumericId === null) {
        return "";
    }
    return " AND $cashierIdField = :cashierNumericId";
}

// Bind cashier param helper
function bindCashierParam($stmt) {
    global $selectedCashier;
    if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
        $stmt->bindParam(':cashierId', $selectedCashier);
    }
}

// Bind cashier numeric param helper
function bindCashierParamNumeric($stmt) {
    global $selectedCashierNumericId;
    if ($selectedCashierNumericId !== null) {
        $stmt->bindValue(':cashierNumericId', $selectedCashierNumericId, PDO::PARAM_INT);
    }
}

try {
    // Check if eft_payments table exists
    $eftTableExists = false;
    try {
        $checkEftTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'");
        $eftTableExists = ($checkEftTable->fetchColumn() !== false);
    } catch (PDOException $e) {
        $eftTableExists = false;
    }

    // 1. Cash Sales (Expected)
    if ($eftTableExists) {
        $cashSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(
                o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
            ), 0)
            FROM orders o
            WHERE (" . getRangeWhere('o.created_at') . ")" . getCashierFilter('o.cashier_id') . "
        ");
    } else {
        $cashSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(total), 0) 
            FROM orders 
            WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
        ");
    }
    $cashSalesQuery->bindParam(':startDatetime', $startDatetime);
    $cashSalesQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($cashSalesQuery);
    $cashSalesQuery->execute();
    $totalCashSales = $cashSalesQuery->fetchColumn();

    // Cash In
    $cashInQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions 
        WHERE type='cash-in' AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $cashInQuery->bindParam(':startDatetime', $startDatetime);
    $cashInQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($cashInQuery);
    $cashInQuery->execute();
    $totalCashIn = $cashInQuery->fetchColumn();

    // Credit Payments
    $creditPaymentsQuery = $db->prepare("
        SELECT COALESCE(SUM(p.amount), 0) 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.payment_status = 'paid' AND (" . getRangeWhere('p.payment_date') . ")" . getCashierFilter('p.cashier_id') . "
    ");
    $creditPaymentsQuery->bindParam(':startDatetime', $startDatetime);
    $creditPaymentsQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($creditPaymentsQuery);
    $creditPaymentsQuery->execute();
    $totalCreditPayments = $creditPaymentsQuery->fetchColumn();

    // Cash Out
    $cashOutQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions 
        WHERE type='cash-out' AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $cashOutQuery->bindParam(':startDatetime', $startDatetime);
    $cashOutQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($cashOutQuery);
    $cashOutQuery->execute();
    $totalCashOut = $cashOutQuery->fetchColumn();

    // Calculate cash in till / cash sales expected
    $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
    $cashSalesExpected = $cashInTill;

    // 2. Card Sales (Expected)
    $cardSalesExpected = 0;
    if ($eftTableExists) {
        $cardSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(ep.amount), 0)
            FROM eft_payments ep
            JOIN orders o ON ep.order_id = o.id
            WHERE (" . getRangeWhere('ep.payment_date') . ")" . getCashierFilter('ep.cashier_id') . "
        ");
        $cardSalesQuery->bindParam(':startDatetime', $startDatetime);
        $cardSalesQuery->bindParam(':endDatetime', $endDatetime);
        bindCashierParam($cardSalesQuery);
        $cardSalesQuery->execute();
        $cardSalesExpected = $cardSalesQuery->fetchColumn();
    }

    // 3. Unpaid Credit Sales
    $unpaidCreditSalesQuery = $db->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0)
        FROM credit_sales
        WHERE payment_status = 'unpaid' AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $unpaidCreditSalesQuery->bindParam(':startDatetime', $startDatetime);
    $unpaidCreditSalesQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($unpaidCreditSalesQuery);
    $unpaidCreditSalesQuery->execute();
    $unpaidCreditSales = $unpaidCreditSalesQuery->fetchColumn();

    // 4. Open Tabs Balance
    $openTabsQuery = $db->prepare("
        SELECT COALESCE(SUM(current_balance), 0)
        FROM tabs
        WHERE status = 'open' AND (" . getRangeWhere('opened_at') . ")" . getCashierFilterNumeric('cashier_id') . "
    ");
    $openTabsQuery->bindParam(':startDatetime', $startDatetime);
    $openTabsQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParamNumeric($openTabsQuery);
    $openTabsQuery->execute();
    $openTabsBalance = $openTabsQuery->fetchColumn();

    $unpaidTabs = $unpaidCreditSales + $openTabsBalance;

    // 5. Credit Returns
    $creditReturnsQuery = $db->prepare("
        SELECT COALESCE(SUM(return_amount), 0)
        FROM credit_returns
        WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $creditReturnsQuery->bindParam(':startDatetime', $startDatetime);
    $creditReturnsQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($creditReturnsQuery);
    $creditReturnsQuery->execute();
    $creditReturnsAmount = $creditReturnsQuery->fetchColumn();
    $creditReturns = $creditReturnsAmount + $totalCreditPayments;

    // 6. Expenses (excluding tips and cash back)
    $expensesQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cash_transactions
        WHERE type = 'cash-out' 
        AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%')
        AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $expensesQuery->bindParam(':startDatetime', $startDatetime);
    $expensesQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($expensesQuery);
    $expensesQuery->execute();
    $expenses = $expensesQuery->fetchColumn();

    // 7. Cash Back (system value)
    $cashBackQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cash_transactions
        WHERE type = 'cash-out' 
        AND (description LIKE '%Cash Back%' OR description LIKE '%cash back%')
        AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $cashBackQuery->bindParam(':startDatetime', $startDatetime);
    $cashBackQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($cashBackQuery);
    $cashBackQuery->execute();
    $cashBackSystem = $cashBackQuery->fetchColumn();

    // 8. Tips (system value)
    $tipsQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM cash_transactions
        WHERE type = 'cash-out' 
        AND (description LIKE '%Tips%' OR description LIKE '%tip%')
        AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $tipsQuery->bindParam(':startDatetime', $startDatetime);
    $tipsQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($tipsQuery);
    $tipsQuery->execute();
    $tipsSystem = $tipsQuery->fetchColumn();

    // 9. Voids
    $voidsQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM void_transactions
        WHERE (" . getRangeWhere('voided_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $voidsQuery->bindParam(':startDatetime', $startDatetime);
    $voidsQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($voidsQuery);
    $voidsQuery->execute();
    $voids = $voidsQuery->fetchColumn();

    // 10. Refunds
    $refundsQuery = $db->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM refunds
        WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $refundsQuery->bindParam(':startDatetime', $startDatetime);
    $refundsQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($refundsQuery);
    $refundsQuery->execute();
    $refunds = $refundsQuery->fetchColumn();

    // 11. Total Value of Items Sold
    $totalItemsSoldQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $totalItemsSoldQuery->bindParam(':startDatetime', $startDatetime);
    $totalItemsSoldQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($totalItemsSoldQuery);
    $totalItemsSoldQuery->execute();
    $totalItemsSold = $totalItemsSoldQuery->fetchColumn() ?? 0;

    // Return all calculated values
    echo json_encode([
        'success' => true,
        'date' => $endDate,
        'start_datetime' => $startDatetime,
        'end_datetime' => $endDatetime,
        'cashier_id' => $selectedCashier,
        'cashier_name' => $selectedCashierName,
        // CASH section
        'cash_sales_expected' => floatval($cashSalesExpected),
        'cash_in_till' => floatval($cashInTill),
        // CARD & CREDIT section
        'card_sales_expected' => floatval($cardSalesExpected),
        'unpaid_credit_sales' => floatval($unpaidCreditSales),
        'open_tabs_balance' => floatval($openTabsBalance),
        'unpaid_tabs' => floatval($unpaidTabs),
        'credit_returns' => floatval($creditReturns),
        // DEDUCTIONS section
        'expenses' => floatval($expenses),
        'cash_back_system' => floatval($cashBackSystem),
        'tips_system' => floatval($tipsSystem),
        // ADJUSTMENTS section
        'voids' => floatval($voids),
        'refunds' => floatval($refunds),
        // TOTAL section
        'total_items_sold' => floatval($totalItemsSold)
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
