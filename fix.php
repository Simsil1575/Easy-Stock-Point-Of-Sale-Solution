<?php
/**
 * Migration script to fix the tab_payments CHECK constraint
 * This adds 'mixed' as a valid payment_method option
 */

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Starting migration to fix tab_payments CHECK constraint...\n";
    
    $db->beginTransaction();
    
    // 1. Create a new table with the corrected CHECK constraint
    $db->exec("
        CREATE TABLE IF NOT EXISTS tab_payments_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tab_id INTEGER NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            payment_method TEXT NOT NULL CHECK(payment_method IN ('cash', 'eft', 'mixed')),
            transaction_ref TEXT,
            wallet_provider TEXT,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            cashier_id INTEGER,
            order_id INTEGER,
            FOREIGN KEY(cashier_id) REFERENCES users(id),
            FOREIGN KEY(tab_id) REFERENCES tabs(id) ON DELETE CASCADE
        )
    ");
    
    // 2. Copy existing data from the old table
    $db->exec("
        INSERT INTO tab_payments_new (id, tab_id, amount, payment_method, transaction_ref, wallet_provider, payment_date, cashier_id, order_id)
        SELECT id, tab_id, amount, payment_method, transaction_ref, wallet_provider, payment_date, cashier_id, order_id
        FROM tab_payments
    ");
    
    // 3. Drop the old table
    $db->exec("DROP TABLE tab_payments");
    
    // 4. Rename the new table to the original name
    $db->exec("ALTER TABLE tab_payments_new RENAME TO tab_payments");
    
    $db->commit();
    
    echo "Migration completed successfully!\n";
    echo "The tab_payments table now accepts 'cash', 'eft', and 'mixed' as valid payment methods.\n";
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
