<?php
header('Content-Type: application/json');
date_default_timezone_set('Africa/Harare'); // GMT+2

// Database connection
try {
    $db = new PDO('sqlite:../pos.db');
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
                    // Handle image upload
                    $image_url = '';
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../products/';
                        $image_name = uniqid() . '_' . basename($_FILES['image']['name']);
                        $image_path = $upload_dir . $image_name;
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $image_path)) {
                            $image_url = $image_name;
                        }
                    }

                    $stmt = $db->prepare("INSERT INTO products (name, quantity, price, buying_price, image_url) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['quantity'],
                        $_POST['price'],
                        $_POST['buying_price'],
                        $image_url
                    ]);
                    
                    $product_id = $db->lastInsertId();
                    $product = $db->query("SELECT * FROM products WHERE id = $product_id")->fetch(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Product added successfully',
                        'product' => $product,
                        'refresh' => true // Add flag to indicate page should refresh
                    ]);
                } catch (PDOException $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Error adding product: ' . $e->getMessage()]);
                }
                break;

            case 'edit':
                try {
                    $image_url = $_POST['current_image_url'];
                    // Handle image update
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../products/';
                        $image_name = uniqid() . '_' . basename($_FILES['image']['name']);
                        $image_path = $upload_dir . $image_name;
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $image_path)) {
                            // Delete old image if it exists
                            if ($image_url && file_exists($upload_dir . $image_url)) {
                                unlink($upload_dir . $image_url);
                            }
                            $image_url = $image_name;
                        }
                    }

                    $stmt = $db->prepare("UPDATE products SET name = ?, quantity = ?, price = ?, buying_price = ?, image_url = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['quantity'],
                        $_POST['price'],
                        $_POST['buying_price'],
                        $image_url,
                        $_POST['id']
                    ]);
                    
                    $product = $db->query("SELECT * FROM products WHERE id = {$_POST['id']}")->fetch(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Product updated successfully',
                        'product' => $product
                    ]);
                } catch (PDOException $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Error updating product: ' . $e->getMessage()]);
                }
                break;

            case 'delete':
                try {
                    // Get product info to delete associated image
                    $product = $db->query("SELECT * FROM products WHERE id = {$_POST['id']}")->fetch(PDO::FETCH_ASSOC);
                    
                    // Delete product
                    $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    
                    // Delete associated image (protect default.png)
                    if ($product['image_url'] && file_exists('../products/' . $product['image_url'])) {
                        $imagePath = '../products/' . $product['image_url'];
                        if (basename($imagePath) !== 'default.png' && strpos($imagePath, 'default.png') === false) {
                            unlink($imagePath);
                        }
                    }
                    
                    echo json_encode(['status' => 'success', 'message' => 'Product deleted successfully']);
                } catch (PDOException $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Error deleting product: ' . $e->getMessage()]);
                }
                break;

            default:
                echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No action specified']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
