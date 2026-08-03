<?php
require_once __DIR__ . '/../cashier_helper.php';
requireApiSession(['admin', 'manager']);

require_once __DIR__ . '/../recipe_stock_helper.php';
$db = new PDO('sqlite:../pos.db');
configureSqlitePdo($db);

/**
 * Restore stock for a sold line (mirrors process_order / process_credit deduct rules).
 */
function reverseRestoreSaleLineStock(PDO $db, string $productName, float $qty): void
{
    if ($qty <= 0) {
        return;
    }
    if (in_array($productName, ['EFT Income', 'Lay-bye Payment', 'Cart Discount', 'Gratuity'], true)) {
        return;
    }
    $infoStmt = $db->prepare('SELECT category FROM products WHERE name = ?');
    $infoStmt->execute([$productName]);
    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
    $isFood = $info && strtolower(trim((string) ($info['category'] ?? ''))) === 'food';

    restoreRecipeStockByProductName($db, $productName, $qty);
    if (!$isFood) {
        $db->prepare('UPDATE products SET quantity = quantity + :quantity WHERE name = :product_name')
            ->execute([':quantity' => $qty, ':product_name' => $productName]);
    }
}

try {
    $db->beginTransaction();

    $lastOrder = $db->query("SELECT id, created_at FROM orders ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $lastCreditSale = $db->query("SELECT id, created_at FROM credit_sales ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $lastPayment = $db->query("SELECT p.id, p.sale_id, p.payment_date, 'payment' as type
                              FROM payments p
                              ORDER BY p.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $lastEftPayment = $db->query("SELECT e.id, e.order_id as sale_id, e.payment_date, 'eft' as type
                                 FROM eft_payments e
                                 ORDER BY e.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $transaction = null;
    $paymentToReverse = null;

    if ($lastPayment && $lastEftPayment) {
        $paymentToReverse = (strtotime($lastPayment['payment_date']) > strtotime($lastEftPayment['payment_date'])) ?
            $lastPayment : $lastEftPayment;
    } elseif ($lastPayment) {
        $paymentToReverse = $lastPayment;
    } elseif ($lastEftPayment) {
        $paymentToReverse = $lastEftPayment;
    }

    if ($paymentToReverse) {
        $saleId = $paymentToReverse['sale_id'];

        $creditSale = $db->prepare("SELECT * FROM credit_sales WHERE id = ?");
        $creditSale->execute([$saleId]);
        $sale = $creditSale->fetch(PDO::FETCH_ASSOC);

        if ($sale) {
            if ($paymentToReverse['type'] == 'payment') {
                $paymentStmt = $db->prepare("SELECT amount FROM payments WHERE id = ?");
                $paymentStmt->execute([$paymentToReverse['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                $amount = $payment['amount'];

                $deleteStmt = $db->prepare("DELETE FROM payments WHERE id = ?");
                $deleteStmt->execute([$paymentToReverse['id']]);
            } else {
                $paymentStmt = $db->prepare("SELECT amount FROM eft_payments WHERE id = ?");
                $paymentStmt->execute([$paymentToReverse['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                $amount = $payment['amount'];

                $deleteStmt = $db->prepare("DELETE FROM eft_payments WHERE id = ?");
                $deleteStmt->execute([$paymentToReverse['id']]);
            }

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
            $stmtItems = $db->prepare("SELECT product_name, quantity FROM order_items WHERE order_id = :order_id");
            $stmtItems->execute([':order_id' => $transaction['id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                reverseRestoreSaleLineStock($db, $item['product_name'], floatval($item['quantity']));
            }

            $stmtDeleteItems = $db->prepare("DELETE FROM order_items WHERE order_id = :order_id");
            $stmtDeleteItems->execute([':order_id' => $transaction['id']]);

            $stmtDeleteOrder = $db->prepare("DELETE FROM orders WHERE id = :order_id");
            $stmtDeleteOrder->execute([':order_id' => $transaction['id']]);
        } else {
            $saleId = $transaction['id'];

            $creditorStmt = $db->prepare("SELECT creditor_id FROM credit_sales WHERE id = ?");
            $creditorStmt->execute([$saleId]);
            $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
            $creditorId = $creditor ? $creditor['creditor_id'] : 0;

            $stmtItems = $db->prepare("SELECT product_name, quantity FROM credit_sale_items WHERE sale_id = :sale_id");
            $stmtItems->execute([':sale_id' => $saleId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                reverseRestoreSaleLineStock($db, $item['product_name'], floatval($item['quantity']));
            }

            $stmtDeletePayments = $db->prepare("DELETE FROM payments WHERE sale_id = :sale_id");
            $stmtDeletePayments->execute([':sale_id' => $saleId]);

            $stmtDeleteEftPayments = $db->prepare("DELETE FROM eft_payments WHERE order_id = :sale_id");
            $stmtDeleteEftPayments->execute([':sale_id' => $saleId]);

            $stmtDeleteItems = $db->prepare("DELETE FROM credit_sale_items WHERE sale_id = :sale_id");
            $stmtDeleteItems->execute([':sale_id' => $saleId]);

            $stmtDeleteSale = $db->prepare("DELETE FROM credit_sales WHERE id = :sale_id");
            $stmtDeleteSale->execute([':sale_id' => $saleId]);

            $db->commit();
            $_SESSION['payment_success'] = 'Credit sale has been reversed successfully';
            header('Location: ../credit-transactions.php?creditor_id=' . $creditorId);
            exit();
        }

        $db->commit();
    }

    header('Location: home');
    exit();

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['payment_error'] = 'Failed to reverse transaction: ' . $e->getMessage();
    header('Location: index.php?error=reverse_failed');
    exit();
}
