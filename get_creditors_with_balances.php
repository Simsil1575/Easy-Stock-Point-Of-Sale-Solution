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

try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/credit_limit_helper.php';
    
    // Fetch creditors with their outstanding balances
    $creditors = $db->query("
        SELECT 
            c.id,
            c.name,
            c.phone,
            c.active,
            COALESCE(c.credit_limit, 0) AS credit_limit,
            COALESCE(SUM(cs.total_amount - cs.paid_amount), 0) as outstanding_balance,
            COUNT(cs.id) as total_transactions,
            MAX(cs.created_at) as last_transaction_date
        FROM creditors c
        LEFT JOIN credit_sales cs ON c.id = cs.creditor_id AND cs.payment_status != 'paid'
        WHERE c.active = 1
        GROUP BY c.id, c.name, c.phone, c.active, c.credit_limit
        ORDER BY c.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($creditors as &$creditor) {
        $creditor = enrichCreditorWithCreditInfo($db, $creditor);
    }
    unset($creditor);
    
    echo json_encode(['success' => true, 'creditors' => $creditors]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching creditors: ' . $e->getMessage()]);
}
