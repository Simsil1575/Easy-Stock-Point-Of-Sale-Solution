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
$db = new PDO('sqlite:../pos.db');
// Set the default timezone to Namibian time
date_default_timezone_set('Africa/Harare');

// Fetch products from the database
$stmt = $db->query('
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
    GROUP BY p.id
    ORDER BY total_sold DESC
');

$products = [];
$lowStock = [];
$outOfStock = [];

// Fetch unique categories
$catStmt = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = [];
while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[] = $cat['category'];
}

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
    if ($row['quantity'] <= 0) {
        $outOfStock[] = $row;
    } else if ($row['quantity'] < 5) {
        $lowStock[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="3.4.16"></script>
    <style>
        .toast-notification {
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
            z-index: 99999 !important;
            position: fixed !important;
        }
        .calculator-popup {
            min-width: 250px;
        }
        .calculator-icon {
            padding: 8px;
            margin: -8px;
            display: inline-block;
            position: relative;
        }
        .calculator-icon::after {
            content: '';
            position: absolute;
            top: -8px;
            left: -8px;
            right: -8px;
            bottom: -8px;
            z-index: -1;
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
            z-index: 80; /* Below sidebar (10000) and hamburger (10000) */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
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
        }

        /* Table container responsive */
        .mobile-table-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Ensure parent containers don't overflow */
        @media (max-width: 640px) {
            .mobile-table-container,
            .mobile-table-container > table {
                max-width: 100%;
                box-sizing: border-box;
            }
            
            /* Force table to fit container */
            .mobile-table-container table {
                width: 100% !important;
                max-width: 100% !important;
            }
            
            /* Ensure all columns respect their width */
            .mobile-table-container table th,
            .mobile-table-container table td {
                box-sizing: border-box;
            }
        }

        /* Ensure table fits on mobile without horizontal scroll */
        @media (max-width: 640px) {
            .mobile-table-container {
                overflow-x: hidden;
                max-width: 100%;
                width: 100%;
            }
            
            .mobile-table-container table {
                font-size: 0.65rem;
                table-layout: fixed;
                width: 100%;
                max-width: 100%;
                border-collapse: collapse;
            }
            
            /* Minimal padding on mobile */
            table th,
            table td {
                padding: 0.375rem 0.25rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            /* Product name column - allow some space but truncate */
            table th:nth-child(1),
            table td:nth-child(1) {
                width: 25%;
                max-width: 25%;
                padding-left: 0.5rem;
                padding-right: 0.25rem;
            }
            
            /* Quantity column - compact */
            table th:nth-child(2),
            table td:nth-child(2) {
                width: 10%;
                max-width: 10%;
                text-align: center;
                padding: 0.375rem 0.125rem;
            }
            
            /* Price column - compact */
            table th:nth-child(3),
            table td:nth-child(3) {
                width: 12%;
                max-width: 12%;
                text-align: center;
                padding: 0.375rem 0.125rem;
            }
            
            /* Cost column - compact */
            table th:nth-child(4),
            table td:nth-child(4) {
                width: 12%;
                max-width: 12%;
                text-align: center;
                padding: 0.375rem 0.125rem;
            }
            
            /* Image column - very compact */
            table th:nth-child(5),
            table td:nth-child(5) {
                width: 8%;
                max-width: 8%;
                text-align: center;
                padding: 0.375rem 0.125rem;
            }
            
            /* Actions column - compact with icons only */
            table th:nth-child(6),
            table td:nth-child(6) {
                width: 13%;
                max-width: 13%;
                text-align: center;
                padding: 0.375rem 0.25rem;
                padding-right: 0.5rem;
            }
            
            /* Make images smaller on mobile */
            table td:nth-child(5) img.mobile-table-image,
            table td:nth-child(5) .mobile-table-image {
                width: 1.5rem !important;
                height: 1.5rem !important;
                max-width: 1.5rem !important;
                max-height: 1.5rem !important;
            }
            
            table td:nth-child(5) .fas.fa-cube.mobile-table-icon,
            table td:nth-child(5) .mobile-table-icon {
                font-size: 0.875rem !important;
            }
            
            /* Make action links use icons only on mobile - very compact */
            table td:nth-child(6) a {
                font-size: 0.5rem;
                padding: 0.25rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin: 0 0.0625rem;
                min-width: 1.25rem;
                min-height: 1.25rem;
                white-space: nowrap;
            }
            
            /* Make icons smaller on mobile */
            table td:nth-child(6) a svg {
                width: 0.75rem;
                height: 0.75rem;
                display: block;
            }
            
            table td:nth-child(6) a span {
                display: none;
            }
            
            /* Remove sort icons on mobile to save space */
            table th svg {
                display: none;
            }
            
            /* Make header text smaller */
            table th {
                font-size: 0.6rem;
                font-weight: 600;
            }
            
            /* Ensure table doesn't overflow */
            table {
                box-sizing: border-box;
            }
        }
        
        /* Very small phones (320px - 375px) */
        @media (max-width: 375px) {
            .mobile-table-container table {
                font-size: 0.6rem;
            }
            
            table th,
            table td {
                padding: 0.25rem 0.125rem;
            }
            
            table th:nth-child(1),
            table td:nth-child(1) {
                padding-left: 0.375rem;
            }
            
            table th:nth-child(6),
            table td:nth-child(6) {
                padding-right: 0.375rem;
            }
            
            table td:nth-child(5) img.mobile-table-image,
            table td:nth-child(5) .mobile-table-image {
                width: 1.25rem !important;
                height: 1.25rem !important;
                max-width: 1.25rem !important;
                max-height: 1.25rem !important;
            }
            
            table td:nth-child(6) a {
                padding: 0.1875rem;
                min-width: 1rem;
                min-height: 1rem;
            }
            
            table td:nth-child(6) a svg {
                width: 0.625rem;
                height: 0.625rem;
            }
        }
        
        /* Tablet styles */
        @media (min-width: 641px) and (max-width: 1023px) {
            .mobile-table-container table {
                table-layout: auto;
                font-size: 0.75rem;
            }
            
            table th {
                padding: 0.75rem 1rem;
            }
            
            table td {
                padding: 0.875rem 1rem;
            }
            
            /* Show text on tablet for actions */
            table td:nth-child(6) a svg {
                display: none;
            }
            
            table td:nth-child(6) a span {
                display: inline;
            }
        }
        
        /* Desktop table - keep original size */
        @media (min-width: 1024px) {
            .mobile-table-container {
                overflow-x: visible;
            }
            
            .mobile-table-container table {
                table-layout: auto;
                font-size: 0.875rem;
            }
            
            /* Restore original desktop padding - override mobile styles */
            .mobile-table-container table th:first-child {
                padding: 1.5rem 1.5rem !important; /* py-6 px-6 */
            }
            
            .mobile-table-container table th:not(:first-child) {
                padding: 0.75rem 1.5rem !important; /* py-3 px-6 */
            }
            
            .mobile-table-container table td {
                padding: 1rem 1.5rem !important; /* py-4 px-6 */
            }
            
            /* Remove fixed width constraints on desktop */
            .mobile-table-container table th:nth-child(1),
            .mobile-table-container table td:nth-child(1),
            .mobile-table-container table th:nth-child(2),
            .mobile-table-container table td:nth-child(2),
            .mobile-table-container table th:nth-child(3),
            .mobile-table-container table td:nth-child(3),
            .mobile-table-container table th:nth-child(4),
            .mobile-table-container table td:nth-child(4),
            .mobile-table-container table th:nth-child(5),
            .mobile-table-container table td:nth-child(5),
            .mobile-table-container table th:nth-child(6),
            .mobile-table-container table td:nth-child(6) {
                width: auto !important;
                min-width: auto !important;
            }
            
            /* Remove sticky positioning on desktop */
            .mobile-table-container table th:nth-child(6),
            .mobile-table-container table td:nth-child(6) {
                position: static !important;
            }
        }
    </style>
</head>
<body class="bg-gray-50 overflow-x-hidden">
    <div class="flex min-h-screen">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <!-- Fixed Header -->
            <div class="fixed top-0 left-0 lg:left-64 right-0 z-50 bg-gray-50 shadow-sm">
                <div class="w-full max-w-7xl mx-auto px-3 sm:px-4 md:px-6 lg:px-8">
                    <!-- Mobile: Two Row Layout -->
                    <div class="lg:hidden">
                        <!-- Top Row: Title and Icons -->
                        <div class="flex items-center justify-between gap-2 sm:gap-3 py-3 sm:py-4">
                            <!-- Left Side: Hamburger, Title, Action Icons -->
                            <div class="flex items-center gap-2 sm:gap-3 md:gap-4 flex-1 min-w-0">
                                <!-- Mobile Hamburger Menu Button -->
                                <div class="hamburger bg-[#f3f4f6] p-2 rounded flex-shrink-0" onclick="toggleSidebar()">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                
                                <!-- Title -->
                                <h1 class="text-lg sm:text-xl md:text-2xl font-bold truncate flex-shrink-0">Inventory Management</h1>
                                
                                <!-- Action Icons Group -->
                                <div class="flex items-center gap-1.5 sm:gap-2 md:gap-3 flex-shrink-0 ml-auto">
                                    <!-- Notification Icon -->
                                    <div class="relative cursor-pointer flex-shrink-0">
                                        <svg onclick="toggleNotifications(event)" class="h-5 w-5 sm:h-6 sm:w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200 cursor-pointer" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                        </svg>
                                        <?php
                                        $notificationCount = count($outOfStock) + count($lowStock);
                                        if ($notificationCount > 0): ?>
                                            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] rounded-full min-h-[1rem] min-w-[1rem] px-1 flex items-center justify-center pointer-events-none"><?= $notificationCount ?></span>
                                        <?php endif; ?>
                                        
                                        <!-- Notifications Dropdown -->
                                        <div id="notificationsDropdown" class="hidden absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-80 md:w-96 max-w-[90vw] sm:max-w-none bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar">
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
                                                                            <a href="edit.php?id=<?= $product['id'] ?>" class="text-gray-700 hover:text-teal-600 transition-colors">
                                                                                <?= htmlspecialchars($product['name']) ?>
                                                                            </a>
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
                                                                                <a href="edit.php?id=<?= $product['id'] ?>" class="text-gray-700 hover:text-teal-600 transition-colors">
                                                                                    <?= htmlspecialchars($product['name']) ?>
                                                                                </a>
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
                                    
                                    <!-- Truck Icon for Receiving -->
                                    <div class="relative cursor-pointer flex-shrink-0" title="Stock Receiving">
                                        <svg onclick="window.location.href='receiving'" class="h-5 w-5 sm:h-6 sm:w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                                        </svg>
                                    </div>
                                    
                                    <!-- Clipboard Icon for Stock Taking -->
                                    <div class="relative cursor-pointer flex-shrink-0" title="Stock Taking">
                                        <svg onclick="window.location.href='stock_taking'" class="h-5 w-5 sm:h-6 sm:w-6 text-gray-400 hover:text-blue-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bottom Row: Search and Action Buttons -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3 pb-3 sm:pb-4 border-t border-gray-200 pt-3">
                            <!-- Search Input -->
                            <div class="relative flex-1 sm:flex-initial sm:min-w-[200px] md:min-w-[250px] order-2 sm:order-1">
                                <input type="text" id="searchInput" placeholder="Search products..." class="w-full pl-9 sm:pl-10 pr-3 sm:pr-4 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 absolute left-2.5 sm:left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0 order-1 sm:order-2">
                                <a href="stock_tracking.php?export_pdf=true&report_type=inventory" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 text-xs sm:text-sm text-white rounded-md bg-gradient-to-r from-teal-500 to-teal-500 hover:from-teal-600 hover:to-teal-600 shadow-sm transition-colors whitespace-nowrap flex-shrink-0">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1.5 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <span class="hidden sm:inline">Inventory PDF</span>
                                    <span class="sm:hidden">PDF</span>
                                </a>
                                
                                <a href="add_product" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 text-xs sm:text-sm bg-gray-300 hover:bg-gray-400 text-black font-medium rounded-md shadow-sm whitespace-nowrap flex-shrink-0 transition-colors">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1.5 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                    </svg>
                                    <span class="hidden sm:inline">Add Product</span>
                                    <span class="sm:hidden">Add</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Desktop: Single Row Layout -->
                    <div class="hidden lg:flex items-center justify-between gap-4 py-4">
                        <!-- Left Side: Title and Action Icons -->
                        <div class="flex items-center gap-4 flex-shrink-0">
                            <!-- Title -->
                            <h1 class="text-2xl lg:text-3xl font-bold whitespace-nowrap">Inventory Management</h1>
                            
                            <!-- Action Icons Group -->
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <!-- Notification Icon -->
                                <div class="relative cursor-pointer flex-shrink-0">
                                    <svg onclick="toggleNotifications(event)" class="h-6 w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200 cursor-pointer" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                    <?php
                                    $notificationCount = count($outOfStock) + count($lowStock);
                                    if ($notificationCount > 0): ?>
                                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full min-h-[1.25rem] min-w-[1.25rem] px-1 flex items-center justify-center pointer-events-none"><?= $notificationCount ?></span>
                                    <?php endif; ?>
                                    
                                    <!-- Notifications Dropdown -->
                                    <div id="notificationsDropdownDesktop" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar">
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
                                                                        <a href="edit.php?id=<?= $product['id'] ?>" class="text-gray-700 hover:text-teal-600 transition-colors">
                                                                            <?= htmlspecialchars($product['name']) ?>
                                                                        </a>
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
                                                                            <a href="edit.php?id=<?= $product['id'] ?>" class="text-gray-700 hover:text-teal-600 transition-colors">
                                                                                <?= htmlspecialchars($product['name']) ?>
                                                                            </a>
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
                                
                                <!-- Truck Icon for Receiving -->
                                <div class="relative cursor-pointer flex-shrink-0" title="Stock Receiving">
                                    <svg onclick="window.location.href='receiving'" class="h-6 w-6 text-gray-400 hover:text-teal-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                                    </svg>
                                </div>
                                
                                <!-- Clipboard Icon for Stock Taking -->
                                <div class="relative cursor-pointer flex-shrink-0" title="Stock Taking">
                                    <svg onclick="window.location.href='stock_taking'" class="h-6 w-6 text-gray-400 hover:text-blue-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Side: Search and Action Buttons -->
                        <div class="flex items-center gap-3 flex-1 justify-end">
                            <!-- Search Input -->
                            <div class="relative flex-1 max-w-md">
                                <input type="text" id="searchInputDesktop" placeholder="Search products..." class="w-full pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                                <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <a href="stock_tracking.php?export_pdf=true&report_type=inventory" class="inline-flex items-center justify-center px-4 py-2 text-sm text-white rounded-md bg-gradient-to-r from-teal-500 to-teal-500 hover:from-teal-600 hover:to-teal-600 shadow-sm transition-colors whitespace-nowrap">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <span>Inventory PDF</span>
                                </a>
                                
                                <a href="add_product" class="inline-flex items-center justify-center px-4 py-2 text-sm bg-gray-300 hover:bg-gray-400 text-black font-medium rounded-md shadow-sm whitespace-nowrap transition-colors">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                    </svg>
                                    <span>Add Product</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Spacer for fixed header -->
            <div class="h-[120px] sm:h-[140px] lg:h-20 mb-4"></div>
            
            <div class="w-full max-w-7xl mx-auto px-3 sm:px-4 md:px-6 lg:px-8">
                <div class="bg-white rounded-lg shadow-sm overflow-hidden w-full">
                    <div class="mobile-table-container w-full">
                    <table class="w-full divide-y divide-gray-200">
                        <thead class="bg-gray-300">
                            <tr>
                                <th scope="col" class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-6 text-left text-[10px] sm:text-xs md:text-sm lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(0)">
                                    <div class="flex items-center">
                                        <span class="hidden sm:inline">Product</span>
                                        <span class="sm:hidden">Prod</span>
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden sm:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-3 text-center text-[10px] sm:text-xs md:text-sm lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(1, true)">
                                    <div class="flex items-center justify-center">
                                        Qty
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden sm:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-3 text-center text-[10px] sm:text-xs md:text-sm lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(2, true)">
                                    <div class="flex items-center justify-center">
                                        Price
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden sm:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-3 text-center text-[10px] sm:text-xs md:text-sm lg:text-xs font-medium text-black uppercase tracking-wider cursor-pointer" onclick="sortTable(3, true)">
                                    <div class="flex items-center justify-center">
                                        Cost
                                        <svg class="w-2.5 h-2.5 sm:w-3 sm:h-3 lg:w-3 lg:h-3 ml-0.5 sm:ml-1.5 lg:ml-1.5 hidden sm:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"></path>
                                        </svg>
                                    </div>
                                </th>
                                <th scope="col" class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-3 text-center text-[10px] sm:text-xs md:text-sm lg:text-xs font-medium text-black uppercase tracking-wider">
                                    <span class="hidden lg:inline">Image</span>
                                    <span class="lg:hidden">Img</span>
                                </th>
                                <th scope="col" class="px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-3 text-center text-[10px] sm:text-xs md:text-sm lg:text-xs font-medium text-black uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="tableBody">
                            <?php
                            $db = new SQLite3('../pos.db');
                            $results = $db->query("SELECT * FROM products");
                            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                                echo "<tr class='hover:bg-gray-50 transition-colors' data-category='" . htmlspecialchars($row['category'] ?? '') . "'>";
                                echo "<td class='px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-[10px] sm:text-xs md:text-sm lg:text-sm font-medium text-black-900 truncate' title='{$row['name']}'>{$row['name']}</td>";
                                echo "<td class='px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm text-black-500'>{$row['quantity']}</td>";
                                echo "<td class='px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm text-black-500'>{$row['price']}</td>";
                                echo "<td class='px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm text-black-500'>{$row['buying_price']}</td>";
                                echo "<td class='px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center'><div class='flex items-center justify-center relative'><img src='../products/{$row['image_url']}' alt='Product' class='w-6 h-6 sm:w-8 sm:h-8 md:w-9 md:h-9 lg:w-10 lg:h-10 rounded-lg object-cover mobile-table-image' style='display:none;' onload=\"this.style.display='block';this.nextElementSibling.style.display='none';\" onerror=\"this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='inline-block';\"><i class='fas fa-cube text-gray-400 text-lg sm:text-xl md:text-2xl lg:text-3xl mobile-table-icon'></i></div></td>";
                                echo "<td class='px-2 sm:px-3 md:px-4 lg:px-6 py-2 sm:py-3 md:py-4 lg:py-4 whitespace-nowrap text-center text-[10px] sm:text-xs md:text-sm lg:text-sm font-medium'>";
                                echo "<a href='edit.php?id={$row['id']}' class='text-teal-600 hover:text-teal-900 mr-0.5 sm:mr-3 lg:mr-3 px-0.5 py-0.5 inline-flex items-center justify-center' title='Edit'>";
                                echo "<svg class='w-3.5 h-3.5 sm:hidden' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'></path></svg>";
                                echo "<span class='hidden sm:inline'>Edit</span>";
                                echo "</a>";
                                echo "<a href='#' data-product-id='{$row['id']}' data-product-name='" . htmlspecialchars($row['name']) . "' class='delete-link text-red-600 hover:text-red-900 px-0.5 py-0.5 inline-flex items-center justify-center' title='Delete'>";
                                echo "<svg class='w-3.5 h-3.5 sm:hidden' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16'></path></svg>";
                                echo "<span class='hidden sm:inline'>Delete</span>";
                                echo "</a>";
                                echo "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>

                    <div class="px-2 sm:px-4 lg:px-6 py-3 sm:py-4 border-t border-gray-200">
                        <!-- Mobile: Compact pagination layout -->
                        <div class="flex flex-col gap-3 sm:hidden">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex gap-1">
                                    <button id="firstPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        <<
                                    </button>
                                    <button id="prevPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        <
                                    </button>
                                </div>
                                <div class="flex items-center gap-2 flex-1 justify-center">
                                    <span id="pageNumber" class="text-[10px] text-gray-700 whitespace-nowrap">Page 1 of 1</span>
                                    <input type="number" id="pageInput" min="1" class="w-12 px-1.5 py-1 border rounded text-[10px] text-center" placeholder="Pg">
                                </div>
                                <div class="flex gap-1">
                                    <button id="nextPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        >
                                    </button>
                                    <button id="lastPage" class="inline-flex items-center justify-center px-2 py-1.5 border border-gray-300 text-[10px] font-medium rounded text-gray-700 bg-white hover:bg-gray-50 min-w-[2rem]">
                                        >>
                                    </button>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <select id="categoryFilter" class="flex-1 px-2 py-1.5 border rounded text-[10px] bg-transparent">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button id="viewAllMobile" class="px-3 py-1.5 border border-gray-300 rounded text-[10px] font-medium text-gray-700 bg-white hover:bg-gray-50 whitespace-nowrap">
                                    View All
                                </button>
                            </div>
                        </div>
                        
                        <!-- Desktop: Full pagination layout -->
                        <div class="hidden sm:flex sm:flex-row sm:justify-between sm:items-center gap-4">
                            <div class="flex gap-2">
                                <button id="firstPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    <<
                                </button>
                                <button id="prevPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </button>
                            </div>
                            <div class="flex flex-col sm:flex-row items-center gap-2 sm:gap-4">
                                <span id="pageNumberDesktop" class="text-xs sm:text-sm text-gray-700">Page 1 of 1</span>
                                <div class="flex items-center gap-2">
                                    <input type="number" id="pageInputDesktop" min="1" class="w-16 sm:w-20 px-2 py-1 border rounded text-xs sm:text-sm" placeholder="Page">
                                </div>
                                <div class="flex items-center gap-2">
                                    <select id="categoryFilterDesktop" class="px-2 py-1 border rounded text-xs sm:text-sm w-full sm:w-auto">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button id="viewAllDesktop" class="px-3 py-1 border border-gray-300 rounded text-xs sm:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 whitespace-nowrap">
                                        View All
                                    </button>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button id="nextPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </button>
                                <button id="lastPageDesktop" class="inline-flex items-center px-2 sm:px-4 py-2 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    >>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="w-full max-w-7xl mx-auto px-3 sm:px-4 md:px-6 lg:px-8">
                <div class="text-center mt-4 sm:mt-6">
                    <a href="stock_tracking" class="text-teal-600 hover:text-teal-800 flex items-center justify-center space-x-2 text-sm sm:text-base transition-colors duration-200">
                        <span>Inventory Logs</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 sm:h-5 sm:w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Set rows per page based on screen size
        let rowsPerPage = window.innerWidth < 640 ? 10 : 6;
        
        // Update rowsPerPage on window resize
        window.addEventListener('resize', () => {
            // Don't reset pagination if showAllMode is active
            if (showAllMode) {
                return;
            }
            rowsPerPage = window.innerWidth < 640 ? 10 : 6;
            showPage(currentPage);
        });
        const tableBody = document.getElementById("tableBody");
        let allRows = Array.from(tableBody.children);
        let rows = [...allRows];
        const pageNumber = document.getElementById("pageNumber");
        let sortDirection = {};
        const totalPages = Math.ceil(rows.length / rowsPerPage);
        let currentPage = 1;
        const searchInput = document.getElementById('searchInput');
        const searchInputDesktop = document.getElementById('searchInputDesktop');
        const categoryFilter = document.getElementById('categoryFilter');
        const categoryFilterDesktop = document.getElementById('categoryFilterDesktop');
        
        // Sync search inputs
        function syncSearchInputs(value) {
            if (searchInput) searchInput.value = value;
            if (searchInputDesktop) searchInputDesktop.value = value;
        }
        
        // Get active search input value
        function getSearchValue() {
            return (searchInput && searchInput.value) || (searchInputDesktop && searchInputDesktop.value) || '';
        }
        
        // Store current page and category in sessionStorage
        function saveCurrentPage() {
            sessionStorage.setItem('inventoryCurrentPage', currentPage);
            const activeFilter = categoryFilter || categoryFilterDesktop;
            if (activeFilter) {
                sessionStorage.setItem('inventoryCategory', activeFilter.value);
            }
        }
        
        // Retrieve current page and category from sessionStorage
        function loadCurrentPage() {
            const savedPage = sessionStorage.getItem('inventoryCurrentPage');
            const savedCategory = sessionStorage.getItem('inventoryCategory');
            if (savedPage) {
                currentPage = parseInt(savedPage);
            }
            if (savedCategory) {
                if (categoryFilter) categoryFilter.value = savedCategory;
                if (categoryFilterDesktop) categoryFilterDesktop.value = savedCategory;
                filterRows(getSearchValue());
            }
        }
        
        // Load saved page on page initialization
        loadCurrentPage();

        // Add event listeners for both search inputs
        function handleSearchInput(e) {
            const value = e.target.value;
            // Sync both inputs
            syncSearchInputs(value);
            // If user types in search, exit showAllMode and use pagination
            if (showAllMode) {
                showAllMode = false;
                resetPagination();
            }
            filterRows(value);
        }
        
        if (searchInput) {
            searchInput.addEventListener('input', handleSearchInput);
        }
        if (searchInputDesktop) {
            searchInputDesktop.addEventListener('input', handleSearchInput);
        }

        // Helper function to handle category filter change
        function handleCategoryFilter() {
            // If category is selected, exit showAllMode and use pagination
            if (showAllMode) {
                showAllMode = false;
                resetPagination();
            }
            
            // Sync both filters
            const activeFilter = categoryFilter || categoryFilterDesktop;
            const selectedValue = activeFilter ? activeFilter.value : '';
            if (categoryFilter && categoryFilterDesktop) {
                categoryFilter.value = selectedValue;
                categoryFilterDesktop.value = selectedValue;
            }
            filterRows(getSearchValue());
        }

        // Add event listeners for category filters
        if (categoryFilter) categoryFilter.addEventListener('change', handleCategoryFilter);
        if (categoryFilterDesktop) categoryFilterDesktop.addEventListener('change', handleCategoryFilter);
        
        // View All button functionality - toggle between show all and pagination
        let showAllMode = false;
        
        function handleViewAll() {
            // Toggle showAllMode
            showAllMode = !showAllMode;
            
            if (showAllMode) {
                // Enter show all mode
                if (categoryFilter) categoryFilter.value = '';
                if (categoryFilterDesktop) categoryFilterDesktop.value = '';
                filterRows(getSearchValue());
                showAllProducts();
            } else {
                // Exit show all mode and return to pagination
                resetPagination();
            }
        }
        
        function showAllProducts() {
            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');
            
            // Show only filtered rows (all matching products)
            rows.forEach(row => row.style.display = 'table-row');
            
            // Update page number display to show "All Products"
            const pageNumberMobile = document.getElementById('pageNumber');
            const pageNumberDesktop = document.getElementById('pageNumberDesktop');
            if (pageNumberMobile) pageNumberMobile.textContent = `All Products (${rows.length})`;
            if (pageNumberDesktop) pageNumberDesktop.textContent = `All Products (${rows.length})`;
            
            // Hide pagination controls
            const paginationControls = document.querySelectorAll('#firstPage, #prevPage, #nextPage, #lastPage, #firstPageDesktop, #prevPageDesktop, #nextPageDesktop, #lastPageDesktop, #pageInput, #pageInputDesktop');
            paginationControls.forEach(control => {
                if (control) control.style.display = 'none';
            });
            
            // Ensure showAllMode flag is set
            showAllMode = true;
        }
        
        function resetPagination() {
            showAllMode = false;
            // Show pagination controls
            const paginationControls = document.querySelectorAll('#firstPage, #prevPage, #nextPage, #lastPage, #firstPageDesktop, #prevPageDesktop, #nextPageDesktop, #lastPageDesktop, #pageInput, #pageInputDesktop');
            paginationControls.forEach(control => {
                if (control) control.style.display = '';
            });
            // Reset to first page and show paginated results
            currentPage = 1;
            showPage(currentPage);
        }
        
        const viewAllMobile = document.getElementById('viewAllMobile');
        const viewAllDesktop = document.getElementById('viewAllDesktop');
        if (viewAllMobile) viewAllMobile.addEventListener('click', handleViewAll);
        if (viewAllDesktop) viewAllDesktop.addEventListener('click', handleViewAll);

        function filterRows(searchTerm) {
            // If searchTerm is not provided, get from active input
            if (searchTerm === undefined) {
                searchTerm = getSearchValue();
            }
            const activeFilter = categoryFilter || categoryFilterDesktop;
            const selectedCategory = activeFilter ? activeFilter.value : '';
            rows = allRows.filter(row => {
                const productName = row.children[0].textContent.toLowerCase();
                const productCategory = row.dataset.category || '';
                const matchesSearch = productName.includes(searchTerm.toLowerCase());
                const matchesCategory = !selectedCategory || productCategory === selectedCategory;
                return matchesSearch && matchesCategory;
            });
            
            // If showAllMode is active, show all products; otherwise use pagination
            if (showAllMode) {
                showAllProducts();
            } else {
                currentPage = 1;
                showPage(currentPage);
            }
            saveCurrentPage();
        }

        function showPage(page) {
            // Don't paginate if showAllMode is active
            if (showAllMode) {
                return;
            }
            
            const start = (page - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
            
            allRows.forEach(row => row.style.display = 'none');
            rows.slice(start, end).forEach(row => row.style.display = 'table-row');
            
            // Update both mobile and desktop page numbers
            const pageNumberMobile = document.getElementById('pageNumber');
            const pageNumberDesktop = document.getElementById('pageNumberDesktop');
            if (pageNumberMobile) pageNumberMobile.textContent = `Page ${page} of ${maxPage}`;
            if (pageNumberDesktop) pageNumberDesktop.textContent = `Page ${page} of ${maxPage}`;
            
            // Update both mobile and desktop page inputs
            const pageInputMobile = document.getElementById('pageInput');
            const pageInputDesktop = document.getElementById('pageInputDesktop');
            if (pageInputMobile) {
                pageInputMobile.value = page;
                pageInputMobile.placeholder = `Pg (1-${maxPage})`;
            }
            if (pageInputDesktop) {
                pageInputDesktop.value = page;
                pageInputDesktop.placeholder = `Page (1-${maxPage})`;
            }
            
            saveCurrentPage();
        }

        function sortTable(columnIndex, isNumeric = false) {
            if (!sortDirection[columnIndex]) {
                sortDirection[columnIndex] = 'asc';
            } else {
                sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            }

            rows.sort((a, b) => {
                let aValue = a.children[columnIndex].textContent.trim();
                let bValue = b.children[columnIndex].textContent.trim();

                if (isNumeric) {
                    aValue = parseFloat(aValue);
                    bValue = parseFloat(bValue);
                } else {
                    aValue = aValue.toLowerCase();
                    bValue = bValue.toLowerCase();
                }

                if (sortDirection[columnIndex] === 'asc') {
                    return aValue > bValue ? 1 : -1;
                } else {
                    return aValue < bValue ? 1 : -1;
                }
            });

            // Clear and re-append sorted rows
            while (tableBody.firstChild) {
                tableBody.removeChild(tableBody.firstChild);
            }
            rows.forEach(row => tableBody.appendChild(row));

            // Only show page if not in showAllMode
            if (!showAllMode) {
                showPage(currentPage);
            }
            saveCurrentPage();
        }

        // Mobile pagination controls
        const prevPageMobile = document.getElementById("prevPage");
        const nextPageMobile = document.getElementById("nextPage");
        const firstPageMobile = document.getElementById("firstPage");
        const lastPageMobile = document.getElementById("lastPage");
        const pageInputMobile = document.getElementById("pageInput");
        
        // Desktop pagination controls
        const prevPageDesktop = document.getElementById("prevPageDesktop");
        const nextPageDesktop = document.getElementById("nextPageDesktop");
        const firstPageDesktop = document.getElementById("firstPageDesktop");
        const lastPageDesktop = document.getElementById("lastPageDesktop");
        const pageInputDesktop = document.getElementById("pageInputDesktop");
        
        // Helper function to handle prev page
        function handlePrevPage() {
            if (currentPage > 1) {
                currentPage--;
                showPage(currentPage);
                saveCurrentPage();
            }
        }
        
        // Helper function to handle next page
        function handleNextPage() {
            if (currentPage * rowsPerPage < rows.length) {
                currentPage++;
                showPage(currentPage);
                saveCurrentPage();
            }
        }
        
        // Helper function to handle first page
        function handleFirstPage() {
            currentPage = 1;
            showPage(currentPage);
            saveCurrentPage();
        }
        
        // Helper function to handle last page
        function handleLastPage() {
            currentPage = Math.ceil(rows.length / rowsPerPage);
            showPage(currentPage);
            saveCurrentPage();
        }
        
        // Helper function to handle page input
        function handlePageInput(inputElement) {
            const desiredPage = parseInt(inputElement.value);
            if (!isNaN(desiredPage)) {
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                currentPage = Math.min(Math.max(1, desiredPage), maxPage);
                showPage(currentPage);
                saveCurrentPage();
            }
        }
        
        // Add event listeners for mobile
        if (prevPageMobile) prevPageMobile.addEventListener("click", handlePrevPage);
        if (nextPageMobile) nextPageMobile.addEventListener("click", handleNextPage);
        if (firstPageMobile) firstPageMobile.addEventListener("click", handleFirstPage);
        if (lastPageMobile) lastPageMobile.addEventListener("click", handleLastPage);
        if (pageInputMobile) pageInputMobile.addEventListener("change", () => handlePageInput(pageInputMobile));
        
        // Add event listeners for desktop
        if (prevPageDesktop) prevPageDesktop.addEventListener("click", handlePrevPage);
        if (nextPageDesktop) nextPageDesktop.addEventListener("click", handleNextPage);
        if (firstPageDesktop) firstPageDesktop.addEventListener("click", handleFirstPage);
        if (lastPageDesktop) lastPageDesktop.addEventListener("click", handleLastPage);
        if (pageInputDesktop) pageInputDesktop.addEventListener("change", () => handlePageInput(pageInputDesktop));

        // Add event listeners for inline editing
        tableBody.addEventListener('dblclick', (e) => {
            const cell = e.target;
            if (cell.tagName === 'TD' && cell.cellIndex < 4) { // Only allow editing for first 4 columns
                enableEditing(cell);
            }
        });

        function enableEditing(cell) {
            const originalValue = cell.textContent.trim();
            cell.dataset.originalValue = originalValue; // Store original value for error handling
            const input = document.createElement('input');
            input.type = cell.cellIndex === 0 ? 'text' : 'number';
            input.value = originalValue;
            input.classList.add('px-2', 'py-1', 'border', 'rounded');
            
            // Set input width to match column width exactly
            const cellWidth = cell.offsetWidth;
            input.style.width = `${cellWidth}px`;
            input.style.maxWidth = `${cellWidth}px`;
            input.style.minWidth = `${cellWidth}px`;
            input.style.boxSizing = 'border-box';

            cell.textContent = '';
            cell.appendChild(input);

            if (cell.cellIndex === 3) {
                const calculatorIcon = document.createElement('span');
                calculatorIcon.innerHTML = '<i class="fas fa-calculator text-gray-500 hover:text-teal-500 cursor-pointer ml-2"></i>';
                calculatorIcon.classList.add('calculator-icon');
                calculatorIcon.style.padding = '8px';  // Add padding around the icon
                calculatorIcon.style.margin = '-8px';  // Compensate for the padding to maintain layout
                calculatorIcon.style.display = 'inline-block';  // Ensure padding works correctly
                cell.appendChild(calculatorIcon);

                const calculatorPopup = document.createElement('div');
                calculatorPopup.className = 'calculator-popup hidden absolute bg-white p-4 rounded-lg shadow-lg border border-gray-200 z-50';
                calculatorPopup.innerHTML = `
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Total Cost</label>
                        <input type="number" id="totalCost" class="w-full px-3 py-2 border rounded-md" placeholder="Enter total cost">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Number of Items</label>
                        <input type="number" id="itemCount" class="w-full px-3 py-2 border rounded-md" placeholder="Enter number of items">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cost per Item</label>
                        <div id="costPerItem" class="w-full px-3 py-2 bg-gray-50 rounded-md">0.00</div>
                    </div>
                    <button id="applyCost" class="w-full bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700">Apply</button>
                `;
                document.body.appendChild(calculatorPopup);

                const updatePopupPosition = () => {
                    const rect = calculatorIcon.getBoundingClientRect();
                    calculatorPopup.style.top = `${rect.bottom + window.scrollY + 5}px`;
                    calculatorPopup.style.left = `${rect.left + window.scrollX}px`;
                };

                let hideTimeout;

                calculatorIcon.addEventListener('mouseenter', (e) => {
                    e.stopPropagation();
                    clearTimeout(hideTimeout);
                    calculatorPopup.classList.remove('hidden');
                    updatePopupPosition();
                });

                calculatorIcon.addEventListener('mouseleave', (e) => {
                    const toElement = e.relatedTarget;
                    if (!calculatorPopup.contains(toElement)) {
                        hideTimeout = setTimeout(() => {
                            calculatorPopup.classList.add('hidden');
                        }, 500); // 500ms delay before hiding
                    }
                });

                calculatorPopup.addEventListener('mouseenter', () => {
                    clearTimeout(hideTimeout);
                });

                calculatorPopup.addEventListener('mouseleave', (e) => {
                    const toElement = e.relatedTarget;
                    if (!calculatorIcon.contains(toElement)) {
                        hideTimeout = setTimeout(() => {
                            calculatorPopup.classList.add('hidden');
                        }, 500); // 500ms delay before hiding
                    }
                });

                const totalCostInput = calculatorPopup.querySelector('#totalCost');
                const itemCountInput = calculatorPopup.querySelector('#itemCount');
                const costPerItemDiv = calculatorPopup.querySelector('#costPerItem');
                const applyButton = calculatorPopup.querySelector('#applyCost');

                // Add click handlers to select all text in inputs
                totalCostInput.addEventListener('click', function() {
                    this.select();
                });
                
                itemCountInput.addEventListener('click', function() {
                    this.select();
                });

                const calculateCost = () => {
                    const totalCost = parseFloat(totalCostInput.value) || 0;
                    const itemCount = parseFloat(itemCountInput.value) || 0;
                    const costPerItem = itemCount > 0 ? totalCost / itemCount : 0;
                    costPerItemDiv.textContent = costPerItem.toFixed(2);
                };

                totalCostInput.addEventListener('input', calculateCost);
                itemCountInput.addEventListener('input', calculateCost);

                applyButton.addEventListener('click', () => {
                    const costPerItem = parseFloat(costPerItemDiv.textContent);
                    if (!isNaN(costPerItem)) {
                        input.value = costPerItem.toFixed(2);
                        save();
                        calculatorPopup.classList.add('hidden');
                    }
                });

                document.addEventListener('click', (e) => {
                    if (!calculatorPopup.contains(e.target) && !calculatorIcon.contains(e.target)) {
                        calculatorPopup.classList.add('hidden');
                    }
                });
            }

            input.focus();

            const save = () => {
                const newValue = input.value.trim();
                
                // For quantity field, ensure it's a valid number or empty
                if (cell.cellIndex === 1) { // quantity column
                    if (newValue === '' || newValue === null) {
                        newValue = '0';
                    } else if (!/^\d+$/.test(newValue)) {
                        // If not a valid integer, revert to original value
                        cell.textContent = originalValue;
                        return;
                    }
                }
                
                if (newValue !== originalValue) {
                    updateDatabase(cell, newValue);
                } else {
                    cell.textContent = newValue;
                }
            };

            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    save();
                }
            });

            input.addEventListener('blur', save);
        }

        async function updateDatabase(cell, newValue) {
            const row = cell.parentElement;
            const productId = row.querySelector('a[href*="edit.php"]').href.split('=')[1];
            const column = ['name', 'quantity', 'price', 'buying_price'][cell.cellIndex];
            
            try {
                const response = await fetch('update_product.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: productId,
                        column: column,
                        value: newValue
                    })
                });

                const result = await response.json();
                
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Update failed');
                }
                
                // Update the cell with the new value
                cell.textContent = newValue;
                showToast('Product updated successfully!', 'success');
                saveCurrentPage(); // Save page after update
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to update product: ' + error.message, 'error');
                // Revert to original value
                cell.textContent = cell.dataset.originalValue || originalValue;
            }
        }

        // Add delete functionality
        tableBody.addEventListener('click', (e) => {
            const deleteLink = e.target.closest('.delete-link');
            if (deleteLink) {
                e.preventDefault();
                const productId = deleteLink.getAttribute('data-product-id');
                const productName = deleteLink.getAttribute('data-product-name');
                showDeleteModal(productId, productName);
            }
        });

        // Show beautiful delete confirmation modal
        function showDeleteModal(productId, productName) {
            // Create modal overlay
            const overlay = document.createElement('div');
            overlay.id = 'deleteModalOverlay';
            overlay.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px);';
            
            // Create modal content
            const modal = document.createElement('div');
            modal.className = 'bg-white rounded-2xl shadow-2xl max-w-md w-full transform transition-all';
            modal.style.animation = 'modalSlideIn 0.3s ease-out';
            modal.style.margin = 'auto';
            
            modal.innerHTML = `
                <div class="p-6">
                    <!-- Icon -->
                    <div class="flex justify-center mb-4">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Title -->
                    <h3 class="text-xl font-bold text-gray-900 text-center mb-2">Delete Product?</h3>
                    
                    <!-- Message -->
                    <p class="text-gray-600 text-center mb-1">Are you sure you want to delete</p>
                    <p class="text-gray-900 font-semibold text-center mb-6">"${productName}"?</p>
                    <p class="text-sm text-red-600 text-center mb-6">This action cannot be undone.</p>
                    
                    <!-- Buttons -->
                    <div class="flex gap-3">
                        <button id="cancelDelete" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition-colors">
                            Cancel
                        </button>
                        <button id="confirmDelete" data-product-id="${productId}" class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors shadow-sm">
                            Delete Product
                        </button>
                    </div>
                </div>
            `;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            
            // Add animation keyframes if not exists
            if (!document.getElementById('deleteModalStyles')) {
                const style = document.createElement('style');
                style.id = 'deleteModalStyles';
                style.textContent = `
                    @keyframes modalSlideIn {
                        from {
                            opacity: 0;
                            transform: scale(0.9) translateY(-20px);
                        }
                        to {
                            opacity: 1;
                            transform: scale(1) translateY(0);
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Close on overlay click
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeDeleteModal();
                }
            });
            
            // Cancel button
            document.getElementById('cancelDelete').addEventListener('click', closeDeleteModal);
            
            // Confirm button
            document.getElementById('confirmDelete').addEventListener('click', () => {
                const id = document.getElementById('confirmDelete').getAttribute('data-product-id');
                closeDeleteModal();
                deleteProduct(id);
            });
        }
        
        function closeDeleteModal() {
            const overlay = document.getElementById('deleteModalOverlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => overlay.remove(), 300);
            }
        }

        async function deleteProduct(productId) {
            try {
                const response = await fetch('delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: productId })
                });

                if (!response.ok) {
                    throw new Error('Delete failed');
                }

                // Remove the row from the table
                const row = document.querySelector(`a[data-product-id="${productId}"]`).closest('tr');
                row.remove();
                showToast('Product deleted successfully!', 'success');
                
                // Update pagination
                allRows = Array.from(tableBody.children);
                rows = [...allRows];
                
                // Adjust current page if needed
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                if (currentPage > maxPage) {
                    currentPage = maxPage;
                }
                
                // Only show page if not in showAllMode
                if (!showAllMode) {
                    showPage(currentPage);
                } else {
                    // If in showAllMode, refresh the display
                    showAllProducts();
                }
                saveCurrentPage();
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to delete product', 'error');
            }
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            // Remove any existing toasts first
            const existingToasts = document.querySelectorAll('.toast-notification');
            existingToasts.forEach(toast => toast.remove());

            const icons = {
                success: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`,
                error: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>`,
                info: `<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`
            };

            const toast = document.createElement('div');
            // Position in top right corner - adjust for fixed header on desktop
            const isMobile = window.innerWidth < 1024;
            // On desktop, account for fixed header height (approximately 80px with padding)
            const topPosition = isMobile ? 'top-20' : 'top-20'; // Account for fixed header
            const rightPosition = 'right-4'; // Right corner
            toast.className = `toast-notification fixed ${topPosition} ${rightPosition} px-4 py-3 rounded-md text-white shadow-lg flex items-center gap-2 ${
                type === 'success' ? 'bg-teal-500' : 
                type === 'error' ? 'bg-rose-600' : 
                'bg-sky-500'
            }`;
            
            // Set extremely high z-index using inline style to ensure it's above everything
            toast.style.zIndex = '99999';
            toast.style.position = 'fixed';
            
            // Reset initial state to ensure animation works
            toast.style.transform = 'translateX(100%)';
            toast.style.opacity = '0';
            
            toast.innerHTML = `
                ${icons[type]}
                <span>${message}</span>
            `;

            document.body.appendChild(toast);
            
            // Force reflow to ensure initial state is applied
            toast.offsetHeight;
            
            // Animate in using requestAnimationFrame for smoother animation
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    toast.style.transform = 'translateX(0)';
                    toast.style.opacity = '1';
                });
            });

            setTimeout(() => {
                toast.style.transform = 'translateX(100%)';
                toast.style.opacity = '0';
                setTimeout(() => {
                    toast.remove();
                }, 500);
            }, 3000);
        }

        // Initialize the page display - only if not in showAllMode
        if (!showAllMode) {
            showPage(currentPage);
        }

        // Handle URL parameters for toast notifications
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('delete')) {
            const status = urlParams.get('delete');
            showToast(
                status === 'success' ? 'Product deleted!' : 'Failed to delete product',
                status === 'success' ? 'success' : 'error'
            );
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        if (urlParams.has('add')) {
            showToast('Product added successfully!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    </script>


<script>
    function toggleNotifications(event) {
        // Prevent event from bubbling to click outside handler
        if (event) {
            event.stopPropagation();
        }
        
        const dropdown = document.getElementById('notificationsDropdown');
        const dropdownDesktop = document.getElementById('notificationsDropdownDesktop');
        
        // Determine which dropdown to use based on screen size
        const isDesktop = window.innerWidth >= 1024;
        const targetDropdown = isDesktop ? (dropdownDesktop || dropdown) : (dropdown || dropdownDesktop);
        
        if (targetDropdown) {
            const isHidden = targetDropdown.classList.contains('hidden');
            
            // Close both dropdowns first
            if (dropdown) {
                dropdown.classList.add('hidden', 'opacity-0', 'scale-95');
                dropdown.classList.remove('opacity-100', 'scale-100');
            }
            if (dropdownDesktop) {
                dropdownDesktop.classList.add('hidden', 'opacity-0', 'scale-95');
                dropdownDesktop.classList.remove('opacity-100', 'scale-100');
            }
            
            // Toggle the appropriate dropdown
            if (isHidden) {
                targetDropdown.classList.remove('hidden', 'opacity-0', 'scale-95');
                targetDropdown.classList.add('opacity-100', 'scale-100');
            } else {
                targetDropdown.classList.add('opacity-0', 'scale-95');
                setTimeout(() => {
                    targetDropdown.classList.add('hidden');
                }, 300);
            }
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notificationsDropdown');
        const dropdownDesktop = document.getElementById('notificationsDropdownDesktop');
        
        // Check if click is on notification icon
        const notificationIcon = event.target.closest('svg[onclick*="toggleNotifications"]');
        const notificationContainer = event.target.closest('.relative.cursor-pointer');
        const isNotificationIcon = notificationIcon || 
                                  (notificationContainer && notificationContainer.querySelector('svg[onclick*="toggleNotifications"]'));
        
        // Check if click is inside any dropdown
        const isInsideDropdown = (dropdown && dropdown.contains(event.target)) || 
                                 (dropdownDesktop && dropdownDesktop.contains(event.target));
        
        // Only close if clicking outside both the icon and the dropdown
        if (!isNotificationIcon && !isInsideDropdown) {
            if (dropdown) {
                dropdown.classList.add('hidden', 'opacity-0', 'scale-95');
                dropdown.classList.remove('opacity-100', 'scale-100');
            }
            if (dropdownDesktop) {
                dropdownDesktop.classList.add('hidden', 'opacity-0', 'scale-95');
                dropdownDesktop.classList.remove('opacity-100', 'scale-100');
            }
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