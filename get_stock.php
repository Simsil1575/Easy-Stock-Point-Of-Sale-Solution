<?php
$db = new PDO('sqlite:pos.db');
$product_id = $_GET['product_id'];

$stmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$stock = $stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode(['stock' => $stock]); 