<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get order_id from POST
$orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit();
}

try {
    // Database connection
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch order details
    $orderStmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit();
    }
    
    // Fetch order items
    $itemsStmt = $db->prepare("SELECT product_name as name, quantity, price FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch EFT payment if exists
    $eftStmt = $db->prepare("SELECT * FROM eft_payments WHERE order_id = ? LIMIT 1");
    $eftStmt->execute([$orderId]);
    $eftPayment = $eftStmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch mixed payment if exists
    $mixedStmt = $db->prepare("SELECT * FROM mixed_payments WHERE order_id = ? LIMIT 1");
    $mixedStmt->execute([$orderId]);
    $mixedPayment = $mixedStmt->fetch(PDO::FETCH_ASSOC);
    
    // Format response
    $response = [
        'success' => true,
        'order' => $order,
        'items' => $items,
        'eft_payment' => $eftPayment ?: null,
        'mixed_payment' => $mixedPayment ?: null
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>
