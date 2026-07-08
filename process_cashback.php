<?php
session_start();
require_once 'cashier_helper.php';

// Set timezone
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!validateCashierSession()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

// Support both payloads: (1) home.php style: cash_back, eft_total, transaction_date, wallet_provider, transaction_ref
// (2) simple: amount, customer, notes
$isHomeFormat = isset($input['cash_back']);
if ($isHomeFormat) {
    $amount = floatval($input['cash_back']);
    $transactionDate = $input['transaction_date'] ?? date('Y-m-d');
    $walletProvider = trim($input['wallet_provider'] ?? 'Customer');
    $transactionRef = trim($input['transaction_ref'] ?? '');
    $description = 'Cash Back' . ($walletProvider !== 'Customer' ? ' - ' . $walletProvider : '');
    $notes = $transactionRef;
    $timestamp = $transactionDate . ' 10:00:00';
} else {
    if (!isset($input['amount'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }
    $amount = floatval($input['amount']);
    $customer = $input['customer'] ?? '';
    $notes = $input['notes'] ?? '';
    $description = 'Cash Back' . ($customer ? ' - ' . $customer : '');
    $timestamp = date('Y-m-d H:i:s');
}

if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
    exit();
}

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $cashier = getCashierInfo();
    $cashierId = $cashier['username'];
    
    $db->beginTransaction();
    
    // 1. Insert cash back as cash-out in cash_transactions (deduction from till)
    $stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['cash-out', $amount, $description, $cashierId, $timestamp]);
    
    // 2. When home/cashier-center format: also record EFT so card sales and cash-up show the EFT side (customer paid by card, got cash)
    if ($isHomeFormat) {
        $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn();
        if ($eftTableExists) {
            // Placeholder order for cash-back EFT (so eft_payments has an order_id and card sales include this amount)
            $orderStmt = $db->prepare("INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (?, ?, ?, ?)");
            $orderStmt->execute([$amount, 0, $timestamp, $cashierId]);
            $orderId = $db->lastInsertId();
            
            $eftStmt = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
            $eftStmt->execute([
                $orderId,
                $transactionRef ?: 'Cash Back',
                $walletProvider,
                $amount,
                $cashierId,
                $timestamp
            ]);
        }
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Cash back processed successfully',
        'amount' => $amount
    ]);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
