<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
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

// Get all cashiers for staff reports
$cashiers = [];
try {
    $cashiersQuery = $userDb->query("SELECT id, username, role FROM users ORDER BY username");
    $cashiers = $cashiersQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Empty array
}

// Get all creditors for credit reports
$creditors = [];
try {
    $creditorsQuery = $db->query("SELECT id, name FROM creditors WHERE active = 1 ORDER BY name");
    $creditors = $creditorsQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Empty array
}

// Get product categories
$categories = [];
try {
    $categoriesQuery = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $categoriesQuery->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Empty array
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Center - POS System</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <script src="src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="sweetalert2@11.js"></script>
    <script src="lucide.js"></script>
    
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
        
        /* Report card styles */
        .report-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            background: white !important;
        }
        
        .report-card:active {
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
        
        /* Period selector styles */
        .period-selector {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .period-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
        }
        
        .period-btn:hover {
            background: #f3f4f6;
        }
        
        .period-btn.active {
            background: #14b8a6;
            color: white;
            border-color: #14b8a6;
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
                <h1 class="text-lg font-semibold text-gray-800">Reports Center</h1>
                <div class="w-8"></div>
            </div>
            
            <main class="p-4 lg:p-6">
                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2">Reports Center</h1>
                    <p class="text-gray-600">Generate reports for your shift and daily operations</p>
                </div>
                
                <!-- Cashier Reports Only -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        
                        <!-- Sales Reports -->
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('sales', 'Sales Report', 'Complete sales overview with totals and breakdowns')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-receipt text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Sales</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Sales Report</h3>
                            <p class="text-sm text-gray-500">Complete sales overview with totals and breakdowns</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('daily_sales', 'Daily Sales Report', 'Detailed sales for a specific day')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-day text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Sales</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Daily Sales Report</h3>
                            <p class="text-sm text-gray-500">Detailed sales for a specific day</p>
                        </div>
                        
                        <!-- Item Sales Report -->
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('item_sales', 'Item Sales Report', 'Individual product sales performance')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-chart-pie text-blue-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">Products</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Item Sales Report</h3>
                            <p class="text-sm text-gray-500">Individual product sales performance</p>
                        </div>
                        
                        <!-- Payments Reports -->
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('cash_sales', 'Cash Sales Report', 'All cash transactions')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cash Sales Report</h3>
                            <p class="text-sm text-gray-500">All cash transactions</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('card_sales', 'Card Sales Report', 'All EFT/card transactions')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-credit-card text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Card Sales Report</h3>
                            <p class="text-sm text-gray-500">All EFT/card transactions</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('payment_summary', 'Payment Summary Report', 'Overview of all payment methods')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-chart-bar text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Payment Summary Report</h3>
                            <p class="text-sm text-gray-500">Overview of all payment methods</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('cashup', 'Cash-Up Report', 'Daily cash reconciliation')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-cash-register text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cash-Up Report</h3>
                            <p class="text-sm text-gray-500">Daily cash reconciliation</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="handleCashBack()">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hand-holding-usd text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Process Cash Back</h3>
                            <p class="text-sm text-gray-500">Record EFT + cash back (same as home)</p>
                        </div>
                        
                        <!-- Credit & Tabs Reports -->
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('credit_sales', 'Credit Sales Report', 'All credit transactions')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hand-holding-usd text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Credit Sales Report</h3>
                            <p class="text-sm text-gray-500">All credit transactions</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('outstanding_credit', 'Outstanding Credit Report', 'Unpaid credit balances')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Outstanding Credit Report</h3>
                            <p class="text-sm text-gray-500">Unpaid credit balances</p>
                        </div>
                        
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('tabs', 'Tabs Report', 'Open and closed tabs summary')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Tabs</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Tabs Report</h3>
                            <p class="text-sm text-gray-500">Open and closed tabs summary</p>
                        </div>
                        
                        <!-- Expenses Reports -->
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('expenses', 'Expenses Report', 'All business expenses')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-file-invoice text-red-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">Expenses</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Expenses Report</h3>
                            <p class="text-sm text-gray-500">All business expenses</p>
                        </div>
                        
                        <!-- Refunds & Voids Reports -->
                        <div class="report-card bg-gray-50 rounded-xl p-5 border border-gray-200" onclick="openReportModal('refunds', 'Refunds Report', 'All refund transactions')">
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-undo-alt text-orange-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Refunds</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Refunds Report</h3>
                            <p class="text-sm text-gray-500">All refund transactions</p>
                        </div>
                        
                    
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Report Modal -->
    <div id="reportModal" class="modal-overlay">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 id="modalTitle" class="text-xl font-bold text-gray-800">Generate Report</h3>
                    <button onclick="closeReportModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p id="modalDescription" class="text-gray-500 mb-6">Select the date range for your report</p>
                
                <form id="reportForm" onsubmit="generateReport(event)">
                    <input type="hidden" id="reportType" name="report_type">
                    
                    <!-- Period Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quick Select</label>
                        <div class="period-selector">
                            <button type="button" class="period-btn" onclick="setQuickPeriod('today')">Today</button>
                            <button type="button" class="period-btn" onclick="setQuickPeriod('yesterday')">Yesterday</button>
                            <button type="button" class="period-btn" onclick="setQuickPeriod('week')">This Week</button>
                            <button type="button" class="period-btn" onclick="setQuickPeriod('month')">This Month</button>
                            <button type="button" class="period-btn" onclick="setQuickPeriod('year')">This Year</button>
                        </div>
                    </div>
                    
                    <!-- Date Range -->
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                            <input type="date" id="startDate" name="start_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input type="date" id="endDate" name="end_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500" required>
                        </div>
                    </div>
                    
                    <!-- Additional Filters (shown based on report type) -->
                    <div id="cashierFilter" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cashier (Optional)</label>
                        <select id="cashierId" name="cashier_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="">All Cashiers</option>
                            <?php foreach ($cashiers as $cashier): ?>
                                <option value="<?= htmlspecialchars($cashier['username']) ?>"><?= htmlspecialchars($cashier['username']) ?> (<?= ucfirst($cashier['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="creditorFilter" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer (Optional)</label>
                        <select id="creditorId" name="creditor_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="">All Customers</option>
                            <?php foreach ($creditors as $creditor): ?>
                                <option value="<?= $creditor['id'] ?>"><?= htmlspecialchars($creditor['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="categoryFilter" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category (Optional)</label>
                        <select id="categoryId" name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Generate Button -->
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeReportModal()" class="flex-1 px-4 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" id="generateBtn" class="flex-1 px-4 py-3 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700 transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-file-pdf"></i>
                            Generate PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Update current time
        setInterval(() => {
            const now = new Date();
            document.getElementById('currentTime').textContent = 
                now.getHours().toString().padStart(2, '0') + ':' + 
                now.getMinutes().toString().padStart(2, '0');
        }, 60000);
        
        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        
        // Modal functions
        function openReportModal(type, title, description) {
            document.getElementById('reportType').value = type;
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalDescription').textContent = description;
            
            // Set default dates to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('startDate').value = today;
            document.getElementById('endDate').value = today;
            
            // Show/hide filters based on report type
            document.getElementById('cashierFilter').classList.add('hidden');
            document.getElementById('creditorFilter').classList.add('hidden');
            document.getElementById('categoryFilter').classList.add('hidden');
            
            if (['cashier_sales', 'shift', 'cashup'].includes(type)) {
                document.getElementById('cashierFilter').classList.remove('hidden');
            }
            
            if (['credit_sales', 'outstanding_credit', 'tabs'].includes(type)) {
                document.getElementById('creditorFilter').classList.remove('hidden');
            }
            
            if (['item_sales', 'plu', 'current_stock', 'low_stock'].includes(type)) {
                document.getElementById('categoryFilter').classList.remove('hidden');
            }
            
            // Reset period buttons
            document.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
            
            // Show modal
            document.getElementById('reportModal').classList.add('active');
        }
        
        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('active');
        }
        
        // Quick period selection
        function setQuickPeriod(period) {
            const today = new Date();
            let startDate, endDate;
            
            switch(period) {
                case 'today':
                    startDate = endDate = today;
                    break;
                case 'yesterday':
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    startDate = endDate = yesterday;
                    break;
                case 'week':
                    const weekStart = new Date(today);
                    weekStart.setDate(today.getDate() - today.getDay());
                    startDate = weekStart;
                    endDate = today;
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = today;
                    break;
                case 'year':
                    startDate = new Date(today.getFullYear(), 0, 1);
                    endDate = today;
                    break;
            }
            
            document.getElementById('startDate').value = formatDate(startDate);
            document.getElementById('endDate').value = formatDate(endDate);
            
            // Update active button
            document.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }
        
        function formatDate(date) {
            return date.toISOString().split('T')[0];
        }
        
        // Generate report
        function generateReport(event) {
            event.preventDefault();
            
            const btn = document.getElementById('generateBtn');
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<div class="spinner"></div> Generating...';
            btn.disabled = true;
            
            const formData = new FormData(document.getElementById('reportForm'));
            const params = new URLSearchParams();
            
            for (let [key, value] of formData.entries()) {
                if (value) params.append(key, value);
            }
            
            // Open PDF in new window
            const url = 'generate_report_pdf.php?' + params.toString();
            window.open(url, '_blank');
            
            // Reset button
            setTimeout(() => {
                btn.innerHTML = originalContent;
                btn.disabled = false;
                closeReportModal();
            }, 1000);
        }
        
        // Close modal on outside click
        document.getElementById('reportModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReportModal();
            }
        });
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeReportModal();
            }
        });
        
        // Process Cash Back – same as home.php: EFT + cash-out so cash-up and reports show both
        function handleCashBack() {
            const today = new Date().toISOString().split('T')[0];
            Swal.fire({
                title: '<h1 class="text-2xl font-bold text-gray-700 mb-4">Cash Back</h1>',
                html: `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Amount:</label>
                            <input type="number" id="cashBackAmount" min="0" step="0.01"
                                class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl focus:border-gray-500 focus:ring-2 focus:ring-gray-200 text-base font-medium shadow-sm bg-gray-50 hover:bg-gray-100"
                                placeholder="0.00">
                            <p class="text-xs text-gray-500 mt-1">Enter the amount for EFT payment and cash withdrawal</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Date:</label>
                            <input type="date" id="cashBackDate" value="${today}" max="${today}"
                                class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl focus:border-gray-500 focus:ring-2 focus:ring-gray-200 text-base font-medium shadow-sm bg-gray-50 hover:bg-gray-100" required>
                            <p class="text-xs text-gray-500 mt-1">Select the date when the cash back occurred</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Provider (Optional):</label>
                            <select id="cashBackProvider" class="w-full px-4 py-2 border-2 border-gray-100 rounded-xl focus:border-gray-500 focus:ring-2 focus:ring-gray-200 text-base font-medium shadow-sm bg-gray-50 hover:bg-gray-100">
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
                    const walletProvider = document.getElementById('cashBackProvider').value || 'Customer';
                    return { amount, transactionDate, transactionRef: '', walletProvider };
                }
            }).then((result) => {
                if (!result.isConfirmed) return;
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
                        Swal.fire({
                            icon: 'success',
                            title: 'Cash Back Processed',
                            text: 'Cash back and EFT recorded successfully',
                            timer: 1500,
                            showConfirmButton: false
                        });
                        setTimeout(() => location.reload(), 1200);
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
                    const errorMessage = (error.responseJSON && error.responseJSON.error) ? error.responseJSON.error : 'Error processing cash back';
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMessage,
                        timer: 3000,
                        showConfirmButton: false
                    });
                });
            });
        }
    </script>
</body>
</html>
