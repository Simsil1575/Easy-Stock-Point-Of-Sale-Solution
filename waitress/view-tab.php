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

// Helper function to get username from user_id or return username if already a string
function getUsernameById($userId) {
    if (empty($userId)) return 'Unknown';
    
    // If it's already a username (not numeric), return it as is
    if (!is_numeric($userId)) {
        return $userId;
    }
    
    try {
        $userDb = new PDO('sqlite:../user.db');
        $userDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $userDb->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user ? $user['username'] : 'User #' . $userId;
    } catch (Exception $e) {
        return 'User #' . $userId;
    }
}

// Handle POST requests for payments and item edits/deletes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_item_id'])) {
        // Delete tab item
        $itemId = intval($_POST['delete_item_id']);
        $tabId = intval($_POST['tab_id']);
        $itemStmt = $db->prepare("SELECT tab_id, quantity, price, product_name FROM tab_items WHERE id = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            $db->beginTransaction();
            try {
                // Restore product quantity to stock
                $restoreStmt = $db->prepare("UPDATE products SET quantity = quantity + ? WHERE name = ?");
                $restoreStmt->execute([$item['quantity'], $item['product_name']]);
                
                // Update daily stock summary (decrease sold_quantity since we're returning to stock)
                $currentDate = date('Y-m-d');
                $stmtEnsureDailySummary = $db->prepare("
                    INSERT OR IGNORE INTO daily_stock_summary 
                    (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                    VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
                ");
                $stmtEnsureDailySummary->execute([$currentDate, $item['product_name']]);
                
                $stmtUpdateDailySummary = $db->prepare("
                    UPDATE daily_stock_summary 
                    SET sold_quantity = CASE 
                        WHEN sold_quantity - ? < 0 THEN 0 
                        ELSE sold_quantity - ? 
                    END
                    WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)
                ");
                $stmtUpdateDailySummary->execute([$item['quantity'], $item['quantity'], $currentDate, $item['product_name']]);
                
                // Delete the item
                $deleteStmt = $db->prepare("DELETE FROM tab_items WHERE id = ?");
                $deleteStmt->execute([$itemId]);
                
                // Update tab balance (subtract the item cost)
                $itemTotal = $item['quantity'] * $item['price'];
                $updateStmt = $db->prepare("UPDATE tabs SET current_balance = current_balance - ? WHERE id = ?");
                $updateStmt->execute([$itemTotal, $item['tab_id']]);
                
                $db->commit();
                $_SESSION['success'] = 'Product removed from tab and restored to stock successfully';
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
        $tabId = intval($_POST['tab_id']);
        $newQuantity = intval($_POST['edit_item_quantity']);
        
        if ($newQuantity <= 0) {
            $_SESSION['error'] = 'Quantity must be greater than zero';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        $itemStmt = $db->prepare("SELECT tab_id, quantity, price FROM tab_items WHERE id = ?");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            $db->beginTransaction();
            try {
                // Use original price (price cannot be changed)
                $originalPrice = floatval($item['price']);
                
                // Calculate old and new totals
                $oldTotal = $item['quantity'] * $originalPrice;
                $newTotal = $newQuantity * $originalPrice;
                $difference = $newTotal - $oldTotal;
                
                // Update the item (only quantity, price stays the same)
                $updateStmt = $db->prepare("UPDATE tab_items SET quantity = ? WHERE id = ?");
                $updateStmt->execute([$newQuantity, $itemId]);
                
                // Update tab balance (add the difference)
                $updateBalanceStmt = $db->prepare("UPDATE tabs SET current_balance = current_balance + ? WHERE id = ?");
                $updateBalanceStmt->execute([$difference, $item['tab_id']]);
                
                $db->commit();
                $_SESSION['success'] = 'Product updated successfully';
                header('Location: view-tab.php?id=' . $item['tab_id']);
                exit();
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Failed to update item: ' . $e->getMessage();
                header('Location: view-tab.php?id=' . $item['tab_id']);
                exit();
            }
        }
        header('Location: credit-tabs');
        exit();
    } elseif (isset($_POST['confirm_order'])) {
        // Confirm order - set pending manager approval
        $tabId = intval($_POST['tab_id']);
        
        // Verify tab exists
        $tabStmt = $db->prepare("SELECT id, status FROM tabs WHERE id = ?");
        $tabStmt->execute([$tabId]);
        $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tab) {
            $_SESSION['error'] = 'Tab not found';
            header('Location: credit-tabs');
            exit();
        }
        
        // Check if tab has items
        $itemsStmt = $db->prepare("SELECT COUNT(*) FROM tab_items WHERE tab_id = ?");
        $itemsStmt->execute([$tabId]);
        $itemCount = $itemsStmt->fetchColumn();
        
        if ($itemCount == 0) {
            $_SESSION['error'] = 'Cannot confirm order: Tab has no items';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        try {
            // Ensure pending_manager_approval column exists
            try {
                $db->exec("ALTER TABLE tabs ADD COLUMN pending_manager_approval INTEGER DEFAULT 0");
            } catch (PDOException $e) {
                // Column might already exist, ignore error
            }
            
            // Set pending_manager_approval to 1
            $updateStmt = $db->prepare("UPDATE tabs SET pending_manager_approval = 1 WHERE id = ?");
            $updateStmt->execute([$tabId]);
            
            // Get current balance for printing
            $balanceStmt = $db->prepare("SELECT current_balance FROM tabs WHERE id = ?");
            $balanceStmt->execute([$tabId]);
            $balanceData = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            $currentBalance = $balanceData ? floatval($balanceData['current_balance']) : 0;
            
            $_SESSION['success'] = 'Order confirmed and sent to manager for approval';
            // Redirect with print_balance parameter to automatically print receipt
            header('Location: view-tab.php?id=' . $tabId . '&print_balance=1&balance=' . number_format($currentBalance, 2, '.', ''));
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed to confirm order: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    } elseif (isset($_POST['payment_amount'])) {
        // Make payment on tab - treat as sale and decrease quantities
        $tabId = intval($_POST['tab_id']);
        $amount = floatval($_POST['payment_amount']);
        $paymentMethod = $_POST['payment_method'];
        $transactionRef = $_POST['transaction_ref'] ?? '';
        $walletProvider = $_POST['wallet_provider'] ?? '';
        $cashAmount = floatval($_POST['cash_amount'] ?? 0);
        $eftAmount = floatval($_POST['eft_amount'] ?? 0);
        
        if ($amount <= 0) {
            $_SESSION['error'] = 'Payment amount must be greater than zero';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        // Validate mixed payment
        $isMixedPayment = $paymentMethod === 'mixed';
        if ($isMixedPayment && abs(($cashAmount + $eftAmount) - $amount) > 0.01) {
            $_SESSION['error'] = 'Cash + EFT must equal payment amount';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        // Transaction reference is optional for EFT payments
        
        // Check tab balance and get original cashier who opened the tab
        $tabStmt = $db->prepare("SELECT current_balance, cashier_id FROM tabs WHERE id = ?");
        $tabStmt->execute([$tabId]);
        $tab = $tabStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tab) {
            $_SESSION['error'] = 'Tab not found';
            header('Location: credit-tabs');
            exit();
        }
        
        // Use the cashier who originally opened the tab, not the person making the payment
        // Resolve cashier_id to username if it's a number
        $cashierId = $tab['cashier_id'] ?? null;
        $cashierUsername = getUsernameById($cashierId);
        
        // Create tab_item_payments table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS tab_item_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tab_item_id INTEGER NOT NULL,
                payment_id INTEGER NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                FOREIGN KEY(tab_item_id) REFERENCES tab_items(id),
                FOREIGN KEY(payment_id) REFERENCES tab_payments(id)
            )
        ");
        
        // Create order_id column in tab_payments if it doesn't exist
        try {
            $db->exec("ALTER TABLE tab_payments ADD COLUMN order_id INTEGER");
        } catch (PDOException $e) {
            // Column might already exist, ignore error
        }
        
        // Get unpaid tab items (ordered by oldest first - FIFO)
        $tabItemsStmt = $db->prepare("
            SELECT ti.*, (ti.quantity * ti.price) as item_total,
                   COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as paid_amount
            FROM tab_items ti
            WHERE ti.tab_id = ?
                AND COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
            ORDER BY ti.added_at ASC
        ");
        $tabItemsStmt->execute([$tabId]);
        $unpaidItems = $tabItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($unpaidItems)) {
            $_SESSION['error'] = 'No unpaid items in this tab';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
        
        $db->beginTransaction();
        try {
            // Calculate which items to pay for (FIFO - pay oldest items first)
            $remainingPayment = $amount;
            $itemsToPay = [];
            
            foreach ($unpaidItems as $item) {
                if ($remainingPayment <= 0) break;
                
                // Get how much is already paid for this item
                $alreadyPaid = floatval($item['paid_amount'] ?? 0);
                $itemTotal = floatval($item['item_total']);
                $unpaidAmount = $itemTotal - $alreadyPaid;
                
                if ($unpaidAmount > 0) {
                    $paymentForThisItem = min($remainingPayment, $unpaidAmount);
                    $paymentRatio = $paymentForThisItem / $itemTotal;
                    
                    $itemsToPay[] = [
                        'item_id' => $item['id'],
                        'product_name' => $item['product_name'],
                        'quantity' => intval($item['quantity']),
                        'price' => floatval($item['price']),
                        'payment_amount' => $paymentForThisItem,
                        'payment_ratio' => $paymentRatio,
                        'paid_quantity' => intval($item['quantity'] * $paymentRatio) // Quantity to decrease
                    ];
                    
                    $remainingPayment -= $paymentForThisItem;
                }
            }
            
            if ($remainingPayment > 0.01) { // Allow small rounding differences
                throw new Exception('Payment amount exceeds unpaid tab balance');
            }
            
            // Create order record (like process_order.php)
            $cashReceived = ($paymentMethod === 'cash' || $isMixedPayment) ? ($isMixedPayment ? $cashAmount : $amount) : 0;
            $orderStmt = $db->prepare("INSERT INTO orders (total, cash_received, created_at, cashier_id) VALUES (?, ?, ?, ?)");
            $orderStmt->execute([
                $amount,
                $cashReceived,
                date('Y-m-d H:i:s'),
                $cashierUsername
            ]);
            $orderId = $db->lastInsertId();
            
            // Prepare statements for updating quantities and daily stock summary
            $stmtUpdateInventory = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE name = ?");
            $stmtOrderItems = $db->prepare("INSERT INTO order_items (order_id, product_name, quantity, price) VALUES (?, ?, ?, ?)");
            
            $stmtUpdateDailySummary = $db->prepare("
                INSERT OR REPLACE INTO daily_stock_summary 
                (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                VALUES (
                    ?,
                    (SELECT id FROM products WHERE name = ?),
                    COALESCE((SELECT opening_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0),
                    COALESCE((SELECT closing_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0),
                    COALESCE((SELECT received_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0),
                    COALESCE((SELECT sold_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0) + ?,
                    COALESCE((SELECT damaged_quantity FROM daily_stock_summary WHERE date = ? AND product_id = (SELECT id FROM products WHERE name = ?)), 0)
                )
            ");
            
            $stmtEnsureDailySummary = $db->prepare("
                INSERT OR IGNORE INTO daily_stock_summary 
                (date, product_id, opening_quantity, closing_quantity, received_quantity, sold_quantity, damaged_quantity)
                VALUES (?, (SELECT id FROM products WHERE name = ?), 0, 0, 0, 0, 0)
            ");
            
            
            $currentDate = date('Y-m-d');
            
            // Check stock availability before processing
            $stmtCheckStock = $db->prepare("SELECT quantity FROM products WHERE name = ?");
            foreach ($itemsToPay as $itemPayment) {
                $stmtCheckStock->execute([$itemPayment['product_name']]);
                $product = $stmtCheckStock->fetch(PDO::FETCH_ASSOC);
                if (!$product || $product['quantity'] < $itemPayment['paid_quantity']) {
                    throw new Exception('Insufficient stock for ' . $itemPayment['product_name'] . '. Available: ' . ($product['quantity'] ?? 0) . ', Required: ' . $itemPayment['paid_quantity']);
                }
            }
            
            // Process each item being paid for
            foreach ($itemsToPay as $itemPayment) {
                $paidQty = $itemPayment['paid_quantity'];
                
                if ($paidQty > 0) {
                    // Decrease product quantity
                    $stmtUpdateInventory->execute([$paidQty, $itemPayment['product_name']]);
                    
                    // Add to order_items
                    $itemPrice = $itemPayment['price'] * $paidQty;
                    $stmtOrderItems->execute([
                        $orderId,
                        $itemPayment['product_name'],
                        $paidQty,
                        $itemPrice
                    ]);
                    
                    // Ensure daily stock summary exists
                    $stmtEnsureDailySummary->execute([$currentDate, $itemPayment['product_name']]);
                    
                    // Update daily stock summary
                    $stmtUpdateDailySummary->execute([
                        $currentDate, $itemPayment['product_name'],
                        $currentDate, $itemPayment['product_name'],
                        $currentDate, $itemPayment['product_name'],
                        $currentDate, $itemPayment['product_name'],
                        $currentDate, $itemPayment['product_name'], $paidQty,
                        $currentDate, $itemPayment['product_name']
                    ]);
                }
            }
            
            // Insert payment record
            $paymentStmt = $db->prepare("INSERT INTO tab_payments (tab_id, amount, payment_method, transaction_ref, wallet_provider, cashier_id, order_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $paymentStmt->execute([$tabId, $amount, $paymentMethod, $transactionRef, $walletProvider, $cashierUsername, $orderId]);
            $paymentId = $db->lastInsertId();
            
            // Link payments to items
            $linkStmt = $db->prepare("INSERT INTO tab_item_payments (tab_item_id, payment_id, amount) VALUES (?, ?, ?)");
            foreach ($itemsToPay as $itemPayment) {
                $linkStmt->execute([$itemPayment['item_id'], $paymentId, $itemPayment['payment_amount']]);
            }
            
            // Remove fully paid items from tab_items
            // First get all items for this tab with their payment totals
            $checkPaidItemsStmt = $db->prepare("
                SELECT ti.id, ti.quantity, ti.price,
                       COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as total_paid
                FROM tab_items ti
                WHERE ti.tab_id = ?
            ");
            $checkPaidItemsStmt->execute([$tabId]);
            $allItems = $checkPaidItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Delete items that are fully paid
            $deletePaidStmt = $db->prepare("DELETE FROM tab_items WHERE id = ?");
            foreach ($allItems as $item) {
                $itemTotal = floatval($item['quantity']) * floatval($item['price']);
                $totalPaid = floatval($item['total_paid']);
                if ($totalPaid >= $itemTotal) {
                    $deletePaidStmt->execute([$item['id']]);
                }
            }
            
            // Update tab balance
            $updateStmt = $db->prepare("UPDATE tabs SET current_balance = current_balance - ? WHERE id = ?");
            $updateStmt->execute([$amount, $tabId]);
            
            // Clear pending manager approval flag when payment is processed
            try {
                $db->exec("ALTER TABLE tabs ADD COLUMN pending_manager_approval INTEGER DEFAULT 0");
            } catch (PDOException $e) {
                // Column might already exist, ignore error
            }
            $clearPendingStmt = $db->prepare("UPDATE tabs SET pending_manager_approval = 0 WHERE id = ?");
            $clearPendingStmt->execute([$tabId]);
            
            // Handle EFT payments (like process_order.php)
            $isEftPayment = ($paymentMethod === 'eft' || ($isMixedPayment && $eftAmount > 0));
            if ($isEftPayment) {
                $eftAmountToRecord = $isMixedPayment ? $eftAmount : $amount;
                
                // Create eft_payments table if it doesn't exist
                $db->exec("
                    CREATE TABLE IF NOT EXISTS eft_payments (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        order_id INTEGER NOT NULL,
                        transaction_ref TEXT,
                        wallet_provider TEXT,
                        amount DECIMAL(10,2) NOT NULL,
                        cashier_id TEXT,
                        payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY(order_id) REFERENCES orders(id)
                    )
                ");
                
                $stmtEftPayment = $db->prepare("INSERT INTO eft_payments (order_id, transaction_ref, wallet_provider, amount, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtEftPayment->execute([
                    $orderId,
                    $transactionRef,
                    $walletProvider,
                    $eftAmountToRecord,
                    $cashierUsername,
                    date('Y-m-d H:i:s')
                ]);
                
                // Handle mixed payment
                if ($isMixedPayment) {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS mixed_payments (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            order_id INTEGER NOT NULL,
                            cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                            eft_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                            eft_transaction_ref TEXT,
                            eft_wallet_provider TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            cashier_id TEXT,
                            FOREIGN KEY(order_id) REFERENCES orders(id)
                        )
                    ");
                    
                    $stmtMixed = $db->prepare("INSERT INTO mixed_payments (order_id, cash_amount, eft_amount, eft_transaction_ref, eft_wallet_provider, cashier_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtMixed->execute([
                        $orderId,
                        $cashAmount,
                        $eftAmount,
                        $transactionRef,
                        $walletProvider,
                        $cashierUsername
                    ]);
                }
            }
            
            $db->commit();
            $_SESSION['success'] = 'Payment processed successfully and product quantities updated';
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to process payment: ' . $e->getMessage();
            header('Location: view-tab.php?id=' . $tabId);
            exit();
        }
    }
}

// Get tab ID from query parameter
$tabId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($tabId <= 0) {
    header('Location: credit-tabs');
    exit();
}

// Fetch tab details
$viewTabStmt = $db->prepare("
    SELECT t.*, c.name as creditor_name, c.phone as creditor_phone,
           t.cashier_id
    FROM tabs t
    LEFT JOIN creditors c ON t.creditor_id = c.id
    WHERE t.id = ?
");
$viewTabStmt->execute([$tabId]);
$viewTab = $viewTabStmt->fetch(PDO::FETCH_ASSOC);

if (!$viewTab) {
    header('Location: credit-tabs');
    exit();
}

// Add username to tab
$viewTab['opened_by_username'] = getUsernameById($viewTab['cashier_id']);

// First, check ALL tab_items for this tab to see if there are duplicates
$allItemsCheck = $db->prepare("SELECT id, product_name, quantity, price FROM tab_items WHERE tab_id = ? ORDER BY id");
$allItemsCheck->execute([$tabId]);
$allItems = $allItemsCheck->fetchAll(PDO::FETCH_ASSOC);
error_log("ALL tab_items for tab $tabId: " . print_r($allItems, true));

// Fetch tab items (only unpaid items) - using GROUP BY to ensure uniqueness
$tabItemsStmt = $db->prepare("
    SELECT ti.id, ti.tab_id, ti.product_name, ti.quantity, ti.price, ti.added_at, ti.added_by,
           COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) as paid_amount,
           (ti.quantity * ti.price) as item_total,
           (SELECT image_url FROM products WHERE name = ti.product_name LIMIT 1) as product_image
    FROM tab_items ti
    WHERE ti.tab_id = ?
        AND COALESCE((SELECT SUM(amount) FROM tab_item_payments WHERE tab_item_id = ti.id), 0) < (ti.quantity * ti.price)
    GROUP BY ti.id
    ORDER BY ti.added_at DESC
");
$tabItemsStmt->execute([$tabId]);
$tabItems = $tabItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure we have unique items by ID using array indexing (most robust method)
$uniqueItemsById = [];
foreach ($tabItems as $item) {
    $itemId = intval($item['id']);
    // Only keep the first occurrence of each ID
    if (!isset($uniqueItemsById[$itemId])) {
        $uniqueItemsById[$itemId] = $item;
    }
}
// Convert back to indexed array
$tabItems = array_values($uniqueItemsById);

// Add usernames to tab items
foreach ($tabItems as &$item) {
    $item['added_by_username'] = getUsernameById($item['added_by']);
}
unset($item); // Break the reference

// Fetch tab payments
$tabPaymentsStmt = $db->prepare("
    SELECT tp.*, tp.cashier_id
    FROM tab_payments tp
    WHERE tp.tab_id = ?
    ORDER BY tp.payment_date DESC
");
$tabPaymentsStmt->execute([$tabId]);
$tabPayments = $tabPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Add usernames to payments
foreach ($tabPayments as &$payment) {
    $payment['cashier_username'] = getUsernameById($payment['cashier_id']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($viewTab['tab_name']) ?> - Tab Details</title>
    <script src="../receipt.php?js=true"></script>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../src/jquery-3.6.0.min.js"></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <script src="../lucide.js"></script>
    <script src="../sweetalert2@11.js"></script>

    <style>
        .sidebar {
            position: fixed;
            height: 100%;
        }
        .content {
            margin-left: 250px; /* Adjust this value based on the width of your sidebar */
        }
        .fade-in {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .status-badge { 
            padding: 0.25rem 0.75rem; 
            border-radius: 20px; 
            font-size: 0.85rem; 
        }
        .payment-method-btn {
            transition: all 0.2s ease;
        }
        .payment-method-btn.payment-method-selected {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        input[name="payment_method"]:checked + label.payment-method-btn,
        input[name="payment_method"]:checked ~ label.payment-method-btn {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            .content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
            
            .container {
                padding: 1rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
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
        
        /* Mobile Table Responsive - Vertical Card Layout */
        @media (max-width: 768px) {
            /* Remove overflow-x-auto on mobile and prevent container overflow */
            .overflow-x-auto {
                overflow-x: visible !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            /* Ensure table containers don't overflow */
            .bg-white.rounded-lg {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow: hidden;
            }
            
            /* Ensure tables fit within container */
            table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed;
                box-sizing: border-box;
            }
            
            /* Hide table headers on mobile */
            table thead {
                display: none;
            }
            
            /* Convert table rows to compact cards */
            table tbody tr {
                display: block;
                width: 100%;
                max-width: 100%;
                margin-bottom: 0.5rem;
                background: white;
                border: 2px solid #d1d5db;
                border-radius: 0.375rem;
                padding: 0.5rem;
                box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
                height: auto !important;
                position: relative;
                box-sizing: border-box;
            }
            
            /* Convert table cells to compact inline blocks */
            table tbody td {
                display: flex;
                align-items: center;
                width: 100% !important;
                max-width: 100% !important;
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
                box-sizing: border-box;
                word-wrap: break-word;
            }
            
            /* Remove border from last cell in each row */
            table tbody td:last-child {
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
            
            /* Hide label if data-label is empty (for total rows) */
            table tbody td[data-label=""]::before {
                display: none;
            }
            
            /* Ensure content inside cells wraps properly and takes remaining space */
            table tbody td > div {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 0.25rem;
                flex: 1;
                min-width: 0;
                justify-content: flex-start !important;
            }
            
            table tbody td > span:not(::before),
            table tbody td > button {
                flex: 1;
                min-width: 0;
            }
            
            /* Ensure badges are aligned to the left */
            table tbody td > div span.inline-flex {
                flex: 0 0 auto;
                margin-left: 0;
            }
            
            /* Actions column - center align */
            table tbody td[data-label="Actions"] {
                justify-content: center;
            }
            
            table tbody td[data-label="Actions"]::before {
                display: none; /* Hide label for Actions column */
            }
            
            /* Right align numeric columns */
            table tbody td[data-label="Qty"],
            table tbody td[data-label="Unit Price"],
            table tbody td[data-label="Total"],
            table tbody td[data-label="Amount"] {
                justify-content: space-between;
            }
            
            /* Remove hover effect on mobile cards */
            table tbody tr:hover {
                background: white;
            }
            
            /* Ensure images don't overflow */
            table tbody td img {
                max-width: 100%;
                height: auto;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="container mx-auto p-6">
                <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded shadow" role="alert">
                    <p><?= $_SESSION['error'] ?></p>
                </div>
                <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-teal-100 border-l-4 border-teal-500 text-teal-700 p-4 mb-4 rounded shadow" role="alert">
                    <p><?= $_SESSION['success'] ?></p>
                </div>
                <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <!-- DEBUG: Tab Items Info (toggle with ?debug=1 in URL) -->
                <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4 rounded shadow" id="debugInfo">
                    <p class="font-bold">DEBUG INFO:</p>
                    <p>Total Items: <?= count($tabItems) ?></p>
                    <p>All Items in DB for this tab:</p>
                    <pre class="text-xs mt-2 bg-white p-2 rounded"><?= print_r($allItems ?? [], true) ?></pre>
                    <p>Tab Items after query:</p>
                    <pre class="text-xs mt-2 bg-white p-2 rounded"><?= print_r($tabItems, true) ?></pre>
                </div>
                <?php endif; ?>

                <!-- Header -->
                <div class="mb-6">
                    <div class="flex flex-col lg:flex-row lg:justify-between lg:items-start gap-4 mb-4">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="flex items-center gap-3 lg:hidden">
                            <div class="hamburger bg-[#f3f4f6] p-2" onclick="toggleSidebar()">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900 mb-1">
                                    <?= htmlspecialchars($viewTab['tab_name']) ?>
                                </h1>
                                <p class="text-sm text-gray-500">
                                    <?php if ($viewTab['creditor_name']): ?>
                                        <span class="mr-3 inline-flex items-center"><i data-lucide="user" class="w-4 h-4 mr-1"></i><?= htmlspecialchars($viewTab['creditor_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="status-badge <?= $viewTab['status'] === 'open' ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-800' ?> px-2 py-1 rounded-full text-xs">
                                        <?= ucfirst($viewTab['status']) ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        <!-- Desktop Header -->
                        <div class="hidden lg:block">
                            <h1 class="text-3xl font-bold text-gray-900 mb-1">
                                <?= htmlspecialchars($viewTab['tab_name']) ?>
                            </h1>
                            <p class="text-sm text-gray-500">
                                <?php if ($viewTab['creditor_name']): ?>
                                    <span class="mr-3 inline-flex items-center"><i data-lucide="user" class="w-4 h-4 mr-1"></i><?= htmlspecialchars($viewTab['creditor_name']) ?></span>
                                <?php endif; ?>
                                <span class="status-badge <?= $viewTab['status'] === 'open' ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-800' ?> px-2 py-1 rounded-full text-xs">
                                    <?= ucfirst($viewTab['status']) ?>
                                </span>
                            </p>
                        </div>
                        <a href="credit-tabs" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>Back
                        </a>
                    </div>

                    <!-- Key Info Bar -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-center gap-6">
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Current Balance</p>
                                    <p class="text-2xl font-bold <?= $viewTab['current_balance'] > 0 ? 'text-red-600' : ($viewTab['current_balance'] < 0 ? 'text-teal-600' : 'text-gray-600') ?>">
                                        N$<?= number_format($viewTab['current_balance'], 2) ?>
                                    </p>
                                </div>
                                <?php if ($viewTab['creditor_phone']): ?>
                                <div class="border-l border-gray-200 pl-6">
                                    <p class="text-xs text-gray-500 mb-1">Contact</p>
                                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($viewTab['creditor_phone']) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($viewTab['status'] === 'open'): ?>
                            <div class="flex flex-wrap gap-2">
                                <?php 
                                $hasItems = !empty($tabItems);
                                $pendingApproval = isset($viewTab['pending_manager_approval']) && $viewTab['pending_manager_approval'] == 1;
                                ?>
                                <?php if ($hasItems && !$pendingApproval): ?>
                                <button onclick="confirmOrder(<?= $viewTab['id'] ?>)"
                                    class="inline-flex items-center px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white rounded-lg text-sm font-medium transition-colors">
                                    <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>Confirm Order
                                </button>
                                <?php endif; ?>
                                <?php if ($pendingApproval): ?>
                                <span class="inline-flex items-center px-4 py-2 bg-yellow-100 text-yellow-800 rounded-lg text-sm font-medium border border-yellow-200">
                                    <i data-lucide="clock" class="w-4 h-4 mr-2"></i>Confirmed
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Products in Tab -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                        <h3 class="text-lg font-semibold text-gray-700">
                            <i data-lucide="shopping-cart" class="w-5 h-5 mr-2 text-gray-600 inline-block"></i>Products
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left px-4 py-3 text-sm font-medium text-gray-500">Product</th>
                                    <th class="text-right px-4 py-3 text-sm font-medium text-gray-500">Qty</th>
                                    <th class="text-right px-4 py-3 text-sm font-medium text-gray-500">Unit Price</th>
                                    <th class="text-right px-4 py-3 text-sm font-medium text-gray-500">Total</th>
                                    <?php 
                                    $pendingApproval = isset($viewTab['pending_manager_approval']) && $viewTab['pending_manager_approval'] == 1;
                                    if (!$pendingApproval): 
                                    ?>
                                    <th class="text-center px-4 py-3 text-sm font-medium text-gray-500">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $pendingApproval = isset($viewTab['pending_manager_approval']) && $viewTab['pending_manager_approval'] == 1;
                                $colspan = $pendingApproval ? 4 : 5;
                                ?>
                                <?php if (empty($tabItems)): ?>
                                    <tr>
                                        <td colspan="<?= $colspan ?>" class="p-6 text-center text-sm text-gray-500">No products in this tab</td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $itemsTotal = 0;
                                    foreach($tabItems as $item): 
                                        $itemTotal = $item['quantity'] * $item['price'];
                                        $itemsTotal += $itemTotal;
                                    ?>
                                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                                            <td class="px-4 py-3" data-label="Product">
                                                <div class="flex items-center gap-3">
                                                    <div class="relative w-10 h-10 rounded-lg overflow-hidden">
                                                        <img src="../products/<?= htmlspecialchars($item['product_image'] ?? '') ?>" 
                                                             alt="<?= htmlspecialchars($item['product_name']) ?>" 
                                                             class="w-full h-full object-cover" 
                                                             onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';if(typeof lucide !== 'undefined'){lucide.createIcons();}">
                                                        <div class="w-full h-full flex items-center justify-center bg-gray-100" style="display:none;">
                                                            <i data-lucide="package" class="w-5 h-5 text-gray-400"></i>
                                                        </div>
                                                    </div>
                                                    <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['product_name']) ?></span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-600 text-right" data-label="Qty"><?= $item['quantity'] ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-600 text-right" data-label="Unit Price">N$<?= number_format($item['price'], 2) ?></td>
                                            <td class="px-4 py-3 text-sm font-semibold text-gray-900 text-right" data-label="Total">N$<?= number_format($itemTotal, 2) ?></td>
                                            <?php if (!$pendingApproval): ?>
                                            <td class="px-4 py-3 text-center" data-label="Actions">
                                                <div class="flex items-center justify-center gap-2">
                                                    <button onclick="openEditItemModal(<?= $item['id'] ?>, '<?= htmlspecialchars($item['product_name'], ENT_QUOTES) ?>', <?= $item['quantity'] ?>, <?= $item['price'] ?>, <?= $viewTab['id'] ?>)" 
                                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors">
                                                        <i data-lucide="edit" class="w-3 h-3 mr-1"></i>Edit
                                                    </button>
                                                    <button onclick="deleteTabItem(<?= $item['id'] ?>, '<?= htmlspecialchars($item['product_name'], ENT_QUOTES) ?>', <?= $viewTab['id'] ?>)" 
                                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors">
                                                        <i data-lucide="trash-2" class="w-3 h-3 mr-1"></i>Delete
                                                    </button>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="border-t-2 border-gray-300 bg-gray-50 font-semibold">
                                        <td colspan="3" class="px-4 py-3 text-right text-sm text-gray-700" data-label="">Total:</td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right" data-label="">N$<?= number_format($itemsTotal, 2) ?></td>
                                        <?php if (!$pendingApproval): ?>
                                        <td class="px-4 py-3" data-label=""></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

             
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden backdrop-blur-sm">
        <div class="relative top-10 mx-auto mb-10 max-w-md">
            <div class="bg-white rounded-lg shadow-xl border border-gray-200">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Make Payment</h3>
                            <p class="text-sm text-gray-500 mt-0.5">Tab: <?= htmlspecialchars($viewTab['tab_name']) ?></p>
                        </div>
                        <button onclick="closePaymentModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <form method="POST" id="paymentForm" class="p-6">
                    <input type="hidden" name="tab_id" value="<?= $viewTab['id'] ?>">
                    
                    <div class="space-y-5">
                        <!-- Amount Section -->
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Amount</label>
                            <div class="flex items-baseline gap-2">
                                <span class="text-gray-600 font-medium">N$</span>
                                <input type="number" name="payment_amount" id="paymentAmount" step="0.01" min="0.01" max="<?= $viewTab['current_balance'] ?>" 
                                       value="<?= $viewTab['current_balance'] ?>" required
                                       class="flex-1 text-2xl font-bold text-gray-900 bg-transparent border-0 focus:ring-0 p-0">
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Available: <span class="font-medium text-gray-700">N$<?= number_format($viewTab['current_balance'], 2) ?></span></p>
                        </div>

                        <!-- Payment Method Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">Payment Method</label>
                            <div class="grid grid-cols-3 gap-3" id="paymentMethodButtons">
                                <input type="radio" name="payment_method" id="method_cash" value="cash" class="hidden" checked>
                                <label for="method_cash" class="payment-method-btn flex flex-col items-center justify-center p-4 border-2 border-gray-300 rounded-lg cursor-pointer transition-all hover:border-gray-400 hover:bg-gray-50 payment-method-selected">
                                    <i data-lucide="wallet" class="w-6 h-6 text-gray-600 mb-2"></i>
                                    <span class="text-sm font-medium text-gray-700">Cash</span>
                                </label>
                                
                                <input type="radio" name="payment_method" id="method_eft" value="eft" class="hidden">
                                <label for="method_eft" class="payment-method-btn flex flex-col items-center justify-center p-4 border-2 border-gray-300 rounded-lg cursor-pointer transition-all hover:border-gray-400 hover:bg-gray-50">
                                    <i data-lucide="smartphone" class="w-6 h-6 text-gray-600 mb-2"></i>
                                    <span class="text-sm font-medium text-gray-700">EFT</span>
                                </label>
                                
                                <input type="radio" name="payment_method" id="method_mixed" value="mixed" class="hidden">
                                <label for="method_mixed" class="payment-method-btn flex flex-col items-center justify-center p-4 border-2 border-gray-300 rounded-lg cursor-pointer transition-all hover:border-gray-400 hover:bg-gray-50">
                                    <i data-lucide="arrow-left-right" class="w-6 h-6 text-gray-600 mb-2"></i>
                                    <span class="text-sm font-medium text-gray-700">Mixed</span>
                                </label>
                            </div>
                        </div>

                        <!-- Mixed Payment Fields -->
                        <div id="mixedFields" class="hidden space-y-4">
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                <label class="block text-sm font-medium text-gray-700 mb-3">Split Payment</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Cash (N$)</label>
                                        <input type="number" name="cash_amount" id="cashAmount" step="0.01" min="0" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">EFT (N$)</label>
                                        <input type="number" name="eft_amount" id="eftAmount" step="0.01" min="0" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-sm">
                                    </div>
                                </div>
                                <p class="text-xs text-gray-600 mt-2">Total: <span id="mixedTotal" class="font-semibold text-gray-900">N$0.00</span></p>
                            </div>
                        </div>

                        <!-- EFT Payment Fields -->
                        <div id="eftFields" class="hidden space-y-4">
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Transaction Details</label>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Transaction Reference</label>
                                        <input type="text" name="transaction_ref" id="transactionRef"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-sm"
                                               placeholder="Enter reference number">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Wallet Provider</label>
                                        <select name="wallet_provider" id="walletProvider"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-sm">
                                            <option value="">Select provider</option>
                                            <option value="Credit Card">Credit Card (Swipe)</option>
                                            <option value="E-wallet">E-wallet</option>
                                            <option value="Easy Wallet">Easy Wallet</option>
                                            <option value="Pay2Cell">Pay2Cell</option>
                                            <option value="gray Wallet">gray Wallet</option>
                                            <option value="Ned Wallet">Ned Wallet</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-3 pt-4 border-t border-gray-200">
                            <button type="submit" class="flex-1 bg-gray-700 hover:bg-gray-800 text-white font-medium py-3 px-4 rounded-lg transition-colors">
                                Process Payment
                            </button>
                            <button type="button" onclick="closePaymentModal()" 
                                    class="px-4 py-3 border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium rounded-lg transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div id="editItemModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden backdrop-blur-sm">
        <div class="relative top-20 mx-auto p-6 max-w-md w-full">
            <div class="bg-white rounded-lg shadow-xl border border-gray-200">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">Edit Item</h3>
                        <button onclick="closeEditItemModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Form -->
                <form method="POST" id="editItemForm" class="p-6">
                    <input type="hidden" name="edit_item_id" id="edit_item_id">
                    <input type="hidden" name="tab_id" id="edit_tab_id">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Product Name</label>
                            <input type="text" id="edit_product_name" readonly
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Quantity</label>
                            <input type="number" name="edit_item_quantity" id="edit_item_quantity" step="1" min="1" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-gray-900">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Unit Price</label>
                            <input type="number" id="edit_item_price" step="0.01" min="0" readonly
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700">
                            <input type="hidden" name="edit_item_price" id="edit_item_price_hidden">
                        </div>
                        
                        <div class="pt-2 pb-4 border-t border-gray-200">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-gray-700">Total:</span>
                                <span id="edit_item_total" class="text-lg font-bold text-gray-900">N$0.00</span>
                            </div>
                        </div>
                        
                        <div class="flex gap-3 pt-2">
                            <button type="submit" class="flex-1 bg-gray-700 hover:bg-gray-800 text-white font-medium py-2.5 px-4 rounded-lg transition-colors">
                                Save Changes
                            </button>
                            <button type="button" onclick="closeEditItemModal()" 
                                    class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2.5 px-4 rounded-lg transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
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

        // sendToPrinter loaded from ../receipt.php?js=true (routes to QZ Tray when enabled)
        if (typeof sendToPrinter === 'undefined') {
            function sendToPrinter(receiptData) {
                var dataWithBusiness = Object.assign({}, receiptData, {
                    business_name: receiptData.business_name || businessInfo.business_name,
                    location: receiptData.location || businessInfo.location,
                    phone: receiptData.phone || businessInfo.phone,
                    footer_text: receiptData.footer_text || businessInfo.footer_text,
                    vat_inclusive: receiptData.vat_inclusive || businessInfo.vat_inclusive,
                    vat_rate: receiptData.vat_rate || businessInfo.vat_rate
                });
                return fetch('../receipt.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dataWithBusiness)
                }).then(function(r) { return r.json(); });
            }
        }

        // Edit Item Modal functions
        function openEditItemModal(itemId, productName, quantity, price, tabId) {
            document.getElementById('edit_item_id').value = itemId;
            document.getElementById('edit_tab_id').value = tabId;
            document.getElementById('edit_product_name').value = productName;
            document.getElementById('edit_item_quantity').value = quantity;
            document.getElementById('edit_item_price').value = price.toFixed(2);
            document.getElementById('edit_item_price_hidden').value = price.toFixed(2);
            updateEditItemTotal();
            document.getElementById('editItemModal').classList.remove('hidden');
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function closeEditItemModal() {
            document.getElementById('editItemModal').classList.add('hidden');
            document.getElementById('editItemForm').reset();
        }

        function updateEditItemTotal() {
            const quantity = parseFloat(document.getElementById('edit_item_quantity').value) || 0;
            const price = parseFloat(document.getElementById('edit_item_price').value) || 0;
            const total = quantity * price;
            document.getElementById('edit_item_total').textContent = 'N$' + total.toFixed(2);
        }

        // Delete Tab Item function
        function deleteTabItem(itemId, productName, tabId) {
            Swal.fire({
                title: 'Delete Item?',
                html: `<p class="text-sm text-gray-600">Are you sure you want to remove <strong>${productName}</strong> from this tab?</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a form and submit it
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const itemIdInput = document.createElement('input');
                    itemIdInput.type = 'hidden';
                    itemIdInput.name = 'delete_item_id';
                    itemIdInput.value = itemId;
                    
                    const tabIdInput = document.createElement('input');
                    tabIdInput.type = 'hidden';
                    tabIdInput.name = 'tab_id';
                    tabIdInput.value = tabId;
                    
                    form.appendChild(itemIdInput);
                    form.appendChild(tabIdInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Confirm Order function
        function confirmOrder(tabId) {
            Swal.fire({
                title: 'Confirm Order?',
                html: `<p class="text-sm text-gray-600">This order will be sent to the manager for payment approval.</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#374151',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Confirm',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a form and submit it
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const confirmInput = document.createElement('input');
                    confirmInput.type = 'hidden';
                    confirmInput.name = 'confirm_order';
                    confirmInput.value = '1';
                    
                    const tabIdInput = document.createElement('input');
                    tabIdInput.type = 'hidden';
                    tabIdInput.name = 'tab_id';
                    tabIdInput.value = tabId;
                    
                    form.appendChild(confirmInput);
                    form.appendChild(tabIdInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Update total when quantity or price changes
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Lucide icons
            lucide.createIcons();
            
            const quantityInput = document.getElementById('edit_item_quantity');
            const priceInput = document.getElementById('edit_item_price');
            
            if (quantityInput) {
                quantityInput.addEventListener('input', updateEditItemTotal);
            }
            if (priceInput) {
                priceInput.addEventListener('input', updateEditItemTotal);
            }

            // Handle payment method radio buttons
            const paymentMethodRadios = document.querySelectorAll('input[name="payment_method"]');
            const paymentAmount = document.getElementById('paymentAmount');
            const cashAmount = document.getElementById('cashAmount');
            const eftAmount = document.getElementById('eftAmount');
            const mixedTotal = document.getElementById('mixedTotal');
            
            function updateMixedTotal() {
                const cash = parseFloat(cashAmount?.value || 0);
                const eft = parseFloat(eftAmount?.value || 0);
                const total = cash + eft;
                if (mixedTotal) {
                    mixedTotal.textContent = 'N$' + total.toFixed(2);
                }
                // Update main payment amount if mixed payment is selected
                const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
                if (selectedMethod && selectedMethod.value === 'mixed' && paymentAmount) {
                    paymentAmount.value = total.toFixed(2);
                }
            }
            
            // Listen to radio button changes
            paymentMethodRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    updatePaymentMethodUI(this.value);
                });
            });
            
            // Update mixed total when amounts change
            if (cashAmount && eftAmount) {
                cashAmount.addEventListener('input', updateMixedTotal);
                eftAmount.addEventListener('input', updateMixedTotal);
            }
            
            // Form validation
            const paymentForm = document.getElementById('paymentForm');
            if (paymentForm) {
                paymentForm.addEventListener('submit', function(e) {
                    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
                    
                    if (selectedMethod && selectedMethod.value === 'mixed') {
                        const cash = parseFloat(cashAmount?.value || 0);
                        const eft = parseFloat(eftAmount?.value || 0);
                        const total = parseFloat(paymentAmount?.value || 0);
                        
                        if (Math.abs((cash + eft) - total) > 0.01) {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'error',
                                title: 'Invalid Amount',
                                text: 'Cash + EFT amount must equal the payment amount',
                                confirmButtonColor: '#3B82F6',
                            });
                            return false;
                        }
                    }
                });
            }
        });

        // Function to close payment modal and reset form
        function closePaymentModal() {
            const modal = document.getElementById('paymentModal');
            const form = document.getElementById('paymentForm');
            if (modal) {
                modal.classList.add('hidden');
            }
            if (form) {
                form.reset();
                // Reset payment method to cash
                const cashRadio = document.getElementById('method_cash');
                if (cashRadio) {
                    cashRadio.checked = true;
                    updatePaymentMethodUI('cash');
                }
            }
        }

        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('[role="alert"]');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Handle pay_all parameter from URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('pay_all') === '1') {
                const amount = parseFloat(urlParams.get('amount')) || <?= $viewTab['current_balance'] ?>;
                const isEft = urlParams.get('eft') === '1';
                const isMixed = urlParams.get('mixed') === '1';
                
                // Show payment modal with amount pre-filled
                openPaymentModal(<?= $viewTab['id'] ?>, amount);
                
                if (isMixed) {
                    document.getElementById('method_mixed').checked = true;
                    updatePaymentMethodUI('mixed');
                } else if (isEft) {
                    document.getElementById('method_eft').checked = true;
                    updatePaymentMethodUI('eft');
                } else {
                    document.getElementById('method_cash').checked = true;
                    updatePaymentMethodUI('cash');
                }
                
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tabId ?>');
            }

            // Handle print_balance parameter from URL (auto-print after order confirmation)
            if (urlParams.get('print_balance') === '1') {
                // Use balance from URL if provided, otherwise use PHP variable
                const balanceFromUrl = urlParams.get('balance');
                const balance = balanceFromUrl ? parseFloat(balanceFromUrl) : <?= number_format($viewTab['current_balance'], 2, '.', '') ?>;
                printTabBalance(<?= $viewTab['id'] ?>, '<?= htmlspecialchars($viewTab['tab_name']) ?>', balance);
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tabId ?>');
            }
        });

        // Print tab balance receipt
        function printTabBalance(tabId, tabName, balance) {
            // Format tab items for receipt
            const items = <?= json_encode(array_map(function($item) {
                return [
                    'name' => $item['product_name'],
                    'quantity' => intval($item['quantity']),
                    'price' => floatval($item['price']) * intval($item['quantity']),
                    'unit_price' => floatval($item['price'])
                ];
            }, $tabItems)) ?>;
            
            // Format payments for receipt
            const payments = <?= json_encode(array_map(function($payment) {
                return [
                    'amount' => floatval($payment['amount']),
                    'payment_method' => $payment['payment_method'],
                    'payment_date' => $payment['payment_date']
                ];
            }, $tabPayments)) ?>;

            const receiptData = {
                tab_id: tabId,
                tab_name: tabName,
                creditor_name: '<?= htmlspecialchars($viewTab['creditor_name'] ?? 'N/A', ENT_QUOTES) ?>',
                total_balance: balance,
                is_tab_balance_receipt: true,
                is_balance_receipt: true,
                items: items,
                payments: payments,
                cashier_username: '<?= htmlspecialchars($viewTab['opened_by_username'] ?? 'Unknown', ENT_QUOTES) ?>'
            };

            sendToPrinter(receiptData)
            .then(printData => {
                if (printData.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Balance Receipt Printed',
                        text: `Balance receipt for ${tabName} has been sent to printer.`,
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
                    text: 'An error occurred while printing the balance receipt.',
                    confirmButtonColor: '#3B82F6',
                });
            });
        }

        // Split bill helper: use existing payment modal but pre-fill with per-person amount
        function openSplitBillModal(tabId, currentBalance) {
            if (!currentBalance || currentBalance <= 0) {
                return;
            }

            Swal.fire({
                title: 'Split Bill',
                html: `
                    <div class="space-y-3 text-left">
                        <p class="text-sm text-gray-600 mb-1">Current balance: <span class="font-semibold">N$${currentBalance.toFixed(2)}</span></p>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Number of people</label>
                        <input type="number"
                               id="splitBillCount"
                               min="2"
                               max="20"
                               step="1"
                               value="2"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm" />
                        <p class="text-xs text-gray-500 mt-1">The bill will be split into equal parts. You can run this again if you need a different amount for the next guest.</p>
                    </div>
                `,
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'Continue',
                customClass: {
                    popup: 'rounded-xl shadow-lg'
                },
                preConfirm: () => {
                    const input = document.getElementById('splitBillCount');
                    const count = parseInt(input.value, 10);
                    if (!count || count < 2) {
                        Swal.showValidationMessage('Enter at least 2 guests to split the bill.');
                        return false;
                    }
                    const perShareRaw = currentBalance / count;
                    const perShare = Math.round(perShareRaw * 100) / 100;
                    return { count, perShare, remainder: (currentBalance - perShare * count) };
                }
            }).then(result => {
                if (!result.isConfirmed || !result.value) return;

                const { count, perShare, remainder } = result.value;
                const remainderText = Math.abs(remainder) >= 0.01
                    ? `<p class="text-xs text-amber-600 mt-2">Note: Due to rounding, the last guest may pay slightly more or less.</p>`
                    : '';

                Swal.fire({
                    icon: 'info',
                    title: 'Split Summary',
                    html: `
                        <p class="text-sm text-gray-700">Bill split into <span class="font-semibold">${count}</span> parts.</p>
                        <p class="text-sm text-gray-700 mt-1">Each pays approximately <span class="font-semibold">N$${perShare.toFixed(2)}</span>.</p>
                        ${remainderText}
                        <p class="text-xs text-gray-500 mt-3">We will open the payment screen for one share. Repeat this action for each guest you process.</p>
                    `,
                    confirmButtonText: 'Pay First Share',
                    showCancelButton: true,
                    customClass: {
                        popup: 'rounded-xl shadow-lg'
                    }
                }).then(confirmResult => {
                    if (!confirmResult.isConfirmed) return;
                    // Reuse existing payment flow with per-person amount
                    openPaymentModal(tabId, perShare);
                });
            });
        }

        // Open Payment Modal
        function openPaymentModal(tabId, amount) {
            const modal = document.getElementById('paymentModal');
            const amountInput = document.getElementById('paymentAmount');
            
            // Set amount
            if (amountInput) {
                amountInput.value = parseFloat(amount).toFixed(2);
            }
            
            // Reset form and set defaults
            const form = document.getElementById('paymentForm');
            if (form) {
                form.reset();
                // Set amount again after reset
                if (amountInput) {
                    amountInput.value = parseFloat(amount).toFixed(2);
                }
            }
            
            // Reset to cash by default
            const cashRadio = document.getElementById('method_cash');
            if (cashRadio) {
                cashRadio.checked = true;
                updatePaymentMethodUI('cash');
            }
            
            // Clear mixed payment fields
            const cashAmount = document.getElementById('cashAmount');
            const eftAmount = document.getElementById('eftAmount');
            if (cashAmount) cashAmount.value = '';
            if (eftAmount) eftAmount.value = '';
            
            // Show modal
            modal.classList.remove('hidden');
            lucide.createIcons();
        }
        
        // Update payment method UI
        function updatePaymentMethodUI(method) {
            // Remove selected class from all buttons
            document.querySelectorAll('.payment-method-btn').forEach(btn => {
                btn.classList.remove('payment-method-selected', 'border-gray-500', 'bg-gray-100');
                btn.classList.add('border-gray-300');
            });
            
            // Show/hide fields
            const eftFields = document.getElementById('eftFields');
            const mixedFields = document.getElementById('mixedFields');
            const transactionRef = document.getElementById('transactionRef');
            const walletProvider = document.getElementById('walletProvider');
            const cashAmount = document.getElementById('cashAmount');
            const eftAmount = document.getElementById('eftAmount');
            
            if (method === 'cash') {
                eftFields?.classList.add('hidden');
                mixedFields?.classList.add('hidden');
                if (transactionRef) transactionRef.removeAttribute('required');
                if (cashAmount) cashAmount.removeAttribute('required');
                if (eftAmount) eftAmount.removeAttribute('required');
                
                // Highlight cash button
                const cashBtn = document.querySelector('label[for="method_cash"]');
                if (cashBtn) {
                    cashBtn.classList.add('payment-method-selected', 'border-gray-500', 'bg-gray-100');
                    cashBtn.classList.remove('border-gray-300');
                }
            } else if (method === 'eft') {
                eftFields?.classList.remove('hidden');
                mixedFields?.classList.add('hidden');
                if (transactionRef) transactionRef.removeAttribute('required');
                if (cashAmount) cashAmount.removeAttribute('required');
                if (eftAmount) eftAmount.removeAttribute('required');
                
                // Highlight EFT button
                const eftBtn = document.querySelector('label[for="method_eft"]');
                if (eftBtn) {
                    eftBtn.classList.add('payment-method-selected', 'border-gray-500', 'bg-gray-100');
                    eftBtn.classList.remove('border-gray-300');
                }
            } else if (method === 'mixed') {
                eftFields?.classList.remove('hidden');
                mixedFields?.classList.remove('hidden');
                if (transactionRef) transactionRef.removeAttribute('required');
                if (cashAmount) cashAmount.setAttribute('required', 'required');
                if (eftAmount) eftAmount.setAttribute('required', 'required');
                
                // Highlight mixed button
                const mixedBtn = document.querySelector('label[for="method_mixed"]');
                if (mixedBtn) {
                    mixedBtn.classList.add('payment-method-selected', 'border-gray-500', 'bg-gray-100');
                    mixedBtn.classList.remove('border-gray-300');
                }
            }
        }
        
        // Mobile sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            
            if (sidebar) sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
            if (hamburger) hamburger.classList.toggle('open');
        }
        
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
            if (hamburger) hamburger.classList.remove('open');
        }
    </script>
</body>
</html>

