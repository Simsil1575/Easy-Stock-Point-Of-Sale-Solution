<?php
// Database connection
$db = new PDO('sqlite:../pos.db');

// Fetch product quantities from the database
$stmt = $db->query('SELECT name, quantity FROM products');
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Send the data as JSON
header('Content-Type: application/json');
echo json_encode($products);
