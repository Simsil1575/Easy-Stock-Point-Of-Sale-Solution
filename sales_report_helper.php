<?php

require_once __DIR__ . '/includes/sales_report_cashier_filter.php';
require_once __DIR__ . '/cash_transactions_totals_helper.php';

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
function buildSalesReportDailyBreakdown(
    PDO $db,
    string $startDateTime,
    string $endDateTime,
    string $cashierUsername = '',
    string $cashierUserId = ''
): array {
    $cashWhere = "created_at >= " . $db->quote($startDateTime) . " AND created_at <= " . $db->quote($endDateTime);
    $ordersWhere = "o.created_at >= " . $db->quote($startDateTime) . " AND o.created_at <= " . $db->quote($endDateTime);
    $creditWhere = "created_at >= " . $db->quote($startDateTime) . " AND created_at <= " . $db->quote($endDateTime);

    $cashWhere = salesReportAppendCashierSql($db, $cashWhere, 'cashier_id', $cashierUsername, $cashierUserId);
    $ordersWhere = salesReportAppendCashierSql($db, $ordersWhere, 'o.cashier_id', $cashierUsername, $cashierUserId);
    $creditWhere = salesReportAppendCashierSql($db, $creditWhere, 'cashier_id', $cashierUsername, $cashierUserId);

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

/**
 * Cashier sales with cash vs EFT split (order remainder is cash after eft_payments).
 */
function buildCashierSalesReportData(
    PDO $db,
    PDO $userDb,
    string $ordersWhereClause,
    string $creditWhereClause,
    string $creditDetailWhere,
    string $cashierId = '',
    string $cashTransactionsWhereClause = '1=1'
): array {
    $outflowWhere = cashReportOutflowWhereSql('description');
    $cashBackWhere = cashBackDescriptionSql('description');
    $expenseSumSql = "
        SELECT COALESCE(SUM(" . cashWithdrawalsSumExpr() . "), 0)
        FROM cash_transactions
        WHERE {$outflowWhere}
        AND (cashier_id = ? OR CAST(cashier_id AS TEXT) = ?)
        AND ($cashTransactionsWhereClause)
    ";
    $cashBackSumSql = "
        SELECT COALESCE(SUM(amount), 0)
        FROM cash_transactions
        WHERE type = 'cash-out'
        AND {$cashBackWhere}
        AND (cashier_id = ? OR CAST(cashier_id AS TEXT) = ?)
        AND ($cashTransactionsWhereClause)
    ";
    $expenseListSql = "
        SELECT id, type, amount, description, created_at
        FROM cash_transactions
        WHERE {$outflowWhere}
        AND (cashier_id = ? OR CAST(cashier_id AS TEXT) = ?)
        AND ($cashTransactionsWhereClause)
        ORDER BY created_at ASC
    ";
    $cashBackListSql = "
        SELECT id, type, amount, description, created_at
        FROM cash_transactions
        WHERE type = 'cash-out'
        AND {$cashBackWhere}
        AND (cashier_id = ? OR CAST(cashier_id AS TEXT) = ?)
        AND ($cashTransactionsWhereClause)
        ORDER BY created_at ASC
    ";

    $cashiersQuery = $userDb->query("SELECT id, username, role FROM users");
    $allCashiers = $cashiersQuery->fetchAll(PDO::FETCH_ASSOC);

    $eftTableExists = false;
    try {
        $eftTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='eft_payments'")->fetchColumn() !== false;
    } catch (PDOException $e) {
    }

    if ($eftTableExists) {
        $salesSql = "
            SELECT COUNT(*) as order_count,
                   COALESCE(SUM(o.total), 0) as total_sales,
                   COALESCE(SUM(o.total - COALESCE((SELECT SUM(amount) FROM eft_payments WHERE order_id = o.id), 0)), 0) as cash_sales,
                   COALESCE(SUM(COALESCE((SELECT SUM(amount) FROM eft_payments WHERE order_id = o.id), 0)), 0) as eft_sales
            FROM orders o
            WHERE (o.cashier_id = ? OR CAST(o.cashier_id AS TEXT) = ?) AND ($ordersWhereClause)
        ";
    } else {
        $salesSql = "
            SELECT COUNT(*) as order_count,
                   COALESCE(SUM(o.total), 0) as total_sales,
                   COALESCE(SUM(o.total), 0) as cash_sales,
                   0 as eft_sales
            FROM orders o
            WHERE (o.cashier_id = ? OR CAST(o.cashier_id AS TEXT) = ?) AND ($ordersWhereClause)
        ";
    }

    $cashierSales = [];
    foreach ($allCashiers as $cashier) {
        if ($cashierId && $cashier['username'] != $cashierId) {
            continue;
        }

        $salesQuery = $db->prepare($salesSql);
        $salesQuery->execute([$cashier['username'], $cashier['id']]);
        $salesData = $salesQuery->fetch(PDO::FETCH_ASSOC) ?: [];

        $creditQuery = $db->prepare("
            SELECT COUNT(*) as credit_count, COALESCE(SUM(total_amount), 0) as credit_total
            FROM credit_sales
            WHERE (cashier_id = ? OR CAST(cashier_id AS TEXT) = ?) AND ($creditWhereClause)
        ");
        $creditQuery->execute([$cashier['username'], $cashier['id']]);
        $creditData = $creditQuery->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalSales = round((float) ($salesData['total_sales'] ?? 0), 2);
        $cashSales = round((float) ($salesData['cash_sales'] ?? 0), 2);
        $eftSales = round((float) ($salesData['eft_sales'] ?? 0), 2);
        $creditTotal = round((float) ($creditData['credit_total'] ?? 0), 2);

        $expenseQuery = $db->prepare($expenseSumSql);
        $expenseQuery->execute([$cashier['username'], $cashier['id']]);
        $expenseTotal = round((float) ($expenseQuery->fetchColumn() ?: 0), 2);

        $cashBackQuery = $db->prepare($cashBackSumSql);
        $cashBackQuery->execute([$cashier['username'], $cashier['id']]);
        $cashBackTotal = round((float) ($cashBackQuery->fetchColumn() ?: 0), 2);
        $tillDeductions = round($expenseTotal + $cashBackTotal, 2);

        $expenseListQuery = $db->prepare($expenseListSql);
        $expenseListQuery->execute([$cashier['username'], $cashier['id']]);
        $expenseItems = $expenseListQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cashBackListQuery = $db->prepare($cashBackListSql);
        $cashBackListQuery->execute([$cashier['username'], $cashier['id']]);
        $cashBackItems = $cashBackListQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $expenseItems = array_merge($expenseItems, $cashBackItems);

        $cashierSales[] = [
            'cashier_id' => $cashier['username'],
            'cashier_name' => $cashier['username'],
            'role' => $cashier['role'],
            'order_count' => (int) ($salesData['order_count'] ?? 0),
            'total_sales' => $totalSales,
            'cash_sales' => $cashSales,
            'eft_sales' => $eftSales,
            'credit_count' => (int) ($creditData['credit_count'] ?? 0),
            'credit_total' => $creditTotal,
            'expense_total' => $tillDeductions,
            'cash_back_total' => $cashBackTotal,
            'expenses' => $expenseItems,
            'net_cash' => round($cashSales - $tillDeductions, 2),
            'grand_total' => round($totalSales + $creditTotal, 2),
        ];
    }

    usort($cashierSales, static fn($a, $b) => $b['grand_total'] <=> $a['grand_total']);

    $allExpenses = [];
    foreach ($cashierSales as $cashierRow) {
        foreach ($cashierRow['expenses'] as $expense) {
            $allExpenses[] = [
                'id' => $expense['id'],
                'cashier_name' => $cashierRow['cashier_name'],
                'amount' => round(abs((float) ($expense['amount'] ?? 0)), 2),
                'description' => $expense['description'] !== '' ? $expense['description'] : ((($expense['type'] ?? '') === 'refund') ? 'Refund' : 'Expense'),
                'created_at' => $expense['created_at'],
            ];
        }
    }
    usort($allExpenses, static fn($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

    $totalCash = round(array_sum(array_column($cashierSales, 'cash_sales')), 2);
    $totalExpenses = round(array_sum(array_column($cashierSales, 'expense_total')), 2);

    return [
        'cashiers' => $cashierSales,
        'expenses' => $allExpenses,
        'order_transactions' => [],
        'credit_transactions' => [],
        'summary' => [
            'total_cashiers' => count($cashierSales),
            'total_cash' => $totalCash,
            'total_eft' => round(array_sum(array_column($cashierSales, 'eft_sales')), 2),
            'total_credit' => round(array_sum(array_column($cashierSales, 'credit_total')), 2),
            'total_expenses' => $totalExpenses,
            'net_cash_expected' => round($totalCash - $totalExpenses, 2),
            'total_sales' => round(array_sum(array_column($cashierSales, 'grand_total')), 2),
            'cashier_label' => $cashierId !== '' ? $cashierId : 'All cashiers',
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
