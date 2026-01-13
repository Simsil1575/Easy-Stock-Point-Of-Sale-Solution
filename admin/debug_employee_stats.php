<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Database connection
try {
    $db = new PDO("sqlite:../pos.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo json_encode(['success' => true, 'message' => 'Database connected successfully']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Test basic queries
try {
    // Test if tables exist
    $tables = ['orders', 'eft_payments', 'credit_sales', 'payments', 'users'];
    $tableStatus = [];
    
    foreach ($tables as $table) {
        try {
            $result = $db->query("SELECT COUNT(*) FROM $table LIMIT 1");
            $tableStatus[$table] = 'exists';
        } catch (PDOException $e) {
            $tableStatus[$table] = 'missing';
        }
    }
    
    // Test cashier_id data
    $cashierData = [];
    
    // Check orders table
    try {
        $result = $db->query("SELECT COUNT(*) as count, COUNT(DISTINCT cashier_id) as unique_cashiers FROM orders WHERE cashier_id IS NOT NULL AND cashier_id != ''");
        $cashierData['orders'] = $result->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cashierData['orders'] = ['error' => $e->getMessage()];
    }
    
    // Check eft_payments table
    try {
        $result = $db->query("SELECT COUNT(*) as count, COUNT(DISTINCT cashier_id) as unique_cashiers FROM eft_payments WHERE cashier_id IS NOT NULL AND cashier_id != ''");
        $cashierData['eft_payments'] = $result->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cashierData['eft_payments'] = ['error' => $e->getMessage()];
    }
    
    // Check credit_sales table
    try {
        $result = $db->query("SELECT COUNT(*) as count, COUNT(DISTINCT cashier_id) as unique_cashiers FROM credit_sales WHERE cashier_id IS NOT NULL AND cashier_id != ''");
        $cashierData['credit_sales'] = $result->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cashierData['credit_sales'] = ['error' => $e->getMessage()];
    }
    
    // Check payments table
    try {
        $result = $db->query("SELECT COUNT(*) as count, COUNT(DISTINCT cashier_id) as unique_cashiers FROM payments WHERE cashier_id IS NOT NULL AND cashier_id != ''");
        $cashierData['payments'] = $result->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cashierData['payments'] = ['error' => $e->getMessage()];
    }
    
    // Check users table
    try {
        $result = $db->query("SELECT COUNT(*) as count FROM users WHERE role IN ('admin', 'manager', 'employee')");
        $cashierData['users'] = $result->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cashierData['users'] = ['error' => $e->getMessage()];
    }
    
    $response = [
        'success' => true,
        'tableStatus' => $tableStatus,
        'cashierData' => $cashierData,
        'session' => [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ]
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Debug error: ' . $e->getMessage()]);
}
?> 