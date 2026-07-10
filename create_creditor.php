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
    require_once __DIR__ . '/credit_limit_helper.php';
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($data['name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $creditLimit = normalizeCreditLimit($data['credit_limit'] ?? 0);
    
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
    $stmt = $db->prepare("INSERT INTO creditors (name, phone, credit_limit, active) VALUES (?, ?, ?, 1)");
    $stmt->execute([$name, $phone, $creditLimit]);
    
    $creditorId = (int) $db->lastInsertId();
    
    $newCreditor = enrichCreditorWithCreditInfo($db, [
        'id' => $creditorId,
        'name' => $name,
        'phone' => $phone,
        'active' => 1,
        'outstanding_balance' => 0,
        'total_transactions' => 0,
        'last_transaction_date' => null,
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Creditor created successfully', 'creditor' => $newCreditor]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error creating creditor: ' . $e->getMessage()]);
}
