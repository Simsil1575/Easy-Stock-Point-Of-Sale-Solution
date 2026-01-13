<?php
header('Content-Type: application/json');

// Database connection
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $stmt = $db->prepare("INSERT INTO products (name, quantity, price, buying_price, image_url) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$_POST['name'], $_POST['quantity'], $_POST['price'], $_POST['buying_price'], $_POST['image_url']]);
                    echo json_encode(['success' => true]);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Error adding product: ' . $e->getMessage()]);
                }
                break;

            case 'delete':
                try {
                    $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    echo json_encode(['success' => true]);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Error deleting product: ' . $e->getMessage()]);
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No action specified']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
