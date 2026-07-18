<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../");
    exit();
}

// Check if user is admin or manager
$allowedRoles = ['admin', 'manager'];
if (!in_array(strtolower($_SESSION['role']), $allowedRoles)) {
    header("Location: ../home.php");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:../active.db');
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}

// Database connection
$db = new PDO('sqlite:../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure cashup_records table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cashup_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashup_date DATE NOT NULL,
            cashier_id VARCHAR(100) DEFAULT 'all',
            cashier_name VARCHAR(255) DEFAULT 'All Staff',
            is_individual_cashout INTEGER DEFAULT 0,
            cash_sales_expected DECIMAL(10,2) DEFAULT 0.00,
            cash_on_hand DECIMAL(10,2) DEFAULT 0.00,
            over_short DECIMAL(10,2) DEFAULT 0.00,
            card_sales_expected DECIMAL(10,2) DEFAULT 0.00,
            unpaid_credit_sales DECIMAL(10,2) DEFAULT 0.00,
            open_tabs_balance DECIMAL(10,2) DEFAULT 0.00,
            unpaid_tabs DECIMAL(10,2) DEFAULT 0.00,
            credit_returns DECIMAL(10,2) DEFAULT 0.00,
            expenses DECIMAL(10,2) DEFAULT 0.00,
            cash_back DECIMAL(10,2) DEFAULT 0.00,
            tips DECIMAL(10,2) DEFAULT 0.00,
            hubbly DECIMAL(10,2) DEFAULT 0.00,
            beerhouse DECIMAL(10,2) DEFAULT 0.00,
            voids DECIMAL(10,2) DEFAULT 0.00,
            refunds DECIMAL(10,2) DEFAULT 0.00,
            total_items_sold DECIMAL(10,2) DEFAULT 0.00,
            created_by VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            UNIQUE(cashup_date, cashier_id)
        )
    ");
} catch (PDOException $e) {
    // Table might already exist
}

// Handle POST requests for deleting cashups
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $db->prepare("DELETE FROM cashup_records WHERE id = ?");
        $stmt->execute([$_POST['delete_id']]);
        $_SESSION['success'] = 'Cash-up record deleted successfully';
        header('Location: cashups.php');
        exit();
    }
}

// Fetch all cashup records
$cashups = $db->query("
    SELECT * FROM cashup_records
    ORDER BY cashup_date DESC, created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalRecords = count($cashups);
$recordsWithShortage = array_filter($cashups, function($c) { return $c['over_short'] < 0; });
$recordsWithOverage = array_filter($cashups, function($c) { return $c['over_short'] > 0; });
$recordsBalanced = array_filter($cashups, function($c) { return $c['over_short'] == 0; });
$totalShortage = array_sum(array_map(function($c) { return $c['over_short'] < 0 ? abs($c['over_short']) : 0; }, $cashups));
$totalOverage = array_sum(array_map(function($c) { return $c['over_short'] > 0 ? $c['over_short'] : 0; }, $cashups));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash-Up Management</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <script src="../lucide.js"></script>
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
        
        /* Modern scrollbar styles */
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: rgb(133, 133, 133) #E5E7EB;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 2px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #E5E7EB;
            border-radius: 1px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #14b8a6;
            border-radius: 1px;
        }

        /* Table styles */
        .cashup-row {
            transition: background-color 0.2s;
        }

        .cashup-row:hover {
            background-color: #f9fafb;
        }

        th[onclick] {
            user-select: none;
        }

        th[onclick]:hover {
            background-color: #f3f4f6;
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
            
            .container {
                padding: 1rem;
            }
        }

        /* Mobile table responsiveness */
        @media (max-width: 768px) {
            .table-container {
                display: none;
            }
            
            .mobile-cards-container {
                display: block;
            }
            
            .mobile-cashup-card {
                background: white;
                border: 1px solid #e5e7eb;
                border-radius: 0.75rem;
                padding: 1rem;
                margin-bottom: 0.75rem;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
                transition: all 0.3s ease;
            }
            
            .mobile-cashup-card:active {
                transform: scale(0.98);
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                background: #f9fafb;
            }
            
            .mobile-cashup-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 0.75rem;
                padding-bottom: 0.75rem;
                border-bottom: 1px solid #f3f4f6;
            }
            
            .mobile-cashup-card-title {
                font-size: 1.125rem;
                font-weight: 600;
                color: #111827;
            }
            
            .mobile-cashup-card-id {
                font-size: 0.875rem;
                color: #6b7280;
                font-weight: 500;
            }
            
            .mobile-cashup-card-body {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
                margin-bottom: 0.75rem;
            }
            
            .mobile-cashup-card-field {
                display: flex;
                flex-direction: column;
            }
            
            .mobile-cashup-card-label {
                font-size: 0.75rem;
                color: #6b7280;
                text-transform: uppercase;
                font-weight: 500;
                margin-bottom: 0.25rem;
                letter-spacing: 0.05em;
            }
            
            .mobile-cashup-card-value {
                font-size: 0.875rem;
                color: #111827;
                font-weight: 500;
            }
            
            .mobile-cashup-card-actions {
                display: flex;
                gap: 0.5rem;
                padding-top: 0.75rem;
                border-top: 1px solid #f3f4f6;
                flex-wrap: wrap;
            }
            
            .mobile-cashup-card-actions a,
            .mobile-cashup-card-actions button {
                flex: 1;
                min-width: 70px;
                padding: 0.75rem 0.5rem;
                text-align: center;
                border-radius: 0.5rem;
                font-size: 0.75rem;
                font-weight: 600;
                transition: all 0.2s;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
                min-height: 60px;
            }
            
            .mobile-cashup-card-full-width {
                grid-column: 1 / -1;
            }
            
            .mobile-status-badge {
                display: inline-block;
                padding: 0.25rem 0.75rem;
                border-radius: 9999px;
                font-size: 0.75rem;
                font-weight: 600;
            }
        }

        @media (min-width: 769px) {
            .mobile-cards-container {
                display: none;
            }
            
            .table-container {
                display: block;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="content flex-1 lg:ml-64">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="w-full p-4 lg:p-6">
                <!-- Header Row -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold">Cash-Up Records</h1>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 z-20" role="alert">
                    <span class="block sm:inline"><?= $_SESSION['error'] ?></span>
                    <button onclick="this.parentElement.remove()" class="absolute top-0 right-0 px-3 py-1">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-teal-100 border border-teal-400 text-teal-700 px-4 py-3 rounded relative mb-4 z-20" role="alert">
                    <span class="block sm:inline"><?= $_SESSION['success'] ?></span>
                    <button onclick="this.parentElement.remove()" class="absolute top-0 right-0 px-3 py-1">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-teal-100 rounded-lg">
                                <i data-lucide="file-text" class="w-5 h-5 text-teal-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase font-medium">Total Records</p>
                                <p class="text-xl font-bold text-gray-800"><?= $totalRecords ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-green-100 rounded-lg">
                                <i data-lucide="check-circle" class="w-5 h-5 text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase font-medium">Balanced</p>
                                <p class="text-xl font-bold text-green-600"><?= count($recordsBalanced) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-red-100 rounded-lg">
                                <i data-lucide="trending-down" class="w-5 h-5 text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase font-medium">Total Short</p>
                                <p class="text-xl font-bold text-red-600">N$<?= number_format($totalShortage, 2) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-blue-100 rounded-lg">
                                <i data-lucide="trending-up" class="w-5 h-5 text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase font-medium">Total Over</p>
                                <p class="text-xl font-bold text-blue-600">N$<?= number_format($totalOverage, 2) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cashups Table -->
                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:border-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col gap-3 md:flex-row md:gap-4 items-stretch md:items-center justify-between">
                                        <div class="relative w-full md:max-w-xs md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="searchInput" class="py-2.5 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-teal-500 focus:ring-teal-500" placeholder="Search cashups...">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <!-- Filters -->
                                        <div class="flex gap-2 items-stretch md:items-center flex-wrap">
                                            <input type="date" id="dateFilter" class="flex-1 md:flex-none py-2.5 px-3 border border-gray-200 rounded-lg text-sm focus:border-teal-500 focus:ring-teal-500" placeholder="Filter by date">
                                            <select id="statusFilter" class="flex-1 md:flex-none py-2.5 px-3 border border-gray-200 rounded-lg text-sm focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">All Status</option>
                                                <option value="balanced">Balanced</option>
                                                <option value="short">Short</option>
                                                <option value="over">Over</option>
                                            </select>
                                            <select id="cashierFilter" class="flex-1 md:flex-none py-2.5 px-3 border border-gray-200 rounded-lg text-sm focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">All Cashiers</option>
                                                <?php 
                                                $cashierNames = array_unique(array_column($cashups, 'cashier_name'));
                                                foreach($cashierNames as $name): 
                                                ?>
                                                <option value="<?= htmlspecialchars(strtolower($name)) ?>"><?= htmlspecialchars($name) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Desktop Table -->
                                <div class="table-container overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="py-3 px-4 pe-0">
                                                    <div class="flex items-center h-5">
                                                        <input id="selectAllCheckbox" type="checkbox" class="border-gray-200 rounded text-teal-600 focus:ring-teal-500" onchange="toggleAllRows(this)">
                                                    </div>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(0)">
                                                    ID <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(1)">
                                                    Date <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(2)">
                                                    Cashier <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(3)">
                                                    Expected <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(4)">
                                                    On Hand <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(5)">
                                                    Over/Short <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(6)">
                                                    Total Sales <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(7)">
                                                    Created At <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="cashupTableBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php if (empty($cashups)): ?>
                                                <tr>
                                                    <td colspan="10" class="px-6 py-12 text-center">
                                                        <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No cash-up records found.</p>
                                                        <p class="text-gray-400 text-sm mt-2">Cash-up records will appear here after you complete a cash-up.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($cashups as $cashup): 
                                                    $overShort = floatval($cashup['over_short']);
                                                    $statusClass = $overShort < 0 ? 'text-red-600' : ($overShort > 0 ? 'text-blue-600' : 'text-green-600');
                                                    $statusBadge = $overShort < 0 ? 'bg-red-100 text-red-800' : ($overShort > 0 ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800');
                                                    $statusText = $overShort < 0 ? 'Short' : ($overShort > 0 ? 'Over' : 'Balanced');
                                                ?>
                                                    <tr class="cashup-row hover:bg-gray-50 transition-colors cursor-pointer" 
                                                        data-cashup-id="<?= $cashup['id'] ?>"
                                                        data-cashup-date="<?= htmlspecialchars(strtolower($cashup['cashup_date'])) ?>"
                                                        data-cashup-cashier="<?= htmlspecialchars(strtolower($cashup['cashier_name'])) ?>"
                                                        data-cashup-status="<?= $overShort < 0 ? 'short' : ($overShort > 0 ? 'over' : 'balanced') ?>"
                                                        data-cashup-expected="<?= $cashup['cash_sales_expected'] ?>"
                                                        data-cashup-onhand="<?= $cashup['cash_on_hand'] ?>"
                                                        data-cashup-overshort="<?= $overShort ?>"
                                                        data-cashup-total="<?= $cashup['total_items_sold'] ?>"
                                                        data-cashup-created="<?= strtolower($cashup['created_at']) ?>"
                                                        onclick="handleRowClick(event, <?= $cashup['id'] ?>, '<?= htmlspecialchars($cashup['cashier_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cashup['cashup_date'], ENT_QUOTES) ?>')">
                                                        <td class="py-3 ps-4" onclick="event.stopPropagation()">
                                                            <div class="flex items-center h-5">
                                                                <input type="checkbox" class="row-checkbox border-gray-200 rounded text-teal-600 focus:ring-teal-500" value="<?= $cashup['id'] ?>">
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800"><?= $cashup['id'] ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?= date('Y-m-d', strtotime($cashup['cashup_date'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?= htmlspecialchars($cashup['cashier_name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">N$<?= number_format($cashup['cash_sales_expected'], 2) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">N$<?= number_format($cashup['cash_on_hand'], 2) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?= $statusClass ?>">
                                                            <?= $overShort >= 0 ? '+' : '' ?>N$<?= number_format($overShort, 2) ?>
                                                            <span class="ml-2 px-2 py-0.5 text-xs rounded-full <?= $statusBadge ?>"><?= $statusText ?></span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-teal-600">N$<?= number_format($cashup['total_items_sold'], 2) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('Y-m-d H:i', strtotime($cashup['created_at'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium" onclick="event.stopPropagation()">
                                                            <div class="flex items-center justify-end gap-2">
                                                                <a href="view-cashup.php?cashier=<?= urlencode($cashup['cashier_id']) ?>&date=<?= urlencode($cashup['cashup_date']) ?>" 
                                                                   class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800"
                                                                   title="View Details">
                                                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                                                </a>
                                                                <button onclick="printCashupReceipt(<?= $cashup['id'] ?>, <?= json_encode($cashup) ?>);"
                                                                    class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-gray-600 hover:text-gray-800"
                                                                    title="Print Receipt">
                                                                    <i data-lucide="printer" class="w-4 h-4"></i>
                                                                </button>
                                                                <button onclick="deleteCashup(<?= $cashup['id'] ?>, '<?= htmlspecialchars($cashup['cashup_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cashup['cashier_name'], ENT_QUOTES) ?>');"
                                                                    class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800"
                                                                    title="Delete">
                                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Mobile Cards -->
                                <div id="mobileCardsContainer" class="mobile-cards-container px-3 md:px-4 pb-4">
                                    <?php if (empty($cashups)): ?>
                                        <div class="text-center py-12">
                                            <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                            <p class="text-gray-500 text-lg">No cash-up records found.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach($cashups as $cashup): 
                                            $overShort = floatval($cashup['over_short']);
                                            $statusClass = $overShort < 0 ? 'text-red-600' : ($overShort > 0 ? 'text-blue-600' : 'text-green-600');
                                            $statusBadge = $overShort < 0 ? 'bg-red-100 text-red-800' : ($overShort > 0 ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800');
                                            $statusText = $overShort < 0 ? 'Short' : ($overShort > 0 ? 'Over' : 'Balanced');
                                        ?>
                                            <div class="mobile-cashup-card" 
                                                 data-cashup-id="<?= $cashup['id'] ?>"
                                                 data-cashup-date="<?= htmlspecialchars(strtolower($cashup['cashup_date'])) ?>"
                                                 data-cashup-cashier="<?= htmlspecialchars(strtolower($cashup['cashier_name'])) ?>"
                                                 data-cashup-status="<?= $overShort < 0 ? 'short' : ($overShort > 0 ? 'over' : 'balanced') ?>"
                                                 data-cashup-expected="<?= $cashup['cash_sales_expected'] ?>"
                                                 data-cashup-onhand="<?= $cashup['cash_on_hand'] ?>"
                                                 data-cashup-overshort="<?= $overShort ?>"
                                                 data-cashup-total="<?= $cashup['total_items_sold'] ?>"
                                                 data-cashup-created="<?= strtolower($cashup['created_at']) ?>"
                                                 onclick="handleMobileCardClick(event, <?= $cashup['id'] ?>, '<?= htmlspecialchars($cashup['cashier_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cashup['cashup_date'], ENT_QUOTES) ?>')">
                                                <div class="mobile-cashup-card-header">
                                                    <div>
                                                        <div class="mobile-cashup-card-title"><?= htmlspecialchars($cashup['cashier_name']) ?></div>
                                                        <div class="text-sm text-gray-500"><?= date('Y-m-d', strtotime($cashup['cashup_date'])) ?></div>
                                                    </div>
                                                    <div class="mobile-cashup-card-id">ID: <?= $cashup['id'] ?></div>
                                                </div>
                                                <div class="mobile-cashup-card-body">
                                                    <div class="mobile-cashup-card-field">
                                                        <div class="mobile-cashup-card-label">Status</div>
                                                        <div class="mobile-cashup-card-value">
                                                            <span class="mobile-status-badge <?= $statusBadge ?>"><?= $statusText ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="mobile-cashup-card-field">
                                                        <div class="mobile-cashup-card-label">Over/Short</div>
                                                        <div class="mobile-cashup-card-value font-bold <?= $statusClass ?>">
                                                            <?= $overShort >= 0 ? '+' : '' ?>N$<?= number_format($overShort, 2) ?>
                                                        </div>
                                                    </div>
                                                    <div class="mobile-cashup-card-field">
                                                        <div class="mobile-cashup-card-label">Expected</div>
                                                        <div class="mobile-cashup-card-value">N$<?= number_format($cashup['cash_sales_expected'], 2) ?></div>
                                                    </div>
                                                    <div class="mobile-cashup-card-field">
                                                        <div class="mobile-cashup-card-label">On Hand</div>
                                                        <div class="mobile-cashup-card-value">N$<?= number_format($cashup['cash_on_hand'], 2) ?></div>
                                                    </div>
                                                    <div class="mobile-cashup-card-field mobile-cashup-card-full-width">
                                                        <div class="mobile-cashup-card-label">Total Sales</div>
                                                        <div class="mobile-cashup-card-value font-bold text-teal-600">N$<?= number_format($cashup['total_items_sold'], 2) ?></div>
                                                    </div>
                                                </div>
                                                <div class="mobile-cashup-card-actions" onclick="event.stopPropagation()">
                                                    <a href="view-cashup.php?cashier=<?= urlencode($cashup['cashier_id']) ?>&date=<?= urlencode($cashup['cashup_date']) ?>" 
                                                       class="bg-blue-50 text-blue-600 hover:bg-blue-100">
                                                        <i data-lucide="eye" class="w-4 h-4 mx-auto"></i>
                                                        <span class="block mt-1">View</span>
                                                    </a>
                                                    <button onclick="printCashupReceipt(<?= $cashup['id'] ?>, <?= json_encode($cashup) ?>);"
                                                        class="bg-gray-50 text-gray-600 hover:bg-gray-100">
                                                        <i data-lucide="printer" class="w-4 h-4 mx-auto"></i>
                                                        <span class="block mt-1">Print</span>
                                                    </button>
                                                    <button onclick="deleteCashup(<?= $cashup['id'] ?>, '<?= htmlspecialchars($cashup['cashup_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cashup['cashier_name'], ENT_QUOTES) ?>');"
                                                        class="bg-red-50 text-red-600 hover:bg-red-100">
                                                        <i data-lucide="trash-2" class="w-4 h-4 mx-auto"></i>
                                                        <span class="block mt-1">Delete</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Pagination -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row items-center justify-between gap-3 md:gap-4">
                                        <div class="text-xs md:text-sm text-gray-700 text-center md:text-left">
                                            Showing <span id="showingFrom" class="font-semibold">1</span> to <span id="showingTo" class="font-semibold">10</span> of <span id="totalRows" class="font-semibold"><?= count($cashups) ?></span> entries
                                        </div>
                                        <nav class="flex items-center justify-center flex-wrap gap-1" id="paginationNav">
                                            <!-- Pagination buttons will be generated by JavaScript -->
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Table management variables
        let currentPage = 1;
        let rowsPerPage = 10;
        let currentSortColumn = -1;
        let sortDirection = 'asc';
        let allRows = [];
        let allMobileCards = [];
        let filteredRows = [];
        let filteredMobileCards = [];

        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Get all table rows
            const tableBody = document.getElementById('cashupTableBody');
            allRows = Array.from(tableBody.querySelectorAll('.cashup-row'));
            filteredRows = [...allRows];

            // Get all mobile cards
            const mobileContainer = document.getElementById('mobileCardsContainer');
            if (mobileContainer) {
                allMobileCards = Array.from(mobileContainer.querySelectorAll('.mobile-cashup-card'));
                filteredMobileCards = [...allMobileCards];
            }

            // Initialize table
            initializeTable();

            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    filterTable();
                });
            }

            // Date filter
            const dateFilter = document.getElementById('dateFilter');
            if (dateFilter) {
                dateFilter.addEventListener('change', function() {
                    filterTable();
                });
            }

            // Status filter
            const statusFilter = document.getElementById('statusFilter');
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    filterTable();
                });
            }

            // Cashier filter
            const cashierFilter = document.getElementById('cashierFilter');
            if (cashierFilter) {
                cashierFilter.addEventListener('change', function() {
                    filterTable();
                });
            }

            // Auto-hide success/error messages after 5 seconds
            const alerts = document.querySelectorAll('[role="alert"]');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });

        // Initialize table with pagination
        function initializeTable() {
            filterTable();
        }

        // Filter table based on search and filters
        function filterTable() {
            const searchInput = document.getElementById('searchInput');
            const dateFilter = document.getElementById('dateFilter');
            const statusFilter = document.getElementById('statusFilter');
            const cashierFilter = document.getElementById('cashierFilter');
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const dateValue = dateFilter ? dateFilter.value : '';
            const statusValue = statusFilter ? statusFilter.value : '';
            const cashierValue = cashierFilter ? cashierFilter.value : '';

            // Filter function for both rows and cards
            const filterItem = (item) => {
                const cashupDate = item.getAttribute('data-cashup-date') || '';
                const cashupCashier = item.getAttribute('data-cashup-cashier') || '';
                const cashupStatus = item.getAttribute('data-cashup-status') || '';
                const cashupId = item.getAttribute('data-cashup-id') || '';
                const cashupCreated = item.getAttribute('data-cashup-created') || '';

                // Search filter
                const matchesSearch = searchTerm === '' || 
                    cashupDate.includes(searchTerm) || 
                    cashupCashier.includes(searchTerm) || 
                    cashupId.includes(searchTerm) ||
                    cashupCreated.includes(searchTerm);

                // Date filter
                const matchesDate = dateValue === '' || cashupDate === dateValue;

                // Status filter
                const matchesStatus = statusValue === '' || cashupStatus === statusValue;

                // Cashier filter
                const matchesCashier = cashierValue === '' || cashupCashier === cashierValue;

                return matchesSearch && matchesDate && matchesStatus && matchesCashier;
            };

            filteredRows = allRows.filter(filterItem);
            filteredMobileCards = allMobileCards.filter(filterItem);

            currentPage = 1;
            renderTable();
        }

        // Sort table
        function sortTable(columnIndex) {
            if (currentSortColumn === columnIndex) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortColumn = columnIndex;
                sortDirection = 'asc';
            }

            const sortItems = (a, b) => {
                let aValue, bValue;

                switch(columnIndex) {
                    case 0: // ID
                        aValue = parseInt(a.getAttribute('data-cashup-id') || 0);
                        bValue = parseInt(b.getAttribute('data-cashup-id') || 0);
                        break;
                    case 1: // Date
                        aValue = a.getAttribute('data-cashup-date') || '';
                        bValue = b.getAttribute('data-cashup-date') || '';
                        break;
                    case 2: // Cashier
                        aValue = a.getAttribute('data-cashup-cashier') || '';
                        bValue = b.getAttribute('data-cashup-cashier') || '';
                        break;
                    case 3: // Expected
                        aValue = parseFloat(a.getAttribute('data-cashup-expected') || 0);
                        bValue = parseFloat(b.getAttribute('data-cashup-expected') || 0);
                        break;
                    case 4: // On Hand
                        aValue = parseFloat(a.getAttribute('data-cashup-onhand') || 0);
                        bValue = parseFloat(b.getAttribute('data-cashup-onhand') || 0);
                        break;
                    case 5: // Over/Short
                        aValue = parseFloat(a.getAttribute('data-cashup-overshort') || 0);
                        bValue = parseFloat(b.getAttribute('data-cashup-overshort') || 0);
                        break;
                    case 6: // Total Sales
                        aValue = parseFloat(a.getAttribute('data-cashup-total') || 0);
                        bValue = parseFloat(b.getAttribute('data-cashup-total') || 0);
                        break;
                    case 7: // Created At
                        aValue = a.getAttribute('data-cashup-created') || '';
                        bValue = b.getAttribute('data-cashup-created') || '';
                        break;
                    default:
                        return 0;
                }

                if (typeof aValue === 'number') {
                    return sortDirection === 'asc' ? aValue - bValue : bValue - aValue;
                } else {
                    return sortDirection === 'asc' 
                        ? aValue.localeCompare(bValue)
                        : bValue.localeCompare(aValue);
                }
            };

            filteredRows.sort(sortItems);
            filteredMobileCards.sort(sortItems);

            renderTable();
        }

        // Render table with pagination
        function renderTable() {
            const tableBody = document.getElementById('cashupTableBody');
            const mobileContainer = document.getElementById('mobileCardsContainer');
            const totalRows = filteredRows.length;
            const totalPages = Math.ceil(totalRows / rowsPerPage);
            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = startIndex + rowsPerPage;
            const pageRows = filteredRows.slice(startIndex, endIndex);
            const pageCards = filteredMobileCards.slice(startIndex, endIndex);

            // Render desktop table
            if (tableBody) {
                tableBody.innerHTML = '';

                if (pageRows.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="10" class="px-6 py-12 text-center"><i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No cash-up records found matching your criteria.</p></td></tr>';
                } else {
                    pageRows.forEach(row => {
                        const clonedRow = row.cloneNode(true);
                        tableBody.appendChild(clonedRow);
                    });
                }
            }

            // Render mobile cards
            if (mobileContainer) {
                mobileContainer.innerHTML = '';

                if (pageCards.length === 0) {
                    mobileContainer.innerHTML = '<div class="text-center py-12"><i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No cash-up records found matching your criteria.</p></div>';
                } else {
                    pageCards.forEach(card => {
                        const clonedCard = card.cloneNode(true);
                        mobileContainer.appendChild(clonedCard);
                    });
                }
            }

            // Update pagination info
            document.getElementById('showingFrom').textContent = totalRows === 0 ? 0 : startIndex + 1;
            document.getElementById('showingTo').textContent = Math.min(endIndex, totalRows);
            document.getElementById('totalRows').textContent = totalRows;

            // Render pagination
            renderPagination(totalPages);

            // Reinitialize icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        // Render pagination buttons
        function renderPagination(totalPages) {
            const paginationNav = document.getElementById('paginationNav');
            paginationNav.innerHTML = '';

            if (totalPages <= 1) return;

            // Previous button
            const prevButton = document.createElement('button');
            prevButton.type = 'button';
            prevButton.className = 'p-2.5 min-w-[44px] min-h-[44px] inline-flex items-center justify-center gap-x-2 text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none';
            prevButton.disabled = currentPage === 1;
            prevButton.innerHTML = '<span aria-hidden="true">«</span>';
            prevButton.onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            };
            paginationNav.appendChild(prevButton);

            // Page number buttons
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    const pageButton = document.createElement('button');
                    pageButton.type = 'button';
                    pageButton.className = `min-w-[44px] min-h-[44px] flex justify-center items-center text-gray-800 hover:bg-gray-100 py-2.5 text-sm rounded-full ${i === currentPage ? 'bg-teal-100 text-teal-700 font-semibold' : ''}`;
                    pageButton.textContent = i;
                    pageButton.onclick = () => {
                        currentPage = i;
                        renderTable();
                    };
                    paginationNav.appendChild(pageButton);
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'px-2 py-2.5 text-gray-500 text-sm';
                    ellipsis.textContent = '...';
                    paginationNav.appendChild(ellipsis);
                }
            }

            // Next button
            const nextButton = document.createElement('button');
            nextButton.type = 'button';
            nextButton.className = 'p-2.5 min-w-[44px] min-h-[44px] inline-flex items-center justify-center gap-x-2 text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none';
            nextButton.disabled = currentPage === totalPages;
            nextButton.innerHTML = '<span aria-hidden="true">»</span>';
            nextButton.onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            };
            paginationNav.appendChild(nextButton);
        }

        // Handle row click
        function handleRowClick(event, cashupId, cashierId, cashupDate) {
            if (event.target.closest('a, button, input[type="checkbox"]')) {
                return;
            }
            window.location.href = `view-cashup.php?cashier=${encodeURIComponent(cashierId)}&date=${encodeURIComponent(cashupDate)}`;
        }

        // Handle mobile card click
        function handleMobileCardClick(event, cashupId, cashierId, cashupDate) {
            if (event.target.closest('a, button, .mobile-cashup-card-actions')) {
                return;
            }
            window.location.href = `view-cashup.php?cashier=${encodeURIComponent(cashierId)}&date=${encodeURIComponent(cashupDate)}`;
        }

        // Toggle all rows checkbox
        function toggleAllRows(checkbox) {
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            rowCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        // Delete cashup
        function deleteCashup(cashupId, cashupDate, cashierName) {
            Swal.fire({
                title: 'Delete Cash-Up Record?',
                html: `<div class="text-center">
                    <p class="text-gray-700 mb-2">Are you sure you want to delete the cash-up record for</p>
                    <p class="text-lg font-semibold text-gray-900">${cashierName}</p>
                    <p class="text-gray-600">on ${cashupDate}</p>
                    <p class="text-sm text-red-600 mt-2">This action cannot be undone!</p>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                customClass: {
                    popup: 'rounded-2xl shadow-xl',
                    confirmButton: 'px-6 py-2.5 rounded-lg hover:bg-red-700 transition-all',
                    cancelButton: 'px-6 py-2.5 rounded-lg hover:bg-gray-200 transition-all'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="delete_id" value="${cashupId}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Print cashup receipt
        async function printCashupReceipt(cashupId, cashupData) {
            try {
                Swal.fire({
                    title: 'Printing...',
                    text: 'Sending to printer',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const receiptData = {
                    is_cashup_report: true,
                    date: cashupData.cashup_date,
                    cashier_username: cashupData.cashier_name,
                    employees: [{
                        name: cashupData.cashier_name,
                        cash_sales: parseFloat(cashupData.cash_sales_expected || 0),
                        eft_sales: parseFloat(cashupData.card_sales_expected || 0),
                        total_sales: parseFloat(cashupData.total_items_sold || 0)
                    }],
                    total_cash_sales: parseFloat(cashupData.cash_sales_expected || 0),
                    total_eft_sales: parseFloat(cashupData.card_sales_expected || 0),
                    grand_total: parseFloat(cashupData.total_items_sold || 0),
                    cash_on_hand: parseFloat(cashupData.cash_on_hand || 0),
                    over_short: parseFloat(cashupData.over_short || 0),
                    expenses: parseFloat(cashupData.expenses || 0),
                    cash_back: parseFloat(cashupData.cash_back || 0),
                    tips: parseFloat(cashupData.tips || 0),
                    hubbly: parseFloat(cashupData.hubbly || 0),
                    beerhouse: parseFloat(cashupData.beerhouse || 0),
                    voids: parseFloat(cashupData.voids || 0),
                    refunds: parseFloat(cashupData.refunds || 0)
                };

                const result = await sendToPrinter(receiptData);

                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Receipt Printed',
                        text: 'Cash-up receipt printed successfully!',
                        timer: 2000,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Print Error',
                        text: result.message || 'Failed to print receipt'
                    });
                }
            } catch (error) {
                console.error('Print error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Print Error',
                    text: 'Error printing receipt: ' + error.message
                });
            }
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
</body>
</html>
