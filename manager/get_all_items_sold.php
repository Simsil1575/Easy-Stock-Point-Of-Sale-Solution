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

// Get business closing time
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00';
} catch (PDOException $e) {
    $closingTime = '00:00';
}

// Get selected date from GET parameter, default to today
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Calculate business day boundaries
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch all items sold for the selected date
$itemsQuery = $db->prepare("
    SELECT 
        oi.product_name,
        SUM(oi.quantity) as total_quantity,
        ROUND(SUM(oi.price) / SUM(oi.quantity), 2) as unit_price,
        SUM(oi.price) as total_value
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    AND o.cashier_id IS NOT NULL
    AND o.cashier_id != ''
    GROUP BY oi.product_name
    ORDER BY total_value DESC
");

$itemsQuery->bindParam(':selectedDate', $selectedDate);
$itemsQuery->bindParam(':nextDay', $nextDay);
$itemsQuery->bindParam(':closingTime', $closingTime);
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
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    AND o.cashier_id IS NOT NULL
    AND o.cashier_id != ''
");

$totalsQuery->bindParam(':selectedDate', $selectedDate);
$totalsQuery->bindParam(':nextDay', $nextDay);
$totalsQuery->bindParam(':closingTime', $closingTime);
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
