<?php
header('Content-Type: application/json');

try {
    // Connect to the SQLite database
    $pdo = new PDO('sqlite:pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the JSON data from the request body
    $data = json_decode(file_get_contents('php://input'), true);
    $hide_available_quantity = $data['hide_available_quantity'] ?? 0;

    // Add column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }

    // Update the setting in the database
    $stmt = $pdo->prepare("UPDATE product_settings SET hide_available_quantity = ? WHERE id = 1");
    $stmt->execute([$hide_available_quantity]);

    // Return a success response
    echo json_encode(['success' => true, 'message' => 'Setting updated successfully']);
} catch (PDOException $e) {
    // Return an error response
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>






