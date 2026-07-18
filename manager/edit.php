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


require_once __DIR__ . '/../recipe_stock_helper.php';
require_once __DIR__ . '/../ensure_stock_changes_username.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new SQLite3('../pos.db');
    configureSqlite3($db);
    ensureStockChangesUsernameColumn($db);

    $id = $_POST['id'];
    $name = $_POST['name'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $buying_price_raw = isset($_POST['buying_price']) ? trim((string)$_POST['buying_price']) : '';
    $buying_price = $buying_price_raw === '' ? null : (float)$buying_price_raw;
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
    if ($buying_price === null) {
        $stmt->bindValue(':buying_price', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':buying_price', $buying_price, SQLITE3_FLOAT);
    }
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
            (product_id, action, quantity_change, old_quantity, new_quantity, username)
            VALUES (:product_id, :action, :quantity_change, :old_quantity, :new_quantity, :username)");
        $stmtInsert->bindValue(':product_id', $id, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':action', $action, SQLITE3_TEXT);
        $stmtInsert->bindValue(':quantity_change', $quantityChange, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':old_quantity', $oldQuantity, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':new_quantity', $newQuantity, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':username', currentStockChangeUsername(), SQLITE3_TEXT);
        $stmtInsert->execute();

        adjustRecipeStockByProductIdSQLite3($db, (int) $id, (float) $quantityChange);
    }

    header('Location: edit?id=' . $id . '&edit=success');
    exit;
}

// Get product data for editing
if (isset($_GET['id'])) {
    $db = new SQLite3('../pos.db');
    configureSqlite3($db);
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
<body class="bg-gray-100">
    <div class="flex">
        <div class="sidebar fixed h-full">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="content flex-1 lg:ml-64">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="w-full px-4 lg:px-6 py-6">
                <!-- Header Row: Title + Controls -->
                <div class="sticky top-0 z-50 bg-gray-100 py-4 mb-6 flex items-center justify-between gap-4 -mx-4 lg:-mx-6 px-4 lg:px-6 shadow-sm">
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
                                            <input type="number" step="0.01" name="buying_price" id="buying_price"
                                                placeholder="0.00"
                                                value="<?php echo isset($product['buying_price']) && $product['buying_price'] !== null && $product['buying_price'] !== '' ? htmlspecialchars((string)$product['buying_price']) : ''; ?>"
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
                                            <input type="number" name="quantity" id="quantity" required
                                                placeholder="Enter stock quantity"
                                                value="<?php echo $product['quantity']; ?>"
                                                class="block w-full px-3 py-2 border-l-0 border border-gray-300 rounded-r-md 
                                                placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        </div>
                                    </div>
                                </div>

                                <!-- Product details -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label for="restock_level" class="block text-sm font-medium text-gray-700 mb-2">Restock Level</label>
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
                                    <div id="discountStartField" class="<?php echo (!isset($product['discount']) || floatval($product['discount']) <= 0) ? 'hidden' : ''; ?>">
                                        <label for="discount_start" class="block text-sm font-medium text-gray-700 mb-2">Discount Start Date</label>
                                        <input type="date" name="discount_start" id="discount_start"
                                            value="<?php echo $product['discount_start'] ? date('Y-m-d', strtotime($product['discount_start'])) : ''; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div id="discountEndField" class="<?php echo (!isset($product['discount']) || floatval($product['discount']) <= 0) ? 'hidden' : ''; ?>">
                                        <label for="discount_end" class="block text-sm font-medium text-gray-700 mb-2">Discount End Date</label>
                                        <input type="date" name="discount_end" id="discount_end"
                                            value="<?php echo $product['discount_end'] ? date('Y-m-d', strtotime($product['discount_end'])) : ''; ?>"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column - Image Upload and Cropper -->
                            <div>
                                <div class="flex items-center gap-2 mb-2">
                                    <label for="image" class="block text-sm font-medium text-gray-700">Product Image</label>
                                    <button type="button" id="googleSearchBtn" title="Search Google Images" aria-label="Search Google Images"
                                        class="inline-flex items-center justify-center w-7 h-7 rounded-md text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition duration-150">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                        </svg>
                                    </button>
                                </div>
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
        const googleSearchBtn = document.getElementById('googleSearchBtn');
        const productNameInput = document.getElementById('name');
        const discountInput = document.getElementById('discount');
        const discountStartField = document.getElementById('discountStartField');
        const discountEndField = document.getElementById('discountEndField');
        const discountStartInput = document.getElementById('discount_start');
        const discountEndInput = document.getElementById('discount_end');
        let cropper = null;

        function getGoogleImagesUrl(query) {
            const params = new URLSearchParams({
                q: query,
                udm: '2'
            });
            return `https://www.google.com/search?${params.toString()}`;
        }

        function updateDiscountDateFields() {
            const value = parseFloat(discountInput.value);
            const hasDiscount = discountInput.value.trim() !== '' && !Number.isNaN(value) && value > 0;
            discountStartField.classList.toggle('hidden', !hasDiscount);
            discountEndField.classList.toggle('hidden', !hasDiscount);
            if (!hasDiscount) {
                discountStartInput.value = '';
                discountEndInput.value = '';
            }
        }

        function createCropperInstance() {
            return new Cropper(previewImage, {
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

        // Initialize cropper if existing image is present
        if (!previewContainer.classList.contains('hidden')) {
            cropper = createCropperInstance();
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
                    cropper = createCropperInstance();
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

        googleSearchBtn.addEventListener('click', () => {
            const query = (productNameInput?.value || '').trim();
            if (!query) {
                showToast('Enter a product name first.', 'error');
                return;
            }
            window.open(getGoogleImagesUrl(query), '_blank', 'noopener,noreferrer');
        });
        discountInput.addEventListener('input', updateDiscountDateFields);
        updateDiscountDateFields();

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

        // Initialize barcode display
        document.addEventListener('DOMContentLoaded', function() {
            const barcodeInput = document.getElementById('barcode');
            if (barcodeInput && barcodeInput.value) {
                updateBarcodeDisplay(barcodeInput.value);
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

