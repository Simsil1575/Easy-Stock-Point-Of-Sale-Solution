<?php
try {
    // Connect to user database
    $userDb = new PDO('sqlite:user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all sessions
    $stmt = $userDb->query("SELECT * FROM user_sessions ORDER BY created_at DESC");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h1>User Sessions</h1>";
    echo "<pre>";
    print_r($sessions);
    echo "</pre>";
    
    // Check for auth_token cookie
    echo "<h1>Auth Token Cookie</h1>";
    if (isset($_COOKIE['auth_token'])) {
        echo "Cookie value: " . htmlspecialchars($_COOKIE['auth_token']);
    } else {
        echo "No auth_token cookie found";
    }
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 