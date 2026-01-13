<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Database connection
$db = new PDO('sqlite:../pos.db');

// Get current week range
$currentWeek = isset($_GET['week']) ? $_GET['week'] : date('Y-m-d');
$weekStart = date('Y-m-d', strtotime($currentWeek . ' -' . (date('w', strtotime($currentWeek))) . ' days'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

// Get previous and next week dates
$prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));

// Fetch weekly stock data
$stmt = $db->prepare("
    SELECT 
        p.id as product_id,
        p.name as product_name,
        p.price,
        p.buying_price,
        p.quantity as current_quantity,
        dss.date,
        dss.opening_quantity,
        dss.closing_quantity,
        dss.received_quantity,
        dss.sold_quantity,
        dss.damaged_quantity
    FROM products p
    LEFT JOIN daily_stock_summary dss ON p.id = dss.product_id 
        AND dss.date BETWEEN ? AND ?
    ORDER BY p.name, dss.date
");
$stmt->execute([$weekStart, $weekEnd]);
$stockData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize data by product
$productData = [];
foreach ($stockData as $row) {
    $productId = $row['product_id'];
    if (!isset($productData[$productId])) {
        $productData[$productId] = [
            'name' => $row['product_name'],
            'price' => $row['price'],
            'buying_price' => $row['buying_price'],
            'current_quantity' => $row['current_quantity'],
            'days' => []
        ];
    }
    
    if ($row['date']) {
        $productData[$productId]['days'][$row['date']] = [
            'opening_quantity' => $row['opening_quantity'],
            'closing_quantity' => $row['closing_quantity'],
            'received_quantity' => $row['received_quantity'],
            'sold_quantity' => $row['sold_quantity'],
            'damaged_quantity' => $row['damaged_quantity']
        ];
    }
}

// Generate week dates
$weekDates = [];
for ($i = 0; $i < 7; $i++) {
    $weekDates[] = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
}

// Calculate week totals
$weekTotals = [
    'total_sales' => 0,
    'total_received' => 0,
    'total_damaged' => 0,
    'total_revenue' => 0,
    'total_profit' => 0
];

foreach ($productData as $product) {
    foreach ($product['days'] as $dayData) {
        $weekTotals['total_sales'] += $dayData['sold_quantity'];
        $weekTotals['total_received'] += $dayData['received_quantity'];
        $weekTotals['total_damaged'] += $dayData['damaged_quantity'];
        $weekTotals['total_revenue'] += $dayData['sold_quantity'] * $product['price'];
        $weekTotals['total_profit'] += ($dayData['sold_quantity'] * $product['price']) - ($dayData['sold_quantity'] * $product['buying_price']);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Stock Tracking</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .header {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            opacity: 0.9;
            font-size: 16px;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .week-navigation {
            display: flex;
            align-items: center;
            gap: 12px;
            background: white;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .nav-btn {
            background: #e2e8f0;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #475569;
            transition: all 0.2s;
            text-decoration: none;
        }

        .nav-btn:hover {
            background: #cbd5e1;
            color: #334155;
        }

        .week-range {
            font-weight: 600;
            color: #334155;
            font-size: 16px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }

        .stat-value.currency::before {
            content: 'N$';
            font-size: 18px;
            color: #64748b;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .table-header {
            background: #f1f5f9;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #334155;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .product-name {
            font-weight: 600;
            color: #1e293b;
            min-width: 200px;
        }

        .day-header {
            text-align: center;
            min-width: 120px;
        }

        .day-data {
            text-align: center;
            font-size: 12px;
            min-width: 120px;
        }

        .quantity-cell {
            padding: 8px;
        }

        .quantity-row {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .quantity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }

        .opening { background: #dbeafe; color: #1e40af; }
        .closing { background: #dcfce7; color: #166534; }
        .received { background: #fef3c7; color: #92400e; }
        .sold { background: #fee2e2; color: #dc2626; }
        .damaged { background: #fce7f3; color: #be185d; }

        .no-data {
            color: #94a3b8;
            font-style: italic;
        }

        .current-day {
            background: #f0f9ff;
            border: 2px solid #0ea5e9;
        }

        .back-btn {
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: #4b5563;
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .header {
                padding: 16px;
            }

            .header h1 {
                font-size: 24px;
            }

            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .table-scroll {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Weekly Stock Tracking</h1>
            <p>Comprehensive view of stock movements and performance metrics</p>
        </div>

        <div class="controls">
            <a href="stock_taking.php" class="back-btn">
                ← Back to Stock Taking
            </a>
            
            <div class="week-navigation">
                <a href="?week=<?php echo $prevWeek; ?>" class="nav-btn">← Previous Week</a>
                <span class="week-range">
                    <?php echo date('M j', strtotime($weekStart)) . ' - ' . date('M j, Y', strtotime($weekEnd)); ?>
                </span>
                <a href="?week=<?php echo $nextWeek; ?>" class="nav-btn">Next Week →</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Sales</h3>
                <div class="stat-value"><?php echo number_format($weekTotals['total_sales']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Received</h3>
                <div class="stat-value"><?php echo number_format($weekTotals['total_received']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Damaged</h3>
                <div class="stat-value"><?php echo number_format($weekTotals['total_damaged']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Revenue</h3>
                <div class="stat-value currency"><?php echo number_format($weekTotals['total_revenue'], 2); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Profit</h3>
                <div class="stat-value currency"><?php echo number_format($weekTotals['total_profit'], 2); ?></div>
            </div>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h2>Daily Stock Summary</h2>
            </div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th class="product-name">Product</th>
                            <th>Current Stock</th>
                            <?php foreach ($weekDates as $date): ?>
                                <th class="day-header <?php echo (date('Y-m-d') == $date) ? 'current-day' : ''; ?>">
                                    <?php echo date('D', strtotime($date)); ?><br>
                                    <small><?php echo date('M j', strtotime($date)); ?></small>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productData as $productId => $product): ?>
                            <tr>
                                <td class="product-name"><?php echo htmlspecialchars($product['name']); ?></td>
                                <td class="day-data">
                                    <strong><?php echo number_format($product['current_quantity']); ?></strong>
                                </td>
                                <?php foreach ($weekDates as $date): ?>
                                    <td class="day-data <?php echo (date('Y-m-d') == $date) ? 'current-day' : ''; ?>">
                                        <?php if (isset($product['days'][$date])): ?>
                                            <div class="quantity-cell">
                                                <div class="quantity-row">
                                                    <?php if ($product['days'][$date]['opening_quantity'] > 0): ?>
                                                        <div class="quantity-item opening">
                                                            <span>Open:</span>
                                                            <span><?php echo number_format($product['days'][$date]['opening_quantity']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($product['days'][$date]['received_quantity'] > 0): ?>
                                                        <div class="quantity-item received">
                                                            <span>+Recv:</span>
                                                            <span><?php echo number_format($product['days'][$date]['received_quantity']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($product['days'][$date]['sold_quantity'] > 0): ?>
                                                        <div class="quantity-item sold">
                                                            <span>-Sold:</span>
                                                            <span><?php echo number_format($product['days'][$date]['sold_quantity']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($product['days'][$date]['damaged_quantity'] > 0): ?>
                                                        <div class="quantity-item damaged">
                                                            <span>-Dmg:</span>
                                                            <span><?php echo number_format($product['days'][$date]['damaged_quantity']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($product['days'][$date]['closing_quantity'] > 0): ?>
                                                        <div class="quantity-item closing">
                                                            <span>Close:</span>
                                                            <span><?php echo number_format($product['days'][$date]['closing_quantity']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="no-data">No data</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 300000);

        // Add keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft') {
                window.location.href = '?week=<?php echo $prevWeek; ?>';
            } else if (e.key === 'ArrowRight') {
                window.location.href = '?week=<?php echo $nextWeek; ?>';
            }
        });
    </script>
</body>
</html>