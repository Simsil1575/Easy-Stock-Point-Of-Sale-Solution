<?php
header('Content-Type: application/json');

try {
    // Connect to the SQLite database
    $pdo = new PDO('sqlite:pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        show_all_products BOOLEAN NOT NULL DEFAULT 0,
        hide_available_quantity BOOLEAN NOT NULL DEFAULT 0,
        default_print_receipt BOOLEAN NOT NULL DEFAULT 0
    )");

    // Check if row exists, if not create it
    $checkStmt = $pdo->query("SELECT COUNT(*) FROM product_settings WHERE id = 1");
    $rowExists = $checkStmt->fetchColumn();
    
    if ($rowExists == 0) {
        $pdo->exec("INSERT INTO product_settings (id, show_all_products, hide_available_quantity, default_print_receipt) VALUES (1, 0, 0, 0)");
    }

    // Get the JSON data from the request body
    $data = json_decode(file_get_contents('php://input'), true);
    $show_all_products = $data['show_all_products'] ?? 0;

    // Update the setting in the database
    $stmt = $pdo->prepare("UPDATE product_settings SET show_all_products = ? WHERE id = 1");
    $stmt->execute([$show_all_products]);

    // Return a success response
    echo json_encode(['success' => true, 'message' => 'Setting updated successfully']);
} catch (PDOException $e) {
    // Return an error response
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>