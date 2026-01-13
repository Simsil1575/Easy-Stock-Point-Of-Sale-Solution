<?php
session_start();
header('Content-Type: application/json');

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($data['name'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Table name is required']);
        exit();
    }
    
    // Check if table with same name already exists and is open
    $checkStmt = $db->prepare("SELECT id FROM tabs WHERE LOWER(tab_name) = LOWER(?) AND status = 'open'");
    $checkStmt->execute([$name]);
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A table with this name is already open']);
        exit();
    }
    
    // Insert new table/tab
    $stmt = $db->prepare("
        INSERT INTO tabs (tab_name, current_balance, status, opened_at, cashier_id) 
        VALUES (?, 0, 'open', datetime('now'), ?)
    ");
    $stmt->execute([$name, $_SESSION['user_id']]);
    
    $tableId = $db->lastInsertId();
    
    // Fetch the newly created table
    $newTable = $db->query("
        SELECT 
            id,
            tab_name as name,
            current_balance as balance,
            status,
            opened_at
        FROM tabs
        WHERE id = $tableId
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'message' => 'Table created successfully', 'table' => $newTable]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error creating table: ' . $e->getMessage()]);
}






