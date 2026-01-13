<?php
// Stock movement data endpoint (manager)
// Returns JSON with labels and series for stock_in, stock_out (sales + damages), damages, net_movement

session_start();

header('Content-Type: application/json');

try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $e->getMessage()]);
    exit;
}

$view = isset($_GET['view']) ? $_GET['view'] : 'daily'; // daily | weekly | monthly

// Date helpers
function getDateRange($view) {
    $today = date('Y-m-d');
    switch ($view) {
        case 'weekly':
            $dayOfWeek = date('w');
            if ($dayOfWeek == 0) { // Sunday
                $startDate = date('Y-m-d', strtotime('monday last week'));
                $endDate = $today; // today (Sunday)
            } else {
                $startDate = date('Y-m-d', strtotime('monday this week'));
                $endDate = date('Y-m-d', strtotime('sunday this week'));
            }
            break;
        case 'monthly':
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
            break;
        case 'daily':
        default:
            $startDate = date('Y-m-d', strtotime('-6 days'));
            $endDate = $today;
            break;
    }
    return [$startDate, $endDate];
}

list($startDate, $endDate) = getDateRange($view);

try {
    if ($view === 'monthly') {
        // Aggregate by month for current year
        $query = "
            WITH RECURSIVE months(month) AS (
                SELECT date('now', 'start of year')
                UNION ALL
                SELECT date(month, '+1 month') FROM months
                WHERE month < date('now', 'start of year', '+11 months')
            ),
            stock_in AS (
                SELECT strftime('%Y-%m', changed_at) AS period, COALESCE(SUM(quantity_change), 0) AS qty
                FROM stock_changes
                WHERE action IN ('Restock','restock','Add','add')
                  AND strftime('%Y', changed_at) = strftime('%Y', 'now')
                GROUP BY strftime('%Y-%m', changed_at)
            ),
            damages AS (
                SELECT strftime('%Y-%m', changed_at) AS period, COALESCE(SUM(ABS(quantity_change)), 0) AS qty
                FROM stock_changes
                WHERE action IN ('Damage','damage','Damaged','damaged')
                  AND strftime('%Y', changed_at) = strftime('%Y', 'now')
                GROUP BY strftime('%Y-%m', changed_at)
            ),
            sales AS (
                SELECT period, COALESCE(SUM(qty),0) AS qty FROM (
                    SELECT strftime('%Y-%m', o.created_at) AS period, SUM(oi.quantity) AS qty
                    FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    GROUP BY strftime('%Y-%m', o.created_at)
                    UNION ALL
                    SELECT strftime('%Y-%m', cs.created_at) AS period, SUM(csi.quantity) AS qty
                    FROM credit_sale_items csi
                    JOIN credit_sales cs ON csi.sale_id = cs.id
                    GROUP BY strftime('%Y-%m', cs.created_at)
                ) t GROUP BY period
            )
            SELECT 
                strftime('%Y-%m', months.month) AS period,
                COALESCE(stock_in.qty, 0) AS stock_in_qty,
                COALESCE(sales.qty, 0) AS sales_qty,
                COALESCE(damages.qty, 0) AS damage_qty
            FROM months
            LEFT JOIN stock_in ON strftime('%Y-%m', months.month) = stock_in.period
            LEFT JOIN sales ON strftime('%Y-%m', months.month) = sales.period
            LEFT JOIN damages ON strftime('%Y-%m', months.month) = damages.period
            ORDER BY months.month ASC
        ";
        $labelsFormatter = function($period) { return date('F Y', strtotime($period . '-01')); };
    } elseif ($view === 'weekly') {
        // Aggregate into 4 weeks for current month
        $query = "
            WITH RECURSIVE weeks(week_num) AS (
                SELECT 1 UNION ALL SELECT week_num + 1 FROM weeks WHERE week_num < 4
            ),
            stock_in AS (
                SELECT 
                    CASE 
                        WHEN date(changed_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                        WHEN date(changed_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                        WHEN date(changed_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                        ELSE 4
                    END AS week_num,
                    COALESCE(SUM(quantity_change), 0) AS qty
                FROM stock_changes
                WHERE action IN ('Restock','restock','Add','add')
                  AND date(changed_at) >= date('now','start of month')
                  AND date(changed_at) <= date('now','start of month','+1 month','-1 day')
                GROUP BY week_num
            ),
            damages AS (
                SELECT 
                    CASE 
                        WHEN date(changed_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                        WHEN date(changed_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                        WHEN date(changed_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                        ELSE 4
                    END AS week_num,
                    COALESCE(SUM(ABS(quantity_change)), 0) AS qty
                FROM stock_changes
                WHERE action IN ('Damage','damage','Damaged','damaged')
                  AND date(changed_at) >= date('now','start of month')
                  AND date(changed_at) <= date('now','start of month','+1 month','-1 day')
                GROUP BY week_num
            ),
            sales AS (
                SELECT week_num, COALESCE(SUM(qty),0) AS qty FROM (
                    SELECT 
                        CASE 
                            WHEN date(o.created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(o.created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(o.created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            ELSE 4
                        END AS week_num,
                        SUM(oi.quantity) AS qty
                    FROM order_items oi JOIN orders o ON oi.order_id = o.id
                    WHERE date(o.created_at) >= date('now','start of month')
                      AND date(o.created_at) <= date('now','start of month','+1 month','-1 day')
                    GROUP BY week_num
                    UNION ALL
                    SELECT 
                        CASE 
                            WHEN date(cs.created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(cs.created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(cs.created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            ELSE 4
                        END AS week_num,
                        SUM(csi.quantity) AS qty
                    FROM credit_sale_items csi JOIN credit_sales cs ON csi.sale_id = cs.id
                    WHERE date(cs.created_at) >= date('now','start of month')
                      AND date(cs.created_at) <= date('now','start of month','+1 month','-1 day')
                    GROUP BY week_num
                ) t GROUP BY week_num
            )
            SELECT 
                weeks.week_num AS period,
                COALESCE(stock_in.qty,0) AS stock_in_qty,
                COALESCE(sales.qty,0) AS sales_qty,
                COALESCE(damages.qty,0) AS damage_qty
            FROM weeks
            LEFT JOIN stock_in ON weeks.week_num = stock_in.week_num
            LEFT JOIN sales ON weeks.week_num = sales.week_num
            LEFT JOIN damages ON weeks.week_num = damages.week_num
            ORDER BY weeks.week_num ASC
        ";
        $labelsFormatter = function($period) { return 'Week ' . $period; };
    } else {
        // Daily over selected range (last 7 days)
        $query = "
            WITH RECURSIVE dates(date) AS (
                SELECT '$startDate' as date
                UNION ALL
                SELECT date(date, '+1 day') FROM dates WHERE date < '$endDate'
            ),
            stock_in AS (
                SELECT date(changed_at) AS period, COALESCE(SUM(quantity_change),0) AS qty
                FROM stock_changes
                WHERE action IN ('Restock','restock','Add','add')
                  AND date(changed_at) >= '$startDate' AND date(changed_at) <= '$endDate'
                GROUP BY date(changed_at)
            ),
            damages AS (
                SELECT date(changed_at) AS period, COALESCE(SUM(ABS(quantity_change)),0) AS qty
                FROM stock_changes
                WHERE action IN ('Damage','damage','Damaged','damaged')
                  AND date(changed_at) >= '$startDate' AND date(changed_at) <= '$endDate'
                GROUP BY date(changed_at)
            ),
            sales AS (
                SELECT period, COALESCE(SUM(qty),0) AS qty FROM (
                    SELECT date(o.created_at) AS period, SUM(oi.quantity) AS qty
                    FROM order_items oi JOIN orders o ON oi.order_id = o.id
                    WHERE date(o.created_at) >= '$startDate' AND date(o.created_at) <= '$endDate'
                    GROUP BY date(o.created_at)
                    UNION ALL
                    SELECT date(cs.created_at) AS period, SUM(csi.quantity) AS qty
                    FROM credit_sale_items csi JOIN credit_sales cs ON csi.sale_id = cs.id
                    WHERE date(cs.created_at) >= '$startDate' AND date(cs.created_at) <= '$endDate'
                    GROUP BY date(cs.created_at)
                ) t GROUP BY period
            )
            SELECT 
                dates.date AS period,
                COALESCE(stock_in.qty,0) AS stock_in_qty,
                COALESCE(sales.qty,0) AS sales_qty,
                COALESCE(damages.qty,0) AS damage_qty
            FROM dates
            LEFT JOIN stock_in ON dates.date = stock_in.period
            LEFT JOIN sales ON dates.date = sales.period
            LEFT JOIN damages ON dates.date = damages.period
            ORDER BY dates.date ASC
        ";
        $labelsFormatter = function($period) { return date('D j', strtotime($period)); };
    }

    $stmt = $db->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $stockIn = [];
    $salesOut = [];
    $damages = [];
    $netMovement = [];

    foreach ($rows as $row) {
        $labels[] = $labelsFormatter($row['period']);
        $in = (int)$row['stock_in_qty'];
        $outSales = (int)$row['sales_qty'];
        $outDamage = (int)$row['damage_qty'];
        $labelsRaw[] = $row['period'];
        $stockIn[] = $in;
        $salesOut[] = $outSales;
        $damages[] = $outDamage;
        $netMovement[] = $in - ($outSales + $outDamage);
    }

    echo json_encode([
        'success' => true,
        'view' => $view,
        'labels' => $labels,
        'stockIn' => $stockIn,
        'salesOut' => $salesOut,
        'damages' => $damages,
        'netMovement' => $netMovement,
        'dataCount' => count($rows)
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'view' => $view]);
}

?>


