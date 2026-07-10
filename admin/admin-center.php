<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ./");
    exit();
}

// Check activation status with expiration
require_once '../activation_helper.php';
$activationCheck = checkActivationStatus();
if ($activationCheck['status'] === 'not_activated' || $activationCheck['status'] === 'expired') {
    header('Location: settings');
    exit();
}

// Database connections
try {
    $db = new PDO('sqlite:../pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $userDb = new PDO('sqlite:../user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $infoDb = new PDO('sqlite:../info.db');
    $infoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get business info
$businessInfo = [];
try {
    $businessInfo = $infoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Default values
}

// Get all creditors for credit operations
$creditors = [];
try {
    $creditorsQuery = $db->query("SELECT id, name FROM creditors WHERE active = 1 ORDER BY name");
    $creditors = $creditorsQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Empty array
}

// Get products for tips-as-inventory (e.g. tips as drinks)
$productsForTips = [];
try {
    $productsForTips = $db->query("SELECT id, name, price, quantity FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Empty array
}

// Get all cashiers and waitresses for cash up modal dropdown (same as Admin/home.php)
$allCashUpEmployees = [];
try {
    $employeesQuery = $userDb->query("SELECT id, username, role FROM users WHERE role IN ('cashier', 'waitress') ORDER BY username");
    $allCashUpEmployees = $employeesQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If query fails, leave empty
}

require_once __DIR__ . '/../ui_cards_helper.php';
ensureUiCardsSchema($infoDb);
$uiCardScope = 'admin_menu';
$hiddenUiCards = uiGetHiddenCards($infoDb, $uiCardScope);
$showHiddenUiCards = isset($_GET['show_hidden']);
$uiCardsCustomizeMode = isset($_GET['customize']) || $showHiddenUiCards;
$uiCardsApiUrl = '../ui_cards_api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Operations - POS System</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <script src="../receipt.php?js=true"></script>
    
    <style>
        .fade-in {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        
        input, select, textarea {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }
        
        /* Custom scrollbar */
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: rgb(133, 133, 133) #E5E7EB;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #E5E7EB;
            border-radius: 2px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #14b8a6;
            border-radius: 2px;
        }
        
        /* Operation card styles */
        .operation-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .operation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            background: white !important;
        }
        
        .operation-card:active {
            transform: scale(0.98);
        }
        
        /* Modal styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 1rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: scale(1);
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
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }

        .hamburger span:nth-child(1) { top: 0px; }
        .hamburger span:nth-child(2) { top: 10px; }
        .hamburger span:nth-child(3) { top: 20px; }

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
            z-index: 80;
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

        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
            }
            
            main {
                padding: 1rem;
            }
        }
        
        /* Loading spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #14b8a6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Mobile Overlay -->
        <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
        
        <!-- Main Content -->
        <div class="content flex-1 lg:ml-64">
            <!-- Mobile Header -->
            <div class="lg:hidden bg-white shadow-sm p-4 flex items-center justify-between sticky top-0 z-50">
                <div class="hamburger" onclick="toggleSidebar()">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <h1 class="text-lg font-semibold text-gray-800">Admin Operations</h1>
                <div class="w-8"></div>
            </div>
            
            <main class="p-4 lg:p-6">
                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2">Admin Operations Center</h1>
                    <p class="text-gray-600">Quick access to all Admin functions and operations</p>
                </div>
                
                <!-- All Operations in One Container -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6<?= $uiCardsCustomizeMode ? ' ui-cards-customize-mode' : '' ?>">
                    <?php include __DIR__ . '/../includes/ui_cards_toolbar.php'; ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        
                        <!-- Tabs -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="tabs" onclick="window.location.href='credit-tabs'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-indigo-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Tabs</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Tabs</h3>
                            <p class="text-sm text-gray-500">Open and manage customer tabs</p>
                        </div>

                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="laybye" onclick="window.location.href='laybye.php'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-violet-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-check text-violet-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-violet-100 text-violet-700 px-2 py-1 rounded-full">Lay-bye</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Lay-byes</h3>
                            <p class="text-sm text-gray-500">Plans, deposits, and payments</p>
                        </div>
                        
                        <!-- Credit Book -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="credit_book" onclick="window.location.href='credit-book'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-book text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Credit Book</h3>
                            <p class="text-sm text-gray-500">View and manage credit accounts</p>
                        </div>
                        
                        <!-- Cash In/Out -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="cash" onclick="window.location.href='cash'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Cash</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cash In/Out</h3>
                            <p class="text-sm text-gray-500">Record cash in and cash out</p>
                        </div>
                        
                        <!-- Add Expenses -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="expense" onclick="openOperationModal('expense', 'Add Expense', 'Record business expense (cash out)')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-file-invoice text-red-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">Expenses</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add Expenses</h3>
                            <p class="text-sm text-gray-500">Record business expenses and costs</p>
                        </div>
                        
                        <!-- Process Cash Back -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="cash_back" onclick="handleCashBack()">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hand-holding-usd text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Cash</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Process Cash Back</h3>
                            <p class="text-sm text-gray-500">Give cash back to customers</p>
                        </div>
                        
                        <!-- Cash Up -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="cash_up" onclick="openCashUpModal()">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-cash-register text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Cash</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cash Up</h3>
                            <p class="text-sm text-gray-500">End of day cash reconciliation</p>
                        </div>
                        
                        <!-- Add Damages -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="damages" onclick="window.location.href='damaged_goods.php'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Inventory</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add Damages</h3>
                            <p class="text-sm text-gray-500">Record damaged or spoiled goods</p>
                        </div>

                        <!-- Receive stock -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="receiving" onclick="window.location.href='receiving.php'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-dolly text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Inventory</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Receive stock</h3>
                            <p class="text-sm text-gray-500">Purchase orders, deliveries, and goods in</p>
                        </div>

                        <!-- Purchase orders (supplier POs) -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="suppliers" onclick="window.location.href='purchase_orders.php?tab=suppliers'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-violet-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-file-invoice text-violet-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-violet-100 text-violet-700 px-2 py-1 rounded-full">Procurement</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Suppliers</h3>
                            <p class="text-sm text-gray-500">Manage suppliers, create POs, and download PDFs</p>
                        </div>

                        <!-- Add product -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="add_product" onclick="window.location.href='add_product'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-cyan-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-plus-circle text-cyan-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-cyan-100 text-cyan-700 px-2 py-1 rounded-full">Inventory</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add product</h3>
                            <p class="text-sm text-gray-500">Create a new product in the catalog</p>
                        </div>

                        <!-- Opening / closing stock -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="stock_taking" onclick="window.location.href='stock_taking'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-sky-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-warehouse text-sky-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-sky-100 text-sky-700 px-2 py-1 rounded-full">Inventory</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Opening / closing stock</h3>
                            <p class="text-sm text-gray-500">Stock counts, opening and closing balances</p>
                        </div>
                        
                        <!-- Create Creditor Account -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="create_creditor" onclick="window.location.href='credit-book.php'">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Create Creditor Account</h3>
                            <p class="text-sm text-gray-500">Set up new credit customer account</p>
                        </div>
                        
                        <!-- Add Credit -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="add_credit" onclick="openOperationModal('addCredit', 'Add Credit', 'Add credit sale (system balance)')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-book text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add Credit</h3>
                            <p class="text-sm text-gray-500">Add credit sale (system balance) for a creditor</p>
                        </div>
                        
                        <!-- Add Credit Returns (Record Payment) -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="credit_return" onclick="openOperationModal('credit_return', 'Record Credit Payment', 'Record payment against creditor balance')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-undo text-purple-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add Credit Returns</h3>
                            <p class="text-sm text-gray-500">Record payment for credit customers</p>
                        </div>
                        
                        <!-- Add Tips -->
                        <div class="operation-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="tips" onclick="openOperationModal('tips', 'Add Tips', 'Record tips received')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hand-holding-heart text-pink-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-pink-100 text-pink-700 px-2 py-1 rounded-full">Tips</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add Tips</h3>
                            <p class="text-sm text-gray-500">Record tips and gratuities</p>
                        </div>
                        
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Add Credit Modal (system balance sale) -->
    <div id="addCreditModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800">Add Credit</h3>
                    <button type="button" onclick="closeModal('addCreditModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-500 mb-6">Add a credit sale as &quot;System Balance&quot; (no product items). Sale date is stored as selected.</p>
                <form id="addCreditForm" onsubmit="processAddCredit(event)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date of sale</label>
                        <input type="date" id="addCreditDate" name="date" required
                            value="<?= date('Y-m-d') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Creditor account</label>
                        <select id="addCreditCreditor" name="creditor_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required>
                            <option value="">Select creditor</option>
                            <?php foreach ($creditors as $creditor): ?>
                                <option value="<?= $creditor['id'] ?>"><?= htmlspecialchars($creditor['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (N$)</label>
                        <input type="number" step="0.01" min="0.01" id="addCreditAmount" name="amount" required
                            placeholder="0.00"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Due date</label>
                        <input type="date" id="addCreditDueDate" name="due_date"
                            value="<?= date('Y-m-d') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeModal('addCreditModal')" class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 px-4 py-3 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-check"></i>
                            Add Credit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Credit Payment Modal (record payment against creditor balance) -->
    <div id="credit_returnModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800">Record Credit Payment</h3>
                    <button type="button" onclick="closeModal('credit_returnModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-500 mb-6">Record a credit payment (System Balance). Choose Cash or EFT. Stored with the selected date.</p>
                <form id="creditReturnForm" onsubmit="processCreditReturn(event)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date of payment</label>
                        <input type="date" id="creditReturnDate" name="date" required
                            value="<?= date('Y-m-d') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Creditor account</label>
                        <select id="creditReturnCustomer" name="creditor_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required>
                            <option value="">Select creditor</option>
                            <?php foreach ($creditors as $creditor): ?>
                                <option value="<?= $creditor['id'] ?>"><?= htmlspecialchars($creditor['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment method</label>
                        <select id="creditPaymentMethod" name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required>
                            <option value="cash">Cash</option>
                            <option value="eft">EFT</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment amount (N$)</label>
                        <input type="number" step="0.01" min="0.01" id="creditReturnAmount" name="amount" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required placeholder="0.00">
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeModal('credit_returnModal')" class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 px-4 py-3 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-check"></i>
                            Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Expense Modal (cash-out like cash.php) -->
    <div id="expenseModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800">Add Expense</h3>
                    <button type="button" onclick="closeModal('expenseModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-500 mb-4">Record an expense (cash out). Same as cash-out in Cash page.</p>
                <form id="expenseForm" onsubmit="processExpense(event)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date of expense</label>
                        <input type="date" id="expenseDate" name="date" required
                            value="<?= date('Y-m-d') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expense / Description</label>
                        <input type="text" id="expenseDescription" name="description" required
                            placeholder="e.g. Electricity, Ice Cubes, Toilet Paper"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" step="0.01" min="0.01" id="expenseAmount" name="amount" required
                            placeholder="0.00"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeModal('expenseModal')" class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 px-4 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-check"></i>
                            Record Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Tips Modal -->
    <div id="tipsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800">Add Tips</h3>
                    <button onclick="closeModal('tipsModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-500 mb-4">Record tips as cash/card or as inventory (e.g. drinks given as tips).</p>
                
                <form id="tipsForm" onsubmit="processTips(event)">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" id="tipDate" name="date" value="<?= date('Y-m-d') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tip type</label>
                        <select id="tipType" name="tip_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" onchange="toggleTipTypeFields()">
                            <option value="cash_card">Cash / Card</option>
                            <option value="inventory">Inventory (e.g. drink)</option>
                        </select>
                    </div>
                    
                    <!-- Cash/Card fields -->
                    <div id="tipCashCardFields">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tip Amount</label>
                            <input type="number" step="0.01" id="tipAmount" name="amount" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="0.00">
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                            <select id="tipPaymentMethod" name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Inventory fields (tips as drinks / products) -->
                    <div id="tipInventoryFields" class="hidden">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Product</label>
                            <select id="tipProductId" name="product_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <option value="">Select product (e.g. drink)</option>
                                <?php foreach ($productsForTips as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" data-price="<?= htmlspecialchars($p['price']) ?>" data-qty="<?= (int)$p['quantity'] ?>"><?= htmlspecialchars($p['name']) ?> (N$<?= number_format($p['price'], 2) ?>, stock: <?= (int)$p['quantity'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                            <input type="number" min="1" id="tipQuantity" name="quantity" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="1" value="1">
                            <p class="text-xs text-gray-500 mt-1">Deducts from inventory</p>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                        <textarea id="tipNotes" name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"></textarea>
                    </div>
                    
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeModal('tipsModal')" class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 px-4 py-3 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-check"></i>
                            Add Tip
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Cash Up Multi-Step Modal (same as Admin/home.php, uses get_cashup_data.php) -->
    <div id="cashUpModal" class="hidden fixed inset-0 z-[10000] overflow-y-auto">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity" onclick="closeCashUpModal()"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl transform transition-all">
                <div class="bg-gradient-to-r from-teal-600 to-teal-500 rounded-t-2xl px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-white/20 rounded-xl">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-white">Cash Up</h2>
                                <p class="text-teal-100 text-sm">Complete the daily cash reconciliation</p>
                            </div>
                        </div>
                        <button onclick="closeCashUpModal()" class="p-2 hover:bg-white/20 rounded-lg transition-colors">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="flex items-center justify-center gap-2 mt-6">
                        <div id="step_indicator_1" class="w-8 h-8 rounded-full bg-teal-400 text-white flex items-center justify-center text-sm font-semibold ring-2 ring-teal-300">1</div>
                        <div class="w-8 h-1 bg-white/30 rounded"></div>
                        <div id="step_indicator_2" class="w-8 h-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm font-semibold">2</div>
                        <div class="w-8 h-1 bg-white/30 rounded"></div>
                        <div id="step_indicator_3" class="w-8 h-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm font-semibold">3</div>
                        <div class="w-8 h-1 bg-white/30 rounded"></div>
                        <div id="step_indicator_4" class="w-8 h-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm font-semibold">4</div>
                        <div class="w-8 h-1 bg-white/30 rounded"></div>
                        <div id="step_indicator_5" class="w-8 h-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm font-semibold">5</div>
                        <div class="w-8 h-1 bg-white/30 rounded"></div>
                        <div id="step_indicator_6" class="w-8 h-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm font-semibold">6</div>
                    </div>
                </div>
                <div id="cashup_loading" class="hidden absolute inset-0 bg-white/80 rounded-2xl flex items-center justify-center z-10">
                    <div class="text-center">
                        <div class="w-12 h-12 border-4 border-teal-500 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
                        <p class="text-gray-600 font-medium">Loading data...</p>
                    </div>
                </div>
                <div class="p-6">
                    <div id="cashup_step_1">
                        <h3 class="text-lg font-semibold text-teal-700 mb-4">Select Date Range & Staff Member</h3>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-4">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Important:</strong> All amounts in the following steps will be calculated based on the date range you select below. Make sure to choose the correct dates before proceeding.
                            </p>
                        </div>
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Starting Date & Hour (24h)</label>
                                    <div class="flex gap-2 items-center">
                                        <input type="date" id="cashup_start_date" class="flex-1 min-w-0 px-3 py-3 border-2 border-teal-100 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 transition-all bg-teal-50 hover:bg-teal-100" value="<?= date('Y-m-d') ?>">
                                        <select id="cashup_start_hour" class="w-20 shrink-0 px-3 py-3 border-2 border-teal-100 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 bg-teal-50 hover:bg-teal-100 text-center font-medium" title="Hour (24h)"><?php for ($h = 0; $h < 24; $h++) { echo '<option value="'.$h.'"'.($h===0?' selected':'').'>'.str_pad($h,2,'0',STR_PAD_LEFT).':00</option>'; } ?></select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Ending Date & Hour (24h)</label>
                                    <div class="flex gap-2 items-center">
                                        <input type="date" id="cashup_end_date" class="flex-1 min-w-0 px-3 py-3 border-2 border-teal-100 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 transition-all bg-teal-50 hover:bg-teal-100" value="<?= date('Y-m-d') ?>">
                                        <select id="cashup_end_hour" class="w-20 shrink-0 px-3 py-3 border-2 border-teal-100 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 bg-teal-50 hover:bg-teal-100 text-center font-medium" title="Hour (24h)"><?php for ($h = 0; $h < 24; $h++) { echo '<option value="'.$h.'"'.($h===23?' selected':'').'>'.str_pad($h,2,'0',STR_PAD_LEFT).':00</option>'; } ?></select>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label for="cashup_cashier" class="block text-sm font-medium text-gray-700 mb-2">Cashier / Waitress (Optional)</label>
                                <select id="cashup_cashier" class="w-full px-4 py-3 border-2 border-teal-100 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 transition-all bg-teal-50 hover:bg-teal-100">
                                    <option value="all">All Staff</option>
                                    <?php foreach ($allCashUpEmployees as $employee): ?>
                                    <option value="<?= htmlspecialchars($employee['username']) ?>">
                                        <?= htmlspecialchars($employee['username']) ?> (<?= ucfirst($employee['role']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-sm text-gray-500 mt-2">Select a specific staff member or "All Staff" for combined totals</p>
                            </div>
                        </div>
                    </div>
                    <div id="cashup_step_2" class="hidden">
                        <div class="bg-teal-50 border border-teal-200 rounded-lg px-4 py-2 mb-4 hidden" id="cashup_date_range_display">
                            <p class="text-sm text-teal-800"><i class="fas fa-calendar-alt mr-2"></i><span id="cashup_date_range_text"></span></p>
                        </div>
                        <h3 class="text-lg font-semibold text-teal-700 mb-4">Summary</h3>
                        <input type="hidden" id="cashup_tips" value="0">
                        <input type="hidden" id="cashup_unpaid_credit" value="0">
                        <input type="hidden" id="cashup_credit_returns" value="0">
                        <div class="space-y-6 font-mono text-sm">
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <div class="bg-gray-100 px-4 py-2 border-b border-gray-200"><span class="font-semibold text-gray-800">Credit</span></div>
                                <div class="px-4 py-3 space-y-1.5 bg-white">
                                    <div class="flex justify-between"><span class="text-gray-700">- Credit (Unpaid) (N$)</span><span id="step2_credit_unpaid" class="font-medium text-right">0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-700">- Credit Return (Payments) (N$)</span><span id="step2_credit_returns" class="font-medium text-right">0.00</span></div>
                                </div>
                            </div>
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <div class="px-4 py-3 bg-white">
                                    <div class="flex justify-between items-center"><span class="text-gray-700 font-medium">Tips</span><span id="step2_tips" class="font-medium text-right">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <div class="px-4 py-3 bg-white">
                                    <div class="flex justify-between items-center"><span class="text-gray-700 font-medium">Expenses</span><span id="step2_expenses" class="font-medium text-right">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <div class="px-4 py-3 bg-white">
                                    <div class="flex justify-between items-center"><span class="text-gray-700 font-medium">Damages</span><span id="step2_damages" class="font-medium text-right">N$ 0.00</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="cashup_step_3" class="hidden">
                        <div class="bg-teal-50 border border-teal-200 rounded-lg px-4 py-2 mb-4">
                            <p class="text-sm text-teal-800"><i class="fas fa-calendar-alt mr-2"></i><span id="cashup_date_range_text_step3"></span></p>
                        </div>
                        <h3 class="text-lg font-semibold text-teal-700 mb-4">Expenses</h3>
                        <div class="bg-red-50 rounded-xl p-5">
                            <p class="text-sm text-gray-600 mb-4">Enter total expenses (cash-outs) for this period. System value is pre-filled when data is loaded; you can adjust from your count.</p>
                            <div>
                                <label for="cashup_expenses" class="block text-sm font-medium text-gray-700 mb-2">Total Expenses (N$)</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">N$</span>
                                    <input type="number" id="cashup_expenses" step="0.01" min="0" placeholder="0.00" class="w-full pl-12 pr-4 py-4 border-2 border-red-200 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 text-xl font-semibold text-right transition-all bg-white">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="cashup_step_4" class="hidden">
                        <div class="bg-teal-50 border border-teal-200 rounded-lg px-4 py-2 mb-4">
                            <p class="text-sm text-teal-800"><i class="fas fa-calendar-alt mr-2"></i><span id="cashup_date_range_text_step4"></span></p>
                        </div>
                        <h3 class="text-lg font-semibold text-teal-700 mb-4">Enter Cash On Hand</h3>
                        <div class="bg-gradient-to-br from-teal-50 to-cyan-50 rounded-xl p-5 mb-5">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-gray-600 font-medium">Cash Sales (Expected)</span>
                                <span id="step4_expected" class="text-xl font-bold text-teal-700">N$ 0.00</span>
                            </div>
                            <div class="border-t border-teal-200 pt-3">
                                <label for="cashup_cash_on_hand" class="block text-sm font-medium text-gray-700 mb-2">Actual Cash On Hand</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">N$</span>
                                    <input type="number" id="cashup_cash_on_hand" step="0.01" min="0" placeholder="0.00" class="w-full pl-12 pr-4 py-4 border-2 border-teal-300 rounded-xl focus:ring-2 focus:ring-teal-200 focus:border-teal-500 text-xl font-semibold text-right transition-all">
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-3 border-t border-teal-200">
                                <span class="text-gray-600 font-medium">Over / Short</span>
                                <span id="step4_over_short" class="text-2xl font-bold text-teal-700">N$ 0.00</span>
                            </div>
                        </div>
                    </div>
                    <div id="cashup_step_5" class="hidden">
                        <div class="bg-teal-50 border border-teal-200 rounded-lg px-4 py-2 mb-4">
                            <p class="text-sm text-teal-800"><i class="fas fa-calendar-alt mr-2"></i><span id="cashup_date_range_text_step5"></span></p>
                        </div>
                        <h3 class="text-lg font-semibold text-teal-700 mb-4">Enter EFT On Hand</h3>
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-5 mb-5">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-gray-600 font-medium">EFT Sales (Expected)</span>
                                <span id="step5_eft_expected" class="text-xl font-bold text-blue-700">N$ 0.00</span>
                            </div>
                            <div class="border-t border-blue-200 pt-3">
                                <label for="cashup_eft_on_hand" class="block text-sm font-medium text-gray-700 mb-2">Actual EFT On Hand</label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">N$</span>
                                    <input type="number" id="cashup_eft_on_hand" step="0.01" min="0" placeholder="0.00" class="w-full pl-12 pr-4 py-4 border-2 border-blue-300 rounded-xl focus:ring-2 focus:ring-blue-200 focus:border-blue-500 text-xl font-semibold text-right transition-all">
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-3 border-t border-blue-200">
                                <span class="text-gray-600 font-medium">Over / Short</span>
                                <span id="step5_eft_over_short" class="text-2xl font-bold text-blue-700">N$ 0.00</span>
                            </div>
                        </div>
                    </div>
                    <div id="cashup_step_6" class="hidden">
                        <h3 class="text-lg font-semibold text-teal-700 mb-4">Review & Print Receipt</h3>
                        <div class="bg-gray-50 rounded-xl p-4 mb-4">
                            <div class="flex justify-between text-sm mb-2"><span class="text-gray-600">Date:</span><span id="review_date" class="font-semibold">-</span></div>
                            <div class="flex justify-between text-sm"><span class="text-gray-600">Staff:</span><span id="review_cashier" class="font-semibold">-</span></div>
                        </div>
                        <div class="space-y-3 max-h-64 overflow-y-auto pr-2">
                            <div class="border-l-4 border-teal-500 pl-3">
                                <h4 class="font-semibold text-gray-700 text-sm mb-2">CASH</h4>
                                <div class="space-y-1 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Expected:</span><span id="review_cash_expected" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">On Hand:</span><span id="review_cash_on_hand" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Over/Short:</span><span id="review_over_short" class="font-semibold">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border-l-4 border-blue-500 pl-3">
                                <h4 class="font-semibold text-gray-700 text-sm mb-2">EFT</h4>
                                <div class="space-y-1 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Expected:</span><span id="review_eft_expected" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">On Hand:</span><span id="review_eft_on_hand" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Over/Short:</span><span id="review_eft_over_short" class="font-semibold">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border-l-4 border-indigo-500 pl-3">
                                <h4 class="font-semibold text-gray-700 text-sm mb-2">CREDIT</h4>
                                <div class="space-y-1 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Unpaid Credit Sales:</span><span id="review_unpaid_credit" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Credit Returns:</span><span id="review_credit_returns" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Open Tabs:</span><span id="review_open_tabs" class="font-medium">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border-l-4 border-red-500 pl-3">
                                <h4 class="font-semibold text-gray-700 text-sm mb-2">DEDUCTIONS</h4>
                                <div class="space-y-1 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Expenses:</span><span id="review_expenses" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Tips:</span><span id="review_tips" class="font-medium">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border-l-4 border-orange-500 pl-3">
                                <h4 class="font-semibold text-gray-700 text-sm mb-2">ADJUSTMENTS</h4>
                                <div class="space-y-1 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Voids:</span><span id="review_voids" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Refunds:</span><span id="review_refunds" class="font-medium">N$ 0.00</span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Damages:</span><span id="review_damages" class="font-medium">N$ 0.00</span></div>
                                </div>
                            </div>
                            <div class="border-l-4 border-teal-700 pl-3 bg-teal-50 rounded-r-lg py-2">
                                <div class="flex justify-between text-sm">
                                    <span class="font-semibold text-teal-700">Total Items Sold:</span>
                                    <span id="review_total_sold" class="font-bold text-teal-700">N$ 0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-between">
                    <button id="cashup_prev_btn" onclick="cashUpPrevStep()" class="invisible px-5 py-2.5 text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-100 transition-colors font-medium flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Previous
                    </button>
                    <div class="flex gap-3">
                        <button onclick="closeCashUpModal()" class="px-5 py-2.5 text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-100 transition-colors font-medium">Cancel</button>
                        <button id="cashup_next_btn" onclick="cashUpNextStep()" class="px-6 py-2.5 bg-teal-600 text-white rounded-xl hover:bg-teal-700 transition-colors font-medium flex items-center gap-2">Next <i class="fas fa-arrow-right"></i></button>
                        <button id="cashup_submit_btn" onclick="submitCashUp()" class="hidden px-6 py-2.5 bg-teal-600 text-white rounded-xl hover:bg-teal-700 transition-colors font-medium flex items-center gap-2"><i class="fas fa-print"></i> Print Receipt</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functions
        function openOperationModal(type, title, description) {
            const modalId = type + 'Modal';
            const modal = document.getElementById(modalId);
            if (modal) {
                if (type === 'tips') {
                    document.getElementById('tipType').value = 'cash_card';
                    if (typeof toggleTipTypeFields === 'function') toggleTipTypeFields();
                }
                modal.classList.add('active');
            }
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
            }
        }
        
        // Open cash drawer (same as cash.php / home.php flow)
        function openCashDrawer() {
            if (typeof window.sendToPrinter === 'function') {
                return window.sendToPrinter({ open_drawer_only: true, cashier_username: '<?php echo $_SESSION['username'] ?? ''; ?>' })
                    .catch(() => ({ success: false }));
            }
            return fetch('../open_drawer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ open_drawer_only: true, cashier_username: '<?php echo $_SESSION['username'] ?? ''; ?>' })
            }).then(r => r.json()).catch(() => ({ success: false }));
        }
        
        // Process Cash Back - same logic as home.php (Swal dialog, EFT/cash back amount, date, provider)
        function handleCashBack() {
            const today = new Date().toISOString().split('T')[0];
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-gray-700 mb-4">Cash Back</h1>',
                html: `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Amount:</label>
                            <input type="number" id="cashBackAmount" min="0" step="0.01"
                                class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl focus:border-gray-500 focus:ring-2 focus:ring-gray-200 text-base font-medium shadow-sm transition-all duration-200 bg-gray-50 hover:bg-gray-100"
                                placeholder="0.00">
                            <p class="text-xs text-gray-500 mt-1">Enter the amount for EFT payment and cash withdrawal</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Date:</label>
                            <input type="date" id="cashBackDate" value="${today}" max="${today}"
                                class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl focus:border-gray-500 focus:ring-2 focus:ring-gray-200 text-base font-medium shadow-sm transition-all duration-200 bg-gray-50 hover:bg-gray-100" required>
                            <p class="text-xs text-gray-500 mt-1">Select the date when the cash back occurred</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Provider (Optional):</label>
                            <select id="cashBackProvider" class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl focus:border-gray-500 focus:ring-2 focus:ring-gray-200 text-base font-medium shadow-sm transition-all duration-200 bg-gray-50 hover:bg-gray-100">
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
                customClass: { popup: 'rounded-2xl shadow-xl' },
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
                    const transactionRef = (document.getElementById('cashBackRef') && document.getElementById('cashBackRef').value) ? document.getElementById('cashBackRef').value.trim() : '';
                    const walletProvider = document.getElementById('cashBackProvider').value || 'Customer';
                    return { amount, transactionDate, transactionRef, walletProvider };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const { amount, transactionDate, transactionRef, walletProvider } = result.value;
                    const cashbackData = {
                        eft_total: amount,
                        cash_back: amount,
                        sale_amount: amount,
                        transaction_date: transactionDate,
                        transaction_ref: transactionRef || '',
                        wallet_provider: walletProvider || 'Customer'
                    };
                    fetch('../process_cashback.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(cashbackData)
                    })
                    .then(res => {
                        if (!res.ok) {
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
                            openCashDrawer().then(() => {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Cash Back Processed',
                                    text: 'Cash back transaction completed successfully',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
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
                        let errorMessage = 'Error processing cash back';
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
        
        // Process Add Credit (system balance sale)
        function processAddCredit(event) {
            event.preventDefault();
            const form = event.target;
            const date = form.querySelector('[name="date"]').value;
            const creditorId = form.querySelector('[name="creditor_id"]').value;
            const amount = form.querySelector('[name="amount"]').value;
            const dueDate = form.querySelector('[name="due_date"]').value || date;
            if (!creditorId || !amount || parseFloat(amount) <= 0) {
                Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a creditor and enter a valid amount.' });
                return;
            }
            fetch('../process_credit_sale_simple.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ date, creditor_id: creditorId, amount: parseFloat(amount), due_date: dueDate })
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    Swal.fire({ icon: 'success', title: 'Success', text: 'Credit sale added successfully.', timer: 2000 });
                    closeModal('addCreditModal');
                    form.reset();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed to add credit sale' });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred.' }));
        }
        
        // Process Credit Payment (System Balance, Cash or EFT)
        function processCreditReturn(event) {
            event.preventDefault();
            const form = event.target;
            const date = form.querySelector('[name="date"]').value;
            const creditorId = form.querySelector('[name="creditor_id"]').value;
            const amount = form.querySelector('[name="amount"]').value;
            const paymentMethod = form.querySelector('[name="payment_method"]').value;
            if (!creditorId || !amount || parseFloat(amount) <= 0) {
                Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a creditor and enter a valid amount.' });
                return;
            }
            const payload = { date, creditor_id: creditorId, amount: parseFloat(amount), payment_method: paymentMethod };
            fetch('../process_credit_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    Swal.fire({ icon: 'success', title: 'Success', text: 'Credit payment recorded successfully.', timer: 2000 });
                    closeModal('credit_returnModal');
                    form.reset();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed to record payment' });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred.' }));
        }
        
        function toggleTipTypeFields() {
            const tipType = document.getElementById('tipType').value;
            const cashCard = document.getElementById('tipCashCardFields');
            const inventory = document.getElementById('tipInventoryFields');
            const tipAmount = document.getElementById('tipAmount');
            if (tipType === 'inventory') {
                cashCard.classList.add('hidden');
                inventory.classList.remove('hidden');
                tipAmount.removeAttribute('required');
            } else {
                cashCard.classList.remove('hidden');
                inventory.classList.add('hidden');
                tipAmount.setAttribute('required', 'required');
            }
        }
        
        // Process Tips (cash/card or inventory)
        function processTips(event) {
            event.preventDefault();
            const tipType = document.getElementById('tipType').value;
            const formData = new FormData(event.target);
            const tipDate = formData.get('date') || new Date().toISOString().split('T')[0];
            let data = { tip_type: tipType, notes: formData.get('notes') || '', date: tipDate };
            if (tipType === 'inventory') {
                const productId = formData.get('product_id');
                const quantity = parseInt(formData.get('quantity'), 10) || 1;
                if (!productId) {
                    Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select a product.' });
                    return;
                }
                data.product_id = productId;
                data.quantity = quantity;
            } else {
                const amount = parseFloat(formData.get('amount'));
                if (!amount || amount <= 0) {
                    Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter a valid tip amount.' });
                    return;
                }
                data.amount = amount;
                data.payment_method = formData.get('payment_method') || 'cash';
            }
            
            fetch('../process_tips.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    Swal.fire({ icon: 'success', title: 'Success', text: result.message || 'Tip recorded successfully', timer: 2000 });
                    closeModal('tipsModal');
                    document.getElementById('tipsForm').reset();
                    document.getElementById('tipDate').value = new Date().toISOString().split('T')[0];
                    document.getElementById('tipQuantity').value = 1;
                    toggleTipTypeFields();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed to record tip' });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while recording tip' }));
        }
        
        // Process Expense (cash-out, same as cash.php)
        function processExpense(event) {
            event.preventDefault();
            const form = event.target;
            const data = {
                date: document.getElementById('expenseDate').value,
                description: document.getElementById('expenseDescription').value.trim(),
                amount: parseFloat(document.getElementById('expenseAmount').value)
            };
            if (!data.description) {
                Swal.fire({ icon: 'warning', title: 'Required', text: 'Enter expense description.' });
                return;
            }
            if (!data.amount || data.amount <= 0) {
                Swal.fire({ icon: 'warning', title: 'Required', text: 'Enter a valid amount.' });
                return;
            }
            fetch('../process_expense.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    Swal.fire({ icon: 'success', title: 'Success', text: result.message || 'Expense recorded.', timer: 2000 });
                    closeModal('expenseModal');
                    form.reset();
                    document.getElementById('expenseDate').value = new Date().toISOString().split('T')[0];
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message || 'Failed to record expense' });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred' }));
        }
        
        // Close modal on outside click
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
        
        // ==========================================
        // CASH UP MULTI-STEP MODAL (same as Admin/home.php, uses get_cashup_data.php)
        // ==========================================
        let cashUpCurrentStep = 1;
        let cashUpTotalSteps = 6;
        let cashUpSystemData = null;
        
        function getCashUpStartTime() {
            const h = document.getElementById('cashup_start_hour');
            return h ? String(parseInt(h.value, 10)).padStart(2, '0') + ':00' : '00:00';
        }
        function getCashUpEndTime() {
            const h = document.getElementById('cashup_end_hour');
            return h ? String(parseInt(h.value, 10)).padStart(2, '0') + ':59' : '23:59';
        }
        
        function openCashUpModal() {
            cashUpCurrentStep = 1;
            cashUpSystemData = null;
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('cashup_start_date').value = today;
            document.getElementById('cashup_start_hour').value = '0';
            document.getElementById('cashup_end_date').value = today;
            document.getElementById('cashup_end_hour').value = '23';
            document.getElementById('cashup_cashier').value = 'all';
            document.getElementById('cashup_cash_on_hand').value = '';
            document.getElementById('cashup_eft_on_hand').value = '';
            document.getElementById('cashup_tips').value = '';
            document.getElementById('cashup_unpaid_credit').value = '';
            document.getElementById('cashup_credit_returns').value = '';
            document.getElementById('cashup_expenses').value = '';
            document.getElementById('cashUpModal').classList.remove('hidden');
            updateCashUpStepDisplay();
        }
        
        function closeCashUpModal() {
            document.getElementById('cashUpModal').classList.add('hidden');
        }
        
        function updateCashUpStep2Summary() {
            if (!cashUpSystemData) return;
            const fmt = (n) => (n == null || isNaN(n) ? 0 : n).toFixed(2);
            const el = (id, text) => { const e = document.getElementById(id); if (e) e.textContent = text; };
            el('step2_credit_unpaid', fmt(cashUpSystemData.unpaid_credit_sales));
            el('step2_credit_returns', fmt(cashUpSystemData.credit_returns));
            el('step2_tips', 'N$ ' + fmt(cashUpSystemData.tips_system));
            el('step2_expenses', 'N$ ' + fmt(cashUpSystemData.expenses));
            el('step2_damages', 'N$ ' + fmt(cashUpSystemData.damages));
        }
        
        function updateCashUpStepDisplay() {
            for (let i = 1; i <= cashUpTotalSteps; i++) {
                const stepContent = document.getElementById('cashup_step_' + i);
                if (stepContent) stepContent.classList.add('hidden');
            }
            const currentStepContent = document.getElementById('cashup_step_' + cashUpCurrentStep);
            if (currentStepContent) currentStepContent.classList.remove('hidden');
            for (let i = 1; i <= 6; i++) {
                const ind = document.getElementById('step_indicator_' + i);
                if (!ind) continue;
                ind.classList.remove('bg-teal-400', 'ring-2', 'ring-teal-300', 'bg-gray-200', 'text-gray-600');
                if (i < cashUpCurrentStep) {
                    ind.classList.add('bg-teal-400', 'text-white', 'ring-2', 'ring-teal-300');
                } else if (i === cashUpCurrentStep) {
                    ind.classList.add('bg-teal-400', 'text-white', 'ring-2', 'ring-teal-300');
                } else {
                    ind.classList.add('bg-gray-200', 'text-gray-600');
                }
            }
            document.getElementById('cashup_prev_btn').classList.toggle('invisible', cashUpCurrentStep === 1);
            document.getElementById('cashup_next_btn').classList.toggle('hidden', cashUpCurrentStep === cashUpTotalSteps);
            document.getElementById('cashup_submit_btn').classList.toggle('hidden', cashUpCurrentStep !== cashUpTotalSteps);
        }
        
        /** Opens read-only report with the same figures as the printed receipt (cashup_print_report.php). */
        function openCashUpReceiptReport(snapshot) {
            try {
                const json = JSON.stringify(snapshot);
                const s = btoa(unescape(encodeURIComponent(json)));
                window.open('cashup_print_report.php?s=' + encodeURIComponent(s), '_blank');
            } catch (e) {
                console.error('openCashUpReceiptReport', e);
            }
        }
        
        async function cashUpNextStep() {
            if (cashUpCurrentStep === 1) {
                const startDate = document.getElementById('cashup_start_date').value;
                const startTime = getCashUpStartTime();
                const endDate = document.getElementById('cashup_end_date').value;
                const endTime = getCashUpEndTime();
                if (!startDate || !endDate) {
                    showCashUpNotification('Please select starting and ending date and time', 'error');
                    return;
                }
                const startDt = new Date(startDate + 'T' + startTime);
                const endDt = new Date(endDate + 'T' + endTime);
                if (endDt <= startDt) {
                    showCashUpNotification('Ending date & time must be after starting date & time', 'error');
                    return;
                }
                const cashierId = document.getElementById('cashup_cashier').value;
                document.getElementById('cashup_loading').classList.remove('hidden');
                try {
                    const response = await fetch('get_cashup_data.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            start_date: startDate,
                            start_time: startTime,
                            end_date: endDate,
                            end_time: endTime,
                            cashier_id: cashierId
                        })
                    });
                    cashUpSystemData = await response.json();
                    document.getElementById('cashup_loading').classList.add('hidden');
                    if (!cashUpSystemData.success) {
                        showCashUpNotification('Error loading data: ' + (cashUpSystemData.error || 'Unknown error'), 'error');
                        return;
                    }
                    document.getElementById('cashup_tips').value = cashUpSystemData.tips_system?.toFixed(2) || '0.00';
                    document.getElementById('cashup_unpaid_credit').value = cashUpSystemData.unpaid_credit_sales?.toFixed(2) ?? '';
                    document.getElementById('cashup_credit_returns').value = cashUpSystemData.credit_returns?.toFixed(2) ?? '';
                    document.getElementById('cashup_expenses').value = cashUpSystemData.expenses?.toFixed(2) ?? '';
                    updateCashUpStep2Summary();
                    document.getElementById('step4_expected').textContent = '—';
                    document.getElementById('cashup_cash_on_hand').value = '';
                    document.getElementById('step4_over_short').textContent = '—';
                    document.getElementById('step4_over_short').className = 'text-2xl font-bold text-gray-500';
                    document.getElementById('step5_eft_expected').textContent = '—';
                    document.getElementById('step5_eft_over_short').textContent = '—';
                    document.getElementById('step5_eft_over_short').className = 'text-2xl font-bold text-gray-500';
                    const dateRangeText = startDate + ' ' + startTime + ' — ' + endDate + ' ' + endTime;
                    document.getElementById('cashup_date_range_text').textContent = dateRangeText;
                    document.getElementById('cashup_date_range_text_step3').textContent = dateRangeText;
                    document.getElementById('cashup_date_range_text_step4').textContent = dateRangeText;
                    document.getElementById('cashup_date_range_text_step5').textContent = dateRangeText;
                } catch (error) {
                    document.getElementById('cashup_loading').classList.add('hidden');
                    showCashUpNotification('Error loading data: ' + error.message, 'error');
                    return;
                }
            }
            if (cashUpCurrentStep === 4) {
                const cashOnHand = document.getElementById('cashup_cash_on_hand').value;
                if (!cashOnHand || isNaN(parseFloat(cashOnHand))) {
                    showCashUpNotification('Please enter a valid cash on hand amount', 'error');
                    return;
                }
            }
            if (cashUpCurrentStep === 5) {
                const eftOnHand = document.getElementById('cashup_eft_on_hand').value;
                if (!eftOnHand || isNaN(parseFloat(eftOnHand))) {
                    showCashUpNotification('Please enter a valid EFT on hand amount', 'error');
                    return;
                }
                updateCashUpReview();
            }
            if (cashUpCurrentStep < cashUpTotalSteps) {
                cashUpCurrentStep++;
                updateCashUpStepDisplay();
            }
        }
        
        function cashUpPrevStep() {
            if (cashUpCurrentStep > 1) {
                cashUpCurrentStep--;
                if (cashUpCurrentStep === 1) cashUpSystemData = null;
                updateCashUpStepDisplay();
            }
        }
        
        function updateCashUpReview() {
            if (!cashUpSystemData) return;
            const cashOnHand = parseFloat(document.getElementById('cashup_cash_on_hand').value) || 0;
            const eftOnHand = parseFloat(document.getElementById('cashup_eft_on_hand').value) || 0;
            const tips = parseFloat(document.getElementById('cashup_tips').value) || 0;
            const unpaidCredit = parseFloat(document.getElementById('cashup_unpaid_credit').value) || 0;
            const creditReturns = parseFloat(document.getElementById('cashup_credit_returns').value) || 0;
            const expenses = parseFloat(document.getElementById('cashup_expenses').value) || 0;
            const hiddenExpectedLabel = '—';
            const startDate = document.getElementById('cashup_start_date').value;
            const endDate = document.getElementById('cashup_end_date').value;
            document.getElementById('review_date').textContent = startDate + ' — ' + endDate;
            document.getElementById('review_cashier').textContent = document.getElementById('cashup_cashier').selectedOptions[0].text;
            document.getElementById('review_cash_expected').textContent = hiddenExpectedLabel;
            document.getElementById('review_cash_on_hand').textContent = 'N$ ' + cashOnHand.toFixed(2);
            document.getElementById('review_over_short').textContent = hiddenExpectedLabel;
            document.getElementById('review_over_short').className = 'font-semibold text-gray-500';
            document.getElementById('review_eft_expected').textContent = hiddenExpectedLabel;
            document.getElementById('review_eft_on_hand').textContent = 'N$ ' + eftOnHand.toFixed(2);
            document.getElementById('review_eft_over_short').textContent = hiddenExpectedLabel;
            document.getElementById('review_eft_over_short').className = 'font-semibold text-gray-500';
            document.getElementById('review_unpaid_credit').textContent = 'N$ ' + unpaidCredit.toFixed(2);
            document.getElementById('review_credit_returns').textContent = 'N$ ' + creditReturns.toFixed(2);
            document.getElementById('review_open_tabs').textContent = 'N$ ' + (cashUpSystemData.open_tabs_balance || 0).toFixed(2);
            document.getElementById('review_expenses').textContent = 'N$ ' + expenses.toFixed(2);
            document.getElementById('review_tips').textContent = 'N$ ' + tips.toFixed(2);
            document.getElementById('review_voids').textContent = 'N$ ' + (cashUpSystemData.voids || 0).toFixed(2);
            document.getElementById('review_refunds').textContent = 'N$ ' + (cashUpSystemData.refunds || 0).toFixed(2);
            document.getElementById('review_damages').textContent = 'N$ ' + (Number(cashUpSystemData.damages) || 0).toFixed(2);
            document.getElementById('review_total_sold').textContent = 'N$ ' + (cashUpSystemData.total_items_sold || 0).toFixed(2);
        }
        
        async function submitCashUp() {
            if (!cashUpSystemData) {
                showCashUpNotification('No data loaded. Please start over.', 'error');
                return;
            }
            const startDate = document.getElementById('cashup_start_date').value;
            const startTime = getCashUpStartTime();
            const endDate = document.getElementById('cashup_end_date').value;
            const endTime = getCashUpEndTime();
            const cashierId = document.getElementById('cashup_cashier').value;
            const cashierName = document.getElementById('cashup_cashier').selectedOptions[0].text;
            const cashOnHand = parseFloat(document.getElementById('cashup_cash_on_hand').value) || 0;
            const eftOnHand = parseFloat(document.getElementById('cashup_eft_on_hand').value) || 0;
            const tips = parseFloat(document.getElementById('cashup_tips').value) || 0;
            const unpaidCreditSales = parseFloat(document.getElementById('cashup_unpaid_credit').value) || 0;
            const creditReturns = parseFloat(document.getElementById('cashup_credit_returns').value) || 0;
            const expenses = parseFloat(document.getElementById('cashup_expenses').value) || 0;
            let cashSalesExpected = 0;
            let cardSalesExpected = 0;
            try {
                const expResp = await fetch('get_cashup_data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        start_date: startDate,
                        start_time: startTime,
                        end_date: endDate,
                        end_time: endTime,
                        cashier_id: cashierId,
                        include_expected_amounts: true
                    })
                });
                const expData = await expResp.json();
                if (!expData.success) {
                    showCashUpNotification('Could not load expected amounts: ' + (expData.error || 'Unknown error'), 'error');
                    return;
                }
                cashSalesExpected = parseFloat(expData.cash_sales_expected) || 0;
                cardSalesExpected = parseFloat(expData.card_sales_expected) || 0;
            } catch (e) {
                showCashUpNotification('Could not load expected amounts: ' + e.message, 'error');
                return;
            }
            const overShort = cashOnHand - cashSalesExpected;
            const eftOverShort = eftOnHand - cardSalesExpected;
            const receiptData = {
                is_cashup_master_report: true,
                print_only: true,
                start_date: startDate,
                start_time: startTime,
                end_date: endDate,
                end_time: endTime,
                date: endDate,
                cashier_username: '<?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>',
                filter_cashier_id: cashierId,
                filter_cashier_name: cashierName,
                is_individual_cashout: cashierId !== 'all',
                cash_sales_expected: cashSalesExpected,
                cash_on_hand: cashOnHand,
                over_short: overShort,
                card_sales_expected: cardSalesExpected,
                eft_on_hand: eftOnHand,
                eft_over_short: eftOverShort,
                unpaid_credit_sales: unpaidCreditSales,
                open_tabs_balance: cashUpSystemData.open_tabs_balance || 0,
                unpaid_tabs: cashUpSystemData.unpaid_tabs || 0,
                credit_returns: creditReturns,
                expenses: expenses,
                tips: tips,
                voids: cashUpSystemData.voids || 0,
                refunds: cashUpSystemData.refunds || 0,
                damages: Number(cashUpSystemData.damages) || 0,
                total_items_sold: cashUpSystemData.total_items_sold || 0
            };
            const submitBtn = document.getElementById('cashup_submit_btn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Printing...';
            try {
                const printFn = (typeof window.sendToPrinter === 'function')
                    ? window.sendToPrinter
                    : (data) => fetch('../receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => r.json());
                const result = await printFn(receiptData);
                if (result && result.success) {
                    const reportSnapshot = {
                        start_date: startDate,
                        start_time: startTime,
                        end_date: endDate,
                        end_time: endTime,
                        range_label: startDate + ' ' + startTime + ' — ' + endDate + ' ' + endTime,
                        filter_cashier_name: cashierName,
                        cashier_username: '<?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>',
                        is_individual_cashout: cashierId !== 'all',
                        cash_sales_expected: cashSalesExpected,
                        cash_on_hand: cashOnHand,
                        over_short: overShort,
                        card_sales_expected: cardSalesExpected,
                        eft_on_hand: eftOnHand,
                        eft_over_short: eftOverShort,
                        unpaid_credit_sales: unpaidCreditSales,
                        open_tabs_balance: cashUpSystemData.open_tabs_balance || 0,
                        unpaid_tabs: cashUpSystemData.unpaid_tabs || 0,
                        credit_returns: creditReturns,
                        expenses: expenses,
                        tips: tips,
                        voids: cashUpSystemData.voids || 0,
                        refunds: cashUpSystemData.refunds || 0,
                        damages: Number(cashUpSystemData.damages) || 0,
                        total_items_sold: cashUpSystemData.total_items_sold || 0,
                        total_cash_in: Number(cashUpSystemData.total_cash_in) || 0,
                        total_cash_out: Number(cashUpSystemData.total_cash_out) || 0,
                        total_credit_payments: Number(cashUpSystemData.total_credit_payments) || 0,
                        total_cash_received: Number(cashUpSystemData.total_cash_received) || 0,
                        total_cash_sales: Number(cashUpSystemData.total_cash_sales) || 0,
                        cash_back_system: Number(cashUpSystemData.cash_back_system) || 0,
                        tips_system: Number(cashUpSystemData.tips_system) || 0
                    };
                    const saveData = {
                        start_date: startDate,
                        start_time: startTime,
                        end_date: endDate,
                        end_time: endTime,
                        date: endDate,
                        cashier_id: cashierId,
                        cashier_name: cashierName,
                        cash_sales_expected: cashSalesExpected,
                        cash_on_hand: cashOnHand,
                        over_short: overShort,
                        card_sales_expected: cardSalesExpected,
                        eft_on_hand: eftOnHand,
                        eft_over_short: eftOverShort,
                        unpaid_credit_sales: unpaidCreditSales,
                        open_tabs_balance: cashUpSystemData.open_tabs_balance || 0,
                        unpaid_tabs: cashUpSystemData.unpaid_tabs || 0,
                        credit_returns: creditReturns,
                        expenses: expenses,
                        cash_back: 0,
                        tips: tips,
                        hubbly: 0,
                        beerhouse: 0,
                        voids: cashUpSystemData.voids || 0,
                        refunds: cashUpSystemData.refunds || 0,
                        total_items_sold: cashUpSystemData.total_items_sold || 0
                    };
                    try {
                        const saveResponse = await fetch('save_cashup.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(saveData)
                        });
                        const saveResult = await saveResponse.json();
                        if (saveResult && saveResult.success) {
                            showCashUpNotification('Cash-up printed and saved successfully!', 'success');
                        } else {
                            showCashUpNotification('Printed but failed to save: ' + (saveResult.error || 'Unknown error'), 'error');
                        }
                    } catch (saveError) {
                        showCashUpNotification('Printed but failed to save to database', 'error');
                    }
                    setTimeout(() => {
                        closeCashUpModal();
                        openCashUpReceiptReport(reportSnapshot);
                    }, 1500);
                } else {
                    showCashUpNotification('Print failed: ' + (result?.message || result?.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                showCashUpNotification('Print error: ' + error.message, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        }
        
        function showCashUpNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = 'fixed top-4 right-4 px-6 py-4 rounded-xl shadow-2xl z-[10001] transform transition-all duration-300 ' + (type === 'success' ? 'bg-teal-500 text-white' : 'bg-red-500 text-white');
            notification.innerHTML = '<div class="flex items-center gap-3"><i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + ' text-xl"></i><span class="font-medium">' + message + '</span></div>';
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('opacity-0', 'translate-y-[-10px]');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const cashOnHandInput = document.getElementById('cashup_cash_on_hand');
            if (cashOnHandInput) {
                cashOnHandInput.addEventListener('input', function() {
                    if (cashUpSystemData) {
                        const display = document.getElementById('step4_over_short');
                        if (display) {
                            display.textContent = '—';
                            display.className = 'text-2xl font-bold text-gray-500';
                        }
                    }
                });
            }
            const eftOnHandInput = document.getElementById('cashup_eft_on_hand');
            if (eftOnHandInput) {
                eftOnHandInput.addEventListener('input', function() {
                    if (cashUpSystemData) {
                        const display = document.getElementById('step5_eft_over_short');
                        if (display) {
                            display.textContent = '—';
                            display.className = 'text-2xl font-bold text-gray-500';
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
