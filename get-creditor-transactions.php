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

// Check activation status
$pdo = new PDO('sqlite:active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Software not activated']);
    exit();
}

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get creditor_id from GET parameter
    $creditorId = $_GET['creditor_id'] ?? null;
    
    if (!$creditorId || !is_numeric($creditorId)) {
        throw new Exception('Valid creditor ID is required');
    }
    
    // Validate creditor exists
    $creditorStmt = $db->prepare("SELECT * FROM creditors WHERE id = ? AND active = 1");
    $creditorStmt->execute([$creditorId]);
    $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$creditor) {
        throw new Exception('Creditor not found or inactive');
    }
    
    // Get all credit sales for this creditor with their items
    $salesStmt = $db->prepare("
        SELECT 
            cs.id,
            cs.total_amount,
            cs.paid_amount,
            cs.due_date,
            cs.created_at,
            cs.payment_status,
            cs.cashier_id,
            (cs.total_amount - cs.paid_amount) as balance
        FROM credit_sales cs
        WHERE cs.creditor_id = ?
        ORDER BY cs.created_at DESC
    ");
    $salesStmt->execute([$creditorId]);
    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get items for each sale
    $transactions = [];
    foreach ($sales as $sale) {
        $itemsStmt = $db->prepare("
            SELECT product_name, quantity, price
            FROM credit_sale_items
            WHERE sale_id = ?
        ");
        $itemsStmt->execute([$sale['id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format items as a string for the receipt
        $itemsString = '';
        foreach ($items as $item) {
            $itemsString .= $item['product_name'] . ' (x' . $item['quantity'] . '), ';
        }
        $itemsString = rtrim($itemsString, ', ');
        
        $transactions[] = [
            'id' => $sale['id'],
            'date' => $sale['created_at'],
            'total_amount' => $sale['total_amount'],
            'paid_amount' => $sale['paid_amount'],
            'balance' => $sale['balance'],
            'due_date' => $sale['due_date'],
            'payment_status' => $sale['payment_status'],
            'items' => $itemsString,
            'cashier_id' => $sale['cashier_id']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'creditor' => $creditor,
        'count' => count($transactions)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
