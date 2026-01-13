<?php
// Script to create the missing mixed_payments table
try {
    $db = new PDO('sqlite:pos.db');
    
    // Create the mixed_payments table
    $sql = "CREATE TABLE IF NOT EXISTS mixed_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        eft_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        eft_transaction_ref TEXT,
        eft_wallet_provider TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        cashier_id INTEGER,
        FOREIGN KEY(order_id) REFERENCES orders(id)
    )";
    
    $db->exec($sql);
    
    // Create indexes for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_mixed_payments_order ON mixed_payments(order_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_mixed_payments_date ON mixed_payments(created_at)");
    
    echo "Successfully created mixed_payments table and indexes!";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
