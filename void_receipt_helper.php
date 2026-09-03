<?php
/**
 * Build receipt payload for void transaction printing.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function buildVoidReceiptData(array $params): array
{
    $items = $params['items'] ?? [];
    $formattedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $formattedItems[] = [
            'product_name' => $item['product_name'] ?? $item['name'] ?? 'Unknown',
            'quantity' => $item['quantity'] ?? 1,
            'price' => $item['price'] ?? 0,
        ];
    }

    return [
        'is_void_receipt' => true,
        'void_id' => $params['void_id'] ?? null,
        'order_id' => $params['order_id'] ?? null,
        'credit_sale_id' => $params['credit_sale_id'] ?? null,
        'items' => $formattedItems,
        'total' => floatval($params['total'] ?? 0),
        'payment_method' => $params['payment_method'] ?? 'cash',
        'cash_received' => floatval($params['cash_received'] ?? 0),
        'eft_amount' => isset($params['eft_amount']) ? floatval($params['eft_amount']) : null,
        'transaction_ref' => $params['transaction_ref'] ?? null,
        'wallet_provider' => $params['wallet_provider'] ?? null,
        'cashier_username' => $params['cashier_username'] ?? $params['cashier_id'] ?? 'Unknown',
        'void_source' => $params['void_source'] ?? 'void',
        'creditor_name' => $params['creditor_name'] ?? null,
        'reason' => $params['reason'] ?? null,
        'print_only' => true,
    ];
}
