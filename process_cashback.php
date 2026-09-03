<?php
session_start();
require_once 'cashier_helper.php';
require_once __DIR__ . '/cashback_accounting_helper.php';
require_once __DIR__ . '/cashback_receipt_helper.php';

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
    $timestamp = $transactionDate . ' 10:00:00';
} else {
    if (!isset($input['amount'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }
    $amount = floatval($input['amount']);
    $customer = $input['customer'] ?? '';
    $description = 'Cash Back' . ($customer ? ' - ' . $customer : '');
    $timestamp = date('Y-m-d H:i:s');
    $walletProvider = 'Customer';
    $transactionRef = '';
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

    $accounting = recordCashBackAccounting(
        $db,
        $amount,
        $cashierId,
        $timestamp,
        $description,
        $isHomeFormat,
        $walletProvider,
        $transactionRef
    );

    $db->commit();

    $cashBackReceipt = buildCashBackReceiptData([
        'order_id' => $accounting['order_id'] ?? null,
        'cash_transaction_id' => $accounting['cash_transaction_id'] ?? null,
        'amount' => $amount,
        'wallet_provider' => $walletProvider,
        'transaction_ref' => $transactionRef,
        'transaction_date' => $isHomeFormat ? $transactionDate : date('Y-m-d'),
        'cashier_username' => $cashierId,
        'description' => $description,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Cash back processed successfully',
        'amount' => $amount,
        'cash_back_receipt' => $cashBackReceipt,
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
    ]);
}
