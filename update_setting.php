<?php
header('Content-Type: application/json');

try {
    // Connect to the SQLite database
    $pdo = new PDO('sqlite:pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the JSON data from the request body
    $data = json_decode(file_get_contents('php://input'), true);
    $show_all_products = $data['show_all_products'] ?? 0;

    // Update the setting in the database
    $stmt = $pdo->prepare("UPDATE product_settings SET show_all_products = ? WHERE id = 1");
    $stmt->execute([$show_all_products]);

    // Return a success response
} catch (PDOException $e) {
    // Return an error response
}
?>