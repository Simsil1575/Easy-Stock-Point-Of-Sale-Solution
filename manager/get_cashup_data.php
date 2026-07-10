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

// Check if user is logged in and is admin or manager
$allowedRoles = ['admin', 'manager'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowedRoles)) {
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
$includeExpectedAmounts = !empty($input['include_expected_amounts']);

// Build datetime range (inclusive start, inclusive end)
// Ensure we have full datetime format with seconds for proper SQLite comparison
if (strlen($startTime) === 5) {
    // Format is HH:MM, append :00 for seconds
    $startDatetime = $startDate . ' ' . $startTime . ':00';
} else {
    $startDatetime = $startDate . ' ' . $startTime;
}

if (strlen($endTime) === 5) {
    // Format is HH:MM, append :59 for seconds to include the entire last minute
    $endDatetime = $endDate . ' ' . $endTime . ':59';
} else {
    $endDatetime = $endDate . ' ' . $endTime;
}

// Log the datetime range for debugging
error_log("[CashUp] Date range: " . $startDatetime . " to " . $endDatetime . " | Cashier: " . $selectedCashier);

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $infoDb = new PDO('sqlite:../info.db');
    $userDb = new PDO('sqlite:../user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get business closing time (same as cash.php) for business-day alignment
$closingTime = '22:00';
try {
    $bi = $infoDb->query("SELECT closing_time FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($bi['closing_time'])) {
        $closingTime = trim($bi['closing_time']);
        if (strlen($closingTime) === 5) {
            $closingTime .= ':00';
        }
    }
} catch (PDOException $e) {
    // keep default
}

// Use business-day boundaries when times are full calendar days (00:00–23:59), for one or many days.
// Each labeled calendar day runs from closing_time that day until one minute before closing next calendar day.
// Matches cash.php / till logic for the same date selection.
$isFullCalendarDayRange = (preg_match('/^00:00(:00)?$/i', trim($startTime)))
    && (preg_match('/^23:59(:59)?$/i', trim($endTime)))
    && (strtotime($startDate) <= strtotime($endDate));

if ($isFullCalendarDayRange) {
    $ct = (strlen($closingTime) === 8 ? $closingTime : $closingTime . ':00');
    $startDatetime = $startDate . ' ' . $ct;
    $dayAfterEnd = date('Y-m-d', strtotime($endDate . ' +1 day'));
    $endPrev = date('H:i', strtotime('-1 minute', strtotime($endDate . ' ' . substr($closingTime, 0, 5))));
    $endDatetime = $dayAfterEnd . ' ' . $endPrev . ':59';
    error_log("[CashUp] Business day range: " . $startDatetime . " to " . $endDatetime);
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
    // Use SQLite's datetime comparison which works with string format 'YYYY-MM-DD HH:MM:SS'
    // This ensures proper comparison regardless of how timestamps are stored
    return " (datetime($dateField) >= datetime(:startDatetime) AND datetime($dateField) <= datetime(:endDatetime)) ";
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

    // 1. Cash Sales (Expected) - orders.cashier_id may be INTEGER (user id) or TEXT (username); filter by both
    $ordersCashierFilter = (($selectedCashier === 'all' || empty($selectedCashier)) ? '' : " AND (o.cashier_id = :cashierId OR o.cashier_id = :cashierNumericId) ");
    $ordersCashierFilterNoAlias = (($selectedCashier === 'all' || empty($selectedCashier)) ? '' : " AND (cashier_id = :cashierId OR cashier_id = :cashierNumericId) ");
    if ($eftTableExists) {
        $cashSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(
                o.total - COALESCE((SELECT SUM(amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)
            ), 0)
            FROM orders o
            WHERE (" . getRangeWhere('o.created_at') . ")" . $ordersCashierFilter . "
        ");
    } else {
        $cashSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(total), 0) 
            FROM orders 
            WHERE (" . getRangeWhere('created_at') . ")" . $ordersCashierFilterNoAlias . "
        ");
    }
    $cashSalesQuery->bindParam(':startDatetime', $startDatetime);
    $cashSalesQuery->bindParam(':endDatetime', $endDatetime);
    if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
        $cashSalesQuery->bindValue(':cashierId', $selectedCashier);
        $cashSalesQuery->bindValue(':cashierNumericId', $selectedCashierNumericId ?? -1, PDO::PARAM_INT);
    }
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

    // Cash Out (ALL withdrawals – same as cash.php so cash in till balances)
    // cash.php uses all cash-out; tips and cash back are still taken from till
    $cashOutQuery = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM cash_transactions 
        WHERE type='cash-out' 
        AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $cashOutQuery->bindParam(':startDatetime', $startDatetime);
    $cashOutQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($cashOutQuery);
    $cashOutQuery->execute();
    $totalCashOut = $cashOutQuery->fetchColumn();

    // Calculate cash in till / cash sales expected (matches cash.php formula)
    $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
    $cashSalesExpected = $cashInTill;

    // 2. Card Sales (Expected) - eft_payments.cashier_id may be INTEGER or TEXT; filter by both
    $cardSalesExpected = 0;
    if ($eftTableExists) {
        $eftCashierFilter = (($selectedCashier === 'all' || empty($selectedCashier)) ? '' : " AND (ep.cashier_id = :cashierId OR ep.cashier_id = :cashierNumericId) ");
        $cardSalesQuery = $db->prepare("
            SELECT COALESCE(SUM(ep.amount), 0)
            FROM eft_payments ep
            JOIN orders o ON ep.order_id = o.id
            WHERE (" . getRangeWhere('ep.payment_date') . ")" . $eftCashierFilter . "
        ");
        $cardSalesQuery->bindParam(':startDatetime', $startDatetime);
        $cardSalesQuery->bindParam(':endDatetime', $endDatetime);
        if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
            $cardSalesQuery->bindValue(':cashierId', $selectedCashier);
            $cardSalesQuery->bindValue(':cashierNumericId', $selectedCashierNumericId ?? -1, PDO::PARAM_INT);
        }
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
    // This matches the same logic used in totalCashOut above to ensure consistency
    // Expenses are ONLY actual business expenses, NOT tips or cash back transactions
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

    // 7. Tips (system value) — from tips table only (never cash-out / not expenses)
    $tipsSystem = 0;
    try {
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tips'")->fetchColumn()) {
            $tipsQuery = $db->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM tips
                WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
            ");
            $tipsQuery->bindParam(':startDatetime', $startDatetime);
            $tipsQuery->bindParam(':endDatetime', $endDatetime);
            bindCashierParam($tipsQuery);
            $tipsQuery->execute();
            $tipsSystem = (float) $tipsQuery->fetchColumn();
        }
    } catch (PDOException $e) {
        $tipsSystem = 0;
    }

    // 7b. Cash Back (system value — same as generate_report_pdf getCashDataForDate)
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

    // 8. Voids
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

    // 8b. Voids Count (same as root for Z-report)
    $voidsCountQuery = $db->prepare("
        SELECT COUNT(*)
        FROM void_transactions
        WHERE (" . getRangeWhere('voided_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $voidsCountQuery->bindParam(':startDatetime', $startDatetime);
    $voidsCountQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($voidsCountQuery);
    $voidsCountQuery->execute();
    $voidsCount = $voidsCountQuery->fetchColumn();

    // 9. Refunds
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

    // 9b. Refunds Count (same as root for Z-report)
    $refundsCountQuery = $db->prepare("
        SELECT COUNT(*)
        FROM refunds
        WHERE (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
    ");
    $refundsCountQuery->bindParam(':startDatetime', $startDatetime);
    $refundsCountQuery->bindParam(':endDatetime', $endDatetime);
    bindCashierParam($refundsCountQuery);
    $refundsCountQuery->execute();
    $refundsCount = $refundsCountQuery->fetchColumn();

    // 10. Total Value of Items Sold (same cashier filter as orders)
    $totalItemsSoldQuery = $db->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE (" . getRangeWhere('created_at') . ")" . $ordersCashierFilterNoAlias . "
    ");
    $totalItemsSoldQuery->bindParam(':startDatetime', $startDatetime);
    $totalItemsSoldQuery->bindParam(':endDatetime', $endDatetime);
    if ($selectedCashier !== 'all' && !empty($selectedCashier)) {
        $totalItemsSoldQuery->bindValue(':cashierId', $selectedCashier);
        $totalItemsSoldQuery->bindValue(':cashierNumericId', $selectedCashierNumericId ?? -1, PDO::PARAM_INT);
    }
    $totalItemsSoldQuery->execute();
    $totalItemsSold = $totalItemsSoldQuery->fetchColumn() ?? 0;

    // Damages (value in period)
    $damagesAmount = 0;
    try {
        $dgExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='damaged_goods'")->fetchColumn();
        if ($dgExists) {
            $damagesQuery = $db->prepare("
                SELECT COALESCE(SUM(d.quantity * p.price), 0)
                FROM damaged_goods d
                JOIN products p ON d.product_id = p.id
                WHERE datetime(d.date) >= datetime(:startDatetime) AND datetime(d.date) <= datetime(:endDatetime)
            ");
            $damagesQuery->bindParam(':startDatetime', $startDatetime);
            $damagesQuery->bindParam(':endDatetime', $endDatetime);
            $damagesQuery->execute();
            $damagesAmount = floatval($damagesQuery->fetchColumn());
        }
    } catch (PDOException $e) {
        $damagesAmount = 0;
    }

    // Return all calculated values
    $payload = [
        'success' => true,
        'date' => $endDate,
        'start_datetime' => $startDatetime,
        'end_datetime' => $endDatetime,
        'cashier_id' => $selectedCashier,
        'cashier_name' => $selectedCashierName,
        // CASH section
        'cash_sales_expected' => floatval($cashSalesExpected),
        'cash_in_till' => floatval($cashInTill),
        'total_cash_sales' => floatval($totalCashSales),
        // CARD & CREDIT section
        'card_sales_expected' => floatval($cardSalesExpected),
        'unpaid_credit_sales' => floatval($unpaidCreditSales),
        'open_tabs_balance' => floatval($openTabsBalance),
        'unpaid_tabs' => floatval($unpaidTabs),
        'credit_returns' => floatval($creditReturns),
        // DEDUCTIONS section
        'expenses' => floatval($expenses),
        'tips_system' => floatval($tipsSystem),
        'cash_back_system' => floatval($cashBackSystem),
        'total_cash_in' => floatval($totalCashIn),
        'total_cash_out' => floatval($totalCashOut),
        'total_credit_payments' => floatval($totalCreditPayments),
        'total_cash_received' => floatval($totalCashIn + $totalCashSales + $totalCreditPayments),
        'damages' => floatval($damagesAmount),
        // ADJUSTMENTS section
        'voids' => floatval($voids),
        'voids_count' => intval($voidsCount),
        'refunds' => floatval($refunds),
        'refunds_count' => intval($refundsCount),
        // TOTAL section
        'total_items_sold' => floatval($totalItemsSold)
    ];
    if (!$includeExpectedAmounts) {
        unset($payload['cash_sales_expected'], $payload['cash_in_till'], $payload['card_sales_expected']);
        $payload['expected_amounts_hidden'] = true;
    }
    echo json_encode($payload);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
