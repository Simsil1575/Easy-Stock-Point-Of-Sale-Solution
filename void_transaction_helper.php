<?php
/**
 * Ensures void_transactions has columns for deleted-sale tracking and inserts audit rows.
 */
function ensureVoidTransactionsExtendedSchema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS void_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        total DECIMAL(10, 2) NOT NULL,
        cash_received DECIMAL(10, 2) NOT NULL,
        items TEXT NOT NULL,
        payment_method TEXT,
        transaction_ref TEXT,
        wallet_provider TEXT,
        eft_amount DECIMAL(10, 2),
        cashier_id TEXT,
        voided_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    try {
        $db->exec("ALTER TABLE void_transactions ADD COLUMN void_source TEXT DEFAULT 'void'");
    } catch (PDOException $e) {
    }
    try {
        $db->exec("ALTER TABLE void_transactions ADD COLUMN credit_sale_id INTEGER NULL");
    } catch (PDOException $e) {
    }
    try {
        $db->exec("ALTER TABLE void_transactions ADD COLUMN creditor_name TEXT NULL");
    } catch (PDOException $e) {
    }
}

/**
 * Snapshot a deleted POS order into void_transactions (call before removing order rows).
 */
function recordVoidForDeletedOrder(PDO $db, int $orderId): void
{
    ensureVoidTransactionsExtendedSchema($db);

    $orderStmt = $db->prepare("SELECT id, total, cash_received, cashier_id, created_at FROM orders WHERE id = ?");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return;
    }

    $itemsStmt = $db->prepare("SELECT product_name, quantity, price FROM order_items WHERE order_id = ? ORDER BY id");
    $itemsStmt->execute([$orderId]);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsPayload = [];
    foreach ($rows as $row) {
        $itemsPayload[] = [
            'name' => $row['product_name'],
            'quantity' => (float) $row['quantity'],
            'price' => (float) $row['price'],
        ];
    }

    $eftStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM eft_payments WHERE order_id = ?");
    $eftStmt->execute([$orderId]);
    $eftSum = (float) $eftStmt->fetchColumn();

    $mixedRow = null;
    try {
        $m = $db->prepare("SELECT cash_amount, eft_amount, eft_transaction_ref, eft_wallet_provider FROM mixed_payments WHERE order_id = ? LIMIT 1");
        $m->execute([$orderId]);
        $mixedRow = $m->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
    }

    $total = (float) $order['total'];
    $paymentMethod = 'cash';
    $transactionRef = null;
    $walletProvider = null;

    if ($mixedRow && ((float) $mixedRow['eft_amount'] > 0 || (float) $mixedRow['cash_amount'] > 0)) {
        $paymentMethod = 'mixed';
        $transactionRef = $mixedRow['eft_transaction_ref'] ?? null;
        $walletProvider = $mixedRow['eft_wallet_provider'] ?? null;
    } elseif ($eftSum > 0.009) {
        $eftRow = $db->prepare("SELECT transaction_ref, wallet_provider FROM eft_payments WHERE order_id = ? ORDER BY id LIMIT 1");
        $eftRow->execute([$orderId]);
        $er = $eftRow->fetch(PDO::FETCH_ASSOC);
        if ($er) {
            $transactionRef = $er['transaction_ref'] ?? null;
            $walletProvider = $er['wallet_provider'] ?? null;
        }
        if ($eftSum >= $total - 0.02) {
            $paymentMethod = 'e-wallet';
        } else {
            $paymentMethod = 'mixed';
        }
    }

    $ins = $db->prepare("
        INSERT INTO void_transactions (
            order_id, credit_sale_id, total, cash_received, items, payment_method,
            transaction_ref, wallet_provider, eft_amount, cashier_id, voided_at, void_source, creditor_name
        ) VALUES (
            :order_id, NULL, :total, :cash_received, :items, :payment_method,
            :transaction_ref, :wallet_provider, :eft_amount, :cashier_id, :voided_at, 'deleted_order', NULL
        )
    ");
    $ins->execute([
        ':order_id' => $orderId,
        ':total' => $total,
        ':cash_received' => (float) $order['cash_received'],
        ':items' => json_encode($itemsPayload),
        ':payment_method' => $paymentMethod,
        ':transaction_ref' => $transactionRef,
        ':wallet_provider' => $walletProvider,
        ':eft_amount' => $eftSum > 0 ? $eftSum : null,
        ':cashier_id' => $order['cashier_id'] !== null && $order['cashier_id'] !== '' ? (string) $order['cashier_id'] : 'Unknown',
        ':voided_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Snapshot a deleted credit sale into void_transactions (call before removing credit sale rows).
 */
function recordVoidForDeletedCreditSale(PDO $db, int $saleId): void
{
    ensureVoidTransactionsExtendedSchema($db);

    $saleStmt = $db->prepare("
        SELECT cs.*, cr.name AS creditor_name
        FROM credit_sales cs
        LEFT JOIN creditors cr ON cs.creditor_id = cr.id
        WHERE cs.id = ?
    ");
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return;
    }

    $itemsStmt = $db->prepare("SELECT product_name, quantity, price FROM credit_sale_items WHERE sale_id = ? ORDER BY id");
    $itemsStmt->execute([$saleId]);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsPayload = [];
    foreach ($rows as $row) {
        $itemsPayload[] = [
            'name' => $row['product_name'],
            'quantity' => (float) $row['quantity'],
            'price' => (float) $row['price'],
        ];
    }

    $pm = $sale['payment_status'] ?? 'credit';
    $readablePm = in_array($pm, ['paid', 'eft', 'partial', 'unpaid'], true) ? $pm : 'credit';

    $ins = $db->prepare("
        INSERT INTO void_transactions (
            order_id, credit_sale_id, total, cash_received, items, payment_method,
            transaction_ref, wallet_provider, eft_amount, cashier_id, voided_at, void_source, creditor_name
        ) VALUES (
            NULL, :credit_sale_id, :total, :cash_received, :items, :payment_method,
            NULL, NULL, NULL, :cashier_id, :voided_at, 'deleted_credit', :creditor_name
        )
    ");
    $ins->execute([
        ':credit_sale_id' => $saleId,
        ':total' => (float) $sale['total_amount'],
        ':cash_received' => 0,
        ':items' => json_encode($itemsPayload),
        ':payment_method' => $readablePm,
        ':cashier_id' => $sale['cashier_id'] !== null && $sale['cashier_id'] !== '' ? (string) $sale['cashier_id'] : 'Unknown',
        ':voided_at' => date('Y-m-d H:i:s'),
        ':creditor_name' => $sale['creditor_name'] ?? null,
    ]);
}
