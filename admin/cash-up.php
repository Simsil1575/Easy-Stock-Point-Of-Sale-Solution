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

// Check activation status with expiration
require_once '../activation_helper.php';
$activationCheck = checkActivationStatus();
if ($activationCheck['status'] === 'not_activated' || $activationCheck['status'] === 'expired') {
    header('Location: settings');
    exit();
}

// Get business closing time from business_info
$businessInfo = [];
try {
    $businessInfoDb = new PDO('sqlite:../info.db');
    $businessInfo = $businessInfoDb->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $closingTime = $businessInfo['closing_time'] ?? '00:00'; // Default to 00:00 if not set
} catch (PDOException $e) {
    // Default closing time if DB error
    $closingTime = '00:00';
}

// Database connection
$db = new PDO('sqlite:../pos.db');
if ($db->errorCode()) {
    die("Connection failed: " . $db->errorInfo()[2]);
}

// Connect to user database to get employee names
$userDb = new PDO('sqlite:../user.db');
$userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Function to get employee name from cashier_id (handles both username and ID)
function getEmployeeName($cashierId, $userDb) {
    if (empty($cashierId)) {
        return 'Unknown Employee';
    }
    
    // First try to find by username (if cashier_id is stored as username)
    try {
        $stmt = $userDb->prepare("SELECT username FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$cashierId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user['username'];
        }
    } catch (PDOException $e) {
        // Continue to try by ID
    }
    
    // Try to find by ID (if cashier_id is stored as ID)
    if (is_numeric($cashierId)) {
        try {
            $stmt = $userDb->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$cashierId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user['username'];
            }
        } catch (PDOException $e) {
            // Return cashier_id as fallback
        }
    }
    
    // Return cashier_id as fallback if no match found
    return $cashierId;
}

// Get selected date from GET parameter, default to today
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Calculate business day boundaries based on closing time
$closingHour = (int)substr($closingTime, 0, 2);
$closingMinute = (int)substr($closingTime, 3, 2);
$isAfterMidnight = $closingHour < 12;

// Calculate next day for business day logic
$nextDay = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch daily sales grouped by employee (cashier_id)
// Using same calculation logic as reports.php: cash = total - eft_payments
$employeeSalesQuery = $db->prepare("
    SELECT 
        o.cashier_id,
        ROUND(SUM(o.total - COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 2) as total_cash,
        ROUND(SUM(COALESCE((SELECT SUM(ep.amount) FROM eft_payments ep WHERE ep.order_id = o.id), 0)), 2) as total_eft,
        ROUND(SUM(o.total), 2) as total_sales
    FROM orders o
    WHERE (
        (DATE(o.created_at) = :selectedDate AND strftime('%H:%M', o.created_at) >= :closingTime) OR
        (DATE(o.created_at) = :nextDay AND strftime('%H:%M', o.created_at) < :closingTime AND " . ($isAfterMidnight ? "1=1" : "1=0") . ")
    )
    AND o.cashier_id IS NOT NULL
    AND o.cashier_id != ''
    GROUP BY o.cashier_id
    HAVING SUM(o.total) > 0
    ORDER BY total_sales DESC
");

$employeeSalesQuery->bindParam(':selectedDate', $selectedDate);
$employeeSalesQuery->bindParam(':nextDay', $nextDay);
$employeeSalesQuery->bindParam(':closingTime', $closingTime);
$employeeSalesQuery->execute();
$employeeSales = $employeeSalesQuery->fetchAll(PDO::FETCH_ASSOC);

// Map employee names to cashier_ids and filter to only cashiers
$employeeNames = [];
$cashierOnlySales = [];
$allCashierIds = [];

// Get all cashier IDs/usernames from users table for faster lookup
try {
    $cashiersQuery = $userDb->query("SELECT id, username FROM users WHERE role = 'cashier'");
    $cashiers = $cashiersQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cashiers as $cashier) {
        $allCashierIds[$cashier['id']] = true;
        $allCashierIds[$cashier['username']] = true;
    }
} catch (PDOException $e) {
    // If query fails, we'll check individually
}

foreach ($employeeSales as $employee) {
    $cashierId = $employee['cashier_id'] ?? 'Unknown';
    if (empty($cashierId) || $cashierId === 'Unknown') {
        continue; // Skip unknown cashiers
    }
    
    // Check if this employee is a cashier
    $isCashier = false;
    
    // Fast check if we have the list
    if (!empty($allCashierIds) && isset($allCashierIds[$cashierId])) {
        $isCashier = true;
    } else {
        // Check individually
        try {
            // Try to find by username first
            $stmt = $userDb->prepare("SELECT role FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$cashierId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && strtolower($user['role']) === 'cashier') {
                $isCashier = true;
            } elseif (!$user && is_numeric($cashierId)) {
                // Try by ID
                $stmt = $userDb->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$cashierId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user && strtolower($user['role']) === 'cashier') {
                    $isCashier = true;
                }
            }
        } catch (PDOException $e) {
            // If we can't check and no cashier list exists, skip (don't assume)
            $isCashier = false;
        }
    }
    
    if ($isCashier) {
        $employeeName = getEmployeeName($cashierId, $userDb);
        $employeeNames[$cashierId] = $employeeName;
        // Ensure amounts are numeric
        $employee['total_cash'] = floatval($employee['total_cash'] ?? 0);
        $employee['total_eft'] = floatval($employee['total_eft'] ?? 0);
        $employee['total_sales'] = floatval($employee['total_sales'] ?? 0);
        $cashierOnlySales[] = $employee;
    }
}

// Replace employeeSales with filtered cashier-only sales
$employeeSales = $cashierOnlySales;

// Calculate grand totals
$grandTotalCash = 0;
$grandTotalEft = 0;
$grandTotalSales = 0;
foreach ($employeeSales as $emp) {
    $grandTotalCash += $emp['total_cash'];
    $grandTotalEft += $emp['total_eft'];
    $grandTotalSales += $emp['total_sales'];
}

// Get all cashiers for filter dropdown
$allEmployees = [];
try {
    $employeesQuery = $userDb->query("SELECT id, username, role FROM users WHERE role = 'cashier' ORDER BY username");
    $allEmployees = $employeesQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If query fails, use cashier_ids from sales data
    foreach ($employeeSales as $emp) {
        $cashierId = $emp['cashier_id'] ?? 'Unknown';
        $allEmployees[] = [
            'id' => $cashierId,
            'username' => $employeeNames[$cashierId] ?? $cashierId,
            'role' => 'cashier'
        ];
    }
}

// Get selected employee filter from GET parameter
$selectedEmployee = isset($_GET['employee']) ? $_GET['employee'] : 'all';

// Filter employee sales if specific employee is selected
if ($selectedEmployee !== 'all') {
    $employeeSales = array_filter($employeeSales, function($emp) use ($selectedEmployee, $employeeNames) {
        $cashierId = $emp['cashier_id'] ?? 'Unknown';
        $employeeName = $employeeNames[$cashierId] ?? $cashierId;
        return $cashierId == $selectedEmployee || $employeeName == $selectedEmployee;
    });
    $employeeSales = array_values($employeeSales); // Re-index array
    
    // Recalculate grand totals for filtered employees
    $grandTotalCash = 0;
    $grandTotalEft = 0;
    $grandTotalSales = 0;
    foreach ($employeeSales as $emp) {
        $grandTotalCash += $emp['total_cash'];
        $grandTotalEft += $emp['total_eft'];
        $grandTotalSales += $emp['total_sales'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Up - Daily Sales by Cashier</title>
    <script src="../navigation.js" async></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <script src="../sweetalert2@11.js"></script>
    <script src="../lucide.js"></script>

    <style>
        * {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            box-sizing: border-box;
        }
        
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            overflow-x: hidden;
            max-width: 100vw;
        }
        
        .container {
            max-width: 100vw;
            padding: 0 1rem;
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
            
            .container {
                padding: 1rem;
            }
        }

        /* Table styles */
        .cashier-row {
            transition: background-color 0.2s;
        }

        .cashier-row:hover {
            background-color: #f9fafb;
        }

        th[onclick] {
            user-select: none;
        }

        th[onclick]:hover {
            background-color: #f3f4f6;
        }

        /* Mobile table responsiveness */
        @media (max-width: 768px) {
            /* Hide table on mobile */
            .table-container {
                display: none;
            }
            
            /* Show card layout on mobile */
            .mobile-cards-container {
                display: block;
            }
            
            .mobile-cashier-card {
                background: white;
                border: 1px solid #e5e7eb;
                border-radius: 0.75rem;
                padding: 1rem;
                margin-bottom: 0.75rem;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
                transition: all 0.3s ease;
                cursor: pointer;
            }
            
            .mobile-cashier-card:active {
                transform: scale(0.98);
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                background: #f9fafb;
            }
            
            .mobile-cashier-card:last-child {
                margin-bottom: 0;
            }
            
            .mobile-cashier-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 0.75rem;
                padding-bottom: 0.75rem;
                border-bottom: 1px solid #f3f4f6;
            }
            
            .mobile-cashier-card-title {
                font-size: 1.125rem;
                font-weight: 600;
                color: #111827;
                flex: 1;
                margin-right: 0.5rem;
            }
            
            .mobile-cashier-card-body {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
                margin-bottom: 0.75rem;
            }
            
            .mobile-cashier-card-field {
                display: flex;
                flex-direction: column;
            }
            
            .mobile-cashier-card-label {
                font-size: 0.75rem;
                color: #6b7280;
                text-transform: uppercase;
                font-weight: 500;
                margin-bottom: 0.25rem;
                letter-spacing: 0.05em;
            }
            
            .mobile-cashier-card-value {
                font-size: 0.875rem;
                color: #111827;
                font-weight: 500;
            }
            
            .mobile-cashier-card-total {
                font-size: 1.125rem;
                font-weight: 700;
            }
            
            .mobile-cashier-card-actions {
                display: flex;
                gap: 0.5rem;
                padding-top: 0.75rem;
                border-top: 1px solid #f3f4f6;
                flex-wrap: wrap;
            }
            
            .mobile-cashier-card-actions a,
            .mobile-cashier-card-actions button {
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
            
            .mobile-cashier-card-actions a i,
            .mobile-cashier-card-actions button i {
                width: 18px;
                height: 18px;
            }
            
            .mobile-cashier-card-full-width {
                grid-column: 1 / -1;
            }
            
            .overflow-x-auto {
                -webkit-overflow-scrolling: touch;
            }
        }

        @media (min-width: 769px) {
            /* Hide mobile cards on desktop */
            .mobile-cards-container {
                display: none;
            }
            
            /* Show table on desktop */
            .table-container {
                display: block;
            }
        }
        
        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
        }
    </style>
</head>
<body class="bg-gray-50" style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <!-- Header Row: Daily Cash Up Report + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm no-print">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Daily Cash Up Report</h1>
                    </div>
                    
                    <!-- Date Selection and Print Buttons -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <div class="flex items-center gap-2">
                            <label for="dateSelect" class="text-sm font-medium text-gray-700 hidden sm:inline">Date:</label>
                            <input 
                                type="date" 
                                id="dateSelect" 
                                value="<?php echo htmlspecialchars($selectedDate); ?>" 
                                class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                onchange="updateFilters()"
                            >
                        </div>
                        <button 
                            onclick="window.print()" 
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors flex items-center gap-2 shadow-sm"
                        >
                            <i class="fas fa-print"></i>
                            <span class="hidden sm:inline">Print</span>
                        </button>
                    </div>
                </div>

                <!-- Summary Cards Section -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 no-print">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase">Cash Sales</p>
                                <h3 class="text-xl font-bold text-teal-600">N$<?php echo number_format($grandTotalCash, 2); ?></h3>
                            </div>
                            <div class="p-2 bg-teal-100 rounded-full">
                                <i class="fas fa-money-bill-wave text-teal-600"></i>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase">EFT Sales</p>
                                <h3 class="text-xl font-bold text-purple-600">N$<?php echo number_format($grandTotalEft, 2); ?></h3>
                            </div>
                            <div class="p-2 bg-purple-100 rounded-full">
                                <i class="fas fa-credit-card text-purple-600"></i>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase">Total Sales</p>
                                <h3 class="text-xl font-bold text-gray-800">N$<?php echo number_format($grandTotalSales, 2); ?></h3>
                            </div>
                            <div class="p-2 bg-gray-100 rounded-full">
                                <i class="fas fa-wallet text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cashiers Table -->
                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4 no-print">
                                    <div class="flex flex-col gap-3 md:flex-row md:gap-4 items-stretch md:items-center justify-between">
                                        <div class="relative w-full md:max-w-xs md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="searchInput" class="py-2.5 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-teal-500 focus:ring-teal-500" placeholder="Search cashiers...">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <!-- Employee Filter -->
                                        <div class="flex gap-2 items-stretch md:items-center">
                                            <select id="employeeFilter" class="flex-1 md:flex-none py-2.5 px-3 border border-gray-200 rounded-lg text-sm focus:border-teal-500 focus:ring-teal-500" onchange="updateFilters()">
                                                <option value="all" <?php echo $selectedEmployee === 'all' ? 'selected' : ''; ?>>All Cashiers</option>
                                                <?php foreach ($allEmployees as $emp): 
                                                    $empId = $emp['id'] ?? $emp['username'] ?? '';
                                                    $empName = $emp['username'] ?? $empId;
                                                    $isSelected = ($selectedEmployee == $empId || $selectedEmployee == $empName);
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($empId); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($empName); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Desktop Table -->
                                <div class="table-container overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(0)">
                                                    Cashier <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(1)">
                                                    Cash Sales <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(2)">
                                                    EFT Sales <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(3)">
                                                    Total Sales <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="cashiersTableBody" class="divide-y divide-gray-200">
                                            <?php if (empty($employeeSales)): ?>
                                                <tr>
                                                    <td colspan="5" class="px-6 py-12 text-center">
                                                        <i data-lucide="users" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No cashier sales found for the selected date.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($employeeSales as $employee): 
                                                    $cashierId = $employee['cashier_id'] ?? 'Unknown';
                                                    $employeeName = $employeeNames[$cashierId] ?? getEmployeeName($cashierId, $userDb);
                                                ?>
                                                    <tr class="cashier-row hover:bg-gray-50 transition-colors cursor-pointer" 
                                                        data-cashier-id="<?= htmlspecialchars($cashierId) ?>"
                                                        data-cashier-name="<?= htmlspecialchars(strtolower($employeeName)) ?>"
                                                        data-cash="<?= $employee['total_cash'] ?>"
                                                        data-eft="<?= $employee['total_eft'] ?>"
                                                        data-total="<?= $employee['total_sales'] ?>"
                                                        onclick="handleRowClick(event, '<?= htmlspecialchars($cashierId) ?>')">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="flex items-center">
                                                                <div class="h-10 w-10 flex-shrink-0 rounded-full bg-teal-100 flex items-center justify-center">
                                                                    <span class="text-teal-600 font-semibold text-sm"><?= strtoupper(substr($employeeName, 0, 2)) ?></span>
                                                                </div>
                                                                <div class="ml-4">
                                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($employeeName) ?></div>
                                                                    <div class="text-xs text-gray-500">ID: <?= htmlspecialchars($cashierId) ?></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-teal-600">
                                                            N$<?= number_format($employee['total_cash'], 2) ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-purple-600">
                                                            N$<?= number_format($employee['total_eft'], 2) ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                                            N$<?= number_format($employee['total_sales'], 2) ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium" onclick="event.stopPropagation()">
                                                            <div class="flex items-center justify-end gap-2">
                                                                <a href="view-cashup.php?cashier=<?= urlencode($cashierId) ?>&date=<?= htmlspecialchars($selectedDate) ?>" 
                                                                   class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800"
                                                                   title="View Details">
                                                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                                                </a>
                                                                <button onclick="printCashierReport('<?= htmlspecialchars($cashierId) ?>', '<?= htmlspecialchars($employeeName, ENT_QUOTES) ?>')"
                                                                    class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-gray-600 hover:text-gray-800"
                                                                    title="Print Report">
                                                                    <i data-lucide="printer" class="w-4 h-4"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if (!empty($employeeSales)): ?>
                                        <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">Grand Total</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-teal-600">N$<?= number_format($grandTotalCash, 2) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-purple-600">N$<?= number_format($grandTotalEft, 2) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">N$<?= number_format($grandTotalSales, 2) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap"></td>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                
                                <!-- Mobile Cards -->
                                <div id="mobileCardsContainer" class="mobile-cards-container px-3 md:px-4 pb-4">
                                    <?php if (empty($employeeSales)): ?>
                                        <div class="text-center py-12">
                                            <i data-lucide="users" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                            <p class="text-gray-500 text-lg">No cashier sales found for the selected date.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach($employeeSales as $employee): 
                                            $cashierId = $employee['cashier_id'] ?? 'Unknown';
                                            $employeeName = $employeeNames[$cashierId] ?? getEmployeeName($cashierId, $userDb);
                                        ?>
                                            <div class="mobile-cashier-card" 
                                                 data-cashier-id="<?= htmlspecialchars($cashierId) ?>"
                                                 data-cashier-name="<?= htmlspecialchars(strtolower($employeeName)) ?>"
                                                 data-cash="<?= $employee['total_cash'] ?>"
                                                 data-eft="<?= $employee['total_eft'] ?>"
                                                 data-total="<?= $employee['total_sales'] ?>"
                                                 onclick="handleMobileCardClick(event, '<?= htmlspecialchars($cashierId) ?>')">
                                                <div class="mobile-cashier-card-header">
                                                    <div class="flex items-center gap-3">
                                                        <div class="h-10 w-10 flex-shrink-0 rounded-full bg-teal-100 flex items-center justify-center">
                                                            <span class="text-teal-600 font-semibold text-sm"><?= strtoupper(substr($employeeName, 0, 2)) ?></span>
                                                        </div>
                                                        <div class="mobile-cashier-card-title"><?= htmlspecialchars($employeeName) ?></div>
                                                    </div>
                                                </div>
                                                <div class="mobile-cashier-card-body">
                                                    <div class="mobile-cashier-card-field">
                                                        <div class="mobile-cashier-card-label">Cash Sales</div>
                                                        <div class="mobile-cashier-card-value text-teal-600 font-semibold">
                                                            N$<?= number_format($employee['total_cash'], 2) ?>
                                                        </div>
                                                    </div>
                                                    <div class="mobile-cashier-card-field">
                                                        <div class="mobile-cashier-card-label">EFT Sales</div>
                                                        <div class="mobile-cashier-card-value text-purple-600 font-semibold">
                                                            N$<?= number_format($employee['total_eft'], 2) ?>
                                                        </div>
                                                    </div>
                                                    <div class="mobile-cashier-card-field mobile-cashier-card-full-width">
                                                        <div class="mobile-cashier-card-label">Total Sales</div>
                                                        <div class="mobile-cashier-card-value mobile-cashier-card-total text-gray-900">
                                                            N$<?= number_format($employee['total_sales'], 2) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mobile-cashier-card-actions" onclick="event.stopPropagation()">
                                                    <a href="view-cashup.php?cashier=<?= urlencode($cashierId) ?>&date=<?= htmlspecialchars($selectedDate) ?>" 
                                                       class="bg-blue-50 text-blue-600 hover:bg-blue-100">
                                                        <i data-lucide="eye" class="w-4 h-4 mx-auto"></i>
                                                        <span class="block mt-1">View</span>
                                                    </a>
                                                    <button onclick="printCashierReport('<?= htmlspecialchars($cashierId) ?>', '<?= htmlspecialchars($employeeName, ENT_QUOTES) ?>')"
                                                        class="bg-gray-50 text-gray-600 hover:bg-gray-100">
                                                        <i data-lucide="printer" class="w-4 h-4 mx-auto"></i>
                                                        <span class="block mt-1">Print</span>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <!-- Mobile Grand Total Card -->
                                        <div class="mobile-cashier-card bg-gray-50 border-2 border-gray-300 mt-4">
                                            <div class="mobile-cashier-card-header border-b-2 border-gray-300">
                                                <div class="mobile-cashier-card-title text-gray-800">Grand Total</div>
                                            </div>
                                            <div class="mobile-cashier-card-body">
                                                <div class="mobile-cashier-card-field">
                                                    <div class="mobile-cashier-card-label">Cash Sales</div>
                                                    <div class="mobile-cashier-card-value text-teal-600 font-bold">
                                                        N$<?= number_format($grandTotalCash, 2) ?>
                                                    </div>
                                                </div>
                                                <div class="mobile-cashier-card-field">
                                                    <div class="mobile-cashier-card-label">EFT Sales</div>
                                                    <div class="mobile-cashier-card-value text-purple-600 font-bold">
                                                        N$<?= number_format($grandTotalEft, 2) ?>
                                                    </div>
                                                </div>
                                                <div class="mobile-cashier-card-field mobile-cashier-card-full-width">
                                                    <div class="mobile-cashier-card-label">Total Sales</div>
                                                    <div class="mobile-cashier-card-value mobile-cashier-card-total text-gray-900">
                                                        N$<?= number_format($grandTotalSales, 2) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Pagination -->
                                <div class="py-3 px-4 no-print">
                                    <div class="flex flex-col md:flex-row items-center justify-between gap-3 md:gap-4">
                                        <div class="text-xs md:text-sm text-gray-700 text-center md:text-left">
                                            Showing <span id="showingFrom" class="font-semibold">1</span> to <span id="showingTo" class="font-semibold"><?= count($employeeSales) ?></span> of <span id="totalRows" class="font-semibold"><?= count($employeeSales) ?></span> cashiers
                                        </div>
                                        <nav class="flex items-center justify-center flex-wrap gap-1" id="paginationNav">
                                            <!-- Pagination buttons will be generated by JavaScript if needed -->
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

    <?php
    // Fetch business info for Android printing
    $dbInfo = new PDO('sqlite:../info.db');
    $businessInfo = $dbInfo->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => '',
            'phone' => '',
            'footer_text' => 'Thank you!',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    ?>

    <script>
        // Business info for Android printing
        var businessInfo = {
            business_name: <?= json_encode($businessInfo['name'] ?? 'POS SOLUTION') ?>,
            location: <?= json_encode($businessInfo['location'] ?? '') ?>,
            phone: <?= json_encode($businessInfo['phone'] ?? '') ?>,
            footer_text: <?= json_encode($businessInfo['footer_text'] ?? 'Thank you!') ?>,
            vat_inclusive: <?= json_encode($businessInfo['vat_inclusive'] ?? 'exclusive') ?>,
            vat_rate: <?= json_encode(floatval($businessInfo['vat_rate'] ?? 15.0)) ?>
        };

        // Helper function to send receipt to printer - uses Android native printing if available
        function sendToPrinter(receiptData) {
            var dataWithBusiness = Object.assign({}, receiptData, {
                business_name: receiptData.business_name || businessInfo.business_name,
                location: receiptData.location || businessInfo.location,
                phone: receiptData.phone || businessInfo.phone,
                footer_text: receiptData.footer_text || businessInfo.footer_text,
                vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                vat_rate: receiptData.vat_rate || businessInfo.vat_rate
            });
            
            var printer = window.AndroidPrinter || window.NativePrinter || null;
            
            if (printer && typeof printer.printReceipt === 'function') {
                console.log('[sendToPrinter] Using Android native printing');
                try {
                    printer.printReceipt(JSON.stringify(dataWithBusiness));
                    return Promise.resolve({ success: true, message: 'Printed via Android', printer_type: 'android_native' });
                } catch (e) {
                    console.error('[sendToPrinter] Android print error:', e.message);
                }
            }
            
            return fetch('../receipt.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dataWithBusiness)
            }).then(function(r) { return r.json(); });
        }

        // Table management variables
        let currentPage = 1;
        let rowsPerPage = 10;
        let currentSortColumn = -1;
        let sortDirection = 'asc';
        let allRows = [];
        let allMobileCards = [];
        let filteredRows = [];
        let filteredMobileCards = [];
        const selectedDate = '<?php echo htmlspecialchars($selectedDate); ?>';

        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Get all table rows
            const tableBody = document.getElementById('cashiersTableBody');
            allRows = Array.from(tableBody.querySelectorAll('.cashier-row'));
            filteredRows = [...allRows];

            // Get all mobile cards
            const mobileContainer = document.getElementById('mobileCardsContainer');
            if (mobileContainer) {
                allMobileCards = Array.from(mobileContainer.querySelectorAll('.mobile-cashier-card:not(.bg-gray-50)'));
                filteredMobileCards = [...allMobileCards];
            }

            // Initialize table
            initializeTable();

            // Search functionality
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    filterTable();
                });
            }
        });

        // Initialize table with pagination
        function initializeTable() {
            filterTable();
        }

        // Filter table based on search
        function filterTable() {
            const searchInput = document.getElementById('searchInput');
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';

            // Filter function for both rows and cards
            const filterItem = (item) => {
                const cashierName = item.getAttribute('data-cashier-name') || '';
                const cashierId = item.getAttribute('data-cashier-id') || '';

                return searchTerm === '' || 
                    cashierName.includes(searchTerm) || 
                    cashierId.toLowerCase().includes(searchTerm);
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
                    case 0: // Cashier Name
                        aValue = a.getAttribute('data-cashier-name') || '';
                        bValue = b.getAttribute('data-cashier-name') || '';
                        break;
                    case 1: // Cash Sales
                        aValue = parseFloat(a.getAttribute('data-cash') || 0);
                        bValue = parseFloat(b.getAttribute('data-cash') || 0);
                        break;
                    case 2: // EFT Sales
                        aValue = parseFloat(a.getAttribute('data-eft') || 0);
                        bValue = parseFloat(b.getAttribute('data-eft') || 0);
                        break;
                    case 3: // Total Sales
                        aValue = parseFloat(a.getAttribute('data-total') || 0);
                        bValue = parseFloat(b.getAttribute('data-total') || 0);
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
            const tableBody = document.getElementById('cashiersTableBody');
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
                    tableBody.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center"><i data-lucide="users" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No cashiers found matching your criteria.</p></td></tr>';
                } else {
                    pageRows.forEach(row => {
                        const clonedRow = row.cloneNode(true);
                        tableBody.appendChild(clonedRow);
                    });
                }
            }

            // Render mobile cards (excluding grand total card)
            if (mobileContainer) {
                // Save the grand total card if it exists
                const grandTotalCard = mobileContainer.querySelector('.mobile-cashier-card.bg-gray-50');
                
                mobileContainer.innerHTML = '';

                if (pageCards.length === 0) {
                    mobileContainer.innerHTML = '<div class="text-center py-12"><i data-lucide="users" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No cashiers found matching your criteria.</p></div>';
                } else {
                    pageCards.forEach(card => {
                        const clonedCard = card.cloneNode(true);
                        mobileContainer.appendChild(clonedCard);
                    });
                    
                    // Re-add grand total card at the end
                    if (grandTotalCard) {
                        mobileContainer.appendChild(grandTotalCard.cloneNode(true));
                    }
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
            prevButton.className = 'p-2.5 min-w-[40px] min-h-[40px] inline-flex items-center justify-center text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none';
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
                    pageButton.className = `min-w-[40px] min-h-[40px] flex justify-center items-center text-gray-800 hover:bg-gray-100 py-2.5 text-sm rounded-full ${i === currentPage ? 'bg-teal-100 text-teal-600 font-semibold' : ''}`;
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
            nextButton.className = 'p-2.5 min-w-[40px] min-h-[40px] inline-flex items-center justify-center text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none';
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

        // Handle row click - navigate to view-cashup.php
        function handleRowClick(event, cashierId) {
            if (event.target.closest('a, button')) {
                return;
            }
            window.location.href = `view-cashup.php?cashier=${encodeURIComponent(cashierId)}&date=${selectedDate}`;
        }

        // Handle mobile card click
        function handleMobileCardClick(event, cashierId) {
            if (event.target.closest('a, button, .mobile-cashier-card-actions')) {
                return;
            }
            window.location.href = `view-cashup.php?cashier=${encodeURIComponent(cashierId)}&date=${selectedDate}`;
        }

        // Update filters and reload page
        function updateFilters() {
            const date = document.getElementById('dateSelect').value;
            const employee = document.getElementById('employeeFilter').value;
            const params = new URLSearchParams();
            params.set('date', date);
            if (employee !== 'all') {
                params.set('employee', employee);
            }
            window.location.href = 'cash-up?' + params.toString();
        }

        // Print cashier report via thermal printer
        async function printCashierReport(cashierId, cashierName) {
            // Find the cashier row to get the data
            const cashierRow = document.querySelector(`.cashier-row[data-cashier-id="${cashierId}"]`) || 
                               document.querySelector(`.mobile-cashier-card[data-cashier-id="${cashierId}"]`);
            
            if (!cashierRow) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Could not find cashier data'
                });
                return;
            }
            
            const cashSales = parseFloat(cashierRow.getAttribute('data-cash') || 0);
            const eftSales = parseFloat(cashierRow.getAttribute('data-eft') || 0);
            const totalSales = parseFloat(cashierRow.getAttribute('data-total') || 0);
            
            try {
                Swal.fire({
                    title: 'Printing...',
                    text: 'Sending to receipt printer',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Prepare receipt data
                const receiptData = {
                    is_cashup_report: true,
                    date: selectedDate,
                    cashier_username: cashierName,
                    employees: [{
                        name: cashierName,
                        cash_sales: cashSales,
                        eft_sales: eftSales,
                        total_sales: totalSales
                    }],
                    total_cash_sales: cashSales,
                    total_eft_sales: eftSales,
                    grand_total: totalSales,
                    cash_sales: cashSales,
                    eft_sales: eftSales
                };
                
                // Send to printer (Android native or server)
                const result = await sendToPrinter(receiptData);
                
                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Receipt Printed',
                        text: `Cash-up receipt for ${cashierName} printed successfully!`,
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
