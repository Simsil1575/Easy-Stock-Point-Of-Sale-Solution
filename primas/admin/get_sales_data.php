<?php
// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => "Connection failed: " . $e->getMessage()]);
    exit;
}

// Get view parameter
$view = isset($_GET['view']) ? $_GET['view'] : 'daily';

// Get business closing time from business_info
$businessInfo = [];
$closingTime = '00:00';
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00';
} catch (PDOException $e) {
    $closingTime = '00:00';
}

// Function to get date range based on view
function getDateRange($view) {
    $today = date('Y-m-d');
    
    switch($view) {
        case 'weekly':
            // Get Monday to Sunday of current week
            $dayOfWeek = date('w'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            
            if ($dayOfWeek == 0) {
                // If today is Sunday, use this week (Sunday to Saturday)
                $startDate = date('Y-m-d', strtotime('monday last week'));
                $endDate = date('Y-m-d'); // Today (Sunday)
            } else {
                // Monday to Sunday of this week
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
            // Last 7 days for daily view
            $startDate = date('Y-m-d', strtotime('-6 days'));
            $endDate = $today;
            break;
    }
    
    return [$startDate, $endDate];
}

// Get sales data based on selected view
function getSalesData($db, $view = 'daily') {
    list($startDate, $endDate) = getDateRange($view);
    
    $query = "";
    switch($view) {
        case 'monthly':
            // Get monthly data for the current year
            $query = "
                WITH RECURSIVE months(month) AS (
                    SELECT date('now', 'start of year')
                    UNION ALL
                    SELECT date(month, '+1 month')
                    FROM months
                    WHERE month < date('now', 'start of year', '+11 months')
                ),
                order_totals AS (
                    SELECT 
                        strftime('%Y-%m', created_at) as period,
                        COALESCE(SUM(total), 0) as order_total
                    FROM orders
                    WHERE strftime('%Y', created_at) = strftime('%Y', 'now')
                    GROUP BY strftime('%Y-%m', created_at)
                ),
                credit_totals AS (
                    SELECT 
                        strftime('%Y-%m', created_at) as period,
                        COALESCE(SUM(total_amount), 0) as credit_total
                    FROM credit_sales
                    WHERE strftime('%Y', created_at) = strftime('%Y', 'now')
                    GROUP BY strftime('%Y-%m', created_at)
                ),
                cash_in_totals AS (
                    SELECT 
                        strftime('%Y-%m', created_at) as period,
                        COALESCE(SUM(amount), 0) as cash_in_total
                    FROM cash_transactions
                    WHERE type = 'cash-in' AND strftime('%Y', created_at) = strftime('%Y', 'now')
                    GROUP BY strftime('%Y-%m', created_at)
                )
                SELECT 
                    strftime('%Y-%m', months.month) as period,
                    (COALESCE(order_totals.order_total, 0) + COALESCE(credit_totals.credit_total, 0) + COALESCE(cash_in_totals.cash_in_total, 0)) as total_sales
                FROM months
                LEFT JOIN order_totals ON strftime('%Y-%m', months.month) = order_totals.period
                LEFT JOIN credit_totals ON strftime('%Y-%m', months.month) = credit_totals.period
                LEFT JOIN cash_in_totals ON strftime('%Y-%m', months.month) = cash_in_totals.period
                ORDER BY months.month ASC";
            break;
            
        case 'weekly':
            // Get weekly data for the current month (Week 1-4)
            $query = "
                WITH RECURSIVE weeks(week_num) AS (
                    SELECT 1
                    UNION ALL
                    SELECT week_num + 1
                    FROM weeks
                    WHERE week_num < 4
                ),
                order_totals AS (
                    SELECT 
                        CASE 
                            WHEN date(created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+21 days') AND date('now', 'start of month', '+1 month', '-1 day') THEN 4
                        END as week_num,
                        COALESCE(SUM(total), 0) as order_total
                    FROM orders
                    WHERE date(created_at) >= date('now', 'start of month')
                    AND date(created_at) <= date('now', 'start of month', '+1 month', '-1 day')
                    GROUP BY week_num
                ),
                credit_totals AS (
                    SELECT 
                        CASE 
                            WHEN date(created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+21 days') AND date('now', 'start of month', '+1 month', '-1 day') THEN 4
                        END as week_num,
                        COALESCE(SUM(total_amount), 0) as credit_total
                    FROM credit_sales
                    WHERE date(created_at) >= date('now', 'start of month')
                    AND date(created_at) <= date('now', 'start of month', '+1 month', '-1 day')
                    GROUP BY week_num
                ),
                cash_in_totals AS (
                    SELECT 
                        CASE 
                            WHEN date(created_at) BETWEEN date('now', 'start of month') AND date('now', 'start of month', '+6 days') THEN 1
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+7 days') AND date('now', 'start of month', '+13 days') THEN 2
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+14 days') AND date('now', 'start of month', '+20 days') THEN 3
                            WHEN date(created_at) BETWEEN date('now', 'start of month', '+21 days') AND date('now', 'start of month', '+1 month', '-1 day') THEN 4
                        END as week_num,
                        COALESCE(SUM(amount), 0) as cash_in_total
                    FROM cash_transactions
                    WHERE type = 'cash-in' AND date(created_at) >= date('now', 'start of month')
                    AND date(created_at) <= date('now', 'start of month', '+1 month', '-1 day')
                    GROUP BY week_num
                )
                SELECT 
                    weeks.week_num as period,
                    (COALESCE(order_totals.order_total, 0) + COALESCE(credit_totals.credit_total, 0) + COALESCE(cash_in_totals.cash_in_total, 0)) as total_sales
                FROM weeks
                LEFT JOIN order_totals ON weeks.week_num = order_totals.week_num
                LEFT JOIN credit_totals ON weeks.week_num = credit_totals.week_num
                LEFT JOIN cash_in_totals ON weeks.week_num = cash_in_totals.week_num
                ORDER BY weeks.week_num ASC";
            break;
            
        case 'daily':
        default:
            // Get daily data for the last 7 days
            $query = "
                WITH RECURSIVE dates(date) AS (
                    SELECT '$startDate' as date
                    UNION ALL
                    SELECT date(date, '+1 day')
                    FROM dates
                    WHERE date < '$endDate'
                ),
                order_totals AS (
                    SELECT 
                        date(created_at) as period,
                        COALESCE(SUM(total), 0) as order_total
                    FROM orders
                    WHERE date(created_at) >= '$startDate'
                    AND date(created_at) <= '$endDate'
                    GROUP BY date(created_at)
                ),
                credit_totals AS (
                    SELECT 
                        date(created_at) as period,
                        COALESCE(SUM(total_amount), 0) as credit_total
                    FROM credit_sales
                    WHERE date(created_at) >= '$startDate'
                    AND date(created_at) <= '$endDate'
                    GROUP BY date(created_at)
                ),
                cash_in_totals AS (
                    SELECT 
                        date(created_at) as period,
                        COALESCE(SUM(amount), 0) as cash_in_total
                    FROM cash_transactions
                    WHERE type = 'cash-in' AND date(created_at) >= '$startDate'
                    AND date(created_at) <= '$endDate'
                    GROUP BY date(created_at)
                )
                SELECT 
                    dates.date as period,
                    (COALESCE(order_totals.order_total, 0) + COALESCE(credit_totals.credit_total, 0) + COALESCE(cash_in_totals.cash_in_total, 0)) as total_sales
                FROM dates
                LEFT JOIN order_totals ON dates.date = order_totals.period
                LEFT JOIN credit_totals ON dates.date = credit_totals.period
                LEFT JOIN cash_in_totals ON dates.date = cash_in_totals.period
                ORDER BY dates.date ASC";
            break;
    }
    
    $stmt = $db->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get data and format it
try {
    $chartData = getSalesData($db, $view);

    $labels = [];
    $salesData = [];

    foreach ($chartData as $data) {
        switch($view) {
            case 'monthly':
                $labels[] = date('F Y', strtotime($data['period'] . '-01'));
                break;
            case 'weekly':
                $labels[] = 'Week ' . $data['period'];
                break;
            case 'daily':
            default:
                $labels[] = date('D j', strtotime($data['period']));
                break;
        }
        $salesData[] = (float)$data['total_sales'];
    }

    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'sales' => $salesData,
        'view' => $view,
        'dataCount' => count($chartData)
    ]);
} catch (Exception $e) {
    // Return error response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Error fetching sales data: ' . $e->getMessage(),
        'view' => $view
    ]);
} 