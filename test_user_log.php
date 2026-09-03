<?php
// Test script to check user_log data
date_default_timezone_set('Africa/Harare');

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>User Log Table Schema:</h2>";
    $schema = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='user_log'")->fetchColumn();
    echo "<pre>" . htmlspecialchars($schema) . "</pre>";
    
    echo "<h2>All User Log Entries (Last 20):</h2>";
    $logs = $db->query("SELECT * FROM user_log ORDER BY timestamp DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Action Type</th><th>Timestamp</th><th>Date Only</th></tr>";
    foreach ($logs as $log) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($log['id']) . "</td>";
        echo "<td>" . htmlspecialchars($log['user_id']) . "</td>";
        echo "<td>" . htmlspecialchars($log['action_type']) . "</td>";
        echo "<td>" . htmlspecialchars($log['timestamp']) . "</td>";
        echo "<td>" . date('Y-m-d', strtotime($log['timestamp'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>Distinct Users in Log:</h2>";
    $users = $db->query("SELECT DISTINCT user_id FROM user_log ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
    echo "<pre>" . print_r($users, true) . "</pre>";
    
    echo "<h2>Test Query for 'Cashier' on 2026-08-20:</h2>";
    $testDate = '2026-08-20';
    $testUser = 'Cashier';
    
    $stmt = $db->prepare("
        SELECT user_id, action_type, timestamp, DATE(timestamp) as date_only
        FROM user_log 
        WHERE user_id = :username 
          AND DATE(timestamp) = :date
        ORDER BY timestamp ASC
    ");
    $stmt->execute([':username' => $testUser, ':date' => $testDate]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($results) . " records:</p>";
    echo "<pre>" . print_r($results, true) . "</pre>";
    
    echo "<h2>Test Query for 'Admin' on 2026-08-28:</h2>";
    $testDate2 = '2026-08-28';
    $testUser2 = 'Admin';
    
    $stmt2 = $db->prepare("
        SELECT user_id, action_type, timestamp, DATE(timestamp) as date_only
        FROM user_log 
        WHERE user_id = :username 
          AND DATE(timestamp) = :date
        ORDER BY timestamp ASC
    ");
    $stmt2->execute([':username' => $testUser2, ':date' => $testDate2]);
    $results2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($results2) . " records:</p>";
    echo "<pre>" . print_r($results2, true) . "</pre>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
