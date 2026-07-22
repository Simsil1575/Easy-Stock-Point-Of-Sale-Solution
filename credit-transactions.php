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

$pdo = new PDO('sqlite:active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

$db = new PDO('sqlite:pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once __DIR__ . '/inc/credit_sale_payment_status.php';
$creditorId = (int)($_GET['creditor_id'] ?? 0);

// Date filter: 'all' = all days, else specific date (default today)
$dateParam = isset($_GET['date']) ? trim($_GET['date']) : '';
if (strtolower($dateParam) === 'all') {
    $filterDate = 'all';
    $isAllDays = true;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $filterDate = $dateParam;
    $isAllDays = false;
} else {
    $filterDate = date('Y-m-d');
    $isAllDays = false;
}

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
                    INSERT INTO payments (sale_id, amount, payment_date, cashier_id) 
                    VALUES (?, ?, ?, ?)
                ");
                $paymentStmt->execute([$txn['id'], $txn['remaining_amount'], date('Y-m-d H:i:s'), $_SESSION['username'] ?? 'Unknown']);
                
                // If EFT payment, also record in eft_payments table
                if ($isEft && !empty($transactionRef) && !empty($walletProvider)) {
                    $eftStmt = $db->prepare("
                        INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $eftStmt->execute([$txn['id'], $transactionRef, $walletProvider, $txn['remaining_amount'], $_SESSION['username'] ?? 'Unknown', date('Y-m-d H:i:s')]);
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
    
    // Redirect to remove the pay_all parameter, keep date (including 'all')
    header("Location: credit-transactions.php?creditor_id=" . $creditorId . "&date=" . urlencode($filterDate));
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

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE credit_sales SET paid_amount = paid_amount + ? WHERE id = ?')->execute([$paymentAmount, $saleId]);
            $stmt = $db->prepare('INSERT INTO payments (sale_id, amount, payment_date, cashier_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s'), $_SESSION['username'] ?? 'Unknown']);
            $paymentStatus = resolve_credit_sale_payment_status($db, (int) $saleId);
            $db->prepare('UPDATE credit_sales SET payment_status = ? WHERE id = ?')->execute([$paymentStatus, $saleId]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

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
        
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE credit_sales SET paid_amount = paid_amount + ? WHERE id = ?')->execute([$paymentAmount, $saleId]);
            $stmt = $db->prepare('INSERT INTO payments (sale_id, amount, payment_date, cashier_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s'), $_SESSION['username'] ?? 'Unknown']);
            $stmt = $db->prepare('INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$saleId, $transactionRef, $walletProvider, $paymentAmount, $_SESSION['username'] ?? 'Unknown', date('Y-m-d H:i:s')]);
            $paymentStatus = resolve_credit_sale_payment_status($db, (int) $saleId);
            $db->prepare('UPDATE credit_sales SET payment_status = ? WHERE id = ?')->execute([$paymentStatus, $saleId]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

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
    header('Location: credit-book.php?date=' . urlencode($filterDate));
    exit();
}

// Get transactions for this creditor (by date or all days)
if ($isAllDays) {
    $transactionsStmt = $db->prepare("
        SELECT cs.*, 
               (cs.total_amount - cs.paid_amount) AS balance,
               GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') AS items,
               (SELECT SUM(amount) FROM payments WHERE sale_id = cs.id) AS total_paid
        FROM credit_sales cs
        LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
        WHERE cs.creditor_id = ?
        GROUP BY cs.id
        ORDER BY cs.created_at DESC
    ");
    $transactionsStmt->execute([$creditorId]);
} else {
    $transactionsStmt = $db->prepare("
        SELECT cs.*, 
               (cs.total_amount - cs.paid_amount) AS balance,
               GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') AS items,
               (SELECT SUM(amount) FROM payments WHERE sale_id = cs.id) AS total_paid
        FROM credit_sales cs
        LEFT JOIN credit_sale_items csi ON cs.id = csi.sale_id
        WHERE cs.creditor_id = ? AND DATE(cs.created_at) = ?
        GROUP BY cs.id
        ORDER BY cs.created_at DESC
    ");
    $transactionsStmt->execute([$creditorId, $filterDate]);
}
$transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Partial payments for this creditor (by date or all days)
if ($isAllDays) {
    $partialPaymentsStmt = $db->prepare("
        SELECT p.*, cs.creditor_id, cs.total_amount, cs.paid_amount, cs.payment_status, 
               (SELECT GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') 
                FROM credit_sale_items csi WHERE csi.sale_id = cs.id) AS items
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.creditor_id = ? AND cs.payment_status = 'partial'
        ORDER BY p.payment_date DESC
    ");
    $partialPaymentsStmt->execute([$creditorId]);
} else {
    $partialPaymentsStmt = $db->prepare("
        SELECT p.*, cs.creditor_id, cs.total_amount, cs.paid_amount, cs.payment_status, 
               (SELECT GROUP_CONCAT(csi.product_name || ' (' || csi.quantity || 'x N$' || csi.price || ')', ', ') 
                FROM credit_sale_items csi WHERE csi.sale_id = cs.id) AS items
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.creditor_id = ? AND cs.payment_status = 'partial' AND DATE(p.payment_date) = ?
        ORDER BY p.payment_date DESC
    ");
    $partialPaymentsStmt->execute([$creditorId, $filterDate]);
}
$partialPayments = $partialPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Payment history (by date or all days)
if ($isAllDays) {
    $paymentsStmt = $db->prepare("
        SELECT p.*, cs.creditor_id 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.creditor_id = ?
        ORDER BY p.payment_date DESC
    ");
    $paymentsStmt->execute([$creditorId]);
} else {
    $paymentsStmt = $db->prepare("
        SELECT p.*, cs.creditor_id 
        FROM payments p
        JOIN credit_sales cs ON p.sale_id = cs.id
        WHERE cs.creditor_id = ? AND DATE(p.payment_date) = ?
        ORDER BY p.payment_date DESC
    ");
    $paymentsStmt->execute([$creditorId, $filterDate]);
}
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// EFT payment history (by date or all days)
if ($isAllDays) {
    $eftPaymentsStmt = $db->prepare("
        SELECT e.*, cs.creditor_id, cs.id as credit_sale_id
        FROM eft_payments e
        JOIN credit_sales cs ON e.order_id = cs.id
        WHERE cs.creditor_id = ?
        ORDER BY e.payment_date DESC
    ");
    $eftPaymentsStmt->execute([$creditorId]);
} else {
    $eftPaymentsStmt = $db->prepare("
        SELECT e.*, cs.creditor_id, cs.id as credit_sale_id
        FROM eft_payments e
        JOIN credit_sales cs ON e.order_id = cs.id
        WHERE cs.creditor_id = ? AND DATE(e.payment_date) = ?
        ORDER BY e.payment_date DESC
    ");
    $eftPaymentsStmt->execute([$creditorId, $filterDate]);
}
$eftPayments = $eftPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get wallet providers for EFT dropdown
$walletProviders = ['Account(Swipe)', 'E-wallet', 'BlueWallet', 'PayPulse', 'Bank Transfer', 'Standard Bank', 'First National Bank', 'Bank Windhoek', 'Nedbank'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($creditor['name']) ?> Transactions</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <script src="src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="sweetalert2@11.js"></script>
    <!-- Load sendToPrinter function from receipt.php -->
    <script src="receipt.php?js=true"></script>
    
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

        /* Ensure sidebar is above overlay on mobile */
        @media (max-width: 1023px) {
            #sidebar {
                z-index: 10000 !important;
            }
        }

        @media (min-width: 1024px) {
            .hamburger {
                display: none;
            }
            .mobile-overlay {
                display: none;
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
                    
                    <!-- Date filter + Go Back -->
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-sm font-medium text-gray-700 hidden sm:inline">Date:</label>
                        <input type="date" id="filterDate" value="<?= $isAllDays ? '' : htmlspecialchars($filterDate) ?>"
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm"
                            onchange="if(this.value) window.location.href='credit-transactions.php?creditor_id=<?= (int)$creditorId ?>&date=' + this.value">
                        <a href="credit-transactions.php?creditor_id=<?= (int)$creditorId ?>&date=all" class="inline-flex items-center px-2.5 sm:px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $isAllDays ? 'bg-gray-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">All days</a>
                        <?php if ($isAllDays): ?>
                        <a href="credit-transactions.php?creditor_id=<?= (int)$creditorId ?>&date=<?= date('Y-m-d') ?>" class="inline-flex items-center px-2.5 sm:px-3 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors">Today</a>
                        <?php endif; ?>
                        <a href="credit-book?date=<?= urlencode($filterDate) ?>" class="inline-flex items-center px-3 lg:px-4 py-2 border border-gray-300 rounded-md shadow-sm text-xs lg:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                            <svg class="w-4 h-4 lg:w-5 lg:h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            <span class="hidden sm:inline">Go Back</span>
                        </a>
                    </div>
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

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Due Date</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Items</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Total</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Cashier</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Progress</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): 
                                $progress = ($transaction['paid_amount'] / $transaction['total_amount']) * 100;
                            ?>
                            <tr class="border-t border-gray-100 hover:bg-gray-50">
                                <td class="p-4 text-sm" data-label="Date"><?= date('d M Y', strtotime($transaction['created_at'])) ?></td>
                                <td class="p-4 text-sm font-medium <?= (strtotime($transaction['due_date']) < time()) ? 'text-red-600' : 'text-gray-600' ?>" data-label="Due Date">
                                    <?= date('d M Y', strtotime($transaction['due_date'])) ?>
                                </td>
                                <td class="p-4 text-sm max-w-[300px]" data-label="Items"><?= htmlspecialchars($transaction['items']) ?></td>
                                <td class="p-4 text-sm font-medium" data-label="Total">
                                    N$<?= number_format(
                                        ($transaction['payment_status'] === 'partial') 
                                            ? ($transaction['total_amount'] - $transaction['paid_amount']) 
                                            : $transaction['total_amount'], 
                                        2
                                    ) ?>
                                </td>
                                <td class="p-4 text-sm text-gray-600" data-label="Cashier"><?= !empty($transaction['cashier_id']) ? htmlspecialchars($transaction['cashier_id']) : '—' ?></td>
                                <td class="p-4" data-label="Progress">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full border transition-colors
                                        <?php
                                            if ($transaction['payment_status'] === 'paid') {
                                                echo 'bg-teal-100 text-teal-800 border-teal-200 hover:bg-teal-200';
                                            } elseif ($transaction['payment_status'] === 'eft') {
                                                echo 'bg-teal-100 text-teal-800 border-teal-200 hover:bg-teal-200';
                                            } elseif ($transaction['payment_status'] === 'paid_mixed') {
                                                echo 'bg-indigo-100 text-indigo-800 border-indigo-200 hover:bg-indigo-200';
                                            } elseif ($transaction['payment_status'] === 'partial') {
                                                echo 'bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200';
                                            } else {
                                                echo 'bg-red-100 text-red-800 border-red-200 hover:bg-red-200';
                                            }
                                        ?>">
                                        <?php 
                                            if ($transaction['payment_status'] === 'paid') {
                                                echo 'Cash';
                                            } elseif ($transaction['payment_status'] === 'eft') {
                                                echo 'EFT';
                                            } elseif ($transaction['payment_status'] === 'paid_mixed') {
                                                echo 'Cash + EFT';
                                            } elseif ($transaction['payment_status'] === 'partial') {
                                                echo 'Partial';
                                            } else {
                                                echo 'Unpaid';
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td class="p-4" data-label="Actions">
                                    <?php if (!in_array($transaction['payment_status'], ['paid', 'eft', 'paid_mixed'], true)): ?>
                                        <div class="flex flex-wrap items-center justify-center gap-2">
                                            <button type="button" class="text-sm px-3 py-1.5 bg-teal-100 hover:bg-teal-200 text-teal-600 rounded-md cash-payment-btn flex items-center gap-1.5" 
                                                data-sale-id="<?= $transaction['id'] ?>"
                                                data-balance="<?= $transaction['total_amount'] - $transaction['paid_amount'] ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                </svg>
                                                Cash
                                            </button>
                                            <button type="button" class="text-sm px-3 py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-600 rounded-md eft-payment-btn flex items-center gap-1.5"
                                                data-sale-id="<?= $transaction['id'] ?>"
                                                data-balance="<?= $transaction['total_amount'] - $transaction['paid_amount'] ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                                </svg>
                                                EFT
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center w-full">
                                            <div class="w-6 h-6 text-teal-500">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php foreach ($partialPayments as $payment): ?>
                            <tr class="border-t border-gray-100 hover:bg-gray-50">
                                <td class="p-4 text-sm" data-label="Date"><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                <td class="p-4 text-sm font-medium text-gray-600" data-label="Due Date">N/A</td>
                                <td class="p-4 text-sm max-w-[300px]" data-label="Items"><?= htmlspecialchars($payment['items']) ?></td>
                                <td class="p-4 text-sm font-medium" data-label="Total">N$<?= number_format($payment['amount'], 2) ?></td>
                                <td class="p-4 text-sm text-gray-600" data-label="Cashier"><?= !empty($payment['cashier_id']) ? htmlspecialchars($payment['cashier_id']) : '—' ?></td>
                                <td class="p-4" data-label="Progress">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full border transition-colors bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200">
                                        Partial Payment
                                    </span>
                                </td>
                                <td class="p-4" data-label="Actions">
                                    <div class="flex items-center justify-center w-full">
                                        <div class="w-6 h-6 text-teal-500">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Total</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Cashier</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Wallet Provider</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eftPayments as $payment): ?>
                                <tr class="border-t border-gray-100 hover:bg-gray-50">
                                    <td class="p-4 text-sm" data-label="Date"><?= date('d M Y H:i', strtotime($payment['payment_date'])) ?></td>
                                    <td class="p-4 text-sm font-medium text-teal-600" data-label="Amount">N$<?= number_format($payment['amount'], 2) ?></td>
                                    <td class="p-4 text-sm" data-label="Cashier"><?= !empty($payment['cashier_id']) ? htmlspecialchars($payment['cashier_id']) : '—' ?></td>
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

    const BALANCE_RECEIPT_TRANSACTIONS = <?= json_encode($transactions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const BALANCE_RECEIPT_CREDITOR_NAME = <?= json_encode($creditor['name'] ?? '') ?>;
    const BALANCE_RECEIPT_TOTAL = <?= json_encode(array_sum(array_column($transactions, 'balance'))) ?>;
    const BALANCE_RECEIPT_CREDITOR_ID = <?= (int)$creditorId ?>;
    const SESSION_CASHIER_USERNAME = <?= json_encode($_SESSION['username'] ?? 'Unknown') ?>;

    window.printTotalBalanceReceipt = function printTotalBalanceReceipt() {
        const receiptData = {
            creditor_id: BALANCE_RECEIPT_CREDITOR_ID,
            total_balance: BALANCE_RECEIPT_TOTAL,
            creditor_name: BALANCE_RECEIPT_CREDITOR_NAME,
            is_balance_receipt: true,
            transactions: (BALANCE_RECEIPT_TRANSACTIONS || []).filter(function(t) {
                return parseFloat(t.balance || 0) > 0;
            }).map(function(t) {
                return {
                    items: t.items,
                    balance: t.balance,
                    date: t.created_at
                };
            })
        };

        sendToPrinter(receiptData)
            .then(function(response) {
                if (response && response.success) {
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
                        text: (response && response.message) ? response.message : 'Could not print receipt.',
                        confirmButtonColor: '#3B82F6',
                    });
                }
            })
            .catch(function(error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Printing Failed',
                    text: (error && error.message) ? error.message : 'An error occurred while trying to print the receipt.',
                    confirmButtonColor: '#3B82F6',
                });
            });
    };

    // sendToPrinter function is now loaded from receipt.php?js=true
    // The function is defined in receipt.php and automatically handles Android printing
    // The Android interceptor in MainActivity.java only listens to receipt.php calls
    if (typeof sendToPrinter === 'undefined') {
        console.warn('[credit-transactions.php] sendToPrinter not loaded from receipt.php, using fallback');
        function sendToPrinter(receiptData) {
            // Ensure print_only flag is set for regular receipts
            if (!receiptData.print_only && !receiptData.is_cashup_report && !receiptData.is_balance_receipt && !receiptData.is_tab_balance_receipt && !receiptData.is_payment_receipt) {
                receiptData.print_only = true;
            }
            
            // Add business info to receipt data
            var dataWithBusiness = Object.assign({}, receiptData, {
                business_name: receiptData.business_name || businessInfo.business_name,
                location: receiptData.location || businessInfo.location,
                phone: receiptData.phone || businessInfo.phone,
                footer_text: receiptData.footer_text || businessInfo.footer_text,
                vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                vat_rate: receiptData.vat_rate || businessInfo.vat_rate
            });
            
            // Use fetch to receipt.php - the interceptor will catch this
            return fetch('receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dataWithBusiness)
            }).then(function(r) { 
                return r.json();
            });
        }
    }

    // Function to open cash drawer
    function openCashDrawer() {
        const drawerData = {
            open_drawer_only: true,
            cashier_username: SESSION_CASHIER_USERNAME
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
                                            sendToPrinter(data.receipt_data)
                                            .then(function(printResp) {
                                                Swal.fire({ icon: 'success', title: 'Receipt Printed', text: 'Payment and receipt successful.' });
                                                window.location.reload();
                                            })
                                            .catch(function() {
                                                Swal.fire({ icon: 'error', title: 'Printing Failed', text: 'Payment succeeded, but receipt printing failed.' });
                                                window.location.reload();
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
                                            sendToPrinter(data.receipt_data)
                                            .then(function(printResp) {
                                                Swal.fire({ icon: 'success', title: 'Receipt Printed', text: 'Payment and receipt successful.' });
                                                window.location.reload();
                                            })
                                            .catch(function() {
                                                Swal.fire({ icon: 'error', title: 'Printing Failed', text: 'Payment succeeded, but receipt printing failed.' });
                                                window.location.reload();
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