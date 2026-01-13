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

// Database connection
$db = new PDO('sqlite:pos.db');

// Fetch the show_all_products setting
$settingStmt = $db->query("SELECT show_all_products FROM product_settings LIMIT 1");
$setting = $settingStmt->fetch(PDO::FETCH_ASSOC);
$show_all_products = $setting['show_all_products'] ?? 0; // Default to 0 if not set

// Fetch products from the database
$query = '
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
';

// Add a condition to filter out products with quantity <= 0 if show_all_products is unchecked
if (!$show_all_products) {
    $query .= ' WHERE p.quantity > 0';
}

$query .= ' GROUP BY p.id ORDER BY total_sold DESC';

$stmt = $db->query($query);

$products = [];
$lowStock = [];
$outOfStock = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
    if ($row['quantity'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['quantity'] < 5) {
        $lowStock[] = $row;
    }
}

// Add this after fetching products
$creditors = $db->query("SELECT * FROM creditors WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>POS Solution</title>
    <link href="src/output.css" rel="stylesheet">
    <script src="navigation.js" async></script>
    <script src="src/howler.min.js"></script>
    <script src="src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">


    <style>

    * {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }

        
    /* Modern, colorful, and skinny sidebar styles */
    .sidebar {
        width: 3px;
        background: #f3f4f6;
        transition: width 0.3s ease;
    }

    .sidebar:hover {
        width: 12px;
    }

    .sidebar-icon {
        @apply w-6 h-6 text-white opacity-75 transition-all duration-300;
    }

    .sidebar:hover .sidebar-icon {
        @apply opacity-100;
    }

    .sidebar-text {
        @apply ml-3 text-white font-medium opacity-0 transition-opacity duration-300;
    }

    .sidebar:hover .sidebar-text {
        opacity: 1;
    }

    /* Modern, ultra-thin, and visible scrollbar styles */
    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color:rgb(133, 133, 133) #E5E7EB;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 2px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: #E5E7EB;
        border-radius: 1px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #4F46E5;
        border-radius: 1px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #f3f4f6 ;
    }

    /* Custom scrollbar for products container */
    .products-container::-webkit-scrollbar {
        width: 16px;
        background-color: #f5f5f5;
    }

    .products-container::-webkit-scrollbar-track {
        border-radius: 10px;
        background: #f1f1f1;
        box-shadow: inset 0 0 6px rgba(0,0,0,0.1);
    }

    .products-container::-webkit-scrollbar-thumb {
        background-color: #4F46E5;
        border-radius: 10px;
        border: 3px solid #f1f1f1;
        transition: background-color 0.3s;
    }

    .products-container::-webkit-scrollbar-thumb:hover {
        background-color: #4338CA;
    }

    .products-container::-webkit-scrollbar-thumb:active {
        background-color: #3730A3;
    }
    
    /* Mobile responsive adjustments */
    @media (max-width: 1023px) {
        .content {
            margin-left: 0 !important;
        }
        
        main {
            padding: 1rem;
        }
        
        .w-3/4 {
            width: 100%;
        }
        
        .w-96 {
            width: 100%;
        }
        
        #cart {
            position: fixed;
            bottom: 0;
            right: 0;
            left: 0;
            height: 50vh;
            border-radius: 1rem 1rem 0 0;
            margin: 0;
            z-index: 9997;
            transform: translateY(100%);
            transition: transform 0.3s ease-in-out;
        }
        
        /* Ensure modals appear above cart on mobile */
        .swal2-container {
            z-index: 10000 !important;
        }
        
        .swal2-popup {
            z-index: 10001 !important;
        }
        
        #cart.mobile-open {
            transform: translateY(0);
        }
        
        .mobile-cart-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9996;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-cart-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Mobile product grid - maintain aspect ratio and image sizes */
        .product-item {
            aspect-ratio: 1 / 1.4;
            min-height: 235px;
            width: calc(100% - 0.5rem);
            margin-bottom: 1rem;
        }
        
        #productGrid {
            gap: 1rem !important;
        }
        
        .product-item .w-full.h-60 {
            height: 50%;
            min-height: 140px;
        }
        
        .product-item .p-5 {
            height: 50%;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 0.75rem;
            gap: 0.25rem;
        }
        
        .product-item .p-5 p:first-child {
            font-size: 0.875rem;
            line-height: 1.25rem;
            margin-bottom: 0.25rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .product-item .p-5 p:last-child {
            margin-bottom: 0;
        }
        
        /* Mobile product item adjustments */
        .product-item {
            display: flex;
            flex-direction: column;
        }
        
        .product-item .w-full.h-60 {
            flex-shrink: 0;
        }
        
        .product-item .p-5 {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        
        /* Center product grid on mobile */
        #productGrid {
            justify-items: center;
            padding-right: 4px;
        }
    }
    
    /* Mobile hamburger menu styles */
    .hamburger {
        position: relative;
        width: 30px;
        height: 24px;
        cursor: pointer;
        z-index: 10000;
    }
    
    .hamburger span {
        display: block;
        position: absolute;
        height: 3px;
        width: 100%;
        background:rgb(0, 0, 0);
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
        z-index: 10;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    
    .mobile-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Touch scrolling improvements for product grid */
    #productGrid {
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        scroll-behavior: smooth;
    }

    /* Firefox support */
    .products-container {
        scrollbar-width: thin;
        scrollbar-color: #4F46E5 #f1f1f1;
    }

    .products-container {
        overflow-y: auto;
        height: 500px; /* Adjust based on your needs */
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }

    /* Cart scrollbar (existing custom-scrollbar class) */
    .custom-scrollbar::-webkit-scrollbar {
        width: 8px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #666;
    }
    </style>

</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">


    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <div class="container">

        <!-- Main Content Area -->
 

<main class="flex-1 pr-4 p-6 bg-gray-50 flex flex-col lg:flex-row">

    <!-- Mobile Sidebar Overlay -->
    <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>

    <!-- Products Section -->
    <div class="w-full lg:w-3/4 pr-0 lg:pr-6 max-h-[calc(100%)]">
        <!-- Search and Notifications Row -->
        <div class="mb-6 flex items-center justify-between gap-4">
            <!-- Mobile Controls Row -->
            <div class="flex items-center gap-3">
                <!-- Mobile Hamburger Menu Button -->
                <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                
            <!-- Mobile Cart Toggle Button -->
            <button id="mobileCartToggle" class="lg:hidden bg-gray-600 text-white p-2 rounded-lg hover:bg-gray-700 transition-colors duration-200 relative" onclick="toggleMobileCart()">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.4 5M17 13l1.4 5M9 21h6M9 21a2 2 0 11-4 0M15 21a2 2 0 104 0"></path>
                </svg>
                <!-- Cart Item Counter -->
                <span id="mobileCartCounter" class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full min-w-[20px] h-5 px-1 flex items-center justify-center pointer-events-none hidden">0</span>
            </button>
            </div>
            
            <!-- Search Bar -->
            <div class="relative flex-1">
                <input type="text" id="searchBar" style="width: 99%;" class="pl-10 pr-12 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-500 focus:border-teal-500 transition-colors duration-200" placeholder="Search for products or scan barcode..." oninput="filterProducts()" onkeydown="handleBarcodeEntry(event)">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                    </svg>
                </div>
                <!-- Clear button -->
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                    <svg id="clearSearch" onclick="clearSearch()" class="h-5 w-5 text-gray-400 cursor-pointer opacity-0 transition-opacity duration-200 pointer-events-none" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
            </div>

            <!-- Notifications Icon -->
            <div class="relative ml-auto">
                <div class="cursor-pointer" style="padding: 4px;">
                    <svg onclick="toggleNotifications()" class="h-6 w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php $notificationCount = count($outOfStock) + count($lowStock); ?>
                    <?php if ($notificationCount > 0): ?>
                        <span class="absolute top-2 right-2 transform translate-x-1/2 -translate-y-1/2 bg-red-500 text-white text-xs rounded-full min-w-[20px] h-5 px-1 flex items-center justify-center pointer-events-none" style="line-height: 1.25;"><?= $notificationCount ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Notifications Dropdown -->
                <div id="notificationsDropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar">
                    <?php if (empty($outOfStock) && empty($lowStock)): ?>
                        <div class="p-6 text-center">
                            <div class="mx-auto w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                            </div>
                            <p class="text-gray-500 font-medium">No notifications</p>
                            <p class="text-gray-400 text-sm mt-1">You're all caught up!</p>
                        </div>
                    <?php else: ?>

                        <?php if (!empty($outOfStock)): ?>
                            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-200">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-4 flex-1">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-sm font-semibold text-gray-900">Out of Stock Products</h3>
                                            <span class="text-xs font-medium text-red-500 bg-red-50 px-2 py-1 rounded-full"><?= count($outOfStock) ?></span>
                                        </div>
                                        <div class="mt-2 space-y-2">
                                            <?php foreach($outOfStock as $product): ?>
                                                <div class="flex items-center text-sm">
                                                    <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                    <span class="text-gray-700"><?= htmlspecialchars($product['name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($lowStock)): ?>
                            <div class="p-4 hover:bg-gray-50 transition-colors duration-200">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                            <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-4 flex-1">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-sm font-semibold text-gray-900">Low Stock Alert</h3>
                                            <span class="text-xs font-medium text-yellow-500 bg-yellow-50 px-2 py-1 rounded-full"><?= count($lowStock) ?></span>
                                        </div>
                                        <div class="mt-2 space-y-2">
                                            <?php foreach($lowStock as $product): ?>
                                                <div class="flex items-center justify-between text-sm">
                                                    <div class="flex items-center">
                                                        <svg class="w-4 h-4 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                        </svg>
                                                        <span class="text-gray-700"><?= htmlspecialchars($product['name']) ?></span>
                                                    </div>
                                                    <span class="text-yellow-600 font-medium"><?= $product['quantity'] ?> left</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if (empty($products)): ?>
            <div class="grid grid-cols-1 gap-8 py-4">
                <div class="col-span-full flex flex-col items-center justify-center p-8">
                    <div class="w-24 h-24 mb-6 bg-gray-100 rounded-full flex items-center justify-center">
                        <svg class="w-16 h-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-semibold text-gray-800 mb-2">No Products Available</h3>
                    <p class="text-gray-500 text-center max-w-md">It looks like there are no products in the database. Please add some products to get started.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-8 py-4 overflow-y-auto pr-0 lg:pr-4 custom-scrollbar" id="productGrid" style="max-height: calc(100vh - 8rem); height: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;">
                <?php foreach ($products as $product): ?>
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer transition-transform duration-300 hover:scale-105 product-item select-none" 
                        data-price="<?= $product['price'] ?>" 
                        data-name="<?= htmlspecialchars($product['name']) ?>" 
                        data-barcode="<?= htmlspecialchars($product['barcode']) ?>"
                        data-discount="<?= $product['discount'] ?? 0 ?>"
                        data-discount-start="<?= $product['discount_start'] ?? '' ?>"
                        data-discount-end="<?= $product['discount_end'] ?? '' ?>"
                        onclick="addToCart(this)" 
                        style="height: 100%;">
                    <div class="w-full h-60 overflow-hidden relative">
                        <?php if ($product['discount'] > 0 && strtotime($product['discount_start']) <= time() && strtotime($product['discount_end']) >= time()): ?>
                            <div class="absolute top-2 right-2 z-10">
                                <span class="bg-red-500 text-white px-3 py-1 rounded-full text-sm font-bold transform rotate-12 shadow-lg">
                                    <?= number_format($product['discount'], 0) ?>% OFF
                                </span>
                            </div>
                        <?php endif; ?>
                        <img 
                            src="products/<?= htmlspecialchars($product['image_url']) ?>" 
                            alt="<?= htmlspecialchars($product['name']) ?>" 
                            class="w-full h-full object-cover object-center absolute inset-0 pointer-events-none" 
                            style="transition: transform 0.3s ease;" 
                            loading="lazy"
                            width="200"
                            height="192"
                            decoding="async"
                            onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="w-full h-full flex items-center justify-center bg-gray-100" style="display:none;">
                            <i class="fas fa-cube text-gray-400 text-4xl sm:text-5xl lg:text-6xl"></i>
                        </div>
                    </div>
                
                        <div class="p-5 flex flex-col">
                             <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="<?= htmlspecialchars($product['name']) ?>"><?= htmlspecialchars($product['name']) ?></p>
                           <?php if ($product['discount'] > 0 && strtotime($product['discount_start']) <= time() && strtotime($product['discount_end']) >= time()): ?>
                               <div class="flex items-center gap-2">
                                   <p class="text-2xl font-extrabold text-teal-800">N$<?= number_format($product['price'] * (1 - $product['discount']/100), 2) ?></p>
                                   <p class="text-lg text-gray-500 line-through">N$<?= number_format($product['price'], 2) ?></p>
                               </div>
                           <?php else: ?>
                               <p class="text-2xl font-extrabold text-teal-800">N$<?= number_format($product['price'], 2) ?></p>
                           <?php endif; ?>
                            <p class="text-sm mb-2 hidden <?php 
                                if ($product['quantity'] < 5) {
                                    echo 'text-red-600';
                                } elseif ($product['quantity'] < 10) {
                                    echo 'text-yellow-600';
                                } else {
                                    echo 'text-teal-600';
                                }
                            ?>">Available: <span><?= $product['quantity'] ?></span></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function toggleNotifications() {
        const dropdown = document.getElementById('notificationsDropdown');
        dropdown.classList.toggle('hidden');
        if (!dropdown.classList.contains('hidden')) {
            dropdown.classList.remove('opacity-0', 'scale-95');
            dropdown.classList.add('opacity-100', 'scale-100');
        } else {
            dropdown.classList.add('opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notificationsDropdown');
        const notificationIcon = event.target.closest('svg');
        if (!dropdown.contains(event.target) && !notificationIcon) {
            dropdown.classList.add('hidden', 'opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    });
    </script>


    <script>
        // Add this event listener for keydown on the document
        document.addEventListener('keydown', function(event) {
    const searchBar = document.getElementById('searchBar');
    const cashReceived = document.getElementById('cashReceived');

    // Check if the key pressed is a letter, number, or space
    if (/^[a-zA-Z0-9\s]$/.test(event.key)) {
        // If cashReceived is focused, do not trigger search
        if (document.activeElement === cashReceived) {
            return; // Exit the function, allowing the input to handle the key press
        }

        // Skip if we're editing a quantity (input field with quantity-input class)
        if (document.activeElement.classList && document.activeElement.classList.contains('quantity-input')) {
            return;
        }

        // Focus on the search bar if it's not already focused
        if (document.activeElement !== searchBar) {
            searchBar.focus();
        }
        // Trigger the search
        filterProducts();
    }
});

        function handleBarcodeEntry(event) {
            // If Enter key is pressed in the search bar
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission
                
                // Get the barcode value BEFORE clearing the search bar
                const searchTerm = document.getElementById('searchBar').value.trim();
                if (searchTerm === '') return;
                
                // Look for product with matching barcode - OPTIMIZED DIRECT LOOKUP
                const products = document.querySelectorAll('.product-item[data-barcode="' + searchTerm + '"]');
                
                if (products.length > 0) {
                    // Add product to cart immediately
                    addToCart(products[0]);
                    // Clear search AFTER processing
                    clearSearch();
                } else {
                    // Show a notification that no product was found
                    Swal.fire({
                        icon: 'warning',
                        title: 'Product Not Found',
                        text: `No product found with barcode: ${searchTerm}`,
                        timer: 2000,
                        timerProgressBar: true
                    });
                clearSearch();
                }
            }
        }

        function filterProducts() {
            const searchTerm = document.getElementById('searchBar').value.toLowerCase();
            const clearButton = document.getElementById('clearSearch');

            // Toggle clear button visibility
            if (searchTerm.length > 0) {
                clearButton.classList.remove('opacity-0', 'pointer-events-none');
                clearButton.classList.add('opacity-100', 'pointer-events-auto');
            } else {
                clearButton.classList.add('opacity-0', 'pointer-events-none');
                clearButton.classList.remove('opacity-100', 'pointer-events-auto');
            }

            // Optimize product filtering with debounce
            if (window.filterTimeout) {
                clearTimeout(window.filterTimeout);
            }
            
            window.filterTimeout = setTimeout(() => {
                const products = document.querySelectorAll('.product-item');
            products.forEach(product => {
                const name = product.getAttribute('data-name').toLowerCase();
                    const barcode = product.getAttribute('data-barcode').toLowerCase();
                    product.style.display = (name.includes(searchTerm) || barcode.includes(searchTerm)) ? 'block' : 'none';
            });
            }, 50); // Very short delay for typing but prevents excessive processing
        }

        function clearSearch() {
            document.getElementById('searchBar').value = '';
            filterProducts();
        }

              // Add event listener for backspace key
              document.addEventListener('keydown', function(event) {
            if (event.key === 'Backspace') {
                // Skip if we're editing a quantity (input field with quantity-input class)
                if (document.activeElement.classList && document.activeElement.classList.contains('quantity-input')) {
                    return;
                }
                clearSearch();
            }
        });
    </script>

    <!-- Mobile Cart Overlay -->
    <div id="mobileCartOverlay" class="mobile-cart-overlay lg:hidden" onclick="closeMobileCart()"></div>
    
    <!-- Cart Sidebar -->
    <div id="cart" class="w-full lg:w-96 h-full bg-gray-100 shadow-lg rounded-xl p-4 m-2 border border-gray-300 flex flex-col custom-scrollbar" style="height: calc(100vh - 4rem); overflow-y: auto;">
        
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                <svg class="w-8 h-8 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.4 5M17 13l1.4 5M9 21h6M9 21a2 2 0 11-4 0M15 21a2 2 0 104 0"></path></svg>
                Cart
            </h2>
            <!-- Mobile Cart Toggle Button -->
            <button class="lg:hidden text-gray-500 hover:text-gray-700 transition-colors duration-200 flex items-center gap-2" onclick="toggleMobileCart()">
                <span class="text-sm font-medium">Cart</span>
                <svg id="cartToggleIcon" class="w-5 h-5 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                </svg>
            </button>
            <p class="text-lg font-bold text-gray-900 flex items-center">
                N$<span id="cartTotal" class="text-teal-700 text-3xl">0.00</span>
            </p>


        </div>
        <ul id="cartItems" class="mb-6 space-y-4 border border-gray-300 rounded-lg p-4 text-base flex-grow">
            <!-- Cart items will be added here dynamically -->
        </ul>

        <div class="mt-4">
            <div class="flex items-center space-x-2 mb-4">
                <div class="w-3/5">
                    <label for="cashReceived" class="block mb-2 text-gray-900 text-sm">Cash Received:</label>
                    <input type="number" id="cashReceived" class="p-2 text-sm w-full rounded-lg border border-gray-300 focus:border-blue-500 focus:ring focus:ring-blue-200 shadow-md" step="1" oninput="calculateChange()">
                </div>
                <div class="flex mt-8">
                    <button class="bg-gray-200 text-gray-800 px-3 py-2 rounded-l-lg hover:bg-gray-300 transition-colors duration-200 text-sm font-medium shadow-sm flex items-center justify-center h-10 border-r border-gray-300" onclick="handleCreditPurchase()">
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        Credit
                    </button>
                    <button class="bg-gray-200 text-gray-800 px-3 py-2 rounded-r-lg hover:bg-gray-300 transition-colors duration-200 text-sm font-medium shadow-sm flex items-center justify-center h-10" onclick="handleEWalletPurchase()">
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                        EFT
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap space-x-2 mb-4">
                <button class="bg-gradient-to-r from-blue-600 to-blue-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-blue-700 hover:to-blue-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(5)">N$5</button>
                <button class="bg-gradient-to-r from-teal-600 to-teal-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-teal-700 hover:to-teal-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(10)">N$10</button>
                <button class="bg-gradient-to-r from-red-600 to-red-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-red-700 hover:to-red-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(20)">N$20</button>
                <button class="bg-gradient-to-r from-yellow-600 to-yellow-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-yellow-700 hover:to-yellow-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(30)">N$30</button>
                <button class="bg-gradient-to-r from-purple-600 to-purple-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-purple-700 hover:to-purple-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(50)">N$50</button>
                <button class="bg-gradient-to-r from-gray-600 to-gray-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-gray-700 hover:to-gray-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(100)">N$100</button>
                <button class="bg-gradient-to-r from-teal-600 to-teal-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-teal-700 hover:to-teal-900 transition-colors duration-300 mb-2 text-sm"  onclick="addCash(200)">N$200</button>
                <button class="bg-gradient-to-r from-teal-600 to-teal-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-teal-700 hover:to-teal-900 transition-colors duration-300 mb-2 text-sm" onclick="handleMixedPayment()">Cash/EFT</button>
            </div>
        </div>

        <div class="flex justify-between items-center mb-6">
            <p class="text-lg font-bold text-gray-900 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Change: N$<span id="changeAmount" class="text-teal-700 text-2xl">0.00</span>
            </p>
        </div>

        <div class="mt-4">
            <button class="bg-gradient-to-r from-blue-600 to-blue-800 text-white px-4 py-3 rounded-lg shadow-md hover:from-blue-700 hover:to-blue-900 transition-colors duration-300 flex justify-center items-center text-lg w-full" onclick="checkout()">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h1l1 2h13l1-2h1m-2 0a2 2 0 100-4 2 2 0 000 4zm-1 0H6m-1 0a2 2 0 100-4 2 2 0 000 4zm-1 0H3m0 0v6a2 2 0 002 2h14a2 2 0 002-2v-6"></path></svg>
                Checkout
            </button>
            <input type="submit" class="hidden" id="checkoutSubmit">
        </div>
        <script>
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    const cashReceived = document.getElementById('cashReceived');
                    const cartElement = document.getElementById('cart');
                    const searchBar = document.getElementById('searchBar');
                    
                    // Don't trigger checkout if we're in the search bar (for barcode scanning)
                    if (document.activeElement === searchBar) {
                        return; // Let the handleBarcodeEntry function handle this
                    }
                    
                    // Trigger checkout if:
                    // 1. Cash received input is focused and has a value, OR
                    // 2. Focus is somewhere inside the cart area
                    if ((document.activeElement === cashReceived && cashReceived.value.trim() !== '') || 
                        (cartElement.contains(document.activeElement) || document.activeElement === cartElement)) {
                        event.preventDefault();
                        checkout();
                    }
                }
            });
        </script>

        <button class="mt-4 bg-gradient-to-r from-red-600 to-red-800 text-white px-4 py-3 rounded-lg shadow-md hover:from-red-700 hover:to-red-900 transition-colors duration-300 flex justify-center items-center text-lg w-full" onclick="clearCart()">
            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            Clear Cart
        </button>
        <div class="text-sm text-gray-600 font-mono select-none text-center mt-4">
  </div>
    </div>
    </div>

</main>


<script>
  function toggleFullscreen() {
    const elem = document.documentElement; // The whole document will go fullscreen
    const icon = document.getElementById("fullscreenIcon");

    if (!document.fullscreenElement) {
      elem.requestFullscreen().catch((err) => {
        alert(`Error attempting to enable fullscreen mode: ${err.message}`);
      });
      icon.classList.remove("fa-expand");
      icon.classList.add("fa-compress");
    } else {
      document.exitFullscreen();
      icon.classList.remove("fa-compress");
      icon.classList.add("fa-expand");
    }
  }
</script>

<script>
        // Add this at the beginning of your script
        const sound = new Howl({
            src: ['beep-29.mp3'],
            volume: 0.5
        });

        const cashSound = new Howl({
        src: ['pay.mp3'],
        volume: 0.5
    });

        // Barcode scanner variables
        let barcodeBuffer = '';
        let barcodeTimeout = null;
        const BARCODE_DELAY = 100; // milliseconds

        let cart = [];

        function addToCart(element) {
            const price = parseFloat(element.getAttribute('data-price'));
            const name = element.getAttribute('data-name');
            const barcode = element.getAttribute('data-barcode');
            const discount = parseFloat(element.getAttribute('data-discount') || 0);
            const discountStart = element.getAttribute('data-discount-start');
            const discountEnd = element.getAttribute('data-discount-end');
            
            // Calculate final price based on discount
            let finalPrice = price;
            if (discount > 0 && discountStart && discountEnd) {
                const now = new Date().getTime();
                const start = new Date(discountStart).getTime();
                const end = new Date(discountEnd).getTime();
                if (now >= start && now <= end) {
                    finalPrice = price * (1 - discount/100);
                }
            }
            
            const existingItem = cart.find(item => item.name === name);
            if (existingItem) {
                existingItem.quantity += 1;
                // Recalculate total price based on unit price and new quantity
                existingItem.price = finalPrice * existingItem.quantity;
            } else {
                cart.push({ name, price: finalPrice, quantity: 1, barcode });
            }
            sound.play(); // Play the sound when an item is added
            updateCart();
        }

        function updateCart() {
            const cartItems = document.getElementById('cartItems');
            const cartTotal = document.getElementById('cartTotal');
            const mobileCartCounter = document.getElementById('mobileCartCounter');
            cartItems.innerHTML = '';
            let total = 0;
            let totalItems = 0;

            cart.forEach((item, index) => {
                const li = document.createElement('li');
                li.className = 'relative flex justify-between items-center p-4 mb-2 bg-white rounded-lg shadow-md border border-gray-200';
                
                // Get the product element to check for discount
                const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                const discount = parseFloat(productElement?.getAttribute('data-discount') || 0);
                const discountStart = productElement?.getAttribute('data-discount-start');
                const discountEnd = productElement?.getAttribute('data-discount-end');
                
                let priceDisplay = '';
                if (discount > 0 && discountStart && discountEnd) {
                    const now = new Date().getTime();
                    const start = new Date(discountStart).getTime();
                    const end = new Date(discountEnd).getTime();
                    if (now >= start && now <= end) {
                        priceDisplay = `<span class="text-teal-700 font-bold">N$${item.price.toFixed(2)}</span>`;
                    } else {
                        priceDisplay = `<span class="text-teal-700 font-bold">N$${item.price.toFixed(2)}</span>`;
                    }
                } else {
                    priceDisplay = `<span class="text-teal-700 font-bold">N$${item.price.toFixed(2)}</span>`;
                }

                li.innerHTML = `
                    <div class="flex items-center gap-2">
                        <span class="text-gray-900 font-medium">${item.name} <span id="quantity-${index}" class="bg-blue-200 text-blue-800 px-2 py-1 rounded cursor-pointer hover:bg-blue-300 transition-colors" title="Click to edit quantity" onclick="editQuantity(${index})">x${item.quantity}</span></span>
                    </div>
                    ${priceDisplay}
                    <span class="absolute top-0 right-0 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-full w-5 h-5 flex items-center justify-center shadow-lg cursor-pointer" onclick="removeFromCart(${index})" style="margin: -5px -5px;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </span>
                `;
                cartItems.appendChild(li);
                total += item.price;
                totalItems += item.quantity;
            });

            cartTotal.innerText = total.toFixed(2);
            
            // Update mobile cart counter
            if (totalItems > 0) {
                mobileCartCounter.textContent = totalItems;
                mobileCartCounter.classList.remove('hidden');
            } else {
                mobileCartCounter.classList.add('hidden');
            }
            
            calculateChange();
        }

        function editQuantity(index) {
            const quantitySpan = document.getElementById(`quantity-${index}`);
            
            // Check if already editing to prevent multiple triggers
            if (quantitySpan.tagName === 'INPUT') {
                return;
            }
            
            const currentQuantity = cart[index].quantity;
            
            // Get the original unit price from the product element
            const productElement = document.querySelector(`.product-item[data-name="${cart[index].name}"]`);
            let unitPrice = cart[index].price / currentQuantity; // Fallback calculation
            
            if (productElement) {
                const price = parseFloat(productElement.getAttribute('data-price'));
                const discount = parseFloat(productElement.getAttribute('data-discount') || 0);
                const discountStart = productElement.getAttribute('data-discount-start');
                const discountEnd = productElement.getAttribute('data-discount-end');
                
                // Calculate unit price with discount if applicable
                if (discount > 0 && discountStart && discountEnd) {
                    const now = new Date().getTime();
                    const start = new Date(discountStart).getTime();
                    const end = new Date(discountEnd).getTime();
                    if (now >= start && now <= end) {
                        unitPrice = price * (1 - discount/100);
                    } else {
                        unitPrice = price;
                    }
                } else {
                    unitPrice = price;
                }
            }
            
            // Create input field
            const input = document.createElement('input');
            input.type = 'number';
            input.value = currentQuantity;
            input.min = '1';
            input.className = 'w-16 px-2 py-1 text-center border border-blue-300 rounded text-sm quantity-input';
            input.style.backgroundColor = '#dbeafe';
            input.style.color = '#1e40af';
            
            // Replace span with input
            quantitySpan.parentNode.replaceChild(input, quantitySpan);
            
            // Focus and select the input
            input.focus();
            input.select();
            
            // Handle input completion
            function finishEditing() {
                const newQuantity = parseInt(input.value);
                
                if (newQuantity && newQuantity > 0) {
                    // Check available stock
                    const productElement = document.querySelector(`.product-item[data-name="${cart[index].name}"]`);
                    if (productElement) {
                        const quantityElement = productElement.querySelector('p:last-child');
                        const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                        
                        // Add current cart quantity back to available stock for comparison
                        const totalAvailable = availableQuantity + currentQuantity;
                        
                        if (newQuantity > totalAvailable) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Insufficient Stock',
                                text: `Only ${totalAvailable} units available for ${cart[index].name}`,
                                timer: 3000,
                                timerProgressBar: true
                            });
                            // Restore original quantity
                            cart[index].quantity = currentQuantity;
                        } else {
                            // Update cart with new quantity and recalculate price
                            cart[index].quantity = newQuantity;
                            cart[index].price = unitPrice * newQuantity;
                            sound.play();
                        }
                    } else {
                        // Update cart with new quantity if product element not found
                        cart[index].quantity = newQuantity;
                        cart[index].price = unitPrice * newQuantity;
                        sound.play();
                    }
                } else {
                    // Invalid quantity, restore original
                    cart[index].quantity = currentQuantity;
                }
                
                // Refresh the cart display
                updateCart();
            }
            
            // Handle Enter key and blur events
            input.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    finishEditing();
                }
                if (event.key === 'Escape') {
                    // Cancel editing, restore original quantity
                    cart[index].quantity = currentQuantity;
                    updateCart();
                }
            });
            
            input.addEventListener('blur', finishEditing);
        }

        function addCash(amount) {
        const cashReceived = document.getElementById('cashReceived');
        cashReceived.value = (parseFloat(cashReceived.value) || 0) + amount;
        calculateChange();
        sound.play(); // Play the cash sound when adding cash
    }


    function removeFromCart(index) {
            cart.splice(index, 1);
            sound.play(); // Play sound when removing item
            updateCart();
        }

        function calculateChange() {
            const cartTotal = parseFloat(document.getElementById('cartTotal').innerText) || 0; // Ensure cartTotal is a number
            const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
            const change = cashReceived - cartTotal;
            document.getElementById('changeAmount').innerText = change >= 0 ? change.toFixed(2) : '0.00';
        }

        function handleEWalletPurchase() {
            // First check if cart is empty
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Empty Cart',
                    text: 'Please add items to cart before processing e-wallet payment',
                    allowOutsideClick: false,
                });
                return;
            }

            // Check for out-of-stock items
            const outOfStockItems = cart.filter(item => {
                const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                if (productElement) {
                    const quantityElement = productElement.querySelector('p:last-child');
                    const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                    return availableQuantity < item.quantity;
                }
                return false;
            });

            if (outOfStockItems.length > 0) {
                const itemNames = outOfStockItems.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                });
                return;
            }

            // Show e-wallet payment modal
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">E-wallet Payment</h1>',
                html: `
                    <div class="space-y-4">
                                 <select id="walletProvider" 
                                class="w-full px-4 py-2 border-2 border-teal-100 rounded-xl 
                                       focus:border-teal-500 focus:ring-2 focus:ring-teal-200 
                                       text-base font-medium shadow-sm transition-all duration-200
                                       bg-teal-50 hover:bg-teal-100">
                            <option value="Credit Card" selected>Credit Card(Swipe)</option>
                            <option value="E-wallet">E-wallet</option>
                            <option value="Easy Wallet">Easy Wallet</option>
                            <option value="Pay2Cell">Pay2Cell</option>
                            <option value="Blue Wallet">Blue Wallet</option>
                            <option value="Ned Wallet">Ned Wallet</option>
                        </select>
                        <input type="text" 
                               id="transactionRef" 
                               class="w-full px-4 py-2 border-2 border-teal-100 rounded-xl 
                                      focus:border-teal-500 focus:ring-2 focus:ring-teal-200 
                                      text-base font-medium shadow-sm transition-all duration-200
                                      bg-teal-50 hover:bg-teal-100"
                               placeholder="Enter transaction reference (optional)">
                        
                        <label class="block text-sm font-medium text-gray-700 mb-2 mt-4">E-wallet Provider:</label>
     
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Confirm Payment <i class="fas fa-check-circle ml-2"></i>',
                confirmButtonClass: 'swal2-confirm-btn bg-teal-600 hover:bg-teal-700 text-white px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const transactionRef = document.getElementById('transactionRef').value;
                    const walletProvider = document.getElementById('walletProvider').value;
                    return { transactionRef, walletProvider }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const saleData = {
                        transaction_ref: result.value.transactionRef,
                        wallet_provider: result.value.walletProvider,
                        items: cart,
                        total: parseFloat(document.getElementById('cartTotal').innerText),
                        payment_method: 'e-wallet',
                        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
                    };

                    // Process the e-wallet payment
                    fetch('process_order.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(saleData),
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            // Add order_id to the data being sent to receipt.php
                            saleData.order_id = result.order_id;
                            
                            // Show success message
                            cashSound.play();
                            Swal.fire({
                                icon: 'success',
                                title: 'EFT Payment Successful!',
                                html: '',
                                footer: `
                                    <div style="display: flex; align-items: center; justify-content: center; width: 100%;">
                                        <div style="display: flex; align-items: center; padding-right: 0;">
                                            <a href='reverse_transaction.php' onclick='reverseTransaction()' style='display: flex; align-items: center; color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em;'><i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction</a>
                                        </div>
                                        <div style="height: 32px; width: 1px; background: #e5e7eb; margin: 0 18px 0 18px; position: relative;"></div>
                                        <div style="display: flex; align-items: center; padding-left: 18px;">
                                            <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;'>
                                            <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                                        </div>
                                    </div>
                                `,
                                allowOutsideClick: false,
                                focusConfirm: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    // Only print receipt if checkbox is checked
                                    const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                                    if (printReceipt) {
                                        // Print receipt only (no drawer for EFT payments)
                                        saleData.print_only = true;
                                        fetch('receipt.php', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                            },
                                            body: JSON.stringify(saleData),
                                        })
                                        .then(printResponse => printResponse.json())
                                        .then(printResult => {
                                            console.log('Receipt printed successfully');
                                        })
                                        .catch(printError => {
                                            console.error('Receipt printing error:', printError);
                                        });
                                    }
                                    // Clear cart and refresh quantities
                                    clearCart();
                                    refreshProductQuantities();
                                    // Close mobile cart if open
                                    closeMobileCart();
                                }
                            });
                        } else {
                            Swal.fire('Error', result.message || 'Failed to process payment', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire('Error', 'Could not process e-wallet payment', 'error');
                    });
                }
            });
        }

        function handleMixedPayment() {
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Empty Cart',
                    text: 'Please add items to cart before processing mixed payment',
                    allowOutsideClick: false,
                });
                return;
            }

            const outOfStockItems = cart.filter(item => {
                const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                if (productElement) {
                    const quantityElement = productElement.querySelector('p:last-child');
                    const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                    return availableQuantity < item.quantity;
                }
                return false;
            });

            if (outOfStockItems.length > 0) {
                const itemNames = outOfStockItems.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                });
                return;
            }

            const total = parseFloat(document.getElementById('cartTotal').innerText) || 0;

            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">Cash + EFT</h1>',
                html: `
                    <div class="space-y-3 text-left">
                        <div class="text-sm text-gray-700">Total: <span class="font-bold">N$${total.toFixed(2)}</span></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Cash Amount</label>
                                <input type="number" id="mixedCash" min="0" step="0.01" class="w-full px-3 py-2 border-2 border-teal-100 rounded-lg focus:border-teal-500 focus:ring-2 focus:ring-teal-200" placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">EFT Amount</label>
                                <input type="number" id="mixedEft" min="0" step="0.01" class="w-full px-3 py-2 border-2 border-teal-100 rounded-lg focus:border-teal-500 focus:ring-2 focus:ring-teal-200" placeholder="0.00">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Provider</label>
                            <select id="mixedProvider" class="w-full px-3 py-2 border-2 border-teal-100 rounded-lg focus:border-teal-500 focus:ring-2 focus:ring-teal-200">
                                <option value="Credit Card" selected>Credit Card (Swipe)</option>
                                <option value="E-wallet">E-wallet</option>
                                <option value="Easy Wallet">Easy Wallet</option>
                                <option value="Pay2Cell">Pay2Cell</option>
                                <option value="Blue Wallet">Blue Wallet</option>
                                <option value="Ned Wallet">Ned Wallet</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Ref (optional)</label>
                            <input type="text" id="mixedRef" class="w-full px-3 py-2 border-2 border-teal-100 rounded-lg focus:border-teal-500 focus:ring-2 focus:ring-teal-200" placeholder="Reference">
                        </div>
                        <div class="text-xs text-gray-500">Cash + EFT must equal total.</div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Confirm Payment',
                customClass: { popup: 'rounded-2xl shadow-xl' },
                focusConfirm: false,
                preConfirm: () => {
                    const cashAmount = parseFloat(document.getElementById('mixedCash').value) || 0;
                    const eftAmount = parseFloat(document.getElementById('mixedEft').value) || 0;
                    if ((cashAmount + eftAmount).toFixed(2) !== total.toFixed(2)) {
                        Swal.showValidationMessage('Cash + EFT must equal total');
                        return false;
                    }
                    return {
                        cashAmount,
                        eftAmount,
                        provider: document.getElementById('mixedProvider').value,
                        ref: document.getElementById('mixedRef').value
                    };
                }
            }).then(result => {
                if (!result.isConfirmed) return;
                const { cashAmount, eftAmount, provider, ref } = result.value;

                const saleData = {
                    items: cart,
                    total: total,
                    payment_method: 'mixed',
                    cash_amount: cashAmount,
                    eft_amount: eftAmount,
                    wallet_provider: provider,
                    transaction_ref: ref,
                    cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
                };

                fetch('process_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(saleData)
                })
                .then(r => r.json())
                .then(result => {
                    if (result.success) {
                        saleData.order_id = result.order_id;
                        
                        // Open cash drawer for mixed payment (cash portion)
                        if (cashAmount > 0) {
                            openCashDrawer();
                        }
                        
                        cashSound.play();
                        Swal.fire({
                            icon: 'success',
                            title: 'Payment Successful',
                            footer: `
                                <div style="display: flex; align-items: center; justify-content: center; width: 100%;">
                                    <div style="display: flex; align-items: center; padding-right: 0;">
                                        <a href='reverse_transaction.php' onclick='reverseTransaction()' style='display: flex; align-items: center; color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em;'><i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction</a>
                                    </div>
                                    <div style="height: 32px; width: 1px; background: #e5e7eb; margin: 0 18px 0 18px; position: relative;"></div>
                                    <div style="display: flex; align-items: center; padding-left: 18px;">
                                        <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;'>
                                        <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                                    </div>
                                </div>
                            `,
                            allowOutsideClick: false,
                            focusConfirm: false
                        }).then(res => {
                            if (res.isConfirmed) {
                                const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                                
                                if (printReceipt) {
                                    // Print receipt only (drawer already opened above)
                                    saleData.print_only = true;
                                    fetch('receipt.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify(saleData)
                                    }).catch(err => console.error('Receipt printing error:', err));
                                }
                                clearCart();
                                refreshProductQuantities();
                                // Close mobile cart if open
                                closeMobileCart();
                            }
                        });
                    } else {
                        Swal.fire('Error', result.message || 'Failed to process mixed payment', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire('Error', 'Could not process mixed payment', 'error');
                });
            });
        }

        function handleCreditPurchase() {
            // First check if cart is empty
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Empty Cart',
                    text: 'Please add items to cart before processing credit purchase',
                    allowOutsideClick: false,
                });
                return;
            }

            // New out-of-stock check
            const outOfStockItems = cart.filter(item => {
                const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                if (productElement) {
                    const quantityElement = productElement.querySelector('p:last-child');
                    const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                    return availableQuantity < item.quantity;
                }
                return false;
            });

            if (outOfStockItems.length > 0) {
                const itemNames = outOfStockItems.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                }).then(() => {
                    // Reset processing state when alert is closed
                    isProcessing = false;
                    checkoutBtn.innerHTML = originalText;
                    checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                });
                return;
            }

            // Show creditor selection modal
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">1. Choose Creditor</h1>',
                html: `
                    <div class="space-y-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select from active creditors:</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <select id="creditorSelect" 
                                    class="w-full px-4 py-2 border-2 border-teal-100 rounded-xl 
                                           focus:border-teal-500 focus:ring-2 focus:ring-teal-200 
                                           text-base font-medium shadow-sm transition-all duration-200
                                           bg-teal-50 hover:bg-teal-100">
                                <?php foreach ($creditors as $creditor): ?>
                                    <option value="<?= $creditor['id'] ?>" class="text-gray-700">
                                        <?= htmlspecialchars($creditor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <a href="credit-book.php" title="Add Account" style="margin-left: 4px; color: #a1a1a1; font-size: 1.6em; display: flex; align-items: center;">
                                <i class="fas fa-plus-circle"></i>
                            </a>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Next <i class="fas fa-arrow-right ml-2"></i>',
                confirmButtonClass: 'swal2-confirm-btn bg-teal-600 hover:bg-teal-700 text-white px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const creditorId = document.getElementById('creditorSelect').value;
                    if (!creditorId) {
                        Swal.showValidationMessage('<span class="text-red-500">Please select a creditor to continue</span>');
                    }
                    return { creditorId }
                }
            }).then((firstResult) => {
                if (firstResult.isConfirmed) {
                    // Show due date input modal
                    Swal.fire({
                        title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">2. Set Due Date</h1>',
                        html: `
                            <div class="space-y-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Payment deadline:</label>
                                <input type="date" 
                                       id="dueDate" 
                                       min="${new Date().toISOString().split('T')[0]}"
                                       class="w-full px-4 py-2 border-2 border-teal-100 rounded-xl 
                                              focus:border-teal-500 focus:ring-2 focus:ring-teal-200 
                                              text-base font-medium shadow-sm transition-all duration-200
                                              bg-teal-50 hover:bg-teal-100">
                            </div>
                        `,
                        focusConfirm: false,
                        showCancelButton: true,
                        reverseButtons: true,
                        confirmButtonText: 'Confirm Sale <i class="fas fa-check-circle ml-2"></i>',
                        confirmButtonClass: 'swal2-confirm-btn bg-teal-600 hover:bg-teal-700 text-white px-6 py-2 rounded-lg',
                        cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                        customClass: {
                            popup: 'rounded-2xl shadow-xl',
                        },
                        allowOutsideClick: false,
                        preConfirm: () => {
                            const dueDate = document.getElementById('dueDate').value;
                            if (!dueDate) {
                                Swal.showValidationMessage('<span class="text-red-500">A valid due date is required</span>');
                            }
                            return { dueDate }
                        }
                    }).then((secondResult) => {
                        if (secondResult.isConfirmed) {
                            const saleData = {
                                creditor_id: firstResult.value.creditorId,
                                due_date: secondResult.value.dueDate,
                                items: cart,
                                total: parseFloat(document.getElementById('cartTotal').innerText),
                                cash_received: 0,
                                cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
                            };

                            fetch('process_credit.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify(saleData),
                            })
                            .then(response => response.json())
                            .then(result => {
                                if (result.success) {
                                    // Add sale_id to the data being sent to receipt.php
                                    saleData.sale_id = result.sale_id;
                                    // Add creditor name to the data
                                    saleData.creditor_name = result.creditor_name;
                                    
                                    // Open cash drawer for credit sales (they don't involve cash, so no drawer opening)
                                    
                                    // Show success message first
                                    cashSound.play();
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Credit Sale Recorded!',
                                        html: '',
                                        footer: `
                                            <div style="display: flex; align-items: center; justify-content: center; width: 100%;">
                                                <div style="display: flex; align-items: center; padding-right: 0;">
                                                    <a href='reverse_transaction.php' onclick='reverseTransaction()' style='display: flex; align-items: center; color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em;'><i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction</a>
                                                </div>
                                                <div style="height: 32px; width: 1px; background: #e5e7eb; margin: 0 18px 0 18px; position: relative;"></div>
                                                <div style="display: flex; align-items: center; padding-left: 18px;">
                                                    <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;'>
                                                    <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                                                </div>
                                            </div>
                                        `,
                                        allowOutsideClick: false,
                                        focusConfirm: false
                                    }).then((result) => {
                                        if (result.isConfirmed) {
                                            // Only print receipt if checkbox is checked
                                            const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                                            if (printReceipt) {
                                                // Print receipt only (no drawer for credit payments)
                                                saleData.print_only = true;
                                                fetch('receipt.php', {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/json',
                                                    },
                                                    body: JSON.stringify(saleData),
                                                })
                                                .then(printResponse => printResponse.json())
                                                .then(printResult => {
                                                    console.log('Receipt printed successfully');
                                                })
                                                .catch(printError => {
                                                    console.error('Receipt printing error:', printError);
                                                });
                                            }
                                            // Clear cart and refresh quantities
                                            clearCart();
                                            refreshProductQuantities();
                                            // Close mobile cart if open
                                            closeMobileCart();
                                        }
                                    });
                                } else {
                                    Swal.fire('Error', result.message, 'error');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                Swal.fire('Error', 'Could not process credit sale', 'error');
                            });
                        }
                    });
                }
            });
        }

        let isProcessing = false;

        // Function to open cash drawer only (no receipt printing)
        function openCashDrawer() {
            const drawerData = {
                items: cart,
                total: parseFloat(document.getElementById('cartTotal').innerText),
                cash_received: parseFloat(document.getElementById('cashReceived').value),
                cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
                open_drawer_only: true
            };

            console.log('Opening cash drawer with data:', drawerData);

            fetch('receipt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(drawerData),
            })
            .then(res => res.json())
            .then(result => {
                console.log('Cash drawer response:', result);
                if (result.success) {
                    console.log('Cash drawer opened successfully');
                } else {
                    console.error('Cash drawer failed:', result.message);
                }
            })
            .catch(err => console.error('Drawer opening error:', err));
        }

        function checkout() {
    if (isProcessing) return;
    isProcessing = true;

    const checkoutBtn = document.querySelector('button[onclick="checkout()"]');
    const originalText = checkoutBtn.innerHTML;
    checkoutBtn.innerHTML = `
        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h1l1 2h13l1-2h1m-2 0a2 2 0 100-4 2 2 0 000 4zm-1 0H6m-1 0a2 2 0 100-4 2 2 0 000 4zm-1 0H3m0 0v6a2 2 0 002 2h14a2 2 0 002-2v-6"></path></svg>
        Checkout`;
    checkoutBtn.classList.add('opacity-50', 'cursor-not-allowed');

    const cartTotal = parseFloat(document.getElementById('cartTotal').innerText);
    const cashInput = document.getElementById('cashReceived');
    const cashReceived = parseFloat(cashInput.value);

    // Validate cash input
    if (isNaN(cashReceived)) {
        Swal.fire({
            icon: 'error',
            title: 'Missing Cash Amount',
            text: 'Please enter a valid cash amount.',
            allowOutsideClick: false,
        }).then(() => {
            isProcessing = false;
            checkoutBtn.innerHTML = originalText;
            checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        return;
    }

    const change = cashReceived - cartTotal;

    const outOfStockItems = cart.filter(item => {
        const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
        if (productElement) {
            const quantityElement = productElement.querySelector('p:last-child');
            const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
            return availableQuantity < item.quantity;
        }
        return false;
    });

    if (outOfStockItems.length > 0) {
        const itemNames = outOfStockItems.map(item => item.name).join(', ');
        Swal.fire({
            icon: 'error',
            title: 'Out of Stock',
            text: `Insufficient stock for: ${itemNames}`,
            allowOutsideClick: false,
        }).then(() => {
            isProcessing = false;
            checkoutBtn.innerHTML = originalText;
            checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        return;
    }

    if (cashReceived < cartTotal) {
        Swal.fire({
            icon: 'error',
            title: 'Insufficient Cash',
            text: 'The cash received is less than the total amount.',
            allowOutsideClick: false,
        }).then(() => {
            isProcessing = false;
            checkoutBtn.innerHTML = originalText;
            checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        return;
    }

    if (cart.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Empty Cart',
            text: 'Please add items to the cart before checking out.',
            allowOutsideClick: false,
        }).then(() => {
            isProcessing = false;
            checkoutBtn.innerHTML = originalText;
            checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        return;
    }

    const data = {
        items: cart,
        total: cartTotal,
        cash_received: cashReceived,
        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
    };

    fetch('process_order.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            data.order_id = result.order_id;

            // Always open cash drawer for cash payments immediately
            openCashDrawer();

            Swal.fire({
                icon: 'success',
                title: `Change: N$${change.toFixed(2)}`,
                confirmButtonText: 'OK',
                footer: `
                    <div style="display: flex; justify-content: center; align-items: center;">
                        <a href='reverse_transaction.php' onclick='reverseTransaction()' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                            <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                        </a>
                        <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;'>
                        <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                    </div>
                `,
                allowOutsideClick: false,
                focusConfirm: false
            }).then(result => {
                if (result.isConfirmed) {
                    const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                    
                    if (printReceipt) {
                        // Also print receipt if requested (print only, no drawer)
                        data.print_only = true;
                        fetch('receipt.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(data),
                        })
                        .then(res => res.json())
                        .then(() => console.log('Receipt printed.'))
                        .catch(err => console.error('Receipt printing error:', err));
                    }

                    clearCart();
                    refreshProductQuantities();
                    // Close mobile cart if open
                    closeMobileCart();
                }
            });

            cashSound.play();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Order Failed',
                text: result.message || 'Please enter a valid cash amount.',
                allowOutsideClick: false,
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'There was an error processing your order.',
            allowOutsideClick: false,
        });
    })
    .finally(() => {
        isProcessing = false;
        checkoutBtn.innerHTML = originalText;
        checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    });
}

        function refreshProductQuantities() {
        fetch('get_product_quantities.php')
            .then(response => response.json())
            .then(data => {
                data.forEach(product => {
                    const productElement = document.querySelector(`.product-item[data-name="${product.name}"]`);
                    if (productElement) {
                        // Update quantity (hidden)
                        const quantityElement = productElement.querySelector('p:last-child');
                        quantityElement.textContent = `Available: ${product.quantity}`;
                        quantityElement.className = `text-sm hidden ${
                            product.quantity < 5 ? 'text-red-600' :
                            product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'
                        }`;

                        // Update price display with discount if applicable
                        const priceContainer = productElement.querySelector('.p-5');
                        if (priceContainer) {
                            const discount = parseFloat(product.discount) || 0;
                            const discountStart = product.discount_start;
                            const discountEnd = product.discount_end;
                            const price = parseFloat(product.price);

                            if (discount > 0 && discountStart && discountEnd) {
                                const now = new Date().getTime();
                                const start = new Date(discountStart).getTime();
                                const end = new Date(discountEnd).getTime();
                                if (now >= start && now <= end) {
                                    const discountedPrice = price * (1 - discount/100);
                                    priceContainer.innerHTML = `
                                        <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="${product.name}">${product.name}</p>
                                        <div class="flex items-center gap-2">
                                            <p class="text-2xl font-extrabold text-teal-800">N$${discountedPrice.toFixed(2)}</p>
                                            <p class="text-lg text-gray-500 line-through">N$${price.toFixed(2)}</p>
                                        </div>
                                        <p class="text-sm mb-2 hidden ${product.quantity < 5 ? 'text-red-600' : product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'}">Available: ${product.quantity}</p>
                                    `;
                                } else {
                                    priceContainer.innerHTML = `
                                        <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="${product.name}">${product.name}</p>
                                        <p class="text-2xl font-extrabold text-teal-800">N$${price.toFixed(2)}</p>
                                        <p class="text-sm mb-2 hidden ${product.quantity < 5 ? 'text-red-600' : product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'}">Available: ${product.quantity}</p>
                                    `;
                                }
                            } else {
                                priceContainer.innerHTML = `
                                    <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="${product.name}">${product.name}</p>
                                    <p class="text-2xl font-extrabold text-teal-800">N$${price.toFixed(2)}</p>
                                    <p class="text-sm mb-2 hidden ${product.quantity < 5 ? 'text-red-600' : product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'}">Available: ${product.quantity}</p>
                                `;
                            }
                        }
                    }
                });
            })
            .catch(error => console.error('Error refreshing product quantities:', error));
    }

    // Refresh product quantities every 30 seconds
    setInterval(refreshProductQuantities, 30000);

    // Initial refresh when the page loads
    document.addEventListener('DOMContentLoaded', refreshProductQuantities);

        function clearCart() {
            cart = [];
            updateCart();
            document.getElementById('cashReceived').value = '';
            document.getElementById('changeAmount').innerText = '0.00';
        }
        
        // Mobile cart functions
        function toggleMobileCart() {
            const cart = document.getElementById('cart');
            const overlay = document.getElementById('mobileCartOverlay');
            const toggleIcon = document.getElementById('cartToggleIcon');
            
            cart.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
            
            // Rotate the arrow icon
            if (cart.classList.contains('mobile-open')) {
                toggleIcon.style.transform = 'rotate(180deg)';
            } else {
                toggleIcon.style.transform = 'rotate(0deg)';
            }
        }
        
        function closeMobileCart() {
            const cart = document.getElementById('cart');
            const overlay = document.getElementById('mobileCartOverlay');
            const toggleIcon = document.getElementById('cartToggleIcon');
            
            cart.classList.remove('mobile-open');
            overlay.classList.remove('active');
            toggleIcon.style.transform = 'rotate(0deg)';
        }
        
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
    <script>
        // Add any JavaScript for responsive behavior here
    </script>
    <script src="sweetalert2@11.js"></script>


    <script>
        function initializeChart() {
            // Prepare data for the chart
            var productNames = <?php echo json_encode(array_column($topSellingProducts, 'product_name')); ?>;
            var productQuantities = <?php echo json_encode(array_column($topSellingProducts, 'total_quantity')); ?>;

            // Create the pie chart
            var ctx = document.getElementById('topProductsChart').getContext('2d');
            var topProductsChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: productNames,
                    datasets: [{
                        data: productQuantities,
                        backgroundColor: [
                            '#FF6384',
                            '#36A2EB',
                            '#FFCE56',
                            '#4BC0C0',
                            '#9966FF'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    title: {
                        display: true,
                        text: 'Top Selling Products'
                    },
                    animation: {
                        duration: 3000 // Set animation duration to 3 seconds
                    }
                }
            });
        }

        function initializePageScripts() {
            setTimeout(function() {
                if (typeof Chart !== 'undefined') {
                    initializeChart();
                } else {
                    console.error('Chart.js is not loaded');
                }
            }, 300); // Reduced delay from 1000ms to 300ms for faster initialization
        }

        // Call initializePageScripts when the page loads
        document.addEventListener('DOMContentLoaded', initializePageScripts);

        // Add event listener for barcode scanner input to process immediately
        document.addEventListener('keydown', function(event) {
            // Skip if we're in an input field that's not the search bar
            if (document.activeElement.tagName === 'INPUT' && document.activeElement.id !== 'searchBar') {
                return;
            }
            
            // Focus search bar for any alphanumeric input when not already focused
            if (/^[a-zA-Z0-9]$/.test(event.key) && document.activeElement.id !== 'searchBar') {
                document.getElementById('searchBar').focus();
                // Clear any existing search to start fresh
                document.getElementById('searchBar').value = '';
            }
            
            // Handle rapid barcode input
            if (/^[a-zA-Z0-9]$/.test(event.key)) {
                // Reset timeout on each keypress
                if (barcodeTimeout) clearTimeout(barcodeTimeout);
                
                // Add character to buffer
                barcodeBuffer += event.key;
                
                // Set timeout to process barcode
                barcodeTimeout = setTimeout(() => {
                    // If buffer has content and input was fast (like a scanner)
                    if (barcodeBuffer.length > 5) {
                        // Look for product with this barcode
                        const product = document.querySelector(`.product-item[data-barcode="${barcodeBuffer}"]`);
                        if (product) {
                            addToCart(product);
                            sound.play();
                            document.getElementById('searchBar').value = '';
                        }
                    }
                    barcodeBuffer = ''; // Clear buffer after processing
                }, BARCODE_DELAY);
            } else if (event.key === 'Enter') {
                // Process Enter key immediately for barcode scanners that send Enter
                if (barcodeBuffer.length > 5) {
                    const product = document.querySelector(`.product-item[data-barcode="${barcodeBuffer}"]`);
                    if (product) {
                        addToCart(product);
                        sound.play();
                        document.getElementById('searchBar').value = '';
                        event.preventDefault(); // Prevent form submission
                    }
                    barcodeBuffer = ''; // Clear buffer
                    if (barcodeTimeout) clearTimeout(barcodeTimeout);
                }
            }
        });
    </script>

    <!-- Prevent double-clicking the checkout button -->
    <script>
        document.getElementById('checkoutBtn').addEventListener('click', function(e) {
            // Check if button is already disabled
            if (this.disabled) {
                e.preventDefault();
                return false;
            }
            
            // Disable the button
            this.disabled = true;
            this.classList.add('processing');
            
            // Visual feedback (optional)
            const originalText = this.innerHTML;
            this.innerHTML = `
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h1l1 2h13l1-2h1m-2 0a2 2 0 100-4 2 2 0 000 4zm-1 0H6m-1 0a2 2 0 100-4 2 2 0 000 4zm-1 0H3m0 0v6a2 2 0 002 2h14a2 2 0 002-2v-6"></path></svg>
                Checkout`;
            
            // Re-enable after 3 seconds (adjust time as needed)
            setTimeout(() => {
                this.disabled = false;
                this.classList.remove('processing');
                this.innerHTML = originalText;
            }, 3000);
        });
    </script>
</body>
</html>
