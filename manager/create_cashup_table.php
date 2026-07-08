<?php
/**
 * Create Cash Up Table
 * Run this script once to create the cashup_records table in pos.db
 */

// Set timezone
date_default_timezone_set('Africa/Harare');

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Create cashup_records table
$sql = "
CREATE TABLE IF NOT EXISTS cashup_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Date and Staff Info
    cashup_date DATE NOT NULL,
    cashier_id VARCHAR(100) DEFAULT 'all',
    cashier_name VARCHAR(255) DEFAULT 'All Staff',
    is_individual_cashout INTEGER DEFAULT 0,
    
    -- CASH Section
    cash_sales_expected DECIMAL(10,2) DEFAULT 0.00,
    cash_on_hand DECIMAL(10,2) DEFAULT 0.00,
    over_short DECIMAL(10,2) DEFAULT 0.00,
    
    -- CARD & CREDIT Section
    card_sales_expected DECIMAL(10,2) DEFAULT 0.00,
    unpaid_credit_sales DECIMAL(10,2) DEFAULT 0.00,
    open_tabs_balance DECIMAL(10,2) DEFAULT 0.00,
    unpaid_tabs DECIMAL(10,2) DEFAULT 0.00,
    credit_returns DECIMAL(10,2) DEFAULT 0.00,
    
    -- DEDUCTIONS Section
    expenses DECIMAL(10,2) DEFAULT 0.00,
    cash_back DECIMAL(10,2) DEFAULT 0.00,
    tips DECIMAL(10,2) DEFAULT 0.00,
    
    -- SALES SOURCES Section
    hubbly DECIMAL(10,2) DEFAULT 0.00,
    beerhouse DECIMAL(10,2) DEFAULT 0.00,
    
    -- ADJUSTMENTS Section
    voids DECIMAL(10,2) DEFAULT 0.00,
    refunds DECIMAL(10,2) DEFAULT 0.00,
    
    -- TOTAL Section
    total_items_sold DECIMAL(10,2) DEFAULT 0.00,
    
    -- Metadata
    created_by VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    
    -- Unique constraint to prevent duplicate cashups for same date/cashier
    UNIQUE(cashup_date, cashier_id)
);
";

try {
    $db->exec($sql);
    echo "SUCCESS: cashup_records table created successfully!\n";
    
    // Create index for faster queries
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cashup_date ON cashup_records(cashup_date);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cashup_cashier ON cashup_records(cashier_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cashup_created_at ON cashup_records(created_at);");
    
    echo "SUCCESS: Indexes created successfully!\n";
    
    // Show table structure
    echo "\nTable Structure:\n";
    echo str_repeat('-', 60) . "\n";
    $columns = $db->query("PRAGMA table_info(cashup_records)");
    foreach ($columns as $col) {
        echo sprintf("%-25s %-15s %s\n", 
            $col['name'], 
            $col['type'], 
            $col['notnull'] ? 'NOT NULL' : 'NULL'
        );
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nDone!\n";
