<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ./");
    exit();
}

// Check activation status with expiration
require_once 'activation_helper.php';
$activationCheck = checkActivationStatus();
if ($activationCheck['status'] === 'not_activated' || $activationCheck['status'] === 'expired') {
    header('Location: settings');
    exit();
}
?>

<?php
// Set the default timezone to the correct timezone for your location
date_default_timezone_set('Africa/Harare');

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '22:00'; // Default to 10:00 PM if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '22:00';
}

// New SQLite connection
$db = new PDO('sqlite:pos.db');

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);

// If closing time is after midnight (e.g., 2:00 AM), we need to consider transactions
// that happened after midnight but before closing time as part of the previous day
$isAfterMidnight = $closingHour < 12;

// Fetch distinct dates where cash transactions occurred, considering business closing time
$distinctDatesQuery = $db->prepare("
    SELECT DISTINCT business_date
    FROM (
        SELECT
            CASE 
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date
        FROM cash_transactions
        UNION ALL
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
                WHEN strftime('%H:%M', payment_date) BETWEEN '00:00' AND '$closingTime' AND " . ($isAfterMidnight ? "1=1" : "1=0") . "
                THEN date(datetime(payment_date, '-1 day'))
                ELSE date(payment_date)
            END AS business_date
        FROM payments
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

// Determine current business date
$currentTime = date('H:i');
$currentDate = date('Y-m-d');
$currentBusinessDate = $selectedDate;

// If current time is between 00:00 and closing time, and closing time is after midnight,
// then current business date is yesterday
if ($isAfterMidnight && $currentTime >= '00:00' && $currentTime < $closingTime) {
    $currentBusinessDate = date('Y-m-d', strtotime('-1 day'));
}

// Create cash_transactions table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS cash_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// Create credit_returns table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS credit_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    credit_sale_id INTEGER,
    return_amount DECIMAL(10,2) NOT NULL,
    reason TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cashier_id TEXT,
    FOREIGN KEY(credit_sale_id) REFERENCES credit_sales(id)
)");

// Create cash_up_summary table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS cash_up_summary (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    credit_returns_count INTEGER DEFAULT 0,
    credit_returns_amount DECIMAL(10,2) DEFAULT 0,
    eft_income_count INTEGER DEFAULT 0,
    eft_income_amount DECIMAL(10,2) DEFAULT 0,
    damages_count INTEGER DEFAULT 0,
    damages_amount DECIMAL(10,2) DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// Handle POST requests for cash in/out and delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Check if this is a cash-out and validate against available balance
        if ($_POST['action'] === 'cash-out') {
            // Determine the current business date based on closing time
            $currentTime = date('H:i');
            $currentDate = date('Y-m-d');
            $currentBusinessDate = $currentDate;
            
            // If current time is between 00:00 and closing time, and closing time is after midnight,
            // then current business date is yesterday
            if ($isAfterMidnight && $currentTime >= '00:00' && $currentTime < $closingTime) {
                $currentBusinessDate = date('Y-m-d', strtotime('-1 day'));
            }
            
            // Calculate current cash in till using current business date logic
            $nextBusinessDay = date('Y-m-d', strtotime($currentBusinessDate . ' +1 day'));
            
            // Get current business date's cash in transactions
            $cashInQuery = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) 
                FROM cash_transactions 
                WHERE type='cash-in' AND (
                    (DATE(created_at) = :currentBusinessDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                    (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $cashInQuery->bindParam(':currentBusinessDate', $currentBusinessDate);
            $cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cashInQuery->bindParam(':closingTime', $closingTime);
            $cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $cashInQuery->execute();
            $totalCashIn = $cashInQuery->fetchColumn();
            
            // Get current business date's cash sales (excluding EFT payments if applicable)
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
                    WHERE (
                        (DATE(o.created_at) = :currentBusinessDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
                        (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $cashSalesQuery->bindParam(':currentBusinessDate', $currentBusinessDate);
                $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $cashSalesQuery->bindParam(':closingTime', $closingTime);
                $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $cashSalesQuery->execute();
            } else {
                $cashSalesQuery = $db->prepare("
                    SELECT COALESCE(SUM(total), 0) 
                    FROM orders 
                    WHERE (
                        (DATE(created_at) = :currentBusinessDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $cashSalesQuery->bindParam(':currentBusinessDate', $currentBusinessDate);
                $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $cashSalesQuery->bindParam(':closingTime', $closingTime);
                $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $cashSalesQuery->execute();
            }
            $totalCashSales = $cashSalesQuery->fetchColumn();
            
            // Get current business date's credit payments using payments table (like reports.php)
            $creditPaymentsQuery = $db->prepare("
                SELECT COALESCE(SUM(p.amount), 0) 
                FROM payments p
                JOIN credit_sales cs ON p.sale_id = cs.id
                WHERE cs.payment_status = 'paid' AND (
                    (DATE(p.payment_date) = :currentBusinessDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
                    (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $creditPaymentsQuery->bindParam(':currentBusinessDate', $currentBusinessDate);
            $creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
            $creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $creditPaymentsQuery->execute();
            $totalCreditPayments = $creditPaymentsQuery->fetchColumn();
            
            // Get current business date's cash out
            $cashOutQuery = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) 
                FROM cash_transactions 
                WHERE type='cash-out' AND (
                    (DATE(created_at) = :currentBusinessDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                    (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                )
            ");
            $cashOutQuery->bindParam(':currentBusinessDate', $currentBusinessDate);
            $cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
            $cashOutQuery->bindParam(':closingTime', $closingTime);
            $cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
            $cashOutQuery->execute();
            $totalCashOut = $cashOutQuery->fetchColumn();
            
            // Calculate cash in till for current business date's business day
            $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
            
            // If withdrawal amount exceeds balance, return error
            if (floatval($_POST['amount']) > floatval($cashInTill)) {
                if(isset($_POST['ajax'])) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot withdraw more than the available balance (N$' . number_format($cashInTill, 2) . ')']);
                    exit;
                }
                // Redirect back with error message if not ajax
                header('Location: cash.php?error=insufficient_funds&available=' . $cashInTill);
                exit;
            }
        }
        
        $stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, created_at) VALUES (?, ?, ?, datetime('now', '+2 hours'))");
        $stmt->execute([$_POST['action'], $_POST['amount'], $_POST['description']]);
        
        if(isset($_POST['ajax'])) {
            $id = $db->lastInsertId();
            $newTransaction = $db->query("SELECT * FROM cash_transactions WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            echo json_encode($newTransaction);
            exit;
        }
        header('Location: cash.php');
        exit;
    } elseif (isset($_POST['delete_transaction_id'])) {
        $stmt = $db->prepare("DELETE FROM cash_transactions WHERE id = ?");
        $stmt->execute([$_POST['delete_transaction_id']]);
        
        if(isset($_POST['ajax'])) {
            echo json_encode(['status' => 'success']);
            exit;
        }
        header('Location: cash.php');
        exit;
    } elseif (isset($_POST['record_credit_return'])) {
        // Handle credit return recording - simplified version without specific credit sale
        $stmt = $db->prepare("INSERT INTO credit_returns (credit_sale_id, return_amount, reason, cashier_id, created_at) VALUES (NULL, ?, ?, ?, datetime('now', '+2 hours'))");
        $stmt->execute([$_POST['return_amount'], $_POST['reason'], $_SESSION['username'] ?? 'Unknown']);
        
        if(isset($_POST['ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Credit return recorded successfully']);
            exit;
        }
        header('Location: cash.php');
        exit;
    } elseif (isset($_POST['record_eft_income'])) {
        // Handle EFT income recording
        $stmt = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['order_id'] ?? null, $_POST['transaction_ref'], $_POST['wallet_provider'], $_POST['amount'], $_POST['cashier_id'] ?? 1]);
        
        if(isset($_POST['ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'EFT income recorded successfully']);
            exit;
        }
        header('Location: cash.php');
        exit;
    } elseif (isset($_POST['record_damage'])) {
        // Handle damage recording
        $stmt = $db->prepare("INSERT INTO damaged_goods (product_id, quantity, reason) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['product_id'], $_POST['quantity'], $_POST['reason']]);
        
        if(isset($_POST['ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Damage recorded successfully']);
            exit;
        }
        header('Location: cash.php');
        exit;
    } elseif (isset($_POST['record_creditor'])) {
        // Handle creditor recording
        $stmt = $db->prepare("INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, cashier_id) VALUES (?, ?, ?, datetime('now', '+2 hours'), ?)");
        $stmt->execute([$_POST['creditor_id'], $_POST['total_amount'], $_POST['due_date'], $_POST['cashier_id'] ?? 1]);
        
        if(isset($_POST['ajax'])) {
            echo json_encode(['status' => 'success', 'message' => 'Creditor sale recorded successfully']);
            exit;
        }
        header('Location: cash.php');
        exit;
    }
}

// Get total cash balance
$balance = $db->query("SELECT 
    (SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-in') -
    (SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type='cash-out') as balance
")->fetchColumn();

// Fetch selected date's transactions (both cash-in and cash-out)
$nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$selectedDateTransactionsQuery = $db->prepare("
    SELECT * FROM cash_transactions 
    WHERE (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
    )
    ORDER BY created_at DESC
");
$selectedDateTransactionsQuery->bindParam(':selectedDate', $selectedDate);
$selectedDateTransactionsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$selectedDateTransactionsQuery->bindParam(':closingTime', $closingTime);
$selectedDateTransactionsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$selectedDateTransactionsQuery->execute();
$transactions = $selectedDateTransactionsQuery->fetchAll(PDO::FETCH_ASSOC);

// Calculate selected date's total withdrawals
$selectedDateTotalWithdrawalsQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type = 'cash-out' AND (
        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
    )
");
$selectedDateTotalWithdrawalsQuery->bindParam(':selectedDate', $selectedDate);
$selectedDateTotalWithdrawalsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
$selectedDateTotalWithdrawalsQuery->bindParam(':closingTime', $closingTime);
$selectedDateTotalWithdrawalsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
$selectedDateTotalWithdrawalsQuery->execute();
$selectedDateTotalWithdrawals = $selectedDateTotalWithdrawalsQuery->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Management</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <script src="src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <script src="lucide.js"></script>

    <style>
        /* Prevent horizontal overflow on all devices */
        * {
            box-sizing: border-box;
        }
        
        html, body {
            overflow-x: hidden;
            max-width: 100vw;
        }
        
        .content {
            margin-left: 250px;
        }
        .fade-in {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Add animation for new elements appearing */
        .fade-in {
            animation: fadeInUp 0.3s ease-out forwards;
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Searchable Dropdown Styles */
        .searchable-dropdown {
            position: relative;
        }
        
        .searchable-dropdown input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            outline: none;
        }
        
        .searchable-dropdown input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .dropdown-options {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .dropdown-option {
            padding: 0.5rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .dropdown-option:hover {
            background-color: #f3f4f6;
        }
        
        .dropdown-option.selected {
            background-color: #3b82f6;
            color: white;
        }
        
        .dropdown-option:last-child {
            border-bottom: none;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
            }
            
            .container {
                padding: 1rem;
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            /* Prevent body overflow */
            body {
                overflow-x: hidden !important;
                max-width: 100vw !important;
            }
            
            /* Ensure flex containers don't overflow */
            .flex {
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            /* Fix header row overflow */
            .sticky.top-0 {
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            /* Ensure grid containers fit */
            .grid {
                max-width: 100%;
            }
            
            /* Card padding adjustments */
            .bg-white.shadow-lg.rounded-lg,
            .bg-white.rounded-xl.shadow-lg {
                padding: 1rem !important;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            /* Form padding adjustments */
            form.p-6 {
                padding: 1rem !important;
                max-width: 100%;
            }
            
            /* Grid gap adjustments */
            .grid.gap-6 {
                gap: 1rem !important;
                max-width: 100%;
            }
            
            /* Fix header section with buttons */
            .flex.items-center.justify-between {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            /* Ensure date input is on the right on mobile */
            @media (max-width: 1023px) {
                .sticky.top-0 > div:last-child {
                    justify-content: flex-end !important;
                    margin-left: auto;
                }
            }
            
            /* Ensure buttons and inputs don't overflow */
            button, input, select {
                max-width: 100%;
            }
            
            /* Fix date form and buttons row */
            .flex.items-center.gap-2,
            .flex.items-center.gap-4 {
                flex-wrap: wrap;
                max-width: 100%;
            }
            
            /* Fix container negative margins on mobile */
            .container.mx-auto {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            
            /* Fix sticky header negative margins */
            .sticky.top-0 {
                margin-left: -1rem !important;
                margin-right: -1rem !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            
            /* Ensure all cards and containers fit */
            .bg-white,
            .bg-gray-50,
            .rounded-lg,
            .rounded-xl {
                max-width: 100%;
                overflow-x: hidden;
            }
            
            /* Fix search input in table header */
            input[type="text"],
            input[type="number"],
            select,
            textarea {
                max-width: 100%;
                box-sizing: border-box;
            }
            
            /* Fix table header section */
            .p-4.py-2.bg-gray-200 {
                padding: 0.75rem 1rem !important;
                flex-wrap: wrap;
                gap: 0.75rem;
            }
            
            /* Ensure table container doesn't overflow */
            .overflow-x-auto {
                max-width: 100%;
            }
            
            /* Fix pagination controls */
            .px-6.py-2,
            .px-3.py-3 {
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
                padding-top: 0.75rem !important;
                padding-bottom: 0.75rem !important;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            /* Mobile pagination specific styles */
            @media (max-width: 1023px) {
                /* Pagination container */
                .px-3.py-3 {
                    padding: 0.75rem !important;
                }
                
                /* Pagination buttons - make touch-friendly */
                #firstPage,
                #prevPage,
                #nextPage,
                #lastPage,
                #goToPage {
                    min-height: 36px;
                    min-width: 36px;
                    touch-action: manipulation;
                    -webkit-tap-highlight-color: transparent;
                }
                
                /* Page input - smaller on mobile */
                #pageInput {
                    min-height: 36px;
                    font-size: 0.875rem;
                }
                
                /* Page number text */
                #pageNumber {
                    font-size: 0.75rem;
                }
                
                /* Ensure pagination doesn't overflow */
                div.flex.flex-col {
                    width: 100%;
                    max-width: 100%;
                }
                
                /* Stack pagination elements vertically on very small screens */
                @media (max-width: 480px) {
                    div.flex.flex-col > div {
                        width: 100%;
                        justify-content: center;
                    }
                    
                    /* Make buttons full width on very small screens */
                    #firstPage,
                    #prevPage,
                    #nextPage,
                    #lastPage {
                        flex: 1;
                        min-width: 0;
                    }
                }
            }
            
            /* Ensure modals don't cause overflow */
            .fixed.inset-0 {
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            /* Text size adjustments */
            .text-2xl {
                font-size: 1.5rem !important;
            }
            
            .text-lg {
                font-size: 1rem !important;
            }
            
            /* Button adjustments */
            button.px-6 {
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
            }
            
            /* Modal adjustments */
            .fixed.inset-0.z-50 {
                padding: 1rem;
            }
            
            /* Table header adjustments */
            table thead th {
                padding: 0.5rem !important;
                font-size: 0.75rem !important;
            }
            
            table tbody td {
                padding: 0.5rem !important;
                font-size: 0.875rem !important;
            }
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000;
        }
        
        /* Ensure sidebar is above overlay on mobile */
        @media (max-width: 1023px) {
            #sidebar {
                z-index: 10000 !important;
            }
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
        
        @media (min-width: 1024px) {
            .hamburger {
                display: none;
            }
            .mobile-overlay {
                display: none;
            }
        }
        
        /* Mobile table responsive */
        @media (max-width: 1023px) {
            .overflow-x-auto {
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
            
            /* Pagination controls mobile */
            .px-6.py-2 {
                padding: 0.75rem 1rem !important;
            }
            
            /* Ensure modals appear above everything on mobile */
            .fixed.inset-0.z-\[10000\] {
                z-index: 10000 !important;
            }
            
            /* Alert container mobile adjustments */
            #alertContainer {
                width: calc(100% - 2rem) !important;
                max-width: 20rem !important;
                right: 1rem !important;
                top: 1rem !important;
            }
            
            /* Form spacing adjustments */
            .space-y-4 > * + * {
                margin-top: 0.75rem !important;
            }
            
            /* Button text adjustments */
            button span.hidden {
                display: none !important;
            }
        }
        
        /* Mobile Table Responsive - Vertical Card Layout */
        @media (max-width: 768px) {
            /* Remove overflow-x-auto on mobile and prevent container overflow */
            .overflow-x-auto {
                overflow-x: visible !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            /* Ensure table containers don't overflow */
            .bg-white.rounded-lg {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow: hidden;
            }
            
            /* Ensure tables fit within container */
            table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed;
                box-sizing: border-box;
            }
            
            /* Hide table headers on mobile */
            table thead {
                display: none;
            }
            
            /* Convert table rows to compact cards */
            table tbody tr {
                display: block;
                width: 100%;
                max-width: 100%;
                margin-bottom: 0.5rem;
                background: white;
                border: 2px solid #d1d5db;
                border-radius: 0.375rem;
                padding: 0.5rem;
                box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
                height: auto !important;
                position: relative;
                box-sizing: border-box;
            }
            
            /* Convert table cells to compact inline blocks */
            table tbody td {
                display: flex;
                align-items: center;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0.375rem 0.25rem !important;
                text-align: left !important;
                border: none !important;
                border-bottom: 1px solid #f3f4f6 !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: unset !important;
                height: auto !important;
                line-height: 1.3 !important;
                gap: 0.5rem;
                font-size: 0.8rem !important;
                color: #111827;
                box-sizing: border-box;
                word-wrap: break-word;
            }
            
            /* Remove border from last cell in each row */
            table tbody td:last-child {
                border-bottom: none !important;
            }
            
            /* Add labels inline with data using CSS */
            table tbody td::before {
                content: attr(data-label) ":";
                display: inline-block;
                font-weight: 600;
                font-size: 0.7rem;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.025em;
                min-width: 4rem;
                flex-shrink: 0;
            }
            
            /* Hide label if data-label is empty (for total rows) */
            table tbody td[data-label=""]::before {
                display: none;
            }
            
            /* Ensure content inside cells wraps properly and takes remaining space */
            table tbody td > div {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 0.25rem;
                flex: 1;
                min-width: 0;
                justify-content: flex-start !important;
            }
            
            table tbody td > span:not(::before),
            table tbody td > button {
                flex: 1;
                min-width: 0;
            }
            
            /* Ensure badges are aligned to the left */
            table tbody td > div span.inline-flex {
                flex: 0 0 auto;
                margin-left: 0;
            }
            
            /* Actions column - center align */
            table tbody td[data-label="Actions"] {
                justify-content: center;
                padding: 0.5rem !important;
            }
            
            table tbody td[data-label="Actions"]::before {
                display: none; /* Hide label for Actions column */
            }
            
            /* Actions column buttons - wrap and stack on mobile */
            table tbody td[data-label="Actions"] > div {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                justify-content: center;
                align-items: center;
                width: 100%;
            }
            
            table tbody td[data-label="Actions"] button {
                flex: 0 0 auto;
                min-width: auto;
                white-space: nowrap;
                font-size: 0.75rem !important;
                padding: 0.375rem 0.75rem !important;
            }
            
            /* Checkmark icon in Actions column */
            table tbody td[data-label="Actions"] > div.flex {
                display: flex;
                justify-content: center;
                align-items: center;
                width: 100%;
            }
            
            table tbody td[data-label="Actions"] > div.flex > div.w-6 {
                margin: 0;
            }
            
            /* Right align numeric columns */
            table tbody td[data-label="Qty"],
            table tbody td[data-label="Unit Price"],
            table tbody td[data-label="Total"],
            table tbody td[data-label="Amount"] {
                justify-content: space-between;
            }
            
            /* Remove hover effect on mobile cards */
            table tbody tr:hover {
                background: white;
            }
            
            /* Ensure images don't overflow */
            table tbody td img {
                max-width: 100%;
                height: auto;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">
    <div class="flex">
        <!-- Confirmation Modal -->
        <div id="confirmationModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[10000] hidden">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full transform transition-all scale-95 opacity-0" id="modalContent">
                <div class="p-6">
                    <div class="flex items-center mb-4">
                        <svg class="h-8 w-8 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        <h3 class="text-xl font-bold text-gray-900">Confirm Deletion</h3>
                    </div>
                    <p class="text-gray-700 mb-6">Are you sure you want to delete this transaction? This action cannot be undone.</p>
                    <div class="flex justify-end space-x-3">
                        <button id="cancelDelete" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-gray-400">
                            Cancel
                        </button>
                        <button id="confirmDelete" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-red-500">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0" style="max-width: 100vw; overflow-x: hidden;">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-4 lg:p-6" style="max-width: 100%; overflow-x: hidden;">
                <!-- Alert Notification Container -->
                <div id="alertContainer" class="fixed top-4 right-4 z-[10000] w-80 transform transition-transform duration-300 translate-x-full" style="max-width: calc(100vw - 2rem);"></div>
                
                <?php if (isset($_GET['error']) && $_GET['error'] === 'insufficient_funds'): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-md z-20" role="alert">
                    <div class="flex items-center">
                        <svg class="h-6 w-6 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <p class="font-medium">
                            Error: Cannot withdraw more than the available balance in the till.
                            <?php if (isset($_GET['available'])): ?>
                            <span class="block mt-1 text-sm">Available balance: N$<?= number_format($_GET['available'], 2) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Header Row: Title and Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm" style="max-width: 100vw; overflow-x: hidden;">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-lg sm:text-xl lg:text-2xl xl:text-3xl font-bold truncate max-w-[55vw] sm:max-w-[65vw] md:max-w-full">
                            Cash Management
                        </h1>
                    </div>
                    
                    <!-- Right side: Date Selection and Buttons -->
                    <div class="flex items-center gap-2 lg:gap-4 flex-wrap lg:flex-nowrap flex-1 min-w-0 justify-end lg:justify-start">
                        <form method="POST" action="" class="flex items-center gap-2 flex-shrink-0" id="dateForm">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <!-- calendar icon -->
                                    <i data-lucide="calendar" class="w-4 h-4 lg:w-5 lg:h-5 text-gray-500"></i>
                                </div>
                                <select id="date" name="date" onchange="updateCashReport();" class="bg-gray-50 border border-gray-300 text-gray-900 text-xs lg:text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block pl-8 lg:pl-10 pr-8 lg:pr-10 p-2 lg:p-2.5 shadow-sm transition-colors cursor-pointer max-w-full">
                                    <?php foreach ($distinctDates as $date): ?>
                                        <option value="<?= htmlspecialchars($date) ?>" <?= $date == $selectedDate ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($date) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                       
                    </div>
                </div>
                
                <!-- Cash Balance Info -->
                <?php
                // Calculate cash in till using selected date's business day logic
                $nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
                
                // 1. Selected date's cash in transactions
                $cashInQuery = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM cash_transactions 
                    WHERE type='cash-in' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $cashInQuery->bindParam(':selectedDate', $selectedDate);
                $cashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $cashInQuery->bindParam(':closingTime', $closingTime);
                $cashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $cashInQuery->execute();
                $totalCashIn = $cashInQuery->fetchColumn();
                
                // 2. Selected date's cash sales (excluding EFT payments)
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
                        WHERE (
                            (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
                            (DATE(o.created_at) = :nextBusinessDay AND strftime('%H:%M', o.created_at) < :closingTime AND :isAfterMidnight = 1)
                        )
                    ");
                    $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
                    $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                    $cashSalesQuery->bindParam(':closingTime', $closingTime);
                    $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                    $cashSalesQuery->execute();
                } else {
                    $cashSalesQuery = $db->prepare("
                        SELECT COALESCE(SUM(total), 0) 
                        FROM orders 
                        WHERE (
                            (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                            (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                        )
                    ");
                    $cashSalesQuery->bindParam(':selectedDate', $selectedDate);
                    $cashSalesQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                    $cashSalesQuery->bindParam(':closingTime', $closingTime);
                    $cashSalesQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                    $cashSalesQuery->execute();
                }
                $totalCashSales = $cashSalesQuery->fetchColumn();
                
                // 3. Selected date's cash received from credit sales payments using payments table (like reports.php)
                $creditPaymentsQuery = $db->prepare("
                    SELECT COALESCE(SUM(p.amount), 0) 
                    FROM payments p
                    JOIN credit_sales cs ON p.sale_id = cs.id
                    WHERE cs.payment_status = 'paid' AND (
                        (DATE(p.payment_date) = :selectedDate AND strftime('%H:%M', p.payment_date) >= :closingTime) OR
                        (DATE(p.payment_date) = :nextBusinessDay AND strftime('%H:%M', p.payment_date) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $creditPaymentsQuery->bindParam(':selectedDate', $selectedDate);
                $creditPaymentsQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $creditPaymentsQuery->bindParam(':closingTime', $closingTime);
                $creditPaymentsQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $creditPaymentsQuery->execute();
                $totalCreditPayments = $creditPaymentsQuery->fetchColumn();
                
                // 4. Selected date's cash out (withdrawals)
                $cashOutQuery = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM cash_transactions 
                    WHERE type='cash-out' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $cashOutQuery->bindParam(':selectedDate', $selectedDate);
                $cashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $cashOutQuery->bindParam(':closingTime', $closingTime);
                $cashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $cashOutQuery->execute();
                $totalCashOut = $cashOutQuery->fetchColumn();
                
                // Final cash in till calculation using selected date's business day
                $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
                
                // Calculate data for cash up modal badges
                // 1. Credit Returns for selected date
                $creditReturnsQuery = $db->prepare("
                    SELECT COUNT(*) as count, COALESCE(SUM(return_amount), 0) as amount
                    FROM credit_returns 
                    WHERE DATE(created_at) = :selectedDate
                ");
                $creditReturnsQuery->bindParam(':selectedDate', $selectedDate);
                $creditReturnsQuery->execute();
                $creditReturnsData = $creditReturnsQuery->fetch(PDO::FETCH_ASSOC);
                $creditReturnsCount = $creditReturnsData['count'];
                $creditReturnsAmount = $creditReturnsData['amount'];
                
                // 2. EFT Income (Hubbly Swipes) for selected date
                $eftIncomeQuery = $db->prepare("
                    SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as amount
                    FROM eft_payments 
                    WHERE (
                        (DATE(payment_date) = :selectedDate AND strftime('%H:%M', payment_date) >= :closingTime) OR
                        (DATE(payment_date) = :nextBusinessDay AND strftime('%H:%M', payment_date) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $eftIncomeQuery->bindParam(':selectedDate', $selectedDate);
                $eftIncomeQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $eftIncomeQuery->bindParam(':closingTime', $closingTime);
                $eftIncomeQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $eftIncomeQuery->execute();
                $eftIncomeData = $eftIncomeQuery->fetch(PDO::FETCH_ASSOC);
                $eftIncomeCount = $eftIncomeData['count'];
                $eftIncomeAmount = $eftIncomeData['amount'];
                
                // 3. Damages for selected date
                $damagesQuery = $db->prepare("
                    SELECT COUNT(*) as count, COALESCE(SUM(d.quantity * p.price), 0) as amount
                    FROM damaged_goods d
                    JOIN products p ON d.product_id = p.id
                    WHERE DATE(d.date) = :selectedDate
                ");
                $damagesQuery->bindParam(':selectedDate', $selectedDate);
                $damagesQuery->execute();
                $damagesData = $damagesQuery->fetch(PDO::FETCH_ASSOC);
                $damagesCount = $damagesData['count'];
                $damagesAmount = $damagesData['amount'];
                
                // 4. Creditors for selected date
                $creditorsQuery = $db->prepare("
                    SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as amount
                    FROM credit_sales 
                    WHERE DATE(created_at) = :selectedDate
                ");
                $creditorsQuery->bindParam(':selectedDate', $selectedDate);
                $creditorsQuery->execute();
                $creditorsData = $creditorsQuery->fetch(PDO::FETCH_ASSOC);
                $creditorsCount = $creditorsData['count'];
                $creditorsAmount = $creditorsData['amount'];
                
                // Fetch data for dropdowns
                // 1. Fetch creditors for dropdown
                $creditorsDropdownQuery = $db->prepare("SELECT id, name, phone FROM creditors WHERE active = 1 ORDER BY name");
                $creditorsDropdownQuery->execute();
                $creditorsList = $creditorsDropdownQuery->fetchAll(PDO::FETCH_ASSOC);
                
                // 2. Fetch products for dropdown
                $productsDropdownQuery = $db->prepare("SELECT id, name, price FROM products ORDER BY name");
                $productsDropdownQuery->execute();
                $productsList = $productsDropdownQuery->fetchAll(PDO::FETCH_ASSOC);
                
                // 3. Fetch credit sales for dropdown (with creditor names)
                $creditSalesDropdownQuery = $db->prepare("
                    SELECT cs.id, cs.total_amount, cs.due_date, c.name as creditor_name, cs.payment_status
                    FROM credit_sales cs
                    JOIN creditors c ON cs.creditor_id = c.id
                    WHERE cs.payment_status = 'unpaid'
                    ORDER BY cs.created_at DESC
                ");
                $creditSalesDropdownQuery->execute();
                $creditSalesList = $creditSalesDropdownQuery->fetchAll(PDO::FETCH_ASSOC);
                ?>
                


            <!-- Daily Income/Expenses Summary -->
            <?php
                // Get selected date
                $selectedDate = $selectedDate;
                
                // Calculate selected date's cash-in
                $selectedDateCashInQuery = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM cash_transactions 
                    WHERE type = 'cash-in' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $selectedDateCashInQuery->bindParam(':selectedDate', $selectedDate);
                $selectedDateCashInQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $selectedDateCashInQuery->bindParam(':closingTime', $closingTime);
                $selectedDateCashInQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $selectedDateCashInQuery->execute();
                $selectedDateCashIn = $selectedDateCashInQuery->fetchColumn();
                
                // Calculate selected date's cash-out
                $selectedDateCashOutQuery = $db->prepare("
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM cash_transactions 
                    WHERE type = 'cash-out' AND (
                        (DATE(created_at) = :selectedDate AND strftime('%H:%M', created_at) >= :closingTime) OR
                        (DATE(created_at) = :nextBusinessDay AND strftime('%H:%M', created_at) < :closingTime AND :isAfterMidnight = 1)
                    )
                ");
                $selectedDateCashOutQuery->bindParam(':selectedDate', $selectedDate);
                $selectedDateCashOutQuery->bindParam(':nextBusinessDay', $nextBusinessDay);
                $selectedDateCashOutQuery->bindParam(':closingTime', $closingTime);
                $selectedDateCashOutQuery->bindParam(':isAfterMidnight', $isAfterMidnight, PDO::PARAM_INT);
                $selectedDateCashOutQuery->execute();
                $selectedDateCashOut = $selectedDateCashOutQuery->fetchColumn();
                
                // Calculate selected date's net
                $selectedDateNet = $selectedDateCashIn - $selectedDateCashOut;
                ?>


  

                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 relative z-10">
                    <!-- Cash In Till Card -->
                    <div class="bg-white shadow-lg rounded-lg p-4 lg:p-6">
                        <div class="flex items-center justify-between mb-3 lg:mb-4">
                            <h3 class="text-base lg:text-lg font-semibold text-gray-800">Cash Available in Till</h3>
                            <div class="p-2 bg-blue-100 rounded-full">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="cash-in-till text-xl lg:text-2xl font-bold <?= $cashInTill >= 0 ? 'text-blue-600' : 'text-red-600' ?>">
                            N$<?= number_format($cashInTill, 2) ?>
                        </p>
                        <p class="text-xs lg:text-sm text-gray-500 mt-2">Current cash balance in the till</p>
                    </div>
                    

                    <!-- Total Withdrawals Card -->
                    <div class="bg-white shadow-lg rounded-lg p-4 lg:p-6">
                        <div class="flex items-center justify-between mb-3 lg:mb-4">
                            <h3 class="text-base lg:text-lg font-semibold text-gray-800">Selected Date's Withdrawals</h3>
                            <div class="p-2 bg-red-100 rounded-full">
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="total-withdrawals text-xl lg:text-2xl font-bold text-red-600">N$<?= number_format($selectedDateTotalWithdrawals, 2) ?></p>
                        <p class="text-xs lg:text-sm text-gray-500 mt-2">Total cash withdrawn on selected date</p>
                    </div>
                </div>

              
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 relative z-10">
                    <!-- Cash In Form -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-teal-500 to-teal-500 py-3 lg:py-4 px-4 lg:px-6">
                            <h3 class="text-white text-base lg:text-lg font-bold flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Cash Deposit
                            </h3>
                        </div>
                        <form id="cashInForm" method="POST" class="p-4 lg:p-6">
                            <input type="hidden" name="action" value="cash-in">
                            <input type="hidden" name="ajax" value="1">
                            
                            <div class="space-y-4">
                                <div class="relative">
                                    <div class="relative mt-1">
                                        <input type="text" name="description" id="cash-in-description" placeholder="Reason for deposit" required
                                            class="hidden w-full pl-10 pr-3 py-3 border-gray-300 rounded-lg focus:ring-teal-500 focus:border-teal-500 shadow-sm text-gray-900 placeholder-gray-400 transition-all duration-200">
                                    </div>
                                    
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-700 mb-2">Select a category:</p>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="income-category px-3 py-1.5 bg-teal-100 text-teal-800 text-xs font-medium rounded-full border border-teal-200 hover:bg-teal-200 transition-colors">
                                               Opening Balance
                                            </button>
                                            <button type="button" class="income-category px-3 py-1.5 bg-teal-100 text-teal-800 text-xs font-medium rounded-full border border-teal-200 hover:bg-teal-200 transition-colors">
                                                Credit Payment
                                            </button>
                                            <button type="button" class="income-category px-3 py-1.5 bg-teal-100 text-teal-800 text-xs font-medium rounded-full border border-teal-200 hover:bg-teal-200 transition-colors">
                                                Other Income
                                            </button>
                                        </div>
                                        <div class="mt-3">
                                            <button type="button" id="custom-income-btn" class="text-xs text-teal-600 hover:text-teal-700 font-medium flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                                Custom description
                                            </button>
                                            <div id="custom-income-field" class="hidden mt-2">
                                                <input type="text" id="custom-income-input" placeholder="Enter custom description" 
                                                    class="w-full pl-3 pr-3 py-2 border-gray-300 rounded-lg focus:ring-teal-500 focus:border-teal-500 shadow-sm text-gray-900 placeholder-gray-400 transition-all duration-200 text-sm">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="cash-in-amount-container" class="relative hidden">
                                    <label for="cash-in-amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <div class="relative mt-1 rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500 sm:text-sm">N$</span>
                                        </div>
                                        <input type="number" name="amount" id="cash-in-amount" placeholder="0.00" step="0.01" required
                                            class="block w-full pl-10 pr-12 py-3 border-gray-300 rounded-lg focus:ring-teal-500 focus:border-teal-500 shadow-sm text-gray-900 placeholder-gray-400 transition-all duration-200">
                                    </div>
                                </div>
                                
                                <button type="submit" id="cash-in-submit" class="hidden w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-all duration-200">
                                    <svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                    </svg>
                                    Deposit Cash
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Cash Out Form -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-rose-500 to-red-500 py-3 lg:py-4 px-4 lg:px-6">
                            <h3 class="text-white text-base lg:text-lg font-bold flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                </svg>
                                Cash Withdrawal
                            </h3>
                        </div>
                        <form id="cashOutForm" method="POST" class="p-4 lg:p-6">
                            <input type="hidden" name="action" value="cash-out">
                            <input type="hidden" name="ajax" value="1">
                            
                            <div class="space-y-4">
                                <div class="relative">
                                    <div class="relative mt-1">
                                        <input type="text" name="description" id="cash-out-description" placeholder="Reason for withdrawal" required
                                            class="hidden w-full pl-10 pr-3 py-3 border-gray-300 rounded-lg focus:ring-rose-500 focus:border-rose-500 shadow-sm text-gray-900 placeholder-gray-400 transition-all duration-200">
                                    </div>
                                    
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-700 mb-2">Select a category:</p>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="expense-category px-3 py-1.5 bg-rose-100 text-rose-800 text-xs font-medium rounded-full border border-rose-200 hover:bg-rose-200 transition-colors">
                                                Ice Cubes
                                            </button>
                                            <button type="button" class="expense-category px-3 py-1.5 bg-rose-100 text-rose-800 text-xs font-medium rounded-full border border-rose-200 hover:bg-rose-200 transition-colors">
                                            Electricity
                                            </button>
                                            <button type="button" class="expense-category px-3 py-1.5 bg-rose-100 text-rose-800 text-xs font-medium rounded-full border border-rose-200 hover:bg-rose-200 transition-colors">
                                                Other Expenses
                                            </button>
                                            <button type="button" class="expense-category px-3 py-1.5 bg-rose-100 text-rose-800 text-xs font-medium rounded-full border border-rose-200 hover:bg-rose-200 transition-colors">
                                            Toilet Paper
                                            </button>
    
                                        </div>
                                        <div class="mt-3">
                                            <button type="button" id="custom-expense-btn" class="text-xs text-rose-600 hover:text-rose-700 font-medium flex items-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                                Custom description
                                            </button>
                                            <div id="custom-expense-field" class="hidden mt-2">
                                                <input type="text" id="custom-expense-input" placeholder="Enter custom description" 
                                                    class="w-full pl-3 pr-3 py-2 border-gray-300 rounded-lg focus:ring-rose-500 focus:border-rose-500 shadow-sm text-gray-900 placeholder-gray-400 transition-all duration-200 text-sm">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="cash-out-amount-container" class="relative hidden">
                                    <label for="cash-out-amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <div class="relative mt-1 rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500 sm:text-sm">N$</span>
                                        </div>
                                        <input type="number" name="amount" id="cash-out-amount" placeholder="0.00" step="0.01" required
                                            class="block w-full pl-10 pr-12 py-3 border-gray-300 rounded-lg focus:ring-rose-500 focus:border-rose-500 shadow-sm text-gray-900 placeholder-gray-400 transition-all duration-200">
                                    </div>
                                </div>
                                
                                <button type="submit" id="cash-out-submit" class="hidden w-full inline-flex items-center justify-center px-5 py-3 border border-transparent text-base font-medium rounded-lg shadow-sm text-white bg-rose-600 hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-rose-500 transition-all duration-200">
                                    <svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                    </svg>
                                    Withdraw Cash
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
    
                
                <!-- Income and Expenses Table (Weekly Summary) -->
                <div class="bg-white shadow-lg rounded-lg mb-8 overflow-hidden relative z-10">
                    <div class="p-4 py-2 bg-gray-200 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                        <h2 class="text-lg lg:text-xl font-semibold text-gray-800 flex items-center">
                            <svg class="w-4 h-4 lg:w-5 lg:h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z"/>
                            </svg>
                            <span class="text-sm lg:text-base">Daily Cash Transactions (<?= htmlspecialchars($selectedDate) ?>)</span>
                        </h2>
                        <div class="relative w-full lg:max-w-72" style="max-width: 100%;">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                                </svg>
                            </div>
                            <input type="text" id="transactionSearch" onkeyup="filterTransactions()" placeholder="Search transactions..." 
                                   class="w-full pl-10 pr-4 py-2 lg:py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none focus:border-blue-500 shadow-sm transition duration-200 text-sm" style="max-width: 100%; box-sizing: border-box;">
                        </div>
                    </div>
                    <div class="overflow-x-auto" style="-webkit-overflow-scrolling: touch; overscroll-behavior: contain;">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTransactionsTable(0)">
                                        <div class="flex items-center">
                                            <span>ID</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTransactionsTable(1)">
                                        <div class="flex items-center">
                                            <span>Type</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTransactionsTable(2, true)">
                                        <div class="flex items-center">
                                            <span>Amount</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTransactionsTable(3)">
                                        <div class="flex items-center">
                                            <span>Description</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer" onclick="sortTransactionsTable(4)">
                                        <div class="flex items-center">
                                            <span>Date</span>
                                            <svg class="w-3 h-3 ml-1.5 sort-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                            </svg>
                                        </div>
                                    </th>
                        
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="transactionsTableBody">
                                <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900" data-label="ID">
                                        <?= $transaction['id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm" data-label="Type">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $transaction['type'] === 'cash-in' ? 'bg-teal-100 text-teal-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= ucfirst(str_replace('-', ' ', $transaction['type'])) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm 
                                        <?= $transaction['type'] === 'cash-in' ? 'text-teal-600 font-medium' : 'text-red-600 font-medium' ?>" data-label="Amount">
                                        N$<?= number_format($transaction['amount'], 2) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" data-label="Description">
                                        <?= htmlspecialchars($transaction['description']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" data-label="Date">
                                        <?= date('Y-m-d H:i', strtotime($transaction['created_at'])) ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                                <?php if (count($transactions) === 0): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500" data-label="">
                                        No transactions found for selected date
                                </td>
                            </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                </div>
                
                    <!-- Pagination Controls -->
                    <div class="px-3 lg:px-6 py-3 bg-gray-50 border-t border-gray-200">
                        <div class="flex flex-col lg:flex-row justify-between items-center gap-3 lg:gap-0">
                            <!-- Navigation Buttons - Left Side -->
                            <div class="flex gap-2 w-full lg:w-auto justify-center lg:justify-start">
                                <button id="firstPage" class="inline-flex items-center justify-center px-2 lg:px-3 py-2 border border-gray-300 text-xs lg:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm min-w-[36px] lg:min-w-auto">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                                    </svg>
                                    <span class="hidden lg:inline ml-1">First</span>
                                </button>
                                <button id="prevPage" class="inline-flex items-center justify-center px-2 lg:px-3 py-2 border border-gray-300 text-xs lg:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm min-w-[36px] lg:min-w-auto">
                                    <svg class="w-4 h-4 lg:mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                    <span class="hidden lg:inline">Prev</span>
                                </button>
                            </div>
                            
                            <!-- Page Info and Navigation - Center -->
                            <div class="flex flex-col lg:flex-row items-center gap-2 lg:gap-4 w-full lg:w-auto">
                                <span id="pageNumber" class="text-xs lg:text-sm text-gray-700 font-medium whitespace-nowrap">Page 1 of 1</span>
                                <div class="flex items-center gap-2">
                                    <input type="number" id="pageInput" min="1" class="w-16 lg:w-20 px-2 lg:px-3 py-1.5 lg:py-2 border border-gray-300 rounded-md text-xs lg:text-sm shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Page">
                                    <button id="goToPage" class="inline-flex items-center justify-center px-2 lg:px-3 py-1.5 lg:py-2 text-xs lg:text-sm font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 transition-colors shadow-sm min-w-[40px]">Go</button>
                                </div>
                            </div>
                            
                            <!-- Navigation Buttons - Right Side -->
                            <div class="flex gap-2 w-full lg:w-auto justify-center lg:justify-end">
                                <button id="nextPage" class="inline-flex items-center justify-center px-2 lg:px-3 py-2 border border-gray-300 text-xs lg:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm min-w-[36px] lg:min-w-auto">
                                    <span class="hidden lg:inline mr-1">Next</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </button>
                                <button id="lastPage" class="inline-flex items-center justify-center px-2 lg:px-3 py-2 border border-gray-300 text-xs lg:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm min-w-[36px] lg:min-w-auto">
                                    <span class="hidden lg:inline mr-1">Last</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Cash In/Out Forms -->

            </div>
        </div>
    </div>

    <?php
    // Fetch business info for Android printing
    $dbInfo = new PDO('sqlite:info.db');
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

        // Cash Back Button Handler
        document.addEventListener('DOMContentLoaded', function() {
            const cashBackBtn = document.getElementById('cashBackBtn');
            
            if (cashBackBtn) {
                cashBackBtn.addEventListener('click', function() {
                    $('#cashBackModal').removeClass('hidden');
                    $('#payment_amount').focus();
                });
            }
        });
        
        // Global dismissAlert function for use in the inline onclick attribute
        function dismissAlert(alertId) {
            const alertElement = document.getElementById(alertId);
            if (!alertElement) return;
            
            // Hide with animation
            alertElement.classList.add('opacity-0', 'scale-95');
            alertElement.classList.remove('opacity-100', 'scale-100');
            
            // Remove from DOM after animation completes
            setTimeout(() => {
                alertElement.remove();
                
                // Hide container if no alerts left
                const alertContainer = document.getElementById('alertContainer');
                if (alertContainer.children.length === 0) {
                    alertContainer.classList.add('translate-x-full');
                }
            }, 300);
        }
        
        // --- Pagination and Sorting Variables ---
        let currentSortColumn = -1;
        let currentSortDirection = 1;
        let currentPage = 1;
        let rowsPerPage = 7;
        
        // Function to sort the transactions table
        function sortTransactionsTable(columnIndex, isNumeric = false) {
            const tableBody = document.getElementById('transactionsTableBody');
            const rows = Array.from(tableBody.querySelectorAll('tr'));
            
            // Skip if no data or no data row
            if (rows.length === 0 || (rows.length === 1 && rows[0].querySelector('td[colspan]'))) {
                return;
            }
            
            // Determine sort direction
            if (currentSortColumn === columnIndex) {
                currentSortDirection *= -1; // Toggle direction if same column
            } else {
                currentSortDirection = 1; // Default to ascending for new column
            }
            
            currentSortColumn = columnIndex;
            
            // Sort the rows
            rows.sort((a, b) => {
                // Skip rows with colspan (no data message)
                if (a.querySelector('td[colspan]') || b.querySelector('td[colspan]')) {
                    return 0;
                }
                
                let aValue = a.cells[columnIndex].textContent.trim();
                let bValue = b.cells[columnIndex].textContent.trim();
                
                if (isNumeric) {
                    // Extract numeric value (for amounts with currency symbol)
                    aValue = parseFloat(aValue.replace(/[^0-9.-]+/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^0-9.-]+/g, '')) || 0;
                    return (aValue - bValue) * currentSortDirection;
                } else if (columnIndex === 4) {
                    // Special handling for dates
                    return new Date(aValue) > new Date(bValue) ? 
                        currentSortDirection : 
                        currentSortDirection * -1;
                } else {
                    // String comparison
                    return aValue.localeCompare(bValue) * currentSortDirection;
                }
            });
            
            // Reapply the sorted rows
            rows.forEach(row => tableBody.appendChild(row));
            
            // Reset to first page after sorting
            showPage(1);
        }
        
        // Function to filter transactions based on search input
        function filterTransactions() {
            const input = document.getElementById('transactionSearch');
            const filter = input.value.toLowerCase();
            const tableBody = document.getElementById('transactionsTableBody');
            const rows = Array.from(tableBody.querySelectorAll('tr'));
            let visibleRows = 0;
            
            // Store all rows that match the filter
            const matchingRows = [];
            
            rows.forEach(row => {
                // Skip the no-data row if present
                if (row.querySelector('td[colspan]')) {
                    row.style.display = 'none';
                    return;
                }
                
                const cells = row.querySelectorAll('td');
                let rowVisible = false;
                
                Array.from(cells).forEach(cell => {
                    if (cell.textContent.toLowerCase().indexOf(filter) > -1) {
                        rowVisible = true;
                    }
                });
                
                // Store the row and its visibility state
                if (rowVisible) {
                    matchingRows.push(row);
                    visibleRows++;
                }
                
                // Hide all rows initially
                row.style.display = 'none';
            });
            
            // If no rows match, show the no-data row if it exists
            const noDataRow = tableBody.querySelector('tr td[colspan]')?.parentNode;
            if (noDataRow && visibleRows === 0) {
                noDataRow.style.display = '';
                noDataRow.querySelector('td').textContent = 'No matching transactions found';
            } else if (noDataRow) {
                noDataRow.style.display = 'none';
            }
            
            // Update pagination based on filtered rows
            currentPage = 1;
            updatePagination(matchingRows);
            
            // Show first page of filtered results
            showFilteredPage(1, matchingRows);
        }
        
        // Function to show a specific page of filtered results
        function showFilteredPage(page, filteredRows) {
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const maxPage = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            
            // Show only rows for current page
            filteredRows.forEach((row, index) => {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });
            
            // Update pagination display
            document.getElementById('pageNumber').textContent = `Page ${page} of ${maxPage}`;
            document.getElementById('pageInput').value = page;
            document.getElementById('pageInput').max = maxPage;
            
            // Update button states
            document.getElementById('prevPage').disabled = page === 1;
            document.getElementById('firstPage').disabled = page === 1;
            document.getElementById('nextPage').disabled = page >= maxPage;
            document.getElementById('lastPage').disabled = page >= maxPage;
            
            currentPage = page;
        }
        
        // Function to update pagination controls based on filtered rows
        function updatePagination(filteredRows) {
            const maxPage = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            
            // Update pagination event listeners for filtered results
            document.getElementById('firstPage').addEventListener('click', () => showFilteredPage(1, filteredRows));
            document.getElementById('prevPage').addEventListener('click', () => {
                if (currentPage > 1) showFilteredPage(currentPage - 1, filteredRows);
            });
            document.getElementById('nextPage').addEventListener('click', () => {
                if (currentPage < maxPage) showFilteredPage(currentPage + 1, filteredRows);
            });
            document.getElementById('lastPage').addEventListener('click', () => {
                showFilteredPage(maxPage, filteredRows);
            });
            document.getElementById('goToPage').addEventListener('click', () => {
                const pageInput = document.getElementById('pageInput');
                const pageNum = parseInt(pageInput.value, 10);
                
                if (pageNum && !isNaN(pageNum) && pageNum >= 1 && pageNum <= maxPage) {
                    showFilteredPage(pageNum, filteredRows);
                }
            });
        }
        
        // Original show page function for unfiltered data
        function showPage(page) {
            // If search input has a value, use the filter function instead
            const searchInput = document.getElementById('transactionSearch');
            if (searchInput && searchInput.value.trim() !== '') {
                filterTransactions();
                return;
            }
            
            const tableBody = document.getElementById('transactionsTableBody');
            const rows = Array.from(tableBody.querySelectorAll('tr'));
            
            // Skip if table is empty or only has no-data row
            if (rows.length === 0 || (rows.length === 1 && rows[0].querySelector('td[colspan]'))) {
                return;
            }
            
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
            
            // Hide all rows
            rows.forEach(row => row.style.display = 'none');
            
            // Show only rows for current page
            rows.slice(start, end).forEach(row => row.style.display = '');
            
            // Update pagination display
            document.getElementById('pageNumber').textContent = `Page ${page} of ${maxPage}`;
            document.getElementById('pageInput').value = page;
            document.getElementById('pageInput').max = maxPage;
            
            // Update button states
            document.getElementById('prevPage').disabled = page === 1;
            document.getElementById('firstPage').disabled = page === 1;
            document.getElementById('nextPage').disabled = page >= maxPage;
            document.getElementById('lastPage').disabled = page >= maxPage;
            
            currentPage = page;
        }

        $(document).ready(function() {
            // Initialize Lucide icons
            lucide.createIcons();
            
            // Function to open cash drawer
            function openCashDrawer() {
                const drawerData = {
                    open_drawer_only: true,
                    cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
                };
                
                return $.ajax({
                    url: 'receipt.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(drawerData),
                    dataType: 'json'
                });
            }
            
            // Function to update cash display without page reload
            function updateCashDisplay() {
                $.ajax({
                    url: 'get_cash_data.php',
                    method: 'GET',
                    data: { date: $('#date').val() },
                    dataType: 'json',
                    success: function(data) {
                        // Update cash in till
                        $('.cash-in-till').text('N$' + parseFloat(data.cashInTill).toFixed(2));
                        
                        // Update withdrawals
                        $('.total-withdrawals').text('N$' + parseFloat(data.totalWithdrawals).toFixed(2));
                        
                        // Update color based on balance
                        if (data.cashInTill >= 0) {
                            $('.cash-in-till').removeClass('text-red-600').addClass('text-blue-600');
                        } else {
                            $('.cash-in-till').removeClass('text-blue-600').addClass('text-red-600');
                        }
                    }
                });
            }
            
            // Function to add new transaction to table without page reload
            function addTransactionToTable(transaction) {
                const tableBody = document.getElementById('transactionsTableBody');
                const noDataRow = tableBody.querySelector('tr td[colspan]');
                
                // Remove no-data row if it exists
                if (noDataRow) {
                    noDataRow.parentNode.remove();
                }
                
                // Create new row
                const newRow = document.createElement('tr');
                newRow.className = 'hover:bg-gray-50 transition-colors fade-in';
                
                const typeClass = transaction.type === 'cash-in' ? 'bg-teal-100 text-teal-800' : 'bg-red-100 text-red-800';
                const amountClass = transaction.type === 'cash-in' ? 'text-teal-600 font-medium' : 'text-red-600 font-medium';
                const typeLabel = transaction.type === 'cash-in' ? 'Cash In' : 'Cash Out';
                
                newRow.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${transaction.id}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${typeClass}">${typeLabel}</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm ${amountClass}">N$${parseFloat(transaction.amount).toFixed(2)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${transaction.description}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${new Date(transaction.created_at).toLocaleString('en-CA', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'})}</td>
                `;
                
                // Add to top of table
                tableBody.insertBefore(newRow, tableBody.firstChild);
                
                // Update pagination
                showPage(1);
            }
            
            // Function to reset form UI
            function resetFormUI(formId) {
                if (formId === '#cashInForm') {
                    $('.income-category').removeClass('ring-2 ring-teal-500 bg-teal-200');
                    $('#cash-in-amount-container').addClass('hidden');
                    $('#cash-in-submit').addClass('hidden');
                    $('#custom-income-field').addClass('hidden');
                    $('#custom-income-input').val('');
                } else {
                    $('.expense-category').removeClass('ring-2 ring-rose-500 bg-rose-200');
                    $('#cash-out-amount-container').addClass('hidden');
                    $('#cash-out-submit').addClass('hidden');
                    $('#custom-expense-field').addClass('hidden');
                    $('#custom-expense-input').val('');
                }
            }
            
            // Add loading states to forms
            function addLoadingState(formId) {
                const submitButton = formId === '#cashInForm' ? '#cash-in-submit' : '#cash-out-submit';
                const originalText = $(submitButton).html();
                
                $(submitButton).prop('disabled', true).html(`
                    <svg class="animate-spin h-5 w-5 mr-2 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processing...
                `);
                
                return originalText;
            }
            
            function removeLoadingState(formId, originalText) {
                const submitButton = formId === '#cashInForm' ? '#cash-in-submit' : '#cash-out-submit';
                $(submitButton).prop('disabled', false).html(originalText);
            }

            // Update the form submission handler to include loading states
            function handleFormSubmitWithDrawer(formId) {
                $(formId).on('submit', function(e) {
                    e.preventDefault();
                    
                    // Add loading state
                    const originalText = addLoadingState(formId);
                    
                    // For cash-out form, check if amount exceeds available balance
                    if (formId === '#cashOutForm') {
                        const withdrawalAmount = parseFloat($('#cash-out-amount').val());
                        const availableBalance = <?= $cashInTill ?>; // Use the calculated $cashInTill value
                        
                        if (withdrawalAmount > availableBalance) {
                            removeLoadingState(formId, originalText);
                            showAlert('Cannot withdraw more than the available balance (N$' + availableBalance.toFixed(2) + ')', 'error');
                            return false;
                        }
                    }
                    
                    // First submit the form data
                    $.ajax({
                        url: 'cash.php',
                        method: 'POST',
                        data: $(this).serialize(),
                        dataType: 'json',
                        success: function(response) {
                            if (response.status === 'error') {
                                removeLoadingState(formId, originalText);
                                showAlert(response.message || 'An error occurred while processing your request.', 'error');
                                return;
                            }
                            
                            $(formId)[0].reset();
                            
                            // Show success message based on form type
                            const successMessage = formId === '#cashInForm' ? 
                                'Cash deposit completed successfully.' : 
                                'Cash withdrawal completed successfully.';
                            showAlert(successMessage, 'success');
                            
                            // Then open the cash drawer
                            openCashDrawer().always(function() {
                                // Instead of reloading the page, update the UI dynamically
                                updateCashDisplay();
                                addTransactionToTable(response);
                                
                                // Reset form UI and loading state
                                resetFormUI(formId);
                                removeLoadingState(formId, originalText);
                            });
                        },
                        error: function(xhr) {
                            removeLoadingState(formId, originalText);
                            let errorMessage = 'An error occurred while processing your request.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                errorMessage = xhr.responseJSON.message;
                            }
                            showAlert(errorMessage, 'error');
                        }
                    });
                });
            }
            
            // Initialize form handlers
            handleFormSubmitWithDrawer('#cashInForm');
            handleFormSubmitWithDrawer('#cashOutForm');
            
            // Handle cash drawer opening button
            $('#openCashDrawer').on('click', function() {
                $(this).prop('disabled', true).addClass('bg-gray-500').removeClass('bg-teal-600 hover:bg-teal-700');
                
                openCashDrawer().done(function(response) {
                    if (response.success) {
                        // Show success feedback
                        $('#openCashDrawer').text('Drawer Opened').addClass('bg-teal-600').removeClass('bg-gray-500');
                        showAlert('Cash drawer opened successfully', 'success');
                        
                        // Reset button after 2 seconds
                            setTimeout(function() {
                            $('#openCashDrawer').prop('disabled', false)
                                .html('<svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>Open Cash Drawer')
                                .addClass('bg-teal-600 hover:bg-teal-700').removeClass('bg-gray-500');
                        }, 2000);
                    } else {
                        alert('Error: ' + response.message);
                        $('#openCashDrawer').prop('disabled', false)
                            .addClass('bg-teal-600 hover:bg-teal-700').removeClass('bg-gray-500');
                    }
                }).fail(function(xhr) {
                    let errorMessage = 'Error connecting to the printer service';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }
                    alert(errorMessage);
                    $('#openCashDrawer').prop('disabled', false)
                        .addClass('bg-teal-600 hover:bg-teal-700').removeClass('bg-gray-500');
                });
            });

            // Handle delete button clicks
            $('.delete-transaction').on('click', function() {
                const transactionId = $(this).data('id');
                
                // Store the transaction ID for later use
                $('#confirmDelete').data('id', transactionId);
                
                // Show confirmation modal with animation
                $('#confirmationModal').removeClass('hidden');
                setTimeout(() => {
                    $('#modalContent').removeClass('scale-95 opacity-0').addClass('scale-100 opacity-100');
                }, 10);
            });
            
            // Handle cancel button in modal
            $('#cancelDelete').on('click', function() {
                // Hide modal with animation
                $('#modalContent').removeClass('scale-100 opacity-100').addClass('scale-95 opacity-0');
                setTimeout(() => {
                    $('#confirmationModal').addClass('hidden');
                }, 300);
            });
            
            // Handle confirm delete in modal
            $('#confirmDelete').on('click', function() {
                const transactionId = $(this).data('id');
                
                // Hide modal
                $('#modalContent').removeClass('scale-100 opacity-100').addClass('scale-95 opacity-0');
                setTimeout(() => {
                    $('#confirmationModal').addClass('hidden');
                    
                    // Delete the transaction
                    $.ajax({
                        url: 'cash.php',
                        method: 'POST',
                        data: {
                            delete_transaction_id: transactionId,
                            ajax: 1
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.status === 'success') {
                                showAlert('Transaction deleted successfully', 'success');
                                
                                // Remove the transaction row from the table
                                $(`tr[data-id="${transactionId}"]`).fadeOut(300, function() {
                                    $(this).remove();
                                    
                                    // Update cash display
                                    updateCashDisplay();
                                    
                                    // Update pagination
                                    showPage(currentPage);
                                    
                                    // Check if table is empty and show no data message
                                    const tableBody = document.getElementById('transactionsTableBody');
                                    if (tableBody.children.length === 0) {
                                        const noDataRow = document.createElement('tr');
                                        noDataRow.innerHTML = `
                                            <td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                                No transactions found for selected date
                                            </td>
                                        `;
                                        tableBody.appendChild(noDataRow);
                                    }
                                });
                            } else {
                                showAlert('Error deleting transaction', 'error');
                            }
                        },
                        error: function() {
                            showAlert('Error deleting transaction', 'error');
                        }
                    });
                }, 300);
            });
            
            // Handle click outside modal to close it
            $('#confirmationModal').on('click', function(e) {
                if (e.target === this) {
                    $('#modalContent').removeClass('scale-100 opacity-100').addClass('scale-95 opacity-0');
                    setTimeout(() => {
                        $('#confirmationModal').addClass('hidden');
                    }, 300);
                }
            });
            
            // Initialize pagination and sorting for transactions
            initializeTransactionsPaginationAndSorting();

            // Cash Back Modal Handler
            $('#cancelCashBack, #cancelCashBack2').on('click', function() {
                $('#cashBackModal').addClass('hidden');
                $('#cashBackForm')[0].reset();
                $('#sale_amount').text('N$0.00');
                $('#total_payment').text('N$0.00');
                $('#cash_withdrawn').text('N$0.00');
            });
            
            $('#cashBackForm').on('submit', function(e) {
                e.preventDefault();
                
                const amount = parseFloat($('#payment_amount').val()) || 0;
                
                if (amount <= 0) {
                    showAlert('Amount must be greater than zero', 'error');
                    return;
                }
                
                // Same amount is used for both payment and cash back
                const paymentAmount = amount;
                const cashBackAmount = amount;
                
                // Sale amount = payment amount (net sale)
                const saleAmount = paymentAmount;
                
                // Record cashback transaction
                const cashbackData = {
                    eft_total: paymentAmount,
                    cash_back: cashBackAmount,
                    sale_amount: saleAmount,
                    transaction_ref: $('#transaction_ref').val(),
                    wallet_provider: $('#wallet_provider').val() || 'Cash Back'
                };
                
                $.ajax({
                    url: 'process_cashback.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(cashbackData),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Open cash drawer before showing success and reloading
                            openCashDrawer().always(function() {
                                showAlert('Cash back transaction completed successfully', 'success');
                                $('#cashBackModal').addClass('hidden');
                                $('#cashBackForm')[0].reset();
                                $('#sale_amount').text('N$0.00');
                                $('#total_payment').text('N$0.00');
                                $('#cash_withdrawn').text('N$0.00');
                                // Refresh the page to update display
                                setTimeout(() => location.reload(), 1000);
                            });
                        } else {
                            showAlert(response.error || 'Error processing cash back', 'error');
                        }
                    },
                    error: function(xhr) {
                        let errorMessage = 'Error processing cash back';
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            errorMessage = xhr.responseJSON.error;
                        }
                        showAlert(errorMessage, 'error');
                    }
                });
            });
            
            // Function to update payment information display
            function updateCashBackDisplay() {
                const amount = parseFloat($('#payment_amount').val()) || 0;
                
                $('#total_payment').text('N$' + amount.toFixed(2));
                $('#cash_withdrawn').text('N$' + amount.toFixed(2));
                $('#sale_amount').text('N$' + amount.toFixed(2));
            }
            
            // Calculate payment information when value changes
            $('#payment_amount').on('input', function() {
                updateCashBackDisplay();
            });

            // Searchable Dropdown Functionality
            function initializeSearchableDropdown(searchInputId, optionsId, hiddenInputId) {
                const searchInput = document.getElementById(searchInputId);
                const optionsContainer = document.getElementById(optionsId);
                const hiddenInput = document.getElementById(hiddenInputId);
                
                if (!searchInput || !optionsContainer || !hiddenInput) return;
                
                // Show dropdown on focus
                searchInput.addEventListener('focus', function() {
                    optionsContainer.style.display = 'block';
                });
                
                // Hide dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !optionsContainer.contains(e.target)) {
                        optionsContainer.style.display = 'none';
                    }
                });
                
                // Filter options on input
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const options = optionsContainer.querySelectorAll('.dropdown-option');
                    
                    options.forEach(option => {
                        const text = option.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            option.style.display = 'block';
                        } else {
                            option.style.display = 'none';
                        }
                    });
                });
                
                // Handle option selection
                optionsContainer.addEventListener('click', function(e) {
                    if (e.target.closest('.dropdown-option')) {
                        const option = e.target.closest('.dropdown-option');
                        const id = option.dataset.id;
                        const value = option.dataset.value;
                        
                        hiddenInput.value = id;
                        searchInput.value = value;
                        optionsContainer.style.display = 'none';
                        
                        // Add visual feedback
                        optionsContainer.querySelectorAll('.dropdown-option').forEach(opt => {
                            opt.classList.remove('selected');
                        });
                        option.classList.add('selected');
                    }
                });
            }
            
            // Initialize all searchable dropdowns (only if elements exist)
            const creditSaleSearch = document.getElementById('credit_sale_search');
            const creditSaleOptions = document.getElementById('credit_sale_options');
            const creditSaleId = document.getElementById('credit_sale_id');
            if (creditSaleSearch && creditSaleOptions && creditSaleId) {
                initializeSearchableDropdown('credit_sale_search', 'credit_sale_options', 'credit_sale_id');
            }
            
            const productSearch = document.getElementById('product_search');
            const productOptions = document.getElementById('product_options');
            const productId = document.getElementById('product_id');
            if (productSearch && productOptions && productId) {
                initializeSearchableDropdown('product_search', 'product_options', 'product_id');
            }
            
            const creditorSearch = document.getElementById('creditor_search');
            const creditorOptions = document.getElementById('creditor_options');
            const creditorId = document.getElementById('creditor_id');
            if (creditorSearch && creditorOptions && creditorId) {
                initializeSearchableDropdown('creditor_search', 'creditor_options', 'creditor_id');
            }

            // Cash-up functionality (only if button exists)
            const printCashupBtn = document.getElementById('printCashup');
            if (printCashupBtn) {
                printCashupBtn.addEventListener('click', function() {
                    // Show the modal first
                    const modal = document.getElementById('cashTillModal');
                    if (modal) {
                        modal.classList.remove('hidden');
                        
                        // Get the expected cash amount from PHP variable
                        const expectedAmount = <?php echo $cashInTill; ?>;
                        const expectedAmountEl = document.getElementById('expectedCashAmount');
                        if (expectedAmountEl) {
                            expectedAmountEl.textContent = expectedAmount.toFixed(2);
                        }
                    }
                });
            }

            const cancelCashTill = document.getElementById('cancelCashTill');
            if (cancelCashTill) {
                cancelCashTill.addEventListener('click', function() {
                    const modal = document.getElementById('cashTillModal');
                    if (modal) {
                        modal.classList.add('hidden');
                    }
                });
            }
            
            const cancelCashTill2 = document.getElementById('cancelCashTill2');
            if (cancelCashTill2) {
                cancelCashTill2.addEventListener('click', function() {
                    const modal = document.getElementById('cashTillModal');
                    if (modal) {
                        modal.classList.add('hidden');
                    }
                });
            }

            const confirmCashTill = document.getElementById('confirmCashTill');
            if (confirmCashTill) {
                confirmCashTill.addEventListener('click', function() {
                    const actualCashInTill = document.getElementById('actualCashInTill');
                    const expectedCashAmount = document.getElementById('expectedCashAmount');
                    
                    if (!actualCashInTill || !expectedCashAmount) {
                        console.error('Required elements for cash till confirmation not found');
                        return;
                    }
                    
                    const actualAmount = parseFloat(actualCashInTill.value) || 0;
                    const expectedAmount = parseFloat(expectedCashAmount.textContent);
                
                if (isNaN(actualAmount) || actualAmount < 0) {
                    showAlert('Please enter a valid cash amount', 'error');
                    return;
                }
                
                const difference = actualAmount - expectedAmount;
                
                // Prepare data for the cash-up report
                const dateElement = document.getElementById('date');
                if (!dateElement) {
                    console.error('Date element not found');
                    showAlert('Date element not found', 'error');
                    return;
                }
                
                const selectedDate = dateElement.value;
                const currentUser = "<?= isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown User' ?>";
                
                // First fetch cash available in till data from fetch_report_data.php
                const formData = new FormData();
                formData.append('date', selectedDate);
                formData.append('actual_cash_in_till', actualAmount);
                formData.append('cash_difference', difference);

                fetch('fetch_report_data.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showAlert(data.error, 'error');
                        return;
                    }
                    
                    // Open cash drawer before generating report
                    openCashDrawer();
                    
                    // Create a form to submit for PDF generation
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'cash-pdf.php';
                    
                    // Add all data as hidden fields
                    const cashupData = {
                        is_cashup: 'true',
                        date: selectedDate,
                        cashier_username: currentUser,
                        total_cash_sales: data.cashSalesTotal || 0,
                        eft_sales_total: data.eftSalesTotal || 0,
                        unpaid_credit: data.unpaidCredit || 0,
                        cash_on_hand: data.cashOnHand || 0,
                        cash_available_in_till: data.cashAvailableInTill || 0,
                        actual_cash_in_till: actualAmount,
                        cash_difference: difference,
                        total_cash_in: data.totalCashIn || 0,
                        total_cash_out: data.totalCashOut || 0,
                        cumulative_cash_sales: data.cumulativeCashSales || 0,
                        cumulative_paid_credit: data.cumulativePaidCredit || 0
                    };
                    
                    // Debug log the data being sent
                    console.log('Sending data to cash-pdf.php:', cashupData);
                    
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
                    
                    // Also print the cash-up receipt directly from browser
                    const printData = Object.assign({}, cashupData, {
                        is_cashup_report: true,
                        cash_sales: data.cash_sales || 0,
                        credit_cash: data.credit_cash || 0,
                        credit_eft: data.credit_eft || 0,
                        eft_sales: data.eft_sales || 0,
                        credit_unpaid: data.credit_unpaid || 0,
                        total_income: data.total_income || 0,
                        total_expense: data.total_expense || 0,
                        net_amount: data.net_amount || 0
                    });
                    sendToPrinter(printData)
                    .then(result => {
                        if (result.success) {
                            showAlert('Cash-up receipt printed successfully.', 'success');
                        } else {
                            showAlert('Receipt printing failed: ' + (result.message || 'Unknown error'), 'error');
                        }
                    })
                    .catch(err => {
                        showAlert('Receipt printing failed: ' + err, 'error');
                    });
                    
                    // Hide the modal
                    const cashTillModal = document.getElementById('cashTillModal');
                    if (cashTillModal) {
                        cashTillModal.classList.add('hidden');
                    }
                    
                    // Show success notification
                    showAlert(
                        difference === 0 ? 
                            'Cash till balanced successfully' : 
                            `Cash till ${difference > 0 ? 'surplus' : 'shortage'} of N$${Math.abs(difference).toFixed(2)} recorded`,
                        difference === 0 ? 'success' : 'warning'
                    );
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Failed to generate cash-up report', 'error');
                });
                });
            }

            // Add click handlers for income category badges
            $('.income-category').on('click', function() {
                $('#cash-in-description').val($(this).text().trim());
                // Add visual feedback for selection
                $('.income-category').removeClass('ring-2 ring-teal-500 bg-teal-200');
                $(this).addClass('ring-2 ring-teal-500 bg-teal-200');
                
                // Show amount input and submit button
                $('#cash-in-amount-container').removeClass('hidden').addClass('fade-in');
                $('#cash-in-submit').removeClass('hidden').addClass('fade-in');
                $('#cash-in-amount').focus();
            });
            
            // Add click handlers for expense category badges
            $('.expense-category').on('click', function() {
                $('#cash-out-description').val($(this).text().trim());
                // Add visual feedback for selection
                $('.expense-category').removeClass('ring-2 ring-rose-500 bg-rose-200');
                $(this).addClass('ring-2 ring-rose-500 bg-rose-200');
                
                // Show amount input and submit button
                $('#cash-out-amount-container').removeClass('hidden').addClass('fade-in');
                $('#cash-out-submit').removeClass('hidden').addClass('fade-in');
                $('#cash-out-amount').focus();
            });
            
            // Custom description toggle for income
            $('#custom-income-btn').on('click', function() {
                $('#custom-income-field').toggleClass('hidden');
                if (!$('#custom-income-field').hasClass('hidden')) {
                    $('#custom-income-input').focus();
                    $('.income-category').removeClass('ring-2 ring-teal-500 bg-teal-200');
                }
            });
            
            // Custom description toggle for expense
            $('#custom-expense-btn').on('click', function() {
                $('#custom-expense-field').toggleClass('hidden');
                if (!$('#custom-expense-field').hasClass('hidden')) {
                    $('#custom-expense-input').focus();
                    $('.expense-category').removeClass('ring-2 ring-rose-500 bg-rose-200');
                }
            });
            
            // Update hidden input when typing in custom description
            $('#custom-income-input').on('input', function() {
                $('#cash-in-description').val($(this).val());
                if($(this).val().trim() !== '') {
                    // Show amount input and submit button
                    $('#cash-in-amount-container').removeClass('hidden').addClass('fade-in');
                    $('#cash-in-submit').removeClass('hidden').addClass('fade-in');
                } else {
                    // Hide amount input and submit button if description is empty
                    $('#cash-in-amount-container').addClass('hidden');
                    $('#cash-in-submit').addClass('hidden');
                }
            });
            
            $('#custom-expense-input').on('input', function() {
                $('#cash-out-description').val($(this).val());
                if($(this).val().trim() !== '') {
                    // Show amount input and submit button
                    $('#cash-out-amount-container').removeClass('hidden').addClass('fade-in');
                    $('#cash-out-submit').removeClass('hidden').addClass('fade-in');
                } else {
                    // Hide amount input and submit button if description is empty
                    $('#cash-out-amount-container').addClass('hidden');
                    $('#cash-out-submit').addClass('hidden');
                }
            });
        });
        
        // Function to initialize pagination
        function initializeTransactionsPaginationAndSorting() {
            // Set up pagination event listeners
            document.getElementById('firstPage').addEventListener('click', () => showPage(1));
            document.getElementById('prevPage').addEventListener('click', () => {
                if (currentPage > 1) showPage(currentPage - 1);
            });
            document.getElementById('nextPage').addEventListener('click', () => {
                const tableBody = document.getElementById('transactionsTableBody');
                const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                
                if (currentPage < maxPage) showPage(currentPage + 1);
            });
            document.getElementById('lastPage').addEventListener('click', () => {
                const tableBody = document.getElementById('transactionsTableBody');
                const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                
                showPage(maxPage);
            });
            document.getElementById('goToPage').addEventListener('click', () => {
                const pageInput = document.getElementById('pageInput');
                const pageNum = parseInt(pageInput.value, 10);
                
                if (pageNum && !isNaN(pageNum)) {
                    const tableBody = document.getElementById('transactionsTableBody');
                    const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
                    const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                    
                    if (pageNum >= 1 && pageNum <= maxPage) {
                        showPage(pageNum);
                    }
                }
            });
            
            // Initialize the first page
            showPage(1);
        }
        
        // Function to show tailwind alerts
        function showAlert(message, type = 'info') {
            const alertContainer = document.getElementById('alertContainer');
            const alertId = 'alert-' + Date.now();
            
            // Define color schemes based on alert type
            let colors = {
                error: 'bg-red-100 border-red-500 text-red-700',
                success: 'bg-teal-100 border-teal-500 text-teal-700',
                warning: 'bg-yellow-100 border-yellow-500 text-yellow-700',
                info: 'bg-blue-100 border-blue-500 text-blue-700'
            };
            
            // Create alert element
            const alertElement = document.createElement('div');
            alertElement.id = alertId;
            alertElement.className = `${colors[type]} border-l-4 p-4 mb-4 rounded shadow-md transform transition-all duration-300 ease-in-out opacity-0 scale-95`;
            
            // Generate icon based on type
            let icon = '';
            if (type === 'error') {
                icon = '<svg class="h-6 w-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
            } else if (type === 'success') {
                icon = '<svg class="h-6 w-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            } else if (type === 'warning') {
                icon = '<svg class="h-6 w-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
            } else {
                icon = '<svg class="h-6 w-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
            }
            
            // Set content
            alertElement.innerHTML = `
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        ${icon}
                        <p class="font-medium">${message}</p>
                    </div>
                    <button class="text-gray-500 hover:text-gray-700 focus:outline-none" onclick="dismissAlert('${alertId}')">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;
            
            // Add to container
            alertContainer.appendChild(alertElement);
            
            // Show container if it was hidden
            alertContainer.classList.remove('translate-x-full');
            
            // Show alert with animation
            setTimeout(() => {
                alertElement.classList.remove('opacity-0', 'scale-95');
                alertElement.classList.add('opacity-100', 'scale-100');
            }, 10);
            
            // Auto dismiss after 5 seconds
            setTimeout(() => {
                dismissAlert(alertId);
            }, 5000);
        }
        
        // Function to update cash report data via form submission
        function updateCashReport() {
            document.getElementById('dateForm').submit();
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

    <!-- Add modal for cash till input -->
    <div id="cashTillModal" class="fixed inset-0 z-[10000] flex items-center justify-center bg-gray-500/50 backdrop-blur-sm transition-opacity duration-300 hidden">
        <div class="relative w-full max-w-lg mx-auto rounded-lg shadow-xl bg-white border border-gray-200 p-0 overflow-hidden animate-in fade-in-90 scale-in-95">
            <div class="flex flex-col items-center px-6 py-6">
                <div class="flex items-center justify-between w-full mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 tracking-tight">Cash Till Verification</h3>
                    <button id="cancelCashTill" class="ml-2 p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="w-full mb-4">
                    <p class="text-sm text-gray-600 mb-2">Please enter the actual amount of cash in the till:</p>
                    <input type="number" step="0.01" id="actualCashInTill" class="w-full px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300 text-base font-medium shadow-sm transition" placeholder="Enter amount">
                </div>
                <div class="w-full flex items-center justify-between mb-4">
                    <span class="text-sm text-gray-600">Expected amount:</span>
                    <span class="text-lg font-bold text-gray-900">N$<span id="expectedCashAmount">0.00</span></span>
                </div>
                
                <!-- Footer Section with Badges -->
                <div class="w-full border-t border-gray-200 pt-4 mt-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-3">Daily Summary Badges:</h4>
                    <div class="grid grid-cols-1 gap-3">
                        <!-- Credit Returns Badge -->
                        <div class="flex items-center justify-between p-3 bg-red-50 border border-red-200 rounded-lg">
                            <div class="flex items-center">
                                <div class="p-2 bg-red-100 rounded-full mr-3">
                                    <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-red-800">Credit Returns</p>
                                    <p class="text-xs text-red-600"><?= $creditReturnsCount ?> returns</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-red-800">N$<?= number_format($creditReturnsAmount, 2) ?></p>
                            </div>
                        </div>
                        
                        <!-- EFT Income (Hubbly Swipes) Badge -->
                        <div class="flex items-center justify-between p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex items-center">
                                <div class="p-2 bg-blue-100 rounded-full mr-3">
                                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15l1-1m0 0l1-1m-1 1l-1-1m1 1l1 1m6-2V9a2 2 0 00-2-2H9a2 2 0 00-2 2v6m6 0a2 2 0 012 2v2a2 2 0 01-2 2H9a2 2 0 01-2-2v-2a2 2 0 012-2m6 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v6m6 0a2 2 0 012 2v2a2 2 0 01-2 2H9a2 2 0 01-2-2v-2a2 2 0 012-2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-blue-800">EFT Income</p>
                                    <p class="text-xs text-blue-600"><?= $eftIncomeCount ?> transactions</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-blue-800">N$<?= number_format($eftIncomeAmount, 2) ?></p>
                            </div>
                        </div>
                        
                        <!-- Damages Badge -->
                        <div class="flex items-center justify-between p-3 bg-orange-50 border border-orange-200 rounded-lg">
                            <div class="flex items-center">
                                <div class="p-2 bg-orange-100 rounded-full mr-3">
                                    <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-orange-800">Damages</p>
                                    <p class="text-xs text-orange-600"><?= $damagesCount ?> items</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-orange-800">N$<?= number_format($damagesAmount, 2) ?></p>
                            </div>
                        </div>
                        
                        <!-- Creditors Badge -->
                        <div class="flex items-center justify-between p-3 bg-purple-50 border border-purple-200 rounded-lg">
                            <div class="flex items-center">
                                <div class="p-2 bg-purple-100 rounded-full mr-3">
                                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-purple-800">Creditors</p>
                                    <p class="text-xs text-purple-600"><?= $creditorsCount ?> sales</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-purple-800">N$<?= number_format($creditorsAmount, 2) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex w-full gap-3 mt-6">
                    <button id="confirmCashTill" class="flex-1 px-4 py-2 rounded-md bg-gray-900 text-white font-medium shadow-sm hover:bg-gray-800 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <i class="fas fa-check mr-2"></i>Confirm
                    </button>
                    <button id="cancelCashTill2" class="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 font-medium shadow-sm hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Credit Return Modal -->
    <div id="creditReturnModal" class="fixed inset-0 z-[10000] flex items-center justify-center bg-gray-500/50 backdrop-blur-sm transition-opacity duration-300 hidden">
        <div class="relative w-full max-w-md mx-auto rounded-lg shadow-xl bg-white border border-gray-200 p-0 overflow-hidden">
            <div class="flex flex-col items-center px-6 py-6">
                <div class="flex items-center justify-between w-full mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 tracking-tight">Record Credit Return</h3>
                    <button id="cancelCreditReturn" class="ml-2 p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form id="creditReturnForm" class="w-full space-y-4">
                    <div>
                        <label for="return_amount" class="block text-sm font-medium text-gray-700 mb-1">Return Amount</label>
                        <input type="number" step="0.01" id="return_amount" name="return_amount" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-red-300 focus:border-red-300">
                    </div>
                    <div>
                        <label for="return_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                        <textarea id="return_reason" name="reason" rows="3" class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-red-300 focus:border-red-300"></textarea>
                    </div>
                    <div class="flex w-full gap-3 mt-6">
                        <button type="submit" class="flex-1 px-4 py-2 rounded-md bg-red-600 text-white font-medium shadow-sm hover:bg-red-700 transition-colors focus:outline-none focus:ring-2 focus:ring-red-300">
                            Record Return
                        </button>
                        <button type="button" id="cancelCreditReturn2" class="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 font-medium shadow-sm hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EFT Income Modal -->
    <div id="eftIncomeModal" class="fixed inset-0 z-[10000] flex items-center justify-center bg-gray-500/50 backdrop-blur-sm transition-opacity duration-300 hidden">
        <div class="relative w-full max-w-md mx-auto rounded-lg shadow-xl bg-white border border-gray-200 p-0 overflow-hidden">
            <div class="flex flex-col items-center px-6 py-6">
                <div class="flex items-center justify-between w-full mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 tracking-tight">Record EFT Income</h3>
                    <button id="cancelEftIncome" class="ml-2 p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form id="eftIncomeForm" class="w-full space-y-4">
                    <div>
                        <label for="transaction_ref" class="block text-sm font-medium text-gray-700 mb-1">Transaction Reference</label>
                        <input type="text" id="transaction_ref" name="transaction_ref" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-300 focus:border-blue-300">
                    </div>
                    <div>
                        <label for="wallet_provider" class="block text-sm font-medium text-gray-700 mb-1">Wallet Provider</label>
                        <select id="wallet_provider" name="wallet_provider" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-300 focus:border-blue-300">
                            <option value="">Select provider</option>
                            <option value="Hubbly">Hubbly</option>
                            <option value="E-Wallet">E-Wallet</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label for="eft_amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" step="0.01" id="eft_amount" name="amount" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-300 focus:border-blue-300">
                    </div>
                    <div class="flex w-full gap-3 mt-6">
                        <button type="submit" class="flex-1 px-4 py-2 rounded-md bg-blue-600 text-white font-medium shadow-sm hover:bg-blue-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-300">
                            Record EFT
                        </button>
                        <button type="button" id="cancelEftIncome2" class="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 font-medium shadow-sm hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Damage Modal -->
    <div id="damageModal" class="fixed inset-0 z-[10000] flex items-center justify-center bg-gray-500/50 backdrop-blur-sm transition-opacity duration-300 hidden">
        <div class="relative w-full max-w-md mx-auto rounded-lg shadow-xl bg-white border border-gray-200 p-0 overflow-hidden">
            <div class="flex flex-col items-center px-6 py-6">
                <div class="flex items-center justify-between w-full mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 tracking-tight">Record Damage</h3>
                    <button id="cancelDamage" class="ml-2 p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form id="damageForm" class="w-full space-y-4">
                    <div>
                        <label for="product_search" class="block text-sm font-medium text-gray-700 mb-1">Select Product</label>
                        <div class="searchable-dropdown">
                            <input type="text" id="product_search" placeholder="Search products..." class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-300">
                            <input type="hidden" id="product_id" name="product_id" required>
                            <div class="dropdown-options" id="product_options">
                                <?php foreach ($productsList as $product): ?>
                                <div class="dropdown-option" data-id="<?= $product['id'] ?>" data-value="<?= htmlspecialchars($product['name'] . ' - N$' . number_format($product['price'], 2)) ?>">
                                    <div class="font-medium"><?= htmlspecialchars($product['name']) ?></div>
                                    <div class="text-sm text-gray-600">N$<?= number_format($product['price'], 2) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="damage_quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity Damaged</label>
                        <input type="number" id="damage_quantity" name="quantity" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-300">
                    </div>
                    <div>
                        <label for="damage_reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for Damage</label>
                        <textarea id="damage_reason" name="reason" rows="3" class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-300"></textarea>
                    </div>
                    <div class="flex w-full gap-3 mt-6">
                        <button type="submit" class="flex-1 px-4 py-2 rounded-md bg-orange-600 text-white font-medium shadow-sm hover:bg-orange-700 transition-colors focus:outline-none focus:ring-2 focus:ring-orange-300">
                            Record Damage
                        </button>
                        <button type="button" id="cancelDamage2" class="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 font-medium shadow-sm hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Creditor Modal -->
    <div id="creditorModal" class="fixed inset-0 z-[10000] flex items-center justify-center bg-gray-500/50 backdrop-blur-sm transition-opacity duration-300 hidden">
        <div class="relative w-full max-w-md mx-auto rounded-lg shadow-xl bg-white border border-gray-200 p-0 overflow-hidden">
            <div class="flex flex-col items-center px-6 py-6">
                <div class="flex items-center justify-between w-full mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 tracking-tight">Record Creditor Sale</h3>
                    <button id="cancelCreditor" class="ml-2 p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form id="creditorForm" class="w-full space-y-4">
                    <div>
                        <label for="creditor_search" class="block text-sm font-medium text-gray-700 mb-1">Select Creditor</label>
                        <div class="searchable-dropdown">
                            <input type="text" id="creditor_search" placeholder="Search creditors..." class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-purple-300 focus:border-purple-300">
                            <input type="hidden" id="creditor_id" name="creditor_id" required>
                            <div class="dropdown-options" id="creditor_options">
                                <?php foreach ($creditorsList as $creditor): ?>
                                <div class="dropdown-option" data-id="<?= $creditor['id'] ?>" data-value="<?= htmlspecialchars($creditor['name'] . ' (' . $creditor['phone'] . ')') ?>">
                                    <div class="font-medium"><?= htmlspecialchars($creditor['name']) ?></div>
                                    <div class="text-sm text-gray-600"><?= htmlspecialchars($creditor['phone']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="creditor_total_amount" class="block text-sm font-medium text-gray-700 mb-1">Total Amount</label>
                        <input type="number" step="0.01" id="creditor_total_amount" name="total_amount" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-purple-300 focus:border-purple-300">
                    </div>
                    <div>
                        <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                        <input type="date" id="due_date" name="due_date" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-purple-300 focus:border-purple-300">
                    </div>
                    <div class="flex w-full gap-3 mt-6">
                        <button type="submit" class="flex-1 px-4 py-2 rounded-md bg-purple-600 text-white font-medium shadow-sm hover:bg-purple-700 transition-colors focus:outline-none focus:ring-2 focus:ring-purple-300">
                            Record Creditor
                        </button>
                        <button type="button" id="cancelCreditor2" class="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 font-medium shadow-sm hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cash Back Modal -->
    <div id="cashBackModal" class="fixed inset-0 z-[10000] flex items-center justify-center bg-gray-500/50 backdrop-blur-sm transition-opacity duration-300 hidden">
        <div class="relative w-full max-w-md mx-auto rounded-lg shadow-xl bg-white border border-gray-200 p-0 overflow-hidden">
            <div class="flex flex-col items-center px-6 py-6">
                <div class="flex items-center justify-between w-full mb-4">
                    <h3 class="text-xl font-semibold text-gray-900 tracking-tight">Cash Back Transaction</h3>
                    <button id="cancelCashBack" class="ml-2 p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <svg class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form id="cashBackForm" class="w-full space-y-4">
                    <div>
                        <label for="payment_amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" step="0.01" id="payment_amount" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300">
                        <p class="text-xs text-gray-500 mt-1">Enter the amount for EFT payment and cash withdrawal</p>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Information</label>
                        <div class="space-y-1">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Total Payment:</span>
                                <span class="text-base font-semibold text-gray-700" id="total_payment">N$0.00</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Cash Withdrawn:</span>
                                <span class="text-base font-semibold text-red-600" id="cash_withdrawn">N$0.00</span>
                            </div>
                            <div class="flex justify-between items-center border-t border-gray-200 pt-1 mt-1">
                                <span class="text-sm text-gray-600">Sale Amount:</span>
                                <span class="text-lg font-bold text-gray-800" id="sale_amount">N$0.00</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="transaction_ref" class="block text-sm font-medium text-gray-700 mb-1">Transaction Reference</label>
                        <input type="text" id="transaction_ref" name="transaction_ref" required class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300">
                    </div>
                    <div>
                        <label for="wallet_provider" class="block text-sm font-medium text-gray-700 mb-1">Wallet Provider</label>
                        <select id="wallet_provider" name="wallet_provider" class="w-full px-3 py-2 rounded-md border border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300">
                            <option value="">Select provider</option>
                            <option value="Hubbly">Hubbly</option>
                            <option value="E-Wallet">E-Wallet</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cash Back">Cash Back</option>
                        </select>
                    </div>
                    <div class="flex w-full gap-3 mt-6">
                        <button type="submit" class="flex-1 px-4 py-2 rounded-md bg-gray-600 text-white font-medium shadow-sm hover:bg-gray-700 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                            <i data-lucide="check-circle" class="w-4 h-4 inline-block mr-2"></i>
                            Process Cash Back
                        </button>
                        <button type="button" id="cancelCashBack2" class="flex-1 px-4 py-2 rounded-md bg-gray-100 text-gray-700 font-medium shadow-sm hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
