<?php

require_once __DIR__ . '/ensure_laybye_schema.php';

/**
 * Creates orders + order_items (synthetic line) + eft/mixed rows for a lay-bye payment.
 */
function laybyeCreatePaymentOrder(
    PDO $db,
    float $amount,
    float $cashReceivedForOrder,
    string $paymentMethod,
    string $transactionRef,
    string $walletProvider,
    float $cashAmount,
    float $eftAmount,
    string $cashierUsername
): int {
    ensureLaybyeSchema($db);
    $productName = laybyePaymentProductName();

    $orderStmt = $db->prepare("INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (?, ?, ?, ?)");
    $orderStmt->execute([$amount, $cashReceivedForOrder, date('Y-m-d H:i:s'), $cashierUsername]);
    $orderId = (int) $db->lastInsertId();

    $stmtOrderItems = $db->prepare("INSERT INTO order_items (order_id, product_name, quantity, price, buying_price) VALUES (?, ?, ?, ?, ?)");
    $stmtOrderItems->execute([$orderId, $productName, 1, $amount, null]);

    $isMixedPayment = $paymentMethod === 'mixed';
    $isEftPayment = ($paymentMethod === 'eft' || ($isMixedPayment && $eftAmount > 0.001));

    if ($isEftPayment) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS eft_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                transaction_ref TEXT,
                wallet_provider TEXT,
                amount DECIMAL(10,2) NOT NULL,
                cashier_id TEXT,
                payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(order_id) REFERENCES orders(id)
            )
        ");
        $eftAmountToRecord = $isMixedPayment ? $eftAmount : $amount;
        $stmtEft = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtEft->execute([$orderId, $transactionRef, $walletProvider, $eftAmountToRecord, $cashierUsername, date('Y-m-d H:i:s')]);
    }

    if ($isMixedPayment) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mixed_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                eft_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                eft_transaction_ref TEXT,
                eft_wallet_provider TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                cashier_id TEXT,
                FOREIGN KEY(order_id) REFERENCES orders(id)
            )
        ");
        $stmtMixed = $db->prepare("INSERT INTO mixed_payments (order_id, cash_amount, eft_amount, eft_transaction_ref, eft_wallet_provider, cashier_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtMixed->execute([$orderId, $cashAmount, $eftAmount, $transactionRef, $walletProvider, $cashierUsername]);
    }

    return $orderId;
}

function laybyeAdvanceDueDate(string $planFrequency, ?string $fromDate = null): ?string
{
    $from = $fromDate ? new DateTime($fromDate) : new DateTime('now');
    if ($planFrequency === 'monthly') {
        $from->modify('+1 month');
    } else {
        $from->modify('+7 days');
    }
    return $from->format('Y-m-d');
}

/** Inverse of laybyeAdvanceDueDate for voiding an installment payment. */
function laybyeRetreatDueDate(string $planFrequency, ?string $fromDate = null): ?string
{
    if (!$fromDate) {
        return null;
    }
    $from = new DateTime($fromDate);
    if ($planFrequency === 'monthly') {
        $from->modify('-1 month');
    } else {
        $from->modify('-7 days');
    }
    return $from->format('Y-m-d');
}

/** Order line names that must not affect physical stock on void/delete. */
function laybyeIsSyntheticOrderItemName(string $productName): bool
{
    return $productName === laybyePaymentProductName() || $productName === 'EFT Income';
}

/**
 * When a POS order linked to laybye_payments is deleted, reverse the account and remove the payment row.
 * Caller must be inside a transaction. Safe to call when no row exists for order_id.
 */
function laybyeRevertPaymentOrder(PDO $db, int $orderId): void
{
    ensureLaybyeSchema($db);
    $payStmt = $db->prepare('SELECT * FROM laybye_payments WHERE order_id = ? LIMIT 1');
    $payStmt->execute([$orderId]);
    $pay = $payStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        return;
    }

    $kind = $pay['payment_kind'] ?? '';
    $payId = (int) $pay['id'];
    $laybyeId = (int) $pay['laybye_id'];
    $amount = round(floatval($pay['amount']), 2);

    if ($kind === 'refund') {
        $db->prepare('DELETE FROM laybye_payments WHERE id = ?')->execute([$payId]);
        return;
    }

    if ($kind !== 'deposit' && $kind !== 'installment') {
        $db->prepare('DELETE FROM laybye_payments WHERE id = ?')->execute([$payId]);
        return;
    }

    $accStmt = $db->prepare('SELECT * FROM laybye_accounts WHERE id = ?');
    $accStmt->execute([$laybyeId]);
    $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        $db->prepare('DELETE FROM laybye_payments WHERE id = ?')->execute([$payId]);
        return;
    }

    $status = $acc['status'] ?? '';
    if (!in_array($status, ['active', 'completed'], true)) {
        $db->prepare('DELETE FROM laybye_payments WHERE id = ?')->execute([$payId]);
        return;
    }

    $balanceDue = round(floatval($acc['balance_due']), 2);
    $depositAmount = round(floatval($acc['deposit_amount'] ?? 0), 2);
    $planFreq = $acc['plan_frequency'] ?? 'weekly';
    $nextDue = $acc['next_due_date'] ?? null;

    if ($kind === 'deposit') {
        $balanceDue = round($balanceDue + $amount, 2);
        $depositAmount = round(max(0, $depositAmount - $amount), 2);
    } else {
        $balanceDue = round($balanceDue + $amount, 2);
        if ($nextDue) {
            $nextDue = laybyeRetreatDueDate($planFreq, $nextDue);
        }
    }

    $newStatus = $status;
    $closedAt = $acc['closed_at'] ?? null;
    if ($status === 'completed' && $balanceDue > 0.01) {
        $newStatus = 'active';
        $closedAt = null;
    }

    $db->prepare('
        UPDATE laybye_accounts
        SET balance_due = ?, deposit_amount = ?, next_due_date = ?, status = ?, closed_at = ?
        WHERE id = ?
    ')->execute([
        max(0, $balanceDue),
        $depositAmount,
        $nextDue,
        $newStatus,
        $closedAt,
        $laybyeId,
    ]);

    $db->prepare('DELETE FROM laybye_payments WHERE id = ?')->execute([$payId]);
}

/**
 * Delete POS orders linked to any lay-bye payment row (eft/mixed/order_items/orders).
 */
function laybyeDeleteLinkedPaymentOrders(PDO $db, int $laybyeId): void
{
    $payStmt = $db->prepare('SELECT order_id FROM laybye_payments WHERE laybye_id = ? AND order_id IS NOT NULL');
    $payStmt->execute([$laybyeId]);
    $orderIds = [];
    while ($row = $payStmt->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int) ($row['order_id'] ?? 0);
        if ($oid > 0) {
            $orderIds[] = $oid;
        }
    }
    $orderIds = array_values(array_unique($orderIds));
    if ($orderIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $db->prepare("DELETE FROM eft_payments WHERE order_id IN ($ph)")->execute($orderIds);
    $db->prepare("DELETE FROM mixed_payments WHERE order_id IN ($ph)")->execute($orderIds);
    $db->prepare("DELETE FROM order_items WHERE order_id IN ($ph)")->execute($orderIds);
    $db->prepare("DELETE FROM orders WHERE id IN ($ph)")->execute($orderIds);
}

/**
 * Remove lay-bye account row and CASCADE children. Caller must verify manager PIN and permissions.
 * Active: same cash/stock reversal as cancel, without keeping a cancelled account row.
 * Completed / cancelled: removes linked payment orders only; no stock or cash changes.
 *
 * @throws RuntimeException on not found or invalid state
 */
function laybyePermanentlyDeleteAccount(PDO $db, int $laybyeId, string $cashierUsername): void
{
    ensureLaybyeSchema($db);
    $accStmt = $db->prepare('SELECT * FROM laybye_accounts WHERE id = ?');
    $accStmt->execute([$laybyeId]);
    $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        throw new RuntimeException('Lay-bye not found');
    }
    $status = $acc['status'] ?? '';

    if ($status === 'active') {
        require_once __DIR__ . '/recipe_stock_helper.php';

        $payStmt = $db->prepare("SELECT * FROM laybye_payments WHERE laybye_id = ? AND payment_kind IN ('deposit','installment')");
        $payStmt->execute([$laybyeId]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
        $orderIds = [];
        foreach ($payments as $p) {
            if (!empty($p['order_id'])) {
                $orderIds[] = (int) $p['order_id'];
            }
        }
        $orderIds = array_values(array_unique(array_filter($orderIds)));

        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $db->prepare("DELETE FROM eft_payments WHERE order_id IN ($ph)")->execute($orderIds);
            $db->prepare("DELETE FROM mixed_payments WHERE order_id IN ($ph)")->execute($orderIds);
            $db->prepare("DELETE FROM order_items WHERE order_id IN ($ph)")->execute($orderIds);
            $db->prepare("DELETE FROM orders WHERE id IN ($ph)")->execute($orderIds);
        }

        $itemsStmt = $db->prepare('SELECT * FROM laybye_items WHERE laybye_id = ?');
        $itemsStmt->execute([$laybyeId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $currentDate = date('Y-m-d');
        $stmtGetProductInfo = $db->prepare('SELECT category FROM products WHERE name = ?');
        $resolveProductStmt = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');

        foreach ($items as $item) {
            $name = $item['product_name'];
            $qty = (int) $item['quantity'];
            $stmtGetProductInfo->execute([$name]);
            $info = $stmtGetProductInfo->fetch(PDO::FETCH_ASSOC);
            $cat = $info ? ($info['category'] ?? '') : '';
            $isFood = strtolower(trim($cat)) === 'food';
            restoreRecipeStockByProductName($db, $name, floatval($qty));
            if (!$isFood) {
                $db->prepare('UPDATE products SET quantity = quantity + ? WHERE name = ?')->execute([$qty, $name]);
            }
            $resolveProductStmt->execute([$name]);
            if ($resolveProductStmt->fetchColumn()) {
                $db->prepare("
                    INSERT OR IGNORE INTO daily_stock_summary 
                    (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                    VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
                ")->execute([$currentDate, $name]);
                $db->prepare("
                    UPDATE daily_stock_summary 
                    SET sold_quantity = CASE WHEN sold_quantity - ? < 0 THEN 0 ELSE sold_quantity - ? END
                    WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
                ")->execute([$qty, $qty, $currentDate, $name]);
            }
        }

        $db->prepare('DELETE FROM laybye_accounts WHERE id = ?')->execute([$laybyeId]);
        return;
    }

    if (!in_array($status, ['completed', 'cancelled'], true)) {
        throw new RuntimeException('Cannot delete this lay-bye');
    }

    laybyeDeleteLinkedPaymentOrders($db, $laybyeId);
    $db->prepare('DELETE FROM laybye_accounts WHERE id = ?')->execute([$laybyeId]);
}
