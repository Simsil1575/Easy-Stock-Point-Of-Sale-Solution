<?php

/**
 * Supplemental totals for sales reports: product sales, gratuity, manual tips, cash back.
 */
function buildSalesReportBreakdown(PDO $db, string $ordersWhereClause, string $startDateTime, string $endDateTime): array
{
    ensure_orders_gratuity_columns_for_sales_report($db);

    $productSalesStmt = $db->prepare("
        SELECT COALESCE(SUM(oi.price), 0)
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE ($ordersWhereClause)
    ");
    $productSalesStmt->execute();
    $productSalesTotal = round((float) $productSalesStmt->fetchColumn(), 2);

    $gratuityStmt = $db->prepare("
        SELECT COALESCE(SUM(COALESCE(o.gratuity_amount, 0)), 0)
        FROM orders o
        WHERE ($ordersWhereClause)
    ");
    $gratuityStmt->execute();
    $gratuityTotal = round((float) $gratuityStmt->fetchColumn(), 2);

    $manualTipsTotal = 0.0;
    try {
        $tipsWhere = str_replace('o.created_at', 't.created_at', $ordersWhereClause);
        if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tips'")->fetchColumn()) {
            $tipsStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM tips t WHERE ($tipsWhere)");
            $tipsStmt->execute();
            $manualTipsTotal = round((float) $tipsStmt->fetchColumn(), 2);
        }
    } catch (PDOException $e) {
    }

    $cashBackOrders = [];
    $cashBackTotal = 0.0;
    try {
        $cashBackStmt = $db->prepare("
            SELECT o.id, o.total, o.created_at, COALESCE(o.cashier_id, 'Unknown') AS cashier_name,
                   COALESCE(ep.eft_amount, 0) AS eft_amount
            FROM orders o
            LEFT JOIN (
                SELECT order_id, SUM(amount) AS eft_amount
                FROM eft_payments
                GROUP BY order_id
            ) ep ON ep.order_id = o.id
            WHERE ($ordersWhereClause)
              AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)
              AND (
                  EXISTS (
                      SELECT 1 FROM eft_payments ep2
                      WHERE ep2.order_id = o.id
                        AND (
                            LOWER(COALESCE(ep2.transaction_ref, '')) LIKE '%cash back%'
                            OR LOWER(COALESCE(ep2.wallet_provider, '')) IN ('hubbly', 'beerhouse', 'customer')
                        )
                  )
                  OR EXISTS (
                      SELECT 1 FROM cash_transactions ct
                      WHERE ct.type = 'cash-out'
                        AND LOWER(COALESCE(ct.description, '')) LIKE '%cash back%'
                        AND ct.created_at = o.created_at
                  )
              )
            ORDER BY o.created_at DESC
        ");
        $cashBackStmt->execute();
        foreach ($cashBackStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = round((float) ($row['total'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $cashBackTotal += $amount;
            $cashBackOrders[] = [
                'id' => (int) ($row['id'] ?? 0),
                'total' => $amount,
                'created_at' => $row['created_at'] ?? '',
                'cashier_name' => $row['cashier_name'] ?? 'Unknown',
                'eft_amount' => round((float) ($row['eft_amount'] ?? 0), 2),
                'cash_amount' => round(max(0.0, $amount - (float) ($row['eft_amount'] ?? 0)), 2),
                'order_type' => 'cash_back',
                'items' => [[
                    'product_name' => 'Cash Back',
                    'quantity' => 1,
                    'price' => $amount,
                ]],
            ];
        }
        $cashBackTotal = round($cashBackTotal, 2);
    } catch (PDOException $e) {
    }

    return [
        'product_sales_total' => $productSalesTotal,
        'gratuity_total' => $gratuityTotal,
        'manual_tips_total' => $manualTipsTotal,
        'cash_back_total' => $cashBackTotal,
        'cash_back_orders' => $cashBackOrders,
    ];
}

/**
 * Daily sales breakdown with cash in/out affecting each day's and period grand total.
 */
function buildSalesReportDailyBreakdown(PDO $db, string $startDateTime, string $endDateTime): array
{
    $cashWhere = "created_at >= " . $db->quote($startDateTime) . " AND created_at <= " . $db->quote($endDateTime);
    $ordersWhere = "o.created_at >= " . $db->quote($startDateTime) . " AND o.created_at <= " . $db->quote($endDateTime);
    $creditWhere = "created_at >= " . $db->quote($startDateTime) . " AND created_at <= " . $db->quote($endDateTime);

    $days = [];
    $startDay = substr($startDateTime, 0, 10);
    $endDay = substr($endDateTime, 0, 10);
    $cursor = new DateTime($startDay);
    $end = new DateTime($endDay);
    while ($cursor <= $end) {
        $day = $cursor->format('Y-m-d');
        $days[$day] = [
            'date' => $day,
            'order_count' => 0,
            'cash_sales' => 0.0,
            'card_sales' => 0.0,
            'credit_sales' => 0.0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'sales_subtotal' => 0.0,
            'day_total' => 0.0,
        ];
        $cursor->modify('+1 day');
    }

    try {
        $orderRows = $db->query("
            SELECT DATE(o.created_at) AS day,
                   COUNT(*) AS order_count,
                   COALESCE(SUM(o.total - COALESCE(eft.eft_amount, 0)), 0) AS cash_sales,
                   COALESCE(SUM(COALESCE(eft.eft_amount, 0)), 0) AS card_sales
            FROM orders o
            LEFT JOIN (
                SELECT order_id, SUM(amount) AS eft_amount
                FROM eft_payments
                GROUP BY order_id
            ) eft ON eft.order_id = o.id
            WHERE ($ordersWhere)
            GROUP BY DATE(o.created_at)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orderRows as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day === '' || !isset($days[$day])) {
                continue;
            }
            $days[$day]['order_count'] = (int) ($row['order_count'] ?? 0);
            $days[$day]['cash_sales'] = round((float) ($row['cash_sales'] ?? 0), 2);
            $days[$day]['card_sales'] = round((float) ($row['card_sales'] ?? 0), 2);
        }
    } catch (PDOException $e) {
    }

    try {
        $creditRows = $db->query("
            SELECT DATE(created_at) AS day, COALESCE(SUM(total_amount), 0) AS credit_sales
            FROM credit_sales
            WHERE ($creditWhere)
            GROUP BY DATE(created_at)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($creditRows as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day === '' || !isset($days[$day])) {
                continue;
            }
            $days[$day]['credit_sales'] = round((float) ($row['credit_sales'] ?? 0), 2);
        }
    } catch (PDOException $e) {
    }

    $cashMovements = [];
    try {
        $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cash_transactions'")->fetchColumn();
        if ($tableExists) {
            $cashMovements = $db->query("
                SELECT id, type, amount, description, cashier_id, created_at
                FROM cash_transactions
                WHERE ($cashWhere)
                ORDER BY created_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($cashMovements as $mv) {
                $day = substr((string) ($mv['created_at'] ?? ''), 0, 10);
                if ($day === '' || !isset($days[$day])) {
                    continue;
                }
                $amount = round((float) ($mv['amount'] ?? 0), 2);
                if (($mv['type'] ?? '') === 'cash-in') {
                    $days[$day]['cash_in'] = round($days[$day]['cash_in'] + $amount, 2);
                } elseif (($mv['type'] ?? '') === 'cash-out') {
                    $days[$day]['cash_out'] = round($days[$day]['cash_out'] + $amount, 2);
                }
            }
        }
    } catch (PDOException $e) {
    }

    $totalCashIn = 0.0;
    $totalCashOut = 0.0;
    $totalCashSales = 0.0;
    $totalCardSales = 0.0;
    $totalCreditSales = 0.0;
    $totalOrders = 0;

    foreach ($days as &$dayRow) {
        $dayRow['sales_subtotal'] = round(
            $dayRow['cash_sales'] + $dayRow['card_sales'] + $dayRow['credit_sales'],
            2
        );
        $dayRow['day_total'] = round(
            $dayRow['sales_subtotal'] + $dayRow['cash_in'] - $dayRow['cash_out'],
            2
        );
        $totalCashIn += $dayRow['cash_in'];
        $totalCashOut += $dayRow['cash_out'];
        $totalCashSales += $dayRow['cash_sales'];
        $totalCardSales += $dayRow['card_sales'];
        $totalCreditSales += $dayRow['credit_sales'];
        $totalOrders += $dayRow['order_count'];
    }
    unset($dayRow);

    $dailyRows = array_values($days);
    usort($dailyRows, static fn($a, $b) => strcmp($b['date'], $a['date']));

    $salesSubtotal = round($totalCashSales + $totalCardSales + $totalCreditSales, 2);

    return [
        'days' => $dailyRows,
        'cash_movements' => $cashMovements,
        'totals' => [
            'order_count' => $totalOrders,
            'cash_sales' => round($totalCashSales, 2),
            'card_sales' => round($totalCardSales, 2),
            'credit_sales' => round($totalCreditSales, 2),
            'sales_subtotal' => $salesSubtotal,
            'cash_in_total' => round($totalCashIn, 2),
            'cash_out_total' => round($totalCashOut, 2),
            'adjusted_grand_total' => round($salesSubtotal + $totalCashIn - $totalCashOut, 2),
        ],
    ];
}

function salesReportAnnotateOrder(array &$order, array $itemRows): void
{
    $gratuity = round((float) ($order['gratuity_amount'] ?? 0), 2);
    $order['gratuity_amount'] = $gratuity;
    $order['items_subtotal'] = round(array_sum(array_map(static fn($r) => (float) ($r['price'] ?? 0), $itemRows)), 2);
    $order['order_type'] = 'sale';

    if ($gratuity > 0) {
        $order['items'][] = [
            'product_name' => 'Gratuity',
            'quantity' => 1,
            'price' => $gratuity,
        ];
    }
}

function salesReportIsCashBackOrder(PDO $db, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }
    $stmt = $db->prepare("
        SELECT 1
        FROM orders o
        WHERE o.id = ?
          AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)
          AND EXISTS (
              SELECT 1 FROM eft_payments ep
              WHERE ep.order_id = o.id
                AND LOWER(COALESCE(ep.transaction_ref, '')) LIKE '%cash back%'
          )
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    return (bool) $stmt->fetchColumn();
}

function ensure_orders_gratuity_columns_for_sales_report(PDO $db): void
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
