<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}

require_once __DIR__ . '/ensure_laybye_schema.php';
require_once __DIR__ . '/recipe_stock_helper.php';

// Cashier POS: cashiers, admins, and managers may sell; waitresses / hubbly use their own POS entry
$userRole = strtolower($_SESSION['role']);
$isHubblyPos = (defined('HUBBLY_POS') && HUBBLY_POS) || $userRole === 'hubbly';
if ($userRole === 'waitress') {
    header('Location: waitress/home');
    exit();
}
if ($userRole === 'hubbly' && !(defined('HUBBLY_POS') && HUBBLY_POS)) {
    header('Location: hubbly/home');
    exit();
}
$dashboardHomeUrl = null;
if ($userRole === 'admin') {
    $dashboardHomeUrl = 'admin/home';
} elseif ($userRole === 'manager') {
    $dashboardHomeUrl = 'manager/home';
}
$sidebarCashierPosOnly = ($dashboardHomeUrl !== null);

// Hubbly users only punch products in their assigned category
$hubblyCategoryFilter = '';
if ($isHubblyPos) {
    require_once __DIR__ . '/ensure_user_role_constraint.php';
    $hubblyCategoryFilter = trim((string) ($_SESSION['assigned_category'] ?? ''));
    if ($hubblyCategoryFilter === '') {
        $hubblyCategoryFilter = getUserAssignedCategory((int) ($_SESSION['user_id'] ?? 0));
        $_SESSION['assigned_category'] = $hubblyCategoryFilter;
    }
}

// Database connection
$db = new PDO('sqlite:pos.db');
configureSqlitePdo($db);

// Ensure skip_stock_checks column exists for older databases
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN skip_stock_checks BOOLEAN NOT NULL DEFAULT 0");
} catch (PDOException $e) {
    // Column already exists, continue
}

// Ensure use_qz_tray column exists for receipt printing mode
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN use_qz_tray BOOLEAN NOT NULL DEFAULT 0");
} catch (PDOException $e) {
    // Column already exists, continue
}

try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_ip TEXT");
} catch (PDOException $e) {
    // Column already exists, continue
}
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN kitchen_printer_port INTEGER NOT NULL DEFAULT 9100");
} catch (PDOException $e) {
    // Column already exists, continue
}
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN cashier_idle_timeout_seconds INTEGER NOT NULL DEFAULT 120");
} catch (PDOException $e) {
    // Column already exists, continue
}
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN cashier_inactivity_enabled BOOLEAN NOT NULL DEFAULT 1");
} catch (PDOException $e) {
    // Column already exists, continue
}
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN drawer_open_on_checkout TEXT NOT NULL DEFAULT 'on_ok'");
} catch (PDOException $e) {
    // Column already exists, continue
}
try {
    $db->exec("ALTER TABLE product_settings ADD COLUMN show_reverse_transaction BOOLEAN NOT NULL DEFAULT 1");
} catch (PDOException $e) {
    // Column already exists, continue
}
foreach ([
    "ALTER TABLE product_settings ADD COLUMN gratuity_percent REAL NOT NULL DEFAULT 0",
    "ALTER TABLE product_settings ADD COLUMN gratuity_default_enabled INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE product_settings ADD COLUMN gratuity_default_include_in_total INTEGER NOT NULL DEFAULT 1",
] as $__grSql) {
    try {
        $db->exec($__grSql);
    } catch (PDOException $e) {
        // Column already exists
    }
}

// Fetch the show_all_products setting
$settingStmt = $db->query("SELECT show_all_products, default_print_receipt, hide_available_quantity, skip_stock_checks, use_qz_tray, kitchen_printer_ip, kitchen_printer_port, cashier_idle_timeout_seconds, cashier_inactivity_enabled, drawer_open_on_checkout, show_reverse_transaction FROM product_settings LIMIT 1");
$setting = $settingStmt->fetch(PDO::FETCH_ASSOC);
$show_all_products = $setting['show_all_products'] ?? 0; // Default to 0 if not set
$default_print_receipt = $setting['default_print_receipt'] ?? 0; // Default to 0 if not set
$hide_available_quantity = $setting['hide_available_quantity'] ?? 0; // Default to 0 if not set
$skip_stock_checks = isset($setting['skip_stock_checks']) ? (int)$setting['skip_stock_checks'] : 0;
$use_qz_tray = isset($setting['use_qz_tray']) ? (int)$setting['use_qz_tray'] : 0;
$kitchen_printer_ip = trim((string)($setting['kitchen_printer_ip'] ?? ''));
$kitchen_printer_port = isset($setting['kitchen_printer_port']) ? (int)$setting['kitchen_printer_port'] : 9100;
if ($kitchen_printer_port <= 0 || $kitchen_printer_port > 65535) {
    $kitchen_printer_port = 9100;
}
$kitchen_printer_configured = $kitchen_printer_ip !== '' ? 1 : 0;
$cashier_idle_timeout_seconds = isset($setting['cashier_idle_timeout_seconds']) ? (int) $setting['cashier_idle_timeout_seconds'] : 120;
if ($cashier_idle_timeout_seconds < 30) {
    $cashier_idle_timeout_seconds = 30;
}
if ($cashier_idle_timeout_seconds > 3600) {
    $cashier_idle_timeout_seconds = 3600;
}
require_once __DIR__ . '/inactivity_settings_helper.php';
$inactivitySettings = loadInactivitySettings($db);
$inactivity_enabled_for_user = inactivityEnabledForSession($inactivitySettings);
$cashier_idle_timeout_seconds = (int) $inactivitySettings['idle_seconds'];
debugInactivityLog('home.php:load', 'home preloaded inactivity config for cashier POS', [
    'enabled_for_user' => $inactivity_enabled_for_user,
    'idle_seconds' => $cashier_idle_timeout_seconds,
    'session_role' => (string) ($_SESSION['role'] ?? ''),
], 'D');
$drawer_open_on_checkout = isset($setting['drawer_open_on_checkout']) ? trim((string)$setting['drawer_open_on_checkout']) : 'on_ok';
if (!in_array($drawer_open_on_checkout, ['on_ok', 'on_checkout'], true)) {
    $drawer_open_on_checkout = 'on_ok';
}
$show_reverse_transaction = isset($setting['show_reverse_transaction']) ? (int) $setting['show_reverse_transaction'] : 1;
$topSellingProducts = [];

// Fetch products from the database
$query = '
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
';

// Hide internal lay-bye ledger product from the POS grid (still used for order lines)
$laybyePosExclude = laybyePaymentProductWhereExclude('p.name');
if (!$show_all_products) {
    $query .= ' WHERE p.quantity > 0 AND ' . $laybyePosExclude;
} else {
    $query .= ' WHERE ' . $laybyePosExclude;
}

if ($hubblyCategoryFilter !== '') {
    $query .= ' AND LOWER(TRIM(COALESCE(p.category, \'\'))) = LOWER(:hubbly_cat)';
}

$query .= ' GROUP BY p.id ORDER BY total_sold DESC';

if ($hubblyCategoryFilter !== '') {
    $stmt = $db->prepare($query);
    $stmt->execute([':hubbly_cat' => $hubblyCategoryFilter]);
} elseif ($isHubblyPos) {
    // Hubbly user with no category assigned — empty grid
    $stmt = null;
} else {
    $stmt = $db->query($query);
}

$products = [];
$lowStock = [];
$outOfStock = [];

if ($stmt) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $products[] = $row;
        if ($row['quantity'] <= 0) {
            $outOfStock[] = $row;
        } else if ($row['quantity'] < 5) {
            $lowStock[] = $row;
        }
    }
}

// Full stock map for client-side validation (includes products hidden from the grid)
$productStockMap = [];
$stockMapStmt = $db->query('SELECT name, quantity FROM products');
while ($stockRow = $stockMapStmt->fetch(PDO::FETCH_ASSOC)) {
    $productStockMap[$stockRow['name']] = (int) $stockRow['quantity'];
}

// Add this after fetching products
$creditors = $db->query("SELECT * FROM creditors WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);

// Fetch unique categories from products (custom order: Bar, Restaurant, Laundry, Rooms, then others alphabetically)
$categories = [];
if ($isHubblyPos) {
    if ($hubblyCategoryFilter !== '') {
        $categories = [$hubblyCategoryFilter];
    }
} else {
    $categoriesQuery = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' AND " . laybyePaymentProductWhereExclude('name') . " 
ORDER BY 
    CASE 
        WHEN LOWER(category) = 'bar' THEN 1
        WHEN LOWER(category) = 'restaurant' THEN 2
        WHEN LOWER(category) = 'laundry' THEN 3
        WHEN LOWER(category) = 'rooms' THEN 4
        ELSE 5
    END,
    category";
    $categoriesStmt = $db->query($categoriesQuery);
    while ($catRow = $categoriesStmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = $catRow['category'];
    }
}

// Fetch business info for printing (used by Android native printing)
$dbInfo = new PDO('sqlite:info.db');
$businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$businessInfo) {
    $businessInfo = [
        'name' => 'POS SOLUTION',
        'location' => 'Your Business Address',
        'phone' => 'Your Phone Number',
        'footer_text' => 'Thank you for your purchase!',
        'vat_inclusive' => 'exclusive',
        'vat_rate' => 15.0
    ];
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
    <script src="navigation.js" async></script>
    <script src="src/howler.min.js"></script>
    <script src="src/chart.js"></script>
    <script src="lucide.js"></script>
    <script src="sweetalert2@11.js"></script>
    <?php $kbAssetPrefix = ''; $kbPart = 'styles'; include __DIR__ . '/includes/kioskboard_payment.php'; ?>
    <!-- Load sendToPrinter function from receipt.php -->
    <script src="receipt.php?js=true"></script>
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
    
    /* Mobile responsive adjustments - Phones and small devices (up to 768px) */
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
        
        /* Ensure modals appear above cart on mobile and sidebar */
        .swal2-container {
            z-index: 10000 !important;
        }
        
        .swal2-popup {
            z-index: 10001 !important;
        }
        
        .swal2-backdrop-show {
            z-index: 9999 !important;
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
        .mb-1.flex.items-center.gap-4.flex-wrap,
        .mb-6.flex.items-center.gap-4.flex-wrap {
            margin-bottom: 1rem;
            gap: 0.5rem;
            width: 100%;
            max-width: 100%;
        }
        
        /* Ensure first row items (hamburger, search, notification) stay together - prevent wrapping */
        .mb-1.flex.items-center.gap-4.flex-wrap > .flex.items-center.gap-3 {
            flex-shrink: 0 !important;
        }
        
        .mb-1.flex.items-center.gap-4.flex-wrap > .relative.flex-1 {
            flex-shrink: 1 !important;
            min-width: 120px !important;
        }
        
        .mb-1.flex.items-center.gap-4.flex-wrap > .relative.flex-shrink-0:last-child {
            flex-shrink: 0 !important;
            flex-grow: 0 !important;
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
    
    /* Tablets specific (769px - 1023px) - 1024px+ gets desktop layout */
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

    /* Ensure sidebar is above overlay on mobile */
    @media (max-width: 1023px) {
        #sidebar {
            z-index: 10000 !important;
        }
    }

    @media (min-width: 1024px) {
        .hamburger {
            display: none;
        }
        .mobile-overlay {
            display: none;
        }
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
    
    /* Category filter wrapper */
    .category-filter-wrapper {
        position: relative;
        max-width: 400px;
        min-width: 200px;
    }
    
    /* Category filter badges - horizontal scrollable, no scrollbar */
    .category-filter-container {
        background: transparent;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE and Edge */
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        scroll-behavior: smooth;
    }
    
    .category-filter-container > div {
        padding: 0.5rem 0.75rem;
    }
    
    .category-filter-container::-webkit-scrollbar {
        display: none; /* Chrome, Safari, Opera */
        width: 0;
        height: 0;
    }
    
    /* Category scroll buttons */
    .category-scroll-btn {
        width: 32px;
        height: 32px;
        border: 1px solid #e5e7eb;
    }
    
    .category-scroll-btn:hover {
        border-color: #d1d5db;
        transform: scale(1.05);
    }
    
    .category-scroll-btn:active {
        transform: scale(0.95);
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
        background: #4b5563;
        color: white;
        border-color: #4b5563;
        box-shadow: 0 2px 4px 0 rgba(75, 85, 99, 0.3);
    }
    
    .category-badge.active:hover {
        background: #374151;
        border-color: #374151;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px 0 rgba(75, 85, 99, 0.4);
    }
    
    /* Mobile adjustments for category filter - Phones and Tablets (up to 1023px) */
    @media (max-width: 1023px) {
        /* Header row layout - all items in first row except categories */
        .mb-1.flex.items-center.gap-4.flex-wrap,
        .mb-6.flex.items-center.gap-4.flex-wrap {
            gap: 0.5rem;
            align-items: center;
        }
        
        /* Mobile controls (hamburger + cart) - order 1, stay together, never wrap */
        .mb-1.flex.items-center.gap-4.flex-wrap > .flex.items-center.gap-3,
        .mb-6.flex.items-center.gap-4.flex-wrap > .flex.items-center.gap-3 {
            order: 1;
            flex-shrink: 0 !important;
            gap: 0.5rem;
        }
        
        /* Mobile search bar - order 2, grows to fill space, stays in first row, can shrink */
        .mb-1.flex.items-center.gap-4.flex-wrap > .relative.flex-1,
        .mb-6.flex.items-center.gap-4.flex-wrap > .relative.flex-1 {
            order: 2;
            flex: 1 1 0;
            min-width: 100px;
            max-width: none;
            flex-shrink: 1 !important;
        }
        
        /* Mobile notification icon - order 3, ALWAYS stays in same first row, never wraps */
        .mb-1.flex.items-center.gap-4.flex-wrap > .relative.flex-shrink-0:last-child,
        .mb-6.flex.items-center.gap-4.flex-wrap > .relative.flex-shrink-0:last-child {
            order: 3;
            flex-shrink: 0 !important;
            flex-grow: 0 !important;
        }
        
        /* Category filter - order 4, ALWAYS full width on second row, forces wrap */
        .category-filter-wrapper {
            max-width: 100%;
            width: 100%;
            margin-top: 0.25rem;
            order: 4;
            flex-basis: 100% !important;
            flex-shrink: 0 !important;
            min-width: 100% !important;
        }
        
        .category-filter-container {
            padding-left: 2rem !important;
            padding-right: 2rem !important;
        }
        
        .category-filter-container > div {
            padding: 0.4rem 0.5rem;
        }
        
        .category-badge {
            padding: 0.4rem 0.75rem;
            font-size: 0.8125rem;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
        
        .category-scroll-btn {
            width: 28px;
            height: 28px;
            padding: 0.25rem;
        }
    }
    
    /* Small phones (up to 375px) */
    @media (max-width: 375px) {
        .category-filter-container {
            padding-left: 1.75rem !important;
            padding-right: 1.75rem !important;
        }
        
        .category-filter-container > div {
            padding: 0.35rem 0.4rem;
        }
        
        .category-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            margin-right: 0.4rem;
        }
        
        .category-scroll-btn {
            width: 28px;
            height: 28px;
            padding: 0.25rem;
        }
    }
    
    /* Medium phones (376px - 480px) */
    @media (min-width: 376px) and (max-width: 480px) {
        .category-filter-container {
            padding-left: 2rem !important;
            padding-right: 2rem !important;
        }
        
        .category-filter-container > div {
            padding: 0.4rem 0.5rem;
        }
        
        .category-badge {
            padding: 0.4rem 0.7rem;
            font-size: 0.8125rem;
        }
        
        .category-scroll-btn {
            width: 30px;
            height: 30px;
        }
    }
    
    /* Large phones and small tablets (481px - 768px) */
    @media (min-width: 481px) and (max-width: 768px) {
        .category-filter-container {
            padding-left: 2.25rem !important;
            padding-right: 2.25rem !important;
        }
        
        .category-filter-container > div {
            padding: 0.45rem 0.6rem;
        }
        
        .category-badge {
            padding: 0.45rem 0.8rem;
            font-size: 0.875rem;
        }
        
        .category-scroll-btn {
            width: 32px;
            height: 32px;
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
    
    /* Large tablets - iPad Pro (913px - 1023px) - 1024px+ gets desktop */
    @media (min-width: 913px) and (max-width: 1023px) {
        .category-filter-wrapper {
            padding: 0.5rem 0.75rem;
            max-width: 100%;
            min-width: 100%;
            width: 100%;
            margin-top: 0.25rem;
            order: 4;
            flex-basis: 100%;
        }
        
        .category-filter-container {
            padding-left: 2.5rem !important;
            padding-right: 2.5rem !important;
        }
        
        .category-filter-container > div {
            padding: 0.5rem 0.75rem;
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
    
    /* All tablets - category filter consistency (769px - 1023px) - 1024px+ gets desktop */
    @media (min-width: 769px) and (max-width: 1023px) {
        .category-filter-wrapper {
            padding: 0.5rem 0.75rem;
            max-width: 100%;
            width: 100%;
            order: 4;
            flex-basis: 100%;
        }
        
        .category-filter-container {
            padding-left: 2.5rem !important;
            padding-right: 2.5rem !important;
        }
        
        .category-filter-container > div {
            padding: 0.5rem 0.75rem;
        }
        
        .category-badge {
            padding: 0.45rem 0.85rem;
            font-size: 0.875rem;
        }
        
        .container {
            max-width: 100% !important;
        }
    }
    
    /* All devices 1024px and above - ensure max-width (desktop layout) */
    @media (min-width: 1024px) {
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
    
    /* Optimize for smaller desktop screens (1024px - 1440px) like 1280x800 */
    @media (min-width: 1024px) and (max-width: 1440px) {
        /* Reduce cart width to fit better */
        #cart {
            width: 22rem !important; /* 288px instead of 384px (w-96) */
            min-width: 22rem;
            max-width: 22rem;
        }
        
        /* Adjust products section to use remaining space - use flex instead of fixed width */
        main > div.w-full.lg\:w-3\/4 {
            width: auto !important;
            flex: 1 1 auto !important;
            min-width: 0 !important;
            max-width: none !important;
        }
        
        /* Ensure main flex container distributes space properly - minimize gap between grid and cart */
        main.flex {
            gap: 0.25rem !important;
        }
        
        /* Reduce padding to maximize space - minimize right padding to reduce gap */
        main {
            padding: 0.75rem !important;
            padding-right: 0.25rem !important;
        }
        
        /* Remove right padding from products section to reduce gap */
        main > div.w-full.lg\:w-3\/4 {
            padding-right: 0 !important;
        }
        
        /* Fix header row overflow - evenly distribute space */
        .mb-1.flex.items-center.gap-4 {
            margin-bottom: 0.5rem !important;
            gap: 0.5rem !important;
            max-width: 100% !important;
            flex-wrap: nowrap !important;
            width: 100% !important;
            justify-content: space-between !important;
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }
        
        /* Constrain products section container */
        main > div.w-full.lg\:w-3\/4 {
            overflow: hidden !important;
            max-width: 100% !important;
        }
        
        /* Evenly split search bar and category filter - tablets */
        .mb-1.flex.items-center.gap-4 > .relative.flex-1,
        .mb-6.flex.items-center.gap-4 > .relative.flex-1 {
            min-width: 0 !important;
            max-width: none !important;
            flex: 1 1 0 !important;
            flex-shrink: 1 !important;
            flex-grow: 1 !important;
        }
        
        /* Category filter wrapper - evenly split with search bar */
        .mb-1.flex.items-center.gap-4 > .category-filter-wrapper,
        .mb-6.flex.items-center.gap-4 > .category-filter-wrapper {
            max-width: none !important;
            flex: 1 1 0 !important;
            flex-shrink: 1 !important;
            flex-grow: 1 !important;
            min-width: 0 !important;
        }
        
        /* Reduce search bar font size and padding on tablets */
        #searchBar {
            font-size: 0.875rem !important;
            padding-left: 2.5rem !important;
            padding-right: 3rem !important; /* Space for camera button in right corner */
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
        }
        
        .category-filter-container {
            overflow: hidden !important;
        }
        
        /* Ensure notifications icon doesn't cause overflow */
        .mb-1.flex.items-center.gap-4 > .relative.flex-shrink-0:last-child {
            flex-shrink: 0 !important;
        }
        
        /* Reduce gaps in product grid */
        #productGrid {
            padding: 1.25rem !important;
        }
        
        /* Reduce product card padding */
        .product-item .p-5 {
            padding: 0.625rem !important;
        }
        
        /* Reduce product card font sizes slightly */
        .product-item .p-5 p:first-child {
            font-size: 0.875rem !important;
        }
        
        .product-item .p-5 p.text-2xl {
            font-size: 1.5rem !important;
        }
        
        /* Reduce header spacing */
        .mb-6 {
            margin-bottom: 0.75rem !important;
        }
        
        /* Reduce cart padding and margin - minimize left margin to reduce gap */
        #cart {
            padding: 0.75rem !important;
            margin: 0.5rem 0.5rem 0.5rem 0.25rem !important;
        }
        
        /* Reduce search bar and header gaps */
        .mb-6.flex.items-center.gap-4 {
            gap: 0.5rem !important;
        }
        
        /* Ensure no horizontal overflow */
        html, body {
            overflow-x: hidden !important;
            max-width: 100vw !important;
        }
        
        .content {
            overflow-x: hidden !important;
            max-width: 100% !important;
        }
        
        /* Ensure main container fits viewport */
        main {
            max-width: 100% !important;
            min-width: 0 !important;
        }
        
        /* Ensure flex container doesn't overflow */
        .flex {
            min-width: 0 !important;
        }
        
        /* Reduce category badge padding */
        .category-badge {
            padding: 0.375rem 0.75rem !important;
            font-size: 0.8125rem !important;
        }
    }
    
    /* Nest Hub (1024x600) - further reduce cart width */
    @media (min-width: 1024px) and (max-width: 1024px) and (max-height: 600px) {
        #cart {
            width: 18rem !important; /* 288px - reduced from 22rem for Nest Hub */
            min-width: 18rem !important;
            max-width: 18rem !important;
            padding: 0.5rem !important;
            margin: 0.25rem 0.25rem 0.25rem 0.125rem !important;
        }
        
        /* Evenly split header space on Nest Hub */
        .mb-1.flex.items-center.gap-4 {
            gap: 0.5rem !important;
            justify-content: space-between !important;
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }
        
        /* Evenly split search bar and category filter - Nest Hub */
        .mb-1.flex.items-center.gap-4 > .relative.flex-1,
        .mb-6.flex.items-center.gap-4 > .relative.flex-1 {
            max-width: none !important;
            min-width: 0 !important;
            flex: 1 1 0 !important;
            flex-shrink: 1 !important;
            flex-grow: 1 !important;
        }
        
        /* Category filter wrapper - evenly split with search bar */
        .mb-1.flex.items-center.gap-4 > .category-filter-wrapper,
        .mb-6.flex.items-center.gap-4 > .category-filter-wrapper {
            max-width: none !important;
            flex: 1 1 0 !important;
            flex-shrink: 1 !important;
            flex-grow: 1 !important;
            min-width: 0 !important;
        }
        
        /* Reduce search bar font size and padding on Nest Hub */
        #searchBar {
            font-size: 0.75rem !important;
            padding-left: 2rem !important;
            padding-right: 2.75rem !important; /* Space for camera button in right corner */
            padding-top: 0.375rem !important;
            padding-bottom: 0.375rem !important;
        }
        
        #searchBar::placeholder {
            font-size: 0.7rem !important;
        }
        
        /* Reduce icon sizes in search bar */
        #searchBar ~ div svg {
            width: 0.875rem !important;
            height: 0.875rem !important;
        }
        
        #cameraScanBtn svg {
            width: 0.875rem !important;
            height: 0.875rem !important;
        }
        
        #clearSearch {
            width: 0.875rem !important;
            height: 0.875rem !important;
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
    <script>
        window.CASHIER_INACTIVITY_ENABLED = <?= !empty($inactivity_enabled_for_user) ? 'true' : 'false' ?>;
        window.CASHIER_IDLE_TIMEOUT_SECONDS = <?= (int) $cashier_idle_timeout_seconds ?>;
    </script>

</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">


    <div class="flex" style="max-width: 100vw; overflow-x: hidden;">
        <?php
        if ($isHubblyPos) {
            include __DIR__ . '/hubbly/sidebar.php';
        } else {
            include __DIR__ . '/sidebar.php';
        }
        ?>
        <div class="flex-1 content lg:ml-0 ml-0" style="min-width: 0; overflow-x: hidden;">
            <div class="container" style="max-width: 100%;">

        <!-- Main Content Area -->
 

        <main class="flex-1 pr-4 p-6 bg-gray-50 flex flex-col lg:flex-row" style="height: 100vh; overflow: hidden; max-width: 100%; min-width: 0;">

    <!-- Mobile Sidebar Overlay -->
    <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>

    <!-- Products Section -->
    <div class="w-full lg:w-3/4 pr-0 lg:pr-6 max-h-[calc(100vh)]" style="min-width: 0; flex-shrink: 1; max-width: 100%;">
        <!-- Search, Category Filter and Notifications Row -->
        <div class="mb-1 flex items-center gap-4 flex-wrap lg:flex-nowrap">
            <!-- Mobile Controls Row -->
            <div class="flex items-center gap-3">
                <?php if (!empty($dashboardHomeUrl)): ?>
                <a href="<?= htmlspecialchars($dashboardHomeUrl) ?>" class="inline-flex items-center gap-2 px-2 sm:px-3 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors duration-200" title="<?= $userRole === 'admin' ? 'Back to Admin Dashboard' : 'Back to Manager Dashboard' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                    </svg>
                    <span class="hidden sm:inline">Dashboard</span>
                </a>
                <?php endif; ?>
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
                <!-- Clear button (appears when there's text) -->
                <div class="absolute inset-y-0 right-10 pr-3 flex items-center">
                    <svg id="clearSearch" onclick="clearSearch()" class="h-5 w-5 text-gray-400 cursor-pointer opacity-0 transition-opacity duration-200 pointer-events-none" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <!-- Camera/Scan button - Right corner -->
                <button id="cameraScanBtn" onclick="toggleCameraScanner()" class="absolute inset-y-0 right-0 pr-3 flex items-center p-1.5 text-gray-400 hover:text-teal-500 transition-colors duration-200 focus:outline-none" title="Scan barcode with camera">
                    <!-- Scan icon with only corner edges, nothing in the middle -->
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <!-- Top-left corner -->
                        <polyline points="4,8 4,4 8,4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        <!-- Top-right corner -->
                        <polyline points="16,4 20,4 20,8" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        <!-- Bottom-right corner -->
                        <polyline points="20,16 20,20 16,20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        <!-- Bottom-left corner -->
                        <polyline points="8,20 4,20 4,16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            
            <!-- Category Filter Section -->
            <?php if (!empty($categories)): ?>
            <div class="category-filter-wrapper flex-shrink-0 w-full lg:w-auto relative flex items-center">
                <!-- Left Arrow Button -->
                <button id="categoryScrollLeft" class="category-scroll-btn absolute left-0 z-10 bg-white hover:bg-gray-100 text-gray-600 hover:text-gray-800 rounded-full p-1.5 shadow-md transition-all duration-200 flex items-center justify-center opacity-0 pointer-events-none" onclick="scrollCategoryFilter('left')" style="top: 50%; transform: translateY(-50%); display: none;">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                
                <div id="categoryFilterContainer" class="category-filter-container flex-1 overflow-x-auto" style="padding-left: 0; padding-right: 0;">
                    <div class="flex items-center">
                        <?php if (!$isHubblyPos): ?>
                        <button class="category-badge active" data-category="all" onclick="filterByCategory('all')">
                            All
                        </button>
                        <?php endif; ?>
                        <?php foreach ($categories as $category): ?>
                            <button class="category-badge <?= $isHubblyPos ? 'active' : '' ?>" data-category="<?= htmlspecialchars($category) ?>" onclick="filterByCategory('<?= htmlspecialchars($category) ?>')">
                                <?= htmlspecialchars($category) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Right Arrow Button -->
                <button id="categoryScrollRight" class="category-scroll-btn absolute right-0 z-10 bg-white hover:bg-gray-100 text-gray-600 hover:text-gray-800 rounded-full p-1.5 shadow-md transition-all duration-200 flex items-center justify-center opacity-0 pointer-events-none" onclick="scrollCategoryFilter('right')" style="top: 50%; transform: translateY(-50%); display: none;">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
            <?php endif; ?>

            <!-- Notifications Icon -->
            <div class="relative flex-shrink-0">
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
                    <p class="text-gray-500 text-center max-w-md">
                        <?php if ($isHubblyPos && $hubblyCategoryFilter === ''): ?>
                            No product category is assigned to this Hubbly user. Ask an admin to set one under Users → Edit User.
                        <?php elseif ($isHubblyPos): ?>
                            No products found in category “<?= htmlspecialchars($hubblyCategoryFilter) ?>”. Add products with that category, or change the assigned category for this user.
                        <?php else: ?>
                            It looks like there are no products in the database. Please add some products to get started.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 gap-3 md:gap-4 lg:gap-8 py-4 overflow-y-auto pr-0 lg:pr-4 custom-scrollbar" id="productGrid" style="max-height: calc(100vh - 14vh); height: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;">
                <?php foreach ($products as $product): ?>
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer transition-transform duration-300 hover:scale-105 product-item select-none" 
                        data-price="<?= $product['price'] ?>" 
                        data-name="<?= htmlspecialchars($product['name']) ?>"
                        data-available-quantity="<?= (int)$product['quantity'] ?>"
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

        // Skip if we're editing a quantity or kioskboard field
        if (document.activeElement.classList && (
            document.activeElement.classList.contains('quantity-input') ||
            document.activeElement.classList.contains('js-kioskboard-input')
        )) {
            return;
        }

        // Skip when typing inside a Swal modal
        if (document.activeElement.closest && document.activeElement.closest('.swal2-popup')) {
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

        // Camera scanner toggle function
        let isCameraScanning = false;
        function toggleCameraScanner() {
            if (typeof AndroidBarcodeScanner === 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Scanner Not Available',
                    text: 'Camera scanner is only available in the Android app',
                    timer: 2000,
                    timerProgressBar: true
                });
                return;
            }

            try {
                if (isCameraScanning) {
                    AndroidBarcodeScanner.stopScanning();
                    isCameraScanning = false;
                    updateCameraButton(false);
                    Swal.fire({
                        icon: 'info',
                        title: 'Scanner Stopped',
                        text: 'Camera scanner has been stopped',
                        timer: 1500,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                } else {
                    AndroidBarcodeScanner.startScanning();
                    isCameraScanning = true;
                    updateCameraButton(true);
                    Swal.fire({
                        icon: 'success',
                        title: 'Scanner Started',
                        text: 'Camera scanner is now active. Point at a barcode to scan.',
                        timer: 2000,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                }
            } catch (error) {
                console.error('Error toggling camera scanner:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to toggle camera scanner: ' + error.message,
                    timer: 2000,
                    timerProgressBar: true
                });
            }
        }

        // Update camera button appearance
        function updateCameraButton(isActive) {
            const btn = document.getElementById('cameraScanBtn');
            if (!btn) return;
            
            if (isActive) {
                btn.classList.remove('text-gray-400', 'hover:text-teal-500');
                btn.classList.add('text-teal-500', 'hover:text-teal-600');
                btn.title = 'Stop camera scanner';
            } else {
                btn.classList.remove('text-teal-500', 'hover:text-teal-600');
                btn.classList.add('text-gray-400', 'hover:text-teal-500');
                btn.title = 'Scan barcode with camera';
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
        
        // Category filter arrow button scrolling
        function scrollCategoryFilter(direction) {
            const container = document.getElementById('categoryFilterContainer');
            if (!container) return;
            
            const scrollAmount = 200; // pixels to scroll
            const currentScroll = container.scrollLeft;
            const maxScroll = container.scrollWidth - container.clientWidth;
            
            if (direction === 'left') {
                container.scrollTo({
                    left: Math.max(0, currentScroll - scrollAmount),
                    behavior: 'smooth'
                });
            } else if (direction === 'right') {
                container.scrollTo({
                    left: Math.min(maxScroll, currentScroll + scrollAmount),
                    behavior: 'smooth'
                });
            }
            
            // Update arrow visibility after a short delay
            setTimeout(updateCategoryArrows, 100);
        }
        
        function updateCategoryArrows() {
            const container = document.getElementById('categoryFilterContainer');
            const leftBtn = document.getElementById('categoryScrollLeft');
            const rightBtn = document.getElementById('categoryScrollRight');
            
            if (!container || !leftBtn || !rightBtn) return;
            
            const scrollLeft = container.scrollLeft;
            const maxScroll = container.scrollWidth - container.clientWidth;
            const hasOverflow = container.scrollWidth > container.clientWidth;
            
            // Determine padding based on screen size (mobile vs desktop)
            const isMobile = window.innerWidth <= 1023;
            const paddingValue = isMobile ? '2rem' : '2.5rem';
            
            // Show/hide arrows based on scroll position and overflow
            if (hasOverflow) {
                if (scrollLeft <= 0) {
                    // At the start - hide left, show right
                    leftBtn.style.display = 'none';
                    leftBtn.classList.add('opacity-0', 'pointer-events-none');
                    rightBtn.style.display = 'flex';
                    rightBtn.classList.remove('opacity-0', 'pointer-events-none');
                    container.style.setProperty('padding-left', '0', 'important');
                    container.style.setProperty('padding-right', paddingValue, 'important');
                } else if (scrollLeft >= maxScroll - 1) {
                    // At the end - show left, hide right
                    leftBtn.style.display = 'flex';
                    leftBtn.classList.remove('opacity-0', 'pointer-events-none');
                    rightBtn.style.display = 'none';
                    rightBtn.classList.add('opacity-0', 'pointer-events-none');
                    container.style.setProperty('padding-left', paddingValue, 'important');
                    container.style.setProperty('padding-right', '0', 'important');
                } else {
                    // In the middle - show both
                    leftBtn.style.display = 'flex';
                    leftBtn.classList.remove('opacity-0', 'pointer-events-none');
                    rightBtn.style.display = 'flex';
                    rightBtn.classList.remove('opacity-0', 'pointer-events-none');
                    container.style.setProperty('padding-left', paddingValue, 'important');
                    container.style.setProperty('padding-right', paddingValue, 'important');
                }
            } else {
                // No overflow, hide both arrows and remove all padding
                leftBtn.style.display = 'none';
                leftBtn.classList.add('opacity-0', 'pointer-events-none');
                rightBtn.style.display = 'none';
                rightBtn.classList.add('opacity-0', 'pointer-events-none');
                container.style.setProperty('padding-left', '0', 'important');
                container.style.setProperty('padding-right', '0', 'important');
            }
        }
        
        // Initialize category filter arrows on page load
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('categoryFilterContainer');
            if (container) {
                // Check initial state
                updateCategoryArrows();
                
                // Update on scroll
                container.addEventListener('scroll', updateCategoryArrows);
                
                // Update on window resize
                window.addEventListener('resize', updateCategoryArrows);
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
    <div id="cart" class="w-full lg:w-96 h-full bg-gray-100 shadow-lg rounded-xl p-4 m-2 border border-gray-300 flex flex-col custom-scrollbar" style="height: calc(100vh - 4rem); overflow-y: auto; flex-shrink: 0; min-width: 0;">
        
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
        <p id="cartDiscountSummary" class="hidden mb-2 text-sm text-amber-800 font-medium text-right -mt-2"></p>
        <ul id="cartItems" class="mb-6 space-y-4 border border-gray-300 rounded-lg p-4 text-base flex-grow">
            <!-- Cart items will be added here dynamically -->
        </ul>

        <div class="mt-4">
            <div class="flex flex-nowrap items-end gap-2 mb-4 w-full min-w-0 overflow-x-auto">
                <div class="flex-1 min-w-0 basis-0">
                    <label for="cashReceived" class="block mb-2 text-gray-900 text-sm">Cash Received:</label>
                    <div class="kioskboard-input-wrap">
                        <input type="number" id="cashReceived" data-pos-kb-placement="bottom" class="js-kioskboard-input js-kioskboard-decimal p-2 text-sm w-full h-10 rounded-lg border border-gray-300 focus:border-gray-500 focus:ring focus:ring-gray-200 shadow-md box-border" step="1" inputmode="decimal" autocomplete="off" oninput="calculateChange()">
                        <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                    </div>
                </div>
                <div class="flex shrink-0 rounded-lg overflow-hidden shadow-sm border border-gray-300">
                    <button type="button" class="bg-gray-200 text-gray-800 px-2 sm:px-3 py-2 hover:bg-gray-300 transition-colors duration-200 text-xs sm:text-sm font-medium flex items-center justify-center h-10 border-r border-gray-300 rounded-none whitespace-nowrap" onclick="handleAccountPurchase()">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        Account
                    </button>
                    <button type="button" class="bg-gray-200 text-gray-800 px-2 sm:px-3 py-2 hover:bg-gray-300 transition-colors duration-200 text-xs sm:text-sm font-medium flex items-center justify-center h-10 border-r border-gray-300 rounded-none whitespace-nowrap" onclick="handleEWalletPurchase()">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                        EFT
                    </button>
                    <button type="button" title="Cart discount" class="bg-gray-200 text-gray-800 px-2 py-2 hover:bg-gray-300 transition-colors duration-200 text-sm font-semibold flex items-center justify-center h-10 rounded-none min-w-[2.5rem]" onclick="handleCartDiscount()">
                        %
                    </button>
                </div>
            </div>

            <div id="cashButtonsContainer" class="flex flex-wrap space-x-2 mb-4">
                <button class="bg-sky-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-sky-800 transition-colors duration-300 mb-2 text-sm" onclick="addCash(5)">N$5</button>
                <button class="bg-teal-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-700 transition-colors duration-300 mb-2 text-sm" onclick="addCash(10)">N$10</button>
                <button class="bg-red-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-red-700 transition-colors duration-300 mb-2 text-sm" onclick="addCash(20)">N$20</button>
                <button class="bg-yellow-500 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-yellow-600 transition-colors duration-300 mb-2 text-sm" onclick="addCash(30)">N$30</button>
                <button class="bg-orange-600 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-orange-700 transition-colors duration-300 mb-2 text-sm" onclick="addCash(50)">N$50</button>
                <button class="bg-neutral-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-neutral-800 transition-colors duration-300 mb-2 text-sm" onclick="addCash(100)">N$100</button>
                <button class="bg-lime-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-lime-800 transition-colors duration-300 mb-2 text-sm"  onclick="addCash(200)">N$200</button>
                <button class="bg-teal-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-800 transition-colors duration-300 mb-2 text-sm" onclick="handleMixedPayment()">Mixed</button>
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
                <button class="bg-red-700 text-gray-100 font-semibold px-3 py-2 rounded-lg shadow hover:bg-red-800 transition-colors duration-200 mb-2 text-sm" onclick="handleRefund()">
                    <i data-lucide="undo-2" class="w-4 h-4 inline-block mr-1 text-gray-100 opacity-80"></i>
                    Refund
                </button>
                <button class="bg-amber-600 text-gray-100 font-semibold px-3 py-2 rounded-lg shadow hover:bg-amber-700 transition-colors duration-200 mb-2 text-sm" onclick="handleChange()">
                    <i data-lucide="coins" class="w-4 h-4 inline-block mr-1 text-gray-100 opacity-80"></i>
                    Change
                </button>
                <button id="toggleExtraButtons2" class="bg-teal-700 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:bg-teal-800 transition-colors duration-300 mb-2 text-sm" onclick="toggleExtraButtons()">
                    <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-3">
            <p class="text-lg font-bold text-gray-900 flex items-center shrink-0 whitespace-nowrap">
                <svg class="w-6 h-6 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Change: N$<span id="changeAmount" class="text-teal-700 text-2xl">0.00</span>
            </p>
        </div>

        <div class="mt-4">
            <button class="px-4 py-3 rounded-lg shadow-md transition-colors duration-300 flex justify-center items-center text-lg w-full bg-teal-600 hover:bg-teal-700 text-white" onclick="checkout()">
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
        const skipStockChecks = <?php echo $skip_stock_checks; ?>;
        const defaultPrintReceipt = <?php echo $default_print_receipt; ?>;
        const kitchenPrinterConfigured = <?php echo (int)$kitchen_printer_configured; ?>;
        const drawerOpenOnCheckout = <?php echo json_encode($drawer_open_on_checkout); ?>;
        const showReverseTransaction = <?php echo (int)$show_reverse_transaction; ?>;
        // receipt.php?js=true may already define `useQzTray` as a var.
        if (typeof useQzTray === 'undefined') {
            var useQzTray = <?php echo $use_qz_tray ? 'true' : 'false'; ?>;
        }

        // Helper function to build payment confirmation footer HTML
        function buildPaymentFooterHTML() {
            const reverseLink = showReverseTransaction ? 
                `<a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                    <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                </a>` : '';
            
            return `
                <div style="display: flex; justify-content: center; align-items: center;">
                    ${reverseLink}
                    <input type='checkbox' id='printReceiptCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;' ${defaultPrintReceipt ? 'checked' : ''}>
                    <label for='printReceiptCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Print with receipt</label>
                </div>
            `;
        }

        // Server-side stock map (refreshed periodically); used when DOM quantity is missing or stale
        let productStockByName = <?php echo json_encode($productStockMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // Get available quantity from product element (data attribute or "Available:" text). Used for stock checks even when quantity is hidden.
        function getAvailableQuantity(productElement) {
            if (!productElement) return null;
            const dataQty = productElement.getAttribute('data-available-quantity');
            if (dataQty !== null && dataQty !== '') {
                const parsed = parseInt(dataQty, 10);
                return Number.isNaN(parsed) ? null : parsed;
            }
            const quantityElement = productElement.querySelector('p:last-child');
            if (quantityElement && quantityElement.textContent.includes('Available:')) {
                const m = quantityElement.textContent.match(/Available:\s*(\d+)/);
                if (!m) return null;
                const parsed = parseInt(m[1], 10);
                return Number.isNaN(parsed) ? null : parsed;
            }
            return null;
        }

        function findProductElement(productName) {
            if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
                return document.querySelector('.product-item[data-name="' + CSS.escape(productName) + '"]');
            }
            const items = document.querySelectorAll('.product-item[data-name]');
            for (const el of items) {
                if (el.getAttribute('data-name') === productName) return el;
            }
            return null;
        }

        function getStockQuantityForName(productName) {
            const productElement = findProductElement(productName);
            if (productElement) {
                const domQty = getAvailableQuantity(productElement);
                if (domQty !== null && !Number.isNaN(domQty)) return domQty;
            }
            if (Object.prototype.hasOwnProperty.call(productStockByName, productName)) {
                return productStockByName[productName];
            }
            return null;
        }

        function getCartOutOfStockItems(cartItems) {
            if (skipStockChecks) return [];
            return cartItems.filter(item => {
                const availableQuantity = getStockQuantityForName(item.name);
                // Fail-safe: unknown stock is treated as insufficient (prevents silent skip)
                if (availableQuantity === null || Number.isNaN(availableQuantity)) return true;
                return availableQuantity < item.quantity;
            });
        }

        // Current user info for table ownership
        const currentUserId = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
        const currentUserRole = <?php echo json_encode($_SESSION['role'] ?? 'cashier'); ?>;
        
        // Business info for Android native printing
        // Make businessInfo global so sendToPrinter (from receipt.php?js=true) can access it
        window.businessInfo = {
            business_name: <?php echo json_encode($businessInfo['name'] ?? 'POS SOLUTION'); ?>,
            location: <?php echo json_encode($businessInfo['location'] ?? ''); ?>,
            phone: <?php echo json_encode($businessInfo['phone'] ?? ''); ?>,
            footer_text: <?php echo json_encode($businessInfo['footer_text'] ?? 'Thank you for your purchase!'); ?>,
            logo_path: <?php echo json_encode(trim((string)($businessInfo['logo_path'] ?? ''))); ?>,
            vat_inclusive: <?php echo json_encode($businessInfo['vat_inclusive'] ?? 'exclusive'); ?>,
            vat_rate: <?php echo json_encode(floatval($businessInfo['vat_rate'] ?? 15.0)); ?>
        };
        // Also keep local reference for backward compatibility
        const businessInfo = window.businessInfo;
        
        // sendToPrinter function is now loaded from receipt.php?js=true
        // The function is defined in receipt.php and automatically handles Android printing
        // The Android interceptor in MainActivity.java only listens to receipt.php calls
        if (typeof sendToPrinter === 'undefined') {
            console.warn('[home.php] sendToPrinter not loaded from receipt.php, using fallback');
            function sendToPrinter(receiptData) {
                // Ensure print_only flag is set (parity with receipt.php behavior for regular receipts)
                if (!receiptData.print_only && !receiptData.is_cashup_report && !receiptData.is_balance_receipt) {
                    receiptData.print_only = true;
                }

                // Add business info to receipt data
                var dataWithBusiness = Object.assign({}, receiptData, {
                    business_name: receiptData.business_name || businessInfo.business_name,
                    location: receiptData.location || businessInfo.location,
                    phone: receiptData.phone || businessInfo.phone,
                    footer_text: receiptData.footer_text || businessInfo.footer_text,
                    vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                    vat_rate: receiptData.vat_rate || businessInfo.vat_rate
                });

                if (dataWithBusiness.print_to_kitchen_printer === true) {
                    var ru = (window.location && window.location.origin ? window.location.origin : '') + '/receipt.php';
                    return fetch(ru, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(dataWithBusiness)
                    }).then(function(r) { return r.json(); });
                }

                var ua = (navigator.userAgent || '').toLowerCase();
                var isAndroidLike = ua.indexOf('android') !== -1 || ua.indexOf('median') !== -1;

                // QZ Tray printing (desktop/web) - uses qzreceipt.php and waits for completion.
                if (useQzTray && !isAndroidLike) {
                    window.__qzTrayPrintQueue = window.__qzTrayPrintQueue || Promise.resolve();
                    window.__qzTrayPrintQueue = window.__qzTrayPrintQueue.then(function() {
                        return new Promise(function(resolve) {
                            var qzSupported = !!dataWithBusiness.is_cashup_report || !!dataWithBusiness.is_balance_receipt
                                || (! (dataWithBusiness.tab_id || dataWithBusiness.table_id)
                                    && !dataWithBusiness.is_tab_balance_receipt
                                    && !dataWithBusiness.is_payment_receipt
                                    && !dataWithBusiness.is_refund_receipt);

                            if (!qzSupported) {
                                return resolve({ success: false, message: 'Unsupported receipt type for QZ Tray' });
                            }

                            var iframe = document.createElement('iframe');
                            iframe.style.display = 'none';
                            iframe.width = '0';
                            iframe.height = '0';

                            var encoded = encodeURIComponent(JSON.stringify(dataWithBusiness));
                            var timeoutId = null;

                            function cleanup(handlerFn, result) {
                                try {
                                    if (handlerFn) window.removeEventListener('message', handlerFn);
                                } catch (e) {}
                                if (timeoutId) clearTimeout(timeoutId);
                                try { iframe.remove(); } catch (e) {}
                                resolve(result);
                            }

                            function onMessage(event) {
                                if (!event || !event.data || event.data.type !== 'printComplete') return;
                                cleanup(onMessage, {
                                    success: !!event.data.success,
                                    message: event.data.message || 'QZ Tray print completed'
                                });
                            }

                            timeoutId = setTimeout(function() {
                                cleanup(onMessage, { success: false, message: 'QZ Tray print timeout' });
                            }, 60000);

                            window.addEventListener('message', onMessage);

                            iframe.src = (window.location && window.location.origin ? window.location.origin : '') + '/qzreceipt.php?data=' + encoded;
                            document.body.appendChild(iframe);
                        });
                    });

                    return window.__qzTrayPrintQueue;
                }

                // Default: receipt.php (works for Android interceptor and ESC/POS server printing)
                return fetch('receipt.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dataWithBusiness)
                }).then(function(r) {
                    return r.json();
                });
            }
        }
        
        // Debug function to check Android printer status
        function checkAndroidPrinter() {
            var status = {
                userAgent: navigator.userAgent,
                isMedian: navigator.userAgent.toLowerCase().indexOf('median') !== -1,
                AndroidPrinter: typeof window.AndroidPrinter,
                NativePrinter: typeof window.NativePrinter,
                median: typeof window.median,
                JSBridge: typeof window.JSBridge,
                hasPrintReceipt: false,
                pingResult: null
            };
            
            // Check AndroidPrinter
            if (window.AndroidPrinter) {
                status.hasPrintReceipt = typeof window.AndroidPrinter.printReceipt === 'function';
                if (typeof window.AndroidPrinter.ping === 'function') {
                    try {
                        status.pingResult = window.AndroidPrinter.ping();
                    } catch (e) {
                        status.pingResult = 'error: ' + e.message;
                    }
                }
            }
            
            // Check NativePrinter
            if (window.NativePrinter) {
                status.NativePrinterHasPrint = typeof window.NativePrinter.printReceipt === 'function';
            }
            
            // List all window properties that might be interfaces
            var interfaces = [];
            for (var key in window) {
                if (typeof window[key] === 'object' && window[key] !== null) {
                    if (typeof window[key].printReceipt === 'function') {
                        interfaces.push(key);
                    }
                }
            }
            status.interfacesWithPrintReceipt = interfaces;
            
            console.log('[AndroidPrinter Status]', JSON.stringify(status, null, 2));
            alert('Printer Status:\n' + JSON.stringify(status, null, 2));
            return status;
        }
        
        // Quick test print function
        function testAndroidPrint() {
            var printer = window.AndroidPrinter || window.NativePrinter;
            if (printer && printer.testPrint) {
                console.log('[TestPrint] Calling testPrint...');
                printer.testPrint();
                alert('Test print sent!');
            } else {
                alert('No printer interface with testPrint found.\nAndroidPrinter: ' + typeof window.AndroidPrinter + '\nNativePrinter: ' + typeof window.NativePrinter);
            }
        }

        let cart = [];
        let cartDiscountPercent = 0;
        let cartDiscountFixed = 0;

        function roundMoney(v) {
            return Math.round(Number(v) * 100) / 100;
        }

        function getCartLineSubtotal() {
            return cart.reduce((sum, item) => sum + item.price, 0);
        }

        function getCartDiscountAmountRounded() {
            const sub = roundMoney(getCartLineSubtotal());
            if (cart.length === 0 || sub <= 0) return 0;
            if (cartDiscountFixed > 0) {
                const amt = roundMoney(cartDiscountFixed);
                return Math.min(amt, sub);
            }
            if (cartDiscountPercent > 0) {
                return roundMoney(sub * (cartDiscountPercent / 100));
            }
            return 0;
        }

        function orderItemsWithDiscount() {
            const discountAmt = getCartDiscountAmountRounded();
            if (discountAmt <= 0) return cart.slice();
            return cart.concat([{ name: 'Cart Discount', price: -discountAmt, quantity: 1, barcode: '' }]);
        }

        function getSaleTotal() {
            const el = document.getElementById('cartTotal');
            if (!el) return 0;
            const t = parseFloat(String(el.innerText).replace(/,/g, ''));
            return isNaN(t) ? 0 : t;
        }

        function orderItemsForCheckout() {
            return orderItemsWithDiscount();
        }

        function getPayloadOrderTotal() {
            return roundMoney(orderItemsForCheckout().reduce((sum, item) => sum + item.price, 0));
        }

        function getAmountDue() {
            return getSaleTotal();
        }

        function handleCartDiscount() {
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Empty cart',
                    text: 'Add items before applying a discount.',
                    timer: 2200,
                    showConfirmButton: false
                });
                return;
            }

            const sub = roundMoney(getCartLineSubtotal());
            const pctPresets = [5, 10, 15, 20, 25, 50];
            const fixPresetCandidates = [5, 10, 25, 50, 100, 200, 500];
            const fixPresets = fixPresetCandidates.filter((a) => a > 0 && a <= sub + 1e-9);

            const pctChips = pctPresets.map(
                (p) =>
                    `<button type="button" data-preset-pct="${p}" class="disc-preset-pct rounded-xl border border-slate-200/90 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-teal-400 hover:bg-teal-50 hover:text-teal-900">${p}%</button>`
            ).join('');

            const fixChips =
                fixPresets.length > 0
                    ? fixPresets
                          .map(
                              (a) =>
                                  `<button type="button" data-preset-fix="${a}" class="disc-preset-fix rounded-xl border border-slate-200/90 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-teal-400 hover:bg-teal-50 hover:text-teal-900">N$${a}</button>`
                          )
                          .join('')
                    : `<span class="text-xs text-slate-500">No fixed presets for this subtotal — use custom amount.</span>`;

            const initialPct = cartDiscountPercent > 0 ? String(cartDiscountPercent) : '';
            const initialFix = cartDiscountFixed > 0 ? String(cartDiscountFixed) : '';

            const html = `
<div class="disc-modal-root text-left font-sans">
  <p class="mb-4 text-center text-sm text-slate-500">Apply a cart-level discount on the current subtotal.</p>

  <div class="mb-5 rounded-2xl border border-slate-200/90 bg-gradient-to-br from-slate-50 via-white to-teal-50/60 p-4 shadow-sm">
    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Subtotal</p>
    <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">N$<span id="discSubVal">${sub.toFixed(2)}</span></p>
  </div>

  <div class="mb-4 flex gap-1 rounded-2xl bg-slate-100/90 p-1">
    <button type="button" id="discTabPct" class="disc-tab flex-1 rounded-xl py-2.5 text-sm font-semibold transition ${cartDiscountFixed > 0 ? 'text-slate-500 hover:text-slate-800' : 'bg-white text-teal-800 shadow-sm ring-1 ring-slate-200/80'}">Percent off</button>
    <button type="button" id="discTabFix" class="disc-tab flex-1 rounded-xl py-2.5 text-sm font-semibold transition ${cartDiscountFixed > 0 ? 'bg-white text-teal-800 shadow-sm ring-1 ring-slate-200/80' : 'text-slate-500 hover:text-slate-800'}">Fixed off</button>
  </div>

  <div id="discPanelPct" class="disc-panel mb-4 space-y-3 ${cartDiscountFixed > 0 ? 'hidden' : ''}">
    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Custom percent</label>
    <div class="relative">
      <input id="discPctInput" type="number" inputmode="decimal" min="0" max="100" step="any" placeholder="0"
        value="${initialPct}"
        class="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-4 pr-10 text-lg font-semibold tabular-nums text-slate-900 shadow-inner outline-none transition focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20" />
      <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-lg font-bold text-slate-400">%</span>
    </div>
    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quick</p>
    <div class="flex flex-wrap gap-2">${pctChips}</div>
  </div>

  <div id="discPanelFix" class="disc-panel mb-4 space-y-3 ${cartDiscountFixed > 0 ? '' : 'hidden'}">
    <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amount to deduct (N$)</label>
    <div class="relative">
      <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-lg font-bold text-slate-400">N$</span>
      <input id="discFixInput" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0.00"
        value="${initialFix}"
        class="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-10 pr-4 text-lg font-semibold tabular-nums text-slate-900 shadow-inner outline-none transition focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20" />
    </div>
    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quick</p>
    <div class="flex flex-wrap gap-2">${fixChips}</div>
    <button type="button" id="discPresetFullOff" class="mt-1 w-full rounded-xl border border-dashed border-teal-300/80 bg-teal-50/40 py-2.5 text-xs font-bold uppercase tracking-wide text-teal-800 transition hover:bg-teal-100/80">Full subtotal off (100%)</button>
  </div>

  <div class="mb-5 flex items-center justify-between gap-4 rounded-2xl border border-teal-200/80 bg-gradient-to-r from-teal-50 to-emerald-50/80 px-4 py-3">
    <div>
      <p class="text-[10px] font-bold uppercase tracking-widest text-teal-600/90">After discount</p>
      <p id="discPreviewDiscRow" class="text-xs text-slate-600"></p>
    </div>
    <p id="discPayVal" class="text-2xl font-bold tabular-nums text-teal-900">N$${roundMoney(sub - getCartDiscountAmountRounded()).toFixed(2)}</p>
  </div>

  <div class="flex flex-col gap-2 sm:flex-row sm:flex-row-reverse">
    <button type="button" id="discApplyBtn" class="flex-1 rounded-2xl bg-teal-600 py-3.5 text-sm font-bold text-white shadow-lg shadow-teal-600/25 transition hover:bg-teal-700 active:scale-[0.98]">Apply discount</button>
    <button type="button" id="discClearBtn" class="flex-1 rounded-2xl border-2 border-slate-200 bg-white py-3.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 active:scale-[0.98]">Clear discount</button>
  </div>
</div>`;

            Swal.fire({
                title: '<span class="text-xl font-bold tracking-tight text-slate-900">Discount</span>',
                html,
                width: 'min(720px, 96vw)',
                padding: '1.5rem',
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: 'Cancel',
                buttonsStyling: true,
                customClass: {
                    popup: '!rounded-3xl border border-slate-200/80 shadow-2xl',
                    title: '!mb-2 !pb-0 !text-slate-900',
                    htmlContainer: '!mt-0'
                },
                focusCancel: false,
                didOpen: () => {
                    const root = Swal.getHtmlContainer();
                    if (!root) return;

                    const tabPct = root.querySelector('#discTabPct');
                    const tabFix = root.querySelector('#discTabFix');
                    const panelPct = root.querySelector('#discPanelPct');
                    const panelFix = root.querySelector('#discPanelFix');
                    const inputPct = root.querySelector('#discPctInput');
                    const inputFix = root.querySelector('#discFixInput');
                    const payVal = root.querySelector('#discPayVal');
                    const previewDisc = root.querySelector('#discPreviewDiscRow');

                    const activeTabClasses = 'bg-white text-teal-800 shadow-sm ring-1 ring-slate-200/80';
                    const idleTabClasses = 'text-slate-500 hover:text-slate-800';

                    function setTab(which) {
                        const pctActive = which === 'pct';
                        tabPct.className =
                            'disc-tab flex-1 rounded-xl py-2.5 text-sm font-semibold transition ' +
                            (pctActive ? activeTabClasses : idleTabClasses);
                        tabFix.className =
                            'disc-tab flex-1 rounded-xl py-2.5 text-sm font-semibold transition ' +
                            (!pctActive ? activeTabClasses : idleTabClasses);
                        panelPct.classList.toggle('hidden', !pctActive);
                        panelFix.classList.toggle('hidden', pctActive);
                        refreshPreview();
                    }

                    function discountFromInputs() {
                        const pctActive = !panelPct.classList.contains('hidden');
                        let disc = 0;
                        let label = '';
                        if (pctActive) {
                            const p = parseFloat(inputPct.value);
                            const pct = isNaN(p) ? 0 : Math.max(0, Math.min(100, p));
                            disc = roundMoney(sub * (pct / 100));
                            label = pct > 0 ? `−N$${disc.toFixed(2)} (${pct}%)` : '';
                        } else {
                            const f = parseFloat(inputFix.value);
                            const fix = isNaN(f) ? 0 : Math.max(0, f);
                            disc = Math.min(roundMoney(fix), sub);
                            label = fix > 0 ? `−N$${disc.toFixed(2)} fixed` : '';
                        }
                        return { disc: roundMoney(disc), label };
                    }

                    function refreshPreview() {
                        const { disc, label } = discountFromInputs();
                        const pay = roundMoney(sub - disc);
                        payVal.textContent = 'N$' + pay.toFixed(2);
                        previewDisc.textContent = label || 'No discount';
                    }

                    tabPct.addEventListener('click', () => setTab('pct'));
                    tabFix.addEventListener('click', () => setTab('fix'));

                    root.querySelectorAll('.disc-preset-pct').forEach((btn) => {
                        btn.addEventListener('click', () => {
                            inputPct.value = btn.getAttribute('data-preset-pct') || '';
                            setTab('pct');
                            refreshPreview();
                        });
                    });

                    root.querySelectorAll('.disc-preset-fix').forEach((btn) => {
                        btn.addEventListener('click', () => {
                            inputFix.value = btn.getAttribute('data-preset-fix') || '';
                            setTab('fix');
                            refreshPreview();
                        });
                    });

                    inputPct.addEventListener('input', refreshPreview);
                    inputFix.addEventListener('input', refreshPreview);

                    const fullOff = root.querySelector('#discPresetFullOff');
                    if (fullOff) {
                        fullOff.addEventListener('click', () => {
                            inputFix.value = sub.toFixed(2);
                            setTab('fix');
                            refreshPreview();
                        });
                    }

                    if (cartDiscountFixed > 0) setTab('fix');
                    else setTab('pct');

                    root.querySelector('#discClearBtn').addEventListener('click', () => {
                        cartDiscountPercent = 0;
                        cartDiscountFixed = 0;
                        updateCart();
                        Swal.close();
                    });

                    root.querySelector('#discApplyBtn').addEventListener('click', () => {
                        const pctActive = !panelPct.classList.contains('hidden');
                        if (pctActive) {
                            const p = parseFloat(inputPct.value);
                            const pct = isNaN(p) || inputPct.value === '' ? 0 : Math.max(0, Math.min(100, p));
                            cartDiscountPercent = pct;
                            cartDiscountFixed = 0;
                        } else {
                            const f = parseFloat(inputFix.value);
                            const fix = isNaN(f) || inputFix.value === '' ? 0 : Math.max(0, f);
                            cartDiscountFixed = Math.min(roundMoney(fix), sub);
                            cartDiscountPercent = 0;
                        }
                        updateCart();
                        Swal.close();
                    });

                    refreshPreview();
                }
            });
        }

        // Expose cart state to global inactivity script
        window.__hasCartItems = () => Array.isArray(cart) && cart.length > 0;
        window.__isCartEmpty = () => !(Array.isArray(cart) && cart.length > 0);

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
            const newQuantity = existingItem ? existingItem.quantity + 1 : 1;

            if (!skipStockChecks) {
                const availableQuantity = getAvailableQuantity(element) ?? getStockQuantityForName(name);
                if (availableQuantity === null || Number.isNaN(availableQuantity) || availableQuantity < newQuantity) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Insufficient Stock',
                        text: availableQuantity !== null && !Number.isNaN(availableQuantity)
                            ? `Only ${availableQuantity} units available for ${name}`
                            : `Unable to verify stock for ${name}`,
                        timer: 3000,
                        timerProgressBar: true
                    });
                    return;
                }
            }

            if (existingItem) {
                existingItem.quantity = newQuantity;
                existingItem.price = finalPrice * newQuantity;
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
                const productElement = findProductElement(item.name);
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

            if (cart.length === 0) {
                cartDiscountPercent = 0;
                cartDiscountFixed = 0;
            }

            const discountAmt = getCartDiscountAmountRounded();
            const payableTotal = roundMoney(total - discountAmt);
            cartTotal.innerText = payableTotal.toFixed(2);

            const discountSummary = document.getElementById('cartDiscountSummary');
            if (discountSummary) {
                if (discountAmt > 0 && cart.length > 0) {
                    let label = '';
                    if (cartDiscountFixed > 0) {
                        label = `Subtotal N$${total.toFixed(2)} · N$${roundMoney(cartDiscountFixed).toFixed(2)} off (−N$${discountAmt.toFixed(2)})`;
                    } else if (cartDiscountPercent > 0) {
                        label = `Subtotal N$${total.toFixed(2)} · ${cartDiscountPercent}% (−N$${discountAmt.toFixed(2)})`;
                    }
                    if (label) {
                        discountSummary.textContent = label;
                        discountSummary.classList.remove('hidden');
                    } else {
                        discountSummary.classList.add('hidden');
                    }
                } else {
                    discountSummary.classList.add('hidden');
                }
            }
            
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
            const productElement = findProductElement(cart[index].name);
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
            input.min = '0.001';
            input.step = 'any';
            input.className = 'w-16 px-2 py-1 text-center border border-gray-300 rounded text-sm quantity-input js-kioskboard-input js-kioskboard-decimal';
            input.setAttribute('data-pos-kb-placement', 'bottom');
            input.style.backgroundColor = '#dbeafe';
            input.style.color = '#1e40af';
            
            // Replace span with input
            quantitySpan.parentNode.replaceChild(input, quantitySpan);

            if (window.PosKioskBoard) {
                window.PosKioskBoard.bindDecimal(input, { placement: 'bottom', allowRealKeyboard: false });
            }

            requestAnimationFrame(function () {
                input.focus();
                input.select();
                if (window.PosKioskBoard && window.PosKioskBoard.openInput) {
                    window.PosKioskBoard.openInput(input);
                }

                // #region agent log
                fetch('http://127.0.0.1:7918/ingest/543ece8e-e9a4-4ceb-9f09-b26f1ebce51b',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'8253e3'},body:JSON.stringify({sessionId:'8253e3',runId:'post-fix-bottom',hypothesisId:'B',location:'home.php:editQuantity',message:'quantity keyboard opened',data:{index:index,placement:input.getAttribute('data-pos-kb-placement'),keyboardExists:!!document.getElementById('KioskBoard-VirtualKeyboard'),keyboardBottom:!!(document.getElementById('KioskBoard-VirtualKeyboard')&&document.getElementById('KioskBoard-VirtualKeyboard').classList.contains('pos-kb-bottom-panel'))},timestamp:Date.now()})}).catch(function(){});
                // #endregion
            });
            
            // Handle input completion
            function finishEditing() {
                if (window.PosKioskBoard) {
                    window.PosKioskBoard.close();
                }
                const newQuantity = parseFloat(input.value);
                
                if (!Number.isNaN(newQuantity) && newQuantity > 0) {
                    // Check available stock unless skip stock checks is enabled
                    if (!skipStockChecks) {
                        const availableQuantity = getStockQuantityForName(cart[index].name);
                        if (availableQuantity === null || Number.isNaN(availableQuantity) || newQuantity > availableQuantity) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Insufficient Stock',
                                text: availableQuantity !== null && !Number.isNaN(availableQuantity)
                                    ? `Only ${availableQuantity} units available for ${cart[index].name}`
                                    : `Unable to verify stock for ${cart[index].name}`,
                                timer: 3000,
                                timerProgressBar: true
                            });
                            cart[index].quantity = currentQuantity;
                            updateCart();
                            return;
                        }
                    }
                    // Update cart with new quantity and recalculate price (keep up to 3 decimal places)
                    const qty = Math.round(newQuantity * 1000) / 1000;
                    cart[index].quantity = qty;
                    cart[index].price = unitPrice * qty;
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
                    if (window.PosKioskBoard) {
                        window.PosKioskBoard.close();
                    }
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

        function resetToggleButtons() {
            const cashButtonsContainer = document.getElementById('cashButtonsContainer');
            const extraButtonsContainer = document.getElementById('extraButtonsContainer');
            const toggleBtn = document.getElementById('toggleExtraButtons');
            const toggleBtn2 = document.getElementById('toggleExtraButtons2');
            
            if (!cashButtonsContainer || !extraButtonsContainer) {
                return;
            }
            
            // Show cash buttons, hide extra buttons
            cashButtonsContainer.classList.remove('hidden');
            extraButtonsContainer.classList.add('hidden');
            
            // Reset icons to plus (+)
            const icon = toggleBtn ? toggleBtn.querySelector('svg path') : null;
            const icon2 = toggleBtn2 ? toggleBtn2.querySelector('svg path') : null;
            if (icon) {
                icon.setAttribute('d', 'M12 4v16m8-8H4');
            }
            if (icon2) {
                icon2.setAttribute('d', 'M12 4v16m8-8H4');
            }
        }

        function toggleExtraButtons() {
            const cashButtonsContainer = document.getElementById('cashButtonsContainer');
            const extraButtonsContainer = document.getElementById('extraButtonsContainer');
            const toggleBtn = document.getElementById('toggleExtraButtons');
            const toggleBtn2 = document.getElementById('toggleExtraButtons2');
            
            if (!cashButtonsContainer || !extraButtonsContainer) {
                console.error('Required containers not found');
                return;
            }
            
            const icon = toggleBtn ? toggleBtn.querySelector('svg path') : null;
            const icon2 = toggleBtn2 ? toggleBtn2.querySelector('svg path') : null;
            
            if (cashButtonsContainer.classList.contains('hidden')) {
                // Show cash buttons, hide extra buttons
                cashButtonsContainer.classList.remove('hidden');
                extraButtonsContainer.classList.add('hidden');
                // Change icon back to plus (+)
                if (icon) {
                    icon.setAttribute('d', 'M12 4v16m8-8H4');
                }
                if (icon2) {
                    icon2.setAttribute('d', 'M12 4v16m8-8H4');
                }
            } else {
                // Hide cash buttons, show extra buttons
                cashButtonsContainer.classList.add('hidden');
                extraButtonsContainer.classList.remove('hidden');
                // Change icon to minus (-)
                if (icon) {
                    icon.setAttribute('d', 'M20 12H4');
                }
                if (icon2) {
                    icon2.setAttribute('d', 'M20 12H4');
                }
                // Re-initialize Lucide icons when container is shown
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }
        }

        function handleCashBack() {
            // Get today's date as default
            const today = new Date().toISOString().split('T')[0];
            
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
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Date:</label>
                            <input type="date" 
                                   id="cashBackDate" 
                                   value="${today}"
                                   max="${today}"
                                   class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl 
                                          focus:border-gray-500 focus:ring-2 focus:ring-gray-200 
                                          text-base font-medium shadow-sm transition-all duration-200
                                          bg-gray-50 hover:bg-gray-100"
                                   required>
                            <p class="text-xs text-gray-500 mt-1">Select the date when the cash back occurred</p>
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
                                <option value="Hubbly">Hubbly</option>
                                <option value="Beerhouse">Beerhouse</option>
                                <option value="Customer">Customer</option>
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
                    const transactionDate = document.getElementById('cashBackDate').value;
                    if (!transactionDate) {
                        Swal.showValidationMessage('Please select a transaction date');
                        return false;
                    }
                    const transactionRef = document.getElementById('cashBackRef').value.trim() || '';
                    const walletProvider = document.getElementById('cashBackProvider').value || 'Customer';
                    return { amount, transactionDate, transactionRef, walletProvider };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const amount = result.value.amount;
                    const transactionDate = result.value.transactionDate;
                    const transactionRef = result.value.transactionRef || '';
                    const walletProvider = result.value.walletProvider || 'Customer';
                    
                    // Same amount is used for both payment and cash back
                    const eftTotal = amount;
                    const cashBackAmt = amount;
                    const saleAmount = amount;
                    
                    // Record cashback transaction
                    const cashbackData = {
                        eft_total: eftTotal,
                        cash_back: cashBackAmt,
                        sale_amount: saleAmount,
                        transaction_date: transactionDate,
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

            // Open drawer so cashier can count cash on hand
            openCashDrawer().catch(function() {});
            
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
                
                Swal.fire({
                        title: '<h1 class="text-2xl font-bold text-teal-700 mb-4">Cash Up - ' + selectedDate + '</h1>',
                        html: `
                            <div class="space-y-4">
                                <p class="text-sm text-gray-600 text-left">Enter the cash you counted. System expected cash is shown on the printed report after you submit.</p>
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
                            return { actualAmount };
                        }
                    }).then((result) => {
                        if (!result.isConfirmed) return;
                        const actualAmount = result.value.actualAmount;

                        fetch('get_cashup_data.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                start_date: selectedDate,
                                end_date: selectedDate,
                                start_time: '00:00',
                                end_time: '23:59',
                                cashier_id: currentUser,
                                include_expected_amounts: true,
                                actual_cash_in_till: actualAmount,
                                record_cashup_variance: true
                            })
                        })
                        .then(response => response.json())
                        .then(cashupData => {
                            if (!cashupData.success) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: cashupData.error || 'Failed to generate cash-up report',
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                                return;
                            }

                            if (cashupData.variance_record_error) {
                                console.warn('Variance record:', cashupData.variance_record_error);
                            }

                            const expectedAmount = parseFloat(cashupData.expected_cash_at_cashup != null ? cashupData.expected_cash_at_cashup : (cashupData.cash_in_till || 0));
                            const difference = typeof cashupData.cash_difference === 'number' ? cashupData.cash_difference : (actualAmount - expectedAmount);

                            const staffName = cashupData.cashier_name || currentUser;
                            const tillSystem = Number(cashupData.cash_in_till) || 0;
                            const cashSalesExpectedNum = Number(cashupData.cash_sales_expected) || 0;
                            const dateRangeStr = (cashupData.start_datetime && cashupData.end_datetime)
                                ? (cashupData.start_datetime + ' — ' + cashupData.end_datetime)
                                : (selectedDate + ' 00:00 — ' + selectedDate + ' 23:59');

                            const zReportData = {
                                is_cashup_report: true,
                                print_only: true,
                                date: selectedDate,
                                date_range: dateRangeStr,
                                cashier_username: currentUser,
                                cashier_name: staffName,
                                filter_cashier_name: staffName,
                                staff_name: staffName,
                                is_individual_cashout: true,
                                cash_sales_expected: cashSalesExpectedNum,
                                cash_in_till: tillSystem,
                                cash_on_hand: actualAmount,
                                card_sales_expected: Number(cashupData.card_sales_expected) || 0,
                                eft_on_hand: Number(cashupData.card_sales_expected) || 0,
                                eft_over_short: 0,
                                cash_sales: Number(cashupData.total_cash_sales) || 0,
                                total_cash_sales: Number(cashupData.total_cash_sales) || 0,
                                eft_sales: Number(cashupData.card_sales_expected) || 0,
                                total_eft_sales: Number(cashupData.card_sales_expected) || 0,
                                total_income: (Number(cashupData.total_cash_sales) || 0) + (Number(cashupData.card_sales_expected) || 0),
                                expected_cash: tillSystem,
                                unpaid_credit_sales: Number(cashupData.unpaid_credit_sales) || 0,
                                credit_unpaid: Number(cashupData.unpaid_credit_sales) || 0,
                                open_tabs_balance: Number(cashupData.open_tabs_balance) || 0,
                                open_tabs: Number(cashupData.open_tabs_balance) || 0,
                                unpaid_tabs: Number(cashupData.unpaid_tabs) || 0,
                                credit_returns: Number(cashupData.credit_returns) || 0,
                                cash_in: Number(cashupData.cash_in) || 0,
                                cash_out: Number(cashupData.cash_out) || 0,
                                expenses: Number(cashupData.expenses) || 0,
                                total_expense: Number(cashupData.expenses) || 0,
                                cash_back: Number(cashupData.cash_back_system) || 0,
                                cash_back_system: Number(cashupData.cash_back_system) || 0,
                                cash_back_beerhouse: Number(cashupData.cash_back_beerhouse) || 0,
                                cash_back_hubbly: Number(cashupData.cash_back_hubbly) || 0,
                                cash_back_customer: Number(cashupData.cash_back_customer) || 0,
                                tips: Number(cashupData.tips_system) || 0,
                                tips_system: Number(cashupData.tips_system) || 0,
                                hansa_cash: Number(cashupData.hansa_cash) || 0,
                                hansa_eft: Number(cashupData.hansa_eft) || 0,
                                hansa_units: parseInt(cashupData.hansa_units, 10) || 0,
                                voids: Number(cashupData.voids) || 0,
                                voids_count: parseInt(cashupData.voids_count, 10) || 0,
                                refunds: Number(cashupData.refunds) || 0,
                                refunds_count: parseInt(cashupData.refunds_count, 10) || 0,
                                damages: Number(cashupData.damages) || 0,
                                total_items_sold: Number(cashupData.total_items_sold) || 0,
                                generated_at: new Date().toLocaleString('en-ZA', {
                                    timeZone: 'Africa/Harare',
                                    year: 'numeric',
                                    month: '2-digit',
                                    day: '2-digit',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    second: '2-digit'
                                })
                            };

                            openCashDrawer();

                            const pdfData = {
                                is_cashup: 'true',
                                date: selectedDate,
                                cashier_username: currentUser,
                                total_cash_sales: cashupData.total_cash_sales || 0,
                                eft_sales_total: cashupData.card_sales_expected || 0,
                                unpaid_credit: cashupData.unpaid_credit_sales || 0,
                                cash_on_hand: actualAmount,
                                cash_available_in_till: tillSystem,
                                expected_cash: expectedAmount,
                                actual_cash_in_till: actualAmount,
                                cash_difference: difference,
                                total_cash_in: cashupData.cash_in || 0,
                                total_cash_out: cashupData.cash_out || 0,
                                cumulative_cash_sales: cashupData.total_cash_sales || 0,
                                cumulative_paid_credit: 0
                            };

                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'cash-pdf.php';
                            for (const [key, value] of Object.entries(pdfData)) {
                                const hiddenField = document.createElement('input');
                                hiddenField.type = 'hidden';
                                hiddenField.name = key;
                                hiddenField.value = value;
                                form.appendChild(hiddenField);
                            }
                            document.body.appendChild(form);
                            form.submit();
                            document.body.removeChild(form);

                            sendToPrinter(zReportData)
                            .then(printResult => {
                                if (printResult.success) {
                                    cashSound.play();
                                    return Swal.fire({
                                        icon: 'success',
                                        title: 'Cash Up Complete',
                                        text: (difference === 0 ?
                                            'Cash till balanced successfully' :
                                            `Cash till ${difference > 0 ? 'surplus' : 'shortage'} of N$${Math.abs(difference).toFixed(2)} recorded`) +
                                            '. You will be logged out.',
                                        timer: 2000,
                                        showConfirmButton: false
                                    }).then(() => {
                                        window.location.href = 'logout.php';
                                    });
                                } else {
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'PDF Generated',
                                        text: 'Receipt printing failed: ' + (printResult.message || 'Unknown error'),
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

        // Change button: open drawer and show change amount
        function handleChange() {
            const changeEl = document.getElementById('changeAmount');
            const changeAmount = changeEl ? parseFloat(changeEl.innerText.replace(/,/g, '')) || 0 : 0;
            openCashDrawer().then(function(result) {
                if (result && result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Change',
                        html: changeAmount > 0
                            ? `<p class="text-lg">Give change: <strong class="text-teal-600">N$${changeAmount.toFixed(2)}</strong></p><p class="text-sm text-gray-500 mt-2">Cash drawer opened.</p>`
                            : '<p class="text-gray-600">Cash drawer opened.</p><p class="text-sm text-gray-500 mt-2">No change amount set. Enter cash received at checkout to see change.</p>',
                        confirmButtonColor: '#0d9488'
                    });
                } else {
                    Swal.fire({ icon: 'info', title: 'Change', html: `<p class="text-lg">Give change: <strong class="text-teal-600">N$${changeAmount.toFixed(2)}</strong></p>`, confirmButtonColor: '#0d9488' });
                }
            }).catch(function() {
                Swal.fire({ icon: 'info', title: 'Change', html: `<p class="text-lg">Give change: <strong class="text-teal-600">N$${changeAmount.toFixed(2)}</strong></p>`, confirmButtonColor: '#0d9488' });
            });
        }

        // Refund handling
        let selectedRefundTransaction = null;
        let refundItems = [];

        function handleRefund() {
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-red-700 mb-4">Refund</h1>',
                html: `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Search Transaction:</label>
                            <input type="text" 
                                   id="refundTransactionSearch" 
                                   class="w-full px-4 py-2 border-2 border-red-100 rounded-xl 
                                          focus:border-red-500 focus:ring-2 focus:ring-red-200 
                                          text-base font-medium shadow-sm transition-all duration-200
                                          bg-red-50 hover:bg-red-100"
                                   placeholder="Enter Order ID or search by date..."
                                   onkeyup="searchTransactions(this.value)">
                            <p class="text-xs text-gray-500 mt-1">Search by order ID or leave empty to see recent transactions</p>
                        </div>
                        <div id="transactionsList" class="max-h-60 overflow-y-auto border rounded-lg">
                            <p class="text-gray-500 text-center py-4">Loading transactions...</p>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                showConfirmButton: false,
                cancelButtonText: 'Close',
                cancelButtonClass: 'swal2-cancel-btn bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                    htmlContainer: 'text-left'
                },
                width: '600px',
                allowOutsideClick: false,
                didOpen: () => {
                    loadRecentTransactions();
                }
            });
        }

        function loadRecentTransactions() {
            fetch('api/get_transactions.php?limit=20')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.transactions && data.transactions.length > 0) {
                        displayTransactions(data.transactions);
                    } else {
                        document.getElementById('transactionsList').innerHTML = 
                            '<p class="text-gray-500 text-center py-4">No transactions found</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading transactions:', error);
                    document.getElementById('transactionsList').innerHTML = 
                        '<p class="text-red-500 text-center py-4">Error loading transactions: ' + error.message + '</p>';
                });
        }

        function searchTransactions(query) {
            if (query.length === 0) {
                loadRecentTransactions();
                return;
            }
            
            fetch('api/get_transactions.php?search=' + encodeURIComponent(query))
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.transactions && data.transactions.length > 0) {
                        displayTransactions(data.transactions);
                    } else {
                        document.getElementById('transactionsList').innerHTML = 
                            '<p class="text-gray-500 text-center py-4">No transactions found for "' + query + '"</p>';
                    }
                })
                .catch(error => {
                    console.error('Error searching transactions:', error);
                    document.getElementById('transactionsList').innerHTML = 
                        '<p class="text-red-500 text-center py-4">Error searching: ' + error.message + '</p>';
                });
        }

        function displayTransactions(transactions) {
            let html = '<div class="divide-y divide-gray-200">';
            transactions.forEach(tx => {
                const date = new Date(tx.created_at).toLocaleString();
                html += `
                    <div class="p-3 hover:bg-red-50 cursor-pointer transition-colors duration-200" 
                         onclick="selectTransaction(${tx.id})">
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="font-semibold text-gray-800">Order #${tx.id}</span>
                                <span class="text-xs text-gray-500 ml-2">${date}</span>
                            </div>
                            <span class="font-bold text-red-600">N$${parseFloat(tx.total).toFixed(2)}</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">${tx.item_count} item(s)</div>
                    </div>
                `;
            });
            html += '</div>';
            document.getElementById('transactionsList').innerHTML = html;
        }

        function selectTransaction(orderId) {
            Swal.close();
            
            // Show loading
            Swal.fire({
                title: 'Loading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Fetch transaction details and items
            fetch('api/get_transaction_items.php?order_id=' + orderId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.items && data.items.length > 0) {
                        selectedRefundTransaction = data.order;
                        refundItems = data.items.map(item => ({
                            ...item,
                            refund_qty: 0,
                            max_qty: parseInt(item.quantity) || 0
                        }));
                        showRefundItemsModal();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Could not load transaction details. ' + (data.error || '')
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load transaction: ' + error.message
                    });
                });
        }

        function showRefundItemsModal() {
            let itemsHtml = refundItems.map((item, index) => `
                <div class="flex items-center justify-between p-3 border-b border-gray-100 hover:bg-gray-50">
                    <div class="flex-1">
                        <span class="font-medium text-gray-800">${item.product_name}</span>
                        <div class="text-xs text-gray-500">Price: N$${parseFloat(item.price).toFixed(2)} × ${item.quantity}</div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="button" 
                                class="w-8 h-8 bg-gray-200 hover:bg-gray-300 rounded-full flex items-center justify-center text-gray-700 font-bold"
                                onclick="updateRefundQty(${index}, -1)">-</button>
                        <input type="number" 
                               id="refundQty_${index}" 
                               class="w-16 text-center border rounded-lg py-1 px-2" 
                               value="0" 
                               min="0" 
                               max="${item.quantity}"
                               onchange="setRefundQty(${index}, this.value)">
                        <button type="button" 
                                class="w-8 h-8 bg-red-200 hover:bg-red-300 rounded-full flex items-center justify-center text-red-700 font-bold"
                                onclick="updateRefundQty(${index}, 1)">+</button>
                        <button type="button" 
                                class="ml-2 px-2 py-1 bg-red-100 hover:bg-red-200 text-red-700 text-xs rounded"
                                onclick="setRefundQty(${index}, ${item.quantity})">All</button>
                    </div>
                </div>
            `).join('');

            Swal.fire({
                title: `<h1 class="text-xl font-bold text-red-700 mb-2">Refund Order #${selectedRefundTransaction.id}</h1>`,
                html: `
                    <div class="text-left">
                        <div class="bg-gray-100 rounded-lg p-3 mb-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Original Total:</span>
                                <span class="font-semibold">N$${parseFloat(selectedRefundTransaction.total).toFixed(2)}</span>
                            </div>
                            <div class="flex justify-between text-sm mt-1">
                                <span class="text-gray-600">Date:</span>
                                <span>${new Date(selectedRefundTransaction.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h3 class="text-sm font-semibold text-gray-700 mb-2">Select items to refund:</h3>
                            <div class="max-h-64 overflow-y-auto border rounded-lg">
                                ${itemsHtml}
                            </div>
                        </div>
                        <div class="bg-red-50 rounded-lg p-3">
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-red-700">Refund Total:</span>
                                <span id="refundTotal" class="text-xl font-bold text-red-700">N$0.00</span>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Refund:</label>
                            <select id="refundReason" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-red-200">
                                <option value="Customer Request">Customer Request</option>
                                <option value="Wrong Item">Wrong Item</option>
                                <option value="Quality Issue">Quality Issue</option>
                                <option value="Price Error">Price Error</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mt-3" id="otherReasonDiv" style="display: none;">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Specify Reason:</label>
                            <input type="text" id="otherReason" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-red-200" placeholder="Enter reason...">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Process Refund',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                    confirmButton: 'bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg',
                    cancelButton: 'bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg'
                },
                width: '550px',
                allowOutsideClick: false,
                didOpen: () => {
                    // Initialize refund total
                    calculateRefundTotal();
                    
                    // Setup reason dropdown handler
                    const reasonSelect = document.getElementById('refundReason');
                    if (reasonSelect) {
                        reasonSelect.addEventListener('change', function() {
                            document.getElementById('otherReasonDiv').style.display = 
                                this.value === 'Other' ? 'block' : 'none';
                        });
                    }
                },
                preConfirm: () => {
                    const itemsToRefund = refundItems.filter(item => item.refund_qty > 0);
                    if (itemsToRefund.length === 0) {
                        Swal.showValidationMessage('Please select at least one item to refund');
                        return false;
                    }
                    
                    let reason = document.getElementById('refundReason').value;
                    if (reason === 'Other') {
                        reason = document.getElementById('otherReason').value || 'Other';
                    }
                    
                    return {
                        items: itemsToRefund,
                        reason: reason
                    };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    processRefund(result.value);
                }
            });
        }

        function updateRefundQty(index, change) {
            const input = document.getElementById('refundQty_' + index);
            let newVal = parseInt(input.value) + change;
            newVal = Math.max(0, Math.min(newVal, refundItems[index].max_qty));
            input.value = newVal;
            refundItems[index].refund_qty = newVal;
            calculateRefundTotal();
        }

        function setRefundQty(index, value) {
            const qty = Math.max(0, Math.min(parseInt(value) || 0, refundItems[index].max_qty));
            document.getElementById('refundQty_' + index).value = qty;
            refundItems[index].refund_qty = qty;
            calculateRefundTotal();
        }

        function calculateRefundTotal() {
            let total = 0;
            refundItems.forEach(item => {
                total += item.refund_qty * parseFloat(item.price);
            });
            document.getElementById('refundTotal').innerText = 'N$' + total.toFixed(2);
        }

        function processRefund(data) {
            const refundData = {
                order_id: selectedRefundTransaction.id,
                items: data.items.map(item => ({
                    order_item_id: item.id,
                    product_name: item.product_name,
                    quantity: item.refund_qty,
                    price: item.price,
                    buying_price: item.buying_price || 0
                })),
                reason: data.reason,
                total: data.items.reduce((sum, item) => sum + (item.refund_qty * parseFloat(item.price)), 0)
            };

            fetch('api/process_refund.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(refundData)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Prepare receipt data for printing
                    const receiptData = {
                        is_refund_receipt: true,
                        refund_id: result.refund_id,
                        order_id: selectedRefundTransaction.id,
                        items: data.items.map(item => ({
                            product_name: item.product_name,
                            quantity: item.refund_qty,
                            price: item.price
                        })),
                        total: refundData.total,
                        reason: data.reason,
                        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
                        print_only: true,
                        // Include business info
                        business_name: window.businessInfo?.business_name || businessInfo?.business_name,
                        location: window.businessInfo?.location || businessInfo?.location,
                        phone: window.businessInfo?.phone || businessInfo?.phone,
                        footer_text: window.businessInfo?.footer_text || businessInfo?.footer_text,
                        vat_inclusive: window.businessInfo?.vat_inclusive || businessInfo?.vat_inclusive,
                        vat_rate: window.businessInfo?.vat_rate || businessInfo?.vat_rate
                    };
                    
                    // Print refund receipt
                    if (typeof sendToPrinter === 'function') {
                        sendToPrinter(receiptData).catch(printError => {
                            console.error('Refund receipt printing error:', printError);
                        });
                    }
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Refund Processed',
                        html: `
                            <div class="text-left">
                                <p class="mb-2">Refund #${result.refund_id} has been processed successfully.</p>
                                <p class="font-semibold text-red-600">Amount: N$${refundData.total.toFixed(2)}</p>
                                <p class="text-sm text-gray-600 mt-2">Receipt has been printed.</p>
                            </div>
                        `,
                        confirmButtonColor: '#dc2626'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Refund Failed',
                        text: result.message || 'An error occurred while processing the refund'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to process refund. Please try again.'
                });
            });
        }


    function removeFromCart(index) {
            cart.splice(index, 1);
            sound.play(); // Play sound when removing item
            updateCart();
        }

        function calculateChange() {
            const amountDue = typeof getAmountDue === 'function' ? getAmountDue() : parseFloat(document.getElementById('cartTotal').innerText) || 0;
            const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
            const change = cashReceived - amountDue;
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

            // Check for out-of-stock items unless skip stock checks is enabled
            const outOfStockItemsEwallet = getCartOutOfStockItems(cart);
            if (outOfStockItemsEwallet.length > 0) {
                    const itemNames = outOfStockItemsEwallet.map(item => item.name).join(', ');
                    Swal.fire({
                        icon: 'error',
                        title: 'Out of Stock',
                        text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                        allowOutsideClick: false,
                    });
                    return;
            }

            const total = getPayloadOrderTotal();
            const cashReceived = parseFloat(document.getElementById('cashReceived')?.value) || 0;
            // Cash in Cash Received + EFT = mixed; empty cash = plain EFT
            const isMixedFromCash = cashReceived > 0;

            if (isMixedFromCash && cashReceived >= total) {
                Swal.fire({
                    icon: 'info',
                    title: 'Cash Covers Total',
                    text: 'Cash received covers the full amount. Use cash payment instead of EFT.',
                    allowOutsideClick: false,
                });
                return;
            }

            const cashAmount = isMixedFromCash ? cashReceived : 0;
            const eftAmount = isMixedFromCash ? Math.round((total - cashAmount) * 100) / 100 : total;
            const modalTitle = isMixedFromCash ? 'Cash + EFT Payment' : 'E-wallet Payment';
            const confirmTitle = isMixedFromCash ? 'Confirm Cash + EFT' : 'Confirm EFT Payment';
            const successTitle = isMixedFromCash ? 'Payment Processed' : 'EFT Payment Processed';

            // Show e-wallet payment modal
            Swal.fire({
                title: `<h1 class="text-2xl font-bold text-teal-700 mb-4">${modalTitle}</h1>`,
                html: `
                    <div class="space-y-4">
                        ${isMixedFromCash ? `
                        <div class="text-left text-sm text-gray-700 bg-teal-50 border border-teal-100 rounded-xl px-4 py-3">
                            <div>Total: <span class="font-bold">N$${total.toFixed(2)}</span></div>
                            <div>Cash: <span class="font-bold">N$${cashAmount.toFixed(2)}</span></div>
                            <div>EFT: <span class="font-bold">N$${eftAmount.toFixed(2)}</span></div>
                        </div>` : ''}
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
                        items: orderItemsForCheckout(),
                        total: total,
                        payment_method: isMixedFromCash ? 'mixed' : 'e-wallet',
                        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
                        // Always include business info from info.db
                        business_name: window.businessInfo?.business_name || businessInfo?.business_name,
                        location: window.businessInfo?.location || businessInfo?.location,
                        phone: window.businessInfo?.phone || businessInfo?.phone,
                        footer_text: window.businessInfo?.footer_text || businessInfo?.footer_text,
                        vat_inclusive: window.businessInfo?.vat_inclusive || businessInfo?.vat_inclusive,
                        vat_rate: window.businessInfo?.vat_rate || businessInfo?.vat_rate
                    };

                    if (isMixedFromCash) {
                        saleData.cash_amount = cashAmount;
                        saleData.eft_amount = eftAmount;
                    }

                    // Store transaction data globally for reverse transaction
                    window.pendingTransactionData = saleData;

                    // Ask for final confirmation and optionally receipt printing
                    Swal.fire({
                        icon: 'success',
                        title: confirmTitle,
                        confirmButtonText: 'OK',
                        footer: buildPaymentFooterHTML(),
                        allowOutsideClick: false,
                        focusConfirm: false
                    }).then((confirmRes) => {
                        if (!confirmRes.isConfirmed) {
                            // Clear pending transaction data if cancelled
                            window.pendingTransactionData = null;
                            return;
                        }
                        const printReceipt = document.getElementById('printReceiptCheckbox')?.checked;
                        // Process the payment AFTER confirmation
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
                                
                                // Clear pending transaction data after successful checkout
                                window.pendingTransactionData = null;
                                
                                if (isMixedFromCash && cashAmount > 0) {
                                    openCashDrawer();
                                }
                                cashSound.play();
                                if (printReceipt) {
                                    saleData.print_only = true;
                                    sendToPrinter(saleData).catch(printError => console.error('Receipt printing error:', printError));
                                }
                                clearCart();
                                refreshProductQuantities();
                                closeMobileCart();
                                Swal.fire({icon:'success', title: successTitle, timer:1200, showConfirmButton:false});
                            } else {
                                Swal.fire('Error', result.message || 'Failed to process payment', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire('Error', isMixedFromCash ? 'Could not process mixed payment' : 'Could not process e-wallet payment', 'error');
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

            const outOfStockItemsMixed = getCartOutOfStockItems(cart);
            if (outOfStockItemsMixed.length > 0) {
                const itemNames = outOfStockItemsMixed.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                });
                return;
            }

            const total = getPayloadOrderTotal();

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
                    items: orderItemsForCheckout(),
                    total: total,
                    payment_method: 'mixed',
                    cash_amount: cashAmount,
                    eft_amount: eftAmount,
                    wallet_provider: provider,
                    transaction_ref: ref,
                    cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
                    // Always include business info from info.db
                    business_name: window.businessInfo?.business_name || businessInfo?.business_name,
                    location: window.businessInfo?.location || businessInfo?.location,
                    phone: window.businessInfo?.phone || businessInfo?.phone,
                    footer_text: window.businessInfo?.footer_text || businessInfo?.footer_text,
                    vat_inclusive: window.businessInfo?.vat_inclusive || businessInfo?.vat_inclusive,
                    vat_rate: window.businessInfo?.vat_rate || businessInfo?.vat_rate
                };

                // Store transaction data globally for reverse transaction
                window.pendingTransactionData = saleData;

                // Final confirmation before processing and optional print
                Swal.fire({
                    icon: 'success',
                    title: 'Confirm Cash + EFT',
                    confirmButtonText: 'OK',
                    footer: buildPaymentFooterHTML(),
                    allowOutsideClick: false,
                    focusConfirm: false
                }).then(ok => {
                    if (!ok.isConfirmed) {
                        // Clear pending transaction data if cancelled
                        window.pendingTransactionData = null;
                        return;
                    }
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
                            
                            // Clear pending transaction data after successful checkout
                            window.pendingTransactionData = null;
                            
                            if (cashAmount > 0) {
                                openCashDrawer();
                            }
                            cashSound.play();
                            if (printReceipt) {
                                saleData.print_only = true;
                                sendToPrinter(saleData).catch(err => console.error('Receipt printing error:', err));
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

        function handleAccountPurchase() {
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Empty Cart',
                    text: 'Please add items to your cart first',
                    allowOutsideClick: false,
                });
                return;
            }
            const outOfStockItemsAccount = getCartOutOfStockItems(cart);
            if (outOfStockItemsAccount.length > 0) {
                const itemNames = outOfStockItemsAccount.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                });
                return;
            }
            Swal.fire({
                title: '<span class="text-xl font-semibold text-gray-900 tracking-tight">Account</span>',
                html: `
                    <p class="text-sm text-gray-500 mb-1">Choose how to put this sale on account.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4 text-left">
                        <button type="button" id="accBtnCredit" class="group relative w-full rounded-2xl border border-gray-200/80 bg-gradient-to-br from-white via-white to-teal-50/90 p-4 sm:p-5 shadow-sm hover:border-teal-300/80 hover:shadow-md hover:shadow-teal-100/40 transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2">
                            <div class="flex gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-teal-100 text-teal-700 ring-1 ring-teal-200/60 group-hover:bg-teal-600 group-hover:text-white group-hover:ring-teal-500/30 transition-colors duration-200" aria-hidden="true">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                </div>
                                <div class="min-w-0 flex-1 pt-0.5">
                                    <span class="block font-semibold text-gray-900 text-[15px] leading-snug group-hover:text-teal-900 transition-colors">Credit on account</span>
                                    <span class="mt-1 block text-xs text-gray-500 leading-relaxed">Goods leave now — balance on the customer&rsquo;s running credit account.</span>
                                </div>
                            </div>
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-teal-400 opacity-0 group-hover:opacity-100 transition-opacity hidden sm:block" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        </button>
                        <button type="button" id="accBtnLaybye" class="group relative w-full rounded-2xl border border-gray-200/80 bg-gradient-to-br from-white via-white to-indigo-50/90 p-4 sm:p-5 shadow-sm hover:border-indigo-300/80 hover:shadow-md hover:shadow-indigo-100/40 transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            <div class="flex gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200/60 group-hover:bg-indigo-600 group-hover:text-white group-hover:ring-indigo-500/30 transition-colors duration-200" aria-hidden="true">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M19 17v4m2-2h-4"/></svg>
                                </div>
                                <div class="min-w-0 flex-1 pt-0.5">
                                    <span class="block font-semibold text-gray-900 text-[15px] leading-snug group-hover:text-indigo-900 transition-colors">Lay-bye</span>
                                    <span class="mt-1 block text-xs text-gray-500 leading-relaxed">Deposit &amp; plan — customer pays in instalments; goods held until paid off.</span>
                                </div>
                            </div>
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-indigo-400 opacity-0 group-hover:opacity-100 transition-opacity hidden sm:block" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        </button>
                    </div>
                `,
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: 'Close',
                customClass: { popup: 'rounded-2xl shadow-2xl bg-white sm:max-w-xl' },
                didOpen: () => {
                    document.getElementById('accBtnCredit').onclick = () => {
                        Swal.close();
                        handleCreditPurchase();
                    };
                    document.getElementById('accBtnLaybye').onclick = () => {
                        Swal.close();
                        handleLaybyeFromCart();
                    };
                }
            });
        }

        function handleLaybyeFromCart() {
            if (cart.length === 0) {
                Swal.fire({ icon: 'error', title: 'Empty Cart', text: 'Please add items to cart first', allowOutsideClick: false });
                return;
            }
            const outOfStockItemsLaybye = getCartOutOfStockItems(cart);
            if (outOfStockItemsLaybye.length > 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `Insufficient quantity: ${outOfStockItemsLaybye.map(i => i.name).join(', ')}`,
                    allowOutsideClick: false,
                });
                return;
            }
            fetch('get_creditors_with_balances.php')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Failed to load creditors', 'error');
                        return;
                    }
                    showCreditorSelectionModal(data.creditors || [], proceedWithCreditorLaybye, { context: 'laybye' });
                })
                .catch(() => Swal.fire('Error', 'Failed to load creditors', 'error'));
        }

        function proceedWithCreditorLaybye(creditorId) {
            const cartTotal = getPayloadOrderTotal();
            const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x.toISOString().split('T')[0]; };
            const addMonth = (d) => { const x = new Date(d); x.setMonth(x.getMonth() + 1); return x.toISOString().split('T')[0]; };
            const today = new Date().toISOString().split('T')[0];
            const defaultPeriodForFreq = (frequency) => (frequency === 'monthly' ? 4 : 12);
            /** Balance after deposit ÷ number of remaining payments (clamped 1–120). */
            const laybyeSuggestedInstallment = (balAfter, numPeriods) => {
                if (balAfter <= 0) return 0;
                let p = parseInt(numPeriods, 10);
                if (!Number.isFinite(p) || p < 1) p = 1;
                if (p > 120) p = 120;
                return Math.max(0, Math.round((balAfter / p) * 100) / 100);
            };

            Swal.fire({
                title: '<span class="text-lg font-semibold text-gray-800">Lay-bye deposit</span>',
                html: `
                    <div class="text-left space-y-3 text-sm">
                        <p>Total: <strong>N$${cartTotal.toFixed(2)}</strong></p>
                        <label class="block text-gray-600">Deposit (N$)</label>
                        <input type="number" id="laybyeDeposit" min="0" step="0.01" max="${cartTotal}" value="" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                cancelButtonText: 'Back',
                confirmButtonText: 'Next',
                
                focusConfirm: false,
                customClass: { popup: 'rounded-xl shadow-lg bg-white' },
                didOpen: function () { swalPlaceConfirmButtonLeft(); },
                preConfirm: () => {
                    const dep = parseFloat(document.getElementById('laybyeDeposit').value) || 0;
                    if (dep < 0 || dep > cartTotal + 0.01) {
                        Swal.showValidationMessage('Invalid deposit');
                        return false;
                    }
                    return { deposit: dep };
                }
            }).then(res => {
                if (res.dismiss === Swal.DismissReason.cancel) {
                    fetch('get_creditors_with_balances.php').then(r => r.json()).then(d => {
                        if (d.success) showCreditorSelectionModal(d.creditors || [], proceedWithCreditorLaybye, { context: 'laybye' });
                    });
                    return;
                }
                if (!res.isConfirmed) return;
                const deposit = res.value.deposit;
                const balAfter = Math.round((cartTotal - deposit) * 100) / 100;
                const startFreq = 'weekly';
                const startPeriod = defaultPeriodForFreq(startFreq);
                const suggestedInst = laybyeSuggestedInstallment(balAfter, startPeriod);

                Swal.fire({
                    title: '<span class="text-lg font-semibold text-gray-800">Payment plan</span>',
                    html: `
                        <div class="text-left space-y-3 text-sm">
                            <div>
                                <label class="block text-gray-600 mb-1">Frequency</label>
                                <select id="laybyeFreq" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-600 mb-1">Plan length (number of payments)</label>
                                <input type="number" id="laybyePeriod" min="1" max="120" step="1" value="${startPeriod}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <p class="text-xs text-gray-500 mt-1">Installment updates from balance after deposit ÷ this count (you can still edit the amount).</p>
                            </div>
                            <div>
                                <label class="block text-gray-600 mb-1">Installment amount (N$)</label>
                                <input type="number" id="laybyeInstallment" min="0" step="0.01" value="${suggestedInst}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-gray-600 mb-1">Next due date</label>
                                <input type="date" id="laybyeNextDue" class="w-full px-3 py-2 border border-gray-300 rounded-lg" value="${addDays(today, 7)}">
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    reverseButtons: true,
                    confirmButtonText: 'Next',
                    cancelButtonText: 'Back',
                    focusConfirm: false,
                    customClass: { popup: 'rounded-xl shadow-lg bg-white' },
                    didOpen: () => {
                        swalPlaceConfirmButtonLeft();
                        const freq = document.getElementById('laybyeFreq');
                        const due = document.getElementById('laybyeNextDue');
                        const instEl = document.getElementById('laybyeInstallment');
                        const perEl = document.getElementById('laybyePeriod');
                        const syncInst = () => {
                            instEl.value = String(laybyeSuggestedInstallment(balAfter, perEl.value));
                        };
                        freq.addEventListener('change', () => {
                            due.value = freq.value === 'monthly' ? addMonth(today) : addDays(today, 7);
                            perEl.value = String(defaultPeriodForFreq(freq.value));
                            syncInst();
                        });
                        perEl.addEventListener('input', syncInst);
                        perEl.addEventListener('change', syncInst);
                    },
                    preConfirm: () => {
                        const inst = parseFloat(document.getElementById('laybyeInstallment').value) || 0;
                        const nd = document.getElementById('laybyeNextDue').value;
                        const per = parseInt(document.getElementById('laybyePeriod').value, 10);
                        if (inst < 0) {
                            Swal.showValidationMessage('Invalid installment');
                            return false;
                        }
                        if (!Number.isFinite(per) || per < 1 || per > 120) {
                            Swal.showValidationMessage('Plan length must be between 1 and 120 payments');
                            return false;
                        }
                        if (!nd) {
                            Swal.showValidationMessage('Next due date required');
                            return false;
                        }
                        return {
                            plan_frequency: document.getElementById('laybyeFreq').value,
                            plan_period: per,
                            installment_amount: inst,
                            next_due_date: nd
                        };
                    }
                }).then(planRes => {
                    if (planRes.dismiss === Swal.DismissReason.cancel) {
                        proceedWithCreditorLaybye(creditorId);
                        return;
                    }
                    if (!planRes.isConfirmed) return;
                    const plan = planRes.value;

                    const runPaymentStep = () => {
                        Swal.fire({
                            title: '<span class="text-lg font-semibold text-gray-800">Deposit payment</span>',
                            html: `
                                <div class="text-left space-y-3 text-sm">
                                    <p>Deposit: <strong>N$${deposit.toFixed(2)}</strong></p>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-2"><input type="radio" name="lbpm" value="cash" checked class="laybyePayM"> Cash</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="lbpm" value="eft" class="laybyePayM"> EFT</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="lbpm" value="mixed" class="laybyePayM"> Mixed</label>
                                    </div>
                                    <div id="lbCashFields" class="space-y-2">
                                        <label class="text-gray-600">Cash tendered</label>
                                        <input type="number" id="lbCashTendered" step="0.01" min="0" value="${deposit}" class="w-full px-3 py-2 border rounded-lg">
                                    </div>
                                    <div id="lbEftFields" class="space-y-2 hidden">
                                        <label class="text-gray-600">Provider</label>
                                        <select id="lbEftProvider" class="w-full px-3 py-2 border rounded-lg">
                                            <option>Credit Card (Swipe)</option>
                                            <option>E-wallet</option>
                                            <option>Easy Wallet</option>
                                        </select>
                                        <label class="text-gray-600">Reference (optional)</label>
                                        <input type="text" id="lbEftRef" class="w-full px-3 py-2 border rounded-lg">
                                    </div>
                                    <div id="lbMixedFields" class="space-y-2 hidden">
                                        <div class="grid grid-cols-2 gap-2">
                                            <div><label class="text-gray-600">Cash</label><input type="number" id="lbMixCash" step="0.01" min="0" value="0" class="w-full px-3 py-2 border rounded-lg"></div>
                                            <div><label class="text-gray-600">EFT</label><input type="number" id="lbMixEft" step="0.01" min="0" value="${deposit}" class="w-full px-3 py-2 border rounded-lg"></div>
                                        </div>
                                        <label class="text-gray-600">Provider</label>
                                        <select id="lbMixProvider" class="w-full px-3 py-2 border rounded-lg">
                                            <option>Credit Card (Swipe)</option>
                                            <option>E-wallet</option>
                                        </select>
                                        <label class="text-gray-600">Reference (optional)</label>
                                        <input type="text" id="lbMixRef" class="w-full px-3 py-2 border rounded-lg">
                                    </div>
                                </div>
                            `,
                            showCancelButton: true,
                            reverseButtons: true,
                            confirmButtonText: 'Complete lay-bye',
                            cancelButtonText: 'Back',
                            focusConfirm: false,
                            customClass: { popup: 'rounded-xl shadow-lg bg-white' },
                            didOpen: () => {
                                swalPlaceConfirmButtonLeft();
                                const sync = () => {
                                    const m = document.querySelector('.laybyePayM:checked').value;
                                    document.getElementById('lbCashFields').classList.toggle('hidden', m !== 'cash');
                                    document.getElementById('lbEftFields').classList.toggle('hidden', m !== 'eft');
                                    document.getElementById('lbMixedFields').classList.toggle('hidden', m !== 'mixed');
                                };
                                document.querySelectorAll('.laybyePayM').forEach(r => r.addEventListener('change', sync));
                                sync();
                            },
                            preConfirm: () => {
                                const m = document.querySelector('.laybyePayM:checked').value;
                                let payment_method = m;
                                let transaction_ref = '';
                                let wallet_provider = '';
                                let cash_amount = 0;
                                let eft_amount = 0;
                                let cash_tendered = 0;
                                if (deposit <= 0.01) {
                                    return { payment_method: 'cash', transaction_ref: '', wallet_provider: '', cash_amount: 0, eft_amount: 0, cash_tendered: 0 };
                                }
                                if (m === 'cash') {
                                    cash_tendered = parseFloat(document.getElementById('lbCashTendered').value) || 0;
                                    if (cash_tendered + 0.001 < deposit) {
                                        Swal.showValidationMessage('Cash tendered must cover deposit');
                                        return false;
                                    }
                                } else if (m === 'eft') {
                                    wallet_provider = document.getElementById('lbEftProvider').value;
                                    transaction_ref = document.getElementById('lbEftRef').value.trim();
                                } else {
                                    cash_amount = parseFloat(document.getElementById('lbMixCash').value) || 0;
                                    eft_amount = parseFloat(document.getElementById('lbMixEft').value) || 0;
                                    if (Math.abs((cash_amount + eft_amount) - deposit) > 0.02) {
                                        Swal.showValidationMessage('Cash + EFT must equal deposit');
                                        return false;
                                    }
                                    wallet_provider = document.getElementById('lbMixProvider').value;
                                    transaction_ref = document.getElementById('lbMixRef').value.trim();
                                }
                                return { payment_method, transaction_ref, wallet_provider, cash_amount, eft_amount, cash_tendered };
                            }
                        }).then(payRes => {
                            if (payRes.dismiss === Swal.DismissReason.cancel) {
                                Swal.fire({
                                    title: '<span class="text-lg font-semibold text-gray-800">Payment plan</span>',
                                    html: `
                                        <div class="text-left space-y-3 text-sm">
                                            <div>
                                                <label class="block text-gray-600 mb-1">Frequency</label>
                                                <select id="laybyeFreq2" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                                    <option value="weekly" ${plan.plan_frequency === 'weekly' ? 'selected' : ''}>Weekly</option>
                                                    <option value="monthly" ${plan.plan_frequency === 'monthly' ? 'selected' : ''}>Monthly</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-gray-600 mb-1">Plan length (number of payments)</label>
                                                <input type="number" id="laybyePeriod2" min="1" max="120" step="1" value="${(() => { const n = Number(plan.plan_period); return Number.isFinite(n) && n >= 1 && n <= 120 ? n : defaultPeriodForFreq(plan.plan_frequency || 'weekly'); })()}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                                <p class="text-xs text-gray-500 mt-1">Installment updates from balance after deposit ÷ this count (you can still edit the amount).</p>
                                            </div>
                                            <div>
                                                <label class="block text-gray-600 mb-1">Installment amount (N$)</label>
                                                <input type="number" id="laybyeInstallment2" min="0" step="0.01" value="${plan.installment_amount}" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                            </div>
                                            <div>
                                                <label class="block text-gray-600 mb-1">Next due date</label>
                                                <input type="date" id="laybyeNextDue2" class="w-full px-3 py-2 border border-gray-300 rounded-lg" value="${plan.next_due_date}">
                                            </div>
                                        </div>
                                    `,
                                    showCancelButton: true,
                                    reverseButtons: true,
                                    confirmButtonText: 'Next',
                                    cancelButtonText: 'Back',
                                    focusConfirm: false,
                                    customClass: { popup: 'rounded-xl shadow-lg bg-white' },
                                    didOpen: () => {
                                        swalPlaceConfirmButtonLeft();
                                        const balAfter2 = Math.round((cartTotal - deposit) * 100) / 100;
                                        const freq = document.getElementById('laybyeFreq2');
                                        const due = document.getElementById('laybyeNextDue2');
                                        const instEl = document.getElementById('laybyeInstallment2');
                                        const perEl = document.getElementById('laybyePeriod2');
                                        const syncInst = () => {
                                            instEl.value = String(laybyeSuggestedInstallment(balAfter2, perEl.value));
                                        };
                                        freq.addEventListener('change', () => {
                                            due.value = freq.value === 'monthly' ? addMonth(today) : addDays(today, 7);
                                            perEl.value = String(defaultPeriodForFreq(freq.value));
                                            syncInst();
                                        });
                                        perEl.addEventListener('input', syncInst);
                                        perEl.addEventListener('change', syncInst);
                                    },
                                    preConfirm: () => {
                                        const inst = parseFloat(document.getElementById('laybyeInstallment2').value) || 0;
                                        const nd = document.getElementById('laybyeNextDue2').value;
                                        const per = parseInt(document.getElementById('laybyePeriod2').value, 10);
                                        if (inst < 0) { Swal.showValidationMessage('Invalid installment'); return false; }
                                        if (!Number.isFinite(per) || per < 1 || per > 120) {
                                            Swal.showValidationMessage('Plan length must be between 1 and 120 payments');
                                            return false;
                                        }
                                        if (!nd) { Swal.showValidationMessage('Next due date required'); return false; }
                                        return {
                                            plan_frequency: document.getElementById('laybyeFreq2').value,
                                            plan_period: per,
                                            installment_amount: inst,
                                            next_due_date: nd
                                        };
                                    }
                                }).then(pr2 => {
                                    if (pr2.dismiss === Swal.DismissReason.cancel) {
                                        proceedWithCreditorLaybye(creditorId);
                                        return;
                                    }
                                    if (!pr2.isConfirmed) return;
                                    Object.assign(plan, pr2.value);
                                    runPaymentStep();
                                });
                                return;
                            }
                            if (!payRes.isConfirmed) return;
                            const pay = payRes.value;
                            const payload = {
                                creditor_id: creditorId,
                                items: orderItemsForCheckout(),
                                total: cartTotal,
                                deposit: deposit,
                                plan_frequency: plan.plan_frequency,
                                plan_period: plan.plan_period,
                                installment_amount: plan.installment_amount,
                                next_due_date: plan.next_due_date,
                                payment_method: pay.payment_method,
                                transaction_ref: pay.transaction_ref,
                                wallet_provider: pay.wallet_provider,
                                cash_amount: pay.cash_amount,
                                eft_amount: pay.eft_amount,
                                cash_tendered: pay.cash_tendered
                            };
                            fetch('process_laybye.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            })
                                .then(r => r.json())
                                .then(result => {
                                    if (result.success) {
                                        cashSound.play();
                                        clearCart();
                                        refreshProductQuantities();
                                        closeMobileCart();
                                        Swal.fire({ icon: 'success', title: 'Lay-bye created', text: result.reference || '', timer: 1800, showConfirmButton: false });
                                    } else {
                                        Swal.fire('Error', result.message || 'Failed to create lay-bye', 'error');
                                    }
                                })
                                .catch(() => Swal.fire('Error', 'Could not create lay-bye', 'error'));
                        });
                    };

                    if (deposit > 0.01) {
                        runPaymentStep();
                    } else {
                        fetch('process_laybye.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                creditor_id: creditorId,
                                items: orderItemsForCheckout(),
                                total: cartTotal,
                                deposit: 0,
                                plan_frequency: plan.plan_frequency,
                                plan_period: plan.plan_period,
                                installment_amount: plan.installment_amount,
                                next_due_date: plan.next_due_date,
                                payment_method: 'cash',
                                transaction_ref: '',
                                wallet_provider: '',
                                cash_amount: 0,
                                eft_amount: 0,
                                cash_tendered: 0
                            })
                        })
                            .then(r => r.json())
                            .then(result => {
                                if (result.success) {
                                    cashSound.play();
                                    clearCart();
                                    refreshProductQuantities();
                                    closeMobileCart();
                                    Swal.fire({ icon: 'success', title: 'Lay-bye created', text: result.reference || '', timer: 1800, showConfirmButton: false });
                                } else {
                                    Swal.fire('Error', result.message || 'Failed', 'error');
                                }
                            })
                            .catch(() => Swal.fire('Error', 'Could not create lay-bye', 'error'));
                    }
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

            const outOfStockItemsCredit = getCartOutOfStockItems(cart);
            if (outOfStockItemsCredit.length > 0) {
                const itemNames = outOfStockItemsCredit.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                });
                return;
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
                    showCreditorSelectionModal(creditors, proceedWithCreditor, { context: 'credit' });
                })
                .catch(error => {
                    console.error('Error fetching creditors:', error);
                    Swal.fire('Error', 'Failed to load creditors', 'error');
                });
        }

        /** Ensure SweetAlert2 confirm (e.g. Next) is to the right of cancel (Back) when both are shown. */
        function formatCreditMoney(amount) {
            return 'N$' + parseFloat(amount || 0).toFixed(2);
        }

        function getCreditorAvailableLabel(creditor) {
            if (!creditor || !creditor.is_limit_enforced) return '';
            const available = parseFloat(creditor.available_credit || 0);
            const limit = parseFloat(creditor.credit_limit || 0);
            return `${formatCreditMoney(available)} / ${formatCreditMoney(limit)}`;
        }

        function checkCreditSaleWithinLimit(creditorId, saleAmount) {
            const creditors = window._lastCreditorsList || [];
            const creditor = creditors.find(c => String(c.id) === String(creditorId));
            if (!creditor || !creditor.is_limit_enforced) return true;
            const sale = parseFloat(saleAmount || 0);
            const available = parseFloat(creditor.available_credit || 0);
            if (sale > available + 0.005) {
                Swal.fire({
                    icon: 'error',
                    title: 'Credit Limit Exceeded',
                    html: `Limit: <b>${formatCreditMoney(creditor.credit_limit)}</b><br>Outstanding: <b>${formatCreditMoney(creditor.outstanding_balance)}</b><br>Available: <b>${formatCreditMoney(available)}</b><br>Requested: <b>${formatCreditMoney(sale)}</b>`
                });
                return false;
            }
            return true;
        }

        function swalPlaceConfirmButtonLeft() {
            // No-op, SweetAlert2 puts confirm to the right by default.
        }

        function showCreditorSelectionModal(creditors, afterSelectFn, modalOptions) {
            window._lastCreditorsList = creditors || [];
            if (typeof afterSelectFn === 'function') {
                window._lastCreditorModalCallback = afterSelectFn;
            }
            const opts = modalOptions && typeof modalOptions === 'object' ? modalOptions : {};
            if (opts.context === 'laybye' || opts.context === 'credit') {
                window._creditorModalContext = opts.context;
            }
            const modalContext = window._creditorModalContext || 'credit';
            const isLaybyePick = modalContext === 'laybye';
            const modalTitleHtml = isLaybyePick
                ? '<h1 class="text-xl font-semibold text-gray-700 mb-3">Choose lay-bye account</h1>'
                : '<h1 class="text-xl font-semibold text-gray-700 mb-3">Choose Creditor</h1>';
            const creditorSelectCallback = window._lastCreditorModalCallback || proceedWithCreditor;
            // Create creditor list HTML with search
            let creditorsListHTML = '';
            if (creditors.length === 0) {
                creditorsListHTML = `
                    <div class="text-center py-8">
                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <p class="text-gray-600 text-xs font-medium">${isLaybyePick ? 'No active lay-bye accounts' : 'No active creditors'}</p>
                        <p class="text-gray-400 text-xs mt-0.5">Create a new account</p>
                    </div>
                `;
            } else {
                creditors.forEach(creditor => {
                    const balance = parseFloat(creditor.outstanding_balance || 0);
                    const balanceClass = balance > 0 ? 'text-orange-500 font-bold' : 'text-teal-600 font-semibold';
                    const balanceText = balance > 0 ? `N$${balance.toFixed(2)}` : 'N$0.00';
                    const available = parseFloat(creditor.available_credit || 0);
                    const limitEnforced = !!creditor.is_limit_enforced;
                    const availableClass = !limitEnforced ? balanceClass : (available <= 0.005 ? 'text-red-600 font-bold' : (available < parseFloat(creditor.credit_limit || 0) * 0.2 ? 'text-orange-500 font-bold' : 'text-teal-600 font-semibold'));
                    const rightLabel = limitEnforced ? getCreditorAvailableLabel(creditor) : balanceText;
                    const rightSub = limitEnforced ? `<div class="text-[10px] text-gray-500">Bal: ${balanceText}</div>` : '';
                    
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
                                <div class="flex flex-col items-end ml-auto flex-shrink-0">
                                    <span class="${availableClass} font-medium whitespace-nowrap px-2 py-0.5 rounded-full text-xs bg-gray-100 border border-gray-200" style="min-width: 65px; text-align: center;">
                                        ${limitEnforced ? 'Avail: ' + rightLabel : rightLabel}
                                    </span>
                                    ${rightSub}
                                </div>
                            </div>
                        </div>
                    `;
                });
            }

            Swal.fire({
                title: modalTitleHtml,
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
                                    <span>${isLaybyePick ? 'Balance' : 'Available'}</span>
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
                        const pickMsg = isLaybyePick ? 'Please select a lay-bye account first' : 'Please select a creditor account first';
                        Swal.showValidationMessage('<span class="text-red-500 text-sm">' + pickMsg + '</span>');
                        return false;
                    }
                    const creditorId = selectedItem.getAttribute('data-id');
                    return { creditorId: creditorId };
                },
                didOpen: () => {
                    swalPlaceConfirmButtonLeft();
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
                            const ctx = window._creditorModalContext || 'credit';
                            if (ctx === 'credit' && !checkCreditSaleWithinLimit(id, getPayloadOrderTotal())) {
                                return;
                            }
                            Swal.close();
                            creditorSelectCallback(selectedCreditorId);
                        }, 200); // Small delay for visual feedback
                    };
                }
            }).then((result) => {
                // Handle if user clicks Next without selection
                if (result && result.isConfirmed && result.value && result.value.creditorId) {
                    creditorSelectCallback(result.value.creditorId);
                } else if (result && result.isConfirmed && !result.value) {
                    // User tried to proceed without selection (should not happen due to preConfirm, but just in case)
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Selection',
                        text: isLaybyePick ? 'Please select a lay-bye account first' : 'Please select a creditor account first',
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
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Credit Limit (N$)</label>
                            <input type="number" 
                                   id="newCreditorLimit" 
                                   min="0" step="0.01"
                                   class="w-full px-3 py-2.5 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200" 
                                   placeholder="0 = unlimited" 
                                   value="0"
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
                    const credit_limit = parseFloat(document.getElementById('newCreditorLimit').value || '0');
                    
                    if (!name) {
                        Swal.showValidationMessage('<span class="text-red-500">Creditor name is required</span>');
                        return false;
                    }
                    
                    return { name, phone, credit_limit: isNaN(credit_limit) ? 0 : Math.max(0, credit_limit) };
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
            if (!checkCreditSaleWithinLimit(creditorId, getPayloadOrderTotal())) {
                return;
            }
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
                didOpen: function () { swalPlaceConfirmButtonLeft(); },
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
                        items: orderItemsForCheckout(),
                        total: getPayloadOrderTotal(),
                        cash_received: 0,
                        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
                        // Always include business info from info.db
                        business_name: window.businessInfo?.business_name || businessInfo?.business_name,
                        location: window.businessInfo?.location || businessInfo?.location,
                        phone: window.businessInfo?.phone || businessInfo?.phone,
                        footer_text: window.businessInfo?.footer_text || businessInfo?.footer_text,
                        vat_inclusive: window.businessInfo?.vat_inclusive || businessInfo?.vat_inclusive,
                        vat_rate: window.businessInfo?.vat_rate || businessInfo?.vat_rate
                    };

                    // Final confirmation and optional print before processing credit sale
                    Swal.fire({
                        icon: 'success',
                        title: 'Confirm Credit Sale',
                        confirmButtonText: 'OK',
                        footer: buildPaymentFooterHTML(),
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
                                    sendToPrinter(saleData).catch(printError => console.error('Receipt printing error:', printError));
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

            // Use Android native or server
            return sendToPrinter(drawerData)
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

    const chargeTotal = getPayloadOrderTotal();
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

    const change = cashReceived - chargeTotal;

    const outOfStockItemsCash = getCartOutOfStockItems(cart);
    if (outOfStockItemsCash.length > 0) {
        const itemNames = outOfStockItemsCash.map(item => item.name).join(', ');
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

    if (cashReceived < chargeTotal) {
        Swal.fire({
            icon: 'error',
            title: 'Insufficient Cash',
            text: 'The cash received is less than the amount due.',
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
        items: orderItemsForCheckout(),
        total: chargeTotal,
        cash_received: cashReceived,
        payment_method: 'cash',  // Ensure payment_method is set for cash payments
        cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
        // Always include business info from info.db
        business_name: window.businessInfo?.business_name || businessInfo?.business_name,
        location: window.businessInfo?.location || businessInfo?.location,
        phone: window.businessInfo?.phone || businessInfo?.phone,
        footer_text: window.businessInfo?.footer_text || businessInfo?.footer_text,
        vat_inclusive: window.businessInfo?.vat_inclusive || businessInfo?.vat_inclusive,
        vat_rate: window.businessInfo?.vat_rate || businessInfo?.vat_rate
    };

    // Store transaction data globally for reverse transaction
    window.pendingTransactionData = data;

    // If drawer should open on checkout, open it now before the confirmation dialog
    if (drawerOpenOnCheckout === 'on_checkout') {
        openCashDrawer().catch(err => console.error('Drawer opening error:', err));
    }

    // Ask for confirmation first; process only after OK
    Swal.fire({
        icon: 'success',
        title: `Change: N$${change.toFixed(2)}`,
        confirmButtonText: 'OK',
        footer: buildPaymentFooterHTML(),
        allowOutsideClick: false,
        focusConfirm: false
    }).then(result => {
        if (!result.isConfirmed) {
            // Clear pending transaction data if checkout is cancelled
            window.pendingTransactionData = null;
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
                
                // Clear pending transaction data after successful checkout
                window.pendingTransactionData = null;
                
                // Only open drawer if setting is 'on_ok' (default behavior)
                if (drawerOpenOnCheckout === 'on_ok') {
                    openCashDrawer();
                }
                cashSound.play();
                if (printReceipt) {
                    data.print_only = true;
                    sendToPrinter(data).catch(err => console.error('Receipt printing error:', err));
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
                    const qty = Math.max(0, parseInt(product.quantity, 10) || 0);
                    productStockByName[product.name] = qty;

                    const productElement = findProductElement(product.name);
                    if (productElement) {
                        productElement.setAttribute('data-available-quantity', String(qty));
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

            const outOfStockItemsTab = getCartOutOfStockItems(cart);
            if (outOfStockItemsTab.length > 0) {
                const itemNames = outOfStockItemsTab.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                    allowOutsideClick: false,
                });
                return;
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
            // Check if user can select all tables (managers and admins can)
            const canSelectAllTables = ['manager', 'admin'].includes(currentUserRole);

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
                    const balanceClass = balance > 0 ? 'text-orange-500 font-bold' : (balance < -0.005 ? 'text-teal-700 font-semibold' : 'text-teal-600 font-semibold');
                    const balanceText = balance > 0 ? `N$${balance.toFixed(2)}` : (balance < -0.005 ? `Cr N$${Math.abs(balance).toFixed(2)}` : 'N$0.00');
                    
                    // Check if this table belongs to the current user or if user can select all
                    const tableCashierId = table.cashier_id;
                    const isOwnTable = !tableCashierId || tableCashierId == currentUserId;
                    const canSelect = canSelectAllTables || isOwnTable;
                    
                    // Styling for selectable vs non-selectable tables
                    const containerClass = canSelect 
                        ? 'table-item bg-white rounded-lg p-2 mb-1 cursor-pointer hover:bg-gray-200 transition-colors duration-200 relative'
                        : 'table-item bg-gray-100 rounded-lg p-2 mb-1 cursor-not-allowed opacity-50 relative';
                    const onClickAttr = canSelect ? `onclick="selectTable(${table.id})"` : '';
                    const iconClass = canSelect ? 'text-gray-500' : 'text-gray-400';
                    const nameClass = canSelect ? 'text-gray-700' : 'text-gray-400';
                    const lockedIcon = !canSelect ? `
                        <svg class="w-3 h-3 text-gray-400 flex-shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    ` : '';
                    
                    tablesListHTML += `
                        <div class="${containerClass}" 
                             data-id="${table.id}" 
                             data-name="${table.name.toLowerCase()}"
                             data-number="${table.number}"
                             data-selectable="${canSelect}"
                             ${onClickAttr}>
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2 min-w-0" style="max-width: 50%;">
                                    <svg class="w-4 h-4 ${iconClass} flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                    <span class="font-medium ${nameClass} truncate">${table.name}</span>
                                    ${lockedIcon}
                                </div>
                                <div class="flex items-center gap-1.5 ml-auto flex-shrink-0">
                                    <span class="${canSelect ? balanceClass : 'text-gray-400'} font-medium whitespace-nowrap px-2 py-0.5 rounded-full text-xs bg-gray-100 border border-gray-200" style="min-width: 65px; text-align: center;">
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
                        <p class="text-sm text-teal-700 bg-teal-50 border border-teal-100 rounded-lg px-3 py-2">
                            Touch screen: tap the search field — the keyboard opens on the right side.
                        </p>
                        <!-- Search Bar and Create Table Button in same row -->
                        <div class="flex items-center gap-2">
                            <div class="kioskboard-input-wrap relative flex-1">
                                <input type="text" 
                                       id="tableSearch" 
                                       class="w-full h-10 px-3 pl-9 pr-10 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200 js-kioskboard-input js-kioskboard-text" 
                                       placeholder="Search..."
                                       autocomplete="off">
                                <svg class="absolute left-2.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-500 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
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
                    swalPlaceConfirmButtonLeft();
                    let selectedTableId = null;

                    if (window.PosKioskBoard) {
                        window.PosKioskBoard.bindSwalFields();
                    }

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

                    if (searchInput) {
                        requestAnimationFrame(function () {
                            searchInput.focus();
                            if (window.PosKioskBoard && window.PosKioskBoard.openInput) {
                                window.PosKioskBoard.openInput(searchInput);
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
                },
                willClose: () => {
                    if (window.PosKioskBoard) {
                        window.PosKioskBoard.close();
                    }
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
                        <p class="text-sm text-teal-700 bg-teal-50 border border-teal-100 rounded-lg px-3 py-2">
                            Touch screen: tap the table name field — the keyboard opens on the right side.
                        </p>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5" for="newTableName">Table Name <span class="text-red-500">*</span></label>
                            <div class="kioskboard-input-wrap">
                                <input type="text" 
                                       id="newTableName" 
                                       class="w-full px-3 py-2.5 pr-10 bg-[#f3f4f6] border-none rounded-lg text-gray-700 placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-300 transition-all duration-200 js-kioskboard-input js-kioskboard-text" 
                                       placeholder="Enter table name" 
                                       autocomplete="off">
                                <svg class="kioskboard-touch-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.001"/><path d="M10 8h.001"/><path d="M14 8h.001"/><path d="M18 8h.001"/><path d="M8 12h.001"/><path d="M12 12h.001"/><path d="M16 12h.001"/><path d="M7 16h10"/></svg>
                            </div>
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
                didOpen: () => {
                    if (window.PosKioskBoard) {
                        window.PosKioskBoard.bindSwalFields();
                    }
                    const nameInput = document.getElementById('newTableName');
                    if (nameInput) {
                        requestAnimationFrame(function () {
                            nameInput.focus();
                            if (window.PosKioskBoard && window.PosKioskBoard.openInput) {
                                window.PosKioskBoard.openInput(nameInput);
                            }
                        });
                    }
                },
                willClose: () => {
                    if (window.PosKioskBoard) {
                        window.PosKioskBoard.close();
                    }
                },
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
                items: orderItemsForCheckout(),
                total: getPayloadOrderTotal(),
                cash_received: 0,
                cashier_username: '<?php echo $_SESSION['username'] ?? 'Unknown'; ?>',
                // Always include business info from info.db
                business_name: window.businessInfo?.business_name || businessInfo?.business_name,
                location: window.businessInfo?.location || businessInfo?.location,
                phone: window.businessInfo?.phone || businessInfo?.phone,
                footer_text: window.businessInfo?.footer_text || businessInfo?.footer_text,
                vat_inclusive: window.businessInfo?.vat_inclusive || businessInfo?.vat_inclusive,
                vat_rate: window.businessInfo?.vat_rate || businessInfo?.vat_rate
            };

            // Final confirmation before processing tab sale
            Swal.fire({
                icon: 'success',
                title: 'Confirm Order',
                confirmButtonText: 'OK',
                footer: `
                    <div style="display: flex; justify-content: center; align-items: center;">
                        ${showReverseTransaction ? `<a href='#' onclick='return reverseTransaction(event)' style='color: #a1a1a1; text-decoration: none; font-weight: 500; font-size: 1.05em; margin-right: 18px;'>
                            <i class='fas fa-undo' style='margin-right: 6px;'></i> Reverse transaction
                        </a>` : ''}
                        <input type='checkbox' id='sendToKitchenCheckbox' style='transform: scale(1.2); margin-right: 8px; vertical-align: middle;' ${defaultPrintReceipt ? 'checked' : ''} ${kitchenPrinterConfigured ? '' : 'disabled'}>
                        <label for='sendToKitchenCheckbox' style='font-size: 1.05em; vertical-align: middle; cursor:pointer;'>Send to kitchen</label>
                    </div>
                `,
                allowOutsideClick: false,
                focusConfirm: false
            }).then(ok => {
                if (!ok.isConfirmed) return;
                const sendToKitchen = document.getElementById('sendToKitchenCheckbox')?.checked && kitchenPrinterConfigured;
                
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
                        
                        if (sendToKitchen) {
                            const kitchenTicketData = {
                                ...saleData,
                                print_only: true,
                                print_to_kitchen_printer: true
                            };
                            delete kitchenTicketData.is_payment_receipt;
                            sendToPrinter(kitchenTicketData).then(function(pr) {
                                if (pr && pr.success === false) {
                                    console.error('Kitchen print:', pr.message);
                                    Swal.fire({ icon: 'warning', title: 'Kitchen print', text: pr.message || 'Could not print to kitchen printer.', timer: 2500, showConfirmButton: false });
                                }
                            }).catch(printError => console.error('Kitchen ticket printing error:', printError));
                        }
                        
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
            cartDiscountPercent = 0;
            cartDiscountFixed = 0;
            updateCart();
            document.getElementById('cashReceived').value = '';
            document.getElementById('changeAmount').innerText = '0.00';
            resetToggleButtons(); // Reset toggle to show cash buttons
        }

        // (Inactivity logout handled globally via `cashier_inactivity.js`)
        
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
            
            // Get the pending transaction data
            const transactionData = window.pendingTransactionData;
            
            if (!transactionData) {
                if (typeof Swal !== 'undefined') {
                    Swal.close();
                }
                return false;
            }
            
            // Close the SweetAlert dialog
            if (typeof Swal !== 'undefined') {
                Swal.close();
            }
            
            // Secretly insert void transaction into database via AJAX
            fetch('void_transaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(transactionData)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Reset processing state and clear pending data
                    isProcessing = false;
                    window.pendingTransactionData = null;
                    
                    // Reset checkout button
                    const checkoutBtn = document.querySelector('button[onclick="checkout()"]');
                    if (checkoutBtn) {
                        checkoutBtn.innerHTML = 'Checkout';
                        checkoutBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                    
                    // Show success message
                    Swal.fire({
                        icon: 'success',
                        title: 'Transaction Reversed',
                        text: 'The transaction has been voided.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    console.error('Error voiding transaction:', result.error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to void transaction. Please try again.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'There was an error voiding the transaction.',
                    timer: 2000,
                    showConfirmButton: false
                });
            });
            
            return false;
        }
    </script>
    <?php $kbAssetPrefix = ''; $kbPart = 'script'; include __DIR__ . '/includes/kioskboard_payment.php'; ?>

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

            // Skip kioskboard / Swal modal inputs
            if (document.activeElement.classList && document.activeElement.classList.contains('js-kioskboard-input')) {
                return;
            }
            if (document.activeElement.closest && document.activeElement.closest('.swal2-popup')) {
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
        
        // Show Android debug info on page load (only in Android WebView)
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var status = {
                    windowAndroidPrinter: typeof window.AndroidPrinter,
                    hasInterface: !!(window.AndroidPrinter),
                    hasPrintReceipt: !!(window.AndroidPrinter && window.AndroidPrinter.printReceipt),
                    userAgent: navigator.userAgent
                };
                console.log('[AndroidPrinter Check on Load]', status);
                
                // If running in Android app (check user agent for 'median')
                if (navigator.userAgent.toLowerCase().indexOf('median') !== -1) {
                    console.log('[AndroidPrinter] Running in Median app');
                    if (!window.AndroidPrinter) {
                        console.warn('[AndroidPrinter] Interface NOT available!');
                    }
                }
            }, 1000);
        });

    </script>
</body>
</html>
