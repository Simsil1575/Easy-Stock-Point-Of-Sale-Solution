<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
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

// Get invoicing customers for statement reports
$invCustomers = [];
try {
    $invCustomers = $db->query("SELECT id, name FROM customers WHERE COALESCE(active, 1) = 1 ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Empty — customers table may not exist yet
}

require_once __DIR__ . '/../ensure_purchase_order_schema.php';
require_once __DIR__ . '/../purchase_order_lib.php';
ensurePurchaseOrderSchema($db);
$suppliers = poListActiveSuppliers($db);

require_once __DIR__ . '/../ui_cards_helper.php';
ensureUiCardsSchema($infoDb);
$uiCardScope = 'admin_reports';
$hiddenUiCards = uiGetHiddenCards($infoDb, $uiCardScope);
$orderedUiCards = uiGetCardOrder($infoDb, $uiCardScope);
$showHiddenUiCards = isset($_GET['show_hidden']);
$uiCardsCustomizeMode = isset($_GET['customize']) || $showHiddenUiCards;
$uiCardsApiUrl = '../ui_cards_api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Center - POS System</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <script src="../lucide.js"></script>
    
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
                <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 mb-2">Reports Center</h1>
                        <p class="text-gray-600">Generate and download comprehensive reports for your business</p>
                    </div>
                    <?php $reportsSearchInclude = 'field'; include __DIR__ . '/../includes/reports_center_search.php'; ?>
                </div>
                
              
                <!-- All Reports in One Container -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6<?= $uiCardsCustomizeMode ? ' ui-cards-customize-mode' : '' ?>">
                    <?php include __DIR__ . '/../includes/ui_cards_toolbar.php'; ?>
                    <div id="reportsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">

                        <?php
                        $invReportCards = [
                            ['quotations', 'Quotation Report', 'All quotations with totals', 'fa-file-lines', 'teal'],
                            ['invoices', 'Invoices Report', 'All invoices with paid and balances', 'fa-file-invoice-dollar', 'emerald'],
                            ['outstanding', 'Outstanding Invoices', 'Unpaid invoice balances', 'fa-hourglass-half', 'amber'],
                            ['overdue', 'Overdue Invoices', 'Invoices past their due date', 'fa-triangle-exclamation', 'rose'],
                            ['payments', 'Payments Report', 'Payments received in a period', 'fa-money-bill-wave', 'green'],
                            ['customer_statement', 'Customer Statement', 'Statement of account per customer', 'fa-address-card', 'cyan'],
                            ['sales_by_customer', 'Sales by Customer', 'Billing totals grouped by customer', 'fa-users', 'orange'],
                            ['monthly_summary', 'Monthly Invoice Summary', 'Invoice totals grouped by month', 'fa-calendar', 'lime'],
                            ['conversion', 'Quotation Conversion', 'Quotation to invoice conversion', 'fa-arrows-turn-right', 'fuchsia'],
                        ];
                        foreach ($invReportCards as $rc):
                            [$key, $title, $desc, $icon, $color] = $rc;
                            $needsCustomer = $key === 'customer_statement' ? 'true' : 'false';
                        ?>
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="inv_<?= $key ?>" onclick="openInvReportModal('<?= $key ?>', '<?= htmlspecialchars($title, ENT_QUOTES) ?>', '<?= htmlspecialchars($desc, ENT_QUOTES) ?>', <?= $needsCustomer ?>)">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-<?= $color ?>-100 rounded-lg flex items-center justify-center">
                                    <i class="fas <?= $icon ?> text-<?= $color ?>-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-<?= $color ?>-100 text-<?= $color ?>-700 px-2 py-1 rounded-full">Invoicing</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1"><?= htmlspecialchars($title) ?></h3>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($desc) ?></p>
                        </div>
                        <?php endforeach; ?>

                        <!-- Sales Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="sales" onclick="openReportModal('sales', 'Sales Report', 'Complete sales overview with totals and breakdowns')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-receipt text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Sales</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Sales Report</h3>
                            <p class="text-sm text-gray-500">Complete sales overview with totals and breakdowns</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="daily_sales" onclick="openReportModal('daily_sales', 'Daily Sales Report', 'Detailed sales for a specific day')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-day text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Sales</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Daily Sales Report</h3>
                            <p class="text-sm text-gray-500">Detailed sales for a specific day</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="monthly_sales" onclick="openReportModal('monthly_sales', 'Monthly Sales Report', 'Sales summary for the entire month')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Sales</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Monthly Sales Report</h3>
                            <p class="text-sm text-gray-500">Sales summary for the entire month</p>
                        </div>
                        
                        <!-- Products Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="plu" onclick="openReportModal('plu', 'PLU Report', 'Product lookup codes and pricing')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-barcode text-blue-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full">Products</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">PLU Report</h3>
                            <p class="text-sm text-gray-500">Product lookup codes and pricing</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="item_sales" onclick="openReportModal('item_sales', 'Item Sales Report', 'Individual product sales performance')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
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
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="cash_sales" onclick="openReportModal('cash_sales', 'Cash Sales Report', 'All cash transactions')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cash Sales Report</h3>
                            <p class="text-sm text-gray-500">All cash transactions</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="card_sales" onclick="openReportModal('card_sales', 'Card Sales Report', 'All EFT/card transactions')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-credit-card text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Card Sales Report</h3>
                            <p class="text-sm text-gray-500">All EFT/card transactions</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="payment_summary" onclick="openReportModal('payment_summary', 'Payment Summary Report', 'Overview of all payment methods')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-chart-bar text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Payment Summary Report</h3>
                            <p class="text-sm text-gray-500">Overview of all payment methods</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="gratuity" onclick="openReportModal('gratuity', 'Gratuity Report', 'Gratuity totals and orders by cashier')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hand-holding-heart text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">POS</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Gratuity Report</h3>
                            <p class="text-sm text-gray-500">Totals and breakdown by cashier</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="tips" onclick="openReportModal('tips', 'Tips Report', 'Recorded tips and checkout gratuity by cashier')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-coins text-amber-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded-full">POS</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Tips Report</h3>
                            <p class="text-sm text-gray-500">Manual tips, checkout and tab gratuity</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="vat" onclick="openReportModal('vat', 'VAT Report', 'VAT position from sales totals and your business VAT settings')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-emerald-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-percent text-emerald-700 text-xl"></i>
                                </div>
                                <span class="text-xs bg-emerald-100 text-emerald-800 px-2 py-1 rounded-full">Tax</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">VAT Report</h3>
                            <p class="text-sm text-gray-500">Turnover, calculated VAT, and ex-VAT / gross by your VAT mode</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="cashup" onclick="openReportModal('cashup', 'Cash-Up Report', 'Daily cash reconciliation')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-cash-register text-green-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Payments</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cash-Up Report</h3>
                            <p class="text-sm text-gray-500">Daily cash reconciliation</p>
                        </div>
                        
                        <!-- Credit & Tabs Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="credit_sales" onclick="openReportModal('credit_sales', 'Credit Sales Report', 'All credit transactions')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hand-holding-usd text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Credit Sales Report</h3>
                            <p class="text-sm text-gray-500">All credit transactions</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="outstanding_credit" onclick="openReportModal('outstanding_credit', 'Outstanding Credit Report', 'Unpaid credit balances')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Credit</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Outstanding Credit Report</h3>
                            <p class="text-sm text-gray-500">Unpaid credit balances</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="tabs" onclick="openReportModal('tabs', 'Tabs Report', 'Open and closed tabs summary')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
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
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="expenses" onclick="openReportModal('expenses', 'Expenses Report', 'All business expenses')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-file-invoice text-red-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded-full">Expenses</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Expenses Report</h3>
                            <p class="text-sm text-gray-500">All business expenses</p>
                        </div>
                        
                        <!-- Stock Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="current_stock" onclick="openReportModal('current_stock', 'Current Stock Report', 'Current inventory levels')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-boxes text-indigo-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Stock</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Current Stock Report</h3>
                            <p class="text-sm text-gray-500">Current inventory levels</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="stock_movement" onclick="openReportModal('stock_movement', 'Stock Movement Report', 'Stock in and out movements')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exchange-alt text-indigo-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Stock</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Stock Movement Report</h3>
                            <p class="text-sm text-gray-500">Stock in and out movements</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="low_stock" onclick="openReportModal('low_stock', 'Low Stock Report', 'Items below restock level')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exclamation-circle text-indigo-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Stock</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Low Stock Report</h3>
                            <p class="text-sm text-gray-500">Items below restock level</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="stock_variance" onclick="openReportModal('stock_variance', 'Stock Variance Report', 'Discrepancies between expected and actual stock')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-not-equal text-indigo-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full">Stock</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Stock Variance Report</h3>
                            <p class="text-sm text-gray-500">Expected vs actual stock</p>
                        </div>

                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="supplier_receiving" onclick="openReportModal('supplier_receiving', 'Receiving by Supplier', 'Stock received grouped by supplier')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-truck-loading text-teal-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-teal-100 text-teal-700 px-2 py-1 rounded-full">Supplier</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Receiving by Supplier</h3>
                            <p class="text-sm text-gray-500">Receiving history grouped by supplier</p>
                        </div>
                        
                        <!-- Refunds & Voids Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="refunds" onclick="openReportModal('refunds', 'Refunds Report', 'All refund transactions')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-undo-alt text-orange-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Refunds</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Refunds Report</h3>
                            <p class="text-sm text-gray-500">All refund transactions</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="voids" onclick="openReportModal('voids', 'Voids Report', 'Voided and administrator-deleted sales (line items listed below each entry)')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-ban text-orange-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Voids</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Voids Report</h3>
                            <p class="text-sm text-gray-500">Voids and deleted transactions with product detail</p>
                        </div>
                        
                        <!-- Staff Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="cashier_sales" onclick="openReportModal('cashier_sales', 'Cashier Sales Report', 'Sales by individual cashier')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-user-tie text-pink-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-pink-100 text-pink-700 px-2 py-1 rounded-full">Staff</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Cashier Sales Report</h3>
                            <p class="text-sm text-gray-500">Sales by individual cashier</p>
                        </div>
                        
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="shift" onclick="openReportModal('shift', 'Shift Report', 'Staff login/logout activity')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clock text-pink-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-pink-100 text-pink-700 px-2 py-1 rounded-full">Staff</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Shift Report</h3>
                            <p class="text-sm text-gray-500">Staff login/logout activity</p>
                        </div>
                        
                        <!-- Accounting Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="profit_loss" onclick="openReportModal('profit_loss', 'Profit & Loss Report', 'Revenue, costs, and profit analysis')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-cyan-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-balance-scale text-cyan-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-cyan-100 text-cyan-700 px-2 py-1 rounded-full">Accounting</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Profit & Loss Report</h3>
                            <p class="text-sm text-gray-500">Revenue, costs, and profit analysis</p>
                        </div>
                        
                        <!-- System Reports -->
                        <div class="report-card ui-selectable-card bg-gray-50 rounded-xl p-5 border border-gray-200" data-card-id="audit_log" onclick="openReportModal('audit_log', 'Audit Log Report', 'Logins, sales, receiving, adjustments, and opening/closing stock')">
                            <div class="ui-card-checkbox-wrap" onclick="event.stopPropagation()"><input type="checkbox" class="ui-card-checkbox rounded border-gray-300 text-teal-600 focus:ring-teal-500" aria-label="Select card"></div>
                            <div class="flex items-start justify-between mb-3">
                                <div class="w-12 h-12 bg-gray-200 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-history text-gray-600 text-xl"></i>
                                </div>
                                <span class="text-xs bg-gray-200 text-gray-700 px-2 py-1 rounded-full">System</span>
                            </div>
                            <h3 class="font-semibold text-gray-800 mb-1">Audit Log Report</h3>
                            <p class="text-sm text-gray-500">System activity and user actions</p>
                        </div>
                        
                        <?php $reportsSearchInclude = 'empty'; include __DIR__ . '/../includes/reports_center_search.php'; ?>
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
                    <input type="hidden" id="reportSource" name="report_source" value="standard">
                    
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

                    <div id="supplierFilter" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Supplier (Optional)</label>
                        <select id="supplierId" name="supplier_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="invCustomerFilter" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                        <select id="invCustomerId" name="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="0">All Customers</option>
                            <?php foreach ($invCustomers as $cust): ?>
                                <option value="<?= (int) $cust['id'] ?>"><?= htmlspecialchars($cust['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="invFormatFilter" class="mb-4 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Format</label>
                        <select id="invFormat" name="format" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="pdf">PDF</option>
                            <option value="csv">CSV</option>
                            <option value="excel">Excel</option>
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
        function hideAllReportFilters() {
            document.getElementById('cashierFilter').classList.add('hidden');
            document.getElementById('creditorFilter').classList.add('hidden');
            document.getElementById('categoryFilter').classList.add('hidden');
            document.getElementById('supplierFilter').classList.add('hidden');
            document.getElementById('invCustomerFilter').classList.add('hidden');
            document.getElementById('invFormatFilter').classList.add('hidden');
        }

        function openReportModal(type, title, description) {
            document.getElementById('reportSource').value = 'standard';
            document.getElementById('reportType').value = type;
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalDescription').textContent = description;
            
            // Set default dates to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('startDate').value = today;
            document.getElementById('endDate').value = today;
            
            hideAllReportFilters();
            
            if (['cashier_sales', 'shift', 'cashup', 'gratuity', 'tips'].includes(type)) {
                document.getElementById('cashierFilter').classList.remove('hidden');
            }
            
            if (['credit_sales', 'outstanding_credit', 'tabs'].includes(type)) {
                document.getElementById('creditorFilter').classList.remove('hidden');
            }

            if (type === 'supplier_receiving') {
                document.getElementById('supplierFilter').classList.remove('hidden');
            }
            
            var categoryReportTypes = ['sales', 'daily_sales', 'monthly_sales', 'item_sales', 'cash_sales', 'card_sales', 'payment_summary', 'vat', 'credit_sales', 'outstanding_credit', 'tabs', 'refunds', 'voids', 'plu', 'current_stock', 'low_stock', 'stock_movement', 'stock_variance', 'cashier_sales', 'profit_loss'];
            if (categoryReportTypes.indexOf(type) !== -1) {
                document.getElementById('categoryFilter').classList.remove('hidden');
            }

            updateGenerateBtnLabel('pdf');
            
            // Reset period buttons
            document.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
            
            // Show modal
            document.getElementById('reportModal').classList.add('active');
        }

        function openInvReportModal(report, title, description, needsCustomer) {
            document.getElementById('reportSource').value = 'invoicing';
            document.getElementById('reportType').value = report;
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalDescription').textContent = description;

            const today = new Date().toISOString().split('T')[0];
            const first = today.substring(0, 8) + '01';
            document.getElementById('startDate').value = first;
            document.getElementById('endDate').value = today;

            hideAllReportFilters();
            document.getElementById('invFormatFilter').classList.remove('hidden');
            document.getElementById('invFormat').value = 'pdf';
            if (needsCustomer) {
                document.getElementById('invCustomerFilter').classList.remove('hidden');
                document.getElementById('invCustomerId').value = '0';
            }

            updateGenerateBtnLabel('pdf');
            document.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('reportModal').classList.add('active');
        }

        function updateGenerateBtnLabel(format) {
            const btn = document.getElementById('generateBtn');
            const fmt = (format || 'pdf').toLowerCase();
            const icon = fmt === 'csv' || fmt === 'excel' ? 'fa-file-excel' : 'fa-file-pdf';
            const label = fmt === 'csv' ? 'Generate CSV' : (fmt === 'excel' ? 'Generate Excel' : 'Generate PDF');
            btn.innerHTML = '<i class="fas ' + icon + '"></i> ' + label;
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

            const source = document.getElementById('reportSource').value;
            let url;

            if (source === 'invoicing') {
                const params = new URLSearchParams({
                    report: document.getElementById('reportType').value,
                    start: document.getElementById('startDate').value,
                    end: document.getElementById('endDate').value,
                    format: document.getElementById('invFormat').value || 'pdf',
                    customer_id: document.getElementById('invCustomerId').value || '0'
                });
                url = '../invoicing_reports.php?' + params.toString();
            } else {
                const formData = new FormData(document.getElementById('reportForm'));
                const params = new URLSearchParams();
                for (let [key, value] of formData.entries()) {
                    if (value && key !== 'report_source' && key !== 'format' && key !== 'customer_id') {
                        params.append(key, value);
                    }
                }
                url = 'generate_report_pdf.php?' + params.toString();
            }
            
            window.open(url, '_blank');
            
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
    </script>
    <script>
        document.getElementById('invFormat')?.addEventListener('change', function() {
            updateGenerateBtnLabel(this.value);
        });
    </script>
    <?php $reportsSearchInclude = 'script'; include __DIR__ . '/../includes/reports_center_search.php'; ?>
</body>
</html>
