<?php

/**
 * Build gratuity report data as a fixed percentage of total sales.
 */
function buildGratuityReportData(PDO $db, PDO $userDb, string $ordersWhereClause, string $cashierId = '', float $gratuityRatePercent = 7.0): array
{
    $gratuityRatePercent = max(0.0, min(100.0, round($gratuityRatePercent, 2)));
    $gratuityRate = $gratuityRatePercent / 100;

    $selectedCashierUserId = '';
    if ($cashierId !== '') {
        try {
            $uStmt = $userDb->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $uStmt->execute([$cashierId]);
            $uid = $uStmt->fetchColumn();
            if ($uid !== false) {
                $selectedCashierUserId = (string) $uid;
            }
        } catch (PDOException $e) {
        }
    }

    $cashierFilter = '';
    $params = [];
    if ($cashierId !== '') {
        $cashierFilter = ' AND (o.cashier_id = ? OR CAST(o.cashier_id AS TEXT) = ?)';
        $params = [$cashierId, $selectedCashierUserId];
    }

    $sumStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(o.total), 0) AS total_sales
        FROM orders o
        WHERE ($ordersWhereClause)$cashierFilter
    ");
    $sumStmt->execute($params);
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $byCashierStmt = $db->prepare("
        SELECT COALESCE(o.cashier_id, 'Unknown') AS cashier_name,
               COALESCE(SUM(o.total), 0) AS total_sales
        FROM orders o
        WHERE ($ordersWhereClause)$cashierFilter
        GROUP BY o.cashier_id
        ORDER BY total_sales DESC
    ");
    $byCashierStmt->execute($params);
    $byCashier = $byCashierStmt->fetchAll(PDO::FETCH_ASSOC);

    $eftTableExists = false;
    $mixedTableExists = false;
    try {
        $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn() !== false;
        $mixedTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mixed_payments'")->fetchColumn() !== false;
    } catch (PDOException $e) {
    }

    $detailWhere = "($ordersWhereClause)$cashierFilter";
    $detailParams = $params;

    $mixedJoin = '';
    $mixedSelect = ', 0 AS has_mixed, 0.0 AS mixed_cash, 0.0 AS mixed_eft';
    if ($mixedTableExists) {
        $mixedJoin = '
            LEFT JOIN (
                SELECT order_id, SUM(cash_amount) AS mc, SUM(eft_amount) AS me
                FROM mixed_payments GROUP BY order_id
            ) mx ON mx.order_id = o.id';
        $mixedSelect = ',
                CASE WHEN mx.order_id IS NOT NULL THEN 1 ELSE 0 END AS has_mixed,
                COALESCE(mx.mc, 0) AS mixed_cash,
                COALESCE(mx.me, 0) AS mixed_eft';
    }

    $eftJoin = '';
    $eftSelect = ', 0.0 AS eft_total';
    if ($eftTableExists) {
        $eftJoin = '
            LEFT JOIN (
                SELECT order_id, SUM(amount) AS eft_total FROM eft_payments GROUP BY order_id
            ) ep ON ep.order_id = o.id';
        $eftSelect = ', COALESCE(ep.eft_total, 0) AS eft_total';
    }

    $detailSql = "
        SELECT o.id AS order_id,
               o.created_at,
               COALESCE(o.cashier_id, 'Unknown') AS cashier_name,
               o.total AS order_total
               $mixedSelect
               $eftSelect
        FROM orders o
        $mixedJoin
        $eftJoin
        WHERE $detailWhere
        ORDER BY o.created_at DESC
    ";
    $detailStmt = $db->prepare($detailSql);
    $detailStmt->execute($detailParams);
    $detailLines = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $sumDetailCash = 0.0;
    $sumDetailCard = 0.0;
    $cashCardByCashier = [];
    foreach ($detailLines as $dl) {
        $hasMixed = !empty($dl['has_mixed']);
        if ($mixedTableExists && $hasMixed) {
            $cashAmt = round(floatval($dl['mixed_cash']), 2);
            $cardAmt = round(floatval($dl['mixed_eft']), 2);
        } else {
            $eft = round(floatval($dl['eft_total']), 2);
            $tot = round(floatval($dl['order_total']), 2);
            $cardAmt = $eft;
            $cashAmt = round(max(0.0, $tot - $eft), 2);
        }
        $sumDetailCash += $cashAmt;
        $sumDetailCard += $cardAmt;

        $key = (string) ($dl['cashier_name'] ?? 'Unknown');
        if (!isset($cashCardByCashier[$key])) {
            $cashCardByCashier[$key] = ['cash_total' => 0.0, 'card_total' => 0.0];
        }
        $cashCardByCashier[$key]['cash_total'] += $cashAmt;
        $cashCardByCashier[$key]['card_total'] += $cardAmt;
    }

    $totalSales = (float) ($sumRow['total_sales'] ?? 0);
    $totalGratuity = round($totalSales * $gratuityRate, 2);

    foreach ($byCashier as &$bcRow) {
        $sales = (float) ($bcRow['total_sales'] ?? 0);
        $key = (string) ($bcRow['cashier_name'] ?? 'Unknown');
        $bcRow['gratuity_total'] = round($sales * $gratuityRate, 2);
        $bcRow['cash_total'] = round($cashCardByCashier[$key]['cash_total'] ?? 0.0, 2);
        $bcRow['card_total'] = round($cashCardByCashier[$key]['card_total'] ?? 0.0, 2);
    }
    unset($bcRow);

    return [
        'summary' => [
            'total_gratuity' => $totalGratuity,
            'orders_with_gratuity' => (int) ($sumRow['total_orders'] ?? 0),
            'total_orders' => (int) ($sumRow['total_orders'] ?? 0),
            'total_sales' => $totalSales,
            'gratuity_rate_percent' => $gratuityRatePercent,
            'detail_cash_total' => round($sumDetailCash, 2),
            'detail_card_total' => round($sumDetailCard, 2),
        ],
        'by_cashier' => $byCashier,
    ];
}

function resolveGratuityReportRate(PDO $db, float $defaultPercent = 7.0): float
{
    try {
        $gp = $db->query('SELECT gratuity_percent FROM product_settings LIMIT 1')->fetchColumn();
        if ($gp !== false && (float) $gp > 0) {
            return (float) $gp;
        }
    } catch (PDOException $e) {
    }

    return $defaultPercent;
}
