<?php
// Start output buffering to catch any unwanted output
ob_start();
date_default_timezone_set('Africa/Harare');

try {
    // Database connection
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $parseOptionalBuyingPrice = static function (array $post): ?float {
        $raw = isset($post['buying_price']) ? trim((string)$post['buying_price']) : '';
        if ($raw === '') {
            return null;
        }
        return (float)$raw;
    };

    // Function to handle image upload and return the filename
    function uploadImage($file) {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $imageName = basename($file['name']);
        $imageExtension = pathinfo($imageName, PATHINFO_EXTENSION);

        // Check if the image extension is allowed
        if (!in_array(strtolower($imageExtension), $allowedExtensions)) {
            throw new Exception("Unsupported image format: $imageExtension");
        }

        $imagePath = '../products/' . $imageName;
        if (!move_uploaded_file($file['tmp_name'], $imagePath)) {
            throw new Exception("Failed to upload image");
        }

        return $imageName;
    }

    $response = ['status' => 'error', 'message' => 'Invalid request'];

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    $name = htmlspecialchars($_POST['name']);
                    $quantity = intval($_POST['quantity']);
                    $price = floatval($_POST['price']);
                    $buying_price = $parseOptionalBuyingPrice($_POST);
                    
                    // Check if a product with the same name already exists
                    $checkSql = "SELECT COUNT(*) FROM products WHERE name = :name";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([':name' => $name]);
                    $count = $checkStmt->fetchColumn();

                    if ($count > 0) {
                        $response = ['status' => 'error', 'message' => 'Product with the same name already exists'];
                        break;
                    }

                    if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
                        $random_name = bin2hex(random_bytes(8)) . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                        $_FILES['image']['name'] = $random_name;
                        $image_name = uploadImage($_FILES['image']);
                    } else {
                        $response = ['status' => 'error', 'message' => 'Image upload failed'];
                        break;
                    }
                    
                    $sql = "INSERT INTO products (name, quantity, price, buying_price, image_url) VALUES (:name, :quantity, :price, :buying_price, :image_url)";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([':name' => $name, ':quantity' => $quantity, ':price' => $price, ':buying_price' => $buying_price, ':image_url' => $image_name])) {
                        $product_id = $db->lastInsertId(); // Get the ID of the newly inserted product
                        $response = [
                            'status' => 'success',
                            'message' => 'Product added successfully',
                            'product' => [
                                'id' => $product_id,
                                'name' => $name,
                                'quantity' => $quantity,
                                'price' => $price,
                                'buying_price' => $buying_price,
                                'image_url' => $image_name
                            ]
                        ];
                    } else {
                        $response = ['status' => 'error', 'message' => 'Error adding product: ' . $stmt->errorInfo()[2]];
                    }
                    break;
                
                case 'edit':
                    $id = intval($_POST['id']);
                    $name = htmlspecialchars($_POST['name']);
                    $quantity = intval($_POST['quantity']);
                    $price = floatval($_POST['price']);
                    $buying_price = $parseOptionalBuyingPrice($_POST);
                    $current_image_url = htmlspecialchars($_POST['current_image_url']);
                    
                    // Check if a product with the same name already exists, excluding the current product
                    $checkSql = "SELECT COUNT(*) FROM products WHERE name = :name AND id != :id";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([':name' => $name, ':id' => $id]);
                    $count = $checkStmt->fetchColumn();

                    if ($count > 0) {
                        $response = ['status' => 'error', 'message' => 'Product with the same name already exists'];
                        break;
                    }

                    if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
                        $random_name = bin2hex(random_bytes(8)) . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                        $_FILES['image']['name'] = $random_name;
                        $image_name = uploadImage($_FILES['image']);
                    } else {
                        $image_name = $current_image_url;
                    }
                    
                    $sql = "UPDATE products SET name=:name, quantity=:quantity, price=:price, buying_price=:buying_price, image_url=:image_url WHERE id=:id";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([':name' => $name, ':quantity' => $quantity, ':price' => $price, ':buying_price' => $buying_price, ':image_url' => $image_name, ':id' => $id])) {
                        $response = ['status' => 'success', 'message' => 'Product updated successfully'];
                    } else {
                        $response = ['status' => 'error', 'message' => 'Error updating product: ' . $stmt->errorInfo()[2]];
                    }
                    break;
                
                case 'delete':
                    $id = intval($_POST['id']);
                    $sql = "DELETE FROM products WHERE id=:id";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([':id' => $id])) {
                        $response = ['status' => 'success', 'message' => 'Product deleted successfully'];
                    } else {
                        $response = ['status' => 'error', 'message' => 'Error deleting product: ' . $stmt->errorInfo()[2]];
                    }
                    break;
            }
        }
    }
} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

// Clear any output buffer
ob_end_clean();

// Set proper header and output JSON
header('Content-Type: application/json');
echo json_encode($response);

// Close database connection if it exists
if (isset($db)) {
    $db = null;
}
exit();
?>