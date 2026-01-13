<?php
// delete_records_ajax.php

try {
    // Database connection
    $db = new PDO('sqlite:pos.db');
    
    // Disable foreign keys temporarily
    $db->exec('PRAGMA foreign_keys = OFF');
    
    // Delete all records from the tables
    $db->exec("DELETE FROM orders");
    $db->exec("DELETE FROM order_items");
    
    // Re-enable foreign keys
    $db->exec('PRAGMA foreign_keys = ON');
    
    // Send a success response
    echo "All records deleted successfully.";
} catch (PDOException $e) {
    // Send an error response
    echo "Error deleting records: " . $e->getMessage();
}
