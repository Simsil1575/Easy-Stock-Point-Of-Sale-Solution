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
    header('Location: ../settings');
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
        
        // Get creditor details for interest transactions
        $creditorStmt = $db->prepare("SELECT id, name FROM creditors WHERE id = ?");
        $creditorStmt->execute([$creditorId]);
        $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
        
        while ($txn = $unpaidTxns->fetch(PDO::FETCH_ASSOC)) {
            if ($txn['remaining_amount'] > 0) {
                // Automatically calculate interest (18% of payment amount)
                $interestRate = 0.18; // 18% interest rate
                $interestAmount = round($txn['remaining_amount'] * $interestRate, 2);
                
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
                
                // Always create interest transaction for each payment
                if ($interestAmount > 0) {
                    // Calculate due date (30 days from now as default)
                    $dueDate = date('Y-m-d', strtotime('+30 days'));
                    
                    // Create credit sale for interest
                    $interestStmt = $db->prepare("
                        INSERT INTO credit_sales (creditor_id, total_amount, paid_amount, due_date, created_at, payment_status, cashier_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $interestStmt->execute([
                        $creditorId,
                        $interestAmount,
                        $interestAmount, // Mark as fully paid
                        $dueDate,
                        date('Y-m-d H:i:s'),
                        $isEft ? 'eft' : 'paid',
                        $_SESSION['username'] ?? 'System'
                    ]);
                    $interestSaleId = $db->lastInsertId();
                    
                    // Create credit sale item for interest
                    $interestItemStmt = $db->prepare("
                        INSERT INTO credit_sale_items (sale_id, product_name, quantity, price) 
                        VALUES (?, 'Interest', 1, ?)
                    ");
                    $interestItemStmt->execute([$interestSaleId, $interestAmount]);
                    
                    // Record payment for interest (since it's paid immediately)
                    $interestPaymentStmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)");
                    $interestPaymentStmt->execute([$interestSaleId, $interestAmount, date('Y-m-d H:i:s')]);
                    
                    // If EFT payment, also record EFT payment for interest
                    if ($isEft && !empty($transactionRef) && !empty($walletProvider)) {
                        $interestEftStmt = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) VALUES (?, ?, ?, ?, ?)");
                        $interestEftStmt->execute([$interestSaleId, $transactionRef, $walletProvider, $interestAmount, date('Y-m-d H:i:s')]);
                    }
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
        $interestAmount = isset($_POST['interest_amount']) ? (float)$_POST['interest_amount'] : 0;
        
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
        
        // Automatically calculate interest if not provided
        // Interest is calculated as 18% of the payment amount (you can adjust this percentage)
        if ($interestAmount == 0 && $paymentAmount > 0) {
            $interestRate = 0.18; // 18% interest rate
            $interestAmount = round($paymentAmount * $interestRate, 2);
        }
        
        // Begin transaction
        $db->beginTransaction();
        try {
            // Update credit sale record
            $stmt = $db->prepare("UPDATE credit_sales 
                                SET paid_amount = paid_amount + ?, 
                                    payment_status = CASE WHEN (paid_amount + ?) >= total_amount THEN 'paid' ELSE 'partial' END
                                WHERE id = ?");
            $stmt->execute([$paymentAmount, $paymentAmount, $saleId]);
            
            // Record payment with timezone-aware timestamp
            $stmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)");
            $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s')]);
            
            // Always create interest transaction for all payments
            $interestSaleId = null;
            if ($interestAmount > 0) {
                // Calculate due date (30 days from now as default)
                $dueDate = date('Y-m-d', strtotime('+30 days'));
                
                // Create credit sale for interest
                $interestStmt = $db->prepare("
                    INSERT INTO credit_sales (creditor_id, total_amount, paid_amount, due_date, created_at, payment_status, cashier_id) 
                    VALUES (?, ?, ?, ?, ?, 'paid', ?)
                ");
                $interestStmt->execute([
                    $sale['creditor_id'],
                    $interestAmount,
                    $interestAmount, // Mark as fully paid
                    $dueDate,
                    date('Y-m-d H:i:s'),
                    $_SESSION['username'] ?? 'System'
                ]);
                $interestSaleId = $db->lastInsertId();
                
                // Create credit sale item for interest
                $interestItemStmt = $db->prepare("
                    INSERT INTO credit_sale_items (sale_id, product_name, quantity, price) 
                    VALUES (?, 'Interest', 1, ?)
                ");
                $interestItemStmt->execute([$interestSaleId, $interestAmount]);
                
                // Record payment for interest (since it's paid immediately)
                $interestPaymentStmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)");
                $interestPaymentStmt->execute([$interestSaleId, $interestAmount, date('Y-m-d H:i:s')]);
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage()
            ]);
            exit();
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
        
        // Add interest to items if applicable
        if ($interestAmount > 0) {
            $items[] = [
                'name' => 'Interest',
                'quantity' => 1,
                'price' => $interestAmount
            ];
        }

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
        $interestAmount = isset($_POST['interest_amount']) ? (float)$_POST['interest_amount'] : 0;
        
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
        
        // Automatically calculate interest if not provided
        // Interest is calculated as 18% of the payment amount (you can adjust this percentage)
        if ($interestAmount == 0 && $paymentAmount > 0) {
            $interestRate = 0.18; // 18% interest rate
            $interestAmount = round($paymentAmount * $interestRate, 2);
        }
        
        // Begin transaction
        $db->beginTransaction();
        try {
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
            
            // Always create interest transaction for all payments
            $interestSaleId = null;
            if ($interestAmount > 0) {
                // Calculate due date (30 days from now as default)
                $dueDate = date('Y-m-d', strtotime('+30 days'));
                
                // Create credit sale for interest
                $interestStmt = $db->prepare("
                    INSERT INTO credit_sales (creditor_id, total_amount, paid_amount, due_date, created_at, payment_status, cashier_id) 
                    VALUES (?, ?, ?, ?, ?, 'eft', ?)
                ");
                $interestStmt->execute([
                    $sale['creditor_id'],
                    $interestAmount,
                    $interestAmount, // Mark as fully paid
                    $dueDate,
                    date('Y-m-d H:i:s'),
                    $_SESSION['username'] ?? 'System'
                ]);
                $interestSaleId = $db->lastInsertId();
                
                // Create credit sale item for interest
                $interestItemStmt = $db->prepare("
                    INSERT INTO credit_sale_items (sale_id, product_name, quantity, price) 
                    VALUES (?, 'Interest', 1, ?)
                ");
                $interestItemStmt->execute([$interestSaleId, $interestAmount]);
                
                // Record payment for interest (since it's paid immediately)
                $interestPaymentStmt = $db->prepare("INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)");
                $interestPaymentStmt->execute([$interestSaleId, $interestAmount, date('Y-m-d H:i:s')]);
                
                // Record EFT payment for interest
                $interestEftStmt = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) VALUES (?, ?, ?, ?, ?)");
                $interestEftStmt->execute([$interestSaleId, $transactionRef, $walletProvider, $interestAmount, date('Y-m-d H:i:s')]);
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage()
            ]);
            exit();
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
        
        // Add interest to items if applicable
        if ($interestAmount > 0) {
            $items[] = [
                'name' => 'Interest',
                'quantity' => 1,
                'price' => $interestAmount
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
    
    <style>
        .sidebar { position: fixed; height: 100%; }
        .content { margin-left: 250px; }
        .fade-in { animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .payment-progress { height: 8px; }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <div class="sidebar"><?php include 'sidebar.php'; ?></div>
        <div class="flex-1 content">
            <div class="container mx-auto p-6">
                <?php if (isset($_SESSION['payment_success'])): ?>
                <div class="bg-teal-100 border-l-4 border-teal-500 text-teal-700 p-4 mb-4 rounded shadow" role="alert">
                    <p><?= $_SESSION['payment_success'] ?></p>
                </div>
                <?php unset($_SESSION['payment_success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['payment_error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded shadow" role="alert">
                    <p><?= $_SESSION['payment_error'] ?></p>
                </div>
                <?php unset($_SESSION['payment_error']); ?>
                <?php endif; ?>

                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900">
                            <?= htmlspecialchars($creditor['name']) ?>'s Credit History
                        </h1>
                        <p class="text-sm text-gray-500 mt-1">
                            Last activity: <?= !empty($transactions) ? date('M d, Y', strtotime(end($transactions)['created_at'])) : 'N/A' ?>
                        </p>
                    </div>
                    <a href="credit-book" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Go Back
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
                                <p class="text-xl font-semibold">N$<?= number_format(array_sum(array_column($transactions, 'balance')) * 1.18, 2) ?></p>
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
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Progress</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): 
                                $progress = ($transaction['paid_amount'] / $transaction['total_amount']) * 100;
                            ?>
                            <tr class="border-t border-gray-100 hover:bg-gray-50">
                                <td class="p-4 text-sm"><?= date('d M Y', strtotime($transaction['created_at'])) ?></td>
                                <td class="p-4 text-sm font-medium <?= (strtotime($transaction['due_date']) < time()) ? 'text-red-600' : 'text-gray-600' ?>">
                                    <?= date('d M Y', strtotime($transaction['due_date'])) ?>
                                </td>
                                <td class="p-4 text-sm max-w-[300px]"><?= htmlspecialchars($transaction['items']) ?></td>
                                <td class="p-4 text-sm font-medium">
                                    N$<?= number_format(
                                        ($transaction['payment_status'] === 'partial') 
                                            ? ($transaction['total_amount'] - $transaction['paid_amount']) 
                                            : $transaction['total_amount'], 
                                        2
                                    ) ?>
                                </td>
                                <td class="p-4">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full border transition-colors
                                        <?php
                                            if ($transaction['payment_status'] === 'paid') {
                                                echo 'bg-emerald-100 text-emerald-800 border-emerald-200 hover:bg-emerald-200';
                                            } elseif ($transaction['payment_status'] === 'eft') {
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
                                            } elseif ($transaction['payment_status'] === 'partial') {
                                                echo 'Partial';
                                            } else {
                                                echo 'Unpaid';
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <?php if ($transaction['payment_status'] !== 'paid' && $transaction['payment_status'] !== 'eft'): ?>
                                        <div class="flex space-x-2">
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
                                        <div class="w-6 h-6 text-teal-500">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php foreach ($partialPayments as $payment): ?>
                            <tr class="border-t border-gray-100 hover:bg-gray-50">
                                <td class="p-4 text-sm"><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                <td class="p-4 text-sm font-medium text-gray-600">N/A</td>
                                <td class="p-4 text-sm max-w-[300px]"><?= htmlspecialchars($payment['items']) ?></td>
                                <td class="p-4 text-sm font-medium">N$<?= number_format($payment['amount'], 2) ?></td>
                                <td class="p-4">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full border transition-colors bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200">
                                        Partial Payment
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="w-6 h-6 text-teal-500">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
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
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Wallet Provider</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eftPayments as $payment): ?>
                                <tr class="border-t border-gray-100 hover:bg-gray-50">
                                    <td class="p-4 text-sm"><?= date('d M Y H:i', strtotime($payment['payment_date'])) ?></td>
                                    <td class="p-4 text-sm font-medium text-teal-600">N$<?= number_format($payment['amount'], 2) ?></td>
                                    <td class="p-4 text-sm"><?= htmlspecialchars($payment['wallet_provider']) ?></td>
                                    <td class="p-4 text-sm"><?= htmlspecialchars($payment['transaction_ref']) ?></td>
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
        <input type="hidden" name="interest_amount" id="cash_interest_amount">
    </form>
    
    <form id="eftPaymentForm" method="POST" style="display:none;">
        <input type="hidden" name="sale_id" id="eft_sale_id">
        <input type="hidden" name="eft_payment_amount" id="eft_payment_amount">
        <input type="hidden" name="transaction_ref" id="eft_transaction_ref">
        <input type="hidden" name="wallet_provider" id="eft_wallet_provider">
        <input type="hidden" name="interest_amount" id="eft_interest_amount">
    </form>

    <script>
    // Function to update interest amount display for cash payment
    function updateInterestAmount(amount) {
        const interestAmount = parseFloat(amount || 0) * 0.18;
        const displayElement = document.getElementById('interestAmountDisplay');
        if (displayElement) {
            displayElement.textContent = 'N$' + interestAmount.toFixed(2);
        }
    }
    
    // Function to update interest amount display for EFT payment
    function updateEftInterestAmount(amount) {
        const interestAmount = parseFloat(amount || 0) * 0.18;
        const displayElement = document.getElementById('eftInterestAmountDisplay');
        if (displayElement) {
            displayElement.textContent = 'N$' + interestAmount.toFixed(2);
        }
    }
    
    // Function to open cash drawer
    function openCashDrawer() {
        const drawerData = {
            open_drawer_only: true,
            cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
        };

        console.log('Opening cash drawer');

        fetch('../receipt.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(drawerData),
        })
        .then(res => res.json())
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
                        <p class="text-lg font-semibold text-gray-800 mb-4">Remaining Balance: N$${(parseFloat(balance) * 1.18).toFixed(2)}</p>
                        <div class="flex flex-col space-y-3">
                            <div class="flex flex-col">
                                <label class="text-left text-sm font-medium text-gray-700 mb-1">Payment Amount</label>
                                <input id="paymentAmountInput" type="number" step="0.01" min="0.01" max="${parseFloat(balance)}" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                    placeholder="Enter amount to pay" value="${parseFloat(balance).toFixed(2)}">
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-md p-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-amber-800">Interest (18%):</span>
                                    <span id="interestAmountDisplay" class="text-sm font-bold text-amber-900">N$${(parseFloat(balance) * 0.18).toFixed(2)}</span>
                                </div>
                            </div>
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
                didOpen: () => {
                    const input = document.getElementById('paymentAmountInput');
                    const display = document.getElementById('interestAmountDisplay');
                    if (input && display) {
                        input.addEventListener('input', function() {
                            const amount = parseFloat(this.value || 0);
                            const interest = amount * 0.18;
                            display.textContent = 'N$' + interest.toFixed(2);
                        });
                    }
                },
                preConfirm: () => {
                    const amount = document.getElementById('paymentAmountInput').value;
                    if (!amount || amount <= 0 || amount > parseFloat(balance)) {
                        Swal.showValidationMessage('Please enter a valid amount between 0.01 and ' + parseFloat(balance).toFixed(2));
                        return false;
                    }
                    // Calculate interest amount (18%)
                    const interestAmount = parseFloat(amount) * 0.18;
                    return { amount: amount, interest: interestAmount.toFixed(2) };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#cash_sale_id').val(saleId);
                    $('#cash_payment_amount').val(result.value.amount);
                    $('#cash_interest_amount').val(result.value.interest);
                    
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
                        <p class="text-lg font-semibold text-gray-800 mb-4">Remaining Balance: N$${(parseFloat(balance) * 1.18).toFixed(2)}</p>
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
                        <div class="bg-amber-50 border border-amber-200 rounded-md p-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-amber-800">Interest (18%):</span>
                                <span id="eftInterestAmountDisplay" class="text-sm font-bold text-amber-900">N$${(parseFloat(balance) * 0.18).toFixed(2)}</span>
                            </div>
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
                didOpen: () => {
                    const input = document.getElementById('eftPaymentAmountInput');
                    const display = document.getElementById('eftInterestAmountDisplay');
                    if (input && display) {
                        input.addEventListener('input', function() {
                            const amount = parseFloat(this.value || 0);
                            const interest = amount * 0.18;
                            display.textContent = 'N$' + interest.toFixed(2);
                        });
                    }
                },
                preConfirm: () => {
                    const walletProvider = document.getElementById('walletProviderInput').value;
                    const transactionRef = document.getElementById('transactionRefInput').value;
                    const paymentAmount = document.getElementById('eftPaymentAmountInput').value;
                    if (!paymentAmount || paymentAmount <= 0 || paymentAmount > parseFloat(balance)) {
                        Swal.showValidationMessage('Please enter a valid amount between 0.01 and ' + parseFloat(balance).toFixed(2));
                        return false;
                    }
                    // Calculate interest amount (18%)
                    const interestAmount = parseFloat(paymentAmount) * 0.18;
                    // transactionRef is now optional
                    return { walletProvider, transactionRef, paymentAmount, interest: interestAmount.toFixed(2) };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#eft_sale_id').val(saleId);
                    $('#eft_payment_amount').val(result.value.paymentAmount);
                    $('#eft_transaction_ref').val(result.value.transactionRef);
                    $('#eft_wallet_provider').val(result.value.walletProvider);
                    $('#eft_interest_amount').val(result.value.interest);
                    
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
                window.location = `../delete-transaction.php?id=${saleId}`;
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
    </script>
</body>
</html>
<?php $db = null; ?> 