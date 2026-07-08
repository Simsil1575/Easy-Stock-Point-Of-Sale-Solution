<?php
session_start();
header('Content-Type: application/json');

try {
    // Get the directory where this script is located
    $scriptDir = dirname(__FILE__);
    $dbPath = $scriptDir . '/../pos.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    
    if ($orderId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid order ID'
        ]);
        exit;
    }
    
    // Get order details
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = :id");
    $stmt->bindValue(':id', $orderId, PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode([
            'success' => false,
            'message' => 'Order not found'
        ]);
        exit;
    }
    
    // Get order items - using the same structure as reports.php
    $stmt = $db->prepare("
        SELECT oi.id, oi.order_id, oi.product_name, oi.quantity, oi.price, oi.buying_price
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id
    ");
    $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        echo json_encode([
            'success' => false,
            'message' => 'No items found for this order'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
