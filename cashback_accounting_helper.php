<?php

/**
 * Shared cash-back accounting: till cash-out and optional synthetic order + eft_payments.
 * Description should contain "Cash Back" for cash-up / reporting queries.
 *
 * @param bool $recordEftSide When true, also inserts orders + eft_payments (card side), if eft_payments table exists.
 */
/**
 * @return array{order_id: int|null, cash_transaction_id: int|null}
 */
function recordCashBackAccounting(
    PDO $db,
    float $amount,
    string $cashierId,
    string $timestamp,
    string $description,
    bool $recordEftSide,
    string $walletProvider = 'Customer',
    string $transactionRef = ''
): array {
    if ($amount <= 0) {
        return ['order_id' => null, 'cash_transaction_id' => null];
    }

    $stmt = $db->prepare('INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['cash-out', $amount, $description, $cashierId, $timestamp]);
    $cashTransactionId = (int) $db->lastInsertId();

    if (!$recordEftSide) {
        return ['order_id' => null, 'cash_transaction_id' => $cashTransactionId ?: null];
    }

    $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn();
    if (!$eftTableExists) {
        return ['order_id' => null, 'cash_transaction_id' => $cashTransactionId ?: null];
    }

    $orderStmt = $db->prepare('INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (?, ?, ?, ?)');
    $orderStmt->execute([$amount, 0, $timestamp, $cashierId]);
    $orderId = (int) $db->lastInsertId();

    $eftStmt = $db->prepare('INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?)');
    $eftStmt->execute([
        $orderId,
        $transactionRef !== '' ? $transactionRef : 'Cash Back',
        $walletProvider !== '' ? $walletProvider : 'Customer',
        $amount,
        $cashierId,
        $timestamp,
    ]);

    return [
        'order_id' => $orderId > 0 ? $orderId : null,
        'cash_transaction_id' => $cashTransactionId > 0 ? $cashTransactionId : null,
    ];
}

/** Remove till cash-out lines tied to a POS order cash-back. */
function deleteCashBackAccountingForOrder(PDO $db, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }

    $stmt = $db->prepare("DELETE FROM cash_transactions WHERE type = 'cash-out' AND description LIKE ?");
    $stmt->execute(['%Order #' . $orderId . '%']);
}
