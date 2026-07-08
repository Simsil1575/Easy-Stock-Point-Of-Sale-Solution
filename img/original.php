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
$settingStmt = $db->query("SELECT show_all_products, default_print_receipt, hide_available_quantity FROM product_settings LIMIT 1");
$setting = $settingStmt->fetch(PDO::FETCH_ASSOC);
$show_all_products = $setting['show_all_products'] ?? 0; // Default to 0 if not set
$default_print_receipt = $setting['default_print_receipt'] ?? 0; // Default to 0 if not set
$hide_available_quantity = $setting['hide_available_quantity'] ?? 0; // Default to 0 if not set

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

// Fetch unique categories from products
$categoriesQuery = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category";
$categoriesStmt = $db->query($categoriesQuery);
$categories = [];
while ($catRow = $categoriesStmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[] = $catRow['category'];
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="msapplication-TileColor" content="#ffffff">
    <!-- Force white bottom navigation on Android devices -->
    <meta name="theme-color" content="#ffffff" media="(max-width: 767px)">
    <title>POS Solution</title>
    <link href="src/output.css" rel="stylesheet">
    <script src="../receipt.php?js=true"></script>
    <script src="navigation.js" async></script>
    <script src="src/howler.min.js"></script>
    <script src="src/chart.js"></script>
    <script src="lucide.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <!-- Enforce white color on Android bottom navigation bar -->
    <meta name="color-scheme" content="light only">
    <!-- For Chrome on Android to ensure white navbar -->
    <meta name="theme-color" content="#ffffff" id="android-white-nav">
    <meta name="nav-button-color" content="white">
    <meta name="navigation-bar-color" content="#ffffff">

    <style>

    * {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
    
    /* Global container max-width constraints */
    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .container {
        max-width: 100% !important;
    }
    
    .content {
        max-width: 100%;
    }
    
    main {
        max-width: 100%;
    }
    
    #productGrid {
        max-width: 100%;
    }

        /* Product card image container - consistent 1:1 ratio */
    .product-image-container {
        position: relative;
        width: 100%;
        padding-bottom: 100%; /* 1:1 aspect ratio */
        height: 0 !important;
    }
    
    .product-image-container img,
    .product-image-container > div {
        position: absolute !important;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
    }
    
    /* Override h-60 class for product images */
    .product-item .h-60 {
        height: 0 !important;
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
    
    /* Mobile responsive adjustments - Phones and small devices */
    @media (max-width: 768px) {
        .content {
            margin-left: 0 !important;
            width: 100%;
            overflow-x: hidden;
        }
        
        main {
            padding: 0.75rem;
            padding-bottom: 1rem;
            width: 100%;
            overflow-x: hidden;
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
        
        /* Mobile header row - prevent overlap and wrapping */
        .mb-6.flex.items-center.gap-4.flex-wrap {
            margin-bottom: 1rem;
            gap: 0.5rem;
            width: 100%;
            max-width: 100%;
        }
        
        /* Mobile product grid - maintain aspect ratio and image sizes */
        .product-item {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: auto;
            aspect-ratio: unset;
        }
        
        #productGrid {
            gap: 0.75rem !important;
            padding-right: 0.25rem;
            max-height: calc(100vh - 13rem);
        }
        
        /* Consistent image container - same ratio as desktop */
        .product-item .w-full.h-60 {
            width: 100%;
            height: 0;
            padding-bottom: 100%; /* 1:1 ratio */
            position: relative;
            flex-shrink: 0;
            overflow: hidden;
        }
        
        .product-item .w-full.h-60 img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        
        .product-item .w-full.h-60 .flex.items-center.justify-center {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        
        /* Product info section */
        .product-item .p-5 {
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-height: auto;
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
        
        .product-item .p-5 p:not(:first-child) {
            margin-bottom: 0;
            font-size: 0.875rem;
        }
        
        /* Center product grid on mobile */
        #productGrid {
            justify-items: stretch;
        }
    }
    
    /* Tablets specific (769px - 1023px) */
    @media (min-width: 769px) and (max-width: 1023px) {
        .content {
            margin-left: 0 !important;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }
        
        .w-3/4 {
            width: 100%;
            max-width: 100%;
        }
        
        .w-full {
            max-width: 100%;
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
        
        #cart.mobile-open {
            transform: translateY(0);
        }
        
        /* Prevent horizontal scrolling on tablets */
        body {
            overflow-x: hidden;
            max-width: 100vw;
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
    
    /* Ultra-thin scrollbar for modal creditor list */
    #creditorsListContainer::-webkit-scrollbar {
        width: 1px;
    }
    
    #creditorsListContainer::-webkit-scrollbar-track {
        background: transparent;
    }
    
    #creditorsListContainer::-webkit-scrollbar-thumb {
        background-color: #9ca3af;
        border-radius: 0;
    }
    
    #creditorsListContainer::-webkit-scrollbar-thumb:hover {
        background-color: #6b7280;
    }
    
    /* Firefox thin scrollbar for modal */
    #creditorsListContainer {
        scrollbar-width: thin;
        scrollbar-color: #9ca3af transparent;
    }
    
    /* Category filter badges - horizontal scrollable, no scrollbar */
    .category-filter-container {
        background: transparent;
        padding: 0.5rem 0.75rem;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        scroll-behavior: smooth;
        max-width: 400px;
        min-width: 200px;
    }
    
    .category-filter-container::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
        width: 0;
        height: 0;
    }
    
    .category-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1rem;
        margin-right: 0.5rem;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
        font-size: 0.875rem;
        font-weight: 500;
        color: #374151;
        user-select: none;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }
    
    .category-badge:hover {
        background: #e5e7eb;
        border-color: #d1d5db;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
    }
    
    .category-badge.active {
        background: #14b8a6;
        color: white;
        border-color: #14b8a6;
        box-shadow: 0 2px 4px 0 rgba(20, 184, 166, 0.3);
    }
    
    .category-badge.active:hover {
        background: #0d9488;
        border-color: #0d9488;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px 0 rgba(20, 184, 166, 0.4);
    }
    
    /* Mobile adjustments for category filter - Phones and Tablets */
    @media (max-width: 1023px) {
        /* Header row layout - all items in first row except categories */
        .mb-6.flex.items-center.gap-4.flex-wrap {
            gap: 0.5rem;
            align-items: center;
        }
        
        /* Mobile controls (hamburger + cart) - order 1, stay together */
        .mb-6.flex.items-center.gap-4.flex-wrap > .flex.items-center.gap-3 {
            order: 1;
            flex-shrink: 0;
            gap: 0.5rem;
        }
        
        /* Mobile search bar - order 2, grows to fill space, stays in first row */
        .mb-6.flex.items-center.gap-4.flex-wrap > .relative.flex-1 {
            order: 2;
            flex: 1 1 0;
            min-width: 100px;
            max-width: none;
        }
        
        /* Mobile notification icon - order 3, stays in same first row */
        .mb-6.flex.items-center.gap-4.flex-wrap > .relative.flex-shrink-0:last-child {
            order: 3;
            flex-shrink: 0;
        }
        
        /* Category filter - order 4, full width on second row */
        .category-filter-container {
            padding: 0.4rem 0.5rem;
            max-width: 100%;
            width: 100%;
            margin-top: 0.25rem;
            order: 4;
            flex-basis: 100%;
        }
        
        .category-badge {
            padding: 0.4rem 0.75rem;
            font-size: 0.8125rem;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
    }
    
    /* Small phones (up to 375px) */
    @media (max-width: 375px) {
        .category-filter-container {
            padding: 0.35rem 0.4rem;
        }
        
        .category-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            margin-right: 0.4rem;
        }
    }
    
    /* Medium phones (376px - 480px) */
    @media (min-width: 376px) and (max-width: 480px) {
        .category-filter-container {
            padding: 0.4rem 0.5rem;
        }
        
        .category-badge {
            padding: 0.4rem 0.7rem;
            font-size: 0.8125rem;
        }
    }
    
    /* Large phones and small tablets (481px - 768px) */
    @media (min-width: 481px) and (max-width: 768px) {
        .category-filter-container {
            padding: 0.45rem 0.6rem;
            margin-top: 0.25rem;
        }
        
        .category-badge {
            padding: 0.45rem 0.8rem;
            font-size: 0.875rem;
        }
        
        /* Small tablet grid adjustments */
        #productGrid {
            gap: 0.875rem !important;
            max-height: calc(100vh - 12.5rem);
            padding: 0.5rem 0.5rem 1rem 0;
        }
        
        .product-item .p-5 {
            padding: 0.875rem;
        }
        
        .product-item .p-5 p:first-child {
            font-size: 0.875rem;
            line-height: 1.2rem;
        }
        
        main {
            padding: 0.875rem;
            padding-bottom: 0.5rem;
        }
        
        .mb-6.flex.items-center.gap-4.flex-wrap {
            margin-bottom: 0.875rem;
        }
    }
    
    /* Medium tablets - iPad Mini, smaller iPads (768px - 820px) */
    @media (min-width: 768px) and (max-width: 820px) {
        main {
            padding: 1rem 0.875rem;
        }
        
        #productGrid {
            gap: 0.875rem !important;
            max-height: calc(100vh - 12rem);
            padding: 0.5rem 0.5rem 1rem 0;
        }
        
        .product-item .p-5 {
            padding: 0.875rem;
        }
        
        .product-item .p-5 p:first-child {
            font-size: 0.9rem;
        }
        
        .mb-6.flex.items-center.gap-4.flex-wrap {
            margin-bottom: 0.875rem;
            gap: 0.625rem;
        }
    }
    
    /* Standard tablets - iPad (821px - 912px) */
    @media (min-width: 821px) and (max-width: 912px) {
        main {
            padding: 1rem;
        }
        
        #productGrid {
            gap: 1rem !important;
            max-height: calc(100vh - 12rem);
            padding: 0.5rem 0.75rem 1rem 0;
        }
        
        .product-item .p-5 {
            padding: 0.9rem;
        }
        
        .product-item .p-5 p:first-child {
            font-size: 0.9375rem;
        }
        
        .mb-6.flex.items-center.gap-4.flex-wrap {
            margin-bottom: 1rem;
        }
    }
    
    /* Large tablets - iPad Pro (913px - 1024px) */
    @media (min-width: 913px) and (max-width: 1024px) {
        .category-filter-container {
            padding: 0.5rem 0.75rem;
            max-width: 100%;
            min-width: 100%;
            width: 100%;
            margin-top: 0.25rem;
            order: 4;
            flex-basis: 100%;
        }
        
        .category-badge {
            padding: 0.5rem 0.9rem;
            font-size: 0.875rem;
            margin-right: 0.5rem;
        }
        
        /* Large tablet product grid adjustments */
        #productGrid {
            gap: 1.125rem !important;
            padding: 0.5rem 0.75rem 1rem 0;
            max-height: calc(100vh - 12rem);
        }
        
        /* Tablet product cards */
        .product-item {
            width: 100%;
            height: auto;
        }
        
        .product-item .product-image-container {
            padding-bottom: 100%;
        }
        
        .product-item .p-5 {
            padding: 1rem;
        }
        
        .product-item .p-5 p:first-child {
            font-size: 0.9375rem;
            line-height: 1.3rem;
        }
        
        /* Tablet header adjustments */
        main {
            padding: 1rem 1.25rem;
        }
        
        .mb-6.flex.items-center.gap-4.flex-wrap {
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
    }
    
    /* All tablets - category filter consistency (768px - 1024px) */
    @media (min-width: 768px) and (max-width: 1024px) {
        .category-filter-container {
            padding: 0.5rem 0.75rem;
            max-width: 100%;
            width: 100%;
            order: 4;
            flex-basis: 100%;
        }
        
        .category-badge {
            padding: 0.45rem 0.85rem;
            font-size: 0.875rem;
        }
        
        .container {
            max-width: 100% !important;
        }
    }
    
    /* All devices 768px and above - ensure max-width */
    @media (min-width: 768px) {
        .container {
            max-width: 100% !important;
        }
        
        .content {
            max-width: 100%;
        }
        
        main {
            max-width: 100%;
        }
        
        #productGrid {
            max-width: 100%;
        }
    }
    
    /* Touch device optimizations */
    @media (hover: none) and (pointer: coarse) {
        .category-badge {
            min-height: 36px;
            padding: 0.4rem 0.75rem;
            touch-action: manipulation;
        }
        
        .category-filter-container {
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
        }
    }
    </style>

</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">


    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <div class="container">

        <!-- Main Content Area -->
 

        <main class="flex-1 pr-4 p-6 bg-gray-50 flex flex-col lg:flex-row" style="height: 100vh; overflow: hidden;">

    <!-- Mobile Sidebar Overlay -->
    <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>

    <!-- Products Section -->
    <div class="w-full lg:w-3/4 pr-0 lg:pr-6 max-h-[calc(100vh)]">
        <!-- Search, Category Filter and Notifications Row -->
        <div class="mb-6 flex items-center gap-4 flex-wrap lg:flex-nowrap">
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
            <div class="relative flex-1 min-w-[200px] w-full lg:w-auto">
                <input type="text" id="searchBar" class="w-full pl-10 pr-12 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-500 focus:border-teal-500 transition-colors duration-200" placeholder="Search for products or scan barcode..." oninput="filterProducts()" onkeydown="handleBarcodeEntry(event)">
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
            
            <!-- Category Filter Section -->
            <?php if (!empty($categories)): ?>
            <div class="category-filter-container flex-shrink-0 w-full lg:w-auto">
                <div class="flex items-center">
                    <button class="category-badge active" data-category="all" onclick="filterByCategory('all')">
                        All
                    </button>
                    <?php foreach ($categories as $category): ?>
                        <button class="category-badge" data-category="<?= htmlspecialchars($category) ?>" onclick="filterByCategory('<?= htmlspecialchars($category) ?>')">
                            <?= htmlspecialchars($category) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notifications Icon -->
            <div class="relative flex-shrink-0">
                <div class="cursor-pointer" style="padding: 4px;">
                    <svg onclick="toggleNotifications()" class="h-6 w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if (!$hide_available_quantity): ?>
                    <?php $notificationCount = count($outOfStock) + count($lowStock); ?>
                    <?php if ($notificationCount > 0): ?>
                        <span class="absolute top-2 right-2 transform translate-x-1/2 -translate-y-1/2 bg-red-500 text-white text-xs rounded-full min-w-[20px] h-5 px-1 flex items-center justify-center pointer-events-none" style="line-height: 1.25;"><?= $notificationCount ?></span>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Notifications Dropdown -->
                <div id="notificationsDropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar">
                    <?php if ($hide_available_quantity || (empty($outOfStock) && empty($lowStock))): ?>
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
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 gap-3 md:gap-4 lg:gap-8 py-4 overflow-y-auto pr-0 lg:pr-4 custom-scrollbar" id="productGrid" style="max-height: calc(100vh - 18vh); height: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;">
                <?php foreach ($products as $product): ?>
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer transition-transform duration-300 hover:scale-105 product-item select-none" 
                        data-price="<?= $product['price'] ?>" 
                        data-name="<?= htmlspecialchars($product['name']) ?>" 
                        data-barcode="<?= htmlspecialchars($product['barcode']) ?>"
                        data-category="<?= htmlspecialchars($product['category'] ?? '') ?>"
                        data-discount="<?= $product['discount'] ?? 0 ?>"
                        data-discount-start="<?= $product['discount_start'] ?? '' ?>"
                        data-discount-end="<?= $product['discount_end'] ?? '' ?>"
                        onclick="addToCart(this)" 
                        style="height: 100%;">
                    <div class="w-full h-60 overflow-hidden relative product-image-container">
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
                            class="w-full h-full object-cover object-center pointer-events-none" 
                            style="transition: transform 0.3s ease; position: absolute; top: 0; left: 0;" 
                            loading="lazy"
                            width="200"
                            height="200"
                            decoding="async"
                            onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="w-full h-full flex items-center justify-center bg-gray-100" style="display:none; position: absolute; top: 0; left: 0;">
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
                            <?php if (!$hide_available_quantity): ?>
                            <p class="text-sm mb-2 <?php 
                                if ($product['quantity'] < 5) {
                                    echo 'text-red-600';
                                } elseif ($product['quantity'] < 10) {
                                    echo 'text-yellow-600';
                                } else {
                                    echo 'text-teal-600';
                                }
                            ?>">Available: <span><?= $product['quantity'] ?></span></p>
                            <?php endif; ?>
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
                    const productCategory = product.getAttribute('data-category') || '';
                    
                    // Check search filter
                    const searchMatch = searchTerm === '' || name.includes(searchTerm) || barcode.includes(searchTerm);
                    
                    // Check category filter
                    const categoryMatch = currentCategory === 'all' || productCategory === currentCategory;
                    
                    // Show/hide based on both filters
                    product.style.display = (searchMatch && categoryMatch) ? 'block' : 'none';
                });
            }, 50); // Very short delay for typing but prevents excessive processing
        }

        function clearSearch() {
            document.getElementById('searchBar').value = '';
            filterProducts();
        }

        // Category filter function
        let currentCategory = 'all';
        
        function filterByCategory(category) {
            currentCategory = category;
            
            // Update active badge
            document.querySelectorAll('.category-badge').forEach(badge => {
                badge.classList.remove('active');
            });
            const activeBadge = document.querySelector(`.category-badge[data-category="${category}"]`);
            if (activeBadge) {
                activeBadge.classList.add('active');
            }
            
            // Re-apply filterProducts to combine with search
            filterProducts();
        }
        
        // Category filter scroll on hover (only for devices with hover support)
        document.addEventListener('DOMContentLoaded', function() {
            const categoryContainer = document.querySelector('.category-filter-container');
            if (categoryContainer) {
                // Only enable hover scroll on devices that support hover (not touch-only)
                const hasHover = window.matchMedia('(hover: hover)').matches;
                
                if (hasHover) {
                    let scrollInterval;
                    let isHovering = false;
                    
                    categoryContainer.addEventListener('mouseenter', function(e) {
                        isHovering = true;
                        const containerRect = categoryContainer.getBoundingClientRect();
                        const scrollWidth = categoryContainer.scrollWidth;
                        const clientWidth = categoryContainer.clientWidth;
                        
                        // Only scroll if content overflows
                        if (scrollWidth > clientWidth) {
                            startAutoScroll(e.clientX, containerRect);
                        }
                    });
                    
                    categoryContainer.addEventListener('mousemove', function(e) {
                        if (isHovering) {
                            const containerRect = categoryContainer.getBoundingClientRect();
                            startAutoScroll(e.clientX, containerRect);
                        }
                    });
                    
                    categoryContainer.addEventListener('mouseleave', function() {
                        isHovering = false;
                        if (scrollInterval) {
                            clearInterval(scrollInterval);
                            scrollInterval = null;
                        }
                    });
                    
                    function startAutoScroll(mouseX, containerRect) {
                        // Clear existing interval
                        if (scrollInterval) {
                            clearInterval(scrollInterval);
                            scrollInterval = null;
                        }
                        
                        const containerLeft = containerRect.left;
                        const containerRight = containerRect.right;
                        const containerWidth = containerRect.width;
                        const edgeThreshold = containerWidth * 0.3; // 30% from edges
                        
                        // Check if mouse is near left edge
                        if (mouseX - containerLeft < edgeThreshold) {
                            // Scroll left
                            scrollInterval = setInterval(() => {
                                if (categoryContainer.scrollLeft > 0) {
                                    categoryContainer.scrollLeft -= 8;
                                } else {
                                    clearInterval(scrollInterval);
                                    scrollInterval = null;
                                }
                            }, 5);
                        }
                        // Check if mouse is near right edge
                        else if (containerRight - mouseX < edgeThreshold) {
                            // Scroll right
                            const maxScroll = categoryContainer.scrollWidth - categoryContainer.clientWidth;
                            scrollInterval = setInterval(() => {
                                if (categoryContainer.scrollLeft < maxScroll) {
                                    categoryContainer.scrollLeft += 8;
                                } else {
                                    clearInterval(scrollInterval);
                                    scrollInterval = null;
                                }
                            }, 5);
                        }
                    }
                }
            }
        });

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
            <!-- Cart Header (shown when cart is empty) -->
            <h2 id="cartHeader" class="text-2xl font-bold text-gray-900 flex items-center">
                <svg class="w-8 h-8 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.4 5M17 13l1.4 5M9 21h6M9 21a2 2 0 11-4 0M15 21a2 2 0 104 0"></path></svg>
                Cart
            </h2>
            <!-- Add to Tab Button (shown when items are in cart) -->
            <button id="addToTabButton" class="hidden bg-gray-400 hover:bg-gray-500 text-white font-bold px-4 py-2 rounded-lg shadow-md transition-colors duration-300 flex items-center gap-2" onclick="handleAddToTab()">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Add to Tab
            </button>
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
                    <input type="number" id="cashReceived" class="p-2 text-sm w-full rounded-lg border border-gray-300 focus:border-gray-500 focus:ring focus:ring-gray-200 shadow-md" step="1" oninput="calculateChange()">
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
                <button class="bg-sky-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-sky-800 transition-colors duration-300 mb-2 text-sm" onclick="addCash(5)">N$5</button>
                <button class="bg-teal-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-700 transition-colors duration-300 mb-2 text-sm" onclick="addCash(10)">N$10</button>
                <button class="bg-red-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-red-700 transition-colors duration-300 mb-2 text-sm" onclick="addCash(20)">N$20</button>
                <button class="bg-yellow-500 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-yellow-600 transition-colors duration-300 mb-2 text-sm" onclick="addCash(30)">N$30</button>
                <button class="bg-orange-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-orange-700 transition-colors duration-300 mb-2 text-sm" onclick="addCash(50)">N$50</button>
                <button class="bg-neutral-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-neutral-800 transition-colors duration-300 mb-2 text-sm" onclick="addCash(100)">N$100</button>
                <button class="bg-lime-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-lime-800 transition-colors duration-300 mb-2 text-sm"  onclick="addCash(200)">N$200</button>
                <button class="bg-teal-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-800 transition-colors duration-300 mb-2 text-sm" onclick="handleMixedPayment()">Cash/EFT</button>
                <button id="toggleExtraButtons" class="bg-teal-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-800 transition-colors duration-300 mb-2 text-sm" onclick="toggleExtraButtons()">
                    <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Additional buttons (hidden by default) -->
            <div id="extraButtonsContainer" class="hidden flex flex-wrap space-x-2 mb-4">
                <button class="bg-gray-700 text-gray-100 font-semibold px-3 py-2 rounded-lg shadow hover:bg-gray-800 transition-colors duration-200 mb-2 text-sm" onclick="handleCashBack()">
                    <i data-lucide="rotate-cw" class="w-4 h-4 inline-block mr-1 text-gray-100 opacity-80"></i>
                    Cash Back
                </button>
                <button class="bg-teal-800 text-gray-100 font-semibold px-3 py-2 rounded-lg shadow hover:bg-teal-900 transition-colors duration-200 mb-2 text-sm" onclick="handleCashUp()">
                    <i data-lucide="trending-up" class="w-4 h-4 inline-block mr-1 text-gray-100 opacity-80"></i>
                    Cash Up
                </button>
                <button class="bg-stone-700 text-gray-100 font-semibold px-3 py-2 rounded-lg shadow hover:bg-stone-800 transition-colors duration-200 mb-2 text-sm" onclick="handleTips()">
                    <i data-lucide="hand-coins" class="w-4 h-4 inline-block mr-1 text-gray-100 opacity-80"></i>
                    Tips
                </button>
            </div>
        </div>

        <div class="flex justify-between items-center mb-6">
            <p class="text-lg font-bold text-gray-900 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Change: N$<span id="changeAmount" class="text-teal-700 text-2xl">0.00</span>
            </p>
        </div>

        <div class="mt-4">
            <button style="background: oklch(60% 0.118 184.704); color: #fff;" class="px-4 py-3 rounded-lg shadow-md transition-colors duration-300 flex justify-center items-center text-lg w-full hover:opacity-90" onclick="checkout()">
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

        <button class="mt-4 bg-gray-200 text-gray-700 px-4 py-3 rounded-lg shadow-md border border-gray-200 hover:bg-gray-300 hover:text-gray-900 transition-colors duration-300 flex justify-center items-center text-lg w-full font-medium" onclick="clearCart()">
            <svg class="w-6 h-6 mr-2 stroke-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
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

        // Settings from PHP
        const hideAvailableQuantity = <?php echo $hide_available_quantity; ?>;
        const defaultPrintReceipt = <?php echo $default_print_receipt; ?>;

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
                        <span class="text-gray-900 font-medium">${item.name} <span id="quantity-${index}" class="bg-gray-200 text-gray-800 px-2 py-1 rounded cursor-pointer hover:bg-gray-300 transition-colors" title="Click to edit quantity" onclick="editQuantity(${index})">x${item.quantity}</span></span>
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
            
            // Toggle between Cart header and Add to Tab button
            const cartHeader = document.getElementById('cartHeader');
            const addToTabButton = document.getElementById('addToTabButton');
            
            if (totalItems > 0) {
                // Show Add to Tab button, hide Cart header
                if (cartHeader) cartHeader.classList.add('hidden');
                if (addToTabButton) addToTabButton.classList.remove('hidden');
            } else {
                // Show Cart header, hide Add to Tab button
                if (cartHeader) cartHeader.classList.remove('hidden');
                if (addToTabButton) addToTabButton.classList.add('hidden');
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
            input.className = 'w-16 px-2 py-1 text-center border border-gray-300 rounded text-sm quantity-input';
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
                    // Skip stock check if hideAvailableQuantity is enabled
                    if (!hideAvailableQuantity) {
                        // Check available stock
                        const productElement = document.querySelector(`.product-item[data-name="${cart[index].name}"]`);
                        if (productElement) {
                            const quantityElement = productElement.querySelector('p:last-child');
                            if (quantityElement && quantityElement.textContent.includes('Available:')) {
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
                                    updateCart();
                                    return;
                                }
                            }
                        }
                    }
                    
                    // Update cart with new quantity and recalculate price
                    cart[index].quantity = newQuantity;
                    cart[index].price = unitPrice * newQuantity;
                    sound.play();
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

        function toggleExtraButtons() {
            const container = document.getElementById('extraButtonsContainer');
            const toggleBtn = document.getElementById('toggleExtraButtons');
            const icon = toggleBtn.querySelector('svg path');
            
            if (container.classList.contains('hidden')) {
                container.classList.remove('hidden');
                // Change icon to minus (-)
                icon.setAttribute('d', 'M20 12H4');
                // Re-initialize Lucide icons when container is shown
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            } else {
                container.classList.add('hidden');
                // Change icon back to plus (+)
                icon.setAttribute('d', 'M12 4v16m8-8H4');
            }
        }

        function handleCashBack() {
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-gray-700 mb-4">Cash Back</h1>',
                html: `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Amount:</label>
                            <input type="number" 
                                   id="cashBackAmount" 
                                   min="0" 
                                   step="0.01" 
                                   class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl 
                                          focus:border-gray-500 focus:ring-2 focus:ring-gray-200 
                                          text-base font-medium shadow-sm transition-all duration-200
                                          bg-gray-50 hover:bg-gray-100"
                                   placeholder="0.00">
                            <p class="text-xs text-gray-500 mt-1">Enter the amount for EFT payment and cash withdrawal</p>
                        </div>
                        <div class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Reference (Optional):</label>
                            <input type="text" 
                                   id="cashBackRef" 
                                   class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl 
                                          focus:border-gray-500 focus:ring-2 focus:ring-gray-200 
                                          text-base font-medium shadow-sm transition-all duration-200
                                          bg-gray-50 hover:bg-gray-100"
                                   placeholder="Enter reference">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Provider (Optional):</label>
                            <select id="cashBackProvider" 
                                    class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl 
                                           focus:border-gray-500 focus:ring-2 focus:ring-gray-200 
                                           text-base font-medium shadow-sm transition-all duration-200
                                           bg-gray-50 hover:bg-gray-100">
                                <option value="Cash Back">Cash Back</option>
                                <option value="Kapana">Kapana</option>
                                <option value="Hubbly">Hubbly</option>
                                <option value="Kitchen">Kitchen</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Confirm',
                confirmButtonClass: 'swal2-confirm-btn bg-gray-600 hover:bg-gray-700 text-white px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const amount = parseFloat(document.getElementById('cashBackAmount').value);
                    if (!amount || amount <= 0) {
                        Swal.showValidationMessage('Please enter a valid cash back amount');
                        return false;
                    }
                    const transactionRef = document.getElementById('cashBackRef').value.trim() || '';
                    const walletProvider = document.getElementById('cashBackProvider').value || 'Cash Back';
                    return { amount, transactionRef, walletProvider };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const amount = result.value.amount;
                    const transactionRef = result.value.transactionRef || '';
                    const walletProvider = result.value.walletProvider || 'Cash Back';
                    
                    // Same amount is used for both payment and cash back
                    const eftTotal = amount;
                    const cashBackAmt = amount;
                    const saleAmount = amount;
                    
                    // Record cashback transaction
                    const cashbackData = {
                        eft_total: eftTotal,
                        cash_back: cashBackAmt,
                        sale_amount: saleAmount,
                        transaction_ref: transactionRef,
                        wallet_provider: walletProvider
                    };
                    
                    // Process the cashback - exactly like cash.php
                    fetch('process_cashback.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(cashbackData)
                    })
                    .then(res => {
                        if (!res.ok) {
                            // If response is not ok, try to parse error
                            return res.json().then(data => {
                                throw { responseJSON: { error: data.error || 'Error processing cash back' } };
                            }).catch(() => {
                                throw { responseJSON: { error: 'Error processing cash back' } };
                            });
                        }
                        return res.json();
                    })
                    .then(response => {
                        if (response.success) {
                            // Open cash drawer before showing success and reloading (like cash.php)
                            // Use .then() to wait for drawer to complete, matching cash.php's .always()
                            openCashDrawer().then(() => {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Cash Back Processed',
                                    text: `Cash back transaction completed successfully`,
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                                
                                // Refresh the page to update display (1 second delay like cash.php)
                                setTimeout(() => location.reload(), 1000);
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.error || 'Error processing cash back',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        let errorMessage = 'Error processing cash back';
                        // Extract error message like cash.php does
                        if (error.responseJSON && error.responseJSON.error) {
                            errorMessage = error.responseJSON.error;
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: errorMessage,
                            timer: 3000,
                            showConfirmButton: false
                        });
                    });
                }
            });
        }

        function handleCashUp() {
            // Get today's date as default
            const today = new Date().toISOString().split('T')[0];
            const currentUser = '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>';
            
            // Show date selection modal first
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">Select Date for Cash Up</h1>',
                html: `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select Date:</label>
                            <input type="date" 
                                   id="cashUpDate" 
                                   value="${today}"
                                   max="${today}"
                                   class="w-full px-4 py-2 border-2 border-teal-100 rounded-xl 
                                          focus:border-teal-500 focus:ring-2 focus:ring-teal-200 
                                          text-base font-medium shadow-sm transition-all duration-200
                                          bg-teal-50 hover:bg-teal-100">
                        </div>
                        <p class="text-xs text-gray-500">Choose the date for which you want to perform cash up</p>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Continue',
                confirmButtonClass: 'swal2-confirm-btn bg-teal-600 hover:bg-teal-700 text-white px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const selectedDate = document.getElementById('cashUpDate').value;
                    if (!selectedDate) {
                        Swal.showValidationMessage('Please select a date');
                        return false;
                    }
                    return { selectedDate };
                }
            }).then((dateResult) => {
                if (!dateResult.isConfirmed) return;
                
                const selectedDate = dateResult.value.selectedDate;
                
                // First fetch expected cash amount from fetch_report_data.php
                const formData = new FormData();
                formData.append('date', selectedDate);
                
                fetch('fetch_report_data.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error || 'Failed to load cash data',
                            timer: 3000,
                            showConfirmButton: false
                        });
                        return;
                    }
                    
                    const expectedAmount = parseFloat(data.cashAvailableInTill || 0);
                    
                    Swal.fire({
                        title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">Cash Up - ' + selectedDate + '</h1>',
                        html: `
                            <div class="space-y-4">
                                <div class="w-full flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-700">Expected Cash in Till:</span>
                                    <span class="text-lg font-bold text-teal-700">N$${expectedAmount.toFixed(2)}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Actual Cash in Till:</label>
                                    <input type="number" 
                                           id="actualCashInTill" 
                                           min="0" 
                                           step="0.01" 
                                           class="w-full px-4 py-2 border-2 border-teal-100 rounded-xl 
                                                  focus:border-teal-500 focus:ring-2 focus:ring-teal-200 
                                                  text-base font-medium shadow-sm transition-all duration-200
                                                  bg-teal-50 hover:bg-teal-100"
                                           placeholder="0.00">
                                </div>
                                <p class="text-xs text-gray-500">Enter the actual amount of cash in the till</p>
                            </div>
                        `,
                        showCancelButton: true,
                        reverseButtons: true,
                        confirmButtonText: 'Generate Report',
                        confirmButtonClass: 'swal2-confirm-btn bg-teal-600 hover:bg-teal-700 text-white px-6 py-2 rounded-lg',
                        cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                        customClass: {
                            popup: 'rounded-2xl shadow-xl',
                        },
                        allowOutsideClick: false,
                        preConfirm: () => {
                            const actualAmount = parseFloat(document.getElementById('actualCashInTill').value);
                            if (isNaN(actualAmount) || actualAmount < 0) {
                                Swal.showValidationMessage('Please enter a valid cash amount');
                                return false;
                            }
                            return { actualAmount, expectedAmount };
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            const actualAmount = result.value.actualAmount;
                            const expectedAmount = result.value.expectedAmount;
                            const difference = actualAmount - expectedAmount;
                            
                            // Fetch full cash up data
                            const cashupFormData = new FormData();
                            cashupFormData.append('date', selectedDate);
                            cashupFormData.append('actual_cash_in_till', actualAmount);
                            cashupFormData.append('cash_difference', difference);
                        
                        fetch('fetch_report_data.php', {
                            method: 'POST',
                            body: cashupFormData
                        })
                        .then(response => response.json())
                        .then(cashupData => {
                            if (cashupData.error) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: cashupData.error || 'Failed to generate cash-up report',
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                                return;
                            }
                            
                            // Open cash drawer before generating report
                            openCashDrawer();
                            
                            // Create a form to submit for PDF generation
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'cash-pdf.php';
                            
                            // Add all data as hidden fields
                            const pdfData = {
                                is_cashup: 'true',
                                date: selectedDate,
                                cashier_username: currentUser,
                                total_cash_sales: cashupData.cashSalesTotal || 0,
                                eft_sales_total: cashupData.eftSalesTotal || 0,
                                unpaid_credit: cashupData.unpaidCredit || 0,
                                cash_on_hand: cashupData.cashOnHand || 0,
                                cash_available_in_till: cashupData.cashAvailableInTill || 0,
                                expected_cash: expectedAmount, // Add expected cash amount
                                actual_cash_in_till: actualAmount,
                                cash_difference: difference,
                                total_cash_in: cashupData.totalCashIn || 0,
                                total_cash_out: cashupData.totalCashOut || 0,
                                cumulative_cash_sales: cashupData.cumulativeCashSales || 0,
                                cumulative_paid_credit: cashupData.cumulativePaidCredit || 0
                            };
                            
                            for (const [key, value] of Object.entries(pdfData)) {
                                const hiddenField = document.createElement('input');
                                hiddenField.type = 'hidden';
                                hiddenField.name = key;
                                hiddenField.value = value;
                                form.appendChild(hiddenField);
                            }
                            
                            // Add form to body and submit for PDF
                            document.body.appendChild(form);
                            form.submit();
                            document.body.removeChild(form);
                            
                            // Also print the cash-up receipt
                            const printData = Object.assign({}, pdfData, {
                                is_cashup_report: true,
                                expected_cash: expectedAmount,
                                cash_sales: cashupData.cash_sales || 0,
                                credit_cash: cashupData.credit_cash || 0,
                                credit_eft: cashupData.credit_eft || 0,
                                eft_sales: cashupData.eft_sales || 0,
                                credit_unpaid: cashupData.credit_unpaid || 0,
                                total_income: cashupData.total_income || 0,
                                total_expense: cashupData.total_expense || 0,
                                net_amount: cashupData.net_amount || 0
                            });
                            
                            // Use sendToPrinter (routes to QZ Tray when enabled)
                            const printFn = (typeof window.sendToPrinter === 'function')
                                ? (d) => window.sendToPrinter(d)
                                : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
                            printFn(printData)
                            .then(result => {
                                if (result.success) {
                                    cashSound.play();
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Cash Up Complete',
                                        text: difference === 0 ? 
                                            'Cash till balanced successfully' : 
                                            `Cash till ${difference > 0 ? 'surplus' : 'shortage'} of N$${Math.abs(difference).toFixed(2)} recorded`,
                                        timer: 2000,
                                        showConfirmButton: false
                                    });
                                } else {
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'PDF Generated',
                                        text: 'Receipt printing failed: ' + (result.message || 'Unknown error'),
                                        timer: 3000,
                                        showConfirmButton: false
                                    });
                                }
                            })
                            .catch(err => {
                                console.error('Print error:', err);
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'PDF Generated',
                                    text: 'Receipt printing failed',
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                            });
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Failed to generate cash-up report',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        });
                    }
                });
                })
                .catch(error => {
                    console.error('Error fetching cash data:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load cash data',
                        timer: 3000,
                        showConfirmButton: false
                    });
                });
            });
        }

        function handleTips() {
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-cyan-700 mb-4">Tips</h1>',
                html: `
                    <div class="space-y-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tip Amount:</label>
                        <input type="number" 
                               id="tipAmount" 
                               min="0" 
                               step="0.01" 
                               class="w-full px-4 py-2 border-2 border-cyan-100 rounded-xl 
                                      focus:border-cyan-500 focus:ring-2 focus:ring-cyan-200 
                                      text-base font-medium shadow-sm transition-all duration-200
                                      bg-cyan-50 hover:bg-cyan-100"
                               placeholder="0.00">
                        <p class="text-xs text-gray-500">Tip amount received from customer</p>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Confirm',
                confirmButtonClass: 'swal2-confirm-btn bg-cyan-600 hover:bg-cyan-700 text-white px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const amount = parseFloat(document.getElementById('tipAmount').value);
                    if (!amount || amount <= 0) {
                        Swal.showValidationMessage('Please enter a valid tip amount');
                        return false;
                    }
                    return { amount };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const cashReceived = document.getElementById('cashReceived');
                    const currentCash = parseFloat(cashReceived.value) || 0;
                    const tip = result.value.amount;
                    const newCashReceived = currentCash + tip;
                    
                    cashReceived.value = newCashReceived;
                    calculateChange();
                    sound.play();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Tip Added',
                        text: `N$${tip.toFixed(2)} tip added to cash received`,
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            });
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

            // Check for out-of-stock items (skip if hideAvailableQuantity is enabled)
            if (!hideAvailableQuantity) {
                const outOfStockItems = cart.filter(item => {
                    const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                    if (productElement) {
                        const quantityElement = productElement.querySelector('p:last-child');
                        if (quantityElement && quantityElement.textContent.includes('Available:')) {
                            const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                            return availableQuantity < item.quantity;
                        }
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
                            <option value="gray Wallet">gray Wallet</option>
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

                    // Ask for final confirmation and optionally receipt printing
                    Swal.fire({
                        icon: 'success',
                        title: 'Confirm EFT Payment',
                        confirmButtonText: 'OK',
                        footer: `
                            <div style="display: flex; justify-content: center; align-items: center;">
                                <a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                                    <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                                </a>
                                <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;' checked>
                                <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                            </div>
                        `,
                        allowOutsideClick: false,
                        focusConfirm: false
                    }).then((confirmRes) => {
                        if (!confirmRes.isConfirmed) return;
                        const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                        // Process the e-wallet payment AFTER confirmation
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
                                saleData.order_id = result.order_id;
                                cashSound.play();
                                if (printReceipt) {
                                    saleData.print_only = true;
                                    const pf = (typeof window.sendToPrinter === 'function') ? (d) => window.sendToPrinter(d) : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
                                    pf(saleData).catch(printError => console.error('Receipt printing error:', printError));
                                }
                                clearCart();
                                refreshProductQuantities();
                                closeMobileCart();
                                Swal.fire({icon:'success', title:'EFT Payment Processed', timer:1200, showConfirmButton:false});
                            } else {
                                Swal.fire('Error', result.message || 'Failed to process payment', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire('Error', 'Could not process e-wallet payment', 'error');
                        });
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

            // Skip stock check if hideAvailableQuantity is enabled
            if (!hideAvailableQuantity) {
                const outOfStockItems = cart.filter(item => {
                    const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                    if (productElement) {
                        const quantityElement = productElement.querySelector('p:last-child');
                        if (quantityElement && quantityElement.textContent.includes('Available:')) {
                            const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                            return availableQuantity < item.quantity;
                        }
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
                                <option value="gray Wallet">gray Wallet</option>
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

                // Final confirmation before processing and optional print
                Swal.fire({
                    icon: 'success',
                    title: 'Confirm Cash + EFT',
                    confirmButtonText: 'OK',
                    footer: `
                        <div style="display: flex; justify-content: center; align-items: center;">
                            <a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                                <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                            </a>
                            <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;' ${defaultPrintReceipt ? 'checked' : ''}>
                            <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                        </div>
                    `,
                    allowOutsideClick: false,
                    focusConfirm: false
                }).then(ok => {
                    if (!ok.isConfirmed) return;
                    const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;

                    fetch('process_order.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(saleData)
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            saleData.order_id = result.order_id;
                            if (cashAmount > 0) {
                                openCashDrawer();
                            }
                            cashSound.play();
                            if (printReceipt) {
                                saleData.print_only = true;
                                const pf = (typeof window.sendToPrinter === 'function') ? (d) => window.sendToPrinter(d) : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
                                pf(saleData).catch(err => console.error('Receipt printing error:', err));
                            }
                            clearCart();
                            refreshProductQuantities();
                            closeMobileCart();
                            Swal.fire({icon:'success', title:'Payment Processed', timer:1200, showConfirmButton:false});
                        } else {
                            Swal.fire('Error', result.message || 'Failed to process mixed payment', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Could not process mixed payment', 'error');
                    });
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

            // Skip stock check if hideAvailableQuantity is enabled
            if (!hideAvailableQuantity) {
                const outOfStockItems = cart.filter(item => {
                    const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                    if (productElement) {
                        const quantityElement = productElement.querySelector('p:last-child');
                        if (quantityElement && quantityElement.textContent.includes('Available:')) {
                            const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                            return availableQuantity < item.quantity;
                        }
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
            }

            // Fetch creditors with balances
            fetch('get_creditors_with_balances.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Failed to load creditors', 'error');
                        return;
                    }

                    const creditors = data.creditors || [];
                    
                    // Show enhanced creditor selection modal
                    showCreditorSelectionModal(creditors);
                })
                .catch(error => {
                    console.error('Error fetching creditors:', error);
                    Swal.fire('Error', 'Failed to load creditors', 'error');
                });
        }

        function showCreditorSelectionModal(creditors) {
            // Create creditor list HTML with search
            let creditorsListHTML = '';
            if (creditors.length === 0) {
                creditorsListHTML = `
                    <div class="text-center py-8">
                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <p class="text-gray-600 text-xs font-medium">No active creditors</p>
                        <p class="text-gray-400 text-xs mt-0.5">Create a new account</p>
                    </div>
                `;
            } else {
                creditors.forEach(creditor => {
                    const balance = parseFloat(creditor.outstanding_balance || 0);
                    const balanceClass = balance > 0 ? 'text-orange-500 font-bold' : 'text-teal-600 font-semibold';
                    const balanceText = balance > 0 ? `N$${balance.toFixed(2)}` : 'N$0.00';
                    
                    creditorsListHTML += `
                        <div class="creditor-item bg-white rounded-lg p-2 mb-1 cursor-pointer hover:bg-gray-200 transition-colors duration-200 relative" 
                             data-id="${creditor.id}" 
                             data-name="${creditor.name.toLowerCase()}"
                             data-phone="${(creditor.phone || '').toLowerCase()}"
                             data-balance="${balance}"
                             data-transactions="${creditor.total_transactions || 0}"
                             onclick="selectCreditor(${creditor.id})">
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2 min-w-0" style="max-width: 35%;">
                                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    <span class="font-medium text-gray-700 truncate">${creditor.name}</span>
                                </div>
                                ${creditor.phone ? `<span class="text-gray-500 whitespace-nowrap absolute left-1/2 transform -translate-x-1/2">${creditor.phone}</span>` : ''}
                                <div class="flex items-center gap-2 ml-auto flex-shrink-0">
                                    <span class="${balanceClass} font-medium whitespace-nowrap px-2 py-0.5 rounded-full text-xs bg-gray-100 border border-gray-200" style="min-width: 65px; text-align: center;">
                                        ${balanceText}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }

            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Choose Creditor</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <!-- Search Bar and Create Account Button in same row -->
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <input type="text" 
                                       id="creditorSearch" 
                                       class="w-full h-10 px-3 pl-9 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                       placeholder="Search...">
                                <svg class="absolute left-2.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <button id="createAccountBtn" 
                                    class="h-10 bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-3 rounded-lg transition-colors duration-200 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <!-- Sortable Header -->
                        <div class="bg-[#f3f4f6] rounded-lg p-2 mb-1">
                            <div class="flex items-center justify-between gap-2 text-xs text-gray-600 font-medium">
                                <div class="flex items-center gap-1.5 min-w-0 cursor-pointer hover:text-gray-700 transition-colors sort-header" data-sort="name" style="max-width: 35%;">
                                    <span>Name</span>
                                    <svg class="w-3 h-3 sort-icon transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-1.5 cursor-pointer hover:text-gray-700 transition-colors sort-header absolute left-1/2 transform -translate-x-1/2" data-sort="phone">
                                    <span>Contact</span>
                                    <svg class="w-3 h-3 sort-icon transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-1.5 cursor-pointer hover:text-gray-700 transition-colors sort-header ml-auto flex-shrink-0" data-sort="balance">
                                    <span>Balance</span>
                                    <svg class="w-3 h-3 sort-icon transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Creditors List -->
                        <div id="creditorsListContainer" class="max-h-48 overflow-y-auto custom-scrollbar bg-[#f3f4f6] rounded-lg p-1.5" style="min-height: 100px;">
                            ${creditorsListHTML}
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Next',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg hidden',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    // Check if a creditor was selected
                    const selectedItem = document.querySelector('.creditor-item.bg-gray-300');
                    if (!selectedItem) {
                        Swal.showValidationMessage('<span class="text-red-500 text-sm">Please select a creditor account first</span>');
                        return false;
                    }
                    const creditorId = selectedItem.getAttribute('data-id');
                    return { creditorId: creditorId };
                },
                didOpen: () => {
                    let selectedCreditorId = null;
                    let currentSort = { field: 'name', direction: 'asc' };
                    
                    // Sorting functionality
                    function sortCreditors(field, direction) {
                        const container = document.getElementById('creditorsListContainer');
                        const items = Array.from(container.querySelectorAll('.creditor-item'));
                        
                        items.sort((a, b) => {
                            let aVal, bVal;
                            
                            if (field === 'name') {
                                aVal = a.getAttribute('data-name');
                                bVal = b.getAttribute('data-name');
                            } else if (field === 'phone') {
                                aVal = a.getAttribute('data-phone') || '';
                                bVal = b.getAttribute('data-phone') || '';
                            } else if (field === 'balance') {
                                aVal = parseFloat(a.getAttribute('data-balance')) || 0;
                                bVal = parseFloat(b.getAttribute('data-balance')) || 0;
                            } else if (field === 'transactions') {
                                aVal = parseInt(a.getAttribute('data-transactions')) || 0;
                                bVal = parseInt(b.getAttribute('data-transactions')) || 0;
                            }
                            
                            if (typeof aVal === 'string') {
                                return direction === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
                            } else {
                                return direction === 'asc' ? aVal - bVal : bVal - aVal;
                            }
                        });
                        
                        // Clear container and re-append sorted items
                        items.forEach(item => container.appendChild(item));
                    }
                    
                    // Initialize default sort (by name)
                    const nameHeader = document.querySelector('.sort-header[data-sort="name"]');
                    if (nameHeader) {
                        const nameIcon = nameHeader.querySelector('.sort-icon');
                        nameIcon.style.display = 'block';
                        nameIcon.style.transform = 'rotate(0deg)';
                    }
                    
                    // Add sort header click handlers
                    const sortHeaders = document.querySelectorAll('.sort-header');
                    sortHeaders.forEach(header => {
                        header.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const sortField = this.getAttribute('data-sort');
                            
                            // Toggle direction if same field
                            if (currentSort.field === sortField) {
                                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                            } else {
                                currentSort.field = sortField;
                                currentSort.direction = 'asc';
                            }
                            
                            // Update visual indicators
                            sortHeaders.forEach(h => {
                                const icon = h.querySelector('.sort-icon');
                                if (h === this) {
                                    icon.style.display = 'block';
                                    icon.style.transform = currentSort.direction === 'asc' ? 'rotate(0deg)' : 'rotate(180deg)';
                                } else {
                                    icon.style.display = 'none';
                                }
                            });
                            
                            // Perform sort
                            sortCreditors(currentSort.field, currentSort.direction);
                        });
                    });
                    
                    // Search functionality
                    const searchInput = document.getElementById('creditorSearch');
                    const creditorsListContainer = document.getElementById('creditorsListContainer');
                    if (searchInput) {
                        searchInput.addEventListener('input', function(e) {
                            const searchTerm = e.target.value.toLowerCase();
                            const creditorItems = document.querySelectorAll('.creditor-item');
                            let visibleCount = 0;
                            
                            creditorItems.forEach(item => {
                                const name = item.getAttribute('data-name') || '';
                                const phone = item.getAttribute('data-phone') || '';
                                if (name.includes(searchTerm) || phone.includes(searchTerm)) {
                                    item.style.display = 'block';
                                    visibleCount++;
                                } else {
                                    item.style.display = 'none';
                                }
                            });
                            
                            // Show/hide "no results" message
                            let noResultsMsg = creditorsListContainer.querySelector('.no-results-msg');
                            if (visibleCount === 0 && searchTerm.length > 0) {
                                if (!noResultsMsg) {
                                    noResultsMsg = document.createElement('div');
                                    noResultsMsg.className = 'no-results-msg text-center py-8 text-gray-500';
                                    noResultsMsg.innerHTML = `
                                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                        </svg>
                                        <p class="text-sm font-medium text-gray-500">No results found</p>
                                    `;
                                    creditorsListContainer.appendChild(noResultsMsg);
                                }
                                noResultsMsg.style.display = 'block';
                            } else if (noResultsMsg) {
                                noResultsMsg.style.display = 'none';
                            }
                        });
                    }
                    
                    // Create account button
                    const createBtn = document.getElementById('createAccountBtn');
                    if (createBtn) {
                        createBtn.addEventListener('click', function() {
                            showCreateCreditorModal(creditors);
                        });
                    }
                    
                    // Select creditor on click
                    window.selectCreditor = function(id) {
                        selectedCreditorId = id;
                        // Remove previous selection
                        document.querySelectorAll('.creditor-item').forEach(item => {
                            item.classList.remove('bg-gray-300');
                            item.classList.add('bg-white');
                        });
                        // Highlight selected
                        const selectedItem = document.querySelector(`.creditor-item[data-id="${id}"]`);
                        if (selectedItem) {
                            selectedItem.classList.remove('bg-white', 'hover:bg-gray-200');
                            selectedItem.classList.add('bg-gray-300');
                        }
                        // Show next button when creditor is selected
                        const confirmBtn = document.querySelector('.swal2-confirm');
                        if (confirmBtn) {
                            confirmBtn.classList.remove('hidden');
                        }
                        // Automatically proceed to next step
                        setTimeout(() => {
                            Swal.close();
                            proceedWithCreditor(selectedCreditorId);
                        }, 200); // Small delay for visual feedback
                    };
                }
            }).then((result) => {
                // Handle if user clicks Next without selection
                if (result && result.isConfirmed && result.value && result.value.creditorId) {
                    proceedWithCreditor(result.value.creditorId);
                } else if (result && result.isConfirmed && !result.value) {
                    // User tried to proceed without selection (should not happen due to preConfirm, but just in case)
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Selection',
                        text: 'Please select a creditor account first',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
                // Handle if user clicks cancel
            });
        }

        function showCreateCreditorModal(existingCreditors) {
            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Create New Account</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Creditor Name <span class="text-red-500">*</span></label>
                            <input type="text" 
                                   id="newCreditorName" 
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="Enter name" 
                                   autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Phone Number (Optional)</label>
                            <input type="text" 
                                   id="newCreditorPhone" 
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="Enter phone" 
                                   autocomplete="off">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Create',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const name = document.getElementById('newCreditorName').value.trim();
                    const phone = document.getElementById('newCreditorPhone').value.trim();
                    
                    if (!name) {
                        Swal.showValidationMessage('<span class="text-red-500">Creditor name is required</span>');
                        return false;
                    }
                    
                    return { name, phone };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create creditor via API
                    fetch('create_creditor.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(result.value)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Account Created',
                                text: `Creditor "${data.creditor.name}" created successfully`,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload creditors and show selection modal again
                                fetch('get_creditors_with_balances.php')
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            showCreditorSelectionModal(data.creditors);
                                        }
                                    });
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to create creditor', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error creating creditor:', error);
                        Swal.fire('Error', 'Failed to create creditor', 'error');
                    });
                }
            });
        }

        function proceedWithCreditor(creditorId) {
            // Show due date input modal
            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Set Due Date</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Payment Deadline:</label>
                        <input type="date" 
                               id="dueDate" 
                               min="${new Date().toISOString().split('T')[0]}"
                               value="${(() => { const now = new Date(); const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0); return lastDay.toISOString().split('T')[0]; })()}"
                               class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200">
                    </div>
                `,
                focusConfirm: false,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Confirm',
                cancelButtonText: 'Back',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const dueDate = document.getElementById('dueDate').value;
                    if (!dueDate) {
                        Swal.showValidationMessage('<span class="text-red-500">A valid due date is required</span>');
                    }
                    return { dueDate, creditorId }
                }
            }).then((secondResult) => {
                // If user clicks Back, reopen creditor selection
                if (secondResult.dismiss === Swal.DismissReason.cancel) {
                    fetch('get_creditors_with_balances.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showCreditorSelectionModal(data.creditors);
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching creditors:', error);
                        });
                    return;
                }
                if (secondResult.isConfirmed) {
                    const saleData = {
                        creditor_id: secondResult.value.creditorId,
                        due_date: secondResult.value.dueDate,
                        items: cart,
                        total: parseFloat(document.getElementById('cartTotal').innerText),
                        cash_received: 0,
                        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
                    };

                    // Final confirmation and optional print before processing credit sale
                    Swal.fire({
                        icon: 'success',
                        title: 'Confirm Credit Sale',
                        confirmButtonText: 'OK',
                        footer: `
                            <div style="display: flex; justify-content: center; align-items: center;">
                                <a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                                    <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                                </a>
                                <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;' ${defaultPrintReceipt ? 'checked' : ''}>
                                <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                            </div>
                        `,
                        allowOutsideClick: false,
                        focusConfirm: false
                    }).then(ok => {
                        if (!ok.isConfirmed) return;
                        const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
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
                                saleData.sale_id = result.sale_id;
                                saleData.creditor_name = result.creditor_name;
                                cashSound.play();
                                if (printReceipt) {
                                    saleData.print_only = true;
                                    const pf = (typeof window.sendToPrinter === 'function') ? (d) => window.sendToPrinter(d) : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
                                    pf(saleData).catch(printError => console.error('Receipt printing error:', printError));
                                }
                                clearCart();
                                refreshProductQuantities();
                                closeMobileCart();
                                Swal.fire({icon:'success', title:'Credit Sale Recorded', timer:1200, showConfirmButton:false});
                            } else {
                                Swal.fire('Error', result.message, 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire('Error', 'Could not process credit sale', 'error');
                        });
                    });
                }
            });
        }

        let isProcessing = false;

        // Function to open cash drawer only (no receipt printing)
        function openCashDrawer() {
            const drawerData = {
                open_drawer_only: true,
                cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
            };
            const pf = (typeof window.sendToPrinter === 'function') ? (d) => window.sendToPrinter(d) : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
            return pf(drawerData)
            .then(result => {
                if (result.success) {
                    console.log('Cash drawer opened successfully');
                } else {
                    console.error('Cash drawer failed:', result.message);
                }
                return result;
            })
            .catch(err => {
                console.error('Drawer opening error:', err);
                return { success: false, error: err };
            });
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

    // Skip stock check if hideAvailableQuantity is enabled
    if (!hideAvailableQuantity) {
        const outOfStockItems = cart.filter(item => {
            const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
            if (productElement) {
                const quantityElement = productElement.querySelector('p:last-child');
                if (quantityElement && quantityElement.textContent.includes('Available:')) {
                    const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                    return availableQuantity < item.quantity;
                }
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

    // Ask for confirmation first; process only after OK
    Swal.fire({
        icon: 'success',
        title: `Change: N$${change.toFixed(2)}`,
        confirmButtonText: 'OK',
        footer: `
            <div style="display: flex; justify-content: center; align-items: center;">
                <a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                    <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                </a>
                <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;' ${defaultPrintReceipt ? 'checked' : ''}>
                <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
            </div>
        `,
        allowOutsideClick: false,
        focusConfirm: false
    }).then(result => {
        if (!result.isConfirmed) {
            isProcessing = false;
            checkoutBtn.innerHTML = originalText;
            checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            return;
        }
        const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;

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
                openCashDrawer();
                cashSound.play();
                if (printReceipt) {
                    data.print_only = true;
                    const pf = (typeof window.sendToPrinter === 'function') ? (d) => window.sendToPrinter(d) : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
                    pf(data).catch(err => console.error('Receipt printing error:', err));
                }
                clearCart();
                refreshProductQuantities();
                closeMobileCart();
                Swal.fire({icon:'success', title:'Payment Processed', timer:1200, showConfirmButton:false});
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
    });
}

        function refreshProductQuantities() {
        fetch('get_product_quantities.php')
            .then(response => response.json())
            .then(data => {
                data.forEach(product => {
                    const productElement = document.querySelector(`.product-item[data-name="${product.name}"]`);
                    if (productElement) {
                        // Update quantity (conditionally hide if setting is enabled)
                        const quantityElement = productElement.querySelector('p:last-child');
                        if (quantityElement) {
                            quantityElement.textContent = `Available: ${product.quantity}`;
                            quantityElement.className = `text-sm mb-2 ${hideAvailableQuantity ? 'hidden' : ''} ${
                                product.quantity < 5 ? 'text-red-600' :
                                product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'
                            }`;
                        }

                        // Update price display with discount if applicable
                        const priceContainer = productElement.querySelector('.p-5');
                        if (priceContainer) {
                            const discount = parseFloat(product.discount) || 0;
                            const discountStart = product.discount_start;
                            const discountEnd = product.discount_end;
                            const price = parseFloat(product.price);
                            
                            // Generate quantity HTML conditionally
                            const quantityHtml = hideAvailableQuantity ? '' : `<p class="text-sm mb-2 ${product.quantity < 5 ? 'text-red-600' : product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'}">Available: ${product.quantity}</p>`;

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
                                        ${quantityHtml}
                                    `;
                                } else {
                                    priceContainer.innerHTML = `
                                        <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="${product.name}">${product.name}</p>
                                        <p class="text-2xl font-extrabold text-teal-800">N$${price.toFixed(2)}</p>
                                        ${quantityHtml}
                                    `;
                                }
                            } else {
                                priceContainer.innerHTML = `
                                    <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis" title="${product.name}">${product.name}</p>
                                    <p class="text-2xl font-extrabold text-teal-800">N$${price.toFixed(2)}</p>
                                    ${quantityHtml}
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

        function handleAddToTab() {
            // First check if cart is empty
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Empty Cart',
                    text: 'Please add items to cart before adding to tab',
                    allowOutsideClick: false,
                });
                return;
            }

            // Skip stock check if hideAvailableQuantity is enabled
            if (!hideAvailableQuantity) {
                const outOfStockItems = cart.filter(item => {
                    const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                    if (productElement) {
                        const quantityElement = productElement.querySelector('p:last-child');
                        if (quantityElement && quantityElement.textContent.includes('Available:')) {
                            const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                            return availableQuantity < item.quantity;
                        }
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
            }

            // Show table selection modal
            showTableSelectionModal();
        }

        function showTableSelectionModal() {
            // Fetch tables with balances
            fetch('get_tables_with_balances.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Failed to load tables', 'error');
                        return;
                    }
                    const tables = data.tables || [];
                    displayTableSelectionModal(tables);
                })
                .catch(error => {
                    console.error('Error fetching tables:', error);
                    Swal.fire('Error', 'Failed to load tables', 'error');
                });
        }

        function displayTableSelectionModal(tables) {

            // Create table list HTML with search
            let tablesListHTML = '';
            if (tables.length === 0) {
                tablesListHTML = `
                    <div class="text-center py-8">
                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        <p class="text-gray-600 text-xs font-medium">No tables available</p>
                    </div>
                `;
            } else {
                tables.forEach(table => {
                    const balance = parseFloat(table.balance || 0);
                    const balanceClass = balance > 0 ? 'text-orange-500 font-bold' : 'text-teal-600 font-semibold';
                    const balanceText = balance > 0 ? `N$${balance.toFixed(2)}` : 'N$0.00';
                    
                    tablesListHTML += `
                        <div class="table-item bg-white rounded-lg p-2 mb-1 cursor-pointer hover:bg-gray-200 transition-colors duration-200 relative" 
                             data-id="${table.id}" 
                             data-name="${table.name.toLowerCase()}"
                             data-number="${table.number}"
                             onclick="selectTable(${table.id})">
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2 min-w-0" style="max-width: 50%;">
                                    <svg class="w-4 h-4 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                    <span class="font-medium text-gray-700 truncate">${table.name}</span>
                                </div>
                                <div class="flex items-center gap-2 ml-auto flex-shrink-0">
                                    <span class="${balanceClass} font-medium whitespace-nowrap px-2 py-0.5 rounded-full text-xs bg-gray-100 border border-gray-200" style="min-width: 65px; text-align: center;">
                                        ${balanceText}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }

            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Choose Table</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <!-- Search Bar and Create Table Button in same row -->
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <input type="text" 
                                       id="tableSearch" 
                                       class="w-full h-10 px-3 pl-9 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                       placeholder="Search...">
                                <svg class="absolute left-2.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <button id="createTableBtn" 
                                    class="h-10 bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-3 rounded-lg transition-colors duration-200 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <!-- Sortable Header -->
                        <div class="bg-[#f3f4f6] rounded-lg p-2 mb-1">
                            <div class="flex items-center justify-between gap-2 text-xs text-gray-600 font-medium">
                                <div class="flex items-center gap-1.5 min-w-0" style="max-width: 50%;">
                                    <span>Table</span>
                                </div>
                                <div class="flex items-center gap-1.5 ml-auto flex-shrink-0">
                                    <span>Balance</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tables List -->
                        <div id="tablesListContainer" class="max-h-64 overflow-y-auto custom-scrollbar bg-[#f3f4f6] rounded-lg p-1.5" style="min-height: 100px;">
                            ${tablesListHTML}
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Next',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg hidden',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    // Check if a table was selected
                    const selectedItem = document.querySelector('.table-item.bg-gray-300');
                    if (!selectedItem) {
                        Swal.showValidationMessage('<span class="text-red-500 text-sm">Please select a table first</span>');
                        return false;
                    }
                    const tableId = selectedItem.getAttribute('data-id');
                    const nameElement = selectedItem.querySelector('span');
                    const tableName = nameElement ? nameElement.textContent.trim() : `Table ${tableId}`;
                    return { tableId, tableName };
                },
                didOpen: () => {
                    let selectedTableId = null;
                    
                    // Search functionality
                    const searchInput = document.getElementById('tableSearch');
                    const tablesListContainer = document.getElementById('tablesListContainer');
                    if (searchInput) {
                        searchInput.addEventListener('input', function(e) {
                            const searchTerm = e.target.value.toLowerCase();
                            const tableItems = document.querySelectorAll('.table-item');
                            let visibleCount = 0;
                            
                            tableItems.forEach(item => {
                                const name = item.getAttribute('data-name') || '';
                                const number = item.getAttribute('data-number') || '';
                                if (name.includes(searchTerm) || number.includes(searchTerm)) {
                                    item.style.display = 'block';
                                    visibleCount++;
                                } else {
                                    item.style.display = 'none';
                                }
                            });
                            
                            // Show/hide "no results" message
                            let noResultsMsg = tablesListContainer.querySelector('.no-results-msg');
                            if (visibleCount === 0 && searchTerm.length > 0) {
                                if (!noResultsMsg) {
                                    noResultsMsg = document.createElement('div');
                                    noResultsMsg.className = 'no-results-msg text-center py-8 text-gray-500';
                                    noResultsMsg.innerHTML = `
                                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                        </svg>
                                        <p class="text-sm font-medium text-gray-500">No results found</p>
                                    `;
                                    tablesListContainer.appendChild(noResultsMsg);
                                }
                                noResultsMsg.style.display = 'block';
                            } else if (noResultsMsg) {
                                noResultsMsg.style.display = 'none';
                            }
                        });
                    }
                    
                    // Create table button
                    const createTableBtn = document.getElementById('createTableBtn');
                    if (createTableBtn) {
                        createTableBtn.addEventListener('click', function() {
                            showCreateTableModal(tables);
                        });
                    }
                    
                    // Select table on click
                    window.selectTable = function(id) {
                        selectedTableId = id;
                        // Remove previous selection
                        document.querySelectorAll('.table-item').forEach(item => {
                            item.classList.remove('bg-gray-300');
                            item.classList.add('bg-white');
                        });
                        // Highlight selected
                        const selectedItem = document.querySelector(`.table-item[data-id="${id}"]`);
                        let tableName = `Table ${id}`;
                        if (selectedItem) {
                            selectedItem.classList.remove('bg-white', 'hover:bg-gray-200');
                            selectedItem.classList.add('bg-gray-300');
                            // Get table name from the item
                            const nameElement = selectedItem.querySelector('span');
                            if (nameElement) {
                                tableName = nameElement.textContent.trim();
                            }
                        }
                        // Show next button when table is selected
                        const confirmBtn = document.querySelector('.swal2-confirm');
                        if (confirmBtn) {
                            confirmBtn.classList.remove('hidden');
                        }
                        // Automatically proceed to next step
                        setTimeout(() => {
                            Swal.close();
                            proceedWithTable(selectedTableId, tableName);
                        }, 200); // Small delay for visual feedback
                    };
                }
            }).then((result) => {
                // Handle if user clicks Next without selection
                if (result && result.isConfirmed && result.value && result.value.tableId) {
                    const tableName = result.value.tableName || `Table ${result.value.tableId}`;
                    proceedWithTable(result.value.tableId, tableName);
                } else if (result && result.isConfirmed && !result.value) {
                    // User tried to proceed without selection
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Selection',
                        text: 'Please select a table first',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        }

        function showCreateTableModal(existingTables) {
            Swal.fire({
                title: '<h1 class="text-xl font-semibold text-gray-700 mb-3">Create New Table</h1>',
                html: `
                    <div class="space-y-3" style="max-width: 500px;">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Table Name <span class="text-red-500">*</span></label>
                            <input type="text" 
                                   id="newTableName" 
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="Enter table name" 
                                   autocomplete="off">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Create',
                confirmButtonClass: 'swal2-confirm-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                cancelButtonClass: 'swal2-cancel-btn bg-[#f3f4f6] hover:bg-gray-200 text-gray-700 font-medium px-6 py-2 rounded-lg',
                focusConfirm: false,
                customClass: {
                    popup: 'rounded-xl shadow-lg bg-white',
                },
                allowOutsideClick: false,
                preConfirm: () => {
                    const name = document.getElementById('newTableName').value.trim();
                    
                    if (!name) {
                        Swal.showValidationMessage('<span class="text-red-500">Table name is required</span>');
                        return false;
                    }
                    
                    return { name };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create table via API
                    fetch('create_table.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(result.value)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Table Created',
                                text: `Table "${data.table.name}" created successfully`,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                // Reload tables and show selection modal again
                                fetch('get_tables_with_balances.php')
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            displayTableSelectionModal(data.tables);
                                        }
                                    });
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to create table', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error creating table:', error);
                        Swal.fire('Error', 'Failed to create table', 'error');
                    });
                }
            });
        }

        function proceedWithTable(tableId, tableName = null) {
            // Use provided table name or default
            if (!tableName) {
                tableName = `Table ${tableId}`;
            }

            // Process the tab sale directly without due date
            const saleData = {
                table_id: tableId,
                table_name: tableName,
                items: cart,
                total: parseFloat(document.getElementById('cartTotal').innerText),
                cash_received: 0,
                cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>'
            };

            // Final confirmation before processing tab sale
            Swal.fire({
                icon: 'success',
                title: 'Confirm Order',
                confirmButtonText: 'OK',
                footer: `
                    <div style="display: flex; justify-content: center; align-items: center;">
                        <a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em;'>
                            <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                        </a>
                    </div>
                `,
                allowOutsideClick: false,
                focusConfirm: false
            }).then(ok => {
                if (!ok.isConfirmed) return;
                
                // Process the tab sale using process_tab.php
                fetch('process_tab.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        table_id: tableId,
                        table_name: tableName,
                        items: saleData.items,
                        total: saleData.total,
                        cashier_username: saleData.cashier_username
                    }),
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        saleData.tab_id = result.tab_id;
                        saleData.table_name = tableName;
                        cashSound.play();
                        // Always print kitchen ticket automatically
                        saleData.print_only = true;
                        const pf = (typeof window.sendToPrinter === 'function') ? (d) => window.sendToPrinter(d) : (d) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(r => r.json());
                        pf(saleData).catch(printError => console.error('Kitchen ticket printing error:', printError));
                        clearCart();
                        refreshProductQuantities();
                        closeMobileCart();
                        Swal.fire({icon:'success', title:'Order Placed', text: `Added to ${tableName}`, timer:1200, showConfirmButton:false});
                    } else {
                        Swal.fire('Error', result.message || 'Failed to process tab sale', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Could not process tab sale', 'error');
                });
            });
        }

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
        
        function reverseTransaction(event) {
            if (event) event.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.close();
            }
            return false;
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
        document.addEventListener('DOMContentLoaded', function() {
            initializePageScripts();
            // Initialize Lucide icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

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
