<?php

/**
 * Build receipt payload for cash back printing.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function buildCashBackReceiptData(array $params): array
{
    $amount = floatval($params['amount'] ?? 0);

    return [
        'is_cash_back_receipt' => true,
        'print_only' => true,
        'order_id' => $params['order_id'] ?? null,
        'cash_transaction_id' => $params['cash_transaction_id'] ?? null,
        'amount' => $amount,
        'total' => $amount,
        'payment_method' => 'cash',
        'wallet_provider' => $params['wallet_provider'] ?? 'Customer',
        'transaction_ref' => $params['transaction_ref'] ?? '',
        'transaction_date' => $params['transaction_date'] ?? date('Y-m-d'),
        'cashier_username' => $params['cashier_username'] ?? 'Unknown',
        'description' => $params['description'] ?? 'Cash Back',
        'is_cash_back_copy' => !empty($params['is_cash_back_copy']),
    ];
}
