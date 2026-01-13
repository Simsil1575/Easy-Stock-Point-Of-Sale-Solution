<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Fetch products from the database with restock level
$stmt = $db->query('
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
    GROUP BY p.id
    ORDER BY total_sold DESC
');

$products = [];
$lowStock = [];
$outOfStock = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
    $restockLevel = $row['restock_level'] ?? 5; // Default to 5 if not set
    
    if ($row['quantity'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['quantity'] < $restockLevel) {
        $lowStock[] = $row;
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'outOfStock' => $outOfStock,
    'lowStock' => $lowStock,
    'totalProducts' => count($products)
]);
?> 