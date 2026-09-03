<?php
/**
 * Shared cash transaction totals for till balance and withdrawals.
 *
 * Only manual cash-in / cash-out belong in Cash Management.
 * Exchange differences are already reflected in order totals when products change.
 */

function cashWithdrawalsSumExpr(): string
{
    return "CASE
        WHEN type = 'cash-out' THEN amount
        WHEN type = 'refund' THEN ABS(amount)
        ELSE 0
    END";
}

function cashWithdrawalsSumSql(): string
{
    return 'COALESCE(SUM(' . cashWithdrawalsSumExpr() . '), 0)';
}

function sumCashWithdrawalsFromQuery(PDO $db, string $whereSql, callable $bindFn): float
{
    $stmt = $db->prepare('SELECT ' . cashWithdrawalsSumSql() . ' FROM cash_transactions WHERE ' . $whereSql);
    $bindFn($stmt);
    $stmt->execute();
    return (float) $stmt->fetchColumn();
}

/**
 * Normalize stored amounts for display.
 * Refunds are stored signed; cash-out is stored positive.
 */
function cashTransactionDisplayMeta(array $transaction): array
{
    $type = (string) ($transaction['type'] ?? '');
    $amount = (float) ($transaction['amount'] ?? 0);

    if ($type === 'cash-in') {
        return [
            'amount' => abs($amount),
            'direction' => 'in',
        ];
    }

    return [
        'amount' => abs($amount),
        'direction' => 'out',
    ];
}

/** Cash Management only tracks manual cash-in/out (not exchange difference rows). */
function isCashManagementTransaction(array $transaction): bool
{
    $type = (string) ($transaction['type'] ?? '');
    return in_array($type, ['cash-in', 'cash-out', 'refund'], true);
}

function cashManagementTypesSql(string $typeColumn = 'type'): string
{
    return "{$typeColumn} IN ('cash-in', 'cash-out', 'refund')";
}

/** SQL exclusions for till expenses (not tips or cash back). */
function cashExpenseDescriptionExclusionSql(string $descriptionColumn = 'description'): string
{
    return "(
        {$descriptionColumn} NOT LIKE '%Tips%'
        AND {$descriptionColumn} NOT LIKE '%Cash Back%'
        AND {$descriptionColumn} NOT LIKE '%tip%'
        AND {$descriptionColumn} NOT LIKE '%cash back%'
    )";
}

function cashReportOutflowWhereSql(string $descriptionColumn = 'description'): string
{
    $exclude = cashExpenseDescriptionExclusionSql($descriptionColumn);

    return "(
        (type = 'cash-out' AND {$exclude})
        OR type = 'refund'
    )";
}

/** Whether a daily-report row represents cash leaving the till. */
function reportsIsOutflowRow(array $row): bool
{
    $st = strtolower(trim((string) ($row['sale_type'] ?? '')));
    if (in_array($st, ['expense', 'refund', 'cash_back'], true)) {
        return true;
    }

    $products = (string) ($row['products'] ?? '');
    return stripos($products, 'Refund for Order #') !== false;
}

/** Signed amount for report tables (outflows are negative). */
function reportsDisplayTotal(array $row): float
{
    $total = (float) ($row['total'] ?? 0);

    return reportsIsOutflowRow($row) ? -abs($total) : $total;
}

/** Sum reportable expenses (cash-out, excluding tips/cash back) for a date WHERE clause. */
function sumCashReportExpensesForWhere(PDO $db, string $whereSql): float
{
    $exclude = cashExpenseDescriptionExclusionSql('description');
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cash_transactions
            WHERE type = 'cash-out'
              AND {$exclude}
              AND ({$whereSql})
        ");
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Sum cash-back withdrawals for a date WHERE clause. */
function sumCashReportCashBackForWhere(PDO $db, string $whereSql): float
{
    $cashBackWhere = cashBackDescriptionSql('description');
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM cash_transactions
            WHERE type = 'cash-out'
              AND {$cashBackWhere}
              AND ({$whereSql})
        ");
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Expenses, refunds, and cash back — amounts to subtract from gross cash sales. */
function sumCashTillDeductionsForWhere(PDO $db, string $whereSql): float
{
    return sumCashReportOutflowsForWhere($db, $whereSql)
        + sumCashReportCashBackForWhere($db, $whereSql);
}

/** Sum till deductions for a single business day (reports pages). */
function sumCashTillDeductionsReportTotal(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    return sumExpenseReportTotal($db, $selectedDate, $nextDay, $closingTime, $isAfterMidnight)
        + sumCashBackReportTotal($db, $selectedDate, $nextDay, $closingTime, $isAfterMidnight);
}

/** Sum refunds for a date WHERE clause. */
function sumCashReportRefundsForWhere(PDO $db, string $whereSql): float
{
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(ABS(amount)), 0)
            FROM cash_transactions
            WHERE type = 'refund'
              AND ({$whereSql})
        ");
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Sum expenses and refunds for a date WHERE clause. */
function sumCashReportOutflowsForWhere(PDO $db, string $whereSql): float
{
    $outflowWhere = cashReportOutflowWhereSql('description');
    try {
        $stmt = $db->prepare('
            SELECT COALESCE(SUM(' . cashWithdrawalsSumExpr() . '), 0)
            FROM cash_transactions
            WHERE ' . $outflowWhere . '
              AND (' . $whereSql . ')
        ');
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Prepared statement for outflow totals using business-day bind params (:date, :nextDay). */
function prepareCashReportOutflowsSumStatement(PDO $db, string $bdWhereCreated): PDOStatement
{
    $outflowWhere = cashReportOutflowWhereSql('description');

    return $db->prepare('
        SELECT COALESCE(SUM(' . cashWithdrawalsSumExpr() . '), 0)
        FROM cash_transactions
        WHERE ' . $outflowWhere . '
          AND (' . $bdWhereCreated . ')
    ');
}

function cashBackDescriptionSql(string $descriptionColumn = 'description'): string
{
    return "(
        {$descriptionColumn} LIKE '%Cash Back%'
        OR {$descriptionColumn} LIKE '%cash back%'
    )";
}

function fetchCashBackReportRows(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): array {
    require_once __DIR__ . '/business_day_helper.php';

    $where = bdSingleDayWhereSql('created_at', ':selectedDate', ':nextDay', $closingTime, $isAfterMidnight);
    $cashBackWhere = cashBackDescriptionSql('description');
    $sql = "
        SELECT id, type, amount, description, cashier_id, created_at, terminal_name, terminal_mac
        FROM cash_transactions
        WHERE type = 'cash-out'
          AND {$cashBackWhere}
          AND ({$where})
        ORDER BY created_at DESC
    ";

    try {
        $stmt = $db->prepare($sql);
        bdBindSingleDayParams($stmt, $selectedDate, $nextDay);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $description = trim((string) ($row['description'] ?? ''));
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'cash_transaction_id' => (int) ($row['id'] ?? 0),
            'total' => abs((float) ($row['amount'] ?? 0)),
            'tips' => 0,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'payment_date' => null,
            'products' => $description !== '' ? $description : 'Cash Back',
            'sale_type' => 'cash_back',
            'payment_status' => 'paid',
            'provider_name' => null,
            'creditor_name' => null,
            'cashier_id' => $row['cashier_id'] ?? null,
            'tab_name' => null,
            'tab_cashier_id' => null,
            'laybye_id' => null,
            'laybye_reference' => null,
            'laybye_payment_kind' => null,
            'laybye_creditor_name' => null,
            'terminal_name' => $row['terminal_name'] ?? null,
            'terminal_mac' => $row['terminal_mac'] ?? null,
        ];
    }

    return $out;
}

function fetchExpenseReportRows(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): array {
    require_once __DIR__ . '/business_day_helper.php';

    $where = bdSingleDayWhereSql('created_at', ':selectedDate', ':nextDay', $closingTime, $isAfterMidnight);
    $outflowWhere = cashReportOutflowWhereSql('description');
    $sql = "
        SELECT id, type, amount, description, cashier_id, created_at, terminal_name, terminal_mac
        FROM cash_transactions
        WHERE {$outflowWhere}
          AND ({$where})
        ORDER BY created_at DESC
    ";

    try {
        $stmt = $db->prepare($sql);
        bdBindSingleDayParams($stmt, $selectedDate, $nextDay);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $txnType = (string) ($row['type'] ?? 'cash-out');
        $isRefund = $txnType === 'refund';
        $description = trim((string) ($row['description'] ?? ''));
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'cash_transaction_id' => (int) ($row['id'] ?? 0),
            'total' => abs((float) ($row['amount'] ?? 0)),
            'tips' => 0,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'payment_date' => null,
            'products' => $description !== '' ? $description : ($isRefund ? 'Refund' : 'Expense'),
            'sale_type' => $isRefund ? 'refund' : 'expense',
            'payment_status' => 'paid',
            'provider_name' => null,
            'creditor_name' => null,
            'cashier_id' => $row['cashier_id'] ?? null,
            'tab_name' => null,
            'tab_cashier_id' => null,
            'laybye_id' => null,
            'laybye_reference' => null,
            'laybye_payment_kind' => null,
            'laybye_creditor_name' => null,
            'terminal_name' => $row['terminal_name'] ?? null,
            'terminal_mac' => $row['terminal_mac'] ?? null,
        ];
    }

    return $out;
}

/** Sum till expenses for a business day (excludes tips and cash back). */
function sumExpenseReportTotal(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    require_once __DIR__ . '/business_day_helper.php';

    $where = bdSingleDayWhereSql('created_at', ':selectedDate', ':nextDay', $closingTime, $isAfterMidnight);
    $outflowWhere = cashReportOutflowWhereSql('description');
    $sql = "
        SELECT COALESCE(SUM(" . cashWithdrawalsSumExpr() . "), 0)
        FROM cash_transactions
        WHERE {$outflowWhere}
          AND ({$where})
    ";

    try {
        $stmt = $db->prepare($sql);
        bdBindSingleDayParams($stmt, $selectedDate, $nextDay);
        $stmt->execute();
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Sum cash-back withdrawals for a business day (POS change, etc.). */
function sumCashBackReportTotal(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    require_once __DIR__ . '/business_day_helper.php';

    $where = bdSingleDayWhereSql('created_at', ':selectedDate', ':nextDay', $closingTime, $isAfterMidnight);
    $cashBackWhere = cashBackDescriptionSql('description');
    $sql = "
        SELECT COALESCE(SUM(amount), 0)
        FROM cash_transactions
        WHERE type = 'cash-out'
          AND {$cashBackWhere}
          AND ({$where})
    ";

    try {
        $stmt = $db->prepare($sql);
        bdBindSingleDayParams($stmt, $selectedDate, $nextDay);
        $stmt->execute();
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}
