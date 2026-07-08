<?php
/**
 * Migration Script: Add EFT On Hand columns to cashup_records table
 * Run this once to update the existing database structure
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
        die("\n<br>✗ Error: cashup_records table does not exist. Please create the table first.\n<br>");
    }
    
    echo "✓ cashup_records table found.\n<br>";
    
    // Check if columns already exist
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
        echo "✓ All columns already exist. No migration needed.\n<br>";
    } else {
        // Add missing columns
        foreach ($columnsToAdd as $column) {
            $sql = "ALTER TABLE cashup_records ADD COLUMN {$column} DECIMAL(10, 2) DEFAULT 0.00";
            $db->exec($sql);
            echo "✓ Added column: {$column}\n<br>";
        }
        
        echo "\n<br>✓ Migration completed successfully!\n<br>";
    }
    
    // Show updated table structure
    echo "\n<br><strong>Updated table structure:</strong>\n<br>";
    $tableInfo = $db->query("PRAGMA table_info(cashup_records)")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    foreach ($tableInfo as $col) {
        echo sprintf("%-20s %-15s %s\n", $col['name'], $col['type'], $col['dflt_value'] ? "DEFAULT {$col['dflt_value']}" : '');
    }
    echo "</pre>";
    
} catch (PDOException $e) {
    die("\n<br>✗ Migration failed: " . $e->getMessage());
}
