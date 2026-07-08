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

// Database connection
$db = new PDO('sqlite:../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create cash_transactions table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS cash_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cashier_id INTEGER
)");

// Get view parameter for filtering
$currentView = isset($_GET['view']) ? $_GET['view'] : '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_expense'])) {
        // Add new expense (cash-out)
        $amount = floatval($_POST['amount']);
        $description = trim($_POST['description']);
        $category = trim($_POST['category'] ?? '');
        
        if ($amount > 0 && !empty($description)) {
            $fullDescription = !empty($category) ? "[$category] $description" : $description;
            $stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES ('cash-out', ?, ?, ?, datetime('now', 'localtime'))");
            $stmt->execute([$amount, $fullDescription, $_SESSION['username'] ?? 'Unknown']);
            $_SESSION['success'] = 'Expense added successfully';
        } else {
            $_SESSION['error'] = 'Please enter a valid amount and description';
        }
        header('Location: expenses');
        exit();
    }
    
    if (isset($_POST['delete_id'])) {
        // Delete expense
        $stmt = $db->prepare("DELETE FROM cash_transactions WHERE id = ? AND type = 'cash-out'");
        $stmt->execute([$_POST['delete_id']]);
        $_SESSION['success'] = 'Expense deleted successfully';
        header('Location: expenses');
        exit();
    }
    
    if (isset($_POST['edit_id'])) {
        // Edit expense
        $amount = floatval($_POST['edit_amount']);
        $description = trim($_POST['edit_description']);
        
        if ($amount > 0 && !empty($description)) {
            $stmt = $db->prepare("UPDATE cash_transactions SET amount = ?, description = ? WHERE id = ? AND type = 'cash-out'");
            $stmt->execute([$amount, $description, $_POST['edit_id']]);
            $_SESSION['success'] = 'Expense updated successfully';
        } else {
            $_SESSION['error'] = 'Please enter a valid amount and description';
        }
        header('Location: expenses');
        exit();
    }
}

// Build query based on view filter
$whereClause = "WHERE type = 'cash-out'";
$params = [];

// Date filtering based on view
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

if ($currentView === 'daily' || isset($_GET['date'])) {
    $filterDate = isset($_GET['date']) ? $_GET['date'] : $today;
    $whereClause .= " AND DATE(created_at) = ?";
    $params[] = $filterDate;
} elseif ($currentView === 'weekly') {
    $whereClause .= " AND DATE(created_at) >= ?";
    $params[] = $weekStart;
} elseif ($currentView === 'monthly') {
    $whereClause .= " AND DATE(created_at) >= ?";
    $params[] = $monthStart;
}

// Category filtering
if ($currentView === 'categories') {
    // Get unique categories
    $categoriesQuery = $db->query("SELECT DISTINCT 
        CASE 
            WHEN description LIKE '[%]%' THEN SUBSTR(description, 2, INSTR(description, ']') - 2)
            ELSE 'Uncategorized'
        END as category
        FROM cash_transactions 
        WHERE type = 'cash-out'
        ORDER BY category");
    $categories = $categoriesQuery->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch all expenses (cash-out transactions)
$query = "SELECT * FROM cash_transactions $whereClause ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalExpenses = array_sum(array_column($expenses, 'amount'));

// Get today's expenses
$todayExpensesQuery = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND DATE(created_at) = ?");
$todayExpensesQuery->execute([$today]);
$todayExpenses = $todayExpensesQuery->fetchColumn();

// Get this week's expenses
$weekExpensesQuery = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND DATE(created_at) >= ?");
$weekExpensesQuery->execute([$weekStart]);
$weekExpenses = $weekExpensesQuery->fetchColumn();

// Get this month's expenses
$monthExpensesQuery = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'cash-out' AND DATE(created_at) >= ?");
$monthExpensesQuery->execute([$monthStart]);
$monthExpenses = $monthExpensesQuery->fetchColumn();

// Get expense categories summary
$categorySummaryQuery = $db->query("SELECT 
    CASE 
        WHEN description LIKE '[%]%' THEN SUBSTR(description, 2, INSTR(description, ']') - 2)
        ELSE 'Other'
    END as category,
    SUM(amount) as total,
    COUNT(*) as count
    FROM cash_transactions 
    WHERE type = 'cash-out'
    GROUP BY category
    ORDER BY total DESC
    LIMIT 5");
$categorySummary = $categorySummaryQuery->fetchAll(PDO::FETCH_ASSOC);

// Predefined expense categories
$expenseCategories = [
    'Utilities' => 'Electricity, Water, Internet',
    'Supplies' => 'Office supplies, Cleaning supplies',
    'Maintenance' => 'Repairs, Equipment maintenance',
    'Transport' => 'Fuel, Vehicle expenses',
    'Salaries' => 'Staff payments, Wages',
    'Rent' => 'Shop rent, Storage rent',
    'Stock' => 'Inventory purchases',
    'Marketing' => 'Advertising, Promotions',
    'Other' => 'Miscellaneous expenses'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Management</title>
    <script src="navigation.js" async></script>
    <link href="src/output.css" rel="stylesheet">
    <script src="src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="favicon.ico" type="image/png">
    <link rel="stylesheet" href="src/font-awesome/css/all.min.css">
    <script src="sweetalert2@11.js"></script>
    <script src="lucide.js"></script>
    <style>
        * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        
        .fade-in {
            animation: fadeIn 0.5s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Sortable table headers */
        .sortable {
            cursor: pointer;
            user-select: none;
        }
        
        .sortable:hover {
            background-color: #f3f4f6;
        }
        
        .sort-icon {
            display: inline-block;
            margin-left: 4px;
            opacity: 0.3;
        }
        
        .sortable.asc .sort-icon-asc,
        .sortable.desc .sort-icon-desc {
            opacity: 1;
        }
        
        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #14b8a6;
            border-radius: 3px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #0d9488;
        }
        
        /* Stat card hover effect */
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        /* Mobile responsive */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
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
        
        /* Table row hover */
        tbody tr:hover {
            background-color: #f9fafb;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-4 lg:p-6">
                <!-- Header -->
                <div class="sticky top-0 z-40 bg-gray-50 py-4 mb-6 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 -mx-4 lg:-mx-6 px-4 lg:px-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div>
                            <h1 class="text-xl lg:text-2xl font-bold text-gray-900">Expenses</h1>
                            <p class="text-sm text-gray-500">Manage your business expenses</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-3 w-full lg:w-auto">
                        <!-- Add Expense Button -->
                        <button onclick="openAddModal()" 
                                class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-xl hover:from-red-600 hover:to-red-700 transition-all duration-300 shadow-md hover:shadow-lg font-medium">
                            <i data-lucide="plus" class="w-4 h-4"></i>
                            <span>Add Expense</span>
                        </button>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4 fade-in" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($_SESSION['error']) ?></span>
                    <button onclick="this.parentElement.remove()" class="absolute top-0 right-0 px-3 py-1">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-teal-100 border border-teal-400 text-teal-700 px-4 py-3 rounded-lg relative mb-4 fade-in" role="alert">
                    <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success']) ?></span>
                    <button onclick="this.parentElement.remove()" class="absolute top-0 right-0 px-3 py-1">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <?php unset($_SESSION['success']); endif; ?>
                
                <!-- Stats Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card bg-white rounded-xl shadow-sm p-4 border border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                                <i data-lucide="calendar" class="w-5 h-5 text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Today</p>
                                <p class="text-lg font-bold text-red-600">N$<?= number_format($todayExpenses, 2) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card bg-white rounded-xl shadow-sm p-4 border border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center">
                                <i data-lucide="calendar-days" class="w-5 h-5 text-orange-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">This Week</p>
                                <p class="text-lg font-bold text-orange-600">N$<?= number_format($weekExpenses, 2) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card bg-white rounded-xl shadow-sm p-4 border border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                <i data-lucide="calendar-range" class="w-5 h-5 text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">This Month</p>
                                <p class="text-lg font-bold text-purple-600">N$<?= number_format($monthExpenses, 2) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card bg-white rounded-xl shadow-sm p-4 border border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                                <i data-lucide="receipt" class="w-5 h-5 text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Total Records</p>
                                <p class="text-lg font-bold text-gray-700"><?= count($expenses) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Content -->
                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:divide-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                        <div class="relative max-w-xs w-full md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="searchInput" class="py-2 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-slate-900 dark:border-gray-700 dark:text-gray-400 dark:focus:ring-gray-600" placeholder="Search for expenses">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <!-- Filters -->
                                        <div class="flex gap-2 items-center">
                                            <!-- Date Filter -->
                                            <input type="date" id="dateFilter" 
                                                   class="py-2 px-3 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500"
                                                   value="<?= isset($_GET['date']) ? $_GET['date'] : '' ?>">
                                            
                                            <!-- Category Filter -->
                                            <select id="categoryFilter" 
                                                    class="py-2 px-3 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="">All Categories</option>
                                                <?php foreach ($expenseCategories as $cat => $desc): ?>
                                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                            <!-- Export Button -->
                                            <button onclick="exportToCSV()" 
                                                    class="py-2 px-3 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition-colors flex items-center gap-1">
                                                <i data-lucide="download" class="w-4 h-4"></i>
                                                Export
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Table -->
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="expensesTable">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="py-3 px-4 pe-0">
                                                    <div class="flex items-center h-5">
                                                        <input id="selectAll" type="checkbox" 
                                                               class="border-gray-200 rounded text-blue-600 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:checked:bg-blue-500 dark:checked:border-blue-500 dark:focus:ring-offset-gray-800" onchange="toggleAllRows(this)">
                                                        <label for="selectAll" class="sr-only">Select All</label>
                                                    </div>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(0)">
                                                    ID <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(1)">
                                                    Date <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(2)">
                                                    Description <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase">
                                                    Category
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(3)">
                                                    Amount <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="expensesBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php if (empty($expenses)): ?>
                                                <tr>
                                                    <td colspan="7" class="px-6 py-12 text-center">
                                                        <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No expenses found. Add your first expense.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($expenses as $expense): 
                                                    // Extract category from description
                                                    $category = 'Other';
                                                    $description = $expense['description'];
                                                    if (preg_match('/^\[([^\]]+)\]/', $description, $matches)) {
                                                        $category = $matches[1];
                                                        $description = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $description));
                                                    }
                                                ?>
                                                    <tr class="expense-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" 
                                                        data-id="<?= $expense['id'] ?>"
                                                        data-date="<?= date('Y-m-d', strtotime($expense['created_at'])) ?>"
                                                        data-description="<?= htmlspecialchars(strtolower($description)) ?>"
                                                        data-category="<?= htmlspecialchars(strtolower($category)) ?>"
                                                        data-amount="<?= $expense['amount'] ?>">
                                                        <td class="py-3 ps-4" onclick="event.stopPropagation()">
                                                            <div class="flex items-center h-5">
                                                                <input type="checkbox" class="row-checkbox border-gray-200 rounded text-blue-600 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:checked:bg-blue-500 dark:checked:border-blue-500 dark:focus:ring-offset-gray-800" value="<?= $expense['id'] ?>">
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200">#<?= $expense['id'] ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200">
                                                            <div class="flex flex-col">
                                                                <span class="font-medium"><?= date('d M Y', strtotime($expense['created_at'])) ?></span>
                                                                <span class="text-xs text-gray-500"><?= date('H:i', strtotime($expense['created_at'])) ?></span>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-200 max-w-xs">
                                                            <span class="truncate block" title="<?= htmlspecialchars($description) ?>">
                                                                <?= htmlspecialchars($description) ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                                                <?= htmlspecialchars($category) ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600">
                                                            -N$<?= number_format($expense['amount'], 2) ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                                                            <div class="flex items-center justify-end gap-2">
                                                                <button onclick="editExpense(<?= $expense['id'] ?>, '<?= htmlspecialchars(addslashes($expense['description'])) ?>', <?= $expense['amount'] ?>)"
                                                                        class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800 disabled:opacity-50 disabled:pointer-events-none dark:text-blue-500 dark:hover:text-blue-400 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600"
                                                                        title="Edit">
                                                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                                                </button>
                                                                <button onclick="deleteExpense(<?= $expense['id'] ?>)"
                                                                        class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800 disabled:opacity-50 disabled:pointer-events-none dark:text-red-500 dark:hover:text-red-400"
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
                                
                                <!-- Pagination -->
                                <div class="py-1 px-4">
                                    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                                        <div class="text-sm text-gray-700 dark:text-gray-300">
                                            Showing <span id="showingFrom">1</span> to <span id="showingTo"><?= min(10, count($expenses)) ?></span> of <span id="totalRows"><?= count($expenses) ?></span> entries
                                        </div>
                                        <nav class="flex items-center space-x-1" id="paginationNav">
                                            <!-- Pagination buttons will be generated by JavaScript -->
                                        </nav>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Category Summary (shown when view=report) -->
                <?php if ($currentView === 'report' && !empty($categorySummary)): ?>
                <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Expense Categories Summary</h3>
                    <div class="space-y-3">
                        <?php foreach ($categorySummary as $cat): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center">
                                    <i data-lucide="tag" class="w-4 h-4 text-red-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($cat['category']) ?></p>
                                    <p class="text-xs text-gray-500"><?= $cat['count'] ?> transactions</p>
                                </div>
                            </div>
                            <p class="font-semibold text-red-600">N$<?= number_format($cat['total'], 2) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add Expense Modal -->
    <div id="addModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/50" onclick="closeAddModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md relative">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Add New Expense</h3>
                    <button onclick="closeAddModal()" class="absolute top-4 right-4 p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="add_expense" value="1">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="category" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <?php foreach ($expenseCategories as $cat => $desc): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?> - <?= htmlspecialchars($desc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (N$)</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                               placeholder="0.00">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" required rows="3"
                                  class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                  placeholder="Enter expense description..."></textarea>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="button" onclick="closeAddModal()" 
                                class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2.5 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:from-red-600 hover:to-red-700 transition-all font-medium">
                            Add Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Expense Modal -->
    <div id="editModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/50" onclick="closeEditModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md relative">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Edit Expense</h3>
                    <button onclick="closeEditModal()" class="absolute top-4 right-4 p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="edit_id" id="editId">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (N$)</label>
                        <input type="number" name="edit_amount" id="editAmount" step="0.01" min="0.01" required
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="edit_description" id="editDescription" required rows="3"
                                  class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"></textarea>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="button" onclick="closeEditModal()" 
                                class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2.5 bg-gradient-to-r from-teal-500 to-teal-600 text-white rounded-lg hover:from-teal-600 hover:to-teal-700 transition-all font-medium">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            initTable();
        });
        
        // Table state
        let currentPage = 1;
        let perPage = 25;
        let sortColumn = 'date';
        let sortDirection = 'desc';
        let allRows = [];
        let filteredRows = [];
        
        function initTable() {
            const tbody = document.getElementById('expensesBody');
            allRows = Array.from(tbody.querySelectorAll('.expense-row'));
            filteredRows = [...allRows];
            
            // Initialize sorting
            document.querySelectorAll('.sortable').forEach(th => {
                th.addEventListener('click', () => handleSort(th));
            });
            
            // Initialize search
            document.getElementById('searchInput').addEventListener('input', debounce(filterTable, 300));
            
            // Initialize filters
            document.getElementById('dateFilter').addEventListener('change', filterTable);
            document.getElementById('categoryFilter').addEventListener('change', filterTable);
            document.getElementById('perPageSelect').addEventListener('change', function() {
                perPage = parseInt(this.value);
                currentPage = 1;
                renderTable();
            });
            
            // Initialize select all
            document.getElementById('selectAll').addEventListener('change', function() {
                document.querySelectorAll('.row-checkbox').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
            
            // Initialize pagination
            document.getElementById('prevBtn').addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
            
            document.getElementById('nextBtn').addEventListener('click', () => {
                const totalPages = Math.ceil(filteredRows.length / perPage);
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
            
            renderTable();
        }
        
        function handleSort(th) {
            const column = th.dataset.sort;
            
            // Update sort direction
            if (sortColumn === column) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortColumn = column;
                sortDirection = 'asc';
            }
            
            // Update UI
            document.querySelectorAll('.sortable').forEach(el => {
                el.classList.remove('asc', 'desc');
            });
            th.classList.add(sortDirection);
            
            // Sort rows
            filteredRows.sort((a, b) => {
                let aVal, bVal;
                
                switch (column) {
                    case 'id':
                        aVal = parseInt(a.dataset.id);
                        bVal = parseInt(b.dataset.id);
                        break;
                    case 'date':
                        aVal = a.dataset.date;
                        bVal = b.dataset.date;
                        break;
                    case 'description':
                        aVal = a.dataset.description;
                        bVal = b.dataset.description;
                        break;
                    case 'amount':
                        aVal = parseFloat(a.dataset.amount);
                        bVal = parseFloat(b.dataset.amount);
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
                if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
            
            currentPage = 1;
            renderTable();
        }
        
        function filterTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
            const dateFilter = document.getElementById('dateFilter').value;
            const categoryFilter = document.getElementById('categoryFilter').value.toLowerCase();
            
            filteredRows = allRows.filter(row => {
                const description = row.dataset.description || '';
                const category = row.dataset.category || '';
                const date = row.dataset.date || '';
                const id = row.dataset.id || '';
                
                // Search filter
                const matchesSearch = !searchTerm || 
                    description.includes(searchTerm) || 
                    category.includes(searchTerm) ||
                    id.includes(searchTerm);
                
                // Date filter
                const matchesDate = !dateFilter || date === dateFilter;
                
                // Category filter
                const matchesCategory = !categoryFilter || category === categoryFilter;
                
                return matchesSearch && matchesDate && matchesCategory;
            });
            
            currentPage = 1;
            renderTable();
        }
        
        function renderTable() {
            const tbody = document.getElementById('expensesBody');
            const start = (currentPage - 1) * perPage;
            const end = start + perPage;
            const pageRows = filteredRows.slice(start, end);
            
            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');
            
            // Show filtered and paginated rows
            pageRows.forEach(row => row.style.display = '');
            
            // Update pagination info
            document.getElementById('showingStart').textContent = filteredRows.length > 0 ? start + 1 : 0;
            document.getElementById('showingEnd').textContent = Math.min(end, filteredRows.length);
            document.getElementById('totalRecords').textContent = filteredRows.length;
            
            // Update pagination buttons
            const totalPages = Math.ceil(filteredRows.length / perPage);
            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = currentPage >= totalPages;
            
            // Render page numbers
            renderPageNumbers(totalPages);
        }
        
        function renderPageNumbers(totalPages) {
            const container = document.getElementById('pageNumbers');
            container.innerHTML = '';
            
            const maxVisible = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage + 1 < maxVisible) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `min-w-[40px] flex justify-center items-center py-2 text-sm rounded-lg ${
                    i === currentPage 
                        ? 'bg-teal-500 text-white' 
                        : 'text-gray-800 hover:bg-gray-100'
                }`;
                btn.textContent = i;
                btn.addEventListener('click', () => {
                    currentPage = i;
                    renderTable();
                });
                container.appendChild(btn);
            }
        }
        
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').classList.remove('hidden');
        }
        
        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
        }
        
        function editExpense(id, description, amount) {
            document.getElementById('editId').value = id;
            document.getElementById('editDescription').value = description;
            document.getElementById('editAmount').value = amount;
            document.getElementById('editModal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
        
        function deleteExpense(id) {
            Swal.fire({
                title: 'Delete Expense?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="delete_id" value="${id}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function exportToCSV() {
            const rows = [['ID', 'Date', 'Description', 'Category', 'Amount']];
            
            filteredRows.forEach(row => {
                rows.push([
                    row.dataset.id,
                    row.dataset.date,
                    row.dataset.description,
                    row.dataset.category,
                    row.dataset.amount
                ]);
            });
            
            const csv = rows.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `expenses_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
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
        
        // Auto-hide alerts
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    </script>
</body>
</html>

