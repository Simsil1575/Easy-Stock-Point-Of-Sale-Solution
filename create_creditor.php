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
    $phone = trim($data['phone'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Creditor name is required']);
        exit();
    }
    
    // Check if creditor with same name already exists (case-insensitive)
    $checkStmt = $db->prepare("SELECT id FROM creditors WHERE LOWER(name) = LOWER(?) AND active = 1");
    $checkStmt->execute([$name]);
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A creditor with this name already exists']);
        exit();
    }
    
    // Insert new creditor
    $stmt = $db->prepare("INSERT INTO creditors (name, phone, active) VALUES (?, ?, 1)");
    $stmt->execute([$name, $phone]);
    
    $creditorId = $db->lastInsertId();
    
    // Fetch the newly created creditor with balance
    $newCreditor = $db->query("
        SELECT 
            c.id,
            c.name,
            c.phone,
            c.active,
            0 as outstanding_balance,
            0 as total_transactions,
            NULL as last_transaction_date
        FROM creditors c
        WHERE c.id = $creditorId
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'message' => 'Creditor created successfully', 'creditor' => $newCreditor]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error creating creditor: ' . $e->getMessage()]);
}
