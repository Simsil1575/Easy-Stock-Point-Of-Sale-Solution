<?php

declare(strict_types=1);

require_once __DIR__ . '/business_day_helper.php';
require_once __DIR__ . '/medical_aid_lib.php';

/**
 * Medical aid rows and totals for Transactions / Sales reports.
 */

function medicalAidSalesTableExists(PDO $db): bool
{
    return medicalAidTableExists($db, 'medical_aid_sales');
}

function medicalAidBusinessDayWhere(
    string $column,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): string {
    return bdSingleDayWhereSql($column, medicalAidQuoteSql($selectedDate), medicalAidQuoteSql($nextDay), $closingTime, $isAfterMidnight);
}

function fetchMedicalAidSaleReportRows(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): array {
    if (!medicalAidSalesTableExists($db)) {
        return [];
    }

    $where = medicalAidBusinessDayWhere('s.created_at', $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    $sql = "
        SELECT
            s.id,
            s.total_amount,
            s.paid_amount,
            s.payment_status,
            s.sale_type,
            s.created_at,
            s.cashier_id,
            p.patient_name,
            p.scheme_name,
            p.member_number,
            (
                SELECT GROUP_CONCAT(i.product_name || ' (x' || i.quantity || ')', ', ')
                FROM medical_aid_sale_items i
                WHERE i.sale_id = s.id
            ) AS items_summary
        FROM medical_aid_sales s
        JOIN medical_aid_patients p ON p.id = s.patient_id
        WHERE s.payment_status IN ('unpaid', 'partial')
          AND {$where}
        ORDER BY s.created_at DESC
    ";

    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $due = (float) ($row['total_amount'] ?? 0) - (float) ($row['paid_amount'] ?? 0);
        if ($due <= 0) {
            continue;
        }
        $patientName = trim((string) ($row['patient_name'] ?? ''));
        $scheme = trim((string) ($row['scheme_name'] ?? ''));
        $member = trim((string) ($row['member_number'] ?? ''));
        $items = trim((string) ($row['items_summary'] ?? ''));
        $products = 'Medical Aid — ' . $patientName;
        if ($scheme !== '') {
            $products .= ' (' . $scheme;
            if ($member !== '') {
                $products .= ' · ' . $member;
            }
            $products .= ')';
        }
        if ($items !== '') {
            $products .= ' — ' . $items;
        }

        $out[] = [
            'id' => 'MA-' . (int) $row['id'],
            'raw_id' => (int) $row['id'],
            'total' => $due,
            'tips' => 0,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'products' => $products,
            'sale_type' => 'medical_aid_unpaid',
            'payment_status' => (string) ($row['payment_status'] ?? 'unpaid'),
            'provider_name' => $scheme ?: null,
            'creditor_name' => $patientName,
            'cashier_id' => (string) ($row['cashier_id'] ?? 'Unknown'),
            'tab_name' => null,
            'tab_cashier_id' => null,
            'laybye_id' => null,
            'laybye_reference' => null,
            'laybye_payment_kind' => null,
            'laybye_creditor_name' => null,
            'member_number' => $member,
            'is_medical_aid' => true,
        ];
    }

    return $out;
}

function fetchMedicalAidPaymentReportRows(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): array {
    if (!medicalAidTableExists($db, 'medical_aid_payments')) {
        return [];
    }

    $where = medicalAidBusinessDayWhere('mp.created_at', $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    $sql = "
        SELECT
            mp.id,
            mp.amount,
            mp.payment_reference,
            mp.scheme_name,
            mp.created_at,
            mp.recorded_by,
            p.patient_name,
            p.member_number
        FROM medical_aid_payments mp
        JOIN medical_aid_patients p ON p.id = mp.patient_id
        WHERE {$where}
        ORDER BY mp.created_at DESC
    ";

    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ref = trim((string) ($row['payment_reference'] ?? ''));
        $patientName = trim((string) ($row['patient_name'] ?? ''));
        $scheme = trim((string) ($row['scheme_name'] ?? ''));
        $products = 'Medical Aid Payment — ' . $patientName;
        if ($ref !== '') {
            $products .= ' (Ref: ' . $ref . ')';
        }

        $out[] = [
            'id' => 'MAP-' . (int) $row['id'],
            'raw_id' => (int) $row['id'],
            'total' => (float) ($row['amount'] ?? 0),
            'tips' => 0,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'products' => $products,
            'sale_type' => 'medical_aid_payment',
            'payment_status' => 'paid',
            'provider_name' => $scheme ?: null,
            'creditor_name' => $patientName,
            'cashier_id' => (string) ($row['recorded_by'] ?? 'Unknown'),
            'tab_name' => null,
            'tab_cashier_id' => null,
            'laybye_id' => null,
            'laybye_reference' => null,
            'laybye_payment_kind' => null,
            'laybye_creditor_name' => null,
            'payment_reference' => $ref,
            'is_medical_aid' => true,
        ];
    }

    return $out;
}

function sumMedicalAidUnpaid(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    if (!medicalAidSalesTableExists($db)) {
        return 0.0;
    }

    $where = medicalAidBusinessDayWhere('created_at', $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    try {
        $sql = "
            SELECT COALESCE(SUM(total_amount - paid_amount), 0)
            FROM medical_aid_sales
            WHERE payment_status IN ('unpaid', 'partial')
              AND {$where}
        ";
        return round((float) $db->query($sql)->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function sumMedicalAidPayments(
    PDO $db,
    string $selectedDate,
    string $nextDay,
    string $closingTime,
    bool $isAfterMidnight
): float {
    if (!medicalAidTableExists($db, 'medical_aid_payments')) {
        return 0.0;
    }

    $where = medicalAidBusinessDayWhere('created_at', $selectedDate, $nextDay, $closingTime, $isAfterMidnight);

    try {
        $sql = "
            SELECT COALESCE(SUM(amount), 0)
            FROM medical_aid_payments
            WHERE {$where}
        ";
        return round((float) $db->query($sql)->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * SQL UNION ALL fragments for daily breakdown queries (medical aid unpaid + payments).
 */
function medicalAidDailyBreakdownUnionSql(string $closingTime, bool $isAfterMidnight): string
{
    $afterMidnight = $isAfterMidnight ? '1=1' : '1=0';

    return "
        UNION ALL

        SELECT
            CASE
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '{$closingTime}' AND {$afterMidnight}
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date,
            (total_amount - paid_amount) as amount,
            'medical_aid_unpaid' as source,
            'income' as transaction_type
        FROM medical_aid_sales
        WHERE payment_status IN ('unpaid', 'partial')

        UNION ALL

        SELECT
            CASE
                WHEN strftime('%H:%M', created_at) BETWEEN '00:00' AND '{$closingTime}' AND {$afterMidnight}
                THEN date(datetime(created_at, '-1 day'))
                ELSE date(created_at)
            END AS business_date,
            amount,
            'medical_aid_payment' as source,
            'income' as transaction_type
        FROM medical_aid_payments
    ";
}

function medicalAidDailyBreakdownSumColumns(): string
{
    return "
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'medical_aid_unpaid' THEN t.amount ELSE 0 END) as medical_aid_unpaid,
        SUM(CASE WHEN t.transaction_type = 'income' AND t.source = 'medical_aid_payment' THEN t.amount ELSE 0 END) as medical_aid_payments,
    ";
}

function medicalAidQuoteSql(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}
