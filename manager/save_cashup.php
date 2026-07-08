<?php
/**
 * Save Cash Up Data API
 * Saves the cash-up record to the database
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone
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

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit();
}

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Extract data from input (use end_date as cashup_date when date range is provided)
$cashupDate = $input['date'] ?? $input['end_date'] ?? date('Y-m-d');
$cashierId = $input['cashier_id'] ?? 'all';
$cashierName = $input['cashier_name'] ?? 'All Staff';
$isIndividualCashout = ($cashierId !== 'all' && !empty($cashierId)) ? 1 : 0;

// Cash section
$cashSalesExpected = floatval($input['cash_sales_expected'] ?? 0);
$cashOnHand = floatval($input['cash_on_hand'] ?? 0);
$overShort = floatval($input['over_short'] ?? 0);

// Card & Credit section
$cardSalesExpected = floatval($input['card_sales_expected'] ?? 0);
$eftOnHand = floatval($input['eft_on_hand'] ?? 0);
$eftOverShort = floatval($input['eft_over_short'] ?? 0);
$unpaidCreditSales = floatval($input['unpaid_credit_sales'] ?? 0);
$openTabsBalance = floatval($input['open_tabs_balance'] ?? 0);
$unpaidTabs = floatval($input['unpaid_tabs'] ?? 0);
$creditReturns = floatval($input['credit_returns'] ?? 0);

// Deductions section
$expenses = floatval($input['expenses'] ?? 0);
$cashBack = floatval($input['cash_back'] ?? 0);
$tips = floatval($input['tips'] ?? 0);

// Sales Sources section
$hubbly = floatval($input['hubbly'] ?? 0);
$beerhouse = floatval($input['beerhouse'] ?? 0);

// Adjustments section
$voids = floatval($input['voids'] ?? 0);
$refunds = floatval($input['refunds'] ?? 0);

// Total section
$totalItemsSold = floatval($input['total_items_sold'] ?? 0);

// Metadata
$createdBy = $_SESSION['username'] ?? 'Admin';
$notes = $input['notes'] ?? null;

try {
    // Use INSERT OR REPLACE to handle duplicates (same date + cashier)
    $sql = "INSERT OR REPLACE INTO cashup_records (
        cashup_date,
        cashier_id,
        cashier_name,
        is_individual_cashout,
        cash_sales_expected,
        cash_on_hand,
        over_short,
        card_sales_expected,
        eft_on_hand,
        eft_over_short,
        unpaid_credit_sales,
        open_tabs_balance,
        unpaid_tabs,
        credit_returns,
        expenses,
        cash_back,
        tips,
        hubbly,
        beerhouse,
        voids,
        refunds,
        total_items_sold,
        created_by,
        created_at,
        notes
    ) VALUES (
        :cashup_date,
        :cashier_id,
        :cashier_name,
        :is_individual_cashout,
        :cash_sales_expected,
        :cash_on_hand,
        :over_short,
        :card_sales_expected,
        :eft_on_hand,
        :eft_over_short,
        :unpaid_credit_sales,
        :open_tabs_balance,
        :unpaid_tabs,
        :credit_returns,
        :expenses,
        :cash_back,
        :tips,
        :hubbly,
        :beerhouse,
        :voids,
        :refunds,
        :total_items_sold,
        :created_by,
        :created_at,
        :notes
    )";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':cashup_date', $cashupDate);
    $stmt->bindParam(':cashier_id', $cashierId);
    $stmt->bindParam(':cashier_name', $cashierName);
    $stmt->bindParam(':is_individual_cashout', $isIndividualCashout, PDO::PARAM_INT);
    $stmt->bindParam(':cash_sales_expected', $cashSalesExpected);
    $stmt->bindParam(':cash_on_hand', $cashOnHand);
    $stmt->bindParam(':over_short', $overShort);
    $stmt->bindParam(':card_sales_expected', $cardSalesExpected);
    $stmt->bindParam(':eft_on_hand', $eftOnHand);
    $stmt->bindParam(':eft_over_short', $eftOverShort);
    $stmt->bindParam(':unpaid_credit_sales', $unpaidCreditSales);
    $stmt->bindParam(':open_tabs_balance', $openTabsBalance);
    $stmt->bindParam(':unpaid_tabs', $unpaidTabs);
    $stmt->bindParam(':credit_returns', $creditReturns);
    $stmt->bindParam(':expenses', $expenses);
    $stmt->bindParam(':cash_back', $cashBack);
    $stmt->bindParam(':tips', $tips);
    $stmt->bindParam(':hubbly', $hubbly);
    $stmt->bindParam(':beerhouse', $beerhouse);
    $stmt->bindParam(':voids', $voids);
    $stmt->bindParam(':refunds', $refunds);
    $stmt->bindParam(':total_items_sold', $totalItemsSold);
    $stmt->bindParam(':created_by', $createdBy);
    $createdAt = date('Y-m-d H:i:s');
    $stmt->bindParam(':created_at', $createdAt);
    $stmt->bindParam(':notes', $notes);
    
    $stmt->execute();
    
    $recordId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Cash-up record saved successfully',
        'record_id' => $recordId,
        'date' => $cashupDate,
        'cashier' => $cashierName
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to save: ' . $e->getMessage()]);
}
