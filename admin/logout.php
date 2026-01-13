<?php
session_start();

if (isset($_SESSION['user_id'])) {
    try {
        $db = new PDO('sqlite:../pos.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Record logout
        $logStmt = $db->prepare("INSERT INTO user_log (user_id, action_type) VALUES (:username, 'login')");
        $logStmt->execute([':username' => $user['username']]);
    } catch(PDOException $e) {
        error_log("Logout logging failed: " . $e->getMessage());
    }
}

// Clear session data
session_unset();
session_destroy();

// Redirect to login
header("Location: /");
exit();
