<?php
header('Content-Type: application/json');

// Start session
session_start();

// Set Central Africa Time timezone
date_default_timezone_set('Africa/Harare');

// Database connection
$db = new PDO('sqlite:pos.db');

// Get the raw POST data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

try {
    // Start a transaction
    $db->beginTransaction();

    $eftTotal = floatval($data['eft_total']);
    $cashBack = floatval($data['cash_back']);
    $saleAmount = floatval($data['sale_amount']);
    $transactionRef = $data['transaction_ref'] ?? null;
    $walletProvider = $data['wallet_provider'] ?? 'Cash Back';

    // 1. Create an order record with the sale amount (net of cash back)
    $stmtOrder = $db->prepare("INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (:total, :cash_received, :created_at, :cashier_id)");
    $stmtOrder->execute([
        ':total' => $saleAmount,
        ':cash_received' => 0, // All payment is EFT, no cash received
        ':created_at' => date('Y-m-d H:i:s'),
        ':cashier_id' => $_SESSION['username'] ?? 'Unknown'
    ]);

    $orderId = $db->lastInsertId();

    // 2. Create EFT payment record for the FULL EFT total (including cash back portion)
    $stmtEft = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (:order_id, :transaction_ref, :wallet_provider, :amount, :cashier_id, :payment_date)");
    $stmtEft->execute([
        ':order_id' => $orderId,
        ':transaction_ref' => $transactionRef,
        ':wallet_provider' => $walletProvider,
        ':amount' => $eftTotal,
        ':cashier_id' => $_SESSION['username'] ?? 'Unknown',
        ':payment_date' => date('Y-m-d H:i:s')
    ]);

    // 3. Create cash-out transaction for the cash back amount (only if cash back > 0)
    if ($cashBack > 0) {
        $stmtCashOut = $db->prepare("INSERT INTO cash_transactions (type, amount, description, created_at) VALUES (:type, :amount, :description, :created_at)");
        $stmtCashOut->execute([
            ':type' => 'cash-out',
            ':amount' => $cashBack,
            ':description' => 'Cash Back - ' . ($transactionRef ?? 'Unknown'),
            ':created_at' => date('Y-m-d H:i:s', strtotime('+2 hours'))
        ]);
    }

    // Commit the transaction
    $db->commit();

    echo json_encode(['success' => true, 'order_id' => $orderId]);
} catch (Exception $e) {
    // Rollback the transaction in case of error
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

