<?php
session_start();
header('Content-Type: application/json');

try {
    // Get the directory where this script is located
    $scriptDir = dirname(__FILE__);
    $dbPath = $scriptDir . '/../pos.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['order_id']) || !isset($input['items']) || empty($input['items'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid refund data'
        ]);
        exit;
    }
    
    $orderId = intval($input['order_id']);
    $items = $input['items'];
    $reason = isset($input['reason']) ? $input['reason'] : 'No reason provided';
    $total = floatval($input['total']);
    $cashierId = $_SESSION['username'] ?? 'Unknown';
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Insert into refunds table
        $stmt = $db->prepare("
            INSERT INTO refunds (order_id, total_amount, reason, cashier_id, created_at)
            VALUES (:order_id, :total, :reason, :cashier_id, datetime('now', 'localtime'))
        ");
        $stmt->execute([
            ':order_id' => $orderId,
            ':total' => $total,
            ':reason' => $reason,
            ':cashier_id' => $cashierId
        ]);
        
        $refundId = $db->lastInsertId();
        
        // Insert refund items
        $stmtItems = $db->prepare("
            INSERT INTO refund_items (refund_id, order_item_id, product_name, quantity, price, buying_price)
            VALUES (:refund_id, :order_item_id, :product_name, :quantity, :price, :buying_price)
        ");
        
        // Prepare statement for updating product stock
        $stmtStock = $db->prepare("
            UPDATE products SET quantity = quantity + :qty WHERE name = :product_name
        ");
        
        // Prepare statement for updating order_items quantities
        $stmtOrderItems = $db->prepare("
            UPDATE order_items 
            SET quantity = quantity - :refund_qty 
            WHERE id = :order_item_id AND quantity >= :refund_qty
        ");
        
        foreach ($items as $item) {
            $orderItemId = isset($item['order_item_id']) ? intval($item['order_item_id']) : null;
            $refundQty = intval($item['quantity']);
            $productName = $item['product_name'];
            
            // Insert refund item record
            $stmtItems->execute([
                ':refund_id' => $refundId,
                ':order_item_id' => $orderItemId,
                ':product_name' => $productName,
                ':quantity' => $refundQty,
                ':price' => $item['price'],
                ':buying_price' => isset($item['buying_price']) ? $item['buying_price'] : 0
            ]);
            
            // Update order_items - reduce quantity (this removes/updates the original order item)
            if ($orderItemId) {
                $stmtOrderItems->execute([
                    ':refund_qty' => $refundQty,
                    ':order_item_id' => $orderItemId
                ]);
                
                // If quantity becomes 0 or negative, set to 0 (keep record for audit)
                $checkQtyStmt = $db->prepare("
                    UPDATE order_items 
                    SET quantity = 0 
                    WHERE id = :order_item_id AND quantity < 0
                ");
                $checkQtyStmt->bindValue(':order_item_id', $orderItemId, PDO::PARAM_INT);
                $checkQtyStmt->execute();
            }
            
            // Return stock to inventory
            $stmtStock->execute([
                ':qty' => $refundQty,
                ':product_name' => $productName
            ]);
        }
        
        // Update order total - recalculate based on remaining items
        $recalcStmt = $db->prepare("
            UPDATE orders 
            SET total = (
                SELECT COALESCE(SUM(quantity * price), 0) 
                FROM order_items 
                WHERE order_id = :order_id AND quantity > 0
            )
            WHERE id = :order_id
        ");
        $recalcStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $recalcStmt->execute();
        
        // Check if order total is now 0 (all items refunded)
        $checkOrderStmt = $db->prepare("SELECT total FROM orders WHERE id = :order_id");
        $checkOrderStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $checkOrderStmt->execute();
        $orderTotal = $checkOrderStmt->fetchColumn();
        
        // If all items are refunded (total = 0), remove order and related records
        if ($orderTotal == 0 || $orderTotal === null) {
            // Delete order_items with 0 quantity
            $deleteOrderItemsStmt = $db->prepare("
                DELETE FROM order_items WHERE order_id = :order_id AND quantity = 0
            ");
            $deleteOrderItemsStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteOrderItemsStmt->execute();
            
            // Delete related eft_payments
            $deleteEftStmt = $db->prepare("DELETE FROM eft_payments WHERE order_id = :order_id");
            $deleteEftStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteEftStmt->execute();
            
            // Delete related mixed_payments
            $deleteMixedStmt = $db->prepare("DELETE FROM mixed_payments WHERE order_id = :order_id");
            $deleteMixedStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteMixedStmt->execute();
            
            // Delete related tab_payments
            $deleteTabStmt = $db->prepare("DELETE FROM tab_payments WHERE order_id = :order_id");
            $deleteTabStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteTabStmt->execute();
            
            // Finally, delete the order itself
            $deleteOrderStmt = $db->prepare("DELETE FROM orders WHERE id = :order_id");
            $deleteOrderStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteOrderStmt->execute();
        } else {
            // Partial refund - delete order_items with 0 quantity
            $deleteZeroQtyStmt = $db->prepare("
                DELETE FROM order_items WHERE order_id = :order_id AND quantity = 0
            ");
            $deleteZeroQtyStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $deleteZeroQtyStmt->execute();
        }
        
        // Record cash transaction for refund
        $stmtCash = $db->prepare("
            INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at)
            VALUES ('refund', :amount, :description, :cashier_id, datetime('now', 'localtime'))
        ");
        $stmtCash->execute([
            ':amount' => -$total,
            ':description' => 'Refund for Order #' . $orderId . ' - ' . $reason,
            ':cashier_id' => $cashierId
        ]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'refund_id' => $refundId,
            'message' => 'Refund processed successfully'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
