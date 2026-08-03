<?php
require_once __DIR__ . '/cashier_helper.php';
requireApiSession();

$db = new PDO('sqlite:pos.db');
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
if ($product_id <= 0) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product_id']);
    exit;
}

$stmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$stock = $stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode(['stock' => $stock]);
