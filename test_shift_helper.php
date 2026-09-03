<?php
// Test script for shift helper functionality
session_start();
date_default_timezone_set('Africa/Harare');

require_once __DIR__ . '/user_shift_helper.php';

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>User Log Table Schema:</h2>";
    $schema = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='user_log'")->fetchColumn();
    echo "<pre>" . htmlspecialchars($schema) . "</pre>";
    
    echo "<h2>Recent User Log Entries:</h2>";
    $logs = $db->query("SELECT * FROM user_log ORDER BY timestamp DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($logs, true) . "</pre>";
    
    // Test with a sample user if logged in
    if (isset($_SESSION['username'])) {
        $username = $_SESSION['username'];
        echo "<h2>Shift Times for User: $username</h2>";
        $shiftTimes = getUserShiftTimes($db, $username);
        echo "<pre>" . print_r($shiftTimes, true) . "</pre>";
        
        echo "<h2>Formatted Shift Data for Reports:</h2>";
        $reportShift = getCurrentUserShiftForReports($db);
        echo "<pre>" . print_r($reportShift, true) . "</pre>";
    } else {
        echo "<p>No user logged in. Please log in to test shift times.</p>";
    }
    
    echo "<h2>Test Complete!</h2>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
