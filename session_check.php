<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to check if user is logged in
function checkLogin() {
    // Debug session data
    error_log("Session data: " . print_r($_SESSION, true));
    
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        // Clear any remnants of failed sessions
        session_unset();
        session_destroy();
        
        // Start a fresh session
        session_start();
        
        // Set a temporary message to explain redirect
        $_SESSION['login_message'] = "Please log in to continue.";
        
        // Redirect to login page with relative path
        header("Location: index.php");
        exit();
    }
    
    // For debugging - uncomment this in development
    error_log("User is logged in. User ID: " . $_SESSION['user_id'] . ", Username: " . $_SESSION['username'] . ", Role: " . $_SESSION['role']);
}

// Call the function to check login status
checkLogin();
?> 