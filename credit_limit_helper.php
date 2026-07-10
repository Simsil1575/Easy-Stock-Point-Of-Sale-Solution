<?php

/**
 * Shared credit-limit logic for creditors.
 * credit_limit <= 0 means unlimited (backward compatible with default 0).
 */

function normalizeCreditLimit($value): float
{
    $limit = round(max(0, floatval($value)), 2);
    return $limit;
}

function getCreditorOutstandingBalance(PDO $db, int $creditorId): float
{
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount), 0) AS balance
        FROM credit_sales
        WHERE creditor_id = ?
    ");
    $stmt->execute([$creditorId]);
    return round(max(0, (float) $stmt->fetchColumn()), 2);
}

function getCreditorCreditLimit(PDO $db, int $creditorId): float
{
    $stmt = $db->prepare("SELECT COALESCE(credit_limit, 0) FROM creditors WHERE id = ?");
    $stmt->execute([$creditorId]);
    $row = $stmt->fetchColumn();
    if ($row === false) {
        return 0.0;
    }
    return normalizeCreditLimit($row);
}

function isCreditLimitEnforced(float $creditLimit): bool
{
    return $creditLimit > 0;
}

function computeAvailableCredit(float $creditLimit, float $outstandingBalance): float
{
    if (!isCreditLimitEnforced($creditLimit)) {
        return PHP_FLOAT_MAX;
    }
    return round(max(0, $creditLimit - $outstandingBalance), 2);
}

function getCreditorCreditInfo(PDO $db, int $creditorId): array
{
    $creditLimit = getCreditorCreditLimit($db, $creditorId);
    $outstandingBalance = getCreditorOutstandingBalance($db, $creditorId);
    $isLimitEnforced = isCreditLimitEnforced($creditLimit);
    $availableCredit = $isLimitEnforced
        ? computeAvailableCredit($creditLimit, $outstandingBalance)
        : null;

    return [
        'credit_limit' => $creditLimit,
        'outstanding_balance' => $outstandingBalance,
        'available_credit' => $availableCredit,
        'is_limit_enforced' => $isLimitEnforced,
        'can_accept_amount' => !$isLimitEnforced || $availableCredit > 0.005,
    ];
}

function formatCreditCurrency(float $amount): string
{
    return 'N$' . number_format($amount, 2);
}

function assertCreditSaleWithinLimit(PDO $db, int $creditorId, float $saleAmount): void
{
    $saleAmount = round(max(0, $saleAmount), 2);
    if ($saleAmount <= 0) {
        return;
    }

    $info = getCreditorCreditInfo($db, $creditorId);
    if (!$info['is_limit_enforced']) {
        return;
    }

    $available = (float) $info['available_credit'];
    if ($saleAmount > $available + 0.005) {
        throw new Exception(sprintf(
            'Credit limit exceeded. Limit: %s, Outstanding: %s, Available: %s, Requested: %s',
            formatCreditCurrency($info['credit_limit']),
            formatCreditCurrency($info['outstanding_balance']),
            formatCreditCurrency($available),
            formatCreditCurrency($saleAmount)
        ));
    }
}

function enrichCreditorWithCreditInfo(PDO $db, array $creditor): array
{
    $creditorId = (int) ($creditor['id'] ?? 0);
    if ($creditorId <= 0) {
        return $creditor;
    }

    $info = getCreditorCreditInfo($db, $creditorId);
    $creditor['credit_limit'] = $info['credit_limit'];
    $creditor['outstanding_balance'] = isset($creditor['outstanding_balance'])
        ? round((float) $creditor['outstanding_balance'], 2)
        : $info['outstanding_balance'];
    $creditor['available_credit'] = $info['available_credit'];
    $creditor['is_limit_enforced'] = $info['is_limit_enforced'];
    $creditor['can_accept_amount'] = $info['can_accept_amount'];

    return $creditor;
}
