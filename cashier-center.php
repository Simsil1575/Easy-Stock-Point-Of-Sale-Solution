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
require_once 'activation_helper.php';
$activationCheck = checkActivationStatus();
if ($activationCheck['status'] === 'not_activated' || $activationCheck['status'] === 'expired') {
    header('Location: settings');
    exit();
}

// Database connections
try {
    $db = new PDO('sqlite:pos.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $userDb = new PDO('sqlite:user.db');
    $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $infoDb = new PDO('sqlite:info.db');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Operations - POS System</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="sweetalert2@11.js"></script>
    <!-- Unified printing & QZ routing -->
    <script src="receipt.php?js=true"></script>
    
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
                <h1 class="text-lg font-semibold text-gray-800">Cashier Operations</h1>
                <div class="w-8"></div>
            </div>
            
            <main class="p-4 lg:p-6">
                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2">Cashier Operations Center</h1>
                    <p class="text-gray-600">Quick access to all cashier functions and operations</p>
                </div>
                
                <!-- All Operations in One Container -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        
                        <!-- Tabs -->
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="window.location.href='credit-tabs'">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-indigo-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Tabs</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Tabs</h3>
                            <p class="text-sm text-gray-500">Open and manage customer tabs</p>
                        </div>
                        
                        <!-- Credit Book -->
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="window.location.href='credit-book'">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="window.location.href='cash'">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openOperationModal('expense', 'Add Expense', 'Record business expense (cash out)')">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="handleCashBack()">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openCashUpModal()">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="window.location.href='damaged_goods.php'">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Inventory</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Add Damages</h3>
                            <p class="text-sm text-gray-500">Record damaged or spoiled goods</p>
                        </div>
                        
                        <!-- Create Creditor Account -->
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="window.location.href='credit-book.php'">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openOperationModal('addCredit', 'Add Credit', 'Add credit sale (system balance)')">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openOperationModal('credit_return', 'Record Credit Payment', 'Record payment against creditor balance')">
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
                        <div class="operation-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openOperationModal('tips', 'Add Tips', 'Record tips received')">
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
    
    <!-- Cash Up Modal (Simple Z-Report) -->
    <div id="cashUpModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800">Cash Up - Generate Z-Report</h3>
                    <button type="button" onclick="closeCashUpModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-500 mb-6">Select the date and time range for the Z-Report.</p>
                
                <form id="cashUpForm" onsubmit="processCashUp(event)">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input 
                                type="date" 
                                id="cashUpStartDate" 
                                name="start_date" 
                                required
                                max="<?= date('Y-m-d') ?>"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-base"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Start Time</label>
                            <input 
                                type="time" 
                                id="cashUpStartTime" 
                                name="start_time" 
                                value="00:00"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-base"
                            >
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input 
                                type="date" 
                                id="cashUpEndDate" 
                                name="end_date" 
                                required
                                max="<?= date('Y-m-d') ?>"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-base"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">End Time</label>
                            <input 
                                type="time" 
                                id="cashUpEndTime" 
                                name="end_time" 
                                value="23:59"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-base"
                            >
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-6">
                        <i class="fas fa-info-circle"></i> Report includes sales, cash in till, expenses, cash back, tips, voids, refunds. For a full business day use Start 00:00 and End 23:59.
                    </p>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-receipt text-blue-600 text-lg mt-1"></i>
                            <div>
                                <h4 class="font-semibold text-blue-900 mb-1">Z-Report Will Include:</h4>
                                <ul class="text-sm text-blue-800 space-y-1">
                                    <li>• Total Sales (Cash & Card)</li>
                                    <li>• Cash in Till</li>
                                    <li>• Credit Sales & Returns</li>
                                    <li>• Expenses & Cash Back</li>
                                    <li>• Voids & Refunds</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex gap-3">
                        <button 
                            type="button" 
                            onclick="closeCashUpModal()" 
                            class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            class="flex-1 px-4 py-3 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700 transition-colors flex items-center justify-center gap-2"
                        >
                            <i class="fas fa-print"></i>
                            Generate & Print Z-Report
                        </button>
                    </div>
                </form>
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
            if (typeof sendToPrinter === 'function') {
                return sendToPrinter({ open_drawer_only: true, cashier_username: '<?php echo $_SESSION['username'] ?? ''; ?>' })
                    .catch(() => ({ success: false }));
            }
            return fetch('open_drawer.php', {
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
                    fetch('process_cashback.php', {
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
            fetch('process_credit_sale_simple.php', {
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
            fetch('process_credit_payment.php', {
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
            
            fetch('process_tips.php', {
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
            fetch('process_expense.php', {
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
        // CASH UP MODAL (Simple Z-Report)
        // ==========================================
        
        function openCashUpModal() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('cashUpStartDate').value = today;
            document.getElementById('cashUpStartTime').value = '00:00';
            document.getElementById('cashUpEndDate').value = today;
            document.getElementById('cashUpEndTime').value = '23:59';
            document.getElementById('cashUpModal').classList.add('active');
        }
        
        function closeCashUpModal() {
            document.getElementById('cashUpModal').classList.remove('active');
        }
        
        async function processCashUp(event) {
            event.preventDefault();
            
            const startDate = document.getElementById('cashUpStartDate').value;
            const startTime = document.getElementById('cashUpStartTime').value || '00:00';
            const endDate = document.getElementById('cashUpEndDate').value;
            const endTime = document.getElementById('cashUpEndTime').value || '23:59';
            
            if (!startDate || !endDate) {
                Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select start and end date.' });
                return;
            }
            
            const startDt = new Date(startDate + 'T' + startTime);
            const endDt = new Date(endDate + 'T' + endTime);
            if (endDt <= startDt) {
                Swal.fire({ icon: 'warning', title: 'Invalid range', text: 'End date & time must be after start date & time.' });
                return;
            }
            
            // Show loading
            Swal.fire({
                title: 'Generating Z-Report...',
                text: 'Please wait while we prepare your cash up report',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            try {
                // Fetch cash up data
                const response = await fetch('get_cashup_data.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        start_date: startDate,
                        start_time: startTime.length === 5 ? startTime : startTime.substring(0, 5),
                        end_date: endDate,
                        end_time: endTime.length === 5 ? endTime : endTime.substring(0, 5),
                        cashier_id: 'all'
                    })
                });
                
                const cashUpData = await response.json();
                
                if (!cashUpData.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: cashUpData.error || 'Failed to load cash up data'
                    });
                    return;
                }
                
                // Prepare cash-up report for printing (use receipt.php formatting)
                const zReportData = {
                    is_cashup_report: true,
                    print_only: true,
                    date: endDate,
                    date_range: startDate + ' ' + (startTime.length === 5 ? startTime : startTime.substring(0, 5)) + ' — ' + endDate + ' ' + (endTime.length === 5 ? endTime : endTime.substring(0, 5)),
                    cashier_username: '<?php echo htmlspecialchars($_SESSION['username'] ?? 'Cashier'); ?>',
                    cashier_name: '<?php echo htmlspecialchars($_SESSION['username'] ?? 'Cashier'); ?>',
                    
                    // Sales Summary (use total_cash_sales / card_sales_expected for revenue; fallback to cash_sales_expected)
                    cash_sales: cashUpData.total_cash_sales ?? cashUpData.cash_sales_expected ?? 0,
                    eft_sales: cashUpData.card_sales_expected || 0,
                    total_income: (cashUpData.total_cash_sales ?? cashUpData.cash_sales_expected ?? 0) + (cashUpData.card_sales_expected || 0),
                    
                    // Cash In Till
                    expected_cash: cashUpData.cash_in_till || 0,
                    
                    // Credit & Tabs
                    credit_unpaid: cashUpData.unpaid_credit_sales || 0,
                    credit_returns: cashUpData.credit_returns || 0,
                    open_tabs: cashUpData.open_tabs_balance || 0,
                    
                    // Cash Transactions
                    cash_in: cashUpData.cash_in || 0,
                    cash_out: cashUpData.cash_out || 0,
                    total_expense: cashUpData.expenses || 0,
                    
                    // Cash Back & Tips
                    cash_back_system: cashUpData.cash_back_system || 0,
                    cash_back_beerhouse: cashUpData.cash_back_beerhouse || 0,
                    cash_back_hubbly: cashUpData.cash_back_hubbly || 0,
                    cash_back_customer: cashUpData.cash_back_customer || 0,
                    tips_system: cashUpData.tips_system || 0,
                    
                    // Hansa Payments
                    hansa_cash: cashUpData.hansa_cash || 0,
                    hansa_eft: cashUpData.hansa_eft || 0,
                    
                    // Other
                    voids: cashUpData.voids || 0,
                    voids_count: cashUpData.voids_count || 0,
                    refunds: cashUpData.refunds || 0,
                    refunds_count: cashUpData.refunds_count || 0,
                    damages: cashUpData.damages || 0,
                    
                    // Timestamp
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
                
                // Print via unified sendToPrinter (routes to QZ when enabled)
                if (typeof sendToPrinter === 'function') {
                    const r = await sendToPrinter(zReportData);
                    if (!r || !r.success) throw new Error(r?.message || 'Printing failed');
                } else {
                    // Fallback to legacy endpoint
                    const printResponse = await fetch('print_receipt.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(zReportData)
                    });
                    const printResult = await printResponse.json();
                    if (!printResult.success) throw new Error(printResult.error || 'Printing failed');
                }

                // Success UI
                {
                    Swal.fire({
                        icon: 'success',
                        title: 'Z-Report Generated!',
                        html: `
                            <div class="text-left">
                                <p class="mb-2"><strong>Period:</strong> ${startDate} ${startTime} — ${endDate} ${endTime}</p>
                                <p class="mb-2"><strong>Total Sales:</strong> N$ ${zReportData.total_income.toFixed(2)}</p>
                                <p class="mb-2"><strong>Cash:</strong> N$ ${zReportData.cash_sales.toFixed(2)}</p>
                                <p class="mb-2"><strong>Card:</strong> N$ ${zReportData.eft_sales.toFixed(2)}</p>
                                <p class="mb-2"><strong>Cash in Till:</strong> N$ ${zReportData.expected_cash.toFixed(2)}</p>
                            </div>
                            <p class="mt-4 text-sm text-gray-600">Z-Report has been printed</p>
                        `,
                        confirmButtonText: 'OK',
                        timer: 5000
                    });
                    
                    closeCashUpModal();
                }
                
            } catch (error) {
                console.error('Cash Up Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing cash up: ' + error.message
                });
            }
        }
    </script>
</body>
</html>
