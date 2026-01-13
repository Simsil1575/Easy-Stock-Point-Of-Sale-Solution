<?php
// Database connection
$db = new PDO('sqlite:pos.db');

// Fetch product quantities and discount information from the database
$stmt = $db->query('SELECT name, quantity, price, discount, discount_start, discount_end FROM products');
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Send the data as JSON
header('Content-Type: application/json');
echo json_encode($products);
