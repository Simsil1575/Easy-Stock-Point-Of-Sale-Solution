<?php
/**
 * Shared receipt payment helpers (mixed cash + EFT change, etc.)
 */
if (!function_exists('receipt_mixed_payment_change')) {
    /**
     * Change to return when customer tendered more cash + EFT than amount due.
     *
     * @param array<string,mixed> $data Receipt payload (cash_amount, eft_amount, cash_received)
     */
    function receipt_mixed_payment_change(array $data, float $amountDue): float
    {
        $cash = floatval($data['cash_amount'] ?? $data['cash_received'] ?? 0);
        $eft = floatval($data['eft_amount'] ?? 0);

        return round(max(0.0, $cash + $eft - $amountDue), 2);
    }
}

if (!function_exists('receipt_payment_cash_back')) {
    /**
     * Cash change to give back (EFT overpayment or explicit cash_back on receipt payload).
     *
     * @param array<string,mixed> $data
     */
    function receipt_payment_cash_back(array $data): float
    {
        $cashBack = round(floatval($data['cash_back'] ?? $data['cash_back_amount'] ?? 0), 2);
        if ($cashBack > 0.001) {
            return $cashBack;
        }

        $method = strtolower(trim((string) ($data['payment_method'] ?? '')));
        $total = floatval($data['total'] ?? $data['total_amount'] ?? 0);

        if ($method === 'e-wallet' || $method === 'eft') {
            $eft = floatval($data['eft_amount'] ?? 0);

            return round(max(0.0, $eft - $total), 2);
        }

        if ($method === 'mixed') {
            return receipt_mixed_payment_change($data, $total);
        }

        return 0.0;
    }
}

if (!function_exists('receipt_should_open_drawer')) {
    /**
     * Whether the cash drawer should open for this receipt payload.
     *
     * @param array<string,mixed> $data
     */
    function receipt_should_open_drawer(array $data): bool
    {
        if (!empty($data['open_drawer_only']) || !empty($data['force_open_drawer'])) {
            return true;
        }

        if (!empty($data['is_cash_back_receipt']) && empty($data['is_cash_back_copy'])) {
            return true;
        }

        if (receipt_payment_cash_back($data) > 0.001) {
            return true;
        }

        $method = strtolower(trim((string) ($data['payment_method'] ?? '')));

        if ($method === 'cash') {
            return true;
        }

        if ($method === 'mixed') {
            $cash = floatval($data['cash_amount'] ?? $data['cash_received'] ?? 0);

            return $cash > 0.001;
        }

        if ($method === 'credit' || $method === 'medical_aid') {
            return false;
        }

        return false;
    }
}

if (!function_exists('receipt_should_pulse_drawer_for_print')) {
    /**
     * Whether drawer pulse should run before/during a receipt print job.
     *
     * @param array<string,mixed> $data
     */
    function receipt_should_pulse_drawer_for_print(array $data): bool
    {
        if (!empty($data['is_cash_back_copy'])) {
            return false;
        }

        if (isset($data['creditor_id']) && !isset($data['is_payment_receipt'])) {
            return false;
        }

        if (!empty($data['is_cashup_report'])
            || !empty($data['is_balance_receipt'])
            || !empty($data['is_tab_balance_receipt'])) {
            return false;
        }

        return receipt_should_open_drawer($data);
    }
}
