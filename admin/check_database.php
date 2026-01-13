<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

// Database connection
$db = new PDO('sqlite:../pos.db');

echo "<h2>Database Structure Check</h2>";

try {
    // Check if users table exists
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    $usersTableExists = $stmt->fetch();
    
    if ($usersTableExists) {
        echo "<p>✅ Users table exists</p>";
        
        // Check users table structure
        $stmt = $db->query("PRAGMA table_info(users)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p>Users table columns:</p><ul>";
        foreach ($columns as $column) {
            echo "<li>{$column['name']} ({$column['type']})</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>❌ Users table does not exist</p>";
        
        // Create users table
        $createUsersTable = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'cashier',
            email TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        $db->exec($createUsersTable);
        echo "<p>✅ Users table created successfully</p>";
    }
    
    // Check if opening_stock table exists
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='opening_stock'");
    $openingStockExists = $stmt->fetch();
    
    if ($openingStockExists) {
        echo "<p>✅ Opening stock table exists</p>";
    } else {
        echo "<p>❌ Opening stock table does not exist</p>";
    }
    
    // Check if closing_stock table exists
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='closing_stock'");
    $closingStockExists = $stmt->fetch();
    
    if ($closingStockExists) {
        echo "<p>✅ Closing stock table exists</p>";
    } else {
        echo "<p>❌ Closing stock table does not exist</p>";
    }
    
    // Check if daily_stock_summary table exists
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='daily_stock_summary'");
    $dailySummaryExists = $stmt->fetch();
    
    if ($dailySummaryExists) {
        echo "<p>✅ Daily stock summary table exists</p>";
    } else {
        echo "<p>❌ Daily stock summary table does not exist</p>";
    }
    
    echo "<br><a href='create_stock_tables.php'>Create Stock Tables</a>";
    echo "<br><a href='inventory'>Go back to Inventory</a>";
    
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?> 