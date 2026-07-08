<?php
/**
 * Cashier Helper - Centralized cashier_id handling for all transactions
 * 
 * USAGE:
 * 1. Include this file: require_once 'cashier_helper.php';
 * 2. Get cashier info: $cashier = getCashierInfo();
 * 3. Use in INSERT: $cashier['username'] for cashier_id column
 * 
 * This ensures consistent cashier tracking across all transactions.
 */

/**
 * Get current cashier information from session
 * Returns array with both id and username for flexibility
 * 
 * @return array ['id' => int|null, 'username' => string]
 */
function getCashierInfo() {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? 'Unknown'
    ];
}

/**
 * Get cashier username for INSERT statements
 * This is the PRIMARY method to use for all cashier_id insertions
 * 
 * @return string Username or 'Unknown' if not logged in
 */
function getCashierUsername() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return $_SESSION['username'] ?? 'Unknown';
}

/**
 * Get cashier user ID for INSERT statements (when numeric ID is required)
 * 
 * @return int|null User ID or null if not logged in
 */
function getCashierId() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return $_SESSION['user_id'] ?? null;
}

/**
 * Validate that a user is logged in before processing transactions
 * 
 * @param bool $redirect If true, redirects to login page
 * @return bool True if logged in, false otherwise
 */
function validateCashierSession($redirect = false) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $isLoggedIn = isset($_SESSION['user_id']) && isset($_SESSION['username']);
    
    if (!$isLoggedIn && $redirect) {
        header("Location: /");
        exit();
    }
    
    return $isLoggedIn;
}

/**
 * Convert a cashier_id (username or ID) to username
 * Useful for display and reporting
 * 
 * @param mixed $cashierId The cashier_id value (username string or numeric ID)
 * @param PDO $userDb Connection to user.db
 * @return string The username
 */
function resolveToUsername($cashierId, $userDb = null) {
    if (empty($cashierId)) {
        return 'Unknown';
    }
    
    // If it's already a non-numeric string, return as-is
    if (!is_numeric($cashierId)) {
        return $cashierId;
    }
    
    // If numeric, try to lookup username from user.db
    if ($userDb !== null) {
        try {
            $stmt = $userDb->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$cashierId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user['username'];
            }
        } catch (PDOException $e) {
            // Silently fail and return the ID as string
        }
    }
    
    return (string)$cashierId;
}

/**
 * Convert a cashier_id (username or ID) to numeric ID
 * Useful when you need the user ID
 * 
 * @param mixed $cashierId The cashier_id value (username string or numeric ID)
 * @param PDO $userDb Connection to user.db
 * @return int|null The user ID or null if not found
 */
function resolveToUserId($cashierId, $userDb) {
    if (empty($cashierId)) {
        return null;
    }
    
    // If already numeric, return as int
    if (is_numeric($cashierId)) {
        return (int)$cashierId;
    }
    
    // Lookup by username
    try {
        $stmt = $userDb->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$cashierId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return (int)$user['id'];
        }
    } catch (PDOException $e) {
        // Silently fail
    }
    
    return null;
}

/**
 * Build a WHERE clause that handles both username and ID formats
 * Useful for filtering queries by cashier
 * 
 * @param string $columnName The cashier_id column name
 * @param mixed $cashierValue The value to filter by (username or ID)
 * @return string SQL WHERE clause fragment
 */
function buildCashierWhereClause($columnName, $cashierValue) {
    if (empty($cashierValue) || $cashierValue === 'all') {
        return "";
    }
    
    // Handle both string username and numeric ID
    return " AND ($columnName = :cashier_filter OR CAST($columnName AS TEXT) = :cashier_filter)";
}
