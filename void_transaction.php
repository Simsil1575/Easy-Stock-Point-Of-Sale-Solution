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

    // Create void_transactions table if it doesn't exist
    $db->exec("CREATE TABLE IF NOT EXISTS void_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        total DECIMAL(10, 2) NOT NULL,
        cash_received DECIMAL(10, 2) NOT NULL,
        items TEXT NOT NULL,
        payment_method TEXT,
        transaction_ref TEXT,
        wallet_provider TEXT,
        eft_amount DECIMAL(10, 2),
        cashier_id TEXT,
        voided_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        void_source TEXT DEFAULT 'void',
        credit_sale_id INTEGER,
        creditor_name TEXT,
        FOREIGN KEY(order_id) REFERENCES orders(id)
    )");
    try {
        $db->exec("ALTER TABLE void_transactions ADD COLUMN void_source TEXT DEFAULT 'void'");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE void_transactions ADD COLUMN credit_sale_id INTEGER NULL");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE void_transactions ADD COLUMN creditor_name TEXT NULL");
    } catch (Exception $e) {
    }

    // Determine cash_received based on payment method
    $cashReceived = 0;
    if (isset($data['payment_method'])) {
        if ($data['payment_method'] === 'mixed') {
            $cashReceived = isset($data['cash_amount']) ? floatval($data['cash_amount']) : 0;
        } elseif ($data['payment_method'] === 'cash') {
            $cashReceived = isset($data['cash_received']) ? floatval($data['cash_received']) : 0;
        } else {
            // EFT/e-wallet payments have no cash received
            $cashReceived = 0;
        }
    } else {
        $cashReceived = isset($data['cash_received']) ? floatval($data['cash_received']) : 0;
    }

    // Insert void transaction record
    $stmt = $db->prepare("INSERT INTO void_transactions (order_id, total, cash_received, items, payment_method, transaction_ref, wallet_provider, eft_amount, cashier_id, voided_at) VALUES (:order_id, :total, :cash_received, :items, :payment_method, :transaction_ref, :wallet_provider, :eft_amount, :cashier_id, :voided_at)");
    $stmt->execute([
        ':order_id' => $data['order_id'] ?? null,
        ':total' => $data['total'],
        ':cash_received' => $cashReceived,
        ':items' => json_encode($data['items'] ?? []),
        ':payment_method' => $data['payment_method'] ?? 'cash',
        ':transaction_ref' => $data['transaction_ref'] ?? $data['ref'] ?? null,
        ':wallet_provider' => $data['wallet_provider'] ?? $data['provider'] ?? null,
        ':eft_amount' => isset($data['eft_amount']) ? floatval($data['eft_amount']) : null,
        ':cashier_id' => $_SESSION['username'] ?? 'Unknown',
        ':voided_at' => date('Y-m-d H:i:s')
    ]);

    // Commit the transaction
    $db->commit();

    echo json_encode(['success' => true, 'message' => 'Transaction voided successfully']);
} catch (Exception $e) {
    // Rollback the transaction in case of error
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
