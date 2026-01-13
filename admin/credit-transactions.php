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

$db = new PDO('sqlite:../pos.db');
$creditorId = $_GET['creditor_id'] ?? 0;

// Handle "Pay All" functionality
if (isset($_GET['pay_all']) && $_GET['pay_all'] == 1) {
    // Get all unpaid transactions for this creditor
    $unpaidTxns = $db->prepare("
        SELECT id, (total_amount - paid_amount) AS remaining_amount 
        FROM credit_sales 
        WHERE creditor_id = ? AND payment_status != 'paid'
    ");
    $unpaidTxns->execute([$creditorId]);
    
    // Process payment for each transaction
    $db->beginTransaction();
    try {
        // Check if this is an EFT payment
        $isEft = isset($_GET['eft']) && $_GET['eft'] == 1;
        $transactionRef = $_GET['transaction_ref'] ?? '';
        $walletProvider = $_GET['wallet_provider'] ?? '';
        
        while ($txn = $unpaidTxns->fetch(PDO::FETCH_ASSOC)) {
            if ($txn['remaining_amount'] > 0) {
                // Update credit sale record
                $updateStmt = $db->prepare("
                    UPDATE credit_sales 
                    SET paid_amount = total_amount, 
                        payment_status = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$isEft ? 'eft' : 'paid', $txn['id']]);
                
                // Record payment with timezone-aware timestamp
                $paymentStmt = $db->prepare("
                    INSERT INTO payments (sale_id, amount, payment_date) 
                    VALUES (?, ?, ?)
                ");
                $paymentStmt->execute([$txn['id'], $txn['remaining_amount'], date('Y-m-d H:i:s')]);
                
                // If EFT payment, also record in eft_payments table
                if ($isEft && !empty($transactionRef) && !empty($walletProvider)) {
                    $eftStmt = $db->prepare("
                        INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $eftStmt->execute([$txn['id'], $transactionRef, $walletProvider, $txn['remaining_amount'], date('Y-m-d H:i:s')]);
                }
            }
        }
        $db->commit();
        
        // Set success message and store payment type
        $_SESSION['payment_success'] = 'All outstanding balances have been paid successfully';
        $_SESSION['payment_type'] = $isEft ? 'eft' : 'cash';
        $_SESSION['auto_open_drawer'] = !$isEft; // Open drawer only for cash payments
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['payment_error'] = 'Failed to process payments: ' . $e->getMessage();
    }
    
    // Store auto_print in session if it was passed
    if (isset($_GET['auto_print'])) {
        $_SESSION['auto_print'] = true;
    }
    
    // Redirect to remove the pay_all parameter
    header("Location: credit-transactions.php?creditor_id=" . $creditorId);
    exit();
}

// Handle payment submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
    // Handle cash payment
    if (isset($_POST['payment_amount'])) {
        $saleId = $_POST['sale_id'];
        $paymentAmount = (float)$_POST['payment_amount'];
        
        // Get sale details for receipt
        $saleDetails = $db->prepare("
            SELECT cs.*, c.name as creditor_name, 
                   GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') AS items
            FROM credit_sales cs
            JOIN creditors c ON cs.creditor_id = c.id
            LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
            WHERE cs.id = ?
            GROUP BY cs.id
        ");
        $saleDetails->execute([$saleId]);
        $sale = $saleDetails->fetch(PDO::FETCH_ASSOC);
        
        // Prepare items array
        $items = [];
        $saleItems = $db->prepare("SELECT product_name, quantity, price FROM credit_sale_items WHERE sale_id = ?");
        $saleItems->execute([$saleId]);
        while ($item = $saleItems->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] * $item['quantity']
            ];
        }

        // Update credit sale record
        $stmt = $db->prepare("UPDATE credit_sales 
                            SET paid_amount = paid_amount + ?, 
                                payment_status = CASE WHEN (paid_amount + ?) >= total_amount THEN 'paid' ELSE 'partial' END
                            WHERE id = ?");
        $stmt->execute([$paymentAmount, $paymentAmount, $saleId]);
        
        // Record payment with timezone-aware timestamp
        $stmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)");
        $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s')]);

        // Prepare receipt data
        $receiptData = [
            'creditor_id' => $sale['creditor_id'],
            'creditor_name' => $sale['creditor_name'],
            'sale_id' => $saleId,
            'items' => $items,
            'total_amount' => $sale['total_amount'],
            'cash_received' => $paymentAmount,
            'payment_type' => 'cash',
            'remaining_balance' => $sale['total_amount'] - ($sale['paid_amount'] + $paymentAmount),
            'date' => date('Y-m-d H:i:s')
        ];

        // Return JSON with receipt data
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'receipt_data' => $receiptData
        ]);
        exit();
    }
    
    // Handle EFT payment
    if (isset($_POST['eft_payment_amount'])) {
        $saleId = $_POST['sale_id'];
        $paymentAmount = (float)$_POST['eft_payment_amount'];
        $transactionRef = $_POST['transaction_ref'];
        $walletProvider = $_POST['wallet_provider'];
        
        // Get sale details for receipt
        $saleDetails = $db->prepare("
            SELECT cs.*, c.name as creditor_name, 
                   GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') AS items
            FROM credit_sales cs
            JOIN creditors c ON cs.creditor_id = c.id
            LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
            WHERE cs.id = ?
            GROUP BY cs.id
        ");
        $saleDetails->execute([$saleId]);
        $sale = $saleDetails->fetch(PDO::FETCH_ASSOC);
        
        // Update credit sale record
        $stmt = $db->prepare("UPDATE credit_sales 
                            SET paid_amount = paid_amount + ?, 
                                payment_status = CASE WHEN (paid_amount + ?) >= total_amount THEN 'eft' ELSE 'partial' END
                            WHERE id = ?");
        $stmt->execute([$paymentAmount, $paymentAmount, $saleId]);
        
        // Record payment with timezone-aware timestamp
        $stmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)");
        $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s')]);
        
        // Record EFT payment details with timezone-aware timestamp
        $stmt = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$saleId, $transactionRef, $walletProvider, $paymentAmount, date('Y-m-d H:i:s')]);

        // Prepare items array
        $items = [];
        $saleItems = $db->prepare("SELECT product_name, quantity, price FROM credit_sale_items WHERE sale_id = ?");
        $saleItems->execute([$saleId]);
        while ($item = $saleItems->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] * $item['quantity']
            ];
        }

        // Prepare receipt data
        $receiptData = [
            'creditor_id' => $sale['creditor_id'],
            'creditor_name' => $sale['creditor_name'],
            'sale_id' => $saleId,
            'items' => $items,
            'total_amount' => $sale['total_amount'],
            'payment_method' => 'e-wallet',
            'wallet_provider' => $walletProvider,
            'transaction_ref' => $transactionRef,
            'payment_amount' => $paymentAmount,
            'remaining_balance' => $sale['total_amount'] - ($sale['paid_amount'] + $paymentAmount),
            'date' => date('Y-m-d H:i:s')
        ];

        // Return JSON with receipt data
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'receipt_data' => $receiptData
        ]);
        exit();
    }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Failed to process payment: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Get creditor details
$creditor = $db->query("SELECT * FROM creditors WHERE id = $creditorId")->fetch(PDO::FETCH_ASSOC);
if (!$creditor) {
    header('Location: credit-book.php');
    exit();
}

// Get all transactions for this creditor
$transactions = $db->query("
    SELECT cs.*, 
           (cs.total_amount - cs.paid_amount) AS balance,
           GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') AS items,
           (SELECT SUM(amount) FROM payments WHERE sale_id = cs.id) AS total_paid
    FROM credit_sales cs
    LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
    WHERE cs.creditor_id = $creditorId
    GROUP BY cs.id
    ORDER BY cs.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get all partial payments for this creditor
$partialPayments = $db->query("
    SELECT p.*, cs.creditor_id, cs.total_amount, cs.paid_amount, cs.payment_status, 
           (SELECT GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') 
            FROM credit_sale_items csi WHERE csi.sale_id = cs.id) AS items
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.creditor_id = $creditorId AND cs.payment_status = 'partial'
    ORDER BY p.payment_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get payment history
$payments = $db->query("
    SELECT p.*, cs.creditor_id 
    FROM payments p
    JOIN credit_sales cs ON p.sale_id = cs.id
    WHERE cs.creditor_id = $creditorId
    ORDER BY p.payment_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get EFT payment history
$eftPayments = $db->query("
    SELECT e.*, cs.creditor_id, cs.id as credit_sale_id
    FROM eft_payments e
    JOIN credit_sales cs ON e.order_id = cs.id
    WHERE cs.creditor_id = $creditorId
    ORDER BY e.payment_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get wallet providers for EFT dropdown
$walletProviders = ['Account(Swipe)', 'E-wallet', 'BlueWallet', 'PayPulse', 'Bank Transfer', 'Standard Bank', 'First National Bank', 'Bank Windhoek', 'Nedbank'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($creditor['name']) ?> Transactions</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <script src="../lucide.js"></script>
    <style>
        .sidebar { position: fixed; height: 100%; }
        .content { margin-left: 250px; }
        .fade-in { animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .payment-progress { height: 8px; }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; }
        
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
                z-index: 1 !important;
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
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000;
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
<body class="bg-gray-50">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <?php if (isset($_SESSION['payment_success'])): ?>
                <div class="bg-teal-100 border-l-4 border-teal-500 text-teal-700 p-4 mb-4 rounded shadow z-20" role="alert">
                    <p><?= $_SESSION['payment_success'] ?></p>
                </div>
                <?php unset($_SESSION['payment_success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['payment_error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded shadow z-20" role="alert">
                    <p><?= $_SESSION['payment_error'] ?></p>
                </div>
                <?php unset($_SESSION['payment_error']); ?>
                <?php endif; ?>

                <!-- Header Row: Title + Go Back Button -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div>
                            <h1 class="text-xl lg:text-2xl font-semibold text-gray-900">
                                <?= htmlspecialchars($creditor['name']) ?>'s Credit History
                            </h1>
                            <p class="text-xs lg:text-sm text-gray-500 mt-1">
                                Last activity: <?= !empty($transactions) ? date('M d, Y', strtotime(end($transactions)['created_at'])) : 'N/A' ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Go Back Button -->
                    <a href="credit-book" class="inline-flex items-center px-3 lg:px-4 py-2 border border-gray-300 rounded-md shadow-sm text-xs lg:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                        <svg class="w-4 h-4 lg:w-5 lg:h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        <span class="hidden sm:inline">Go Back</span>
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="p-3 bg-blue-100 rounded-lg">
                                <span class="text-blue-600">💰</span>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Balance</p>
                                <p class="text-xl font-semibold">N$<?= number_format(array_sum(array_column($transactions, 'balance')), 2) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                        <button onclick="printTotalBalanceReceipt()" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                            </svg>
                            Print Total Balance Receipt
                        </button>
                    </div>
                </div>

                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:divide-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                        <div class="relative max-w-xs w-full md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="hs-table-with-pagination-search" class="py-2 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-slate-900 dark:border-gray-700 dark:text-gray-400 dark:focus:ring-gray-600" placeholder="Search for transactions">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Table -->
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(0)">
                                                    Date <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(1)">
                                                    Due Date <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Items</th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(2)">
                                                    Total <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">Progress</th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="transactionsTableBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php if (empty($transactions)): ?>
                                                <tr>
                                                    <td colspan="6" class="px-6 py-12 text-center">
                                                        <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No transactions found.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($transactions as $transaction): 
                                                    $progress = ($transaction['paid_amount'] / $transaction['total_amount']) * 100;
                                                ?>
                                                <tr class="transaction-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" 
                                                    data-date="<?= strtolower(date('Y-m-d', strtotime($transaction['created_at']))) ?>"
                                                    data-due-date="<?= strtolower(date('Y-m-d', strtotime($transaction['due_date']))) ?>"
                                                    data-total="<?= $transaction['total_amount'] ?>">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= date('d M Y', strtotime($transaction['created_at'])) ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?= (strtotime($transaction['due_date']) < time()) ? 'text-red-600' : 'text-gray-800 dark:text-gray-200' ?>" data-label="Due Date">
                                                        <?= date('d M Y', strtotime($transaction['due_date'])) ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-200 max-w-[300px]">
                                                        <span class="truncate block" title="<?= htmlspecialchars($transaction['items']) ?>">
                                                            <?= htmlspecialchars($transaction['items']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200">
                                                        N$<?= number_format(
                                                            ($transaction['payment_status'] === 'partial') 
                                                                ? ($transaction['total_amount'] - $transaction['paid_amount']) 
                                                                : $transaction['total_amount'], 
                                                            2
                                                        ) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                            <?php
                                                                if ($transaction['payment_status'] === 'paid') {
                                                                    echo 'bg-green-100 text-green-800';
                                                                } elseif ($transaction['payment_status'] === 'eft') {
                                                                    echo 'bg-blue-100 text-blue-800';
                                                                } elseif ($transaction['payment_status'] === 'partial') {
                                                                    echo 'bg-yellow-100 text-yellow-800';
                                                                } else {
                                                                    echo 'bg-red-100 text-red-800';
                                                                }
                                                            ?>">
                                                            <?php 
                                                                if ($transaction['payment_status'] === 'paid') {
                                                                    echo 'Cash';
                                                                } elseif ($transaction['payment_status'] === 'eft') {
                                                                    echo 'EFT';
                                                                } elseif ($transaction['payment_status'] === 'partial') {
                                                                    echo 'Partial';
                                                                } else {
                                                                    echo 'Unpaid';
                                                                }
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                                                        <?php if ($transaction['payment_status'] !== 'paid' && $transaction['payment_status'] !== 'eft'): ?>
                                                            <div class="flex items-center justify-end gap-2">
                                                                <button type="button" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-green-600 hover:text-green-800 disabled:opacity-50 disabled:pointer-events-none dark:text-green-500 dark:hover:text-green-400 cash-payment-btn" 
                                                                    data-sale-id="<?= $transaction['id'] ?>"
                                                                    data-balance="<?= $transaction['total_amount'] - $transaction['paid_amount'] ?>"
                                                                    title="Cash Payment">
                                                                    <i data-lucide="dollar-sign" class="w-4 h-4"></i>
                                                                </button>
                                                                <button type="button" class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-purple-600 hover:text-purple-800 disabled:opacity-50 disabled:pointer-events-none dark:text-purple-500 dark:hover:text-purple-400 eft-payment-btn"
                                                                    data-sale-id="<?= $transaction['id'] ?>"
                                                                    data-balance="<?= $transaction['total_amount'] - $transaction['paid_amount'] ?>"
                                                                    title="EFT Payment">
                                                                    <i data-lucide="credit-card" class="w-4 h-4"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="flex items-center justify-end">
                                                                <i data-lucide="check-circle" class="w-5 h-5 text-green-500"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>

                                                <?php foreach ($partialPayments as $payment): ?>
                                                <tr class="transaction-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200">N/A</td>
                                                    <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-200 max-w-[300px]">
                                                        <span class="truncate block" title="<?= htmlspecialchars($payment['items']) ?>">
                                                            <?= htmlspecialchars($payment['items']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200">N$<?= number_format($payment['amount'], 2) ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                            Partial Payment
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                                                        <div class="flex items-center justify-end">
                                                            <i data-lucide="check-circle" class="w-5 h-5 text-green-500"></i>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <div class="py-1 px-4">
                                    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                                        <div class="text-sm text-gray-700 dark:text-gray-300">
                                            Showing <span id="showingFrom">1</span> to <span id="showingTo"><?= min(10, count($transactions) + count($partialPayments)) ?></span> of <span id="totalRows"><?= count($transactions) + count($partialPayments) ?></span> entries
                                        </div>
                                        <nav class="flex items-center space-x-1" id="paginationNav">
                                            <!-- Pagination buttons will be generated by JavaScript -->
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EFT Payment History Section -->
                <?php if (!empty($eftPayments)): ?>
                <div class="mt-8">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-2 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                            EFT Payment History
                        </div>
                    </h2>
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Wallet Provider</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eftPayments as $payment): ?>
                                <tr class="border-t border-gray-100 hover:bg-gray-50">
                                    <td class="p-4 text-sm" data-label="Date"><?= date('d M Y H:i', strtotime($payment['payment_date'])) ?></td>
                                    <td class="p-4 text-sm font-medium text-teal-600" data-label="Amount">N$<?= number_format($payment['amount'], 2) ?></td>
                                    <td class="p-4 text-sm" data-label="Wallet Provider"><?= htmlspecialchars($payment['wallet_provider']) ?></td>
                                    <td class="p-4 text-sm" data-label="Reference"><?= htmlspecialchars($payment['transaction_ref']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Hidden forms for submissions -->
    <form id="cashPaymentForm" method="POST" style="display:none;">
        <input type="hidden" name="sale_id" id="cash_sale_id">
        <input type="hidden" name="payment_amount" id="cash_payment_amount">
    </form>
    
    <form id="eftPaymentForm" method="POST" style="display:none;">
        <input type="hidden" name="sale_id" id="eft_sale_id">
        <input type="hidden" name="eft_payment_amount" id="eft_payment_amount">
        <input type="hidden" name="transaction_ref" id="eft_transaction_ref">
        <input type="hidden" name="wallet_provider" id="eft_wallet_provider">
    </form>

    <?php
    // Fetch business info for Android printing
    $dbInfo = new PDO('sqlite:../info.db');
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
        
        return fetch('../receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(dataWithBusiness)
        }).then(function(r) { return r.json(); });
    }

    // Function to open cash drawer
    function openCashDrawer() {
        const drawerData = {
            open_drawer_only: true,
            cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
        };

        console.log('Opening cash drawer');

        sendToPrinter(drawerData)
        .then(result => {
            console.log('Cash drawer response:', result);
            if (result.success) {
                console.log('Cash drawer opened successfully');
            } else {
                console.error('Cash drawer failed:', result.message);
            }
        })
        .catch(err => console.error('Drawer opening error:', err));
    }
    
    // Check if we need to open drawer after Pay All cash payment
    $(document).ready(function() {
        <?php 
        // Check if we just processed a cash Pay All payment
        $shouldOpenDrawer = isset($_SESSION['auto_open_drawer']) && $_SESSION['auto_open_drawer'] === true;
        if ($shouldOpenDrawer) {
            // Clear session flags so they don't trigger again
            unset($_SESSION['auto_open_drawer']);
            unset($_SESSION['payment_type']);
            unset($_SESSION['auto_print']);
            ?>
            // Open cash drawer after successful Pay All cash payment
            openCashDrawer();
            <?php
        }
        ?>
    });
    
    // Handle cash payment
    $(document).ready(function() {
        $('.cash-payment-btn').on('click', function() {
            const saleId = $(this).data('sale-id');
            const balance = $(this).data('balance');
            
            Swal.fire({
                title: 'Cash Payment',
                html: `<div class="text-center">
                        <div class="mb-4">
                            <svg class="w-12 h-12 text-teal-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <p class="text-lg font-semibold text-gray-800 mb-4">Remaining Balance: N$${parseFloat(balance).toFixed(2)}</p>
                        <div class="flex flex-col">
                            <label class="text-left text-sm font-medium text-gray-700 mb-1">Payment Amount</label>
                            <input id="paymentAmountInput" type="number" step="0.01" min="0.01" max="${parseFloat(balance)}" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                placeholder="Enter amount to pay" value="${parseFloat(balance).toFixed(2)}">
                        </div>
                       </div>`,
                showCancelButton: true,
                confirmButtonText: 'Process Payment',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#10B981',
                cancelButtonColor: '#6B7280',
                customClass: {
                    confirmButton: 'hover:bg-blue-700 px-4 py-2 rounded-lg',
                    cancelButton: 'hover:bg-gray-200 px-4 py-2 rounded-lg'
                },
                reverseButtons: true,
                showClass: {
                    popup: 'animate__animated animate__fadeInDown animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOutUp animate__faster'
                },
                preConfirm: () => {
                    const amount = document.getElementById('paymentAmountInput').value;
                    if (!amount || amount <= 0 || amount > parseFloat(balance)) {
                        Swal.showValidationMessage('Please enter a valid amount between 0.01 and ' + parseFloat(balance).toFixed(2));
                        return false;
                    }
                    return amount;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#cash_sale_id').val(saleId);
                    $('#cash_payment_amount').val(result.value);
                    
                    // Submit form and handle receipt
                    $.ajax({
                        url: 'credit-transactions.php',
                        method: 'POST',
                        data: $('#cashPaymentForm').serialize(),
                        success: function(response) {
                            let data;
                            try {
                                data = typeof response === 'string' ? JSON.parse(response) : response;
                            } catch (e) {
                                Swal.fire({ icon: 'error', title: 'Error', text: 'Invalid server response.' });
                                return;
                            }
                            if (data.success && data.receipt_data) {
                                // Open cash drawer after successful cash payment
                                openCashDrawer();
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Payment Successful!',
                                    html: '',
                                    footer: `
                                        <div style="display: flex; align-items: center; justify-content: center; width: 100%;">
                                            <div style="display: flex; align-items: center; padding-right: 0;">
                                                <a href='#' onclick='return reverseTransaction(event)' style='display: flex; align-items: center; color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em;'><i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction</a>
                                            </div>
                                            <div style="height: 32px; width: 1px; background: #e5e7eb; margin: 0 18px 0 18px; position: relative;"></div>
                                            <div style="display: flex; align-items: center; padding-left: 18px;">
                                                <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;'>
                                                <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                                            </div>
                                        </div>
                                    `,
                                    confirmButtonText: 'OK',
                                    allowOutsideClick: false,
                                    focusConfirm: false
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                                        if (printReceipt) {
                                            $.ajax({
                                                url: '../receipt.php',
                                                method: 'POST',
                                                contentType: 'application/json',
                                                data: JSON.stringify(data.receipt_data),
                                                success: function(printResp) {
                                                    Swal.fire({ icon: 'success', title: 'Receipt Printed', text: 'Payment and receipt successful.' });
                                                    window.location.reload();
                                                },
                                                error: function() {
                                                    Swal.fire({ icon: 'error', title: 'Printing Failed', text: 'Payment succeeded, but receipt printing failed.' });
                                                    window.location.reload();
                                                }
                                            });
                                        } else {
                                            window.location.reload();
                                        }
                                    }
                                });
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: 'Payment succeeded, but no receipt data returned.' });
                                window.location.reload();
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to process payment. Please try again.',
                                confirmButtonColor: '#3B82F6',
                            });
                        }
                    });
                }
            });
        });
        
        // Handle EFT payment
        $('.eft-payment-btn').on('click', function() {
            const saleId = $(this).data('sale-id');
            const balance = $(this).data('balance');
            
            // Create wallet provider options
            let walletOptions = '';
            <?php foreach ($walletProviders as $provider): ?>
                walletOptions += `<option value="<?= $provider ?>"><?= $provider ?></option>`;
            <?php endforeach; ?>
            
            Swal.fire({
                title: 'EFT Payment',
                html: `<div class="text-center mb-4">
                        <div class="bg-teal-100 text-teal-600 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                        </div>
                        <p class="text-lg font-semibold text-gray-800 mb-4">N$${parseFloat(balance).toFixed(2)}</p>
                      </div>
                      <div class="space-y-4">
                        <div class="flex flex-col">
                          <label class="text-left text-sm font-medium text-gray-700 mb-1">Wallet Provider</label>
                          <select id="walletProviderInput" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            ${walletOptions}
                          </select>
                        </div>
                        <div class="flex flex-col">
                          <label class="text-left text-sm font-medium text-gray-700 mb-1">Transaction Reference <span class='text-gray-400'>(optional)</span></label>
                          <input id="transactionRefInput" type="text" placeholder="Enter reference (optional)" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div class="flex flex-col">
                          <label class="text-left text-sm font-medium text-gray-700 mb-1">Payment Amount</label>
                          <input id="eftPaymentAmountInput" type="number" step="0.01" min="0.01" max="${parseFloat(balance)}" value="${parseFloat(balance).toFixed(2)}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                            placeholder="Enter amount to pay">
                        </div>
                      </div>`,
                showCancelButton: true,
                confirmButtonText: 'Process Payment',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#10B981',
                cancelButtonColor: '#6B7280',
                customClass: {
                    confirmButton: 'hover:bg-teal-700 px-4 py-2 rounded-lg',
                    cancelButton: 'hover:bg-gray-200 px-4 py-2 rounded-lg'
                },
                focusConfirm: false,
                preConfirm: () => {
                    const walletProvider = document.getElementById('walletProviderInput').value;
                    const transactionRef = document.getElementById('transactionRefInput').value;
                    const paymentAmount = document.getElementById('eftPaymentAmountInput').value;
                    if (!paymentAmount || paymentAmount <= 0 || paymentAmount > parseFloat(balance)) {
                        Swal.showValidationMessage('Please enter a valid amount between 0.01 and ' + parseFloat(balance).toFixed(2));
                        return false;
                    }
                    // transactionRef is now optional
                    return { walletProvider, transactionRef, paymentAmount };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#eft_sale_id').val(saleId);
                    $('#eft_payment_amount').val(result.value.paymentAmount);
                    $('#eft_transaction_ref').val(result.value.transactionRef);
                    $('#eft_wallet_provider').val(result.value.walletProvider);
                    
                    // Submit form and handle receipt
                    $.ajax({
                        url: 'credit-transactions.php',
                        method: 'POST',
                        data: $('#eftPaymentForm').serialize(),
                        success: function(response) {
                            let data;
                            try {
                                data = typeof response === 'string' ? JSON.parse(response) : response;
                            } catch (e) {
                                Swal.fire({ icon: 'error', title: 'Error', text: 'Invalid server response.' });
                                return;
                            }
                            if (data.success && data.receipt_data) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Payment Successful!',
                                    html: '',
                                    footer: `
                                        <div style="display: flex; align-items: center; justify-content: center; width: 100%;">
                                            <div style="display: flex; align-items: center; padding-right: 0;">
                                                <a href='#' onclick='return reverseTransaction(event)' style='display: flex; align-items: center; color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em;'><i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction</a>
                                            </div>
                                            <div style="height: 32px; width: 1px; background: #e5e7eb; margin: 0 18px 0 18px; position: relative;"></div>
                                            <div style="display: flex; align-items: center; padding-left: 18px;">
                                                <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;'>
                                                <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                                            </div>
                                        </div>
                                    `,
                                    confirmButtonText: 'OK',
                                    allowOutsideClick: false,
                                    focusConfirm: false
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                                        if (printReceipt) {
                                            $.ajax({
                                                url: '../receipt.php',
                                                method: 'POST',
                                                contentType: 'application/json',
                                                data: JSON.stringify(data.receipt_data),
                                                success: function(printResp) {
                                                    Swal.fire({ icon: 'success', title: 'Receipt Printed', text: 'Payment and receipt successful.' });
                                                    window.location.reload();
                                                },
                                                error: function() {
                                                    Swal.fire({ icon: 'error', title: 'Printing Failed', text: 'Payment succeeded, but receipt printing failed.' });
                                                    window.location.reload();
                                                }
                                            });
                                        } else {
                                            window.location.reload();
                                        }
                                    }
                                });
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: 'Payment succeeded, but no receipt data returned.' });
                                window.location.reload();
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to process payment. Please try again.',
                                confirmButtonColor: '#3B82F6',
                            });
                        }
                    });
                }
            });
        });
    });

    function deleteTransaction(saleId) {
        Swal.fire({
            title: 'Delete Transaction?',
            text: "This will also remove all associated payments!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Delete'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location = `delete-transaction.php?id=${saleId}`;
            }
        });
    }

    function printTotalBalanceReceipt() {
        const creditorId = <?= $creditorId ?>;
        const totalBalance = <?= array_sum(array_column($transactions, 'balance')) ?>;
        
        // Get all unpaid transactions with their items
        const transactions = <?= json_encode($transactions) ?>;
        
        const receiptData = {
            creditor_id: creditorId,
            total_balance: totalBalance,
            creditor_name: "<?= htmlspecialchars($creditor['name']) ?>",
            is_balance_receipt: true,
            transactions: transactions.filter(t => t.balance > 0).map(t => ({
                items: t.items,
                balance: t.balance,
                date: t.created_at
            }))
        };

        $.ajax({
            url: '../receipt.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(receiptData),
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Receipt Printed',
                        text: 'The total balance receipt has been printed successfully.',
                        confirmButtonColor: '#3B82F6',
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Printing Failed',
                        text: response.message + (response.details ? '\n\n' + response.details : ''),
                        confirmButtonColor: '#3B82F6',
                    });
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = 'An error occurred while trying to print the receipt.';
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.message) {
                        errorMessage = response.message;
                    }
                    if (response.details) {
                        errorMessage += '\n\n' + response.details;
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                }
                
                Swal.fire({
                    icon: 'error',
                    title: 'Printing Failed',
                    text: errorMessage,
                    confirmButtonColor: '#3B82F6',
                });
            }
        });
    }

    function reverseTransaction(event) {
        if (event) event.preventDefault();
        if (typeof Swal !== 'undefined') {
            Swal.close();
        }
        return false;
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
</body>
</html>
<?php $db = null; ?> 