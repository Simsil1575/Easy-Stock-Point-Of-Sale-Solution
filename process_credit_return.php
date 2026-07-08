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

if (!$input || !isset($input['creditor_id']) || !isset($input['amount']) || !isset($input['reason'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

$creditorId = intval($input['creditor_id']);
$amount = floatval($input['amount']);
$reason = $input['reason'];

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit();
}

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $cashier = getCashierInfo();
    $timestamp = date('Y-m-d H:i:s');
    
    // Get creditor info
    $creditorStmt = $db->prepare("SELECT name, balance FROM creditors WHERE id = ?");
    $creditorStmt->execute([$creditorId]);
    $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$creditor) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit();
    }
    
    // Update creditor balance (reduce debt)
    $newBalance = $creditor['balance'] - $amount;
    $updateStmt = $db->prepare("UPDATE creditors SET balance = ? WHERE id = ?");
    $updateStmt->execute([$newBalance, $creditorId]);
    
    // Record the credit return transaction
    $transactionStmt = $db->prepare("
        INSERT INTO credit_transactions (creditor_id, type, amount, balance_after, description, cashier_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $description = 'Credit Return - ' . $reason;
    
    $transactionStmt->execute([
        $creditorId,
        'return',
        $amount,
        $newBalance,
        $description,
        $cashier['username'],
        $timestamp
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Credit return processed successfully',
        'customer' => $creditor['name'],
        'amount' => $amount,
        'new_balance' => $newBalance
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
