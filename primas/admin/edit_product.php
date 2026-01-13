<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new SQLite3('../pos.db');
    
    $id = $_POST['id'];
    $name = $_POST['name'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $buying_price = $_POST['buying_price'];
    $restock_level = $_POST['restock_level'];
    $capacity = $_POST['capacity'];
    $expiry_date = $_POST['expiry_date'];
    $barcode = $_POST['barcode'];
    
    // Check if another product with same name already exists (excluding current product)
    $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE name = :name AND id != :id");
    $check_stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $check_stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $check_stmt->execute()->fetchArray();
    
    if ($result['count'] > 0) {
        // Another product with same name exists, redirect with error
        header("Location: edit_product?id=$id&error=duplicate_name");
        exit;
    }
    
    // Handle image upload
    // ... existing image handling code ...
    
    // Update product in database
    // ... existing update code ...
    
    header('Location: inventory?update=success');
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product</title>
    <link rel="stylesheet" href="../styles/output.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-semibold text-gray-800 mb-8">Edit Product</h1>
            
            <?php if (isset($_GET['error']) && $_GET['error'] === 'duplicate_name'): ?>
            <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700">
                            Another product with this name already exists. Please use a different name.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- ... existing form fields ... -->
            </form>
        </div>
    </div>
</body>
</html> 