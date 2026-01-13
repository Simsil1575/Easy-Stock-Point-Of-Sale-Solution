<?php
// Check activation status
$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Database connection
$db = new PDO('sqlite:../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

header('Content-Type: application/json');

try {
    $type = $_POST['type'] ?? '';
    $id = $_POST['id'] ?? null;
    $date = $_POST['date'] ?? null;
    $name = $_POST['name'] ?? null;

    switch ($type) {
        case 'sales':
            // Delete from orders and order_items
            $db->beginTransaction();
            
            // Disable foreign key constraints
            $db->exec('PRAGMA foreign_keys = OFF');
            
            // First, get the items in the order to restore their quantities
            $stmt = $db->prepare("SELECT product_name, quantity FROM order_items WHERE order_id = ?");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Restore the quantities in the inventory
            $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name");
            foreach ($items as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $item['product_name']
                ]);
            }

            // Delete all related records (order doesn't matter with foreign keys disabled)
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id = ?");
            $stmt->execute([$id]);
            
            $stmt = $db->prepare("DELETE FROM order_items WHERE order_id = ?");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            
            // Re-enable foreign key constraints
            $db->exec('PRAGMA foreign_keys = ON');
            
            $db->commit();
            break;

        case 'daily':
            // Delete all records for a specific date
            $db->beginTransaction();
            
            // Disable foreign key constraints
            $db->exec('PRAGMA foreign_keys = OFF');
            
            // First, get all credit sales for this date to restore product quantities
            $stmt = $db->prepare("SELECT csi.product_name, csi.quantity FROM credit_sale_items csi 
                                 JOIN credit_sales cs ON csi.sale_id = cs.id 
                                 WHERE DATE(cs.created_at) = ?");
            $stmt->execute([$date]);
            $creditItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Restore the quantities in the inventory for credit sales
            $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name");
            foreach ($creditItems as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $item['product_name']
                ]);
            }
            
            // Get all orders for this date to restore product quantities
            $stmt = $db->prepare("SELECT product_name, quantity FROM order_items oi 
                                 JOIN orders o ON oi.order_id = o.id 
                                 WHERE DATE(o.created_at) = ?");
            $stmt->execute([$date]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Restore the quantities in the inventory for orders
            foreach ($orderItems as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $item['product_name']
                ]);
            }
            
            // Delete all related records (order doesn't matter with foreign keys disabled)
            $stmt = $db->prepare("DELETE FROM payment_logs WHERE sale_id IN 
                                 (SELECT id FROM credit_sales WHERE DATE(created_at) = ?)");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM payments WHERE sale_id IN 
                                 (SELECT id FROM credit_sales WHERE DATE(created_at) = ?)");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id IN 
                                 (SELECT id FROM orders WHERE DATE(created_at) = ?)");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id IN 
                                 (SELECT id FROM credit_sales WHERE DATE(created_at) = ?)");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM order_items WHERE order_id IN 
                                 (SELECT id FROM orders WHERE DATE(created_at) = ?)");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM credit_sale_items WHERE sale_id IN 
                                 (SELECT id FROM credit_sales WHERE DATE(created_at) = ?)");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM orders WHERE DATE(created_at) = ?");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM credit_sales WHERE DATE(created_at) = ?");
            $stmt->execute([$date]);
            
            $stmt = $db->prepare("DELETE FROM cash_transactions WHERE DATE(created_at) = ?");
            $stmt->execute([$date]);
            
            // Re-enable foreign key constraints
            $db->exec('PRAGMA foreign_keys = ON');
            
            $db->commit();
            break;

        case 'product':
            // Delete all records for a specific product
            $db->beginTransaction();
            
            // Disable foreign key constraints
            $db->exec('PRAGMA foreign_keys = OFF');
            
            // First, get all credit sales that contain this product to restore quantities
            $stmt = $db->prepare("SELECT csi.sale_id, csi.quantity FROM credit_sale_items csi WHERE csi.product_name = ?");
            $stmt->execute([$name]);
            $creditItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Restore quantities for credit sales
            $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name");
            foreach ($creditItems as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $name
                ]);
            }
            
            // Get all orders that contain this product to restore quantities
            $stmt = $db->prepare("SELECT oi.order_id, oi.quantity FROM order_items oi WHERE oi.product_name = ?");
            $stmt->execute([$name]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Restore quantities for orders
            foreach ($orderItems as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $name
                ]);
            }
            
            // Delete all related records (order doesn't matter with foreign keys disabled)
            $stmt = $db->prepare("DELETE FROM payment_logs WHERE sale_id IN 
                                 (SELECT sale_id FROM credit_sale_items WHERE product_name = ?)");
            $stmt->execute([$name]);
            
            $stmt = $db->prepare("DELETE FROM payments WHERE sale_id IN 
                                 (SELECT sale_id FROM credit_sale_items WHERE product_name = ?)");
            $stmt->execute([$name]);
            
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id IN 
                                 (SELECT order_id FROM order_items WHERE product_name = ?)");
            $stmt->execute([$name]);
            
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id IN 
                                 (SELECT sale_id FROM credit_sale_items WHERE product_name = ?)");
            $stmt->execute([$name]);
            
            $stmt = $db->prepare("DELETE FROM order_items WHERE product_name = ?");
            $stmt->execute([$name]);
            
            $stmt = $db->prepare("DELETE FROM credit_sale_items WHERE product_name = ?");
            $stmt->execute([$name]);
            
            // Re-enable foreign key constraints
            $db->exec('PRAGMA foreign_keys = ON');
            
            $db->commit();
            break;

        case 'eft':
            // Delete EFT payment record
            $db->beginTransaction();
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE id = ?");
            $stmt->execute([$id]);
            $db->commit();
            break;

        case 'cash':
            // Delete cash transaction
            $db->beginTransaction();
            $stmt = $db->prepare("DELETE FROM cash_transactions WHERE id = ?");
            $stmt->execute([$id]);
            $db->commit();
            break;

        case 'credit':
            // Reset credit sale payment status instead of deleting
            $db->beginTransaction();
            
            // Check if this is a paid credit sale that should be reset
            $stmt = $db->prepare("SELECT payment_status, paid_amount FROM credit_sales WHERE id = ?");
            $stmt->execute([$id]);
            $creditSale = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$creditSale) {
                throw new Exception('Credit sale not found');
            }
            
            // Only reset if the sale is currently paid or partially paid
            if ($creditSale['payment_status'] === 'paid' || $creditSale['payment_status'] === 'eft' || $creditSale['payment_status'] === 'partial') {
                // Reset the credit sale to unpaid status
                $stmt = $db->prepare("UPDATE credit_sales SET paid_amount = 0, payment_status = 'unpaid' WHERE id = ?");
                $stmt->execute([$id]);
                
                // Delete related payment records
                $stmt = $db->prepare("DELETE FROM payments WHERE sale_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM payment_logs WHERE sale_id = ?");
                $stmt->execute([$id]);
            } else {
                // If already unpaid, delete the entire credit sale record
                // Disable foreign key constraints
                $db->exec('PRAGMA foreign_keys = OFF');
                
                // First, get the credit sale details to restore product quantities
                $stmt = $db->prepare("SELECT csi.product_name, csi.quantity FROM credit_sale_items csi WHERE csi.sale_id = ?");
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Restore the quantities in the inventory
                $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name");
                foreach ($items as $item) {
                    $stmtUpdateInventory->execute([
                        ':quantity' => $item['quantity'],
                        ':product_name' => $item['product_name']
                    ]);
                }
                
                // Delete all related records
                $stmt = $db->prepare("DELETE FROM payment_logs WHERE sale_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM payments WHERE sale_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM eft_payments WHERE order_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM credit_sale_items WHERE sale_id = ?");
                $stmt->execute([$id]);
                
                $stmt = $db->prepare("DELETE FROM credit_sales WHERE id = ?");
                $stmt->execute([$id]);
                
                // Re-enable foreign key constraints
                $db->exec('PRAGMA foreign_keys = ON');
            }
            
            $db->commit();
            break;

        case 'credit_payment':
            // Delete individual credit payment record
            $db->beginTransaction();
            
            // Get payment details to update credit sale
            $stmt = $db->prepare("SELECT sale_id, amount FROM payments WHERE id = ?");
            $stmt->execute([$id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception('Payment record not found');
            }
            
            // Update credit sale paid amount
            $stmt = $db->prepare("UPDATE credit_sales SET paid_amount = paid_amount - ? WHERE id = ?");
            $stmt->execute([$payment['amount'], $payment['sale_id']]);
            
            // Update payment status based on remaining amount
            $stmt = $db->prepare("
                UPDATE credit_sales 
                SET payment_status = CASE 
                    WHEN paid_amount <= 0 THEN 'unpaid'
                    WHEN paid_amount < total_amount THEN 'partial'
                    ELSE 'paid'
                END 
                WHERE id = ?
            ");
            $stmt->execute([$payment['sale_id']]);
            
            // Delete the payment record
            $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            break;

        case 'credit_eft_payment':
            // Delete individual credit EFT payment record
            $db->beginTransaction();
            
            // Get EFT payment details to update credit sale
            $stmt = $db->prepare("SELECT order_id, amount FROM eft_payments WHERE id = ?");
            $stmt->execute([$id]);
            $eftPayment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$eftPayment) {
                throw new Exception('EFT payment record not found');
            }
            
            // Update credit sale paid amount
            $stmt = $db->prepare("UPDATE credit_sales SET paid_amount = paid_amount - ? WHERE id = ?");
            $stmt->execute([$eftPayment['amount'], $eftPayment['order_id']]);
            
            // Update payment status based on remaining amount
            $stmt = $db->prepare("
                UPDATE credit_sales 
                SET payment_status = CASE 
                    WHEN paid_amount <= 0 THEN 'unpaid'
                    WHEN paid_amount < total_amount THEN 'partial'
                    ELSE 'paid'
                END 
                WHERE id = ?
            ");
            $stmt->execute([$eftPayment['order_id']]);
            
            // Delete the EFT payment record
            $stmt = $db->prepare("DELETE FROM eft_payments WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            break;

        default:
            throw new Exception('Invalid record type');
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 