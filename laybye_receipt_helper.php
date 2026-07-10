<?php

/**
 * Build receipt payloads for lay-bye balance/statement and per-payment thermal receipts (receipt.php / sendToPrinter).
 */

function laybyeResolveCashierNameForReceipt(?string $cashierId): string
{
    if ($cashierId === null || $cashierId === '') {
        return 'Cashier';
    }
    if (!is_numeric($cashierId)) {
        return $cashierId;
    }
    try {
        $userDb = new PDO('sqlite:' . __DIR__ . '/user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $userDb->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([(int) $cashierId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        return $u ? (string) $u['username'] : ('User #' . $cashierId);
    } catch (Exception $e) {
        return 'User #' . $cashierId;
    }
}

function laybyePaymentRowIsPrintable(array $p): bool
{
    $kind = strtolower((string) ($p['payment_kind'] ?? ''));
    if (!in_array($kind, ['deposit', 'installment', 'refund'], true)) {
        return false;
    }
    $amt = floatval($p['amount'] ?? 0);

    return $amt > 0.0001 && (int) ($p['id'] ?? 0) > 0;
}

/**
 * @param array<string, mixed> $laybye
 * @param list<array<string, mixed>> $laybyeItems
 * @param list<array<string, mixed>> $payments newest-first or any order (lines follow array order)
 * @param array<string, mixed> $businessInfo row from business_info
 *
 * @return array<string, mixed>
 */
function laybyeBuildBalanceReceiptPayload(
    array $laybye,
    array $laybyeItems,
    array $payments,
    array $businessInfo,
    string $sessionUsername
): array {
    $items = [];
    $goodsTotal = 0;
    foreach ($laybyeItems as $r) {
        $qty = (int) $r['quantity'];
        $unit = floatval($r['price']);
        $line = round($qty * $unit, 2);
        $goodsTotal += $line;
        $items[] = [
            'name' => $r['product_name'],
            'quantity' => $qty,
            'price' => $line,
            'unit_price' => $unit,
        ];
    }
    $paymentLines = [];
    foreach ($payments as $p) {
        $paymentLines[] = sprintf(
            '%s %s N$%s %s',
            $p['payment_date'] ?? '',
            $p['payment_kind'] ?? '',
            number_format((float) ($p['amount'] ?? 0), 2),
            $p['payment_method'] ?? ''
        );
    }

    return [
        'is_balance_receipt' => true,
        'is_laybye_balance_receipt' => true,
        'print_only' => true,
        'laybye_reference' => $laybye['reference'] ?? '',
        'creditor_name' => $laybye['creditor_name'] ?? 'N/A',
        'laybye_status' => $laybye['status'] ?? '',
        'laybye_plan_frequency' => $laybye['plan_frequency'] ?? '',
        'laybye_next_due_date' => $laybye['next_due_date'] ?? '',
        'laybye_goods_total' => round($goodsTotal, 2),
        'laybye_deposit_amount' => round(floatval($laybye['deposit_amount'] ?? 0), 2),
        'total_balance' => round(floatval($laybye['balance_due'] ?? 0), 2),
        'items' => $items,
        'laybye_payment_lines' => $paymentLines,
        'business_name' => $businessInfo['name'] ?? 'POS SOLUTION',
        'location' => $businessInfo['location'] ?? '',
        'phone' => $businessInfo['phone'] ?? '',
        'footer_text' => $businessInfo['footer_text'] ?? 'Thank you!',
        'cashier_username' => $sessionUsername,
    ];
}

/**
 * @param PDO $db pos.db
 * @param array<string, mixed> $laybye
 * @param list<array<string, mixed>> $payments
 * @param array<string, mixed> $businessInfo
 *
 * @return array<string, array<string, mixed>> keyed by payment id string
 */
function laybyeBuildPaymentReceiptPayloads(PDO $db, array $laybye, array $payments, array $businessInfo): array
{
    $vatInclusive = $businessInfo['vat_inclusive'] ?? 'exclusive';
    $vatRate = isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0;
    $ref = (string) ($laybye['reference'] ?? '');
    $creditor = (string) ($laybye['creditor_name'] ?? '');
    $laybyeId = (int) ($laybye['id'] ?? 0);

    $mixedStmt = $db->prepare('SELECT cash_amount, eft_amount, eft_transaction_ref, eft_wallet_provider FROM mixed_payments WHERE order_id = ? LIMIT 1');

    $out = [];
    foreach ($payments as $p) {
        if (!laybyePaymentRowIsPrintable($p)) {
            continue;
        }
        $pid = (int) $p['id'];
        $amt = floatval($p['amount'] ?? 0);
        $method = strtolower((string) ($p['payment_method'] ?? 'cash'));
        $oid = !empty($p['order_id']) ? (int) $p['order_id'] : null;
        $receiptOrderId = $oid ? $oid : ('LB' . $laybyeId . 'P' . $pid);
        $kind = (string) ($p['payment_kind'] ?? 'payment');
        $label = 'Lay-bye ' . $kind . ($ref !== '' ? ' (' . $ref . ')' : '');
        $cashierName = laybyeResolveCashierNameForReceipt(isset($p['cashier_id']) ? (string) $p['cashier_id'] : null);

        $rec = [
            'order_id' => $receiptOrderId,
            'items' => [
                ['name' => $label, 'quantity' => 1, 'price' => $amt],
            ],
            'cashier_username' => $cashierName,
            'total' => $amt,
            'tips' => 0,
            'payment_method' => $method,
            'transaction_ref' => (string) ($p['transaction_ref'] ?? ''),
            'wallet_provider' => (string) ($p['wallet_provider'] ?? ''),
            'creditor_name' => $creditor,
            'print_only' => true,
            'is_payment_receipt' => true,
            'vat_inclusive' => $vatInclusive,
            'vat_rate' => $vatRate,
        ];
        if ($method === 'cash') {
            $rec['cash_received'] = $amt;
        }
        if ($method === 'mixed' && $oid) {
            $mixedStmt->execute([$oid]);
            $mix = $mixedStmt->fetch(PDO::FETCH_ASSOC);
            if ($mix) {
                $rec['cash_amount'] = floatval($mix['cash_amount'] ?? 0);
                $rec['eft_amount'] = floatval($mix['eft_amount'] ?? 0);
                $rec['eft_transaction_ref'] = (string) ($mix['eft_transaction_ref'] ?? '');
                $rec['eft_wallet_provider'] = (string) ($mix['eft_wallet_provider'] ?? '');
            }
        }
        $out[(string) $pid] = $rec;
    }

    return $out;
}
