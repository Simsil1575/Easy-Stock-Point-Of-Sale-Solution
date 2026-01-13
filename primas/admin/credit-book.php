<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../../");
    exit();
}

// Check activation status
$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}
?>


<?php
// New SQLite connection
$db = new PDO('sqlite:../pos.db');



// Handle POST requests for adding/updating/deleting credit records
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        // Check creditor balance first
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total_amount - paid_amount), 0) AS balance 
            FROM credit_sales 
            WHERE creditor_id = ?
        ");
        $stmt->execute([$_POST['delete_id']]);
        $balance = $stmt->fetchColumn();

        if ($balance > 0) {
            $_SESSION['error'] = 'Cannot delete creditor with outstanding balance';
            header('Location: credit-book.php');
            exit();
        }

        // Handle deletion if balance is zero
        $stmt = $db->prepare("DELETE FROM creditors WHERE id = ?");
        $stmt->execute([$_POST['delete_id']]);
        $_SESSION['success'] = 'Creditor deleted successfully';
        header('Location: credit-book');
        exit();
    } else {
        // Handle add/edit
        $name = $_POST['name'];
        $phone = $_POST['phone'];
        $active = 1;

        if (empty($_POST['id'])) {
            // Add new creditor
            $stmt = $db->prepare("INSERT INTO creditors (name, phone, active) VALUES (?, ?, ?)");
            $stmt->execute([$name, $phone, $active]);
        } else {
            // Update creditor
            $stmt = $db->prepare("UPDATE creditors SET name = ?, phone = ?, active = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $active, $_POST['id']]);
        }
        header('Location: credit-book');
        exit();
    }
}

// Fetch all credit records
$creditors = $db->query("SELECT * FROM creditors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Update the credit sales query to group by creditor and show totals
$creditSales = $db->query("
    SELECT 
        creditors.id AS creditor_id,
        creditors.name AS creditor_name,
        SUM(credit_sales.total_amount) as total_amount,
        SUM(credit_sales.paid_amount) as paid_amount,
        MAX(credit_sales.due_date) as latest_due_date,
        COUNT(*) as total_transactions
    FROM credit_sales 
    LEFT JOIN creditors ON credit_sales.creditor_id = creditors.id
    GROUP BY credit_sales.creditor_id
    ORDER BY total_amount - paid_amount DESC
")->fetchAll(PDO::FETCH_ASSOC);

$salesByCreditor = [];
foreach ($creditSales as $sale) {
    $salesByCreditor[$sale['creditor_id']] = $sale;
}

// Fetch upcoming due credit sales (within 7 days)
$dueSoonStmt = $db->prepare("
    SELECT creditors.id, creditors.name, credit_sales.due_date, 
           (credit_sales.total_amount - credit_sales.paid_amount) as remaining
    FROM credit_sales
    JOIN creditors ON credit_sales.creditor_id = creditors.id
    WHERE credit_sales.payment_status = 'unpaid'
    AND credit_sales.due_date BETWEEN DATE('now') AND DATE('now', '+7 days')
    ORDER BY credit_sales.due_date ASC
");
$dueSoonStmt->execute();
$dueSoonSales = $dueSoonStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unpaid creditors (balance > 0)
$unpaidCreditors = [];
foreach ($salesByCreditor as $creditorId => $sale) {
    $balance = $sale['total_amount'] - $sale['paid_amount'];
    if ($balance > 0) {
        $unpaidCreditors[] = [
            'id' => $creditorId,
            'name' => $sale['creditor_name'],
            'balance' => $balance
        ];
    }
}

$notificationCount = count($unpaidCreditors) + count($dueSoonSales);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creditor Management</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
   

    <style>
        .fade-in {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .search-input {
            transition: all 0.3s ease;
        }
        .search-input:focus {
            box-shadow: 0 0 8px rgba(79, 70, 229, 0.2);
        }
        /* Add consistent row height styles */
        table tbody tr {
            height: 48px; /* Fixed height for all rows */
            line-height: 1.5;
        }
        /* Clickable row styles */
        table tbody tr.creditor-row {
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }
        table tbody tr.creditor-row:hover {
            background-color: #f3f4f6 !important;
            transform: translateX(2px);
        }
        table tbody tr.creditor-row:active {
            transform: translateX(0);
            background-color: #e5e7eb !important;
        }
        table tbody td {
            padding: 8px 12px;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Ensure action buttons are properly aligned */
        table tbody td:last-child {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        /* Style for the no-data row */
        table tbody tr td[colspan] {
            height: 48px;
            line-height: 48px;
            text-align: center;
        }
        
        .container {
            max-width: 100vw; /* Ensure container does not exceed viewport width */
            padding: 0 1rem; /* Add some padding for better spacing */
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
        
        /* Mobile Vertical Table Structure */
        @media (max-width: 768px) {
            .overflow-x-auto {
                overflow: visible;
                background: #f3f4f6;
            }
            
            /* Hide table headers on mobile */
            table thead {
                display: none;
            }
            
            /* Convert table rows to compact cards */
            table tbody tr {
                display: block;
                width: 100%;
                margin-bottom: 0.75rem;
                background: white;
                border: 2px solid #d1d5db;
                border-radius: 0.375rem;
                padding: 0.5rem;
                box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
                height: auto !important;
                position: relative;
            }
            
            /* Convert table cells to compact inline blocks */
            table tbody td {
                display: flex;
                align-items: center;
                width: 100% !important;
                padding: 0.375rem 0.25rem !important;
                text-align: left !important;
                border: none !important;
                border-bottom: 1px solid #f3f4f6 !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: unset !important;
                height: auto !important;
                line-height: 1.3 !important;
                gap: 0.5rem;
                font-size: 0.8rem !important;
                color: #111827;
            }
            
            /* Remove border from last visible cell in each row */
            table tbody td:last-child:not([data-label="Actions"]) {
                border-bottom: none !important;
            }
            
            /* If Actions is the last child, remove border from the cell before it */
            table tbody tr td[data-label="Actions"]:last-child {
                border-bottom: none !important;
            }
            
            table tbody tr td[data-label="Actions"]:last-child + td,
            table tbody tr td:nth-last-child(2):not([data-label="Actions"]) {
                border-bottom: none !important;
            }
            
            /* Add labels inline with data using CSS */
            table tbody td::before {
                content: attr(data-label) ":";
                display: inline-block;
                font-weight: 600;
                font-size: 0.7rem;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.025em;
                min-width: 4rem;
                flex-shrink: 0;
            }
            
            /* Center align specific cells */
            table tbody td[data-label="ID"] {
                justify-content: flex-start;
            }
            
            /* Actions cell special handling - positioned like Print button in reports.php */
            table tbody td[data-label="Actions"] {
                position: absolute;
                top: 0.5rem;
                right: 0.5rem;
                width: auto !important;
                padding: 0 !important;
                border: none !important;
                justify-content: flex-end;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            table tbody td[data-label="Actions"]::before {
                display: none; /* Hide label for Actions column */
            }
            
            /* Actions button styling on mobile - match print button style */
            table tbody td[data-label="Actions"] > div {
                display: flex;
                gap: 0.5rem;
                align-items: center;
            }
            
            table tbody td[data-label="Actions"] a,
            table tbody td[data-label="Actions"] button {
                width: auto;
                height: 2rem;
                min-height: 2rem;
                padding: 0.375rem 0.625rem;
                justify-content: center;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.15);
                border: 1px solid #9ca3af;
                display: inline-flex;
                align-items: center;
                border-radius: 0.375rem;
                font-size: 0.75rem;
            }
            
            /* Quick Actions cell special handling */
            table tbody td[data-label="Quick Actions"] {
                padding: 0.375rem 0.25rem !important;
                align-items: flex-start;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            table tbody td[data-label="Quick Actions"]::before {
                display: none; /* Hide label for Quick Actions column */
            }
            
            /* Remove hover effect on mobile cards */
            table tbody tr:hover {
                background: white;
            }
            
            /* Mobile Pagination - Fit in one row */
            .bg-gray-50.border-t {
                padding: 0.5rem 0.375rem !important;
                overflow-x: visible !important;
            }
            
            .bg-gray-50.border-t > div {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                align-items: center !important;
                width: 100% !important;
                min-width: 0 !important;
                overflow: visible !important;
            }
            
            /* Ensure parent containers don't restrict pagination */
            .bg-white.shadow-md {
                overflow-x: visible !important;
            }
            
            /* Compact button groups */
            .bg-gray-50.border-t > div > div {
                display: flex !important;
                gap: 0.25rem !important;
                flex-shrink: 0;
            }
            
            /* Left button group - first and prev */
            .bg-gray-50.border-t > div > div:first-child {
                flex-shrink: 0;
            }
            
            /* Right button group - next and last */
            .bg-gray-50.border-t > div > div:last-child {
                flex-shrink: 0;
            }
            
            /* First/Last buttons - icon only, smaller */
            .bg-gray-50.border-t button#firstPage,
            .bg-gray-50.border-t button#lastPage {
                padding: 0.375rem !important;
                min-width: 2rem !important;
                width: 2rem !important;
            }
            
            .bg-gray-50.border-t button#firstPage svg,
            .bg-gray-50.border-t button#lastPage svg {
                width: 1rem !important;
                height: 1rem !important;
                margin: 0 !important;
            }
            
            /* Prev/Next buttons - compact text */
            .bg-gray-50.border-t button#prevPage,
            .bg-gray-50.border-t button#nextPage {
                padding: 0.375rem 0.4rem !important;
                font-size: 0.65rem !important;
                min-width: auto !important;
                white-space: nowrap;
            }
            
            .bg-gray-50.border-t button#prevPage svg,
            .bg-gray-50.border-t button#nextPage svg {
                width: 0.875rem !important;
                height: 0.875rem !important;
            }
            
            /* Center section - compact and flexible */
            .bg-gray-50.border-t > div > div:nth-child(2) {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                flex-shrink: 1;
                min-width: 0;
                max-width: 100%;
                overflow: hidden;
            }
            
            /* Page number text - smaller and compact */
            .bg-gray-50.border-t span[id*="PageNumber"] {
                font-size: 0.65rem !important;
                white-space: nowrap;
                flex-shrink: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 5rem;
            }
            
            /* Page input - compact */
            .bg-gray-50.border-t input[type="number"] {
                width: 2.5rem !important;
                padding: 0.375rem 0.375rem !important;
                font-size: 0.65rem !important;
                min-width: 2.5rem;
                max-width: 2.5rem;
            }
            
            /* Go button - compact */
            .bg-gray-50.border-t input[type="number"] + button,
            .bg-gray-50.border-t button#goToPage {
                padding: 0.375rem 0.5rem !important;
                font-size: 0.65rem !important;
                white-space: nowrap;
            }
            
            /* All pagination buttons - consistent height */
            .bg-gray-50.border-t button {
                height: 2rem !important;
                min-height: 2rem !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <!-- Header Row: Creditor Management + Controls -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold mb-0">Creditor Management</h1>
                    </div>
                    
                    <!-- Right side: Notification Icon -->
                    <div class="relative cursor-pointer ml-auto">
                        <svg onclick="toggleCreditorNotifications()" class="h-6 w-6 text-gray-400 hover:text-gray-500 transition-colors duration-200" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <?php if ($notificationCount > 0): ?>
                            <span class="absolute top-1 right-1 -mt-2 -mr-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center pointer-events-none"><?= $notificationCount ?></span>
                        <?php endif; ?>
                        
                        <!-- Notifications Dropdown -->
                        <div id="creditorNotificationsDropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-2xl shadow-2xl z-50 transform transition-all duration-300 opacity-0 scale-95 border border-gray-100 max-h-[80vh] overflow-y-auto custom-scrollbar">
                            <?php if (empty($unpaidCreditors) && empty($dueSoonSales)): ?>
                                <div class="p-6 text-center">
                                    <div class="mx-auto w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                        </svg>
                                    </div>
                                    <p class="text-gray-500 font-medium">No notifications</p>
                                    <p class="text-gray-400 text-sm mt-1">All credits are in good standing</p>
                                </div>
                            <?php else: ?>
                                <?php if (!empty($unpaidCreditors)): ?>
                                    <div class="p-4 border-b border-gray-100 hover:bg-red-50 transition-colors duration-200">
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
                                                    <h3 class="text-sm font-semibold text-red-900">Unpaid Balances</h3>
                                                        <span class="text-xs font-medium text-red-500 bg-red-50 px-2 py-1 rounded-full"><?= count($unpaidCreditors) ?></span>
                                                </div>
                                                <div class="mt-2 space-y-2">
                                                    <?php foreach($unpaidCreditors as $creditor): ?>
                                                        <div class="flex items-center text-sm">
                                                            <svg class="w-4 h-4 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                            <a href="credit-transactions.php?creditor_id=<?= $creditor['id'] ?>" class="text-red-700 hover:text-gray-600 transition-colors flex items-center justify-between w-full">
                                                                <span class="font-medium text-red-800"><?= htmlspecialchars($creditor['name']) ?></span>
                                                                <span class="ml-2 px-2 py-1 bg-red-50 text-red-600 text-xs font-semibold rounded-full">
                                                                    N$<?= number_format($creditor['balance'], 2) ?>
                                                                </span>
                                                            </a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($dueSoonSales)): ?>
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
                                                    <h3 class="text-sm font-semibold text-gray-900">Upcoming Due Dates</h3>
                                                        <span class="text-xs font-medium text-yellow-500 bg-yellow-50 px-2 py-1 rounded-full"><?= count($dueSoonSales) ?></span>
                                                </div>
                                                <div class="mt-2 space-y-2">
                                                    <?php foreach($dueSoonSales as $sale): ?>
                                                        <div class="flex items-center justify-between text-sm">
                                                            <div class="flex items-center">
                                                                <svg class="w-4 h-4 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                                </svg>
                                                                <a href="credit-transactions.php?creditor_id=<?= $sale['id'] ?>" class="text-gray-700 hover:text-gray-600 transition-colors">
                                                                    <?= htmlspecialchars($sale['name']) ?>
                                                                </a>
                                                            </div>
                                                            <span class="text-yellow-600 font-medium">
                                                                Due <?= date('M j', strtotime($sale['due_date'])) ?> (N$<?= number_format($sale['remaining'], 2) ?>)
                                                            </span>
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
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-gray-100 border border-gray-400 text-gray-700 px-4 py-3 rounded relative mb-4 z-20" role="alert">
                    <span class="block sm:inline"><?= $_SESSION['error'] ?></span>
                    <button onclick="this.parentElement.remove()" class="absolute top-0 right-0 px-3 py-1">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <!-- Creditor Account Form -->
                <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 mb-8 border border-gray-100 overflow-hidden">
                    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-6 pb-4 border-b border-gray-200">
                        <h2 class="text-xl md:text-2xl font-bold text-gray-800 flex items-center">
                            <svg class="w-5 h-5 md:w-6 md:h-6 mr-2 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <span class="break-words">Account Management</span>
                        </h2>
                        <!-- Submit Button moved to top right -->
                        <button type="submit" form="creditorForm"
                            class="bg-gradient-to-r from-gray-600 to-gray-700 text-white font-semibold py-2.5 md:py-3 px-4 md:px-8 rounded-lg transition-all duration-300 transform hover:scale-105 shadow-md hover:shadow-lg flex items-center justify-center w-full md:w-auto text-sm md:text-base">
                            <i class="fas <?= isset($_GET['edit']) ? 'fa-save' : 'fa-user-plus' ?> mr-2"></i><span><?= isset($_GET['edit']) ? 'Update' : 'Create' ?> Account</span>
                        </button>
                    </div>
                    <form id="creditorForm" method="POST" class="space-y-6">
                        <?php if (isset($_GET['edit'])): 
                            $editCreditor = $db->query("SELECT * FROM creditors WHERE id = " . $_GET['edit'])->fetch(PDO::FETCH_ASSOC);
                        ?>
                            <input type="hidden" name="id" value="<?= $editCreditor['id'] ?>">
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <!-- Name Field -->
                            <div class="space-y-2 group">
                                <label class="block text-md font-semibold text-gray-700 flex items-center">
                                    <i class="fas fa-user-circle mr-2 text-gray-500"></i>Creditor Name <span class="text-gray-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="text" name="name" required 
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition duration-200 placeholder-gray-400 shadow-sm"
                                        value="<?= $editCreditor['name'] ?? '' ?>"
                                        placeholder="Tate John">
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
                                        </svg>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Enter the full name of the creditor</p>
                            </div>

                            <!-- Phone Field -->
                            <div class="space-y-2 group">
                                <label class="block text-md font-semibold text-gray-700 flex items-center">
                                    <i class="fas fa-mobile-alt mr-2 text-gray-500"></i>Contact Number
                                </label>
                                <div class="relative">
                                    <input type="tel" name="phone" 
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition duration-200 placeholder-gray-400 shadow-sm"
                                        value="<?= $editCreditor['phone'] ?? '' ?>"
                                        placeholder="0814534236">
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" />
                                        </svg>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Mobile number for contact purposes</p>
                            </div>
                        </div>

                        <div class="flex justify-between items-center pt-4 mt-6 border-t border-gray-100">
                            <!-- Active Status -->

                            
                            <?php if (isset($_GET['edit'])): ?>
                            <a href="credit-book.php" class="text-gray-500 hover:text-gray-700 transition-colors">
                                <i class="fas fa-times mr-1"></i> Cancel editing
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Creditors List -->
                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:divide-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                        <div class="relative max-w-xs w-full md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="searchInput" onkeyup="filterCreditors()" class="py-2 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-slate-900 dark:border-gray-700 dark:text-gray-400 dark:focus:ring-gray-600" placeholder="Search for creditors">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <!-- Status Filter -->
                                        <div class="flex gap-2 items-center">
                                            <select id="statusFilter" class="py-2 px-3 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="">All Status</option>
                                                <option value="new">New</option>
                                                <option value="unpaid">Unpaid</option>
                                                <option value="paid">Paid</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Table -->
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortCreditorsTable(0, true)">
                                                    ID <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortCreditorsTable(1)">
                                                    Name <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortCreditorsTable(2)">
                                                    Contact <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortCreditorsTable(3)">
                                                    Status <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortCreditorsTable(4, true)">
                                                    Balance <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                    
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($creditors as $creditor): 
                                    // Get creditor's balance from creditSales data
                                    $saleData = $salesByCreditor[$creditor['id']] ?? null;
                                    $creditorBalance = $saleData ? ($saleData['total_amount'] - $saleData['paid_amount']) : 0;
                                ?>
                                <tr class="creditor-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer" data-creditor-id="<?= htmlspecialchars($creditor['id']) ?>" onclick="viewCreditor(<?= $creditor['id'] ?>)">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200">
                                        <?= htmlspecialchars($creditor['id']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200">
                                        <?= htmlspecialchars($creditor['name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200">
                                        <?= !empty($creditor['phone']) ? htmlspecialchars($creditor['phone']) : 'N/A' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full
                                            <?= ($saleData['total_transactions'] ?? 0) === 0 ? 'bg-gray-100 text-gray-800' : 
                                               ($creditorBalance > 0 ? 'bg-yellow-100 text-yellow-800' : 
                                               'bg-green-100 text-green-800') ?>">
                                            <?= ($saleData['total_transactions'] ?? 0) === 0 ? 'New' : ($creditorBalance > 0 ? 'Unpaid' : 'Paid') ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold 
                                        <?= ($saleData['total_transactions'] ?? 0) === 0 ? 'text-gray-800' : ($creditorBalance > 0 ? 'text-red-600' : 'text-teal-600') ?>">
                                        N$<?= number_format($creditorBalance, 2) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center" onclick="event.stopPropagation()">
                                        <?php if (isset($salesByCreditor[$creditor['id']]) && ($salesByCreditor[$creditor['id']]['total_amount'] - $salesByCreditor[$creditor['id']]['paid_amount']) > 0): ?>
                                        <div class="flex gap-2 justify-center">
                                            <button onclick="event.stopPropagation(); printCreditorBalance(<?= $creditor['id'] ?>, '<?= htmlspecialchars($creditor['name']) ?>', <?= number_format($salesByCreditor[$creditor['id']]['total_amount'] - $salesByCreditor[$creditor['id']]['paid_amount'], 2, '.', '') ?>)"
                                                class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-gray-600 hover:text-gray-800 disabled:opacity-50 disabled:pointer-events-none dark:text-gray-400 dark:hover:text-gray-300"
                                                title="Print">
                                                <i data-lucide="printer" class="w-4 h-4"></i>
                                            </button>
                                            <a href="javascript:void(0);" 
                                               onclick="event.stopPropagation(); confirmPayAll(<?= $creditor['id'] ?>, '<?= htmlspecialchars($creditor['name']) ?>', <?= number_format($salesByCreditor[$creditor['id']]['total_amount'] - $salesByCreditor[$creditor['id']]['paid_amount'], 2, '.', '') ?>)"
                                               class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-green-600 hover:text-green-800 disabled:opacity-50 disabled:pointer-events-none dark:text-green-500 dark:hover:text-green-400"
                                               title="Pay">
                                                <i data-lucide="credit-card" class="w-4 h-4"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium" onclick="event.stopPropagation()">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="credit-transactions.php?creditor_id=<?= $creditor['id'] ?>" 
                                               onclick="event.stopPropagation()"
                                               class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800 disabled:opacity-50 disabled:pointer-events-none dark:text-blue-500 dark:hover:text-blue-400 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600"
                                               title="View">
                                                <i data-lucide="eye" class="w-4 h-4"></i>
                                            </a>
                                            <a href="credit-book.php?edit=<?= $creditor['id'] ?>" 
                                               onclick="event.stopPropagation()"
                                               class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800 disabled:opacity-50 disabled:pointer-events-none dark:text-blue-500 dark:hover:text-blue-400"
                                               title="Edit">
                                                <i data-lucide="pencil" class="w-4 h-4"></i>
                                            </a>
                                            <button onclick="event.stopPropagation(); deleteCreditor(<?= $creditor['id'] ?>)" 
                                               class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800 disabled:opacity-50 disabled:pointer-events-none dark:text-red-500 dark:hover:text-red-400"
                                               title="Delete">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (count($creditors) === 0): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-2 whitespace-nowrap text-center text-sm text-gray-500">
                                        No creditors found
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="py-1 px-4">
                        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                Showing <span id="showingFrom">1</span> to <span id="showingTo"><?= min(10, count($creditors)) ?></span> of <span id="totalRows"><?= count($creditors) ?></span> entries
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

                <!-- New Credit Sales Section -->
         
            </div>
        </div>
    </div>
    <?php $db = null; ?>

    <script>
    // Global variables for sorting and pagination
    let currentSortColumn = -1;
    let currentSortDirection = 1;
    let currentPage = 1;
    let rowsPerPage = 10;
    let allRows = [];
    let filteredRows = [];

    // Initialize the table when document is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        
        // Store all rows initially
        const tableBody = document.querySelector('tbody');
        allRows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
        filteredRows = [...allRows];
        
        // Add pagination controls
        addPaginationControls();
        
        // Initialize pagination
        showPage(1);
        
        // Add event listeners for search
        const searchInput = document.getElementById('searchInput');
        searchInput.addEventListener('input', function() {
            filterTable(this.value);
        });
        
        // Add event listeners for sorting
        const headers = document.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            if (index < headers.length - 1) { // Skip actions column
                header.addEventListener('click', () => {
                    sortTable(index, header.classList.contains('numeric'));
                });
            }
        });
    });

    function addPaginationControls() {
        const tableContainer = document.querySelector('.overflow-x-auto');
        if (tableContainer && !document.getElementById('paginationControls')) {
            const paginationContainer = document.createElement('div');
            paginationContainer.id = 'paginationControls';
            paginationContainer.className = 'px-6 py-2 bg-gray-50 border-t border-gray-200';
            
            paginationContainer.innerHTML = `
                <div class="flex justify-between items-center">
                    <div class="flex gap-2">
                        <button id="firstPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                            </svg>
                        </button>
                        <button id="prevPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                            Prev
                        </button>
                    </div>
                    <div class="flex items-center gap-4">
                        <span id="pageNumber" class="text-sm text-gray-700 font-medium">Page 1 of 1</span>
                        <div class="flex items-center gap-2">
                            <input type="number" id="pageInput" min="1" class="w-20 px-3 py-2 border border-gray-300 rounded-md text-sm shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Page">
                            <button id="goToPage" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 transition-colors shadow-sm">Go</button>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button id="nextPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                            Next
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                        <button id="lastPage" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
            
            tableContainer.appendChild(paginationContainer);
            
            // Set up pagination event listeners
            document.getElementById('firstPage').addEventListener('click', () => showPage(1));
            document.getElementById('prevPage').addEventListener('click', () => {
                if (currentPage > 1) showPage(currentPage - 1);
            });
            document.getElementById('nextPage').addEventListener('click', () => {
                const tableBody = document.querySelector('tbody');
                const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                
                if (currentPage < maxPage) showPage(currentPage + 1);
            });
            document.getElementById('lastPage').addEventListener('click', () => {
                const tableBody = document.querySelector('tbody');
                const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
                const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                
                showPage(maxPage);
            });
            document.getElementById('goToPage').addEventListener('click', () => {
                const pageInput = document.getElementById('pageInput');
                const pageNum = parseInt(pageInput.value, 10);
                
                if (pageNum && !isNaN(pageNum)) {
                    const tableBody = document.querySelector('tbody');
                    const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
                    const maxPage = Math.ceil(rows.length / rowsPerPage) || 1;
                    
                    if (pageNum >= 1 && pageNum <= maxPage) {
                        showPage(pageNum);
                    }
                }
            });
        }
    }

    function filterTable(searchTerm) {
        searchTerm = searchTerm.toLowerCase().trim();
        
        if (!searchTerm) {
            filteredRows = [...allRows];
        } else {
            filteredRows = allRows.filter(row => {
                const cells = row.querySelectorAll('td');
                return Array.from(cells).some((cell, index) => {
                    if (index === cells.length - 1) return false; // Skip actions column
                    const cellText = cell.textContent.toLowerCase().trim();
                    return cellText.includes(searchTerm);
                });
            });
        }
        
        // Update the table
        updateTable();
        showPage(1);
    }

    function sortTable(columnIndex, isNumeric) {
        if (currentSortColumn === columnIndex) {
            currentSortDirection *= -1;
        } else {
            currentSortColumn = columnIndex;
            currentSortDirection = 1;
        }
        
        filteredRows.sort((a, b) => {
            const aValue = a.cells[columnIndex].textContent.trim();
            const bValue = b.cells[columnIndex].textContent.trim();
            
            if (isNumeric) {
                const aNum = parseFloat(aValue.replace(/[^0-9.-]+/g, '')) || 0;
                const bNum = parseFloat(bValue.replace(/[^0-9.-]+/g, '')) || 0;
                return (aNum - bNum) * currentSortDirection;
            } else {
                return aValue.localeCompare(bValue) * currentSortDirection;
            }
        });
        
        updateTable();
        showPage(1);
    }

    function updateTable() {
        const tableBody = document.querySelector('tbody');
        const noDataRow = tableBody.querySelector('tr td[colspan]')?.parentNode;

        // Remove all rows except the no-data row if it exists
        while (tableBody.firstChild) {
            tableBody.removeChild(tableBody.firstChild);
        }

        if (filteredRows.length === 0) {
            // Show "no data" row
            const newNoDataRow = document.createElement('tr');
            const noDataCell = document.createElement('td');
            noDataCell.setAttribute('colspan', '7'); // Make sure this matches your table's column count
            noDataCell.className = 'px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500';
            noDataCell.textContent = 'No matching creditors found';
            newNoDataRow.appendChild(noDataCell);
            tableBody.appendChild(newNoDataRow);
        } else {
            // Re-attach the original row elements (do not clone)
            filteredRows.forEach(row => tableBody.appendChild(row));
        }
        
        // Re-initialize Lucide icons after table update
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    function showPage(page) {
        const tableBody = document.querySelector('tbody');
        const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
        
        if (rows.length === 0) {
            updatePaginationControls(1, 1);
            return;
        }
        
        const maxPage = Math.ceil(rows.length / rowsPerPage);
        page = Math.max(1, Math.min(page, maxPage));
        
        // Hide all rows
        rows.forEach(row => row.style.display = 'none');
        
        // Show rows for current page
        const start = (page - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        rows.slice(start, end).forEach(row => row.style.display = '');
        
        currentPage = page;
        updatePaginationControls(page, maxPage);
    }

    function updatePaginationControls(currentPage, maxPage) {
        const pageNumber = document.getElementById('pageNumber');
        const pageInput = document.getElementById('pageInput');
        const prevPage = document.getElementById('prevPage');
        const nextPage = document.getElementById('nextPage');
        const firstPage = document.getElementById('firstPage');
        const lastPage = document.getElementById('lastPage');
        
        if (pageNumber) pageNumber.textContent = `Page ${currentPage} of ${maxPage}`;
        if (pageInput) {
            pageInput.value = currentPage;
            pageInput.max = maxPage;
        }
        if (prevPage) prevPage.disabled = currentPage === 1;
        if (nextPage) nextPage.disabled = currentPage >= maxPage;
        if (firstPage) firstPage.disabled = currentPage === 1;
        if (lastPage) lastPage.disabled = currentPage >= maxPage;
    }

    function viewCreditor(creditorId) {
        window.location.href = `credit-transactions.php?creditor_id=${creditorId}`;
    }

    function deleteCreditor(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
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

    function confirmPayAll(creditorId, creditorName, totalBalance) {
        Swal.fire({
            html: `<div class="text-center space-y-3">
                    <div class="bg-gradient-to-r from-gray-50 to-white p-6 rounded-xl shadow-sm">
                        <p class="text-gray-600 font-medium">Confirm Payment</p>
                        <h3 class="text-xl font-semibold text-gray-800 mt-1">${creditorName} <span class="text-sm text-gray-500 font-normal">(Account #${creditorId})</span></h3>
                        <div class="mt-4 bg-teal-50 p-3 rounded-lg inline-block">
                            <span class="text-2xl font-bold text-teal-700">N$${totalBalance.toFixed(2)}</span>
                        </div>
                        <p class="text-sm text-gray-400 mt-4">Select your preferred payment method</p>
                    </div>
                </div>`,
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonColor: '#2563eb',
            denyButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280',
            confirmButtonText: '<i class="fas fa-wallet mr-2"></i> Cash',
            denyButtonText: '<i class="fas fa-mobile-alt mr-2"></i> EFT',
            cancelButtonText: '<i class="fas fa-times mr-2"></i> Cancel',
            customClass: {
                confirmButton: 'px-6 py-2.5 rounded-lg hover:bg-blue-700 transition-all',
                denyButton: 'px-6 py-2.5 rounded-lg hover:bg-teal-700 transition-all',
                cancelButton: 'px-6 py-2.5 rounded-lg hover:bg-gray-200 transition-all'
            },
        }).then((result) => {
            if (result.isConfirmed) {
                // Cash payment - redirect with print flag
                window.location.href = `credit-transactions.php?creditor_id=${creditorId}&pay_all=1&auto_print=1`;
            } else if (result.isDenied) {
                // EFT payment - show EFT form
                showEftPaymentForm(creditorId, creditorName, totalBalance);
            }
        });
    }

    function showEftPaymentForm(creditorId, creditorName, totalBalance) {
        // Create wallet provider options
        let walletOptions = '';
        const walletProviders = ['E-wallet', 'BlueWallet', 'PayPulse', 'Bank Transfer', 'Standard Bank', 'First National Bank', 'Bank Windhoek', 'Nedbank'];
        walletProviders.forEach(provider => {
            walletOptions += `<option value="${provider}">${provider}</option>`;
        });
        
        Swal.fire({
            title: 'EFT Payment',
            html: `<div class="text-center mb-4">
                    <div class="bg-teal-100 text-teal-600 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                    </div>
                    <p class="mb-2">Payment for <strong>${creditorName}</strong></p>
                    <p class="text-lg font-semibold text-gray-800 mb-4">N$${totalBalance.toFixed(2)}</p>
                  </div>
                  <div class="space-y-4">
                    <div class="flex flex-col">
                      <label class="text-left text-sm font-medium text-gray-700 mb-1">Wallet Provider</label>
                      <select id="walletProviderInput" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        ${walletOptions}
                      </select>
                    </div>
                    <div class="flex flex-col">
                      <label class="text-left text-sm font-medium text-gray-700 mb-1">Transaction Reference</label>
                      <input id="transactionRefInput" type="text" placeholder="Enter reference" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                  </div>`,
            showCancelButton: true,
            confirmButtonText: 'Process Payment',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#10B981',
            cancelButtonColor: '#6B7280',
            customClass: {
                confirmButton: 'hover:bg-teal-700 px-4 py-2 rounded-lg',
                cancelButton: 'hover:bg-gray-200 px-4 py-2 rounded-lg'
            },
            focusConfirm: false,
            preConfirm: () => {
                const walletProvider = document.getElementById('walletProviderInput').value;
                const transactionRef = document.getElementById('transactionRefInput').value;
                
                if (!transactionRef) {
                    Swal.showValidationMessage('Please enter a transaction reference');
                    return false;
                }
                
                return { walletProvider, transactionRef };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Redirect with EFT parameters and print flag
                window.location.href = `credit-transactions.php?creditor_id=${creditorId}&pay_all=1&eft=1&transaction_ref=${encodeURIComponent(result.value.transactionRef)}&wallet_provider=${encodeURIComponent(result.value.walletProvider)}&auto_print=1`;
            }
        });
    }

    function toggleCreditorNotifications() {
        const dropdown = document.getElementById('creditorNotificationsDropdown');
        dropdown.classList.toggle('hidden');
        dropdown.classList.toggle('opacity-0');
        dropdown.classList.toggle('scale-95');
        dropdown.classList.toggle('opacity-100');
        dropdown.classList.toggle('scale-100');
    }

    // Close notifications when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('creditorNotificationsDropdown');
        const bellIcon = event.target.closest('svg');
        
        if (!dropdown.contains(event.target) && !bellIcon) {
            dropdown.classList.add('hidden', 'opacity-0', 'scale-95');
            dropdown.classList.remove('opacity-100', 'scale-100');
        }
    });

    // Print creditor balance receipt
    function printCreditorBalance(creditorId, creditorName, balance) {
        // Get all unpaid transactions for this creditor
        fetch(`../get-creditor-transactions.php?creditor_id=${creditorId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const receiptData = {
                        creditor_id: creditorId,
                        creditor_name: creditorName,
                        total_balance: balance,
                        is_balance_receipt: true,
                        transactions: data.transactions,
                        cashier_username: '<?= $_SESSION['username'] ?? 'Unknown' ?>'
                    };

                    // Send to receipt.php for printing
                    fetch('../receipt.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(receiptData)
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(printData => {
                        if (printData.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Balance Receipt Printed',
                                text: `Balance receipt for ${creditorName} has been sent to printer.`,
                                confirmButtonColor: '#3B82F6',
                                timer: 4000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Printing Failed',
                                text: printData.message || 'Failed to print balance receipt.',
                                confirmButtonColor: '#3B82F6',
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Print error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Printing Failed',
                            text: 'An error occurred while printing the balance receipt: ' + error.message,
                            confirmButtonColor: '#3B82F6',
                        });
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to fetch creditor transactions.',
                        confirmButtonColor: '#3B82F6',
                    });
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to fetch creditor data for printing: ' + error.message,
                    confirmButtonColor: '#3B82F6',
                });
            });
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

    // Barcode scanner variables (similar to home.php)
    let barcodeBuffer = '';
    let barcodeTimeout = null;
    const BARCODE_DELAY = 100; // milliseconds

    // Barcode scanner functionality
    document.addEventListener('keydown', function(event) {
        // Skip if we're in an input field (search input, form inputs, etc.)
        if (document.activeElement.tagName === 'INPUT' || 
            document.activeElement.tagName === 'TEXTAREA' ||
            document.activeElement.isContentEditable) {
            return;
        }
        
        // Handle rapid barcode input
        if (/^[0-9]$/.test(event.key)) {
            // Reset timeout on each keypress
            if (barcodeTimeout) clearTimeout(barcodeTimeout);
            
            // Add character to buffer
            barcodeBuffer += event.key;
            
            // Set timeout to process barcode
            barcodeTimeout = setTimeout(() => {
                // If buffer has content (creditor ID)
                if (barcodeBuffer.length > 0) {
                    // Look for creditor with this ID
                    const creditorRow = document.querySelector(`.creditor-row[data-creditor-id="${barcodeBuffer}"]`);
                    if (creditorRow) {
                        // Get the creditor ID from the row
                        const creditorId = creditorRow.getAttribute('data-creditor-id');
                        // Redirect to creditor transactions page
                        window.location.href = `credit-transactions.php?creditor_id=${creditorId}`;
                    } else {
                        // Show notification if creditor not found
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Creditor Not Found',
                                text: `No creditor found with ID: ${barcodeBuffer}`,
                                timer: 2000,
                                timerProgressBar: true,
                                showConfirmButton: false
                            });
                        }
                    }
                }
                barcodeBuffer = ''; // Clear buffer after processing
            }, BARCODE_DELAY);
        } else if (event.key === 'Enter') {
            // Process Enter key immediately for barcode scanners that send Enter
            if (barcodeBuffer.length > 0) {
                const creditorRow = document.querySelector(`.creditor-row[data-creditor-id="${barcodeBuffer}"]`);
                if (creditorRow) {
                    const creditorId = creditorRow.getAttribute('data-creditor-id');
                    window.location.href = `credit-transactions.php?creditor_id=${creditorId}`;
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Creditor Not Found',
                            text: `No creditor found with ID: ${barcodeBuffer}`,
                            timer: 2000,
                            timerProgressBar: true,
                            showConfirmButton: false
                        });
                    }
                }
                barcodeBuffer = ''; // Clear buffer
                if (barcodeTimeout) clearTimeout(barcodeTimeout);
                event.preventDefault(); // Prevent any form submission
            }
        } else {
            // If non-numeric key is pressed, clear the buffer
            barcodeBuffer = '';
            if (barcodeTimeout) clearTimeout(barcodeTimeout);
        }
    });
    </script>
</body>
</html>
