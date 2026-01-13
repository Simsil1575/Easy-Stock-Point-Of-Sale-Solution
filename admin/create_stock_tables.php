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

try {
    // Read the SQL file
    $sql = file_get_contents('../create_stock_tables.sql');
    
    // Execute the SQL statements
    $db->exec($sql);
    
    echo "Stock tracking tables created successfully!";
    echo "<br><a href='inventory'>Go back to Inventory</a>";
    
} catch (Exception $e) {
    echo "Error creating tables: " . $e->getMessage();
}
?> 