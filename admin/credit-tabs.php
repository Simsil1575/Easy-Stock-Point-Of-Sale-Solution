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

// Helper function to get username from user_id with caching
function getUsernameById($userId, &$usernameCache = []) {
    if (empty($userId)) return 'Unknown';
    
    // Check cache first
    if (isset($usernameCache[$userId])) {
        return $usernameCache[$userId];
    }
    
    try {
        $userDb = new PDO('sqlite:../user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $userDb->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $username = $user ? $user['username'] : 'User #' . $userId;
        $usernameCache[$userId] = $username; // Cache the result
        return $username;
    } catch (Exception $e) {
        $username = 'User #' . $userId;
        $usernameCache[$userId] = $username;
        return $username;
    }
}

// Helper function to batch fetch usernames for better performance
function getUsernamesByIds($userIds) {
    if (empty($userIds)) return [];
    
    $usernameMap = [];
    try {
        $userDb = new PDO('sqlite:../user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $userDb->prepare("SELECT id, username FROM users WHERE id IN ($placeholders)");
        $stmt->execute($userIds);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($users as $user) {
            $usernameMap[(int)$user['id']] = $user['username'];
        }
    } catch (Exception $e) {
        error_log("Error fetching usernames: " . $e->getMessage());
    }
    
    return $usernameMap;
}

// Helper function to recalculate tab balance from unpaid items
function recalculateTabBalance($db, $tabId) {
    try {
        // Calculate balance as: sum of all item totals - sum of all payments on those items
        $balanceStmt = $db->prepare("
            SELECT 
                COALESCE(SUM(ti.quantity * ti.price), 0) as total_items,
                COALESCE((
                    SELECT SUM(tip.amount) 
                    FROM tab_item_payments tip
                    INNER JOIN tab_items ti2 ON tip.tab_item_id = ti2.id
                    WHERE ti2.tab_id = ?
                ), 0) as total_paid
            FROM tab_items ti
            WHERE ti.tab_id = ?
        ");
        $balanceStmt->execute([$tabId, $tabId]);
        $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        
        $newBalance = floatval($balance['total_items']) - floatval($balance['total_paid']);
        
        // Get opening balance
        $openingStmt = $db->prepare("SELECT opening_balance FROM tabs WHERE id = ?");
        $openingStmt->execute([$tabId]);
        $opening = $openingStmt->fetch(PDO::FETCH_ASSOC);
        $openingBalance = floatval($opening['opening_balance'] ?? 0);
        
        // Final balance = opening balance + unpaid items
        $finalBalance = $openingBalance + $newBalance;
        
        // Update the tab balance
        $updateStmt = $db->prepare("UPDATE tabs SET current_balance = ? WHERE id = ?");
        $updateStmt->execute([$finalBalance, $tabId]);
        
        return $finalBalance;
    } catch (Exception $e) {
        error_log("Error recalculating tab balance: " . $e->getMessage());
        throw $e;
    }
}

// Handle POST requests for adding/updating/deleting/closing tabs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_item_id'])) {
        // Delete tab item
        $itemId = intval($_POST['delete_item_id']);
        $itemStmt = $db->prepare("SELECT tab_id FROM tab_items WHERE id = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            $tabId = $item['tab_id'];
            $db->beginTransaction();
            try {
                // Delete related tab_item_payments first (cascade should handle this, but being explicit)
                $deletePaymentsStmt = $db->prepare("DELETE FROM tab_item_payments WHERE tab_item_id = ?");
                $deletePaymentsStmt->execute([$itemId]);
                
                // Delete the item
                $deleteStmt = $db->prepare("DELETE FROM tab_items WHERE id = ?");
                $deleteStmt->execute([$itemId]);
                
                // Recalculate tab balance from scratch
                recalculateTabBalance($db, $tabId);
                
                $db->commit();
                $_SESSION['success'] = 'Product removed from tab successfully';
                header('Location: view-tab.php?id=' . $tabId);
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Failed to delete item: ' . $e->getMessage();
                header('Location: view-tab.php?id=' . $tabId);
                exit();
            }
        }
        header('Location: credit-tabs');
        exit();
    } elseif (isset($_POST['edit_item_id'])) {
        // Edit tab item
        $itemId = intval($_POST['edit_item_id']);
        $newQuantity = intval($_POST['edit_item_quantity']);
        $newPrice = floatval($_POST['edit_item_price']);
        
        if ($newQuantity <= 0) {
            $_SESSION['error'] = 'Quantity must be greater than zero';
            header('Location: view-tab.php?id=' . $_POST['tab_id']);
            exit();
        }
        
        $itemStmt = $db->prepare("SELECT tab_id FROM tab_items WHERE id = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            $tabId = $item['tab_id'];
            $db->beginTransaction();
            try {
                // Check if there are payments on this item - if so, we can't edit price
                $paymentCheckStmt = $db->prepare("SELECT COUNT(*) FROM tab_item_payments WHERE tab_item_id = ?");
                $paymentCheckStmt->execute([$itemId]);
                $hasPayments = $paymentCheckStmt->fetchColumn() > 0;
                
                if ($hasPayments) {
                    // If item has payments, only allow quantity changes, not price changes
                    // Get current price to preserve it
                    $currentItemStmt = $db->prepare("SELECT price FROM tab_items WHERE id = ?");
                    $currentItemStmt->execute([$itemId]);
                    $currentItem = $currentItemStmt->fetch(PDO::FETCH_ASSOC);
                    $newPrice = floatval($currentItem['price']); // Keep original price
                }
                
                // Update the item
                $updateStmt = $db->prepare("UPDATE tab_items SET quantity = ?, price = ? WHERE id = ?");
                $updateStmt->execute([$newQuantity, $newPrice, $itemId]);
                
                // Recalculate tab balance from scratch
                recalculateTabBalance($db, $tabId);
                
                $db->commit();
                $_SESSION['success'] = 'Product updated successfully';
                header('Location: view-tab.php?id=' . $tabId);
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Failed to update item: ' . $e->getMessage();
                header('Location: view-tab.php?id=' . $tabId);
                exit();
            }
        }
        header('Location: credit-tabs');
        exit();
    } elseif (isset($_POST['payment_amount'])) {
        // Make payment on tab - redirect to view-tab.php for proper item-based payment processing
        $tabId = intval($_POST['tab_id']);
        $_SESSION['error'] = 'Please use the payment feature from the tab details page for accurate payment processing.';
        header('Location: view-tab.php?id=' . $tabId);
        exit();
    } elseif (isset($_POST['delete_id'])) {
        // Check tab balance first
        $stmt = $db->prepare("SELECT current_balance, status FROM tabs WHERE id = ?");
        $stmt->execute([$_POST['delete_id']]);
        $tab = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tab['current_balance'] > 0) {
            $_SESSION['error'] = 'Cannot delete tab with outstanding balance. Please close it first.';
            header('Location: credit-tabs');
            exit();
        }

        // Handle deletion if balance is zero
        $stmt = $db->prepare("DELETE FROM tabs WHERE id = ?");
        $stmt->execute([$_POST['delete_id']]);
        $_SESSION['success'] = 'Tab deleted successfully';
        header('Location: credit-tabs');
        exit();
    } elseif (isset($_POST['close_id'])) {
        // Close tab
        $stmt = $db->prepare("UPDATE tabs SET status = 'closed', closed_at = CURRENT_TIMESTAMP, closed_by = ? WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'] ?? null, $_POST['close_id']]);
        $_SESSION['success'] = 'Tab closed successfully';
        header('Location: credit-tabs');
        exit();
    } elseif (isset($_POST['reopen_id'])) {
        // Reopen tab
        $stmt = $db->prepare("UPDATE tabs SET status = 'open', closed_at = NULL, closed_by = NULL WHERE id = ?");
        $stmt->execute([$_POST['reopen_id']]);
        $_SESSION['success'] = 'Tab reopened successfully';
        header('Location: credit-tabs');
        exit();
    } else {
        // Handle add/edit
        $creditor_id = !empty($_POST['creditor_id']) ? $_POST['creditor_id'] : null;
        $tab_name = $_POST['tab_name'];
        $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : 0.00;
        $notes = $_POST['notes'] ?? '';
        $cashier_id = $_SESSION['user_id'] ?? null;

        if (empty($_POST['id'])) {
            // Add new tab
            $stmt = $db->prepare("INSERT INTO tabs (creditor_id, tab_name, opening_balance, current_balance, notes, cashier_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$creditor_id, $tab_name, $opening_balance, $opening_balance, $notes, $cashier_id]);
            $newTabId = $db->lastInsertId();
            // Recalculate balance to ensure accuracy (will be same as opening_balance for new tab, but ensures consistency)
            recalculateTabBalance($db, $newTabId);
            $_SESSION['success'] = 'Tab created successfully';
        } else {
            // Update tab (only allow updating name and notes, not balance)
            $stmt = $db->prepare("UPDATE tabs SET tab_name = ?, notes = ? WHERE id = ?");
            $stmt->execute([$tab_name, $notes, $_POST['id']]);
            // Recalculate balance after update to ensure accuracy
            recalculateTabBalance($db, $_POST['id']);
            $_SESSION['success'] = 'Tab updated successfully';
        }
        header('Location: credit-tabs');
        exit();
    }
}

// Get view parameter from URL
$currentView = isset($_GET['view']) ? $_GET['view'] : '';

// Fetch all tabs - admin version shows all tabs
$tabsStmt = $db->prepare("
    SELECT 
        t.id,
        t.creditor_id,
        t.tab_name,
        t.opening_balance,
        t.current_balance,
        t.status,
        t.opened_at,
        t.closed_at,
        t.closed_by,
        t.notes,
        t.cashier_id,
        t.pending_manager_approval,
        c.name as creditor_name,
        c.phone as creditor_phone
    FROM tabs t
    LEFT JOIN creditors c ON t.creditor_id = c.id
    ORDER BY t.opened_at DESC
");
$tabsStmt->execute();
$tabs = $tabsStmt->fetchAll(PDO::FETCH_ASSOC);

// Add usernames to tabs using getUsernameById (with caching)
$usernameCache = [];
foreach ($tabs as &$tab) {
    $tab['opened_by_username'] = getUsernameById($tab['cashier_id'], $usernameCache);
}
unset($tab); // Break reference

// Fetch all active creditors for the dropdown
$creditors = $db->query("SELECT id, name, phone FROM creditors WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$openTabs = array_filter($tabs, function($tab) { return $tab['status'] === 'open'; });
$closedTabs = array_filter($tabs, function($tab) { return $tab['status'] === 'closed'; });
$totalOpenBalance = array_sum(array_column($openTabs, 'current_balance'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tab Management</title>
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
        .search-input {
            transition: all 0.3s ease;
        }
        .search-input:focus {
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.2);
        }
        .tab-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: 1px solid rgba(229, 231, 235, 0.8);
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
            position: relative;
            overflow: hidden;
        }
        .tab-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #10b981 0%, #14b8a6 50%, #06b6d4 100%);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .tab-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
            background: linear-gradient(135deg, #ffffff 0%, #f0fdfa 100%);
        }
        .tab-card:hover::before {
            transform: scaleX(1);
        }
        .tab-card.selected {
            border-color: rgba(16, 185, 129, 0.4);
            background: linear-gradient(135deg, rgba(240, 253, 250, 0.8) 0%, rgba(255, 255, 255, 1) 100%);
            box-shadow: 0 25px 50px -12px rgba(16, 185, 129, 0.2), 0 0 0 1px rgba(16, 185, 129, 0.2);
        }
        .tab-card.selected::before {
            transform: scaleX(1);
        }
        .tab-card.selected:hover {
            transform: translateY(-8px) scale(1.03);
        }
        .tab-card.unpaid {
            border-color: rgba(239, 68, 68, 0.3);
            background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
        }
        .tab-card.unpaid::before {
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%);
        }
        .tab-card.unpaid:hover {
            box-shadow: 0 25px 50px -12px rgba(239, 68, 68, 0.2), 0 0 0 1px rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.4);
            background: linear-gradient(135deg, #fee2e2 0%, #fef2f2 100%);
        }
        .premium-badge {
            background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }
        .unpaid-badge {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2);
        }
        .premium-button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .premium-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        .premium-button:hover::before {
            width: 300px;
            height: 300px;
        }
    </style>

<style>

* {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
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
    background-color: #14b8a6;
    border-radius: 1px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: #10b981;
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
    background-color: #14b8a6;
    border-radius: 10px;
    border: 3px solid #f1f1f1;
    transition: background-color 0.3s;
}

.products-container::-webkit-scrollbar-thumb:hover {
    background-color: #10b981;
}

.products-container::-webkit-scrollbar-thumb:active {
    background-color: #059669;
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
    
    /* Ensure modals appear above cart on mobile */
    .swal2-container {
        z-index: 10000 !important;
    }
    
    .swal2-popup {
        z-index: 10001 !important;
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
    
    /* Mobile product grid - maintain aspect ratio and image sizes */
    .product-item {
        aspect-ratio: 1 / 1.4;
        min-height: 235px;
        width: calc(100% - 0.5rem);
        margin-bottom: 1rem;
    }
    
    #productGrid {
        gap: 1rem !important;
    }
    
    .product-item .w-full.h-60 {
        height: 50%;
        min-height: 140px;
    }
    
    .product-item .p-5 {
        height: 50%;
        min-height: 140px;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        padding: 0.75rem;
        gap: 0.25rem;
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
    
    .product-item .p-5 p:last-child {
        margin-bottom: 0;
    }
    
    /* Mobile product item adjustments */
    .product-item {
        display: flex;
        flex-direction: column;
    }
    
    .product-item .w-full.h-60 {
        flex-shrink: 0;
    }
    
    .product-item .p-5 {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }
    
    /* Center product grid on mobile */
    #productGrid {
        justify-items: center;
        padding-right: 4px;
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
    z-index: 80;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.mobile-overlay.active {
    opacity: 1;
    visibility: visible;
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
    scrollbar-color: #14b8a6 #f1f1f1;
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

/* Table styles */
.tab-row {
    transition: background-color 0.2s;
}

.tab-row:hover {
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
    .overflow-x-auto {
        -webkit-overflow-scrolling: touch;
    }
    
    table {
        min-width: 800px;
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
                <!-- Header Row: Title -->
                <div class="sticky top-0 z-50 bg-gray-50 py-4 mb-6 flex items-center justify-between gap-4 -mx-6 px-6 shadow-sm">
                    <!-- Mobile Controls Row -->
                    <div class="flex items-center gap-3">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <h1 class="text-xl lg:text-2xl xl:text-3xl font-bold">Manage Tabs</h1>
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

                <!-- Tabs Table -->
                <div class="flex flex-col">
                    <div class="-m-1.5 overflow-x-auto">
                        <div class="p-1.5 min-w-full inline-block align-middle">
                            <div class="border rounded-lg divide-y divide-gray-200 dark:border-gray-700 dark:divide-gray-700 bg-white">
                                <!-- Search and Filters -->
                                <div class="py-3 px-4">
                                    <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                        <div class="relative max-w-xs w-full md:w-auto">
                                            <label class="sr-only">Search</label>
                                            <input type="text" id="hs-table-with-pagination-search" class="py-2 px-3 ps-9 block w-full border-gray-200 shadow-sm rounded-lg text-sm focus:z-10 focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-slate-900 dark:border-gray-700 dark:text-gray-400 dark:focus:ring-gray-600" placeholder="Search for items">
                                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                                <i data-lucide="search" class="w-4 h-4 text-gray-400"></i>
                                            </div>
                                        </div>
                                        <!-- Status Filter -->
                                        <div class="flex gap-2 items-center">
                                            <select id="statusFilter" class="py-2 px-3 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="" <?= ($currentView === '' || $currentView === 'balance') ? 'selected' : '' ?>>All Status</option>
                                                <option value="open" <?= $currentView === 'active' ? 'selected' : '' ?>>Open</option>
                                                <option value="closed" <?= $currentView === 'closed' ? 'selected' : '' ?>>Closed</option>
                                            </select>
                                            <select id="balanceFilter" class="py-2 px-3 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="" <?= ($currentView === '' || $currentView === 'active' || $currentView === 'closed') ? 'selected' : '' ?>>All Balances</option>
                                                <option value="unpaid" <?= $currentView === 'balance' ? 'selected' : '' ?>>Unpaid (>0)</option>
                                                <option value="paid">Paid (=0)</option>
                                                <option value="overpaid">Overpaid (<0)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Table -->
                                <div class="overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col" class="py-3 px-4 pe-0">
                                                    <div class="flex items-center h-5">
                                                        <input id="hs-table-pagination-checkbox-all" type="checkbox" class="border-gray-200 rounded text-blue-600 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:checked:bg-blue-500 dark:checked:border-blue-500 dark:focus:ring-offset-gray-800" onchange="toggleAllRows(this)">
                                                        <label for="hs-table-pagination-checkbox-all" class="sr-only">Checkbox</label>
                                                    </div>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(0)">
                                                    ID <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(1)">
                                                    Tab Name <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(2)">
                                                    Creditor <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(3)">
                                                    Status <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(4)">
                                                    Balance <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(5)">
                                                    Opened By <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600" onclick="sortTable(6)">
                                                    Opened At <i data-lucide="arrow-up-down" class="w-3 h-3 inline-block ml-1"></i>
                                                </th>
                                                <th scope="col" class="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tabsTableBody" class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php if (empty($tabs)): ?>
                                                <tr>
                                                    <td colspan="9" class="px-6 py-12 text-center">
                                                        <i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i>
                                                        <p class="text-gray-500 text-lg">No tabs found. Create your first tab.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach($tabs as $tab): 
                                                    $isUnpaid = $tab['current_balance'] > 0;
                                                ?>
                                                    <tr class="tab-row hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer" 
                                                        data-tab-id="<?= $tab['id'] ?>"
                                                        data-tab-name="<?= htmlspecialchars(strtolower($tab['tab_name'])) ?>"
                                                        data-tab-status="<?= strtolower($tab['status']) ?>"
                                                        data-tab-opened-by="<?= htmlspecialchars(strtolower($tab['opened_by_username'] ?? '')) ?>"
                                                        data-tab-opened-at="<?= strtolower(date('Y-m-d H:i', strtotime($tab['opened_at']))) ?>"
                                                        data-tab-creditor="<?= htmlspecialchars(strtolower($tab['creditor_name'] ?? '')) ?>"
                                                        data-tab-balance="<?= $tab['current_balance'] ?>"
                                                        onclick="handleRowClick(event, <?= $tab['id'] ?>)">
                                                        <td class="py-3 ps-4" onclick="event.stopPropagation()">
                                                            <div class="flex items-center h-5">
                                                                <input type="checkbox" class="row-checkbox border-gray-200 rounded text-blue-600 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:checked:bg-blue-500 dark:checked:border-blue-500 dark:focus:ring-offset-gray-800" value="<?= $tab['id'] ?>">
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200"><?= $tab['id'] ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($tab['tab_name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= htmlspecialchars($tab['creditor_name'] ?? 'N/A') ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $tab['status'] === 'open' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                                                <?= ucfirst($tab['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?= $tab['current_balance'] > 0 ? 'text-red-600' : ($tab['current_balance'] < 0 ? 'text-teal-600' : 'text-gray-800') ?>">
                                                            N$<?= number_format($tab['current_balance'], 2) ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= htmlspecialchars($tab['opened_by_username'] ?? 'Unknown') ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 dark:text-gray-200"><?= date('Y-m-d H:i', strtotime($tab['opened_at'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-end text-sm font-medium" onclick="event.stopPropagation()">
                                                            <div class="flex items-center justify-end gap-2">
                                                                <a href="view-tab.php?id=<?= $tab['id'] ?>" 
                                                                   class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-blue-600 hover:text-blue-800 disabled:opacity-50 disabled:pointer-events-none dark:text-blue-500 dark:hover:text-blue-400 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600"
                                                                   title="View">
                                                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                                                </a>
                                                                
                                                                <?php if ($tab['current_balance'] > 0): ?>
                                                                    <button onclick="printTabBalance(<?= $tab['id'] ?>, '<?= htmlspecialchars($tab['tab_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($tab['creditor_name'] ?? 'N/A', ENT_QUOTES) ?>', <?= number_format($tab['current_balance'], 2, '.', '') ?>);"
                                                                        class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-gray-600 hover:text-gray-800 disabled:opacity-50 disabled:pointer-events-none dark:text-gray-400 dark:hover:text-gray-300"
                                                                        title="Print Balance">
                                                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                                                    </button>
                                                                    <a href="view-tab.php?id=<?= $tab['id'] ?>&pay_all=1&amount=<?= number_format($tab['current_balance'], 2, '.', '') ?>"
                                                                       class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-green-600 hover:text-green-800 disabled:opacity-50 disabled:pointer-events-none dark:text-green-500 dark:hover:text-green-400"
                                                                       title="Pay Now">
                                                                        <i data-lucide="credit-card" class="w-4 h-4"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($tab['status'] === 'closed'): ?>
                                                                    <form method="POST" style="display: inline;" 
                                                                          onsubmit="return confirm('Are you sure you want to reopen this tab?');">
                                                                        <input type="hidden" name="reopen_id" value="<?= $tab['id'] ?>">
                                                                        <button type="submit" 
                                                                                class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-cyan-600 hover:text-cyan-800 disabled:opacity-50 disabled:pointer-events-none"
                                                                                title="Reopen Tab">
                                                                            <i data-lucide="unlock" class="w-4 h-4"></i>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($tab['current_balance'] == 0): ?>
                                                                    <button onclick="deleteTab(<?= $tab['id'] ?>, '<?= htmlspecialchars($tab['tab_name'], ENT_QUOTES) ?>');"
                                                                        class="inline-flex items-center gap-x-1 text-sm font-semibold rounded-lg border border-transparent text-red-600 hover:text-red-800 disabled:opacity-50 disabled:pointer-events-none dark:text-red-500 dark:hover:text-red-400"
                                                                        title="Delete Tab">
                                                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                                    </button>
                                                                <?php endif; ?>
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
                                            Showing <span id="showingFrom">1</span> to <span id="showingTo">10</span> of <span id="totalRows"><?= count($tabs) ?></span> entries
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
        let filteredRows = [];

        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Get all table rows and ensure no duplicates
            const tableBody = document.getElementById('tabsTableBody');
            const allRowsArray = Array.from(tableBody.querySelectorAll('.tab-row'));
            // Deduplicate by tab ID to prevent duplicate rows
            const seenIds = new Set();
            allRows = allRowsArray.filter(row => {
                const tabId = row.getAttribute('data-tab-id');
                if (seenIds.has(tabId)) {
                    return false; // Skip duplicate
                }
                seenIds.add(tabId);
                return true;
            });
            filteredRows = [...allRows];

            // Initialize table (this will apply filters based on dropdown values)
            initializeTable();

            // Search functionality
            const searchInput = document.getElementById('hs-table-with-pagination-search');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
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

            // Balance filter
            const balanceFilter = document.getElementById('balanceFilter');
            if (balanceFilter) {
                balanceFilter.addEventListener('change', function() {
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
            const searchInput = document.getElementById('hs-table-with-pagination-search');
            const statusFilter = document.getElementById('statusFilter');
            const balanceFilter = document.getElementById('balanceFilter');
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const statusValue = statusFilter ? statusFilter.value : '';
            const balanceValue = balanceFilter ? balanceFilter.value : '';

            filteredRows = allRows.filter(row => {
                const tabName = row.getAttribute('data-tab-name') || '';
                const tabStatus = row.getAttribute('data-tab-status') || '';
                const tabOpenedBy = row.getAttribute('data-tab-opened-by') || '';
                const tabOpenedAt = row.getAttribute('data-tab-opened-at') || '';
                const tabCreditor = row.getAttribute('data-tab-creditor') || '';
                const tabId = row.getAttribute('data-tab-id') || '';
                const tabBalance = parseFloat(row.getAttribute('data-tab-balance') || 0);

                // Search filter
                const matchesSearch = searchTerm === '' || 
                    tabName.includes(searchTerm) || 
                    tabStatus.includes(searchTerm) || 
                    tabOpenedBy.includes(searchTerm) || 
                    tabOpenedAt.includes(searchTerm) ||
                    tabCreditor.includes(searchTerm) ||
                    tabId.includes(searchTerm);

                // Status filter
                const matchesStatus = statusValue === '' || tabStatus === statusValue;

                // Balance filter
                let matchesBalance = true;
                if (balanceValue === 'unpaid') {
                    matchesBalance = tabBalance > 0;
                } else if (balanceValue === 'paid') {
                    matchesBalance = tabBalance === 0;
                } else if (balanceValue === 'overpaid') {
                    matchesBalance = tabBalance < 0;
                }

                return matchesSearch && matchesStatus && matchesBalance;
            });

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

            filteredRows.sort((a, b) => {
                let aValue, bValue;

                switch(columnIndex) {
                    case 0: // ID
                        aValue = parseInt(a.getAttribute('data-tab-id') || 0);
                        bValue = parseInt(b.getAttribute('data-tab-id') || 0);
                        break;
                    case 1: // Tab Name
                        aValue = a.getAttribute('data-tab-name') || '';
                        bValue = b.getAttribute('data-tab-name') || '';
                        break;
                    case 2: // Creditor
                        aValue = a.getAttribute('data-tab-creditor') || '';
                        bValue = b.getAttribute('data-tab-creditor') || '';
                        break;
                    case 3: // Status
                        aValue = a.getAttribute('data-tab-status') || '';
                        bValue = b.getAttribute('data-tab-status') || '';
                        break;
                    case 4: // Balance
                        aValue = parseFloat(a.getAttribute('data-tab-balance') || 0);
                        bValue = parseFloat(b.getAttribute('data-tab-balance') || 0);
                        break;
                    case 5: // Opened By
                        aValue = a.getAttribute('data-tab-opened-by') || '';
                        bValue = b.getAttribute('data-tab-opened-by') || '';
                        break;
                    case 6: // Opened At
                        aValue = a.getAttribute('data-tab-opened-at') || '';
                        bValue = b.getAttribute('data-tab-opened-at') || '';
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
            });

            renderTable();
        }

        // Render table with pagination
        function renderTable() {
            const tableBody = document.getElementById('tabsTableBody');
            const totalRows = filteredRows.length;
            const totalPages = Math.ceil(totalRows / rowsPerPage);
            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = startIndex + rowsPerPage;
            const pageRows = filteredRows.slice(startIndex, endIndex);

            // Clear table body
            tableBody.innerHTML = '';

            // Add rows for current page
            if (pageRows.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="9" class="px-6 py-12 text-center"><i data-lucide="file-x" class="w-16 h-16 text-gray-300 mx-auto mb-4"></i><p class="text-gray-500 text-lg">No tabs found matching your criteria.</p></td></tr>';
            } else {
                pageRows.forEach(row => {
                    // Clone the row to avoid duplicate references
                    const clonedRow = row.cloneNode(true);
                    tableBody.appendChild(clonedRow);
                });
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
            prevButton.className = 'p-2.5 inline-flex items-center gap-x-2 text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none dark:text-white dark:hover:bg-white/10 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600';
            prevButton.disabled = currentPage === 1;
            prevButton.innerHTML = '<span aria-hidden="true">«</span><span class="sr-only">Previous</span>';
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
                    pageButton.className = `min-w-[40px] flex justify-center items-center text-gray-800 hover:bg-gray-100 py-2.5 text-sm rounded-full disabled:opacity-50 disabled:pointer-events-none dark:text-white dark:hover:bg-white/10 ${i === currentPage ? 'bg-gray-100 dark:bg-white/10' : ''}`;
                    pageButton.textContent = i;
                    pageButton.onclick = () => {
                        currentPage = i;
                        renderTable();
                    };
                    paginationNav.appendChild(pageButton);
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'px-2 text-gray-500';
                    ellipsis.textContent = '...';
                    paginationNav.appendChild(ellipsis);
                }
            }

            // Next button
            const nextButton = document.createElement('button');
            nextButton.type = 'button';
            nextButton.className = 'p-2.5 inline-flex items-center gap-x-2 text-sm rounded-full text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none dark:text-white dark:hover:bg-white/10 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-gray-600';
            nextButton.disabled = currentPage === totalPages;
            nextButton.innerHTML = '<span class="sr-only">Next</span><span aria-hidden="true">»</span>';
            nextButton.onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            };
            paginationNav.appendChild(nextButton);
        }

        // Handle row click
        function handleRowClick(event, tabId) {
            // Don't navigate if clicking on action buttons or checkboxes
            if (event.target.closest('a, button, form, input[type="checkbox"]')) {
                return;
            }
            window.location.href = `view-tab.php?id=${tabId}`;
        }

        // Toggle all rows checkbox
        function toggleAllRows(checkbox) {
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            rowCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        // Print tab balance receipt
        function deleteTab(tabId, tabName) {
            Swal.fire({
                title: 'Delete Tab?',
                html: `<div class="text-center">
                    <p class="text-gray-700 mb-2">Are you sure you want to delete</p>
                    <p class="text-lg font-semibold text-gray-900">${tabName}</p>
                    <p class="text-sm text-red-600 mt-2">This action cannot be undone!</p>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                customClass: {
                    confirmButton: 'px-6 py-2.5 rounded-lg hover:bg-red-700 transition-all',
                    cancelButton: 'px-6 py-2.5 rounded-lg hover:bg-gray-200 transition-all'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="delete_id" value="${tabId}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function printTabBalance(tabId, tabName, balance) {
            window.location.href = `view-tab.php?id=${tabId}&print_balance=1`;
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

        // Barcode scanner variables (similar to credit-book.php)
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
                    // If buffer has content (tab ID)
                    if (barcodeBuffer.length > 0) {
                        // Look for tab row with this ID (check both visible and all rows)
                        const tabRow = document.querySelector(`.tab-row[data-tab-id="${barcodeBuffer}"]`);
                        if (tabRow) {
                            // Get the tab ID from the row
                            const tabId = tabRow.getAttribute('data-tab-id');
                            // Redirect to tab view page
                            window.location.href = `view-tab.php?id=${tabId}`;
                        } else {
                            // Check if tab exists in allRows (might be filtered out)
                            const tabExists = allRows.some(row => row.getAttribute('data-tab-id') === barcodeBuffer);
                            if (tabExists) {
                                // Clear filters and show the tab
                                const searchInput = document.getElementById('hs-table-with-pagination-search');
                                const statusFilter = document.getElementById('statusFilter');
                                const balanceFilter = document.getElementById('balanceFilter');
                                
                                if (searchInput) searchInput.value = '';
                                if (statusFilter) statusFilter.value = '';
                                if (balanceFilter) balanceFilter.value = '';
                                
                                filterTable();
                                
                                // Find and scroll to the row
                                setTimeout(() => {
                                    const tabRow = document.querySelector(`.tab-row[data-tab-id="${barcodeBuffer}"]`);
                                    if (tabRow) {
                                        tabRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        tabRow.classList.add('bg-blue-50');
                                        setTimeout(() => tabRow.classList.remove('bg-blue-50'), 2000);
                                    }
                                }, 100);
                            } else {
                                // Show notification if tab not found
                                if (typeof Swal !== 'undefined') {
                                    Swal.fire({
                                        icon: 'warning',
                                        title: 'Tab Not Found',
                                        text: `No tab found with ID: ${barcodeBuffer}`,
                                        timer: 2000,
                                        timerProgressBar: true,
                                        showConfirmButton: false
                                    });
                                }
                            }
                        }
                    }
                    barcodeBuffer = ''; // Clear buffer after processing
                }, BARCODE_DELAY);
            } else if (event.key === 'Enter') {
                // Process Enter key immediately for barcode scanners that send Enter
                if (barcodeBuffer.length > 0) {
                    const tabRow = document.querySelector(`.tab-row[data-tab-id="${barcodeBuffer}"]`);
                    if (tabRow) {
                        const tabId = tabRow.getAttribute('data-tab-id');
                        window.location.href = `view-tab.php?id=${tabId}`;
                    } else {
                        // Check if tab exists in allRows
                        const tabExists = allRows.some(row => row.getAttribute('data-tab-id') === barcodeBuffer);
                        if (tabExists) {
                            // Clear filters and show the tab
                            const searchInput = document.getElementById('hs-table-with-pagination-search');
                            const statusFilter = document.getElementById('statusFilter');
                            const balanceFilter = document.getElementById('balanceFilter');
                            
                            if (searchInput) searchInput.value = '';
                            if (statusFilter) statusFilter.value = '';
                            if (balanceFilter) balanceFilter.value = '';
                            
                            filterTable();
                            
                            setTimeout(() => {
                                const tabRow = document.querySelector(`.tab-row[data-tab-id="${barcodeBuffer}"]`);
                                if (tabRow) {
                                    tabRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    tabRow.classList.add('bg-blue-50');
                                    setTimeout(() => tabRow.classList.remove('bg-blue-50'), 2000);
                                }
                            }, 100);
                        } else {
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Tab Not Found',
                                    text: `No tab found with ID: ${barcodeBuffer}`,
                                    timer: 2000,
                                    timerProgressBar: true,
                                    showConfirmButton: false
                                });
                            }
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