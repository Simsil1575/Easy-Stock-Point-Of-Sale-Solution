<?php
/**
 * Migration script to create receiving_records and receiving_items tables
 * Run this once to create the necessary tables for tracking receiving batches
 */

// Database connection
$db = new PDO('sqlite:../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // Create receiving_records table
    $db->exec("
        CREATE TABLE IF NOT EXISTS receiving_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            receiving_date DATETIME NOT NULL,
            total_items INTEGER NOT NULL DEFAULT 0,
            total_quantity INTEGER NOT NULL DEFAULT 0,
            total_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
            email_status TEXT NOT NULL DEFAULT 'pending' CHECK(email_status IN ('pending', 'sent', 'failed', 'skipped')),
            email_attempts INTEGER NOT NULL DEFAULT 0,
            email_error TEXT,
            email_sent_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    echo "Created receiving_records table successfully.\n";
    
    // Create receiving_items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS receiving_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            quantity_added INTEGER NOT NULL,
            old_quantity INTEGER NOT NULL,
            new_quantity INTEGER NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            buying_price DECIMAL(10,2) NOT NULL,
            total_value DECIMAL(10,2) NOT NULL,
            total_cost DECIMAL(10,2) NOT NULL,
            FOREIGN KEY(record_id) REFERENCES receiving_records(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES products(id)
        )
    ");
    
    echo "Created receiving_items table successfully.\n";
    
    // Create indexes for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_receiving_records_user ON receiving_records(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_receiving_records_date ON receiving_records(receiving_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_receiving_records_email_status ON receiving_records(email_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_receiving_items_record ON receiving_items(record_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_receiving_items_product ON receiving_items(product_id)");
    
    echo "Created indexes successfully.\n";
    echo "\n=== Migration completed successfully! ===\n";
    
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
