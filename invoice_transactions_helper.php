<?php

declare(strict_types=1);

/**
 * Invoice payment rows and totals for Transactions / Sales reports.
 */

function invoicePaymentTimestampExpr(string $alias = 'ip'): string
{
    return "COALESCE({$alias}.created_at, {$alias}.payment_date || ' 12:00:00')";
}

function invoicePaymentBusinessDayWhere(
    string $column,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): string {
    $afterMidnightFlag = $isAfterMidnight ? '1=1' : '1=0';
    return "(
        (DATE({$column}) = " . quoteSql($selectedDate) . " AND strftime('%H:%M', {$column}) >= " . quoteSql($closingTime) . ") OR
        (DATE({$column}) = " . quoteSql($nextDay) . " AND strftime('%H:%M', {$column}) < " . quoteSql($closingTime) . " AND {$afterMidnightFlag})
    )";
}

function invoicePaymentsTableExists(PDO $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $exists = (bool) $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='invoice_payments'")->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function invoicePaymentMethodToSaleType(string $method): ?string
{
    switch ($method) {
        case 'Cash':
            return 'invoice_cash';
        case 'Card':
        case 'Mobile Money':
            return 'invoice_eft';
        case 'Bank Transfer':
        case 'Cheque':
            return 'invoice_payment';
        case 'Credit':
        default:
            return null;
    }
}

function invoicePaymentEftMethodsSql(): string
{
    return "'Card','Mobile Money'";
}

function fetchInvoicePaymentReportRows(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): array {
    if (!invoicePaymentsTableExists($db)) {
        return [];
    }

    $ts = invoicePaymentTimestampExpr('ip');
    $where = invoicePaymentBusinessDayWhere($ts, $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    $sql = "
        SELECT
            ip.id,
            ip.invoice_id,
            ip.amount AS total,
            ip.payment_method,
            ip.reference,
            ip.received_by AS cashier_id,
            {$ts} AS created_at,
            ip.payment_date,
            i.invoice_number,
            COALESCE(c.name, 'Walk-in') AS customer_name,
            (
                SELECT GROUP_CONCAT(
                    COALESCE(ii.description, 'Item') || ' (x' || ii.quantity || ')',
                    ', '
                )
                FROM invoice_items ii
                WHERE ii.invoice_id = i.id
            ) AS item_summary
        FROM invoice_payments ip
        JOIN invoices i ON i.id = ip.invoice_id
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE ip.payment_method != 'Credit'
          AND {$where}
        ORDER BY {$ts} DESC
    ";

    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $saleType = invoicePaymentMethodToSaleType((string) ($row['payment_method'] ?? ''));
        if ($saleType === null) {
            continue;
        }

        $invoiceLabel = trim((string) ($row['invoice_number'] ?? ''));
        if ($invoiceLabel === '') {
            $invoiceLabel = 'INV-' . (int) ($row['invoice_id'] ?? 0);
        }
        $customerName = trim((string) ($row['customer_name'] ?? ''));
        $itemSummary = trim((string) ($row['item_summary'] ?? ''));
        $products = 'Invoice ' . $invoiceLabel . ' — ' . $customerName;
        if ($itemSummary !== '') {
            $products .= ' (' . $itemSummary . ')';
        }

        $out[] = [
            'id' => 'IP-' . (int) $row['id'],
            'raw_id' => (int) $row['id'],
            'invoice_id' => (int) ($row['invoice_id'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
            'tips' => 0,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'payment_date' => (string) ($row['payment_date'] ?? ''),
            'products' => $products,
            'sale_type' => $saleType,
            'payment_status' => 'paid',
            'payment_method' => (string) ($row['payment_method'] ?? ''),
            'provider_name' => null,
            'creditor_name' => $customerName,
            'cashier_id' => (string) ($row['cashier_id'] ?? 'Unknown'),
            'tab_name' => null,
            'tab_cashier_id' => null,
            'laybye_id' => null,
            'laybye_reference' => null,
            'laybye_payment_kind' => null,
            'laybye_creditor_name' => null,
            'is_invoice_payment' => true,
        ];
    }

    return $out;
}

function sumInvoiceCashPayments(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    if (!invoicePaymentsTableExists($db)) {
        return 0.0;
    }

    $ts = invoicePaymentTimestampExpr('ip');
    $where = invoicePaymentBusinessDayWhere($ts, $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    try {
        $sql = "
            SELECT COALESCE(SUM(ip.amount), 0)
            FROM invoice_payments ip
            WHERE ip.payment_method = 'Cash'
              AND {$where}
        ";
        return round((float) $db->query($sql)->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Sum invoice cash payments for a datetime range (cash-up / till APIs).
 * Optional cashier filter matches received_by (username or numeric id string).
 */
function sumInvoiceCashPaymentsInRange(
    PDO $db,
    string $startDatetime,
    string $endDatetime,
    ?string $cashierId = null,
    ?int $cashierNumericId = null
): float {
    if (!invoicePaymentsTableExists($db)) {
        return 0.0;
    }

    $ts = invoicePaymentTimestampExpr('ip');
    $sql = "
        SELECT COALESCE(SUM(ip.amount), 0)
        FROM invoice_payments ip
        WHERE ip.payment_method = 'Cash'
          AND datetime({$ts}) >= datetime(:startDatetime)
          AND datetime({$ts}) <= datetime(:endDatetime)
    ";

    $applyCashierFilter = ($cashierId !== null && $cashierId !== '' && $cashierId !== 'all');
    if ($applyCashierFilter) {
        if ($cashierNumericId !== null) {
            $sql .= ' AND (ip.received_by = :cashierId OR ip.received_by = :cashierNumericId)';
        } else {
            $sql .= ' AND ip.received_by = :cashierId';
        }
    }

    try {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':startDatetime', $startDatetime);
        $stmt->bindValue(':endDatetime', $endDatetime);
        if ($applyCashierFilter) {
            $stmt->bindValue(':cashierId', $cashierId);
            if ($cashierNumericId !== null) {
                $stmt->bindValue(':cashierNumericId', (string) $cashierNumericId);
            }
        }
        $stmt->execute();
        return round((float) $stmt->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function sumInvoiceEftPayments(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    if (!invoicePaymentsTableExists($db)) {
        return 0.0;
    }

    $ts = invoicePaymentTimestampExpr('ip');
    $where = invoicePaymentBusinessDayWhere($ts, $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    try {
        $sql = "
            SELECT COALESCE(SUM(ip.amount), 0)
            FROM invoice_payments ip
            WHERE ip.payment_method IN (" . invoicePaymentEftMethodsSql() . ")
              AND {$where}
        ";
        return round((float) $db->query($sql)->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function fetchInvoicePaymentExportRows(PDO $db, string $startDate, string $endDate): array
{
    if (!invoicePaymentsTableExists($db)) {
        return [];
    }

    $ts = invoicePaymentTimestampExpr('ip');
    $sql = "
        SELECT
            ip.id,
            ip.invoice_id,
            i.invoice_number,
            COALESCE(c.name, 'Walk-in') AS customer_name,
            ip.payment_date,
            ip.payment_method,
            ip.amount,
            ip.reference,
            ip.received_by,
            ip.notes,
            {$ts} AS recorded_at
        FROM invoice_payments ip
        JOIN invoices i ON i.id = ip.invoice_id
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE DATE({$ts}) >= " . quoteSql($startDate) . "
          AND DATE({$ts}) <= " . quoteSql($endDate) . "
          AND ip.payment_method != 'Credit'
        ORDER BY {$ts} DESC
    ";

    try {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function quoteSql(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}
