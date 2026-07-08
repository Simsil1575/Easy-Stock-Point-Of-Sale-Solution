<?php

session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

function ensureRecipeTablesSQLite(SQLite3 $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS product_recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS recipe_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            ingredient_product_id INTEGER NOT NULL,
            quantity_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
            FOREIGN KEY (recipe_id) REFERENCES product_recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
        )
    ");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new SQLite3('../pos.db');
    ensureRecipeTablesSQLite($db);

    $id = $_POST['id'];
    $name = $_POST['name'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $buying_price = $_POST['buying_price'];
    $restock_level = $_POST['restock_level'];
    $capacity = $_POST['capacity'];
    $expiry_date = $_POST['expiry_date'];
    $barcode = $_POST['barcode'];
    
    // Get old quantity before update
    $stmtSelect = $db->prepare("SELECT quantity FROM products WHERE id = :id");
    $stmtSelect->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmtSelect->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $oldQuantity = $row['quantity'] ?? 0;

    // Handle image upload
    $image_url = $_POST['current_image'];
    if (!empty($_POST['cropped_image'])) {
        // Handle cropped image
        $data = $_POST['cropped_image'];
        $target_dir = "../products/";
        
        // Extract image type and data
        list($type, $data) = explode(';', $data);
        list(, $data) = explode(',', $data);
        $data = base64_decode($data);
        
        // Create unique filename
        $image_url = uniqid() . '.png'; // Save as PNG by default
        file_put_contents($target_dir . $image_url, $data);
        
        // Delete old image if it's not the default
        if ($_POST['current_image'] !== '../props/default.png' && $_POST['current_image'] !== 'default.png' && $_POST['current_image'] !== 'props/default.png') {
            $oldImagePath = '../products/' . $_POST['current_image'];
            if (file_exists($oldImagePath) && basename($oldImagePath) !== 'default.png' && strpos($oldImagePath, 'default.png') === false) {
                unlink($oldImagePath);
            }
        }
    } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // Original file upload handling
        $target_dir = "../products/";
        $imageFileType = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $image_url = uniqid() . '.' . $imageFileType;
        move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $image_url);
        
        // Delete old image if it's not the default
        if ($_POST['current_image'] !== '../props/default.png' && $_POST['current_image'] !== 'default.png' && $_POST['current_image'] !== 'props/default.png') {
            $oldImagePath = '../products/' . $_POST['current_image'];
            if (file_exists($oldImagePath) && basename($oldImagePath) !== 'default.png' && strpos($oldImagePath, 'default.png') === false) {
                unlink($oldImagePath);
            }
        }
    } else {
        // If no new image is provided, and current image is empty or not set, use default.png
        if (empty($_POST['current_image']) || $_POST['current_image'] === '') {
            $image_url = '../props/default.png';
        }
    }

    $stmt = $db->prepare("UPDATE products SET 
        name = :name, 
        quantity = :quantity, 
        price = :price, 
        buying_price = :buying_price, 
        image_url = :image_url, 
        restock_level = :restock_level, 
        capacity = :capacity, 
        expiry_date = :expiry_date,
        barcode = :barcode,
        category = :category,
        discount = :discount,
        discount_start = :discount_start,
        discount_end = :discount_end
        WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':quantity', $quantity, SQLITE3_INTEGER);
    $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
    $stmt->bindValue(':buying_price', $buying_price, SQLITE3_FLOAT);
    $stmt->bindValue(':image_url', $image_url, SQLITE3_TEXT);
    $stmt->bindValue(':restock_level', $restock_level, SQLITE3_INTEGER);
    $stmt->bindValue(':capacity', $capacity, SQLITE3_TEXT);
    $stmt->bindValue(':expiry_date', $expiry_date, SQLITE3_TEXT);
    $stmt->bindValue(':barcode', $barcode, SQLITE3_TEXT);
    $stmt->bindValue(':category', $_POST['category'], SQLITE3_TEXT);
    $stmt->bindValue(':discount', $_POST['discount'], SQLITE3_FLOAT);
    $stmt->bindValue(':discount_start', $_POST['discount_start'], SQLITE3_TEXT);
    $stmt->bindValue(':discount_end', $_POST['discount_end'], SQLITE3_TEXT);
    $stmt->execute();

    // Track stock changes if quantity was modified
    $newQuantity = (int)$quantity;
    $quantityChange = $newQuantity - $oldQuantity;
    
    if ($quantityChange !== 0) {
        $action = $quantityChange > 0 ? 'Restock' : 'Adjust';
        $stmtInsert = $db->prepare("INSERT INTO stock_changes 
            (product_id, action, quantity_change, old_quantity, new_quantity)
            VALUES (:product_id, :action, :quantity_change, :old_quantity, :new_quantity)");
        $stmtInsert->bindValue(':product_id', $id, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':action', $action, SQLITE3_TEXT);
        $stmtInsert->bindValue(':quantity_change', $quantityChange, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':old_quantity', $oldQuantity, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':new_quantity', $newQuantity, SQLITE3_INTEGER);
        $stmtInsert->execute();
    }

    // Save recipe rows for this product (ingredients + qty per unit)
    $ingredientIds = $_POST['recipe_ingredient_id'] ?? [];
    $ingredientQtys = $_POST['recipe_qty'] ?? [];
    $recipeRows = [];
    $rowCount = min(count($ingredientIds), count($ingredientQtys));
    for ($i = 0; $i < $rowCount; $i++) {
        $ingredientId = intval($ingredientIds[$i]);
        $qtyPerUnit = floatval($ingredientQtys[$i]);
        if ($ingredientId > 0 && $qtyPerUnit > 0 && $ingredientId !== intval($id)) {
            $recipeRows[] = [
                'ingredient_id' => $ingredientId,
                'qty' => $qtyPerUnit
            ];
        }
    }

    // Remove existing recipe first
    $deleteItemsStmt = $db->prepare("DELETE FROM recipe_items WHERE recipe_id IN (SELECT id FROM product_recipes WHERE product_id = :product_id)");
    $deleteItemsStmt->bindValue(':product_id', $id, SQLITE3_INTEGER);
    $deleteItemsStmt->execute();
    $deleteRecipeStmt = $db->prepare("DELETE FROM product_recipes WHERE product_id = :product_id");
    $deleteRecipeStmt->bindValue(':product_id', $id, SQLITE3_INTEGER);
    $deleteRecipeStmt->execute();

    if (!empty($recipeRows)) {
        $createRecipeStmt = $db->prepare("INSERT INTO product_recipes (product_id, updated_at) VALUES (:product_id, CURRENT_TIMESTAMP)");
        $createRecipeStmt->bindValue(':product_id', $id, SQLITE3_INTEGER);
        $createRecipeStmt->execute();
        $recipeId = $db->lastInsertRowID();

        $insertRecipeItemStmt = $db->prepare("
            INSERT INTO recipe_items (recipe_id, ingredient_product_id, quantity_per_unit)
            VALUES (:recipe_id, :ingredient_id, :qty)
        ");
        foreach ($recipeRows as $recipeRow) {
            $insertRecipeItemStmt->bindValue(':recipe_id', $recipeId, SQLITE3_INTEGER);
            $insertRecipeItemStmt->bindValue(':ingredient_id', $recipeRow['ingredient_id'], SQLITE3_INTEGER);
            $insertRecipeItemStmt->bindValue(':qty', $recipeRow['qty'], SQLITE3_FLOAT);
            $insertRecipeItemStmt->execute();
        }
    }

    header('Location: edit?id=' . $id . '&edit=success');
    exit;
}

// Get product data for editing
if (isset($_GET['id'])) {
    $db = new SQLite3('../pos.db');
    ensureRecipeTablesSQLite($db);
    $id = $_GET['id'];
    
    $stmt = $db->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $product = $result->fetchArray(SQLITE3_ASSOC);
    
    // Fetch unique categories
    $categories = [];
    $catResult = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    while ($row = $catResult->fetchArray(SQLITE3_ASSOC)) {
        $categories[] = $row['category'];
    }

    $ingredientOptions = [];
    $ingredientResult = $db->query("SELECT id, name, quantity FROM products ORDER BY name ASC");
    while ($row = $ingredientResult->fetchArray(SQLITE3_ASSOC)) {
        $ingredientOptions[] = $row;
    }

    $recipeItems = [];
    $recipeStmt = $db->prepare("
        SELECT ri.ingredient_product_id, ri.quantity_per_unit
        FROM product_recipes pr
        INNER JOIN recipe_items ri ON ri.recipe_id = pr.id
        WHERE pr.product_id = :product_id
        ORDER BY ri.id ASC
    ");
    $recipeStmt->bindValue(':product_id', $id, SQLITE3_INTEGER);
    $recipeResult = $recipeStmt->execute();
    while ($row = $recipeResult->fetchArray(SQLITE3_ASSOC)) {
        $recipeItems[] = $row;
    }
    
    if (!$product) {
        header('Location: inventory');
        exit;
    }
} else {
    header('Location: inventory');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product</title>
    <script src="3.4.16"></script>
    <script src="cropper.min.js"></script>
    <link rel="stylesheet" href="cropper.min.css"/>
    <style>
        .toast-notification {
            transition: opacity 0.5s, transform 0.5s;
            transform: translateY(-100%);
            opacity: 0;
            animation: slideIn 0.5s forwards;
        }
        @keyframes slideIn {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000; /* Highest - always accessible */
        }
        
        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        
        .hamburger span:nth-child(1) {
            top: 0px;
        }
        
        .hamburger span:nth-child(2) {
            top: 10px;
        }
        
        .hamburger span:nth-child(3) {
            top: 20px;
        }
        
        .hamburger.open span:nth-child(1) {
            top: 10px;
            transform: rotate(135deg);
        }
        
        .hamburger.open span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }
        
        .hamburger.open span:nth-child(3) {
            top: 10px;
            transform: rotate(-135deg);
        }
        
        /* Mobile sidebar overlay */
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80; /* Below sidebar (9999) and hamburger (10000) - matches credit-tabs.php */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Ensure sidebar maintains proper z-index above overlay */
        .sidebar {
            z-index: 10000 !important;
        }
        
        #sidebar {
            z-index: 10000 !important;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            /* Remove left margin on mobile */
            .ml-64 {
                margin-left: 0 !important;
            }
            
            /* Ensure content takes full width on mobile */
            .flex-1 {
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            .content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <!-- Header Row: Title + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 rounded" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Edit Product</h1>
                    </div>
                    
                    <!-- Right Side Controls -->
                    <div class="flex items-center gap-2">
                        <a href="inventory" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            <span class="hidden sm:inline">Go Back</span>
                            <span class="sm:hidden">Back</span>
                        </a>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-lg p-8">
                    
                    <?php if (isset($error_message)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($product['id']); ?>">
                        <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($product['image_url']); ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Left Column - Input Fields -->
                            <div class="space-y-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Product Name</label>
                                    <input type="text" name="name" id="name" required
                                        value="<?php echo htmlspecialchars($product['name']); ?>"
                                        data-original-name="<?php echo htmlspecialchars($product['name']); ?>"
                                        placeholder="Enter product name"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>

                                <!-- Price fields row -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label for="price" class="block text-sm font-medium text-gray-700 mb-2">Selling Price <span class="text-red-500">*</label>
                                        <div class="grid grid-cols-[auto,1fr] rounded-md shadow-sm">
                                            <div class="flex items-center justify-center px-3 py-2 border border-gray-300 rounded-l-md bg-gray-50">
                                                <span class="text-gray-500 sm:text-sm">N$</span>
                                            </div>
                                            <input type="number" step="0.01" name="price" id="price" required min="0"
                                                placeholder="0.00"
                                                value="<?php echo $product['price']; ?>"
                                                class="block w-full px-3 py-2 border-l-0 border border-gray-300 rounded-r-md 
                                                placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        </div>
                                    </div>
                                    <div>
                                        <label for="buying_price" class="block text-sm font-medium text-gray-700 mb-2">Buying Price <span class="text-red-500">*</label>
                                        <div class="grid grid-cols-[auto,1fr] rounded-md shadow-sm">
                                            <div class="flex items-center justify-center px-3 py-2 border border-gray-300 rounded-l-md bg-gray-50">
                                                <span class="text-gray-500 sm:text-sm">N$</span>
                                            </div>
                                            <input type="number" step="0.01" name="buying_price" id="buying_price" required min="0"
                                                placeholder="0.00"
                                                value="<?php echo $product['buying_price']; ?>"
                                                class="block w-full px-3 py-2 border-l-0 border border-gray-300 rounded-r-md 
                                                placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        </div>
                                    </div>
                                    <div>
                                        <label for="quantity" class="block text-sm font-medium text-gray-700 mb-2">Stock Quantity <span class="text-red-500">*</label>
                                        <div class="grid grid-cols-[auto,1fr] rounded-md shadow-sm">
                                            <div class="flex items-center justify-center px-3 py-2 border border-gray-300 rounded-l-md bg-gray-50">
                                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
                                                </svg>
                                            </div>
                                            <input type="number" name="quantity" id="quantity" required min="0"
                                                placeholder="Enter stock quantity"
                                                value="<?php echo $product['quantity']; ?>"
                                                class="block w-full px-3 py-2 border-l-0 border border-gray-300 rounded-r-md 
                                                placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        </div>
                                    </div>
                                </div>

                                <!-- Product details row -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="restock_level" class="block text-sm font-medium text-gray-700 mb-2">Restock Level</span></label>
                                        <input type="number" name="restock_level" id="restock_level" min="0"
                                            placeholder="Minimum stock alert"
                                            value="<?php echo $product['restock_level']; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div>
                                        <label for="capacity" class="block text-sm font-medium text-gray-700 mb-2">Capacity/Size</label>
                                        <input type="text" name="capacity" id="capacity" 
                                            placeholder="e.g. 330ml, 1 liter, 500g"
                                            value="<?php echo $product['capacity']; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div>
                                        <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-2">Expiry Date</label>
                                        <input type="date" name="expiry_date" id="expiry_date"
                                            value="<?php echo $product['expiry_date'] ? $product['expiry_date'] : ''; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div>
                                        <label for="barcode" class="block text-sm font-medium text-gray-700 mb-2">Barcode</label>
                                        <div class="flex items-center gap-2">
                                        <input type="text" name="barcode" id="barcode" 
                                            value="<?php echo htmlspecialchars($product['barcode']); ?>"
                                            placeholder="Enter barcode"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out"
                                                oninput="updateBarcodeDisplay(this.value)">
                                            <?php if (!empty($product['barcode'])): ?>
                                                <button type="button" onclick="copyBarcode('<?php echo htmlspecialchars($product['barcode']); ?>')"
                                                    class="p-2 text-gray-500 hover:text-teal-600 transition-colors duration-200"
                                                    title="Copy barcode">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h4a2 2 0 002-2M8 5a2 2 0 012-2h4a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                                </svg>
                            </button>
                                            <?php endif; ?>
                        </div>
                                        <?php if (!empty($product['barcode'])): ?>
                                            <div class="mt-2">
                                                <div id="barcodeDisplay" class="w-full max-w-xs">
                                                    <img src="https://barcode.tec-it.com/barcode.ashx?data=<?php echo urlencode($product['barcode']); ?>&code=Code128&dpi=96" 
                                                         alt="Barcode" 
                                                         class="w-full h-auto">
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Category and Discount Section -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                                        <input type="text" name="category" id="category" 
                                            list="category-list"
                                            value="<?php echo htmlspecialchars($product['category']); ?>"
                                            placeholder="Type or select a category"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        <datalist id="category-list">
                                            <?php 
                                            $catResult = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
                                            while ($row = $catResult->fetchArray(SQLITE3_ASSOC)) {
                                                echo '<option value="' . htmlspecialchars($row['category']) . '">';
                                            }
                                            ?>
                                        </datalist>
                                    </div>
                                    <div>
                                        <label for="discount" class="block text-sm font-medium text-gray-700 mb-2">Discount (%)</label>
                                        <input type="number" name="discount" id="discount" min="0" max="100" step="0.01"
                                            placeholder="Enter discount percentage"
                                            value="<?php echo $product['discount'] ?? 0; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                </div>

                                <!-- Discount Date Range -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="discount_start" class="block text-sm font-medium text-gray-700 mb-2">Discount Start Date</label>
                                        <input type="date" name="discount_start" id="discount_start"
                                            value="<?php echo $product['discount_start'] ? date('Y-m-d', strtotime($product['discount_start'])) : ''; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div>
                                        <label for="discount_end" class="block text-sm font-medium text-gray-700 mb-2">Discount End Date</label>
                                        <input type="date" name="discount_end" id="discount_end"
                                            value="<?php echo $product['discount_end'] ? date('Y-m-d', strtotime($product['discount_end'])) : ''; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                </div>

                                <!-- Recipe Builder -->
                                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                    <div class="flex items-center justify-between mb-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-gray-800">Recipe-based Product</h3>
                                            <p class="text-xs text-gray-500">Set ingredients deducted automatically when this product is sold.</p>
                                        </div>
                                        <button type="button" id="addRecipeRowBtn" class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700">
                                            Add Ingredient
                                        </button>
                                    </div>

                                    <div id="recipeRows" class="space-y-2">
                                        <?php if (!empty($recipeItems)): ?>
                                            <?php foreach ($recipeItems as $recipeItem): ?>
                                                <div class="grid grid-cols-12 gap-2 items-center recipe-row">
                                                    <div class="col-span-7">
                                                        <select name="recipe_ingredient_id[]" class="w-full px-2 py-2 border border-gray-300 rounded-md text-sm">
                                                            <option value="">Select ingredient</option>
                                                            <?php foreach ($ingredientOptions as $ingredient): ?>
                                                                <?php if (intval($ingredient['id']) !== intval($product['id'])): ?>
                                                                    <option value="<?= intval($ingredient['id']) ?>" <?= intval($recipeItem['ingredient_product_id']) === intval($ingredient['id']) ? 'selected' : '' ?>>
                                                                        <?= htmlspecialchars($ingredient['name']) ?> (Stock: <?= number_format(floatval($ingredient['quantity']), 2) ?>)
                                                                    </option>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-span-4">
                                                        <input type="number" step="0.0001" min="0.0001" name="recipe_qty[]" value="<?= number_format(floatval($recipeItem['quantity_per_unit']), 4, '.', '') ?>" placeholder="Qty per 1 unit" class="w-full px-2 py-2 border border-gray-300 rounded-md text-sm">
                                                    </div>
                                                    <div class="col-span-1 text-right">
                                                        <button type="button" class="remove-recipe-row text-rose-600 hover:text-rose-800 text-lg leading-none">&times;</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">Example: Strawberry Daiquiri -> Rum 0.0500, Strawberry Puree 0.0300, Ice 0.2000.</p>
                                </div>
                            </div>

                            <!-- Right Column - Image Upload and Cropper -->
                            <div>
                                <label for="image" class="block text-sm font-medium text-gray-700 mb-2">Product Image</label>
                                <div id="drop-zone" class="border-2 border-dashed border-gray-300 rounded-lg p-6 cursor-pointer">
                                    <div class="flex items-center justify-center flex-col">
                                        <label class="flex items-center px-3 py-2 bg-white text-gray-400 
                                            rounded-md shadow-sm text-sm font-medium border border-gray-400 cursor-pointer 
                                            hover:bg-gray-200 transition duration-150 ease-in-out">
                                            <svg class="w-5 h-5 mr-2" fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                                <path d="M16.88 9.1A4 4 0 0 1 16 17H5a5 5 0 0 1-1-9.9V7a3 3 0 0 1 4.52-2.59A4.98 4.98 0 0 1 17 8c0 .38-.04.74-.12 1.1zM11 11h3l-4-4-4 4h3v3h2v-3z" />
                                            </svg>
                                            Upload Image
                                            <input type='file' name="image" id="image" accept="image/*" class="hidden" />
                                        </label>
                                        <span id="file-chosen" class="ml-3 text-sm text-gray-500 mt-3">or drag and drop image here</span>
                                    </div>
                                </div>
                                <div class="mt-4 <?php echo ($product['image_url'] !== '../props/default.png') ? '' : 'hidden'; ?>" id="image-preview-container">
                                    <div class="w-64 h-64 overflow-hidden relative bg-white">
                                        <img id="preview-image" class="w-full h-full object-cover" src="../products/<?php echo $product['image_url']; ?>" />
                                    </div>
                                    <input type="hidden" name="cropped_image" id="cropped-image" />
                                </div>
                                <p class="mt-2 text-sm text-gray-500">Upload a product image (JPEG, PNG, max 5MB)</p>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-4 mt-6">
                            <a href="inventory" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">
                                Cancel
                            </a>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition duration-150 ease-in-out">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add this toast container at the bottom of the body -->
    <div id="toast-container" class="fixed top-4 right-4 z-50"></div>

    <script>
        const fileInput = document.getElementById('image');
        const fileChosen = document.getElementById('file-chosen');
        const previewContainer = document.getElementById('image-preview-container');
        const previewImage = document.getElementById('preview-image');
        const croppedImageInput = document.getElementById('cropped-image');
        const form = document.querySelector('form');
        let cropper = null;
        const ingredientOptions = <?= json_encode(array_values(array_map(function($ingredient) use ($product) {
            return [
                'id' => intval($ingredient['id']),
                'name' => $ingredient['name'],
                'quantity' => floatval($ingredient['quantity']),
                'isCurrent' => intval($ingredient['id']) === intval($product['id'])
            ];
        }, $ingredientOptions)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        // Initialize cropper if existing image is present
        if (!previewContainer.classList.contains('hidden')) {
            cropper = new Cropper(previewImage, {
                aspectRatio: 1 / 1,
                viewMode: 0,
                dragMode: 'move',
                autoCropArea: 1.0,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                background: false,
                modal: false,
                zoomable: true,
                zoomOnTouch: true,
                zoomOnWheel: true,
                wheelZoomRatio: 0.1
            });
        }

        fileInput.addEventListener('change', function(e) {
            if(this.files && this.files[0]) {
                fileChosen.textContent = this.files[0].name;
                const reader = new FileReader();
                reader.onload = (e) => {
                    previewContainer.classList.remove('hidden');
                    previewImage.src = e.target.result;
                    if(cropper) {
                        cropper.destroy();
                    }
                    cropper = new Cropper(previewImage, {
                        aspectRatio: 1 / 1,
                        viewMode: 0,
                        dragMode: 'move',
                        autoCropArea: 1.0,
                        cropBoxMovable: true,
                        cropBoxResizable: true,
                        toggleDragModeOnDblclick: false,
                        background: false,
                        modal: false,
                        zoomable: true,
                        zoomOnTouch: true,
                        zoomOnWheel: true,
                        wheelZoomRatio: 0.1
                    });
                };
                reader.readAsDataURL(this.files[0]);
            } else {
                fileChosen.textContent = 'No file chosen';
                previewContainer.classList.add('hidden');
                if(cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            }
        });

        form.addEventListener('submit', function(e) {
            if (cropper) {
                e.preventDefault();
                const croppedCanvas = cropper.getCroppedCanvas();
                if (croppedCanvas) {
                    croppedImageInput.value = croppedCanvas.toDataURL('image/png');
                    fileInput.value = ''; // Clear original file input
                    cropper.destroy();
                    cropper = null;
                }
                this.submit(); // Submit the form after handling crop
            }
        });

        // Toast notification function
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast-notification px-6 py-3 rounded-md text-white shadow-lg ${
                type === 'success' ? 'bg-teal-500' : 
                type === 'error' ? 'bg-rose-600' : 
                'bg-sky-500'
            }`;
            toast.textContent = message;
            document.getElementById('toast-container').appendChild(toast);

            setTimeout(() => {
                toast.classList.add('opacity-0', '-translate-y-full');
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

        // Handle URL parameters for toast notifications
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit')) {
            const status = urlParams.get('edit');
            showToast(
                status === 'success' ? 'Product updated successfully!' : 'Update failed',
                status === 'success' ? 'success' : 'error'
            );
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        // Drag and drop handlers
        const dropZone = document.getElementById('drop-zone');
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-blue-500', 'border-solid');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-blue-500', 'border-solid');
            dropZone.classList.add('border-dashed');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-blue-500', 'border-solid');
            dropZone.classList.add('border-dashed');

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(files[0]);
                fileInput.files = dataTransfer.files;
                
                const event = new Event('change');
                fileInput.dispatchEvent(event);
            } else if (e.dataTransfer.types.includes('text/uri-list')) {
                const url = e.dataTransfer.getData('text/uri-list');
                fetch(url)
                    .then(res => res.blob())
                    .then(blob => {
                        const file = new File([blob], "dropped-image", { type: blob.type });
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        fileInput.files = dataTransfer.files;
                        
                        const event = new Event('change');
                        fileInput.dispatchEvent(event);
                    });
            }
        });

        function updateBarcodeDisplay(barcode) {
            const barcodeDisplay = document.getElementById('barcodeDisplay');
            if (barcode && barcode.trim() !== '') {
                barcodeDisplay.innerHTML = `
                    <img src="https://barcode.tec-it.com/barcode.ashx?data=${encodeURIComponent(barcode)}&code=Code128&dpi=96" 
                         alt="Barcode" 
                         class="w-full h-auto">`;
                barcodeDisplay.classList.remove('hidden');
            } else {
                barcodeDisplay.innerHTML = '';
                barcodeDisplay.classList.add('hidden');
            }
        }

        function copyBarcode(barcode) {
            navigator.clipboard.writeText(barcode).then(function() {
                showToast('Barcode copied to clipboard!', 'success');
            }, function() {
                showToast('Failed to copy barcode', 'error');
            });
        }

        function buildIngredientOptionsHtml(selectedId = '') {
            let html = '<option value="">Select ingredient</option>';
            ingredientOptions.forEach((option) => {
                if (option.isCurrent) return;
                const isSelected = String(option.id) === String(selectedId) ? ' selected' : '';
                html += `<option value="${option.id}"${isSelected}>${option.name} (Stock: ${option.quantity.toFixed(2)})</option>`;
            });
            return html;
        }

        function createRecipeRow(selectedId = '', qty = '') {
            const row = document.createElement('div');
            row.className = 'grid grid-cols-12 gap-2 items-center recipe-row';
            row.innerHTML = `
                <div class="col-span-7">
                    <select name="recipe_ingredient_id[]" class="w-full px-2 py-2 border border-gray-300 rounded-md text-sm">
                        ${buildIngredientOptionsHtml(selectedId)}
                    </select>
                </div>
                <div class="col-span-4">
                    <input type="number" step="0.0001" min="0.0001" name="recipe_qty[]" value="${qty}" placeholder="Qty per 1 unit" class="w-full px-2 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div class="col-span-1 text-right">
                    <button type="button" class="remove-recipe-row text-rose-600 hover:text-rose-800 text-lg leading-none">&times;</button>
                </div>
            `;
            return row;
        }

        // Initialize barcode display
        document.addEventListener('DOMContentLoaded', function() {
            const barcodeInput = document.getElementById('barcode');
            if (barcodeInput && barcodeInput.value) {
                updateBarcodeDisplay(barcodeInput.value);
            }

            const recipeRowsContainer = document.getElementById('recipeRows');
            const addRecipeRowBtn = document.getElementById('addRecipeRowBtn');

            if (addRecipeRowBtn && recipeRowsContainer) {
                addRecipeRowBtn.addEventListener('click', function() {
                    recipeRowsContainer.appendChild(createRecipeRow());
                });

                recipeRowsContainer.addEventListener('click', function(e) {
                    if (e.target.classList.contains('remove-recipe-row')) {
                        const row = e.target.closest('.recipe-row');
                        if (row) {
                            row.remove();
                        }
                    }
                });
            }
        });

        // Mobile sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            hamburger.classList.toggle('open');
        }
        
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            hamburger.classList.remove('open');
        }
    </script>
</body>
</html>

