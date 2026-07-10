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
