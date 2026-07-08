<?php
/**
 * Create cashup_records table if it doesn't exist
 * Then add EFT columns
 */

// Set timezone
date_default_timezone_set('Africa/Harare');

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to database successfully.\n<br>";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

try {
    // Check if cashup_records table exists
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cashup_records'")->fetch();
    
    if (!$result) {
        echo "Creating cashup_records table...\n<br>";
        
        // Create the table with EFT columns included
        $createTable = "CREATE TABLE IF NOT EXISTS cashup_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashup_date DATE NOT NULL,
            cashier_id VARCHAR(100) DEFAULT 'all',
            cashier_name VARCHAR(255) DEFAULT 'All Staff',
            is_individual_cashout INTEGER DEFAULT 0,
            cash_sales_expected DECIMAL(10, 2) DEFAULT 0.00,
            cash_on_hand DECIMAL(10, 2) DEFAULT 0.00,
            over_short DECIMAL(10, 2) DEFAULT 0.00,
            card_sales_expected DECIMAL(10, 2) DEFAULT 0.00,
            eft_on_hand DECIMAL(10, 2) DEFAULT 0.00,
            eft_over_short DECIMAL(10, 2) DEFAULT 0.00,
            unpaid_credit_sales DECIMAL(10, 2) DEFAULT 0.00,
            open_tabs_balance DECIMAL(10, 2) DEFAULT 0.00,
            unpaid_tabs DECIMAL(10, 2) DEFAULT 0.00,
            credit_returns DECIMAL(10, 2) DEFAULT 0.00,
            expenses DECIMAL(10, 2) DEFAULT 0.00,
            cash_back DECIMAL(10, 2) DEFAULT 0.00,
            tips DECIMAL(10, 2) DEFAULT 0.00,
            hubbly DECIMAL(10, 2) DEFAULT 0.00,
            beerhouse DECIMAL(10, 2) DEFAULT 0.00,
            voids DECIMAL(10, 2) DEFAULT 0.00,
            refunds DECIMAL(10, 2) DEFAULT 0.00,
            total_items_sold DECIMAL(10, 2) DEFAULT 0.00,
            created_by VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            UNIQUE(cashup_date, cashier_id)
        )";
        
        $db->exec($createTable);
        echo "✓ Created cashup_records table with EFT columns.\n<br>";
        
        // Create indexes
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_cashup_date ON cashup_records (cashup_date)",
            "CREATE INDEX IF NOT EXISTS idx_cashup_cashier ON cashup_records (cashier_id)",
            "CREATE INDEX IF NOT EXISTS idx_cashup_created_at ON cashup_records (created_at)"
        ];
        
        foreach ($indexes as $index) {
            $db->exec($index);
        }
        echo "✓ Created indexes.\n<br>";
        
    } else {
        echo "✓ cashup_records table exists.\n<br>";
        
        // Check if EFT columns exist and add them if needed
        $tableInfo = $db->query("PRAGMA table_info(cashup_records)")->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($tableInfo, 'name');
        
        $columnsToAdd = [];
        
        if (!in_array('eft_on_hand', $columns)) {
            $columnsToAdd[] = 'eft_on_hand';
        }
        
        if (!in_array('eft_over_short', $columns)) {
            $columnsToAdd[] = 'eft_over_short';
        }
        
        if (empty($columnsToAdd)) {
            echo "✓ All EFT columns already exist. No migration needed.\n<br>";
        } else {
            // Add missing columns
            foreach ($columnsToAdd as $column) {
                $sql = "ALTER TABLE cashup_records ADD COLUMN {$column} DECIMAL(10, 2) DEFAULT 0.00";
                $db->exec($sql);
                echo "✓ Added column: {$column}\n<br>";
            }
            
            echo "\n<br>✓ Migration completed successfully!\n<br>";
        }
    }
    
    // Show final table structure
    echo "\n<br><strong>Final table structure:</strong>\n<br>";
    $tableInfo = $db->query("PRAGMA table_info(cashup_records)")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    echo sprintf("%-25s %-20s %-15s\n", "Column Name", "Type", "Default");
    echo str_repeat("-", 60) . "\n";
    foreach ($tableInfo as $col) {
        $default = $col['dflt_value'] !== null ? $col['dflt_value'] : 'NULL';
        echo sprintf("%-25s %-20s %-15s\n", $col['name'], $col['type'], $default);
    }
    echo "</pre>";
    
    echo "\n<br><strong>✓ Setup complete! You can now use the EFT on hand feature.</strong>\n<br>";
    
} catch (PDOException $e) {
    die("\n<br>✗ Error: " . $e->getMessage());
}
