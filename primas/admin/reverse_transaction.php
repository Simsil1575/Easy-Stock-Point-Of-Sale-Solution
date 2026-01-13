<?php
// Database connection
$db = new PDO('sqlite:../pos.db');

try {
    // Start a transaction
    $db->beginTransaction();

    // Get the most recent transaction (order or credit sale)
    $lastOrder = $db->query("SELECT id, created_at FROM orders ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $lastCreditSale = $db->query("SELECT id, created_at FROM credit_sales ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    // Get the most recent payment for credit sales
    $lastPayment = $db->query("SELECT p.id, p.sale_id, p.payment_date, 'payment' as type 
                              FROM payments p 
                              ORDER BY p.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    // Get the most recent EFT payment
    $lastEftPayment = $db->query("SELECT e.id, e.order_id as sale_id, e.payment_date, 'eft' as type 
                                 FROM eft_payments e 
                                 ORDER BY e.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // Determine which transaction is more recent
    $transaction = null;
    $paymentToReverse = null;
    
    // First check if we have a payment to reverse
    if ($lastPayment && $lastEftPayment) {
        $paymentToReverse = (strtotime($lastPayment['payment_date']) > strtotime($lastEftPayment['payment_date'])) ?
            $lastPayment : $lastEftPayment;
    } elseif ($lastPayment) {
        $paymentToReverse = $lastPayment;
    } elseif ($lastEftPayment) {
        $paymentToReverse = $lastEftPayment;
    }
    
    // If we have a recent payment, prioritize reversing that
    if ($paymentToReverse) {
        $saleId = $paymentToReverse['sale_id'];
        
        // Check if this is a credit sale payment
        $creditSale = $db->prepare("SELECT * FROM credit_sales WHERE id = ?");
        $creditSale->execute([$saleId]);
        $sale = $creditSale->fetch(PDO::FETCH_ASSOC);
        
        if ($sale) {
            // This is a credit sale payment
            
            // Get the payment amount
            if ($paymentToReverse['type'] == 'payment') {
                $paymentStmt = $db->prepare("SELECT amount FROM payments WHERE id = ?");
                $paymentStmt->execute([$paymentToReverse['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                $amount = $payment['amount'];
                
                // Delete the payment
                $deleteStmt = $db->prepare("DELETE FROM payments WHERE id = ?");
                $deleteStmt->execute([$paymentToReverse['id']]);
            } else {
                // EFT payment
                $paymentStmt = $db->prepare("SELECT amount FROM eft_payments WHERE id = ?");
                $paymentStmt->execute([$paymentToReverse['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                $amount = $payment['amount'];
                
                // Delete the EFT payment
                $deleteStmt = $db->prepare("DELETE FROM eft_payments WHERE id = ?");
                $deleteStmt->execute([$paymentToReverse['id']]);
            }
            
            // Update the credit sale record
            $updateStmt = $db->prepare("UPDATE credit_sales 
                                       SET paid_amount = paid_amount - ?, 
                                           payment_status = CASE 
                                               WHEN paid_amount - ? <= 0 THEN 'unpaid' 
                                               WHEN paid_amount - ? < total_amount THEN 'partial' 
                                               ELSE payment_status 
                                           END
                                       WHERE id = ?");
            $updateStmt->execute([$amount, $amount, $amount, $saleId]);
            
            $db->commit();
            $_SESSION['payment_success'] = 'Payment has been reversed successfully';
            header('Location: ../credit-transactions.php?creditor_id=' . $sale['creditor_id']);
            exit();
        }
    }
    
    // If no payment to reverse, check for full transactions to reverse
    if ($lastOrder && $lastCreditSale) {
        $transaction = (strtotime($lastOrder['created_at']) > strtotime($lastCreditSale['created_at'])) ? 
            ['type' => 'order', 'id' => $lastOrder['id']] : 
            ['type' => 'credit', 'id' => $lastCreditSale['id']];
    } elseif ($lastOrder) {
        $transaction = ['type' => 'order', 'id' => $lastOrder['id']];
    } elseif ($lastCreditSale) {
        $transaction = ['type' => 'credit', 'id' => $lastCreditSale['id']];
    }

    if ($transaction) {
        if ($transaction['type'] === 'order') {
            // Get the order items to restore inventory
            $stmtItems = $db->prepare("SELECT product_name, quantity FROM order_items WHERE order_id = :order_id");
            $stmtItems->execute([':order_id' => $transaction['id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Restore inventory quantities
            $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name");
            foreach ($items as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $item['product_name']
                ]);
            }

            // Delete order items
            $stmtDeleteItems = $db->prepare("DELETE FROM order_items WHERE order_id = :order_id");
            $stmtDeleteItems->execute([':order_id' => $transaction['id']]);

            // Delete the order
            $stmtDeleteOrder = $db->prepare("DELETE FROM orders WHERE id = :order_id");
            $stmtDeleteOrder->execute([':order_id' => $transaction['id']]);
        } else { 
            // Handle credit sale reversal
            $saleId = $transaction['id'];
            
            // Get creditor ID before deleting
            $creditorStmt = $db->prepare("SELECT creditor_id FROM credit_sales WHERE id = ?");
            $creditorStmt->execute([$saleId]);
            $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
            $creditorId = $creditor ? $creditor['creditor_id'] : 0;

            // Get credit sale items to restore inventory
            $stmtItems = $db->prepare("SELECT product_name, quantity FROM credit_sale_items WHERE sale_id = :sale_id");
            $stmtItems->execute([':sale_id' => $saleId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Restore inventory quantities
            $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name");
            foreach ($items as $item) {
                $stmtUpdateInventory->execute([
                    ':quantity' => $item['quantity'],
                    ':product_name' => $item['product_name']
                ]);
            }

            // Delete payments associated with this sale
            $stmtDeletePayments = $db->prepare("DELETE FROM payments WHERE sale_id = :sale_id");
            $stmtDeletePayments->execute([':sale_id' => $saleId]);
            
            // Delete EFT payments associated with this sale
            $stmtDeleteEftPayments = $db->prepare("DELETE FROM eft_payments WHERE order_id = :sale_id");
            $stmtDeleteEftPayments->execute([':sale_id' => $saleId]);

            // Delete credit sale items
            $stmtDeleteItems = $db->prepare("DELETE FROM credit_sale_items WHERE sale_id = :sale_id");
            $stmtDeleteItems->execute([':sale_id' => $saleId]);

            // Delete the credit sale
            $stmtDeleteSale = $db->prepare("DELETE FROM credit_sales WHERE id = :sale_id");
            $stmtDeleteSale->execute([':sale_id' => $saleId]);
            
            $db->commit();
            $_SESSION['payment_success'] = 'Credit sale has been reversed successfully';
            header('Location: ../credit-transactions.php?creditor_id=' . $creditorId);
            exit();
        }

        // Commit the transaction
        $db->commit();
    }

    // Redirect back to index.php
    header('Location: home');
    exit();

} catch (Exception $e) {
    // Rollback the transaction in case of error
    $db->rollBack();
    $_SESSION['payment_error'] = 'Failed to reverse transaction: ' . $e->getMessage();
    header('Location: index.php?error=reverse_failed');
    exit();
}
