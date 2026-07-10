<?php
/**
 * Resolve credit_sales.payment_status after payment rows are inserted.
 * Cash-only payments live in `payments` only; EFT amounts are in both `payments` and `eft_payments`.
 *
 * @param PDO $db
 * @param int $saleId credit_sales.id (also eft_payments.order_id)
 */
function resolve_credit_sale_payment_status(PDO $db, int $saleId): string
{
    $eps = 0.01;
    $stmt = $db->prepare('SELECT total_amount, paid_amount FROM credit_sales WHERE id = ?');
    $stmt->execute([$saleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'partial';
    }
    $totalAmt = (float) $row['total_amount'];

    $sumPayStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = ?');
    $sumPayStmt->execute([$saleId]);
    $sumPay = (float) $sumPayStmt->fetchColumn();

    $sumEftStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM eft_payments WHERE order_id = ?');
    $sumEftStmt->execute([$saleId]);
    $sumEft = (float) $sumEftStmt->fetchColumn();

    if ($sumPay + $eps < $totalAmt) {
        return 'partial';
    }

    $cashTotal = $sumPay - $sumEft;
    if ($sumEft <= $eps) {
        return 'paid';
    }
    if ($cashTotal <= $eps) {
        return 'eft';
    }
    return 'paid_mixed';
}
