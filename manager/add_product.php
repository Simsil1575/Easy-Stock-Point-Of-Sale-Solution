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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new SQLite3('../pos.db');
    
    $name = $_POST['name'];
    $quantity = $_POST['quantity'];
    $price = $_POST['price'];
    $buying_price = $_POST['buying_price'];
    $restock_level = $_POST['restock_level'];
    $capacity = $_POST['capacity'];
    $expiry_date = $_POST['expiry_date'];
    $barcode = $_POST['barcode'];
    $category = $_POST['category'];
    
    // Check if product with same name already exists
    $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE name = :name");
    $check_stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $result = $check_stmt->execute()->fetchArray();
    
    if ($result['count'] > 0) {
        // Product with same name already exists
        $error_message = "A product with this name already exists. Please use a different name.";
    } else {
    // Handle image upload
    $image_url = 'default.png'; // Default image
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
    } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // Original file upload handling
        $target_dir = "../products/";
        $imageFileType = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $image_url = uniqid() . '.' . $imageFileType;
        move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $image_url);
    }

    // Ensure we're using the correct path for default.png
    if ($image_url === 'default.png') {
        $image_url = '../props/default.png';
    }

    $stmt = $db->prepare("INSERT INTO products (name, quantity, price, buying_price, image_url, restock_level, capacity, expiry_date, barcode, category) VALUES (:name, :quantity, :price, :buying_price, :image_url, :restock_level, :capacity, :expiry_date, :barcode, :category)");
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':quantity', $quantity, SQLITE3_INTEGER);
    $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
    $stmt->bindValue(':buying_price', $buying_price, SQLITE3_FLOAT);
    $stmt->bindValue(':image_url', $image_url, SQLITE3_TEXT);
    $stmt->bindValue(':restock_level', $restock_level, SQLITE3_INTEGER);
    $stmt->bindValue(':capacity', $capacity, SQLITE3_TEXT);
    $stmt->bindValue(':expiry_date', $expiry_date, SQLITE3_TEXT);
    $stmt->bindValue(':barcode', $barcode, SQLITE3_TEXT);
    $stmt->bindValue(':category', $category, SQLITE3_TEXT);
    $stmt->execute();

    // Log the initial stock addition
    $product_id = $db->lastInsertRowID();
    if ((int)$quantity !== 0) {
        $log_stmt = $db->prepare("
            INSERT INTO stock_changes 
            (product_id, action, quantity_change, old_quantity, new_quantity) 
            VALUES (:product_id, 'add', :quantity, 0, :quantity)
        ");
        $log_stmt->bindValue(':product_id', $product_id, SQLITE3_INTEGER);
        $log_stmt->bindValue(':quantity', $quantity, SQLITE3_INTEGER);
        $log_stmt->execute();
    }

    header('Location: inventory?add=success');
    exit;
    }
}

// Fetch unique categories
$categories = [];
$db = new SQLite3('../pos.db');
$catResult = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
while ($row = $catResult->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row['category'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product</title>
    <script src="3.4.16"></script>
    <script src="cropper.min.js"></script>
    <link rel="stylesheet" href="cropper.min.css"/>
    <style>
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
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Add New Product</h1>
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
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Left Column - Input Fields -->
                            <div class="space-y-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Product Name</label>
                                    <input type="text" name="name" id="name" required
                                        placeholder="Enter product name"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                </div>

                                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label for="price" class="block text-sm font-medium text-gray-700 mb-2">Selling Price <span class="text-red-500">*</label>
                                        <div class="grid grid-cols-[auto,1fr] rounded-md shadow-sm">
                                            <div class="flex items-center justify-center px-3 py-2 border border-gray-300 rounded-l-md bg-gray-50">
                                                <span class="text-gray-500 sm:text-sm">N$</span>
                                            </div>
                                            <input type="number" step="0.01" name="price" id="price" required min="0"
                                                placeholder="0.00"
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
                                                class="block w-full px-3 py-2 border-l-0 border border-gray-300 rounded-r-md 
                                                placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                        </div>
                                    </div>
                                </div>

                                <!-- Restock and Capacity row -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="restock_level" class="block text-sm font-medium text-gray-700 mb-2">Restock Level</label>
                                        <input type="number" name="restock_level" id="restock_level"  min="0"
                                            placeholder="Minimum stock alert"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div>
                                        <label for="capacity" class="block text-sm font-medium text-gray-700 mb-2">Capacity/Size</label>
                                        <input type="text" name="capacity" id="capacity" 
                                            placeholder="e.g. 330ml, 1 liter, 500g"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                            </div>

                                <!-- Expiry Date and Barcode row -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                        <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-2">Expiry Date</label>
                                        <input type="date" name="expiry_date" id="expiry_date"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                            focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    </div>
                                    <div>
                                        <label for="barcode" class="block text-sm font-medium text-gray-700 mb-2">Barcode</label>
                                        <div class="flex items-center gap-2">
                                            <input type="text" name="barcode" id="barcode" 
                                                placeholder="Enter barcode"
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                            placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                                focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out"
                                                oninput="updateBarcodeDisplay(this.value)">
                                        </div>
                                        <div class="mt-2">
                                            <div id="barcodeDisplay" class="w-full max-w-xs hidden">
                                                <img src="" 
                                                     alt="Barcode Preview" 
                                                     class="w-full h-auto">
                                            </div>
                                            <p id="barcodeText" class="mt-2 text-sm text-gray-500 hidden"></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Category Section -->
                                <div>
                                    <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                                    <input type="text" name="category" id="category" 
                                        list="category-list"
                                        placeholder="Type or select a category"
                                        class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                        placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 
                                        focus:border-teal-500 sm:text-sm transition duration-150 ease-in-out">
                                    <datalist id="category-list">
                                        <?php 
                                        foreach ($categories as $cat) {
                                            echo '<option value="' . htmlspecialchars($cat) . '">';
                                        }
                                        ?>
                                    </datalist>
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
                                <div class="mt-4 hidden" id="image-preview-container">
                                    <div class="w-64 h-64 overflow-hidden relative bg-white">
                                        <img id="preview-image" class="w-full h-full object-cover" />
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
                                Add Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('image');
        const fileChosen = document.getElementById('file-chosen');
        const previewContainer = document.getElementById('image-preview-container');
        const previewImage = document.getElementById('preview-image');
        const croppedImageInput = document.getElementById('cropped-image');
        const form = document.querySelector('form');
        let cropper = null;

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
            // If no cropper active, form submits normally
        });

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
                // Handle file drop
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(files[0]);
                fileInput.files = dataTransfer.files;
                
                // Trigger change event for existing handler
                const event = new Event('change');
                fileInput.dispatchEvent(event);
            } else if (e.dataTransfer.types.includes('text/uri-list')) {
                // Handle URL drop
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
            const barcodeText = document.getElementById('barcodeText');
            const barcodeSpan = barcodeText.querySelector('span');
            
            if (barcode && barcode.trim() !== '') {
                // Update barcode image
                barcodeDisplay.querySelector('img').src = 
                    `https://barcode.tec-it.com/barcode.ashx?data=${encodeURIComponent(barcode)}&code=Code128&dpi=96`;
                barcodeDisplay.classList.remove('hidden');
                
                // Update barcode text
                barcodeSpan.textContent = barcode;
                barcodeText.classList.remove('hidden');
            } else {
                barcodeDisplay.classList.add('hidden');
                barcodeText.classList.add('hidden');
            }
        }

        // Initialize barcode display functionality
        document.addEventListener('DOMContentLoaded', function() {
            const barcodeInput = document.getElementById('barcode');
            if (barcodeInput) {
                barcodeInput.addEventListener('input', function() {
                    updateBarcodeDisplay(this.value);
                    });
            }
        });

        // Add real-time validation for product name
        document.addEventListener('DOMContentLoaded', function() {
            const nameInput = document.getElementById('name');
            const form = document.querySelector('form');
            
            nameInput.addEventListener('blur', function() {
                if (this.value.trim() !== '') {
                    // Check if product name already exists
                    fetch(`check_product_name.php?name=${encodeURIComponent(this.value.trim())}`)
                        .then(response => response.json())
                        .then(data => {
                            const existingErrorMsg = document.getElementById('name-error');
                            if (existingErrorMsg) existingErrorMsg.remove();
                            
                            if (data.exists) {
                                const errorMsg = document.createElement('p');
                                errorMsg.id = 'name-error';
                                errorMsg.className = 'mt-1 text-sm text-red-600';
                                errorMsg.textContent = 'A product with this name already exists';
                                this.parentNode.appendChild(errorMsg);
                                this.classList.add('border-red-500');
                            } else {
                                this.classList.remove('border-red-500');
                            }
                        });
                }
            });
        });
    </script>

    <script>
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
