<?php
header('Content-Type: application/json');

try {
    // Connect to the SQLite database
    $pdo = new PDO('sqlite:pos.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the JSON data from the request body
    $data = json_decode(file_get_contents('php://input'), true);
    $hide_available_quantity = $data['hide_available_quantity'] ?? null;
    $skip_stock_checks = $data['skip_stock_checks'] ?? null;
    $use_qz_tray = $data['use_qz_tray'] ?? null;

    // Add columns if they don't exist
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN hide_available_quantity BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN skip_stock_checks BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }
    try {
        $pdo->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists, continue
    }

    // Update the setting(s) in the database (only update what was sent)
    if ($hide_available_quantity !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET hide_available_quantity = ? WHERE id = 1");
        $stmt->execute([$hide_available_quantity]);
    }
    if ($skip_stock_checks !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET skip_stock_checks = ? WHERE id = 1");
        $stmt->execute([$skip_stock_checks]);
    }

    if ($use_qz_tray !== null) {
        $stmt = $pdo->prepare("UPDATE product_settings SET use_qz_tray = ? WHERE id = 1");
        $stmt->execute([$use_qz_tray]);
    }

    // Return a success response
    echo json_encode(['success' => true, 'message' => 'Setting updated successfully']);
} catch (PDOException $e) {
    // Return an error response
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>






