<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Set timezone
date_default_timezone_set('Africa/Harare');

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

require_once __DIR__ . '/../business_day_helper.php';

$bdCtx = bdLoadBusinessHoursContext(__DIR__ . '/../info.db');
$closingTime = $bdCtx['closing_time'];
$isAfterMidnight = $bdCtx['is_after_midnight'];

// Get selected date from GET parameter, default to today
$selectedDate = isset($_GET['date']) ? $_GET['date'] : bdDefaultSelectedDate($closingTime, $isAfterMidnight);
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$bdWhereOCreated = bdSingleDayWhereSql('o.created_at', ':selectedDate', ':nextDay', $closingTime, $isAfterMidnight);

// Fetch all items sold for the selected date
$itemsQuery = $db->prepare("
    SELECT 
        oi.product_name,
        SUM(oi.quantity) as total_quantity,
        ROUND(SUM(oi.price) / SUM(oi.quantity), 2) as unit_price,
        SUM(oi.price) as total_value
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    WHERE ($bdWhereOCreated)
    AND o.cashier_id IS NOT NULL
    AND o.cashier_id != ''
    GROUP BY oi.product_name
    ORDER BY total_value DESC
");

bdBindSingleDayParams($itemsQuery, $selectedDate, $nextDay);
$itemsQuery->execute();
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalCash = 0;
$totalEft = 0;
$grandTotal = 0;

$totalsQuery = $db->prepare("
    SELECT 
        ROUND(SUM(o.total - COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 2) as total_cash,
        ROUND(SUM(COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 2) as total_eft,
        ROUND(SUM(o.total), 2) as total_sales
    FROM orders o
    WHERE ($bdWhereOCreated)
    AND o.cashier_id IS NOT NULL
    AND o.cashier_id != ''
");

bdBindSingleDayParams($totalsQuery, $selectedDate, $nextDay);
$totalsQuery->execute();
$totals = $totalsQuery->fetch(PDO::FETCH_ASSOC);

$totalCash = floatval($totals['total_cash'] ?? 0);
$totalEft = floatval($totals['total_eft'] ?? 0);
$grandTotal = floatval($totals['total_sales'] ?? 0);

// Format items for receipt
$formattedItems = [];
foreach ($items as $item) {
    $formattedItems[] = [
        'name' => $item['product_name'],
        'quantity' => floatval($item['total_quantity']),
        'price' => floatval($item['unit_price']),
        'total' => floatval($item['total_value'])
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'items' => $formattedItems,
    'total_cash' => $totalCash,
    'total_eft' => $totalEft,
    'grand_total' => $grandTotal,
    'date' => $selectedDate
]);
?>
