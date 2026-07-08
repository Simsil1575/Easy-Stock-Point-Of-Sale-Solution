<?php
/**
 * Test script to verify cash_transactions table creation
 */

date_default_timezone_set('Africa/Harare');

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Testing cash_transactions Table</h2>";
    
    // Check if table exists
    $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cash_transactions'");
    $exists = $check->fetch();
    
    if ($exists) {
        echo "<p style='color: green;'>✅ Table cash_transactions EXISTS</p>";
        
        // Show table structure
        $columns = $db->query("PRAGMA table_info(cash_transactions)")->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>Table Structure:</h3><ul>";
        foreach ($columns as $col) {
            echo "<li>{$col['name']} ({$col['type']})</li>";
        }
        echo "</ul>";
        
        // Test query
        $test = $db->query("SELECT COUNT(*) FROM cash_transactions")->fetchColumn();
        echo "<p>Total records: $test</p>";
        
    } else {
        echo "<p style='color: red;'>❌ Table cash_transactions DOES NOT EXIST</p>";
        echo "<p>Attempting to create table...</p>";
        
        // Create table
        $db->exec("
            CREATE TABLE IF NOT EXISTS cash_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                cashier_id INTEGER
            )
        ");
        
        // Verify creation
        $check2 = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cash_transactions'");
        if ($check2->fetch()) {
            echo "<p style='color: green;'>✅ Table created successfully!</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to create table</p>";
        }
    }
    
    echo "<p><a href='cash.php'>Go to Cash Management</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}
?>
