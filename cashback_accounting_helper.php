<?php

/**
 * Shared cash-back accounting: till cash-out and optional synthetic order + eft_payments.
 * Description should contain "Cash Back" for cash-up / reporting queries.
 *
 * @param bool $recordEftSide When true, also inserts orders + eft_payments (card side), if eft_payments table exists.
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
): void {
    if ($amount <= 0) {
        return;
    }

    $stmt = $db->prepare('INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['cash-out', $amount, $description, $cashierId, $timestamp]);

    if (!$recordEftSide) {
        return;
    }

    $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn();
    if (!$eftTableExists) {
        return;
    }

    $orderStmt = $db->prepare('INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (?, ?, ?, ?)');
    $orderStmt->execute([$amount, 0, $timestamp, $cashierId]);
    $orderId = $db->lastInsertId();

    $eftStmt = $db->prepare('INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?)');
    $eftStmt->execute([
        $orderId,
        $transactionRef !== '' ? $transactionRef : 'Cash Back',
        $walletProvider !== '' ? $walletProvider : 'Customer',
        $amount,
        $cashierId,
        $timestamp,
    ]);
}
