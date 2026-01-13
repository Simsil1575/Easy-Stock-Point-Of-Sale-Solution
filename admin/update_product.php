<?php
// Ensure no output before JSON
ob_start();

header('Content-Type: application/json');

date_default_timezone_set('Africa/Johannesburg'); // GMT+2 (Windhoek is actually GMT+2, but using Johannesburg which is also GMT+2)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
        exit;
    }
    
    if (!isset($data['id']) || !isset($data['column']) || !isset($data['value'])) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    try {
        $db = new SQLite3('../pos.db');
        
        if (!$db) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
    
        // Determine the correct data type based on column
        $dataTypes = [
            'name' => SQLITE3_TEXT,
            'quantity' => SQLITE3_INTEGER,
            'price' => SQLITE3_FLOAT,
            'buying_price' => SQLITE3_FLOAT
        ];
        
        $dataType = $dataTypes[$data['column']] ?? SQLITE3_TEXT;
    
        // Track stock changes for quantity updates
        if ($data['column'] === 'quantity') {
            // Get old quantity before update
            $stmtSelect = $db->prepare("SELECT quantity FROM products WHERE id = :id");
            $stmtSelect->bindValue(':id', $data['id'], SQLITE3_INTEGER);
            $result = $stmtSelect->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $oldQuantity = $row['quantity'] ?? 0;
        
            // Handle empty or invalid quantity values
            $newQuantity = 0;
            if (!empty($data['value']) && is_numeric($data['value'])) {
                $newQuantity = (int)$data['value'];
            }
            
            // Calculate quantity change and determine action
            $quantityChange = $newQuantity - $oldQuantity;
            
            // Always record stock changes if there's a difference, even if new quantity is 0
            if ($quantityChange !== 0) {
                $action = $quantityChange > 0 ? 'Restock' : 'Adjust';
                
                // Insert stock change record
                $stmtInsert = $db->prepare("INSERT INTO stock_changes 
                    (product_id, action, quantity_change, old_quantity, new_quantity, changed_at)
                    VALUES (:product_id, :action, :quantity_change, :old_quantity, :new_quantity, datetime('now'))");
                $stmtInsert->bindValue(':product_id', $data['id'], SQLITE3_INTEGER);
                $stmtInsert->bindValue(':action', $action, SQLITE3_TEXT);
                $stmtInsert->bindValue(':quantity_change', $quantityChange, SQLITE3_INTEGER);
                $stmtInsert->bindValue(':old_quantity', $oldQuantity, SQLITE3_INTEGER);
                $stmtInsert->bindValue(':new_quantity', $newQuantity, SQLITE3_INTEGER);
                $stmtInsert->execute();
            
                // Update daily stock summary for receiving (when quantity increases)
                if ($quantityChange > 0) {
                    $today = date('Y-m-d');
                    
                    // Update or insert daily stock summary - only update received quantity for restocks
                    $summaryStmt = $db->prepare("
                        INSERT OR REPLACE INTO daily_stock_summary 
                        (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                        VALUES (
                            ?,
                            ?,
                            COALESCE((SELECT opening_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                            COALESCE((SELECT closing_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), ?),
                            COALESCE((SELECT received_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0) + ?,
                            COALESCE((SELECT sold_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0),
                            COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = ?), 0)
                        )
                    ");
                    $summaryStmt->bindValue(1, $today, SQLITE3_TEXT);
                    $summaryStmt->bindValue(2, $data['id'], SQLITE3_INTEGER);
                    $summaryStmt->bindValue(3, $today, SQLITE3_TEXT);
                    $summaryStmt->bindValue(4, $data['id'], SQLITE3_INTEGER);
                    $summaryStmt->bindValue(5, $today, SQLITE3_TEXT);
                    $summaryStmt->bindValue(6, $data['id'], SQLITE3_INTEGER);
                    $summaryStmt->bindValue(7, $newQuantity, SQLITE3_INTEGER);
                    $summaryStmt->bindValue(8, $today, SQLITE3_TEXT);
                    $summaryStmt->bindValue(9, $data['id'], SQLITE3_INTEGER);
                    $summaryStmt->bindValue(10, $quantityChange, SQLITE3_INTEGER);
                    $summaryStmt->bindValue(11, $today, SQLITE3_TEXT);
                    $summaryStmt->bindValue(12, $data['id'], SQLITE3_INTEGER);
                    $summaryStmt->bindValue(13, $today, SQLITE3_TEXT);
                    $summaryStmt->bindValue(14, $data['id'], SQLITE3_INTEGER);
                    $summaryStmt->execute();
                }
            }
        }
    
        // Prepare the update statement
        $stmt = $db->prepare("UPDATE products SET {$data['column']} = :value WHERE id = :id");
        $stmt->bindValue(':value', $data['value'], $dataType);
        $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            ob_clean();
            echo json_encode(['success' => true]);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Database update failed']);
        }
        
        $db->close();
        
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?> 