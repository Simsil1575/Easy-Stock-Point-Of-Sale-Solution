<?php
session_start();
header('Content-Type: application/json');

try {
    // Get the directory where this script is located
    $scriptDir = dirname(__FILE__);
    $dbPath = $scriptDir . '/../pos.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    if (!empty($search)) {
        // Check if search is numeric (order ID) or string (date)
        if (is_numeric($search)) {
            // Search by order ID
            $stmt = $db->prepare("
                SELECT o.id, o.total, o.cash_received, o.created_at, o.cashier_id,
                       COUNT(oi.id) as item_count
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.id = :search
                GROUP BY o.id
                ORDER BY o.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':search', intval($search), PDO::PARAM_INT);
        } else {
            // Search by date
            $stmt = $db->prepare("
                SELECT o.id, o.total, o.cash_received, o.created_at, o.cashier_id,
                       COUNT(oi.id) as item_count
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE DATE(o.created_at) LIKE :search
                GROUP BY o.id
                ORDER BY o.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    } else {
        // Get recent transactions
        $stmt = $db->prepare("
            SELECT o.id, o.total, o.cash_received, o.created_at, o.cashier_id,
                   COUNT(oi.id) as item_count
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
