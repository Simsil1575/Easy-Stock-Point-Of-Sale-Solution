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
require_once __DIR__ . '/../inc/credit_sale_payment_status.php';
require_once __DIR__ . '/../credit_interest_helper.php';
$creditInterestSettings = loadCreditInterestSettings($db);
$creditorId = (int)($_GET['creditor_id'] ?? $_POST['creditor_id'] ?? 0);

/**
 * Apply a payment to one credit sale (cash or EFT). Caller must manage transaction boundaries.
 * @return float Interest amount created for this payment
 */
function apply_credit_sale_payment(PDO $db, int $saleId, float $paymentAmount, string $method, array $eftOpts = [], ?array $interestSettings = null): float
{
    global $creditInterestSettings;
    if ($interestSettings === null) {
        $interestSettings = $creditInterestSettings;
    }
    $interestAmount = calculateCreditInterestAmount($paymentAmount, $interestSettings);

    $stmt = $db->prepare('UPDATE credit_sales SET paid_amount = paid_amount + ? WHERE id = ?');
    $stmt->execute([$paymentAmount, $saleId]);
    $stmt = $db->prepare('INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)');
    $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s')]);

    if ($method === 'eft') {
        $eftStmt = $db->prepare('INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) VALUES (?, ?, ?, ?, ?)');
        $eftStmt->execute([
            $saleId,
            $eftOpts['transaction_ref'] ?? '',
            $eftOpts['wallet_provider'] ?? '',
            $paymentAmount,
            date('Y-m-d H:i:s')
        ]);
    }

    $paymentStatus = resolve_credit_sale_payment_status($db, $saleId);
    $db->prepare('UPDATE credit_sales SET payment_status = ? WHERE id = ?')->execute([$paymentStatus, $saleId]);

    if ($interestAmount > 0) {
        $saleRow = $db->prepare('SELECT creditor_id FROM credit_sales WHERE id = ?');
        $saleRow->execute([$saleId]);
        $creditorIdForInterest = (int)$saleRow->fetchColumn();
        recordCreditInterestSale(
            $db,
            $creditorIdForInterest,
            $interestAmount,
            $interestSettings,
            $method === 'eft' ? 'eft' : 'paid',
            $eftOpts
        );
    }

    return $interestAmount;
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
        
        // Get creditor details for interest transactions
        $creditorStmt = $db->prepare("SELECT id, name FROM creditors WHERE id = ?");
        $creditorStmt->execute([$creditorId]);
        $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
        
        while ($txn = $unpaidTxns->fetch(PDO::FETCH_ASSOC)) {
            if ($txn['remaining_amount'] > 0) {
                $eftOpts = $isEft
                    ? ['transaction_ref' => $transactionRef, 'wallet_provider' => $walletProvider]
                    : [];
                apply_credit_sale_payment(
                    $db,
                    (int) $txn['id'],
                    (float) $txn['remaining_amount'],
                    $isEft ? 'eft' : 'cash',
                    $eftOpts,
                    $creditInterestSettings
                );
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
    // Handle bulk payment for multiple selected transactions
    if (isset($_POST['bulk_payment'])) {
        $bulkPayments = json_decode($_POST['bulk_payments'] ?? '[]', true);
        $paymentMethod = ($_POST['payment_method'] ?? 'cash') === 'eft' ? 'eft' : 'cash';
        $transactionRef = $_POST['transaction_ref'] ?? '';
        $walletProvider = $_POST['wallet_provider'] ?? '';

        if (!is_array($bulkPayments) || count($bulkPayments) === 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No transactions selected for payment.']);
            exit();
        }

        $creditorCheck = $db->prepare('SELECT name FROM creditors WHERE id = ?');
        $creditorCheck->execute([$creditorId]);
        $creditorRow = $creditorCheck->fetch(PDO::FETCH_ASSOC);
        if (!$creditorRow) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid creditor.']);
            exit();
        }

        $saleStmt = $db->prepare('
            SELECT cs.id, cs.total_amount, cs.paid_amount, cs.creditor_id
            FROM credit_sales cs
            WHERE cs.id = ? AND cs.creditor_id = ?
        ');

        $totalPaid = 0.0;
        $totalInterest = 0.0;
        $processedSales = [];

        $db->beginTransaction();
        try {
            foreach ($bulkPayments as $entry) {
                $saleId = (int)($entry['sale_id'] ?? 0);
                $paymentAmount = round((float)($entry['amount'] ?? 0), 2);
                if ($saleId <= 0 || $paymentAmount <= 0) {
                    continue;
                }

                $saleStmt->execute([$saleId, $creditorId]);
                $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
                if (!$sale) {
                    throw new Exception('Invalid transaction selected.');
                }

                $remaining = round((float)$sale['total_amount'] - (float)$sale['paid_amount'], 2);
                if ($paymentAmount > $remaining + 0.01) {
                    throw new Exception('Payment amount exceeds balance for transaction #' . $saleId . '.');
                }

                $eftOpts = $paymentMethod === 'eft'
                    ? ['transaction_ref' => $transactionRef, 'wallet_provider' => $walletProvider]
                    : [];
                $interestAmount = apply_credit_sale_payment($db, $saleId, $paymentAmount, $paymentMethod, $eftOpts);

                $totalPaid += $paymentAmount;
                $totalInterest += $interestAmount;
                $processedSales[] = $saleId;
            }

            if ($totalPaid <= 0) {
                throw new Exception('Enter at least one valid payment amount.');
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }

        $receiptData = [
            'print_only' => true,
            'creditor_id' => $creditorId,
            'creditor_name' => $creditorRow['name'],
            'is_payment_receipt' => true,
            'is_bulk_payment' => true,
            'items' => [
                ['name' => 'Bulk payment (' . count($processedSales) . ' transaction(s))', 'quantity' => 1, 'price' => $totalPaid]
            ],
            'total_amount' => $totalPaid + $totalInterest,
            'cash_received' => $paymentMethod === 'cash' ? $totalPaid : 0,
            'payment_amount' => $paymentMethod === 'eft' ? $totalPaid : 0,
            'payment_type' => $paymentMethod,
            'payment_method' => $paymentMethod === 'eft' ? 'e-wallet' : 'cash',
            'wallet_provider' => $walletProvider,
            'transaction_ref' => $transactionRef,
            'interest_amount' => round($totalInterest, 2),
            'cashier_username' => $_SESSION['username'] ?? 'Cashier',
            'date' => date('Y-m-d H:i:s')
        ];

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'receipt_data' => $receiptData,
            'total_paid' => $totalPaid,
            'transactions_count' => count($processedSales)
        ]);
        exit();
    }

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
        if ($interestAmount == 0 && $paymentAmount > 0) {
            $interestAmount = calculateCreditInterestAmount($paymentAmount, $creditInterestSettings);
        }
        
        // Begin transaction
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE credit_sales SET paid_amount = paid_amount + ? WHERE id = ?');
            $stmt->execute([$paymentAmount, $saleId]);
            $stmt = $db->prepare('INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)');
            $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s')]);
            $paymentStatus = resolve_credit_sale_payment_status($db, (int) $saleId);
            $db->prepare('UPDATE credit_sales SET payment_status = ? WHERE id = ?')->execute([$paymentStatus, $saleId]);

            if ($interestAmount > 0) {
                recordCreditInterestSale($db, (int) $sale['creditor_id'], $interestAmount, $creditInterestSettings, 'paid');
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
                'name' => creditInterestProductLabel($creditInterestSettings),
                'quantity' => 1,
                'price' => $interestAmount
            ];
        }

        // Prepare receipt data
        $receiptData = [
            'print_only' => true,
            'creditor_id' => $sale['creditor_id'],
            'creditor_name' => $sale['creditor_name'],
            'sale_id' => $saleId,
            'items' => $items,
            'total_amount' => $sale['total_amount'],
            'cash_received' => $paymentAmount,
            'payment_type' => 'cash',
            'interest_amount' => round($interestAmount, 2),
            'cashier_username' => $_SESSION['username'] ?? 'Cashier',
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
        if ($interestAmount == 0 && $paymentAmount > 0) {
            $interestAmount = calculateCreditInterestAmount($paymentAmount, $creditInterestSettings);
        }
        
        // Begin transaction
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE credit_sales SET paid_amount = paid_amount + ? WHERE id = ?');
            $stmt->execute([$paymentAmount, $saleId]);
            $stmt = $db->prepare('INSERT INTO payments (sale_id, amount, payment_date) VALUES (?, ?, ?)');
            $stmt->execute([$saleId, $paymentAmount, date('Y-m-d H:i:s')]);
            $stmt = $db->prepare('INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, payment_date) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$saleId, $transactionRef, $walletProvider, $paymentAmount, date('Y-m-d H:i:s')]);
            $paymentStatus = resolve_credit_sale_payment_status($db, (int) $saleId);
            $db->prepare('UPDATE credit_sales SET payment_status = ? WHERE id = ?')->execute([$paymentStatus, $saleId]);

            if ($interestAmount > 0) {
                recordCreditInterestSale(
                    $db,
                    (int) $sale['creditor_id'],
                    $interestAmount,
                    $creditInterestSettings,
                    'eft',
                    ['transaction_ref' => $transactionRef, 'wallet_provider' => $walletProvider]
                );
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
                'name' => creditInterestProductLabel($creditInterestSettings),
                'quantity' => 1,
                'price' => $interestAmount
            ];
        }

        // Prepare receipt data
        $receiptData = [
            'print_only' => true,
            'creditor_id' => $sale['creditor_id'],
            'creditor_name' => $sale['creditor_name'],
            'sale_id' => $saleId,
            'items' => $items,
            'total_amount' => $sale['total_amount'],
            'payment_method' => 'e-wallet',
            'wallet_provider' => $walletProvider,
            'transaction_ref' => $transactionRef,
            'payment_amount' => $paymentAmount,
            'interest_amount' => round($interestAmount, 2),
            'cashier_username' => $_SESSION['username'] ?? 'Cashier',
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

$txnTotalBalance = array_sum(array_column($transactions, 'balance'));
$txnTotalWithInterest = $creditInterestSettings['enabled']
    ? $txnTotalBalance * (1 + $creditInterestSettings['rate_decimal'])
    : $txnTotalBalance;

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
    <script src="../receipt.php?js=true"></script>
    
    <style>
        .sidebar { position: fixed; height: 100%; }
        .content { margin-left: 250px; }
        .fade-in { animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .payment-progress { height: 8px; }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; }
        #bulkSelectionBar {
            position: fixed;
            bottom: 0;
            left: 250px;
            right: 0;
            z-index: 40;
            transform: translateY(100%);
            transition: transform 0.25s ease;
        }
        #bulkSelectionBar.is-visible { transform: translateY(0); }
        .txn-select-checkbox { width: 1rem; height: 1rem; accent-color: #0d9488; cursor: pointer; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <div class="sidebar"><?php include 'sidebar.php'; ?></div>
        <div class="content flex-1 lg:ml-64">
            <div class="w-full p-4 lg:p-6">
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
                                <p class="text-sm text-gray-500"><?= $creditInterestSettings['enabled'] ? 'Total (incl. interest)' : 'Total Balance' ?></p>
                                <p class="text-xl font-semibold">N$<?= number_format($txnTotalWithInterest, 2) ?></p>
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

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden mb-24">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="p-4 w-10">
                                    <input type="checkbox" id="selectAllPayable" class="txn-select-checkbox" title="Select all unpaid">
                                </th>
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
                                $remainingBalance = round((float)$transaction['total_amount'] - (float)$transaction['paid_amount'], 2);
                                $isPayable = !in_array($transaction['payment_status'], ['paid', 'eft', 'paid_mixed'], true) && $remainingBalance > 0;
                            ?>
                            <tr class="border-t border-gray-100 hover:bg-gray-50 <?= $isPayable ? 'payable-row' : '' ?>"
                                <?= $isPayable ? 'data-sale-id="' . (int)$transaction['id'] . '" data-balance="' . number_format($remainingBalance, 2, '.', '') . '" data-date="' . htmlspecialchars($transaction['created_at']) . '" data-items="' . htmlspecialchars(mb_strimwidth($transaction['items'] ?? '', 0, 80, '…')) . '"' : '' ?>>
                                <td class="p-4">
                                    <?php if ($isPayable): ?>
                                    <input type="checkbox" class="txn-select-checkbox txn-row-checkbox"
                                        value="<?= (int)$transaction['id'] ?>"
                                        data-balance="<?= number_format($remainingBalance, 2, '.', '') ?>"
                                        data-created="<?= strtotime($transaction['created_at']) ?>"
                                        data-date="<?= htmlspecialchars(date('d M Y', strtotime($transaction['created_at']))) ?>"
                                        data-items="<?= htmlspecialchars(mb_strimwidth($transaction['items'] ?? '', 0, 60, '…')) ?>">
                                    <?php endif; ?>
                                </td>
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
                                <td class="p-4 text-sm text-gray-600"><?= !empty($transaction['cashier_id']) ? htmlspecialchars($transaction['cashier_id']) : '—' ?></td>
                                <td class="p-4">
                                    <span class="px-3 py-1 text-xs font-medium rounded-full border transition-colors
                                        <?php
                                            if ($transaction['payment_status'] === 'paid') {
                                                echo 'bg-emerald-100 text-emerald-800 border-emerald-200 hover:bg-emerald-200';
                                            } elseif ($transaction['payment_status'] === 'eft') {
                                                echo 'bg-indigo-100 text-indigo-800 border-indigo-200 hover:bg-indigo-200';
                                            } elseif ($transaction['payment_status'] === 'paid_mixed') {
                                                echo 'bg-violet-100 text-violet-800 border-violet-200 hover:bg-violet-200';
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
                                <td class="p-4">
                                    <?php if (!in_array($transaction['payment_status'], ['paid', 'eft', 'paid_mixed'], true)): ?>
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
                                <td class="p-4"></td>
                                <td class="p-4 text-sm"><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                <td class="p-4 text-sm font-medium text-gray-600">N/A</td>
                                <td class="p-4 text-sm max-w-[300px]"><?= htmlspecialchars($payment['items']) ?></td>
                                <td class="p-4 text-sm font-medium">N$<?= number_format($payment['amount'], 2) ?></td>
                                <td class="p-4 text-sm text-gray-600"><?= !empty($payment['cashier_id']) ? htmlspecialchars($payment['cashier_id']) : '—' ?></td>
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
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Cashier</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Wallet Provider</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eftPayments as $payment): ?>
                                <tr class="border-t border-gray-100 hover:bg-gray-50">
                                    <td class="p-4 text-sm"><?= date('d M Y H:i', strtotime($payment['payment_date'])) ?></td>
                                    <td class="p-4 text-sm font-medium text-teal-600">N$<?= number_format($payment['amount'], 2) ?></td>
                                    <td class="p-4 text-sm"><?= !empty($payment['cashier_id']) ? htmlspecialchars($payment['cashier_id']) : '—' ?></td>
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

    <!-- Bulk selection action bar -->
    <div id="bulkSelectionBar" class="bg-white border-t border-gray-200 shadow-lg px-6 py-4">
        <div class="flex flex-wrap items-center justify-between gap-4 max-w-6xl mx-auto">
            <div class="flex flex-wrap items-center gap-6">
                <span class="text-sm text-gray-500"><span id="bulkSelectedCount">0</span> selected</span>
                <div>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Selected balance</p>
                    <p class="text-xl font-semibold text-gray-900">N$<span id="bulkSelectedBalance">0.00</span></p>
                </div>
                <?php if ($creditInterestSettings['enabled']): ?>
                <div id="bulkInterestSummary">
                    <p class="text-xs text-gray-500 uppercase tracking-wide"><?= htmlspecialchars(creditInterestProductLabel($creditInterestSettings)) ?></p>
                    <p class="text-lg font-medium text-amber-700">N$<span id="bulkSelectedWithInterest">0.00</span></p>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="bulkClearBtn" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    Clear
                </button>
                <button type="button" id="bulkPayCashBtn" class="px-4 py-2 text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 rounded-lg transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    Pay Selected (Cash)
                </button>
                <button type="button" id="bulkPayEftBtn" class="px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                    Pay Selected (EFT)
                </button>
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

    <form id="bulkPaymentForm" method="POST" style="display:none;">
        <input type="hidden" name="bulk_payment" value="1">
        <input type="hidden" name="creditor_id" value="<?= (int)$creditorId ?>">
        <input type="hidden" name="bulk_payments" id="bulk_payments_json">
        <input type="hidden" name="payment_method" id="bulk_payment_method">
        <input type="hidden" name="transaction_ref" id="bulk_transaction_ref">
        <input type="hidden" name="wallet_provider" id="bulk_wallet_provider">
    </form>

    <script>
    const CREDITOR_ID = <?= (int)$creditorId ?>;
    const INTEREST_ENABLED = <?= $creditInterestSettings['enabled'] ? 'true' : 'false' ?>;
    const INTEREST_RATE = <?= json_encode($creditInterestSettings['rate_decimal']) ?>;
    const INTEREST_LABEL = <?= json_encode(creditInterestProductLabel($creditInterestSettings)) ?>;
    const BALANCE_RECEIPT_TRANSACTIONS = <?= json_encode($transactions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const BALANCE_RECEIPT_CREDITOR_NAME = <?= json_encode($creditor['name'] ?? '') ?>;
    const BALANCE_RECEIPT_TOTAL = <?= json_encode(array_sum(array_column($transactions, 'balance'))) ?>;
    const SESSION_CASHIER_USERNAME = <?= json_encode($_SESSION['username'] ?? 'Unknown') ?>;

    if (typeof sendToPrinter === 'undefined') {
        window.sendToPrinter = function(receiptData) {
            if (!receiptData.is_balance_receipt && !receiptData.is_payment_receipt && !receiptData.print_only) {
                receiptData.print_only = true;
            }
            return fetch('../receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(receiptData)
            }).then(function(r) { return r.json(); });
        };
    }

    window.printTotalBalanceReceipt = function printTotalBalanceReceipt() {
        const receiptData = {
            creditor_id: CREDITOR_ID,
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

    function formatMoney(n) {
        return parseFloat(n || 0).toFixed(2);
    }

    function calcInterest(amount) {
        if (!INTEREST_ENABLED) return 0;
        return parseFloat(amount || 0) * INTEREST_RATE;
    }

    function totalWithInterest(amount) {
        if (!INTEREST_ENABLED) return parseFloat(amount || 0);
        return parseFloat(amount || 0) * (1 + INTEREST_RATE);
    }

    function getSelectedTransactions() {
        const selected = [];
        document.querySelectorAll('.txn-row-checkbox:checked').forEach(cb => {
            selected.push({
                saleId: parseInt(cb.value, 10),
                balance: parseFloat(cb.dataset.balance),
                created: parseInt(cb.dataset.created, 10) || 0,
                date: cb.dataset.date,
                items: cb.dataset.items
            });
        });
        return selected.sort((a, b) => a.created - b.created);
    }

    function updateBulkSelectionBar() {
        const selected = getSelectedTransactions();
        const count = selected.length;
        const totalBalance = selected.reduce((sum, t) => sum + t.balance, 0);
        const withInterest = totalWithInterest(totalBalance);

        document.getElementById('bulkSelectedCount').textContent = count;
        document.getElementById('bulkSelectedBalance').textContent = formatMoney(totalBalance);
        const bulkWithInterestEl = document.getElementById('bulkSelectedWithInterest');
        if (bulkWithInterestEl) {
            bulkWithInterestEl.textContent = formatMoney(withInterest);
        }

        const bar = document.getElementById('bulkSelectionBar');
        if (count > 0) {
            bar.classList.add('is-visible');
        } else {
            bar.classList.remove('is-visible');
        }

        const payableCount = document.querySelectorAll('.txn-row-checkbox').length;
        const selectAll = document.getElementById('selectAllPayable');
        if (selectAll) {
            selectAll.checked = payableCount > 0 && count === payableCount;
            selectAll.indeterminate = count > 0 && count < payableCount;
        }
    }

    function buildBulkPaymentModalHtml(selected, method) {
        const totalBalance = selected.reduce((s, t) => s + t.balance, 0);
        let rowsHtml = selected.map(t => `
            <tr class="border-t border-gray-100" data-sale-id="${t.saleId}" data-max-balance="${t.balance}">
                <td class="py-2 pr-2 text-xs text-gray-600 whitespace-nowrap">${t.date}</td>
                <td class="py-2 pr-2 text-xs text-gray-700 max-w-[140px] truncate" title="${t.items.replace(/"/g, '&quot;')}">${t.items}</td>
                <td class="py-2 pr-2 text-xs text-right text-gray-600 whitespace-nowrap">N$${formatMoney(t.balance)}</td>
                <td class="py-2 text-right">
                    <input type="number" step="0.01" min="0.01" max="${t.balance}"
                        class="bulk-amount-input w-24 px-2 py-1 text-sm border border-gray-300 rounded-md text-right"
                        data-sale-id="${t.saleId}" value="${formatMoney(t.balance)}">
                </td>
            </tr>
        `).join('');

        const eftFields = method === 'eft' ? `
            <div class="flex flex-col mt-3">
                <label class="text-left text-sm font-medium text-gray-700 mb-1">Wallet Provider</label>
                <select id="bulkWalletProvider" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <?php foreach ($walletProviders as $provider): ?>
                    <option value="<?= htmlspecialchars($provider) ?>"><?= htmlspecialchars($provider) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col mt-3">
                <label class="text-left text-sm font-medium text-gray-700 mb-1">Transaction Reference <span class="text-gray-400">(optional)</span></label>
                <input id="bulkTransactionRef" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Enter reference (optional)">
            </div>
        ` : '';

        return `
            <div class="text-left">
                <div class="grid grid-cols-2 gap-3 mb-4 p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-xs text-gray-500">Selected balance</p>
                        <p class="text-lg font-semibold text-gray-900">N$<span id="bulkModalTotalBalance">${formatMoney(totalBalance)}</span></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Total paying</p>
                        <p class="text-lg font-semibold text-teal-700">N$<span id="bulkModalTotalPaying">${formatMoney(totalBalance)}</span></p>
                    </div>
                </div>
                <div class="flex flex-col mb-3">
                    <label class="text-sm font-medium text-gray-700 mb-1">Quick fill total (distributes oldest first)</label>
                    <input id="bulkQuickTotal" type="number" step="0.01" min="0.01" max="${totalBalance}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                        placeholder="Enter lump sum to distribute" value="${formatMoney(totalBalance)}">
                </div>
                <p class="text-xs text-gray-500 mb-2">Set amount per transaction (partial payments allowed):</p>
                <div class="max-h-48 overflow-y-auto border border-gray-200 rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="text-left p-2 text-xs font-medium text-gray-500">Date</th>
                                <th class="text-left p-2 text-xs font-medium text-gray-500">Items</th>
                                <th class="text-right p-2 text-xs font-medium text-gray-500">Owed</th>
                                <th class="text-right p-2 text-xs font-medium text-gray-500">Pay</th>
                            </tr>
                        </thead>
                        <tbody id="bulkPaymentRows">${rowsHtml}</tbody>
                    </table>
                </div>
                <div class="bg-amber-50 border border-amber-200 rounded-md p-3 mt-3 ${INTEREST_ENABLED ? '' : 'hidden'}">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-amber-800">${INTEREST_LABEL}:</span>
                        <span id="bulkModalInterest" class="text-sm font-bold text-amber-900">N$${formatMoney(calcInterest(totalBalance))}</span>
                    </div>
                </div>
                ${eftFields}
            </div>
        `;
    }

    function distributeBulkQuickTotal(quickTotal) {
        let remaining = parseFloat(quickTotal) || 0;
        const inputs = Array.from(document.querySelectorAll('.bulk-amount-input'));
        // Oldest transactions first (rows sorted by created asc in modal)
        inputs.forEach(input => {
            const max = parseFloat(input.max);
            const pay = Math.min(Math.max(remaining, 0), max);
            input.value = formatMoney(pay);
            remaining -= pay;
        });
        updateBulkModalTotals();
    }

    function updateBulkModalTotals() {
        let totalPaying = 0;
        document.querySelectorAll('.bulk-amount-input').forEach(input => {
            totalPaying += parseFloat(input.value) || 0;
        });
        const interest = calcInterest(totalPaying);
        const elPaying = document.getElementById('bulkModalTotalPaying');
        const elInterest = document.getElementById('bulkModalInterest');
        if (elPaying) elPaying.textContent = formatMoney(totalPaying);
        if (elInterest) elInterest.textContent = 'N$' + formatMoney(interest);
        const quick = document.getElementById('bulkQuickTotal');
        if (quick && document.activeElement !== quick) {
            quick.value = formatMoney(totalPaying);
        }
    }

    function collectBulkPaymentAmounts() {
        const payments = [];
        document.querySelectorAll('.bulk-amount-input').forEach(input => {
            const amount = parseFloat(input.value) || 0;
            if (amount > 0) {
                payments.push({
                    sale_id: parseInt(input.dataset.saleId, 10),
                    amount: amount
                });
            }
        });
        return payments;
    }

    function submitBulkPayment(method, eftDetails) {
        const payments = collectBulkPaymentAmounts();
        if (payments.length === 0) {
            Swal.showValidationMessage('Enter at least one payment amount.');
            return;
        }

        for (const input of document.querySelectorAll('.bulk-amount-input')) {
            const amount = parseFloat(input.value) || 0;
            const max = parseFloat(input.max);
            if (amount > max + 0.001) {
                Swal.showValidationMessage('Amount for transaction #' + input.dataset.saleId + ' exceeds balance (N$' + formatMoney(max) + ').');
                return;
            }
        }

        $('#bulk_payments_json').val(JSON.stringify(payments));
        $('#bulk_payment_method').val(method);
        $('#bulk_transaction_ref').val(eftDetails?.transactionRef || '');
        $('#bulk_wallet_provider').val(eftDetails?.walletProvider || '');

        $.ajax({
            url: 'credit-transactions.php?creditor_id=' + CREDITOR_ID,
            method: 'POST',
            data: $('#bulkPaymentForm').serialize(),
            success: function(response) {
                let data;
                try {
                    data = typeof response === 'string' ? JSON.parse(response) : response;
                } catch (e) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Invalid server response.' });
                    return;
                }
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Payment Failed', text: data.message || 'Unknown error.' });
                    return;
                }
                if (method === 'cash') {
                    openCashDrawer();
                }
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Successful!',
                    html: `<p class="text-gray-600">Paid <strong>N$${formatMoney(data.total_paid)}</strong> across <strong>${data.transactions_count}</strong> transaction(s).</p>`,
                    footer: `
                        <div style="display: flex; align-items: center; justify-content: center; width: 100%;">
                            <input type='checkbox' id='printBulkReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px;'>
                            <label for='printBulkReceiptCheckbox' style='font-size: 1.05em; cursor:pointer;'>Print receipt</label>
                        </div>
                    `,
                    confirmButtonText: 'OK'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const printReceipt = document.getElementById('printBulkReceiptCheckbox')?.checked;
                        if (printReceipt && data.receipt_data) {
                            $.ajax({
                                url: 'receipt.php',
                                method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify(data.receipt_data),
                                complete: function() { window.location.reload(); }
                            });
                        } else {
                            window.location.reload();
                        }
                    }
                });
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to process bulk payment.' });
            }
        });
    }

    function openBulkPaymentModal(method) {
        const selected = getSelectedTransactions();
        if (selected.length === 0) {
            Swal.fire({ icon: 'info', title: 'No selection', text: 'Select at least one unpaid transaction.' });
            return;
        }

        const title = method === 'cash' ? 'Pay Selected — Cash' : 'Pay Selected — EFT';

        Swal.fire({
            title: title,
            html: buildBulkPaymentModalHtml(selected, method),
            width: '560px',
            showCancelButton: true,
            confirmButtonText: 'Process Payment',
            cancelButtonText: 'Cancel',
            confirmButtonColor: method === 'cash' ? '#0d9488' : '#9333ea',
            focusConfirm: false,
            didOpen: () => {
                document.getElementById('bulkQuickTotal')?.addEventListener('input', function() {
                    const max = selected.reduce((s, t) => s + t.balance, 0);
                    let val = parseFloat(this.value) || 0;
                    if (val > max) val = max;
                    if (val < 0) val = 0;
                    distributeBulkQuickTotal(val);
                });
                document.querySelectorAll('.bulk-amount-input').forEach(input => {
                    input.addEventListener('input', updateBulkModalTotals);
                });
            },
            preConfirm: () => {
                const payments = collectBulkPaymentAmounts();
                if (payments.length === 0) {
                    Swal.showValidationMessage('Enter at least one payment amount.');
                    return false;
                }
                for (const input of document.querySelectorAll('.bulk-amount-input')) {
                    const amount = parseFloat(input.value) || 0;
                    const max = parseFloat(input.max);
                    if (amount <= 0) continue;
                    if (amount > max + 0.001) {
                        Swal.showValidationMessage('Payment exceeds balance for one or more transactions.');
                        return false;
                    }
                }
                if (method === 'eft') {
                    return {
                        walletProvider: document.getElementById('bulkWalletProvider')?.value || '',
                        transactionRef: document.getElementById('bulkTransactionRef')?.value || ''
                    };
                }
                return {};
            }
        }).then((result) => {
            if (result.isConfirmed) {
                submitBulkPayment(method, result.value);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('selectAllPayable')?.addEventListener('change', function() {
            document.querySelectorAll('.txn-row-checkbox').forEach(cb => {
                cb.checked = this.checked;
            });
            updateBulkSelectionBar();
        });

        document.querySelectorAll('.txn-row-checkbox').forEach(cb => {
            cb.addEventListener('change', updateBulkSelectionBar);
        });

        document.getElementById('bulkClearBtn')?.addEventListener('click', function() {
            document.querySelectorAll('.txn-row-checkbox').forEach(cb => { cb.checked = false; });
            document.getElementById('selectAllPayable').checked = false;
            updateBulkSelectionBar();
        });

        document.getElementById('bulkPayCashBtn')?.addEventListener('click', () => openBulkPaymentModal('cash'));
        document.getElementById('bulkPayEftBtn')?.addEventListener('click', () => openBulkPaymentModal('eft'));
    });

    // Function to update interest amount display for cash payment
    function updateInterestAmount(amount) {
        const interestAmount = calcInterest(amount);
        const displayElement = document.getElementById('interestAmountDisplay');
        if (displayElement) {
            displayElement.textContent = 'N$' + interestAmount.toFixed(2);
        }
    }
    
    // Function to update interest amount display for EFT payment
    function updateEftInterestAmount(amount) {
        const interestAmount = calcInterest(amount);
        const displayElement = document.getElementById('eftInterestAmountDisplay');
        if (displayElement) {
            displayElement.textContent = 'N$' + interestAmount.toFixed(2);
        }
    }
    
    // Function to open cash drawer
    function openCashDrawer() {
        const drawerData = {
            open_drawer_only: true,
            cashier_username: SESSION_CASHIER_USERNAME
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
                        <p class="text-lg font-semibold text-gray-800 mb-4">Remaining Balance: N$${totalWithInterest(balance).toFixed(2)}</p>
                        <div class="flex flex-col space-y-3">
                            <div class="flex flex-col">
                                <label class="text-left text-sm font-medium text-gray-700 mb-1">Payment Amount</label>
                                <input id="paymentAmountInput" type="number" step="0.01" min="0.01" max="${parseFloat(balance)}" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                    placeholder="Enter amount to pay" value="${parseFloat(balance).toFixed(2)}">
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-md p-3 ${INTEREST_ENABLED ? '' : 'hidden'}">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-amber-800">${INTEREST_LABEL}:</span>
                                    <span id="interestAmountDisplay" class="text-sm font-bold text-amber-900">N$${calcInterest(balance).toFixed(2)}</span>
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
                            display.textContent = 'N$' + calcInterest(amount).toFixed(2);
                        });
                    }
                },
                preConfirm: () => {
                    const amount = document.getElementById('paymentAmountInput').value;
                    if (!amount || amount <= 0 || amount > parseFloat(balance)) {
                        Swal.showValidationMessage('Please enter a valid amount between 0.01 and ' + parseFloat(balance).toFixed(2));
                        return false;
                    }
                    const interestAmount = calcInterest(amount);
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
                                                url: 'receipt.php',
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
                        <p class="text-lg font-semibold text-gray-800 mb-4">Remaining Balance: N$${totalWithInterest(balance).toFixed(2)}</p>
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
                        <div class="bg-amber-50 border border-amber-200 rounded-md p-3 ${INTEREST_ENABLED ? '' : 'hidden'}">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-amber-800">${INTEREST_LABEL}:</span>
                                <span id="eftInterestAmountDisplay" class="text-sm font-bold text-amber-900">N$${calcInterest(balance).toFixed(2)}</span>
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
                            display.textContent = 'N$' + calcInterest(amount).toFixed(2);
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
                    const interestAmount = calcInterest(paymentAmount);
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
                                                url: 'receipt.php',
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