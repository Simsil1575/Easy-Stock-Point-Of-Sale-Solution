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

$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
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
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '22:00'; // Default to 10:00 PM if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '22:00';
}

// New SQLite connection
$db = new PDO('sqlite:../pos.db');

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

// Handle date selection (prefer GET date when returning from error redirect)
$selectedDate = $defaultDate;
if (isset($_POST['date'])) {
    $selectedDate = $_POST['date'];
} elseif (isset($_GET['date']) && in_array($_GET['date'], $distinctDates)) {
    $selectedDate = $_GET['date'];
}

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

// Handle POST requests for cash in/out and delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Check if this is a cash-out and validate against available balance
        if ($_POST['action'] === 'cash-out') {
            // Calculate current cash in till using selected date business day logic
            $nextBusinessDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
            
            // Get selected date's cash in transactions
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
            
            // Get selected date's cash sales (excluding EFT payments if applicable)
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
            
            // Get selected date's credit payments using payments table (like reports.php)
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
            
            // Get selected date's cash out
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
            
            // Calculate cash in till for selected date's business day
            $cashInTill = $totalCashIn + $totalCashSales + $totalCreditPayments - $totalCashOut;
            
            // If withdrawal amount exceeds balance, return error
            if (floatval($_POST['amount']) > floatval($cashInTill)) {
                if(isset($_POST['ajax'])) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot withdraw more than the available balance (N$' . number_format($cashInTill, 2) . ')']);
                    exit;
                }
                // Redirect back with error message if not ajax
                header('Location: cash.php?error=insufficient_funds&available=' . $cashInTill . '&date=' . urlencode($selectedDate));
                exit;
            }
        }
        
        // Use selected date for created_at so the transaction is saved on the chosen date
        $transactionDate = isset($_POST['date']) ? $_POST['date'] : $defaultDate;
        $currentTime = $db->query("SELECT strftime('%H:%M:%S', 'now', '+2 hours')")->fetchColumn();
        $createdAt = $transactionDate . ' ' . $currentTime;
        
        $stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['action'], $_POST['amount'], $_POST['description'], $_SESSION['username'] ?? 'Unknown', $createdAt]);
        
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
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">

    <style>
        .sidebar {
            position: fixed;
            height: 100%;
            z-index: 10000 !important; /* Prevent overlay from overlapping sidebar */
        }
        #sidebar {
            z-index: 10000 !important; /* Ensure sidebar stays above overlay */
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
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000 !important; /* Highest - always accessible */
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
        
        /* Open state - transforms into X */
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
            z-index: 80 !important; /* MUST be below sidebar (9999) and hamburger (10000) */
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
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
            
            .container {
                padding: 1rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            
            /* Fixed header on mobile */
            .sticky.top-0 {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 50 !important; /* Lower than sidebar/overlay */
                background-color: rgb(249 250 251) !important;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                padding-left: 1.5rem !important;
                padding-right: 1.5rem !important;
            }
            
            /* Add padding to content to account for fixed header */
            .container.mx-auto.p-6 {
                padding-top: calc(1.5rem + 100px) !important;
            }
        }
        
        /* Mobile Vertical Table Structure */
        @media (max-width: 768px) {
            /* Remove overflow-x-auto on mobile */
            .overflow-x-auto {
                overflow-x: visible !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            /* Ensure table containers don't overflow */
            .bg-white.rounded-lg,
            .table-container {
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
            
            /* Convert table cells to flex containers */
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
            
            /* Remove border from last cell */
            table tbody td:last-child {
                border-bottom: none !important;
            }
            
            /* Add labels using data-label attribute */
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
            
            /* Hide label if data-label is empty */
            table tbody td[data-label=""]::before {
                display: none;
            }
            
            /* Special handling for action columns */
            table tbody td[data-label="Actions"] {
                justify-content: center;
                padding: 0.5rem !important;
            }
            
            table tbody td[data-label="Actions"]::before {
                display: none; /* Hide label for Actions column */
            }
            
            /* Actions column buttons - wrap and stack */
            table tbody td[data-label="Actions"] > div,
            table tbody td[data-label="Actions"] > button {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                justify-content: center;
                align-items: center;
                width: 100%;
            }
            
            /* Remove hover effect on mobile cards */
            table tbody tr:hover {
                background: white;
            }
        }
        
        /* Mobile Pagination - Fit in one row */
        @media (max-width: 768px) {
            .bg-gray-50.border-t {
                padding: 0.5rem 0.375rem !important;
                overflow-x: visible !important;
            }
            
            .bg-gray-50.border-t > div {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                align-items: center !important;
                width: 100% !important;
                min-width: 0 !important;
                overflow: visible !important;
            }
            
            /* Ensure parent containers don't restrict pagination */
            .bg-white.shadow-lg {
                overflow-x: visible !important;
            }
            
            /* Compact button groups */
            .bg-gray-50.border-t > div > div {
                display: flex !important;
                gap: 0.25rem !important;
                flex-shrink: 0;
            }
            
            /* First/Last buttons - icon only, smaller */
            .bg-gray-50.border-t button#firstPage,
            .bg-gray-50.border-t button#lastPage {
                padding: 0.375rem !important;
                min-width: 2rem !important;
                width: 2rem !important;
            }
            
            .bg-gray-50.border-t button#firstPage svg,
            .bg-gray-50.border-t button#lastPage svg {
                width: 1rem !important;
                height: 1rem !important;
                margin: 0 !important;
            }
            
            /* Prev/Next buttons - compact text */
            .bg-gray-50.border-t button#prevPage,
            .bg-gray-50.border-t button#nextPage {
                padding: 0.375rem 0.4rem !important;
                font-size: 0.65rem !important;
                min-width: auto !important;
                white-space: nowrap;
            }
            
            .bg-gray-50.border-t button#prevPage svg,
            .bg-gray-50.border-t button#nextPage svg {
                width: 0.875rem !important;
                height: 0.875rem !important;
            }
            
            /* Center section - compact and flexible */
            .bg-gray-50.border-t > div > div:nth-child(2) {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                flex-shrink: 1;
                min-width: 0;
                max-width: 100%;
                overflow: hidden;
            }
            
            /* Page number text - smaller and compact */
            .bg-gray-50.border-t span[id*="PageNumber"],
            .bg-gray-50.border-t span[id*="pageNumber"] {
                font-size: 0.65rem !important;
                white-space: nowrap;
                flex-shrink: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 5rem;
            }
            
            /* Page input - compact */
            .bg-gray-50.border-t input[type="number"] {
                width: 2.5rem !important;
                padding: 0.375rem 0.375rem !important;
                font-size: 0.65rem !important;
                min-width: 2.5rem;
                max-width: 2.5rem;
            }
            
            /* Go button - compact */
            .bg-gray-50.border-t input[type="number"] + button {
                padding: 0.375rem 0.5rem !important;
                font-size: 0.65rem !important;
                white-space: nowrap;
            }
            
            /* All pagination buttons - consistent height */
            .bg-gray-50.border-t button {
                height: 2rem !important;
                min-height: 2rem !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <!-- Confirmation Modal -->
        <div id="confirmationModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
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
        
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <!-- Alert Notification Container -->
                <div id="alertContainer" class="fixed top-4 right-4 z-[100] w-80 transform transition-transform duration-300 translate-x-full"></div>
                
                <?php if (isset($_GET['error']) && $_GET['error'] === 'insufficient_funds'): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-md" role="alert">
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
                
                <!-- Header Row: Title + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <a href="manager-center" class="inline-flex items-center px-3 py-2 sm:px-4 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors text-sm flex-shrink-0">
                            <svg class="w-5 h-5 mr-1.5 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            <span class="hidden sm:inline">back</span>
                        </a>
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Cash Management</h1>
                    </div>
                    <!-- Right Side Controls -->
                    <div class="flex items-center gap-4">
                        <form method="POST" action="" class="flex items-center gap-2" id="dateForm">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <!-- calendar icon -->
                                    <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 0 0 -2H6z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <select id="date" name="date" onchange="updateCashReport();" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block pl-10 pr-10 p-2.5 shadow-sm transition-colors cursor-pointer">
                                    <?php foreach ($distinctDates as $date): ?>
                                        <option value="<?= htmlspecialchars($date) ?>" <?= $date == $selectedDate ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($date) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                        <button id="openCashDrawer" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors duration-200">
                            <svg class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                            </svg>
                            Open Cash Drawer
                        </button>
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
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- Cash In Till Card -->
                    <div class="bg-white shadow-lg rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Cash Available in Till</h3>
                            <div class="p-2 bg-blue-100 rounded-full">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold <?= $cashInTill >= 0 ? 'text-blue-600' : 'text-red-600' ?>">
                            N$<?= number_format($cashInTill, 2) ?>
                        </p>
                        <p class="text-sm text-gray-500 mt-2">Current cash balance in the till</p>
                    </div>
                    
 
                    
                    <!-- Selected Date's Withdrawals Card -->
                    <div class="bg-white shadow-lg rounded-lg p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Selected Date's Withdrawals</h3>
                            <div class="p-2 bg-red-100 rounded-full">
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                </svg>
                            </div>
                        </div>
                        <p class="text-2xl font-bold text-red-600">N$<?= number_format($selectedDateTotalWithdrawals, 2) ?></p>
                        <p class="text-sm text-gray-500 mt-2">Total cash withdrawn on selected date</p>
                    </div>
                </div>

             
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- Cash In Form -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-teal-500 to-teal-500 py-4 px-6">
                            <h3 class="text-white text-lg font-bold flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Cash Deposit
                            </h3>
                        </div>
                        <form id="cashInForm" method="POST" class="p-6">
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
                        <div class="bg-gradient-to-r from-rose-500 to-red-500 py-4 px-6">
                            <h3 class="text-white text-lg font-bold flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                </svg>
                                Cash Withdrawal
                            </h3>
                        </div>
                        <form id="cashOutForm" method="POST" class="p-6">
                            <input type="hidden" name="action" value="cash-out">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                            
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
                <div class="bg-white shadow-lg rounded-lg mb-8 overflow-hidden">
                    <div class="p-4 py-2 bg-gray-200 flex justify-between items-center">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <svg class="w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z"/>
                            </svg>
                            Daily Cash Transactions (<?= htmlspecialchars($selectedDate) ?>)
                        </h2>
                        <div class="relative max-w-72">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>
                                </svg>
                            </div>
                            <input type="text" id="transactionSearch" onkeyup="filterTransactions()" placeholder="Search transactions..." 
                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none focus:border-blue-500 shadow-sm transition duration-200">
                        </div>
                    </div>
                    <div class="overflow-x-auto">
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
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="transactionsTableBody">
                                <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td data-label="ID" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= $transaction['id'] ?>
                                    </td>
                                    <td data-label="Type" class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $transaction['type'] === 'cash-in' ? 'bg-teal-100 text-teal-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= ucfirst(str_replace('-', ' ', $transaction['type'])) ?>
                                        </span>
                                    </td>
                                    <td data-label="Amount" class="px-6 py-4 whitespace-nowrap text-sm 
                                        <?= $transaction['type'] === 'cash-in' ? 'text-teal-600 font-medium' : 'text-red-600 font-medium' ?>">
                                        N$<?= number_format($transaction['amount'], 2) ?>
                                    </td>
                                    <td data-label="Description" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($transaction['description']) ?>
                                    </td>
                                    <td data-label="Date" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('Y-m-d H:i', strtotime($transaction['created_at'])) ?>
                                    </td>
                                    <td data-label="Actions" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <button class="delete-transaction inline-flex items-center text-xs font-medium focus:outline-none transition-colors duration-200" 
                                                data-id="<?= $transaction['id'] ?>" 
                                                onclick="deleteRecord('cash_transaction', <?= $transaction['id'] ?>)" 
                                                title="Delete Transaction">
                                            <svg class="w-5 h-5 text-red-600 hover:text-red-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                                <?php if (count($transactions) === 0): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                        No transactions found for selected date
                                </td>
                            </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                </div>
                
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
                                    <button id="goToPage" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 transition-colors shadow-sm">Go</button>
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


                <!-- Cash In/Out Forms -->

            </div>
        </div>
    </div>

    <script>
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
                
                // Only check text content columns (skip the actions column)
                Array.from(cells).slice(0, -1).forEach(cell => {
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
            // Function to open cash drawer
            function openCashDrawer() {
                return $.ajax({
                    url: 'open_drawer.php',
                    method: 'GET',
                    dataType: 'json'
                });
            }
            
            // Replace the previous form handlers with the new ones
            function handleFormSubmitWithDrawer(formId) {
                $(formId).on('submit', function(e) {
                    e.preventDefault();
                    
                    // For cash-out form, check if amount exceeds available balance
                    if (formId === '#cashOutForm') {
                        const withdrawalAmount = parseFloat($('#cash-out-amount').val());
                        const availableBalance = <?= $cashInTill ?>; // Use the calculated $cashInTill value
                        
                        if (withdrawalAmount > availableBalance) {
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
                                // Reload the page after both operations
                                setTimeout(function() {
                                    location.reload();
                                }, 2000); // Increased to give user time to see the success message
                            });
                        },
                        error: function(xhr) {
                            let errorMessage = 'An error occurred while processing your request.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                errorMessage = xhr.responseJSON.message;
                            }
                            showAlert(errorMessage, 'error');
                        }
                    });
                });
            }

            // Replace the previous form handlers with the new ones
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

            // Handle delete button clicks (use event delegation for dynamically added buttons)
            $(document).on('click', '.delete-transaction', function() {
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
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
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
    </script>
</div>
</body>
</html>
