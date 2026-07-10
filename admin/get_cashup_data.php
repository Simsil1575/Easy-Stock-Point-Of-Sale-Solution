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

// Use business-day boundaries when request is single calendar day with full day (00:00–23:59)
// so cash in till matches cash.php for the same date
$nextBusinessDay = date('Y-m-d', strtotime($startDate . ' +1 day'));
$isAfterMidnight = (int)substr($closingTime, 0, 2) < 12;
$isSingleFullDay = ($startDate === $endDate)
    && (preg_match('/^00:00(:00)?$/i', trim($startTime)))
    && (preg_match('/^23:59(:59)?$/i', trim($endTime)));

if ($isSingleFullDay) {
    $startDatetime = $startDate . ' ' . (strlen($closingTime) === 8 ? $closingTime : $closingTime . ':00');
    $endPrev = date('H:i', strtotime('-1 minute', strtotime($startDate . ' ' . substr($closingTime, 0, 5))));
    $endDatetime = $nextBusinessDay . ' ' . $endPrev . ':59';
    error_log("[CashUp] Using business day: " . $startDatetime . " to " . $endDatetime);
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

    // 8. Tips (system value) — from tips table only (never cash-out / not expenses)
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

    // 9b. Voids Count (same as root for Z-report)
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

    // 10b. Refunds Count (same as root for Z-report)
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

    // 11. Total Value of Items Sold (same cashier filter as orders)
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

    // Cash back by type (for step 2 summary) - case insensitive search
    // Customer = any cash back that's NOT Beerhouse/Beerhaus or Hubbly
    $cashBackBeerhouse = 0;
    $cashBackHubbly = 0;
    $cashBackCustomer = 0;
    try {
        $cbBeerhouse = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
            WHERE type = 'cash-out' 
            AND (LOWER(description) LIKE '%cash back%') 
            AND (LOWER(description) LIKE '%beerhouse%' OR LOWER(description) LIKE '%beerhaus%')
            AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
        ");
        $cbBeerhouse->bindParam(':startDatetime', $startDatetime);
        $cbBeerhouse->bindParam(':endDatetime', $endDatetime);
        bindCashierParam($cbBeerhouse);
        $cbBeerhouse->execute();
        $cashBackBeerhouse = floatval($cbBeerhouse->fetchColumn());

        $cbHubbly = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
            WHERE type = 'cash-out' 
            AND (LOWER(description) LIKE '%cash back%') 
            AND (LOWER(description) LIKE '%hubbly%')
            AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
        ");
        $cbHubbly->bindParam(':startDatetime', $startDatetime);
        $cbHubbly->bindParam(':endDatetime', $endDatetime);
        bindCashierParam($cbHubbly);
        $cbHubbly->execute();
        $cashBackHubbly = floatval($cbHubbly->fetchColumn());

        // Customer = cash back that's NOT Beerhouse/Beerhaus or Hubbly (includes plain "Cash Back")
        $cbCustomer = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM cash_transactions
            WHERE type = 'cash-out' 
            AND (LOWER(description) LIKE '%cash back%') 
            AND (LOWER(description) NOT LIKE '%beerhouse%' AND LOWER(description) NOT LIKE '%beerhaus%' AND LOWER(description) NOT LIKE '%hubbly%')
            AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
        ");
        $cbCustomer->bindParam(':startDatetime', $startDatetime);
        $cbCustomer->bindParam(':endDatetime', $endDatetime);
        bindCashierParam($cbCustomer);
        $cbCustomer->execute();
        $cashBackCustomer = floatval($cbCustomer->fetchColumn());
    } catch (PDOException $e) {
        // If breakdown fails, leave zeros; total cash_back_system still used
        error_log("[CashUp] Cash back breakdown error: " . $e->getMessage());
    }

    // Hansa Draught: total value, units, and split into cash vs EFT (for cash/EFT on hand subtraction)
    // order_items.price and credit_sale_items.price store LINE TOTAL (already qty * unit price)
    $hansaTotal = 0;
    $hansaUnits = 0;
    $hansaCash = 0;
    $hansaEft = 0;
    $hansaProductMatch = " (LOWER(TRIM(product_name)) LIKE '%hansa draught%' OR LOWER(TRIM(product_name)) = 'hansa draught') ";
    try {
        $orderItemsTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='order_items'")->fetchColumn();
        if ($orderItemsTable) {
            // Total and units (same as before)
            $hansaOrders = $db->prepare("
                SELECT COALESCE(SUM(oi.price), 0) as total_value, COALESCE(SUM(oi.quantity), 0) as total_qty
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE (datetime(o.created_at) >= datetime(:startDatetime) AND datetime(o.created_at) <= datetime(:endDatetime))
                AND " . str_replace('product_name', 'oi.product_name', $hansaProductMatch) . "
                " . getCashierFilter('o.cashier_id') . "
            ");
            $hansaOrders->bindParam(':startDatetime', $startDatetime);
            $hansaOrders->bindParam(':endDatetime', $endDatetime);
            bindCashierParam($hansaOrders);
            $hansaOrders->execute();
            $row = $hansaOrders->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $hansaTotal += floatval($row['total_value']);
                $hansaUnits += intval($row['total_qty']);
            }
            // Per-order split: Hansa cash vs EFT using order EFT ratio (proportional allocation)
            if ($eftTableExists) {
                $hansaSplitStmt = $db->prepare("
                    SELECT o.id, o.total AS order_total,
                        COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0) AS order_eft,
                        (SELECT COALESCE(SUM(oi.price), 0) FROM order_items oi WHERE oi.order_id = o.id AND " . str_replace('product_name', 'oi.product_name', $hansaProductMatch) . ") AS hansa_line
                    FROM orders o
                    JOIN order_items oi ON oi.order_id = o.id
                    WHERE (datetime(o.created_at) >= datetime(:startDatetime) AND datetime(o.created_at) <= datetime(:endDatetime))
                    AND " . str_replace('product_name', 'oi.product_name', $hansaProductMatch) . "
                    " . getCashierFilter('o.cashier_id') . "
                    GROUP BY o.id
                ");
                $hansaSplitStmt->bindParam(':startDatetime', $startDatetime);
                $hansaSplitStmt->bindParam(':endDatetime', $endDatetime);
                bindCashierParam($hansaSplitStmt);
                $hansaSplitStmt->execute();
                while ($orderRow = $hansaSplitStmt->fetch(PDO::FETCH_ASSOC)) {
                    $orderTotal = floatval($orderRow['order_total']);
                    $orderEft = floatval($orderRow['order_eft']);
                    $hansaLine = floatval($orderRow['hansa_line']);
                    if ($hansaLine <= 0) continue;
                    $eftRatio = ($orderTotal > 0) ? ($orderEft / $orderTotal) : 0;
                    $hansaCash += $hansaLine * (1 - $eftRatio);
                    $hansaEft += $hansaLine * $eftRatio;
                }
            } else {
                $hansaCash = $hansaTotal; // no EFT table: all Hansa from orders = cash
                $hansaEft = 0;
            }
        }
        // From credit_sale_items: count as cash (credit payments received are cash in till)
        $creditSaleItemsTable = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='credit_sale_items'")->fetchColumn();
        if ($creditSaleItemsTable) {
            $hansaCredit = $db->prepare("
                SELECT COALESCE(SUM(csi.price), 0) as total_value, COALESCE(SUM(csi.quantity), 0) as total_qty
                FROM credit_sale_items csi
                JOIN credit_sales cs ON csi.sale_id = cs.id
                WHERE (datetime(cs.created_at) >= datetime(:startDatetime) AND datetime(cs.created_at) <= datetime(:endDatetime))
                AND " . str_replace('product_name', 'csi.product_name', $hansaProductMatch) . "
                " . getCashierFilter('cs.cashier_id') . "
            ");
            $hansaCredit->bindParam(':startDatetime', $startDatetime);
            $hansaCredit->bindParam(':endDatetime', $endDatetime);
            bindCashierParam($hansaCredit);
            $hansaCredit->execute();
            $row = $hansaCredit->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $creditHansa = floatval($row['total_value']);
                $hansaTotal += $creditHansa;
                $hansaUnits += intval($row['total_qty']);
                $hansaCash += $creditHansa; // credit sales Hansa = cash when paid
                // hansaEft unchanged for credit
            }
        }
    } catch (PDOException $e) {
        $hansaTotal = 0;
        $hansaUnits = 0;
        $hansaCash = 0;
        $hansaEft = 0;
    }

    // Subtract Hansa EFT from card sales expected (even on receipts)
    $cardSalesExpected = $cardSalesExpected - $hansaEft;

    // Cash sales (expected) stays equal to cash in till (set above with $cashInTill)

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
        'cash_back_system' => floatval($cashBackSystem),
        'tips_system' => floatval($tipsSystem),
        // Cash back by type (step 2 summary)
        'cash_back_beerhouse' => floatval($cashBackBeerhouse),
        'cash_back_hubbly' => floatval($cashBackHubbly),
        'cash_back_customer' => floatval($cashBackCustomer),
        // Step 2 summary (Hansa: total, units, and split cash/EFT for till reconciliation)
        'hansa_total' => floatval($hansaTotal),
        'hansa_cash' => floatval($hansaCash),
        'hansa_eft' => floatval($hansaEft),
        'hansa_units' => intval($hansaUnits),
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
