<?php

/**
 * Build tips report from recorded tips (tips table) and checkout/tab gratuity (orders.gratuity_amount).
 */
function buildTipsReportData(PDO $db, PDO $userDb, string $tipsWhereClause, string $ordersWhereClause, string $cashierId = ''): array
{
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

    $tipsCashierFilter = '';
    $ordersCashierFilter = '';
    $tipsParams = [];
    $ordersParams = [];
    if ($cashierId !== '') {
        $tipsCashierFilter = ' AND t.cashier_id = ?';
        $tipsParams = [$cashierId];
        $ordersCashierFilter = ' AND (o.cashier_id = ? OR CAST(o.cashier_id AS TEXT) = ?)';
        $ordersParams = [$cashierId, $selectedCashierUserId];
    }

    $entries = [];
    $byCashier = [];
    $byMethod = [
        'cash' => 0.0,
        'card' => 0.0,
        'inventory' => 0.0,
        'other' => 0.0,
    ];
    $manualTotal = 0.0;
    $orderGratuityTotal = 0.0;

    $tipsTableExists = false;
    try {
        $tipsTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tips'")->fetchColumn() !== false;
    } catch (PDOException $e) {
    }

    if ($tipsTableExists) {
        $tipsStmt = $db->prepare("
            SELECT t.id, t.amount, t.payment_method, t.cashier_id, t.notes, t.created_at
            FROM tips t
            WHERE ($tipsWhereClause)$tipsCashierFilter
            ORDER BY t.created_at DESC
        ");
        $tipsStmt->execute($tipsParams);
        foreach ($tipsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $cashierName = (string) ($row['cashier_id'] ?? 'Unknown');
            $methodKey = tipsReportMethodKey((string) ($row['payment_method'] ?? ''));
            $manualTotal += $amount;
            $byMethod[$methodKey] = round($byMethod[$methodKey] + $amount, 2);
            tipsReportAccumulateCashier($byCashier, $cashierName, $amount, $methodKey);

            $entries[] = [
                'created_at' => $row['created_at'] ?? '',
                'source' => 'Manual',
                'cashier_name' => $cashierName,
                'payment_method' => tipsReportMethodLabel($methodKey),
                'amount' => $amount,
                'reference' => (string) ($row['notes'] ?? ''),
            ];
        }
    }

    ensure_orders_gratuity_columns_for_tips_report($db);

    $eftTableExists = false;
    $mixedTableExists = false;
    $tabPaymentsExists = false;
    try {
        $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn() !== false;
        $mixedTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mixed_payments'")->fetchColumn() !== false;
        $tabPaymentsExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tab_payments'")->fetchColumn() !== false;
    } catch (PDOException $e) {
    }

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

    $tabJoin = '';
    $tabSelect = ', 0 AS is_tab_payment, NULL AS tab_name';
    if ($tabPaymentsExists) {
        $tabJoin = '
            LEFT JOIN (
                SELECT tp.order_id, MIN(t.tab_name) AS tab_name
                FROM tab_payments tp
                LEFT JOIN tabs t ON t.id = tp.tab_id
                WHERE COALESCE(tp.tip_amount, 0) > 0 OR tp.order_id IS NOT NULL
                GROUP BY tp.order_id
            ) tb ON tb.order_id = o.id';
        $tabSelect = ', CASE WHEN tb.order_id IS NOT NULL THEN 1 ELSE 0 END AS is_tab_payment, tb.tab_name';
    }

    $orderStmt = $db->prepare("
        SELECT o.id AS order_id,
               o.created_at,
               COALESCE(o.cashier_id, 'Unknown') AS cashier_name,
               COALESCE(o.gratuity_amount, 0) AS gratuity_amount
               $mixedSelect
               $eftSelect
               $tabSelect
        FROM orders o
        $mixedJoin
        $eftJoin
        $tabJoin
        WHERE ($ordersWhereClause)$ordersCashierFilter
          AND COALESCE(o.gratuity_amount, 0) > 0
        ORDER BY o.created_at DESC
    ");
    $orderStmt->execute($ordersParams);
    foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = round((float) ($row['gratuity_amount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;
        }

        $hasMixed = !empty($row['has_mixed']);
        if ($mixedTableExists && $hasMixed) {
            $cashAmt = round((float) ($row['mixed_cash'] ?? 0), 2);
            $cardAmt = round((float) ($row['mixed_eft'] ?? 0), 2);
        } else {
            $eft = round((float) ($row['eft_total'] ?? 0), 2);
            $cardAmt = $eft >= $amount ? $amount : $eft;
            $cashAmt = round(max(0.0, $amount - $cardAmt), 2);
        }

        $cashierName = (string) ($row['cashier_name'] ?? 'Unknown');
        $orderGratuityTotal += $amount;
        if ($cashAmt > 0) {
            $byMethod['cash'] = round($byMethod['cash'] + $cashAmt, 2);
            tipsReportAccumulateCashier($byCashier, $cashierName, $cashAmt, 'cash');
        }
        if ($cardAmt > 0) {
            $byMethod['card'] = round($byMethod['card'] + $cardAmt, 2);
            tipsReportAccumulateCashier($byCashier, $cashierName, $cardAmt, 'card');
        }

        $source = !empty($row['is_tab_payment']) ? 'Tab payment' : 'Checkout';
        $reference = 'Order #' . (int) ($row['order_id'] ?? 0);
        if (!empty($row['tab_name'])) {
            $reference .= ' — ' . (string) $row['tab_name'];
        }

        $entries[] = [
            'created_at' => $row['created_at'] ?? '',
            'source' => $source,
            'cashier_name' => $cashierName,
            'payment_method' => tipsReportOrderMethodLabel($cashAmt, $cardAmt),
            'amount' => $amount,
            'reference' => $reference,
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    $byCashierRows = array_values($byCashier);
    usort($byCashierRows, static function (array $a, array $b): int {
        return ($b['total'] <=> $a['total']);
    });

    $totalTips = round($manualTotal + $orderGratuityTotal, 2);

    return [
        'summary' => [
            'total_tips' => $totalTips,
            'manual_tips_total' => round($manualTotal, 2),
            'order_gratuity_total' => round($orderGratuityTotal, 2),
            'total_entries' => count($entries),
            'by_method' => $byMethod,
        ],
        'by_cashier' => $byCashierRows,
        'entries' => $entries,
    ];
}

function tipsReportMethodKey(string $paymentMethod): string
{
    $method = strtolower(trim($paymentMethod));
    if ($method === 'cash') {
        return 'cash';
    }
    if ($method === 'inventory') {
        return 'inventory';
    }
    if ($method === 'card' || $method === 'eft' || strpos($method, 'card') !== false) {
        return 'card';
    }
    return 'other';
}

function tipsReportMethodLabel(string $methodKey): string
{
    $labels = [
        'cash' => 'Cash',
        'card' => 'Card / EFT',
        'inventory' => 'Inventory',
        'other' => 'Other',
    ];
    return $labels[$methodKey] ?? 'Other';
}

function tipsReportOrderMethodLabel(float $cashAmt, float $cardAmt): string
{
    if ($cashAmt > 0 && $cardAmt > 0) {
        return 'Mixed';
    }
    if ($cardAmt > 0) {
        return 'Card / EFT';
    }
    return 'Cash';
}

function tipsReportAccumulateCashier(array &$byCashier, string $cashierName, float $amount, string $methodKey): void
{
    if (!isset($byCashier[$cashierName])) {
        $byCashier[$cashierName] = [
            'cashier_name' => $cashierName,
            'cash_total' => 0.0,
            'card_total' => 0.0,
            'inventory_total' => 0.0,
            'other_total' => 0.0,
            'total' => 0.0,
        ];
    }

    if ($methodKey === 'cash') {
        $field = 'cash_total';
    } elseif ($methodKey === 'card') {
        $field = 'card_total';
    } elseif ($methodKey === 'inventory') {
        $field = 'inventory_total';
    } else {
        $field = 'other_total';
    }

    $byCashier[$cashierName][$field] = round($byCashier[$cashierName][$field] + $amount, 2);
    $byCashier[$cashierName]['total'] = round($byCashier[$cashierName]['total'] + $amount, 2);
}

function ensure_orders_gratuity_columns_for_tips_report(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $helper = __DIR__ . '/ensure_orders_gratuity_columns.php';
    if (is_file($helper)) {
        require_once $helper;
        if (function_exists('ensure_orders_gratuity_columns')) {
            ensure_orders_gratuity_columns($db);
        }
    }
}
